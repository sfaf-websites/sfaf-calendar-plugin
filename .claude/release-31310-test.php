<?php
/**
 * 3.131.0: THE CANCEL LINK IN THE MORNING-OF REMINDER, BY TEXT AND WHATSAPP.
 *
 *     php .claude/release-31310-test.php
 *
 * The real SFAF_Texts on wp-kit.php, with release-31300-test.php's tables and
 * Twilio recorded, never reached.
 *
 *   A. WhatsApp: the reminder's {{5}} is the person's own cancel link, the
 *      one their confirmation email carries (SFAF_Reminders::cancel_url() of
 *      the registration's token), never another person's; never empty.
 *   B. Text: the reminder ends "Can't make it? Cancel here: [link]", in
 *      Spanish "¿No puedes venir? Cancela aquí: [link]", in Mark's words,
 *      then "Reply STOP to opt out."; without a token, no cancel line.
 *   C. Nothing else moves: Text registrants' update has no cancel line and
 *      its WhatsApp template keeps three variables.
 *
 * PLANTs in plant-31310.php.
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
        if ( preg_match( "/event_id = (\d+) AND status = 'confirmed' AND text_channel IN/", $sql, $m ) ) {
            return array_values( array_filter( $this->rsvps, function ( $r ) use ( $m ) {
                return (int) $r->event_id === (int) $m[1] && 'confirmed' === $r->status && in_array( $r->text_channel, array( 'sms', 'whatsapp' ), true );
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
tw_wa_on();
$db = $GLOBALS['wpdb'];
$ana  = tw_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana' );       $ana->token  = str_repeat( 'a', 32 );
$beto = tw_row( 2, 900, '(415) 555-0102', 'whatsapp', 'es', 'Beto' ); $beto->token = str_repeat( 'b', 32 );
$cruz = tw_row( 3, 900, '(415) 555-0103', 'whatsapp', 'en', 'Cruz' ); $cruz->token = str_repeat( 'c', 32 );
$dee  = tw_row( 4, 900, '(415) 555-0104', 'sms', 'es', 'Dee' );       $dee->token  = str_repeat( 'd', 32 );
$eli  = tw_row( 5, 900, '(415) 555-0105', 'sms', 'en', 'Eli' );       $eli->token  = '';
$fay  = tw_row( 6, 900, '(415) 555-0106', 'whatsapp', 'en', 'Fay' );  $fay->token  = '';
$db->rsvps = array( $ana, $beto, $cruz, $dee, $eli, $fay );
$r = SFAF_Texts::send_reminders( 900 );
$by = array();
foreach ( $GLOBALS['tw_calls'] as $c ) { $by[ str_replace( 'whatsapp:', '', $c['args']['body']['To'] ) ] = $c['args']['body']; }
rt( 'every reminder went (' . $r['sent'] . ')', 6 === $r['sent'] && 6 === count( $by ) );
$link = function ( $t ) { return SFAF_Reminders::cancel_url( $t ); };
rt( 'the link is the confirmation email\'s: cancel_url() of the registration token', false !== strpos( $link( 'xyz' ), 'uc_rsvp_cancel=xyz' ) && $link( $ana->token ) === SFAF_Texts::cancel_link( $ana ) );

/* ---- A. WhatsApp ---- */
$vb = json_decode( $by['+14155550102']['ContentVariables'], true );
$vc = json_decode( $by['+14155550103']['ContentVariables'], true );
rt( 'A: five variables', array( '1', '2', '3', '4', '5' ) === array_map( 'strval', array_keys( (array) $vb ) ) );
rt( "A: {{5}} is Beto's own cancel link", $link( $beto->token ) === $vb['5'] );
rt( "A: {{5}} is Cruz's own, not Beto's", $link( $cruz->token ) === $vc['5'] && $vb['5'] !== $vc['5'] );
rt( 'A: {{1}} to {{4}} are as before', 'Coffee & Conversation' === $vb['1'] && 'https://resources.sfaf.org/events/900/' === $vb['4'] && '' !== $vb['2'] && '' !== $vb['3'] );
rt( 'A: the Spanish template for a Spanish registrant, the English for an English one', tw_hx( 'd' ) === $by['+14155550102']['ContentSid'] && tw_hx( 'c' ) === $by['+14155550103']['ContentSid'] );
$vf = json_decode( $by['+14155550106']['ContentVariables'], true );
rt( 'A: without a token, {{5}} is never empty', '' !== (string) $vf['5'] && 'https://resources.sfaf.org/events/900/' === $vf['5'] );

/* ---- B. Text ---- */
rt( 'B: the English reminder ends with the cancel line, then STOP', 'Reminder from SFAF: Coffee & Conversation is today at 10 am, at 1035 Market St. Event details: https://resources.sfaf.org/events/900/ Can\'t make it? Cancel here: ' . $link( $ana->token ) . ' Reply STOP to opt out.' === $by['+14155550101']['Body'] );
rt( 'B: the Spanish reminder in Mark\'s words', false !== strpos( $by['+14155550104']['Body'], ' ¿No puedes venir? Cancela aquí: ' . $link( $dee->token ) . ' Responda STOP' ) && 0 === strpos( $by['+14155550104']['Body'], 'Recordatorio de SFAF: ' ) );
rt( 'B: each text carries its own link', false === strpos( $by['+14155550101']['Body'], $dee->token ) && false === strpos( $by['+14155550104']['Body'], $ana->token ) );
rt( 'B: without a token, no cancel line', false === strpos( $by['+14155550105']['Body'], 'Cancel here' ) && false !== strpos( $by['+14155550105']['Body'], 'Event details: https://resources.sfaf.org/events/900/ Reply STOP' ) );

/* ---- C. Nothing else moves ---- */
$GLOBALS['tw_calls'] = array();
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 1 );
$ub = array();
foreach ( $GLOBALS['tw_calls'] as $c ) { $ub[ str_replace( 'whatsapp:', '', $c['args']['body']['To'] ) ] = $c['args']['body']; }
rt( 'C: the update has no cancel line', isset( $ub['+14155550101'] ) && false === strpos( $ub['+14155550101']['Body'], 'Cancel' ) );
rt( 'C: the update template keeps three variables', isset( $ub['+14155550102'] ) && array( '1', '2', '3' ) === array_map( 'strval', array_keys( (array) json_decode( $ub['+14155550102']['ContentVariables'], true ) ) ) );

echo "release-31310: $ran checks\n";
if ( $fails ) {
    foreach ( $fails as $f ) { echo "FAIL  $f\n"; }
    exit( 1 );
}
echo "every check passed.\n";
