<?php
/**
 * 3.113.8 IN A BROWSER: THE SIDEBAR'S MONTH BUTTONS, AND AN IMPORTED PICTURE.
 *
 *     php .claude/release-31138-pages-live.php --run
 *     php .claude/release-31138-pages-live.php --run --verbose   and print what was measured
 *     php .claude/release-31138-pages-live.php --run --shots     and save a picture of each
 *
 * The real calendar.css. The sidebar is SFAF_Shortcodes::render_sidebar()'s
 * own markup for December, through wp-kit.php, inside the combined view's
 * panels as the renderer nests them. At 1280px (side by side), 700px (stacked)
 * and 390px:
 *
 *   7  the sidebar paints no shadow and no corner of its own, so the shared
 *      card is the only edge; Nov, Jan and See all events sit inside that card
 *      with the panel's padding between them and its border, and no button
 *      reaches past the column; each month button is the public secondary
 *      button: the teal outline at 4.5:1 or more, the pill, the teal label
 *
 *   4  on the event page, an imported picture 2.4 times as wide as it is tall
 *      is drawn whole at that shape across the column, and a portrait one is
 *      whole and no taller than 80% of the window; a picture chosen here keeps
 *      its 16:9 box
 *
 * PLANTs 7L and 4L in plant-31138.php.
 */

require __DIR__ . '/wp-kit.php';
$root  = dirname( __DIR__ );
$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$side = ( new SFAF_Shortcodes() )->render_sidebar( array(), 10, null, '2026-12' );
$combined = '<div class="uc-calendar uc-view-combined" data-view="combined"><div class="uc-view-panels uc-view-panels-combined">'
    . '<div class="uc-combined-head"><div class="uc-month-head"><div class="uc-month-heading"><h3 class="uc-month-label">December 2026</h3></div></div></div>'
    . '<div class="uc-view-panel uc-panel-calendar"><div class="uc-month"><div class="uc-month-wrap"><table class="uc-month-grid"><tbody><tr><td class="uc-day">1</td><td class="uc-day">2</td><td class="uc-day">3</td><td class="uc-day">4</td><td class="uc-day">5</td><td class="uc-day">6</td><td class="uc-day">7</td></tr></tbody></table></div></div></div>'
    . '<div class="uc-view-panel uc-panel-sidebar">' . $side . '</div></div></div>';

function pic( $w, $h ) {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '"><rect width="100%" height="100%" fill="#16BECF"/><rect x="0" y="0" width="40" height="' . $h . '" fill="#F04937"/><rect x="' . ( $w - 40 ) . '" y="0" width="40" height="' . $h . '" fill="#8CC745"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}
$single = '<div class="uc-single"><div class="uc-single-main" style="max-width:760px">'
    . '<div class="uc-single-image uc-single-image-own-shape" id="wide"><img class="uc-thumb-img" src="' . pic( 1200, 500 ) . '" alt="wide"></div>'
    . '<div class="uc-single-image uc-single-image-own-shape" id="tall"><img class="uc-thumb-img" src="' . pic( 600, 1200 ) . '" alt="tall"></div>'
    . '<div class="uc-single-image" id="ours"><img class="uc-thumb-img" src="' . pic( 1200, 500 ) . '" alt="ours"></div>'
    . '</div></div>';

$probe = <<<'JS'
function rect(el) { var r = el.getBoundingClientRect(); return { l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, h: r.height }; }
function lum(c) { var m = c.match(/\d+(\.\d+)?/g).map(Number).slice(0, 3).map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]; }
function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
var out = { vw: window.innerWidth, pageWidth: document.documentElement.scrollWidth };
var card = document.querySelector('.uc-view-panels-combined'), panel = document.querySelector('.uc-panel-sidebar'), side = document.querySelector('.uc-sidebar');
var ss = getComputedStyle(side), ps = getComputedStyle(panel);
var cb = rect(card), pb = rect(panel);
out.side = { shadow: ss.boxShadow, radius: ss.borderTopLeftRadius, border: ss.borderTopWidth };
out.inner = { l: pb.l + parseFloat(ps.paddingLeft), r: pb.r - parseFloat(ps.paddingRight) };
out.card = cb;
out.tabs = Array.prototype.map.call(document.querySelectorAll('.uc-month-tab'), function (t) {
  var s = getComputedStyle(t), n = t.querySelector('.uc-month-tab-name'), c = t.querySelector('.uc-month-tab-count');
  return { box: rect(t), edge: s.borderTopColor, edgeW: s.borderTopWidth, bg: s.backgroundColor, radius: s.borderTopLeftRadius, ink: getComputedStyle(n).color, count: getComputedStyle(c).color,
    name: n.textContent, nameClipped: n.scrollWidth > n.clientWidth, edgeRatio: ratio(s.borderTopColor, 'rgb(255,255,255)'), inkRatio: ratio(getComputedStyle(n).color, s.backgroundColor),
    dir: t.classList.contains('uc-month-tab-prev') ? 'prev' : 'next', nameBox: rect(n), countBox: rect(c),
    chev: (function (p) { return { w: p.width, h: p.height, fill: p.backgroundColor, mask: (p.webkitMaskImage || p.maskImage || 'none').indexOf('m9 5 7 7-7 7') !== -1, flip: p.transform }; })(getComputedStyle(t, t.classList.contains('uc-month-tab-prev') ? '::before' : '::after')) };
});
out.all = rect(document.querySelector('.uc-sidebar-all'));
out.pics = {};
['wide', 'tall', 'ours'].forEach(function (id) {
  var box = document.getElementById(id), img = box.querySelector('img');
  out.pics[id] = { box: rect(box), img: rect(img), natural: img.naturalWidth / img.naturalHeight, fit: getComputedStyle(img).objectFit, column: rect(box.parentNode).w, vh: window.innerHeight };
});
window.parent.postMessage(JSON.stringify(out), '*');
JS;

$page = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SFAF Calendar: month buttons and imported pictures</title>
<!-- GENERATED BY .claude/release-31138-pages-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/calendar.css">
<style> body { margin: 0; padding: 16px; background: #f4f5f7; } * { transition: none !important; animation: none !important; } </style>
</head>
<body>
$combined
$single
<script>window.addEventListener('load', function () { setTimeout(function () {
$probe
}, 150); });</script>
</body>
</html>
HTML;

$in = __DIR__ . '/release-31138-pages-live.html';
file_put_contents( $in, $page );
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo "wrote the page; --run drives it.\n"; exit( 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

rl( 2 === substr_count( $side, 'uc-actionbtn-secondary' ), 'the sidebar did not render two month buttons for December: ' . substr( $side, 0, 300 ) );
foreach ( array( 'desktop' => 1280, 'stacked' => 700, 'phone' => 390 ) as $wn => $w ) {
    // A frame of that width: headless Chrome's window will not go under ~500px.
    $frame = __DIR__ . '/release-31138-pages-live-' . $wn . '-frame.html';
    $shot  = __DIR__ . '/release-31138-pages-live-' . $wn . '-shot.html';
    $frame_html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $in ) . '" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>';
    file_put_contents( $frame, $frame_html );
    file_put_contents( $shot, str_replace( 'height:900px', 'height:1800px', $frame_html ) );
    $url = 'file:///' . str_replace( '\\', '/', $frame );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=10000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$wn: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $argv, true ) ) { echo "$wn: " . json_encode( $r ) . "\n"; }
    if ( in_array( '--shots', $argv, true ) ) {
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . $w . ',1800 --virtual-time-budget=8000 --screenshot="' . __DIR__ . '/release-31138-pages-' . $wn . '.png" "file:///' . str_replace( '\\', '/', $shot ) . '" 2>NUL' );
    }
    rl( (int) $r['pageWidth'] <= $w, "$wn: the page scrolls sideways" );

    rl( 'none' === $r['side']['shadow'] && '0px' === $r['side']['radius'], "7L: $wn: the sidebar still draws a card of its own: " . json_encode( $r['side'] ) );
    $c = $r['card'];
    foreach ( array_merge( $r['tabs'], array( array( 'box' => $r['all'], 'name' => 'See all events' ) ) ) as $t ) {
        $b = $t['box'];
        rl( $b['l'] >= $c['l'] + 16 && $b['r'] <= $c['r'] - 16 && $b['b'] <= $c['b'] - 16, "7L: $wn: " . $t['name'] . ' is not inside the card with room to spare: ' . json_encode( array( $b, $c ) ) );
        rl( $b['l'] >= $r['inner']['l'] - 0.5 && $b['r'] <= $r['inner']['r'] + 0.5, "7L: $wn: " . $t['name'] . ' reaches past the column: ' . json_encode( array( $b, $r['inner'] ) ) );
    }
    rl( 2 === count( $r['tabs'] ), "$wn: not two month buttons" );
    if ( 2 === count( $r['tabs'] ) ) {
        $t0 = $r['tabs'][0]['box']; $t1 = $r['tabs'][1]['box'];
        rl( abs( $t0['l'] - $r['inner']['l'] ) < 1 && abs( $t1['r'] - $r['inner']['r'] ) < 1 && abs( $t0['w'] - $t1['w'] ) < 1,
            "7L: $wn: the month buttons do not share the column's width: " . json_encode( array( $t0, $t1, $r['inner'] ) ) );
    }
    foreach ( $r['tabs'] as $t ) {
        rl( 'rgb(14, 118, 128)' === $t['edge'] && '1px' === $t['edgeW'] && $t['edgeRatio'] >= 4.5, "7L: $wn: " . $t['name'] . ' has no teal outline: ' . json_encode( $t ) );
        rl( (float) $t['radius'] >= 99 && 'rgb(255, 255, 255)' === $t['bg'], "7L: $wn: " . $t['name'] . ' is not the pill: ' . json_encode( $t ) );
        rl( 'rgb(14, 118, 128)' === $t['ink'] && $t['ink'] === $t['count'] && $t['inkRatio'] >= 4.5, "7L: $wn: " . $t['name'] . "'s label is not the button's teal: " . json_encode( $t ) );
        rl( ! $t['nameClipped'], "$wn: " . $t['name'] . ' is cut off' );
        rl( $t['box']['h'] >= 24, "$wn: " . $t['name'] . ' is under 24px tall' );
        $ch = $t['chev'];
        rl( '14px' === $ch['w'] && '14px' === $ch['h'] && $ch['mask'] && $ch['fill'] === $t['ink'], "7C: $wn: " . $t['name'] . ' has no chevron in its ink: ' . json_encode( $ch ) );
        rl( 'prev' === $t['dir'] ? ( 'none' !== $ch['flip'] && $t['nameBox']['l'] - $t['box']['l'] >= 34 ) : ( 'none' === $ch['flip'] && $t['box']['r'] - $t['countBox']['r'] >= 34 ),
            "7C: $wn: " . $t['name'] . "'s chevron is not on its outer side, pointing out: " . json_encode( $t ) );
    }

    $p = $r['pics'];
    $wide = $p['wide']; $tall = $p['tall']; $ours = $p['ours'];
    rl( abs( $wide['img']['w'] / $wide['img']['h'] - $wide['natural'] ) < 0.02 && abs( $wide['img']['w'] - $wide['column'] ) < 1 && 'contain' === $wide['fit'] && abs( $wide['box']['h'] - $wide['img']['h'] ) < 1,
        "4L: $wn: a wide imported picture is not whole at its own shape across the column: " . json_encode( $wide ) );
    rl( abs( $tall['img']['w'] / $tall['img']['h'] - $tall['natural'] ) < 0.02 && $tall['img']['h'] <= 0.8 * $tall['vh'] + 1, "4L: $wn: a portrait imported picture is cropped or taller than 80% of the window: " . json_encode( $tall ) );
    rl( abs( $ours['box']['w'] / $ours['box']['h'] - 16 / 9 ) < 0.02 && 'cover' === $ours['fit'], "$wn: a picture chosen here lost its 16:9 box: " . json_encode( $ours ) );
}

echo "\n3.113.8: the sidebar's month buttons and an imported picture, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "The month buttons are teal-outlined pills inside the shared card with See all events, and the sidebar draws no card of its own;\nan imported picture is whole at its own shape and a chosen one keeps 16:9, at 1280px, 700px and 390px.\n";
exit( 0 );
