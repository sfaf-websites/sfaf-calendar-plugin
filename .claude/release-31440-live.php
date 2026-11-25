<?php
/**
 * 3.144.0 IN A BROWSER: THE REPLIES SECTION ON THE RSVP PAGE.
 *
 *     php .claude/release-31440-live.php --run
 *     php .claude/release-31440-live.php --run --verbose   and print what was measured
 *
 * REAL: the Replies section (render_text_replies()) beside Text registrants
 * (render_text_registrants()), rendered through wp-kit.php and
 * text-inbox-kit.php with the real portal.css and portal.js, in Chrome at
 * 1280, 430 and 390px. Two threads, the latest first: Beto by WhatsApp; Ana
 * by text, with an answer from Lee and a reply holding a long link.
 *
 *   T  each thread on screen, named, with its number and channel; every
 *      message on screen, wrapped inside the card, the long link included;
 *      an answer set in from the replies; the box as wide as the card's
 *      column and at least 200px; Send on screen, the Text registrants
 *      button's green, at least 44px tall on a phone; Send asks first, in
 *      that green.
 *
 * Every page: no script error and no sideways scroll. PLANT in plant-31440.php.
 */

require __DIR__ . '/wp-kit.php';
require __DIR__ . '/text-inbox-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

$db = ri_fresh();
$LONG = 'Is this the place? https://www.google.com/maps/place/1035+Market+St,+San+Francisco,+CA+94103/@37.7808,-122.4113,17z/data=!3m1!4b1';
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7 );
SFAF_Texts::answer( '+14155550101', 'Is there parking?' );
SFAF_Text_Inbox::reply( 900, '+14155550101', 'Yes, on Market St, two spaces by the door.', 8 );
SFAF_Texts::answer( '+14155550101', $LONG );
SFAF_Texts::send_reminders( 900 );
SFAF_Texts::answer( 'whatsapp:+14155550102', 'Llego diez minutos tarde, ¿está bien?' );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me     = new WP_User();
$me->ID = 1;
$_GET   = array( 'replies' => 1 );
$tr     = rl_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, 900 ) ); } );
$rp     = rl_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } );
foreach ( array( 'Text registrants' => $tr, 'Replies' => $rp ) as $k => $h ) { rl( '' !== trim( $h ) && 0 !== strpos( $h, 'THREW' ), "$k did not render: " . substr( $h, 0, 300 ) ); }
// Text registrants open too, so its Send can be measured beside.
$tr = str_replace( '<details class="uc-card', '<details open class="uc-card', $tr );

function rl_page( $body ) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="../public/css/portal.css"></head><body class="uc-portal" style="margin:0">'
        . '<div class="uc-portal-main"><div class="uc-portal-content">' . $body . '</div></div>'
        . '<script src="../public/js/portal.js"></script>';
}

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function box(el) { var r = el.getBoundingClientRect(); return { l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, h: r.height }; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; out.vw = document.documentElement.clientWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  var card = q('details[data-uc-text-replies]');
  out.open = !!(card && card.open);
  if (!card) { return finish(); }
  var body = q('.uc-registrant-mail-body', card), cb = box(body);
  out.card = box(card);
  out.threads = qa('section[data-uc-reply-thread]', card).map(function (s) {
    var ta = q('textarea[data-uc-reply-message]', s), send = q('button[data-uc-reply-send]', s);
    return {
      phone: s.getAttribute('data-uc-reply-thread'), name: text(q('h3', s)), who: text(q('.uc-reply-who', s)), seen: seen(s),
      msgs: qa('li[data-uc-reply-msg]', s).map(function (li) {
        var b = q('.uc-reply-body', li), r = box(li);
        return { dir: li.getAttribute('data-uc-reply-msg'), text: text(b), seen: seen(b), inside: r.l >= cb.l - 0.5 && r.r <= cb.r + 0.5, l: r.l, bodyFits: b.scrollWidth <= b.clientWidth + 1 };
      }),
      ta: ta ? { w: box(ta).w, inside: box(ta).l >= cb.l - 0.5 && box(ta).r <= cb.r + 0.5, room: cb.w } : null,
      send: send ? { seen: seen(send), bg: getComputedStyle(send).backgroundColor, h: box(send).h, onScreen: box(send).l >= -0.5 && box(send).r <= document.documentElement.clientWidth + 0.5 } : null
    };
  });
  var trSend = q('[data-uc-tr-send]');
  out.trBg = trSend ? getComputedStyle(trSend).backgroundColor : '';
  var first = q('section[data-uc-reply-thread] textarea[data-uc-reply-message]', card);
  if (first) { first.value = 'Thanks'; }
  var s1 = q('section[data-uc-reply-thread] button[data-uc-reply-send]', card);
  if (s1) { s1.click(); }
  setTimeout(function () {
    var dlg = q('dialog.uc-confirm-modal[open]');
    var ok = dlg ? q('button[value="ok"]', dlg) : null;
    out.confirm = ok ? { label: text(ok), bg: getComputedStyle(ok).backgroundColor, msg: text(dlg) } : null;
    finish();
  }, 300);
}, 500); });
})();
JS;

$WIDTHS = array( 1280, 430, 390 );
$files  = array();
foreach ( $WIDTHS as $w ) {
    $inner = __DIR__ . '/release-31440-live-' . $w . '.html';
    file_put_contents( $inner, rl_page( $tr . $rp ) . '<script>' . $probe . '</script></body></html>' );
    $files[ $w ] = __DIR__ . '/release-31440-live-' . $w . '-frame.html';
    file_put_contents( $files[ $w ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $w . 'px;height:2400px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

foreach ( $files as $w => $file ) {
    $name = "replies-$w";
    $dom  = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,2500 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    rl( empty( $r['errors'] ), "$name threw: " . implode( '; ', (array) $r['errors'] ) );
    rl( (int) $r['pageWidth'] <= $w, "PLANT T: $name: the page scrolls sideways, " . $r['pageWidth'] . "px in $w" );
    rl( ! empty( $r['open'] ), "$name: Replies is not open with ?replies=1" );
    $t = isset( $r['threads'] ) ? (array) $r['threads'] : array();
    rl( 2 === count( $t ) && 'Beto Luna' === $t[0]['name'] && 'Ana Ruiz' === $t[1]['name'], "PLANT T: $name: the threads are " . json_encode( array_map( function ( $x ) { return $x['name']; }, $t ) ) . ', not Beto Luna, the latest, then Ana Ruiz' );
    foreach ( $t as $th ) {
        rl( $th['seen'], "$name: {$th['name']}'s thread is not on screen" );
        rl( in_array( $th['who'], array( '(415) 555-0101, Text', '(415) 555-0102, WhatsApp' ), true ), "$name: {$th['name']}'s line reads " . json_encode( $th['who'] ) );
        $ins = array(); $outs = array();
        foreach ( $th['msgs'] as $mm ) {
            rl( $mm['seen'] && $mm['inside'] && $mm['bodyFits'], "PLANT T: $name: {$th['name']}'s message " . json_encode( substr( $mm['text'], 0, 40 ) ) . ' is not wrapped inside the card: ' . json_encode( $mm ) );
            if ( 'in' === $mm['dir'] ) { $ins[] = $mm['l']; } else { $outs[] = $mm['l']; }
        }
        if ( $outs ) { rl( min( $outs ) >= max( $ins ) + 12, "PLANT T: $name: {$th['name']}'s answer is not set in from the replies: " . json_encode( array( $ins, $outs ) ) ); }
        rl( $th['ta'] && $th['ta']['inside'] && $th['ta']['w'] >= min( 200, $th['ta']['room'] - 1 ) && $th['ta']['w'] >= $th['ta']['room'] * 0.6, "PLANT T: $name: {$th['name']}'s box is " . json_encode( $th['ta'] ) );
        rl( $th['send'] && $th['send']['seen'] && $th['send']['onScreen'] && $th['send']['bg'] === $r['trBg'] && '' !== $r['trBg'], "PLANT T: $name: {$th['name']}'s Send is " . json_encode( $th['send'] ) . ', Text registrants\' is ' . $r['trBg'] );
        if ( $w < 600 && $th['send'] ) { rl( $th['send']['h'] >= 44, "PLANT T: $name: {$th['name']}'s Send is " . round( $th['send']['h'] ) . 'px tall on a phone' ); }
    }
    // 3.144.2: and the Text registrants message the first reply answered, above it.
    rl( 4 === count( isset( $t[1]['msgs'] ) ? $t[1]['msgs'] : array() ), "$name: Ana's thread does not hold the message replied to, two replies and the answer" );
    rl( isset( $r['confirm']['bg'] ) && $r['confirm']['bg'] === $r['trBg'] && false !== strpos( $r['confirm']['msg'], 'Send to Beto Luna?' ), "PLANT T: $name: Send asks with " . json_encode( isset( $r['confirm'] ) ? $r['confirm'] : null, JSON_UNESCAPED_UNICODE ) );
}

echo "\n3.144.0 in a browser: the Replies section on the RSVP page\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " FAILURE(S):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo count( $files ) . " pages: the Replies section at 1280, 430 and 390px.\n";
exit( 0 );
