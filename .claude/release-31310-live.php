<?php
/**
 * 3.131.0 IN A BROWSER: THE RSVP FORM'S TEXT OR WHATSAPP, AND WHO GETS IT.
 *
 *     php .claude/release-31310-live.php --run
 *     php .claude/release-31310-live.php --run --verbose   and print what was measured
 *
 * REAL: the event page's RSVP form (sfaf_rsvp_block() with the real
 * calendar.css and calendar.js), and Email registrants and Text registrants
 * (the real renderers with portal.css and portal.js), in Chrome at 1280px and
 * 390px, rendered through wp-kit.php. The form's ajax is caught, not sent.
 *
 * The form, Twilio on:
 *   - from the moment it opens: Mark's phone label and "Get event updates
 *     by:" Text and WhatsApp, neither ticked, no Neither, no consent line,
 *     the phone not required
 *   - Text ticked: the consent line shows and the phone is required; Text
 *     pressed again: neither, the line hidden, not required; Text then
 *     WhatsApp: only WhatsApp
 *   - WhatsApp with no number: Mark's line, nothing sent; with +52 55 1234
 *     5678: sent as whatsapp, and the confirmation screen says "We'll also
 *     send WhatsApp messages to +52 55 1234 5678."
 *   - a number with nothing ticked is sent with no channel
 *   - Español: the label, the heading and the boxes in Mark's Spanish
 *   - WhatsApp ticked shows WhatsApp's own consent line, Text Text's, in
 *     English and Spanish (3.132.0); the Spanish error in usted (3.132.0)
 * The form, Twilio off: "Phone (optional)" and no choice.
 * Who gets it:
 *   - Text registrants: "Sending to 3 people: 2 by text, 1 by WhatsApp.";
 *     searching hides rows and keeps them ticked; Clear all leaves nobody and
 *     turns Send off; By answer ticks exactly who gave it; Didn't get ticks
 *     exactly who missed the logged send; Send asks the right number
 *   - Email registrants: the waitlist tick shows the waiting row and counts
 *     it; the team tick adds the copies to Send's question
 *
 * PLANT in plant-31310.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
function rl_event( $title, $meta = array() ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+7 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
function rl_single( $id ) {
    $words = 'window.ucData={ajaxUrl:"",nonce:"n",rsvpWords:' . wp_json_encode( SFAF_Messages::form_words() ) . '};';
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="../public/css/calendar.css"></head><body style="margin:0;background:#fff">'
        . '<div class="uc-single"><div class="uc-single-card">' . sfaf_rsvp_block( $id ) . '</div></div>'
        . '<script src="vendor/jquery-3.7.1.min.js"></script><script>' . $words . '</script>'
        . '<script src="../public/js/calendar.js"></script>';
}

/* ---- The form, with Twilio off and on. ---- */
$ev = rl_event( 'Support group' );
$off = rl_single( $ev ) . '</body></html>';
SFAF_Texts::save( array( 'twilio_account_sid' => 'AC' . str_repeat( 'a', 32 ), 'twilio_messaging_sid' => 'MG' . str_repeat( 'b', 32 ) ), 'token0123456789' );
$on = rl_single( $ev ) . '</body></html>';
rl( false !== strpos( $on, 'data-uc-texts="sms,whatsapp"' ) && false !== strpos( $off, 'data-uc-texts=""' ), 'the Register button does not say what the form offers, on and off' );

/* ---- Who gets it: Twilio and WhatsApp on, six registrations. ---- */
SFAF_Texts::save( array( 'twilio_account_sid' => 'AC' . str_repeat( 'a', 32 ), 'twilio_messaging_sid' => 'MG' . str_repeat( 'b', 32 ), 'twilio_whatsapp_from' => '+14155550100',
    'twilio_wa_reminder_en' => 'HX' . str_repeat( 'c', 32 ), 'twilio_wa_reminder_es' => 'HX' . str_repeat( 'd', 32 ), 'twilio_wa_update_en' => 'HX' . str_repeat( 'e', 32 ), 'twilio_wa_update_es' => 'HX' . str_repeat( 'f', 32 ) ), '' );
function rl_row( $id, $first, $email, $phone, $channel, $status = 'confirmed' ) {
    return array( 'id' => $id, 'event_id' => $GLOBALS['ev'], 'name' => $first, 'first_name' => $first, 'last_name' => '', 'email' => $email, 'phone' => $phone,
        'status' => $status, 'token' => 't' . $id, 'format' => '', 'lang' => 'en', 'text_channel' => $channel, 'text_opt_in' => 0, 'created_at' => '2026-10-2' . $id . ' 10:00:00' );
}
$GLOBALS['rl_rows'] = array(
    rl_row( 1, 'Ana', 'ana@example.org', '(415) 555-0101', 'sms' ),
    rl_row( 2, 'Beto', 'beto@example.org', '+52 55 1234 5678', 'whatsapp' ),
    rl_row( 3, 'Cruz', 'cruz@example.org', '(415) 555-0103', 'sms' ),
    rl_row( 4, 'Dee', 'dee@example.org', '', '' ),
    rl_row( 5, 'Eve', 'eve@example.org', '', '', 'waitlisted' ),
    rl_row( 6, 'Fay', '', '(415) 555-0106', '' ),
);
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false !== strpos( $sql, 'uc_rsvp_answers' ) ) {
        return 'get_results' === $method ? array( (object) array( 'rsvp_id' => 1, 'question_id' => 'qaaaaaaaaaa', 'option_id' => 'oaaaaaaaaa1' ), (object) array( 'rsvp_id' => 3, 'question_id' => 'qaaaaaaaaaa', 'option_id' => 'oaaaaaaaaa2' ) ) : null;
    }
    if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
    $rows = $GLOBALS['rl_rows'];
    if ( preg_match( "/status\s*=\s*'([a-z_]+)'/", $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['status'] === $m[1]; } );
    }
    if ( preg_match( "/text_channel IN/", $sql ) ) {
        $rows = array_filter( $rows, function ( $r ) { return in_array( $r['text_channel'], array( 'sms', 'whatsapp' ), true ); } );
    }
    if ( 'get_results' === $method ) { return array_map( function ( $r ) { return (object) $r; }, array_values( $rows ) ); }
    if ( 'get_var' === $method ) { return (string) count( $rows ); }
    return null;
};
update_post_meta( $ev, SFAF_Questions::META, array( array( 'id' => 'qaaaaaaaaaa', 'text' => 'Dietary needs?', 'options' => array( array( 'id' => 'oaaaaaaaaa1', 'text' => 'Vegetarian' ), array( 'id' => 'oaaaaaaaaa2', 'text' => 'None' ) ) ) ) );
update_post_meta( $ev, SFAF_Texts::LOG_META, array( array( 'by' => 1, 'at' => '2026-10-29 09:00:00', 'message' => 'Doors at 9:45', 'sms' => 1, 'whatsapp' => 0, 'failed' => 0, 'ids' => array( 1 ) ) ) );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me = new WP_User();
$sections = rl_cap( function () use ( $portal, $me, $ev ) {
    kit_call( 'SFAF_Portal', 'render_registrant_mail', $portal, array( $me, $ev ) );
    kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, $ev ) );
} );
rl( 0 !== strpos( $sections, 'THREW' ), "the two sections did not render: $sections" );
$lists = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
    . '<link rel="stylesheet" href="../public/css/portal.css"></head><body class="uc-portal-body" style="margin:0">'
    . '<div class="uc-portal"><main class="uc-portal-main">' . str_replace( array( '<details ', 'data-uc-disclosure' ), array( '<details open ', 'data-uc-disclosure-off' ), $sections ) . '</main></div>'
    . '<script src="../public/js/portal.js"></script>';

$probe = <<<'JS'
(function () {
var P = window.RL_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
/* The words on show: the form keeps the other language in a hidden twin (3.113.8). */
function vt(el) { var w = el && el.querySelector('.uc-w2'); return w ? text(w.firstElementChild) : text(el); }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
function tick(el, on) { el.checked = on; el.dispatchEvent(new Event('change', { bubbles: true })); }
function press(el) { el.click(); }
function consentShown() { return qa('#uc-rsvp-text-consent span[data-uc-w]').filter(seen).map(vt); }
function boxes(name) { return qa('input[name="uc-rsvp-text-channel"]').filter(function (b) { return b.checked; }).map(function (b) { return b.value; }); }

window.addEventListener('load', function () { setTimeout(function () {
  if (P.indexOf('form') === 0) {
    var sent = [];
    jQuery.ajax = function (o) { sent.push(o && o.data ? o.data : null); if (o && o.success && window.RL_RESP) { o.success(window.RL_RESP(o.data)); } return { done: function () { return this; }, fail: function () { return this; } }; };
    q('.uc-rsvp-btn').click();
    var wrap = q('#uc-rsvp-text-wrap'), phone = q('#uc-rsvp-phone'), consent = q('#uc-rsvp-text-consent');
    var sms = q('input[name="uc-rsvp-text-channel"][value="sms"]'), wa = q('input[name="uc-rsvp-text-channel"][value="whatsapp"]');
    out.open = { shown: seen(wrap), label: vt(q('#uc-rsvp-phone-label')), legend: vt(q('#uc-rsvp-text-wrap legend')),
      options: qa('#uc-rsvp-text-wrap .uc-rsvp-optin').filter(seen).map(vt), ticked: boxes(), consent: seen(consent), required: phone.required };
    if (P.indexOf('form-off') === 0) { finish(); return; }
    press(sms);
    out.afterText = { ticked: boxes(), consent: seen(consent), required: phone.required, aria: phone.getAttribute('aria-required'), lines: consentShown() };
    press(sms);
    out.afterTextAgain = { ticked: boxes(), consent: seen(consent), required: phone.required };
    press(sms); press(wa);
    out.afterBoth = boxes();
    out.waLines = consentShown();
    q('#uc-rsvp-first-name').value = 'Lee';
    q('#uc-rsvp-email').value = 'lee@example.org';
    q('#uc-rsvp-submit-btn').click();
    out.noNumber = { error: seen(q('#uc-rsvp-error')) ? text(q('#uc-rsvp-error')) : '', sent: sent.length };
    phone.value = '+52 55 1234 5678';
    window.RL_RESP = function (d) { return { success: true, first_name: 'Lee', text_channel: d.text_channel, text_phone: d.phone }; };
    q('#uc-rsvp-submit-btn').click();
    out.withNumber = { sent: sent.length, channel: sent.length ? sent[sent.length - 1].text_channel : null, phone: sent.length ? sent[sent.length - 1].phone : null,
      line: text(q('#uc-rsvp-success-texts')), lineShown: seen(q('#uc-rsvp-success-texts')) };
    /* Again, with a number and nothing ticked. */
    q('.uc-rsvp-btn').click();
    out.reopened = { ticked: boxes(), line: seen(q('#uc-rsvp-success-texts')) };
    q('#uc-rsvp-first-name').value = 'Mo';
    q('#uc-rsvp-email').value = 'mo@example.org';
    phone.value = '12';
    window.RL_RESP = function (d) { return { success: true, first_name: 'Mo', text_channel: '', text_phone: '' }; };
    q('#uc-rsvp-submit-btn').click();
    out.numberOnly = { sent: sent.length, channel: sent.length ? sent[sent.length - 1].text_channel : null, phone: sent.length ? sent[sent.length - 1].phone : null, line: seen(q('#uc-rsvp-success-texts')) };
    /* Spanish. */
    q('.uc-rsvp-btn').click();
    var es = q('[data-uc-lang-pick="es"]'); if (es) { es.click(); }
    out.spanish = { label: text(q('#uc-rsvp-phone-label .uc-w2 > span:first-child')) || text(q('#uc-rsvp-phone-label')),
      legend: text(q('#uc-rsvp-text-wrap legend .uc-w2 > span:first-child')) || text(q('#uc-rsvp-text-wrap legend')),
      sms: text(q('[data-uc-w="by_sms"] .uc-w2 > span:first-child')) || text(q('[data-uc-w="by_sms"]')) };
    press(q('input[name="uc-rsvp-text-channel"][value="sms"]'));
    q('#uc-rsvp-first-name').value = 'Ana'; q('#uc-rsvp-email').value = 'ana@example.org'; phone.value = '';
    q('#uc-rsvp-submit-btn').click();
    out.spanishError = text(q('#uc-rsvp-error'));
    out.spanishSmsLines = consentShown();
    press(q('input[name="uc-rsvp-text-channel"][value="whatsapp"]'));
    out.spanishWaLines = consentShown();
    finish(); return;
  }
  /* Who gets it. */
  var tbox = q('[data-uc-rcp][data-uc-rcp-kind="text"]'), ebox = q('[data-uc-rcp][data-uc-rcp-kind="email"]');
  var tsend = q('[data-uc-tr-send]'), esend = q('[data-uc-rm-send]');
  function count(b) { return text(q('[data-uc-rcp-count]', b)); }
  function shownNames(b) { return qa('[data-uc-rcp-row]', b).filter(seen).map(function (r) { return text(q('.uc-rcp-name', r)); }); }
  function tickedNames(b) { return qa('[data-uc-rcp-row]', b).filter(function (r) { var x = q('input', r); return x.checked && !x.disabled; }).map(function (r) { return text(q('.uc-rcp-name', r)); }); }
  out.textStart = { count: count(tbox), ticked: tickedNames(tbox), confirm: tsend.getAttribute('data-uc-confirm') };
  var s = q('[data-uc-rcp-search]', tbox); s.value = 'beto'; s.dispatchEvent(new Event('input', { bubbles: true }));
  out.search = { shown: shownNames(tbox), count: count(tbox), ticked: tickedNames(tbox) };
  s.value = '5550103'; s.dispatchEvent(new Event('input', { bubbles: true }));
  out.searchPhone = shownNames(tbox);
  s.value = ''; s.dispatchEvent(new Event('input', { bubbles: true }));
  press(q('[data-uc-rcp-none]', tbox));
  out.cleared = { count: count(tbox), ticked: tickedNames(tbox), sendOff: tsend.disabled };
  var a = q('[data-uc-rcp-answer]', tbox); a.value = 'qaaaaaaaaaa:oaaaaaaaaa1'; a.dispatchEvent(new Event('change', { bubbles: true }));
  out.byAnswer = { ticked: tickedNames(tbox), count: count(tbox), sendOn: !tsend.disabled };
  var m = q('[data-uc-rcp-missed]', tbox); m.value = '0'; m.dispatchEvent(new Event('change', { bubbles: true }));
  out.missed = { ticked: tickedNames(tbox), count: count(tbox), confirm: tsend.getAttribute('data-uc-confirm'), answerReset: a.value };
  press(q('[data-uc-rcp-all]', tbox));
  out.all = count(tbox);
  out.emailStart = { count: count(ebox), shown: shownNames(ebox), confirm: esend.getAttribute('data-uc-confirm') };
  tick(q('[data-uc-rm-waitlist]'), true);
  out.waitOn = { count: count(ebox), shown: shownNames(ebox) };
  tick(q('[data-uc-rm-team]'), true);
  out.teamOn = esend.getAttribute('data-uc-confirm');
  finish();
}, 300); });
})();
JS;

$pages = array(
    'form-desktop'     => array( 1280, $on ),
    'form-phone'       => array( 390, $on ),
    'form-off-desktop' => array( 1280, $off ),
    'lists-desktop'    => array( 1280, $lists ),
    'lists-phone'      => array( 390, $lists ),
);
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31310-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] . '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body></html>' );
    $files[ $name ] = __DIR__ . '/release-31310-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p[0] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=20000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ][0], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ][0] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}

$consent = 'Text me updates about this event. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help.';
$consent_es = SFAF_Messages::form_words()['es']['text_consent'];
$wa_consent = 'Send me WhatsApp updates about this event. Message frequency varies. Data rates may apply. Reply STOP to opt out, HELP for help.';
$wa_consent_es = 'Envíenme avisos de este evento por WhatsApp. La frecuencia de los mensajes varía. Pueden aplicarse cargos por datos. Responda STOP para cancelar, HELP para ayuda.';
foreach ( $g as $name => $r ) {
    if ( 0 === strpos( $name, 'form-off' ) ) {
        rl( false === $r['open']['shown'] && 'Phone (optional)' === $r['open']['label'], "PLANT F: $name: with Twilio off the form is not as before: " . json_encode( $r['open'] ) );
        continue;
    }
    if ( 0 === strpos( $name, 'form' ) ) {
        $o = $r['open'];
        rl( true === $o['shown'] && 'Phone number, for event updates by text or WhatsApp (optional)' === $o['label'] && 'Get event updates by:' === $o['legend'],
            "PLANT F: $name: the form does not open on Mark's label and the choice: " . json_encode( $o ) );
        rl( array( 'Text', 'WhatsApp' ) === $o['options'] && array() === $o['ticked'] && false === $o['consent'] && false === $o['required'],
            "PLANT F: $name: it opens with " . json_encode( $o ) );
        rl( array( 'sms' ) === $r['afterText']['ticked'] && true === $r['afterText']['consent'] && true === $r['afterText']['required'] && 'true' === $r['afterText']['aria'],
            "PLANT F: $name: ticking Text does not show the consent line and require the phone: " . json_encode( $r['afterText'] ) );
        rl( array() === $r['afterTextAgain']['ticked'] && false === $r['afterTextAgain']['consent'] && false === $r['afterTextAgain']['required'],
            "PLANT F: $name: pressing Text again does not clear it: " . json_encode( $r['afterTextAgain'] ) );
        rl( array( 'whatsapp' ) === $r['afterBoth'], "PLANT F: $name: Text then WhatsApp leaves " . json_encode( $r['afterBoth'] ) );
        rl( 'Enter a phone number to get updates by text or WhatsApp.' === $r['noNumber']['error'] && 0 === $r['noNumber']['sent'],
            "PLANT F: $name: WhatsApp with no number: " . json_encode( $r['noNumber'] ) );
        $w = $r['withNumber'];
        rl( 1 === $w['sent'] && 'whatsapp' === $w['channel'] && '+52 55 1234 5678' === $w['phone'], "PLANT F: $name: WhatsApp with +52 was not sent as whatsapp: " . json_encode( $w ) );
        rl( "We'll also send WhatsApp messages to +52 55 1234 5678." === $w['line'] && true === $w['lineShown'], "PLANT F: $name: the confirmation screen says " . json_encode( $w['line'] ) );
        rl( array() === $r['reopened']['ticked'] && false === $r['reopened']['line'], "$name: reopening keeps the last choice or line: " . json_encode( $r['reopened'] ) );
        rl( 2 === $r['numberOnly']['sent'] && '' === $r['numberOnly']['channel'] && '12' === $r['numberOnly']['phone'] && false === $r['numberOnly']['line'],
            "PLANT F: $name: a number with nothing ticked was not accepted as it is: " . json_encode( $r['numberOnly'] ) );
        rl( array( 'label' => 'Teléfono, para recibir avisos del evento por texto o WhatsApp (opcional)', 'legend' => 'Recibir avisos del evento por:', 'sms' => 'Texto' ) === $r['spanish'],
            "$name: Español reads " . json_encode( $r['spanish'], JSON_UNESCAPED_UNICODE ) );
        rl( 'Escriba un número de teléfono para recibir avisos por texto o WhatsApp.' === $r['spanishError'], "PLANT H: $name: the Spanish error reads " . json_encode( $r['spanishError'], JSON_UNESCAPED_UNICODE ) );
        rl( array( $consent ) === $r['afterText']['lines'], "PLANT H: $name: Text ticked shows " . json_encode( $r['afterText']['lines'] ) );
        rl( array( $wa_consent ) === $r['waLines'], "PLANT H: $name: WhatsApp ticked shows " . json_encode( $r['waLines'] ) );
        rl( array( $consent_es ) === $r['spanishSmsLines'], "PLANT H: $name: Texto ticked shows " . json_encode( $r['spanishSmsLines'], JSON_UNESCAPED_UNICODE ) );
        rl( array( $wa_consent_es ) === $r['spanishWaLines'], "PLANT H: $name: WhatsApp ticked in Spanish shows " . json_encode( $r['spanishWaLines'], JSON_UNESCAPED_UNICODE ) );
        continue;
    }
    rl( 'Sending to 3 people: 2 by text, 1 by WhatsApp.' === $r['textStart']['count'] && array( 'Ana', 'Beto', 'Cruz' ) === $r['textStart']['ticked']
        && 'Send to 3 people? A text cannot be recalled.' === $r['textStart']['confirm'], "PLANT G: $name: Text registrants opens with " . json_encode( $r['textStart'] ) );
    rl( array( 'Beto' ) === $r['search']['shown'] && 'Sending to 3 people: 2 by text, 1 by WhatsApp.' === $r['search']['count'] && array( 'Ana', 'Beto', 'Cruz' ) === $r['search']['ticked'], "PLANT G: $name: searching beto: " . json_encode( $r['search'] ) );
    rl( array( 'Cruz' ) === $r['searchPhone'], "$name: searching by phone digits finds " . json_encode( $r['searchPhone'] ) );
    rl( 'Nobody is ticked.' === $r['cleared']['count'] && array() === $r['cleared']['ticked'] && true === $r['cleared']['sendOff'], "PLANT G: $name: Clear all: " . json_encode( $r['cleared'] ) );
    rl( array( 'Ana' ) === $r['byAnswer']['ticked'] && 'Sending to 1 person: 1 by text.' === $r['byAnswer']['count'] && true === $r['byAnswer']['sendOn'], "PLANT G: $name: By answer Vegetarian: " . json_encode( $r['byAnswer'] ) );
    rl( array( 'Beto', 'Cruz' ) === $r['missed']['ticked'] && 'Send to 2 people? A text cannot be recalled.' === $r['missed']['confirm'] && '' === $r['missed']['answerReset'],
        "PLANT G: $name: Didn't get the logged send: " . json_encode( $r['missed'] ) );
    rl( 'Sending to 3 people: 2 by text, 1 by WhatsApp.' === $r['all'], "$name: Tick all: " . json_encode( $r['all'] ) );
    rl( 'Sending to 4 people by email.' === $r['emailStart']['count'] && ! in_array( 'Eve', $r['emailStart']['shown'], true ) && in_array( 'Fay', $r['emailStart']['shown'], true ),
        "PLANT G: $name: Email registrants opens with " . json_encode( $r['emailStart'] ) );
    rl( 'Sending to 5 people by email.' === $r['waitOn']['count'] && in_array( 'Eve', $r['waitOn']['shown'], true ), "$name: the waitlist tick: " . json_encode( $r['waitOn'] ) );
    rl( 1 === preg_match( '/^Send to (\d+) people\?$/', (string) $r['teamOn'], $tm ) && (int) $tm[1] >= 5, "$name: the team tick leaves Send asking " . json_encode( $r['teamOn'] ) );
}

echo "\n3.131.0: the RSVP form's Text or WhatsApp, and who gets it, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Text or WhatsApp from the moment the form opens, in Mark's words in both languages, each box's own consent line (3.132.0), the number required only with a box ticked and\nthe line on the confirmation screen; the recipient lists search, clear, tick by answer and by a missed send, and count who and how,\nat 1280px and 390px.\n";
exit( 0 );
