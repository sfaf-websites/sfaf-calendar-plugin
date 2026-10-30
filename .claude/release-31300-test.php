<?php
/**
 * 3.130.0: TEXT AND WHATSAPP, THROUGH TWILIO.
 *
 *     php .claude/release-31300-test.php
 *
 * The real SFAF_Texts, SFAF_Reminders::claim_key(), the panel and the RSVP
 * page's section, on wp-kit.php, with the tables they write modelled here and
 * every request to Twilio recorded instead of sent.
 *
 *   A. Settings: each SID checked; the Auth Token sealed, never in the page,
 *      only its last four shown; texts on with the account, token and
 *      Messaging Service; WhatsApp on only with the sender and all four
 *      templates; an error never carries the token.
 *   B. The form: nothing offered until texts can go; then Text, WhatsApp and
 *      Neither, Neither chosen, Mark's consent line word for word in English
 *      and Spanish, the privacy policy; a channel kept only with a number and
 *      only when offered. The old "Text me about this event" is gone from the
 *      form and from Email Templates; its rows are counted, not changed.
 *   C. The morning-of reminder: a text and a WhatsApp message beside the
 *      email, once per number however many runs, nothing to a stopped number,
 *      nothing by WhatsApp until it is on; each in the person's language; the
 *      template's four variables, none empty.
 *   D. Text registrants: who gets it and how, listed before the box; the
 *      button counts only those it can reach; "WhatsApp isn't active yet";
 *      the send is logged; an empty message sends nothing.
 *   E. Replies and STOP: STOP and PARAR recorded on either channel and
 *      honoured on both; START takes it off; HELP answered on WhatsApp and
 *      left to Twilio on a text; anything else
 *      gets Mark's line once a day, with Replies go to, else the site
 *      default; an unsigned request refused, Twilio's own example accepted.
 *   F. The wiring: schema 18, the column and the table, the route behind the
 *      event gate, the reminder run calling the texts before the event is
 *      marked done.
 *
 * PLANTs in plant-31300.php.
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

/* =====================================================================
 * A. Settings
 * =================================================================== */
tw_fresh();
rt( 'A: texts are off with nothing saved', ! SFAF_Texts::sms_on() );
$bad = SFAF_Texts::save( array( 'twilio_account_sid' => 'AC123', 'twilio_messaging_sid' => $MG ), $TOKEN );
rt( 'A: a short Account SID is refused, and nothing is saved', ! $bad['ok'] && false !== strpos( $bad['message'], 'Account SID' ) && '' === SFAF_Texts::setting( 'twilio_messaging_sid' ) );
$ok = tw_texts_on();
rt( 'A: a good account, token and Messaging Service save', $ok['ok'] );
$stored = SFAF_Credentials::raw( 'twilio_auth_token' );
rt( 'A: the token is stored sealed, not as typed', '' !== $stored && false === strpos( $stored, $TOKEN ) && 0 === strpos( $stored, 'mc1:' ) );
rt( 'A: texts are on', SFAF_Texts::sms_on() );
rt( 'A: WhatsApp is off without the sender and templates', ! SFAF_Texts::whatsapp_on() );
rt( 'A: the hint shows the last four only', 'Saved, ending WXYZ.' === SFAF_Texts::token_hint() );
SFAF_Texts::save( array( 'twilio_account_sid' => $SID, 'twilio_messaging_sid' => $MG ), '' );
rt( 'A: a blank token box keeps the token', 'Saved, ending WXYZ.' === SFAF_Texts::token_hint() );
SFAF_Texts::save( array( 'twilio_account_sid' => $SID, 'twilio_messaging_sid' => $MG, 'twilio_whatsapp_from' => '+14155550100', 'twilio_wa_reminder_en' => tw_hx( 'c' ), 'twilio_wa_reminder_es' => tw_hx( 'd' ), 'twilio_wa_update_en' => tw_hx( 'e' ) ) );
rt( 'A: three templates of four leave WhatsApp off', ! SFAF_Texts::whatsapp_on() );
$wa = tw_wa_on();
rt( 'A: all four templates and the sender turn WhatsApp on', $wa['ok'] && SFAF_Texts::whatsapp_on() );
rt( 'A: the sender is kept as +1 and ten digits', '+14155550100' === SFAF_Texts::setting( 'twilio_whatsapp_from' ) );
$bad = SFAF_Texts::save( array( 'twilio_account_sid' => $SID, 'twilio_messaging_sid' => $MG, 'twilio_wa_update_es' => 'HXnope' ) );
rt( 'A: a malformed template SID is refused', ! $bad['ok'] && false !== strpos( $bad['message'], 'HX' ) );
$panel = rt_cap( function () { SFAF_Texts::render_panel(); } );
rt( 'A: the panel renders', false === strpos( $panel, 'THREW' ) && false !== strpos( $panel, 'data-uc-twilio-panel' ) );
rt( 'A: the token is nowhere in the panel', false === strpos( $panel, $TOKEN ) && false === strpos( $panel, $stored ) );
$px = rt_x( $panel );
$tok = $px->query( '//input[@name="twilio_token"]' )->item( 0 );
rt( 'A: the token box is a password box, always empty', $tok && 'password' === $tok->getAttribute( 'type' ) && '' === $tok->getAttribute( 'value' ) );
rt( 'A: Test connection is on the panel', 1 === $px->query( '//button[@data-uc-twilio-test]' )->length );
rt( 'A: the panel gives the incoming message address', 1 === $px->query( '//input[@data-uc-twilio-incoming and @readonly and @value="' . SFAF_Texts::incoming_url() . '"]' )->length );
rt( 'A: the four template boxes', 4 === $px->query( '//input[starts-with(@name,"twilio_wa_")]' )->length );
$GLOBALS['tw_answer'] = array( 'code' => 401, 'body' => '{"message":"Bad token ' . $TOKEN . '","code":20003}' );
$r = SFAF_Texts::api( 'POST', '/Messages.json', array( 'To' => '+14155550123' ) );
rt( 'A: an error from Twilio never carries the token', ! $r['ok'] && false === strpos( $r['error'], $TOKEN ) && false !== strpos( $r['error'], '[token]' ) );
rt( 'A: the request authenticates with the SID and the token', isset( $GLOBALS['tw_calls'][0]['args']['headers']['Authorization'] ) && 'Basic ' . base64_encode( $SID . ':' . $TOKEN ) === $GLOBALS['tw_calls'][0]['args']['headers']['Authorization'] );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-texts.php' );
rt( 'A: the save and the test are administrators only', 2 <= substr_count( $src, "current_user_can( 'manage_options' )" ) && false !== strpos( $src, "check_admin_referer( 'sfaf_twilio_save' )" ) );

/* =====================================================================
 * B. The form
 * =================================================================== */
tw_fresh();
rt( 'B: nothing is offered until texts can go', array() === SFAF_Texts::form_channels() );
rt( 'B: a channel is not kept while texts are off', '' === SFAF_Texts::clean_channel( 'sms', '(415) 555-0123' ) );
tw_texts_on();
rt( 'B: Text and WhatsApp offered once texts can go', array( 'sms', 'whatsapp' ) === SFAF_Texts::form_channels() );
rt( 'B: WhatsApp offered before its templates are in', ! SFAF_Texts::whatsapp_on() && in_array( 'whatsapp', SFAF_Texts::form_channels(), true ) );
rt( 'B: a channel without a number is dropped', '' === SFAF_Texts::clean_channel( 'sms', '' ) );
rt( 'B: a channel the form did not offer is dropped', '' === SFAF_Texts::clean_channel( 'pigeon', '(415) 555-0123' ) );
rt( 'B: Text with a number is kept', 'sms' === SFAF_Texts::clean_channel( 'sms', '(415) 555-0123' ) );
rt( 'B: WhatsApp with a number is kept', 'whatsapp' === SFAF_Texts::clean_channel( 'whatsapp', '(415) 555-0123' ) );
$fw = SFAF_Messages::form_words();
rt( "B: Mark's consent line, word for word", 'Text me updates about this event. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help.' === $fw['en']['text_consent'] );
rt( 'B: the consent line in Spanish names STOP and HELP', false !== strpos( $fw['es']['text_consent'], 'STOP' ) && false !== strpos( $fw['es']['text_consent'], 'HELP' ) && $fw['es']['text_consent'] !== $fw['en']['text_consent'] );
foreach ( array( 'updates_by', 'by_sms', 'by_whatsapp', 'by_none', 'privacy' ) as $k ) {
    rt( "B: the form has '$k' in both languages", isset( $fw['en'][ $k ], $fw['es'][ $k ] ) && '' !== $fw['en'][ $k ] && '' !== $fw['es'][ $k ] );
}
rt( 'B: the old line is gone from the form words', ! isset( $fw['en']['text_opt_in'] ) );
rt( 'B: the old line is gone from Email Templates', ! isset( SFAF_Messages::catalog()['text_opt_in'] ) );
$js = file_get_contents( dirname( __DIR__ ) . '/public/js/calendar.js' );
rt( 'B: three radios, Neither chosen', 1 === preg_match( '/name="uc-rsvp-text-channel" value="sms" \/>.*name="uc-rsvp-text-channel" value="whatsapp" \/>.*name="uc-rsvp-text-channel" value="" checked \/>/s', $js ) );
rt( 'B: the choice is hidden until a number is typed', false !== strpos( $js, '<fieldset class="uc-rsvp-texts" id="uc-rsvp-text-wrap" hidden>' ) && false !== strpos( $js, "if (\$(this).val().trim() && currentTexts.length)" ) );
rt( 'B: the privacy policy is linked beside the choice', false !== strpos( $js, 'id="uc-rsvp-privacy" href="https://www.sfaf.org/privacy-policy/"' ) && 'https://www.sfaf.org/privacy-policy/' === SFAF_Texts::PRIVACY_URL );
rt( 'B: the form posts text_channel, not text_opt_in', false !== strpos( $js, 'text_channel: (phone && currentTexts.length)' ) && false === strpos( $js, 'text_opt_in' ) );
rt( 'B: opening the form chooses Neither again', false !== strpos( $js, "\$('input[name=\"uc-rsvp-text-channel\"][value=\"\"]').prop('checked', true);\n            \$('#uc-rsvp-text-wrap').attr('hidden', 'hidden');" ) );
$tf = file_get_contents( dirname( __DIR__ ) . '/includes/sfaf-template-functions.php' );
rt( 'B: the button carries what the server offers', false !== strpos( $tf, "'data-uc-texts'   => implode( ',', SFAF_Texts::form_channels() )," ) && false === strpos( $tf, 'data-uc-text-label' ) );
$GLOBALS['wpdb']->rsvps = array( tw_row( 1, 900, '(415) 555-0101', '', '', 'Old', 1 ), tw_row( 2, 901, '(415) 555-0102', '', '', 'Older', 1 ), tw_row( 3, 900, '(415) 555-0103', 'sms' ) );
$old = SFAF_Texts::old_ticks();
rt( 'B: the old ticks are counted, rows and events', 2 === $old['rows'] && 2 === $old['events'] );
rt( 'B: an old tick reaches nobody', 'none' === SFAF_Texts::reach( $GLOBALS['wpdb']->rsvps[0] )['state'] );
$panel = rt_cap( function () { SFAF_Texts::render_panel(); } );
rt( 'B: the panel says how many old ticks there are', false !== strpos( $panel, '2 registrations on 2 events ticked the old' ) );
$rs = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-rsvp.php' );
rt( 'B: a registration stores the channel', false !== strpos( $rs, "'text_channel' => \$data['text_channel']," ) && false !== strpos( $rs, "SFAF_Texts::clean_channel(" ) );
if ( preg_match( "/\\\$inserted = \\\$wpdb->insert\( \\\$table, array\((.*?)\), array\(([^)]*)\) \);/s", $rs, $ins ) ) {
    $cols = preg_match_all( "/^\s*'[a-z_]+'\s*=>/m", $ins[1] );
    $fmts = substr_count( $ins[2], "'%" );
    rt( "B: the insert's formats match its columns ($cols, $fmts)", $cols === $fmts );
} else {
    rt( 'B: the registration insert was found', false );
}
$wl = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-waitlist.php' );
rt( 'B: a waitlist join keeps the channel', false !== strpos( $wl, "foreach ( array( 'agreed_at', 'text_channel' ) as \$k ) {" ) );

/* =====================================================================
 * C. The morning-of reminder
 * =================================================================== */
tw_fresh();
tw_texts_on();
$db = $GLOBALS['wpdb'];
$db->rsvps = array(
    tw_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana' ),
    tw_row( 2, 900, '(415) 555-0102', 'whatsapp', 'es', 'Beto' ),
    tw_row( 3, 900, '(415) 555-0103', 'sms', 'es', 'Cruz' ),
    tw_row( 4, 900, '(415) 555-0104', '', 'en', 'Dee' ),
    tw_row( 5, 900, '(415) 555-0105', 'sms', 'en', 'Eli' ),
);
SFAF_Texts::record_stop( '+14155550105', 'sms', 'STOP' );
$r1 = SFAF_Texts::send_reminders( 900 );
$calls = $GLOBALS['tw_calls'];
rt( 'C: two texts go, nothing by WhatsApp while it is off, nothing to Neither or a stop', 2 === $r1['sent'] && 2 === count( $calls ) );
$to = array_map( function ( $c ) { return $c['args']['body']['To']; }, $calls );
rt( 'C: to the two who chose Text', array( '+14155550101', '+14155550103' ) === $to );
rt( 'C: a text goes through the Messaging Service', $GLOBALS['MG'] === $calls[0]['args']['body']['MessagingServiceSid'] );
rt( 'C: the English reminder, as the template reads', 'Reminder from SFAF: Coffee & Conversation is today at 10 am, at 1035 Market St. Event details: https://resources.sfaf.org/events/900/ Reply STOP to opt out.' === $calls[0]['args']['body']['Body'] );
rt( 'C: the Spanish reminder for the one who chose Spanish', 0 === strpos( $calls[1]['args']['body']['Body'], 'Recordatorio de SFAF: Coffee & Conversation es hoy a las ' ) && false !== strpos( $calls[1]['args']['body']['Body'], 'Responda STOP' ) );
$GLOBALS['tw_calls'] = array();
$r2 = SFAF_Texts::send_reminders( 900 );
rt( 'C: a second run sends nothing again', 0 === $r2['sent'] && 2 === $r2['already'] && 0 === count( $GLOBALS['tw_calls'] ) );
rt( 'C: each text is a ledger row, finished as sent', 2 === count( array_filter( $db->ledger, function ( $l ) { return 'sent' === $l['result'] && 'sms' === $l['recipient_type']; } ) ) );
tw_wa_on();
$GLOBALS['tw_calls'] = array();
$r3 = SFAF_Texts::send_reminders( 900 );
rt( 'C: WhatsApp on: the WhatsApp reminder goes, once', 1 === $r3['sent'] && 1 === count( $GLOBALS['tw_calls'] ) );
$wb = $GLOBALS['tw_calls'][0]['args']['body'];
rt( 'C: from and to as WhatsApp numbers', 'whatsapp:+14155550100' === $wb['From'] && 'whatsapp:+14155550102' === $wb['To'] );
rt( "C: the Spanish reminder template for a Spanish registrant", tw_hx( 'd' ) === $wb['ContentSid'] );
$vars = json_decode( $wb['ContentVariables'], true );
rt( 'C: four variables, none empty', is_array( $vars ) && array( '1', '2', '3', '4' ) === array_map( 'strval', array_keys( $vars ) ) && 4 === count( array_filter( $vars, 'strlen' ) ) );
rt( 'C: the variables are name, time, place, link', 'Coffee & Conversation' === $vars['1'] && 'https://resources.sfaf.org/events/900/' === $vars['4'] );
delete_post_meta( 900, '_uc_location' );
delete_post_meta( 900, '_uc_start_time' );
$f = SFAF_Texts::facts( 900, 'en' );
rt( 'C: with no place or time the words are still there', '' !== $f['place'] && '' !== $f['time'] );

/* =====================================================================
 * D. Text registrants
 * =================================================================== */
tw_fresh();
tw_texts_on();
$db = $GLOBALS['wpdb'];
$db->rsvps = array(
    tw_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana' ),
    tw_row( 2, 900, '(415) 555-0102', 'whatsapp', 'es', 'Beto' ),
    tw_row( 3, 900, '(415) 555-0103', 'sms', 'es', 'Cruz' ),
    tw_row( 4, 900, '(415) 555-0101', 'sms', 'en', 'Ana again' ),
);
SFAF_Texts::record_stop( '+14155550103', 'whatsapp', 'PARAR' );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me     = new WP_User();
$html   = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
rt( 'D: the section renders', false === strpos( $html, 'THREW' ) && false !== strpos( $html, 'data-uc-text-registrants' ) );
$x = rt_x( $html );
rt( 'D: it is the Email registrants component', 1 === $x->query( '//details[contains(@class,"uc-card") and contains(@class,"uc-rsvp-settings")]' )->length && 'Text registrants' === rt_text( $x->query( '//summary//strong' )->item( 0 ) ) );
$states = array();
foreach ( $x->query( '//tr[@data-uc-tr-state]' ) as $tr ) { $states[] = $tr->getAttribute( 'data-uc-tr-state' ) . ':' . rt_text( $tr->getElementsByTagName( 'td' )->item( 2 ) ); }
rt( 'D: who gets it and how, one row a number (' . implode( ' | ', $states ) . ')', array( 'sms:Text', "whatsapp_off:Email only: WhatsApp isn't active yet", 'stopped:Nothing: this number sent STOP' ) === $states );
rt( "D: the page says WhatsApp isn't active yet", 1 === $x->query( '//*[@data-uc-tr-wa-off]' )->length && false !== strpos( rt_text( $x->query( '//*[@data-uc-tr-wa-off]' )->item( 0 ) ), "WhatsApp isn't active yet. 1 person chose WhatsApp and gets email only." ) );
$btn = $x->query( '//button[@data-uc-tr-send]' )->item( 0 );
rt( 'D: the button counts only those it can reach', $btn && 'Send to 1 person? A text cannot be recalled.' === $btn->getAttribute( 'data-uc-confirm' ) && ! $btn->hasAttribute( 'disabled' ) );
$form = $x->query( '//form[@data-uc-tr-form]' )->item( 0 );
rt( 'D: the form posts text_registrants_send with its nonce', $form && 1 === $x->query( './/input[@name="uc_action" and @value="text_registrants_send"]', $form )->length && 1 === $x->query( './/input[@name="uc_nonce"]', $form )->length );
rt( 'D: the box is limited to 300 characters', 1 === $x->query( '//textarea[@name="tr_message" and @maxlength="300"]' )->length );
$GLOBALS['tw_calls'] = array();
$empty = SFAF_Texts::send_update( 900, "   \n ", 1 );
rt( 'D: an empty message sends nothing and logs nothing', ! empty( $empty['error'] ) && 0 === count( $GLOBALS['tw_calls'] ) && array() === SFAF_Texts::log( 900 ) );
$sent = SFAF_Texts::send_update( 900, 'Doors open at 9:45. <b>Bring</b> a friend!', 1 );
rt( 'D: one text, to the one it can reach', 1 === $sent['sms'] && 0 === $sent['whatsapp'] && 1 === count( $GLOBALS['tw_calls'] ) && '+14155550101' === $GLOBALS['tw_calls'][0]['args']['body']['To'] );
rt( 'D: the update reads as the template does', 'Update from SFAF about Coffee & Conversation: Doors open at 9:45. Bring a friend! For questions, please email events@sfaf.org. Reply STOP to opt out.' === $GLOBALS['tw_calls'][0]['args']['body']['Body'] );
$log = SFAF_Texts::log( 900 );
rt( 'D: the send is logged with who, the message and the counts', 1 === count( $log ) && 1 === $log[0]['by'] && 'Doors open at 9:45. Bring a friend!' === $log[0]['message'] && 1 === $log[0]['sms'] );
rt( 'D: the log says what happened', 'Sent by text to 1.' === SFAF_Texts::said( $log[0] ) );
rt( 'D: a long message is cut at 300', 300 === strlen( SFAF_Texts::clean_message( str_repeat( 'a', 400 ) ) ) );
$html = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
rt( 'D: the log is shown under the box', false !== strpos( $html, 'Texts sent from this list' ) && false !== strpos( $html, 'Doors open at 9:45. Bring a friend!' ) );
tw_fresh();
$html = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
rt( 'D: absent while texts are off and nobody chose', '' === trim( $html ) );
// A query that hands back every row, as the kit's own stand-in does.
$GLOBALS['wpdb'] = new class extends Tw_Db { function get_results( $sql ) { return $this->rsvps; } };
$GLOBALS['wpdb']->rsvps = array( tw_row( 7, 900, '(415) 555-0107', '', 'en', 'Neve' ) );
rt( 'D: Neither is never listed, whatever the query returns', array() === SFAF_Texts::plan( 900 ) );
$pt = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-portal.php' );
rt( 'D: the route asks the event gate', 1 === preg_match( "/case 'text_registrants_send':\s*\\\$event_id = [^;]+;\s*\\\$post\s*= [^;]+;\s*if \( ! \\\$post \|\| 'uc_event' !== \\\$post->post_type \|\| ! \\\$this->can_edit_event\( \\\$user, \\\$post \) \) \{\s*wp_die\( 'Denied' \);/", $pt ) );
rt( 'D: shown only to whoever may edit the event, beside Email registrants', false !== strpos( $pt, '<?php if ( $this->can_edit_event( $user, $event ) ) { $this->render_registrant_mail( $user, $event_id ); $this->render_text_registrants( $user, $event_id ); } ?>' ) );
rt( 'D: the list marks the channel, and an old tick as such', false !== strpos( $pt, 'SFAF_Texts::channel_label( $r->text_channel )' ) && false !== strpos( $pt, '>Old text tick</span>' ) );

/* =====================================================================
 * E. Replies and STOP
 * =================================================================== */
tw_fresh();
tw_texts_on();
$db = $GLOBALS['wpdb'];
$db->rsvps = array( tw_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana' ), tw_row( 2, 900, '(415) 555-0102', 'whatsapp', 'es', 'Beto' ) );
update_option( 'uc_settings', array( 'email_reply_to' => 'calendar@sfaf.org' ) );
rt( 'E: STOP by text: recorded, Twilio confirms it', '' === SFAF_Texts::answer( '+14155550101', 'Stop' ) && SFAF_Texts::is_stopped( '+14155550101' ) );
rt( 'E: a stopped number gets nothing on either channel', 'stopped' === SFAF_Texts::reach( (object) array_merge( (array) $db->rsvps[0], array( 'text_channel' => 'whatsapp' ) ) )['state'] );
rt( 'E: START takes it off', '' === SFAF_Texts::answer( '+14155550101', 'START' ) && ! SFAF_Texts::is_stopped( '+14155550101' ) );
$a = SFAF_Texts::answer( 'whatsapp:+14155550102', 'PARAR' );
rt( 'E: PARAR on WhatsApp: recorded and confirmed in Spanish', SFAF_Texts::is_stopped( '+14155550102' ) && 0 === strpos( $a, 'No recibirá más mensajes' ) );
$h = SFAF_Texts::answer( 'whatsapp:+14155550101', 'help' );
rt( 'E: HELP on WhatsApp is answered, with the Replies go to address', false !== strpos( $h, 'events@sfaf.org' ) && false !== strpos( $h, 'STOP' ) );
rt( 'E: HELP on a text is left to the Messaging Service', '' === SFAF_Texts::answer( '+14155550101', 'HELP' ) );
$auto = SFAF_Texts::answer( '+14155550101', 'Is there parking?' );
rt( "E: Mark's line, word for word", "This number isn't monitored. For questions about Coffee & Conversation, please contact events@sfaf.org." === $auto );
rt( 'E: once a day per number', '' === SFAF_Texts::answer( '+14155550101', 'Hello?' ) );
rt( 'E: a stranger gets the site default', "This number isn't monitored. For questions about this event, please contact calendar@sfaf.org." === SFAF_Texts::answer( '+14155559999', 'Hi' ) );
delete_post_meta( 900, '_uc_email_replyto' );
$GLOBALS['kit_trans'] = array();
rt( "E: without the event's own address, the creator's", "This number isn't monitored. For questions about Coffee & Conversation, please contact admin@sfaf.org." === SFAF_Texts::answer( '+14155550101', 'Hi' ) );
global $TOKEN;
rt( 'E: an unsigned request is refused', ! SFAF_Texts::signed( array( 'From' => '+14155550101', 'Body' => 'STOP' ), $TOKEN, '' ) );
$params = array( 'From' => '+14155550101', 'Body' => 'STOP' );
$good   = SFAF_Texts::signature( SFAF_Texts::incoming_url(), $params, $TOKEN );
rt( 'E: a request signed for the address is accepted', SFAF_Texts::signed( $params, $TOKEN, $good ) );
rt( 'E: a changed body is refused', ! SFAF_Texts::signed( array( 'From' => '+14155550101', 'Body' => 'START' ), $TOKEN, $good ) );
// Twilio's own worked example (twilio.com/docs/usage/security).
rt( "E: Twilio's example signature", 'GvWf1cFY/Q7PnoempGyD5oXAezc=' === SFAF_Texts::signature( 'https://mycompany.com/myapp.php?foo=1&bar=2', array( 'Digits' => '1234', 'To' => '+18005551212', 'From' => '+14158675310', 'Caller' => '+14158675310', 'CallSid' => 'CA1234567890ABCDE' ), '12345' ) );
rt( 'E: the route is sfaf-calendar/v1/twilio/incoming, POST, checked by signature', 'sfaf-calendar/v1' === SFAF_Texts::REST_NAMESPACE && '/twilio/incoming' === SFAF_Texts::REST_ROUTE && false !== strpos( $src = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-texts.php' ), "'methods'             => 'POST'," ) && false !== strpos( $src, 'if ( ! self::signed( $params, $token, $sig ) ) {' ) );
rt( 'E: a typed message without an ending gets one', 'Doors open at 9:45.' === SFAF_Texts::ended( 'Doors open at 9:45' ) && 'Bring a friend!' === SFAF_Texts::ended( 'Bring a friend!' ) );

/* =====================================================================
 * F. The wiring
 * =================================================================== */
$main = file_get_contents( dirname( __DIR__ ) . '/sfaf-calendar.php' );
rt( 'F: schema 18', 1 === preg_match( "/define\( 'SFAF_DB_VERSION', '(\d+)' \);/", $main, $v ) && (int) $v[1] >= 18 );
rt( 'F: the column on registrations', false !== strpos( $main, "        text_channel varchar(10) NOT NULL DEFAULT '',\n        PRIMARY KEY (id)," ) );
rt( 'F: the old column is kept', false !== strpos( $main, "        text_opt_in tinyint(1) NOT NULL DEFAULT 0,\n" ) );
rt( 'F: the stops table, one row a number', false !== strpos( $main, "\$stops = \$wpdb->prefix . 'uc_text_stops';" ) && false !== strpos( $main, '        UNIQUE KEY phone (phone)' ) );
rt( 'F: the class is loaded and registered', false !== strpos( $main, "'includes/class-sfaf-texts.php'," ) && false !== strpos( $main, '    SFAF_Texts::register();' ) );
rt( "F: the panel's script is loaded", false !== strpos( $main, "SFAF_PLUGIN_URL . 'admin/js/twilio.js'," ) && file_exists( dirname( __DIR__ ) . '/admin/js/twilio.js' ) );
$adm = file_get_contents( dirname( __DIR__ ) . '/admin/class-sfaf-admin.php' );
rt( 'F: the panel is on WP admin Settings', false !== strpos( $adm, '            SFAF_Texts::render_panel();' ) );
$rem = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-reminders.php' );
$p_text = strpos( $rem, '$result[\'texts\'] = SFAF_Texts::send_reminders( $event_id );' );
$p_done = strpos( $rem, 'update_post_meta( $event_id, self::EVENT_DONE_META, time() );', (int) $p_text );
$p_loop = strpos( $rem, 'foreach ( self::recipients( $event_id ) as $key => $who ) {' );
rt( 'F: the reminder run texts after the emails and before marking the event done', false !== $p_text && false !== $p_done && false !== $p_loop && $p_loop < $p_text && $p_text < $p_done );
$cred = SFAF_Credentials::keys();
rt( 'F: the token is a secret to the credential store', 'secret' === $cred['twilio_auth_token'] );
$acc = file_get_contents( __DIR__ . '/event-access-test.php' );
rt( 'F: the route is in the access whitelist under the event gate', false !== strpos( $acc, "'POST:text_registrants_send'  => array( 'event' )," ) );

echo "release-31300: $ran checks\n";
if ( $fails ) {
    foreach ( $fails as $f ) { echo "FAIL  $f\n"; }
    exit( 1 );
}
echo "every check passed.\n";
