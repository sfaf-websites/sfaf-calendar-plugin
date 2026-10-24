<?php
/**
 * THE SERIES PAGE IN A REAL BROWSER (3.116.0).
 *
 *     php .claude/release-31160-live.php          writes the page
 *     php .claude/release-31160-live.php --run    and drives Chrome at 1280px and 390px
 *
 * The real render_series_edit() through wp-kit.php, with the real portal.css
 * and portal.js. Read off the page at each width:
 *
 *   the two columns side by side on a desktop and one under the other on a
 *   phone; the bar at the foot of the window from the top of the page, with
 *   Save changes inside it and green; nothing past the right edge
 *
 * The markup is release-31160-test.php's.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$_GET = array();
ob_start();
kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( new WP_User(), 11 ) );
$html = (string) ob_get_clean();

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function r(el) { if (!el) { return null; } var b = el.getBoundingClientRect(); return { l: Math.round(b.left), t: Math.round(b.top), r: Math.round(b.right), b: Math.round(b.bottom), w: Math.round(b.width), h: Math.round(b.height) }; }
window.addEventListener('load', function () { setTimeout(function () {
  window.scrollTo(0, 0);
  out.vw = window.innerWidth; out.vh = window.innerHeight;
  out.main = r(document.querySelector('.uc-bento-main'));
  out.side = r(document.querySelector('.uc-bento-side'));
  var bar = document.querySelector('[data-uc-editor-bar]');
  out.bar = r(bar);
  out.barPos = bar ? getComputedStyle(bar).position : '';
  var save = bar ? bar.querySelector('.uc-editor-save') : null;
  out.save = r(save);
  out.saveBg = save ? getComputedStyle(save).backgroundColor : '';
  var rm = bar ? bar.querySelector('.uc-editor-delete') : null;
  out.remove = rm ? { text: rm.textContent.trim(), color: getComputedStyle(rm).color, bg: getComputedStyle(rm).backgroundColor, box: r(rm) } : null;
  out.pageWidth = document.documentElement.scrollWidth;
  if (window.parent !== window) { window.parent.postMessage(JSON.stringify(out), '*'); } else { var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre); }
}, 500); });
})();
JS;

$page = preg_replace( '#</body>#i', '<script>' . $probe . '</script></body>', $html, 1 );
if ( $page === $html ) { $page .= '<script>' . $probe . '</script>'; }
$file = __DIR__ . '/release-31160-live.html';
file_put_contents( $file, $page );
echo "wrote the page\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
        /* A frame of that width: headless Chrome's window will not go under ~500px. */
        $frame = __DIR__ . '/release-31160-live-' . $wn . '-frame.html';
        file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="release-31160-live.html" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe><script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
        $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=10000 --dump-dom "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
        @unlink( $frame );
        if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$wn: Chrome returned no probe block" ); continue; }
        $g = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        if ( in_array( '--verbose', $argv, true ) ) { echo "$wn: " . json_encode( $g ) . "\n"; }
        rl( empty( $g['errors'] ), "$wn threw: " . implode( '; ', (array) $g['errors'] ) );
        if ( 'desktop' === $wn ) {
            rl( $g['main'] && $g['side'] && $g['side']['l'] >= $g['main']['r'] && abs( $g['side']['t'] - $g['main']['t'] ) < 2, "desktop: the two columns are not side by side: " . json_encode( array( $g['main'], $g['side'] ) ) );
        } else {
            rl( $g['main'] && $g['side'] && $g['side']['t'] >= $g['main']['b'], "phone: the side column is not under the main one: " . json_encode( array( $g['main'], $g['side'] ) ) );
        }
        rl( 'sticky' === $g['barPos'], "$wn: the bar is not sticky: " . $g['barPos'] );
        rl( $g['bar'] && $g['bar']['b'] <= $g['vh'] + 1 && $g['bar']['t'] >= 0, "$wn: the bar is not at the foot of the window from the top of the page: " . json_encode( array( $g['bar'], $g['vh'] ) ) );
        rl( $g['save'] && $g['save']['b'] <= $g['vh'] + 1 && 'rgb(255, 217, 0)' !== $g['saveBg'], "$wn: Save is not in view, or is yellow: " . json_encode( array( $g['save'], $g['saveBg'] ) ) );
        rl( $g['remove'] && 'Remove series' === $g['remove']['text'] && 'rgba(0, 0, 0, 0)' === $g['remove']['bg'], "$wn: Remove series is not a link: " . json_encode( $g['remove'] ) );
        if ( 'desktop' === $wn ) { rl( $g['remove'] && $g['remove']['box']['l'] - $g['bar']['l'] < 40, "desktop: Remove series is not at the bar's left: " . json_encode( array( $g['remove'], $g['bar'] ) ) ); }
        rl( $g['pageWidth'] <= $g['vw'], "$wn: the page scrolls sideways: " . $g['pageWidth'] . ' > ' . $g['vw'] );
    }
}

echo "\n3.116.0 LIVE: the series page\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Two columns on a desktop and one on a phone, the bar at the foot of the window with Save in reach and Remove series\nas a link, and nothing past the edge.\n";
exit( 0 );
