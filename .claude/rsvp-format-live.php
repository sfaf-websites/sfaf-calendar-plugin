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

/* ---- 5. THE CONTROL AS IT IS DRAWN (3.98.0). --------------------------- */
jQuery('#btn-hybrid').trigger('click');
var seg   = document.querySelector('#uc-rsvp-format .uc-segmented');
var segs  = document.querySelectorAll('#uc-rsvp-format .uc-segment');
var set   = document.querySelector('#uc-rsvp-format .uc-rsvp-format-set');
var form  = document.querySelector('.uc-rsvp-form');

function css(el, p) { return el ? getComputedStyle(el).getPropertyValue(p).trim() : 'ABSENT'; }
/* rect(), not box(): `var box` above is already the jQuery wrapper for the
 * format container, and a function declaration hoists straight into it. */
function rect(el) { return el ? el.getBoundingClientRect() : null; }

say('segmented wrapper       ' + (seg ? 'present' : 'ABSENT'));
say('segments                ' + segs.length);
say('one boundary, radius    ' + css(seg, 'border-radius') + '  border ' + css(seg, 'border-top-width'));
say('segments have own edge  ' + (segs.length ? css(segs[0], 'border-top-width') : 'n/a'));

var sBox = rect(seg), fBox = rect(set);
if (sBox && fBox) {
  say('full width of the row   control ' + Math.round(sBox.width) + 'px, fieldset ' + Math.round(fBox.width) + 'px');
}
if (segs.length === 2) {
  say('two equal segments      ' + Math.round(rect(segs[0]).width) + 'px and ' + Math.round(rect(segs[1]).width) + 'px');
}

/* The chosen and unchosen pair, read off the rendered labels. */
var r0 = segs[0].querySelector('input'), r1 = segs[1].querySelector('input');
var l0 = segs[0].querySelector('.uc-segment-label'), l1 = segs[1].querySelector('.uc-segment-label');
r1.checked = true; r1.dispatchEvent(new Event('change', {bubbles: true}));
say('chosen  fill/ink        ' + css(l1, 'background-color') + ' / ' + css(l1, 'color'));
say('other   fill/ink        ' + css(l0, 'background-color') + ' / ' + css(l0, 'color'));

/* THE RADIOS ARE STILL THERE AND STILL REACHABLE. opacity, not display:none. */
say('radio display/visible   ' + css(r0, 'display') + ' / ' + css(r0, 'visibility'));
say('radio opacity           ' + css(r0, 'opacity'));
say('radio covers segment    ' + (Math.round(rect(r0).width) === Math.round(rect(segs[0]).width) ? 'yes' : 'NO'));

/* ---- 6. EVERY TICK BOX ON THIS FORM, AND ITS GAP. --------------------- */
/* THE FORMAT ROW HAD NONE, which is what sent this looking. A radio or a
 * checkbox whose label starts at its edge reads as one smudged object. */
say('');
say('tick boxes on the form:');
Array.prototype.forEach.call(
  form.querySelectorAll('input[type="checkbox"], input[type="radio"]'),
  function (inp) {
    var lab = inp.closest('label');
    var name = (lab ? lab.textContent : '').replace(/\s+/g, ' ').trim().slice(0, 24) || inp.name;
    if (!lab) { say('  ' + name + '  NO LABEL AROUND IT'); return; }
    /* A segment hides its input under the label on purpose, so the gap
     * question does not apply to it: the segment IS the control. */
    if (lab.classList.contains('uc-segment')) {
      say('  ' + name + '  segment, input covers it by design');
      return;
    }
    var g = getComputedStyle(lab).getPropertyValue('gap').trim();
    var ib = rect(inp), lb = rect(lab);
    var measured = Math.round(ib.left - lb.left) > 0 || g !== 'normal';
    say('  ' + name + '  gap ' + (g === 'normal' ? '(none declared)' : g)
        + (measured ? '' : '   <- NO GAP'));
  }
);

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
<link rel="stylesheet" href="../public/css/calendar.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; }
  /* NO TRANSITIONS ON A MEASURING PAGE. getComputedStyle returns the CURRENT
     interpolated value, so reading a colour in the same tick it was changed
     returns the one it is moving AWAY from. The segments carry a 0.15s
     background transition, and this page read white for the chosen segment
     until this rule was added. */
  * { transition: none !important; animation: none !important; }
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

    /* ---- THE CONTROL'S SHAPE AND ITS MEASURED PAIR (3.98.0). ------------ */
    /* ONE ROUNDED BOUNDARY AROUND THE PAIR, not two buttons that touch. */
    rf_check( false !== strpos( $report, 'segmented wrapper       present' ),
        'the format question is two loose radios again, not the calendar pick-one control' );
    rf_check( false !== strpos( $report, 'segments                2' ),
        'the control does not have exactly two segments' );
    rf_check( false !== strpos( $report, 'one boundary, radius    8px  border 1px' ),
        'the pair has no single rounded boundary around it' );
    rf_check( false !== strpos( $report, 'segments have own edge  0px' ),
        'each segment carries its own border, so the control reads as two boxes rather than one' );

    /* FULL WIDTH OF THE FORM, AND THE TWO SHARE IT EQUALLY. "In person" is a
     * longer word than "Online", and a basis that carried that difference would
     * make the two segments different sizes. */
    if ( preg_match( '/full width of the row   control (\d+)px, fieldset (\d+)px/', $report, $m ) ) {
        rf_check( (int) $m[1] === (int) $m[2],
            'the control is not the full width of the row it sits in' );
    } else {
        rf_check( false, 'the control width could not be measured' );
    }
    if ( preg_match( '/two equal segments      (\d+)px and (\d+)px/', $report, $m ) ) {
        rf_check( abs( (int) $m[1] - (int) $m[2] ) <= 1,
            'the two segments are different widths, so the longer word decides the shape' );
    } else {
        rf_check( false, 'the segment widths could not be measured' );
    }

    /* THE MEASURED PAIR, BOTH WAYS ROUND. #0E7680 and white is 5.35:1, which is
     * the same pair the filter bar's chosen item declares. Read off the
     * RENDERED elements rather than out of the stylesheet, because what a rule
     * says and what a cascade produces are different questions. */
    rf_check( false !== strpos( $report, 'chosen  fill/ink        rgb(14, 118, 128) / rgb(255, 255, 255)' ),
        'the chosen segment is not filled in the measured dark teal with white text' );
    rf_check( false !== strpos( $report, 'other   fill/ink        rgb(255, 255, 255) / rgb(14, 118, 128)' ),
        'the unchosen segment is not white with teal text' );

    /* THE RADIOS ARE STILL THE CONTROL. display:none or visibility:hidden would
     * take them out of the focus order and out of the accessibility tree, which
     * is a control no keyboard can reach and a group no screen reader calls a
     * radio group. */
    rf_check( false === strpos( $report, 'radio display/visible   none' ),
        'the radios are display:none, so the choice cannot be reached by keyboard' );
    rf_check( false === strpos( $report, '/ hidden' ),
        'the radios are visibility:hidden, so the choice is not in the accessibility tree' );
    rf_check( false !== strpos( $report, 'radio opacity           0' ),
        'the radios are not hidden behind their segments, so both the dot and the segment are on screen' );
    rf_check( false !== strpos( $report, 'radio covers segment    yes' ),
        'the radio does not cover its segment, so part of the segment is not clickable' );

    /* ---- EVERY TICK BOX HAS A GAP. ------------------------------------- */
    /* THE FORMAT ROW HAD NONE, which is what sent this looking: nothing styled
     * it, so `.uc-rsvp-form label { display: block }` was the only rule reaching
     * it and the dot sat hard against its word. */
    rf_check( false === strpos( $report, '<- NO GAP' ),
        'a radio or tick box on the RSVP form has no gap between it and its label' );
    rf_check( false === strpos( $report, 'NO LABEL AROUND IT' ),
        'a radio or tick box on the RSVP form is not inside a label at all' );
    rf_check( false !== strpos( $report, 'gap 8px' ),
        'the opt-in checkbox lost the gap it had, so the sweep found nothing to confirm' );
}

if ( $fails ) {
    echo "\nFAIL: " . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "\nthe form asks the format only where there is a choice, and sends the exact\n";
echo "strings the gate compares against.\n";
