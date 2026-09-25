<?php
/**
 * THE SERIES SCREEN, THE EDITOR AND THE PENDING QUEUE, IN A REAL BROWSER (3.103.0).
 *
 *     php .claude/series-defaults-live.php          writes the three pages
 *     php .claude/series-defaults-live.php --run    writes them and drives Chrome
 *
 * The real renderers through wp-kit.php, with the real portal.css and portal.js.
 * What is read off each rendered page:
 *
 *   series     the two default groups, with the stored defaults ticked, at a
 *              normal tick size, inside the form that saves them.
 *   editor     the one-line note under a category and an organizer that came
 *              from the series, visible.
 *   pending    the bar: a tick on every row, select-all revealed by the script,
 *              every button inside the bar's form and typed, and the counts. With
 *              everything ticked, Publish counts only the rows the publish rule
 *              lets through, and the other four count every tick. The held row
 *              says why in words.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function sl_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

function sl_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type || ! in_array( $p->post_status, $status, true ) ) { continue; }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
}

$GLOBALS['kit_query']         = 'sl_query';
$GLOBALS['kit_term_desc'][11] = '<p>Coffee and conversation, every week.</p>';
update_term_meta( 11, SFAF_Series::META_CATEGORIES, array( 12 ) );
update_term_meta( 11, SFAF_Series::META_ORGANIZERS, array( 11 ) );

function sl_event( $status, $terms = array(), $content = '' ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Coffee social ' . $GLOBALS['kit_next'], 'post_content' => $content ) );
    foreach ( array( '_uc_event_date' => '2026-12-03', '_uc_start_time' => '10:00', '_uc_end_time' => '11:30', '_uc_location' => '1035 Market St' ) as $k => $v ) {
        update_post_meta( $id, $k, $v );
    }
    foreach ( $terms as $tax => $ids ) { wp_set_object_terms( $id, $ids, $tax ); }
    return $id;
}

/* Two rows ready to publish, one that is not. */
$ready1 = sl_event( 'uc_imported', array( 'uc_event_category' => array( 11 ), 'uc_organizer' => array( 11 ) ), '<p>Words.</p>' );
$ready2 = sl_event( 'uc_imported', array( 'uc_event_category' => array( 12 ), 'uc_organizer' => array( 12 ), 'uc_series' => array( 11 ) ) );
$held   = sl_event( 'uc_imported' );

/* One event the editor opens, filled from the series. */
$filled = sl_event( 'draft' );
SFAF_Series::join( $filled, 11 );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function sl_capture( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }
$_GET = array();
$pages = array(
    'series'  => sl_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( new WP_User(), 11 ) ); } ),
    'editor'  => sl_capture( function () use ( $portal, $filled ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $filled ) ); } ),
    'pending' => sl_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_pending', $portal, array( new WP_User() ) ); } ),
);

$probe = <<<'JS'
(function () {
var out = { page: window.SL_PAGE, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function box(el) { if (!el) { return 'none'; } var r = el.getBoundingClientRect(); return Math.round(r.width) + 'x' + Math.round(r.height); }
function seen(el) { return !!el && el.getBoundingClientRect().height > 0 && getComputedStyle(el).visibility !== 'hidden'; }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
window.addEventListener('load', function () { setTimeout(function () {
  if (out.page === 'series') {
    ['category', 'organizer'].forEach(function (k) {
      var g = document.querySelector('[data-uc-series-default="' + k + '"]');
      var boxes = g ? g.querySelectorAll('input[type="checkbox"]') : [];
      out[k] = {
        seen: seen(g), label: text(g && g.querySelector('.uc-field-label')),
        ticked: Array.prototype.filter.call(boxes, function (b) { return b.checked; }).map(function (b) { return b.value; }),
        count: boxes.length, size: box(boxes[0]),
        inForm: boxes.length ? !!(boxes[0].form && boxes[0].form.querySelector('[name="uc_action"][value="save_series"]')) : false,
        hint: text(g && g.querySelector('.uc-hint'))
      };
    });
  }
  if (out.page === 'editor') {
    ['category', 'organizer'].forEach(function (k) {
      var n = document.querySelector('[data-uc-from-series="' + k + '"]');
      out[k] = { seen: seen(n), text: text(n) };
    });
  }
  if (out.page === 'pending') {
    var form = document.getElementById('uc-pending-bulk');
    out.form = !!form;
    var ticks = form ? Array.prototype.filter.call(form.elements, function (e) { return e.hasAttribute('data-uc-tick-one'); }) : [];
    out.ticks = ticks.length;
    out.rows = document.querySelectorAll('.uc-queue-item').length;
    out.tickSize = box(ticks[0]);
    var all = form && form.querySelector('[data-uc-tick-all]');
    var allRow = all && all.closest('[data-uc-tick-all-row]');
    out.allSeen = seen(allRow);
    var btns = form ? Array.prototype.slice.call(form.querySelectorAll('button')) : [];
    out.buttons = btns.map(function (b) { return b.value + ':' + b.getAttribute('type') + ':' + (b.form === form ? 'owned' : 'ORPHAN'); });
    function counts() {
      var o = {};
      btns.forEach(function (b) { var c = b.querySelector('[data-uc-tick-count]'); o[b.value] = (c ? c.textContent : '?') + (b.disabled ? ' off' : ' on'); });
      return o;
    }
    out.atRest = counts();
    if (all) { all.click(); }
    out.allTicked = counts();
    if (all) { all.click(); }
    out.noneTicked = counts();
    var holds = document.querySelectorAll('[data-uc-hold]');
    out.holds = Array.prototype.map.call(holds, function (h) { return (seen(h) ? '' : 'HIDDEN ') + text(h); });
    out.holdColor = holds[0] ? getComputedStyle(holds[0]).color : 'none';
    out.pageWidth = document.documentElement.scrollWidth;
    var cols = document.querySelectorAll('[data-uc-pending-panel] > .uc-pending-panel-col');
    out.cols = Array.prototype.map.call(cols, function (c) { var r = c.getBoundingClientRect(); return Math.round(r.left) + '@' + Math.round(r.top); });
    out.colLabels = Array.prototype.map.call(cols, function (c) { return text(c.querySelector('.uc-field-label')); });
    var hint = form && form.querySelector('button[value="apply"]') && form.querySelector('button[value="apply"]').parentNode.querySelector('.uc-hint');
    out.applyHint = seen(hint) ? text(hint) : 'HIDDEN';
  }
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}, 400); });
})();
JS;

$files = array();
foreach ( $pages as $name => $html ) {
    $page = preg_replace( '#</body>#i', '<script>window.SL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $html, 1 );
    if ( $page === $html ) { $page .= '<script>window.SL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script>'; }
    $files[ $name ] = __DIR__ . '/series-defaults-live-' . $name . '.html';
    file_put_contents( $files[ $name ], $page );
}
echo "wrote " . count( $files ) . " pages\n";

/* Structural, before any browser. */
sl_check( false !== strpos( $pages['pending'], 'name="uc_action" value="pending_bulk"' ), 'the pending bar does not post pending_bulk' );
sl_check( 3 === substr_count( $pages['pending'], 'form="uc-pending-bulk" data-uc-tick-one' ), 'every pending row does not carry a tick for the bar' );

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $got = array();
    foreach ( $files as $name => $file ) {
        $url = 'file:///' . str_replace( '\\', '/', $file );
        $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1200,2400 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
        if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { sl_check( false, "$name: Chrome returned no probe block" ); continue; }
        $got[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        echo $name . ': ' . json_encode( $got[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
        sl_check( empty( $got[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $got[ $name ]['errors'] ) );
    }
    /* 13x16 is what the editor's own organizer boxes measure: the house .uc-check
     * tick, a 13px box given a 16px line box. Measured, not assumed. */
    $normal = array( '13x13', '13x16', '14x14', '15x15', '16x16', '18x18' );

    $s = isset( $got['series'] ) ? $got['series'] : array();
    foreach ( array( 'category' => array( 'Default categories', array( '12' ) ), 'organizer' => array( 'Default organizers', array( '11' ) ) ) as $k => $want ) {
        $g = isset( $s[ $k ] ) ? $s[ $k ] : array();
        sl_check( ! empty( $g['seen'] ), "series: the $k defaults are not on the screen" );
        sl_check( isset( $g['label'] ) && $want[0] === $g['label'], "series: the $k group is labelled " . ( isset( $g['label'] ) ? $g['label'] : 'nothing' ) );
        sl_check( isset( $g['ticked'] ) && $want[1] === $g['ticked'], "series: the stored $k default is not the ticked one: " . json_encode( isset( $g['ticked'] ) ? $g['ticked'] : null ) );
        sl_check( isset( $g['size'] ) && in_array( $g['size'], $normal, true ), "series: a $k tick box is not a normal size: " . ( isset( $g['size'] ) ? $g['size'] : '' ) );
        sl_check( ! empty( $g['inForm'] ), "series: the $k boxes are not in the form that saves the series" );
    }

    $e = isset( $got['editor'] ) ? $got['editor'] : array();
    foreach ( array( 'category', 'organizer' ) as $k ) {
        sl_check( ! empty( $e[ $k ]['seen'] ), "editor: the note under the $k is not visible" );
        sl_check( isset( $e[ $k ]['text'] ) && 'Filled in from the Alpha series.' === $e[ $k ]['text'], "editor: the $k note reads " . ( isset( $e[ $k ]['text'] ) ? $e[ $k ]['text'] : 'nothing' ) );
    }

    $p = isset( $got['pending'] ) ? $got['pending'] : array();
    sl_check( ! empty( $p['form'] ), 'pending: no bar' );
    sl_check( isset( $p['ticks'], $p['rows'] ) && 3 === $p['ticks'] && 3 === $p['rows'], 'pending: a tick per row: ' . json_encode( array( isset( $p['ticks'] ) ? $p['ticks'] : null, isset( $p['rows'] ) ? $p['rows'] : null ) ) );
    sl_check( isset( $p['tickSize'] ) && in_array( $p['tickSize'], $normal, true ), 'pending: the row tick is not a normal size: ' . ( isset( $p['tickSize'] ) ? $p['tickSize'] : '' ) );
    sl_check( ! empty( $p['allSeen'] ), 'pending: select-all is not revealed' );
    $want_btns = array( 'apply:submit:owned', 'publish:submit:owned', 'dismiss:submit:owned' );
    sl_check( isset( $p['buttons'] ) && $want_btns === $p['buttons'], 'pending: the buttons, their types and owners: ' . json_encode( isset( $p['buttons'] ) ? $p['buttons'] : null ) );
    $rest = array( 'apply' => '0 off', 'publish' => '0 off', 'dismiss' => '0 off' );
    sl_check( isset( $p['atRest'] ) && $rest === $p['atRest'], 'pending: at rest every button should read 0 and be off: ' . json_encode( isset( $p['atRest'] ) ? $p['atRest'] : null ) );
    $all = array( 'apply' => '3 on', 'publish' => '2 on', 'dismiss' => '3 on' );
    sl_check( isset( $p['allTicked'] ) && $all === $p['allTicked'], 'pending: everything ticked, Publish should count the 2 it would publish and the rest 3: ' . json_encode( isset( $p['allTicked'] ) ? $p['allTicked'] : null ) );
    sl_check( isset( $p['noneTicked'] ) && $rest === $p['noneTicked'], 'pending: unticking everything should turn every button off: ' . json_encode( isset( $p['noneTicked'] ) ? $p['noneTicked'] : null ) );
    sl_check( isset( $p['holds'] ) && array( 'Not ready to publish: needs an organizer, a category, and a description.' ) === $p['holds'], 'pending: the held row says why: ' . json_encode( isset( $p['holds'] ) ? $p['holds'] : null ) );
    sl_check( isset( $p['holdColor'] ) && 'rgb(180, 83, 9)' === $p['holdColor'], 'pending: the held line is not the measured amber: ' . ( isset( $p['holdColor'] ) ? $p['holdColor'] : '' ) );
    /* ONE PANEL (3.104.0): the three controls side by side, level, in this order. */
    $cols = isset( $p['cols'] ) ? $p['cols'] : array();
    $tops = array_map( function ( $c ) { return (int) substr( $c, strpos( $c, '@' ) + 1 ); }, $cols );
    $lefts = array_map( 'intval', $cols );
    sl_check( 3 === count( $cols ) && 1 === count( array_unique( $tops ) ) && $lefts[0] < $lefts[1] && $lefts[1] < $lefts[2], 'pending: the three controls are not side by side: ' . json_encode( $cols ) );
    sl_check( isset( $p['colLabels'] ) && array( 'Series', 'Categories', 'Organizers' ) === $p['colLabels'], 'pending: the panel' . chr(39) . 's controls: ' . json_encode( isset( $p['colLabels'] ) ? $p['colLabels'] : null ) );
    sl_check( isset( $p['applyHint'] ) && false !== strpos( $p['applyHint'], 'A series with defaults also fills the category and organizer' ), 'pending: the line beside Apply: ' . ( isset( $p['applyHint'] ) ? $p['applyHint'] : '' ) );
    sl_check( isset( $p['pageWidth'] ) && $p['pageWidth'] <= 1200, 'pending: the page scrolls sideways: ' . ( isset( $p['pageWidth'] ) ? $p['pageWidth'] : '' ) );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "Series screen, editor and pending queue: the defaults ticked, the note shown, and a bar whose\n";
echo "Publish counts only what the publish rule lets through.\n";
exit( 0 );
