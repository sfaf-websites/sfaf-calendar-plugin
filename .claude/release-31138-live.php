<?php
/**
 * 3.113.8 IN A BROWSER: THE RSVP FORM KEEPS ONE HEIGHT IN BOTH LANGUAGES.
 *
 *     php .claude/release-31138-live.php --run
 *     php .claude/release-31138-live.php --run --verbose   and print what was measured
 *     php .claude/release-31138-live.php --run --shots     and save a picture of each
 *
 * The real calendar.js and calendar.css, the modal opened from Register buttons
 * stamped as the server stamps them, the words from the real
 * SFAF_Messages::form_words(). Two forms: a plain one, and the busiest one (a
 * hybrid event with one format full, a question with a "tell us more", the text
 * opt-in shown). Each opened in English and in Spanish and switched both ways,
 * at 1280px and in 390px, 360px and 320px frames:
 *
 *   - the form view is the same height in English and in Spanish, to the pixel
 *   - Register Now and Cancel sit at the same place in both
 *   - what is visible is the language picked, and only that: the other
 *     language's words are there to hold the room, hidden and out of the
 *     accessibility tree
 *   - nothing scrolls sideways
 *
 * PLANT 1 in plant-31138.php.
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

$screen = '<button class="uc-rsvp-btn" id="btn-plain" data-event-id="41" data-event-title="Support group" data-uc-lang="en" data-uc-formats="in_person" data-uc-full="">Plain</button>'
    . '<button class="uc-rsvp-btn" id="btn-busy" data-event-id="42" data-event-title="Grupo de apoyo para personas mayores de cincuenta" data-uc-lang="es" data-uc-formats="in_person,online" data-uc-full="online" data-uc-text-label="Text me about this event" data-uc-questions="' . $qattr . '">Busy</button>';

$probe = <<<'JS'
var out = { errors: window.__errs, forms: {} };
function q(s) { return document.querySelector(s); }
function top_(s) { var e = q(s); return e ? Math.round(e.getBoundingClientRect().top - q('#uc-rsvp-modal .uc-rsvp-modal').getBoundingClientRect().top) : null; }
/* What a person sees: every text node not inside a hidden twin. */
function seen(el) {
  if (!el) { return null; }
  var t = '', w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  while (w.nextNode()) { if (!w.currentNode.parentNode.closest('.uc-w2-off')) { t += w.currentNode.nodeValue; } }
  return t.replace(/\s+/g, ' ').trim();
}
function hiddenOk() {
  var bad = [];
  document.querySelectorAll('#uc-rsvp-modal .uc-w2-off').forEach(function (s) {
    var cs = getComputedStyle(s);
    if (cs.visibility !== 'hidden' || s.getAttribute('aria-hidden') !== 'true') { bad.push(s.textContent); }
  });
  return bad;
}
function measure() {
  var v = q('#uc-rsvp-modal .uc-rsvp-form-view');
  return { view: Math.round(v.getBoundingClientRect().height), submit: top_('#uc-rsvp-submit-btn'), cancel: top_('#uc-rsvp-cancel-btn'),
    heading: seen(q('#uc-rsvp-heading')), first: seen(q('label[for="uc-rsvp-first-name"]')), emailUse: seen(q('#uc-rsvp-email + .uc-rsvp-note')),
    submitText: seen(q('#uc-rsvp-submit-btn')), optin: seen(q('#uc-rsvp-optin + span')), legend: seen(q('#uc-rsvp-format legend')),
    lang: (q('#uc-rsvp-modal .uc-rsvp-modal') || {}).lang, hiddenBad: hiddenOk() };
}
function pick(l) { jQuery('[data-uc-lang-pick="' + l + '"]').trigger('click'); }
['plain', 'busy'].forEach(function (k) {
  jQuery('#btn-' + k).trigger('click');
  if (k === 'busy') { jQuery('#uc-rsvp-text-wrap').removeAttr('hidden'); jQuery('#uc-q-q1-o1').prop('checked', true).trigger('change'); }
  var r = {};
  pick('en'); r.en = measure();
  pick('es'); r.es = measure();
  pick('en'); r.enAgain = measure();
  out.forms[k] = r;
  if (location.hash !== '#' + k) { jQuery('#uc-rsvp-cancel-btn').trigger('click'); }
});
out.pageWidth = document.documentElement.scrollWidth;
window.parent.postMessage(JSON.stringify(out), '*');
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
<title>SFAF Calendar: the RSVP form in two languages</title>
<!-- GENERATED BY .claude/release-31138-live.php. Do not edit; regenerate. -->
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

$in = __DIR__ . '/release-31138-live.html';
file_put_contents( $in, $inner );
$widths = array( 'desktop' => 1280, 'phone' => 390, 'phone360' => 360, 'phone320' => 320 );
$frames = array();
foreach ( $widths as $wn => $w ) {
    $frames[ $wn ] = __DIR__ . '/release-31138-live-' . $wn . '-frame.html';
    file_put_contents( $frames[ $wn ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $in ) . '" style="display:block;width:' . $w . 'px;height:1400px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo "wrote the pages; --run drives them.\n"; exit( 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$es = $words['es']; $en = $words['en'];
foreach ( $widths as $wn => $w ) {
    $url = 'file:///' . str_replace( '\\', '/', $frames[ $wn ] );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1500 --virtual-time-budget=15000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$wn: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $argv, true ) ) { echo "$wn: " . json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    if ( in_array( '--shots', $argv, true ) ) {
        foreach ( array( 'plain', 'busy' ) as $k ) {
            $shot = __DIR__ . '/release-31138-shot-' . $wn . '-' . $k . '-frame.html';
            file_put_contents( $shot, str_replace( '.html"', '.html#' . $k . '"', (string) file_get_contents( $frames[ $wn ] ) ) );
            shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . $w . ',1400 --virtual-time-budget=8000 --screenshot="' . __DIR__ . '/release-31138-shot-' . $wn . '-' . $k . '.png" "file:///' . str_replace( '\\', '/', $shot ) . '" 2>NUL' );
        }
    }
    rl( empty( $r['errors'] ), "$wn threw: " . implode( '; ', (array) $r['errors'] ) );
    rl( (int) $r['pageWidth'] <= $w, "$wn: the page scrolls sideways" );

    foreach ( array( 'plain', 'busy' ) as $k ) {
        $f = $r['forms'][ $k ];
        rl( $f['en']['view'] === $f['es']['view'] && $f['es']['view'] === $f['enAgain']['view'],
            "1: $wn $k: the form is " . $f['en']['view'] . 'px in English and ' . $f['es']['view'] . 'px in Spanish' );
        rl( $f['en']['submit'] === $f['es']['submit'] && $f['en']['cancel'] === $f['es']['cancel'],
            "1: $wn $k: Register Now or Cancel moves when the language changes: " . json_encode( array( $f['en']['submit'], $f['es']['submit'], $f['en']['cancel'], $f['es']['cancel'] ) ) );
        foreach ( array( 'en', 'es', 'enAgain' ) as $step ) {
            rl( empty( $f[ $step ]['hiddenBad'] ), "1: $wn $k $step: the other language's words can be seen or heard: " . json_encode( $f[ $step ]['hiddenBad'], JSON_UNESCAPED_UNICODE ) );
        }
        $want = array( 'en' => $en, 'es' => $es, 'enAgain' => $en );
        foreach ( $want as $step => $set ) {
            $s = $f[ $step ];
            rl( $set['heading'] === $s['heading'] && $set['first'] === $s['first'] && $set['email_use'] === $s['emailUse'] && $set['submit'] === $s['submitText'] && $set['optin'] === $s['optin'],
                "$wn $k $step: what shows is not the language picked, or not only it: " . json_encode( $s, JSON_UNESCAPED_UNICODE ) );
        }
        if ( 'busy' === $k ) {
            rl( $es['attend'] === $f['es']['legend'] && $en['attend'] === $f['en']['legend'], "$wn busy: the format question did not switch: " . json_encode( array( $f['en']['legend'], $f['es']['legend'] ), JSON_UNESCAPED_UNICODE ) );
        }
    }
}

echo "\n3.113.8: the RSVP form keeps one height in English and Spanish, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "A plain form and a hybrid form with a question are the same height in English and Spanish, with Register Now and Cancel in the same place,\nand only the language picked can be seen, at 1280px, 390px, 360px and 320px.\n";
exit( 0 );
