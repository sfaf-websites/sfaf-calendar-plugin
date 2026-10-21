<?php
/**
 * 3.112.0 IN A REAL BROWSER: THE FILTER BAR WITH ALL THREE DROPDOWNS, AN EVENT
 * PAGE WITH TAG PILLS, THE EDITOR WITH MULTI-DAY TICKED, THE ON CLOSED DAYS
 * TAB, AND THE KEEPALIVE.
 *
 *     php .claude/release-31120-live.php            writes the pages
 *     php .claude/release-31120-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31120-live.php --shots    --run, and writes .claude/screens/release-31120-*.png
 *
 * Every page at 1280px, 430px and 390px: nothing past the right edge and no
 * sideways scroll. Below 600px, every caladmin control and every control on
 * the filter bar is at least 44px tall. The tag pills are links in a line of
 * text, like the category chips beside them, and are held to 24px.
 *
 *   A.3, B  the bar: Organizers and groups, What's included and Location, each
 *          opened in turn, its panel inside the screen and its options with
 *          their counts
 *   A.3    the event page: the pills under Hosted by, each to uc_tag
 *   C.1    the editor: End date beside Date, and Repeats gone while ticked
 *   D.2    On closed days: the row, Keep and Cancel
 *   G      the keepalive: nothing sent after thirty idle minutes; sent after a
 *          click
 *          PLANT G: keepalive firing with no activity
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail() { return true; } }

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
function lv_event( $title, $days, $meta = array(), $terms = array() ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1,
        'post_content' => '<p>A gentle walk through the park with time to talk, and coffee afterwards for anybody who wants to stay.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+' . $days . ' days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    foreach ( array_merge( array( 'uc_event_category' => array( 11 ), 'uc_organizer' => array( 11 ) ), $terms ) as $tax => $ids ) { $GLOBALS['kit_terms'][ $id ][ $tax ] = $ids; }
    return $id;
}
lv_event( 'Coffee social', 2, array(), array( 'uc_venue' => array( 11 ), 'uc_tag' => array( 11 ) ) );
lv_event( 'Harm reduction drop-in', 3, array( '_uc_location' => '1035 Market Street, San Francisco', '_uc_location_name' => 'The 1035 Market community room on the second floor' ), array( 'uc_tag' => array( 12 ) ) );
$walk = lv_event( 'Three-day autumn retreat in the redwoods', 10, array( '_uc_location' => 'Golden Gate Park, San Francisco', '_uc_location_name' => 'Golden Gate Park',
    '_uc_multi_day' => '1', '_uc_last_date' => date( 'Y-m-d', strtotime( '+12 days' ) ) ), array( 'uc_tag' => array( 11, 12 ) ) );

$sc = new SFAF_Shortcodes();
function lv_public( $body ) {
    if ( 0 === strpos( $body, 'THREW' ) ) { return $body; }
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="../public/css/calendar.css"></head><body>' . $body . '</body></html>';
}
$html = array();
$html['bar']  = lv_public( lv_capture( function () use ( $sc ) { $b = $sc->render_calendar_block( array( 'view' => 'list', 'toggle' => 'no', 'filters' => 'category,organizer,series,tag,location' ) ); echo $b['html']; } ) );
$html['page'] = lv_capture( function () use ( $walk ) { $GLOBALS['lv_left'] = 1; $GLOBALS['kit_current'] = $walk; include $GLOBALS['kit_root'] . '/templates/single-uc_event.php'; } );

/* caladmin: the editor with Multi-day ticked, and On closed days. */
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$route  = new ReflectionProperty( 'SFAF_Portal', 'route' );
$route->setAccessible( true );
$route->setValue( $portal, 'events/edit/' . $walk );
$html['edit'] = lv_capture( function () use ( $portal, $walk ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $walk ) ); } );
$closed = lv_event( 'Coffee on the holiday, with a long name that has to wrap on a phone', 20 );
SFAF_Closures::save( '', 'Thanksgiving', date( 'Y-m-d', strtotime( '+20 days' ) ) );
$route->setValue( $portal, 'pending' );
$html['closed'] = lv_capture( function () use ( $portal ) { $_GET = array( 'kind' => 'closed' ); kit_call( 'SFAF_Portal', 'render_pending', $portal, array( new WP_User() ) ); $_GET = array(); } );

$pages = array();
foreach ( $html as $k => $h ) {
    foreach ( array( 'desktop' => 1280, 'wide' => 430, 'phone' => 390 ) as $wn => $w ) {
        $pages[ "$k-$wn" ] = array( 'w' => $w, 'html' => $h );
    }
}

/* Before anything on the page runs: a fetch that records, and a clock the
 * probe can move. */
$head = <<<'JS'
(function () {
  window.LV_calls = []; window.LV_skew = 0;
  var realNow = Date.now.bind(Date);
  Date.now = function () { return realNow() + window.LV_skew; };
  window.fetch = function (url, o) { window.LV_calls.push(String(o && o.body || '')); return Promise.resolve({ json: function () { return Promise.resolve({ success: true, data: { signed_in: true, valid: true } }); } }); };
})();
JS;

$probe = <<<'JS'
(function () {
var P = window.RV_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { if (!el) { return ''; } var c = el.cloneNode(true); Array.prototype.forEach.call(c.querySelectorAll('.uc-visually-hidden, .uc-sr-only, .screen-reader-text'), function (h) { h.parentNode.removeChild(h); }); return c.textContent.replace(/\s+/g, ' ').trim(); }
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
    if (el.closest('.uc-tpl-editor, [hidden], #uc-sidebar, .uc-portal-sidebar, .mce-container, .wp-editor-wrap, noscript')) { return; }
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
    var r = el.getBoundingClientRect(); if (r.width && (r.right > w + 1 || r.left < -1)) { bad.push(name(el) + ' ' + Math.round(r.left) + ' to ' + Math.round(r.right)); }
  });
  return bad.slice(0, 8);
}
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
/* A PHONE HAS A FINGER. The public bar sizes its controls for touch under
   @media (pointer: coarse), and headless Chrome reports a fine pointer, so
   below 600px those rules are switched on where they stand in the sheet:
   same place, same order, same specificity. */
function coarse() {
  var n = 0;
  Array.prototype.forEach.call(document.styleSheets, function (s) {
    var rules; try { rules = s.cssRules; } catch (e) { out.errors.push('cannot read ' + s.href); return; }
    Array.prototype.forEach.call(rules, function (r) { if (r.media && /pointer:\s*coarse/.test(r.media.mediaText)) { r.media.mediaText = 'all'; n++; } });
  });
  return n;
}
window.addEventListener('load', function () { setTimeout(function () {
  var main = q('.uc-portal-main') || document.body;
  if (phone && !q('.uc-portal-main')) { out.coarse = coarse(); }
  out.past = past(main);

  /* The bar: each dropdown opened alone. */
  var who = qa('details.uc-who');
  if (who.length && !q('.uc-portal-main')) {
    out.bar = who.map(function (d) {
      who.forEach(function (o) { o.open = false; });
      d.open = true;
      var panel = q('.uc-who-panel', d);
      var r = { kind: d.getAttribute('data-uc-pick') || 'who', label: text(q('summary', d)), summaryH: Math.round(q('summary', d).getBoundingClientRect().height),
        panel: rect(panel), past: past(d), small: phone ? small(d) : [],
        opts: qa('[data-uc-pick-opt]', d).map(function (i) { return i.value + '=' + text(i.closest('label')); }) };
      d.open = false;
      return r;
    });
    out.barTops = who.map(function (d) { return Math.round(d.getBoundingClientRect().top); });
    out.small = phone ? small(q('.uc-filters, .uc-filter-bar, .uc-calendar') || document.body) : [];
  }

  /* The event page's pills. */
  var pills = qa('.uc-single .uc-tag-pill');
  if (pills.length) {
    out.pills = pills.map(function (a) { return { text: text(a), href: a.getAttribute('href'), seen: seen(a), box: rect(a) }; });
    var hosted = qa('.uc-single *').filter(function (e) { return /^Hosted by/.test(text(e)) && e.children.length < 4; })[0];
    out.hostedBottom = hosted ? Math.round(hosted.getBoundingClientRect().bottom) : null;
    out.pillsLabel = (q('.uc-single .uc-tag-pills') || {}).getAttribute ? q('.uc-single .uc-tag-pills').getAttribute('aria-label') : null;
  }

  if (q('.uc-portal-main')) {
    out.small = phone ? small(main) : [];
    var tick = q('[data-uc-multiday-tick]');
    if (tick) {
      var end = q('[data-uc-multiday-end]'), date = q('[name="date"]'), rep = q('[data-uc-repeat-wrap]');
      out.multi = { ticked: tick.checked, end: seen(end), endInput: end ? (q('input', end) || end).value : null,
        endBox: rect(end), dateBox: rect(date && (date.closest('.uc-field') || date)), repeats: seen(rep) };
      tick.click();
      out.multi.untickedEnd = seen(end); out.multi.untickedRepeats = seen(rep);
      tick.click();

      /* G: the keepalive. */
      if (window.ucSession && window.ucSession.keepalive) {
        window.LV_calls = [];
        window.LV_skew = 31 * 60 * 1000;
        window.ucSession.keepalive();
        var idle = window.LV_calls.filter(function (b) { return /keepalive=1/.test(b); }).length;
        document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        window.LV_skew = 32 * 60 * 1000;
        window.ucSession.keepalive();
        var sent = function () { return window.LV_calls.filter(function (b) { return /keepalive=1/.test(b); }).length; };
        out.keep = { idle: idle, after: sent() - idle };
        /* And a keystroke, after another idle half hour. */
        window.LV_skew = 64 * 60 * 1000;
        var before = sent();
        window.ucSession.keepalive();
        out.keep.idle += sent() - before;
        document.body.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'a' }));
        window.LV_skew = 65 * 60 * 1000;
        before = sent();
        window.ucSession.keepalive();
        out.keep.key = sent() - before;
      } else { out.keep = null; }
    }
    var rows = qa('[data-uc-closed-row]');
    if (q('[data-uc-closed-list]') || rows.length) {
      out.closed = rows.map(function (r) {
        var keep = qa('button, a', r).filter(function (b) { return /^Keep/.test(text(b)); })[0];
        var cancel = qa('button, a', r).filter(function (b) { return /^Cancel/.test(text(b)); })[0];
        return { text: text(r).slice(0, 200), keep: keep ? rect(keep) : null, cancel: cancel ? rect(cancel) : null, past: past(r) };
      });
      out.tab = text(q('.uc-tabs [aria-current], .uc-tab.is-active, [aria-current="page"]'));
    }
  }
  finish();
}, 700); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { lv( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inner = __DIR__ . '/release-31120-live-' . $name . '.html';
    $doc   = preg_replace( '#<head>#i', '<head><script>' . $head . '</script>', $p['html'], 1 );
    file_put_contents( $inner, preg_replace( '#</body>(?![\s\S]*</body>)#i', '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $doc, 1 ) );
    $files[ $name ] = __DIR__ . '/release-31120-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args  = array_slice( $argv, 1 );
$shots = in_array( '--shots', $args, true );
if ( ! in_array( '--run', $args, true ) && ! $shots ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
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
    if ( 0 !== strpos( $name, 'page' ) ) {
        lv( array() === (array) ( isset( $g[ $name ]['small'] ) ? $g[ $name ]['small'] : array() ), "$name: tap targets under 44px: " . json_encode( isset( $g[ $name ]['small'] ) ? $g[ $name ]['small'] : null ) );
    }
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };
foreach ( array( 'desktop' => 1280, 'wide' => 430, 'phone' => 390 ) as $wn => $w ) {
    /* The bar. */
    if ( $w < 600 ) {
        lv( (int) $v( "bar-$wn", 'coarse' ) >= 1, "bar-$wn: no pointer: coarse rules were found to switch on, so the 44px floor was not measured" );
    }
    $bar   = (array) $v( "bar-$wn", 'bar' );
    $kinds = array_map( function ( $d ) { return $d['kind']; }, $bar );
    lv( array( 'who', 'tag', 'location' ) === $kinds, "A.3/B: bar-$wn: the bar is not Organizers and groups, What's included, Location: " . json_encode( $kinds ) );
    foreach ( $bar as $d ) {
        lv( $d['panel'] && $d['panel']['left'] >= 0 && $d['panel']['right'] <= $w && array() === $d['past'], "bar-$wn: the {$d['kind']} panel is not inside the screen: " . json_encode( array( $d['panel'], $d['past'] ) ) );
        lv( array() === $d['small'], "bar-$wn: the {$d['kind']} panel has controls under 44px: " . json_encode( $d['small'] ) );
    }
    $tag = $bar ? array_values( array_filter( $bar, function ( $d ) { return 'tag' === $d['kind']; } ) ) : array();
    $loc = $bar ? array_values( array_filter( $bar, function ( $d ) { return 'location' === $d['kind']; } ) ) : array();
    lv( $tag && 'Tags' === $tag[0]['label'] && 2 === count( $tag[0]['opts'] ) && (bool) preg_match( '/^alpha=Alpha\b.*2/', $tag[0]['opts'][0] ), "A.3: bar-$wn: Tags does not offer both tags with their counts: " . json_encode( $tag ) );
    lv( $loc && 'Location' === $loc[0]['label'] && 3 === count( $loc[0]['opts'] ), "B: bar-$wn: Location does not offer the venue and the two addresses: " . json_encode( $loc ) );

    /* The event page. */
    $pills = (array) $v( "page-$wn", 'pills' );
    lv( 2 === count( $pills ) && array( 'Alpha', 'Beta' ) === array_column( $pills, 'text' ), "A.3: page-$wn: the event's two pills are not drawn: " . json_encode( $pills ) );
    foreach ( $pills as $p ) {
        lv( $p['seen'] && false !== strpos( (string) $p['href'], 'uc_tag=' ) && $p['box']['h'] >= 24 && $p['box']['right'] <= $w, "A.3: page-$wn: a pill is hidden, unlinked, under 24px or off the screen: " . json_encode( $p ) );
        lv( null !== $v( "page-$wn", 'hostedBottom' ) && $p['box']['top'] >= $v( "page-$wn", 'hostedBottom' ), "A.3: page-$wn: the pills are not under Hosted by" );
    }
    lv( 'Tags' === $v( "page-$wn", 'pillsLabel' ), "A.4: page-$wn: the pills are not named Tags" );

    /* The editor. */
    $m = (array) $v( "edit-$wn", 'multi' );
    lv( $m && true === $m['ticked'] && true === $m['end'] && false === $m['repeats'] && $m['endInput'] === date( 'Y-m-d', strtotime( '+12 days' ) ),
        "C.1: edit-$wn: ticked, the editor does not show End date and hide Repeats: " . json_encode( $m ) );
    lv( $m && false === $m['untickedEnd'] && true === $m['untickedRepeats'], "C.1: edit-$wn: unticked, End date stays or Repeats does not come back: " . json_encode( $m ) );
    if ( 'desktop' === $wn ) {
        lv( $m && $m['endBox'] && $m['dateBox'] && abs( $m['endBox']['top'] - $m['dateBox']['top'] ) <= 4, "C.1: edit-$wn: End date is not beside Date: " . json_encode( $m ) );
    }
    $k = $v( "edit-$wn", 'keep' );
    lv( is_array( $k ) && 0 === $k['idle'], "PLANT G: edit-$wn: the keepalive fired after thirty idle minutes: " . json_encode( $k ) );
    lv( is_array( $k ) && 1 === $k['after'] && 1 === $k['key'], "G: edit-$wn: the keepalive did not fire after a click and after a keystroke: " . json_encode( $k ) );

    /* On closed days. */
    $c = (array) $v( "closed-$wn", 'closed' );
    lv( 1 === count( $c ) && $c[0]['keep'] && $c[0]['cancel'] && array() === $c[0]['past'] && false !== strpos( $c[0]['text'], 'Thanksgiving' ),
        "D.2: closed-$wn: one row naming the closure with Keep and Cancel inside the screen: " . json_encode( $c ) );
}

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( $pages as $name => $p ) {
        $png   = __DIR__ . "/screens/release-31120-$name.png";
        $frame = __DIR__ . "/release-31120-shot-$name-frame.html";
        file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="release-31120-live-' . $name . '.html" style="display:block;width:' . $p['w'] . 'px;height:1400px;border:0"></iframe></body></html>' );
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $p['w'] ) . ',1400 --virtual-time-budget=8000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
        lv( file_exists( $png ), "no screenshot written for $name" );
    }
}

if ( $fails ) {
    echo 'RELEASE 3.112.0 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.112.0 live: at 1280px, 430px and 390px, the bar's three dropdowns each open inside the screen with 44px controls; What's included and Location list their options with counts; the event page's pills under Hosted by, linked to uc_tag; the editor shows End date beside Date and hides Repeats while Multi-day is ticked; On closed days has its row with Keep and Cancel; the keepalive waits for a click; nothing off the edge.\n";
