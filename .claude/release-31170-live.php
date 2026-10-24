<?php
/**
 * FOLLOW THE SERIES FOLLOWS THE DROPDOWN, IN A REAL BROWSER (3.117.0).
 *
 *     php .claude/release-31170-live.php          writes the page
 *     php .claude/release-31170-live.php --run    and drives Chrome
 *
 * Add event through wp-kit.php with the real portal.css and portal.js: with no
 * series the tick is hidden; choosing a series shows it; back to none hides it.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$_GET = array();
ob_start();
kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) );
$html = (string) ob_get_clean();

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function seen(el) { return !!el && el.getBoundingClientRect().height > 0 && !el.closest('[hidden]'); }
window.addEventListener('load', function () { setTimeout(function () {
  var label = document.querySelector('[data-uc-follow-check]');
  var sel = document.querySelector('[data-uc-series-select]');
  function to(v) { sel.value = v; sel.dispatchEvent(new Event('change', { bubbles: true })); return seen(label); }
  out.atLoad = seen(label);
  out.s11 = sel ? to('11') : null;
  out.s0 = sel ? to('0') : null;
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}, 400); });
})();
JS;

$page = preg_replace( '#</body>#i', '<script>' . $probe . '</script></body>', $html, 1 );
if ( $page === $html ) { $page .= '<script>' . $probe . '</script>'; }
$file = __DIR__ . '/release-31170-live.html';
file_put_contents( $file, $page );
echo "wrote the page\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1280,2400 --virtual-time-budget=8000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        rl( false, 'Chrome returned no probe block' );
    } else {
        $g = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        if ( in_array( '--verbose', $argv, true ) ) { echo json_encode( $g ) . "\n"; }
        rl( empty( $g['errors'] ), 'the page threw: ' . implode( '; ', (array) $g['errors'] ) );
        rl( false === $g['atLoad'], 'Follow the series shows on Add event with no series: ' . json_encode( $g ) );
        rl( true === $g['s11'], 'choosing a series does not show Follow the series: ' . json_encode( $g ) );
        rl( false === $g['s0'], 'going back to no series does not hide it: ' . json_encode( $g ) );
    }
}

echo "\n3.117.0 LIVE: Follow the series follows the dropdown\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Hidden with no series, shown when one is chosen, hidden again with none.\n";
exit( 0 );
