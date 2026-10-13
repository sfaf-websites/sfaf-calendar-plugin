<?php
/**
 * 3.111.0 IN A REAL BROWSER: THE PINNED SIDEBAR AND LIST, THE EVENT PAGE WITH
 * ITS ATTACHMENT AND THREE DETAIL LINES, BOTH PUBLIC FORMS, THE EDITOR'S NEW
 * FIELDS AND A REVIEWER'S PENDING.
 *
 *     php .claude/release-31110-live.php            writes the pages
 *     php .claude/release-31110-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31110-live.php --shots    --run, and writes .claude/screens/release-31110-*.png
 *
 * Every page at 1280px, 430px and 390px: nothing past the right edge and no
 * sideways scroll. On caladmin pages, and for every control this release
 * added on a public one, nothing under 44px tall below 600px.
 *
 *   A.1  the sidebar and the list open on the pinned event, its Featured mark
 *        drawn in the teal ink
 *        PLANT A.1 LIVE: the Featured mark not drawn
 *   B    no count anywhere on the page block
 *   E.2  the attachment button under the description, outlined, a new tab,
 *        Register's height
 *   F.2  Cost, Ages and Contact after the place
 *   G    both forms, with no Any other pictures
 *   E.1, F.1  Edit event: Cost in Registration, Age range, Contact and the
 *        Attachment in Event details
 *   C    a reviewer's Pending: one row, the approval dialog fits
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }

/* The event page's template, in a document with the calendar's stylesheet. */
function get_header() { echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="../public/css/calendar.css"></head><body>'; }
function get_footer() { echo '</body></html>'; }
function have_posts() { return ! empty( $GLOBALS['lv_left'] ) && $GLOBALS['lv_left']-- > 0; }
function the_post() {}
function the_title() { echo esc_html( get_the_title( get_the_ID() ) ); }
function the_content() { echo get_post_field( 'post_content', get_the_ID() ); }

$fails = array();
function lv( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function lv_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

/* ---- The world. ---------------------------------------------------------- */
function lv_query( $a ) {
    $type = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $stat = isset( $a['post_status'] ) ? (array) $a['post_status'] : array();
    $not  = isset( $a['post__not_in'] ) ? array_map( 'intval', (array) $a['post__not_in'] ) : array();
    $out  = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( $type !== $p->post_type || in_array( (int) $id, $not, true ) ) { continue; }
        if ( $stat && ! in_array( 'any', $stat, true ) && ! in_array( $p->post_status, $stat, true ) ) { continue; }
        $out[] = $p;
    }
    usort( $out, function ( $x, $y ) { return strcmp( (string) get_post_meta( $x->ID, '_uc_event_date', true ), (string) get_post_meta( $y->ID, '_uc_event_date', true ) ); } );
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { $out = wp_list_pluck( $out, 'ID' ); }
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
    return $per > 0 ? array_slice( $out, 0, $per ) : $out;
}
$GLOBALS['kit_query'] = 'lv_query'; $GLOBALS['kit_get_posts'] = 'lv_query'; $GLOBALS['kit_loop'] = true;
$svg = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675"><rect width="1200" height="675" fill="#0E818C"/></svg>' );
kit_write_post( array( 'ID' => 900, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => '' ) );
update_post_meta( 900, '_wp_attached_file', 'calendar-files/autumn-walk-route.pdf' );
$GLOBALS['kit_files'][900] = 'https://resources.sfaf.org/wp-content/uploads/calendar-files/autumn-walk-route.pdf';
function lv_event( $title, $days, $meta = array(), $status = 'publish' ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => $title, 'post_author' => 1,
        'post_content' => '<p>A gentle walk through the park with time to talk, and coffee afterwards for anybody who wants to stay.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+' . $days . ' days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market Street, San Francisco' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 11 );
    return $id;
}
lv_event( 'Coffee social', 2 );
lv_event( 'Harm reduction drop-in', 3 );
$pin = lv_event( 'Autumn walk for everybody, with a route map', 30, array(
    '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20', SFAF_Attachments::META_ID => 900, SFAF_Attachments::META_LABEL => 'Download the route map',
    '_uc_cost' => 'Free', '_uc_age_restriction' => '18 and over', '_uc_contact_name' => 'Pat Example', '_uc_contact_email' => 'pat@example.org', '_uc_location_name' => 'Golden Gate Park',
) );
$store =& sfaf_rsvp_count_store();
$store[ $pin ] = 7;

$sc = new SFAF_Shortcodes();
function lv_public( $body ) {
    if ( 0 === strpos( $body, 'THREW' ) ) { return $body; }
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="../public/css/calendar.css"></head><body>' . $body . '</body></html>';
}
$html = array();
$html['sidebar'] = lv_public( lv_capture( function () use ( $sc, $pin ) { echo $sc->render_sidebar( array( 'pin' => (string) $pin ), 5 ); } ) );
$html['list']    = lv_public( lv_capture( function () use ( $sc, $pin ) { $b = $sc->render_calendar_block( array( 'view' => 'list', 'toggle' => 'no', 'pin' => (string) $pin ) ); echo $b['html']; } ) );
$html['page']    = lv_capture( function () use ( $pin ) { $GLOBALS['lv_left'] = 1; $GLOBALS['kit_current'] = $pin; include $GLOBALS['kit_root'] . '/templates/single-uc_event.php'; } );
$html['request'] = lv_capture( function () { SFAF_Submissions::page_open( 'Request an event' ); kit_call( 'SFAF_Request', 'render_form', null, array( 'tok', 'someone@sfaf.org', array(), array() ) ); SFAF_Submissions::page_close(); } );
$html['submit']  = lv_capture( function () { SFAF_Submissions::page_open( 'Submit an event' ); kit_call( 'SFAF_Submit', 'render_form', null, array( (object) array( 'slug' => 'alpha', 'name' => 'Alpha', 'term_id' => 11 ), array(), array() ) ); SFAF_Submissions::page_close(); } );

/* caladmin: Edit event, and a reviewer's Pending. */
$GLOBALS['kit_users'] = array(); $GLOBALS['kit_umeta'] = array();
$u = new WP_User(); $u->ID = 21; $u->display_name = 'Ben Young'; $u->user_email = 'ben@sfaf.org'; $u->user_login = 'ben';
$GLOBALS['kit_users'][] = $u; $GLOBALS['kit_umeta'][21] = array( '_uc_calendar_role' => 'editor' ); $GLOBALS['kit_refused'][] = 21;
$GLOBALS['kit_options']['sfaf_teams'] = array( 'strut' => array( 'id' => 'strut', 'name' => 'Strut team', 'users' => array( 21 ), 'created' => 1, 'updated' => 1 ) );
SFAF_Submissions::set_organizer_review_team( 11, 'strut' );
$sub = lv_event( 'Neighborhood bake sale for the clinic', 9, array( SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_COMMUNITY,
    SFAF_Request::META_NAME => 'Pat Example', SFAF_Request::META_EMAIL => 'pat@example.org', SFAF_Request::META_AT => '2026-10-10 09:00:00' ), 'pending' );
$GLOBALS['kit_terms'][ $sub ]['uc_organizer'] = array( 11 );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$GLOBALS['portal'] = $portal;
$html['edit']   = lv_capture( function () use ( $portal, $pin ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $pin ) ); } );
$html['review'] = lv_capture( function () use ( $portal, $u ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_pending', $portal, array( $u ) ); } );

$pages = array();
foreach ( $html as $k => $h ) {
    foreach ( array( 'desktop' => 1280, 'wide' => 430, 'phone' => 390 ) as $wn => $w ) {
        $pages[ "$k-$wn" ] = array( 'w' => $w, 'html' => $h );
    }
}

$probe = <<<'JS'
(function () {
var P = window.RV_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { if (!el) { return ''; } var c = el.cloneNode(true); Array.prototype.forEach.call(c.querySelectorAll('.uc-visually-hidden, .uc-sr-only'), function (h) { h.parentNode.removeChild(h); }); return c.textContent.replace(/\s+/g, ' ').trim(); }
function shut(el) { var d = el.closest('details:not([open])'); return !!d && !(el.tagName === 'SUMMARY' && el.parentNode === d) && !(el.closest('summary') && el.closest('summary').parentNode === d); }
function seen(el) { if (!el || !el.getClientRects().length || shut(el)) { return false; } var cs = getComputedStyle(el); return cs.visibility !== 'hidden' && cs.display !== 'none' && +cs.opacity !== 0; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function name(el) { return el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : '') + (el.name ? '[' + el.name + ']' : '') + ' "' + (text(el) || el.getAttribute('aria-label') || el.value || '').slice(0, 28) + '"'; }
var phone = window.innerWidth < 600;
function small(root) {
  var bad = [];
  qa('a.uc-btn, button, select, textarea, summary, input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]), label', root).forEach(function (el) {
    if (!seen(el)) { return; }
    if (el.tagName === 'LABEL' && !q('input[type="checkbox"], input[type="radio"], input[type="file"]', el)) { return; }
    if (el.closest('.uc-tpl-editor, [hidden], #uc-sidebar, .uc-portal-sidebar, .mce-container, .wp-editor-wrap')) { return; }
    var r = el.getBoundingClientRect(); if (!r.width || !r.height) { return; }
    var h = r.height, a = getComputedStyle(el, '::after');
    if (a.content !== 'none' && a.position === 'absolute') { h = r.height - parseFloat(a.top) - parseFloat(a.bottom); }
    if (Math.round(h) < 44) { bad.push(name(el) + ' ' + Math.round(h) + 'px'); }
  });
  return bad.slice(0, 10);
}
function past(root) {
  var w = document.documentElement.clientWidth, bad = [];
  qa('*', root).forEach(function (el) {
    if (!seen(el) || el.closest('[hidden], #uc-sidebar, .uc-portal-sidebar')) { return; }
    var r = el.getBoundingClientRect(); if (r.width && r.right > w + 1) { bad.push(name(el) + ' right ' + Math.round(r.right)); }
  });
  return bad.slice(0, 8);
}
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  var main = q('.uc-portal-main') || document.body;
  out.past = past(main);
  var mark = q('[data-uc-featured]');
  if (mark) { var mc = getComputedStyle(mark); out.mark = { seen: seen(mark), text: text(mark), color: mc.color, h: Math.round(mark.getBoundingClientRect().height) }; }
  var firstRow = q('.uc-sidebar-row, .uc-lrow');
  if (firstRow) { out.firstTitle = text(q('.uc-sidebar-title, .uc-lrow-title', firstRow)); out.firstFeatured = !!q('[data-uc-featured]', firstRow); }
  out.words = text(q('.uc-sidebar, .uc-calendar, .uc-single') || document.body).slice(0, 4000);
  var att = q('[data-uc-attachment] .uc-actionbtn');
  if (att) {
    var ac = getComputedStyle(att);
    out.att = { text: text(att), seen: seen(att), border: ac.borderTopWidth + ' ' + ac.borderTopStyle, bg: ac.backgroundColor, target: att.getAttribute('target'), box: rect(att) };
    if (phone || P.indexOf('page') === 0) { out.attSmall = small(q('[data-uc-attachment]')); }
  }
  var body = q('.uc-single-body'), share = q('.uc-share, .uc-social-share, [class*="share"]'), reg = q('.uc-single-sidebar .uc-card-rsvp .uc-actionbtn');
  if (body) { out.bodyBottom = Math.round(body.getBoundingClientRect().bottom); }
  if (share) { out.shareTop = Math.round(share.getBoundingClientRect().top); }
  var mainCol = q('.uc-single-main'), aside = q('.uc-single-sidebar');
  if (mainCol && aside) { out.stacked = aside.getBoundingClientRect().top >= mainCol.getBoundingClientRect().bottom - 1; }
  if (reg) { out.reg = { text: text(reg), h: Math.round(reg.getBoundingClientRect().height) }; }
  out.facts = qa('[data-uc-fact]').map(function (f) { return f.getAttribute('data-uc-fact') + ':' + text(f); });
  var placeLi = qa('.uc-single-facts li').map(text);
  out.factOrder = placeLi;
  if (q('.uc-request-card, form[enctype]') && !q('.uc-portal-main')) {
    out.extra = !!q('[name^="uc_image_extra"]') || qa('form').some(function (f) { return /Any other pictures/.test(f.textContent); });
    out.hints = qa('.uc-hint').filter(seen).length;
    out.small = phone ? small(document.body) : [];
  }
  if (q('.uc-portal-main')) {
    out.small = phone ? small(main) : [];
    var cost = q('[data-uc-card="registration"] [name="listing_cost"]'), det = q('[data-uc-card="details"]');
    if (cost || det) {
      out.editor = { cost: seen(cost), age: seen(q('[name="listing_age"]', det)), contact: seen(q('[name="contact_email"]', det)),
        upload: seen(q('.uc-attachment-upload', det)), label: (q('[name="attachment_label"]', det) || {}).placeholder || '', other: !!q('[data-uc-card="other-details"]'),
        current: text(q('[data-uc-attachment-current]', det)) };
    }
    var rq = q('[data-uc-review-queue]');
    if (rq) {
      out.rows = qa('[data-uc-review-row]').length;
      var approve = qa('[data-uc-approve-ask]').filter(function (b) { return /^Approve$/.test(text(b)); })[0];
      if (approve) {
        approve.click();
        setTimeout(function () {
          var dlg = q('dialog[open]');
          out.dialog = dlg ? { box: rect(dlg), vw: document.documentElement.clientWidth, past: past(dlg), small: phone ? small(dlg) : [] } : null;
          finish();
        }, 500);
        return;
      }
    }
  }
  finish();
}, 700); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { lv( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inner = __DIR__ . '/release-31110-live-' . $name . '.html';
    file_put_contents( $inner, preg_replace( '#</body>(?![\s\S]*</body>)#i', '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $p['html'], 1 ) );
    $files[ $name ] = __DIR__ . '/release-31110-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args  = array_slice( $argv, 1 );
$shots = in_array( '--shots', $args, true );
if ( ! in_array( '--run', $args, true ) && ! $shots ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
$drive = function ( $file, $w ) use ( $chrome ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w + 20 ) . ',1000 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    return preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ? json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true ) : null;
};
$g = array();
foreach ( $files as $name => $file ) {
    $w = $pages[ $name ]['w'];
    $g[ $name ] = $drive( $file, $w );
    if ( null === $g[ $name ] ) { lv( false, "$name: Chrome returned no probe block" ); continue; }
    lv( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    lv( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    lv( array() === (array) $g[ $name ]['past'], "$name: runs past the screen: " . json_encode( $g[ $name ]['past'] ) );
    if ( 0 === strpos( $name, 'edit' ) || 0 === strpos( $name, 'review' ) ) {
        lv( array() === (array) ( isset( $g[ $name ]['small'] ) ? $g[ $name ]['small'] : array() ), "$name: tap targets under 44px: " . json_encode( isset( $g[ $name ]['small'] ) ? $g[ $name ]['small'] : null ) );
    }
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };
$teal = 'rgb(14, 118, 128)';
foreach ( array( 'desktop', 'wide', 'phone' ) as $wn ) {
    foreach ( array( 'sidebar', 'list' ) as $k ) {
        $m = (array) $v( "$k-$wn", 'mark' );
        lv( $m && true === $m['seen'] && 'featured' === strtolower( $m['text'] ) && $teal === $m['color'],
            "PLANT A.1 LIVE: $k-$wn: the Featured mark is not drawn in the teal ink: " . json_encode( $m ) );
        lv( 'Autumn walk for everybody, with a route map' === $v( "$k-$wn", 'firstTitle' ) && true === $v( "$k-$wn", 'firstFeatured' ), "A.1: $k-$wn: the list does not open on the pinned event: " . json_encode( $v( "$k-$wn", 'firstTitle' ) ) );
        lv( ! preg_match( '/\b7\s*(of|\/)|of 20|spots?\b|registered|places? left/i', (string) $v( "$k-$wn", 'words' ) ), "B: $k-$wn: a count is on the page" );
    }
    $a = (array) $v( "list-$wn", 'att' );
    lv( $a && 'Download the route map' === $a['text'] && $a['seen'], "E.2: list-$wn: the row's attachment button is not drawn: " . json_encode( $a ) );

    /* The event page. */
    $a = (array) $v( "page-$wn", 'att' );
    $r = (array) $v( "page-$wn", 'reg' );
    lv( $a && 'Download the route map' === $a['text'] && 0 === strpos( $a['border'], '1px solid' ) && 'rgb(255, 255, 255)' === $a['bg'] && '_blank' === $a['target'],
        "E.2: page-$wn: the attachment is not the outlined button in a new tab: " . json_encode( $a ) );
    lv( $a && $r && abs( $a['box']['h'] - $r['h'] ) <= 1 && 'Register' === $r['text'], "E.2: page-$wn: the attachment is not Register's height, or Register is not the label: " . json_encode( array( $a ? $a['box'] : null, $r ) ) );
    lv( $a && $a['box']['top'] >= (int) $v( "page-$wn", 'bodyBottom' ), "E.2: page-$wn: the attachment is not under the description" );
    /* UNDER THE DESCRIPTION, AND THAT IS THE ONE PLACE ASSERTED. The share
     * buttons are in the event card, beside the description on a desktop and
     * above it on a phone (order: -1, PROJECT.md 1), so no placement is both. */
    lv( array( 'cost:Cost Free', 'ages:Ages 18 and over', 'contact:Contact Pat Example, pat@example.org' ) === $v( "page-$wn", 'facts' ), "F.2: page-$wn: the three lines are not Cost, Ages and Contact: " . json_encode( $v( "page-$wn", 'facts' ) ) );
    $order = (array) $v( "page-$wn", 'factOrder' );
    $place = array_search( 'Golden Gate Park', $order, true );
    $costi = array_search( 'Cost Free', $order, true );
    lv( false !== $place && false !== $costi && $costi > $place, "F.2: page-$wn: Cost does not come after the place: " . json_encode( $order ) );
    lv( ! preg_match( '/\b7\s*(of|\/)|of 20|spots?\b|registered|places? left/i', (string) $v( "page-$wn", 'words' ) ), "B: page-$wn: a count is on the event page" );

    /* The forms. */
    foreach ( array( 'request', 'submit' ) as $k ) {
        lv( false === $v( "$k-$wn", 'extra' ), "G.2: $k-$wn: Any other pictures is on the form" );
    }

    /* Edit event. */
    $e = (array) $v( "edit-$wn", 'editor' );
    lv( $e && $e['cost'] && $e['age'] && $e['contact'] && $e['upload'] && 'Download event flyer' === $e['label'] && false === $e['other'] && 'autumn-walk-route.pdf' === $e['current'],
        "E.1/F.1: edit-$wn: the editor's new fields are not where they belong: " . json_encode( $e ) );

    /* A reviewer. */
    $d = (array) $v( "review-$wn", 'dialog' );
    lv( 1 === $v( "review-$wn", 'rows' ) && $d && array() === $d['past'] && array() === $d['small'] && $d['box']['right'] <= $d['vw'], "C: review-$wn: one row and a dialog that fits: " . json_encode( array( $v( "review-$wn", 'rows' ), $d ) ) );
}

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( $pages as $name => $p ) {
        $png   = __DIR__ . "/screens/release-31110-$name.png";
        $frame = __DIR__ . "/release-31110-shot-$name-frame.html";
        file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="release-31110-live-' . $name . '.html" style="display:block;width:' . $p['w'] . 'px;height:1400px;border:0"></iframe></body></html>' );
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $p['w'] ) . ',1400 --virtual-time-budget=8000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
        lv( file_exists( $png ), "no screenshot written for $name" );
    }
}

if ( $fails ) {
    echo 'RELEASE 3.111.0 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.111.0 live: at 1280px, 430px and 390px, the sidebar and list open on the pinned event marked Featured in teal; no count; the event page's attachment under the description, outlined, Register's height; Cost, Ages and Contact after the place; both forms without other pictures; the editor's new fields in their cards; a reviewer's one row and its dialog; nothing off the edge.\n";
