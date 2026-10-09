<?php
/**
 * 3.110.3 IN A REAL BROWSER: THE PICKER'S TWO GROUPS, THE UPLOAD, THE IMAGES
 * SCREEN'S THREE PLACES, THE APPROVAL QUESTION AND SET TEAM.
 *
 *     php .claude/release-31103-live.php            writes the pages
 *     php .claude/release-31103-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31103-live.php --shots    --run, and writes .claude/screens/caladmin-31103-*.png
 *
 * Every page at 1280px, 430px and 390px: nothing past the right edge, no
 * sideways scroll, no tap target under 44px below 600px.
 *
 *   A    Edit event's picker, opened, with the search filled in: the series
 *        group first and Other images second, never a submitted file; the
 *        series group follows the dropdown, and is empty with no series;
 *        "Show active images only" leaves the active ones
 *        PLANT A: the series group does not follow the dropdown
 *   B/C  "+ Upload a picture for this event" in the outlined style; a 1200 by
 *        675 file shows in the preview with Event-specific; a 1201-wide one
 *        is refused with the one sentence
 *        PLANT B.JS: the editor's own check lets a 1201-wide file through
 *   A.3  the Images screen under All, Series pictures, Other images and
 *        Submitted, each card naming its place, the submitted one its event
 *   D    the approval dialog, opened from Approve, asking where the picture
 *        goes with the event's series chosen
 *   E    Set team on the events list, open, counting the ticks
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'get_post_mime_type' ) ) { function get_post_mime_type( $id ) { return 'image/jpeg'; } }

$fails = array();
function rp( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rp_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

/* ---- The world: three places, two series, upcoming and past events. ----- */
function rp_meta_ok( $id, $c ) {
    if ( isset( $c['relation'] ) || isset( $c[0] ) ) {
        $or = isset( $c['relation'] ) && 'OR' === strtoupper( $c['relation'] ); $any = false; $all = true;
        foreach ( $c as $k => $x ) { if ( 'relation' === $k ) { continue; } $r = rp_meta_ok( $id, $x ); $any = $any || $r; $all = $all && $r; }
        return $or ? $any : $all;
    }
    $has = isset( $GLOBALS['kit_meta'][ (int) $id ][ $c['key'] ] ); $v = $has ? $GLOBALS['kit_meta'][ (int) $id ][ $c['key'] ] : '';
    $s = is_array( $v ) ? serialize( $v ) : (string) $v; $w = isset( $c['value'] ) ? $c['value'] : null;
    switch ( isset( $c['compare'] ) ? strtoupper( $c['compare'] ) : '=' ) {
        case 'EXISTS': return $has; case 'NOT EXISTS': return ! $has;
        case 'REGEXP': return $has && (bool) preg_match( '#' . $w . '#', $s );
        case 'LIKE': return $has && false !== strpos( $s, (string) $w );
        case 'IN': return $has && in_array( $s, array_map( 'strval', (array) $w ), true );
        case '>=': return $has && $s >= (string) $w;
        default: return $has && $s === (string) $w;
    }
}
function rp_query( $a ) {
    $type = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $stat = isset( $a['post_status'] ) ? (array) $a['post_status'] : array();
    $ids = array_keys( $GLOBALS['kit_posts'] ); rsort( $ids ); $out = array();
    foreach ( $ids as $id ) {
        $p = $GLOBALS['kit_posts'][ $id ];
        if ( $type !== $p->post_type ) { continue; }
        if ( ! empty( $a['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', $a['post__in'] ), true ) ) { continue; }
        /* Events answer their status and nothing else, as every live page here
           does; pictures answer their folder and tag questions properly too. */
        if ( $stat && ! in_array( 'any', $stat, true ) && ! in_array( $p->post_status, $stat, true ) ) { continue; }
        if ( 'attachment' === $type || ! empty( $GLOBALS['rp_strict'] ) ) {
            if ( ! empty( $a['meta_query'] ) && ! rp_meta_ok( $id, $a['meta_query'] ) ) { continue; }
            if ( ! empty( $a['tax_query'] ) ) {
                $ok = true;
                foreach ( $a['tax_query'] as $k => $t ) {
                    if ( 'relation' === $k || ! is_array( $t ) || ! isset( $t['taxonomy'] ) ) { continue; }
                    $on = isset( $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] : array();
                    $ok = $ok && ( ( isset( $t['operator'] ) && 'NOT EXISTS' === $t['operator'] ) ? ! $on : (bool) array_intersect( array_map( 'intval', (array) $t['terms'] ), $on ) );
                }
                if ( ! $ok ) { continue; }
            }
        }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
    return $per > 0 ? array_slice( $out, 0, $per ) : $out;
}
$GLOBALS['kit_query'] = 'rp_query';
/* get_posts() answers properly for every type: active_ids() and submitted_with()
   ask it about events by meta. */
$GLOBALS['kit_get_posts'] = function ( $a ) { $GLOBALS['rp_strict'] = true; $r = rp_query( $a ); $GLOBALS['rp_strict'] = false; return $r; };
$GLOBALS['kit_thumbs'] = true;

$svg = function ( $fill ) { return 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675"><rect width="1200" height="675" fill="' . $fill . '"/></svg>' ); };
foreach ( array(
    601 => array( 'calendar/alpha-clinic-drop-in.jpg', 11, '#0E818C' ),
    602 => array( 'calendar/alpha-evening-walk.jpg', 11, '#6B6764' ),
    603 => array( 'calendar/beta-coffee-social.jpg', 12, '#46661F' ),
    604 => array( 'calendar-other/strut-art-show-flyer.jpg', 0, '#373433' ),
    605 => array( 'calendar-other/one-off-community-dinner.jpg', 0, '#0E7680' ),
    606 => array( 'calendar-submissions/submission-20261001-abc.jpg', 0, '#AD1C0D' ),
) as $pid => $d ) {
    kit_write_post( array( 'ID' => $pid, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => '' ) );
    update_post_meta( $pid, '_wp_attached_file', $d[0] );
    $GLOBALS['kit_images'][ $pid ] = $svg( $d[2] );
    if ( $d[1] ) { $GLOBALS['kit_terms'][ $pid ]['uc_series'] = array( $d[1] ); }
}
function rp_event( $title, $status, $series, $meta = array() ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => $title, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+6 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market Street, San Francisco' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 11 );
    if ( $series ) { $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( $series ); }
    return $id;
}
$edit = rp_event( 'Community dinner at the clinic', 'publish', 11, array( '_thumbnail_id' => 601 ) );
rp_event( 'Strut art show opening night', 'publish', 0, array( '_thumbnail_id' => 604 ) );
rp_event( 'Last year\'s walk', 'publish', 11, array( '_uc_event_date' => '2025-03-01', '_thumbnail_id' => 602 ) );
$sub = rp_event( 'Neighborhood bake sale for the clinic', 'pending', 11, array( SFAF_Submit::META_IMAGE => 606, SFAF_Request::META_NAME => 'Pat Example', SFAF_Request::META_EMAIL => 'pat@example.org' ) );
SFAF_Media::forget_active();

/* Two calendar people and a team, for Set team's choices. */
$GLOBALS['kit_users'] = array(); $GLOBALS['kit_umeta'] = array();
foreach ( array( 3 => 'Ben Young', 4 => 'Ana Martinez' ) as $uid => $uname ) {
    $u = new WP_User(); $u->ID = $uid; $u->display_name = $uname; $u->user_email = 'u' . $uid . '@sfaf.org'; $u->user_login = 'u' . $uid;
    $GLOBALS['kit_users'][] = $u;
    $GLOBALS['kit_umeta'][ $uid ] = array( '_uc_calendar_role' => 3 === $uid ? 'editor' : 'contributor' );
    $GLOBALS['kit_refused'][] = $uid;
}
$GLOBALS['kit_options']['sfaf_teams'] = array( 'prog' => array( 'id' => 'prog', 'name' => 'Programa Latino', 'users' => array( 3, 4 ), 'created' => 1, 'updated' => 1 ) );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$admin  = new WP_User();
function rp_page( $method, $args = array(), $get = array() ) {
    return rp_capture( function () use ( $method, $args, $get ) { $_GET = $get; kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args ); } );
}
$html = array(
    'edit'      => rp_page( 'render_event_form', array( $admin, $edit ) ),
    'add'       => rp_page( 'render_event_form', array( $admin, 0 ) ),
    'images'    => rp_page( 'render_media', array( $admin ) ),
    'imgseries' => rp_page( 'render_media', array( $admin ), array( 'tag' => 'series' ) ),
    'imgother'  => rp_page( 'render_media', array( $admin ), array( 'tag' => 'other' ) ),
    'imgsub'    => rp_page( 'render_media', array( $admin ), array( 'tag' => 'submitted' ) ),
    'pending'   => rp_page( 'render_pending', array( $admin ) ),
    'events'    => rp_page( 'render_events', array( $admin ) ),
);
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
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
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
    if (el.closest('.uc-tpl-editor, [hidden], #uc-sidebar, .uc-portal-sidebar')) { return; }
    var r = el.getBoundingClientRect(); if (!r.width || !r.height) { return; }
    /* A hit area drawn as an absolute ::after counts, as in 3.110.2's check. */
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
function change(el) { el.dispatchEvent(new Event('change', { bubbles: true })); }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
function picFile(w, h, done) {
  var c = document.createElement('canvas'); c.width = w; c.height = h;
  var x = c.getContext('2d'); x.fillStyle = '#0E818C'; x.fillRect(0, 0, w, h);
  c.toBlob(function (b) { done(new File([b], 'clinic-' + w + '.png', { type: 'image/png' })); }, 'image/png');
}
function setFile(input, file) { var dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; change(input); }

window.addEventListener('load', function () { setTimeout(function () {
  var main = q('.uc-portal-main') || document.body;

  /* ---- A and B/C: the editor ---- */
  var picker = q('[data-uc-image-picker][data-uc-image-groups]');
  if (picker) {
    picker.open = true;
    var list = q('[data-uc-filter-list]', picker);
    function shownRows() { return qa('[data-uc-image-option]', list).filter(function (r) { var row = r.closest('label'); return r.value !== '0' && seen(row); }).map(function (r) { return r.value; }); }
    function heads() { return qa('[data-uc-image-group-head]', list).filter(seen).map(function (h) { return h.getAttribute('data-uc-image-group-head'); }); }
    out.heads = heads();
    out.rowsStart = shownRows();
    out.choose = seen(q('[data-uc-image-choose]', picker));
    var series = q('[data-uc-series-select]');
    if (series) {
      series.value = '12'; change(series); out.rowsBeta = shownRows();
      series.value = '0'; change(series); out.rowsNone = shownRows(); out.chooseNone = seen(q('[data-uc-image-choose]', picker));
      series.value = '11'; change(series); out.rowsAlpha = shownRows();
    }
    var search = q('[data-uc-image-search]', picker);
    out.searchSeen = seen(search);
    if (search) { search.value = 'flyer'; search.dispatchEvent(new Event('input', { bubbles: true })); out.rowsSearch = shownRows(); }
    out.pickerPast = past(picker);
    out.pickerSmall = phone ? small(picker) : [];
    out.pickerBox = rect(picker);
    if (search) { search.value = ''; search.dispatchEvent(new Event('input', { bubbles: true })); }
    var tick = q('[data-uc-image-active-only]', picker);
    if (tick) { tick.click(); out.rowsActive = shownRows(); tick.click(); }
    out.submittedOffered = !!q('[data-uc-image-option][value="606"]', document);
    /* The upload: the outlined control, a right file, then a wrong one. */
    var up = q('[data-uc-event-upload]'), btn = up ? up.closest('label') : null;
    if (btn) { var cs = getComputedStyle(btn); out.upload = { text: text(btn), border: cs.borderTopWidth + ' ' + cs.borderTopStyle, color: cs.color, h: Math.round(btn.getBoundingClientRect().height) }; }
    out.past = past(main);
    out.small = phone ? small(main) : [];
    if (up) {
      var pill = q('[data-uc-img-source-tag]'), img = q('[data-uc-image-preview-img]'), err = q('[data-uc-event-upload-error]');
      picFile(1200, 675, function (good) {
        setFile(up, good);
        setTimeout(function () {
          out.goodPill = text(pill); out.goodSrc = (img.getAttribute('src') || '').slice(0, 5); out.goodErr = seen(err);
          out.goodName = text(q('[data-uc-image-current]', picker));
          picFile(1201, 675, function (bad) {
            setFile(up, bad);
            setTimeout(function () { out.badErr = seen(err) ? text(err) : ''; out.badKept = up.files.length; out.badPill = text(pill); out.badName = text(q('[data-uc-image-current]', picker)); finish(); }, 600);
          });
        }, 600);
      });
      return;
    }
    finish();
    return;
  }

  /* ---- A.3: the Images screen ---- */
  var grid = q('.uc-media-grid');
  if (grid || q('.uc-media-library')) {
    out.cards = qa('[data-uc-media-place]').filter(seen).map(function (li) { return li.getAttribute('data-uc-media-place') + ':' + text(q('.uc-media-pill', li)); });
    var with_ = q('.uc-media-with'); out.withText = text(with_);
    var s = q('.uc-media-search input');
    if (s) { s.value = 'flyer'; s.dispatchEvent(new Event('input', { bubbles: true })); out.searched = qa('[data-uc-media-place]').filter(seen).length; s.value = ''; s.dispatchEvent(new Event('input', { bubbles: true })); }
    out.filterSeen = seen(q('.uc-media-filter select'));
    out.activeSeen = seen(q('.uc-media-active input'));
    out.past = past(main); out.small = phone ? small(main) : [];
    finish(); return;
  }

  /* ---- D: the approval dialog ---- */
  var approve = qa('[data-uc-approve-ask]').filter(function (b) { return /^Approve$/.test(text(b)); })[0];
  if (approve) {
    out.past = past(main); out.small = phone ? small(main) : [];
    approve.click();
    setTimeout(function () {
      var dlg = q('dialog[open]');
      var box = dlg ? q('[data-uc-approve-picture]', dlg) : null;
      out.dialog = dlg ? { box: rect(dlg), vw: document.documentElement.clientWidth, picture: !!box,
        series: !!(box && q('input[value="series"]:checked', box)), select: box ? (q('select', box) || {}).value : null,
        legend: box ? text(q('legend', box)) : '', past: past(dlg), small: phone ? small(dlg) : [] } : null;
      finish();
    }, 500);
    return;
  }

  /* ---- E: Set team ---- */
  var team = q('[data-uc-bulk-team]');
  if (team) {
    team.open = true;
    var go = q('button[value="team"]', team);
    out.team = { summary: text(q('summary', team)), teams: qa('input[name="bulk_access_teams[]"]', team).length, people: qa('input[name="bulk_access_people[]"]', team).length, button: text(go) };
    var one = q('[data-uc-tick-one]');
    if (one) { one.click(); out.team.after = text(go); out.team.disabledAfter = go.disabled; }
    out.past = past(main); out.small = phone ? small(main) : [];
  }
  finish();
}, 700); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rp( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inner = __DIR__ . '/release-31103-live-' . $name . '.html';
    file_put_contents( $inner, preg_replace( '#</body>(?![\s\S]*</body>)#i', '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $p['html'], 1 ) );
    $files[ $name ] = __DIR__ . '/release-31103-live-' . $name . '-frame.html';
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
    if ( null === $g[ $name ] ) { rp( false, "$name: Chrome returned no probe block" ); continue; }
    rp( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rp( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( isset( $g[ $name ]['past'] ) ) { rp( array() === $g[ $name ]['past'], "$name: runs past the screen: " . json_encode( $g[ $name ]['past'] ) ); }
    if ( isset( $g[ $name ]['small'] ) ) { rp( array() === $g[ $name ]['small'], "$name: tap targets under 44px: " . json_encode( $g[ $name ]['small'] ) ); }
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };
$rule = 'Pictures must be 1200 by 675 pixels and under 500KB.';

foreach ( array( 'desktop', 'wide', 'phone' ) as $wn ) {
    /* ---- A ---- */
    $t = "edit-$wn";
    rp( array( 'series', 'other' ) === $v( $t, 'heads' ), "A: $t: the groups are not Series pictures then Other images: " . json_encode( $v( $t, 'heads' ) ) );
    rp( array( '602', '601', '605', '604' ) === $v( $t, 'rowsStart' ), "A: $t: Alpha's pictures then Other images, newest first, is not what shows: " . json_encode( $v( $t, 'rowsStart' ) ) );
    /* 601 is the event's chosen picture, which the 3.107.0 rule keeps on
       screen whatever the series, with its "Not in this series" line. */
    rp( array( '603', '601', '605', '604' ) === $v( $t, 'rowsBeta' ), "PLANT A: $t: the series group does not follow the dropdown to Beta: " . json_encode( $v( $t, 'rowsBeta' ) ) );
    rp( array( '601', '605', '604' ) === $v( $t, 'rowsNone' ) && true === $v( $t, 'chooseNone' ), "PLANT A: $t: with no series the series group is not empty and saying so: " . json_encode( array( $v( $t, 'rowsNone' ), $v( $t, 'chooseNone' ) ) ) );
    rp( array( '604' ) === $v( $t, 'rowsSearch' ) && true === $v( $t, 'searchSeen' ), "A: $t: the search for flyer does not narrow to the flyer: " . json_encode( $v( $t, 'rowsSearch' ) ) );
    rp( array( '601', '604' ) === $v( $t, 'rowsActive' ), "A: $t: Show active images only does not leave the two an upcoming event uses: " . json_encode( $v( $t, 'rowsActive' ) ) );
    rp( false === $v( $t, 'submittedOffered' ), "A: $t: a submitted picture is offered" );
    rp( array() === $v( $t, 'pickerPast' ) && array() === $v( $t, 'pickerSmall' ), "A: $t: the open picker: " . json_encode( array( $v( $t, 'pickerPast' ), $v( $t, 'pickerSmall' ) ) ) );
    /* ---- B/C ---- */
    $u = (array) $v( $t, 'upload' );
    rp( $u && '+ Upload a picture for this event' === $u['text'] && 0 === strpos( $u['border'], '1px solid' ), "C.1: $t: the upload is not the outlined control: " . json_encode( $u ) );
    rp( 'Event-specific' === $v( $t, 'goodPill' ) && 'blob:' === $v( $t, 'goodSrc' ) && false === $v( $t, 'goodErr' ) && 'clinic-1200.png' === $v( $t, 'goodName' ),
        "C.1: $t: a 1200 by 675 file does not show in the preview as Event-specific: " . json_encode( array( $v( $t, 'goodPill' ), $v( $t, 'goodSrc' ), $v( $t, 'goodErr' ), $v( $t, 'goodName' ) ) ) );
    /* A refused file gives back what was there: the event's own picture,
       under its own name, which is also what Save keeps. */
    if ( 0 === strpos( $t, 'edit-' ) ) {
        rp( 'Event-specific' === $v( $t, 'badPill' ) && 'alpha-clinic-drop-in.jpg' === $v( $t, 'badName' ), "C.1: $t: after a refused file the block does not show the event's own picture again: " . json_encode( array( $v( $t, 'badPill' ), $v( $t, 'badName' ) ) ) );
    }
    rp( $rule === $v( $t, 'badErr' ) && 0 === $v( $t, 'badKept' ), "PLANT B.JS: $t: a 1201-wide file is not refused with the one sentence: " . json_encode( array( $v( $t, 'badErr' ), $v( $t, 'badKept' ) ) ) );
    rp( array( '605', '604' ) === $v( "add-$wn", 'rowsStart' ) && true === $v( "add-$wn", 'choose' ), "A: add-$wn: with no series Add event offers Other images and says to choose a series: " . json_encode( array( $v( "add-$wn", 'rowsStart' ), $v( "add-$wn", 'choose' ) ) ) );

    /* ---- A.3 ---- */
    rp( array( 'submitted:Submitted', 'other:Other image', 'other:Other image', 'series:Series picture', 'series:Series picture', 'series:Series picture' ) === $v( "images-$wn", 'cards' ),
        "A.3: images-$wn: All images is not the three places, newest first: " . json_encode( $v( "images-$wn", 'cards' ) ) );
    rp( 3 === count( (array) $v( "imgseries-$wn", 'cards' ) ) && 2 === count( (array) $v( "imgother-$wn", 'cards' ) ) && array( 'submitted:Submitted' ) === $v( "imgsub-$wn", 'cards' ),
        "A.3: the three filters at $wn: " . json_encode( array( $v( "imgseries-$wn", 'cards' ), $v( "imgother-$wn", 'cards' ), $v( "imgsub-$wn", 'cards' ) ) ) );
    rp( 'Came with Neighborhood bake sale for the clinic, waiting for approval.' === $v( "imgsub-$wn", 'withText' ), "A.3: imgsub-$wn: the submitted picture does not name its event: " . json_encode( $v( "imgsub-$wn", 'withText' ) ) );
    rp( 1 === $v( "images-$wn", 'searched' ), "A.3: images-$wn: typing flyer does not narrow the grid to one: " . json_encode( $v( "images-$wn", 'searched' ) ) );

    /* ---- D ---- */
    $d = (array) $v( "pending-$wn", 'dialog' );
    rp( $d && true === $d['picture'] && true === $d['series'] && '11' === $d['select'] && 'Where does the picture go?' === $d['legend'],
        "D: pending-$wn: the approval dialog does not ask where the picture goes, with its series chosen: " . json_encode( $d ) );
    rp( $d && array() === $d['past'] && array() === $d['small'] && $d['box']['right'] <= $d['vw'], "D: pending-$wn: the dialog does not fit: " . json_encode( $d ) );

    /* ---- E ---- */
    $e = (array) $v( "events-$wn", 'team' );
    rp( $e && 'Set team' === $e['summary'] && 1 === $e['teams'] && $e['people'] > 0 && 'Set team on 0 events' === $e['button'] && 'Set team on 1 event' === $e['after'] && false === $e['disabledAfter'],
        "E: events-$wn: Set team does not open the choices and count the ticks: " . json_encode( $e ) );
}

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( $pages as $name => $p ) {
        $png   = __DIR__ . "/screens/caladmin-31103-$name.png";
        $frame = __DIR__ . "/release-31103-shot-$name-frame.html";
        file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="release-31103-live-' . $name . '.html" style="display:block;width:' . $p['w'] . 'px;height:1400px;border:0"></iframe></body></html>' );
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $p['w'] ) . ',1400 --virtual-time-budget=8000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
        rp( file_exists( $png ), "no screenshot written for $name" );
    }
}

if ( $fails ) {
    echo 'RELEASE 3.110.3 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.110.3 live: at 1280px, 430px and 390px, the editor's picker in two groups that follow the series and never offer a submission, its search and active tick; the upload outlined, a right file previewed as Event-specific and a 1201-wide one refused; Images in three places with their filters; the approval dialog asking where the picture goes; Set team counting its ticks; nothing off the edge and nothing under 44px on a phone.\n";
