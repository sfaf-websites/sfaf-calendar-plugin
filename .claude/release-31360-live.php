<?php
/**
 * 3.136.0 IN A BROWSER: THE EVENTS LIST'S RSVP PILL.
 *
 *     php .claude/release-31360-live.php --run
 *     php .claude/release-31360-live.php --run --verbose   and print what was measured
 *
 * REAL: events_table() through wp-kit.php with the real portal.css and
 * portal.js, in Chrome at 1280px and 390px, for an administrator and for
 * somebody who may not open the lists.
 *
 *   M  "0 RSVPs", "1 RSVP", "12 RSVPs": each a pill in the band teal
 *      (#E8F9FA) with teal ink (#0E7680), a 14px person before the number,
 *      on one line, at least 24px tall, contrast at least 4.5:1; the hover
 *      rule's pair at least 4.5:1 too and darker than the resting fill; a
 *      link to the event's list for the administrator, the same pill and not
 *      a link for the other; a dash where none are taken
 *
 * PLANT in plant-31360.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
$GLOBALS['kit_db'] = function ( $method, $sql ) { return 'get_results' === $method ? array() : ( 'get_var' === $method ? 0 : null ); };
function rl_event( $id, $title, $rsvps ) {
    kit_write_post( array( 'ID' => $id, 'post_title' => $title, 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
    update_post_meta( $id, '_uc_event_date', '2026-12-0' . ( $id % 9 ) );
    if ( $rsvps ) { update_post_meta( $id, '_uc_rsvp_enabled', '1' ); }
}
rl_event( 901, 'Support group for people living with HIV on Tuesdays', true );
rl_event( 902, 'Twelve', true );
rl_event( 903, 'Just one', true );
rl_event( 904, 'Switched off', false );
$store =& sfaf_rsvp_count_store();
$store[901] = 0; $store[902] = 12; $store[903] = 1; $store[904] = 3;
$ids    = array( 901, 902, 903, 904 );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$admin  = rl_cap( function () use ( $portal, $ids ) { kit_call( 'SFAF_Portal', 'events_table', $portal, array( $ids, wp_get_current_user() ) ); } );
$GLOBALS['kit_refused'] = array( 7 );
$GLOBALS['kit_cannot']  = array( 'manage_options' );
$u = new WP_User(); $u->ID = 7;
$other  = rl_cap( function () use ( $portal, $ids, $u ) { kit_call( 'SFAF_Portal', 'events_table', $portal, array( $ids, $u ) ); } );
$GLOBALS['kit_refused'] = array(); $GLOBALS['kit_cannot'] = array();
foreach ( array( 'admin' => $admin, 'other' => $other ) as $k => $h ) { rl( 0 !== strpos( $h, 'THREW' ), "$k: the list did not render: " . substr( $h, 0, 300 ) ); }
function rl_page( $body ) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="../public/css/portal.css"></head><body class="uc-portal" style="margin:0">'
        . '<div class="uc-portal-main"><div class="uc-portal-content">' . $body . '</div></div>'
        . '<script src="../public/js/portal.js"></script>';
}

$probe = <<<'JS'
(function () {
var P = window.RL_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function rgb(s) { var m = String(s).match(/\d+(\.\d+)?/g); return m ? m.slice(0, 3).map(Number) : null; }
function hex(h) { h = h.replace('#', ''); return [0, 2, 4].map(function (i) { return parseInt(h.substr(i, 2), 16); }); }
function lum(c) { return c.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function ratio(a, b) { var x = lum(a), y = lum(b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  out.pills = qa('[data-uc-rsvp-pill]').map(function (p) {
    var cs = getComputedStyle(p), r = p.getBoundingClientRect(), icon = p.querySelector('svg.sfaf-icon');
    var rg = document.createRange(); rg.selectNodeContents(p.querySelector('span') || p); var tops = {};
    Array.prototype.forEach.call(rg.getClientRects(), function (c) { if (c.width > 1) { tops[Math.round(c.top + c.height / 2)] = 1; } });
    return { tag: p.tagName.toLowerCase(), text: text(p), href: p.getAttribute('href') || '', bg: cs.backgroundColor, color: cs.color, radius: cs.borderTopLeftRadius,
      contrast: ratio(rgb(cs.color), rgb(cs.backgroundColor)), h: Math.round(r.height), lines: Object.keys(tops).length, seen: seen(p), cursor: cs.cursor, underline: cs.textDecorationLine,
      icon: icon ? { w: Math.round(icon.getBoundingClientRect().width), seen: seen(icon), before: !!(icon.compareDocumentPosition(p.querySelector('span')) & Node.DOCUMENT_POSITION_FOLLOWING) } : null };
  });
  out.dashes = qa('[data-uc-rsvp-none]').map(function (d) { return { text: text(d), seen: seen(d) }; });
  // The hover rule, read from the sheet: headless Chrome has no pointer to rest on it.
  out.hover = null;
  qa('link[rel="stylesheet"]').forEach(function (l) {
    try { Array.prototype.forEach.call(l.sheet.cssRules, function (rule) {
      if (rule.selectorText === '.uc-portal a.uc-rsvp-pill:hover') { out.hover = { bg: rule.style.backgroundColor, color: rule.style.color }; }
    }); } catch (e) { out.errors.push('sheet: ' + e.message); }
  });
  if (out.hover) {
    var probe = document.createElement('span'); document.body.appendChild(probe);
    probe.style.backgroundColor = out.hover.bg; probe.style.color = out.hover.color;
    var cs = getComputedStyle(probe); out.hover.bgRgb = cs.backgroundColor; out.hover.contrast = ratio(rgb(cs.color), rgb(cs.backgroundColor));
    out.hover.darker = lum(rgb(cs.backgroundColor)) < lum(hex('E8F9FA'));
    probe.remove();
  }
  finish();
}, 400); });
})();
JS;

$pages = array(
    'admin-desktop' => array( 1280, rl_page( $admin ) ),
    'admin-phone'   => array( 390, rl_page( $admin ) ),
    'other-desktop' => array( 1280, rl_page( $other ) ),
    'other-phone'   => array( 390, rl_page( $other ) ),
);
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31360-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] . '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body></html>' );
    $files[ $name ] = __DIR__ . '/release-31360-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p[0] . 'px;height:1400px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1500 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ][0], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ][0] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}

$BAND = 'rgb(232, 249, 250)';  // --uc-band #E8F9FA
$TEAL = 'rgb(14, 118, 128)';   // --uc-accent-text #0E7680
foreach ( $g as $name => $r ) {
    $link = 0 === strpos( $name, 'admin' );
    rl( array( '0 RSVPs', '12 RSVPs', '1 RSVP' ) === array_column( (array) $r['pills'], 'text' ), "PLANT M: $name: the pills read " . json_encode( array_column( (array) $r['pills'], 'text' ) ) );
    foreach ( (array) $r['pills'] as $p ) {
        rl( $BAND === $p['bg'] && $TEAL === $p['color'] && $p['contrast'] >= 4.5 && '99px' === $p['radius'], "PLANT M: $name: \"{$p['text']}\" is " . json_encode( $p ) );
        rl( $p['seen'] && 1 === $p['lines'] && $p['h'] >= 24 && isset( $p['icon']['w'] ) && 14 === $p['icon']['w'] && $p['icon']['seen'] && $p['icon']['before'], "PLANT M: $name: \"{$p['text']}\" lays out as " . json_encode( $p ) );
        if ( $link ) {
            rl( 'a' === $p['tag'] && false !== strpos( $p['href'], 'event_id=' ) && 'pointer' === $p['cursor'] && 'none' === $p['underline'], "PLANT M: $name: \"{$p['text']}\" is not a link to its list: " . json_encode( $p ) );
        } else {
            rl( 'span' === $p['tag'] && '' === $p['href'] && 'pointer' !== $p['cursor'], "PLANT M: $name: \"{$p['text']}\" is clickable for somebody who may not open the list: " . json_encode( $p ) );
        }
    }
    rl( array( array( 'text' => "\u{2013}", 'seen' => true ) ) === $r['dashes'], "PLANT M: $name: the event that takes no RSVPs reads " . json_encode( $r['dashes'] ) );
    rl( isset( $r['hover']['contrast'] ) && $r['hover']['contrast'] >= 4.5 && $r['hover']['darker'], "PLANT M: $name: the hover is " . json_encode( $r['hover'] ) );
}

echo "\n3.136.0 in a browser: the Events list's RSVP pill\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { foreach ( $fails as $f ) { echo "FAIL  $f\n"; } exit( 1 ); }
echo 'every check passed, on ' . count( $g ) . " pages.\n";
exit( 0 );
