<?php
/**
 * 3.140.0 IN A BROWSER: SHOULD THEY BE TOLD?, AND THE SERIES & CATEGORIES PAGE.
 *
 *     php .claude/release-31400-live.php --run
 *     php .claude/release-31400-live.php --run --verbose   and print what was measured
 *
 * REAL: Edit event (two registrants) for an in-person event at 10 am to noon
 * and an online one with a meeting link, and Series & Categories on both
 * tabs, rendered through wp-kit.php with the real portal.css and portal.js,
 * in Chrome at 1280px and 390px. Every save is caught before it leaves.
 *
 *   The prompt (items 1 and 2):
 *   - turning RSVP on and off, and changing the description, then saving,
 *     asks nothing: the save goes straight through (PLANT T)
 *   - moving the start time asks, and says Time from what the server stored,
 *     never "not set"; moving it back asks nothing again
 *   - on the online event, a new meeting link asks, says Meeting link
 *     Changed, and prints neither link; the description alone asks nothing
 *   The buttons (item 3):
 *   - Back to the form is a quiet link (no box), Save without telling them
 *     the teal outline, Save and email them the green, on one row at 1280px,
 *     the link first; at 390px every one is on screen and nothing scrolls
 *   Series & Categories (item 5):
 *   - two tabs and no fold; Series has the search box over a table with
 *     Series, Schedule, Upcoming and Next date, + New series in the section
 *     head at the right, the one-line sub; typing narrows the rows
 *   - Categories has Create a category at the right of its head, and a row
 *     reads "Pink, hand · 2 events"
 *   The series page (item 6):
 *   - People is folded and says "1 chosen", and ticking makes it 2
 *
 * PLANT in plant-31400.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1, 'pending' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me = new WP_User();
$GLOBALS['kit_users'] = array( $me );
$GLOBALS['kit_umeta'][ $me->ID ]['_uc_calendar_role'] = 'admin';
foreach ( array( 21 => 'Ana Ruiz', 22 => 'Ben Young' ) as $uid => $name ) {
    $u = new WP_User(); $u->ID = $uid; $u->display_name = $name; $u->user_email = strtolower( strtok( $name, ' ' ) ) . '@example.org'; $u->user_login = 'u' . $uid;
    $GLOBALS['kit_users'][] = $u;
    $GLOBALS['kit_umeta'][ $uid ]['_uc_calendar_role'] = 'editor';
}

$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => $me->ID, 'post_content' => '<p>Weekly.</p>' ) );
foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+7 days' ) ), '_uc_start_time' => '10:00', '_uc_end_time' => '12:00', '_uc_location' => '1035 Market Street', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $ev, $k, $v ); }
$on = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Online group', 'post_author' => $me->ID, 'post_content' => '<p>Online.</p>' ) );
foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+8 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $on, $k, $v ); }
SFAF_Online::set( $on, SFAF_Online::MODE_ONLINE, 'https://zoom.us/j/11112222333', array( 'confirmation' ) );

// Two registrants on every event, so the prompt is on the page.
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false !== strpos( $sql, 'uc_rsvps' ) && false !== strpos( $sql, "status = 'confirmed'" ) ) {
        if ( 'get_var' === $method ) { return '2'; }
        if ( 'get_results' === $method ) {
            return array(
                (object) array( 'id' => 1, 'event_id' => 0, 'email' => 'ana@example.org', 'name' => 'Ana', 'first_name' => 'Ana', 'status' => 'confirmed', 'phone' => '', 'text_channel' => '', 'lang' => 'en', 'token' => 't1', 'format' => '' ),
                (object) array( 'id' => 2, 'event_id' => 0, 'email' => 'bo@example.org', 'name' => 'Bo', 'first_name' => 'Bo', 'status' => 'confirmed', 'phone' => '', 'text_channel' => '', 'lang' => 'en', 'token' => 't2', 'format' => '' ),
            );
        }
    }
    return 'get_results' === $method ? array() : null;
};

// The category's colour before anything renders: sfaf_category_color() caches.
update_term_meta( 11, SFAF_Categories::META_COLOR, '#F1668C' );
update_term_meta( 11, SFAF_Categories::META_ICON, 'hand' );
$edit = rl_cap( function () use ( $portal, $me, $ev ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $me, $ev ) ); } );
$online = rl_cap( function () use ( $portal, $me, $on ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $me, $on ) ); } );
foreach ( array( 'edit' => $edit, 'online' => $online ) as $k => $h ) {
    rl( 0 !== strpos( $h, 'THREW' ), "$k did not render: $h" );
    rl( false !== strpos( $h, 'data-uc-change-notice' ), "$k: the change notice is not on the page, so the prompt cannot be tested" );
}
rl( false === strpos( $online, 'data-uc-fact-link' ) && 1 === substr_count( $online, 'zoom.us/j/11112222333' ), 'the online editor prints the meeting link somewhere beside its own field' );

/* ---- Series & Categories, and a series page. ---- */
$GLOBALS['kit_db'] = function ( $method, $sql ) { return 'get_results' === $method ? array() : null; };
// The kit's two terms in every taxonomy: Alpha (11) and Beta (12).
$cid = 11;
$GLOBALS['kit_query'] = function ( $a ) {
    if ( isset( $a['tax_query'][0]['terms'] ) && 11 === (int) $a['tax_query'][0]['terms'] ) { return array( $GLOBALS['ev'], $GLOBALS['on'] ); }
    return array();
};
$GLOBALS['ev'] = $ev; $GLOBALS['on'] = $on;
wp_set_object_terms( $ev, array( $cid ), 'uc_event_category' );
wp_set_object_terms( $on, array( $cid ), 'uc_event_category' );
$series_tab = rl_cap( function () use ( $portal, $me ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_series_list', $portal, array( $me ) ); } );
$cats_tab   = rl_cap( function () use ( $portal, $me ) { $_GET = array( 'tab' => 'categories' ); kit_call( 'SFAF_Portal', 'render_series_list', $portal, array( $me ) ); } );
$sid = 11;
update_term_meta( $sid, SFAF_Series::META_EVENT_DEFAULTS, array( 'notify_users' => array( 21 ) ) );
$series_page = rl_cap( function () use ( $portal, $me, $sid ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( $me, $sid ) ); } );
foreach ( array( 'series tab' => $series_tab, 'categories tab' => $cats_tab, 'series page' => $series_page ) as $k => $h ) {
    rl( 0 !== strpos( $h, 'THREW' ), "$k did not render: " . substr( $h, 0, 300 ) );
}
rl( false === strpos( $series_tab, 'uc-list-fold' ) && false === strpos( $cats_tab, 'uc-list-fold' ), 'PLANT S: a list on Series & Categories still folds' );
rl( false !== strpos( $series_tab, 'Each series groups an event&#039;s dates. Open one to change its schedule or details.' ) || false !== strpos( $series_tab, "Each series groups an event's dates. Open one to change its schedule or details." ), 'the Series sub is not the one line' );
rl( false === strpos( $series_tab, 'To start a series, set a repeat' ), 'the old Series help is still there' );

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('beforeunload', function (e) { e.stopImmediatePropagation(); }, true);
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function wait(ms, fn) { setTimeout(fn, ms); }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
var P = window.RL_PAGE;
// Every save is caught here, after portal.js has had its say.
var sent = 0;
document.addEventListener('submit', function (e) { if (!e.defaultPrevented) { sent++; } e.preventDefault(); }, false);
function dialog() { return q('dialog.uc-notify-modal'); }
function asked() {
  var d = dialog();
  if (!d) { return null; }
  return { title: text(q('h2', d)), rows: qa('.uc-notify-changes li', d).map(function (li) { return { label: text(q('.uc-notify-change-label', li)), was: text(q('.uc-notify-was', li)), now: text(q('.uc-notify-now', li)) }; }), all: text(d) };
}
function shut() { var d = dialog(); if (d) { d.close(); if (d.parentNode) { d.parentNode.removeChild(d); } } }
function save(form, then) {
  var before = sent;
  var btn = q('button[name="save_mode"][value="keep"]') || q('.uc-editor-save');
  form.requestSubmit(btn);
  wait(120, function () { var a = asked(); var r = { asked: a, sent: sent > before }; then(r); });
}
function pick(sel, v) { sel.value = v; sel.dispatchEvent(new Event('change', { bubbles: true })); }

document.addEventListener('DOMContentLoaded', function () { wait(300, function () {
  if (P.indexOf('series') === 0 || P.indexOf('cats') === 0 || P.indexOf('spage') === 0) {
    out.tabs = qa('.uc-view-tabs .uc-view-tab').map(function (a) { return { t: text(a), on: a.classList.contains('uc-view-tab-active') }; });
    out.folds = qa('details.uc-list-fold').length;
    if (P.indexOf('series') === 0) {
      var head = q('#uc-series .uc-section-head'), nb = q('[data-uc-series-new]'), h2 = head && q('h2', head);
      out.newBtn = nb ? { r: rect(nb), headR: rect(head), h2: rect(h2), cls: nb.className } : null;
      out.sub = text(q('#uc-series .uc-section-sub'));
      out.cols = qa('#uc-series thead th').map(text);
      var box = q('#uc-series-q'), table = q('.uc-series-table');
      out.searchAbove = !!box && !!table && (box.getBoundingClientRect().bottom <= table.getBoundingClientRect().top);
      out.rows = qa('.uc-series-table tbody tr').filter(function (r) { return !r.hidden && r.getClientRects().length; }).length;
      box.value = 'bet'; box.dispatchEvent(new Event('input', { bubbles: true }));
      out.rowsArt = qa('.uc-series-table tbody tr').filter(function (r) { return !r.hidden && r.getClientRects().length; }).map(function (r) { return text(q('strong', r)); });
      box.value = 'zzz'; box.dispatchEvent(new Event('input', { bubbles: true }));
      var em = q('[data-uc-filter-empty]');
      out.noneNote = em && !em.hidden ? text(em) : null;
      finish(); return;
    }
    if (P.indexOf('cats') === 0) {
      var ch = q('#uc-categories .uc-section-head'), cb = q('[data-uc-cat-create]');
      out.createBtn = cb ? { r: rect(cb), headR: rect(ch), h2: rect(q('h2', ch)) } : null;
      out.catLine = text(q('.uc-cat-row .uc-cat-id .uc-muted'));
      finish(); return;
    }
    var fold = q('[data-uc-people-fold]');
    out.fold = fold ? { open: fold.open, count: text(q('[data-uc-people-count]', fold)) } : null;
    if (fold) {
      fold.open = true;
      var b = qa('input[type="checkbox"]', fold).filter(function (x) { return !x.checked; })[0];
      if (b) { b.click(); }
      out.foldAfter = text(q('[data-uc-people-count]', fold));
    }
    finish(); return;
  }

  var form = q('[data-uc-change-notice]').closest('form');
  out.factTime = q('[data-uc-change-notice]').getAttribute('data-uc-fact-time');
  if (P.indexOf('online') === 0) {
    var desc = q('textarea[name="description"], textarea[name="post_content"], textarea[name="event_description"]');
    if (desc) { desc.value += ' More.'; }
    save(form, function (r1) {
      out.descOnly = r1; shut();
      var url = q('input[name="meeting_url"]');
      url.value = 'https://zoom.us/j/99998888777';
      save(form, function (r2) {
        out.linkMoved = r2;
        var d = dialog();
        if (d) {
          var back = q('.uc-notify-back', d) || qa('button', d).filter(function (b) { return 'cancel' === b.value; })[0];
          var silent = qa('button', d).filter(function (b) { return 'silent' === b.value; })[0];
          var send = qa('button', d).filter(function (b) { return 'send' === b.value; })[0];
          function look(b) { if (!b) { return null; } var c = getComputedStyle(b); return { r: rect(b), cls: b.className, bg: c.backgroundColor, border: c.borderTopWidth + ' ' + c.borderTopStyle + ' ' + c.borderTopColor, color: c.color, text: text(b) }; }
          out.buttons = { back: look(back), silent: look(silent), send: look(send), dialog: rect(d), vw: window.innerWidth };
        }
        shut();
        finish();
      });
    });
    return;
  }
  // RSVP off and back on: the card's one choice since 3.113.3.
  var choices = qa('input[type="radio"][name="reg_choice"]');
  var was = choices.filter(function (c) { return c.checked; })[0];
  var other = choices.filter(function (c) { return !c.checked; })[0];
  out.hasRsvp = !!was && !!other;
  if (out.hasRsvp) { other.click(); was.click(); }
  out.rsvpBack = was ? was.checked : false;
  save(form, function (r1) {
    out.rsvpOnOff = r1; shut();
    var h = q('[name="start_time_h"]');
    out.hasHour = !!h;
    if (!h) { finish(); return; }
    var hourWas = h.value;
    pick(h, '11');
    save(form, function (r2) {
      out.timeMoved = r2; shut();
      pick(h, hourWas);
      save(form, function (r3) {
        out.timeBack = r3; shut();
        finish();
      });
    });
  });
}); });
})();
JS;

function rl_page( $html, $probe, $name ) {
    $html = str_replace( 'id="uc-session-config"', 'id="uc-session-config-off"', $html );
    return str_replace( '</body>', '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $html );
}
$pages = array();
foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $w => $px ) {
    $pages[ "edit-$w" ]   = array( $px, rl_page( $edit, $probe, "edit-$w" ) );
    $pages[ "online-$w" ] = array( $px, rl_page( $online, $probe, "online-$w" ) );
    $pages[ "series-$w" ] = array( $px, rl_page( $series_tab, $probe, "series-$w" ) );
    $pages[ "cats-$w" ]   = array( $px, rl_page( $cats_tab, $probe, "cats-$w" ) );
}
$pages['spage-desktop'] = array( 1280, rl_page( $series_page, $probe, 'spage-desktop' ) );
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31400-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] );
    $files[ $name ] = __DIR__ . '/release-31400-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p[0] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=20000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ][0], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ][0] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}

$said = array();
foreach ( $g as $name => $r ) {
    if ( 0 === strpos( $name, 'edit-' ) ) {
        rl( ! empty( $r['hasRsvp'] ) && ! empty( $r['hasHour'] ), "$name: no RSVP tick or start hour on the form" );
        $a = isset( $r['rsvpOnOff'] ) ? $r['rsvpOnOff'] : null;
        rl( is_array( $a ) && null === $a['asked'] && $a['sent'], "PLANT T: $name: RSVP on and off asked: " . json_encode( $a ) );
        $t = isset( $r['timeMoved'] ) ? $r['timeMoved'] : null;
        $row = ( is_array( $t ) && $t['asked'] ) ? $t['asked']['rows'] : array();
        rl( 1 === count( $row ) && 'Time' === $row[0]['label'] && $row[0]['was'] === $r['factTime'] && 'not set' !== $row[0]['was'] && $row[0]['now'] !== $row[0]['was'] && 'not set' !== $row[0]['now'],
            "PLANT T: $name: moving the start time asks " . json_encode( $t ) . ' against ' . $r['factTime'] );
        $b = isset( $r['timeBack'] ) ? $r['timeBack'] : null;
        rl( is_array( $b ) && null === $b['asked'] && $b['sent'], "PLANT T: $name: the time put back still asks: " . json_encode( $b ) );
        if ( $row ) { $said[] = "$name: moving the start asks Time {$row[0]['was']} to {$row[0]['now']}; RSVP on and off, and the time put back, save without asking"; }
        continue;
    }
    if ( 0 === strpos( $name, 'online-' ) ) {
        $d = isset( $r['descOnly'] ) ? $r['descOnly'] : null;
        rl( is_array( $d ) && null === $d['asked'] && $d['sent'], "PLANT L: $name: the description alone asked: " . json_encode( $d ) );
        $l = isset( $r['linkMoved'] ) ? $r['linkMoved'] : null;
        $rows = ( is_array( $l ) && $l['asked'] ) ? $l['asked']['rows'] : array();
        rl( 1 === count( $rows ) && 'Meeting link' === $rows[0]['label'] && 'Changed' === $rows[0]['now'] && '' === $rows[0]['was'],
            "PLANT L: $name: a new meeting link asks " . json_encode( $l ) );
        rl( is_array( $l ) && $l['asked'] && false === strpos( $l['asked']['all'], 'zoom.us' ), "$name: the dialog prints a meeting link" );
        $bt = isset( $r['buttons'] ) ? $r['buttons'] : null;
        rl( is_array( $bt ) && $bt['back'] && $bt['silent'] && $bt['send'], "$name: the dialog's three controls were not found" );
        if ( ! is_array( $bt ) || ! $bt['back'] || ! $bt['silent'] || ! $bt['send'] ) { continue; }
        rl( 'Back to the form' === $bt['back']['text'] && false !== strpos( $bt['back']['cls'], 'uc-action-link' ) && 'rgba(0, 0, 0, 0)' === $bt['back']['bg'] && 0 === strpos( $bt['back']['border'], '0px' ),
            "PLANT B: $name: Back to the form is not a quiet link: " . json_encode( $bt['back'] ) );
        rl( 'rgb(255, 255, 255)' === $bt['silent']['bg'] && false !== strpos( $bt['silent']['border'], 'rgb(14, 118, 128)' ) && 'rgb(14, 118, 128)' === $bt['silent']['color'],
            "PLANT B: $name: Save without telling them is not the teal outline: " . json_encode( $bt['silent'] ) );
        rl( 'rgb(21, 128, 61)' === $bt['send']['bg'], "PLANT B: $name: Save and email them is not green: " . json_encode( $bt['send'] ) );
        foreach ( array( 'back', 'silent', 'send' ) as $k ) {
            rl( $bt[ $k ]['r']['left'] >= 0 && $bt[ $k ]['r']['right'] <= $bt['vw'], "$name: $k runs off the window: " . json_encode( $bt[ $k ]['r'] ) );
        }
        if ( false !== strpos( $name, 'desktop' ) ) {
            $mid = function ( $x ) { return $x['r']['top'] + $x['r']['h'] / 2; };
            rl( abs( $mid( $bt['back'] ) - $mid( $bt['send'] ) ) <= 3 && abs( $mid( $bt['silent'] ) - $mid( $bt['send'] ) ) <= 3
                && $bt['back']['r']['right'] < $bt['silent']['r']['left'] && $bt['silent']['r']['right'] < $bt['send']['r']['left'],
                "PLANT B: $name: the three are not on one row, link first: " . json_encode( array( $bt['back']['r'], $bt['silent']['r'], $bt['send']['r'] ) ) );
            $said[] = sprintf( '%s: one row in a %dpx dialog: link at %d, outline at %d, green at %d', $name, $bt['dialog']['w'], $bt['back']['r']['left'], $bt['silent']['r']['left'], $bt['send']['r']['left'] );
        }
        continue;
    }
    if ( 0 === strpos( $name, 'series-' ) ) {
        rl( array( array( 't' => 'Series 2', 'on' => true ), array( 't' => 'Categories 2', 'on' => false ) ) === $r['tabs'], "PLANT S: $name: the tabs are " . json_encode( $r['tabs'] ) );
        rl( 0 === $r['folds'], "PLANT S: $name: a fold is on the page" );
        rl( array( '', 'Series', 'Schedule', 'Upcoming', 'Next date', 'Actions' ) === $r['cols'], "$name: the columns are " . json_encode( $r['cols'] ) );
        rl( $r['searchAbove'] && 2 === $r['rows'] && array( 'Beta' ) === $r['rowsArt'] && 'No series match.' === $r['noneNote'], "PLANT S: $name: the search does not narrow the table: " . json_encode( array( $r['searchAbove'], $r['rows'], $r['rowsArt'], $r['noneNote'] ) ) );
        $nb = $r['newBtn'];
        rl( is_array( $nb ) && false !== strpos( $nb['cls'], 'uc-btn-primary' ), "$name: no + New series button" );
        if ( is_array( $nb ) && false !== strpos( $name, 'desktop' ) ) {
            rl( abs( $nb['r']['right'] - $nb['headR']['right'] ) <= 2 && $nb['r']['top'] < $nb['h2']['bottom'], "PLANT S: $name: + New series is not at the top right of the section: " . json_encode( $nb ) );
        }
        continue;
    }
    if ( 0 === strpos( $name, 'cats-' ) ) {
        rl( isset( $r['tabs'][1] ) && $r['tabs'][1]['on'] && 0 === $r['folds'], "PLANT S: $name: Categories is not the open tab, or it folds: " . json_encode( $r['tabs'] ) );
        rl( 'Pink, hand · 2 events' === $r['catLine'], "PLANT C: $name: a category row reads " . json_encode( $r['catLine'] ) );
        $cb = $r['createBtn'];
        rl( is_array( $cb ), "$name: no Create a category button in the section head" );
        if ( is_array( $cb ) && false !== strpos( $name, 'desktop' ) ) {
            rl( abs( $cb['r']['right'] - $cb['headR']['right'] ) <= 2 && $cb['r']['top'] < $cb['h2']['bottom'], "$name: Create a category is not at the top right: " . json_encode( $cb ) );
        }
        continue;
    }
    if ( 'spage-desktop' === $name ) {
        rl( is_array( $r['fold'] ) && false === $r['fold']['open'] && '1 chosen' === $r['fold']['count'] && '2 chosen' === $r['foldAfter'],
            "PLANT P: $name: People is not folded with its count: " . json_encode( array( $r['fold'], $r['foldAfter'] ?? null ) ) );
    }
}

echo "\n3.140.0: the change prompt, its buttons, Series & Categories and the series People, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
foreach ( $said as $s ) { echo "  $s\n"; }
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Saving with the date, time, place and link untouched never asks; a moved time and a new link do, said rightly; the buttons are one row; both tabs and the People fold are as built, at 1280px and 390px.\n";
exit( 0 );
