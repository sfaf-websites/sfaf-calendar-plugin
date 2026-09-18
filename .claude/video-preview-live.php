<?php
/**
 * THE VIDEO PREVIEW, IN A REAL BROWSER, WITH THE REAL portal.js.
 *
 *     php .claude/video-preview-live.php          writes the page
 *     php .claude/video-preview-live.php --run    writes it and drives Chrome
 *
 * WHAT IT IS FOR. "The preview fills the moment a series is chosen" is a
 * statement about a document after a change event, and the only thing that can
 * answer it is a document and a change event. The same goes for "the tick is
 * offered only where there is a series video to refuse": that one moves under
 * the manager's hands as they change the series, which no rendered snapshot
 * shows.
 *
 * THREE INPUTS, ONE ANSWER, and the order between them is the thing being
 * driven:
 *
 *     the event's own link   wins outright
 *     the tick               nothing plays
 *     the chosen series      its video, with a line saying so
 *     none of those          no preview at all
 *
 * WHAT IT CANNOT PROVE: that render_video_field() emits this markup, or that
 * ucVideoEmbed() agrees with the PHP parser. The first is the companion check
 * at the foot; the second is .claude/video-parse-crosscheck.php.
 */

$root = dirname( __DIR__ );
$out  = $root . '/.claude/video-preview-live.html';

$fails = array();
function vp_check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* Two series: one WITH a video, one without, so "only where there is something
 * to refuse" is a question with both answers on one page. */
$SERIES_EMBED = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';
$MAP          = array( '7' => $SERIES_EMBED );

$screen = <<<'HTML'
<div class="uc-field">
  <span class="uc-field-label">Series</span>
  <select name="series" data-uc-series-select>
    <option value="">No series</option>
    <option value="7">Trans Thrive (has a video)</option>
    <option value="9">Strut Drop-in (no video)</option>
  </select>
</div>

<label class="uc-field">
  <span class="uc-field-label">Video</span>
  <input type="url" name="video_url" id="uc-event-video" value="" />
</label>

<div class="uc-video-preview" data-uc-video-preview hidden>
  <p class="uc-hint uc-video-preview-note" data-uc-video-from-series hidden>
    This event will show the series video.
  </p>
  <div class="uc-video-frame">
    <iframe data-uc-video-frame src="" title="Video preview" loading="lazy" allowfullscreen></iframe>
  </div>
</div>

<label class="uc-check" data-uc-video-none-row hidden>
  <input type="checkbox" name="video_none" value="1" data-uc-video-none />
  Don't show the series video on this event
</label>
HTML;

$probe = <<<'JS'
var lines = [];
function say(s) { lines.push(s); }

var sel     = document.querySelector('[data-uc-series-select]');
var field   = document.getElementById('uc-event-video');
var preview = document.querySelector('[data-uc-video-preview]');
var frame   = document.querySelector('[data-uc-video-frame]');
var note    = document.querySelector('[data-uc-video-from-series]');
var noneRow = document.querySelector('[data-uc-video-none-row]');
var none    = document.querySelector('[data-uc-video-none]');

function vis(el) { return el ? (el.hidden ? 'hidden' : 'shown') : 'ABSENT'; }
function src()   { return frame.getAttribute('src') || '(empty)'; }

function state(label) {
  say(label);
  say('  preview       ' + vis(preview));
  say('  frame src     ' + src());
  say('  series note   ' + vis(note));
  say('  no-video tick ' + vis(noneRow) + ', ' + (none && none.checked ? 'ticked' : 'clear'));
  say('');
}

function pick(v) { sel.value = v; sel.dispatchEvent(new Event('change', {bubbles: true})); }
function type(v) { field.value = v; field.dispatchEvent(new Event('input', {bubbles: true})); }

state('1. A NEW EVENT: no series, no link');

pick('9');
state('2. A SERIES WITH NO VIDEO chosen');

pick('7');
state('3. A SERIES WITH A VIDEO chosen (must fill AT ONCE, before any save)');

type('https://vimeo.com/123456789');
state('4. AND THE EVENT GETS ITS OWN LINK (must switch to it, note must go)');

type('');
state('5. THE OWN LINK CLEARED (must go back to the series video)');

none.click();
state('6. THE TICK (must clear the preview)');

/* STILL TICKED, and now the series changes to one with no video. The tick must
 * go away AND be cleared: a hidden tick that is still on goes on suppressing a
 * video with no control on screen saying so. */
pick('9');
state('7. STILL TICKED, then a series with NO video (tick must go AND clear)');

pick('7');
state('7b. back to the series with a video (nothing must still be suppressed)');

/* RUBBISH, ON A SERIES WITH NO VIDEO, so there is nothing else to fall back to
 * and the question is only "does an address that is not a video draw a player".
 * Typed on a series that HAS one, the right answer is the series video, which
 * is a different question and is scenario 5's. */
pick('9');
type('not a video address at all');
state('8. RUBBISH TYPED IN THE LINK FIELD (must show nothing, not a broken frame)');

type('https://www.youtube.com/embed/dQw4w9WgXcQ');
state('9. EMBED-CODE ADDRESS (refused by both parsers, so nothing plays)');

say('script errors  ' + (window.__errs.length ? window.__errs.join(' | ') : 'none'));
document.getElementById('out').textContent = lines.join('\n');
JS;

$portaljs = file_get_contents( $root . '/public/js/portal.js' );
$mapjson  = wp_json_encode_stub( $MAP );

function wp_json_encode_stub( $a ) { return json_encode( $a ); }

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: the video preview, live</title>
<!-- GENERATED BY .claude/video-preview-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/portal.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; }
</style>
</head>
<body class="uc-portal">
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
<h1>The video preview</h1>
$screen
<script type="application/json" data-uc-series-videos>$mapjson</script>
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
 * THE COMPANION CHECK: every attribute driven here has to be one the renderer
 * writes, or this page tests a screen that does not exist.
 * ------------------------------------------------------------------------ */
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
foreach ( array(
    'data-uc-video-preview',
    'data-uc-video-frame',
    'data-uc-video-from-series',
    'data-uc-video-none-row',
    'data-uc-video-none',
    'data-uc-series-videos',
    'data-uc-series-select',
) as $attr ) {
    /* THE ATTRIBUTE, NOT A LONGER WORD CONTAINING IT. `data-uc-series-videos`
     * matches inside `data-uc-series-videos-off`, so a plant that renamed the
     * map's attribute went straight past this. An attribute ends at a
     * non-attribute character, which is what the boundary asserts. */
    vp_check( (bool) preg_match( '/' . preg_quote( $attr, '/' ) . '(?![A-Za-z0-9_-])/', $portal ),
        'the editor no longer writes ' . $attr . ', so this page drives a screen that does not exist' );
}

/*
 * AND THE TWO THINGS THE SERVER DECIDES ON THE FIRST DRAW.
 *
 * THE PAGE ABOVE CANNOT SEE EITHER OF THESE, because it drives its own markup
 * rather than the renderer's. The script keeps the tick honest once the series
 * changes under it; what the SERVER has to get right is the state the screen
 * arrives in, which is the one a manager sees before touching anything.
 */
vp_check( (bool) preg_match( '/data-uc-video-none-row<\?php echo \$series_has \? \'\' : \' hidden\'; \?>/', $portal ),
    'the no-video tick arrives shown on every event, so an event whose series has no video offers a control that turns off nothing' );
vp_check( (bool) preg_match( '/\$series_has  = \( \$series_now && isset\( \$series_videos\[ \(string\) \$series_now \] \) \);/', $portal ),
    "the first draw no longer asks whether the event's own series has a video" );

/* AND THE COPY IS THE COPY.
 *
 * READ OUT OF THE INLINE HTML, NOT OUT OF THE FILE. The block above this one
 * EXPLAINS that the label used to read "This event has no video" and why it
 * stopped, so a plain strpos() over the source reports the old label as still
 * rendered. Rendered copy is T_INLINE_HTML and nothing else; the first version
 * of this check failed on its own explanation. */
$rendered = '';
foreach ( token_get_all( $portal ) as $t ) {
    if ( is_array( $t ) && T_INLINE_HTML === $t[0] ) { $rendered .= $t[1]; }
}

vp_check( false !== strpos( $rendered, "Don't show the series video on this event" ),
    'the no-video tick is labelled something else, so its label and this page disagree' );
vp_check( false === strpos( $rendered, 'This event has no video' ),
    'the old "This event has no video" label is back, which says nothing about the series it is refusing' );
vp_check( false !== strpos( $rendered, 'This event will show the series video.' ),
    'the line saying the series video will play is gone' );

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

    /*
     * ONE BLOCK, ENDING AT ITS OWN BLANK LINE, NEVER A FIXED WINDOW.
     *
     * THIS WAS WRONG AND A PLANT CAUGHT IT. A 400-character window from the
     * label ran past the end of the block and into the NEXT one, so an
     * assertion about scenario 6 was satisfied by scenario 7's answer: the
     * planted fault left the tick showing a video and the check passed,
     * because the block after it said "hidden". Every state() ends with a blank
     * line, which is the block's real boundary and costs one strpos to find.
     */
    $block = function ( $label ) use ( $report ) {
        $at = strpos( $report, $label );
        if ( false === $at ) { return ''; }
        $end = strpos( $report, "\n\n", $at );
        return ( false === $end ) ? substr( $report, $at ) : substr( $report, $at, $end - $at );
    };
    $s1 = $block( '1. ' ); $s2 = $block( '2. ' ); $s3 = $block( '3. ' ); $s4 = $block( '4. ' );
    $s5 = $block( '5. ' ); $s6 = $block( '6. ' ); $s7 = $block( '7. ' ); $s8 = $block( '8. ' );

    vp_check( false !== strpos( $report, 'script errors  none' ),
        'the editor script threw while the preview was being driven' );

    /* A NEW EVENT WITH NO SERIES SHOWS AN EMPTY FIELD AND NOTHING ELSE. */
    vp_check( false !== strpos( $s1, 'preview       hidden' ),
        'a new event with no series shows a video preview' );
    vp_check( false !== strpos( $s1, 'no-video tick hidden' ),
        'a new event with no series offers a tick that would refuse a video it does not have' );

    /* A SERIES WITH NO VIDEO IS THE SAME CASE. */
    vp_check( false !== strpos( $s2, 'preview       hidden' ),
        'a series with no video still fills the preview' );
    vp_check( false !== strpos( $s2, 'no-video tick hidden' ),
        'the tick is offered on a series with no video, where it would turn off nothing' );

    /* CHOOSING A SERIES WITH A VIDEO FILLS IT AT ONCE. */
    vp_check( false !== strpos( $s3, 'preview       shown' ),
        'choosing a series with a video does not fill the preview, so it still takes a save to find out' );
    vp_check( false !== strpos( $s3, 'series note   shown' ),
        'the preview does not say the video is the series\', so it reads as one the manager attached' );
    vp_check( false !== strpos( $s3, 'no-video tick shown' ),
        'the tick is not offered on a series that HAS a video, which is the only place it does anything' );

    /* THE EVENT'S OWN LINK WINS, AND THE NOTE GOES WITH IT. */
    vp_check( false !== strpos( $s4, 'player.vimeo.com/video/123456789' ),
        "typing the event's own link does not switch the preview to it" );
    vp_check( false !== strpos( $s4, 'series note   hidden' ),
        "the preview still says it is the series' video while showing the event's own" );

    /* CLEARING IT GOES BACK. */
    vp_check( false !== strpos( $s5, 'series note   shown' ),
        'clearing the event\'s own link does not go back to the series video' );

    /* THE TICK CLEARS IT. */
    vp_check( false !== strpos( $s6, 'preview       hidden' ),
        'ticking "don\'t show the series video" leaves it playing' );

    /* AND A SERIES WITH NO VIDEO TAKES THE TICK AWAY AND CLEARS IT. Hidden and
     * still ticked is the worst of the three states: it goes on suppressing a
     * video with no control on screen saying so, and the next series that HAS
     * one would arrive already refused. */
    vp_check( false !== strpos( $s7, 'no-video tick hidden, clear' ),
        'the tick survives a move to a series with no video, so it is still on with nothing on screen saying so' );
    $s7b = $block( '7b. ' );
    vp_check( false !== strpos( $s7b, 'preview       shown' ),
        'coming back to a series with a video shows nothing, because a tick nobody can see is still refusing it' );

    /* RUBBISH SHOWS NOTHING RATHER THAN A BROKEN FRAME. */
    vp_check( false !== strpos( $s8, 'preview       hidden' ),
        'an address that is not a video still draws a player, which loads nothing and looks broken' );

    /* AND SO DOES AN /embed/ ADDRESS, which both parsers refuse on purpose: it
     * is the address out of embed code rather than one out of a browser bar,
     * and accepting it here would preview something the save will reject. */
    $s9 = $block( '9. ' );
    vp_check( false !== strpos( $s9, 'preview       hidden' ),
        'an /embed/ address previews, so the editor shows a video the save will refuse' );
}

if ( $fails ) {
    echo "\nFAIL: " . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "\nthe preview answers the event's own link, then the tick, then the chosen\n";
echo "series, and the tick is offered only where there is a series video to refuse.\n";
