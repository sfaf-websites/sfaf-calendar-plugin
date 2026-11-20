<?php
/**
 * 3.143.1 IN A REAL BROWSER: THE SERIES FILTER, THE BAR, THE BANNER, THE
 * STRIP AND A LONG SPANISH TITLE.
 *
 *     php .claude/release-31431-live.php            writes the pages
 *     php .claude/release-31431-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31431-live.php --run --verbose
 *
 * REAL: the blocks from render_calendar_block() through wp-kit.php, the
 * queries answered by series-filter-kit.php, calendar.css, and both scripts:
 * calendar.js with the blocks in the page, and embed.js loading them into
 * shadow roots (data-isolate="yes") from a stand-in endpoint. Each page is an
 * iframe of the width named. Chrome runs with a profile holding this
 * machine's hyphenation dictionaries (Chrome's own hyphen-data), copied from
 * its user profile: without them Chrome hyphenates nothing, and this says so.
 *
 *   F  (1280, 430, 390) World AIDS Day chosen: the month grid (the day panel
 *      at 430 and 390) and the list show the vigil and the Cycle to Zero ride
 *      added through "Also show", and none of Cycle to Zero's private, draft,
 *      hidden cancelled or other events; the list not the past one; World
 *      AIDS Day ticked
 *   A  (1280, 700, 430, 390) the bar in today's cell: fill #FFFAE0, a 1px
 *      #705F00 edge on every side, Dark Gray words, measured over 4.5:1; from
 *      the cell's left edge to its right; its title on one line wherever the
 *      title's own width fits the bar. At 430 and 390 the strip instead, the
 *      same fill and edge, and the day panel's bar the same colors. The list
 *      banner the same colors.
 *   H  (1280, 700, 430, 390) "Transformaciones", a Spanish event: lang="es"
 *      and hyphens: auto; any break inside the word is at a syllable
 *      (Trans-for-ma-cio-nes) and draws a hyphen. At 700, where the grid's
 *      cell is narrower than the word, it breaks, so a break is required.
 *
 * Every page: no script error and no sideways scroll. PLANTs in plant-31431.php.
 */

require __DIR__ . '/wp-kit.php';
require __DIR__ . '/series-query-kit.php';
require __DIR__ . '/series-filter-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$e     = sf_world();
$today = current_time( 'Y-m-d' );
$SVG   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="#373433"/></svg>';
$pride = SFAF_Announcements::save( '', array( 'title' => 'Pride week', 'start' => $today, 'desc' => 'Come by the SFAF booth on Market Street.', 'link' => 'https://sfaf.org/pride', 'icon_svg' => $SVG ) );
rl( is_string( $pride ), 'the announcement could not be saved' );

$sc = new SFAF_Shortcodes();
$render = function ( $atts ) use ( $sc ) { $b = $sc->render_calendar_block( $atts ); return is_array( $b ) ? (string) $b['html'] : (string) $b; };
$blocks = array(
    'filter' => array(
        'calendar' => $render( array( 'toggle' => 'no', 'view' => 'calendar', 'filters' => 'series', 'active_groups' => 'world-aids-day' ) ),
        'list'     => $render( array( 'toggle' => 'no', 'view' => 'list', 'filters' => 'series', 'active_groups' => 'world-aids-day', 'count' => 20 ) ),
    ),
    'ann' => array(
        'calendar' => $render( array( 'toggle' => 'no', 'view' => 'calendar', 'show_filters' => 'no' ) ),
        'list'     => $render( array( 'toggle' => 'no', 'view' => 'list', 'show_filters' => 'no', 'count' => 20 ) ),
    ),
);
foreach ( $blocks as $topic => $views ) { foreach ( $views as $v => $html ) { rl( '' !== $html, "the $topic $v block rendered nothing" ); } }
rl( false !== strpos( $blocks['filter']['list'], 'Cycle to Zero ride' ), 'the filtered list block does not hold the ride before the browser sees it' );

$probe = <<<'JS'
(function () {
var out = { errors: window.__errs || [], topic: window.RL_TOPIC, width: window.innerWidth };
function rect(el) { if (!el) { return null; } var b = el.getBoundingClientRect(); return { top: b.top, bottom: b.bottom, left: b.left, right: b.right, h: b.height, w: b.width }; }
function seen(el) { if (!el || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden' && el.getBoundingClientRect().width > 0; }
function rgb(s) { var m = String(s).match(/\d+(\.\d+)?/g); return m ? m.slice(0, 3).map(Number) : null; }
function lum(c) { return [0, 1, 2].map(function (i) { var v = c[i] / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function ratio(a, b) { var x = lum(rgb(a)), y = lum(rgb(b)); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; }
function applied(sheet) { var r; try { r = sheet.cssRules; } catch (e) { return true; } for (var i = 0; i < r.length; i++) { if (r[i].type !== 3) { break; } if (!r[i].styleSheet || !applied(r[i].styleSheet)) { return false; } } return true; }
function sheetsIn(root) { var l = root.querySelectorAll('link[rel="stylesheet"]'); return l.length > 0 && Array.prototype.every.call(l, function (x) { return !!x.sheet && applied(x.sheet); }); }
function finish() { out.pageWidth = document.documentElement.scrollWidth; parent.postMessage(JSON.stringify(out), '*'); }
function click(el) { el.dispatchEvent(new MouseEvent('click', { bubbles: true, composed: true, cancelable: true, detail: 1 })); }
function texts(root, sel) { return Array.prototype.filter.call(root.querySelectorAll(sel), seen).map(function (x) { var c = x.cloneNode(true); Array.prototype.forEach.call(c.querySelectorAll('.uc-visually-hidden, .uc-new-tab-note, .screen-reader-text'), function (h) { h.remove(); }); return c.textContent.replace(/\s+/g, ' ').trim().replace(/ \(opens in a new tab\)$/, ''); }); }
function edges(el) {
  var cs = getComputedStyle(el);
  return { bg: cs.backgroundColor, ink: cs.color, bc: [cs.borderTopColor, cs.borderRightColor, cs.borderBottomColor, cs.borderLeftColor], bw: [cs.borderTopWidth, cs.borderRightWidth, cs.borderBottomWidth, cs.borderLeftWidth], bs: [cs.borderTopStyle, cs.borderRightStyle, cs.borderBottomStyle, cs.borderLeftStyle] };
}
function bar(el) {
  if (!el) { return null; }
  var title = el.querySelector('.uc-ann-title'), icon = el.querySelector('.uc-ann-icon'), cs = getComputedStyle(el);
  var o = edges(el);
  o.seen = seen(el); o.r = rect(el); o.contrast = ratio(cs.color, cs.backgroundColor);
  o.titleInk = title ? getComputedStyle(title).color : '';
  if (title) {
    var tr = title.getClientRects(), lh = parseFloat(getComputedStyle(title).lineHeight) || parseFloat(cs.fontSize) * 1.3;
    var probe = title.cloneNode(true); probe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;width:auto'; el.appendChild(probe);
    o.natural = probe.getBoundingClientRect().width; probe.remove();
    o.titleH = title.getBoundingClientRect().height; o.lineH = lh;
    var pl = parseFloat(cs.paddingLeft), pr = parseFloat(cs.paddingRight), gap = parseFloat(cs.columnGap) || 0;
    o.room = el.getBoundingClientRect().width - pl - pr - parseFloat(cs.borderLeftWidth) - parseFloat(cs.borderRightWidth) - (icon ? icon.getBoundingClientRect().width + gap : 0);
  }
  return o;
}
function caret(root, x, y) {
  var p = document.caretPositionFromPoint ? document.caretPositionFromPoint(x, y, root !== document ? { shadowRoots: [root] } : undefined) : null;
  return p ? { node: p.offsetNode, offset: p.offset } : null;
}
/* Where a title breaks, and whether each break draws a hyphen: a line with two
   rects is its text and the hyphen Chrome draws after it. */
function breaks(root, el) {
  if (!el) { return null; }
  var t = el.firstChild, r = document.createRange(); r.selectNodeContents(el);
  var rects = Array.prototype.filter.call(r.getClientRects(), function (x) { return x.width > 0; });
  var lines = [];
  rects.forEach(function (x) { var l = lines[lines.length - 1]; if (l && Math.abs(l.top - x.top) < 3) { l.parts.push(x); } else { lines.push({ top: x.top, h: x.height, parts: [x] }); } });
  var out = { lang: el.getAttribute('lang'), hyphens: getComputedStyle(el).hyphens, text: t ? t.data : '', lines: lines.length, breaks: [], seen: seen(el), w: el.getBoundingClientRect().width };
  for (var i = 1; i < lines.length; i++) {
    var first = lines[i].parts[0], c = caret(root, first.left + 0.5, first.top + first.height / 2);
    var prev = lines[i - 1].parts, last = prev[prev.length - 1];
    out.breaks.push({ at: c && c.node === t ? c.offset : -1, hyphen: prev.length > 1 && last.width <= 8 });
  }
  return out;
}
var hosts = Array.prototype.slice.call(document.querySelectorAll('[data-sfaf-calendar]'));
var shadow = window.RL_MODE === 'shadow';
var tries = 0;
function scopes() {
  if (!shadow) { var bl = Array.prototype.slice.call(document.querySelectorAll('.uc-calendar')); return bl.length >= 2 ? bl.map(function (b) { return { root: document, el: b }; }) : null; }
  var s = hosts.map(function (h) { return h.shadowRoot && h.shadowRoot.querySelector('.uc-calendar') ? { root: h.shadowRoot, el: h.shadowRoot.querySelector('.uc-calendar'), sheet: sheetsIn(h.shadowRoot) } : null; });
  return s.every(function (x) { return x && x.sheet; }) && s.length >= 2 ? s : null;
}
(function wait() {
  var s = scopes();
  var ready = s && (shadow || sheetsIn(document));
  if (!ready && tries++ < 200) { setTimeout(wait, 100); return; }
  out.unready = !ready;
  if (!ready) { return finish(); }
  setTimeout(function () { run(s); }, 400);
})();

function run(s) {
  var phone = window.innerWidth < 600;
  var month = s.filter(function (x) { return !!x.el.querySelector('.uc-month-grid'); })[0];
  var list = s.filter(function (x) { return !x.el.querySelector('.uc-month-grid'); })[0];
  out.found = { month: !!month, list: !!list };
  if (!month || !list) { return finish(); }
  var cell = month.el.querySelector('td.uc-day[data-day="' + window.RL_TODAY + '"]');
  out.cellR = rect(cell);
  if (window.RL_TOPIC === 'filter') {
    out.gridTitles = phone ? [] : texts(month.el, '.uc-month-grid .uc-day-event-title');
    out.listTitles = texts(list.el, '.uc-card-title');
    var box = list.el.querySelector('input[data-uc-who-group][value="world-aids-day"]');
    out.ticked = box ? box.checked : null;
    if (phone && cell) { click(cell); }
    setTimeout(function () {
      out.panelTitles = phone ? texts(month.el, '.uc-month-day-panel .uc-day-event-title') : [];
      finish();
    }, 400);
    return;
  }
  /* The announcements and the Spanish title. */
  var inCell = cell && cell.querySelector('.uc-ann-bars [data-uc-ann-id="' + window.RL_PRIDE + '"]');
  out.cellBar = bar(inCell);
  var cs = cell ? getComputedStyle(cell) : null;
  out.cellInner = cell ? { left: cell.getBoundingClientRect().left + parseFloat(cs.borderLeftWidth) / (cs.borderCollapse === 'collapse' ? 2 : 1), right: cell.getBoundingClientRect().right - parseFloat(cs.borderRightWidth) / (cs.borderCollapse === 'collapse' ? 2 : 1) } : null;
  var strip = cell && cell.querySelector('.uc-ann-strip');
  out.strip = strip ? (function () { var o = edges(strip); o.seen = seen(strip); o.r = rect(strip); return o; })() : null;
  out.banner = bar(list.el.querySelector('.uc-ann-banner[data-uc-ann-id="' + window.RL_PRIDE + '"]'));
  out.listR = rect(list.el.querySelector('.uc-event-card'));
  var es = month.el.querySelector('.uc-month-grid .uc-day-event-title[lang="es"]');
  out.esGrid = phone ? null : breaks(month.root, es);
  out.esAny = !!es;
  out.esGridLang = es ? es.getAttribute('lang') : '';
  if (phone && cell) { click(cell); }
  setTimeout(function () {
    if (phone) {
      out.panelBar = bar(month.el.querySelector('.uc-month-day-panel [data-uc-ann-id="' + window.RL_PRIDE + '"]'));
      out.esPanel = breaks(month.root, month.el.querySelector('.uc-month-day-panel .uc-day-event-title[lang="es"]'));
    }
    finish();
  }, 400);
}
})();
JS;

function rl_fake( $payloads ) {
    return 'window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});'
        . '(function(){var P=' . json_encode( $payloads ) . ';'
        . 'function F(){this.readyState=0;this.status=0;this.responseText="";}F.prototype.open=function(m,u){this._u=u;};F.prototype.setRequestHeader=function(){};'
        . 'F.prototype.send=function(){var s=this,k=/[?&]view=list/.test(s._u)?"list":"calendar";setTimeout(function(){s.readyState=4;s.status=200;s.responseText=JSON.stringify(P[k]);if(s.onreadystatechange){s.onreadystatechange();}if(s.onload){s.onload();}},0);};F.prototype.abort=function(){};window.XMLHttpRequest=F;})();';
}
function rl_page( $topic, $mode, $views, $probe ) {
    $vars = 'window.RL_MODE=' . json_encode( $mode ) . ';window.RL_TOPIC=' . json_encode( $topic ) . ';window.RL_TODAY=' . json_encode( $GLOBALS['today'] )
        . ';window.RL_PRIDE=' . json_encode( $GLOBALS['pride'] ) . ';';
    $payloads = array();
    foreach ( $views as $v => $html ) {
        $payloads[ $v ] = array( 'html' => $html, 'css_url' => '', 'js_version' => '', 'total' => 0, 'max_pages' => 1, 'has_more' => false, 'card_style' => '' );
    }
    $head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . ( 'light' === $mode ? '<link rel="stylesheet" href="../public/css/calendar.css">' : '' )
        . '<style>html,body{margin:0;padding:0;background:#fff}.page{padding:16px}.page>*+*{margin-top:24px}*{transition:none!important;animation:none!important}</style>'
        . '<script>' . $vars . ( 'shadow' === $mode ? rl_fake( $payloads ) : 'window.__errs=[];window.addEventListener("error",function(e){window.__errs.push(e.message);});window.ucData={ajaxUrl:"",nonce:"n"};' ) . '</script></head><body><div class="page">';
    if ( 'shadow' === $mode ) {
        $f    = 'filter' === $topic ? ' data-filters="series" data-active-groups="world-aids-day"' : ' data-filters="none"';
        $body = '<div class="sfaf-calendar-embed" data-sfaf-calendar data-isolate="yes" data-view="calendar"' . $f . '></div>'
            . '<div class="sfaf-calendar-embed" data-sfaf-calendar data-isolate="yes" data-view="list" data-count="20"' . $f . '></div>'
            . '</div><script src="../public/js/embed.js"></script>';
    } else {
        $body = $views['calendar'] . $views['list'] . '</div><script src="vendor/jquery-3.7.1.min.js"></script><script src="../public/js/calendar.js"></script>';
    }
    return $head . $body . '<script>' . $probe . '</script></body></html>';
}

$WIDTHS = array( 'filter' => array( 1280, 430, 390 ), 'ann' => array( 1280, 700, 430, 390 ) );
$pages  = array();
foreach ( array( 'light', 'shadow' ) as $mode ) {
    foreach ( $blocks as $topic => $views ) {
        foreach ( $WIDTHS[ $topic ] as $w ) {
            $pages[ "$topic-$mode-$w" ] = array( 'w' => $w, 'mode' => $mode, 'topic' => $topic, 'html' => rl_page( $topic, $mode, $views, $probe ) );
        }
    }
}
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31431-live-' . $name . '.html';
    file_put_contents( $inner, $p['html'] );
    $files[ $name ] = __DIR__ . '/release-31431-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:2400px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args = array_slice( $argv, 1 );
if ( ! in_array( '--run', $args, true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

/* The hyphenation dictionaries: Chrome's own, from this machine's profile. */
$dicts = str_replace( '\\', '/', (string) getenv( 'LOCALAPPDATA' ) ) . '/Google/Chrome/User Data/hyphen-data';
$prof  = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sfaf-31431-profile-' . getmypid();
$copy  = function ( $from, $to ) use ( &$copy ) { @mkdir( $to, 0777, true ); foreach ( (array) @scandir( $from ) as $f ) { if ( '.' === $f || '..' === $f ) { continue; } is_dir( "$from/$f" ) ? $copy( "$from/$f", "$to/$f" ) : copy( "$from/$f", "$to/$f" ); } };
$has_dicts = is_dir( $dicts ) && (bool) glob( $dicts . '/*/hyph-es.hyb' );
rl( $has_dicts, "Chrome's hyphenation dictionaries are not at $dicts, so hyphenation could not be checked: open Chrome on this machine once and let it fetch them" );
if ( $has_dicts ) { $copy( $dicts, $prof . '/hyphen-data' ); }

$INK = 'rgb(55, 52, 51)'; $FILL = 'rgb(255, 250, 224)'; $EDGE = 'rgb(112, 95, 0)';
$SYLLABLES = array( 5, 8, 10, 13 ); // Trans-for-ma-cio-nes
$no_fonts = ' --host-resolver-rules="MAP fonts.googleapis.com ~NOTFOUND"';
foreach ( $files as $name => $file ) {
    $p   = $pages[ $name ];
    $w   = $p['w'];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files' . $no_fonts . ' --user-data-dir="' . $prof . '" --window-size=' . max( 600, $w + 20 ) . ',2500 --virtual-time-budget=30000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    rl( empty( $r['errors'] ), "$name threw: " . implode( '; ', (array) $r['errors'] ) );
    if ( ! empty( $r['unready'] ) ) { rl( false, "$name: the blocks or their stylesheet never loaded, so nothing was measured" ); continue; }
    rl( (int) $r['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $r['pageWidth'] . "px in $w" );
    rl( ! empty( $r['found']['month'] ) && ! empty( $r['found']['list'] ), "$name: the month and list blocks were not both found" );
    $phone = $w < 600;

    if ( 'filter' === $p['topic'] ) {
        $want = array( 'World AIDS Day vigil', 'Cycle to Zero ride' );
        $never = array( 'Cycle to Zero private', 'Cycle to Zero draft', 'Cycle to Zero hidden', 'Cycle to Zero social', 'Alpha meetup', 'Transformaciones' );
        $grid = $phone ? (array) $r['panelTitles'] : (array) $r['gridTitles'];
        $where = $phone ? "the day panel" : 'the month grid';
        foreach ( $want as $t ) { rl( in_array( $t, $grid, true ), "PLANT F: $name: $where does not show \"$t\": " . json_encode( $grid ) ); rl( in_array( $t, (array) $r['listTitles'], true ), "PLANT F: $name: the list does not show \"$t\": " . json_encode( $r['listTitles'] ) ); }
        foreach ( $never as $t ) { rl( ! in_array( $t, $grid, true ) && ! in_array( $t, (array) $r['listTitles'], true ), "$name: \"$t\" is shown with World AIDS Day chosen" ); }
        rl( ! in_array( 'Cycle to Zero past', (array) $r['listTitles'], true ), "$name: the list shows the past event" );
        if ( ! $phone ) { rl( in_array( 'Cycle to Zero past', $grid, true ) === in_array( 'World AIDS Day past', $grid, true ), "$name: the grid shows the past event shown in World AIDS Day unlike its own past one: " . json_encode( $grid ) ); }
        rl( true === $r['ticked'], "$name: World AIDS Day is not ticked: " . json_encode( $r['ticked'] ) );
        continue;
    }

    /* A: the colors. */
    $look = function ( $b, $what, $sides = 4 ) use ( $name, $INK, $FILL, $EDGE ) {
        rl( is_array( $b ) && $b['seen'], "PLANT A: $name: $what is not on screen" );
        if ( ! is_array( $b ) || ! $b['seen'] ) { return; }
        rl( $FILL === $b['bg'] && ( 'the strip' === $what || ( $INK === $b['ink'] && $INK === $b['titleInk'] ) ), "PLANT A: $name: $what is " . $b['bg'] . ' with ' . $b['ink'] . ' words, not #FFFAE0 with #373433' );
        $ok = true;
        for ( $i = 0; $i < 4; $i++ ) { if ( $EDGE !== $b['bc'][ $i ] || '1px' !== $b['bw'][ $i ] || 'solid' !== $b['bs'][ $i ] ) { $ok = false; } }
        rl( $ok, "PLANT A: $name: $what's edge is " . json_encode( array( $b['bc'], $b['bw'], $b['bs'] ) ) . ', not 1px solid #705F00 all round' );
        if ( isset( $b['contrast'] ) ) { rl( $b['contrast'] >= 4.5, "$name: $what's words measure " . $b['contrast'] . ':1' ); }
    };
    if ( $phone ) {
        rl( ! $r['cellBar']['seen'], "$name: the cell draws the bar at $w" );
        $look( $r['strip'], 'the strip' );
        rl( $r['strip'] && $r['strip']['seen'] && $r['strip']['r']['h'] >= 5, "PLANT A: $name: the strip is " . json_encode( $r['strip'] ) );
        $look( $r['panelBar'], 'the day panel\'s bar' );
    } else {
        $look( $r['cellBar'], 'the bar in today\'s cell' );
        $cb = $r['cellBar']; $ci = $r['cellInner'];
        rl( $cb && $ci && abs( $cb['r']['left'] - $ci['left'] ) <= 1 && abs( $ci['right'] - $cb['r']['right'] ) <= 1,
            "PLANT W: $name: the bar does not run from the cell's left edge to its right: bar " . ( $cb ? round( $cb['r']['left'], 1 ) . '-' . round( $cb['r']['right'], 1 ) : '?' ) . ', cell ' . ( $ci ? round( $ci['left'], 1 ) . '-' . round( $ci['right'], 1 ) : '?' ) );
        if ( $cb && isset( $cb['natural'] ) ) {
            $fits = $cb['natural'] <= $cb['room'] + 0.5;
            rl( ! $fits || $cb['titleH'] < $cb['lineH'] * 1.5, "PLANT W: $name: \"Pride week\" is " . round( $cb['natural'], 1 ) . 'px and fits the bar\'s ' . round( $cb['room'], 1 ) . 'px, yet takes ' . round( $cb['titleH'] ) . 'px' );
        }
        rl( $r['strip'] && ! $r['strip']['seen'], "$name: the strip shows at $w" );
    }
    $look( $r['banner'], 'the list banner' );
    rl( $r['banner'] && $r['listR'] && abs( $r['banner']['r']['w'] - $r['listR']['w'] ) <= 2, "$name: the banner is not the list's width" );

    /* H: the Spanish title. */
    rl( $r['esAny'] && 'es' === $r['esGridLang'], "PLANT H: $name: no grid title is lang=\"es\"" );
    $es = $phone ? $r['esPanel'] : $r['esGrid'];
    rl( is_array( $es ) && $es['seen'] && 'Transformaciones' === $es['text'], "$name: the Spanish title is not on screen: " . json_encode( $es ) );
    if ( is_array( $es ) && $es['seen'] ) {
        rl( 'es' === $es['lang'] && 'auto' === $es['hyphens'], "PLANT H: $name: the Spanish title is lang=" . json_encode( $es['lang'] ) . ', hyphens ' . json_encode( $es['hyphens'] ) );
        foreach ( (array) $es['breaks'] as $b ) {
            rl( in_array( (int) $b['at'], $SYLLABLES, true ) && $b['hyphen'], "PLANT H: $name: \"Transformaciones\" breaks after " . json_encode( substr( 'Transformaciones', 0, max( 0, (int) $b['at'] ) ) ) . ( $b['hyphen'] ? '' : ' with no hyphen' ) . ', not at a syllable with one' );
        }
        if ( 700 === $w ) { rl( count( (array) $es['breaks'] ) > 0, "PLANT H: $name: at 700 the Spanish title does not break in a " . round( $es['w'] ) . 'px column, so nothing was proved' ); }
    }
}

$rm = function ( $d ) use ( &$rm ) { foreach ( (array) @scandir( $d ) as $f ) { if ( '.' === $f || '..' === $f ) { continue; } is_dir( "$d/$f" ) ? $rm( "$d/$f" ) : @unlink( "$d/$f" ); } @rmdir( $d ); };
$rm( $prof );

echo "\n3.143.1: the series filter, the bar, the banner, the strip and a Spanish title, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " FAILURE(S):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo count( $files ) . " pages: calendar.js and embed.js; the series filter at 1280, 430 and 390px; the bar, banner, strip and Spanish title at 1280, 700, 430 and 390px.\n";
exit( 0 );
