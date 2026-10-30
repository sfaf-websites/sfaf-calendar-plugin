<?php
/**
 * TEXT AND WHATSAPP, THROUGH TWILIO (3.130.0).
 *
 * WHO GETS A TEXT. Somebody who gave a phone number on the RSVP form and chose
 * Text or WhatsApp there (`text_channel` on their registration, 'sms' or
 * 'whatsapp'; '' is Neither and every row before 3.130.0). Email goes on as it
 * always has: a text is sent as well, never instead.
 *
 * WHAT IS SENT:
 *   - the morning-of reminder, by SFAF_Reminders::send_for_event(), in the
 *     same run as the email, claimed in the same ledger under "text|" and the
 *     number, so a second run cannot send it twice;
 *   - "Text registrants" from the RSVP list: one message the team types,
 *     wrapped in the person's language, logged on the event like Email
 *     registrants.
 * Each in the language the person chose on the form, else the event's.
 *
 * WHATSAPP STAYS OFF until the Account SID, the Auth Token, the WhatsApp
 * sender and all four template SIDs (the reminder and the update, English and
 * Spanish) are in Settings. WhatsApp outside a conversation the person opened
 * may only be a Meta-approved template, so there is no free-text fallback.
 * Until it is on, somebody who chose WhatsApp gets email only, and the RSVP
 * list says WhatsApp isn't active yet.
 *
 * REPLIES AND STOP come to rest_url( 'sfaf-calendar/v1/twilio/incoming' ),
 * which Twilio signs with the Auth Token; anything unsigned is refused.
 *   - STOP (and Twilio's other stop words, and PARAR) records the number in
 *     uc_text_stops: no more texts or WhatsApp messages to it, on either
 *     channel, from any event. START (or UNSTOP, or YES) takes it off.
 *     On WhatsApp each is answered with a line saying so; on a text, Twilio's
 *     Messaging Service sends its own, so nothing is added.
 *   - HELP (or INFO, or AYUDA) on WhatsApp is answered every time: who is
 *     writing, where to write, and how to stop. On a text the Messaging
 *     Service answers HELP itself, with the reply set in Twilio, so nothing
 *     is added. The form's consent line promises it.
 *   - anything else gets one automatic reply, at most once a day per number:
 *     "This number isn't monitored. For questions about [event name], please
 *     contact [email]." with the event's Replies go to address, else the site
 *     default (SFAF_Reminders::reply_to_for()). Nothing is forwarded.
 *
 * THE FORM OFFERS THE CHOICE only once texts can go (sms_on()): Text,
 * WhatsApp or Neither, Neither by default, beside Mark's consent line and the
 * privacy policy. WhatsApp is offered before its templates are in, as asked.
 *
 * THE OLD "TEXT ME ABOUT THIS EVENT" (3.110.0 to 3.129.0) is text_opt_in on
 * the row. It is left as it was, gives no channel, and sends nothing; the
 * panel says how many there are, for Mark to decide (3.130.0).
 *
 * THE AUTH TOKEN is stored sealed (SFAF_Mailchimp::seal(), keyed to this
 * site's salts) and never shown, logged, put in a page or in an email: the
 * screen says only its last four characters, and every error from Twilio has
 * it taken out before it is shown.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Texts {

    const STOPS_TABLE    = 'uc_text_stops';
    const LOG_META       = '_uc_registrant_text_log';
    const REST_NAMESPACE = 'sfaf-calendar/v1';
    const REST_ROUTE     = '/twilio/incoming';
    const API_BASE       = 'https://api.twilio.com/2010-04-01/Accounts/';
    const MAX_MESSAGE    = 300;
    const PRIVACY_URL    = 'https://www.sfaf.org/privacy-policy/';

    /** Settings, as credential keys. The token is sealed; the rest are identifiers. */
    const SID          = 'twilio_account_sid';
    const TOKEN        = 'twilio_auth_token';
    const SERVICE      = 'twilio_messaging_sid';
    const WA_FROM      = 'twilio_whatsapp_from';
    const WA_TEMPLATES = array(
        'reminder_en' => 'twilio_wa_reminder_en',
        'reminder_es' => 'twilio_wa_reminder_es',
        'update_en'   => 'twilio_wa_update_en',
        'update_es'   => 'twilio_wa_update_es',
    );

    /** Keywords, upper case. Twilio's stop and start words, and PARAR. */
    const STOP_WORDS  = array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT', 'PARAR' );
    const START_WORDS = array( 'START', 'UNSTOP', 'YES' );
    const HELP_WORDS  = array( 'HELP', 'INFO', 'AYUDA' );

    /** The channels the RSVP form offers: none until texts can go. */
    public static function form_channels() {
        return self::sms_on() ? array( 'sms', 'whatsapp' ) : array();
    }

    /** A posted choice as stored: one the form offered, with a number, else ''. */
    public static function clean_channel( $channel, $phone ) {
        $channel = (string) $channel;
        return ( '' !== trim( (string) $phone ) && in_array( $channel, self::form_channels(), true ) ) ? $channel : '';
    }

    /** How the RSVP list names a channel. */
    public static function channel_label( $channel ) {
        return 'whatsapp' === $channel ? 'WhatsApp' : ( 'sms' === $channel ? 'Text' : '' );
    }

    /**
     * Registrations that ticked the old "Text me about this event" (3.110.0 to
     * 3.129.0), and on how many events. They are kept as they were.
     *
     * @return array{rows:int,events:int}
     */
    public static function old_ticks() {
        global $wpdb;
        $t = $wpdb->prefix . 'uc_rsvps';
        $r = $wpdb->get_row( "SELECT COUNT(*) AS n, COUNT(DISTINCT event_id) AS e FROM $t WHERE text_opt_in = 1 AND text_channel = ''" );
        return array( 'rows' => $r ? (int) $r->n : 0, 'events' => $r ? (int) $r->e : 0 );
    }

    public static function register() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'admin_post_sfaf_twilio_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'wp_ajax_sfaf_twilio_test', array( __CLASS__, 'ajax_test' ) );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::STOPS_TABLE;
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------- */

    public static function setting( $key ) {
        return trim( (string) SFAF_Credentials::get( $key ) );
    }

    /** The Auth Token in the clear, for a request to Twilio only. */
    private static function token() {
        return SFAF_Mailchimp::open( SFAF_Credentials::raw( self::TOKEN ) );
    }

    /** What the screen shows of the token. */
    public static function token_hint() {
        $t = self::token();
        if ( '' === $t ) {
            return '' !== SFAF_Credentials::raw( self::TOKEN ) ? 'Saved, but it no longer opens. Paste it again.' : 'None saved.';
        }
        return 'Saved, ending ' . substr( $t, -4 ) . '.';
    }

    /** Texts can go: the account, the token and a Messaging Service. */
    public static function sms_on() {
        return 1 === preg_match( '/^AC[0-9a-f]{32}$/i', self::setting( self::SID ) )
            && '' !== self::token()
            && 1 === preg_match( '/^MG[0-9a-f]{32}$/i', self::setting( self::SERVICE ) );
    }

    /** WhatsApp can go: the account, the token, the sender and all four templates. */
    public static function whatsapp_on() {
        if ( 1 !== preg_match( '/^AC[0-9a-f]{32}$/i', self::setting( self::SID ) ) || '' === self::token() || '' === self::e164( self::setting( self::WA_FROM ) ) ) {
            return false;
        }
        foreach ( self::WA_TEMPLATES as $key ) {
            if ( 1 !== preg_match( '/^HX[0-9a-f]{32}$/i', self::setting( $key ) ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Save the panel. The token only when one was typed; blank keeps it.
     *
     * @return array{ok:bool,message:string}
     */
    public static function save( $fields, $token = '' ) {
        $bad = array();
        $checks = array(
            self::SID     => array( '/^AC[0-9a-f]{32}$/i', 'The Account SID starts with AC and has 34 characters.' ),
            self::SERVICE => array( '/^MG[0-9a-f]{32}$/i', 'The Messaging Service SID starts with MG and has 34 characters.' ),
        );
        foreach ( self::WA_TEMPLATES as $key ) {
            $checks[ $key ] = array( '/^HX[0-9a-f]{32}$/i', 'A template SID starts with HX and has 34 characters.' );
        }
        $clean = array();
        foreach ( array_merge( array( self::SID, self::SERVICE, self::WA_FROM ), array_values( self::WA_TEMPLATES ) ) as $key ) {
            $v = isset( $fields[ $key ] ) ? trim( preg_replace( '/\s+/', '', (string) $fields[ $key ] ) ) : '';
            if ( '' !== $v && isset( $checks[ $key ] ) && 1 !== preg_match( $checks[ $key ][0], $v ) ) {
                $bad[] = $checks[ $key ][1];
                continue;
            }
            if ( self::WA_FROM === $key && '' !== $v ) {
                $n = self::e164( $v );
                if ( '' === $n ) {
                    $bad[] = 'The WhatsApp sender is a phone number, such as +14155550123.';
                    continue;
                }
                $v = $n;
            }
            $clean[ $key ] = $v;
        }
        if ( $bad ) {
            return array( 'ok' => false, 'message' => implode( ' ', array_unique( $bad ) ) . ' Nothing was saved.' );
        }
        foreach ( $clean as $key => $v ) {
            SFAF_Credentials::set( $key, $v );
        }
        $token = trim( (string) $token );
        if ( '' !== $token ) {
            SFAF_Credentials::set( self::TOKEN, SFAF_Mailchimp::seal( $token ) );
        }
        return array( 'ok' => true, 'message' => 'Twilio settings saved.' );
    }

    /* ---------------------------------------------------------------------
     * Numbers and stops
     * ------------------------------------------------------------------- */

    /**
     * A number as Twilio wants it, +15551234567, or '' when it cannot be one.
     * A plain US number of ten digits, or eleven starting 1, gains +1; a number
     * typed with + keeps its country code. Anything else is not guessed at.
     */
    public static function e164( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return '';
        }
        $raw    = preg_replace( '/^whatsapp:/i', '', $raw );
        $plus   = ( 0 === strpos( ltrim( $raw ), '+' ) );
        $digits = preg_replace( '/\D/', '', preg_replace( '/(x|ext\.?)\s*\d+$/i', '', $raw ) );
        if ( $plus ) {
            return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? '+' . $digits : '';
        }
        if ( 10 === strlen( $digits ) ) {
            return '+1' . $digits;
        }
        if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
            return '+' . $digits;
        }
        return '';
    }

    public static function is_stopped( $e164 ) {
        global $wpdb;
        if ( '' === (string) $e164 ) {
            return false;
        }
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE phone = %s', (string) $e164 ) );
    }

    /** STOP on either channel: no more texts or WhatsApp messages to this number. */
    public static function record_stop( $e164, $channel, $word ) {
        global $wpdb;
        if ( '' === (string) $e164 || self::is_stopped( $e164 ) ) {
            return;
        }
        $wpdb->insert( self::table(), array(
            'phone'      => (string) $e164,
            'channel'    => (string) $channel,
            'word'       => substr( (string) $word, 0, 20 ),
            'stopped_at' => current_time( 'mysql' ),
        ), array( '%s', '%s', '%s', '%s' ) );
    }

    public static function clear_stop( $e164 ) {
        global $wpdb;
        $wpdb->delete( self::table(), array( 'phone' => (string) $e164 ), array( '%s' ) );
    }

    /* ---------------------------------------------------------------------
     * Who gets what
     * ------------------------------------------------------------------- */

    /**
     * How one registration can be reached by text, and why not when it cannot.
     *
     * @return array{channel:string,phone:string,state:string}
     *         state: 'sms', 'whatsapp', 'whatsapp_off', 'sms_off', 'stopped',
     *         'no_number' or 'none' (chose Neither).
     */
    public static function reach( $row ) {
        $channel = isset( $row->text_channel ) ? (string) $row->text_channel : '';
        $phone   = self::e164( isset( $row->phone ) ? $row->phone : '' );
        if ( ! in_array( $channel, array( 'sms', 'whatsapp' ), true ) ) {
            return array( 'channel' => '', 'phone' => $phone, 'state' => 'none' );
        }
        if ( '' === $phone ) {
            $state = 'no_number';
        } elseif ( self::is_stopped( $phone ) ) {
            $state = 'stopped';
        } elseif ( 'sms' === $channel ) {
            $state = self::sms_on() ? 'sms' : 'sms_off';
        } else {
            $state = self::whatsapp_on() ? 'whatsapp' : 'whatsapp_off';
        }
        return array( 'channel' => $channel, 'phone' => $phone, 'state' => $state );
    }

    /** The registrations that chose Text or WhatsApp, confirmed, for one event. */
    public static function chose( $event_id ) {
        global $wpdb;
        $t = $wpdb->prefix . 'uc_rsvps';
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE event_id = %d AND status = 'confirmed' AND text_channel IN ('sms','whatsapp') ORDER BY id ASC",
            (int) $event_id
        ) );
    }

    /** The language a person's texts go in: what they chose on the form, else the event's. */
    public static function lang_of( $row ) {
        $l = isset( $row->lang ) ? (string) $row->lang : '';
        return in_array( $l, array( 'en', 'es' ), true ) ? $l : sfaf_event_language( (int) $row->event_id );
    }

    /* ---------------------------------------------------------------------
     * The words
     * ------------------------------------------------------------------- */

    /** The event's facts as a text gives them, in one language. */
    public static function facts( $event_id, $lang ) {
        $es    = ( 'es' === $lang );
        $start = (string) get_post_meta( $event_id, '_uc_start_time', true );
        // sfaf_event_location() answers "Online Event" in English for an online event.
        $place = class_exists( 'SFAF_Online' ) && SFAF_Online::is_online( $event_id )
            ? SFAF_Messages::label( 'online_event', $lang )
            : trim( wp_strip_all_tags( (string) sfaf_event_location( $event_id ) ) );
        // The template puts a period after the place: "Market St." would end "St..".
        $place = rtrim( $place, '. ' );
        // Never empty: a WhatsApp template refuses an empty variable.
        if ( '' === $place ) {
            $place = $es ? 'el lugar indicado en la página del evento' : 'the place on the event page';
        }
        return array(
            'name'  => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
            'time'  => '' !== $start ? sfaf_ap_time( $start, true, $lang ) : SFAF_Messages::label( 'tbc', $lang ),
            'place' => $place,
            'link'  => (string) get_permalink( $event_id ),
            'reply' => SFAF_Reminders::reply_to_for( $event_id ),
        );
    }

    /**
     * The words of a text: the WhatsApp template's, so a text and a WhatsApp
     * message say the same thing. The reminder and the update end with how to
     * stop, which the WhatsApp templates leave to WhatsApp.
     */
    public static function words( $kind, $lang, $v ) {
        $es = ( 'es' === $lang );
        if ( isset( $v['message'] ) ) {
            $v['message'] = self::ended( $v['message'] );
        }
        if ( 'reminder' === $kind ) {
            $s = $es
                ? sprintf( 'Recordatorio de SFAF: %s es hoy a las %s, en %s. Detalles del evento: %s', $v['name'], $v['time'], $v['place'], $v['link'] )
                : sprintf( 'Reminder from SFAF: %s is today at %s, at %s. Event details: %s', $v['name'], $v['time'], $v['place'], $v['link'] );
        } elseif ( 'update' === $kind ) {
            $s = $es
                ? sprintf( 'Novedad de SFAF sobre %s: %s Si tiene preguntas, escriba a %s.', $v['name'], $v['message'], $v['reply'] )
                : sprintf( 'Update from SFAF about %s: %s For questions, please email %s.', $v['name'], $v['message'], $v['reply'] );
        } elseif ( 'help' === $kind ) {
            return $es
                ? sprintf( 'Mensajes de SFAF sobre %s. Si tiene preguntas, escriba a %s. Responda STOP para no recibir más.', $v['name'], $v['reply'] )
                : sprintf( 'SFAF messages about %s. For questions, please email %s. Reply STOP to opt out.', $v['name'], $v['reply'] );
        } elseif ( 'stopped' === $kind ) {
            return $es
                ? 'No recibirá más mensajes de SFAF sobre eventos. Responda START para volver a recibirlos.'
                : 'You will get no more messages from SFAF about events. Reply START to get them again.';
        } elseif ( 'started' === $kind ) {
            return $es
                ? 'Volverá a recibir mensajes de SFAF sobre los eventos en los que se inscriba.'
                : 'You will get messages from SFAF again about events you register for.';
        } else {
            // The automatic answer to a reply.
            return $es
                ? sprintf( 'Este número no se revisa. Si tiene preguntas sobre %s, escriba a %s.', $v['name'], $v['reply'] )
                : sprintf( "This number isn't monitored. For questions about %s, please contact %s.", $v['name'], $v['reply'] );
        }
        return $s . ( $es ? ' Responda STOP para no recibir más.' : ' Reply STOP to opt out.' );
    }

    /** The template's variables, in the order Mark's templates number them. */
    public static function variables( $kind, $v ) {
        return 'reminder' === $kind
            ? array( '1' => $v['name'], '2' => $v['time'], '3' => $v['place'], '4' => $v['link'] )
            : array( '1' => $v['name'], '2' => self::ended( $v['message'] ), '3' => $v['reply'] );
    }

    /** The typed message ends a sentence: "For questions" follows it. */
    public static function ended( $message ) {
        $message = rtrim( (string) $message );
        return ( '' === $message || preg_match( '/[.!?…]["\')]*$/u', $message ) ) ? $message : $message . '.';
    }

    /* ---------------------------------------------------------------------
     * Sending
     * ------------------------------------------------------------------- */

    /**
     * One request to Twilio's Messages API.
     *
     * @return array{ok:bool,code:int,body:array,error:string}
     */
    public static function api( $method, $path, $fields = array() ) {
        $sid   = self::setting( self::SID );
        $token = self::token();
        if ( '' === $sid || '' === $token ) {
            return array( 'ok' => false, 'code' => 0, 'body' => array(), 'error' => 'Twilio is not set up.' );
        }
        $args = array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ) ),
        );
        if ( $fields ) {
            $args['body'] = $fields;
        }
        $res = wp_remote_request( self::API_BASE . rawurlencode( $sid ) . $path, $args );
        if ( is_wp_error( $res ) ) {
            return array( 'ok' => false, 'code' => 0, 'body' => array(), 'error' => self::scrub( 'Could not reach Twilio: ' . $res->get_error_message() ) );
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        $data = is_array( $data ) ? $data : array();
        $ok   = $code >= 200 && $code < 300;
        $err  = $ok ? '' : ( isset( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code ) . ( isset( $data['code'] ) ? ' (' . $data['code'] . ')' : '' );
        return array( 'ok' => $ok, 'code' => $code, 'body' => $data, 'error' => self::scrub( $err ) );
    }

    /** A message with the token taken out. */
    public static function scrub( $text ) {
        $text  = (string) $text;
        $token = self::token();
        return '' !== $token ? str_replace( $token, '[token]', $text ) : $text;
    }

    /**
     * Send one message of a kind to one registration, on its channel.
     *
     * @return bool
     */
    public static function send( $row, $kind, $extra = array() ) {
        $reach = self::reach( $row );
        if ( ! in_array( $reach['state'], array( 'sms', 'whatsapp' ), true ) ) {
            return false;
        }
        $lang = self::lang_of( $row );
        $v    = array_merge( self::facts( (int) $row->event_id, $lang ), $extra );
        if ( 'sms' === $reach['state'] ) {
            $r = self::api( 'POST', '/Messages.json', array(
                'MessagingServiceSid' => self::setting( self::SERVICE ),
                'To'                  => $reach['phone'],
                'Body'                => self::words( $kind, $lang, $v ),
            ) );
        } else {
            $r = self::api( 'POST', '/Messages.json', array(
                'From'             => 'whatsapp:' . self::e164( self::setting( self::WA_FROM ) ),
                'To'               => 'whatsapp:' . $reach['phone'],
                'ContentSid'       => self::setting( self::WA_TEMPLATES[ $kind . '_' . $lang ] ),
                'ContentVariables' => wp_json_encode( self::variables( $kind, $v ) ),
            ) );
        }
        return $r['ok'];
    }

    /**
     * The morning-of reminder by text, beside the email (SFAF_Reminders). One
     * per number per event: claimed in the reminder ledger first.
     *
     * @return array{sent:int,failed:int,already:int}
     */
    public static function send_reminders( $event_id ) {
        $out = array( 'sent' => 0, 'failed' => 0, 'already' => 0 );
        foreach ( self::chose( $event_id ) as $row ) {
            $reach = self::reach( $row );
            if ( ! in_array( $reach['state'], array( 'sms', 'whatsapp' ), true ) ) {
                continue;
            }
            $claim = SFAF_Reminders::claim_key( $event_id, 'text|' . $reach['phone'], $reach['phone'], $reach['state'] );
            if ( ! $claim ) {
                $out['already']++;
                continue;
            }
            $ok = self::send( $row, 'reminder' );
            SFAF_Reminders::finish_row( $claim['id'], $ok ? 'sent' : 'failed' );
            $out[ $ok ? 'sent' : 'failed' ]++;
        }
        return $out;
    }

    /**
     * Who Text registrants reaches, and how: one entry per registration that
     * chose Text or WhatsApp, the same number once.
     *
     * @return array<int,array{row:object,state:string,phone:string}>
     */
    public static function plan( $event_id ) {
        $out  = array();
        $seen = array();
        foreach ( self::chose( $event_id ) as $row ) {
            $reach = self::reach( $row );
            // Neither is nobody's to list, whatever the query handed back.
            if ( 'none' === $reach['state'] ) {
                continue;
            }
            if ( '' !== $reach['phone'] && isset( $seen[ $reach['phone'] ] ) ) {
                continue;
            }
            $seen[ $reach['phone'] ] = true;
            $out[] = array( 'row' => $row, 'state' => $reach['state'], 'phone' => $reach['phone'] );
        }
        return $out;
    }

    /** "Sent by text to 4 and by WhatsApp to 2." and the like. */
    public static function said( $r ) {
        $bits = array();
        foreach ( array( 'sms' => 'by text to %d', 'whatsapp' => 'by WhatsApp to %d' ) as $k => $f ) {
            if ( ! empty( $r[ $k ] ) ) {
                $bits[] = sprintf( $f, (int) $r[ $k ] );
            }
        }
        $s = $bits ? 'Sent ' . implode( ' and ', $bits ) . '.' : 'Nothing was sent.';
        if ( ! empty( $r['failed'] ) ) {
            $s .= ' ' . (int) $r['failed'] . ' did not go through.';
        }
        return $s;
    }

    /**
     * Text registrants: send the message to everybody the plan reaches, and log it.
     *
     * @return array{sms:int,whatsapp:int,failed:int,error?:string}
     */
    public static function send_update( $event_id, $message, $by_user_id ) {
        $message = self::clean_message( $message );
        if ( '' === $message ) {
            return array( 'sms' => 0, 'whatsapp' => 0, 'failed' => 0, 'error' => 'Write the message first.' );
        }
        $out = array( 'sms' => 0, 'whatsapp' => 0, 'failed' => 0 );
        foreach ( self::plan( $event_id ) as $p ) {
            if ( ! in_array( $p['state'], array( 'sms', 'whatsapp' ), true ) ) {
                continue;
            }
            if ( self::send( $p['row'], 'update', array( 'message' => $message ) ) ) {
                $out[ $p['state'] ]++;
            } else {
                $out['failed']++;
            }
        }
        $log = self::log( $event_id );
        array_unshift( $log, array(
            'by'       => (int) $by_user_id,
            'at'       => current_time( 'mysql' ),
            'message'  => $message,
            'sms'      => $out['sms'],
            'whatsapp' => $out['whatsapp'],
            'failed'   => $out['failed'],
        ) );
        update_post_meta( (int) $event_id, self::LOG_META, $log );
        return $out;
    }

    /** A typed message: one line of plain text, at most MAX_MESSAGE characters. */
    public static function clean_message( $raw ) {
        $s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $raw ) ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, self::MAX_MESSAGE ) : substr( $s, 0, self::MAX_MESSAGE );
    }

    /** Every Text registrants send from this event's list, newest first. */
    public static function log( $event_id ) {
        $log = get_post_meta( (int) $event_id, self::LOG_META, true );
        return is_array( $log ) ? $log : array();
    }

    /* ---------------------------------------------------------------------
     * Replies and STOP
     * ------------------------------------------------------------------- */

    public static function routes() {
        register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'incoming' ),
            // Twilio's own signature is the check, in incoming().
            'permission_callback' => '__return_true',
        ) );
    }

    /** The address Twilio is told to post to. */
    public static function incoming_url() {
        return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
    }

    /**
     * Twilio's request signature: the URL, then every POST field's name and
     * value in name order, HMAC-SHA1 with the Auth Token, base64.
     */
    public static function signature( $url, $params, $token ) {
        ksort( $params, SORT_STRING );
        $data = (string) $url;
        foreach ( $params as $k => $v ) {
            $data .= $k . ( is_array( $v ) ? implode( '', $v ) : $v );
        }
        return base64_encode( hash_hmac( 'sha1', $data, (string) $token, true ) );
    }

    /** What an incoming message should get: the TwiML reply, or '' for none. */
    public static function answer( $from, $body ) {
        $channel = ( 0 === stripos( (string) $from, 'whatsapp:' ) ) ? 'whatsapp' : 'sms';
        $phone   = self::e164( $from );
        if ( '' === $phone ) {
            return '';
        }
        $word = strtoupper( trim( preg_replace( '/[^\p{L}\s]/u', '', (string) $body ) ) );
        $row  = self::latest_registration( $phone );
        $lang = $row ? self::lang_of( $row ) : 'en';
        if ( in_array( $word, self::STOP_WORDS, true ) ) {
            self::record_stop( $phone, $channel, $word );
            // Twilio confirms a text's STOP itself; WhatsApp has nobody else to.
            return 'whatsapp' === $channel ? self::words( 'stopped', $lang, array() ) : '';
        }
        if ( in_array( $word, self::START_WORDS, true ) ) {
            self::clear_stop( $phone );
            return 'whatsapp' === $channel ? self::words( 'started', $lang, array() ) : '';
        }
        $v = array(
            'name'  => $row ? html_entity_decode( get_the_title( (int) $row->event_id ), ENT_QUOTES, 'UTF-8' ) : ( 'es' === $lang ? 'este evento' : 'this event' ),
            'reply' => $row ? SFAF_Reminders::reply_to_for( (int) $row->event_id ) : '',
        );
        if ( '' === (string) $v['reply'] ) {
            $v['reply'] = self::site_reply_to();
        }
        if ( in_array( $word, self::HELP_WORDS, true ) ) {
            // On a text, the Messaging Service answers HELP itself, as it does STOP.
            return 'whatsapp' === $channel ? self::words( 'help', $lang, $v ) : '';
        }
        // One automatic reply a day per number, so two machines cannot talk forever.
        $key = 'sfaf_text_reply_' . md5( $phone );
        if ( get_transient( $key ) ) {
            return '';
        }
        set_transient( $key, 1, DAY_IN_SECONDS );
        return self::words( 'auto', $lang, $v );
    }

    private static function site_reply_to() {
        $s = get_option( 'uc_settings', array() );
        $a = isset( $s['email_reply_to'] ) ? trim( (string) $s['email_reply_to'] ) : '';
        return is_email( $a ) ? $a : (string) get_option( 'admin_email' );
    }

    /** The most recent registration with a text channel and this number. */
    public static function latest_registration( $e164 ) {
        global $wpdb;
        $t    = $wpdb->prefix . 'uc_rsvps';
        $tail = substr( preg_replace( '/\D/', '', (string) $e164 ), -4 );
        if ( '' === $tail ) {
            return null;
        }
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE text_channel IN ('sms','whatsapp') AND phone LIKE %s ORDER BY id DESC LIMIT 50",
            '%' . $wpdb->esc_like( $tail )
        ) );
        foreach ( $rows as $r ) {
            if ( self::e164( $r->phone ) === $e164 ) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Whether Twilio signed this request. Twilio signs the address it was
     * given, so both the one the panel shows and the one the request arrived
     * at are tried.
     */
    public static function signed( $params, $token, $sig ) {
        if ( '' === (string) $token || '' === (string) $sig ) {
            return false;
        }
        $urls = array( self::incoming_url() );
        if ( isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
            $urls[] = ( is_ssl() ? 'https://' : 'http://' ) . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        foreach ( array_unique( $urls ) as $url ) {
            if ( hash_equals( self::signature( $url, (array) $params, $token ), (string) $sig ) ) {
                return true;
            }
        }
        return false;
    }

    /** The REST callback: check the signature, then answer in TwiML. */
    public static function incoming( $request ) {
        $params = (array) $request->get_body_params();
        $token  = self::token();
        $sig    = (string) $request->get_header( 'x_twilio_signature' );
        if ( ! self::signed( $params, $token, $sig ) ) {
            return new WP_REST_Response( array( 'message' => 'Unsigned.' ), 403 );
        }
        $reply = self::answer( isset( $params['From'] ) ? (string) $params['From'] : '', isset( $params['Body'] ) ? (string) $params['Body'] : '' );
        $xml   = '<?xml version="1.0" encoding="UTF-8"?><Response>'
            . ( '' !== $reply ? '<Message>' . htmlspecialchars( $reply, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</Message>' : '' )
            . '</Response>';
        header( 'Content-Type: text/xml; charset=utf-8' );
        echo $xml; // phpcs:ignore -- TwiML, escaped above.
        exit;
    }

    /* ---------------------------------------------------------------------
     * WP admin: the panel and its handlers
     * ------------------------------------------------------------------- */

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to do that.' );
        }
        check_admin_referer( 'sfaf_twilio_save' );
        $p = wp_unslash( $_POST );
        $r = self::save( $p, isset( $p['twilio_token'] ) ? (string) $p['twilio_token'] : '' );
        set_transient( 'sfaf_twilio_notice_' . get_current_user_id(), $r, 60 );
        wp_safe_redirect( admin_url( 'edit.php?post_type=uc_event&page=uc-settings' ) . '#uc-twilio' );
        exit;
    }

    /** Test connection: read the account with the SID and the token, saved or typed. */
    public static function ajax_test() {
        check_ajax_referer( 'uc_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
        }
        $sid   = isset( $_POST['sid'] ) ? trim( (string) wp_unslash( $_POST['sid'] ) ) : '';
        $typed = isset( $_POST['token'] ) ? trim( (string) wp_unslash( $_POST['token'] ) ) : '';
        $sid   = '' !== $sid ? $sid : self::setting( self::SID );
        $token = '' !== $typed ? $typed : self::token();
        if ( 1 !== preg_match( '/^AC[0-9a-f]{32}$/i', $sid ) || '' === $token ) {
            wp_send_json_error( array( 'message' => 'Fill in the Account SID and the Auth Token first.' ) );
        }
        $res = wp_remote_get( self::API_BASE . rawurlencode( $sid ) . '.json', array(
            'timeout' => 15,
            'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ) ),
        ) );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => str_replace( $token, '[token]', 'Could not reach Twilio: ' . $res->get_error_message() ) ) );
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( 200 !== $code ) {
            wp_send_json_error( array( 'message' => 401 === $code ? 'Twilio did not accept the Account SID and Auth Token.' : 'Twilio answered HTTP ' . $code . '.' ) );
        }
        $name = is_array( $data ) && isset( $data['friendly_name'] ) ? (string) $data['friendly_name'] : '';
        wp_send_json_success( array( 'message' => 'Connected' . ( '' !== $name ? ' to ' . $name : '' ) . '.' ) );
    }

    /**
     * TWILIO, ON WP ADMIN SETTINGS, below the settings form and outside it, as
     * Mailchimp is: that form rebuilds uc_settings on every save. Administrators
     * only. The token is never put in the page: its box is always empty, and
     * the line beside it gives its last four characters.
     */
    public static function render_panel() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $notice = get_transient( 'sfaf_twilio_notice_' . get_current_user_id() );
        if ( $notice ) {
            delete_transient( 'sfaf_twilio_notice_' . get_current_user_id() );
        }
        $sms  = self::sms_on();
        $wa   = self::whatsapp_on();
        $old  = self::old_ticks();
        $text = array(
            self::SID     => array( 'Account SID', 'AC...' ),
            self::SERVICE => array( 'Messaging Service SID', 'MG...' ),
            self::WA_FROM => array( 'WhatsApp sender', '+14155550123' ),
        );
        $tpl = array(
            'reminder_en' => 'sfaf_event_reminder, English',
            'reminder_es' => 'sfaf_event_reminder, Spanish',
            'update_en'   => 'sfaf_event_update, English',
            'update_es'   => 'sfaf_event_update, Spanish',
        );
        ?>
        <div class="uc-integration-panel uc-twilio-panel<?php echo $notice ? ' uc-panel-open' : ''; ?>" id="uc-twilio" data-uc-twilio-panel>
            <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                <span class="uc-panel-icon"><?php echo sfaf_icon( 'speech', array( 'size' => '20px' ) ); ?></span>
                <div class="uc-panel-info">
                    <h2>Twilio</h2>
                    <p>Texts and WhatsApp messages to registrants</p>
                </div>
                <span class="uc-panel-status <?php echo $sms ? 'uc-status-connected' : 'uc-status-pending'; ?>" data-uc-twilio-status><?php echo $sms ? ( $wa ? 'Texts and WhatsApp on' : 'Texts on' ) : 'Not configured'; ?></span>
                <span class="uc-panel-toggle">&#9660;</span>
            </div>
            <div class="uc-panel-body">
                <?php if ( $notice ) : ?>
                    <div class="notice <?php echo ! empty( $notice['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uc-twilio-form" data-uc-twilio-form>
                    <?php wp_nonce_field( 'sfaf_twilio_save' ); ?>
                    <input type="hidden" name="action" value="sfaf_twilio_save" />
                    <div class="uc-field-row">
                        <label for="uc-tw-<?php echo esc_attr( self::SID ); ?>"><?php echo esc_html( $text[ self::SID ][0] ); ?></label>
                        <input type="text" id="uc-tw-<?php echo esc_attr( self::SID ); ?>" name="<?php echo esc_attr( self::SID ); ?>" value="<?php echo esc_attr( self::setting( self::SID ) ); ?>" placeholder="<?php echo esc_attr( $text[ self::SID ][1] ); ?>" class="uc-input" autocomplete="off" />
                    </div>
                    <div class="uc-field-row">
                        <label for="uc-tw-token">Auth Token</label>
                        <input type="password" id="uc-tw-token" name="twilio_token" value="" autocomplete="new-password" class="uc-input"
                               placeholder="<?php echo esc_attr( self::token_hint() . ( '' !== SFAF_Credentials::raw( self::TOKEN ) ? ' Leave blank to keep it.' : '' ) ); ?>" data-uc-twilio-token />
                    </div>
                    <div class="uc-field-row">
                        <label>Connection</label>
                        <div class="uc-conn-controls">
                            <button type="button" class="button" data-uc-twilio-test>Test connection</button>
                        </div>
                    </div>
                    <p class="description" data-uc-twilio-test-msg hidden></p>
                    <?php foreach ( array( self::SERVICE, self::WA_FROM ) as $key ) : ?>
                        <div class="uc-field-row">
                            <label for="uc-tw-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $text[ $key ][0] ); ?></label>
                            <input type="text" id="uc-tw-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( self::setting( $key ) ); ?>" placeholder="<?php echo esc_attr( $text[ $key ][1] ); ?>" class="uc-input" autocomplete="off" />
                        </div>
                    <?php endforeach; ?>
                    <h3>WhatsApp template SIDs</h3>
                    <p class="description" data-uc-twilio-wa-state><?php echo $wa ? 'WhatsApp is on.' : 'WhatsApp stays off until all four are filled in, with the sender above. Until then, anyone who chose WhatsApp gets email only.'; ?></p>
                    <?php foreach ( $tpl as $k => $label ) :
                        $key = self::WA_TEMPLATES[ $k ];
                        ?>
                        <div class="uc-field-row">
                            <label for="uc-tw-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
                            <input type="text" id="uc-tw-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( self::setting( $key ) ); ?>" placeholder="HX..." class="uc-input" autocomplete="off" />
                        </div>
                    <?php endforeach; ?>
                    <h3>Replies and STOP</h3>
                    <div class="uc-field-row">
                        <label for="uc-tw-incoming">Incoming message address</label>
                        <input type="text" id="uc-tw-incoming" value="<?php echo esc_attr( self::incoming_url() ); ?>" class="uc-input" readonly data-uc-twilio-incoming />
                    </div>
                    <p class="description">In Twilio, set this as the Messaging Service's incoming message webhook, and the WhatsApp sender's, with HTTP POST.</p>
                    <p class="uc-mc-buttons">
                        <button type="submit" class="button button-primary">Save Twilio settings</button>
                    </p>
                </form>
                <?php if ( $old['rows'] ) : ?>
                    <p class="description" data-uc-twilio-old-ticks><?php echo esc_html( sprintf(
                        '%1$d %2$s on %3$d %4$s ticked the old "Text me about this event". They get no texts or WhatsApp messages.',
                        $old['rows'], 1 === $old['rows'] ? 'registration' : 'registrations',
                        $old['events'], 1 === $old['events'] ? 'event' : 'events'
                    ) ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
