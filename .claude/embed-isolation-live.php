<?php
/**
 * THE EMBED LOOKS THE SAME ON EVERY HOST, AND THE COMBINED SIDEBAR ENDS AT ITS
 * CONTENT (3.111.0, J and K).
 *
 *     php .claude/embed-isolation-live.php            writes the pages
 *     php .claude/embed-isolation-live.php --run      writes them, drives Chrome, checks
 *     php .claude/embed-isolation-live.php --shots    --run, and writes .claude/screens/embed-31110-*.png
 *
 * REAL: the combined block from render_calendar_block() through wp-kit.php,
 * public/js/embed.js loaded by src (so it derives calendar.css from its own
 * address, as on a host), calendar.css. STUBBED: the endpoint, answered from
 * that rendered block by a stand-in XMLHttpRequest.
 *
 *   J  The same block on a clean page and on a page whose stylesheet sets
 *      color, background, border, font, text-transform, box-shadow,
 *      line-height, letter-spacing and text-decoration on buttons, links and
 *      spans, at element, class-in-content and :hover-ish specificity. Every
 *      button, link and span inside the block is measured on both and every
 *      value must be equal; the hover panel too. Then the other direction:
 *      a host element wearing the calendar's own class names is unstyled by
 *      it, and the fonts link is in the document head.
 *      THE calendar.js PAGE, the same block in the page's own DOM with
 *      calendar.css in the head, is measured the same way, so a leak there
 *      is named rather than assumed away.
 *   K  The combined view at 1280px and 390px with data-count="4": four dates,
 *      the sidebar panel ends at its content, See all events sits directly
 *      under the month pills, and every pill shows a short month, untruncated.
 *
 * ISOLATION IS OPT-IN (data-isolate="yes"), so the embed is measured both ways:
 * with the attribute every value is the embed's own (PLANT J turns it off);
 * without it the block is in the page, calendar.css is in the head, no fonts
 * link is added and the host stylesheet is allowed through, as in 3.110.5
 * (PLANT J2 turns isolation on for every block).
 *
 * PLANT J puts the block back in the page's own DOM; PLANT K puts the 3.81.0
 * stretch back. plant-31110.php carries both.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }

$fails = array();
function ei( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

function ei_query( $a ) {
    $type = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $out  = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( $type === $p->post_type && 'publish' === $p->post_status ) { $out[] = $p; }
    }
    usort( $out, function ( $x, $y ) { return strcmp( (string) get_post_meta( $x->ID, '_uc_event_date', true ), (string) get_post_meta( $y->ID, '_uc_event_date', true ) ); } );
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { $out = wp_list_pluck( $out, 'ID' ); }
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
    return $per > 0 ? array_slice( $out, 0, $per ) : $out;
}
$GLOBALS['kit_query'] = 'ei_query'; $GLOBALS['kit_get_posts'] = 'ei_query'; $GLOBALS['kit_loop'] = true;
$titles = array( 'Coffee social', 'Harm reduction drop-in', 'Autumn walk', 'Queer book club', 'Strut open house', 'Cycle to Zero ride briefing', 'Volunteer orientation' );
foreach ( $titles as $i => $t ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $t, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+' . ( $i + 1 ) . ' days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 11 );
}

$sc    = new SFAF_Shortcodes();
$block = $sc->render_calendar_block( array( 'view' => 'combined', 'count' => 4, 'filters' => 'category', 'toggle' => 'yes' ) );
$markup = is_array( $block ) ? (string) $block['html'] : '';
ei( '' !== $markup && false !== strpos( $markup, 'uc-view-panels-combined' ), 'the combined block did not render' );
preg_match_all( '#class="uc-sidebar-row[ "]#', $markup, $rows );
ei( 4 === count( $rows[0] ), 'K: the combined view with count 4 drew ' . count( $rows[0] ) . ' sidebar dates' );
preg_match_all( '#<span class="uc-month-tab-name">([^<]*)</span>#', $markup, $names );
ei( $names[1] && ! array_filter( $names[1], function ( $n ) { return ! preg_match( '/^[A-Z][a-z]{2}$/', $n ); } ), 'K: a month pill is not a short month: ' . json_encode( $names[1] ) );

/* The generator offers the count for the combined view and writes it. */
$admin = file_get_contents( dirname( __DIR__ ) . '/admin/class-sfaf-admin.php' );
$ajs   = file_get_contents( dirname( __DIR__ ) . '/admin/js/admin.js' );
ei( false !== strpos( $admin, 'data-when-view="sidebar combined">' . "\n" . '                        <label class="uc-embed-label" for="uc-embed-count">' ), 'K: the generator does not offer How many to show on the combined view' );
ei( (bool) preg_match( "/if \\(choices\\.view === 'combined'\\) \\{\\s*attrs\\['data-count'\\] = String\\(choices\\.count\\);/", $ajs ), 'K: the generator does not write data-count for the combined view' );

/* The generator: "Protect from the host page's styles", off by default, writing
   data-isolate="yes" only when ticked. */
ei( (bool) preg_match( '#<input type="checkbox" id="uc-embed-isolate" />\s*Protect from the host page\'s styles#', $admin ), 'J: the generator has no "Protect from the host page\'s styles" tick that is off by default' );
ei( (bool) preg_match( "/if \\(choices\\.isolate\\) \\{\\s*attrs\\['data-isolate'\\] = 'yes';/", $ajs ), 'J: the generator does not write data-isolate="yes" only when ticked' );

/* ---- The hostile host. ---------------------------------------------------- */
$hostile = <<<'CSS'
button, a, span, li, p, div, h3 { color: #ff00aa; background: #111 linear-gradient(#e0007a, #e0007a); border: 3px dashed #00ff00;
  font-family: "Comic Sans MS", cursive; text-transform: uppercase; box-shadow: 0 0 0 6px #ff0; line-height: 3; letter-spacing: 4px;
  text-decoration: underline wavy; font-weight: 900; border-radius: 0; }
.entry-content button, .entry-content a, .entry-content span { color: #ff69b4; background-color: #c2185b; padding: 20px; }
body .entry-content a:not(.x), body .entry-content button:not(.x) { font-size: 22px; font-style: italic; }
CSS;
$probe = <<<'JS'
(function () {
var out = { errors: window.__errs || [] };
var PROPS = ['color', 'background-color', 'background-image', 'border-top-color', 'border-top-width', 'border-top-style',
  'border-left-color', 'border-left-width', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-transform',
  'box-shadow', 'line-height', 'letter-spacing', 'text-decoration-line', 'border-radius', 'padding-top', 'padding-left'];
function css(el) { var cs = getComputedStyle(el), o = {}; PROPS.forEach(function (p) { o[p] = cs.getPropertyValue(p); }); return o; }
function rect(el) { if (!el) { return null; } var b = el.getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), left: Math.round(b.left), right: Math.round(b.right), h: Math.round(b.height), w: Math.round(b.width) }; }
function path(el, top) { var p = []; for (var n = el; n && n !== top; n = n.parentNode) { if (n.nodeType === 1) { p.unshift(n.tagName.toLowerCase() + (n.className && typeof n.className === 'string' ? '.' + n.className.trim().split(/\s+/)[0] : '')); } } return p.slice(-3).join('>'); }
function sweep(scope, top) {
  return Array.prototype.slice.call(scope.querySelectorAll('button, a, span')).filter(function (el) { return el.getClientRects().length; })
    .map(function (el) { return { at: path(el, top), css: css(el) }; });
}
function sheetsIn(root) {
  var links = root.querySelectorAll('link[rel="stylesheet"]');
  return links.length > 0 && Array.prototype.every.call(links, function (l) { return !!l.sheet; });
}
function finish() { out.pageWidth = document.documentElement.scrollWidth; parent.postMessage(JSON.stringify(out), '*'); }
var tries = 0;
(function wait() {
  var host = document.querySelector('[data-sfaf-calendar]');
  var lp = document.querySelector('.uc-view-panels');
  var flat = window.EI_MODE === 'light' || window.EI_MODE === 'open';
  var scope = flat ? (lp && lp.closest('.uc-calendar')) : (host && host.shadowRoot);
  /* Loaded, not merely linked: a <link> in a shadow root loads like any other, and
     measuring before it arrives measures an unstyled block. */
  var ready = scope && scope.querySelector('.uc-view-panels') && (window.EI_MODE === 'light' || window.EI_MODE === 'none' || sheetsIn(window.EI_MODE === 'open' ? document : scope));
  if (!ready && tries++ < 200) { setTimeout(wait, 100); return; }
  out.unready = !ready;
  /* The page's no-transition rule does not cross into a shadow root, and a pill
     caught mid-transition measures as a colour nobody chose: the same rule goes
     inside. Then wait for the block to stop changing, so both pages are measured
     at rest rather than mid-redraw. */
  if (scope && scope !== document && scope.host && !scope.querySelector('style[data-ei]')) {
    var still = document.createElement('style'); still.setAttribute('data-ei', '');
    still.textContent = '*,*::before,*::after{transition:none!important;animation:none!important}';
    scope.appendChild(still);
  }
  var last = -1, same = 0, rounds = 0;
  (function steady() {
    var n = scope ? scope.querySelectorAll('*').length : 0;
    same = (n === last) ? same + 1 : 0; last = n;
    if (same < 3 && rounds++ < 40) { setTimeout(steady, 150); return; }
    measure();
  })();
  function measure() {
  setTimeout(function () {
    out.shadow = !!(host && host.shadowRoot);
    out.inLight = !!document.querySelector('.uc-view-panels');
    out.fontsLink = !!document.querySelector('head link[data-sfaf-calendar-css="fonts"]');
    out.headCss = !!document.querySelector('head link[data-sfaf-calendar-css="1"]');
    if (!scope) { out.noScope = true; finish(); return; }
    out.items = sweep(scope, scope);
    var probe = document.querySelector('.host-probe');
    out.hostProbe = probe ? css(probe) : null;
    var panel = scope.querySelector('.uc-panel-sidebar'), side = scope.querySelector('.uc-sidebar');
    var tabs = scope.querySelector('.uc-month-tabs'), all = scope.querySelector('.uc-sidebar-all'), grid = scope.querySelector('.uc-panel-calendar');
    out.k = { panel: rect(panel), side: rect(side), tabs: rect(tabs), all: rect(all), grid: rect(grid),
      padBottom: panel ? parseFloat(getComputedStyle(panel).paddingBottom) : null,
      rows: scope.querySelectorAll('.uc-panel-sidebar .uc-sidebar-row').length,
      pills: Array.prototype.map.call(scope.querySelectorAll('.uc-month-tab-name'), function (n) { return { text: n.textContent.trim(), cut: n.scrollWidth > n.clientWidth + 1, w: n.clientWidth }; }),
      family: (function (el) { return el ? getComputedStyle(el).fontFamily : ''; })(scope.querySelector('.uc-sidebar-heading, .uc-month-title, .uc-calendar')) };
    /* The hover preview, opened on the first tile. */
    var tile = scope.querySelector('.uc-day-event a[data-uc-preview]');
    out.tile = !!tile;
    if (tile) { tile.dispatchEvent(new MouseEvent('mouseover', { bubbles: true, composed: true })); }
    var waited = 0;
    (function settle() {
      var pr = document.querySelector('[data-sfaf-calendar-preview]');
      if (pr && pr.shadowRoot && !sheetsIn(pr.shadowRoot) && waited++ < 60) { setTimeout(settle, 100); return; }
      if (pr && pr.shadowRoot && !pr.shadowRoot.querySelector('style[data-ei]')) {
        var st = document.createElement('style'); st.setAttribute('data-ei', '');
        st.textContent = '*,*::before,*::after{transition:none!important;animation:none!important}';
        pr.shadowRoot.appendChild(st);
      }
      setTimeout(measurePreview, 500);
    })();
    function measurePreview() {
      var proot = document.querySelector('[data-sfaf-calendar-preview]');
      var mp = (proot && proot.shadowRoot && proot.shadowRoot.querySelector('.uc-mp')) || document.querySelector('.uc-mp');
      out.preview = mp ? { open: mp.matches(':popover-open'), inShadow: !!(proot && proot.shadowRoot && proot.shadowRoot.contains(mp)), css: css(mp),
        items: sweep(mp, mp) } : null;
      finish();
    }
  }, 300);
  }
})();
})();
JS;

$root  = dirname( __DIR__ );
$fake  = 'window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});'
    . '(function(){var P=' . json_encode( array( 'html' => $markup, 'css_url' => '', 'js_version' => '', 'total' => 0, 'max_pages' => 1, 'has_more' => false, 'card_style' => '' ) ) . ';'
    . 'function F(){this.readyState=0;this.status=0;this.responseText="";}F.prototype.open=function(m,u){this._u=u;};F.prototype.setRequestHeader=function(){};'
    . 'F.prototype.send=function(){var s=this;setTimeout(function(){s.readyState=4;s.status=200;s.responseText=JSON.stringify(P);if(s.onreadystatechange){s.onreadystatechange();}if(s.onload){s.onload();}},0);};F.prototype.abort=function(){};window.XMLHttpRequest=F;})();';
function ei_page( $mode, $hostile_css, $markup, $fake, $probe, $count = 4 ) {
    $head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . ( 'light' === $mode ? '<link rel="stylesheet" href="../public/css/calendar.css">' : '' )
        . ( '' !== $hostile_css ? '<style>' . $hostile_css . '</style>' : '' )
        . '<style>html,body{margin:0;padding:0;background:#fff}.page{padding:16px}*{transition:none!important;animation:none!important}</style>'
        . '<script>window.EI_MODE=' . json_encode( $mode ) . ';' . ( 'light' !== $mode ? $fake : 'window.__errs=[];window.addEventListener("error",function(e){window.__errs.push(e.message);});window.ucData={ajaxUrl:"",nonce:"n"};' ) . '</script></head><body><div class="page entry-content">'
        . '<div class="uc-calendar"><button type="button" class="uc-filter-btn host-probe">A host button</button></div>';
    if ( 'light' !== $mode ) {
        $body = '<div class="sfaf-calendar-embed" data-sfaf-calendar' . ( 'shadow' === $mode ? ' data-isolate="yes"' : '' ) . ' data-view="combined" data-count="' . (int) $count . '" data-filters="category" data-toggle="yes"></div>'
            . '</div><script src="../public/js/embed.js"></script>';
    } else {
        $body = $markup . '</div><script src="vendor/jquery-3.7.1.min.js"></script><script src="../public/js/calendar.js"></script>';
    }
    return $head . $body . '<script>' . $probe . '</script></body></html>';
}

$pages = array();
foreach ( array( 'shadow', 'open', 'light' ) as $mode ) {
    foreach ( array( 'clean' => '', 'hostile' => $hostile ) as $h => $css ) {
        foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
            if ( 'shadow' !== $mode && 'phone' === $wn ) { continue; }
            $pages[ "$mode-$h-$wn" ] = array( 'w' => $w, 'html' => ei_page( $mode, $css, $markup, $fake, $probe ) );
        }
    }
}
/* Two dates, so the grid is the taller column: the case K is about. */
$short = $sc->render_calendar_block( array( 'view' => 'combined', 'count' => 2, 'filters' => 'category', 'toggle' => 'yes' ) );
$fake2 = str_replace( substr( json_encode( $markup ), 1, -1 ), substr( json_encode( (string) $short['html'] ), 1, -1 ), $fake );
$pages['short-desktop'] = array( 'w' => 1280, 'html' => ei_page( 'shadow', '', $markup, $fake2, $probe, 2 ) );
/* A host page with the same host button and no calendar at all: the leak baseline. */
$pages['bare-desktop'] = array( 'w' => 1280, 'html' => '<!DOCTYPE html><html><head><meta charset="utf-8"><style>html,body{margin:0;padding:0;background:#fff}.page{padding:16px}</style><script>window.EI_MODE="none";window.__errs=[];</script></head><body><div class="page entry-content"><div class="uc-calendar"><button type="button" class="uc-filter-btn host-probe">A host button</button></div></div>'
    . '<script>' . str_replace( "var scope = flat ?", "var scope = window.EI_MODE === 'none' ? document : flat ?", str_replace( "scope.querySelector('.uc-view-panels') &&", "(window.EI_MODE === 'none' || scope.querySelector('.uc-view-panels')) &&", $probe ) ) . '</script></body></html>' );

$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/embed-isolation-live-' . $name . '.html';
    file_put_contents( $inner, $p['html'] );
    $files[ $name ] = __DIR__ . '/embed-isolation-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:1400px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args  = array_slice( $argv, 1 );
$shots = in_array( '--shots', $args, true );
if ( ! in_array( '--run', $args, true ) && ! $shots ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $w   = $pages[ $name ]['w'];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w + 20 ) . ',1500 --virtual-time-budget=40000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { ei( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    ei( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    // Not a finding about the embed: the page was measured before its stylesheet arrived.
    ei( empty( $g[ $name ]['unready'] ), "$name: the block or its stylesheet had not loaded after 20 seconds, so nothing on that page was measured at rest" );
    ei( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}
$v = function ( $n, $k ) use ( $g ) { return isset( $g[ $n ][ $k ] ) ? $g[ $n ][ $k ] : null; };

/* Two sweeps of one block, compared element by element. */
function ei_diff( $a, $b, $limit = 6 ) {
    $out = array();
    if ( count( (array) $a ) !== count( (array) $b ) ) { return array( 'element count ' . count( (array) $a ) . ' clean, ' . count( (array) $b ) . ' hostile' ); }
    foreach ( (array) $a as $i => $x ) {
        foreach ( $x['css'] as $p => $val ) {
            if ( $b[ $i ]['css'][ $p ] !== $val ) { $out[] = $x['at'] . ' ' . $p . ': ' . $val . ' became ' . $b[ $i ]['css'][ $p ]; }
        }
    }
    return $out;
}

/* ---- J: embed.js --------------------------------------------------------- */
foreach ( array( 'desktop', 'phone' ) as $wn ) {
    $c = "shadow-clean-$wn"; $h = "shadow-hostile-$wn";
    ei( true === $v( $c, 'shadow' ) && false === $v( $c, 'inLight' ), "PLANT J: $c: the block is not drawn in a shadow root" );
    ei( count( (array) $v( $c, 'items' ) ) >= 20, "J: $c: only " . count( (array) $v( $c, 'items' ) ) . ' buttons, links and spans were measured' );
    $d = ei_diff( $v( $c, 'items' ), $v( $h, 'items' ) );
    ei( ! $d, "PLANT J: $wn: the host page's stylesheet repaints the embed, " . count( $d ) . " value(s):\n      " . implode( "\n      ", array_slice( $d, 0, 8 ) ) );
    ei( true === $v( $c, 'fontsLink' ), "J: $c: the fonts are not linked in the document head" );
    $fam = (string) ( (array) $v( $c, 'k' ) )['family'];
    ei( 0 === strpos( $fam, 'Montserrat' ) || 0 === strpos( $fam, '"Montserrat"' ), "J: $c: the embed is not set in Montserrat: $fam" );
}
ei( $v( 'bare-desktop', 'hostProbe' ) === $v( 'shadow-clean-desktop', 'hostProbe' ), 'PLANT J: the calendar\'s stylesheet reaches a host element that shares its class names: ' . json_encode( array( $v( 'bare-desktop', 'hostProbe' ), $v( 'shadow-clean-desktop', 'hostProbe' ) ) ) );
$pc = (array) $v( 'shadow-clean-desktop', 'preview' ); $ph = (array) $v( 'shadow-hostile-desktop', 'preview' );
ei( true === $v( 'shadow-clean-desktop', 'tile' ), 'J: the month grid drew no tile with a preview' );
ei( $pc && true === $pc['open'] && true === $pc['inShadow'], 'J: the hover preview did not open in a root of its own: ' . json_encode( $pc ? array( $pc['open'], $pc['inShadow'] ) : null ) );
if ( $pc && $ph ) {
    $d = ei_diff( array( array( 'at' => '.uc-mp', 'css' => $pc['css'] ) ), array( array( 'at' => '.uc-mp', 'css' => $ph['css'] ) ) );
    $d = array_merge( $d, ei_diff( $pc['items'], $ph['items'] ) );
    ei( ! $d, "PLANT J: the host page's stylesheet repaints the hover preview, " . count( $d ) . " value(s):\n      " . implode( "\n      ", array_slice( $d, 0, 8 ) ) );
}

/* ---- J: without data-isolate, the embed is what it was in 3.110.5 --------- */
ei( false === $v( 'open-clean-desktop', 'shadow' ) && true === $v( 'open-clean-desktop', 'inLight' ), 'PLANT J2: an embed without data-isolate is drawn in a shadow root' );
ei( true === $v( 'open-clean-desktop', 'headCss' ) && false === $v( 'open-clean-desktop', 'fontsLink' ), 'PLANT J2: an embed without data-isolate does not load calendar.css in the head alone: ' . json_encode( array( $v( 'open-clean-desktop', 'headCss' ), $v( 'open-clean-desktop', 'fontsLink' ) ) ) );
$open = ei_diff( $v( 'open-clean-desktop', 'items' ), $v( 'open-hostile-desktop', 'items' ), 0 );
ei( count( $open ) > 0, 'PLANT J2: without data-isolate the host stylesheet did not reach the embed, so it is isolated anyway' );
$po = (array) $v( 'open-clean-desktop', 'preview' );
ei( ! $po || false === $po['inShadow'], 'PLANT J2: on a page with no isolated block the hover preview is in a shadow root' );

/* ---- J: calendar.js, measured and reported -------------------------------- */
$light = ei_diff( $v( 'light-clean-desktop', 'items' ), $v( 'light-hostile-desktop', 'items' ), 0 );

/* ---- K ------------------------------------------------------------------- */
foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
    $k = (array) $v( "shadow-clean-$wn", 'k' );
    if ( ! $k ) { continue; }
    ei( 4 === $k['rows'], "K: $wn: the sidebar shows {$k['rows']} dates for data-count 4" );
    $foot = $k['panel']['bottom'] - $k['all']['bottom'];
    ei( $foot <= $k['padBottom'] + 2, "PLANT K: $wn: the sidebar runs {$foot}px past See all events, its padding is {$k['padBottom']}px" );
    $gap = $k['all']['top'] - $k['tabs']['bottom'];
    ei( $gap >= 0 && $gap <= 24, "K: $wn: See all events is {$gap}px under the month pills" );
    foreach ( (array) $k['pills'] as $pill ) {
        ei( preg_match( '/^[A-Z][a-z]{2}$/', $pill['text'] ) && ! $pill['cut'], "K: $wn: a month pill is long or truncated: " . json_encode( $pill ) );
    }
    ei( count( (array) $k['pills'] ) >= 1, "K: $wn: no month pills were drawn" );
}

$k = (array) $v( 'short-desktop', 'k' );
ei( $k && 2 === $k['rows'], 'K: short: the sidebar does not show two dates: ' . json_encode( $k ? $k['rows'] : null ) );
if ( $k ) {
    ei( $k['panel']['bottom'] < $k['grid']['bottom'] - 40 && $k['panel']['bottom'] - $k['all']['bottom'] <= $k['padBottom'] + 2,
        'PLANT K: with the grid the taller column, the sidebar is stretched to its height: ' . json_encode( array( 'panel' => $k['panel'], 'grid' => $k['grid'], 'all' => $k['all'] ) ) );
    $gap = $k['all']['top'] - $k['tabs']['bottom'];
    ei( $gap >= 0 && $gap <= 24, "PLANT K: with the grid the taller column, See all events is {$gap}px under the month pills" );
}

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( array( 'shadow-hostile-desktop' => 1280, 'shadow-hostile-phone' => 390, 'light-hostile-desktop' => 1280 ) as $k => $w ) {
        $png = __DIR__ . "/screens/embed-31110-$k.png";
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $w ) . ',1400 --virtual-time-budget=8000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $files[ $k ] ) . '" 2>NUL' );
        ei( file_exists( $png ), "no screenshot written for $k" );
    }
}

if ( $fails ) {
    echo 'EMBED ISOLATION LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo 'embed.js without data-isolate, under the hostile stylesheet: ' . count( $open ) . " value(s) repainted, as in 3.110.5.\n";
echo 'calendar.js on its own page, under the same stylesheet: ' . count( $light ) . " value(s) repainted.\n";
foreach ( array_slice( $light, 0, 12 ) as $l ) { echo '      ' . $l . "\n"; }
echo "embed isolation: with data-isolate=\"yes\" the embed and its hover preview measure the same under a hostile host stylesheet at 1280px and 390px, nothing of the calendar's reaches the host, the fonts load; the combined sidebar shows four dates, ends at its content with See all events under the short month pills.\n";
exit( 0 );
