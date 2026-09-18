<?php
/**
 * WHAT DOES THE REAL RSVP FORM ACTUALLY SEND AS THE FORMAT?
 *
 *     php .claude/rsvp-format-live.php          writes the page
 *     php .claude/rsvp-format-live.php --run    writes it and drives Chrome
 *
 * THE FIRST LINK IN THE CHAIN. The meeting link reaches the right person only
 * if four things agree on one string: the radio's value, the key the AJAX
 * payload uses, the column, and what `SFAF_Online::MODE_ONLINE` compares
 * against. Three of those are PHP and are checked elsewhere; this one is a
 * browser fact, and the only way to know what a form posts is to fill it in and
 * look.
 *
 * SO THIS LOADS THE REAL calendar.js, opens the REAL modal by clicking a REAL
 * RSVP button, presses the Online radio, and intercepts `$.ajax` to read the
 * payload the page was about to send. Nothing here reimplements the form: the
 * markup, the format control and the submit handler are all the shipped ones.
 *
 * WHY IT WAS WORTH BUILDING FOR A BUG THAT WAS NOT HERE. The fault in 3.97.3
 * was on the server, and this page proved the client was innocent, which is the
 * half that turned a search into a diagnosis. A test that only ever runs when
 * it fails has no value on the day it passes; this one had its value then.
 *
 * jQuery IS VENDORED at .claude/vendor/ because calendar.js takes it as the
 * IIFE's argument, and a page that fetches it over the network is a page that
 * fails when the network does.
 */

$root = dirname( __DIR__ );
$out  = $root . '/.claude/rsvp-format-live.html';

$fails = array();
function rf_check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

$jq = $root . '/.claude/vendor/jquery-3.7.1.min.js';
if ( ! file_exists( $jq ) ) {
    echo "jQuery is missing at .claude/vendor/jquery-3.7.1.min.js\n";
    echo "Fetch it once: curl -o .claude/vendor/jquery-3.7.1.min.js https://code.jquery.com/jquery-3.7.1.min.js\n";
    exit( 1 );
}

/* ---------------------------------------------------------------------------
 * THE BUTTONS, exactly as sfaf_rsvp_button_attrs() stamps them: the formats and
 * the full list are both the server's answer, and the script only draws them.
 * One hybrid event with both formats open, and one plain in-person event, so
 * the "no question asked" case is driven as well as the asked one.
 * ------------------------------------------------------------------------ */
$screen = <<<'HTML'
<button class="uc-rsvp-btn" id="btn-hybrid"
        data-event-id="42"
        data-event-title="Programa Latino: Grupo de Apoyo"
        data-uc-formats="in_person,online"
        data-uc-full="">RSVP (hybrid)</button>

<button class="uc-rsvp-btn" id="btn-plain"
        data-event-id="43"
        data-event-title="A plain in-person event"
        data-uc-formats="in_person"
        data-uc-full="">RSVP (in person only)</button>
HTML;

/* ---------------------------------------------------------------------------
 * The probe. $.ajax is replaced before anything is clicked, so the payload is
 * captured rather than sent, and the page never needs a server.
 * ------------------------------------------------------------------------ */
$probe = <<<'JS'
var lines = [];
function say(s) { lines.push(s); }

var sent = null;
jQuery.ajax = function (opts) {
  sent = opts && opts.data ? opts.data : null;
  return { done: function () { return this; }, fail: function () { return this; } };
};

function fill() {
  jQuery('#uc-rsvp-first-name').val('Lee');
  jQuery('#uc-rsvp-last-name').val('Ramirez');
  jQuery('#uc-rsvp-email').val('lee@example.org');
}

/* ---- 1. THE HYBRID EVENT: the question is asked, and answered ONLINE. ---- */
sent = null;
jQuery('#btn-hybrid').trigger('click');

var box = jQuery('#uc-rsvp-format');
say('hybrid: format control  ' + (box.attr('hidden') ? 'HIDDEN' : 'shown'));
var radios = box.find('input[type="radio"]');
say('hybrid: options drawn   ' + radios.length);
var vals = [];
radios.each(function () { vals.push(this.value); });
say('hybrid: option values   [' + vals.join(', ') + ']');
say('hybrid: radio name      ' + (radios.length ? radios[0].name : 'NONE'));

/* Press Online the way a person does: click the label's input. */
box.find('input[value="online"]').prop('checked', true).trigger('change');
fill();
jQuery('#uc-rsvp-submit-btn').trigger('click');

say('hybrid: payload sent    ' + (sent ? 'yes' : 'NO, the submit was refused'));
if (sent) {
  say('hybrid: payload.format  "' + (sent.format === undefined ? 'MISSING KEY' : sent.format) + '"');
  say('hybrid: payload.action  ' + sent.action);
  say('hybrid: payload.event   ' + sent.event_id);
}
say('');

/* ---- 2. IN PERSON, on the same event. ---- */
sent = null;
jQuery('#btn-hybrid').trigger('click');
jQuery('#uc-rsvp-format').find('input[value="in_person"]').prop('checked', true).trigger('change');
fill();
jQuery('#uc-rsvp-submit-btn').trigger('click');
say('hybrid in person: payload.format  "' + (sent ? sent.format : 'NOT SENT') + '"');
say('');

/* ---- 3. THE HYBRID EVENT WITH NOTHING PICKED: it must refuse. ---- */
sent = null;
jQuery('#btn-hybrid').trigger('click');
fill();
jQuery('#uc-rsvp-submit-btn').trigger('click');
say('hybrid, nothing picked: payload  ' + (sent ? 'SENT ANYWAY' : 'refused, as it should be'));
say('hybrid, nothing picked: message  "' + jQuery('#uc-rsvp-error').text() + '"');
say('');

/* ---- 4. A PLAIN EVENT: the question is not asked and '' is sent. ---- */
sent = null;
jQuery('#btn-plain').trigger('click');
say('plain: format control   ' + (jQuery('#uc-rsvp-format').attr('hidden') ? 'hidden, as it should be' : 'SHOWN'));
fill();
jQuery('#uc-rsvp-submit-btn').trigger('click');
say('plain: payload sent     ' + (sent ? 'yes' : 'NO'));
if (sent) {
  say('plain: payload.format   "' + (sent.format === undefined ? 'MISSING KEY' : sent.format) + '"');
}

say('');
say('script errors    ' + (window.__errs.length ? window.__errs.join(' | ') : 'none'));
document.getElementById('out').textContent = lines.join('\n');
JS;

$caljs = file_get_contents( $root . '/public/js/calendar.js' );
$jqsrc = file_get_contents( $jq );

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: what the RSVP form sends as the format</title>
<!-- GENERATED BY .claude/rsvp-format-live.php. Do not edit; regenerate. -->
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; }
  dialog { border: 1px solid #ccc; }
</style>
</head>
<body>
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
<h1>What the RSVP form sends</h1>
$screen
<pre id="out">not run</pre>
<script>
$jqsrc
</script>
<script>
/* What wp_localize_script() hands the real page. */
var ucData = { ajaxUrl: 'https://example.org/wp-admin/admin-ajax.php', nonce: 'testnonce' };
</script>
<script>
$caljs
</script>
<script>
window.addEventListener('load', function () {
  setTimeout(function () {
$probe
  }, 80);
});
</script>
</body>
</html>
HTML;

file_put_contents( $out, $html );
echo "wrote: $out\n";

/* ---------------------------------------------------------------------------
 * THE COMPANION CHECK: the strings this page reads have to be the strings the
 * PHP compares against, or the page proves something about a different word.
 * ------------------------------------------------------------------------ */
$on = file_get_contents( $root . '/includes/class-sfaf-online.php' );
rf_check( (bool) preg_match( "/const MODE_ONLINE\s*=\s*'online';/", $on ),
    "MODE_ONLINE is no longer the string 'online', so the radio's value and the gate no longer agree" );
rf_check( (bool) preg_match( "/const MODE_IN_PERSON\s*=\s*'in_person';/", $on ),
    "MODE_IN_PERSON is no longer the string 'in_person'" );

$rsvp = file_get_contents( $root . '/includes/class-sfaf-rsvp.php' );
rf_check( false !== strpos( $rsvp, "\$_POST['format']" ),
    'the server no longer reads the format off the POST key this form sends' );

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) {
        echo "Chrome not found at $chrome\n";
        exit( 1 );
    }
    $url = 'file:///' . str_replace( '\\', '/', $out );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --window-size=1200,900 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        echo "Chrome returned no probe block.\n";
        exit( 1 );
    }
    $report = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
    echo $report . "\n";

    rf_check( false !== strpos( $report, 'script errors    none' ),
        'the calendar script threw while the form was being driven' );

    /* THE ONE THAT MATTERS: the word on the wire. */
    rf_check( false !== strpos( $report, 'hybrid: payload.format  "online"' ),
        'picking Online does not send the string "online", so the gate can never match it' );
    rf_check( false !== strpos( $report, 'hybrid in person: payload.format  "in_person"' ),
        'picking In person does not send the string "in_person"' );
    rf_check( false !== strpos( $report, 'hybrid: radio name      uc_rsvp_format' ),
        'the format radios are no longer named uc_rsvp_format' );
    rf_check( false !== strpos( $report, 'hybrid: option values   [in_person, online]' ),
        'the two format options are not the two modes the server enumerates' );

    /* THE QUESTION IS ASKED ONLY WHERE THERE IS ONE. */
    rf_check( false !== strpos( $report, 'hybrid: format control  shown' ),
        'a hybrid event does not draw the format question' );
    rf_check( false !== strpos( $report, 'plain: format control   hidden, as it should be' ),
        'a single-format event draws a format question with one answer' );
    rf_check( false !== strpos( $report, 'plain: payload.format   ""' ),
        "a single-format event does not send an empty format, which is what 'the event never asked' means everywhere downstream" );

    /* AND AN UNANSWERED HYBRID FORM DOES NOT POST. */
    rf_check( false !== strpos( $report, 'hybrid, nothing picked: payload  refused, as it should be' ),
        'a hybrid registration can be submitted without answering the format question' );
}

if ( $fails ) {
    echo "\nFAIL: " . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "\nthe form asks the format only where there is a choice, and sends the exact\n";
echo "strings the gate compares against.\n";
