<?php
/**
 * 3.124.0 IN A BROWSER: STAY IN TOUCH ON THE RSVP FORM, AND THE EDITORS' CARD.
 *
 *     php .claude/release-31240-live.php --run
 *     php .claude/release-31240-live.php --run --verbose   and print what was measured
 *     php .claude/release-31240-live.php --shots           --run, and pictures in .claude/screens/
 *
 * THE FORM. The real calendar.js and calendar.css and the words from the real
 * SFAF_Messages::form_words(), opened from Register buttons stamped as the
 * server stamps them: an event with no team, one team, and two. Beside it,
 * 3.123.0's calendar.js (git 44988e8) on the same button, so "the form must
 * not grow noticeably" is a number. At 1280px, 390px and 320px:
 *
 *   A  Stay in touch at the end: the SFAF box, then one per team, in the
 *      words given; Mark's line under the email field; every box unticked
 *      when the form opens, and again after one was ticked and it reopened
 *   B  English and Spanish: the legend, both boxes and the email line switch,
 *      and the form is the same height in both
 *   C  the form is at most 24px taller than 3.123.0's with no team, and each
 *      team adds one row: a line, or two where its Spanish wraps, since the
 *      form keeps the room of the longer language (3.113.8); nothing scrolls
 *      sideways
 *   D  the post: optin and the ticked teams' ids, and nothing else
 *
 * THE CARD. Edit event through wp-kit.php with the real portal.css and
 * portal.js, with two connections, at 1280px and 390px:
 *
 *   E  shown while a connected organizer is ticked, one block per team; gone
 *      when it is unticked, and back when it is ticked again
 *   F  a tag typed and Added becomes a ticked box and a chip, and the box
 *      for it is cleared; the search narrows the boxes; × takes a chip off
 *
 * PLANTs in plant-31240.php.
 */

require __DIR__ . '/wp-kit.php';
$root  = dirname( __DIR__ );
$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
$args  = array_slice( $argv, 1 );
$shots = in_array( '--shots', $args, true );
$run   = $shots || in_array( '--run', $args, true );

/* ======================================================================
 * THE FORM
 * ==================================================================== */
$jq = $root . '/.claude/vendor/jquery-3.7.1.min.js';
if ( ! file_exists( $jq ) ) { echo "jQuery is missing at .claude/vendor/jquery-3.7.1.min.js\n"; exit( 1 ); }
$words = SFAF_Messages::form_words();
$one   = htmlspecialchars( json_encode( array( array( 'id' => 'ca1', 'name' => 'Aging Services' ) ) ), ENT_QUOTES, 'UTF-8' );
$two   = htmlspecialchars( json_encode( array( array( 'id' => 'ca1', 'name' => 'Aging Services' ), array( 'id' => 'cb2', 'name' => 'Black Health Initiative' ) ) ), ENT_QUOTES, 'UTF-8' );
$screen = '<button class="uc-rsvp-btn" id="btn-none" data-event-id="41" data-event-title="Support group" data-uc-lang="en" data-uc-formats="in_person" data-uc-full="">None</button>'
    . '<button class="uc-rsvp-btn" id="btn-one" data-event-id="42" data-event-title="Lunch and learn" data-uc-lang="en" data-uc-formats="in_person" data-uc-full="" data-uc-lists="' . $one . '">One</button>'
    . '<button class="uc-rsvp-btn" id="btn-two" data-event-id="43" data-event-title="Health fair" data-uc-lang="es" data-uc-formats="in_person" data-uc-full="" data-uc-lists="' . $two . '">Two</button>';

$probe = <<<'JS'
var out = { errors: window.__errs, forms: {}, posted: null };
function q(s) { return document.querySelector(s); }
function seen(el) {
  if (!el) { return null; }
  var t = '', w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  while (w.nextNode()) { if (!w.currentNode.parentNode.closest('.uc-w2-off')) { t += w.currentNode.nodeValue; } }
  return t.replace(/\s+/g, ' ').trim();
}
function height() { var v = q('#uc-rsvp-modal .uc-rsvp-form-view'); return v ? Math.round(v.getBoundingClientRect().height) : null; }
function boxes() { return Array.prototype.map.call(document.querySelectorAll('#uc-rsvp-modal input[type="checkbox"]'), function (b) { return { id: b.id || b.value, on: b.checked }; }); }
function measure() {
  var stay = q('#uc-rsvp-stay');
  return { height: height(),
    legend: seen(q('#uc-rsvp-stay legend')), sfaf: seen(q('#uc-rsvp-optin + span')),
    teams: Array.prototype.map.call(document.querySelectorAll('#uc-rsvp-lists label'), function (l) { return seen(l); }),
    emailUse: seen(q('#uc-rsvp-email + .uc-rsvp-note')),
    stayAfterPhone: !!stay && !!q('#uc-rsvp-phone') && (q('#uc-rsvp-phone').compareDocumentPosition(stay) & Node.DOCUMENT_POSITION_FOLLOWING) > 0,
    stayBeforeSubmit: !!stay && (stay.compareDocumentPosition(q('#uc-rsvp-submit-btn')) & Node.DOCUMENT_POSITION_FOLLOWING) > 0,
    ticked: boxes().filter(function (b) { return b.on; }).map(function (b) { return b.id; }),
    teamLine: (function () { var l = document.querySelectorAll('#uc-rsvp-lists label'); return l.length ? Math.round(l[0].getBoundingClientRect().height) : 0; })() };
}
function pick(l) { jQuery('[data-uc-lang-pick="' + l + '"]').trigger('click'); }
var NEW = !!window.__new;
['none', 'one', 'two'].forEach(function (k) {
  jQuery('#btn-' + k).trigger('click');
  var r = {};
  if (NEW) { pick('en'); }
  r.en = measure();
  if (NEW) {
    pick('es'); r.es = measure(); pick('en');
    /* Tick everything, close, reopen: nothing carries over. */
    jQuery('#uc-rsvp-modal input[type="checkbox"]').prop('checked', true);
    jQuery('#uc-rsvp-cancel-btn').trigger('click');
    jQuery('#btn-' + k).trigger('click');
    r.reopened = measure();
  }
  out.forms[k] = r;
  jQuery('#uc-rsvp-cancel-btn').trigger('click');
});
if (NEW) {
  /* What the form posts: the SFAF box and the second team ticked. */
  jQuery.ajax = function (o) { out.posted = o.data; return { done: function () {}, fail: function () {} }; };
  jQuery('#btn-two').trigger('click');
  pick('en');
  jQuery('#uc-rsvp-first-name').val('Ana'); jQuery('#uc-rsvp-email').val('ana@example.org');
  jQuery('#uc-rsvp-optin').prop('checked', true);
  jQuery('#uc-rsvp-lists input[value="cb2"]').prop('checked', true);
  jQuery('#uc-rsvp-submit-btn').trigger('click');
}
out.pageWidth = document.documentElement.scrollWidth;
window.parent.postMessage(JSON.stringify(out), '*');
JS;

$jqsrc  = file_get_contents( $jq );
$data   = json_encode( array( 'ajaxUrl' => 'https://example.org/wp-admin/admin-ajax.php', 'nonce' => 'testnonce', 'rsvpWords' => $words ) );
$scripts = array( 'new' => file_get_contents( $root . '/public/js/calendar.js' ), 'old' => (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show 44988e8:public/js/calendar.js' ) );
rl( strlen( $scripts['old'] ) > 10000, 'could not read 3.123.0\'s calendar.js from git (44988e8)' );
$pages = array();
foreach ( $scripts as $which => $caljs ) {
    $flag = 'new' === $which ? 'true' : 'false';
    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SFAF Calendar: Stay in touch</title>
<!-- GENERATED BY .claude/release-31240-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/calendar.css">
<style> body { margin: 0; padding: 20px; background: #f4f5f7; } * { transition: none !important; animation: none !important; } </style>
</head>
<body>
<script>window.__errs=[];window.__new=$flag;window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
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
    $pages[ $which ] = __DIR__ . '/release-31240-live-form-' . $which . '.html';
    file_put_contents( $pages[ $which ], $html );
}
$widths = array( 'desktop' => 1280, 'phone' => 390, 'phone320' => 320 );
$frames = array();
foreach ( $pages as $which => $page ) {
    foreach ( $widths as $wn => $w ) {
        $frames[ "$which-$wn" ] = __DIR__ . "/release-31240-live-form-$which-$wn-frame.html";
        file_put_contents( $frames[ "$which-$wn" ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
            . '<iframe src="' . basename( $page ) . '" style="display:block;width:' . $w . 'px;height:1400px;border:0"></iframe>'
            . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
    }
}

/* ======================================================================
 * THE CARD
 * ==================================================================== */
$GLOBALS['kit_query']      = function ( $a ) { return array(); };
$GLOBALS['kit_organizers'] = true;   // 11 Alpha, 12 Beta
update_option( SFAF_Mailchimp::OPTION, array(
    'ca1' => array( 'name' => 'Aging Services', 'audience' => 'aud1', 'audience_name' => 'Aging Services list', 'organizers' => array( 11 ), 'tags' => array( 'Seniors' ) ),
    'cb2' => array( 'name' => 'Black Health Initiative', 'audience' => 'aud9', 'audience_name' => 'Black Health list', 'organizers' => array( 12 ), 'tags' => array() ),
) );
$GLOBALS['kit_trans']['sfaf_mc_meta_ca1'] = array( 'audiences' => array( array( 'id' => 'aud1', 'name' => 'Aging Services list' ), array( 'id' => 'aud2', 'name' => 'Aging Services volunteers' ) ), 'tags' => array( 'Lunch club', 'Seniors', 'Volunteers' ), 'at' => time(), 'error' => '' );
$GLOBALS['kit_trans']['sfaf_mc_meta_cb2'] = array( 'audiences' => array( array( 'id' => 'aud9', 'name' => 'Black Health list' ) ), 'tags' => array( 'Health fair' ), 'at' => time(), 'error' => '' );
$eid = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Lunch and learn', 'post_author' => 1 ) );
foreach ( array( '_uc_event_date' => '2027-01-14', '_uc_start_time' => '12:00', '_uc_end_time' => '13:00', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $eid, $k, $v ); }
$GLOBALS['kit_terms'][ $eid ]['uc_organizer'] = array( 11 );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
ob_start();
try { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $eid ) ); } catch ( Throwable $t ) { echo 'THREW ' . $t->getMessage(); }
$edit = (string) ob_get_clean();
rl( false === strpos( $edit, 'THREW' ), 'the editor could not be rendered: ' . substr( $edit, 0, 200 ) );

$card_probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function seen(el) { return !!el && el.getClientRects().length > 0; }
function state() {
  var card = q('[data-uc-card="mailchimp"]');
  return { card: seen(card), a: seen(q('[data-uc-mc-conn="ca1"]')), b: seen(q('[data-uc-mc-conn="cb2"]')) };
}
function org(v, on) { var b = q('input[name="organizer[]"][value="' + v + '"]'); b.checked = on; b.dispatchEvent(new Event('change', { bubbles: true })); }
window.addEventListener('load', function () { setTimeout(function () {
  out.start = state();
  org(11, false); out.none = state();
  org(12, true); out.beta = state();
  org(11, true); out.both = state();
  var a = q('[data-uc-mc-conn="ca1"]');
  var tags = q('[data-uc-mc-tags]', a);
  out.sourceFolded = !seen(q('[data-uc-mc-source]', tags));
  q('[data-uc-mc-toggle]', tags).click();
  out.sourceOpen = seen(q('[data-uc-mc-source]', tags));
  var input = q('[data-uc-mc-new]', tags);
  input.value = '  Fall   2026 ';
  input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
  out.afterEnter = { box: !!q('input[name="mc[ca1][tags][]"][value="Fall 2026"]:checked', tags), input: input.value,
    chips: Array.prototype.map.call(tags.querySelectorAll('.uc-chip-text'), function (c) { return c.textContent; }) };
  input.value = 'lunch CLUB';
  q('[data-uc-mc-new-add]', tags).click();
  out.afterDup = { boxes: tags.querySelectorAll('input[name="mc[ca1][tags][]"]').length,
    chips: Array.prototype.map.call(tags.querySelectorAll('.uc-chip-text'), function (c) { return c.textContent; }) };
  var s = q('[data-uc-mc-search]', tags);
  s.value = 'vol'; s.dispatchEvent(new Event('input', { bubbles: true }));
  out.searched = Array.prototype.filter.call(tags.querySelectorAll('[data-uc-mc-boxes] label'), seen).map(function (l) { return l.textContent.trim(); });
  s.value = ''; s.dispatchEvent(new Event('input', { bubbles: true }));
  q('.uc-chip-remove', tags).click();
  out.afterRemove = Array.prototype.map.call(tags.querySelectorAll('.uc-chip-text'), function (c) { return c.textContent; });
  out.posted = Array.prototype.map.call(document.querySelectorAll('input[name="mc[ca1][tags][]"]:checked'), function (b) { return b.value; });
  out.pageWidth = document.documentElement.scrollWidth;
  window.parent.postMessage(JSON.stringify(out), '*');
}, 400); });
})();
JS;
$card_page = __DIR__ . '/release-31240-live-card.html';
file_put_contents( $card_page, preg_replace( '#</body>#i', '<script>' . $card_probe . '</script></body>', $edit, 1 ) );
$card_frames = array();
foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
    $card_frames[ $wn ] = __DIR__ . '/release-31240-live-card-' . $wn . '-frame.html';
    file_put_contents( $card_frames[ $wn ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $card_page ) . '" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}

if ( ! $run ) { echo "wrote the pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
function rl_chrome( $frame, $w, $h ) {
    global $chrome;
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w + 20 ) . ',' . $h . ' --virtual-time-budget=12000 --dump-dom "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
    return preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ? json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true ) : null;
}

$en = $words['en']; $es = $words['es'];
foreach ( $widths as $wn => $w ) {
    $new = rl_chrome( $frames[ "new-$wn" ], $w, 1500 );
    $old = rl_chrome( $frames[ "old-$wn" ], $w, 1500 );
    if ( ! $new || ! $old ) { rl( false, "$wn: Chrome returned no probe block" ); continue; }
    if ( in_array( '--verbose', $args, true ) ) { echo "form $wn: " . json_encode( array( 'new' => $new, 'old' => $old ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    rl( empty( $new['errors'] ), "$wn threw: " . implode( '; ', (array) $new['errors'] ) );
    rl( (int) $new['pageWidth'] <= $w, "PLANT C: $wn: the page scrolls sideways" );
    $team = array( 'none' => array(), 'one' => array( 'Aging Services' ), 'two' => array( 'Aging Services', 'Black Health Initiative' ) );
    foreach ( $team as $k => $names ) {
        $f = $new['forms'][ $k ];
        /* A */
        rl( $en['stay'] === $f['en']['legend'] && $en['optin'] === $f['en']['sfaf'], "PLANT A: $wn $k: Stay in touch reads " . json_encode( array( $f['en']['legend'], $f['en']['sfaf'] ) ) );
        rl( $f['en']['teams'] === array_map( function ( $n ) use ( $en ) { return str_replace( '%s', $n, $en['team_optin'] ); }, $names ), "PLANT A: $wn $k: the team boxes read " . json_encode( $f['en']['teams'] ) );
        rl( $en['email_use'] === $f['en']['emailUse'], "PLANT A: $wn $k: the email line reads " . json_encode( $f['en']['emailUse'] ) );
        rl( $f['en']['stayAfterPhone'] && $f['en']['stayBeforeSubmit'], "PLANT A: $wn $k: Stay in touch is not at the end of the form" );
        rl( array() === $f['en']['ticked'] && array() === $f['reopened']['ticked'], "PLANT A: $wn $k: a box was ticked when the form opened: " . json_encode( array( $f['en']['ticked'], $f['reopened']['ticked'] ) ) );
        /* B */
        rl( $es['stay'] === $f['es']['legend'] && $es['optin'] === $f['es']['sfaf'] && $es['email_use'] === $f['es']['emailUse'], "PLANT B: $wn $k: the Spanish reads " . json_encode( $f['es'], JSON_UNESCAPED_UNICODE ) );
        rl( $f['es']['teams'] === array_map( function ( $n ) use ( $es ) { return str_replace( '%s', $n, $es['team_optin'] ); }, $names ), "PLANT B: $wn $k: the Spanish team boxes read " . json_encode( $f['es']['teams'], JSON_UNESCAPED_UNICODE ) );
        rl( $f['en']['height'] === $f['es']['height'], "B: $wn $k: the form is " . $f['en']['height'] . 'px in English and ' . $f['es']['height'] . 'px in Spanish' );
        /* C */
        $was = (int) $old['forms'][ $k ]['en']['height'];
        /* A team's row is one line, or two where the longer of its two
           languages wraps: the form holds the room of the longer (3.113.8). */
        $cap = 24 + count( $names ) * ( $w <= 600 ? 66 : 42 );
        rl( $f['en']['height'] - $was <= $cap, "PLANT C: $wn $k: the form grew " . ( $f['en']['height'] - $was ) . "px from 3.123.0's $was" . "px, more than $cap" );
    }
    /* D */
    $p = (array) $new['posted'];
    rl( '1' === ( $p['optin'] ?? '' ) && 'cb2' === ( $p['lists'] ?? null ), "PLANT D: $wn: the form posted " . json_encode( array( $p['optin'] ?? null, $p['lists'] ?? null ) ) );
    if ( $shots ) {
        @mkdir( __DIR__ . '/screens' );
        foreach ( array( 'new' => 'stay', 'old' => 'stay-3123' ) as $which => $name ) {
            $shot = __DIR__ . "/release-31240-shot-$which-$wn.html";
            file_put_contents( $shot, str_replace( '}, 120); });', '}, 120); }); window.addEventListener("load",function(){setTimeout(function(){jQuery("#uc-rsvp-cancel-btn").trigger("click");jQuery("#btn-two").trigger("click");jQuery(\'[data-uc-lang-pick="en"]\').trigger("click");},600);});', (string) file_get_contents( $pages[ $which ] ) ) );
            shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . $w . ',1400 --virtual-time-budget=8000 --screenshot="' . __DIR__ . "/screens/rsvp-$name-$wn.png" . '" "file:///' . str_replace( '\\', '/', $shot ) . '" 2>NUL' );
        }
    }
}

foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
    $g = rl_chrome( $card_frames[ $wn ], $w, 1000 );
    if ( ! $g ) { rl( false, "card $wn: Chrome returned no probe block" ); continue; }
    if ( in_array( '--verbose', $args, true ) ) { echo "card $wn: " . json_encode( $g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    rl( empty( $g['errors'] ), "card $wn threw: " . implode( '; ', (array) $g['errors'] ) );
    rl( (int) $g['pageWidth'] <= $w, "card $wn: the page scrolls sideways" );
    /* E */
    rl( array( 'card' => true, 'a' => true, 'b' => false ) === $g['start'], "PLANT E: card $wn: on opening " . json_encode( $g['start'] ) );
    rl( array( 'card' => false, 'a' => false, 'b' => false ) === $g['none'], "PLANT E: card $wn: with no organizer " . json_encode( $g['none'] ) );
    rl( array( 'card' => true, 'a' => false, 'b' => true ) === $g['beta'], "PLANT E: card $wn: with the other team's organizer " . json_encode( $g['beta'] ) );
    rl( array( 'card' => true, 'a' => true, 'b' => true ) === $g['both'], "PLANT E: card $wn: with both " . json_encode( $g['both'] ) );
    /* F */
    rl( $g['sourceFolded'] && $g['sourceOpen'], "F: card $wn: + Add tag does not open the tags" );
    rl( true === $g['afterEnter']['box'] && '' === $g['afterEnter']['input'] && in_array( 'Fall 2026', (array) $g['afterEnter']['chips'], true ), "PLANT F: card $wn: a typed tag did not become a ticked box and a chip: " . json_encode( $g['afterEnter'] ) );
    rl( 4 === $g['afterDup']['boxes'] && in_array( 'Lunch club', (array) $g['afterDup']['chips'], true ), "F: card $wn: a tag typed in another case made a second box: " . json_encode( $g['afterDup'] ) );
    rl( array( 'Volunteers' ) === $g['searched'], "PLANT F: card $wn: the search shows " . json_encode( $g['searched'] ) );
    rl( count( (array) $g['afterRemove'] ) === count( (array) $g['afterDup']['chips'] ) - 1, "F: card $wn: × did not take a chip off: " . json_encode( $g['afterRemove'] ) );
    rl( $g['posted'] === $g['afterRemove'], "F: card $wn: what posts is not what the chips show: " . json_encode( array( $g['posted'], $g['afterRemove'] ) ) );
    if ( $shots ) {
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $w ) . ',900 --virtual-time-budget=6000 --screenshot="' . __DIR__ . "/screens/editor-mailchimp-$wn.png" . '" "file:///' . str_replace( '\\', '/', $card_frames[ $wn ] ) . '" 2>NUL' );
    }
}

echo "\n3.124.0 LIVE: Stay in touch on the RSVP form, and the Newsletter sign-up card\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Stay in touch closes the form, every box unticked every time it opens, in English and Spanish at one height, at most 24px taller than\n3.123.0's and a row for each team; it posts the SFAF box and the teams ticked. The card follows the organizers, and a typed\ntag becomes a ticked box and a chip, at 1280px, 390px and 320px.\n";
exit( 0 );
