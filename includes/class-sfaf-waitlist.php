<?php
/**
 * THE WAITLIST (3.106.0).
 *
 * When an event's capacity is full, the RSVP form offers "Join the waitlist"
 * instead of registering. A waitlisted person is a row in uc_rsvps with a
 * status of its own, ordered by when they joined, per format on a hybrid event.
 *
 *   waitlisted      in the queue
 *   offered         a place is held for them until offer_expires
 *   offered_manual  no email to offer it by; staff were told, the offer moved on
 *   expired         their offer ran out unanswered
 *   confirmed       in, by accepting an offer or by staff
 *   cancelled       left the waitlist, or removed by staff
 *
 * EVERY COUNT STILL ASKS FOR 'confirmed'. The reminder, announcement and
 * summary audiences, has_registrations() and the count cache never see a row
 * in any other status, so nobody on the waitlist is ever sent a reminder or
 * counted as coming. What changes is only whether a format is FULL:
 * sfaf_format_full() adds the places held by open offers, and treats anybody
 * still waiting as full, so a newcomer cannot take a place that is on offer
 * or jump the queue. See held() and waiting().
 *
 * A PLACE OPENS three ways, and each calls advance(): a cancellation through
 * the link, a Remove from the RSVP list (both fire uc_rsvp_cancelled), and
 * capacity raised in the editor. advance() offers the first waitlisted person
 * for that format a place for 24 hours, or 2 when the event starts within 24,
 * and keeps going while places are free. Somebody with no email cannot be
 * offered anything by mail: the notification list is told their name and
 * phone, their row becomes offered_manual, and the offer moves on.
 *
 * EXPIRY RUNS ON THE EXISTING CRON (SFAF_Cron::tasks(), 'waitlist'). The
 * confirm page also refuses an offer whose time has passed, so a cron that
 * runs late never lets somebody take a place that has moved on.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Waitlist {

    const WAITING = 'waitlisted';
    const OFFERED = 'offered';
    const MANUAL  = 'offered_manual';
    const EXPIRED = 'expired';

    /** The confirm link's query argument. */
    const ARG = 'uc_rsvp_offer';

    /** How long an offer holds a place, and how long when the event is close. */
    const HOURS      = 24;
    const HOURS_SOON = 2;

    public function register() {
        // After the cancel alert at 10, so the list is told first.
        add_action( 'uc_rsvp_cancelled', array( __CLASS__, 'on_released' ), 20, 3 );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_offer' ) );
    }

    /** The statuses of somebody still on the waitlist. */
    public static function waiting_statuses() {
        return array( self::WAITING, self::OFFERED, self::MANUAL );
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uc_rsvps';
    }

    /** The format a row counts against: '' on an event with one format. */
    private static function format_for( $event_id, $format ) {
        return SFAF_Online::is_hybrid( (int) $event_id ) ? (string) $format : '';
    }

    /**
     * Whether this event can have a waitlist at all: it takes RSVPs here, and
     * the format has a limit. A third-party event and one with RSVPs off never
     * show one (sfaf_rsvp_block() returns before the button for both).
     */
    public static function applies( $event_id, $format = '' ) {
        $event_id = (int) $event_id;
        if ( '1' !== (string) get_post_meta( $event_id, '_uc_rsvp_enabled', true ) ) {
            return false;
        }
        if ( '' !== SFAF_Sources::registration_url( $event_id ) ) {
            return false;
        }
        // Any limit, including 0 places (3.107.0); an empty box has none.
        return sfaf_capacity_limited( $event_id, $format );
    }

    /**
     * Whether offers can go out at all: it takes RSVPs here. NOT whether it has
     * a limit (3.107.0): an event whose limit was taken off still has people
     * waiting, and they are offered places like any other opening.
     */
    private static function serves( $event_id ) {
        $event_id = (int) $event_id;
        return '1' === (string) get_post_meta( $event_id, '_uc_rsvp_enabled', true )
            && '' === SFAF_Sources::registration_url( $event_id );
    }

    /** Places held by offers not yet answered or run out. */
    public static function held( $event_id, $format = '' ) {
        global $wpdb;
        $table = self::table();
        $sql   = "SELECT COUNT(*) FROM $table WHERE event_id = %d AND status = %s AND offer_expires > %s";
        $args  = array( (int) $event_id, self::OFFERED, current_time( 'mysql' ) );
        if ( SFAF_Online::is_hybrid( (int) $event_id ) ) {
            $sql   .= ' AND format = %s';
            $args[] = (string) $format;
        }
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
    }

    /** People still waiting for an offer. */
    public static function waiting( $event_id, $format = '' ) {
        global $wpdb;
        $table = self::table();
        $sql   = "SELECT COUNT(*) FROM $table WHERE event_id = %d AND status = %s";
        $args  = array( (int) $event_id, self::WAITING );
        if ( SFAF_Online::is_hybrid( (int) $event_id ) ) {
            $sql   .= ' AND format = %s';
            $args[] = (string) $format;
        }
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
    }

    /** Places nobody has and nobody is being offered. */
    private static function free_places( $event_id, $format ) {
        // No limit: a place for everybody waiting (3.107.0).
        if ( ! sfaf_capacity_limited( (int) $event_id, $format ) ) {
            return PHP_INT_MAX;
        }
        $cap = sfaf_event_capacity( (int) $event_id, $format );
        if ( $cap <= 0 ) {
            return 0;
        }
        $taken = SFAF_Online::is_hybrid( (int) $event_id )
            ? sfaf_get_rsvp_count_by_format( (int) $event_id, $format )
            : sfaf_get_rsvp_count( (int) $event_id );
        return max( 0, $cap - $taken - self::held( $event_id, $format ) );
    }

    /**
     * A person's place in the queue: 1 is next. Everybody still waiting for
     * the same event and format who joined first is ahead, offers included.
     */
    public static function position( $rsvp_id ) {
        global $wpdb;
        $table = self::table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, event_id, format, created_at, status FROM $table WHERE id = %d", (int) $rsvp_id ) );
        if ( ! $row || ! in_array( (string) $row->status, self::waiting_statuses(), true ) ) {
            return 0;
        }
        $ahead = 0;
        foreach ( self::queue( (int) $row->event_id, (string) $row->format ) as $r ) {
            if ( (int) $r->id === (int) $row->id ) {
                break;
            }
            $ahead++;
        }
        return $ahead + 1;
    }

    /**
     * Everybody still on the waitlist for one event and format, first first.
     *
     * @return object[]
     */
    public static function queue( $event_id, $format = '' ) {
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %d", (int) $event_id ) );
        $keep  = array();
        foreach ( (array) $rows as $r ) {
            if ( ! in_array( (string) $r->status, self::waiting_statuses(), true ) ) {
                continue;
            }
            if ( SFAF_Online::is_hybrid( (int) $event_id ) && (string) $r->format !== (string) $format ) {
                continue;
            }
            $keep[] = $r;
        }
        usort( $keep, function ( $a, $b ) {
            $c = strcmp( (string) $a->created_at, (string) $b->created_at );
            return 0 !== $c ? $c : ( (int) $a->id - (int) $b->id );
        } );
        return $keep;
    }

    /**
     * Every waitlist row the RSVP list shows, the ones that ran out included,
     * so the list says what happened to an offer.
     *
     * @return object[]
     */
    public static function rows_for_list( $event_id ) {
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %d", (int) $event_id ) );
        $keep  = array();
        foreach ( (array) $rows as $r ) {
            if ( in_array( (string) $r->status, array_merge( self::waiting_statuses(), array( self::EXPIRED ) ), true ) ) {
                $keep[] = $r;
            }
        }
        usort( $keep, function ( $a, $b ) {
            $c = strcmp( (string) $a->created_at, (string) $b->created_at );
            return 0 !== $c ? $c : ( (int) $a->id - (int) $b->id );
        } );
        return $keep;
    }

    /**
     * Put somebody on the waitlist. SFAF_RSVP::submit() has already checked
     * everything a registration checks; it calls this instead of registering
     * when the format is full.
     *
     * @param array $data The cleaned submission.
     * @return array The submit() answer.
     */
    public static function join( $data ) {
        global $wpdb;
        $table    = self::table();
        $event_id = (int) $data['event_id'];

        if ( '' !== (string) $data['email'] ) {
            $already = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE event_id = %d AND email = %s AND status IN ('waitlisted','offered','offered_manual')",
                $event_id,
                (string) $data['email']
            ) );
            if ( $already > 0 ) {
                return array( 'success' => false, 'message' => 'You are already on the waitlist for this event.' );
            }
        }

        $token = SFAF_Reminders::new_token();
        $ok    = $wpdb->insert( $table, array(
            'event_id'   => $event_id,
            'name'       => $data['name'],
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $data['email'],
            'phone'      => $data['phone'],
            'status'     => self::WAITING,
            'token'      => $token,
            'created_at' => current_time( 'mysql' ),
            'format'     => $data['format'],
        ) );
        if ( ! $ok ) {
            return array( 'success' => false, 'message' => 'Something went wrong. Please try again.' );
        }
        $id       = (int) $wpdb->insert_id;
        $position = self::position( $id );

        if ( '' !== (string) $data['email'] ) {
            $person = (object) array(
                'name' => $data['name'], 'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                'email' => $data['email'], 'token' => $token, 'format' => $data['format'],
            );
            $built = SFAF_Notifications::build( 'waitlist', $event_id, $person, array( 'position' => $position ) );
            if ( $built ) {
                SFAF_Email::send( (string) $data['email'], $built['subject'], $built['html'], $built['text'], SFAF_Reminders::reply_to_for( $event_id ) );
            }
        }

        return array(
            'success'    => true,
            'waitlisted' => true,
            'rsvp_id'    => $id,
            'position'   => $position,
            'first_name' => $data['first_name'],
            'no_email'   => ( '' === (string) $data['email'] ),
            'message'    => sprintf( 'You are on the waitlist. You are number %d.', $position ),
        );
    }

    /* ---------------------------------------------------------------------
     * A place opens
     * ------------------------------------------------------------------- */

    /**
     * uc_rsvp_cancelled: a registration was released, by its own link or by
     * Remove. Offer the place in the format it was in.
     */
    public static function on_released( $event_id, $email = '', $rsvp_id = 0 ) {
        global $wpdb;
        $table  = self::table();
        $format = '';
        if ( (int) $rsvp_id > 0 ) {
            $format = (string) $wpdb->get_var( $wpdb->prepare( "SELECT format FROM $table WHERE id = %d", (int) $rsvp_id ) );
        } elseif ( '' !== (string) $email ) {
            $format = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT format FROM $table WHERE event_id = %d AND email = %s ORDER BY id DESC LIMIT 1", (int) $event_id, (string) $email ) );
        }
        self::advance( (int) $event_id, self::format_for( $event_id, $format ) );
    }

    /** Capacity raised, or taken off (3.107.0): every format may have places now. */
    public static function advance_all( $event_id ) {
        foreach ( sfaf_event_formats( (int) $event_id ) as $format ) {
            self::advance( (int) $event_id, self::format_for( $event_id, $format ) );
        }
    }

    /**
     * Offer free places to the waitlist, first come first served.
     *
     * WITH NO LIMIT (3.107.0), every place is free, so everybody still waiting
     * is offered one, one offer each, in waitlist order, through the same
     * offer() and the same manual alert as a raised limit. The guard is the
     * queue's length, so a long waitlist is not cut off at a hundred.
     *
     * @return int How many offers went out by email.
     */
    public static function advance( $event_id, $format = '' ) {
        $event_id = (int) $event_id;
        if ( ! self::serves( $event_id ) || SFAF_Cancellation::is_cancelled( $event_id ) ) {
            return 0;
        }
        $sent  = 0;
        $limit = max( 100, count( self::queue( $event_id, $format ) ) + 1 );
        for ( $guard = 0; $guard < $limit; $guard++ ) {
            if ( self::free_places( $event_id, $format ) <= 0 ) {
                break;
            }
            $next = null;
            foreach ( self::queue( $event_id, $format ) as $r ) {
                if ( self::WAITING === (string) $r->status ) {
                    $next = $r;
                    break;
                }
            }
            if ( ! $next ) {
                break;
            }
            if ( '' === trim( (string) $next->email ) ) {
                self::mark( (int) $next->id, array( 'status' => self::MANUAL, 'offered_at' => current_time( 'mysql' ) ) );
                self::alert_manual( $event_id, $next );
                continue;
            }
            self::offer( $event_id, $next );
            $sent++;
        }
        return $sent;
    }

    /**
     * How long an offer lasts from now: 24 hours, or 2 when the event starts
     * within 24. Both sides are the site's wall clock, the way every time in
     * this table is stored, so they compare without a zone.
     */
    public static function window_hours( $event_id ) {
        $dt = sfaf_event_datetimes( (int) $event_id );
        if ( $dt ) {
            $gap = strtotime( $dt[0]->format( 'Y-m-d H:i:s' ) ) - strtotime( current_time( 'mysql' ) );
            if ( $gap <= self::HOURS * HOUR_IN_SECONDS ) {
                return self::HOURS_SOON;
            }
        }
        return self::HOURS;
    }

    private static function offer( $event_id, $row ) {
        $token   = SFAF_Reminders::new_token();
        $now     = current_time( 'mysql' );
        $expires = date( 'Y-m-d H:i:s', strtotime( $now ) + self::window_hours( $event_id ) * HOUR_IN_SECONDS );
        self::mark( (int) $row->id, array(
            'status'        => self::OFFERED,
            'offer_token'   => $token,
            'offered_at'    => $now,
            'offer_expires' => $expires,
        ) );
        $built = SFAF_Notifications::build( 'offer', $event_id, $row, array(
            'confirm_url' => self::confirm_url( $token ),
            'expires'     => $expires,
        ) );
        if ( $built ) {
            SFAF_Email::send( (string) $row->email, $built['subject'], $built['html'], $built['text'], SFAF_Reminders::reply_to_for( $event_id ) );
        }
    }

    /**
     * Tell the notification list about somebody who cannot be offered a place
     * by email. English, like every staff message.
     */
    private static function alert_manual( $event_id, $row ) {
        $title = get_the_title( $event_id );
        $name  = SFAF_RSVP::display_name( $row );
        $phone = trim( (string) $row->phone );
        $when  = sfaf_ap_date( (string) get_post_meta( $event_id, '_uc_event_date', true ), 'full' );
        $list  = add_query_arg( 'event_id', (int) $event_id, SFAF_Portal::link( 'rsvps' ) );

        $subject = sprintf( 'Waitlist: a place for %s needs a phone call', $title );
        $lines   = array(
            sprintf( 'A place has opened for %s on %s.', $title, $when ),
            sprintf( 'The next person on the waitlist, %s, gave no email address, so the place cannot be offered by email.', $name ),
            '' !== $phone ? sprintf( 'Phone: %s', $phone ) : 'No phone number was given either.',
            'If they take it, confirm them from the registrations list. The offer has moved on to the next person with an email.',
        );
        $html  = SFAF_Email::heading( 'A waitlisted person needs a call.' );
        foreach ( $lines as $l ) {
            $html .= SFAF_Email::para( $l );
        }
        $html .= SFAF_Email::button( $list, 'Open the registrations list', 'primary' );
        $text  = implode( "\n\n", $lines ) . "\n\nRegistrations: " . $list . "\n\n" . SFAF_Email::POSTAL;

        foreach ( SFAF_Notifications::staff( $event_id ) as $to => $label ) {
            SFAF_Email::send( $to, $subject, SFAF_Email::shell( $subject, $html ), $text );
        }
    }

    private static function mark( $rsvp_id, $fields ) {
        global $wpdb;
        $wpdb->update( self::table(), $fields, array( 'id' => (int) $rsvp_id ) );
        $event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM ' . self::table() . ' WHERE id = %d', (int) $rsvp_id ) );
        if ( $event ) {
            sfaf_clear_rsvp_count_cache( $event );
        }
    }

    /* ---------------------------------------------------------------------
     * Offers that run out
     * ------------------------------------------------------------------- */

    /**
     * On the existing cron: every offer past its time becomes expired, the
     * person is told, and the place moves on.
     *
     * @return array{expired:int}
     */
    public static function run_expiry() {
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = %s", self::OFFERED ) );
        $now   = current_time( 'mysql' );
        $n     = 0;
        foreach ( (array) $rows as $r ) {
            if ( '' === (string) $r->offer_expires || (string) $r->offer_expires > $now ) {
                continue;
            }
            // An event that is off: the person was told, or the link says so.
            // No "offer passed" after it, and the row is left as it is.
            if ( '' !== self::offer_blocked( (int) $r->event_id ) ) {
                continue;
            }
            self::expire( $r );
            $n++;
        }
        return array( 'expired' => $n );
    }

    private static function expire( $row ) {
        self::mark( (int) $row->id, array( 'status' => self::EXPIRED ) );
        $built = SFAF_Notifications::build( 'offer_passed', (int) $row->event_id, $row, array() );
        if ( $built && '' !== trim( (string) $row->email ) ) {
            SFAF_Email::send( (string) $row->email, $built['subject'], $built['html'], $built['text'], SFAF_Reminders::reply_to_for( (int) $row->event_id ) );
        }
        self::advance( (int) $row->event_id, self::format_for( $row->event_id, $row->format ) );
    }

    /* ---------------------------------------------------------------------
     * Confirming
     * ------------------------------------------------------------------- */

    /**
     * Whether an offer on this event can no longer be taken (3.106.2):
     * 'cancelled', 'unavailable' when the event is gone, in the bin, private or
     * not published, else ''. The confirm link and the expiry both ask, and
     * neither changes the row when the answer is not ''.
     */
    public static function offer_blocked( $event_id ) {
        $post = get_post( (int) $event_id );
        if ( ! $post || 'publish' !== $post->post_status || SFAF_Privacy::is_private( (int) $event_id ) ) {
            return 'unavailable';
        }
        return SFAF_Cancellation::is_cancelled( (int) $event_id ) ? 'cancelled' : '';
    }

    public static function confirm_url( $token ) {
        return add_query_arg( self::ARG, $token, home_url( '/' ) );
    }

    /**
     * Accept an offer by its token.
     *
     * @return string 'done', 'gone', 'invalid', or 'cancelled' or 'unavailable'
     *                when the event is off, which changes nothing.
     */
    public static function accept( $token ) {
        $row = self::by_offer_token( $token );
        if ( ! $row ) {
            return 'invalid';
        }
        $off = self::offer_blocked( (int) $row->event_id );
        if ( '' !== $off ) {
            return $off;
        }
        if ( self::OFFERED !== (string) $row->status ) {
            return ( 'confirmed' === (string) $row->status ) ? 'done' : 'gone';
        }
        if ( (string) $row->offer_expires <= current_time( 'mysql' ) ) {
            // The cron has not reached it yet; the time has passed all the same.
            self::expire( $row );
            return 'gone';
        }
        self::admit( $row );
        return 'done';
    }

    /**
     * Staff confirm somebody from the RSVP list: any waiting row, with or
     * without an email, whatever the count says.
     *
     * @return bool
     */
    public static function staff_confirm( $rsvp_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $rsvp_id ) );
        if ( ! $row || ! in_array( (string) $row->status, array_merge( self::waiting_statuses(), array( self::EXPIRED ) ), true ) ) {
            return false;
        }
        self::admit( $row );
        return true;
    }

    /**
     * Move a row in: confirmed, and the normal confirmation with the calendar
     * file and, for online, the meeting link under the delivery rules, then the
     * registration alert, exactly as a registration sends them.
     */
    private static function admit( $row ) {
        self::mark( (int) $row->id, array( 'status' => 'confirmed', 'offer_token' => '' ) );
        $person = (object) array(
            'name' => (string) $row->name, 'first_name' => (string) $row->first_name, 'last_name' => (string) $row->last_name,
            'email' => (string) $row->email, 'token' => (string) $row->token, 'format' => (string) $row->format,
            // The same row they answered on when they joined (3.106.2).
            'rsvp_id' => (int) $row->id,
        );
        if ( '' !== $person->email && SFAF_RSVP::confirmations_enabled() && SFAF_Notifications::on( (int) $row->event_id, 'confirmation' ) ) {
            SFAF_Notifications::send_confirmation( (int) $row->event_id, $person );
        }
        SFAF_Notifications::send_alert( (int) $row->event_id, $person );
    }

    /**
     * Leave the waitlist: by the link in the waitlist confirmation. A held
     * offer given up is offered on.
     */
    public static function leave( $rsvp_id, $by = 0 ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $rsvp_id ) );
        if ( ! $row || ! in_array( (string) $row->status, array_merge( self::waiting_statuses(), array( self::EXPIRED ) ), true ) ) {
            return false;
        }
        self::mark( (int) $row->id, array( 'status' => 'cancelled', 'cancelled_at' => current_time( 'mysql' ), 'removed_by' => (int) $by, 'offer_token' => '' ) );
        if ( self::OFFERED === (string) $row->status ) {
            self::advance( (int) $row->event_id, self::format_for( $row->event_id, $row->format ) );
        }
        return true;
    }

    private static function by_offer_token( $token ) {
        global $wpdb;
        $token = trim( (string) $token );
        if ( '' === $token ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE offer_token = %s AND offer_token <> '' LIMIT 1", $token ) );
    }

    /**
     * The confirm link. A GET shows a page that asks; the POST confirms, for
     * the reason the cancel link does: mail clients and scanners fetch links
     * with nobody clicking.
     */
    public static function maybe_handle_offer() {
        $token = isset( $_GET[ self::ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::ARG ] ) ) : '';
        if ( '' === $token ) {
            return;
        }
        $row = self::by_offer_token( $token );
        if ( ! $row ) {
            sfaf_notice_page( 'That link is not valid', '<p>' . esc_html( 'This link has expired or was not recognized.' ) . '</p>' );
        }
        $event_id = (int) $row->event_id;
        $lang     = sfaf_event_language( $event_id );
        $values   = SFAF_Reminders::page_values( $event_id, $lang );
        $values['expiry'] = sfaf_ap_datetime( (string) $row->offer_expires, 'full', $lang, true );

        // AN EVENT THAT IS OFF (3.106.2): a page saying so, GET or POST, and
        // nothing else. The row is not touched.
        $off = self::offer_blocked( $event_id );
        if ( '' !== $off ) {
            $p = SFAF_Reminders::page_parts( 'offer_off_page', $off, $lang, $values );
            sfaf_notice_page( $p['title'], $p['html'], $lang );
        }

        $posted = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']
            && isset( $_POST['uc_offer_token'] ) && hash_equals( $token, sanitize_text_field( wp_unslash( $_POST['uc_offer_token'] ) ) );

        if ( $posted ) {
            $result = self::accept( $token );
            $p = SFAF_Reminders::page_parts( 'offer_page', 'done' === $result ? 'done' : 'gone', $lang, $values );
            sfaf_notice_page( $p['title'], $p['html'], $lang );
        }
        $live = ( self::OFFERED === (string) $row->status && (string) $row->offer_expires > current_time( 'mysql' ) );
        $p = SFAF_Reminders::page_parts( 'offer_page', $live ? 'ask' : ( 'confirmed' === (string) $row->status ? 'done' : 'gone' ), $lang, $values, array( 'token' => $token ) );
        sfaf_notice_page( $p['title'], $p['html'], $lang );
    }
}
