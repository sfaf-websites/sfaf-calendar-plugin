<?php
/**
 * THE TEXT REPLIES INBOX, MODELLED (3.144.0).
 *
 * Loaded after wp-kit.php by release-31440-test.php and release-31440-live.php:
 * Twilio recorded and never reached, mail and scheduled runs caught, and the
 * tables SFAF_Texts and SFAF_Text_Inbox write (registrations, stops, the
 * reminder ledger, the messages) answered from memory. Not a check.
 */

/* ---- Twilio, recorded and never reached. ---- */
$GLOBALS['tw_calls'] = array();
$GLOBALS['tw_answer'] = array( 'code' => 201, 'body' => '{"sid":"SM1"}' );
function wp_remote_request( $url, $args = array() ) {
    $GLOBALS['tw_calls'][] = array( 'url' => $url, 'args' => $args );
    return array( 'response' => array( 'code' => $GLOBALS['tw_answer']['code'] ), 'body' => $GLOBALS['tw_answer']['body'] );
}
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://resources.sfaf.org/wp-json/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'is_ssl' ) ) { function is_ssl() { return true; } }

/* ---- Mail, caught. ---- */
$GLOBALS['ri_mail'] = array();
function wp_mail( $to, $subject, $body, $headers = '', $att = array() ) { $GLOBALS['ri_mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body ); return true; }

/* ---- Scheduled runs, recorded. ---- */
$GLOBALS['ri_cron'] = array();
function wp_next_scheduled( $hook, $args = array() ) {
    foreach ( $GLOBALS['ri_cron'] as $c ) { if ( $c['hook'] === $hook && $c['args'] === $args ) { return $c['at']; } }
    return false;
}
function wp_schedule_single_event( $at, $hook, $args = array() ) { $GLOBALS['ri_cron'][] = array( 'at' => $at, 'hook' => $hook, 'args' => $args ); return true; }
/* 3.144.1: a closed window clears its backstop run. */
function wp_clear_scheduled_hook( $hook, $args = array() ) {
    $n = count( $GLOBALS['ri_cron'] );
    $GLOBALS['ri_cron'] = array_values( array_filter( $GLOBALS['ri_cron'], function ( $c ) use ( $hook, $args ) { return ! ( $c['hook'] === $hook && $c['args'] === $args ); } ) );
    return $n - count( $GLOBALS['ri_cron'] );
}

/* ---- Who has seen which reply (3.144.1): user meta, written where wp-kit's get_user_meta() reads. ---- */
if ( ! function_exists( 'update_user_meta' ) ) {
    function update_user_meta( $id, $k, $v, $prev = '' ) { $GLOBALS['kit_umeta'][ (int) $id ][ $k ] = $v; return true; }
}

/* ---- The tables: registrations, stops, the reminder ledger, the messages. ---- */
class Ri_Db extends wpdb {
    public $rsvps = array(); public $stops = array(); public $ledger = array(); public $msgs = array();
    function prepare() { return kit_prepare( func_get_args() ); }
    function esc_like( $t ) { return (string) $t; }
    function suppress_errors( $s = true ) { return false; }
    function insert( $table, $data, $f = null ) {
        if ( false !== strpos( $table, 'uc_text_messages' ) ) {
            $this->insert_id = count( $this->msgs ) + 1;
            $data['id'] = $this->insert_id;
            if ( empty( $data['created_at'] ) ) { $data['created_at'] = date( 'Y-m-d H:i:s' ); }
            $this->msgs[] = (object) $data;
            return 1;
        }
        if ( false !== strpos( $table, 'uc_text_stops' ) ) {
            foreach ( $this->stops as $s ) { if ( $s['phone'] === $data['phone'] ) { return false; } }
            $this->stops[] = $data;
            return 1;
        }
        if ( false !== strpos( $table, 'uc_reminder_log' ) ) {
            foreach ( $this->ledger as $l ) { if ( (int) $l['event_id'] === (int) $data['event_id'] && $l['recipient_hash'] === $data['recipient_hash'] ) { return false; } }
            $this->insert_id = count( $this->ledger ) + 1;
            $data['id'] = $this->insert_id;
            $this->ledger[] = $data;
            return 1;
        }
        return false;
    }
    function update( $table, $data, $where, $f = null, $wf = null ) {
        foreach ( $this->ledger as $i => $l ) { if ( isset( $where['id'] ) && (int) $l['id'] === (int) $where['id'] ) { $this->ledger[ $i ] = array_merge( $l, $data ); } }
        return 1;
    }
    function delete( $table, $where, $f = null ) {
        $this->stops = array_values( array_filter( $this->stops, function ( $s ) use ( $where ) { return $s['phone'] !== $where['phone']; } ) );
        return 1;
    }
    function query( $sql ) {
        if ( preg_match( "/UPDATE \S*uc_text_messages SET alerted = (\d+) WHERE phone = '([^']*)' AND direction = 'in' AND alerted = 0/", $sql, $m ) ) {
            $n = 0;
            foreach ( $this->msgs as $r ) { if ( $r->phone === $m[2] && 'in' === $r->direction && 0 === (int) $r->alerted ) { $r->alerted = (int) $m[1]; $n++; } }
            return $n;
        }
        return 0;
    }
    private function msgs_where( $sql ) {
        $rows = $this->msgs;
        if ( preg_match( "/WHERE id = (\d+)/", $sql, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r->id === (int) $m[1]; } ); }
        if ( preg_match( "/phone = '([^']*)'/", $sql, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r->phone === $m[1]; } ); }
        if ( preg_match( "/channel = '([^']*)'/", $sql, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r->channel === $m[1]; } ); }
        if ( preg_match( "/event_id = (\d+)/", $sql, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r->event_id === (int) $m[1]; } ); }
        if ( false !== strpos( $sql, "( direction = 'in' OR kind = 'organizer' )" ) ) {
            $rows = array_filter( $rows, function ( $r ) { return 'in' === $r->direction || 'organizer' === $r->kind; } );
        } elseif ( preg_match( "/direction = '([a-z]+)'/", $sql, $m ) ) {
            $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r->direction === $m[1]; } );
        }
        if ( preg_match( "/alerted = (\d+)/", $sql, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r->alerted === (int) $m[1]; } ); }
        if ( preg_match( "/created_at < '([^']*)'/", $sql, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r->created_at < $m[1]; } ); }
        $rows = array_values( $rows );
        if ( false !== strpos( $sql, 'ORDER BY id DESC' ) ) { $rows = array_reverse( $rows ); }
        return $rows;
    }
    function get_var( $sql ) {
        if ( preg_match( "/uc_text_stops WHERE phone = '([^']*)'/", $sql, $m ) ) {
            foreach ( $this->stops as $s ) { if ( $s['phone'] === stripslashes( $m[1] ) ) { return 1; } }
        }
        return null;
    }
    function get_col( $sql ) {
        if ( false !== strpos( $sql, 'uc_text_messages' ) ) { return array_values( array_unique( array_map( function ( $r ) { return $r->phone; }, $this->msgs_where( $sql ) ) ) ); }
        return array();
    }
    function get_row( $sql ) {
        if ( false !== strpos( $sql, 'uc_text_messages' ) ) { $r = $this->msgs_where( $sql ); return $r ? $r[0] : null; }
        if ( preg_match( '/uc_rsvps WHERE id = (\d+)/', $sql, $m ) ) {
            foreach ( $this->rsvps as $r ) { if ( (int) $r->id === (int) $m[1] ) { return $r; } }
        }
        return null;
    }
    function get_results( $sql ) {
        if ( false !== strpos( $sql, 'uc_text_messages' ) ) { return $this->msgs_where( $sql ); }
        if ( preg_match( "/event_id = (\d+) AND status (?:= 'confirmed'|IN \(([^)]*)\)) AND text_channel IN/", $sql, $m ) ) {
            $ok = ( isset( $m[2] ) && '' !== $m[2] ) ? array_map( function ( $s ) { return trim( $s, " '" ); }, explode( ',', $m[2] ) ) : array( 'confirmed' );
            return array_values( array_filter( $this->rsvps, function ( $r ) use ( $m, $ok ) {
                return (int) $r->event_id === (int) $m[1] && in_array( $r->status, $ok, true ) && in_array( $r->text_channel, array( 'sms', 'whatsapp' ), true );
            } ) );
        }
        if ( preg_match( "/phone LIKE '%(\d+)'/", $sql, $m ) ) {
            $rows = array_filter( $this->rsvps, function ( $r ) use ( $m ) {
                return in_array( $r->text_channel, array( 'sms', 'whatsapp' ), true ) && substr( preg_replace( '/\D/', '', $r->phone ), -strlen( $m[1] ) ) === $m[1];
            } );
            usort( $rows, function ( $a, $b ) { return $b->id - $a->id; } );
            return array_values( $rows );
        }
        return array();
    }
}
function ri_row( $id, $event, $phone, $channel, $lang = 'en', $first = 'Ana', $last = 'Ruiz' ) {
    return (object) array( 'id' => $id, 'event_id' => $event, 'first_name' => $first, 'last_name' => $last, 'name' => trim( "$first $last" ), 'email' => strtolower( $first ) . '@example.org',
        'phone' => $phone, 'status' => 'confirmed', 'text_channel' => $channel, 'lang' => $lang, 'text_opt_in' => 0, 'format' => '', 'token' => 'tok' . $id );
}
$TOKEN = 'abcdef0123456789abcdef012345WXYZ';
function ri_hx( $c ) { return 'HX' . str_repeat( $c, 32 ); }
function ri_fresh( $wa = true ) {
    global $TOKEN;
    kit_reset();
    $GLOBALS['wpdb']     = new Ri_Db();
    $GLOBALS['tw_calls'] = array();
    $GLOBALS['ri_mail']  = array();
    $GLOBALS['ri_cron']  = array();
    $GLOBALS['kit_trans'] = array();
    unset( $GLOBALS['kit_umeta'] );
    $GLOBALS['kit_users'] = array(
        (object) array( 'ID' => 7, 'display_name' => 'Sam Staff', 'user_email' => 'sam@sfaf.org' ),
        (object) array( 'ID' => 8, 'display_name' => 'Lee Lead', 'user_email' => 'lee@sfaf.org' ),
    );
    kit_write_post( array( 'ID' => 900, 'post_title' => 'Coffee &amp; Conversation', 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
    update_post_meta( 900, '_uc_start_time', '10:00' );
    update_post_meta( 900, '_uc_event_date', '2026-12-01' );
    update_post_meta( 900, '_uc_email_replyto', 'events@sfaf.org' );
    update_post_meta( 900, '_uc_location', '1035 Market St.' );
    kit_write_post( array( 'ID' => 901, 'post_title' => 'Grupo de apoyo', 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
    update_post_meta( 901, '_uc_email_replyto', 'grupo@sfaf.org' );
    $fields = array( 'twilio_account_sid' => 'AC' . str_repeat( 'a', 32 ), 'twilio_messaging_sid' => 'MG' . str_repeat( 'b', 32 ) );
    if ( $wa ) {
        $fields += array( 'twilio_whatsapp_from' => '+14155550100', 'twilio_wa_reminder_en' => ri_hx( 'c' ), 'twilio_wa_reminder_es' => ri_hx( 'd' ), 'twilio_wa_update_en' => ri_hx( 'e' ), 'twilio_wa_update_es' => ri_hx( 'f' ) );
    }
    SFAF_Texts::save( $fields, $TOKEN );
    update_option( 'uc_settings', array( 'email_reply_to' => 'calendar@sfaf.org' ) );
    $db = $GLOBALS['wpdb'];
    $db->rsvps = array(
        ri_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana', 'Ruiz' ),
        ri_row( 2, 900, '(415) 555-0102', 'whatsapp', 'es', 'Beto', 'Luna' ),
        ri_row( 3, 901, '(415) 555-0103', 'sms', 'es', 'Cruz', '' ),
    );
    return $db;
}
function ri_mail_to( $who ) { return array_values( array_filter( $GLOBALS['ri_mail'], function ( $m ) use ( $who ) { return $m['to'] === $who; } ) ); }
