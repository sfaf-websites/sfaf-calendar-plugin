<?php
/**
 * THE WAITLIST (3.106.0; places go straight to the next person since 3.110.0).
 *
 * When an event's capacity is full, the RSVP form offers "Join the waitlist"
 * instead of registering. A waitlisted person is a row in uc_rsvps with a
 * status of its own, ordered by when they joined, per format on a hybrid event.
 *
 *   waitlisted      in the queue
 *   confirmed       in, added when a place opened, or by staff
 *   cancelled       left the waitlist, or removed by staff
 *
 * and, from before 3.110.0 only:
 *
 *   offered         a place was held for them; read as waitlisted, and turned
 *                   back into waitlisted by migrate() on the first cron run
 *   offered_manual  the same, for somebody with no email
 *   expired         their offer ran out; off the queue, shown on the list
 *
 * EVERY COUNT STILL ASKS FOR 'confirmed'. The reminder, announcement and
 * summary audiences, has_registrations() and the count cache never see a row
 * in any other status, so nobody on the waitlist is ever sent a reminder or
 * counted as coming. What changes is only whether a format is FULL:
 * sfaf_format_full() treats anybody still waiting as full, so a newcomer
 * cannot jump the queue. See waiting().
 *
 * A PLACE OPENS four ways, and each calls advance(): a cancellation through
 * the link, a Remove from the RSVP list (both fire uc_rsvp_cancelled), and
 * capacity raised or emptied in the editor. advance() moves the first person
 * waiting for that format straight to confirmed and sends the normal
 * confirmation, calendar file and meeting link under the delivery rules, with
 * one more line saying they came off the waitlist. One place, one person:
 * it stops the moment the format has no free place. Somebody with no email is
 * added the same way, and the notification list is told they were added and
 * could not be told, with their phone number.
 *
 * NO OFFERS ANY MORE (3.110.0). There is no offer email, no confirm page, no
 * 24-hour or 2-hour window, no expiry run and no "offer passed" message.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Waitlist {

    const WAITING = 'waitlisted';
    /** From before 3.110.0; see migrate(). */
    const OFFERED = 'offered';
    const MANUAL  = 'offered_manual';
    const EXPIRED = 'expired';

    /** Set once migrate() has turned the old offers back into the queue. */
    const MIGRATED_OPTION = 'sfaf_waitlist_offers_migrated';

    public function register() {
        // After the cancel alert at 10, so the list is told first.
        add_action( 'uc_rsvp_cancelled', array( __CLASS__, 'on_released' ), 20, 3 );
    }

    /**
     * The statuses of somebody still on the waitlist. The two offer statuses
     * are here until migrate() has run, so a row caught mid-offer at the
     * update keeps its place in the queue.
     */
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
     * Whether places can be given at all: it takes RSVPs here. NOT whether it
     * has a limit (3.107.0): an event whose limit was taken off still has
     * people waiting, and they are added like any other opening.
     */
    private static function serves( $event_id ) {
        $event_id = (int) $event_id;
        return '1' === (string) get_post_meta( $event_id, '_uc_rsvp_enabled', true )
            && '' === SFAF_Sources::registration_url( $event_id );
    }

    /** People still waiting. */
    public static function waiting( $event_id, $format = '' ) {
        return count( self::queue( (int) $event_id, $format ) );
    }

    /** Places nobody has. */
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
        return max( 0, $cap - $taken );
    }

    /**
     * A person's place in the queue: 1 is next. Everybody still waiting for
     * the same event and format who joined first is ahead.
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
     * Every waitlist row the RSVP list shows: everybody waiting, and anybody
     * whose offer ran out before 3.110.0, so the list still says so.
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
     * everything a registration checks, the agreement included; it calls this
     * instead of registering when the format is full.
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
        $row   = array(
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
        );
        // The agreement and the text opt-in travel with a waitlist join (3.110.0).
        foreach ( array( 'agreed_at', 'text_opt_in' ) as $k ) {
            if ( isset( $data[ $k ] ) && '' !== (string) $data[ $k ] ) {
                $row[ $k ] = $data[ $k ];
            }
        }
        $ok = $wpdb->insert( $table, $row );
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
     * Remove. The place goes to the next person waiting in that format.
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
     * Move people off the waitlist into the free places, first come first
     * served, one person for each free place and no more.
     *
     * WITH NO LIMIT (3.107.0) every place is free, so everybody still waiting
     * is added. The guard is the queue's length, so a long waitlist is not cut
     * off at a hundred.
     *
     * @return int How many people were added.
     */
    public static function advance( $event_id, $format = '' ) {
        $event_id = (int) $event_id;
        if ( ! self::serves( $event_id ) || SFAF_Cancellation::is_cancelled( $event_id ) ) {
            return 0;
        }
        $added = 0;
        $limit = max( 100, count( self::queue( $event_id, $format ) ) + 1 );
        for ( $guard = 0; $guard < $limit; $guard++ ) {
            if ( self::free_places( $event_id, $format ) <= 0 ) {
                break;
            }
            $queue = self::queue( $event_id, $format );
            if ( ! $queue ) {
                break;
            }
            self::admit( $queue[0], true );
            $added++;
        }
        return $added;
    }

    /**
     * Tell the notification list about somebody added from the waitlist who
     * has no email, so could not be told. English, like every staff message.
     */
    private static function alert_added_untold( $event_id, $row ) {
        $title = get_the_title( $event_id );
        $name  = SFAF_RSVP::display_name( $row );
        $phone = trim( (string) $row->phone );
        $when  = sfaf_ap_date( (string) get_post_meta( $event_id, '_uc_event_date', true ), 'full' );
        $list  = add_query_arg( 'event_id', (int) $event_id, SFAF_Portal::link( 'rsvps' ) );

        $subject = sprintf( 'Waitlist: %s was added and needs a call', $title );
        $lines   = array(
            sprintf( 'A place opened for %s on %s, and %s, next on the waitlist, has been added to the registrations.', $title, $when, $name ),
            'They gave no email address, so they could not be told. Call them to let them know.',
            '' !== $phone ? sprintf( 'Phone: %s', $phone ) : 'No phone number was given either.',
        );
        $html = SFAF_Email::heading( 'A waitlisted person was added and needs a call.' );
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
     * The offers from before 3.110.0
     * ------------------------------------------------------------------- */

    /**
     * Once, on the first cron run after the update: every row still offered,
     * by email or by phone, goes back into the queue where it was, and each
     * event they belong to is advanced under the new rule. Expired rows are
     * left as they are.
     *
     * @return array{migrated:int}
     */
    public static function migrate() {
        if ( get_option( self::MIGRATED_OPTION ) ) {
            return array( 'migrated' => 0 );
        }
        global $wpdb;
        $table  = self::table();
        $rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, event_id FROM $table WHERE status IN (%s, %s)", self::OFFERED, self::MANUAL ) );
        $events = array();
        foreach ( (array) $rows as $r ) {
            self::mark( (int) $r->id, array( 'status' => self::WAITING ) );
            $events[ (int) $r->event_id ] = true;
        }
        foreach ( array_keys( $events ) as $event_id ) {
            self::advance_all( $event_id );
        }
        update_option( self::MIGRATED_OPTION, current_time( 'mysql' ), false );
        return array( 'migrated' => count( (array) $rows ) );
    }

    /* ---------------------------------------------------------------------
     * Moving a person in
     * ------------------------------------------------------------------- */

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
        self::admit( $row, false );
        return true;
    }

    /**
     * Move a row in: confirmed, and the normal confirmation with the calendar
     * file and, for online, the meeting link under the delivery rules, then the
     * registration alert, exactly as a registration sends them. A place that
     * opened adds the waitlist line to the confirmation; with no email to send
     * it to, the list is told they were added and could not be told.
     *
     * @param object $row
     * @param bool   $opened True when a place opened, false when staff confirmed.
     */
    private static function admit( $row, $opened ) {
        self::mark( (int) $row->id, array( 'status' => 'confirmed', 'offer_token' => '' ) );
        $person = (object) array(
            'name' => (string) $row->name, 'first_name' => (string) $row->first_name, 'last_name' => (string) $row->last_name,
            'email' => (string) $row->email, 'token' => (string) $row->token, 'format' => (string) $row->format,
            // The same row they answered on when they joined (3.106.2).
            'rsvp_id' => (int) $row->id,
            'from_waitlist' => (bool) $opened,
        );
        if ( '' !== $person->email && SFAF_RSVP::confirmations_enabled() && SFAF_Notifications::on( (int) $row->event_id, 'confirmation' ) ) {
            SFAF_Notifications::send_confirmation( (int) $row->event_id, $person );
        } elseif ( $opened && '' === trim( $person->email ) ) {
            self::alert_added_untold( (int) $row->event_id, $row );
        }
        SFAF_Notifications::send_alert( (int) $row->event_id, $person );
    }

    /**
     * Leave the waitlist: by the link in the waitlist confirmation.
     */
    public static function leave( $rsvp_id, $by = 0 ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $rsvp_id ) );
        if ( ! $row || ! in_array( (string) $row->status, array_merge( self::waiting_statuses(), array( self::EXPIRED ) ), true ) ) {
            return false;
        }
        self::mark( (int) $row->id, array( 'status' => 'cancelled', 'cancelled_at' => current_time( 'mysql' ), 'removed_by' => (int) $by, 'offer_token' => '' ) );
        return true;
    }
}
