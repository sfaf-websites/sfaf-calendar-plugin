<?php
/**
 * THE FORMAT TICKS, IN A REAL BROWSER, WITH THE REAL portal.js.
 *
 *     php .claude/hybrid-live.php          writes the page
 *     php .claude/hybrid-live.php --run    writes it and drives Chrome
 *
 * WHY THIS AND NOT ANOTHER SOURCE-READING CHECK. "Online and hybrid can both be
 * ticked" and "hybrid shows both halves" are statements about what a document
 * looks like AFTER a sequence of clicks, and the only thing that can answer
 * that is a document and a sequence of clicks. hybrid-test.php asserts that the
 * handlers are wired and that the save folds a posted pair; neither of those is
 * the same question, and 3.81.0 is the release that learned the difference.
 *
 * THE ORDER IS BOTH ORDERS. Ticking online then hybrid and ticking hybrid then
 * online have to land in the same place, and an exclusivity rule written as one
 * assignment rather than two is exactly the shape that works one way round.
 *
 * AND UNTICKING IS A TEST, not a tidy-up. The lock turns Accept RSVPs ON while
 * hybrid is ticked; what has to come back when it is unticked is the value the
 * event ALREADY HAD, not the one the lock left behind. An event with
 * registrations off, briefly made hybrid and then not, must still have them
 * off, or the screen has quietly turned on a form for a manager who never
 * asked for one.
 *
 * WHAT THIS CANNOT PROVE: that render_event_form() emits this markup. The
 * companion check at the foot reads the renderer and compares every attribute
 * driven here against the ones it writes, which is the half that goes stale.
 */

$root = dirname( __DIR__ );
$out  = $root . '/.claude/hybrid-live.html';

$fails = array();
function hl_check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* ---------------------------------------------------------------------------
 * THE MARKUP, matching render_event_form()'s location field, the Accept RSVPs
 * control inside it, and the Display card's RSVP tick in its own card.
 *
 * ACCEPT RSVPs STARTS UNTICKED AND ENABLED, which is the event this is about:
 * one that does NOT take registrations. Starting it ticked would hide the
 * restore fault completely, because the value it came back with would be the
 * value the lock left.
 * ------------------------------------------------------------------------ */
$screen = <<<'HTML'
<div class="uc-field uc-location-field" data-uc-location>
  <span class="uc-field-label">Location</span>

  <input type="hidden" name="uc_rsvp_toggle_present" value="1" />
  <label class="uc-check" data-uc-rsvp-accept-check>
    <input type="checkbox" name="rsvp_enabled" value="1" />
    Accept RSVPs
  </label>
  <span class="uc-hint uc-hint-spec" data-uc-rsvp-hybrid-note hidden>
    A hybrid event has to take RSVPs: choosing in person or online is part of registering.
  </span>

  <input type="hidden" name="uc_online_present" value="1" />
  <input type="hidden" name="uc_online" value="0" />
  <label class="uc-check uc-online-check">
    <input type="checkbox" name="uc_online" value="1" data-uc-online-toggle />
    This is an online event
  </label>

  <input type="hidden" name="uc_hybrid" value="0" />
  <label class="uc-check uc-online-check">
    <input type="checkbox" name="uc_hybrid" value="1" data-uc-hybrid-toggle />
    This is a hybrid event
  </label>

  <div class="uc-online-panel" data-uc-online-panel>
    <label class="uc-field"><span class="uc-field-label">Meeting link</span>
      <input type="url" name="meeting_url" value="" /></label>
    <div class="uc-field">
      <span class="uc-field-label">When the link goes out</span>
      <label class="uc-check"><input type="checkbox" name="meeting_send[]" value="confirmation" /> With the confirmation</label>
      <label class="uc-check"><input type="checkbox" name="meeting_send[]" value="reminder" /> With the reminder</label>
    </div>
  </div>

  <div class="uc-location-place" data-uc-location-place>
    <label class="uc-check">
      <input type="radio" name="location_mode" value="venue" data-uc-location-mode="venue" checked />
      A venue</label>
    <div class="uc-location-venue" data-uc-location-panel="venue">
      <select name="venue_id"><option value="3">Strut</option></select>
    </div>
    <label class="uc-check">
      <input type="radio" name="location_mode" value="custom" data-uc-location-mode="custom" />
      A different location</label>
    <div class="uc-location-custom" data-uc-location-panel="custom">
      <input type="text" name="location_street" value="470 Castro Street" />
    </div>
  </div>
</div>

<div class="uc-field uc-capacity-row" data-uc-capacity-row>
  <span class="uc-field-label">Capacity</span>
  <div class="uc-capacity-boxes">
    <label class="uc-capacity-box">
      <span class="uc-capacity-box-label" data-uc-capacity-label hidden>In person</span>
      <input type="number" name="capacity" min="0" aria-label="Capacity"
             data-uc-capacity-in-person value="" />
    </label>
    <label class="uc-capacity-box" data-uc-online-capacity hidden>
      <span class="uc-capacity-box-label">Online</span>
      <input type="number" name="capacity_online" min="0" aria-label="Capacity, online" value="" />
    </label>
  </div>
  <span class="uc-hint uc-hint-spec">0 means unlimited.</span>
</div>

<div class="uc-card">
  <label class="uc-check" data-uc-rsvp-show-check>
    <input type="checkbox" name="show_rsvp" value="1" checked />
    RSVP
  </label>
  <p class="uc-hint uc-rsvp-show-note" data-uc-rsvp-show-note hidden>
    This event is not accepting RSVPs, so there is no button to show.
  </p>
  <p class="uc-hint uc-rsvp-show-note" data-uc-rsvp-hybrid-show-note hidden>
    A hybrid event needs its registration button: choosing in person or online is part of registering.
  </p>
</div>
HTML;

/* ---------------------------------------------------------------------------
 * The probe. Every line is a fact read back off the document after a click.
 * ------------------------------------------------------------------------ */
$probe = <<<'JS'
var lines = [];
function say(s) { lines.push(s); }

var online  = document.querySelector('[data-uc-online-toggle]');
var hybrid  = document.querySelector('[data-uc-hybrid-toggle]');
var panel   = document.querySelector('[data-uc-online-panel]');
var place   = document.querySelector('[data-uc-location-place]');
var cap     = document.querySelector('[data-uc-online-capacity]');
var capLbl  = document.querySelector('[data-uc-capacity-label]');
var capIn   = document.querySelector('[data-uc-capacity-in-person]');
var sendBox = document.querySelectorAll('input[name="meeting_send[]"]');
var accept  = document.querySelector('[data-uc-rsvp-accept-check] input');
var showBox = document.querySelector('[data-uc-rsvp-show-check] input');
var note    = document.querySelector('[data-uc-rsvp-hybrid-note]');
var shown   = document.querySelector('[data-uc-rsvp-hybrid-show-note]');

function vis(el) { return el ? (el.hidden ? 'hidden' : 'shown') : 'ABSENT'; }
function tick(el) { return el ? (el.checked ? 'ticked' : 'clear') : 'ABSENT'; }
function lock(el) { return el ? (el.disabled ? 'locked' : 'free') : 'ABSENT'; }

function state(label) {
  say(label);
  say('  online tick      ' + tick(online));
  say('  hybrid tick      ' + tick(hybrid));
  say('  meeting panel    ' + vis(panel));
  say('  venue+address    ' + vis(place));
  say('  places online    ' + vis(cap));
  say('  in person label  ' + vis(capLbl));
  say('  capacity name    "' + (capIn ? capIn.getAttribute('aria-label') : 'ABSENT') + '"');
  var on = 0;
  Array.prototype.forEach.call(sendBox, function (b) { if (b.checked) { on++; } });
  say('  link delivery    ' + on + ' of ' + sendBox.length + ' ticked');
  say('  accept rsvps     ' + tick(accept) + ', ' + lock(accept));
  say('  display rsvp     ' + tick(showBox) + ', ' + lock(showBox));
  say('  hybrid notes     accept=' + vis(note) + ' display=' + vis(shown));
  say('');
}

say('bound            ' + (document.querySelector('.uc-location-on') ? 'yes' : 'NO, initLocationPicker never ran'));
say('');

state('ARRIVAL, neither ticked (an in-person event that takes no RSVPs)');

/* ---- ORDER ONE: online, then hybrid. ---- */
online.click();
state('A1. ticked ONLINE');

hybrid.click();
state('A2. then ticked HYBRID  (online must have cleared, both halves must show)');

hybrid.click();
state('A3. then UNTICKED hybrid  (accept rsvps must go back to CLEAR and free)');

/* Put it back to the arrival state for the second order. */
if (online.checked) { online.click(); }
if (hybrid.checked) { hybrid.click(); }

/* ---- ORDER TWO: hybrid, then online. ---- */
hybrid.click();
state('B1. ticked HYBRID first');

online.click();
state('B2. then ticked ONLINE  (hybrid must have cleared, address must hide)');

say('script errors    ' + (window.__errs.length ? window.__errs.join(' | ') : 'none'));
document.getElementById('out').textContent = lines.join('\n');
JS;

$portaljs = file_get_contents( $root . '/public/js/portal.js' );

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: the format ticks, live</title>
<!-- GENERATED BY .claude/hybrid-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/portal.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; }
</style>
</head>
<body class="uc-portal">
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
<h1>The format ticks</h1>
$screen
<pre id="out">not run</pre>
<script>
$portaljs
</script>
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

/* ---------------------------------------------------------------------------
 * THE COMPANION CHECK. Every attribute the probe drives has to be one the
 * renderer actually writes, or this page tests a screen that does not exist.
 * ------------------------------------------------------------------------ */
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
foreach ( array(
    'data-uc-location',
    'data-uc-online-toggle',
    'data-uc-hybrid-toggle',
    'data-uc-online-panel',
    'data-uc-location-place',
    'data-uc-online-capacity',
    'data-uc-capacity-row',
    'data-uc-capacity-label',
    'data-uc-capacity-in-person',
    'data-uc-rsvp-accept-check',
    'data-uc-rsvp-show-check',
    'data-uc-rsvp-hybrid-note',
    'data-uc-rsvp-hybrid-show-note',
) as $attr ) {
    hl_check( false !== strpos( $portal, $attr ),
        'the editor no longer writes ' . $attr . ', so this page drives a screen that does not exist' );
}

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) {
        echo "Chrome not found at $chrome\n";
        exit( 1 );
    }
    $url = 'file:///' . str_replace( '\\', '/', $out );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --window-size=1400,1200 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        echo "Chrome returned no probe block.\n";
        exit( 1 );
    }
    $report = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
    echo $report . "\n";

    /* ---- What the report has to say. ------------------------------------ */
    $block = function ( $label ) use ( $report ) {
        $at = strpos( $report, $label );
        if ( false === $at ) { return ''; }
        /* WIDE ENOUGH FOR THE WHOLE BLOCK. Each state() prints a dozen lines
         * now, and a window that ends mid-block reports the lines past it as
         * missing, which is a test failing for its own reason. */
        return substr( $report, $at, 900 );
    };

    hl_check( false !== strpos( $report, 'bound            yes' ),
        'initLocationPicker never bound, so nothing below this was actually exercised' );
    hl_check( false !== strpos( $report, 'script errors    none' ),
        'the editor script threw while the ticks were being driven' );

    /* EVERY BLOCK UP FRONT. They were sliced out one at a time beside the
     * assertions that read them, which put two of them after their own first
     * use: an undefined variable is an empty string, and strpos('', ...) is
     * false, so those assertions FAILED FOR THEIR OWN REASON and said the
     * screen was wrong. Defining them together costs five lines and removes
     * the ordering entirely. */
    $arrival = $block( 'ARRIVAL' );
    $a1      = $block( 'A1.' );
    $a2      = $block( 'A2.' );
    $a3      = $block( 'A3.' );
    $b1      = $block( 'B1.' );
    $b2      = $block( 'B2.' );

    /* ONE TICK AT A TIME, BOTH ORDERS. */
    hl_check( false !== strpos( $a2, 'online tick      clear' ),
        'ticking hybrid left the online tick on, so both formats are ticked at once' );
    hl_check( false !== strpos( $a2, 'hybrid tick      ticked' ), 'ticking hybrid did not tick hybrid' );
    hl_check( false !== strpos( $b2, 'hybrid tick      clear' ),
        'ticking online left the hybrid tick on, so the exclusivity works one way round only' );
    hl_check( false !== strpos( $b2, 'online tick      ticked' ), 'ticking online did not tick online' );

    /* HYBRID SHOWS BOTH HALVES. */
    hl_check( false !== strpos( $a2, 'meeting panel    shown' ),
        'a hybrid event hides the meeting link panel, and it has a meeting link' );
    hl_check( false !== strpos( $a2, 'venue+address    shown' ),
        'a hybrid event hides the venue and address, and half its registrants are going there' );
    hl_check( false !== strpos( $a2, 'places online    shown' ),
        'a hybrid event hides the online capacity' );

    /* CAPACITY IS ONE ROW, AND HYBRID PUTS TWO BOXES IN IT (3.98.0). With one
     * box the row's own label is the whole name, so the format names appear
     * only when there are two of them to tell apart, and the in-person input's
     * ACCESSIBLE name moves with the visible one. */
    hl_check( false !== strpos( $a2, 'in person label  shown' ),
        'a hybrid event shows two capacity boxes and does not name which is which' );
    hl_check( false !== strpos( $a2, 'capacity name    "Capacity, in person"' ),
        "the in-person capacity box does not tell a screen reader which format it is, on the one event where there are two" );
    hl_check( false !== strpos( $a1, 'in person label  hidden' ),
        'a single-format event names its one capacity box "In person", which on an online event is the wrong word' );
    hl_check( false !== strpos( $a1, 'capacity name    "Capacity"' ),
        'a single-format event gives its one capacity box a format-specific accessible name' );
    hl_check( false !== strpos( $a3, 'in person label  hidden' ),
        'the format names stay on screen after hybrid is unticked, beside a box that is gone' );

    /* THE LINK GOES OUT BY DEFAULT (3.98.0), on the TRANSITION to a format that
     * has a link. A meeting link nobody is sent helps nobody, and it was what an
     * event switched to online started in. */
    hl_check( false !== strpos( $arrival, 'link delivery    0 of 2 ticked' ),
        'the delivery ticks start on, so this proves nothing about the default' );
    hl_check( false !== strpos( $a1, 'link delivery    2 of 2 ticked' ),
        'ticking online does not turn the two link delivery ticks on, so a link is entered and sent nowhere' );
    hl_check( false !== strpos( $b1, 'link delivery    2 of 2 ticked' ),
        'ticking hybrid does not turn the two link delivery ticks on' );

    /* A PURELY ONLINE EVENT SHOWS ONE HALF. */
    hl_check( false !== strpos( $a1, 'venue+address    hidden' ),
        'a purely online event still shows the venue and address' );
    hl_check( false !== strpos( $a1, 'places online    hidden' ),
        'a purely online event shows the online capacity, which is a hybrid control' );
    hl_check( false !== strpos( $b2, 'venue+address    hidden' ),
        'moving from hybrid to online left the address on screen' );

    /* BOTH RSVP CONTROLS LOCK ON WHILE HYBRID IS TICKED. */
    hl_check( false !== strpos( $a2, 'accept rsvps     ticked, locked' ),
        'a hybrid event does not lock Accept RSVPs on, so the format question has no form to be asked on' );
    hl_check( false !== strpos( $a2, 'display rsvp     ticked, locked' ),
        "a hybrid event does not lock the Display card's RSVP tick on" );
    hl_check( false !== strpos( $a2, 'accept=shown display=shown' ),
        'the hybrid event does not say why the two RSVP controls are locked' );

    /* AND THE STORED VALUE COMES BACK WHEN HYBRID IS UNTICKED.
     *
     * THIS IS THE ONE THAT NEEDED A BROWSER. Accept RSVPs arrived CLEAR, and an
     * event whose manager tried hybrid and changed their mind must not be left
     * taking registrations they never asked for. */
    hl_check( false !== strpos( $a3, 'accept rsvps     clear, free' ),
        'unticking hybrid left Accept RSVPs TICKED, so an event that took no registrations now takes them' );
    hl_check( false !== strpos( $a3, 'display rsvp     ticked, free' ),
        "unticking hybrid did not give the Display card's RSVP tick back" );
    hl_check( false !== strpos( $a3, 'hybrid notes     accept=hidden display=hidden' ),
        'the hybrid reason is still on screen after hybrid was unticked' );
    /* AND THE SAME ON THE WAY OUT THROUGH THE OTHER TICK. Hybrid is left by
     * unticking it OR by ticking online, and only one of those two paths runs
     * the restore unless it is hung off the lock rather than off the tick. */
    hl_check( false !== strpos( $b2, 'accept rsvps     clear, free' ),
        'moving from hybrid straight to online left Accept RSVPs ticked, so leaving hybrid restores only one way round' );
}

if ( $fails ) {
    echo "\nFAIL: " . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "\nthe two format ticks are exclusive both ways round, hybrid shows both halves,\n";
echo "and the RSVP controls lock on with it and give the stored value back.\n";
