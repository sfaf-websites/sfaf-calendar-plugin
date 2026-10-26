<?php
/**
 * 3.123.0 IN A REAL BROWSER: EDIT EVENT'S HEADER.
 *
 *     php .claude/release-31230-live.php            writes the pages
 *     php .claude/release-31230-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31230-live.php --shots    --run, and writes .claude/screens/editor-head-*.png
 *
 * The real editor through wp-kit.php with the real portal.css and portal.js,
 * at 1280px, 430px and 390px, for an event that takes RSVPs and has 12
 * confirmed, a draft, and Add event.
 *
 *   A  Back leads with the chevron icon at the stroke the other caladmin icons
 *      draw (2, round), the same as the copy icon beside it; no text arrow
 *   B  View RSVPs (12) is a button drawn as View event page is, beside it,
 *      with the count of confirmed registrations; a draft's reads (0)
 *   C  at 390px and 430px: two rows, the title with Take the tour and Back,
 *      then the page's buttons on one row; nothing overlaps, nothing leaves
 *      the window, the page does not scroll sideways, every tap target is at
 *      least 44px tall and 44px wide; "Copied" moves nothing when it shows.
 *      At 1280px one row, as before
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rh( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rh_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); }
    catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}

$GLOBALS['kit_query'] = function ( $a ) { return array(); };
function rh_event( $status ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Community dinner', 'post_name' => 'community-dinner-' . $status, 'post_author' => 1 ) );
    foreach ( array( '_uc_event_date' => '2026-12-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( 11 );
    return $id;
}
$pub   = rh_event( 'publish' );
$draft = rh_event( 'draft' );
/* Twelve confirmed on the published event; every other count is the kit's empty answer. */
$GLOBALS['kit_db'] = function ( $method, $sql ) use ( $pub ) {
    if ( 'get_var' === $method && false !== strpos( $sql, 'uc_rsvps' ) && false !== strpos( $sql, "status = 'confirmed'" ) && false !== strpos( $sql, 'event_id = ' . $pub ) ) { return 12; }
    return 'get_results' === $method ? array() : null;
};

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function rh_screen( $id ) {
    return rh_capture( function () use ( $id ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $GLOBALS['portal'], array( $GLOBALS['user'], $id ) ); } );
}
$html = array( 'pub' => rh_screen( $pub ), 'draft' => rh_screen( $draft ), 'add' => rh_screen( 0 ) );

$pages = array();
foreach ( $html as $screen => $h ) {
    foreach ( array( 1280, 430, 390 ) as $w ) { $pages[ "$screen-$w" ] = array( 'w' => $w, 'html' => $h ); }
}

$probe = <<<'JS'
(function () {
var out = { page: window.RV_PAGE, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; out.winWidth = window.innerWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  var head = q('.uc-page-head.uc-editor-head');
  out.head = rect(head);
  out.h1 = rect(q('h1', head));
  var back = q('.uc-back-btn', head);
  out.back = back ? { text: text(back), box: rect(back), svg: !!q('svg', back), stroke: q('svg', back) ? getComputedStyle(q('svg', back)).strokeWidth : null,
                      cap: q('svg', back) ? q('svg', back).getAttribute('stroke-linecap') : null, iconW: q('svg', back) ? Math.round(q('svg', back).getBoundingClientRect().width) : 0,
                      bg: getComputedStyle(back).backgroundColor, radius: getComputedStyle(back).borderRadius } : null;
  var copy = q('[data-uc-copy-text]', head);
  out.copyStroke = copy && q('svg', copy) ? getComputedStyle(q('svg', copy)).strokeWidth : null;
  var view = q('[data-uc-saved-action="view-page"], [data-uc-saved-action="preview"]', head);
  var rsvps = q('[data-uc-saved-action="rsvp-list"]', head);
  out.view = view ? { text: text(view), box: rect(view), bg: getComputedStyle(view).backgroundColor, border: getComputedStyle(view).borderTopColor, weight: getComputedStyle(view).fontWeight, size: getComputedStyle(view).fontSize, radius: getComputedStyle(view).borderRadius } : null;
  out.rsvps = rsvps ? { text: text(rsvps), cls: rsvps.className, box: rect(rsvps), bg: getComputedStyle(rsvps).backgroundColor, border: getComputedStyle(rsvps).borderTopColor, weight: getComputedStyle(rsvps).fontWeight, size: getComputedStyle(rsvps).fontSize, radius: getComputedStyle(rsvps).borderRadius } : null;
  out.tour = rect(q('[data-uc-tour-start]', head));
  out.links = rect(q('.uc-page-links', head));
  /* Every control in the header, with its box. */
  out.targets = qa('a, button', head).map(function (el) { return { name: text(el) || el.getAttribute('aria-label'), box: rect(el) }; });
  if (copy) {
    var before = out.targets.map(function (t) { return t.box; });
    /* Headless Chrome has no clipboard to write to; the layout is what is measured here. */
    try { Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: function () { return Promise.resolve(); } } }); } catch (e) { out.errors.push('no clipboard stub'); }
    copy.click();
    setTimeout(function () {
      var said = q('[data-uc-copy-said]', head);
      out.copied = text(said);
      out.saidBox = rect(said);
      out.moved = qa('a, button', head).some(function (el, i) { var r = rect(el), b = before[i]; return !b || r.top !== b.top || r.left !== b.left || r.w !== b.w; });
      finish();
    }, 300);
  } else { finish(); }
}, 400); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rh( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inner = __DIR__ . '/release-31230-live-' . $name . '.html';
    file_put_contents( $inner, preg_replace( '#</body>#i', '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $p['html'], 1 ) );
    $files[ $name ] = __DIR__ . '/release-31230-live-' . $name . '-frame.html';
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
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
$g = array();
foreach ( $files as $name => $file ) {
    $w   = $pages[ $name ]['w'];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w + 20 ) . ',1000 --virtual-time-budget=8000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rh( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rh( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rh( (int) $g[ $name ]['pageWidth'] <= $w, "PLANT C: $name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}

function rh_mid( $b ) { return ( $b['top'] + $b['bottom'] ) / 2; }
function rh_overlap( $a, $b ) { return $a['left'] < $b['right'] && $b['left'] < $a['right'] && $a['top'] < $b['bottom'] && $b['top'] < $a['bottom']; }

foreach ( $g as $name => $o ) {
    list( $screen, $w ) = explode( '-', $name );
    $w     = (int) $w;
    $phone = $w <= 600;

    /* A */
    $b = (array) $o['back'];
    rh( 'Back' === ( $b['text'] ?? '' ) && true === ( $b['svg'] ?? false ), "PLANT A: $name: Back is not the chevron and the word: " . json_encode( $b ) );
    rh( '2px' === ( $b['stroke'] ?? '' ) && 'round' === ( $b['cap'] ?? '' ) && 16 === ( $b['iconW'] ?? 0 ), "PLANT A: $name: the chevron is not the 16px icon at stroke 2: " . json_encode( $b ) );
    if ( null !== $o['copyStroke'] ) { rh( $o['copyStroke'] === ( $b['stroke'] ?? '' ), "A: $name: the chevron's stroke is not the copy icon's" ); }

    /* B */
    if ( 'pub' === $screen ) {
        $r = (array) $o['rsvps']; $v = (array) $o['view'];
        rh( 'View RSVPs (12)' === ( $r['text'] ?? '' ), "PLANT B: $name: the RSVP button reads " . json_encode( $r['text'] ?? null ) );
        foreach ( array( 'bg', 'border', 'weight', 'size', 'radius' ) as $k ) {
            rh( isset( $r[ $k ], $v[ $k ] ) && $r[ $k ] === $v[ $k ], "PLANT B: $name: View RSVPs is not drawn as View event page is ($k): " . json_encode( array( $r[ $k ] ?? null, $v[ $k ] ?? null ) ) );
        }
        if ( $r && $v ) { rh( abs( rh_mid( $r['box'] ) - rh_mid( $v['box'] ) ) <= 2 && $r['box']['right'] <= $v['box']['left'] && $v['box']['left'] - $r['box']['right'] <= 12, "PLANT B: $name: View RSVPs is not beside View event page: " . json_encode( array( $r['box'], $v['box'] ) ) ); }
    } elseif ( 'draft' === $screen ) {
        /* A draft that takes RSVPs has its list too, beside Preview. */
        rh( 'View RSVPs (0)' === ( $o['rsvps']['text'] ?? '' ), "PLANT B: $name: the draft's RSVP button reads " . json_encode( $o['rsvps']['text'] ?? null ) );
    } else {
        rh( null === $o['rsvps'], "B: $name: a View RSVPs button on Add event" );
    }

    /* C */
    $t = (array) $o['tour']; $bk = (array) $b['box']; $h1 = (array) $o['h1'];
    rh( $t && $bk && abs( rh_mid( $t ) - rh_mid( $bk ) ) <= 4 && $t['right'] <= $bk['left'], "PLANT C: $name: Take the tour and Back are not one row: " . json_encode( array( $t, $bk ) ) );
    rh( $h1 && $bk && $h1['right'] <= $t['left'] && $bk['top'] < $h1['bottom'] && $h1['top'] < $bk['bottom'], "PLANT C: $name: the title is not on Back's row: " . json_encode( array( $h1, $t, $bk ) ) );
    $boxes = array_map( function ( $x ) { return $x['box']; }, (array) $o['targets'] );
    foreach ( (array) $o['targets'] as $i => $x ) {
        $xb = $x['box'];
        rh( $xb['left'] >= 0 && $xb['right'] <= $w, "PLANT C: $name: \"{$x['name']}\" leaves the window: " . json_encode( $xb ) );
        if ( $phone ) { rh( $xb['h'] >= 44 && $xb['w'] >= 44, "PLANT C: $name: \"{$x['name']}\" is under 44px: " . json_encode( $xb ) ); }
        foreach ( $boxes as $j => $yb ) {
            if ( $j > $i ) { rh( ! rh_overlap( $xb, $yb ), "PLANT C: $name: \"{$x['name']}\" overlaps " . json_encode( $o['targets'][ $j ]['name'] ) ); }
        }
    }
    if ( $o['links'] ) {
        /* The page's buttons: one row, below the first. */
        $l = $o['links'];
        rh( $phone ? $l['top'] >= max( $bk['bottom'], $h1['bottom'] ) : abs( rh_mid( $l ) - rh_mid( $bk ) ) <= 4, "PLANT C: $name: the page's buttons are not " . ( $phone ? 'below the first row' : 'on Back\'s row' ) . ': ' . json_encode( array( $l, $bk ) ) );
        $mids = array();
        foreach ( (array) $o['targets'] as $x ) { if ( $x['box']['top'] >= $l['top'] - 1 && $x['box']['bottom'] <= $l['bottom'] + 1 ) { $mids[] = rh_mid( $x['box'] ); } }
        rh( $mids && max( $mids ) - min( $mids ) <= 2, "PLANT C: $name: the page's buttons wrap: " . json_encode( $mids ) );
        $rows = $phone ? 2 : 1;
        rh( $o['head']['bottom'] <= $l['bottom'] + 1, "C: $name: something in the header below the page's buttons" );
    }
    if ( isset( $o['copied'] ) ) {
        rh( 'Copied' === $o['copied'], "C: $name: the copy icon did not say Copied: " . json_encode( $o['copied'] ) );
        rh( false === $o['moved'], "PLANT C: $name: showing Copied moved the header's buttons" );
        rh( $o['saidBox'] && $o['saidBox']['right'] <= $w && $o['saidBox']['left'] >= 0, "C: $name: Copied leaves the window: " . json_encode( $o['saidBox'] ) );
    }
}
rh( count( $g ) === count( $pages ), 'only ' . count( $g ) . ' of ' . count( $pages ) . ' pages measured' );

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( array( 'pub-1280', 'pub-390', 'draft-390', 'add-390' ) as $name ) {
        $png = __DIR__ . "/screens/editor-head-$name.png";
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $pages[ $name ]['w'] ) . ',360 --virtual-time-budget=6000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $files[ $name ] ) . '" 2>NUL' );
        rh( file_exists( $png ), "no screenshot written for $name" );
    }
}

echo "\n3.123.0 LIVE: Edit event's header\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Back is the chevron at the icons' stroke, View RSVPs (12) is a button beside View event page, and at 390px and 430px the header\nis two tidy rows with every tap target 44px or more, nothing overlapping or leaving the window; one row at 1280px.\n";
exit( 0 );
