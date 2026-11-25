<?php
/**
 * 3.144.2 IN A BROWSER: THE SEGMENTED TOGGLE'S TEAL EDGE, AND WHAT A REPLY ANSWERED.
 *
 *     php .claude/release-31442-live.php --run
 *     php .claude/release-31442-live.php --run --verbose   and print what was measured
 *
 * REAL: My events | All events (render_scope_toggle()), the RSVP card's choice
 * (SFAF_Register_Elsewhere::render_rsvp_choice()) and the Replies section
 * (render_text_replies()), through wp-kit.php and text-inbox-kit.php with the
 * real portal.css and portal.js, in Chrome at 1280, 430 and 390px. The
 * check-in bar's choice is drawn as the markup its renderer emits.
 *
 *   S  every segmented group: a 1px #0E7680 edge on all four sides, at least
 *      3:1 against the card; a #0E7680 seam between each pair of options;
 *      the chosen one #0E7680 under white words, the others white under
 *      #373433, both at least 4.5:1; the two toggles the same in every one
 *      of these; inside the page
 *   R  above each reply, the message it answered, set in like an answer,
 *      inside the card, its words wrapped inside it, its label muted at
 *      4.5:1, never tinted as New
 *
 * Every page: no script error and no sideways scroll. PLANT in plant-31442.php.
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
update_post_meta( 900, '_uc_rsvp_enabled', '1' );
$P = '+14155550101';
SFAF_Texts::send( $db->rsvps[0], 'reminder' );
SFAF_Texts::answer( $P, 'Running late, is there a side entrance on the Market Street side of the building?' );
SFAF_Texts::answer( $P, 'Five minutes away' );
SFAF_Text_Inbox::reply( 900, $P, 'Yes, on Market St.', 8 );
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7, array( 2 ) );
SFAF_Texts::answer( 'whatsapp:+14155550102', 'Gracias' );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me     = wp_get_current_user();
$scope  = rl_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_scope_toggle', $portal, array( 'mine', 'events' ) ); } );
$rsvp   = rl_cap( function () { SFAF_Register_Elsewhere::render_rsvp_choice( 'here', '' ); } );
$_GET   = array( 'replies' => '1' );
$rp     = rl_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } );
$_GET   = array();
foreach ( array( 'scope toggle' => $scope, 'RSVP choice' => $rsvp, 'Replies' => $rp ) as $k => $h ) { rl( '' !== trim( $h ) && 0 !== strpos( $h, 'THREW' ), "$k did not render: " . substr( $h, 0, 300 ) ); }
$checkin = '<div class="uc-checkin-bar"><fieldset class="uc-checkin-mode"><span class="uc-seg">'
    . '<button type="submit" class="uc-seg-opt-btn" aria-pressed="true">Check in by name</button>'
    . '<button type="submit" class="uc-seg-opt-btn" aria-pressed="false">Enter a count</button></span></fieldset></div>';

function rl_page( $body ) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="../public/css/portal.css"></head><body class="uc-portal" style="margin:0">'
        . $body . '<script src="../public/js/portal.js"></script>';
}
$admin = '<div class="uc-portal-layout"><main class="uc-portal-main"><div class="uc-portal-content">'
    . '<div class="uc-page-head"><h1>Events</h1></div>' . $scope
    . '<div class="uc-card" data-rl-card="rsvp">' . $rsvp . '</div>'
    . '<div class="uc-card" data-rl-card="checkin">' . $checkin . '</div>'
    . $rp . '</div></main></div>';

$probe = <<<'JS'
(function () {
var out = { page: window.RL_PAGE, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function rgb(s) { var m = String(s).match(/\d+(\.\d+)?/g); return m ? m.slice(0, 3).map(Number) : null; }
function lum(c) { return c.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function ratio(a, b) { var x = lum(a), y = lum(b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; }
function box(el) { var r = el.getBoundingClientRect(); return { l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, h: r.height }; }
function under(el) { var n = el.parentElement; while (n) { var c = getComputedStyle(n).backgroundColor; if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') { return c; } n = n.parentElement; } return 'rgb(255, 255, 255)'; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; out.vw = document.documentElement.clientWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  out.groups = [['scope', '.uc-scope-switch', '.uc-scope-opt', function (o) { return o.classList.contains('is-on'); }, function (o) { return o; }],
                ['rsvp', '[data-uc-reg-where] .uc-seg', '.uc-seg-opt', function (o) { return q('input', o).checked; }, function (o) { return q('span', o); }],
                ['checkin', '.uc-checkin-bar .uc-seg', '.uc-seg-opt-btn', function (o) { return o.getAttribute('aria-pressed') === 'true'; }, function (o) { return o; }]].map(function (g) {
    var el = q(g[1]); if (!el) { return { what: g[0], missing: true }; }
    var cs = getComputedStyle(el), r = box(el);
    var opts = qa(g[2], el).map(function (o, i) {
      var face = g[4](o), fs = getComputedStyle(face);
      return { text: text(o), on: g[3](o), bg: fs.backgroundColor, color: fs.color, contrast: ratio(rgb(fs.color), rgb(fs.backgroundColor)), seam: i ? fs.borderLeftWidth + ' ' + fs.borderLeftStyle + ' ' + fs.borderLeftColor : '' };
    });
    return { what: g[0], seen: seen(el), onScreen: r.l >= -0.5 && r.r <= document.documentElement.clientWidth + 0.5,
      widths: [cs.borderTopWidth, cs.borderRightWidth, cs.borderBottomWidth, cs.borderLeftWidth].join(' '),
      styles: [cs.borderTopStyle, cs.borderRightStyle, cs.borderBottomStyle, cs.borderLeftStyle].join(' '),
      colors: [cs.borderTopColor, cs.borderRightColor, cs.borderBottomColor, cs.borderLeftColor], radius: cs.borderTopLeftRadius,
      edgeContrast: ratio(rgb(cs.borderTopColor), rgb(under(el))), opts: opts };
  });
  var card = q('details[data-uc-text-replies]'), cb = card ? box(card) : null;
  out.threads = qa('section[data-uc-reply-thread]').map(function (sec) {
    return qa('li[data-uc-reply-msg]', sec).map(function (li) {
      var cs = getComputedStyle(li), r = box(li), body = q('.uc-reply-body', li), meta = q('.uc-reply-meta', li), br = box(body);
      return { dir: li.getAttribute('data-uc-reply-msg'), kind: li.getAttribute('data-uc-reply-kind') || '', l: r.l, bg: cs.backgroundColor, seen: seen(li),
        inside: cb ? (r.l >= cb.l - 0.5 && r.r <= cb.r + 0.5) : false, bodyFits: br.r <= r.r + 0.5 && br.w <= r.w + 0.5,
        metaContrast: ratio(rgb(getComputedStyle(meta).color), rgb(under(meta))), meta: text(meta), body: text(body).slice(0, 50) };
    });
  });
  finish();
}, 500); });
})();
JS;

$pages = array();
foreach ( array( 1280, 430, 390 ) as $w ) { $pages[ "admin-$w" ] = array( $w, rl_page( $admin ) ); }
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31442-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] . '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body></html>' );
    $files[ $name ] = __DIR__ . '/release-31442-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p[0] . 'px;height:3200px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$TEAL  = 'rgb(14, 118, 128)';  // --uc-accent-text #0E7680
$WHITE = 'rgb(255, 255, 255)';
$INK   = 'rgb(55, 52, 51)';    // --p-text #373433

foreach ( $files as $name => $file ) {
    $w   = $pages[ $name ][0];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,3300 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    rl( empty( $r['errors'] ), "$name threw: " . implode( '; ', (array) $r['errors'] ) );
    rl( (int) $r['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $r['pageWidth'] . "px in $w" );

    /* S. The segmented groups. */
    $looks = array();
    foreach ( (array) $r['groups'] as $g ) {
        if ( ! empty( $g['missing'] ) ) { rl( false, "$name: the {$g['what']} toggle is missing" ); continue; }
        rl( $g['seen'] && $g['onScreen'], "$name: the {$g['what']} toggle is not on screen" );
        rl( '1px 1px 1px 1px' === $g['widths'] && 'solid solid solid solid' === $g['styles'] && array( $TEAL, $TEAL, $TEAL, $TEAL ) === $g['colors'] && $g['edgeContrast'] >= 3,
            "PLANT S: $name: the {$g['what']} toggle's edge is " . json_encode( array( $g['widths'], $g['styles'], $g['colors'], $g['edgeContrast'] ) ) );
        $on = 0;
        foreach ( $g['opts'] as $i => $o ) {
            if ( $i ) { rl( '1px solid ' . $TEAL === $o['seam'], "PLANT S: $name: the {$g['what']} toggle's seam before " . json_encode( $o['text'] ) . ' is ' . $o['seam'] ); }
            if ( $o['on'] ) {
                $on++;
                rl( $TEAL === $o['bg'] && $WHITE === $o['color'] && $o['contrast'] >= 4.5, "PLANT S: $name: the {$g['what']} toggle's chosen " . json_encode( $o['text'] ) . ' is ' . json_encode( $o ) );
            } else {
                rl( $WHITE === $o['bg'] && $INK === $o['color'] && $o['contrast'] >= 4.5, "PLANT S: $name: the {$g['what']} toggle's " . json_encode( $o['text'] ) . ' is ' . json_encode( $o ) );
            }
        }
        rl( 1 === $on, "$name: the {$g['what']} toggle has $on chosen" );
        $looks[ $g['what'] ] = array( $g['widths'], $g['colors'], $g['radius'] );
    }
    rl( isset( $looks['scope'], $looks['rsvp'] ) && $looks['scope'] === $looks['rsvp'], "PLANT S: $name: My events | All events and the RSVP choice differ: " . json_encode( $looks ) );

    /* R. What a reply answered. */
    $t = (array) $r['threads'];
    $flags = array_map( function ( $th ) { return array_map( function ( $x ) { return $x['dir'] . ( $x['kind'] ? ':' . $x['kind'] : '' ); }, $th ); }, $t );
    rl( array( array( 'sent:update', 'in' ), array( 'sent:reminder', 'in', 'in', 'out' ) ) === $flags, "PLANT R: $name: the threads are " . json_encode( $flags ) );
    foreach ( $t as $th ) {
        $ins = array();
        foreach ( $th as $x ) { if ( 'in' === $x['dir'] ) { $ins[] = $x['l']; } }
        foreach ( $th as $x ) {
            rl( $x['seen'] && $x['inside'] && $x['bodyFits'], "PLANT R: $name: " . json_encode( $x['body'] ) . ' is not wrapped inside the card: ' . json_encode( $x ) );
            if ( 'sent' === $x['dir'] ) {
                rl( $ins && $x['l'] >= max( $ins ) + 12, "PLANT R: $name: the message replied to is not set in like an answer: " . json_encode( array( $x['l'], $ins ) ) );
                rl( 'rgba(0, 0, 0, 0)' === $x['bg'] && $x['metaContrast'] >= 4.5, "PLANT R: $name: the message replied to is drawn as " . json_encode( $x ) );
            }
        }
    }
}

echo "\n3.144.2 in a browser: the segmented toggle's teal edge, what a reply answered\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " FAILURE(S):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo count( $files ) . " pages: caladmin at 1280, 430 and 390px.\n";
exit( 0 );
