<?php
/**
 * SERIES DEFAULTS THROUGH "FILL THESE IN", IN A REAL BROWSER (3.119.0).
 *
 *     php .claude/release-31190-live.php          writes the page
 *     php .claude/release-31190-live.php --run    and drives Chrome
 *
 * Add event through wp-kit.php with the real portal.css and portal.js, with a
 * series whose defaults are set: choosing it in the dropdown offers its
 * defaults, and Fill these in writes the times, cost, age range, contact, the
 * notification team and address, the emails and the display ticks into the
 * editor's own controls.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$GLOBALS['kit_query'] = function ( $a ) { return array(); };
update_option( SFAF_Teams::OPTION, array( 'marcom' => array( 'name' => 'MarCom', 'users' => array( 1 ) ) ) );
update_term_meta( 11, SFAF_Series::META_EVENT_DEFAULTS, array(
    'cost' => 'Free', 'age' => '18+', 'contact_name' => 'Ana', 'contact_email' => 'ana@sfaf.org', 'contact_phone' => '',
    'start' => '18:30', 'end' => '20:00', 'venue' => 0,
    'notify_users' => array(), 'notify_teams' => array( 'marcom' ), 'notify_emails' => array( 'pal@example.org' ),
    'notify_off' => array( 'alert' ), 'display' => array( 'show_rsvp' => '1', 'show_calendar' => '0', 'show_social' => '1', 'show_reminders' => '1' ),
) );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$_GET = array();
ob_start();
kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) );
$html = (string) ob_get_clean();

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function val(n) { var b = document.querySelector('[name="' + n + '"]'); return b ? b.value : null; }
function on(sel) { var b = document.querySelector(sel); return b ? b.checked : null; }
window.addEventListener('load', function () { setTimeout(function () {
  var sel = document.querySelector('[data-uc-series-select]');
  if (sel) { sel.value = '11'; sel.dispatchEvent(new Event('change', { bubbles: true })); }
  setTimeout(function () {
    var panel = document.querySelector('[data-uc-prefill-panel]');
    out.panel = panel ? !panel.hidden : null;
    out.offered = panel ? Array.prototype.map.call(panel.querySelectorAll('[data-uc-prefill-opts] input[type="checkbox"]'), function (b) { return b.value; }) : [];
    var apply = document.querySelector('[data-uc-prefill-apply]');
    if (apply) { apply.click(); }
    out.start = val('start_time_h') + ':' + val('start_time_m');
    out.cost = val('listing_cost');
    out.age = val('listing_age');
    out.contact = val('contact_name');
    out.team = on('[name="notify_teams[]"][value="marcom"]');
    out.pill = !!document.querySelector('[name="notify_emails[]"][value="pal@example.org"]');
    out.alert = on('[name="notify_kinds[]"][value="alert"]');
    out.summary = on('[name="notify_kinds[]"][value="summary"]');
    out.calendar = on('input[type="checkbox"][name="show_calendar"]');
    var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
  }, 300);
}, 400); });
})();
JS;

$page = preg_replace( '#</body>#i', '<script>' . $probe . '</script></body>', $html, 1 );
if ( $page === $html ) { $page .= '<script>' . $probe . '</script>'; }
$file = __DIR__ . '/release-31190-live.html';
file_put_contents( $file, $page );
echo "wrote the page\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1280,2400 --virtual-time-budget=9000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        rl( false, 'Chrome returned no probe block' );
    } else {
        $g = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        if ( in_array( '--verbose', $argv, true ) ) { echo json_encode( $g ) . "\n"; }
        rl( empty( $g['errors'] ), 'the page threw: ' . implode( '; ', (array) $g['errors'] ) );
        rl( true === $g['panel'], 'choosing the series does not offer Fill these in: ' . json_encode( $g ) );
        rl( '18:30' === $g['start'], 'the series\' start time is not filled in: ' . json_encode( $g['start'] ) );
        rl( 'Free' === $g['cost'] && '18+' === $g['age'] && 'Ana' === $g['contact'], 'cost, age range or contact not filled in: ' . json_encode( $g ) );
        rl( true === $g['team'] && true === $g['pill'], 'the notification team or address not filled in: ' . json_encode( $g ) );
        rl( false === $g['alert'] && true === $g['summary'], 'the emails not set as the series says: ' . json_encode( $g ) );
        rl( false === $g['calendar'], 'Add to calendar not off as the series says: ' . json_encode( $g ) );
    }
}

echo "\n3.119.0 LIVE: series defaults through Fill these in\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Choosing the series offers its defaults, and Fill these in writes every one into the editor's own controls.\n";
exit( 0 );
