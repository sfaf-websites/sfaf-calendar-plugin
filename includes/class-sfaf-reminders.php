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
     * themselves), any portal users added, and any free-text addresses.
     *
     * This is a notification list and nothing else. It confers no ownership and
     * changes nothing about who may edit the event.
     *
     * @return array<string,string> lowercased email => display label.
     */
    public static function notify_list( $event_id ) {
        $out = array();

        // The creator. WordPress already stores this as post_author, so there
        // is no parallel field to keep in step with it.
        $post = get_post( $event_id );
        if ( $post && ! self::author_opted_out( $event_id ) ) {
            $author = get_userdata( $post->post_author );
            if ( $author && is_email( $author->user_email ) ) {
                $out[ self::normalize( $author->user_email ) ] = $author->display_name . ' (creator)';
            }
        }

        foreach ( (array) get_post_meta( $event_id, self::NOTIFY_USERS_META, true ) as $uid ) {
            $user = get_userdata( (int) $uid );
            if ( $user && is_email( $user->user_email ) ) {
                $out[ self::normalize( $user->user_email ) ] = $user->display_name;
            }
        }

        foreach ( (array) get_post_meta( $event_id, self::NOTIFY_EMAILS_META, true ) as $email ) {
            if ( is_email( $email ) ) {
                $key = self::normalize( $email );
                if ( ! isset( $out[ $key ] ) ) {
                    $out[ $key ] = $email;
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
     */
    private static function new_token() {
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
        $settings = get_option( 'uc_settings', array() );

        $subject = isset( $settings['email_dayof_subject'] ) ? (string) $settings['email_dayof_subject'] : '';
        $body    = isset( $settings['email_dayof_body'] ) ? (string) $settings['email_dayof_body'] : '';
        if ( '' === $subject ) {
            $subject = 'Today: {event_name}';
        }
        if ( '' === $body ) {
            $body = self::default_body();
        }

        // The cancel link belongs to people who actually hold a place. A staff
        // member on the notification list has nothing to cancel, so the token
        // is left out of their copy entirely rather than rendering a link that
        // would tell them there is no registration.
        $cancel = in_array( $type, array( 'rsvp', 'subscriber' ), true ) ? self::cancel_url( $token ) : '';

        $data = array(
            'email'      => $email,
            'cancel_url' => $cancel,
        );

        $subject = sfaf_replace_tokens( $subject, $event_id, $data );
        $body    = sfaf_replace_tokens( $body, $event_id, $data );

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        $from_name  = isset( $settings['email_from_name'] ) ? trim( (string) $settings['email_from_name'] ) : '';
        $from_email = isset( $settings['email_from_address'] ) ? trim( (string) $settings['email_from_address'] ) : '';
        if ( $from_email && is_email( $from_email ) ) {
            $headers[] = $from_name
                ? sprintf( 'From: %s <%s>', $from_name, $from_email )
                : sprintf( 'From: %s', $from_email );
        }

        $reply_to = isset( $settings['email_reply_to'] ) ? trim( (string) $settings['email_reply_to'] ) : '';
        if ( $reply_to && is_email( $reply_to ) ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        return (bool) wp_mail( $email, $subject, $body, $headers );
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

        $row = self::find_by_token( $token );
        if ( ! $row ) {
            self::cancel_page( 'That link is not valid', 'This cancellation link has expired or was not recognised. If you need to cancel, reply to the email you received and we will sort it out.' );
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
                    : sprintf( 'We could not find an active registration for %s against this address. It may already have been cancelled.', $event_title )
            );
        }

        // The ask.
        $html  = '<p>Cancel your place at <strong>' . esc_html( $event_title ) . '</strong>?</p>';
        $html .= '<p>Places are limited, so cancelling puts yours back for someone else.</p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="uc_cancel_token" value="' . esc_attr( $token ) . '" />';
        $html .= '<p><button type="submit">Yes, cancel my place</button></p>';
        $html .= '</form>';
        self::cancel_page( "Can't make it?", $html, false );
    }

    /** Look a claim row up by its token. */
    private static function find_by_token( $token ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE token = %s", $token ) );
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
            "UPDATE $table SET status = 'cancelled' WHERE event_id = %d AND email = %s AND status IN ('confirmed','subscribed')",
            $event_id,
            $email
        ) );
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

    /** The most recent ledger rows across all events. */
    public static function recent( $limit = 100 ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table ORDER BY id DESC LIMIT %d",
            (int) $limit
        ) );
    }
}
