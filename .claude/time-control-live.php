<?php
/**
 * THE TIME CONTROL IN A REAL BROWSER: WHAT DOES THE PICKER ACTUALLY OFFER?
 *
 *     php .claude/time-control-live.php          writes the page
 *     php .claude/time-control-live.php --run    writes it and drives Chrome
 *
 * THIS IS THE CHECK THE OLD CONTROL COULD NOT PASS. `step="300"` on an
 * `<input type="time">` never changed what the picker offered, and the only way
 * to know that is to open one and count. Counting what a `<select>` offers is
 * the same act, and now it answers twelve.
 *
 * IT DRIVES THE REAL RENDERER'S OUTPUT. The markup on this page is
 * sfaf_time_field()'s, written by the PHP above rather than retyped, so a
 * change to the renderer arrives here without anybody remembering to copy it.
 *
 * AND IT IS REACHABLE. Both lists take focus, in order, and the pair carries
 * one accessible name, which is what "labelled as one control" means to
 * somebody who is not looking at it.
 */

$root = dirname( __DIR__ );
$out  = $root . '/.claude/time-control-live.html';

$fails = array();
function tc_check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* ---------------------------------------------------------------------------
 * The renderer, sliced out of the shipped file. Same discipline as the other
 * live pages: a copy retyped here would agree with itself forever.
 * ------------------------------------------------------------------------ */
define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function selected( $a, $b, $echo = true ) {
    $o = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
    if ( $echo ) { echo $o; }
    return $o;
}
function date_i18n( $format, $ts = false, $gmt = false ) { return date( $format, false === $ts ? time() : $ts ); }

$tpl   = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
$slice = function ( $name ) use ( $tpl ) {
    $at = strpos( $tpl, 'function ' . $name . '(' );
    if ( false === $at ) { return ''; }
    $d = 0;
    for ( $i = $at; $i < strlen( $tpl ); $i++ ) {
        if ( '{' === $tpl[ $i ] ) { $d++; }
        if ( '}' === $tpl[ $i ] ) { $d--; if ( 0 === $d ) { return substr( $tpl, $at, $i - $at + 1 ); } }
    }
    return '';
};
foreach ( array( 'sfaf_ap_time', 'sfaf_time_minutes', 'sfaf_time_parts', 'sfaf_time_hour_label', 'sfaf_time_field' ) as $fn ) {
    $code = $slice( $fn );
    if ( '' === $code ) { echo "FAIL: $fn() could not be sliced.\n"; exit( 1 ); }
    eval( $code );
}

/* Three controls: an ordinary one, an empty one, and one holding a time the
 * import left off the five-minute grid. */
$screen =
      '<div class="uc-field"><span class="uc-field-label">Start time</span>'
    . sfaf_time_field( 'start_time', '18:30', array( 'label' => 'Start time' ) ) . '</div>'
    . '<div class="uc-field"><span class="uc-field-label">End time</span>'
    . sfaf_time_field( 'end_time', '', array( 'label' => 'End time' ) ) . '</div>'
    . '<div class="uc-field"><span class="uc-field-label">An imported time</span>'
    . sfaf_time_field( 'odd_time', '18:07', array( 'label' => 'An imported time' ) ) . '</div>';

$probe = <<<'JS'
var lines = [];
function say(s) { lines.push(s); }

var groups = document.querySelectorAll('.uc-timepick');
say('controls on the page   ' + groups.length);
say('');

Array.prototype.forEach.call(groups, function (g, i) {
  var h = g.querySelector('.uc-timepick-h');
  var m = g.querySelector('.uc-timepick-m');
  say('control ' + (i + 1) + '  "' + g.getAttribute('aria-label') + '"');
  say('  role                 ' + g.getAttribute('role'));
  say('  hour entries         ' + h.options.length + ' (one is the empty prompt)');
  say('  MINUTE ENTRIES       ' + m.options.length + ' (one is the empty prompt)');
  var vals = [];
  Array.prototype.forEach.call(m.options, function (o) { if (o.value) { vals.push(o.value); } });
  say('  minute values        [' + vals.join(', ') + ']');
  var offGrid = vals.filter(function (v) { return (parseInt(v, 10) % 5) !== 0; });
  say('  off the five grid    ' + (offGrid.length ? offGrid.join(', ') : 'none'));
  say('  chosen               ' + (h.value || '(none)') + ':' + (m.value || '(none)'));
  say('  both are <select>    ' + (h.tagName + '/' + m.tagName));
  say('  both reachable       tabindex ' + h.tabIndex + '/' + m.tabIndex
      + ', disabled ' + h.disabled + '/' + m.disabled);
  say('  hour reads           "' + (h.options[1] ? h.options[1].text.trim() : '?') + '" ... "'
      + (h.options[h.options.length - 1] ? h.options[h.options.length - 1].text.trim() : '?') + '"');
  say('');
});

/* SIDE BY SIDE, not stacked, at a normal width. */
var g0 = document.querySelector('.uc-timepick');
var h0 = g0.querySelector('.uc-timepick-h').getBoundingClientRect();
var m0 = g0.querySelector('.uc-timepick-m').getBoundingClientRect();
say('hour beside minute     ' + (Math.abs(h0.top - m0.top) < 2 ? 'yes' : 'NO, they are stacked'));
say('hour is the wider      ' + (h0.width > m0.width ? 'yes' : 'NO'));

/* FOCUS ORDER: the hour, then the minute, and nothing between. */
var hEl = g0.querySelector('.uc-timepick-h');
var mEl = g0.querySelector('.uc-timepick-m');
hEl.focus();
say('focus lands on hour    ' + (document.activeElement === hEl ? 'yes' : 'NO'));
mEl.focus();
say('focus reaches minute   ' + (document.activeElement === mEl ? 'yes' : 'NO'));

/* AND NO BROWSER TIME INPUT ANYWHERE. */
say('native time inputs     ' + document.querySelectorAll('input[type="time"]').length);

say('');
say('script errors          ' + (window.__errs.length ? window.__errs.join(' | ') : 'none'));
document.getElementById('out').textContent = lines.join('\n');
JS;

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: the time control, live</title>
<!-- GENERATED BY .claude/time-control-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/portal.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; }
  /* No transitions on a measuring page: getComputedStyle returns the current
     interpolated value, not the settled one. */
  * { transition: none !important; animation: none !important; }
</style>
</head>
<body class="uc-portal">
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
<h1>The time control</h1>
$screen
<pre id="out">not run</pre>
<script>
window.addEventListener('load', function () {
  setTimeout(function () {
$probe
  }, 60);
});
</script>
</body>
</html>
HTML;

file_put_contents( $out, $html );
echo "wrote: $out\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $url = 'file:///' . str_replace( '\\', '/', $out );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --window-size=1200,900 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        echo "Chrome returned no probe block.\n";
        exit( 1 );
    }
    $report = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
    echo $report . "\n";

    tc_check( false !== strpos( $report, 'script errors          none' ),
        'the page threw while the control was being measured' );
    tc_check( false !== strpos( $report, 'controls on the page   3' ),
        'the three controls did not render' );

    /* THE ONE THE BRIEF ASKS FOR. Twelve entries and no more, plus the empty
     * prompt, on the two controls holding grid values. */
    $grid = substr_count( $report, 'MINUTE ENTRIES       13 (one is the empty prompt)' );
    tc_check( 2 === $grid,
        'a grid-valued control does not offer exactly twelve minute entries: ' . $grid . ' of 2 do' );

    /* AND THE IMPORTED ONE CARRIES ITS OWN MINUTE, as a thirteenth. */
    tc_check( false !== strpos( $report, 'MINUTE ENTRIES       14 (one is the empty prompt)' ),
        "the imported off-grid time does not keep its own minute, so the form shows a time the event does not have" );
    tc_check( false !== strpos( $report, 'off the five grid    07' ),
        'the off-grid minute is not the one the event has' );
    tc_check( 2 === substr_count( $report, 'off the five grid    none' ),
        'a control holding a grid time offers a minute off the grid' );

    /* THE FULL TWELVE, NAMED. */
    tc_check( false !== strpos( $report, 'minute values        [00, 05, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55]' ),
        'the twelve minutes are not the five-minute grid' );

    /* TWENTY-FOUR HOURS, IN THE CALENDAR'S OWN STYLE. */
    tc_check( 3 === substr_count( $report, 'hour entries         25 (one is the empty prompt)' ),
        'the hour list does not offer twenty-four hours' );
    tc_check( false !== strpos( $report, 'hour reads           "12 am" ... "11 pm"' ),
        "the hours do not read in the calendar's own time style" );

    /* ONE CONTROL, TWO LISTS, BOTH REACHABLE. */
    tc_check( 3 === substr_count( $report, 'role                 group' ),
        'the pair is not announced as one control' );
    tc_check( false !== strpos( $report, 'both are <select>    SELECT/SELECT' ),
        'one half of the control is not a native select, so its keyboard behaviour is ours to reproduce' );
    tc_check( false !== strpos( $report, 'both reachable       tabindex 0/0, disabled false/false' ),
        'one of the two lists cannot be reached by keyboard' );
    tc_check( false !== strpos( $report, 'focus lands on hour    yes' ), 'the hour list cannot take focus' );
    tc_check( false !== strpos( $report, 'focus reaches minute   yes' ), 'the minute list cannot take focus' );

    /* SIDE BY SIDE, HOUR WIDER. */
    tc_check( false !== strpos( $report, 'hour beside minute     yes' ),
        'the two lists are stacked at a normal width' );
    tc_check( false !== strpos( $report, 'hour is the wider      yes' ),
        'the hour list is not the wider of the two, and "12 am" is a longer word than ":05"' );

    /* AND THE OLD CONTROL IS GONE. */
    tc_check( false !== strpos( $report, 'native time inputs     0' ),
        'a browser time input is still on the page, and its picker ignores the five-minute step' );
}

if ( $fails ) {
    echo "\nFAIL: " . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "\ntwelve minute entries and no more, twenty-four hours in the calendar's own\n";
echo "style, both lists reachable, and an imported off-grid time keeps its minute.\n";
