<?php
/**
 * 3.138.0 IN A BROWSER: THE QUEUE'S PUBLISH, AND LANGUAGE IN WHO GETS IT.
 *
 *     php .claude/release-31380-live.php --run
 *     php .claude/release-31380-live.php --run --verbose   and print what was measured
 *
 * REAL: render_pending() with an imported event waiting, and Email registrants
 * and Text registrants (the real renderers with portal.css and portal.js),
 * in Chrome at 1280px and 390px, rendered through wp-kit.php.
 *
 *   Pending: Publish is drawn in #46661F, a link (no fill, no border, its
 *   own words), and its contrast against the colour it actually sits on,
 *   found by walking up to the first painted background, is at least 4.5:1.
 *   Printed, so the report can quote the measured number.
 *   Who gets it: English only ticks exactly the English registrants who can
 *   be reached, Spanish only exactly the Spanish ones; picking a language
 *   clears By answer and Didn't get, either of those clears Language, and
 *   Tick all and Clear all clear it; the count follows each.
 *
 * PLANT in plant-31380.php.
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
function rl_page( $body ) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="../public/css/portal.css"></head><body class="uc-portal-body" style="margin:0">'
        . '<div class="uc-portal"><main class="uc-portal-main">' . $body . '</main></div>'
        . '<script src="../public/js/portal.js"></script>';
}
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me = new WP_User();

/* ---- Pending, with an imported event waiting. ---- */
function rl_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type || ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) ) { continue; }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
}
$GLOBALS['kit_query'] = 'rl_query';
$imp = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'uc_imported', 'post_title' => 'Imported walk' ) );
update_post_meta( $imp, '_uc_event_date', date( 'Y-m-d', strtotime( '+10 days' ) ) );
$pending = rl_cap( function () use ( $portal, $me ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_pending', $portal, array( $me ) ); } );
rl( 0 !== strpos( $pending, 'THREW' ), "Pending did not render: $pending" );
rl( false !== strpos( $pending, '<button class="uc-link-ok" type="submit">Publish</button>' ), 'Pending has no Publish link on the imported event' );

/* ---- Who gets it: Twilio and WhatsApp on, five registrations, two languages. ---- */
$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+7 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $ev, $k, $v ); }
$GLOBALS['ev'] = $ev;
SFAF_Texts::save( array( 'twilio_account_sid' => 'AC' . str_repeat( 'a', 32 ), 'twilio_messaging_sid' => 'MG' . str_repeat( 'b', 32 ), 'twilio_whatsapp_from' => '+14155550100',
    'twilio_wa_reminder_en' => 'HX' . str_repeat( 'c', 32 ), 'twilio_wa_reminder_es' => 'HX' . str_repeat( 'd', 32 ), 'twilio_wa_update_en' => 'HX' . str_repeat( 'e', 32 ), 'twilio_wa_update_es' => 'HX' . str_repeat( 'f', 32 ) ), 'token0123456789' );
function rl_row( $id, $first, $email, $phone, $channel, $lang ) {
    return array( 'id' => $id, 'event_id' => $GLOBALS['ev'], 'name' => $first, 'first_name' => $first, 'last_name' => '', 'email' => $email, 'phone' => $phone,
        'status' => 'confirmed', 'token' => 't' . $id, 'format' => '', 'lang' => $lang, 'text_channel' => $channel, 'text_opt_in' => 0, 'created_at' => '2026-10-2' . $id . ' 10:00:00' );
}
$GLOBALS['rl_rows'] = array(
    rl_row( 1, 'Ana', 'ana@example.org', '(415) 555-0101', 'sms', 'en' ),
    rl_row( 2, 'Beto', 'beto@example.org', '+52 55 1234 5678', 'whatsapp', 'es' ),
    rl_row( 3, 'Cruz', 'cruz@example.org', '(415) 555-0103', 'sms', 'es' ),
    rl_row( 4, 'Dee', 'dee@example.org', '', '', 'en' ),
    rl_row( 5, 'Fay', '', '(415) 555-0106', '', '' ),
);
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false !== strpos( $sql, 'uc_rsvp_answers' ) ) {
        return 'get_results' === $method ? array( (object) array( 'rsvp_id' => 1, 'question_id' => 'qaaaaaaaaaa', 'option_id' => 'oaaaaaaaaa1' ), (object) array( 'rsvp_id' => 3, 'question_id' => 'qaaaaaaaaaa', 'option_id' => 'oaaaaaaaaa1' ) ) : null;
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
$sections = rl_cap( function () use ( $portal, $me, $ev ) {
    kit_call( 'SFAF_Portal', 'render_registrant_mail', $portal, array( $me, $ev ) );
    kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, $ev ) );
} );
rl( 0 !== strpos( $sections, 'THREW' ), "the two sections did not render: $sections" );
$lists = rl_page( str_replace( array( '<details ', 'data-uc-disclosure' ), array( '<details open ', 'data-uc-disclosure-off' ), $sections ) );

$probe = <<<'JS'
(function () {
var P = window.RL_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
function seen(el) { return !!el && !el.closest('[hidden]') && el.getClientRects().length > 0; }
function painted(el) {
  for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
    var bg = getComputedStyle(n).backgroundColor;
    if (bg && bg !== 'transparent' && !/rgba\([^)]*,\s*0\)$/.test(bg)) { return { bg: bg, at: n.tagName.toLowerCase() + (n.className && typeof n.className === 'string' ? '.' + n.className.trim().split(/\s+/).join('.') : '') }; }
  }
  return { bg: 'rgb(255, 255, 255)', at: 'canvas' };
}
window.addEventListener('load', function () { setTimeout(function () {
  if (P.indexOf('pending') === 0) {
    var b = qa('button.uc-link-ok').filter(function (x) { return text(x) === 'Publish'; })[0];
    if (!b) { out.publish = null; finish(); return; }
    var cs = getComputedStyle(b), under = painted(b.parentElement);
    out.publish = { seen: seen(b), color: cs.color, bg: cs.backgroundColor, borderTop: cs.borderTopWidth + ' ' + cs.borderTopStyle, under: under.bg, underAt: under.at,
      size: cs.fontSize, weight: cs.fontWeight, deco: cs.textDecorationLine };
    finish(); return;
  }
  var tbox = q('[data-uc-rcp][data-uc-rcp-kind="text"]'), ebox = q('[data-uc-rcp][data-uc-rcp-kind="email"]');
  function count(b) { return text(q('[data-uc-rcp-count]', b)); }
  function ticked(b) { return qa('[data-uc-rcp-row]', b).filter(function (r) { var x = q('input', r); return x.checked && !x.disabled; }).map(function (r) { return text(q('.uc-rcp-name', r)); }); }
  function pick(sel, v) { sel.value = v; sel.dispatchEvent(new Event('change', { bubbles: true })); }
  [['text', tbox], ['email', ebox]].forEach(function (pair) {
    var k = pair[0], box = pair[1], r = {};
    var lang = q('[data-uc-rcp-lang]', box), answer = q('[data-uc-rcp-answer]', box), missed = q('[data-uc-rcp-missed]', box);
    r.has = !!lang && seen(lang);
    if (!lang) { out[k] = r; return; }
    var tools = q('.uc-rcp-tools', box);
    r.beside = !!tools && lang.closest('.uc-rcp-tools') === tools;
    r.start = ticked(box);
    pick(lang, 'en'); r.en = { ticked: ticked(box), count: count(box) };
    pick(lang, 'es'); r.es = { ticked: ticked(box), count: count(box) };
    if (answer) { pick(answer, 'qaaaaaaaaaa:oaaaaaaaaa1'); r.answerClears = { lang: lang.value, ticked: ticked(box) }; }
    pick(lang, 'en'); r.langClearsAnswer = answer ? answer.value : 'none';
    if (missed) { pick(missed, '0'); r.missedClears = { lang: lang.value, ticked: ticked(box) }; pick(lang, 'es'); r.langClearsMissed = missed.value; }
    q('[data-uc-rcp-none]', box).click(); r.none = { lang: lang.value, ticked: ticked(box), count: count(box) };
    pick(lang, 'es'); q('[data-uc-rcp-all]', box).click(); r.all = { lang: lang.value, ticked: ticked(box) };
    out[k] = r;
  });
  finish();
}, 300); });
})();
JS;

$pages = array(
    'pending-desktop' => array( 1280, rl_page( $pending ) ),
    'pending-phone'   => array( 390, rl_page( $pending ) ),
    'lists-desktop'   => array( 1280, $lists ),
    'lists-phone'     => array( 390, $lists ),
);
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31380-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] . '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body></html>' );
    $files[ $name ] = __DIR__ . '/release-31380-live-' . $name . '-frame.html';
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

function rl_rgb( $s ) { return preg_match( '/rgba?\((\d+),\s*(\d+),\s*(\d+)/', (string) $s, $m ) ? array( (int) $m[1], (int) $m[2], (int) $m[3] ) : null; }
function rl_lum( $c ) { $f = function ( $v ) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 ); }; return 0.2126 * $f( $c[0] ) + 0.7152 * $f( $c[1] ) + 0.0722 * $f( $c[2] ); }
function rl_ratio( $a, $b ) { $x = rl_lum( $a ); $y = rl_lum( $b ); return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 ); }

$said = array();
foreach ( $g as $name => $r ) {
    if ( 0 === strpos( $name, 'pending' ) ) {
        $p = $r['publish'];
        rl( is_array( $p ) && true === $p['seen'], "PLANT P: $name: no Publish link is on screen" );
        if ( ! is_array( $p ) ) { continue; }
        rl( 'rgb(70, 102, 31)' === $p['color'], "PLANT P: $name: Publish is drawn in {$p['color']}, not #46661F" );
        rl( 'rgba(0, 0, 0, 0)' === $p['bg'] && 0 === strpos( $p['borderTop'], '0px' ), "PLANT P: $name: Publish is a box, not a link: " . json_encode( $p ) );
        $fg = rl_rgb( $p['color'] ); $bg = rl_rgb( $p['under'] );
        $ratio = ( $fg && $bg ) ? rl_ratio( $fg, $bg ) : 0;
        rl( $ratio >= 4.5, sprintf( "PLANT P: $name: Publish is %.2f:1 on %s (%s)", $ratio, $p['under'], $p['underAt'] ) );
        $said[] = sprintf( '%s: Publish %s on %s (%s), %.2f:1, %s %s', $name, $p['color'], $p['under'], $p['underAt'], $ratio, $p['size'], $p['weight'] );
        continue;
    }
    foreach ( array( 'text' => array( 'Ana', 'Beto', 'Cruz' ), 'email' => array( 'Ana', 'Beto', 'Cruz', 'Dee' ) ) as $k => $all ) {
        $x = isset( $r[ $k ] ) ? $r[ $k ] : array();
        rl( ! empty( $x['has'] ) && ! empty( $x['beside'] ), "PLANT L: $name: $k: no Language filter beside the others" );
        if ( empty( $x['has'] ) ) { continue; }
        $en = 'text' === $k ? array( 'Ana' ) : array( 'Ana', 'Dee' );
        $es = array( 'Beto', 'Cruz' );
        rl( $all === $x['start'], "$name: $k: the list opens with " . json_encode( $x['start'] ) );
        rl( $en === $x['en']['ticked'], "PLANT L: $name: $k: English only ticks " . json_encode( $x['en'] ) );
        rl( $es === $x['es']['ticked'], "PLANT L: $name: $k: Spanish only ticks " . json_encode( $x['es'] ) );
        $words = 'text' === $k ? 'Sending to 2 people: 1 by text, 1 by WhatsApp.' : 'Sending to 2 people by email.';
        rl( $words === $x['es']['count'], "$name: $k: the count after Spanish only reads " . json_encode( $x['es']['count'] ) );
        rl( isset( $x['answerClears'] ) && '' === $x['answerClears']['lang'] && array( 'Ana', 'Cruz' ) === $x['answerClears']['ticked'], "PLANT L: $name: $k: By answer leaves Language: " . json_encode( $x['answerClears'] ?? null ) );
        rl( '' === $x['langClearsAnswer'], "PLANT L: $name: $k: Language leaves By answer at " . json_encode( $x['langClearsAnswer'] ) );
        if ( 'text' === $k ) {
            rl( isset( $x['missedClears'] ) && '' === $x['missedClears']['lang'] && array( 'Beto', 'Cruz' ) === $x['missedClears']['ticked'], "PLANT L: $name: $k: Didn't get leaves Language: " . json_encode( $x['missedClears'] ?? null ) );
            rl( '' === ( $x['langClearsMissed'] ?? 'x' ), "PLANT L: $name: $k: Language leaves Didn't get at " . json_encode( $x['langClearsMissed'] ?? null ) );
        }
        rl( '' === $x['none']['lang'] && array() === $x['none']['ticked'] && 'Nobody is ticked.' === $x['none']['count'], "PLANT L: $name: $k: Clear all: " . json_encode( $x['none'] ) );
        rl( '' === $x['all']['lang'] && $all === $x['all']['ticked'], "PLANT L: $name: $k: Tick all: " . json_encode( $x['all'] ) );
    }
}

echo "\n3.138.0: the queue's Publish, and Language in who gets it, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
foreach ( $said as $s ) { echo "  $s\n"; }
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Publish is #46661F and a link at 4.5:1 or better; English only and Spanish only tick exactly their registrants in both lists, clear the other filters and are cleared by them,\nat 1280px and 390px.\n";
exit( 0 );
