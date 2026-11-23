<?php
/**
 * REPLIES TO TEXTS AND WHATSAPP MESSAGES (3.144.0).
 *
 * EVERY MESSAGE IN AND OUT IS A ROW in uc_text_messages (schema 20): the
 * confirmation, the reminder, a Text registrants message and an organizer's
 * reply when it goes out (SFAF_Texts::send() and reply() below), and each
 * reply when it comes in (SFAF_Texts::answer()). STOP, START and HELP are
 * never rows: answer() deals with them first and returns.
 *
 * A REPLY BELONGS TO THE LAST MESSAGE SENT TO THAT NUMBER, from any event,
 * because Twilio does not say what a reply answers. That message gives the
 * reply its event, its registration and who is told:
 *   - a Text registrants message or an organizer's reply: the staff member
 *     who sent it, while their account has an address;
 *   - a confirmation or a reminder: the event's Replies go to address
 *     (SFAF_Reminders::reply_to_for());
 *   - nothing ever sent to the number: its latest registration's event's
 *     Replies go to, else the site default, with no event to link to.
 *
 * FIVE MINUTES, ONE EMAIL. The first reply from a number schedules one alert
 * for that number five minutes on; every reply from it before then goes in
 * the same email. The alert claims its rows with a stamp before sending, so
 * two runs cannot send the same reply twice. A reply arriving after an alert
 * is overdue sends the overdue ones first.
 *
 * THE REPLIES SECTION on the event's RSVP page (SFAF_Portal::
 * render_text_replies()) lists the event's threads, one per number, named
 * from the registration, with a box to answer. An answer goes the way the
 * person wrote: by text, through the Messaging Service with "SFAF:" and the
 * STOP line; by WhatsApp as typed while the person's last WhatsApp message
 * is under 24 hours old, and after that as the approved update template,
 * which is the only thing WhatsApp lets a business send outside that window.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Text_Inbox {

    const TABLE      = 'uc_text_messages';
    const ALERT_HOOK = 'sfaf_text_reply_alert';
    /** Replies from one number within this many seconds go in one email. */
    const BUNDLE     = 300;
    /** WhatsApp's customer service window: free text only within it. */
    const WINDOW     = 86400;

    public static function register() {
        add_action( self::ALERT_HOOK, array( __CLASS__, 'alert' ) );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /* ---------------------------------------------------------------------
     * Recording
     * ------------------------------------------------------------------- */

    /**
     * A message that went out.
     *
     * @param array $m event_id, rsvp_id, phone, channel ('sms' or 'whatsapp'),
     *                 kind ('confirmation', 'reminder', 'update', 'organizer'),
     *                 body, by (user id, 0 for an automatic one), sid.
     */
    public static function sent( $m ) {
        global $wpdb;
        $phone = SFAF_Texts::e164( isset( $m['phone'] ) ? $m['phone'] : '' );
        if ( '' === $phone ) {
            return 0;
        }
        $ok = $wpdb->insert( self::table(), array(
            'event_id'   => isset( $m['event_id'] ) ? (int) $m['event_id'] : 0,
            'rsvp_id'    => isset( $m['rsvp_id'] ) ? (int) $m['rsvp_id'] : 0,
            'phone'      => $phone,
            'channel'    => 'whatsapp' === ( isset( $m['channel'] ) ? $m['channel'] : '' ) ? 'whatsapp' : 'sms',
            'direction'  => 'out',
            'kind'       => isset( $m['kind'] ) ? substr( (string) $m['kind'], 0, 20 ) : '',
            'body'       => isset( $m['body'] ) ? (string) $m['body'] : '',
            'by_user'    => isset( $m['by'] ) ? (int) $m['by'] : 0,
            'reply_to'   => 0,
            'twilio_sid' => isset( $m['sid'] ) ? substr( (string) $m['sid'], 0, 40 ) : '',
            'alerted'    => 0,
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ) );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * A reply that came in: recorded against the last message sent to the
     * number, and its alert scheduled. Never STOP, START or HELP.
     *
     * @return int The row's id, 0 when it could not be recorded.
     */
    public static function received( $phone, $channel, $body, $sid = '' ) {
        global $wpdb;
        $phone = SFAF_Texts::e164( $phone );
        if ( '' === $phone ) {
            return 0;
        }
        $last     = self::last_sent( $phone );
        $event_id = $last ? (int) $last->event_id : 0;
        $rsvp_id  = $last ? (int) $last->rsvp_id : 0;
        if ( ! $last ) {
            $row = SFAF_Texts::latest_registration( $phone );
            if ( $row ) {
                $event_id = (int) $row->event_id;
                $rsvp_id  = (int) $row->id;
            }
        }
        // Anything overdue goes first, in case the scheduled run never came.
        self::send_overdue();
        $ok = $wpdb->insert( self::table(), array(
            'event_id'   => $event_id,
            'rsvp_id'    => $rsvp_id,
            'phone'      => $phone,
            'channel'    => 'whatsapp' === $channel ? 'whatsapp' : 'sms',
            'direction'  => 'in',
            'kind'       => 'reply',
            'body'       => (string) $body,
            'by_user'    => 0,
            'reply_to'   => $last ? (int) $last->id : 0,
            'twilio_sid' => substr( (string) $sid, 0, 40 ),
            'alerted'    => 0,
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ) );
        if ( ! $ok ) {
            return 0;
        }
        $id = (int) $wpdb->insert_id;
        if ( ! wp_next_scheduled( self::ALERT_HOOK, array( $phone ) ) ) {
            wp_schedule_single_event( time() + self::BUNDLE, self::ALERT_HOOK, array( $phone ) );
        }
        return $id;
    }

    /** The last message that went out to a number, from any event, or null. */
    public static function last_sent( $phone ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE phone = %s AND direction = 'out' ORDER BY id DESC LIMIT 1",
            (string) $phone
        ) );
    }

    /** The last reply from a number on a channel, from any event, or null. */
    public static function last_received( $phone, $channel ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE phone = %s AND channel = %s AND direction = 'in' ORDER BY id DESC LIMIT 1",
            (string) $phone, (string) $channel
        ) );
    }

    /* ---------------------------------------------------------------------
     * The alert
     * ------------------------------------------------------------------- */

    /** Replies waiting longer than the bundle and a minute: the run that should have sent them did not come. */
    public static function send_overdue() {
        global $wpdb;
        $before = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::BUNDLE - 60 );
        $phones = (array) $wpdb->get_col( $wpdb->prepare(
            'SELECT DISTINCT phone FROM ' . self::table() . " WHERE direction = 'in' AND alerted = 0 AND created_at < %s",
            $before
        ) );
        foreach ( $phones as $p ) {
            self::alert( (string) $p );
        }
    }

    /**
     * Email the replies from one number not yet sent: one email to each
     * person they go to.
     *
     * @return int How many emails went.
     */
    public static function alert( $phone ) {
        global $wpdb;
        $phone = (string) $phone;
        // Claimed first, with a stamp of this run's own, so a second run finds nothing.
        $stamp = (int) ( microtime( true ) * 1000 ) + wp_rand( 1, 999 );
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table() . " SET alerted = %d WHERE phone = %s AND direction = 'in' AND alerted = 0",
            $stamp, $phone
        ) );
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE phone = %s AND direction = 'in' AND alerted = %d ORDER BY id ASC",
            $phone, $stamp
        ) );
        $groups = array();
        foreach ( $rows as $r ) {
            $to = self::recipient( $r );
            if ( '' === $to ) {
                continue;
            }
            $groups[ $to . '|' . (int) $r->event_id ][] = $r;
        }
        $went = 0;
        foreach ( $groups as $key => $group ) {
            $to    = substr( $key, 0, strrpos( $key, '|' ) );
            $built = self::build_alert( $group );
            if ( SFAF_Email::send( $to, $built['subject'], $built['html'], $built['text'] ) ) {
                $went++;
            }
        }
        return $went;
    }

    /**
     * Who is told about a reply: whoever sent the message it answers when a
     * person sent it, else the event's Replies go to, else the site default.
     */
    public static function recipient( $in ) {
        global $wpdb;
        $out = ! empty( $in->reply_to ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $in->reply_to ) ) : null;
        if ( $out && in_array( (string) $out->kind, array( 'update', 'organizer' ), true ) && (int) $out->by_user ) {
            $u = get_userdata( (int) $out->by_user );
            if ( $u && (int) $u->ID === (int) $out->by_user && ! empty( $u->user_email ) && is_email( $u->user_email ) ) {
                return (string) $u->user_email;
            }
        }
        if ( (int) $in->event_id && get_post( (int) $in->event_id ) ) {
            $a = SFAF_Reminders::reply_to_for( (int) $in->event_id );
            if ( '' !== (string) $a && is_email( $a ) ) {
                return (string) $a;
            }
        }
        $site = SFAF_Texts::site_reply_to();
        return is_email( $site ) ? (string) $site : '';
    }

    /** The registration a thread is named from, or null. */
    public static function registration( $rsvp_id ) {
        global $wpdb;
        if ( ! (int) $rsvp_id ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'uc_rsvps WHERE id = %d', (int) $rsvp_id ) );
    }

    /** How a thread names the person: their registration's name, else the number. */
    public static function name_for( $rows ) {
        foreach ( array_reverse( (array) $rows ) as $r ) {
            $reg = self::registration( (int) $r->rsvp_id );
            if ( $reg ) {
                $n = trim( (string) SFAF_RSVP::display_name( $reg ) );
                if ( '' !== $n ) {
                    return $n;
                }
            }
        }
        return self::show_phone( $rows ? $rows[0]->phone : '' );
    }

    /** +14155550101 as (415) 555-0101; any other country as stored. */
    public static function show_phone( $e164 ) {
        $e164 = (string) $e164;
        return preg_match( '/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m ) ? sprintf( '(%s) %s-%s', $m[1], $m[2], $m[3] ) : $e164;
    }

    /** The RSVP page, opened at Replies. */
    public static function rsvp_link( $event_id ) {
        return add_query_arg( array( 'event_id' => (int) $event_id, 'replies' => 1 ), SFAF_Portal::link( 'rsvps' ) ) . '#uc-replies';
    }

    /** @return array{subject:string,html:string,text:string} */
    public static function build_alert( $rows ) {
        $first    = $rows[0];
        $name     = self::name_for( $rows );
        $phone    = self::show_phone( $first->phone );
        $by       = 'whatsapp' === $first->channel ? 'WhatsApp' : 'text';
        $event_id = (int) $first->event_id;
        $event    = $event_id && get_post( $event_id ) ? html_entity_decode( (string) get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ) : '';
        $n        = count( $rows );
        $subject  = ( 1 === $n ? sprintf( 'Reply from %s', $name ) : sprintf( '%d replies from %s', $n, $name ) ) . ( '' !== $event ? ': ' . $event : '' );
        $date     = $event_id ? (string) get_post_meta( $event_id, '_uc_event_date', true ) : '';
        $details  = array(
            'Name'  => $name,
            'Phone' => $phone,
            'By'    => 'whatsapp' === $first->channel ? 'WhatsApp' : 'Text',
            'Event' => $event,
            'Date'  => '' !== $date ? sfaf_ap_date( $date ) : '',
        );
        $html  = SFAF_Email::heading( sprintf( '%s replied by %s', $name, $by ) );
        $html .= SFAF_Email::details( $details );
        $text  = sprintf( "%s replied by %s.\n\n", $name, $by );
        foreach ( $details as $k => $v ) {
            if ( '' !== trim( (string) $v ) ) {
                $text .= $k . ': ' . $v . "\n";
            }
        }
        $text .= "\n";
        foreach ( $rows as $r ) {
            $when  = sfaf_ap_datetime( (string) $r->created_at );
            $html .= SFAF_Email::label( $when ) . SFAF_Email::para( (string) $r->body );
            $text .= $when . "\n" . (string) $r->body . "\n\n";
        }
        $own = 'whatsapp' === $first->channel
            ? sprintf( 'To answer, reply on the RSVP page, or message %s on WhatsApp from your own phone.', $phone )
            : sprintf( 'To answer, reply on the RSVP page, or text %s from your own phone.', $phone );
        if ( $event_id && get_post( $event_id ) ) {
            $link  = self::rsvp_link( $event_id );
            $html .= SFAF_Email::para( $own );
            $html .= SFAF_Email::button( $link, 'Open the RSVP page' );
            $text .= $own . "\n\nOpen the RSVP page: " . $link . "\n";
        } else {
            $none  = sprintf( 'This number matches no registration. To answer, %s %s from your own phone.', 'whatsapp' === $first->channel ? 'message' : 'text', $phone );
            $html .= SFAF_Email::para( $none );
            $text .= $none . "\n";
        }
        return array(
            'subject' => $subject,
            'html'    => SFAF_Email::shell( $subject, $html ),
            'text'    => $text,
        );
    }

    /* ---------------------------------------------------------------------
     * The Replies section
     * ------------------------------------------------------------------- */

    /**
     * The event's threads, one per number, the most recent first: every
     * reply, and every answer sent from the RSVP page.
     *
     * @return array<int,array{phone:string,channel:string,name:string,rows:array,last:string}>
     */
    public static function threads( $event_id ) {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE event_id = %d AND ( direction = 'in' OR kind = 'organizer' ) ORDER BY id ASC",
            (int) $event_id
        ) );
        $by = array();
        foreach ( $rows as $r ) {
            $by[ (string) $r->phone ][] = $r;
        }
        $out = array();
        foreach ( $by as $phone => $list ) {
            $in = array_values( array_filter( $list, function ( $r ) { return 'in' === $r->direction; } ) );
            if ( ! $in ) {
                continue;
            }
            $last_in = $in[ count( $in ) - 1 ];
            $out[]   = array(
                'phone'   => (string) $phone,
                'channel' => (string) $last_in->channel,
                'name'    => self::name_for( $list ),
                'rows'    => $list,
                'last'    => (string) $list[ count( $list ) - 1 ]->created_at,
                'lastid'  => (int) $list[ count( $list ) - 1 ]->id,
            );
        }
        usort( $out, function ( $a, $b ) { return $b['lastid'] - $a['lastid']; } );
        return $out;
    }

    /** Whether a WhatsApp answer may go as typed: the person's last WhatsApp message is under 24 hours old. */
    public static function window_open( $phone ) {
        $last = self::last_received( SFAF_Texts::e164( $phone ), 'whatsapp' );
        if ( ! $last ) {
            return false;
        }
        return ( strtotime( current_time( 'mysql' ) ) - strtotime( (string) $last->created_at ) ) < self::WINDOW;
    }

    /**
     * Answer a thread from the RSVP page, the way the person wrote.
     *
     * @return array{ok:bool,message:string}
     */
    public static function reply( $event_id, $phone, $message, $by_user_id ) {
        $message = SFAF_Texts::clean_message( $message );
        $phone   = SFAF_Texts::e164( $phone );
        if ( '' === $message ) {
            return array( 'ok' => false, 'message' => 'Write the reply first.' );
        }
        $thread = null;
        foreach ( self::threads( $event_id ) as $t ) {
            if ( $t['phone'] === $phone ) {
                $thread = $t;
            }
        }
        if ( ! $thread ) {
            return array( 'ok' => false, 'message' => 'Nothing was sent: this number has not replied about this event.' );
        }
        if ( SFAF_Texts::is_stopped( $phone ) ) {
            return array( 'ok' => false, 'message' => 'Nothing was sent: this number sent STOP.' );
        }
        $rsvp_id = 0;
        foreach ( array_reverse( $thread['rows'] ) as $r ) {
            if ( (int) $r->rsvp_id ) {
                $rsvp_id = (int) $r->rsvp_id;
                break;
            }
        }
        $reg  = self::registration( $rsvp_id );
        $lang = $reg ? SFAF_Texts::lang_of( $reg ) : sfaf_event_language( (int) $event_id );
        if ( 'whatsapp' === $thread['channel'] ) {
            $from = SFAF_Texts::e164( SFAF_Texts::setting( SFAF_Texts::WA_FROM ) );
            if ( self::window_open( $phone ) && '' !== $from ) {
                $body = $message;
                $how  = 'by WhatsApp';
                $r    = SFAF_Texts::api( 'POST', '/Messages.json', array(
                    'From' => 'whatsapp:' . $from,
                    'To'   => 'whatsapp:' . $phone,
                    'Body' => $body,
                ) );
            } elseif ( SFAF_Texts::whatsapp_on() ) {
                $v    = array_merge( SFAF_Texts::facts( (int) $event_id, $lang ), array( 'message' => $message ) );
                $body = SFAF_Texts::words( 'update', $lang, $v );
                $how  = 'by WhatsApp, as an event update';
                $r    = SFAF_Texts::api( 'POST', '/Messages.json', array(
                    'From'             => 'whatsapp:' . $from,
                    'To'               => 'whatsapp:' . $phone,
                    'ContentSid'       => SFAF_Texts::setting( SFAF_Texts::WA_TEMPLATES[ 'update_' . $lang ] ),
                    'ContentVariables' => wp_json_encode( SFAF_Texts::variables( 'update', $v ) ),
                ) );
            } else {
                return array( 'ok' => false, 'message' => "Nothing was sent: WhatsApp isn't active yet." );
            }
        } else {
            if ( ! SFAF_Texts::sms_on() ) {
                return array( 'ok' => false, 'message' => "Nothing was sent: texts aren't set up." );
            }
            $body = SFAF_Texts::sms( $message, $lang );
            $how  = 'by text';
            $r    = SFAF_Texts::api( 'POST', '/Messages.json', array(
                'MessagingServiceSid' => SFAF_Texts::setting( SFAF_Texts::SERVICE ),
                'To'                  => $phone,
                'Body'                => $body,
            ) );
        }
        if ( empty( $r['ok'] ) ) {
            return array( 'ok' => false, 'message' => 'Twilio did not send it: ' . ( isset( $r['error'] ) ? $r['error'] : 'no answer' ) );
        }
        self::sent( array(
            'event_id' => (int) $event_id,
            'rsvp_id'  => $rsvp_id,
            'phone'    => $phone,
            'channel'  => $thread['channel'],
            'kind'     => 'organizer',
            'body'     => $body,
            'by'       => (int) $by_user_id,
            'sid'      => isset( $r['body']['sid'] ) ? (string) $r['body']['sid'] : '',
        ) );
        return array( 'ok' => true, 'message' => sprintf( 'Sent to %s %s.', $thread['name'], $how ) );
    }
}
