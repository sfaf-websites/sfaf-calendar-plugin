<?php
/**
 * 3.139.0 IN A BROWSER: REPLIES GO TO LOOKS LIKE A SELECT AND OPENS OVER EVERYTHING.
 *
 *     php .claude/release-31390-live.php --run
 *     php .claude/release-31390-live.php --run --verbose   and print what was measured
 *
 * REAL: Add event and Edit event rendered through wp-kit.php with the real
 * portal.css and portal.js, in Chrome at 1280px and 390px.
 *
 *   - The box carries the select's own end cap and chevron: its computed
 *     background-image is the one a select on the same page computes, with
 *     the select's 38px at the right; open, the chevron points up.
 *   - With the box just above the save bar (as near as the page's end lets it), the
 *     list opens upward, wholly on screen, and the browser hits the list at
 *     its first option and at its foot.
 *   - With room for it below (a spacer above the save bar stands in for a
 *     longer form), the list opens downward, past the card's bottom border
 *     and over the save bar, and the browser hits the list there, not the
 *     bar or the card.
 *   - Escape closes it and a click on the box opens it again.
 *   - Who gets these emails keeps its list in the card, not floated.
 *
 * PLANT in plant-31390.php.
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
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me = new WP_User();
$GLOBALS['kit_users'] = array( $me );
$GLOBALS['kit_umeta'][ $me->ID ]['_uc_calendar_role'] = 'admin';
foreach ( array( 21 => 'Ana Ruiz', 22 => 'Ben Young', 23 => 'Cy Ortiz', 24 => 'Dana Park', 25 => 'Eli Moss' ) as $uid => $name ) {
    $u = new WP_User(); $u->ID = $uid; $u->display_name = $name; $u->user_email = strtolower( strtok( $name, ' ' ) ) . '@example.org'; $u->user_login = 'u' . $uid;
    $GLOBALS['kit_users'][] = $u;
    $GLOBALS['kit_umeta'][ $uid ]['_uc_calendar_role'] = 'editor';
}
$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => $me->ID, 'post_content' => '<p>Weekly.</p>' ) );
foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+7 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $ev, $k, $v ); }

$screens = array(
    'add'  => rl_cap( function () use ( $portal, $me ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $me, 0 ) ); } ),
    'edit' => rl_cap( function () use ( $portal, $me, $ev ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $me, $ev ) ); } ),
);
foreach ( $screens as $s => $html ) {
    rl( 0 !== strpos( $html, 'THREW' ), "$s did not render: $html" );
    rl( false !== strpos( $html, 'portal.js' ) && false !== strpos( $html, 'data-uc-replyto' ), "$s: no portal.js or no Replies go to on the page" );
}

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('beforeunload', function (e) { e.stopImmediatePropagation(); }, true);
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), h: Math.round(r.height) }; }
function wait(ms, fn) { setTimeout(fn, ms); }
function key(el, k) { el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true })); }
function hit(x, y, list) { var el = document.elementFromPoint(x, y); return { inList: !!el && list.contains(el), what: el ? el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).join('.') : '') : null }; }
function popOpen(el) { try { return el.matches(':popover-open'); } catch (e) { return null; } }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
function snap(box, list, bar, card) {
  var lr = rect(list), first = list.firstElementChild, fr = rect(first);
  return {
    shown: !list.hidden && list.getClientRects().length > 0, pop: popOpen(list), side: list.getAttribute('data-uc-sl-side'),
    list: lr, box: rect(box), bar: rect(bar), card: rect(card), vh: window.innerHeight, count: list.children.length,
    hitFirst: fr ? hit(fr.left + 20, fr.top + fr.h / 2, list) : null,
    hitLast: lr ? hit(lr.left + 20, lr.bottom - 6, list) : null,
    chevron: getComputedStyle(box).backgroundImage
  };
}
// Put the box's bottom edge at `y` in the window.
function scrollBoxTo(box, y) { window.scrollBy(0, box.getBoundingClientRect().bottom - y); }

document.addEventListener('DOMContentLoaded', function () { wait(300, function () {
  var reply = q('[data-uc-replyto]'), select = reply && q('[data-uc-replyto-pick]', reply);
  var box = reply && q('[data-uc-replyto-search]', reply), list = box && document.getElementById(box.getAttribute('aria-controls'));
  var bar = q('.uc-editor-bar'), card = q('[data-uc-card="notifications"]');
  var rlist = q('[data-uc-recipients] [data-uc-rcpt-list]');
  out.has = { box: !!box, list: !!list, bar: !!bar, card: !!card, rlist: !!rlist };
  if (!box || !list || !bar || !card) { finish(); return; }
  out.rcptFloated = rlist ? (rlist.hasAttribute('popover') || rlist.classList.contains('uc-sl-float')) : null;
  var cs = getComputedStyle(box), ss = getComputedStyle(select);
  out.closed = { boxImage: cs.backgroundImage, selectImage: ss.backgroundImage, boxPad: cs.paddingRight, selectPad: ss.paddingRight,
    boxPos: cs.backgroundPosition, selectPos: ss.backgroundPosition, boxSize: cs.backgroundSize, selectSize: ss.backgroundSize, boxRepeat: cs.backgroundRepeat };

  // A spacer above the save bar, as a longer form would have, so the box can
  // sit anywhere in the window. It goes before the last case, which is the
  // page as it is, scrolled to its end.
  var spacer = document.createElement('div');
  spacer.style.height = window.innerHeight + 'px';
  bar.parentNode.insertBefore(spacer, bar);
  // Mid-window first, to learn the list's height.
  scrollBoxTo(box, Math.round(window.innerHeight / 3));
  box.focus();
  wait(80, function () {
    out.mid = snap(box, list, bar, card);
    var h = out.mid.list ? out.mid.list.h : 0;
    key(box, 'Escape');
    wait(60, function () {
      out.escaped = { shown: !list.hidden && list.getClientRects().length > 0, pop: popOpen(list), expanded: box.getAttribute('aria-expanded') };
      // Down, ending inside the save bar: the box sits the list's height above the bar's middle.
      var barNow = bar.getBoundingClientRect();
      scrollBoxTo(box, Math.round(window.innerHeight - barNow.height / 2 - h - 2));
      wait(80, function () {
        box.click();
        wait(80, function () {
          out.down = snap(box, list, bar, card);
          var lr = list.getBoundingClientRect();
          out.down.hitFoot = hit(lr.left + 20, lr.bottom - 6, list);
          out.down.footInBar = (lr.bottom - 6) >= bar.getBoundingClientRect().top;
          var cb = card.getBoundingClientRect().bottom;
          out.down.hitCardEdge = (cb > lr.top && cb < lr.bottom) ? hit(lr.left + 20, cb, list) : null;
          key(box, 'Escape');
          wait(60, function () {
            // Up: no spacer, the box just above the save bar, or as near it as
            // the page's end allows (on a desktop the card is the last thing).
            spacer.parentNode.removeChild(spacer);
            scrollBoxTo(box, Math.round(bar.getBoundingClientRect().top - 8));
            out.endGap = Math.round(bar.getBoundingClientRect().top - box.getBoundingClientRect().bottom);
            wait(80, function () {
              box.click();
              wait(80, function () {
                out.up = snap(box, list, bar, card);
                key(box, 'Escape');
                finish();
              });
            });
          });
        });
      });
    });
  });
}); });
})();
JS;

function rl_page( $html, $probe ) {
    // The session hold has no server here (caladmin-posts-are-held-for-a-check).
    $html = str_replace( 'id="uc-session-config"', 'id="uc-session-config-off"', $html );
    return str_replace( '</body>', '<script>' . $probe . '</script></body>', $html );
}
$pages = array();
foreach ( $screens as $s => $html ) {
    foreach ( array( 'desktop' => array( 1280, 900 ), 'phone' => array( 390, 844 ) ) as $w => $size ) {
        $pages[ "$s-$w" ] = array( 'w' => $size[0], 'h' => $size[1], 'html' => rl_page( $html, $probe ) );
    }
}
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31390-live-' . $name . '.html';
    file_put_contents( $inner, $p['html'] );
    $files[ $name ] = __DIR__ . '/release-31390-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:' . (int) $p['h'] . 'px;border:0"></iframe>'
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
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ]['w'], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ]['w'] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}

$said = array();
foreach ( $g as $name => $r ) {
    $h = $r['has'];
    rl( $h['box'] && $h['list'] && $h['bar'] && $h['card'], "$name: missing a part: " . json_encode( $h ) );
    if ( ! ( $h['box'] && $h['list'] && $h['bar'] && $h['card'] ) ) { continue; }
    $c = $r['closed'];
    rl( false !== strpos( $c['boxImage'], 'm6 9 6 6 6-6' ) && $c['boxImage'] === $c['selectImage'], "PLANT C: $name: the box does not carry the select's chevron: " . json_encode( array( $c['boxImage'], $c['selectImage'] ) ) );
    rl( '38px' === $c['boxPad'] && $c['boxPad'] === $c['selectPad'] && $c['boxPos'] === $c['selectPos'] && $c['boxSize'] === $c['selectSize'] && 'no-repeat, no-repeat' === $c['boxRepeat'],
        "$name: the chevron is not placed as the select's: " . json_encode( $c ) );
    rl( false === $r['rcptFloated'], "$name: Who gets these emails' list was floated too" );

    foreach ( array( 'mid', 'down', 'up' ) as $k ) {
        $x = isset( $r[ $k ] ) ? $r[ $k ] : null;
        rl( is_array( $x ) && $x['shown'] && $x['count'] >= 6, "$name: $k: the list did not open with every option: " . json_encode( $x ) );
        if ( ! is_array( $x ) || ! $x['shown'] ) { continue; }
        rl( false !== strpos( $x['chevron'], 'm18 15-6-6-6 6' ), "$name: $k: open, the chevron does not point up" );
        rl( $x['list']['top'] >= 0 && $x['list']['bottom'] <= $x['vh'], "PLANT F: $name: $k: the list runs off the window: " . json_encode( array( $x['list'], $x['vh'] ) ) );
        rl( $x['list']['left'] === $x['box']['left'] && $x['list']['right'] === $x['box']['right'], "$name: $k: the list is not the box's width: " . json_encode( array( $x['list'], $x['box'] ) ) );
        rl( ! empty( $x['hitFirst']['inList'] ) && ! empty( $x['hitLast']['inList'] ), "PLANT F: $name: $k: something is drawn over the list: " . json_encode( array( $x['hitFirst'], $x['hitLast'] ) ) );
    }
    if ( isset( $r['mid']['side'] ) ) { rl( 'down' === $r['mid']['side'] && $r['mid']['list']['top'] >= $r['mid']['box']['bottom'], "$name: mid-window, the list does not open under the box: " . json_encode( $r['mid'] ) ); }
    $e = $r['escaped'];
    rl( ! $e['shown'] && ! $e['pop'] && 'false' === $e['expanded'], "$name: Escape leaves the list open: " . json_encode( $e ) );
    if ( isset( $r['down']['side'] ) ) {
        $d = $r['down'];
        rl( 'down' === $d['side'] && $d['list']['top'] >= $d['box']['bottom'], "PLANT U: $name: with room below, the list opened " . $d['side'] );
        rl( $d['footInBar'] && ! empty( $d['hitFoot']['inList'] ), "PLANT F: $name: the save bar is drawn over the list's foot: " . json_encode( array( $d['hitFoot'], $d['list'], $d['bar'] ) ) );
        rl( $d['list']['bottom'] > $d['card']['bottom'] && is_array( $d['hitCardEdge'] ) && $d['hitCardEdge']['inList'], "PLANT F: $name: the card's border crops the list: " . json_encode( array( $d['hitCardEdge'], $d['list'], $d['card'] ) ) );
        $said[] = sprintf( '%s: down %dpx tall, past the card by %dpx, over the bar by %dpx', $name, $d['list']['h'], $d['list']['bottom'] - $d['card']['bottom'], $d['list']['bottom'] - $d['bar']['top'] );
    }
    if ( isset( $r['up']['side'] ) ) {
        $u = $r['up'];
        rl( 'up' === $u['side'] && $u['list']['bottom'] <= $u['box']['top'], "PLANT U: $name: just above the save bar, the list opened " . $u['side'] . ': ' . json_encode( array( $u['list'], $u['box'], $u['bar'] ) ) );
        rl( $r['endGap'] >= 0 && $r['endGap'] < 120, "$name: at the page's end the box is not just above the save bar: " . $r['endGap'] . 'px' );
        $said[] = sprintf( '%s: up %dpx tall, %dpx above the box, the box %dpx above the bar', $name, $u['list']['h'], $u['box']['top'] - $u['list']['bottom'], $r['endGap'] );
    }
}

echo "\n3.139.0: Replies go to as a select, its list over everything, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
foreach ( $said as $s ) { echo "  $s\n"; }
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Replies go to carries the select's chevron and opens over the card's border and the save bar, upward when there is no room below, on Add and Edit at 1280px and 390px.\n";
exit( 0 );
