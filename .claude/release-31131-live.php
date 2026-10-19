<?php
/**
 * 3.113.1 IN A BROWSER: THE ADD BUTTONS' STATE AND THE DISMISSED FOLD.
 *
 *     php .claude/release-31131-live.php --run
 *     php .claude/release-31131-live.php --run --verbose   and print what was measured
 *
 * REAL: Add event rendered through wp-kit.php, with Pending's Dismissed card
 * (the real render_dismissed_queue()) added to the same page, the real
 * portal.css and portal.js, in Chrome at 1280px and 390px, with the script
 * and without it.
 *
 *   - + Add category and + Add organizer: the chevron points down while the
 *     list is shut and up while it is open; open, the button has the hover
 *     face, the accent edge and an inset shadow; a second click shuts it and
 *     puts all three back
 *   - Dismissed: shut on load, its button "Show dismissed (2)" with the
 *     chevron down; pressed, the list shows, the button says "Hide dismissed
 *     (2)", looks pressed, and the chevron points up; pressed again, it shuts.
 *     The same with no script.
 *
 * PLANT 4L and 1L in plant-31131.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1, 'pending' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
function rl_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type || ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) ) { continue; }
        $out[] = $p;
    }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'ID' ) : $out;
}
$GLOBALS['kit_query'] = 'rl_query'; $GLOBALS['kit_get_posts'] = 'rl_query';
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();

$add = rl_cap( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) ); } );
rl( 0 !== strpos( $add, 'THREW' ), "Add event did not render: $add" );
/* Pending's Dismissed fold was checked here until 3.113.2 made Dismissed a
   tab; release-31132-live.php has that. */
$page = $add;

$probe = <<<'JS'
(function () {
var out = { errors: [], buttons: {}, fold: {} };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function shown(el) { return !!el && !el.hidden && el.getClientRects().length > 0; }
/* Degrees from the computed transform: 90 is down for a right-pointing glyph. */
function angle(el) {
  var t = getComputedStyle(el).transform;
  if (!t || t === 'none') { return 0; }
  var m = t.match(/matrix\(([^)]+)\)/); if (!m) { return null; }
  var v = m[1].split(',').map(parseFloat);
  return Math.round(Math.atan2(v[1], v[0]) * 180 / Math.PI);
}
function face(b) { var s = getComputedStyle(b); return { bg: s.backgroundColor, edge: s.borderTopColor, shadow: s.boxShadow }; }
function state(btn, extra) {
  var c = btn.querySelector('.uc-disclosure-chevron');
  var o = { chevron: c ? angle(c) : null, chevronShown: shown(c), face: face(btn), text: btn.innerText.replace(/\s+/g, ' ').trim() };
  if (extra) { extra(o); } return o;
}
function wait(ms, fn) { setTimeout(fn, ms); }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }

/* Transitions off, so a reading is the end state and not a frame of it. */
var st = document.createElement('style'); st.textContent = '*{transition:none !important}'; document.head.appendChild(st);

var fold = q('[data-uc-dismissed-fold]'), sum = fold ? q('summary', fold) : null, list = fold ? q('.uc-queue-list', fold) : null;
function listSeen() { return !!fold && fold.getBoundingClientRect().height - sum.getBoundingClientRect().height > 20 && shown(list); }
out.fold.found = !!sum;
if (sum) { out.fold.shut = state(sum, function (o) { o.open = fold.open; o.list = listSeen(); }); }

wait(50, function () {
  if (sum) { sum.click(); out.fold.opened = state(sum, function (o) { o.open = fold.open; o.list = listSeen(); }); }
  wait(80, function () {
    if (sum) { sum.click(); out.fold.closed = state(sum, function (o) { o.open = fold.open; o.list = listSeen(); }); }
    var togs = Array.prototype.slice.call(document.querySelectorAll('[data-uc-chips-toggle]'));
    out.buttonCount = togs.length;
    (function next(i) {
      if (i >= togs.length) { wait(50, finish); return; }
      var b = togs[i], name = b.textContent.replace(/\s+/g, ' ').trim(), src = b.closest('[data-uc-chips]').querySelector('[data-uc-chips-source]');
      var r = out.buttons[name] = { shown: shown(b) };
      if (!r.shown) { next(i + 1); return; }
      r.shut = state(b, function (o) { o.expanded = b.getAttribute('aria-expanded'); o.list = shown(src); });
      b.click();
      wait(60, function () {
        r.opened = state(b, function (o) { o.expanded = b.getAttribute('aria-expanded'); o.list = shown(src); });
        b.click();
        wait(60, function () {
          r.closed = state(b, function (o) { o.expanded = b.getAttribute('aria-expanded'); o.list = shown(src); });
          next(i + 1);
        });
      });
    })(0);
  });
});
})();
JS;

$pages = array();
foreach ( array( 'js', 'nojs' ) as $mode ) {
    foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
        if ( 'nojs' === $mode && 'phone' === $wn ) { continue; }
        $html = $page;
        if ( 'nojs' === $mode ) { $html = preg_replace( '#<script[^>]*src="[^"]*portal\.js[^"]*"[^>]*></script>#', '', $html ); }
        $pages[ "$mode-$wn" ] = array( 'w' => $w, 'html' => str_replace( '</body>', '<script>' . $probe . '</script></body>', $html ) );
    }
}
rl( false !== strpos( $page, 'portal.js' ), 'the page loads no portal.js, so the script half cannot be tested' );

$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31131-live-' . $name . '.html';
    file_put_contents( $inner, $p['html'] );
    $files[ $name ] = __DIR__ . '/release-31131-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=20000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ]['w'], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ]['w'] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}

$pressed = function ( $f ) { return 'rgb(191, 194, 196)' === $f['bg'] && 'none' !== $f['shadow'] && false !== strpos( $f['shadow'], 'inset' ); };
foreach ( $g as $name => $r ) {
    list( $mode ) = explode( '-', $name );

    /* ---- + Add category and + Add organizer ---- */
    if ( 'nojs' === $mode ) {
        foreach ( (array) $r['buttons'] as $n => $b ) { rl( ! $b['shown'], "$name: with no script, $n shows, though the list is already open" ); }
        continue;
    }
    rl( 2 === (int) $r['buttonCount'], "$name: " . (int) $r['buttonCount'] . ' add buttons, not two' );
    foreach ( array( '+ Add category', '+ Add organizer' ) as $n ) {
        $b = isset( $r['buttons'][ $n ] ) ? $r['buttons'][ $n ] : null;
        if ( ! $b || ! $b['shown'] ) { rl( false, "$name: $n is not on the page" ); continue; }
        rl( 'false' === $b['shut']['expanded'] && ! $b['shut']['list'] && 90 === $b['shut']['chevron'] && $b['shut']['chevronShown'] && ! $pressed( $b['shut']['face'] ),
            "4L $name: $n shut is not chevron down and unpressed: " . json_encode( $b['shut'] ) );
        rl( 'true' === $b['opened']['expanded'] && $b['opened']['list'] && -90 === $b['opened']['chevron'] && $pressed( $b['opened']['face'] ) && 'rgb(14, 118, 128)' === $b['opened']['face']['edge'],
            "4L $name: $n open is not chevron up and pressed: " . json_encode( $b['opened'] ) );
        rl( 'false' === $b['closed']['expanded'] && ! $b['closed']['list'] && 90 === $b['closed']['chevron'] && ! $pressed( $b['closed']['face'] ),
            "4L $name: a second click did not shut $n and put it back: " . json_encode( $b['closed'] ) );
    }
}

echo "\nRelease 3.113.1 in a browser\n" . str_repeat( '=', 72 ) . "\n";
echo count( $g ) . " pages driven.\n";
if ( $fails ) { echo count( $fails ) . " failure(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "The add buttons point down shut and up open, look pressed while open, and a second press shuts them.\n";
exit( 0 );
