<?php
/**
 * Morning-of reminder emails.
 *
 * One reminder per event, on the day of the event, to everyone registered for
 * it plus the event's own notification list. Driven by SFAF_Cron's hourly run.
 *
 * NATIVE EVENTS ONLY
 * ---------------------------------------------------------------------------
 * An event imported from GoFundMe Pro, Eventbrite or any future adapter is
 * excluded outright. Those platforms hold the registrations and send their own
 * reminders; a second email from here would be a duplicate the recipient never
 * asked for, about a registration this site does not own. The check is on the
 * presence of a source, not on a named platform, so a new adapter is excluded
 * the moment it exists rather than the day somebody remembers to add it.
 *
 * SEND-ONCE
 * ---------------------------------------------------------------------------
 * This is the part that has to be right, because the failure mode is a person
 * getting the same email four times. Three independent things guarantee it:
 *
 *   1. SFAF_Cron's run lock, so two runs cannot overlap in the first place.
 *   2. A UNIQUE KEY on (event_id, recipient_hash) in the ledger below. A send
 *      is CLAIMED BY INSERTING THE LEDGER ROW FIRST and only then sending. A
 *      second attempt — overlapping run, manual re-run, a run resumed after a
 *      crash — fails that insert and skips. This layer needs no cooperation
 *      from anything else and holds even with the lock broken.
 *   3. A per-event marker (_uc_reminder_sent_at) written once the event's pass
 *      completes, so later runs never look at it again.
 *
 * A send that fails is RECORDED AS FAILED AND NOT RETRIED. That is deliberate:
 * wp_mail() returning false does not reliably mean nothing was delivered, so
 * retrying risks the exact duplicate this whole design exists to prevent. The
 * failure is counted in the run log where somebody can see it and act.
 *
 * Because the claim happens at send time, an RSVP made after the send simply
 * has no row and no reminder. That is correct — they just signed up; they know
 * the event is today.
 *
 * TIMING
 * ---------------------------------------------------------------------------
 * 6:00am in the site's timezone, reached by the first hourly run at or after
 * it. Up to an hour of drift is accepted.
 *
 * THE EVENT THAT STARTS BEFORE 6AM is handled explicitly rather than skipped:
 * when an event has a start time earlier than 6:00, its reminder becomes due at
 * 00:00 that day instead, so it goes out on the first run after midnight and
 * still arrives before the event. An event with no start time at all keeps the
 * 6:00 slot, because "no time set" must not be read as "starts at midnight".
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Reminders {

    /** Per-event marker: the pass for this event has completed. */
    const EVENT_DONE_META = '_uc_reminder_sent_at';

    /** Notification list meta. */
    const NOTIFY_USERS_META   = '_uc_notify_users';
    const NOTIFY_EMAILS_META  = '_uc_notify_emails';
    const NOTIFY_AUTHOR_OPTOUT_META = '_uc_notify_author_optout';

    /** The hour the reminder is due, site timezone. */
    const DUE_HOUR = 6;

    /** Ledger table name. */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uc_reminder_log';
    }

    public function register() {
        // The cancel link. Same pattern as the .ics endpoint: a front-end
        // query var handled before the theme gets involved.
        add_action( 'template_redirect', array( $this, 'maybe_handle_cancel' ) );
    }

    /** Whether reminders are switched on. On unless explicitly disabled. */
    public static function enabled() {
        $settings = get_option( 'uc_settings', array() );
        return ! isset( $settings['reminders_enabled'] ) || '1' === $settings['reminders_enabled'];
    }

    /* =====================================================================
     * Reply-To
     * ================================================================== */

    /**
     * Where a reply to this event's reminder should land.
     *
     * ONE ADDRESS, NOT A LIST. Several Reply-To headers are handled
     * inconsistently by mail clients and some drop all but the first, so a
     * group mailbox is the reliable way to get replies in front of more than
     * one person. Free text, because it may well not be on the sending domain.
     *
     * REUSES _uc_email_replyto, which predates this: it is the per-event
     * override the RSVP confirmation email has always read, it is already
     * copied down to occurrences by SFAF_Recurrence, and it already has an
     * editor in the WordPress admin. A second field would have meant two
     * answers to "where do replies go" with nothing to say which one won.
     *
     * Resolution order, most specific first:
     *   1. the event's own address
     *   2. the address of whoever created the event
     *   3. the site-wide default in Settings
     *
     * Step 2 is what stops an event set up and forgotten from sending replies
     * into a no-reply void. The sending domain is never assumed or built here;
     * every part of this is a setting or a real user's address.
     *
     * @return string '' when nothing resolves.
     */
    public static function reply_to_for( $event_id ) {
        $own = trim( (string) get_post_meta( (int) $event_id, '_uc_email_replyto', true ) );
        if ( $own && is_email( $own ) ) {
            return $own;
        }

        $post = get_post( (int) $event_id );
        if ( $post ) {
            $author = get_userdata( $post->post_author );
            if ( $author && is_email( $author->user_email ) ) {
                return $author->user_email;
            }
        }

        $settings = get_option( 'uc_settings', array() );
        $fallback = isset( $settings['email_reply_to'] ) ? trim( (string) $settings['email_reply_to'] ) : '';
        return ( $fallback && is_email( $fallback ) ) ? $fallback : '';
    }

    /**
     * Which of the three sources reply_to_for() would use, so the editor can
     * say where replies currently go instead of showing a bare address.
     *
     * @return string 'event' | 'author' | 'setting' | 'none'
     */
    public static function reply_to_source( $event_id ) {
        $own = trim( (string) get_post_meta( (int) $event_id, '_uc_email_replyto', true ) );
        if ( $own && is_email( $own ) ) {
            return 'event';
        }
        $post = get_post( (int) $event_id );
        if ( $post ) {
            $author = get_userdata( $post->post_author );
            if ( $author && is_email( $author->user_email ) ) {
                return 'author';
            }
        }
        $settings = get_option( 'uc_settings', array() );
        $fallback = isset( $settings['email_reply_to'] ) ? trim( (string) $settings['email_reply_to'] ) : '';
        return ( $fallback && is_email( $fallback ) ) ? 'setting' : 'none';
    }

    /* =====================================================================
     * The run
     * ================================================================== */

    /**
     * Send every reminder that is due right now.
     *
     * @return array{status:string,summary:string,counts:array} for the run log.
     */
    public static function run() {
        $counts = array(
            'events'     => 0,
            'recipients' => 0,
            'sent'       => 0,
            'already'    => 0,
            'failed'     => 0,
        );

        if ( ! self::enabled() ) {
            return array(
                'status'  => 'skipped',
                'summary' => 'Reminder emails are switched off in Settings.',
                'counts'  => $counts,
            );
        }

        $events = self::due_events();
        foreach ( $events as $event_id ) {
            $counts['events']++;
            $one = self::send_for_event( $event_id );
            $counts['recipients'] += $one['recipients'];
            $counts['sent']       += $one['sent'];
            $counts['already']    += $one['already'];
            $counts['failed']     += $one['failed'];
        }

        if ( ! $counts['events'] ) {
            return array(
                'status'  => 'ok',
                'summary' => 'Nothing due: no native event today has reached its reminder time.',
                'counts'  => $counts,
            );
        }

        $summary = sprintf(
            '%d event%s due, %d recipient%s: %d sent, %d already recorded, %d failed.',
            $counts['events'], 1 === $counts['events'] ? '' : 's',
            $counts['recipients'], 1 === $counts['recipients'] ? '' : 's',
            $counts['sent'], $counts['already'], $counts['failed']
        );

        return array(
            // A failed send is reported, not treated as a broken runner: the
            // run itself worked, the mail transport did not.
            'status'  => 'ok',
            'summary' => $summary,
            'counts'  => $counts,
        );
    }

    /**
     * Native, published events dated today whose reminder time has passed and
     * whose pass has not already completed.
     *
     * Queried on date + "not done yet" only. Native-vs-imported and the due
     * time are decided in PHP: the set is one day's events, so filtering it
     * here is cheaper and far more legible than three meta joins.
     *
     * @return int[] Post IDs.
     */
    public static function due_events() {
        $today = current_time( 'Y-m-d' );

        $query = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => '_uc_event_date', 'value' => $today ),
                array( 'key' => self::EVENT_DONE_META, 'compare' => 'NOT EXISTS' ),
            ),
        ) );

        $due = array();
        foreach ( $query->posts as $id ) {
            if ( self::is_imported( $id ) ) {
                continue;
            }
            if ( ! self::is_due( $id ) ) {
                continue;
            }
            $due[] = (int) $id;
        }
        return $due;
    }

    /** Whether an event came from a third-party source. */
    public static function is_imported( $post_id ) {
        return '' !== (string) get_post_meta( $post_id, SFAF_Sources::META_SOURCE, true );
    }

    /**
     * The moment this event's reminder becomes due, as a DateTime in the site
     * timezone, or null when the event has no usable date.
     */
    public static function due_at( $post_id ) {
        $date = (string) get_post_meta( $post_id, '_uc_event_date', true );
        if ( '' === $date ) {
            return null;
        }
        $tz = wp_timezone();
        try {
            $due = new DateTime( $date . ' ' . sprintf( '%02d:00', self::DUE_HOUR ), $tz );
        } catch ( Exception $e ) {
            return null;
        }

        // The pre-6am event. Bringing the reminder forward to the start of the
        // day is the only way it can still arrive before the event; leaving it
        // at 6:00 would send it after the doors had opened, and skipping it
        // would mean an early event never gets a reminder at all.
        $start = (string) get_post_meta( $post_id, '_uc_start_time', true );
        if ( '' !== $start ) {
            try {
                $starts = new DateTime( $date . ' ' . $start, $tz );
                if ( $starts < $due ) {
                    $due = new DateTime( $date . ' 00:00', $tz );
                }
            } catch ( Exception $e ) {
                // Unparseable time: keep the 6:00 slot.
            }
        }

        return $due;
    }

    /** Whether this event's reminder time has passed. */
    public static function is_due( $post_id ) {
        $due = self::due_at( $post_id );
        if ( ! $due ) {
            return false;
        }
        return new DateTime( 'now', wp_timezone() ) >= $due;
    }

    /* =====================================================================
     * Recipients
     * ================================================================== */

    /**
     * Everyone who should receive this event's reminder.
     *
     * Two groups, per spec, plus the people who pressed "Get Reminders" — that
     * button stores a row promising a reminder, and this is the thing that
     * keeps the promise.
     *
     * @return array<string,string> lowercased email => type
     *   ('rsvp' | 'subscriber' | 'notify')
     */
    public static function recipients( $event_id ) {
        global $wpdb;
        $out   = array();
        $table = $wpdb->prefix . 'uc_rsvps';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT email, status FROM $table WHERE event_id = %d AND status IN ('confirmed','subscribed')",
            $event_id
        ) );
        foreach ( (array) $rows as $row ) {
            $email = self::normalize( $row->email );
            if ( '' === $email || isset( $out[ $email ] ) ) {
                continue;
            }
            $out[ $email ] = ( 'subscribed' === $row->status ) ? 'subscriber' : 'rsvp';
        }

        foreach ( self::notify_list( $event_id ) as $email => $label ) {
            if ( ! isset( $out[ $email ] ) ) {
                $out[ $email ] = 'notify';
            }
        }

        return $out;
    }

    /**
     * The event's notification list: the author (unless they removed
     * themselves), any portal users added, anyone in a team the event names,
     * and any free-text addresses.
     *
     * This is a notification list and nothing else. It confers no ownership and
     * changes nothing about who may edit the event.
     *
     * RESOLVED HERE, EVERY TIME, AND NEVER STORED.
     *
     * The event holds user ids, team ids and typed addresses. It does not hold
     * a recipient list, and nothing anywhere writes one down. This function is
     * what turns those references into people, it runs when the mail is about
     * to go out, and it is also what the editor calls to show a manager who is
     * currently on the list. One computation, two callers, so the number on
     * the screen and the people who get mail cannot drift apart.
     *
     * That is why taking somebody out of a team stops their mail for every
     * event naming it immediately, why deleting their account does the same,
     * and why somebody added to a team today starts receiving mail for events
     * chosen before they joined. All three are the same fact: there is nothing
     * cached to go stale.
     *
     * DEDUPLICATED BY ADDRESS, keyed on the lowercased email. Somebody picked
     * individually AND sitting in a selected team appears once, and gets one
     * message. The label kept is the first one seen, which is the most
     * specific: creator, then individually chosen, then via a team.
     *
     * @return array<string,string> lowercased email => display label.
     */
    public static function notify_list( $event_id ) {
        // Written out rather than wp_list_pluck()ed. THE KEY IS THE DEDUPLICATION
        // and the whole contract of this function; a helper that is documented
        // to preserve keys is one more thing that has to keep being true for
        // the same address not to be mailed twice.
        $out = array();
        foreach ( self::notify_entries( $event_id ) as $email => $entry ) {
            $out[ $email ] = $entry['label'];
        }
        return $out;
    }

    /**
     * The same list, with WHO each address belongs to.
     *
     * THE ONE RESOLUTION IS HERE NOW, and notify_list() is a projection of it.
     * There is still exactly one place that turns user ids, team ids and typed
     * addresses into recipients; this one also says, per address, whether it
     * came from a WordPress account and which one.
     *
     * THE REGISTRATION ALERT NEEDS THAT, and nothing else can supply it. The
     * alert links an organizer to the RSVP list, which is gated on
     * can_view_all, so the message is built per recipient and each recipient's
     * capability has to be knowable. Looking the address up with
     * get_user_by( 'email' ) at send time would answer a DIFFERENT question:
     * a typed address that happens to match an account would come back as that
     * account, and a free-text address is not that person on this list, it is a
     * string somebody wrote in a box. user_id is 0 for those, always, and 0 is
     * what stops the RSVP link being offered to them.
     *
     * @return array<string,array{label:string,user_id:int}> lowercased email => entry
     */
    public static function notify_entries( $event_id ) {
        $out = array();

        // The creator. WordPress already stores this as post_author, so there
        // is no parallel field to keep in step with it.
        $post = get_post( $event_id );
        if ( $post && ! self::author_opted_out( $event_id ) ) {
            $author = get_userdata( $post->post_author );
            if ( $author && is_email( $author->user_email ) ) {
                $out[ self::normalize( $author->user_email ) ] = array(
                    'label'   => $author->display_name . ' (creator)',
                    'user_id' => (int) $author->ID,
                );
            }
        }

        foreach ( (array) get_post_meta( $event_id, self::NOTIFY_USERS_META, true ) as $uid ) {
            $user = get_userdata( (int) $uid );
            if ( $user && is_email( $user->user_email ) ) {
                $key = self::normalize( $user->user_email );
                if ( ! isset( $out[ $key ] ) ) {
                    $out[ $key ] = array( 'label' => $user->display_name, 'user_id' => (int) $user->ID );
                }
            }
        }

        /*
         * Teams. The event named a team; who that is gets decided now.
         *
         * A team that has been emptied contributes nobody and is not an error.
         * A team id that no longer resolves contributes nobody either, though
         * that cannot arise: SFAF_Teams::delete() refuses while any event
         * names the team, so there is no way to leave a dangling reference
         * behind.
         */
        foreach ( SFAF_Teams::for_event( $event_id ) as $team_id ) {
            $team_name = SFAF_Teams::get( $team_id );
            $team_name = $team_name ? $team_name['name'] : $team_id;
            foreach ( SFAF_Teams::people( $team_id ) as $email => $person ) {
                if ( ! isset( $out[ $email ] ) ) {
                    $out[ $email ] = array(
                        'label'   => $person['label'] . ' (' . $team_name . ' team)',
                        // The member's OWN account. A team confers no access, so
                        // what a team member may see is decided one person at a
                        // time and this is what lets the caller ask.
                        'user_id' => (int) $person['user_id'],
                    );
                }
            }
        }

        // Typed addresses. user_id 0, and it is not a lookup failure: these are
        // strings somebody wrote in a box, they are not accounts, and nothing
        // downstream may treat them as one.
        foreach ( (array) get_post_meta( $event_id, self::NOTIFY_EMAILS_META, true ) as $email ) {
            if ( is_email( $email ) ) {
                $key = self::normalize( $email );
                if ( ! isset( $out[ $key ] ) ) {
                    $out[ $key ] = array( 'label' => $email, 'user_id' => 0 );
                }
            }
        }

        return $out;
    }

    /** Whether the event's creator removed themselves from the list. */
    public static function author_opted_out( $event_id ) {
        return '1' === (string) get_post_meta( $event_id, self::NOTIFY_AUTHOR_OPTOUT_META, true );
    }

    private static function normalize( $email ) {
        return strtolower( trim( (string) $email ) );
    }

    /* =====================================================================
     * Sending
     * ================================================================== */

    /**
     * Send one event's reminders. Claim first, send second, mark the event done
     * last — in that order, so an interruption at any point cannot produce a
     * duplicate on the next run.
     *
     * @return array{recipients:int,sent:int,already:int,failed:int}
     */
    public static function send_for_event( $event_id ) {
        $result = array( 'recipients' => 0, 'sent' => 0, 'already' => 0, 'failed' => 0 );

        // Re-check both guards here, not just in due_events(): send_for_event()
        // is also reachable from a manual run.
        if ( self::is_imported( $event_id ) ) {
            return $result;
        }

        // The per-event switch. The event is still marked done at the end, so a
        // reminder switched off does not leave the event being reconsidered on
        // every run for the rest of the day.
        if ( ! SFAF_Notifications::on( $event_id, 'reminder' ) ) {
            update_post_meta( $event_id, self::EVENT_DONE_META, time() );
            return $result;
        }

        foreach ( self::recipients( $event_id ) as $email => $type ) {
            $result['recipients']++;

            $claim = self::claim( $event_id, $email, $type );
            if ( ! $claim ) {
                // The unique key refused it: this address already has a row for
                // this event, from this run or any earlier one.
                $result['already']++;
                continue;
            }

            $sent = self::send_one( $event_id, $email, $type, $claim['token'] );
            self::finish( $claim['id'], $sent );
            if ( $sent ) {
                $result['sent']++;
            } else {
                $result['failed']++;
            }
        }

        update_post_meta( $event_id, self::EVENT_DONE_META, time() );

        return $result;
    }

    /**
     * Reserve the right to send to this address for this event.
     *
     * The INSERT is the claim. A duplicate (event_id, recipient_hash) is
     * rejected by the unique key and $wpdb->insert() returns false, which is
     * the whole send-once guarantee in one statement.
     *
     * @return array{id:int,token:string}|null
     */
    private static function claim( $event_id, $email, $type ) {
        global $wpdb;

        $token = self::new_token();
        $now   = current_time( 'mysql' );

        // A duplicate key here is expected, not exceptional, so the error is
        // suppressed rather than printed over the page or the cron output.
        $previous = $wpdb->suppress_errors( true );
        $ok = $wpdb->insert( self::table(), array(
            'event_id'       => (int) $event_id,
            'email'          => $email,
            'recipient_hash' => hash( 'sha256', $email ),
            'recipient_type' => $type,
            'token'          => $token,
            'claimed_at'     => $now,
            'result'         => 'claimed',
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        $wpdb->suppress_errors( $previous );

        if ( ! $ok ) {
            return null;
        }
        return array( 'id' => (int) $wpdb->insert_id, 'token' => $token );
    }

    /** Record the outcome against the claimed row. */
    private static function finish( $row_id, $sent ) {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array( 'result' => $sent ? 'sent' : 'failed', 'sent_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $row_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * An unguessable per-recipient, per-event token for the cancel link.
     * No account, no session, and nothing derivable from the email address.
     *
     * PUBLIC SINCE 3.25.0, because the registration row needs one too: the
     * confirmation email carries a cancel link and goes out when somebody
     * registers, which is long before any reminder ledger row exists. One
     * generator, so there is one answer to "how strong is that token".
     */
    public static function new_token() {
        if ( function_exists( 'random_bytes' ) ) {
            try {
                return bin2hex( random_bytes( 16 ) );
            } catch ( \Exception $e ) {
                // fall through
            }
        }
        return md5( wp_generate_password( 32, true, true ) . microtime( true ) . wp_rand() );
    }

    /**
     * Build and send one reminder.
     *
     * Everything provider-specific — from name, from address, reply-to — comes
     * from settings and goes out through wp_mail(), so an SMTP plugin or a
     * wp_mail filter can take delivery over later without a line changing here.
     * No provider is named or depended on anywhere in this file.
     */
    private static function send_one( $event_id, $email, $type, $token ) {
        /*
         * THE CANCEL LINK BELONGS TO PEOPLE WHO HOLD A PLACE. A staff member on
         * the notification list has nothing to cancel, so the token is left out
         * of their copy entirely rather than rendering a link that would tell
         * them there is no registration. is_staff also changes the opening line:
         * "your event is today" is wrong for somebody who is not attending.
         */
        $holds_a_place = in_array( $type, array( 'rsvp', 'subscriber' ), true );

        $person = (object) array(
            'email'    => $email,
            'token'    => $holds_a_place ? $token : '',
            'is_staff' => ! $holds_a_place,
        );

        $built = SFAF_Notifications::build( 'reminder', $event_id, $person );
        if ( ! $built ) {
            return false;
        }

        // From, Reply-To and the plain-text alternative are SFAF_Email's job
        // now, so there is one place that knows how this plugin's mail is
        // addressed. Reply-To is still per event, falling back to the creator
        // and then to the site default: replies to a reminder reach the person
        // running the event, not a mailbox nobody reads.
        return SFAF_Email::send(
            $email,
            $built['subject'],
            $built['html'],
            $built['text'],
            self::reply_to_for( $event_id )
        );
    }

    /** The shipped default body. Every field the spec asks for, plain text. */
    public static function default_body() {
        return "Your event is today.\n\n"
            . "{event_name}\n"
            . "{event_date}\n"
            . "{event_time_range}\n"
            . "{event_location}\n\n"
            . "Details: {event_url}\n"
            . "{cancel_link}";
    }

    /* =====================================================================
     * The cancel link
     * ================================================================== */

    /** Public URL that offers to cancel this recipient's place. */
    public static function cancel_url( $token ) {
        return add_query_arg( 'uc_rsvp_cancel', $token, home_url( '/' ) );
    }

    /**
     * Handle the cancel link.
     *
     * A GET NEVER CANCELS ANYTHING. Mail clients, security scanners and link
     * previewers fetch the URLs in an email without a person ever clicking, so
     * a one-request cancel would drop people's places for them. The link opens
     * a page that asks; the POST from that page is what acts. The token is the
     * only credential, which is what lets this work with no account.
     */
    public function maybe_handle_cancel() {
        $token = isset( $_GET['uc_rsvp_cancel'] ) ? sanitize_text_field( wp_unslash( $_GET['uc_rsvp_cancel'] ) ) : '';
        if ( '' === $token ) {
            return;
        }

        $row = self::resolve_token( $token );
        if ( ! $row ) {
            self::cancel_page( 'That link is not valid', 'This cancellation link has expired or was not recognized. If you need to cancel, reply to the email you received and we will sort it out.' );
        }

        $event_title = get_the_title( $row->event_id );

        $confirmed = isset( $_SERVER['REQUEST_METHOD'] )
            && 'POST' === $_SERVER['REQUEST_METHOD']
            && isset( $_POST['uc_cancel_token'] )
            && hash_equals( $token, sanitize_text_field( wp_unslash( $_POST['uc_cancel_token'] ) ) );

        if ( $confirmed ) {
            $freed = self::cancel_rsvp( (int) $row->event_id, (string) $row->email );
            self::cancel_page(
                $freed ? 'Your place has been released' : 'Nothing to cancel',
                $freed
                    ? sprintf( 'You are no longer registered for %s. Your place has gone back to the count for someone else.', $event_title )
                    : sprintf( 'We could not find an active registration for %s against this address. It may already have been canceled.', $event_title )
            );
        }

        // The ask.
        $html  = '<p>Cancel your place at <strong>' . esc_html( $event_title ) . '</strong>?</p>';
        $html .= '<p>Places are limited, so canceling puts yours back for someone else.</p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="uc_cancel_token" value="' . esc_attr( $token ) . '" />';
        $html .= '<p><button type="submit">Yes, cancel my place</button></p>';
        $html .= '</form>';
        self::cancel_page( "Can't make it?", $html, false );
    }

    /**
     * Which registration a cancel token belongs to.
     *
     * TWO PLACES CARRY A TOKEN AND BOTH ARE CHECKED. The registration row gets
     * one when somebody registers, which is what the confirmation email links
     * to. The reminder ledger gets one per recipient per event, which is what
     * the morning-of reminder links to. They are different rows describing the
     * same person's place, and either link must work: somebody may cancel from
     * the confirmation three weeks later or from the reminder that morning.
     *
     * AN EMPTY TOKEN IS REJECTED BEFORE ANY QUERY. Registrations written before
     * 3.25.0 all carry '', so a blank token in a URL would otherwise match the
     * whole history and offer to cancel a stranger's place.
     *
     * @return object|null with ->event_id and ->email.
     */
    private static function resolve_token( $token ) {
        global $wpdb;

        $token = trim( (string) $token );
        if ( '' === $token ) {
            return null;
        }

        $rsvps = $wpdb->prefix . 'uc_rsvps';
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT event_id, email FROM $rsvps WHERE token = %s AND token <> '' LIMIT 1",
            $token
        ) );
        if ( $row ) {
            return $row;
        }

        $table = self::table();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT event_id, email FROM $table WHERE token = %s AND token <> '' LIMIT 1",
            $token
        ) );
    }

    /**
     * Release a place. Sets the RSVP row to "cancelled" rather than deleting
     * it, so the count frees up while the history stays.
     *
     * @return bool Whether anything was actually released.
     */
    public static function cancel_rsvp( $event_id, $email ) {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';
        $rows  = $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET status = 'cancelled', cancelled_at = %s WHERE event_id = %d AND email = %s AND status IN ('confirmed','subscribed')",
            current_time( 'mysql' ),
            $event_id,
            $email
        ) );

        if ( $rows > 0 ) {
            /*
             * THE PLACE IS FREE THE MOMENT THIS RETURNS, and nothing has to be
             * told about it. Capacity is counted with a COUNT of rows at status
             * 'confirmed' (sfaf_get_rsvp_count), the reminder recipients are
             * selected on the same statuses, and the pre-event summary lists
             * confirmed rows only. Moving the status out of 'confirmed' removes
             * this person from all three at once. There is no counter to
             * decrement and no cache to clear beyond the request-local one.
             */
            sfaf_clear_rsvp_count_cache( (int) $event_id );
            do_action( 'uc_rsvp_cancelled', (int) $event_id, (string) $email );
        }

        return ( $rows > 0 );
    }

    /** A minimal, themeless page for the cancel flow. */
    private static function cancel_page( $title, $message, $escape = true ) {
        wp_die(
            '<h1>' . esc_html( $title ) . '</h1>' . ( $escape ? '<p>' . esc_html( $message ) . '</p>' : $message ),
            esc_html( $title ),
            array( 'response' => 200, 'back_link' => false )
        );
    }

    /* =====================================================================
     * Reporting
     * ================================================================== */

    /**
     * The ledger rows for one event, for the portal.
     *
     * @return object[]
     */
    public static function log_for_event( $event_id ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE event_id = %d ORDER BY claimed_at DESC",
            $event_id
        ) );
    }

    /* recent() removed in 3.28.0: never called. It read the ledger across every
       event, and the screen that would have shown that (the flat all-events
       RSVP list) was retired in 3.5.0. for_event() above is the one that is
       used, and it is the question anybody actually asks. */
}
