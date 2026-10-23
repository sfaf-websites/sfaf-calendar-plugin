<?php
/**
 * THE RSVP CHOICE ON THE SERIES PAGE AND ADD EVENT, IN A REAL BROWSER (3.115.0).
 *
 *     php .claude/release-31150-live.php          writes the pages
 *     php .claude/release-31150-live.php --run    and drives Chrome
 *
 * The real renderers through wp-kit.php, with the real portal.css and
 * portal.js. Read off the pages:
 *
 *   series     External RSVP opens the link box; No RSVP and RSVP Here close it
 *   add event  choosing a series in the dropdown puts the RSVP choice on that
 *              series' one: RSVP Here, External RSVP with its link as the
 *              placeholder, No RSVP; and not once the choice was touched
 *
 * The render and the save are release-31150-test.php's.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$RE = 'SFAF_Register_Elsewhere';

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function rl_cap( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }
$_GET = array();
$pages = array(
    'series' => rl_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( new WP_User(), 11 ) ); } ),
);
update_term_meta( 12, $RE::SERIES_MODE, 'here' );
update_term_meta( 11, $RE::SERIES_MODE, 'elsewhere' );
update_term_meta( 11, $RE::SERIES_URL, 'https://example.org/sign-up' );
$pages += array(
    'add'    => rl_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) ); } ),
);

$probe = <<<'JS'
(function () {
var out = { page: window.RL_PAGE, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function seen(el) { return !!el && el.getBoundingClientRect().height > 0 && !el.closest('[hidden]'); }
function pick(name, value) {
  var r = document.querySelector('input[name="' + name + '"][value="' + value + '"]');
  if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
}
function chosen(name) { var r = document.querySelector('input[name="' + name + '"]:checked'); return r ? r.value : ''; }
window.addEventListener('load', function () { setTimeout(function () {
  if (out.page === 'series') {
    var box = document.querySelector('[data-uc-series-rsvp] [data-uc-reg-url]');
    out.atLoad = chosen('series_reg_choice') + ' ' + seen(box);
    pick('series_reg_choice', 'elsewhere'); out.elsewhere = seen(box);
    pick('series_reg_choice', 'here'); out.here = seen(box);
    pick('series_reg_choice', 'none'); out.none = seen(box);
  }
  if (out.page === 'add') {
    var sel = document.querySelector('[data-uc-series-select]');
    var url = document.querySelector('[data-uc-reg-choice] input[name="reg_url"]');
    function to(v) { sel.value = v; sel.dispatchEvent(new Event('change', { bubbles: true })); return chosen('reg_choice'); }
    out.atLoad = chosen('reg_choice');
    out.s12 = to('12');
    out.s11 = to('11'); out.s11url = url ? url.placeholder : '';
    out.s0 = to('0');
    pick('reg_choice', 'here');
    out.touched = to('12') + ' ' + to('0');
  }
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}, 400); });
})();
JS;

$files = array();
foreach ( $pages as $name => $html ) {
    $page = preg_replace( '#</body>#i', '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $html, 1 );
    if ( $page === $html ) { $page .= '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script>'; }
    $files[ $name ] = __DIR__ . '/release-31150-live-' . $name . '.html';
    file_put_contents( $files[ $name ], $page );
}
echo "wrote " . count( $files ) . " pages\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $got = array();
    foreach ( $files as $name => $file ) {
        $url = 'file:///' . str_replace( '\\', '/', $file );
        $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1280,2400 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
        if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
        $got[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $got[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
        rl( empty( $got[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $got[ $name ]['errors'] ) );
    }
    $s = isset( $got['series'] ) ? $got['series'] : array();
    rl( isset( $s['atLoad'] ) && 'none false' === $s['atLoad'], 'series: at load it is not No RSVP with the link box closed: ' . json_encode( $s ) );
    rl( ! empty( $s['elsewhere'] ) && empty( $s['here'] ) && empty( $s['none'] ), 'series: External RSVP does not open the link box, or the others do not close it: ' . json_encode( $s ) );
    $a = isset( $got['add'] ) ? $got['add'] : array();
    rl( isset( $a['atLoad'] ) && 'none' === $a['atLoad'], 'add: Add event does not start on No RSVP: ' . json_encode( $a ) );
    rl( isset( $a['s12'] ) && 'here' === $a['s12'], 'add: an RSVP Here series does not give RSVP Here: ' . json_encode( $a ) );
    rl( isset( $a['s11'], $a['s11url'] ) && 'elsewhere' === $a['s11'] && 'https://example.org/sign-up' === $a['s11url'], 'add: an External RSVP series does not give External RSVP with its link: ' . json_encode( $a ) );
    rl( isset( $a['s0'] ) && 'none' === $a['s0'], 'add: no series does not give No RSVP: ' . json_encode( $a ) );
    rl( isset( $a['touched'] ) && 'here here' === $a['touched'], 'add: a choice somebody made is overwritten by the dropdown: ' . json_encode( $a ) );
}

echo "\n3.115.0 LIVE: the RSVP choice on the series page and Add event\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "External RSVP opens the series page's link box and the others close it; Add event starts on each series' choice as\nthe dropdown changes, and keeps a choice somebody made.\n";
exit( 0 );
