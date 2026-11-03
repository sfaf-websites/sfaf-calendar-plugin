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
 *      Spanish "¿No puede asistir? Cancele aquí: [link]" (usted from 3.132.0),
 *      then "Reply STOP to opt out."; without a token, no cancel line.
 *   C. Nothing else moves: Text registrants' update has no cancel line and
 *      its WhatsApp template keeps three variables.
 *   F. The RSVP form when Twilio is on, in Mark's words: the phone's label;
 *      "Get event updates by:" Text and WhatsApp as two boxes, neither
 *      ticked, no Neither; a box ticked needs a usable number, US or + and a
 *      country code, else "Enter a phone number..."; a number with nothing
 *      ticked is kept; the confirmation screen says how they will hear.
 *   G. Who gets it: one recipient list for Email registrants and Text
 *      registrants, everybody it can reach ticked, Text registrants only who
 *      chose a channel; By answer and Didn't get from the log; the count of
 *      who and how; the sends go only to who is ticked, and the log keeps
 *      their ids and shows their names. The script half is
 *      release-31310-live.php.
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
rt( 'B: the English reminder ends with the cancel line, then STOP', 'SFAF: Reminder: Coffee & Conversation is today at 10 am, at 1035 Market St. Event details: https://resources.sfaf.org/events/900/ Can\'t make it? Cancel here: ' . $link( $ana->token ) . ' Reply STOP to opt out.' === $by['+14155550101']['Body'] );
rt( 'B: the Spanish reminder in Mark\'s words', false !== strpos( $by['+14155550104']['Body'], ' ¿No puede asistir? Cancele aquí: ' . $link( $dee->token ) . ' Responda STOP' ) && 0 === strpos( $by['+14155550104']['Body'], 'SFAF: Recordatorio: ' ) );
rt( 'B: each text carries its own link', false === strpos( $by['+14155550101']['Body'], $dee->token ) && false === strpos( $by['+14155550104']['Body'], $ana->token ) );
rt( 'B: without a token, no cancel line', false === strpos( $by['+14155550105']['Body'], 'Cancel here' ) && false !== strpos( $by['+14155550105']['Body'], 'Event details: https://resources.sfaf.org/events/900/ Reply STOP' ) );

/* ---- C. Nothing else moves ---- */
$GLOBALS['tw_calls'] = array();
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 1 );
$ub = array();
foreach ( $GLOBALS['tw_calls'] as $c ) { $ub[ str_replace( 'whatsapp:', '', $c['args']['body']['To'] ) ] = $c['args']['body']; }
rt( 'C: the update has no cancel line', isset( $ub['+14155550101'] ) && false === strpos( $ub['+14155550101']['Body'], 'Cancel' ) );
rt( 'C: the update template keeps three variables', isset( $ub['+14155550102'] ) && array( '1', '2', '3' ) === array_map( 'strval', array_keys( (array) json_decode( $ub['+14155550102']['ContentVariables'], true ) ) ) );

/* ---- F. The RSVP form ---- */
tw_fresh();
rt( 'F: a number is not needed while texts are off', ! SFAF_Texts::needs_number( 'sms', '' ) );
tw_texts_on();
rt( 'F: Text with no number needs one', SFAF_Texts::needs_number( 'sms', '' ) );
rt( 'F: WhatsApp with half a number needs one', SFAF_Texts::needs_number( 'whatsapp', '415 555' ) );
rt( 'F: a US number will do', ! SFAF_Texts::needs_number( 'sms', '(415) 555-0123' ) && ! SFAF_Texts::needs_number( 'sms', '1-415-555-0123' ) );
rt( 'F: + and a country code will do', ! SFAF_Texts::needs_number( 'whatsapp', '+52 55 1234 5678' ) && 'whatsapp' === SFAF_Texts::clean_channel( 'whatsapp', '+52 55 1234 5678' ) );
rt( 'F: a number with nothing ticked is never refused', ! SFAF_Texts::needs_number( '', '' ) && ! SFAF_Texts::needs_number( '', '12' ) );
$rs = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-rsvp.php' );
rt( 'F: the registration is refused with Mark\'s line', false !== strpos( $rs, "return array( 'success' => false, 'message' => SFAF_Messages::label( 'e_need_phone', \$say ) );" ) );
rt( 'F: the answer says the channel and the number kept', false !== strpos( $rs, "'text_channel' => \$data['text_channel']," ) && false !== strpos( $rs, "'text_phone'   => '' !== \$data['text_channel'] ? \$data['phone'] : ''," ) );
$fw = SFAF_Messages::form_words();
$want = array(
    'phone_texts'   => array( 'Phone number, for event updates by text or WhatsApp (optional)', 'Teléfono, para recibir avisos del evento por texto o WhatsApp (opcional)' ),
    'updates_by'    => array( 'Get event updates by:', 'Recibir avisos del evento por:' ),
    'by_sms'        => array( 'Text', 'Texto' ),
    'by_whatsapp'   => array( 'WhatsApp', 'WhatsApp' ),
    'need_phone'    => array( 'Enter a phone number to get updates by text or WhatsApp.', 'Escriba un número de teléfono para recibir avisos por texto o WhatsApp.' ),
    'also_sms'      => array( "We'll also text you at %s.", 'También le enviaremos mensajes de texto al %s.' ),
    'also_whatsapp' => array( "We'll also send WhatsApp messages to %s.", 'También le enviaremos mensajes de WhatsApp al %s.' ),
);
foreach ( $want as $k => $pair ) {
    rt( "F: '$k' in Mark's words, both languages", isset( $fw['en'][ $k ], $fw['es'][ $k ] ) && $pair[0] === $fw['en'][ $k ] && $pair[1] === $fw['es'][ $k ] );
}
rt( 'F: no Neither or Email only', ! isset( $fw['en']['by_none'] ) && false === stripos( implode( ' ', $fw['en'] ), 'Email only' ) );
rt( 'F: the server says it in the form\'s words', $want['need_phone'][0] === SFAF_Messages::label( 'e_need_phone', 'en' ) && $want['need_phone'][1] === SFAF_Messages::label( 'e_need_phone', 'es' ) );
$js = file_get_contents( dirname( __DIR__ ) . '/public/js/calendar.js' );
rt( 'F: two boxes, Text then WhatsApp, neither ticked', 1 === preg_match( '/<input type="checkbox" name="uc-rsvp-text-channel" value="sms" \/>.*<input type="checkbox" name="uc-rsvp-text-channel" value="whatsapp" \/>/s', $js ) && false === strpos( $js, 'value="" checked' ) );
rt( 'F: the consent line starts hidden, under the boxes', 1 === preg_match( '/value="whatsapp" \/>.*?<p class="uc-rsvp-note" id="uc-rsvp-text-consent" hidden>/s', $js ) );
rt( 'F: the confirmation screen has its line', false !== strpos( $js, 'id="uc-rsvp-success-texts"' ) );
rt( 'F: the script in plain ASCII, as the 3.129.0 lesson asks', ! preg_match( '/[^\x00-\x7F]/', substr( $js, strpos( $js, 'function textChannel()' ), 900 ) ) );

/* ---- G. Who gets it ---- */
class Tw_Db_G extends Tw_Db {
    public $answers = array();
    function get_results( $sql ) {
        if ( preg_match( "/uc_rsvps WHERE event_id = (\d+)\s*$/", $sql, $m ) ) {
            return array_values( array_filter( $this->rsvps, function ( $r ) use ( $m ) { return (int) $r->event_id === (int) $m[1]; } ) );
        }
        if ( false !== strpos( $sql, 'uc_rsvp_answers' ) ) {
            return $this->answers;
        }
        return parent::get_results( $sql );
    }
}
tw_fresh();
tw_texts_on();
tw_wa_on();
$GLOBALS['wpdb'] = new Tw_Db_G();
$db = $GLOBALS['wpdb'];
$ana  = tw_row( 1, 900, '(415) 555-0101', 'sms', 'en', 'Ana' );
$beto = tw_row( 2, 900, '+52 55 1234 5678', 'whatsapp', 'es', 'Beto' );
$cruz = tw_row( 3, 900, '(415) 555-0103', 'sms', 'en', 'Cruz' );
$dee  = tw_row( 4, 900, '', '', 'en', 'Dee' );
$eve  = tw_row( 5, 900, '', '', 'en', 'Eve' ); $eve->status = 'waitlisted';
$fay  = tw_row( 6, 900, '(415) 555-0106', '', 'en', 'Fay' ); $fay->email = '';
foreach ( array( $ana, $beto, $cruz, $dee, $eve, $fay ) as $r ) { $r->token = 't' . $r->id; }
$db->rsvps = array( $ana, $beto, $cruz, $dee, $eve, $fay );
$db->answers = array( (object) array( 'rsvp_id' => 1, 'question_id' => 'qaaaaaaaaaa', 'option_id' => 'oaaaaaaaaa1' ), (object) array( 'rsvp_id' => 3, 'question_id' => 'qaaaaaaaaaa', 'option_id' => 'oaaaaaaaaa2' ) );
update_post_meta( 900, SFAF_Questions::META, array( array( 'id' => 'qaaaaaaaaaa', 'text' => 'Dietary needs?', 'options' => array( array( 'id' => 'oaaaaaaaaa1', 'text' => 'Vegetarian' ), array( 'id' => 'oaaaaaaaaa2', 'text' => 'None' ) ) ) ) );
update_post_meta( 900, SFAF_Texts::LOG_META, array( array( 'by' => 1, 'at' => '2026-10-29 09:00:00', 'message' => 'Doors at 9:45', 'sms' => 1, 'whatsapp' => 0, 'failed' => 0, 'ids' => array( 1 ) ) ) );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me     = new WP_User();
$th = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
rt( 'G: Text registrants renders', false === strpos( $th, 'THREW' ) && false !== strpos( $th, 'data-uc-rcp' ) );
$x = rt_x( $th );
$rows = array();
foreach ( $x->query( '//li[@data-uc-rcp-row]' ) as $li ) {
    $b = $x->query( './/input[@type="checkbox"]', $li )->item( 0 );
    $rows[] = rt_text( $x->query( './/span[@class="uc-rcp-name"]', $li )->item( 0 ) ) . ':' . $li->getAttribute( 'data-uc-rcp-how' ) . ':' . ( $b->hasAttribute( 'checked' ) ? 'on' : 'off' );
}
rt( 'G: Text registrants lists only who chose a channel, all ticked (' . implode( ' | ', $rows ) . ')', array( 'Ana:sms:on', 'Beto:whatsapp:on', 'Cruz:sms:on' ) === $rows );
rt( 'G: the boxes post tr_ids[] with tr_picked', 3 === $x->query( '//input[@name="tr_ids[]"]' )->length && 1 === $x->query( '//input[@type="hidden" and @name="tr_picked" and @value="1"]' )->length );
rt( 'G: a search box, Clear all and Tick all', 1 === $x->query( '//input[@type="search" and @data-uc-rcp-search]' )->length && 'Clear all' === rt_text( $x->query( '//button[@data-uc-rcp-none]' )->item( 0 ) ) && 'Tick all' === rt_text( $x->query( '//button[@data-uc-rcp-all]' )->item( 0 ) ) );
rt( 'G: who and how, in Mark\'s shape', 'Sending to 3 people: 2 by text, 1 by WhatsApp.' === rt_text( $x->query( '//*[@data-uc-rcp-count]' )->item( 0 ) ) );
$opts = array(); foreach ( $x->query( '//select[@data-uc-rcp-answer]//option[@value!=""]' ) as $o ) { $opts[] = $o->getAttribute( 'value' ) . '=' . rt_text( $o ); }
rt( 'G: By answer offers each question\'s options', array( 'qaaaaaaaaaa:oaaaaaaaaa1=Vegetarian', 'qaaaaaaaaaa:oaaaaaaaaa2=None' ) === $opts && 'Dietary needs?' === $x->query( '//select[@data-uc-rcp-answer]//optgroup' )->item( 0 )->getAttribute( 'label' ) );
$ans = array(); foreach ( $x->query( '//li[@data-uc-rcp-row]' ) as $li ) { $ans[] = $li->getAttribute( 'data-uc-rcp-answers' ); }
rt( 'G: each row carries its answers', array( 'qaaaaaaaaaa:oaaaaaaaaa1', '', 'qaaaaaaaaaa:oaaaaaaaaa2' ) === $ans );
$got = array(); foreach ( $x->query( '//li[@data-uc-rcp-row]' ) as $li ) { $got[] = $li->getAttribute( 'data-uc-rcp-got' ); }
rt( 'G: Didn\'t get offers the logged send, and each row knows if it got it', 1 === $x->query( '//select[@data-uc-rcp-missed]/option[@value="0"]' )->length && array( '0', '', '' ) === $got );
rt( 'G: the log names who received it', false !== strpos( rt_text( $x->query( '//details[@data-uc-rcp-who]' )->item( 0 ) ), 'Ana' ) );
rt( 'G: the list is inside the form, under the message', 1 === $x->query( '//form[@data-uc-tr-form]//textarea[@name="tr_message"]/following::div[@data-uc-rcp][1][ancestor::form[@data-uc-tr-form]]' )->length );
$GLOBALS['tw_calls'] = array();
$r = SFAF_Texts::send_update( 900, 'Room 2 today.', 1, array( 2 ) );
rt( 'G: a send reaches only who is ticked', 1 === $r['whatsapp'] && 0 === $r['sms'] && 1 === count( $GLOBALS['tw_calls'] ) && 'whatsapp:+525512345678' === $GLOBALS['tw_calls'][0]['args']['body']['To'] );
$log = SFAF_Texts::log( 900 );
rt( 'G: the text log keeps who received it', array( 2 ) === $log[0]['ids'] );
$GLOBALS['tw_calls'] = array();
SFAF_Texts::send_update( 900, 'Room 3 today.', 1 );
rt( 'G: no list posted is everybody, as before', 3 === count( $GLOBALS['tw_calls'] ) );

$eh = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_registrant_mail', $portal, array( $me, 900 ) ); } );
rt( 'G: Email registrants renders', false === strpos( $eh, 'THREW' ) && false !== strpos( $eh, 'data-uc-rcp' ) );
$x = rt_x( $eh );
$rows = array();
foreach ( $x->query( '//li[@data-uc-rcp-row]' ) as $li ) {
    $b = $x->query( './/input[@type="checkbox"]', $li )->item( 0 );
    $rows[] = rt_text( $x->query( './/span[@class="uc-rcp-name"]', $li )->item( 0 ) ) . ':' . ( $b->hasAttribute( 'checked' ) ? 'on' : 'off' ) . ( $b->hasAttribute( 'disabled' ) ? ':no-email' : '' ) . ( $li->hasAttribute( 'data-uc-rcp-wait' ) ? ':wait' : '' );
}
rt( 'G: Email registrants lists every registrant, waiting ones for the waitlist tick (' . implode( ' | ', $rows ) . ')', array( 'Ana:on', 'Beto:on', 'Cruz:on', 'Dee:on', 'Eve:on:wait', 'Fay:off:no-email' ) === $rows );
rt( 'G: who, by email', 'Sending to 4 people by email.' === rt_text( $x->query( '//*[@data-uc-rcp-count]' )->item( 0 ) ) );
rt( 'G: the boxes post rm_ids[] with rm_picked, in the form', 6 === $x->query( '//form[@data-uc-rm-form]//input[@name="rm_ids[]"]' )->length && 1 === $x->query( '//form[@data-uc-rm-form]//input[@name="rm_picked"]' )->length );
rt( 'G: the copies are told to the script', null !== json_decode( (string) $x->query( '//form[@data-uc-rm-form]' )->item( 0 )->getAttribute( 'data-uc-rcp-extra' ), true ) );
$plan = SFAF_Registrant_Mail::plan( 900, false, false, array( 1, 3 ) );
rt( 'G: an email send keeps only who is ticked', array( 1, 3 ) === array_map( function ( $p ) { return (int) $p->rsvp_id; }, $plan['participants'] ) );
$plan = SFAF_Registrant_Mail::plan( 900, false, false );
rt( 'G: and everybody without a list, as before', 4 === count( $plan['participants'] ) );
$rm = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-registrant-mail.php' );
rt( 'G: the email log keeps who received it', false !== strpos( $rm, "if ( \$go( \$p ) ) { \$sent++; \$ids[] = (int) \$p->rsvp_id; }" ) && false !== strpos( $rm, "            'ids'       => \$ids," ) );
$_POST = array( 'tr_picked' => '1', 'tr_ids' => array( '2', '2', 'x', '5' ) );
rt( 'G: picked_ids reads the ticks', array( 2, 5 ) === kit_call( 'SFAF_Portal', 'picked_ids', null, array( 'tr' ) ) );
$_POST = array( 'tr_picked' => '1' );
rt( 'G: nobody ticked is an empty list, not everybody', array() === kit_call( 'SFAF_Portal', 'picked_ids', null, array( 'tr' ) ) );
$_POST = array();
rt( 'G: no list posted is null', null === kit_call( 'SFAF_Portal', 'picked_ids', null, array( 'tr' ) ) );
$pt = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-portal.php' );
rt( 'G: nobody ticked is refused at both routes', 2 === substr_count( $pt, "'Tick at least one person to send to.'" ) );

echo "release-31310: $ran checks\n";
if ( $fails ) {
    foreach ( $fails as $f ) { echo "FAIL  $f\n"; }
    exit( 1 );
}
echo "every check passed.\n";
