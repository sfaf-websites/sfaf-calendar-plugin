<?php
/**
 * 3.142.0 IN A REAL BROWSER: THE BAR, THE CARD AND THE BANNER.
 *
 *     php .claude/release-31420-live.php            writes the pages
 *     php .claude/release-31420-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31420-live.php --run --verbose
 *
 * REAL: the blocks from render_calendar_block() through wp-kit.php, calendar.css,
 * and both scripts: calendar.js with the block in the page, and embed.js loading
 * the block into its own shadow root (data-isolate="yes") from a stand-in
 * endpoint, as on sfaf.org. Each page is drawn in an iframe of the width named,
 * because headless Chrome will not go under 504px.
 *
 * For each script, each view (month, list, sidebar) and each width (1280, 430,
 * 390):
 *
 *   month    the Pride week bar on today's cell, right under the number, icon
 *            left of the title, dark gray #373433 with yellow #FFD900 ink, inside
 *            its cell; at 430 and 390 the cell draws the strip instead and the
 *            day panel carries the bar. A click opens the card with the picture,
 *            the description and the link, inside the viewport; a second click,
 *            Escape and a click elsewhere close it. From the keyboard the link
 *            takes focus and Escape gives it back to the bar. On a device that
 *            hovers, hovering opens it and leaving closes it. The title-only bar
 *            opens nothing. The day is not marked closed.
 *   list     the banner above the first event, the same colours, the width of
 *            the list, and the same card.
 *   sidebar  today's bar above the first row, and the same card.
 *
 * Every page: no script error and no sideways scroll.
 *
 * PLANTs in plant-31420.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

function rl_query( $a ) {
    $type = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $out  = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) { if ( $type === $p->post_type && 'publish' === $p->post_status ) { $out[] = $p; } }
    usort( $out, function ( $x, $y ) { return strcmp( (string) get_post_meta( $x->ID, '_uc_event_date', true ), (string) get_post_meta( $y->ID, '_uc_event_date', true ) ); } );
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { $out = wp_list_pluck( $out, 'ID' ); }
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
    return $per > 0 ? array_slice( $out, 0, $per ) : $out;
}
$GLOBALS['kit_query'] = 'rl_query'; $GLOBALS['kit_get_posts'] = 'rl_query'; $GLOBALS['kit_loop'] = true;

$today = current_time( 'Y-m-d' );
$day   = function ( $n ) { return date( 'Y-m-d', strtotime( '+' . $n . ' days' ) ); };
foreach ( array( 0 => 'Coffee social', 1 => 'Harm reduction drop-in', 3 => 'Autumn walk', 4 => 'Queer book club' ) as $d => $t ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $t, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array( '_uc_event_date' => $day( $d ), '_uc_start_time' => '23:00', '_uc_end_time' => '23:30', '_uc_location' => '1035 Market Street' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 11 );
}

/* The picture: a real 1200 by 675 PNG beside the pages. */
function rl_png( $path, $w, $h ) {
    $chunk = function ( $t, $d ) { return pack( 'N', strlen( $d ) ) . $t . $d . pack( 'N', crc32( $t . $d ) ); };
    $raw = str_repeat( "\0" . str_repeat( "\x16\xbe\xcf", $w ), $h );
    file_put_contents( $path, "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, 2, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( $raw ) ) . $chunk( 'IEND', '' ) );
}
rl_png( __DIR__ . '/release-31420-pic.png', 1200, 675 );
$GLOBALS['kit_images'][ 77 ] = 'release-31420-pic.png';
$SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="#FFD900"/></svg>';
$pride = SFAF_Announcements::save( '', array( 'title' => 'Pride week', 'start' => $today, 'end' => $day( 1 ), 'desc' => 'Come by the SFAF booth on Market Street.',
    'link' => 'https://sfaf.org/pride', 'icon_svg' => $SVG, 'image_id' => 77 ) );
$move  = SFAF_Announcements::save( '', array( 'title' => 'Office move', 'start' => $day( 3 ) ) );
rl( is_string( $pride ) && is_string( $move ), 'the announcements could not be saved' );

$sc = new SFAF_Shortcodes();
$blocks = array();
foreach ( array( 'month' => 'calendar', 'list' => 'list', 'sidebar' => 'sidebar' ) as $v => $view ) {
    $b = $sc->render_calendar_block( array( 'view' => $view, 'count' => 5, 'show_filters' => 'no' ) );
    $blocks[ $v ] = is_array( $b ) ? (string) $b['html'] : '';
    rl( '' !== $blocks[ $v ] && false !== strpos( $blocks[ $v ], 'data-uc-ann-id="' . $pride . '"' ), "the $v block did not render the Pride week bar" );
}

$probe = <<<'JS'
(function () {
var out = { errors: window.__errs || [], view: window.RL_VIEW, width: window.innerWidth };
var DARK = 'rgb(55, 52, 51)', YELLOW = 'rgb(255, 217, 0)';
function rect(el) { if (!el) { return null; } var b = el.getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), left: Math.round(b.left), right: Math.round(b.right), h: Math.round(b.height), w: Math.round(b.width) }; }
function seen(el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden' && rect(el).w > 0; }
function rgb(s) { var m = String(s).match(/\d+(\.\d+)?/g); return m ? m.slice(0, 3).map(Number) : null; }
function lum(c) { return [0, 1, 2].map(function (i) { var v = c[i] / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function ratio(a, b) { var x = lum(rgb(a)), y = lum(rgb(b)); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; }
function applied(sheet) { var r; try { r = sheet.cssRules; } catch (e) { return true; } for (var i = 0; i < r.length; i++) { if (r[i].type !== 3) { break; } if (!r[i].styleSheet || !applied(r[i].styleSheet)) { return false; } } return true; }
function sheetsIn(root) { var l = root.querySelectorAll('link[rel="stylesheet"]'); return l.length > 0 && Array.prototype.every.call(l, function (x) { return !!x.sheet && applied(x.sheet); }); }
function finish() { out.pageWidth = document.documentElement.scrollWidth; parent.postMessage(JSON.stringify(out), '*'); }
function bar(el) {
  if (!el) { return null; }
  var cs = getComputedStyle(el), icon = el.querySelector('.uc-ann-icon'), title = el.querySelector('.uc-ann-title');
  return { tag: el.tagName.toLowerCase(), seen: seen(el), r: rect(el), bg: cs.backgroundColor, ink: cs.color, contrast: ratio(cs.color, cs.backgroundColor),
    title: title ? title.textContent.trim() : '', titleInk: title ? getComputedStyle(title).color : '',
    icon: icon ? { seen: seen(icon), r: rect(icon) } : null, titleR: rect(title), expanded: el.getAttribute('aria-expanded') };
}
var host = document.querySelector('[data-sfaf-calendar]');
var shadow = window.RL_MODE === 'shadow';
var tries = 0;
(function wait() {
  var scope = shadow ? (host && host.shadowRoot) : document;
  var ready = scope && scope.querySelector('[data-uc-ann-id]') && sheetsIn(shadow ? scope : document);
  if (!ready && tries++ < 200) { setTimeout(wait, 100); return; }
  out.unready = !ready;
  if (!ready) { return finish(); }
  out.shadow = !!(host && host.shadowRoot && host.shadowRoot === scope);
  setTimeout(function () { run(scope); }, 400);
})();

function cardEl() {
  var pr = document.querySelector('[data-sfaf-calendar-preview]');
  return (pr && pr.shadowRoot && pr.shadowRoot.querySelector('.uc-ann-card')) || document.querySelector('.uc-ann-card');
}
function cardState() {
  var c = cardEl();
  if (!c) { return { exists: false }; }
  var img = c.querySelector('.uc-ann-card-media img'), link = c.querySelector('.uc-ann-card-link'), desc = c.querySelector('.uc-ann-card-desc');
  var title = c.querySelector('.uc-ann-card-title');
  return { exists: true, open: seen(c), r: rect(c), vw: window.innerWidth, vh: window.innerHeight,
    img: seen(img) ? { w: img.naturalWidth, r: rect(img) } : null,
    desc: seen(desc) ? desc.textContent.trim() : null, title: seen(title) ? title.textContent.trim() : null,
    link: seen(link) ? { href: link.getAttribute('href'), target: link.getAttribute('target'), face: getComputedStyle(link).backgroundColor, ink: getComputedStyle(link).color } : null };
}
function active() {
  var a = document.activeElement;
  while (a && a.shadowRoot && a.shadowRoot.activeElement) { a = a.shadowRoot.activeElement; }
  return a;
}
function click(el, detail) { el.dispatchEvent(new MouseEvent('click', { bubbles: true, composed: true, cancelable: true, detail: detail })); }
function key(el, k) { el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, composed: true, cancelable: true })); }

function run(scope) {
  var v = window.RL_VIEW;
  var phone = window.innerWidth < 600;
  var todayCell = scope.querySelector('td.uc-day[data-day="' + window.RL_TODAY + '"]');
  var target = null;
  if (v === 'month') {
    out.cellClass = todayCell ? todayCell.className : '';
    var inCell = todayCell && todayCell.querySelector('.uc-ann-bars [data-uc-ann-id="' + window.RL_PRIDE + '"]');
    out.cellBar = bar(inCell);
    out.cellR = rect(todayCell);
    var num = todayCell && todayCell.querySelector('.uc-day-num');
    out.numR = rect(num);
    var strip = todayCell && todayCell.querySelector('.uc-ann-strip');
    out.strip = strip ? { seen: seen(strip), bg: getComputedStyle(strip).backgroundColor, r: rect(strip) } : null;
    var plain = scope.querySelector('td.uc-day [data-uc-ann-id="' + window.RL_MOVE + '"]');
    out.plain = bar(plain);
    if (phone && todayCell) { click(todayCell, 1); }
    target = phone ? scope.querySelector('.uc-month-day-panel [data-uc-ann-id="' + window.RL_PRIDE + '"]') : inCell;
    out.panelBar = phone ? bar(target) : null;
    var pt = scope.querySelector('.uc-month-day-panel .uc-day-panel-title');
    out.panelTitle = pt ? pt.textContent.trim() : '';
    if (!phone && plain && seen(plain)) { click(plain, 1); out.plainOpens = cardState().open || false; }
  } else if (v === 'list') {
    var banner = scope.querySelector('.uc-ann-banner[data-uc-ann-id="' + window.RL_PRIDE + '"]');
    out.banner = bar(banner);
    var firstCard = scope.querySelector('.uc-event-card');
    out.firstCardR = rect(firstCard);
    out.when = banner && banner.querySelector('.uc-ann-when') ? banner.querySelector('.uc-ann-when').textContent.trim() : '';
    var plainB = scope.querySelector('.uc-ann-banner[data-uc-ann-id="' + window.RL_MOVE + '"]');
    out.plain = bar(plainB);
    target = banner;
  } else {
    var sb = scope.querySelector('.uc-ann-side [data-uc-ann-id="' + window.RL_PRIDE + '"]');
    out.side = bar(sb);
    out.firstRowR = rect(scope.querySelector('.uc-sidebar-row'));
    out.sideMove = !!scope.querySelector('.uc-ann-side [data-uc-ann-id="' + window.RL_MOVE + '"]');
    target = sb;
  }
  out.target = !!target;
  if (!target) { return finish(); }
  setTimeout(function () {
    click(target, 1);
    setTimeout(function () {
      out.clicked = cardState();
      out.clickedExpanded = target.getAttribute('aria-expanded');
      out.targetR = rect(target);
      click(target, 1);
      setTimeout(function () {
        out.secondClick = cardState().open;
        click(target, 1);
        setTimeout(function () {
          click(document.body, 1);
          setTimeout(function () {
            out.elsewhere = cardState().open;
            /* From the keyboard: a click with detail 0. */
            target.focus();
            click(target, 0);
            setTimeout(function () {
              var a = active();
              out.keyFocus = a ? (a.className || a.tagName) : '';
              key(a || target, 'Escape');
              setTimeout(function () {
                out.afterEscape = cardState().open;
                var b = active();
                out.focusBack = b === target;
                out.escExpanded = target.getAttribute('aria-expanded');
                out.canHover = !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
                if (!out.canHover || phone) { return finish(); }
                target.dispatchEvent(new MouseEvent('mouseover', { bubbles: true, composed: true }));
                setTimeout(function () {
                  out.hovered = cardState().open;
                  target.dispatchEvent(new MouseEvent('mouseout', { bubbles: true, composed: true }));
                  setTimeout(function () { out.unhovered = cardState().open; finish(); }, 500);
                }, 500);
              }, 200);
            }, 200);
          }, 200);
        }, 200);
      }, 200);
    }, 300);
  }, 200);
}
})();
JS;

$root = dirname( __DIR__ );
function rl_fake( $markup ) {
    return 'window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});'
        . '(function(){var P=' . json_encode( array( 'html' => $markup, 'css_url' => '', 'js_version' => '', 'total' => 0, 'max_pages' => 1, 'has_more' => false, 'card_style' => '' ) ) . ';'
        . 'function F(){this.readyState=0;this.status=0;this.responseText="";}F.prototype.open=function(m,u){this._u=u;};F.prototype.setRequestHeader=function(){};'
        . 'F.prototype.send=function(){var s=this;setTimeout(function(){s.readyState=4;s.status=200;s.responseText=JSON.stringify(P);if(s.onreadystatechange){s.onreadystatechange();}if(s.onload){s.onload();}},0);};F.prototype.abort=function(){};window.XMLHttpRequest=F;})();';
}
function rl_page( $mode, $view, $markup, $probe ) {
    $vars = 'window.RL_MODE=' . json_encode( $mode ) . ';window.RL_VIEW=' . json_encode( $view ) . ';window.RL_TODAY=' . json_encode( $GLOBALS['today'] )
        . ';window.RL_PRIDE=' . json_encode( $GLOBALS['pride'] ) . ';window.RL_MOVE=' . json_encode( $GLOBALS['move'] ) . ';';
    $data = array( 'month' => 'calendar', 'list' => 'list', 'sidebar' => 'sidebar' );
    $head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . ( 'light' === $mode ? '<link rel="stylesheet" href="../public/css/calendar.css">' : '' )
        . '<style>html,body{margin:0;padding:0;background:#fff}.page{padding:16px}*{transition:none!important;animation:none!important}</style>'
        . '<script>' . $vars . ( 'shadow' === $mode ? rl_fake( $markup ) : 'window.__errs=[];window.addEventListener("error",function(e){window.__errs.push(e.message);});window.ucData={ajaxUrl:"",nonce:"n"};' ) . '</script></head><body><div class="page">';
    if ( 'shadow' === $mode ) {
        $body = '<div class="sfaf-calendar-embed" data-sfaf-calendar data-isolate="yes" data-view="' . $data[ $view ] . '" data-count="5" data-filters="none"></div>'
            . '</div><script src="../public/js/embed.js"></script>';
    } else {
        $body = $markup . '</div><script src="vendor/jquery-3.7.1.min.js"></script><script src="../public/js/calendar.js"></script>';
    }
    return $head . $body . '<script>' . $probe . '</script></body></html>';
}

$WIDTHS = array( 1280, 430, 390 );
$pages  = array();
foreach ( array( 'light', 'shadow' ) as $mode ) {
    foreach ( $blocks as $view => $markup ) {
        foreach ( $WIDTHS as $w ) {
            $pages[ "$mode-$view-$w" ] = array( 'w' => $w, 'mode' => $mode, 'view' => $view, 'html' => rl_page( $mode, $view, $markup, $probe ) );
        }
    }
}
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31420-live-' . $name . '.html';
    file_put_contents( $inner, $p['html'] );
    $files[ $name ] = __DIR__ . '/release-31420-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:1200px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args = array_slice( $argv, 1 );
if ( ! in_array( '--run', $args, true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$DARK = 'rgb(55, 52, 51)'; $YELLOW = 'rgb(255, 217, 0)';
$no_fonts = ' --host-resolver-rules="MAP fonts.googleapis.com ~NOTFOUND"';
foreach ( $files as $name => $file ) {
    $p   = $pages[ $name ];
    $w   = $p['w'];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files' . $no_fonts . ' --window-size=' . max( 600, $w + 20 ) . ',1300 --virtual-time-budget=30000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    rl( empty( $r['errors'] ), "$name threw: " . implode( '; ', (array) $r['errors'] ) );
    if ( ! empty( $r['unready'] ) ) { rl( false, "$name: the block or its stylesheet never loaded, so nothing was measured" ); continue; }
    rl( (int) $r['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $r['pageWidth'] . "px in $w" );
    if ( 'shadow' === $p['mode'] ) { rl( ! empty( $r['shadow'] ), "$name: the embed did not draw into its shadow root" ); }
    $phone = $w < 600;
    $look = function ( $b, $what ) use ( $name, $DARK, $YELLOW ) {
        rl( is_array( $b ) && $b['seen'], "PLANT B: $name: $what is not on screen" );
        if ( ! is_array( $b ) || ! $b['seen'] ) { return; }
        rl( $DARK === $b['bg'] && $YELLOW === $b['ink'] && $YELLOW === $b['titleInk'], "PLANT B: $name: $what is " . $b['bg'] . ' with ' . $b['ink'] . " ink, not #373433 with #FFD900" );
        rl( $b['contrast'] >= 4.5, "$name: $what measures " . $b['contrast'] . ':1' );
        rl( $b['icon'] && $b['icon']['seen'] && $b['icon']['r']['right'] <= $b['titleR']['left'] && $b['icon']['r']['w'] >= 12, "$name: $what does not draw its icon left of the title: " . json_encode( $b['icon'] ) );
        rl( 'Pride week' === $b['title'], "$name: $what reads " . json_encode( $b['title'] ) );
    };
    if ( 'month' === $p['view'] ) {
        rl( false === strpos( (string) $r['cellClass'], 'uc-day-closed' ) && false !== strpos( (string) $r['cellClass'], 'uc-day-ann' ), "$name: today's cell is " . $r['cellClass'] );
        if ( $phone ) {
            rl( ! $r['cellBar']['seen'], "$name: the cell still draws the bar at $w" );
            rl( $r['strip'] && $r['strip']['seen'] && $DARK === $r['strip']['bg'] && $r['strip']['r']['h'] >= 3, "PLANT B: $name: the cell draws no dark strip at $w: " . json_encode( $r['strip'] ) );
            $look( $r['panelBar'], 'the bar in the day panel' );
            rl( (bool) preg_match( '/20[0-9][0-9]$/', (string) $r['panelTitle'] ), "$name: the day panel's heading is not the date: " . json_encode( $r['panelTitle'] ) );
            rl( $r['panelBar'] && $r['panelBar']['r']['h'] >= 28, "$name: the day panel's bar is " . ( $r['panelBar'] ? $r['panelBar']['r']['h'] : 0 ) . 'px tall, under a tap target' );
        } else {
            $look( $r['cellBar'], 'the bar in today\'s cell' );
            $cb = $r['cellBar']; $cr = $r['cellR'];
            rl( $cb && $cb['r']['left'] >= $cr['left'] && $cb['r']['right'] <= $cr['right'] + 1, "$name: the bar spills out of its cell: " . json_encode( array( $cb ? $cb['r'] : null, $cr ) ) );
            rl( $cb && $cb['r']['top'] >= $r['numR']['bottom'] - 1 && $cb['r']['top'] - $cr['top'] <= 40, "$name: the bar is not at the top of the day, under its number: " . json_encode( array( $cb ? $cb['r'] : null, $r['numR'], $cr ) ) );
            rl( $cb && $cb['r']['h'] <= 30, "$name: the bar is " . ( $cb ? $cb['r']['h'] : 0 ) . 'px tall, not slim' );
            rl( $r['strip'] && ! $r['strip']['seen'], "$name: the strip shows at $w" );
            rl( $r['plain'] && 'span' === $r['plain']['tag'] && $r['plain']['seen'] && empty( $r['plainOpens'] ), "$name: the title-only bar is not a plain line that opens nothing: " . json_encode( $r['plain'] ) );
        }
    } elseif ( 'list' === $p['view'] ) {
        $look( $r['banner'], 'the list banner' );
        $bn = $r['banner']; $fc = $r['firstCardR'];
        rl( $bn && $fc && $bn['r']['bottom'] <= $fc['top'] && abs( $bn['r']['w'] - $fc['w'] ) <= 2, "$name: the banner is not above the first event at the list's width: " . json_encode( array( $bn ? $bn['r'] : null, $fc ) ) );
        rl( $bn && $bn['r']['h'] >= 28 && $bn['r']['h'] <= 60, "$name: the banner is " . ( $bn ? $bn['r']['h'] : 0 ) . 'px tall' );
        rl( '' !== (string) $r['when'], "$name: the banner does not say its dates" );
        rl( $r['plain'] && 'span' === $r['plain']['tag'], "$name: the title-only banner is a button" );
    } else {
        $look( $r['side'], 'the sidebar bar' );
        rl( $r['side'] && $r['firstRowR'] && $r['side']['r']['bottom'] <= $r['firstRowR']['top'], "$name: today's bar is not above the first row" );
        rl( empty( $r['sideMove'] ), "$name: the sidebar shows an announcement that is not on today" );
    }
    /* The card. */
    $c = isset( $r['clicked'] ) ? $r['clicked'] : array();
    rl( ! empty( $c['open'] ), "PLANT C: $name: a click on the bar does not open the card: " . json_encode( $c ) );
    if ( ! empty( $c['open'] ) ) {
        rl( $c['img'] && 1200 === (int) $c['img']['w'] && $c['img']['r']['w'] > 100, "$name: the card does not show the picture: " . json_encode( $c['img'] ) );
        rl( 'Come by the SFAF booth on Market Street.' === $c['desc'], "$name: the card's description reads " . json_encode( $c['desc'] ) );
        rl( $c['link'] && 'https://sfaf.org/pride' === $c['link']['href'] && '_blank' === $c['link']['target'], "$name: the card's link is " . json_encode( $c['link'] ) );
        rl( $c['r']['left'] >= 0 && $c['r']['right'] <= $c['vw'] && $c['r']['top'] >= 0 && $c['r']['bottom'] <= $c['vh'], "$name: the card is outside the viewport: " . json_encode( $c['r'] ) . ' in ' . $c['vw'] . 'x' . $c['vh'] );
        rl( 'true' === $r['clickedExpanded'], "$name: the open bar does not say aria-expanded=true" );
        $tr = $r['targetR'];
        rl( $tr && ( ( $c['r']['top'] >= $tr['bottom'] && $c['r']['top'] - $tr['bottom'] <= 12 ) || ( $c['r']['bottom'] <= $tr['top'] && $tr['top'] - $c['r']['bottom'] <= 12 ) ) && abs( $c['r']['left'] - max( 8, min( $tr['left'], $c['vw'] - $c['r']['w'] - 8 ) ) ) <= 2, "PLANT P: $name: the card is not beside its bar: " . json_encode( array( 'card' => $c['r'], 'bar' => $tr ) ) );
    }
    rl( false === $r['secondClick'], "$name: a second click does not close the card" );
    rl( false === $r['elsewhere'], "$name: a click elsewhere does not close the card" );
    rl( 'uc-ann-card-link' === $r['keyFocus'], "$name: from the keyboard the card's link does not take focus: " . json_encode( $r['keyFocus'] ) );
    rl( false === $r['afterEscape'] && true === $r['focusBack'] && 'false' === $r['escExpanded'], "$name: Escape does not close the card and give focus back to the bar: " . json_encode( array( $r['afterEscape'], $r['focusBack'], $r['escExpanded'] ) ) );
    if ( ! $phone ) {
        rl( ! empty( $r['canHover'] ), "$name: Chrome reports no hover at $w, so hovering was not checked" );
        rl( true === $r['hovered'] && false === $r['unhovered'], "PLANT H: $name: hovering does not open the card, or leaving does not close it: " . json_encode( array( $r['hovered'], $r['unhovered'] ) ) );
    }
}

echo "\n3.142.0: the bar, the card and the banner, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) {
    echo count( $fails ) . " FAILURE(S):\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo count( $files ) . " pages: calendar.js and embed.js, month, list and sidebar, at 1280, 430 and 390px.\n";
exit( 0 );
