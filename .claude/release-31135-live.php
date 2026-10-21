<?php
/**
 * 3.113.5 IN A BROWSER: THE EDITOR'S ACTION BAR, WHITE AND FLOATING.
 *
 *     php .claude/release-31135-live.php --run
 *     php .claude/release-31135-live.php --run --verbose   and print what was measured
 *     php .claude/release-31135-live.php --run --shots     and save a picture of each
 *
 * REAL: Edit event and Add event, rendered through wp-kit.php, with the real
 * portal.css and portal.js, in Chrome at 1280px and 390px, measured at the top
 * of the form, halfway down and at the very end:
 *
 *   - the bar is white, over the grey page, under a 1px Dark Gray #373433 rule,
 *     casting a shadow upward; no side borders, no radius
 *   - while the form scrolls it is stuck to the foot of the window, and the
 *     cards pass under it; at the end it sits in the flow
 *   - Cancel event is outlined in #6B6764 with no face, Save changes and
 *     Publish are green, Delete is the red link at the far left (the last line
 *     at 390px), and every button is inside the window
 *
 * PLANTs 2L in plant-31135.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1, 'pending' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }

$fails = array();
function rb( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rb_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => 1, 'post_content' => '<p>Weekly.</p>' ) );
foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+7 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }

$edit = rb_cap( function () use ( $portal, $id ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $id ) ); } );
$add  = rb_cap( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) ); } );
rb( 0 !== strpos( $edit, 'THREW' ), "Edit event did not render: $edit" );
rb( 0 !== strpos( $add, 'THREW' ), "Add event did not render: $add" );

$probe = <<<'JS'
(function () {
var out = { errors: [], at: {} };
window.addEventListener('beforeunload', function (e) { e.stopImmediatePropagation(); }, true);
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function css(el, props) { if (!el) { return null; } var s = getComputedStyle(el), o = {}; props.forEach(function (p) { o[p] = s[p]; }); return o; }
/* What the page shows behind the bar: the first ancestor with a face. */
function face(el) { while (el && el !== document.documentElement) { var b = getComputedStyle(el).backgroundColor; if (b && b !== 'rgba(0, 0, 0, 0)' && b !== 'transparent') { return b; } el = el.parentElement; } return getComputedStyle(document.body).backgroundColor; }
function measure() {
  var bar = q('[data-uc-editor-bar]');
  if (!bar) { return { bar: null }; }
  var br = bar.getBoundingClientRect();
  var o = { bar: rect(bar), vh: window.innerHeight, vw: window.innerWidth,
    css: css(bar, ['position', 'backgroundColor', 'borderTopColor', 'borderTopWidth', 'borderTopStyle', 'borderLeftWidth', 'borderRightWidth', 'borderBottomWidth', 'borderTopLeftRadius', 'boxShadow']),
    behind: face(bar.parentElement) };
  /* Just above the bar, what is there is the page or a card scrolling under it. */
  var above = document.elementFromPoint(Math.round(br.left + 4), Math.max(0, Math.round(br.top) - 3));
  o.aboveFace = above ? face(above) : null;
  /* Inside the bar's own box, the bar is on top of whatever passes under it. */
  var inside = document.elementFromPoint(Math.round(br.left + br.width / 2), Math.round(br.top + 2));
  o.barOnTop = !!inside && (inside === bar || bar.contains(inside));
  var btn = function (sel) { var b = q(sel, bar); return b && b.getClientRects().length ? { box: rect(b), css: css(b, ['backgroundColor', 'borderTopColor', 'borderTopWidth', 'color']), text: b.textContent.replace(/\s+/g, ' ').trim() } : null; };
  o.cancel = btn('.uc-cancel-open'); o.del = btn('.uc-editor-delete'); o.save = btn('.uc-editor-save-main') || btn('.uc-editor-save');
  o.publish = btn('.uc-editor-publish');
  o.buttons = Array.prototype.slice.call(bar.querySelectorAll('button, a')).filter(function (b) { return b.getClientRects().length > 0; }).map(function (b) { return { text: b.textContent.replace(/\s+/g, ' ').trim(), box: rect(b) }; });
  o.scrollY = Math.round(window.scrollY);
  return o;
}
window.addEventListener('load', function () { setTimeout(function () {
  var max = document.documentElement.scrollHeight - window.innerHeight;
  window.scrollTo(0, 0);
  out.at.top = measure();
  window.scrollTo(0, Math.round(max / 2));
  setTimeout(function () {
    out.at.mid = measure();
    window.scrollTo(0, max + 50);
    setTimeout(function () {
      out.at.end = measure();
      out.max = max;
      out.pageWidth = document.documentElement.scrollWidth;
      out.bodyFace = getComputedStyle(document.body).backgroundColor;
      /* Leave it scrolled halfway for the picture. */
      window.scrollTo(0, Math.round(max / 2));
      window.parent.postMessage(JSON.stringify(out), '*');
    }, 250);
  }, 250);
}, 700); });
})();
JS;

$pages = array();
foreach ( array( 'edit' => $edit, 'add' => $add ) as $name => $html ) {
    foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
        $pages[ "$name-$wn" ] = array( 'w' => $w, 'html' => str_replace( '</body>', '<script>' . $probe . '</script></body>', $html ) );
    }
}
rb( false !== strpos( $edit, 'portal.js' ), 'the editor page loads no portal.js' );

$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31135-live-' . $name . '.html';
    file_put_contents( $inner, $p['html'] );
    $files[ $name ] = __DIR__ . '/release-31135-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:800px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
$verbose = in_array( '--verbose', $argv, true );
$shots   = in_array( '--shots', $argv, true );

$g = array();
foreach ( $files as $name => $file ) {
    $url = 'file:///' . str_replace( '\\', '/', $file );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=20000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rb( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( $verbose ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
    if ( $shots ) {
        $png = __DIR__ . '/release-31135-shot-' . $name . '.png';
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . ( $pages[ $name ]['w'] ) . ',800 --virtual-time-budget=8000 --screenshot="' . $png . '" "' . $url . '" 2>NUL' );
    }
}

$white = 'rgb(255, 255, 255)'; $grey = 'rgb(245, 246, 247)';
foreach ( $g as $name => $r ) {
    list( $page, $wn ) = explode( '-', $name );
    rb( empty( $r['errors'] ), "$name threw: " . implode( '; ', (array) $r['errors'] ) );
    rb( (int) $r['pageWidth'] <= $pages[ $name ]['w'], "$name: the page scrolls sideways, " . $r['pageWidth'] . 'px in ' . $pages[ $name ]['w'] );
    rb( $grey === $r['bodyFace'], "$name: the page behind the cards is " . $r['bodyFace'] . ', not the canvas grey' );
    rb( $r['max'] > 400, "$name: the form is too short to scroll under the bar (" . $r['max'] . 'px)' );
    foreach ( array( 'top', 'mid', 'end' ) as $at ) {
        $m = isset( $r['at'][ $at ] ) ? $r['at'][ $at ] : null;
        if ( empty( $m['bar'] ) ) { rb( false, "$name at $at: no bar" ); continue; }
        $c = $m['css'];
        rb( 'sticky' === $c['position'], "2L: $name at $at: the bar is not sticky" );
        rb( $white === $c['backgroundColor'], "2L: $name at $at: the bar is " . $c['backgroundColor'] . ', not white' );
        rb( 'rgb(55, 52, 51)' === $c['borderTopColor'] && '1px' === $c['borderTopWidth'] && 'solid' === $c['borderTopStyle'], "2L: $name at $at: the top rule is not 1px solid Dark Gray: " . json_encode( $c ) );
        rb( '0px' === $c['borderLeftWidth'] && '0px' === $c['borderRightWidth'] && '0px' === $c['borderBottomWidth'] && '0px' === $c['borderTopLeftRadius'], "2L: $name at $at: the bar has side borders or a radius: " . json_encode( $c ) );
        rb( (bool) preg_match( '#^rgba\(55, 52, 51, 0\.1\d*\) 0px -\d+px \d+px#', $c['boxShadow'] ), "2L: $name at $at: no soft shadow cast upward: " . $c['boxShadow'] );
        rb( $grey === $m['behind'], "$name at $at: the bar does not stand on the grey page: " . $m['behind'] );
        rb( $m['barOnTop'], "$name at $at: something is drawn over the bar" );
        if ( 'end' !== $at ) {
            rb( abs( $m['bar']['bottom'] - $m['vh'] ) <= 1, "$name at $at: the bar is not stuck to the foot of the window: " . json_encode( array( $m['bar'], $m['vh'] ) ) );
        }
        foreach ( (array) $m['buttons'] as $b ) {
            if ( 'end' === $at || 'top' === $at ) {
                rb( $b['box']['left'] >= 0 && $b['box']['right'] <= $m['vw'], "$name at $at: \"{$b['text']}\" leaves the window: " . json_encode( $b['box'] ) );
            }
        }
        if ( 'edit' === $page ) {
            rb( $m['cancel'] && 'rgb(107, 103, 100)' === $m['cancel']['css']['borderTopColor'] && 'rgba(0, 0, 0, 0)' === $m['cancel']['css']['backgroundColor'], "$name at $at: Cancel event is not outlined in #6B6764 with no face: " . json_encode( $m['cancel'] ) );
            rb( $m['save'] && 'rgb(21, 128, 61)' === $m['save']['css']['backgroundColor'], "$name at $at: Save changes is not green: " . json_encode( $m['save'] ) );
            rb( $m['del'] && 'rgb(192, 57, 43)' === $m['del']['css']['color'], "$name at $at: Delete is not the red link: " . json_encode( $m['del'] ) );
            if ( $m['del'] && $m['cancel'] && $m['save'] ) {
                if ( 'desktop' === $wn ) {
                    rb( $m['del']['box']['left'] < $m['cancel']['box']['left'] && $m['cancel']['box']['left'] < $m['save']['box']['left'], "$name at $at: the row is not Delete, Cancel event, Save changes, left to right" );
                    $min = min( array_map( function ( $b ) { return $b['box']['left']; }, $m['buttons'] ) );
                    rb( $m['del']['box']['left'] === $min, "$name at $at: Delete is not at the far left" );
                } else {
                    rb( $m['del']['box']['top'] >= $m['save']['box']['bottom'] && $m['del']['box']['top'] >= $m['cancel']['box']['bottom'], "$name at $at: Delete does not take the last line at 390px" );
                }
            }
        } else {
            rb( $m['publish'] && 'rgb(21, 128, 61)' === $m['publish']['css']['backgroundColor'], "$name at $at: Publish is not green: " . json_encode( $m['publish'] ) );
        }
    }
    /* Halfway down, what is just above the bar is a card or the page, not the bar. */
    $mid = isset( $r['at']['mid'] ) ? $r['at']['mid'] : null;
    rb( $mid && $mid['bar']['top'] > 200, "$name: halfway down, the bar is not at the foot of the window" );
    rb( $mid && 'sticky' === $mid['css']['position'] && $r['at']['end']['bar']['bottom'] < $r['at']['end']['vh'] + 1, "$name: at the end the bar is not in the flow" );
}
if ( count( $g ) !== count( $files ) ) { rb( false, 'only ' . count( $g ) . ' of ' . count( $files ) . ' pages were measured' ); }

echo "\n3.113.5: the editor's action bar, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Edit event's and Add event's bar is white over the grey page, under a Dark Gray rule with a shadow cast\nupward, stuck to the window's foot while the form scrolls, at 1280px and 390px.\n";
exit( 0 );
