<?php
/**
 * THE RSVP FORM WITHOUT AN EMAIL, IN A REAL BROWSER (3.102.0).
 *
 *     php .claude/rsvp-noemail-live.php          writes the page
 *     php .claude/rsvp-noemail-live.php --run    writes it and drives Chrome
 *
 * The real calendar.js and calendar.css, the real modal opened from real
 * buttons stamped the way the server stamps them, and $.ajax replaced only to
 * read the payload and hand back an answer. What is read off the RENDERED page:
 * the line under the email field, the box and whether it is on screen, what
 * ticking it does to the field, the line that replaces it for somebody joining
 * online, what the form posts, and whether the success screen tells somebody
 * with no address to check their inbox.
 */

$root = dirname( __DIR__ );
$out  = $root . '/.claude/rsvp-noemail-live.html';

$fails = array();
function rn_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$jq = $root . '/.claude/vendor/jquery-3.7.1.min.js';
if ( ! file_exists( $jq ) ) { echo "jQuery is missing at .claude/vendor/jquery-3.7.1.min.js\n"; exit( 1 ); }

$screen = <<<'HTML'
<button class="uc-rsvp-btn" id="btn-inperson" data-event-id="41" data-event-title="In person" data-uc-formats="in_person" data-uc-full="">In person</button>
<button class="uc-rsvp-btn" id="btn-online" data-event-id="42" data-event-title="Online" data-uc-formats="online" data-uc-full="">Online</button>
<button class="uc-rsvp-btn" id="btn-hybrid" data-event-id="43" data-event-title="Hybrid" data-uc-formats="in_person,online" data-uc-full="">Hybrid</button>
HTML;

$probe = <<<'JS'
var lines = [];
function say(s) { lines.push(s); }
var sent = null, answer = null;
jQuery.ajax = function (opts) {
  sent = opts && opts.data ? opts.data : null;
  if (answer && opts.success) { opts.success(answer); }
  return { done: function () { return this; }, fail: function () { return this; } };
};
function shown(sel) { var e = document.querySelector(sel); return !!(e && e.getClientRects().length && getComputedStyle(e).display !== 'none'); }
function txt(sel) { var e = document.querySelector(sel); return e ? e.textContent.replace(/\s+/g, ' ').trim() : 'ABSENT'; }
function state(label) {
  say(label + ': box ' + (shown('#uc-rsvp-noemail-wrap') ? 'shown' : 'hidden')
    + ', online line ' + (shown('#uc-rsvp-online-note') ? 'shown' : 'hidden')
    + ', ticked ' + jQuery('#uc-rsvp-noemail').is(':checked')
    + ', email ' + (jQuery('#uc-rsvp-email').prop('disabled') ? 'disabled' : 'enabled')
    + ', consequence ' + (shown('#uc-rsvp-noemail-note') ? 'shown' : 'hidden'));
}
function tick(on) { jQuery('#uc-rsvp-noemail').prop('checked', on).trigger('change'); }
function pick(f) { jQuery('#uc-rsvp-format input[value="' + f + '"]').prop('checked', true).trigger('change'); }

/* ---- 1. IN PERSON. ---- */
jQuery('#btn-inperson').trigger('click');
say('privacy line: ' + txt('#uc-rsvp-email + .uc-rsvp-note'));
say('box label: ' + txt('#uc-rsvp-noemail-wrap'));
state('in person, opened');
jQuery('#uc-rsvp-email').val('half@typ');
tick(true);
state('in person, ticked');
say('in person, ticked, field value "' + jQuery('#uc-rsvp-email').val() + '"');
say('consequence line: ' + txt('#uc-rsvp-noemail-note'));
jQuery('#uc-rsvp-first-name').val('Lee');
answer = { success: true, first_name: 'Lee', no_email: true };
sent = null;
jQuery('#uc-rsvp-submit-btn').trigger('click');
say('in person, ticked, posted ' + (sent ? 'no_email "' + sent.no_email + '" email "' + sent.email + '"' : 'NOTHING'));
say('success inbox line ' + (shown('#uc-rsvp-success .uc-rsvp-inbox-note') ? 'shown' : 'hidden'));

/* The next person, with an address, sees the inbox line again. */
jQuery('#btn-inperson').trigger('click');
state('in person, reopened');
jQuery('#uc-rsvp-first-name').val('Ana');
jQuery('#uc-rsvp-email').val('ana@example.org');
answer = { success: true, first_name: 'Ana', no_email: false };
sent = null;
jQuery('#uc-rsvp-submit-btn').trigger('click');
say('with an address, posted ' + (sent ? 'no_email "' + sent.no_email + '" email "' + sent.email + '"' : 'NOTHING'));
say('with an address, success inbox line ' + (shown('#uc-rsvp-success .uc-rsvp-inbox-note') ? 'shown' : 'hidden'));

/* ---- 2. ONLINE: no box, the line instead. ---- */
answer = null;
jQuery('#btn-online').trigger('click');
state('online');
say('online line: ' + txt('#uc-rsvp-online-note'));

/* ---- 3. HYBRID: the choice decides. ---- */
jQuery('#btn-hybrid').trigger('click');
state('hybrid, nothing chosen');
pick('in_person');
tick(true);
state('hybrid, in person, ticked');
pick('online');
state('hybrid, then online');
pick('in_person');
state('hybrid, back to in person');

/* ---- 4. The box's gap, like every tick box on this form. ---- */
jQuery('#btn-inperson').trigger('click');
var lab = document.querySelector('#uc-rsvp-noemail-wrap');
say('box gap ' + getComputedStyle(lab).getPropertyValue('gap').trim() + ', display ' + getComputedStyle(lab).display);

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
<title>SFAF Calendar: the RSVP form without an email</title>
<!-- GENERATED BY .claude/rsvp-noemail-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/calendar.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc; font: 12px/1.5 ui-monospace, Consolas, monospace; }
  * { transition: none !important; animation: none !important; }
</style>
</head>
<body>
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
$screen
<pre id="out">not run</pre>
<script>
$jqsrc
</script>
<script>var ucData = { ajaxUrl: 'https://example.org/wp-admin/admin-ajax.php', nonce: 'testnonce' };</script>
<script>
$caljs
</script>
<script>
window.addEventListener('load', function () { setTimeout(function () {
$probe
}, 80); });
</script>
</body>
</html>
HTML;

file_put_contents( $out, $html );
echo "wrote: $out\n";

/* The server reads the key this form sends. */
rn_check( false !== strpos( file_get_contents( $root . '/includes/class-sfaf-rsvp.php' ), "\$_POST['no_email']" ),
    'the server does not read no_email off the POST, so the tick would be ignored' );

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $url = 'file:///' . str_replace( '\\', '/', $out );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --window-size=1200,900 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { echo "Chrome returned no probe block.\n"; exit( 1 ); }
    $report = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
    echo $report . "\n";

    $has = function ( $line, $why ) use ( $report ) { rn_check( false !== strpos( $report, $line ), $why ); };
    $has( 'script errors    none', 'the calendar script threw while the form was driven' );
    $has( 'privacy line: We use your email only for this event: your confirmation, a reminder, and any changes. It is never shared or added to a list.',
        'the line under the email field is not the one agreed' );
    $has( 'box label: I do not use email, or prefer not to share it.', 'the box does not say what the brief says' );
    $has( 'in person, opened: box shown, online line hidden, ticked false, email enabled, consequence hidden', 'the box is not offered on an in-person registration' );
    $has( 'in person, ticked: box shown, online line hidden, ticked true, email disabled, consequence shown', 'ticking does not disable the field and show the consequence' );
    $has( 'in person, ticked, field value ""', 'ticking does not clear what was typed' );
    $has( 'consequence line: You will not get a confirmation, a reminder, or notice of changes.', 'the consequence line is not the one agreed' );
    $has( 'in person, ticked, posted no_email "1" email ""', 'the form does not post no_email with an empty address' );
    $has( 'success inbox line hidden', 'somebody with no address is told to check their inbox' );
    $has( 'in person, reopened: box shown, online line hidden, ticked false, email enabled, consequence hidden', 'the next person inherits the tick' );
    $has( 'with an address, posted no_email "" email "ana@example.org"', 'an ordinary registration posts a no_email flag' );
    $has( 'with an address, success inbox line shown', 'the inbox line is gone for somebody who gave an address' );
    $has( 'online: box hidden, online line shown, ticked false, email enabled', 'the box is offered on an online registration' );
    $has( 'online line: Joining online needs an email address for the meeting link.', 'the online line is not the one agreed' );
    $has( 'hybrid, nothing chosen: box shown', 'a hybrid form with nothing chosen does not offer the box' );
    $has( 'hybrid, then online: box hidden, online line shown, ticked false, email enabled, consequence hidden', 'choosing online on a hybrid event leaves the box ticked or offered' );
    $has( 'hybrid, back to in person: box shown, online line hidden', 'choosing in person again does not bring the box back' );
    $has( 'box gap 8px, display flex', 'the box has no gap, or its layout was lost' );
}

if ( $fails ) {
    echo "\nFAIL: " . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "\nthe box is offered only where the meeting link is not needed, ticking it clears and disables the\n";
echo "field, the form posts no address, and nobody without one is told to check their inbox.\n";
