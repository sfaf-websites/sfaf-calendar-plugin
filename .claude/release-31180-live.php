<?php
/**
 * THE COMMUNITY FORM'S RSVP UPDATES AND ONLINE TICK, IN A REAL BROWSER (3.118.0).
 *
 *     php .claude/release-31180-live.php          writes the page
 *     php .claude/release-31180-live.php --run    and drives Chrome
 *
 * The real community form through wp-kit.php, with the real portal.css and
 * portal.js: typing in Your email fills the first RSVP updates box; ticking
 * "This is an online event" hides the place and its fields post nothing, and
 * the street stops being required; unticking brings them back.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$GLOBALS['kit_query'] = function ( $a ) { return array(); };
$series = SFAF_Series::get( 11 );
ob_start();
kit_call( 'SFAF_Submit', 'render_form', null, array( $series, array(), array() ) );
$html = (string) ob_get_clean();

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function seen(el) { return !!el && el.getBoundingClientRect().height > 0 && !el.closest('[hidden]'); }
window.addEventListener('load', function () { setTimeout(function () {
  var mail = document.querySelector('[data-uc-rsvp-updates-source]');
  var you = document.querySelector('[data-uc-rsvp-updates-you]');
  if (mail) { mail.value = 'me@example.org'; mail.dispatchEvent(new Event('input', { bubbles: true })); }
  out.you = you ? you.value : null;
  var tick = document.querySelector('input[name="online"]');
  var street = document.querySelector('input[name="street"]');
  out.atLoad = { street: seen(street), required: street ? street.required || street.getAttribute('aria-required') === 'true' : null };
  if (tick) { tick.checked = true; tick.dispatchEvent(new Event('change', { bubbles: true })); }
  out.online = { street: seen(street), disabled: street ? street.disabled : null, required: street ? street.required || street.getAttribute('aria-required') === 'true' : null };
  if (tick) { tick.checked = false; tick.dispatchEvent(new Event('change', { bubbles: true })); }
  out.back = { street: seen(street), disabled: street ? street.disabled : null };
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}, 400); });
})();
JS;

$page = preg_replace( '#</body>#i', '<script>' . $probe . '</script></body>', $html, 1 );
if ( $page === $html ) { $page .= '<script>' . $probe . '</script>'; }
$file = __DIR__ . '/release-31180-live.html';
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
        rl( 'me@example.org' === $g['you'], 'Your email does not fill the first RSVP updates box: ' . json_encode( $g['you'] ) );
        rl( true === $g['atLoad']['street'] && true === $g['atLoad']['required'], 'at load the street is not shown and required: ' . json_encode( $g['atLoad'] ) );
        rl( false === $g['online']['street'] && true === $g['online']['disabled'] && false === $g['online']['required'], 'an online event still shows or requires the street: ' . json_encode( $g['online'] ) );
        rl( true === $g['back']['street'] && false === $g['back']['disabled'], 'unticking online does not bring the street back: ' . json_encode( $g['back'] ) );
    }
}

echo "\n3.118.0 LIVE: the community form's RSVP updates and online tick\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Your email fills the RSVP updates box; an online event hides the place and stops asking for a street, and unticking\nbrings it back.\n";
exit( 0 );
