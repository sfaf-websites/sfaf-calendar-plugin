<?php
/**
 * 3.107.0 IN A REAL BROWSER: THE EVENT EDITOR'S LAYOUT AND BEHAVIOUR PASS, ADD
 * AND EDIT, AT 1280px AND 390px.
 *
 *     php .claude/release-3107-live.php            writes the pages
 *     php .claude/release-3107-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-3107-live.php --shots    --run, and writes .claude/screens/editor-3107-*.png
 *
 * The real editor through wp-kit.php with the real portal.css and portal.js.
 * Every page sits in a 900px-tall frame, so the window scrolls and the sticky
 * bar can be seen doing its job. Every value is read off the laid-out page and
 * compared with the DESIGN.md token it should be.
 *
 *   A   the card order in both columns, by data-uc-card, on Add and on Edit;
 *       from 3.107.1 the series card first in the main column, its width, 16px
 *       above Title, and the series thumbnail at 160x90, 12px radius, on Add (3.108.1)
 *   B   Registration: the tick alone while RSVPs are off, the rest on ticking
 *   D   Links holds the donation dropdown and Volunteer; Organizer is chips and
 *       "+ Add organizer"; the address boxes are labelled by placeholders in
 *       --p-muted; Insert image is outlined with no chevron; one sign-out; one
 *       empty line in Notifications; the FAQ set starts empty and follows the
 *       series
 *   E   the bar: sticky at the window's foot while the page scrolls, --p-panel
 *       with a --p-border top rule, the note at 12px, every button inside the
 *       window at 390px, Delete / Save / main action left to right
 *   F   changing the series re-filters the picture list with no save, and a
 *       chosen picture outside the series stays chosen with its line
 *   G   on Add the ticked weekday follows the date until a tick is touched; on
 *       Edit it never moves
 *   H   every card names itself
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rv_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rv_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); }
    catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}

/* Three pictures in the calendar folder: one per series and one untagged.
   Each a real 1200x675 image that decodes (3.107.1): the 1x1 GIF used until
   then was truncated, so every thumbnail drew as a broken image and the series
   card's fell back to its file name. Alpha's tagged picture is its series
   picture, so it is the one the prefill panel shows. */
foreach ( array( 601 => 11, 602 => 12, 603 => 0 ) as $pid => $sid ) {
    kit_write_post( array( 'ID' => $pid, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Picture ' . $pid ) );
    update_post_meta( $pid, '_wp_attached_file', SFAF_Media_Folder::prefix() . 'pic-' . $pid . '.jpg' );
    $GLOBALS['kit_images'][ $pid ] = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675"><rect width="1200" height="675" fill="#0E818C"/></svg>' );
    if ( $sid ) { $GLOBALS['kit_terms'][ $pid ][ SFAF_Media::TAXONOMY ] = array( $sid ); }
}
/* Three FAQ sets: one starts with Alpha, two with Beta. */
update_option( SFAF_FAQ_Sets::OPTION, array(
    'setalpha' => array( 'name' => 'Alpha basics', 'rows' => array( array( 'question' => 'Is it free?', 'answer' => 'Yes.' ) ) ),
    'setbeta1' => array( 'name' => 'Beta one', 'rows' => array( array( 'question' => 'Parking?', 'answer' => 'No.' ) ) ),
    'setbeta2' => array( 'name' => 'Beta two', 'rows' => array( array( 'question' => 'Food?', 'answer' => 'Yes.' ) ) ),
) );
$GLOBALS['kit_query'] = function ( $a ) {
    $out  = array();
    $type = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( $type === $p->post_type ) { $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; }
    }
    return $out;
};

/* Edit: a published event in Alpha, taking RSVPs, two organizers. */
$eid = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Community dinner', 'post_author' => 1 ) );
foreach ( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '' ) as $k => $v ) { update_post_meta( $eid, $k, $v ); }
$GLOBALS['kit_terms'][ $eid ]['uc_series']    = array( 11 );
$GLOBALS['kit_terms'][ $eid ]['uc_organizer'] = array( 11, 12 );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function rv_screen( $args ) {
    return rv_capture( function () use ( $args ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $GLOBALS['portal'], $args ); } );
}
$add  = rv_screen( array( $user, 0 ) );
$edit = rv_screen( array( $user, $eid ) );
$pages = array(
    'add-desktop'  => array( 'w' => 1280, 'html' => $add ),
    'add-phone'    => array( 'w' => 390,  'html' => $add ),
    'edit-desktop' => array( 'w' => 1280, 'html' => $edit ),
    'edit-phone'   => array( 'w' => 390,  'html' => $edit ),
);

$probe = <<<'JS'
(function () {
var P = window.RV_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
/* Inside a closed <details> Chrome still reports boxes, so that is asked too. */
function shut(el) { for (var n = el; n && n.parentElement; n = n.parentElement) { var d = n.parentElement; if (d.tagName === 'DETAILS' && !d.open && n.tagName !== 'SUMMARY') { return true; } } return false; }
function seen(el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden' && !shut(el); }
function own(h) { return h ? Array.prototype.filter.call(h.childNodes, function (n) { return n.nodeType === 3 || (n.classList && n.classList.contains('uc-req')); }).map(function (n) { return n.textContent; }).join('').replace(/\s+/g, ' ').trim() : ''; }
function box(el) {
  if (!el) { return null; }
  var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
  return { text: text(el).slice(0, 80), seen: seen(el), top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom),
    w: Math.round(r.width), h: Math.round(r.height), color: cs.color, bg: cs.backgroundColor, border: cs.borderTopColor + ' ' + cs.borderTopWidth,
    radius: cs.borderTopLeftRadius, pad: cs.paddingTop, size: cs.fontSize, weight: cs.fontWeight, position: cs.position };
}
function change(el) { el.dispatchEvent(new Event('change', { bubbles: true })); }
function finish() {
  out.pageWidth = document.documentElement.scrollWidth;
  window.parent.postMessage(JSON.stringify(out), '*');
}
window.addEventListener('load', function () { setTimeout(function () {
  var add = P.indexOf('add-') === 0;
  /* H and A: the names, in each column's order. */
  out.main = qa('.uc-bento-main > [data-uc-card]').map(function (c) { return c.getAttribute('data-uc-card'); });
  out.side = qa('.uc-bento-side > [data-uc-card]').map(function (c) { return c.getAttribute('data-uc-card'); });
  out.unnamed = qa('.uc-bento-main > section, .uc-bento-side > section').filter(function (c) { return !c.hasAttribute('data-uc-card'); }).length;
  out.seriesCount = qa('[data-uc-card="series"]').length;
  out.seriesBox = box(q('[data-uc-card="series"]'));
  out.titleBox = box(q('[data-uc-card="title"]'));
  out.mainBox = box(q('.uc-bento-main'));
  out.firstCardTop = Math.min.apply(null, qa('.uc-bento [data-uc-card]').filter(seen).map(function (c) { return Math.round(c.getBoundingClientRect().top); }));
  out.cardStyles = qa('.uc-bento [data-uc-card]').map(function (c) { var s = getComputedStyle(c); return c.getAttribute('data-uc-card') + ' ' + s.borderTopColor + ' ' + s.borderTopWidth + ' ' + s.paddingTop + ' ' + s.borderTopLeftRadius; });
  out.titles = qa('.uc-bento [data-uc-card] > h2, .uc-bento [data-uc-card] > .uc-bento-head > h2').map(function (h) { return own(h).replace(/\s*\*$/, ''); });
  out.logout = qa('.uc-portal-logout').length;
  out.signout = qa('.uc-portal-signout').length;
  out.topbar = seen(q('.uc-portal-topbar'));

  /* E: the bar, at rest and scrolled. */
  var bar = q('[data-uc-editor-bar]');
  out.bar = box(bar);
  out.barStyle = bar ? { bg: getComputedStyle(bar).backgroundColor, top: getComputedStyle(bar).borderTopColor + ' ' + getComputedStyle(bar).borderTopWidth,
    left: getComputedStyle(bar).borderLeftWidth, radius: getComputedStyle(bar).borderTopLeftRadius, bottom: getComputedStyle(bar).bottom } : null;
  out.barNote = box(q('.uc-form-actions-note', bar));
  var content = q('.uc-portal-content');
  if (content) { var cs = getComputedStyle(content), cr = content.getBoundingClientRect(); out.contentBox = { left: Math.round(cr.left + parseFloat(cs.paddingLeft)), right: Math.round(cr.right - parseFloat(cs.paddingRight)) }; }
  window.scrollTo(0, Math.round((document.documentElement.scrollHeight - window.innerHeight) / 2));
  out.scrolled = window.scrollY;
  out.barScrolled = box(bar);
  out.innerH = window.innerHeight;
  out.innerW = window.innerWidth;
  out.buttons = qa('[data-uc-editor-bar] .uc-btn, [data-uc-editor-bar] summary').filter(seen).map(function (b) { var r = b.getBoundingClientRect(); return { t: text(b), left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom) }; });
  out.actionsNote = seen(q('.uc-editor-actions-note'));
  window.scrollTo(0, 0);

  /* B: Registration, closed and opened. */
  var reg = q('[data-uc-registration]');
  var tick = reg ? q('[data-uc-rsvp-accept-check] input', reg) : null;
  var body = reg ? q('[data-uc-registration-body]', reg) : null;
  out.reg = { title: reg ? text(q('h2', reg)) : '', ticked: tick ? tick.checked : null, bodySeen: seen(body),
    visibleControls: reg ? qa('input:not([type=hidden]), select, textarea, button', reg).filter(seen).map(function (i) { return i.name || i.getAttribute('data-uc-q-add') !== null ? (i.name || 'add-question') : i.tagName; }) : [] };
  if (tick && !tick.disabled) {
    tick.click();
    out.regToggled = { ticked: tick.checked, bodySeen: seen(body), capacity: seen(q('input[name="capacity"]', reg)), capPlaceholder: (q('input[name="capacity"]', reg) || {}).placeholder || '',
      email: seen(q('input[name="email_required"]', reg)), questions: seen(q('[data-uc-questions-card]', reg)), questionsTitle: text(q('[data-uc-questions-card] > h3', reg)),
      zeroHint: /0 means unlimited/.test(reg.textContent) };
    tick.click();
    out.regBack = { ticked: tick.checked, bodySeen: seen(body) };
  }

  /* D: Links, Organizer, address, Insert image, Notifications. */
  var links = q('[data-uc-card="links"]');
  out.links = { title: links ? text(q('h2', links)) : '', donate: !!(links && q('select[name="donate_choice"]', links)), volunteer: !!(links && q('input[name="volunteer_url"]', links)), hints: links ? qa('.uc-hint', links).filter(seen).length : -1 };
  var org = q('[data-uc-card="classification"] .uc-org-field');
  out.org = org ? { gridSeen: seen(q('[data-uc-chips-source]', org)), add: text(q('[data-uc-chips-toggle]', org)), addSeen: seen(q('[data-uc-chips-toggle]', org)),
    chips: qa('.uc-chip-text', org).map(text), chipBg: (q('.uc-chip', org) ? getComputedStyle(q('.uc-chip', org)).backgroundColor : '') } : null;
  if (org) { q('[data-uc-chips-toggle]', org).click(); out.orgOpen = seen(q('[data-uc-chips-source]', org)); q('[data-uc-chips-toggle]', org).click(); }
  var custom = q('[data-uc-location-panel="custom"]');
  if (custom) { var radio = q('input[value="custom"][data-uc-location-mode]'); if (radio) { radio.click(); } }
  out.addr = qa('[data-uc-location-panel="custom"] input[type="text"]').map(function (i) {
    return { name: i.name, ph: i.placeholder, phColor: getComputedStyle(i, '::placeholder').color, label: text(i.closest('label') ? q('.uc-field-label', i.closest('label')) : null), seen: seen(i) };
  });
  var ins = q('.uc-desc-images-open');
  out.insert = ins ? { text: text(ins), tag: ins.tagName, border: getComputedStyle(ins).borderTopColor + ' ' + getComputedStyle(ins).borderTopWidth, color: getComputedStyle(ins).color,
    bg: getComputedStyle(ins).backgroundColor, chevron: !!q('.uc-disclosure-chevron', ins), marker: getComputedStyle(ins).listStyleType } : null;
  var notes = q('[data-uc-card="notifications"]');
  out.empties = notes ? qa('p, span', notes).filter(seen).map(text).filter(function (t) { return /yet|nobody|no one/i.test(t); }) : null;
  out.pickerCount = text(q('[data-uc-picker-count]'));

  /* F and D.5: the series drives the pictures and the FAQ set. */
  var series = q('[data-uc-series-select]'), faq = q('[data-uc-faq-set]');
  var list = q('[data-uc-image-picker] [data-uc-filter-list]');
  function shownPics() { return qa('[data-uc-image-series]', list).filter(function (r) { return !r.hasAttribute('data-uc-off-series') && !r.hidden; }).map(function (r) { return q('input', r).value; }); }
  var outside = q('[data-uc-image-outside]');
  out.faqStart = faq ? faq.value : null;
  if (series && list) {
    out.picsStart = shownPics();
    series.value = '0'; change(series);
    out.picsNone = shownPics(); out.faqNone = faq ? faq.value : null;
    series.value = '11'; change(series);
    out.picsAlpha = shownPics(); out.faqAlpha = faq ? faq.value : null;
    series.value = '12'; change(series);
    out.picsBeta = shownPics(); out.faqBeta = faq ? faq.value : null;
    var r602 = q('[data-uc-image-picker] input[value="602"]');
    if (r602) { r602.click(); }
    out.outsideAtBeta = seen(outside);
    series.value = '11'; change(series);
    out.picsAfterPick = shownPics(); out.pickedKept = r602 ? r602.checked : null; out.outsideAtAlpha = seen(outside); out.outsideText = text(outside);
    var all = q('[data-uc-image-show-all]'); out.allSeen = seen(all);
  }

  /* G: the weekday ticks and the date. */
  var date = q('input[name="date"]');
  function days() { return qa('[data-uc-repeat-day]').filter(function (b) { return b.checked; }).map(function (b) { return +b.value; }); }
  if (date && q('[data-uc-repeat-day]')) {
    out.follow = !!q('[data-uc-repeat-follow]');
    date.value = '2026-11-18'; change(date); out.daysWed = days();
    date.value = '2026-11-20'; change(date); out.daysFri = days();
    var mon = q('[data-uc-repeat-day][value="1"]'); mon.click();
    date.value = '2026-11-24'; change(date); out.daysAfterTouch = days();
  }
  /* A.4 (3.107.1): the series thumbnail, measured once the picture has
     decoded. Measured at once, a picture that fails to load still has its box,
     and a moment later it is replaced by its file name. The series is Alpha
     again by now. */
  setTimeout(function () {
    var th = q('[data-uc-card="series"] .uc-prefill-thumb');
    out.thumb = th && seen(th) && th.complete && th.naturalWidth > 0
      ? { w: Math.round(th.getBoundingClientRect().width), h: Math.round(th.getBoundingClientRect().height), r: getComputedStyle(th).borderTopLeftRadius } : null;
    finish();
  }, 500);
}, 600); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rv_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inject = '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script>';
    $html = ( false !== stripos( $p['html'], '</body>' ) ) ? preg_replace( '#</body>#i', $inject . '</body>', $p['html'], 1 ) : $p['html'] . $inject;
    $inner = __DIR__ . '/release-3107-live-' . $name . '.html';
    file_put_contents( $inner, $html );
    $files[ $name ] = __DIR__ . '/release-3107-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args = array_slice( $argv, 1 );
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
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rv_check( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rv_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rv_check( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

/* DESIGN.md's tokens, as the browser reports them. */
$P_BORDER = 'rgb(226, 229, 234)';  // --p-border #E2E5EA
$P_MUTED  = 'rgb(107, 103, 100)';  // --p-muted #6B6764
$P_PANEL  = 'rgb(255, 255, 255)';  // --p-panel #FFFFFF
$ACCENT   = 'rgb(14, 118, 128)';   // --uc-accent-text #0E7680

foreach ( array_keys( $pages ) as $t ) {
    if ( ! isset( $g[ $t ] ) ) { continue; }
    $add   = 0 === strpos( $t, 'add-' );
    $phone = false !== strpos( $t, 'phone' );

    /* A and H. */
    $main = $add
        ? array( 'series', 'title', 'schedule', 'location', 'registration', 'details', 'faqs', 'classification', 'notifications' )
        : array( 'series', 'title', 'schedule', 'location', 'registration', 'details', 'faqs', 'classification', 'notifications' ); // Other details went with the private tick (3.109.0)
    $side = array( 'links', 'display', 'privacy', 'access' );   // Team and access on both since 3.110.1
    rv_check( $main === $v( $t, 'main' ), "PLANT A: $t: the main column reads " . json_encode( $v( $t, 'main' ) ) );
    rv_check( $side === $v( $t, 'side' ), "PLANT A: $t: the side column reads " . json_encode( $v( $t, 'side' ) ) );

    /* A.4 (3.107.1): the series card is the first card on the page, as wide as
       the main column, 16px above Title, and on Add its thumbnail is 160x90 at
       the cards' 12px radius (3.108.1; 56x32 until then). */
    $sb = (array) $v( $t, 'seriesBox' ); $tb = (array) $v( $t, 'titleBox' ); $mb = (array) $v( $t, 'mainBox' );
    rv_check( 1 === $v( $t, 'seriesCount' ), "PLANT A.4: $t: " . (int) $v( $t, 'seriesCount' ) . ' series card(s) on the page' );
    rv_check( $sb && $tb && $mb && $sb['top'] < $tb['top'] && $sb['left'] === $mb['left'] && $sb['w'] === $mb['w'],
        "PLANT A.4: $t: the series card is not first and full width in the main column: " . json_encode( array( 'series' => $sb, 'title' => $tb, 'main' => $mb ) ) );
    rv_check( $sb && $tb && 16 === $tb['top'] - $sb['bottom'], "PLANT A.4: $t: series to Title is " . ( $sb && $tb ? $tb['top'] - $sb['bottom'] : '?' ) . 'px, not 16' );
    rv_check( (int) $v( $t, 'firstCardTop' ) === ( $sb ? $sb['top'] : -1 ), "PLANT A.4: $t: a card sits above the series card at " . $v( $t, 'firstCardTop' ) );
    if ( $add ) {
        rv_check( array( 'w' => 160, 'h' => 90, 'r' => '12px' ) === $v( $t, 'thumb' ), "PLANT A.4: $t: the series thumbnail is " . json_encode( $v( $t, 'thumb' ) ) . ', not 160x90 at 12px' );
    }
    rv_check( 0 === $v( $t, 'unnamed' ), "PLANT H: $t: " . $v( $t, 'unnamed' ) . ' card(s) carry no data-uc-card' );
    foreach ( (array) $v( $t, 'cardStyles' ) as $cs ) {
        rv_check( false !== strpos( $cs, "$P_BORDER 1px 18px 12px" ), "$t: a card is not the standard card: $cs" );
    }
    $titles = (array) $v( $t, 'titles' );
    rv_check( in_array( 'Series and language', $titles, true ) && in_array( 'Links', $titles, true ) && in_array( 'Registration', $titles, true ) && ! in_array( 'Donate', $titles, true ),
        "$t: the card titles read " . json_encode( $titles ) );

    /* D.6 */
    rv_check( 0 === $v( $t, 'logout' ) && 1 === $v( $t, 'signout' ), "PLANT D.6: $t: " . $v( $t, 'logout' ) . ' Log out, ' . $v( $t, 'signout' ) . ' Sign out' );
    rv_check( $phone === (bool) $v( $t, 'topbar' ), "$t: the top bar is " . ( $v( $t, 'topbar' ) ? 'shown' : 'hidden' ) );

    /* E */
    $bar = (array) $v( $t, 'bar' );
    $bs  = (array) $v( $t, 'barStyle' );
    $sc  = (array) $v( $t, 'barScrolled' );
    rv_check( isset( $bar['position'] ) && 'sticky' === $bar['position'] && '0px' === $bs['bottom'], "PLANT E: $t: the bar is not sticky to the foot: " . json_encode( array( $bar['position'] ?? null, $bs['bottom'] ?? null ) ) );
    rv_check( $v( $t, 'scrolled' ) > 0 && isset( $sc['bottom'] ) && abs( $sc['bottom'] - $v( $t, 'innerH' ) ) <= 1 && $sc['top'] < $v( $t, 'innerH' ),
        "PLANT E: $t: scrolled " . $v( $t, 'scrolled' ) . "px, the bar is not at the window's foot: " . json_encode( array( $sc, $v( $t, 'innerH' ) ) ) );
    rv_check( isset( $bs['bg'] ) && $P_PANEL === $bs['bg'] && "$P_BORDER 1px" === $bs['top'] && '0px' === $bs['left'] && '0px' === $bs['radius'],
        "$t: the bar is not --p-panel with one --p-border top rule: " . json_encode( $bs ) );
    $cb = (array) $v( $t, 'contentBox' );
    rv_check( isset( $cb['left'], $bar['left'] ) && abs( $cb['left'] - $bar['left'] ) <= 1 && abs( $cb['right'] - $bar['right'] ) <= 1, "$t: the bar is not the editor's full width: " . json_encode( array( $cb, $bar['left'] ?? null, $bar['right'] ?? null ) ) );
    $note = (array) $v( $t, 'barNote' );
    rv_check( isset( $note['size'] ) && '12px' === $note['size'] && $P_MUTED === $note['color'] && $note['seen'], "$t: the bar's note is not 12px --p-muted: " . json_encode( $note ) );
    $btns   = (array) $v( $t, 'buttons' );
    $labels = array_column( $btns, 't' );
    $want   = $add ? array( 'Save draft', 'Publish' ) : array( 'Delete', 'Cancel event', 'Save changes' );
    usort( $btns, function ( $a, $b ) { return ( abs( $a['top'] - $b['top'] ) > 8 ) ? $a['top'] - $b['top'] : $a['left'] - $b['left']; } );
    rv_check( $want === array_column( $btns, 't' ), "$t: the bar's buttons read " . json_encode( array_column( $btns, 't' ) ) . ' left to right, wanted ' . json_encode( $want ) );
    foreach ( $btns as $b ) {
        rv_check( $b['left'] >= 0 && $b['right'] <= $v( $t, 'innerW' ) && $b['bottom'] <= $v( $t, 'innerH' ), "PLANT E: $t: " . $b['t'] . ' is outside the window: ' . json_encode( $b ) );
    }
    rv_check( $add !== (bool) $v( $t, 'actionsNote' ), "$t: the Cancel and Delete line is " . ( $v( $t, 'actionsNote' ) ? 'shown' : 'not shown' ) );

    /* B.2 and B.3 */
    $reg = (array) $v( $t, 'reg' );
    if ( $add ) {
        rv_check( isset( $reg['ticked'] ) && false === $reg['ticked'] && false === $reg['bodySeen'] && array( 'rsvp_enabled' ) === $reg['visibleControls'],
            "PLANT B.2: $t: with RSVPs off Registration shows more than the tick: " . json_encode( $reg ) );
        $tg = (array) $v( $t, 'regToggled' );
        rv_check( isset( $tg['bodySeen'] ) && $tg['ticked'] && $tg['bodySeen'] && $tg['capacity'] && $tg['email'] && $tg['questions'], "PLANT B.2: $t: ticking Accept RSVPs did not reveal the rest: " . json_encode( $tg ) );
        rv_check( isset( $tg['capPlaceholder'] ) && 'No limit' === $tg['capPlaceholder'] && ! $tg['zeroHint'] && 'Questions for registrants' === $tg['questionsTitle'], "$t: capacity is not an empty box saying No limit, with no 0 hint: " . json_encode( $tg ) );
        $bk = (array) $v( $t, 'regBack' );
        rv_check( isset( $bk['bodySeen'] ) && ! $bk['ticked'] && ! $bk['bodySeen'], "PLANT B.2: $t: unticking did not close it again: " . json_encode( $bk ) );
    } else {
        rv_check( isset( $reg['bodySeen'] ) && $reg['ticked'] && $reg['bodySeen'], "PLANT B.2: $t: an event taking RSVPs does not show its Registration controls: " . json_encode( $reg ) );
    }

    /* D */
    $l = (array) $v( $t, 'links' );
    rv_check( isset( $l['title'] ) && 'Links' === $l['title'] && $l['donate'] && $l['volunteer'] && 0 === $l['hints'], "PLANT D.1: $t: Links is not the donation dropdown and Volunteer: " . json_encode( $l ) );
    $o = (array) $v( $t, 'org' );
    rv_check( isset( $o['gridSeen'] ) && ! $o['gridSeen'] && '+ Add organizer' === $o['add'] && $o['addSeen'] && $v( $t, 'orgOpen' ), "PLANT D.2: $t: Organizer is not the + Add organizer picker: " . json_encode( array( $o, $v( $t, 'orgOpen' ) ) ) );
    if ( ! $add ) {
        rv_check( array( 'Alpha', 'Beta' ) === ( $o['chips'] ?? null ), "$t: the organizer chips read " . json_encode( $o['chips'] ?? null ) );
    }
    $addr = (array) $v( $t, 'addr' );
    rv_check( array( 'Place name', 'Street', 'City', 'State', 'ZIP' ) === array_column( $addr, 'ph' ), "PLANT D.3: $t: the address placeholders read " . json_encode( array_column( $addr, 'ph' ) ) );
    foreach ( $addr as $a ) {
        rv_check( $P_MUTED === $a['phColor'] && 'Place name' !== $a['label'] && 'Street' !== $a['label'] && $a['seen'], "PLANT D.3: $t: " . $a['name'] . ' is not labelled by an --p-muted placeholder alone: ' . json_encode( $a ) );
    }
    $ins = (array) $v( $t, 'insert' );
    rv_check( isset( $ins['text'] ) && 'Insert image' === $ins['text'] && "$ACCENT 1px" === $ins['border'] && $ACCENT === $ins['color'] && ! $ins['chevron'] && 'none' === $ins['marker'],
        "PLANT D.4: $t: Insert image is not an outlined accent button with no arrow: " . json_encode( $ins ) );
    rv_check( array( 'Nobody else yet.' ) === $v( $t, 'empties' ) && '' === $v( $t, 'pickerCount' ), "PLANT D.7: $t: Notifications shows " . json_encode( array( $v( $t, 'empties' ), $v( $t, 'pickerCount' ) ) ) );

    /* D.5 */
    rv_check( ( $add ? '' : 'setalpha' ) === $v( $t, 'faqStart' ), "PLANT D.5: $t: the FAQ set starts on " . json_encode( $v( $t, 'faqStart' ) ) );
    rv_check( '' === $v( $t, 'faqNone' ) && 'setalpha' === $v( $t, 'faqAlpha' ) && '' === $v( $t, 'faqBeta' ),
        "PLANT D.5: $t: the FAQ set did not follow the series (none, Alpha, two Betas): " . json_encode( array( $v( $t, 'faqNone' ), $v( $t, 'faqAlpha' ), $v( $t, 'faqBeta' ) ) ) );

    /* F */
    rv_check( array( '601', '602', '603' ) === $v( $t, 'picsNone' ), "PLANT F: $t: no series does not show the whole folder: " . json_encode( $v( $t, 'picsNone' ) ) );
    rv_check( array( '601' ) === $v( $t, 'picsAlpha' ) && array( '602' ) === $v( $t, 'picsBeta' ), "PLANT F: $t: changing the series did not re-filter without a save: " . json_encode( array( $v( $t, 'picsAlpha' ), $v( $t, 'picsBeta' ) ) ) );
    rv_check( false === $v( $t, 'outsideAtBeta' ) && true === $v( $t, 'pickedKept' ) && array( '601', '602' ) === $v( $t, 'picsAfterPick' ) && true === $v( $t, 'outsideAtAlpha' )
        && 'Not in this series. It stays chosen until you pick another.' === $v( $t, 'outsideText' ),
        "PLANT F: $t: a chosen picture outside the series did not stay chosen with its line: " . json_encode( array( $v( $t, 'outsideAtBeta' ), $v( $t, 'pickedKept' ), $v( $t, 'picsAfterPick' ), $v( $t, 'outsideAtAlpha' ) ) ) );

    /* G */
    if ( $add ) {
        rv_check( true === $v( $t, 'follow' ) && array( 3 ) === $v( $t, 'daysWed' ) && array( 5 ) === $v( $t, 'daysFri' ) && array( 1, 5 ) === $v( $t, 'daysAfterTouch' ),
            "PLANT G: $t: the weekday did not follow the date until touched: " . json_encode( array( $v( $t, 'daysWed' ), $v( $t, 'daysFri' ), $v( $t, 'daysAfterTouch' ) ) ) );
    } else {
        rv_check( false === $v( $t, 'follow' ) && array( 5 ) === $v( $t, 'daysWed' ) && array( 5 ) === $v( $t, 'daysFri' ),
            "PLANT G: $t: Edit event moved the weekday with the date: " . json_encode( array( $v( $t, 'follow' ), $v( $t, 'daysWed' ), $v( $t, 'daysFri' ) ) ) );
    }
}

/* Screenshots for DESIGN.md: the whole page, unframed, at each width. */
if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( $pages as $name => $p ) {
        $png = __DIR__ . '/screens/editor-3107-' . $name . '.png';
        $src = $p['w'] < 504 ? $files[ $name ] : __DIR__ . '/release-3107-live-' . $name . '.html';
        if ( $p['w'] < 504 ) {
            file_put_contents( $src, str_replace( 'height:900px', 'height:3200px', file_get_contents( $src ) ) );
        }
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $p['w'] ) . ',3200 --virtual-time-budget=6000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $src ) . '" 2>NUL' );
        if ( $p['w'] < 504 ) {
            file_put_contents( $src, str_replace( 'height:3200px', 'height:900px', file_get_contents( $src ) ) );
        }
        rv_check( file_exists( $png ), "no screenshot written for $name" );
    }
}

if ( $fails ) {
    echo 'RELEASE 3.107.0 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.107.0 live: card order and names with the series card first in the main column and its thumbnail at 160x90, Registration closed and open, Links, the Organizer picker, the address labels, Insert image, one sign-out, one empty line, the FAQ set and the pictures following the series, the weekday following the date, and the sticky bar, on Add and Edit at 1280px and 390px.\n";
