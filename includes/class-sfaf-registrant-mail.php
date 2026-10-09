<?php
/**
 * EMAIL REGISTRANTS, FROM THE RSVP LIST (3.110.0; the team and the organizer's
 * copy since 3.110.2).
 *
 * One event's RSVP list has an "Email registrants" panel: a subject, a body in
 * the Email Templates editor with its token chips plus bold and links,
 * "Include the waitlist", "Include event team", Preview and Send. The message
 * is SFAF_Notifications::build( 'registrant_message' ): the subject as the
 * heading, the body's paragraphs under it, the event's details and the event
 * page button, in the standard wrapper, in the event's language, with the
 * event's Reply-To, through SFAF_Email::send(), one message to each person.
 *
 * WHO IS SENT IT, ONCE PER ADDRESS:
 *   participants  everybody registered with an email, and everybody waiting
 *                 when "Include the waitlist" is ticked
 *   team          with "Include event team", every person Team and access
 *                 gives the event (its teams' members and the people named),
 *                 who has an email and is not already a participant
 *   organizer     the event's creator, always, one copy sent with the batch,
 *                 unless already one of the above
 * A row with no email cannot be reached: it is counted and named in the line
 * the send leaves, and sent nothing.
 *
 * THE LOG is post meta on the event, newest first: who sent, when, the
 * subject, and how many of each.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Registrant_Mail {

    const LOG_META = '_uc_registrant_mail_log';

    /** The tokens the body and subject may use. */
    public static function tokens() {
        return array( 'first_name', 'title', 'date', 'time', 'location', 'event_link' );
    }

    /** The markup a body may keep: bold, italics and links. Everything else goes. */
    public static function allowed_tags() {
        return array( 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'a' => array( 'href' => array() ) );
    }

    /** A body as typed, with only the markup it may keep, paragraphs intact. */
    public static function clean_body( $raw ) {
        $raw = str_replace( array( "\r\n", "\r" ), "\n", (string) $raw );
        return trim( force_balance_tags( wp_kses( $raw, self::allowed_tags() ) ) );
    }

    /**
     * Who a send reaches, and who it cannot. Participants only: see plan()
     * for the team and the organizer.
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

    /** A WordPress account as the builders read a person. Null without an address. */
    private static function account( $user_id ) {
        $u = get_userdata( (int) $user_id );
        if ( ! $u || ! is_email( (string) $u->user_email ) ) {
            return null;
        }
        $name  = trim( (string) $u->display_name );
        $first = trim( (string) get_user_meta( (int) $user_id, 'first_name', true ) );
        if ( '' === $first ) {
            $bits  = preg_split( '/\s+/', $name );
            $first = (string) $bits[0];
        }
        return (object) array(
            'name' => $name, 'first_name' => $first, 'last_name' => '', 'email' => strtolower( trim( (string) $u->user_email ) ),
            'token' => '', 'format' => '', 'rsvp_id' => 0, 'user_id' => (int) $user_id,
        );
    }

    /** Everybody Team and access gives the event, by team or by name, as accounts with an address. */
    public static function team( $event_id ) {
        $ids = SFAF_Access::people( $event_id );
        $all = SFAF_Teams::all();
        foreach ( SFAF_Access::teams( $event_id ) as $tid ) {
            if ( isset( $all[ $tid ] ) ) {
                $ids = array_merge( $ids, array_map( 'intval', (array) $all[ $tid ]['users'] ) );
            }
        }
        $out = array();
        foreach ( array_unique( $ids ) as $uid ) {
            $p = self::account( $uid );
            if ( $p && ! isset( $out[ $p->email ] ) ) {
                $out[ $p->email ] = $p;
            }
        }
        return array_values( $out );
    }

    /**
     * Everybody one send reaches, each address once, in three groups.
     *
     * @return array{participants:object[],team:object[],organizer:?object,unreachable:object[]}
     */
    public static function plan( $event_id, $include_waitlist, $include_team ) {
        $r    = self::recipients( $event_id, $include_waitlist );
        $seen = array();
        $participants = array();
        foreach ( $r['to'] as $row ) {
            $participants[] = self::person( $row );
            $seen[ strtolower( trim( (string) $row->email ) ) ] = true;
        }
        $team = array();
        if ( $include_team ) {
            foreach ( self::team( $event_id ) as $p ) {
                if ( ! isset( $seen[ $p->email ] ) ) {
                    $team[] = $p;
                    $seen[ $p->email ] = true;
                }
            }
        }
        // The organizer, always, once: not when already one of the above.
        $post      = get_post( (int) $event_id );
        $organizer = $post ? self::account( (int) $post->post_author ) : null;
        if ( $organizer && isset( $seen[ $organizer->email ] ) ) {
            $organizer = null;
        }
        return array( 'participants' => $participants, 'team' => $team, 'organizer' => $organizer, 'unreachable' => $r['unreachable'] );
    }

    /** How many messages a send makes. */
    public static function count_plan( $plan ) {
        return count( $plan['participants'] ) + count( $plan['team'] ) + ( $plan['organizer'] ? 1 : 0 );
    }

    /** The {tokens} in a text that the message cannot fill. */
    public static function unknown_tokens( $text ) {
        preg_match_all( '/\{([a-z_]+)\}/', (string) $text, $m );
        return array_values( array_diff( array_unique( $m[1] ), self::tokens() ) );
    }

    /** The person a registration row is, as the builders read one. */
    private static function person( $row ) {
        return (object) array(
            'name' => (string) $row->name, 'first_name' => (string) $row->first_name, 'last_name' => (string) $row->last_name,
            'email' => (string) $row->email, 'token' => (string) $row->token, 'format' => (string) $row->format,
            'rsvp_id' => (int) $row->id,
        );
    }

    private static function build( $event_id, $person, $subject, $body ) {
        return SFAF_Notifications::build( 'registrant_message', (int) $event_id, $person, array( 'subject' => $subject, 'body' => self::clean_body( $body ) ) );
    }

    /**
     * The message as the first recipient would get it, or null with nobody to
     * send to.
     *
     * @return array|null subject, html, text, and to: the address it is for.
     */
    public static function preview( $event_id, $subject, $body, $include_waitlist, $include_team = false ) {
        $plan  = self::plan( $event_id, $include_waitlist, $include_team );
        $first = $plan['participants'] ? $plan['participants'][0] : ( $plan['team'] ? $plan['team'][0] : $plan['organizer'] );
        if ( ! $first ) {
            return null;
        }
        $built = self::build( $event_id, $first, $subject, $body );
        if ( ! $built ) {
            return null;
        }
        $built['to'] = (string) $first->email;
        return $built;
    }

    /**
     * Send to everybody it reaches, one message each, and log it.
     *
     * @return array{sent:int,team:int,organizer:string,unreachable:string[]}
     *         sent: participants; organizer: the name the copy went to, or ''.
     */
    public static function send( $event_id, $subject, $body, $include_waitlist, $by_user_id, $include_team = false ) {
        $event_id = (int) $event_id;
        $plan     = self::plan( $event_id, $include_waitlist, $include_team );
        $reply    = SFAF_Reminders::reply_to_for( $event_id );
        $go = function ( $person ) use ( $event_id, $subject, $body, $reply ) {
            $built = self::build( $event_id, $person, $subject, $body );
            return $built && SFAF_Email::send( (string) $person->email, $built['subject'], $built['html'], $built['text'], $reply );
        };
        $sent = 0; $team = 0; $org = '';
        foreach ( $plan['participants'] as $p ) {
            if ( $go( $p ) ) { $sent++; }
        }
        foreach ( $plan['team'] as $p ) {
            if ( $go( $p ) ) { $team++; }
        }
        if ( $plan['organizer'] && $go( $plan['organizer'] ) ) {
            $org = '' !== $plan['organizer']->name ? $plan['organizer']->name : $plan['organizer']->email;
        }
        $names = array();
        foreach ( $plan['unreachable'] as $row ) {
            $n = SFAF_RSVP::display_name( $row );
            $names[] = '' !== $n ? $n : 'Somebody with no name';
        }
        $log = self::log( $event_id );
        array_unshift( $log, array(
            'by'        => (int) $by_user_id,
            'at'        => current_time( 'mysql' ),
            'subject'   => (string) $subject,
            'count'     => $sent + $team + ( '' !== $org ? 1 : 0 ),
            'sent'      => $sent,
            'team'      => $team,
            'organizer' => $org,
        ) );
        update_post_meta( $event_id, self::LOG_META, $log );
        return array( 'sent' => $sent, 'team' => $team, 'organizer' => $org, 'unreachable' => $names );
    }

    /**
     * "Sent to 12 participants, 5 team members, and a copy to Pat Example."
     * Also the words for a log row, without "Sent to".
     */
    public static function said( $r, $lead = true ) {
        $bits = array();
        // A log row from 3.110.0 holds only 'count', which was all participants.
        $n = isset( $r['sent'] ) ? (int) $r['sent'] : (int) ( $r['count'] ?? 0 );
        $bits[] = $n . ' ' . ( 1 === $n ? 'participant' : 'participants' );
        $t = (int) ( $r['team'] ?? 0 );
        if ( $t ) {
            $bits[] = $t . ' ' . ( 1 === $t ? 'team member' : 'team members' );
        }
        if ( ! empty( $r['organizer'] ) ) {
            $bits[] = 'a copy to ' . $r['organizer'];
        }
        $last = array_pop( $bits );
        if ( ! $bits ) {
            $words = $last;
        } elseif ( 1 === count( $bits ) ) {
            $words = $bits[0] . ' and ' . $last;
        } else {
            $words = implode( ', ', $bits ) . ', and ' . $last;
        }
        return $lead ? 'Sent to ' . $words . '.' : $words;
    }

    /** Every send from this event's list, newest first. */
    public static function log( $event_id ) {
        $log = get_post_meta( (int) $event_id, self::LOG_META, true );
        return is_array( $log ) ? $log : array();
    }
}
