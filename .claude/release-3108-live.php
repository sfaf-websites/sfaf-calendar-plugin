<?php
/**
 * 3.108.0 IN A REAL BROWSER: A VENUE BY DEFAULT, AND THE EDITOR'S TOUR.
 *
 *     php .claude/release-3108-live.php            writes the pages
 *     php .claude/release-3108-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-3108-live.php --shots    --run, and writes .claude/screens/editor-tour-*.png
 *
 * The real editor through wp-kit.php with the real portal.css and portal.js,
 * each page in a 900px-tall frame so the window scrolls.
 *
 *   A    Add event opens on "A venue" with the dropdown showing; Edit event
 *        opens on what the event has (an address, or a venue); the series
 *        prefill still switches to "A different location" and fills the boxes
 *        when it brings an address, and picks the venue when it brings one
 *   B.1  "Take the tour" is a button between the title and Back, nothing
 *        starts it, and nothing is stored
 *   B.2  the panel is a modal dialog named by the card and described by the
 *        caption; Tab and Shift+Tab cycle inside it; everything behind it is
 *        inert and cannot take focus; arrows move; Esc and Done close and
 *        hand focus back to the button; it runs again
 *   B.3  one step per card present, in page order, Who can edit this only on
 *        Edit; every caption word for word as Mark wrote it
 *   B.5  the ring sits 6px outside the card on every step, and the panel is
 *        inside the window on every step: beside the card at 1280px, below
 *        it at 390px (or held at the window's foot when the card is taller)
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rt_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rt_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); }
    catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}

/* The captions, typed from the brief, not read from the plugin. */
$CAPTIONS = array(
    'series'         => array( 'Series and language', 'Choose the series first. The event takes its description, picture and defaults from it, and the picture list narrows to that series. Choose Spanish if registrants should receive their emails in Spanish.' ),
    'title'          => array( 'Title', 'Give the event the name people will see on the calendar. Keep it short; the description carries the detail.' ),
    'schedule'       => array( 'Schedule', 'Pick the date and the start and end times. For an event that repeats, choose how often and the plugin creates every date for you.' ),
    'location'       => array( 'Location', 'Pick a venue from the list, or choose A different location and type an address. Tick online for a video event, or hybrid when people can come in person or join online.' ),
    'registration'   => array( 'Registration', 'Tick Accept RSVPs to let people register. Set a capacity if places are limited, leave it empty for no limit, or type 0 to send everyone to the waitlist. Add questions if you need to ask registrants something before the event.' ),
    'details'        => array( 'Event details', 'Write the description, add a featured picture from the calendar folder or paste an image link, and add a YouTube or Vimeo link if there is a video.' ),
    'faqs'           => array( 'FAQs', 'Add questions and answers for this event, or apply a saved set and edit it.' ),
    'classification' => array( 'Classification', 'Add at least one category and one organizer. These decide where the event appears in the calendar filters.' ),
    'notifications'  => array( 'Notifications', 'Choose who is told when people register or cancel, and who gets the reminder copies. Set the reply address for the reminder email.' ),
    'links'          => array( 'Links', 'Choose which donation link goes in the emails, and paste a volunteer page if there is one.' ),
    'display'        => array( 'Display', 'Choose which buttons appear on the public event page.' ),
    'access'         => array( 'Who can edit this', 'Add the people who may change this event besides its creator.' ),
);
$BAR_ADD  = 'Save draft keeps the event private until you are ready. Publish puts it on the calendar.';
$BAR_EDIT = 'Save changes updates the event. Cancel event keeps it on the calendar marked cancelled and tells registrants. Delete removes it for good.';

/* Three pictures that decode, one per series and one untagged. */
$pic = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675"><rect width="1200" height="675" fill="#0E818C"/></svg>' );
foreach ( array( 601 => 11, 602 => 12, 603 => 0 ) as $pid => $sid ) {
    kit_write_post( array( 'ID' => $pid, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Picture ' . $pid ) );
    update_post_meta( $pid, '_wp_attached_file', SFAF_Media_Folder::prefix() . 'pic-' . $pid . '.jpg' );
    $GLOBALS['kit_images'][ $pid ] = $pic;
    if ( $sid ) { $GLOBALS['kit_terms'][ $pid ][ SFAF_Media::TAXONOMY ] = array( $sid ); }
}

/* Events, narrowed by series when the query names one, so each series'
   prefill reads its own last event. */
$GLOBALS['kit_query'] = function ( $a ) {
    $out    = array();
    $type   = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $series = 0;
    if ( ! empty( $a['tax_query'] ) ) {
        foreach ( $a['tax_query'] as $c ) {
            if ( is_array( $c ) && isset( $c['taxonomy'] ) && 'uc_series' === $c['taxonomy'] ) { $series = (int) ( is_array( $c['terms'] ) ? reset( $c['terms'] ) : $c['terms'] ); }
        }
    }
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( $type !== $p->post_type ) { continue; }
        if ( $series && ! in_array( $series, isset( $GLOBALS['kit_terms'][ $id ]['uc_series'] ) ? $GLOBALS['kit_terms'][ $id ]['uc_series'] : array(), true ) ) { continue; }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
};

/* An event at an address, in Alpha, taking RSVPs: the Edit pages. */
$eid = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Community dinner', 'post_author' => 1 ) );
foreach ( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_rsvp_enabled' => '1',
    '_uc_location_name' => 'The Hall', '_uc_location' => 'The Hall, 470 Castro St, San Francisco, CA 94114' ) as $k => $v ) { update_post_meta( $eid, $k, $v ); }
foreach ( array( 'street' => '470 Castro St', 'city' => 'San Francisco', 'state' => 'CA', 'zip' => '94114' ) as $part => $v ) {
    $keys = sfaf_location_part_keys();
    update_post_meta( $eid, $keys[ $part ], $v );
}
$GLOBALS['kit_terms'][ $eid ]['uc_series']    = array( 11 );
$GLOBALS['kit_terms'][ $eid ]['uc_organizer'] = array( 11 );

/* An event at the Beta venue, in Beta. */
$vid = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Beta lunch', 'post_author' => 1 ) );
foreach ( array( '_uc_event_date' => '2026-11-21', '_uc_start_time' => '12:00', '_uc_end_time' => '13:00' ) as $k => $v ) { update_post_meta( $vid, $k, $v ); }
$GLOBALS['kit_terms'][ $vid ]['uc_series'] = array( 12 );
$GLOBALS['kit_terms'][ $vid ]['uc_venue']  = array( 12 );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function rt_screen( $args ) {
    return rt_capture( function () use ( $args ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $GLOBALS['portal'], $args ); } );
}
$add   = rt_screen( array( $user, 0 ) );
$edit  = rt_screen( array( $user, $eid ) );
$editv = rt_screen( array( $user, $vid ) );
rt_check( 12 === SFAF_Venues::id_for_event( $vid ), 'setup: the Beta event has no venue' );

$pages = array(
    'add-desktop'         => array( 'w' => 1280, 'html' => $add ),
    'add-phone'           => array( 'w' => 390,  'html' => $add ),
    'edit-desktop'        => array( 'w' => 1280, 'html' => $edit ),
    'edit-phone'          => array( 'w' => 390,  'html' => $edit ),
    'editvenue-desktop'   => array( 'w' => 1280, 'html' => $editv ),
    'prefilladdr-desktop' => array( 'w' => 1280, 'html' => $add ),
    'prefillvenue-desktop'=> array( 'w' => 1280, 'html' => $add ),
);

$probe = <<<'JS'
(function () {
var P = window.RV_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function which(el) { if (!el) { return 'none'; } if (el.hasAttribute('data-uc-tour-back')) { return 'back'; } if (el.hasAttribute('data-uc-tour-next')) { return 'next'; } if (el.hasAttribute('data-uc-tour-done')) { return 'done'; } if (el.hasAttribute('data-uc-tour-start')) { return 'link'; } return el.tagName + '.' + el.className; }
function key(k, shift) { (document.activeElement || document.body).dispatchEvent(new KeyboardEvent('keydown', { key: k, shiftKey: !!shift, bubbles: true, cancelable: true })); }
function store() { var a = '', b = ''; try { a = JSON.stringify(localStorage); } catch (e) { a = 'x'; } try { b = JSON.stringify(sessionStorage); } catch (e) { b = 'x'; } return a + '|' + b + '|' + document.cookie; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
var storeAtLoad = store();

window.addEventListener('load', function () { setTimeout(function () {
  /* A: what the location opens on. */
  var mode = q('[data-uc-location-mode]:checked');
  out.mode = mode ? mode.value : null;
  out.venueShown = seen(q('[data-uc-location-panel="venue"]')) && seen(q('select[name="venue"]'));
  out.customShown = seen(q('[data-uc-location-panel="custom"]'));
  out.venueValue = (q('select[name="venue"]') || {}).value || null;

  if (P.indexOf('prefill') === 0) {
    var s = q('[data-uc-series-select]');
    s.value = P.indexOf('prefilladdr') === 0 ? '11' : '12';
    s.dispatchEvent(new Event('change', { bubbles: true }));
    var apply = q('[data-uc-prefill-apply]');
    out.prefillOffered = seen(apply);
    if (apply) { apply.click(); }
    var m2 = q('[data-uc-location-mode]:checked');
    out.afterMode = m2 ? m2.value : null;
    out.afterVenue = (q('select[name="venue"]') || {}).value || null;
    out.afterStreet = (q('input[name="location_street"]') || {}).value || '';
    out.afterName = (q('input[name="location_name"]') || {}).value || '';
    out.afterCustomShown = seen(q('[data-uc-location-panel="custom"]'));
    out.afterVenueShown = seen(q('[data-uc-location-panel="venue"]'));
    finish();
    return;
  }
  if (P.indexOf('editvenue') === 0) { finish(); return; }

  /* B.1: the button, where it sits, and that nothing started. */
  var link = q('[data-uc-tour-start]');
  out.link = link ? { tag: link.tagName, text: text(link), box: rect(link), color: getComputedStyle(link).color, deco: getComputedStyle(link).textDecorationLine } : null;
  out.h1 = rect(q('.uc-page-head h1'));
  out.back = rect(q('.uc-page-head a.uc-btn'));
  out.startedAlone = !!q('[data-uc-tour]');
  if (!link) { finish(); return; }

  var TOUR = ['series', 'title', 'schedule', 'location', 'registration', 'details', 'faqs', 'classification', 'notifications', 'links', 'display', 'access', 'actions'];
  out.present = TOUR.filter(function (c) { var e = q('[data-uc-card="' + c + '"]'); return !!e && e.getClientRects().length > 0; });

  link.focus(); link.click();
  var root = q('[data-uc-tour]'), panel = q('[data-uc-tour-panel]'), ring = q('[data-uc-tour-ring]');
  out.opened = !!root && !!panel && !!ring;
  if (!out.opened) { finish(); return; }
  out.dialog = { role: panel.getAttribute('role'), modal: panel.getAttribute('aria-modal'),
    label: text(document.getElementById(panel.getAttribute('aria-labelledby') || 'x')),
    desc: text(document.getElementById(panel.getAttribute('aria-describedby') || 'x')) };
  out.focusAtOpen = which(document.activeElement);
  out.ringBorder = getComputedStyle(ring).borderTopColor + ' ' + getComputedStyle(ring).borderTopWidth;

  /* B.2: everything behind is inert and refuses focus. */
  var bg = qa('a[href], button, input:not([type="hidden"]), select, textarea, summary, [tabindex]').filter(function (e) { return !root.contains(e) && !e.disabled; });
  out.bgCount = bg.length;
  out.bgNotInert = bg.filter(function (e) { return !e.closest('[inert]'); }).map(function (e) { return e.tagName + '.' + e.className + '[' + (e.name || '') + ']'; }).slice(0, 5);
  var took = 0;
  bg.slice(0, 60).forEach(function (e) { e.focus(); if (document.activeElement === e) { took++; } });
  out.bgTookFocus = took;

  /* B.2: Tab and Shift+Tab cycle inside the panel. On step one Back is off. */
  q('[data-uc-tour-next]').focus();
  key('Tab'); out.tab1 = which(document.activeElement);
  key('Tab'); out.tab2 = which(document.activeElement);
  key('Tab', true); out.stab1 = which(document.activeElement);
  key('Tab', true); out.stab2 = which(document.activeElement);

  /* B.3 and B.5: every step. */
  out.steps = [];
  var vw = document.documentElement.clientWidth, vh = window.innerHeight;
  out.vw = vw; out.vh = vh;
  for (var n = 0; n < 20; n++) {
    var cardName = out.present[n];
    var card = cardName ? q('[data-uc-card="' + cardName + '"]') : null;
    out.steps.push({ count: text(q('[data-uc-tour-count]')), name: text(q('.uc-tour-name')), text: text(q('.uc-tour-text')),
      card: cardName || null, cardBox: rect(card), ring: rect(ring), panel: rect(panel) });
    var next = q('[data-uc-tour-next]');
    if (next.disabled) { break; }
    next.click();
  }
  out.lastFocus = which(document.activeElement);

  /* Arrows. */
  key('ArrowLeft'); out.afterLeft = text(q('[data-uc-tour-count]'));
  key('ArrowUp'); out.afterUp = text(q('[data-uc-tour-count]'));
  key('ArrowRight'); out.afterRight = text(q('[data-uc-tour-count]'));
  key('ArrowDown'); out.afterDown = text(q('[data-uc-tour-count]'));

  /* Esc closes and hands focus back. */
  key('Escape');
  out.escClosed = !q('[data-uc-tour]');
  out.escFocus = which(document.activeElement);
  out.inertLeft = qa('[inert]').length;

  /* It runs again, and Done closes it. */
  link.click();
  out.againCount = text(q('[data-uc-tour-count]'));
  var done = q('[data-uc-tour-done]');
  if (done) { done.click(); }
  out.doneClosed = !q('[data-uc-tour]');
  out.doneFocus = which(document.activeElement);
  out.inertAfterDone = qa('[inert]').length;
  out.stored = store() !== storeAtLoad;
  finish();
}, 600); });
})();
JS;

/* For the screenshots: open the tour at step one, or walk to the last. */
$shot_js = <<<'JS'
window.addEventListener('load', function () { setTimeout(function () {
  var link = document.querySelector('[data-uc-tour-start]');
  link.click();
  if (window.RV_SHOT === 'last') {
    for (var i = 0; i < 20; i++) { document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true })); }
  }
}, 600); });
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rt_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inject = '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script>';
    $html = preg_replace( '#</body>#i', $inject . '</body>', $p['html'], 1 );
    $inner = __DIR__ . '/release-3108-live-' . $name . '.html';
    file_put_contents( $inner, $html );
    $files[ $name ] = __DIR__ . '/release-3108-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args  = array_slice( $argv, 1 );
$shots = in_array( '--shots', $args, true );
if ( ! in_array( '--run', $args, true ) && ! $shots ) {
    echo 'wrote ' . count( $files ) . " pages; --run drives them.\n";
    exit( $fails ? 1 : 0 );
}
$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
$g = array();
foreach ( $files as $name => $file ) {
    $w   = $pages[ $name ]['w'];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w + 20 ) . ',1000 --virtual-time-budget=10000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rt_check( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rt_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rt_check( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

$ACCENT = 'rgb(14, 118, 128)';   // --uc-accent-text #0E7680, the focus ring's colour

/* ---- A ------------------------------------------------------------------ */
foreach ( array( 'add-desktop', 'add-phone' ) as $t ) {
    rt_check( 'venue' === $v( $t, 'mode' ) && true === $v( $t, 'venueShown' ) && false === $v( $t, 'customShown' ),
        "PLANT A: $t: Add event opens on " . json_encode( array( $v( $t, 'mode' ), $v( $t, 'venueShown' ), $v( $t, 'customShown' ) ) ) . ', not A venue with the dropdown showing' );
}
foreach ( array( 'edit-desktop', 'edit-phone' ) as $t ) {
    rt_check( 'custom' === $v( $t, 'mode' ) && true === $v( $t, 'customShown' ) && false === $v( $t, 'venueShown' ),
        "PLANT A: $t: an event at an address opens on " . json_encode( $v( $t, 'mode' ) ) );
}
rt_check( 'venue' === $v( 'editvenue-desktop', 'mode' ) && '12' === $v( 'editvenue-desktop', 'venueValue' ),
    'PLANT A: an event at a venue opens on ' . json_encode( array( $v( 'editvenue-desktop', 'mode' ), $v( 'editvenue-desktop', 'venueValue' ) ) ) );
rt_check( true === $v( 'prefilladdr-desktop', 'prefillOffered' ) && 'custom' === $v( 'prefilladdr-desktop', 'afterMode' )
    && '470 Castro St' === $v( 'prefilladdr-desktop', 'afterStreet' ) && 'The Hall' === $v( 'prefilladdr-desktop', 'afterName' )
    && true === $v( 'prefilladdr-desktop', 'afterCustomShown' ) && false === $v( 'prefilladdr-desktop', 'afterVenueShown' ),
    'PLANT A PREFILL: a series with an address did not switch to A different location and fill it: ' . json_encode( $g['prefilladdr-desktop'] ?? null ) );
rt_check( true === $v( 'prefillvenue-desktop', 'prefillOffered' ) && 'venue' === $v( 'prefillvenue-desktop', 'afterMode' ) && '12' === $v( 'prefillvenue-desktop', 'afterVenue' ),
    'PLANT A PREFILL: a series with a venue did not pick it: ' . json_encode( $g['prefillvenue-desktop'] ?? null ) );

/* ---- B ------------------------------------------------------------------ */
foreach ( array( 'add-desktop', 'add-phone', 'edit-desktop', 'edit-phone' ) as $t ) {
    if ( ! isset( $g[ $t ] ) ) { continue; }
    $is_add = 0 === strpos( $t, 'add-' );
    $phone = false !== strpos( $t, 'phone' );

    /* B.1 */
    $l = (array) $v( $t, 'link' ); $h1 = (array) $v( $t, 'h1' ); $bk = (array) $v( $t, 'back' );
    rt_check( 'BUTTON' === ( $l['tag'] ?? '' ) && 'Take the tour' === ( $l['text'] ?? '' ) && $ACCENT === ( $l['color'] ?? '' ) && 'underline' === ( $l['deco'] ?? '' ),
        "PLANT B.1: $t: the tour link reads " . json_encode( $l ) );
    if ( $l && $bk && $h1 ) {
        $lc = ( $l['box']['top'] + $l['box']['bottom'] ) / 2; $bc = ( $bk['top'] + $bk['bottom'] ) / 2;
        rt_check( $l['box']['right'] <= $bk['left'] && abs( $lc - $bc ) <= 4 && ( $phone || $l['box']['left'] > $h1['right'] ),
            "PLANT B.1: $t: the link is not between the title and Back: " . json_encode( array( 'h1' => $h1, 'link' => $l['box'], 'back' => $bk ) ) );
    }
    rt_check( false === $v( $t, 'startedAlone' ), "PLANT B.1: $t: the tour started on its own" );
    rt_check( false === $v( $t, 'stored' ), "PLANT B.1: $t: the tour stored something in the browser" );
    rt_check( true === $v( $t, 'opened' ), "$t: the tour did not open" );

    /* B.2 */
    $d = (array) $v( $t, 'dialog' );
    rt_check( 'dialog' === ( $d['role'] ?? '' ) && 'true' === ( $d['modal'] ?? '' ) && 'Series and language' === ( $d['label'] ?? '' ) && $CAPTIONS['series'][1] === ( $d['desc'] ?? '' ),
        "PLANT B.2: $t: the panel is not announced as a dialog named by the card: " . json_encode( $d ) );
    rt_check( 'next' === $v( $t, 'focusAtOpen' ), "PLANT B.2: $t: focus at opening is on " . $v( $t, 'focusAtOpen' ) );
    rt_check( (int) $v( $t, 'bgCount' ) > 20 && array() === $v( $t, 'bgNotInert' ) && 0 === $v( $t, 'bgTookFocus' ),
        "PLANT B.2: $t: the page behind is reachable: " . json_encode( array( $v( $t, 'bgCount' ), $v( $t, 'bgNotInert' ), $v( $t, 'bgTookFocus' ) ) ) );
    rt_check( array( 'done', 'next', 'done', 'next' ) === array( $v( $t, 'tab1' ), $v( $t, 'tab2' ), $v( $t, 'stab1' ), $v( $t, 'stab2' ) ),
        "PLANT B.2: $t: Tab does not cycle inside the panel: " . json_encode( array( $v( $t, 'tab1' ), $v( $t, 'tab2' ), $v( $t, 'stab1' ), $v( $t, 'stab2' ) ) ) );
    rt_check( $ACCENT . ' 3px' === $v( $t, 'ringBorder' ), "$t: the ring is " . $v( $t, 'ringBorder' ) . ', not the focus ring colour at 3px' );

    /* B.3 */
    $want = $is_add
        ? array( 'series', 'title', 'schedule', 'location', 'registration', 'details', 'faqs', 'classification', 'notifications', 'links', 'display', 'actions' )
        : array( 'series', 'title', 'schedule', 'location', 'registration', 'details', 'faqs', 'classification', 'notifications', 'links', 'display', 'access', 'actions' );
    rt_check( $want === $v( $t, 'present' ), "$t: the cards on the page are " . json_encode( $v( $t, 'present' ) ) );
    $steps = (array) $v( $t, 'steps' );
    $n     = count( $want );
    rt_check( count( $steps ) === $n, "PLANT B.3: $t: the tour has " . count( $steps ) . " steps for $n cards: " . json_encode( array_column( $steps, 'name' ) ) );
    foreach ( $steps as $i => $s ) {
        $card = isset( $want[ $i ] ) ? $want[ $i ] : '?';
        $wn   = 'actions' === $card ? 'Action bar' : ( $CAPTIONS[ $card ][0] ?? '?' );
        $wt   = 'actions' === $card ? ( $is_add ? $BAR_ADD : $BAR_EDIT ) : ( $CAPTIONS[ $card ][1] ?? '?' );
        rt_check( ( $i + 1 ) . " of $n" === $s['count'], "PLANT B.3: $t: step " . ( $i + 1 ) . ' counts ' . json_encode( $s['count'] ) );
        rt_check( $wn === $s['name'] && $wt === $s['text'], "PLANT B.3: $t: step " . ( $i + 1 ) . ' is ' . json_encode( array( $s['name'], $s['text'] ) ) . ", not $card" );

        /* B.5: the ring on the card, the panel in the window. */
        $c = $s['cardBox']; $r = $s['ring']; $p = $s['panel'];
        if ( ! $c || ! $r || ! $p ) { rt_check( false, "$t: step " . ( $i + 1 ) . ' has no box' ); continue; }
        rt_check( abs( $r['left'] - ( $c['left'] - 6 ) ) <= 1 && abs( $r['top'] - ( $c['top'] - 6 ) ) <= 1 && abs( $r['right'] - ( $c['right'] + 6 ) ) <= 1 && abs( $r['bottom'] - ( $c['bottom'] + 6 ) ) <= 1,
            "PLANT B.5: $t: step " . ( $i + 1 ) . " ($card): the ring is not on the card: " . json_encode( array( 'card' => $c, 'ring' => $r ) ) );
        rt_check( $p['left'] >= 0 && $p['top'] >= 0 && $p['right'] <= $v( $t, 'vw' ) && $p['bottom'] <= $v( $t, 'vh' ),
            "PLANT B.5: $t: step " . ( $i + 1 ) . " ($card): the panel leaves the window: " . json_encode( array( 'panel' => $p, 'vw' => $v( $t, 'vw' ), 'vh' => $v( $t, 'vh' ) ) ) );
        if ( 'actions' === $card ) {
            rt_check( $p['bottom'] <= $r['top'], "$t: the action bar step's panel is not above the bar: " . json_encode( array( 'panel' => $p, 'ring' => $r ) ) );
        } elseif ( $phone ) {
            /* Below; above only when the card is too near the foot of the page
               to scroll up and there is no room under it; or held at the
               window's foot over a card taller than the window. */
            $no_room = $r['bottom'] + 12 + $p['h'] > $v( $t, 'vh' ) - 12;
            rt_check( $p['top'] >= $r['bottom'] || ( $no_room && $p['bottom'] <= $r['top'] ) || $p['bottom'] === $v( $t, 'vh' ) - 12,
                "$t: step " . ( $i + 1 ) . " ($card): the panel is not below the card at 390px: " . json_encode( array( 'panel' => $p, 'ring' => $r ) ) );
        } else {
            rt_check( $p['left'] >= $r['right'] || $p['right'] <= $r['left'],
                "$t: step " . ( $i + 1 ) . " ($card): the panel is not beside the card at 1280px: " . json_encode( array( 'panel' => $p, 'ring' => $r ) ) );
        }
    }
    rt_check( 'done' === $v( $t, 'lastFocus' ), "$t: on the last step focus is on " . $v( $t, 'lastFocus' ) . ', not Done' );
    $m1 = ( $n - 1 ) . " of $n"; $m2 = ( $n - 2 ) . " of $n";
    rt_check( array( $m1, $m2, $m1, "$n of $n" ) === array( $v( $t, 'afterLeft' ), $v( $t, 'afterUp' ), $v( $t, 'afterRight' ), $v( $t, 'afterDown' ) ),
        "PLANT B.2: $t: the arrow keys gave " . json_encode( array( $v( $t, 'afterLeft' ), $v( $t, 'afterUp' ), $v( $t, 'afterRight' ), $v( $t, 'afterDown' ) ) ) );
    rt_check( true === $v( $t, 'escClosed' ) && 'link' === $v( $t, 'escFocus' ) && 0 === $v( $t, 'inertLeft' ),
        "PLANT B.2: $t: Esc did not close and hand focus back: " . json_encode( array( $v( $t, 'escClosed' ), $v( $t, 'escFocus' ), $v( $t, 'inertLeft' ) ) ) );
    rt_check( "1 of $n" === $v( $t, 'againCount' ) && true === $v( $t, 'doneClosed' ) && 'link' === $v( $t, 'doneFocus' ) && 0 === $v( $t, 'inertAfterDone' ),
        "PLANT B.2: $t: the tour did not run again and close on Done: " . json_encode( array( $v( $t, 'againCount' ), $v( $t, 'doneClosed' ), $v( $t, 'doneFocus' ), $v( $t, 'inertAfterDone' ) ) ) );
}

/* Screenshots for DESIGN.md: step one and the action bar, Add and Edit, both widths. */
if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( array( 'add' => $add, 'edit' => $edit ) as $screen => $html ) {
        foreach ( array( 'first', 'last' ) as $at ) {
            $inner = __DIR__ . "/release-3108-shot-$screen-$at.html";
            file_put_contents( $inner, preg_replace( '#</body>#i', '<script>window.RV_SHOT=' . json_encode( $at ) . ';' . $shot_js . '</script></body>', $html, 1 ) );
            foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
                $png   = __DIR__ . "/screens/editor-tour-$screen-$at-$wn.png";
                $frame = __DIR__ . "/release-3108-shot-$screen-$at-$wn-frame.html";
                file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="' . basename( $inner ) . '" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe></body></html>' );
                shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $w ) . ',900 --virtual-time-budget=6000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
                rt_check( file_exists( $png ), "no screenshot written for $screen $at $wn" );
            }
        }
    }
}

if ( $fails ) {
    echo 'RELEASE 3.108.0 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.108.0 live: Add event opens on A venue, Edit event on what it has, the series prefill still switches and fills; the tour link between the title and Back, never started alone, nothing stored; a modal dialog, Tab cycling inside it, the page behind inert, arrows, Esc and Done, run twice; one step per card present in page order with every caption word for word; the ring on every card and the panel inside the window, on Add and Edit at 1280px and 390px.\n";
