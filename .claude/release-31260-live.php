<?php
/**
 * 3.126.0 IN A BROWSER: THE RSVP FORM'S LANGUAGE PILL AND ITS STARTING LANGUAGE.
 *
 *     php .claude/release-31260-live.php --run
 *     php .claude/release-31260-live.php --run --verbose   and print what was measured
 *     php .claude/release-31260-live.php --run --shots     and save a picture of each
 *
 * The real calendar.js and calendar.css, the modal opened from Register buttons
 * stamped as the server stamps them, and $.ajax replaced only to read what is
 * posted. navigator.languages is a getter the probe moves between opens. At
 * 1280px and in a 390px frame:
 *
 *   - the form is exactly as tall as 3.125.0's (its calendar.js from git, same
 *     page), and the toggle sits above the heading inside the modal
 *   - each button is at least 24px tall, 44px on a phone; the visible pill is
 *     24px on a desktop and 28px on a phone, so the toggle stays compact
 *   - the chosen button is white on #0E7680, the other #0E7680 on white, and
 *     a 14px globe comes first
 *   - en-US and en-GB open both events in English, es-MX and es open both in
 *     Spanish, fr-FR and pt-BR open each in the event's language
 *   - a visitor who switches posts the language they switched to
 *
 * PLANTs in plant-31260.php.
 */

require __DIR__ . '/mail-kit.php';
$root  = dirname( __DIR__ );
$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$jq = $root . '/.claude/vendor/jquery-3.7.1.min.js';
if ( ! file_exists( $jq ) ) { echo "jQuery is missing at .claude/vendor/jquery-3.7.1.min.js\n"; exit( 1 ); }
$words = SFAF_Messages::form_words();

$screen = '<button class="uc-rsvp-btn" id="btn-en" data-event-id="41" data-event-title="Support group" data-uc-lang="en" data-uc-formats="in_person" data-uc-full="">English event</button>'
    . '<button class="uc-rsvp-btn" id="btn-es" data-event-id="42" data-event-title="Grupo de apoyo" data-uc-lang="es" data-uc-formats="in_person" data-uc-full="">Spanish event</button>';

$probe = <<<'JS'
var out = { errors: window.__errs, opens: {} };
var sent = null, answer = null;
jQuery.ajax = function (opts) {
  sent = opts && opts.data ? opts.data : null;
  if (answer && opts.success) { opts.success(answer); }
  return { done: function () { return this; }, fail: function () { return this; } };
};
function q(s) { return document.querySelector(s); }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function closeAll() { jQuery('#uc-rsvp-cancel-btn').trigger('click'); }
function pressed() { return Array.prototype.map.call(document.querySelectorAll('[data-uc-lang-pick]'), function (b) { return b.getAttribute('data-uc-lang-pick') + ':' + b.getAttribute('aria-pressed'); }).join(' '); }
if (location.hash === '#shot') {
  window.__nav = 'fr-FR';
  jQuery('#btn-es').trigger('click');
} else {
window.__nav = 'fr-FR';
jQuery('#btn-en').trigger('click');
var m = q('#uc-rsvp-modal .uc-rsvp-modal');
out.height = m.scrollHeight;
out.isNew = !!q('.uc-rsvp-lang-pill');
if (out.isNew) {
  var pill = q('.uc-rsvp-lang-pill'), before = getComputedStyle(pill, '::before');
  var on = q('[data-uc-lang-pick="en"]'), off = q('[data-uc-lang-pick="es"]'), con = getComputedStyle(on), coff = getComputedStyle(off);
  out.geo = { modal: rect(m), toggle: rect(q('.uc-rsvp-lang')), heading: rect(q('#uc-rsvp-heading')), globe: rect(q('.uc-rsvp-lang-globe')), pill: rect(pill),
    buttons: [rect(on), rect(off)], vw: window.innerWidth,
    outline: { top: before.top, bottom: before.bottom, border: before.borderTopWidth + ' ' + before.borderTopStyle + ' ' + before.borderTopColor, radius: before.borderTopLeftRadius },
    on: { bg: con.backgroundColor, color: con.color, clip: con.backgroundClip, size: con.fontSize }, off: { bg: coff.backgroundColor, color: coff.color } };
  closeAll();
  ['en-US', 'en-GB', 'es-MX', 'es', 'fr-FR', 'pt-BR'].forEach(function (nav) {
    window.__nav = nav;
    out.opens[nav] = {};
    ['en', 'es'].forEach(function (ev) {
      jQuery('#btn-' + ev).trigger('click');
      out.opens[nav][ev] = pressed() + ' ' + (q('#uc-rsvp-modal .uc-rsvp-modal') || {}).lang;
      closeAll();
    });
  });
  window.__nav = 'es-MX';
  jQuery('#btn-en').trigger('click');
  out.openedAs = pressed();
  jQuery('[data-uc-lang-pick="en"]').trigger('click');
  jQuery('#uc-rsvp-first-name').val('Ana');
  jQuery('#uc-rsvp-email').val('ana@example.org');
  answer = { success: true, first_name: 'Ana', no_email: false };
  jQuery('#uc-rsvp-submit-btn').trigger('click');
  out.posted = sent ? sent.lang : null;
}
out.pageWidth = document.documentElement.scrollWidth;
window.parent.postMessage(JSON.stringify(out), '*');
}
JS;

$scripts = array( 'new' => file_get_contents( $root . '/public/js/calendar.js' ), 'old' => (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show 2740e10:public/js/calendar.js' ) );
if ( strlen( $scripts['old'] ) < 1000 ) { echo "could not read 3.125.0's calendar.js from git\n"; exit( 1 ); }
$jqsrc = file_get_contents( $jq );
$data  = json_encode( array( 'ajaxUrl' => 'https://example.org/wp-admin/admin-ajax.php', 'nonce' => 'testnonce', 'rsvpWords' => $words ) );

$files = array();
foreach ( $scripts as $sn => $caljs ) {
    $inner = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SFAF Calendar: the RSVP form's language pill</title>
<!-- GENERATED BY .claude/release-31260-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/calendar.css">
<style> body { margin: 0; padding: 20px; background: #f4f5f7; } * { transition: none !important; animation: none !important; } </style>
</head>
<body>
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
<script>window.__nav = 'fr-FR'; Object.defineProperty(navigator, 'languages', { get: function () { return [window.__nav]; } }); Object.defineProperty(navigator, 'language', { get: function () { return window.__nav; } });</script>
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
    foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
        $in = __DIR__ . "/release-31260-live-$sn-$wn.html";
        file_put_contents( $in, $inner );
        $frame = __DIR__ . "/release-31260-live-$sn-$wn-frame.html";
        file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
            . '<iframe src="' . basename( $in ) . '" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe>'
            . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
        $files[ "$sn-$wn" ] = array( 'w' => $w, 'wn' => $wn, 'sn' => $sn, 'frame' => $frame );
    }
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$r = array();
foreach ( $files as $k => $f ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $f['frame'] ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$k: Chrome returned no probe block" ); continue; }
    $r[ $k ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $argv, true ) ) { echo "$k: " . json_encode( $r[ $k ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    if ( 'new' === $f['sn'] && in_array( '--shots', $argv, true ) ) {
        $shot = __DIR__ . "/release-31260-shot-{$f['wn']}-frame.html";
        file_put_contents( $shot, str_replace( '.html"', '.html#shot"', (string) file_get_contents( $f['frame'] ) ) );
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . $f['w'] . ',900 --virtual-time-budget=8000 --screenshot="' . __DIR__ . "/screens/rsvp-lang-pill-{$f['wn']}.png" . '" "file:///' . str_replace( '\\', '/', $shot ) . '" 2>NUL' );
    }
    rl( empty( $r[ $k ]['errors'] ), "$k threw: " . implode( '; ', (array) $r[ $k ]['errors'] ) );
}

foreach ( array( 'desktop', 'phone' ) as $wn ) {
    if ( ! isset( $r[ "new-$wn" ], $r[ "old-$wn" ] ) ) { continue; }
    $n = $r[ "new-$wn" ];
    $w = $files[ "new-$wn" ]['w'];
    rl( true === $n['isNew'] && false === $r[ "old-$wn" ]['isNew'], "$wn: the pages did not load the two scripts they name" );
    rl( (int) $n['height'] === (int) $r[ "old-$wn" ]['height'], "3P: $wn: the form is " . $n['height'] . 'px tall, 3.125.0\'s is ' . $r[ "old-$wn" ]['height'] . 'px' );
    rl( (int) $n['pageWidth'] <= $w, "$wn: the page scrolls sideways" );
    $g = $n['geo'];
    rl( $g['toggle']['top'] >= $g['modal']['top'] && $g['toggle']['bottom'] <= $g['heading']['top'], "3P: $wn: the toggle is not in the space above the heading: " . json_encode( array( $g['modal'], $g['toggle'], $g['heading'] ) ) );
    rl( $g['toggle']['right'] <= $g['modal']['right'] && $g['toggle']['left'] >= $g['modal']['left'], "$wn: the toggle leaves the modal: " . json_encode( array( $g['modal'], $g['toggle'] ) ) );
    $min = ( 'phone' === $wn ) ? 44 : 24;
    foreach ( $g['buttons'] as $b ) { rl( $b['h'] >= $min, "3P: $wn: a button is " . $b['h'] . "px tall, under $min" ); }
    $seen = $g['pill']['h'] - (int) $g['outline']['top'] - (int) $g['outline']['bottom'];
    rl( $seen === ( 'phone' === $wn ? 28 : 24 ), "3P: $wn: the visible pill is {$seen}px tall: " . json_encode( $g['outline'] ) );
    rl( '1px solid rgb(14, 118, 128)' === $g['outline']['border'] && (int) $g['outline']['radius'] >= 99, "3P: $wn: the pill is not one rounded #0E7680 outline: " . json_encode( $g['outline'] ) );
    rl( 'rgb(14, 118, 128)' === $g['on']['bg'] && 'rgb(255, 255, 255)' === $g['on']['color'], "3P: $wn: the chosen language is not white on #0E7680: " . json_encode( $g['on'] ) );
    rl( 'rgba(0, 0, 0, 0)' === $g['off']['bg'] && 'rgb(14, 118, 128)' === $g['off']['color'], "3P: $wn: the other language is not plain #0E7680: " . json_encode( $g['off'] ) );
    rl( 'padding-box' === $g['on']['clip'], "3P: $wn: the fill is not clipped to the pill: " . json_encode( $g['on'] ) );
    rl( 14 === $g['globe']['w'] && 14 === $g['globe']['h'] && $g['globe']['right'] <= $g['pill']['left'], "3P: $wn: no 14px globe in front of the pill: " . json_encode( array( $g['globe'], $g['pill'] ) ) );
    rl( '13px' === $g['on']['size'], "$wn: the toggle is not 13px: " . json_encode( $g['on'] ) );

    $want = array(
        'en-US' => array( 'en' => 'en:true es:false en', 'es' => 'en:true es:false en' ),
        'en-GB' => array( 'en' => 'en:true es:false en', 'es' => 'en:true es:false en' ),
        'es-MX' => array( 'en' => 'en:false es:true es', 'es' => 'en:false es:true es' ),
        'es'    => array( 'en' => 'en:false es:true es', 'es' => 'en:false es:true es' ),
        'fr-FR' => array( 'en' => 'en:true es:false en', 'es' => 'en:false es:true es' ),
        'pt-BR' => array( 'en' => 'en:true es:false en', 'es' => 'en:false es:true es' ),
    );
    rl( $want === $n['opens'], "4S: $wn: the starting language: " . json_encode( $n['opens'] ) );
    rl( 'en:false es:true' === $n['openedAs'] && 'en' === $n['posted'], "4S: $wn: switching back to English did not post English: " . json_encode( array( $n['openedAs'], $n['posted'] ) ) );
}

echo "\n3.126.0: the RSVP form's language pill and its starting language, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "The pill is a globe and one #0E7680 outline, the chosen language filled, 24px tall on a desktop and 28px on a phone with 44px\nbuttons; the form is 3.125.0's height; it opens on the browser's English or Spanish, the event's language otherwise, and posts\nwhat the visitor picks, at 1280px and 390px.\n";
exit( 0 );
