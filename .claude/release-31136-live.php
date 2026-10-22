<?php
/**
 * 3.113.6 IN A BROWSER: THE RSVP FORM'S ENGLISH | ESPAÑOL TOGGLE.
 *
 *     php .claude/release-31136-live.php --run
 *     php .claude/release-31136-live.php --run --verbose   and print what was measured
 *     php .claude/release-31136-live.php --run --shots     and save a picture of each
 *
 * The real calendar.js and calendar.css, the modal opened from Register buttons
 * stamped as the server stamps them, the words from the real
 * SFAF_Messages::form_words(), and $.ajax replaced only to read what is posted.
 * At 1280px and in a 390px frame:
 *
 *   - the toggle sits in the modal's top padding: the modal is no taller with
 *     it than without it at 1280px, and 16px taller at most at 390px, where
 *     each half is 44px tall like every control on the form; nothing overlaps
 *     the heading
 *   - each half is at least 24px tall, 44px on a phone, and inside the window
 *   - a Spanish event opens in Spanish, an English one in English, with the
 *     pressed half saying which
 *   - a press changes the heading, the labels, the placeholders, the format
 *     choice, the questions' two labels and the button at once, and the
 *     questions themselves stay as typed
 *   - what is posted carries the language picked, and the answer is in it
 *
 * PLANTs 3L in plant-31136.php.
 */

require __DIR__ . '/mail-kit.php';
$root  = dirname( __DIR__ );
$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$jq = $root . '/.claude/vendor/jquery-3.7.1.min.js';
if ( ! file_exists( $jq ) ) { echo "jQuery is missing at .claude/vendor/jquery-3.7.1.min.js\n"; exit( 1 ); }
$words = SFAF_Messages::form_words();
$qs    = array( 'heading' => SFAF_Messages::label( 'q_heading', 'en' ), 'more' => SFAF_Messages::label( 'q_more', 'en' ), 'required' => SFAF_Messages::label( 'q_required', 'en' ), 'hybrid' => true,
    'items' => array( array( 'id' => 'q1', 'text' => 'Which group?', 'style' => 'one', 'required' => true, 'in_person' => false,
        'options' => array( array( 'id' => 'o1', 'text' => 'Youth', 'more' => true ), array( 'id' => 'o2', 'text' => 'Adults', 'more' => false ) ) ) ) );
$qattr = htmlspecialchars( json_encode( $qs ), ENT_QUOTES, 'UTF-8' );

$screen = '<button class="uc-rsvp-btn" id="btn-en" data-event-id="41" data-event-title="Support group" data-uc-lang="en" data-uc-formats="in_person,online" data-uc-full="online" data-uc-questions="' . $qattr . '">English event</button>'
    . '<button class="uc-rsvp-btn" id="btn-es" data-event-id="42" data-event-title="Grupo de apoyo" data-uc-lang="es" data-uc-formats="in_person" data-uc-full="">Spanish event</button>';

$probe = <<<'JS'
var out = { errors: window.__errs, steps: {} };
var sent = null, answer = null;
jQuery.ajax = function (opts) {
  sent = opts && opts.data ? opts.data : null;
  if (answer && opts.success) { opts.success(answer); }
  return { done: function () { return this; }, fail: function () { return this; } };
};
function q(s) { return document.querySelector(s); }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
/* What shows: 3.113.8 lays the other language's words, hidden, under each phrase. */
function seen(e) { var t = '', w = document.createTreeWalker(e, NodeFilter.SHOW_TEXT); while (w.nextNode()) { if (!w.currentNode.parentNode.closest('.uc-w2-off')) { t += w.currentNode.nodeValue; } } return t.replace(/\s+/g, ' ').trim(); }
function txt(s) { var e = q(s); return e ? seen(e) : null; }
function snap() {
  return {
    pressed: Array.prototype.map.call(document.querySelectorAll('[data-uc-lang-pick]'), function (b) { return b.getAttribute('data-uc-lang-pick') + ':' + b.getAttribute('aria-pressed'); }),
    heading: txt('#uc-rsvp-heading'), first: txt('label[for="uc-rsvp-first-name"]'), email: txt('label[for="uc-rsvp-email"]'),
    firstPh: (q('#uc-rsvp-first-name') || {}).placeholder, emailUse: txt('#uc-rsvp-email + .uc-rsvp-note'),
    submit: txt('#uc-rsvp-submit-btn'), cancel: txt('#uc-rsvp-cancel-btn'), optin: txt('#uc-rsvp-optin + span'),
    legend: txt('#uc-rsvp-format legend'), formats: Array.prototype.map.call(document.querySelectorAll('#uc-rsvp-format .uc-segment-label'), function (s) { return seen(s); }),
    qHeading: txt('#uc-rsvp-questions .uc-rsvp-q-heading'), qText: txt('#uc-rsvp-questions legend'), qMore: txt('#uc-rsvp-questions .uc-rsvp-q-more-label'),
    lang: (q('#uc-rsvp-modal .uc-rsvp-modal') || {}).lang
  };
}
function geometry() {
  var m = q('#uc-rsvp-modal .uc-rsvp-modal'), t = q('.uc-rsvp-lang'), h = q('#uc-rsvp-heading');
  var g = { modal: rect(m), toggle: rect(t), heading: rect(h), halves: Array.prototype.map.call(document.querySelectorAll('[data-uc-lang-pick]'), rect), vw: window.innerWidth };
  var with_ = m.scrollHeight;
  t.style.display = 'none';
  g.tallerBy = with_ - m.scrollHeight;
  t.style.display = '';
  var cs = getComputedStyle(q('[data-uc-lang-pick="en"]'));
  g.css = { fontSize: cs.fontSize, color: cs.color };
  return g;
}

if (location.hash === '#form') {
  jQuery('#btn-en').trigger('click');
  jQuery('[data-uc-lang-pick="es"]').trigger('click');
} else {
jQuery('#btn-es').trigger('click');
out.steps.esOpen = snap();
out.geo = geometry();
jQuery('[data-uc-lang-pick="en"]').trigger('click');
out.steps.esThenEn = snap();
closeAll();

jQuery('#btn-en').trigger('click');
out.steps.enOpen = snap();
jQuery('[data-uc-lang-pick="es"]').trigger('click');
out.steps.enThenEs = snap();
jQuery('#uc-rsvp-format input[value="in_person"]').prop('checked', true).trigger('change');
jQuery('#uc-q-q1-o2').prop('checked', true);
jQuery('#uc-rsvp-first-name').val('Ana');
jQuery('#uc-rsvp-email').val('ana@example.org');
answer = { success: true, first_name: 'Ana', no_email: false };
jQuery('#uc-rsvp-submit-btn').trigger('click');
out.posted = sent ? { lang: sent.lang, first: sent.first_name } : null;
out.success = txt('#uc-rsvp-success-msg');
out.successSub = txt('#uc-rsvp-success-sub');
out.inbox = txt('#uc-rsvp-success .uc-rsvp-inbox-note');
out.close = txt('#uc-rsvp-close-btn');
out.pageWidth = document.documentElement.scrollWidth;
window.parent.postMessage(JSON.stringify(out), '*');
}
function closeAll() { jQuery('#uc-rsvp-cancel-btn').trigger('click'); }
JS;

$caljs = file_get_contents( $root . '/public/js/calendar.js' );
$jqsrc = file_get_contents( $jq );
$data  = json_encode( array( 'ajaxUrl' => 'https://example.org/wp-admin/admin-ajax.php', 'nonce' => 'testnonce', 'rsvpWords' => $words ) );

$inner = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SFAF Calendar: the RSVP form's language toggle</title>
<!-- GENERATED BY .claude/release-31136-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/calendar.css">
<style> body { margin: 0; padding: 20px; background: #f4f5f7; } * { transition: none !important; animation: none !important; } </style>
</head>
<body>
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
$screen
<script>
$jqsrc
</script>
<script>var ucData = $data;</script>
<script>
$caljs
</script>
<script>
window.addEventListener('load', function () { setTimeout(function () {
$probe
}, 120); });
</script>
</body>
</html>
HTML;

$files = array();
foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
    $in = __DIR__ . '/release-31136-live-' . $wn . '.html';
    file_put_contents( $in, $inner );
    $files[ $wn ] = array( 'w' => $w, 'frame' => __DIR__ . '/release-31136-live-' . $wn . '-frame.html' );
    file_put_contents( $files[ $wn ]['frame'], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $in ) . '" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo "wrote 2 pages; --run drives them.\n"; exit( 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$es = $words['es']; $en = $words['en'];
foreach ( $files as $wn => $f ) {
    $url = 'file:///' . str_replace( '\\', '/', $f['frame'] );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=15000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$wn: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $argv, true ) ) { echo "$wn: " . json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    if ( in_array( '--shots', $argv, true ) ) {
        $form = __DIR__ . '/release-31136-shot-' . $wn . '-frame.html';
        file_put_contents( $form, str_replace( '.html"', '.html#form"', (string) file_get_contents( $f['frame'] ) ) );
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . $f['w'] . ',900 --virtual-time-budget=8000 --screenshot="' . __DIR__ . '/release-31136-shot-' . $wn . '.png" "file:///' . str_replace( '\\', '/', $form ) . '" 2>NUL' );
    }
    rl( empty( $r['errors'] ), "$wn threw: " . implode( '; ', (array) $r['errors'] ) );
    rl( (int) $r['pageWidth'] <= $f['w'], "$wn: the page scrolls sideways" );

    $g = $r['geo'];
    rl( (int) $g['tallerBy'] <= ( 'phone' === $wn ? 16 : 0 ), "3L: $wn: the toggle makes the form " . $g['tallerBy'] . 'px taller' );
    rl( $g['toggle']['top'] >= $g['modal']['top'] && $g['toggle']['bottom'] <= $g['heading']['top'], "3L: $wn: the toggle is not in the space above the heading: " . json_encode( array( $g['modal'], $g['toggle'], $g['heading'] ) ) );
    rl( $g['toggle']['right'] <= $g['modal']['right'] && $g['toggle']['right'] <= $g['vw'], "3L: $wn: the toggle leaves the modal: " . json_encode( array( $g['modal'], $g['toggle'] ) ) );
    $min = ( 'phone' === $wn ) ? 44 : 24;
    foreach ( (array) $g['halves'] as $h ) { rl( $h['h'] >= $min, "3L: $wn: a half of the toggle is " . $h['h'] . "px tall, under $min" ); }
    rl( '13px' === $g['css']['fontSize'], "3L: $wn: the toggle is not small: " . json_encode( $g['css'] ) );

    $s = $r['steps'];
    rl( array( 'en:false', 'es:true' ) === $s['esOpen']['pressed'] && 'es' === $s['esOpen']['lang'], "3L: $wn: a Spanish event does not open in Spanish: " . json_encode( $s['esOpen']['pressed'] ) );
    rl( $es['heading'] === $s['esOpen']['heading'] && $es['first'] === $s['esOpen']['first'] && $es['submit'] === $s['esOpen']['submit'], "$wn: the Spanish form's words: " . json_encode( $s['esOpen'], JSON_UNESCAPED_UNICODE ) );
    rl( array( 'en:true', 'es:false' ) === $s['esThenEn']['pressed'] && $en['heading'] === $s['esThenEn']['heading'] && $en['email'] === $s['esThenEn']['email'] && $en['first_ph'] === $s['esThenEn']['firstPh'] && $en['email_use'] === $s['esThenEn']['emailUse'] && $en['cancel'] === $s['esThenEn']['cancel'],
        "3L: $wn: English pressed does not change the words at once: " . json_encode( $s['esThenEn'], JSON_UNESCAPED_UNICODE ) );
    rl( array( 'en:true', 'es:false' ) === $s['enOpen']['pressed'] && $en['heading'] === $s['enOpen']['heading'], "$wn: an English event does not open in English" );
    $t = $s['enThenEs'];
    rl( $es['heading'] === $t['heading'] && $es['first'] === $t['first'] && $es['first_ph'] === $t['firstPh'] && $es['optin'] === $t['optin'] && $es['submit'] === $t['submit'], "3L: $wn: Español pressed does not change the form's words: " . json_encode( $t, JSON_UNESCAPED_UNICODE ) );
    rl( $es['attend'] === $t['legend'] && array( $es['in_person'], $es['online'] . ' ' . $es['waitlist_tag'] ) === $t['formats'], "$wn: the format choice did not follow: " . json_encode( array( $t['legend'], $t['formats'] ), JSON_UNESCAPED_UNICODE ) );
    rl( $es['q_heading'] === $t['qHeading'] && $es['q_more'] === $t['qMore'] && 'Which group? *' === $t['qText'], "$wn: the questions' labels did not follow, or their text did not stay as typed: " . json_encode( array( $t['qHeading'], $t['qMore'], $t['qText'] ), JSON_UNESCAPED_UNICODE ) );
    rl( array( 'lang' => 'es', 'first' => 'Ana' ) === $r['posted'], "3L: $wn: the form did not post the language picked: " . json_encode( $r['posted'] ) );
    rl( array( str_replace( '%s', 'Ana', $es['registered_name'] ), $es['see_you'], $es['inbox'], $es['close'] ) === array( $r['success'], $r['successSub'], $r['inbox'], $r['close'] ),
        "3L: $wn: the answer is not all in Spanish: " . json_encode( array( $r['success'], $r['successSub'], $r['inbox'], $r['close'] ), JSON_UNESCAPED_UNICODE ) );
}

echo "\n3.113.6: the RSVP form's English | Español toggle, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "The toggle sits above the heading, adding no height on a desktop and 16px at most on a phone; it opens on the event's language, switches every word of the form\nat once, keeps the questions as typed, and posts the choice, at 1280px and 390px.\n";
exit( 0 );
