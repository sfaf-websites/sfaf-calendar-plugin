<?php
/**
 * 3.143.0 IN A BROWSER: ALSO SHOW THIS EVENT IN SERIES.
 *
 *     php .claude/release-31430-live.php --run
 *     php .claude/release-31430-live.php --run --verbose   and print what was measured
 *
 * REAL: the whole event page (templates/single-uc_event.php) with the real
 * calendar.css; Edit event's Series and language card
 * (render_series_prefill()) and the series page's schedule (render_schedule())
 * with the real portal.css and portal.js; all through wp-kit.php, the series
 * queries answered by series-query-kit.php. In Chrome at 1280, 430 and 390px.
 *
 *   E  Alpha three's page: under "Upcoming in this series", Alpha one,
 *      Gamma day (shown in Alpha), Beta night (shown in Alpha) and Alpha two,
 *      in that order, each shown, a link, inside the box
 *   F  Beta night's card: "Also show this event in series…" under Series;
 *      Alpha's chip shown; + Add series opens the list without Beta, its own;
 *      Series changed to Alpha: Alpha's chip and box go, Beta's box comes back
 *   S  Alpha's series page: "Also shown here" after Upcoming, Beta night's
 *      row with its "also shown here" tag shown, teal on its tint, and "in
 *      Beta", inside the card
 *
 * Every page: no script error and no sideways scroll. PLANT in plant-31430.php.
 */

$GLOBALS['loop_done'] = false;
function have_posts() { return ! $GLOBALS['loop_done']; }
function the_post() { $GLOBALS['loop_done'] = true; $GLOBALS['kit_current'] = $GLOBALS['page_ev']; }
function get_header() { echo ''; }
function get_footer() { echo ''; }
require __DIR__ . '/wp-kit.php';
require __DIR__ . '/series-query-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'the_title' ) ) { function the_title() { echo get_the_title( get_the_ID() ); } }
if ( ! function_exists( 'the_content' ) ) { function the_content() { echo get_post_field( 'post_content', get_the_ID() ); } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
$day = function ( $n ) { return date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' ' . ( $n >= 0 ? '+' : '' ) . $n . ' days' ) ); };
$cap = function ( $fn ) { ob_start(); try { $fn(); } catch ( Throwable $t ) { ob_end_clean(); return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); } return ob_get_clean(); };

at_world();
$a1 = at_event( 'Alpha one', $day( 2 ), 11 );
$a2 = at_event( 'Alpha two', $day( 9 ), 11 );
$a3 = at_event( 'Alpha three', $day( 1 ), 11 );
$b1 = at_event( 'Beta night', $day( 5 ), 12 );
$g1 = at_event( 'Gamma day', $day( 3 ), 13 );
foreach ( array( $a1, $a2, $a3, $b1, $g1 ) as $id ) {
    update_post_meta( $id, '_uc_end_time', '19:30' );
    update_post_meta( $id, '_uc_location', '1035 Market St' );
}
SFAF_Series::set_also_for_event( $b1, array( 11 ) );
SFAF_Series::set_also_for_event( $g1, array( 11 ) );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();

/* E: the event page. */
$GLOBALS['page_ev'] = $a3;
$event = $cap( function () { include $GLOBALS['kit_root'] . '/templates/single-uc_event.php'; } );
rl( 0 !== strpos( $event, 'THREW' ), 'the event page did not render: ' . substr( $event, 0, 300 ) );
$event = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
    . '<link rel="stylesheet" href="../public/css/calendar.css"></head><body style="margin:0;background:#fff">' . $event;

/* F and S: caladmin. */
$card  = $cap( function () use ( $portal, $b1 ) { kit_call( 'SFAF_Portal', 'render_series_prefill', $portal, array( SFAF_Series::all(), 12, $b1 ) ); } );
$sched = $cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_schedule', $portal, array( 11 ) ); } );
rl( 0 !== strpos( $card, 'THREW' ), 'the Series card did not render: ' . substr( $card, 0, 300 ) );
rl( 0 !== strpos( $sched, 'THREW' ), 'the schedule did not render: ' . substr( $sched, 0, 300 ) );
$portal_page = function ( $inner ) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="../public/css/portal.css"></head>'
        . '<body class="uc-portal" style="margin:0"><div class="uc-portal-main"><div class="uc-portal-content"><div class="uc-bento"><div class="uc-bento-main">' . $inner . '</div></div></div></div>'
        . '<script src="../public/js/portal.js"></script>';
};

$probe = <<<'JS'
(function () {
var P = window.RL_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function inside(a, b) { var r = a.getBoundingClientRect(), c = b.getBoundingClientRect(); return r.left >= c.left - 0.5 && r.right <= c.right + 0.5 && r.right <= document.documentElement.clientWidth + 0.5; }
function chips(root) { return qa('[data-uc-chips-list] .uc-chip-text', root).filter(seen).map(text); }
function boxes(root) { return qa('[data-uc-chips-source] label', root).filter(seen).map(text); }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  if (P.indexOf('event') === 0) {
    var box = q('.uc-series-box');
    out.heading = box ? text(q('.uc-series-heading', box)) : '';
    out.items = qa('.uc-series-list li a', box || document).map(function (a) { return { title: text(q('.uc-series-title', a)), shown: seen(a), href: a.getAttribute('href'), inBox: !!box && inside(a, box) }; });
  } else if (P.indexOf('form') === 0) {
    var f = q('[data-uc-also-series]'), sel = q('[data-uc-series-select]');
    out.label = f ? text(q('.uc-field-label', f)) : '';
    out.under = !!(f && sel && (sel.compareDocumentPosition(f) & Node.DOCUMENT_POSITION_FOLLOWING));
    out.before = { chips: chips(f), boxes: boxes(f) };
    var t = q('[data-uc-chips-toggle]', f); if (t) { t.click(); }
    out.open = { chips: chips(f), boxes: boxes(f), inCard: !!f && qa('[data-uc-chips-source] label', f).filter(seen).every(function (l) { return inside(l, f); }) };
    sel.value = '11'; sel.dispatchEvent(new Event('change', { bubbles: true }));
    out.moved = { chips: chips(f), boxes: boxes(f), alphaTicked: !!q('input[name="also_series[]"][value="11"]:checked', f) };
  } else {
    var heads = qa('h4.uc-schedule-head').map(text);
    out.heads = heads;
    out.rows = qa('[data-uc-also-row]').length;
    var row = qa('[data-uc-also-row]').filter(function (r) { return /Beta night/.test(r.textContent); })[0], tag = row && q('.uc-tag-also', row), card = row && row.closest('.uc-card, .uc-bento-card, section');
    out.row = row ? { text: text(row), shown: seen(row), tag: tag ? text(tag) : '', tagShown: seen(tag), ink: tag ? getComputedStyle(tag).color : '', fill: tag ? getComputedStyle(tag).backgroundColor : '', inCard: !!card && inside(row, card) } : null;
  }
  finish();
}, 400); });
})();
JS;

$widths = array( 1280, 430, 390 );
$pages = array();
foreach ( $widths as $w ) {
    $pages[ 'event-' . $w ]  = array( $w, $event );
    $pages[ 'form-' . $w ]   = array( $w, $portal_page( $card ) );
    $pages[ 'series-' . $w ] = array( $w, $portal_page( '<section class="uc-bento-card">' . $sched . '</section>' ) );
}
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31430-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] . '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body></html>' );
    $files[ $name ] = __DIR__ . '/release-31430-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p[0] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --host-resolver-rules="MAP fonts.googleapis.com ~NOTFOUND" --window-size=1300,1000 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ][0], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ][0] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}

foreach ( $g as $name => $r ) {
    if ( 0 === strpos( $name, 'event' ) ) {
        rl( 'Upcoming in this series' === $r['heading'], "PLANT E: $name: the heading reads " . json_encode( $r['heading'] ) );
        $titles = array_column( (array) $r['items'], 'title' );
        rl( array( 'Alpha one', 'Gamma day', 'Beta night', 'Alpha two' ) === $titles, "PLANT E: $name: the list is " . json_encode( $titles ) );
        foreach ( (array) $r['items'] as $it ) { rl( $it['shown'] && $it['inBox'] && '' !== (string) $it['href'], "$name: a date is " . json_encode( $it ) ); }
    } elseif ( 0 === strpos( $name, 'form' ) ) {
        rl( 'Also show this event in series…' === $r['label'] && $r['under'], "PLANT F: $name: the field reads " . json_encode( $r['label'], JSON_UNESCAPED_UNICODE ) . ( $r['under'] ? '' : ', not under Series' ) );
        rl( array( 'Alpha' ) === $r['before']['chips'], "PLANT F: $name: the chips before are " . json_encode( $r['before']['chips'] ) );
        rl( array( 'Alpha', 'Gamma' ) === $r['open']['boxes'] && $r['open']['inCard'], "PLANT F: $name: + Add series offers " . json_encode( $r['open']['boxes'] ) . ( $r['open']['inCard'] ? '' : ', outside the field' ) );
        rl( array() === $r['moved']['chips'] && array( 'Beta', 'Gamma' ) === $r['moved']['boxes'] && ! $r['moved']['alphaTicked'],
            "PLANT F: $name: with Series on Alpha, chips " . json_encode( $r['moved']['chips'] ) . ', boxes ' . json_encode( $r['moved']['boxes'] ) . ( $r['moved']['alphaTicked'] ? ', Alpha still ticked' : '' ) );
    } else {
        $iu = array_search( 'Upcoming', (array) $r['heads'], true ); $ia = array_search( 'Also shown here', (array) $r['heads'], true );
        rl( false !== $iu && false !== $ia && $ia > $iu, "PLANT S: $name: the headings are " . json_encode( $r['heads'] ) );
        $row = $r['row'];
        rl( 2 === (int) $r['rows'], "PLANT S: $name: " . (int) $r['rows'] . ' rows under Also shown here, not Gamma day and Beta night' );
        rl( $row && $row['shown'] && $row['tagShown'] && 'also shown here' === $row['tag'] && false !== strpos( $row['text'], 'in Beta' ) && false !== strpos( $row['text'], 'Beta night' ),
            "PLANT S: $name: the row is " . json_encode( $row ) );
        rl( $row && 'rgb(12, 102, 111)' === $row['ink'] && 'rgb(227, 247, 249)' === $row['fill'], "PLANT S: $name: the tag is " . ( $row ? $row['ink'] . ' on ' . $row['fill'] : 'missing' ) . ', not #0C666F on #E3F7F9' );
        rl( $row && $row['inCard'], "$name: the row runs out of its card" );
    }
}

echo "\n3.143.0: also show this event in series, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "the event page lists events shown in its series in date order; the picker hides the event's own series and follows the\nSeries select; the series page lists them under Also shown here, tagged; at " . implode( ', ', $widths ) . "px.\n";
exit( 0 );
