<?php
/**
 * EMAIL REGISTRANTS, FROM THE RSVP LIST (3.110.0).
 *
 * One event's RSVP list has a closed "Email registrants" section: a subject,
 * a body in the Email Templates editor with its six token chips, "Include the
 * waitlist", Preview and Send. The message is SFAF_Notifications::build(
 * 'registrant_message' ), the same wrapper and details block as every other
 * message, in the event's language, with the event's Reply-To, through
 * SFAF_Email::send(), one message to each person.
 *
 * WHO IS SENT IT: everybody registered with an email, and everybody waiting
 * when the box is ticked, once per address. A row with no email cannot be
 * reached: it is counted and named in the line the send leaves, and sent
 * nothing.
 *
 * THE LOG is post meta on the event, newest first: who sent, when, the
 * subject and how many it went to.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Registrant_Mail {

    const LOG_META = '_uc_registrant_mail_log';

    /** The tokens the body and subject may use. */
    public static function tokens() {
        return array( 'first_name', 'title', 'date', 'time', 'location', 'event_link' );
    }

    /**
     * Who a send reaches, and who it cannot.
     *
     * @return array{to:object[],unreachable:object[]} Rows, one per address in 'to'.
     */
    public static function recipients( $event_id, $include_waitlist ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'uc_rsvps';
        $rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %d", (int) $event_id ) );
        $statuses = array( 'confirmed' );
        if ( $include_waitlist ) {
            $statuses = array_merge( $statuses, SFAF_Waitlist::waiting_statuses() );
        }
        $to = array(); $none = array(); $seen = array();
        foreach ( (array) $rows as $r ) {
            if ( ! in_array( (string) $r->status, $statuses, true ) ) {
                continue;
            }
            $email = strtolower( trim( (string) $r->email ) );
            if ( '' === $email || ! is_email( $email ) ) {
                $none[] = $r;
                continue;
            }
            if ( isset( $seen[ $email ] ) ) {
                continue;
            }
            $seen[ $email ] = true;
            $to[] = $r;
        }
        return array( 'to' => $to, 'unreachable' => $none );
    }

    /** The {tokens} in a text that the message cannot fill. */
    public static function unknown_tokens( $text ) {
        preg_match_all( '/\{([a-z_]+)\}/', (string) $text, $m );
        return array_values( array_diff( array_unique( $m[1] ), self::tokens() ) );
    }

    /** The person a row is, as the builders read one. */
    private static function person( $row ) {
        return (object) array(
            'name' => (string) $row->name, 'first_name' => (string) $row->first_name, 'last_name' => (string) $row->last_name,
            'email' => (string) $row->email, 'token' => (string) $row->token, 'format' => (string) $row->format,
            'rsvp_id' => (int) $row->id,
        );
    }

    /**
     * The message as the first recipient would get it, or null with nobody to
     * send to.
     *
     * @return array|null subject, html, text, and to: the address it is for.
     */
    public static function preview( $event_id, $subject, $body, $include_waitlist ) {
        $r = self::recipients( $event_id, $include_waitlist );
        if ( empty( $r['to'] ) ) {
            return null;
        }
        $first = $r['to'][0];
        $built = SFAF_Notifications::build( 'registrant_message', (int) $event_id, self::person( $first ), array( 'subject' => $subject, 'body' => $body ) );
        if ( ! $built ) {
            return null;
        }
        $built['to'] = (string) $first->email;
        return $built;
    }

    /**
     * Send to everybody it reaches, one message each, and log it.
     *
     * @return array{sent:int,unreachable:string[]} The names of those with no email.
     */
    public static function send( $event_id, $subject, $body, $include_waitlist, $by_user_id ) {
        $event_id = (int) $event_id;
        $r        = self::recipients( $event_id, $include_waitlist );
        $reply    = SFAF_Reminders::reply_to_for( $event_id );
        $sent     = 0;
        foreach ( $r['to'] as $row ) {
            $built = SFAF_Notifications::build( 'registrant_message', $event_id, self::person( $row ), array( 'subject' => $subject, 'body' => $body ) );
            if ( $built && SFAF_Email::send( (string) $row->email, $built['subject'], $built['html'], $built['text'], $reply ) ) {
                $sent++;
            }
        }
        $names = array();
        foreach ( $r['unreachable'] as $row ) {
            $n = SFAF_RSVP::display_name( $row );
            $names[] = '' !== $n ? $n : 'Somebody with no name';
        }
        $log = self::log( $event_id );
        array_unshift( $log, array(
            'by'      => (int) $by_user_id,
            'at'      => current_time( 'mysql' ),
            'subject' => (string) $subject,
            'count'   => $sent,
        ) );
        update_post_meta( $event_id, self::LOG_META, $log );
        return array( 'sent' => $sent, 'unreachable' => $names );
    }

    /** Every send from this event's list, newest first. */
    public static function log( $event_id ) {
        $log = get_post_meta( (int) $event_id, self::LOG_META, true );
        return is_array( $log ) ? $log : array();
    }
}
