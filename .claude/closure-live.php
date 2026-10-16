<?php
/**
 * 3.105.0 IN A REAL BROWSER: CLOSURES, THE CLOSED-DAY WARNING, REMOVE, THE
 * DONATE LIST AND THE PREFILL'S LOCATION.
 *
 *     php .claude/closure-live.php          writes the pages
 *     php .claude/closure-live.php --run    writes them, drives Chrome, checks
 *
 * The real renderers through wp-kit.php, with the real portal.css, portal.js,
 * calendar.css, calendar.js and admin.css. Nothing is asserted about markup
 * that a browser could render differently: every check below is read off the
 * page after Chrome has laid it out and the scripts have run.
 *
 *   closures-admin   the closure editor: two still-open rows drawn with their
 *                    venue and hours; Add a row gives a third, Remove takes it
 *                    away; the POST the browser would send, run through the
 *                    real handler, stores exactly the rows on screen.
 *   grid-desktop     the closed day's cell carries each open line under the
 *                    word, in the green ink, measured against what is behind it.
 *   grid-phone       the cell drops the line at phone width and the day panel,
 *                    opened by tapping the day, carries it instead.
 *   list-card        the closure card: the line on its own row, under the word.
 *   editor-draft     a date on a closure names it under the date; Save draft
 *                    posts with no question.
 *   editor-no        Publish asks once, naming the date and the closure; Cancel
 *                    posts nothing.
 *   editor-yes       the same; Publish posts save_mode=publish.
 *   editor-repeat    a weekly event names every generated date on a closure.
 *   editor-saved     a live event on a closed day: the line, and Save changes
 *                    posts with no question.
 *   request-form,
 *   submit-form      the line under the date on both public forms, no question.
 *   pending          an imported event on a closed day is flagged by its date.
 *   rsvps            Remove on a confirmed row asks, naming the person; Cancel
 *                    posts nothing; Remove posts remove_rsvp for that row.
 *   donate           the list starts on the inherited link, named for what it
 *                    is; Custom shows the box; the name follows the series.
 *   prefill          Fill these in puts the series' last address in the five
 *                    boxes the editor has.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function cl_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function cl_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); }
    catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}

/* ---- The world. -------------------------------------------------------- */
function cl_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type || ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) ) { continue; }
        // The series filter, which is what decides the prefill's "last event".
        foreach ( isset( $a['tax_query'] ) ? (array) $a['tax_query'] : array() as $tq ) {
            if ( is_array( $tq ) && isset( $tq['taxonomy'], $tq['terms'] ) && ! array_intersect( (array) $tq['terms'], wp_get_post_terms( $id, $tq['taxonomy'], array( 'fields' => 'ids' ) ) ) ) { continue 2; }
        }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
}
$GLOBALS['kit_query'] = 'cl_query';

$thanks = SFAF_Closures::save( '', 'Thanksgiving', '2026-11-26', '2026-11-27', '', array(
    array( 'venue' => 11, 'from' => '10:00', 'to' => '14:00' ),
    array( 'venue' => 12, 'from' => '09:30', 'to' => '17:00' ),
) );
SFAF_Closures::save( '', 'Winter break', '2026-12-24', '2026-12-25' );
$en    = "\xE2\x80\x93";
$LINES = array( 'Alpha open 10 am' . $en . '2 pm', 'Beta open 9:30 am' . $en . '5 pm' );

/* The series' last event has an address of its own, for the prefill. */
$last = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Coffee social' ) );
foreach ( array( '_uc_event_date' => '2026-10-01', '_uc_start_time' => '10:00', '_uc_end_time' => '11:30',
    '_uc_location' => '470 Castro St, San Francisco, CA 94114', '_uc_location_name' => 'Strut',
    '_uc_location_street' => '470 Castro St', '_uc_location_city' => 'San Francisco', '_uc_location_state' => 'CA', '_uc_location_zip' => '94114' ) as $k => $v ) {
    update_post_meta( $last, $k, $v );
}
wp_set_object_terms( $last, array( 11 ), 'uc_series' );
update_term_meta( 11, SFAF_Series::META_DONATE, 'https://donate.sfaf.org/campaign/111/donate' );

/* A live event on a closed day, and an import waiting on one. */
$live = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Holiday walk', 'post_content' => '<p>Words.</p>' ) );
foreach ( array( '_uc_event_date' => '2026-11-26', '_uc_start_time' => '10:00', '_uc_end_time' => '11:00', '_uc_location' => '1 Main St' ) as $k => $v ) { update_post_meta( $live, $k, $v ); }
wp_set_object_terms( $live, array( 12 ), 'uc_event_category' );
wp_set_object_terms( $live, array( 11 ), 'uc_organizer' );
$imp = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'uc_imported', 'post_title' => 'Imported walk' ) );
update_post_meta( $imp, '_uc_event_date', '2026-11-26' );

/* The registrations table. */
$GLOBALS['cl_rows'] = array(
    1 => array( 'id' => 1, 'event_id' => $live, 'event_title' => '', 'name' => 'Robin Ruiz', 'first_name' => 'Robin', 'last_name' => 'Ruiz', 'email' => '',
        'phone' => '', 'status' => 'confirmed', 'token' => '', 'format' => '', 'created_at' => '2026-09-20 10:00:00', 'cancelled_at' => null, 'removed_by' => 0 ),
);
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( 'get_results' === $method && false !== strpos( $sql, 'uc_rsvps' ) && false !== strpos( $sql, 'LEFT JOIN' ) ) {
        return array_map( function ( $r ) { $r['post_title'] = 'Holiday walk'; return (object) $r; }, array_values( $GLOBALS['cl_rows'] ) );
    }
    return 'get_results' === $method ? array() : null;
};

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$admin  = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
$sc     = new SFAF_Shortcodes();
$user   = new WP_User();
function cl_screen( $method, $args, $get = array() ) {
    return cl_capture( function () use ( $method, $args, $get ) { $_GET = $get; kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args ); } );
}
function cl_public( $body ) {
    if ( 0 === strpos( $body, 'THREW' ) ) { return $body; }
    return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\">"
        . "<link rel=\"stylesheet\" href=\"../public/css/calendar.css\"></head><body style=\"margin:0;background:#fff\">" . $body
        . "<script src=\"vendor/jquery-3.7.1.min.js\"></script><script>window.ucData={ajaxUrl:''};</script>"
        . "<script src=\"../public/js/calendar.js\"></script></body></html>";
}

$grid = cl_capture( function () use ( $sc ) { $r = $sc->render_calendar_block( array( 'view' => 'calendar', 'month' => '2026-11', 'show_filters' => 'no' ) ); echo $r['html']; } );
$closures_admin = cl_capture( function () use ( $admin ) { $_GET = array( 'edit' => $GLOBALS['thanks'] ); $admin->render_closures_page(); } );
$complete = array( 'title' => 'Drop-in group', 'category[]' => '12', 'organizer[]' => '11', 'description' => '<p>A weekly group.</p>',
    'location_mode' => 'custom', 'location_street' => '470 Castro St', 'location_city' => 'San Francisco',
    'start_time_h' => '18', 'start_time_m' => '00', 'end_time_h' => '19', 'end_time_m' => '30' );
$new_event = cl_screen( 'render_event_form', array( $user, 0 ) );

$pages = array(
    'closures-admin' => array( 'w' => 1200, 'html' => ( 0 === strpos( $closures_admin, 'THREW' ) ) ? $closures_admin
        : '<!DOCTYPE html><html><head><meta charset="utf-8"><link rel="stylesheet" href="../admin/css/admin.css"></head><body class="wp-admin">' . $closures_admin . '</body></html>' ),
    'grid-desktop'   => array( 'w' => 1200, 'html' => cl_public( $grid ) ),
    'grid-phone'     => array( 'w' => 420,  'html' => cl_public( $grid ) ),
    'list-card'      => array( 'w' => 900,  'html' => cl_public( '<div class="uc-calendar"><div class="uc-events-list">' . $sc->render_closure_card( SFAF_Closures::get( $thanks ) ) . '</div></div>' ) ),
    'editor-draft'   => array( 'w' => 1200, 'html' => $new_event, 'set' => array( 'title' => 'Half done', 'date' => '2026-11-26' ), 'press' => 'draft' ),
    'editor-no'      => array( 'w' => 1200, 'html' => $new_event, 'set' => $complete + array( 'date' => '2026-11-26' ), 'press' => 'publish', 'answer' => 'cancel' ),
    'editor-yes'     => array( 'w' => 1200, 'html' => $new_event, 'set' => $complete + array( 'date' => '2026-11-26' ), 'press' => 'publish', 'answer' => 'ok' ),
    'editor-repeat'  => array( 'w' => 1200, 'html' => $new_event, 'set' => array( 'date' => '2026-11-19', 'repeat_mode' => 'weekly', 'repeat_days[]' => '4', 'repeat_ends' => 'on', 'repeat_until' => '2026-12-31' ) ),
    'editor-saved'   => array( 'w' => 1200, 'html' => cl_screen( 'render_event_form', array( $user, $live ) ), 'press' => 'keep' ),
    'request-form'   => array( 'w' => 900,  'html' => cl_capture( function () { SFAF_Submissions::page_open( 'Request' ); kit_call( 'SFAF_Request', 'render_form', null, array( 'tok', 'someone@sfaf.org', array(), array() ) ); SFAF_Submissions::page_close(); } ), 'set' => array( 'date' => '2026-12-25' ) ),
    'submit-form'    => array( 'w' => 900,  'html' => cl_capture( function () { SFAF_Submissions::page_open( 'Submit' ); kit_call( 'SFAF_Submit', 'render_form', null, array( (object) array( 'slug' => 'cycle-to-zero', 'name' => 'Cycle to Zero', 'term_id' => 11 ), array(), array() ) ); SFAF_Submissions::page_close(); } ), 'set' => array( 'date' => '2026-11-27' ) ),
    'pending'        => array( 'w' => 1200, 'html' => cl_screen( 'render_pending', array( $user ) ) ),
    'rsvps'          => array( 'w' => 1200, 'html' => cl_screen( 'render_rsvps', array( $user ), array( 'event_id' => $live ) ) ),
    'donate'         => array( 'w' => 1200, 'html' => $new_event ),
    'prefill'        => array( 'w' => 1200, 'html' => $new_event ),
);

$probe = <<<'JS'
(function () {
var P = window.CL_PAGE, C = window.CL_CASE || {}, out = { page: P, errors: [], posted: null, dialogs: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; }
function rgb(s) { var m = String(s).match(/\d+(\.\d+)?/g); return m ? m.slice(0, 4).map(Number) : null; }
function lum(c) { return [0, 1, 2].map(function (i) { var v = c[i] / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function behind(el) {
  for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
    var cs = getComputedStyle(n), c = rgb(cs.backgroundColor);
    if (cs.backgroundImage !== 'none') { return 'PATTERN'; }
    if (c && (c.length < 4 || c[3] > 0)) { return c; }
  }
  return [255, 255, 255];
}
function contrast(el) {
  var fg = rgb(getComputedStyle(el).color), bg = behind(el);
  if (bg === 'PATTERN') { return 'on a pattern'; }
  var a = lum(fg), b = lum(bg);
  return Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100;
}
function lines(sel) {
  return Array.prototype.map.call(document.querySelectorAll(sel), function (el) {
    return { text: text(el), seen: seen(el), color: getComputedStyle(el).color, contrast: seen(el) ? contrast(el) : null, top: Math.round(el.getBoundingClientRect().top) };
  });
}
function pairs(fd) { var a = []; fd.forEach(function (v, k) { if (typeof v === 'string') { a.push([k, v]); } }); return a; }
document.addEventListener('submit', function (e) {
  if (e.target.closest('dialog') || e.target.method === 'dialog') { return; }
  if (e.defaultPrevented) { return; }
  var fd; try { fd = new FormData(e.target, e.submitter); } catch (x) { fd = new FormData(e.target); }
  out.posted = pairs(fd); e.preventDefault();
});
function set(form, name, value) {
  var els = form.querySelectorAll('[name="' + name + '"]');
  if (!els.length) { out.errors.push('no control named ' + name); return; }
  Array.prototype.forEach.call(els, function (el) {
    if (el.type === 'checkbox' && /\[\]$/.test(name)) {
      /* A list sets exactly: the server pre-ticks today's weekday, so ticking
         one more box made editor-repeat pass only on the day it named. */
      var on = el.value === value;
      if (el.checked !== on) { el.checked = on; el.dispatchEvent(new Event('change', { bubbles: true })); }
    } else if (el.type === 'checkbox' || el.type === 'radio') {
      if (el.value === value) { el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); }
    } else {
      el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
}
/* Answer whatever dialog is open: the closure question as the case says,
   anything else (the completeness question) with its confirming button. */
function answer(then) {
  var d = document.querySelector('dialog.uc-confirm-modal[open]');
  if (!d) { then(); return; }
  var msg = text(d.querySelector('.uc-confirm-msg'));
  out.dialogs.push(msg);
  var closure = msg.indexOf('SFAF is closed on') === 0;
  var btn = d.querySelector('button[value="' + ((closure || C.answerAll) ? (C.answer || 'ok') : 'ok') + '"]');
  var delivered = false;
  d.addEventListener('close', function () { delivered = true; });
  btn.click();
  setTimeout(function () {
    /* HEADLESS DOES NOT DELIVER THIS close ON THESE PAGES, even with the
       compositor flag below (a bare page gets it with the flag; these do not).
       The dialog has closed, with the answer as its returnValue; the event is
       what the browser would send next, so it is sent here, and counted, so
       the report can say the delivery was simulated and the handler was not. */
    if (!delivered && !d.open && d.isConnected) { out.synthClose = (out.synthClose || 0) + 1; d.dispatchEvent(new Event('close')); }
    setTimeout(function () { answer(then); }, 150);
  }, 150);
}
function finish() { var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre); }

window.addEventListener('load', function () { setTimeout(function () {
  var warn = function () { var w = document.querySelector('[data-uc-closed-warn]'); return { seen: seen(w), text: text(w), color: w ? getComputedStyle(w).color : '' }; };

  if (P === 'closures-admin') {
    var rows = function () { return Array.prototype.map.call(document.querySelectorAll('[data-uc-open-rows] [data-uc-open-row]'), function (r) {
      var sel = r.querySelectorAll('select');
      return Array.prototype.map.call(sel, function (s) { return s.name + '=' + s.value; }).join(' ');
    }); };
    out.before = rows();
    document.querySelector('[data-uc-open-add]').click();
    document.querySelector('[data-uc-open-add]').click();
    out.added = rows();
    var all = document.querySelectorAll('[data-uc-open-rows] [data-uc-open-row]');
    all[all.length - 1].querySelector('[data-uc-open-remove]').click();
    var third = document.querySelectorAll('[data-uc-open-rows] [data-uc-open-row]')[2];
    if (third) {
      var s = third.querySelectorAll('select');
      s[0].value = '12'; s[1].value = '12'; s[2].value = '00'; s[3].value = '15'; s[4].value = '30';
    }
    out.after = rows();
    var f = document.querySelector('[data-uc-closure-open]').closest('form');
    out.posted = pairs(new FormData(f));
    out.rowWidth = document.documentElement.scrollWidth;
    return finish();
  }
  if (P === 'grid-desktop' || P === 'grid-phone') {
    var cell = document.querySelector('td[data-day="2026-11-26"]');
    out.label = cell ? cell.getAttribute('aria-label') : '';
    out.cell = lines('td[data-day="2026-11-26"] .uc-closed-open');
    out.word = lines('td[data-day="2026-11-26"] .uc-closed-word');
    if (P === 'grid-phone' && cell) { cell.click(); }
    return setTimeout(function () { out.panel = lines('.uc-day-panel-closed .uc-closed-open'); out.panelWord = lines('.uc-day-panel-closed .uc-closed-word'); finish(); }, 300);
  }
  if (P === 'list-card') {
    out.card = lines('.uc-closure-card .uc-closed-open');
    out.word = lines('.uc-closure-card .uc-closed-word');
    out.when = lines('.uc-closure-card .uc-closure-when');
    return finish();
  }
  if (P === 'pending') {
    out.flag = lines('[data-uc-closed-flag]');
    return finish();
  }
  if (P === 'rsvps') {
    var rm = document.querySelector('[data-uc-rsvp-remove]');
    out.removeSeen = seen(rm);
    C.answerAll = true;
    C.answer = 'cancel';
    rm.click();
    return setTimeout(function () {
      answer(function () {
        out.afterCancel = out.posted;
        C.answer = 'ok';
        rm.click();
        setTimeout(function () { answer(function () { setTimeout(finish, 900); }); }, 300);
      });
    }, 150);
  }
  if (P === 'donate') {
    var sel = document.querySelector('[data-uc-donate-choice]');
    var box = document.querySelector('[data-uc-donate-custom]');
    out.start = { value: sel.value, first: text(sel.options[0]), boxSeen: seen(box), options: Array.prototype.map.call(sel.options, function (o) { return text(o); }) };
    sel.value = 'custom'; sel.dispatchEvent(new Event('change', { bubbles: true }));
    out.custom = { boxSeen: seen(box) };
    sel.value = 'none'; sel.dispatchEvent(new Event('change', { bubbles: true }));
    out.none = { boxSeen: seen(box) };
    var series = document.querySelector('[data-uc-series-select]');
    series.value = window.CL_SERIES; series.dispatchEvent(new Event('change', { bubbles: true }));
    out.afterSeries = text(sel.options[0]);
    return finish();
  }
  if (P === 'prefill') {
    var ser = document.querySelector('[data-uc-series-select]');
    ser.value = window.CL_SERIES; ser.dispatchEvent(new Event('change', { bubbles: true }));
    return setTimeout(function () {
      var apply = document.querySelector('[data-uc-prefill-apply]');
      if (apply) { apply.click(); }
      out.boxes = {};
      ['location_name', 'location_street', 'location_city', 'location_state', 'location_zip'].forEach(function (n) {
        var b = document.querySelector('[name="' + n + '"]'); out.boxes[n] = b ? b.value : 'MISSING';
      });
      var mode = document.querySelector('[data-uc-location-mode="custom"]');
      out.mode = mode ? mode.checked : 'MISSING';
      out.oldField = document.querySelectorAll('[name="location"]').length;
      finish();
    }, 200);
  }

  /* The editor and the two public forms. */
  var date = document.querySelector('input[name="date"]');
  var form = date ? date.form : null;
  if (!form) { out.errors.push('no date field in a form'); return finish(); }
  out.atLoad = warn();
  Object.keys(C.set || {}).forEach(function (k) { set(form, k, C.set[k]); });
  out.warn = warn();
  if (!C.press) { return finish(); }
  var btn = document.querySelector('button[name="save_mode"][value="' + C.press + '"]');
  if (!btn) { out.errors.push('no ' + C.press + ' button'); return finish(); }
  btn.click();
  setTimeout(function () { answer(function () { setTimeout(finish, 900); }); }, 300);
}, 400); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { cl_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $case = array( 'set' => isset( $p['set'] ) ? $p['set'] : array(), 'press' => isset( $p['press'] ) ? $p['press'] : '', 'answer' => isset( $p['answer'] ) ? $p['answer'] : '' );
    $inject = '<script>window.CL_PAGE=' . json_encode( $name ) . ';window.CL_CASE=' . json_encode( $case ) . ';window.CL_SERIES="11";' . $probe . '</script>';
    $html = ( false !== stripos( $p['html'], '</body>' ) ) ? preg_replace( '#</body>#i', $inject . '</body>', $p['html'], 1 ) : $p['html'] . $inject;
    $files[ $name ] = __DIR__ . '/closure-live-' . $name . '.html';
    file_put_contents( $files[ $name ], $html );
}
echo 'wrote ' . count( $files ) . " pages\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $g = array();
    foreach ( $files as $name => $file ) {
        $w   = $pages[ $name ]['w'];
        /* --run-all-compositor-stages-before-draw IS NOT DECORATION. Under
           --virtual-time-budget a <dialog> closed by a timer-driven press never
           delivers its close event without it, so every "yes" in a confirmation
           did nothing and read as the product failing. Found building this
           file; a bare page with no plugin code in it reproduces it. */
        $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . $w . ',3000 --virtual-time-budget=10000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
        if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { cl_check( false, "$name: Chrome returned no probe block" ); continue; }
        $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        cl_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
        if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    }
    $green = 'rgb(70, 102, 31)';
    $closed_ink = 'rgb(173, 28, 13)';
    $v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

    /* ---- A. The closure editor. ---- */
    $ca = $v( 'closures-admin', 'before' );
    cl_check( is_array( $ca ) && 2 === count( $ca ), 'closures-admin: the two saved rows are not drawn: ' . json_encode( $ca ) );
    cl_check( is_array( $ca ) && false !== strpos( (string) $ca[0], 'closure_open_venue[0]=11' ) && false !== strpos( (string) $ca[0], 'closure_open_from_0_h=10' ) && false !== strpos( (string) $ca[0], 'closure_open_to_0_h=14' ),
        'closures-admin: the first row does not show Alpha, 10 to 2: ' . json_encode( $ca ) );
    cl_check( 4 === count( (array) $v( 'closures-admin', 'added' ) ) && 3 === count( (array) $v( 'closures-admin', 'after' ) ), 'closures-admin: Add a row and Remove do not add and take away one row each' );
    $after = (array) $v( 'closures-admin', 'after' );
    cl_check( isset( $after[2] ) && false !== strpos( $after[2], 'closure_open_venue[2]=12' ), 'closures-admin: the added row does not carry its own index: ' . json_encode( $after ) );
    cl_check( (int) $v( 'closures-admin', 'rowWidth' ) <= 1200, 'closures-admin: the rows push the page sideways' );
    /* The POST the page would send, through the real handler. */
    $qs = array();
    foreach ( (array) $v( 'closures-admin', 'posted' ) as $pr ) { $qs[] = rawurlencode( $pr[0] ) . '=' . rawurlencode( $pr[1] ); }
    parse_str( implode( '&', $qs ), $_POST );
    $GLOBALS['kit_redirect_throws'] = true;
    try { $admin->handle_closure_actions(); } catch ( KitRedirect $r ) { /* saved, and sent back to the list */ }
    $stored = SFAF_Closures::get( $thanks );
    cl_check( $stored && array(
        array( 'venue' => 11, 'from' => '10:00', 'to' => '14:00' ),
        array( 'venue' => 12, 'from' => '09:30', 'to' => '17:00' ),
        array( 'venue' => 12, 'from' => '12:00', 'to' => '15:30' ),
    ) === $stored['open'], 'closures-admin: the POST did not store the three rows on screen: ' . json_encode( $stored ? $stored['open'] : null ) );

    /* ---- A. A closed day in every public view. ---- */
    foreach ( array( 'grid-desktop' => 'cell', 'list-card' => 'card', 'grid-phone' => 'panel' ) as $page => $key ) {
        $got = (array) $v( $page, $key );
        cl_check( $LINES === array_column( $got, 'text' ), "$page: the open lines read " . json_encode( array_column( $got, 'text' ) ) );
        foreach ( $got as $l ) {
            cl_check( $l['seen'], "$page: an open line is not on screen" );
            cl_check( $green === $l['color'], "$page: an open line is " . $l['color'] . ", not the green family's ink" );
            cl_check( is_numeric( $l['contrast'] ) && $l['contrast'] >= 4.5, "$page: an open line measures " . json_encode( $l['contrast'] ) . ' against what is behind it' );
        }
    }
    $word = (array) $v( 'grid-desktop', 'word' );
    $cell = (array) $v( 'grid-desktop', 'cell' );
    cl_check( $word && $cell && $cell[0]['top'] > $word[0]['top'], 'grid-desktop: the open line is not under the word Closed' );
    cl_check( false !== strpos( (string) $v( 'grid-desktop', 'label' ), $LINES[0] ), 'grid-desktop: the cell\'s spoken label does not carry what stays open' );
    $phone_cell = (array) $v( 'grid-phone', 'cell' );
    cl_check( $phone_cell && ! $phone_cell[0]['seen'], 'grid-phone: the cell still draws the open line at phone width' );
    $card = (array) $v( 'list-card', 'card' ); $cw = (array) $v( 'list-card', 'when' );
    cl_check( $card && $cw && $card[0]['top'] > $cw[0]['top'], 'list-card: the open line is not on its own row under the word and the dates' );

    /* ---- B. The closed-day warning. ---- */
    $want_one = 'SFAF is closed on Nov 26, 2026 (Thanksgiving).';
    $d = (array) $v( 'editor-draft', 'warn' );
    cl_check( isset( $d['text'] ) && $want_one === $d['text'] && $d['seen'], 'editor-draft: the line under the date reads ' . json_encode( $d ) );
    cl_check( isset( $d['color'] ) && $closed_ink === $d['color'], 'editor-draft: the line is not the closure red ink: ' . ( isset( $d['color'] ) ? $d['color'] : '' ) );
    $at = (array) $v( 'editor-draft', 'atLoad' );
    cl_check( empty( $at['seen'] ), 'editor-draft: the line shows before a date is picked' );
    cl_check( array() === (array) $v( 'editor-draft', 'dialogs' ), 'PLANT: Save draft asked a question: ' . json_encode( $v( 'editor-draft', 'dialogs' ) ) );
    $posted = array_column( (array) $v( 'editor-draft', 'posted' ), 1, 0 );
    cl_check( isset( $posted['save_mode'] ) && 'draft' === $posted['save_mode'], 'PLANT: Save draft on a closed day did not post' );

    $ask = 'SFAF is closed on Nov 26, 2026 (Thanksgiving). Hold the event anyway?';
    foreach ( array( 'editor-no' => null, 'editor-yes' => 'publish' ) as $page => $want ) {
        $dl = (array) $v( $page, 'dialogs' );
        cl_check( 1 === count( array_keys( $dl, $ask, true ) ), "$page: Publish did not ask once, naming the date and the closure: " . json_encode( $dl ) );
        $pp = array_column( (array) $v( $page, 'posted' ), 1, 0 );
        if ( null === $want ) {
            cl_check( null === $v( $page, 'posted' ), "$page: answering no still posted" );
        } else {
            cl_check( isset( $pp['save_mode'] ) && $want === $pp['save_mode'], "PLANT: $page: answering yes did not publish: " . json_encode( $pp ) );
        }
    }
    $rep = (array) $v( 'editor-repeat', 'warn' );
    cl_check( isset( $rep['text'] ) && 'SFAF is closed on Nov 26, 2026 (Thanksgiving) and Dec 24, 2026 (Winter break).' === $rep['text'],
        'editor-repeat: a weekly event does not name every generated date on a closure: ' . json_encode( $rep ) );
    $sv = (array) $v( 'editor-saved', 'atLoad' );
    cl_check( isset( $sv['text'] ) && $want_one === $sv['text'] && $sv['seen'], 'editor-saved: a live event on a closed day does not say so on load: ' . json_encode( $sv ) );
    cl_check( array() === (array) $v( 'editor-saved', 'dialogs' ), 'editor-saved: Save changes asked about the closure' );
    $sp = array_column( (array) $v( 'editor-saved', 'posted' ), 1, 0 );
    cl_check( isset( $sp['save_mode'] ) && 'keep' === $sp['save_mode'], 'editor-saved: Save changes did not post' );
    foreach ( array( 'request-form' => 'SFAF is closed on Dec 25, 2026 (Winter break).', 'submit-form' => 'SFAF is closed on Nov 27, 2026 (Thanksgiving).' ) as $page => $want ) {
        $w = (array) $v( $page, 'warn' );
        cl_check( isset( $w['text'] ) && $want === $w['text'] && $w['seen'], "$page: the line under the date reads " . json_encode( $w ) );
        cl_check( isset( $w['color'] ) && $closed_ink === $w['color'], "$page: the line is " . ( isset( $w['color'] ) ? $w['color'] : '' ) . ' on a public form, not the palette\'s red ink' );
        cl_check( array() === (array) $v( $page, 'dialogs' ), "$page: a public form asked a question" );
    }
    $flag = (array) $v( 'pending', 'flag' );
    cl_check( 1 === count( $flag ) && 'Closed: Thanksgiving' === $flag[0]['text'] && $flag[0]['seen'], 'pending: the import on a closed day is not flagged: ' . json_encode( $flag ) );
    cl_check( isset( $flag[0] ) && is_numeric( $flag[0]['contrast'] ) && $flag[0]['contrast'] >= 4.5, 'pending: the flag measures ' . json_encode( isset( $flag[0] ) ? $flag[0]['contrast'] : null ) );

    /* ---- C. Remove. ---- */
    cl_check( true === $v( 'rsvps', 'removeSeen' ), 'rsvps: Remove is not on the confirmed row' );
    $rd = (array) $v( 'rsvps', 'dialogs' );
    $rq = 'Remove Robin Ruiz\'s registration? Their place is released, and nothing puts it back.';
    cl_check( 2 === count( array_keys( $rd, $rq, true ) ), 'rsvps: Remove did not ask, naming the person, each time: ' . json_encode( $rd ) );
    cl_check( null === $v( 'rsvps', 'afterCancel' ), 'rsvps: answering Cancel still posted' );
    $rp = array_column( (array) $v( 'rsvps', 'posted' ), 1, 0 );
    cl_check( isset( $rp['uc_action'], $rp['rsvp_id'] ) && 'remove_rsvp' === $rp['uc_action'] && '1' === $rp['rsvp_id'], 'rsvps: Remove did not post remove_rsvp for that row: ' . json_encode( $rp ) );

    /* ---- E. The donate list. ---- */
    $ds = (array) $v( 'donate', 'start' );
    cl_check( isset( $ds['value'] ) && 'inherit' === $ds['value'] && 'SFAF default' === $ds['first'], 'donate: a new event does not start on the inherited link, named SFAF default: ' . json_encode( $ds ) );
    cl_check( isset( $ds['options'] ) && array( 'SFAF default', 'None', 'Alpha', 'Custom' ) === $ds['options'], 'donate: the list reads ' . json_encode( isset( $ds['options'] ) ? $ds['options'] : null ) );
    cl_check( isset( $ds['boxSeen'] ) && false === $ds['boxSeen'], 'donate: the custom box shows before Custom is chosen' );
    cl_check( true === $v( 'donate', 'custom' )['boxSeen'] && false === $v( 'donate', 'none' )['boxSeen'], 'donate: Custom does not reveal the box, or None does not hide it' );
    cl_check( 'Series link' === $v( 'donate', 'afterSeries' ), 'donate: choosing a series with a link does not rename the first entry: ' . json_encode( $v( 'donate', 'afterSeries' ) ) );

    /* ---- F. The prefill's location. ---- */
    cl_check( array( 'location_name' => 'Strut', 'location_street' => '470 Castro St', 'location_city' => 'San Francisco', 'location_state' => 'CA', 'location_zip' => '94114' ) === $v( 'prefill', 'boxes' ),
        'PLANT: prefill: Fill these in did not fill the five location boxes: ' . json_encode( $v( 'prefill', 'boxes' ) ) );
    cl_check( true === $v( 'prefill', 'mode' ), 'prefill: the mode is not switched to a different location' );
    cl_check( 0 === $v( 'prefill', 'oldField' ), 'prefill: New Event draws a field called location' );
}

if ( $fails ) {
    echo 'CLOSURE LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo in_array( '--run', $argv, true )
    ? "closure live: the editor, every closed-day view, the warning and its one question, Remove, the donate list and the prefill, all read in Chrome.\n"
    : "pages written; --run drives them.\n";
