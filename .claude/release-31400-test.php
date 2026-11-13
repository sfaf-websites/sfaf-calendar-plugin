<?php
/**
 * 3.140.0: WHAT COUNTS AS A MOVE, THE CHANGE EMAIL'S MEETING LINK, AND THE
 * WAITLIST IN TEXT REGISTRANTS.
 *
 *     php .claude/release-31400-test.php
 *
 * The real SFAF_Portal, SFAF_Notifications and SFAF_Texts on wp-kit.php, with
 * release-31310-test.php's tables and Twilio recorded, never reached. The
 * script half is release-31400-live.php.
 *
 *   M. movable_facts() has a Meeting link fact that is a fingerprint, never
 *      the link; nothing touched is no move (RSVP, description); a new link,
 *      a new time and a new place are moves
 *   E. The change email for a new link: a Meeting link row, "Changed"; the
 *      new link only where the confirmation already carries it; "will be sent
 *      with your reminder" where only the reminder does; neither link printed
 *      otherwise, and the fingerprint never
 *   W. Text registrants: Include the waitlist is on the form, the waiting
 *      person is listed marked waiting and is not ticked; a send without the
 *      tick never reaches them, with it they get it
 *
 * PLANTs in plant-31400.php.
 */
require __DIR__ . '/wp-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $ok ) {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) { $fails[] = $label; }
}
function rt_cap( $fn ) {
    $d = ob_get_level();
    ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; }
    return $h;
}
function rt_x( $html ) {
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    libxml_clear_errors();
    return new DOMXPath( $doc );
}
function rt_text( $n ) { return trim( preg_replace( '/\s+/', ' ', $n ? $n->textContent : '' ) ); }

/* ---- Twilio, recorded and never reached. ---- */
$GLOBALS['tw_calls'] = array();
$GLOBALS['tw_answer'] = array( 'code' => 201, 'body' => '{"sid":"SM1"}' );
if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        $GLOBALS['tw_calls'][] = array( 'url' => $url, 'args' => $args );
        return array( 'response' => array( 'code' => $GLOBALS['tw_answer']['code'] ), 'body' => $GLOBALS['tw_answer']['body'] );
    }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = array() ) { return wp_remote_request( $url, $args ); }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; }
}
if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $p = '' ) { return 'https://resources.sfaf.org/wp-json/' . ltrim( $p, '/' ); }
}
if ( ! function_exists( 'is_ssl' ) ) {
    function is_ssl() { return true; }
}

/* ---- The tables this release writes: registrations, stops, the reminder ledger. ---- */
class Tw_Db extends wpdb {
    public $rsvps = array(); public $stops = array(); public $ledger = array();
    function prepare() { return kit_prepare( func_get_args() ); }
    function esc_like( $t ) { return (string) $t; }
    function suppress_errors( $s = true ) { return false; }
    function insert( $table, $data, $f = null ) {
        if ( false !== strpos( $table, 'uc_text_stops' ) ) {
            foreach ( $this->stops as $s ) { if ( $s['phone'] === $data['phone'] ) { return false; } }
            $this->stops[] = $data;
            return 1;
        }
        if ( false !== strpos( $table, 'uc_reminder_log' ) ) {
            foreach ( $this->ledger as $l ) {
                if ( (int) $l['event_id'] === (int) $data['event_id'] && $l['recipient_hash'] === $data['recipient_hash'] ) { return false; }
            }
            $this->insert_id = count( $this->ledger ) + 1;
            $data['id'] = $this->insert_id;
            $this->ledger[] = $data;
            return 1;
        }
        return false;
    }
    function update( $table, $data, $where, $f = null, $wf = null ) {
        foreach ( $this->ledger as $i => $l ) {
            if ( isset( $where['id'] ) && (int) $l['id'] === (int) $where['id'] ) { $this->ledger[ $i ] = array_merge( $l, $data ); }
        }
        return 1;
    }
    function delete( $table, $where, $f = null ) {
        $this->stops = array_values( array_filter( $this->stops, function ( $s ) use ( $where ) { return $s['phone'] !== $where['phone']; } ) );
        return 1;
    }
    function get_var( $sql ) {
        if ( preg_match( "/uc_text_stops WHERE phone = '([^']*)'/", $sql, $m ) ) {
            foreach ( $this->stops as $s ) { if ( $s['phone'] === stripslashes( $m[1] ) ) { return 1; } }
        }
        return null;
    }
    function get_row( $sql ) {
        if ( false !== strpos( $sql, 'text_opt_in = 1' ) ) {
            $old = array_filter( $this->rsvps, function ( $r ) { return 1 === (int) $r->text_opt_in && '' === $r->text_channel; } );
            return (object) array( 'n' => count( $old ), 'e' => count( array_unique( wp_list_pluck( $old, 'event_id' ) ) ) );
        }
        return null;
    }
    function get_results( $sql ) {
        // Confirmed, or with the waitlist as status IN (...) (3.140.0).
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
function tw_row( $id, $event, $phone, $channel, $lang = '', $first = 'Ana', $old = 0 ) {
    return (object) array( 'id' => $id, 'event_id' => $event, 'first_name' => $first, 'last_name' => '', 'name' => $first, 'email' => strtolower( $first ) . '@example.org',
        'phone' => $phone, 'status' => 'confirmed', 'text_channel' => $channel, 'lang' => $lang, 'text_opt_in' => $old, 'format' => '' );
}
function tw_fresh() {
    kit_reset();
    $GLOBALS['wpdb'] = new Tw_Db();
    $GLOBALS['tw_calls'] = array();
    $GLOBALS['tw_answer'] = array( 'code' => 201, 'body' => '{"sid":"SM1"}' );
    kit_write_post( array( 'ID' => 900, 'post_title' => 'Coffee &amp; Conversation', 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
    update_post_meta( 900, '_uc_start_time', '10:00' );
    update_post_meta( 900, '_uc_email_replyto', 'events@sfaf.org' );
    update_post_meta( 900, '_uc_location', '1035 Market St.' );
}
$TOKEN = 'abcdef0123456789abcdef012345WXYZ';
$SID   = 'AC' . str_repeat( 'a', 32 );
$MG    = 'MG' . str_repeat( 'b', 32 );
function tw_hx( $c ) { return 'HX' . str_repeat( $c, 32 ); }
function tw_texts_on() {
    global $TOKEN, $SID, $MG;
    return SFAF_Texts::save( array( 'twilio_account_sid' => $SID, 'twilio_messaging_sid' => $MG ), $TOKEN );
}
function tw_wa_on() {
    return SFAF_Texts::save( array(
        'twilio_account_sid' => $GLOBALS['SID'], 'twilio_messaging_sid' => $GLOBALS['MG'], 'twilio_whatsapp_from' => '+1 (415) 555-0100',
        'twilio_wa_reminder_en' => tw_hx( 'c' ), 'twilio_wa_reminder_es' => tw_hx( 'd' ), 'twilio_wa_update_en' => tw_hx( 'e' ), 'twilio_wa_update_es' => tw_hx( 'f' ),
    ) );
}

tw_fresh();
tw_texts_on();
$db = $GLOBALS['wpdb'];

/* ---- M. What counts as a move. ---- */
update_post_meta( 900, '_uc_event_date', date( 'Y-m-d', strtotime( '+7 days' ) ) );
update_post_meta( 900, '_uc_end_time', '12:00' );
update_post_meta( 900, '_uc_rsvp_enabled', '1' );
SFAF_Online::set( 900, SFAF_Online::MODE_ONLINE, 'https://zoom.us/j/11112222333', array( 'confirmation' ) );
$f0 = SFAF_Portal::movable_facts( 900 );
rt( 'M: there is a Meeting link fact', isset( $f0['Meeting link'] ) && '' !== $f0['Meeting link'] );
rt( 'M: the fact is a fingerprint, not the link', false === strpos( wp_json_encode( $f0 ), 'zoom.us' ) );
update_post_meta( 900, '_uc_rsvp_enabled', '' );
update_post_meta( 900, '_uc_rsvp_enabled', '1' );
kit_write_post( array( 'ID' => 900, 'post_title' => 'Coffee &amp; Conversation', 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1, 'post_content' => '<p>New words.</p>' ) );
rt( 'M: RSVP off and on, and the description, are no move', array() === SFAF_Portal::movable_diff( $f0, 900 ) );
SFAF_Online::set( 900, SFAF_Online::MODE_ONLINE, 'https://zoom.us/j/99998888777', array( 'confirmation' ) );
$d1 = SFAF_Portal::movable_diff( $f0, 900 );
rt( 'M: a new link is a move, and only that', array( 'Meeting link' ) === array_keys( $d1 ) );
rt( 'M: the move carries no link either side', false === strpos( wp_json_encode( $d1 ), 'zoom.us' ) );
update_post_meta( 900, '_uc_start_time', '11:00' );
$d2 = SFAF_Portal::movable_diff( $f0, 900 );
rt( 'M: a new start is a Time move, from what was stored', isset( $d2['Time'] ) && 'not set' !== $d2['Time']['from'] && $d2['Time']['from'] !== $d2['Time']['to'] );
update_post_meta( 900, '_uc_start_time', '10:00' );

/* ---- E. The change email for a new link. ---- */
$person = (object) array( 'id' => 1, 'name' => 'Ana', 'first_name' => 'Ana', 'email' => 'ana@example.org', 'token' => str_repeat( 'a', 32 ), 'lang' => 'en', 'format' => '' );
$mail = function () use ( $person, $d1 ) {
    $m = SFAF_Notifications::build( 'changed', 900, $person, array( 'changes' => $d1 ) );
    return is_array( $m ) ? ( ( isset( $m['html'] ) ? $m['html'] : '' ) . "\n" . ( isset( $m['text'] ) ? $m['text'] : '' ) ) : (string) $m;
};
$conf = $mail();
rt( 'E: a Meeting link row says Changed', false !== strpos( $conf, 'Meeting link' ) && false !== strpos( $conf, 'Changed' ) );
rt( 'E: with the confirmation carrying it, the new link is in the email', false !== strpos( $conf, 'https://zoom.us/j/99998888777' ) );
rt( 'E: the old link is not', false === strpos( $conf, '11112222333' ) );
rt( 'E: no fingerprint in the email', false === strpos( $conf, $d1['Meeting link']['to'] ) && false === strpos( $conf, $d1['Meeting link']['from'] ) );
SFAF_Online::set( 900, SFAF_Online::MODE_ONLINE, 'https://zoom.us/j/99998888777', array( 'reminder' ) );
$rem = $mail();
rt( 'E: reminder only: no link, and it will come with the reminder', false === strpos( $rem, 'zoom.us' ) && false !== strpos( $rem, 'The new link will be sent with your reminder.' ) );
SFAF_Online::set( 900, SFAF_Online::MODE_ONLINE, 'https://zoom.us/j/99998888777', array() );
$none = $mail();
rt( 'E: sent with neither: no link and no reminder line', false === strpos( $none, 'zoom.us' ) && false === strpos( $none, 'with your reminder' ) && false !== strpos( $none, 'Changed' ) );

/* ---- W. The waitlist in Text registrants. ---- */
tw_fresh();
tw_texts_on();
$db = $GLOBALS['wpdb'];
$ana  = tw_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana' );
$wait = tw_row( 2, 900, '(415) 555-0102', 'sms', 'en', 'Wren' );
$wait->status = SFAF_Waitlist::WAITING;
$db->rsvps = array( $ana, $wait );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me = new WP_User();
$html = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
$x = rt_x( $html );
rt( 'W: the section renders', 0 !== strpos( $html, 'THREW' ) && $x->query( '//*[@data-uc-text-registrants]' )->length );
$tick = $x->query( '//form[@data-uc-tr-form]//input[@name="tr_waitlist"][@data-uc-rm-waitlist]' );
rt( 'W: Include the waitlist is on the form', 1 === $tick->length && false !== strpos( rt_text( $tick->item( 0 )->parentNode ), 'Include the waitlist' ) );
$wrow = $x->query( '//*[@data-uc-rcp-row][@data-uc-rcp-wait]' );
rt( 'W: the waiting person is listed, marked waiting', 1 === $wrow->length && false !== strpos( rt_text( $wrow->item( 0 ) ), 'Wren' ) );
$send = $x->query( '//button[@data-uc-tr-send]' );
rt( 'W: Send counts only who is registered', 1 === $send->length && false !== strpos( $send->item( 0 )->getAttribute( 'data-uc-confirm' ), 'Send to 1 person?' ) );
$GLOBALS['tw_calls'] = array();
$out = SFAF_Texts::send_update( 900, 'Doors at 9:45', 1, array( 1, 2 ), false );
$tos = array_map( function ( $c ) { return $c['args']['body']['To']; }, $GLOBALS['tw_calls'] );
rt( 'W: without the tick, the waitlist gets nothing', array( '+14155550101' ) === $tos && 1 === $out['sms'] );
$GLOBALS['tw_calls'] = array();
$out = SFAF_Texts::send_update( 900, 'Doors at 9:45', 1, array( 1, 2 ), true );
$tos = array_map( function ( $c ) { return $c['args']['body']['To']; }, $GLOBALS['tw_calls'] );
rt( 'W: with it, they get it', array( '+14155550101', '+14155550102' ) === $tos && 2 === $out['sms'] );
rt( 'W: the reminder still goes to who is registered only', 1 === count( SFAF_Texts::plan( 900 ) ) );

echo "release-31400: $ran checks\n";
if ( $fails ) { foreach ( $fails as $f ) { echo "FAIL  $f\n"; } exit( 1 ); }
echo "every check passed.\n";
exit( 0 );
