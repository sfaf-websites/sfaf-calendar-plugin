<?php
/**
 * 3.145.0 IN A REAL BROWSER: A SERIES-SCOPED BLOCK AND A LONG TITLE WITH NO
 * DICTIONARY.
 *
 *     php .claude/release-31450-live.php            writes the pages
 *     php .claude/release-31450-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31450-live.php --run --verbose
 *
 * REAL: the blocks from render_calendar_block() through wp-kit.php, the
 * queries answered by series-filter-kit.php, calendar.css, and both scripts:
 * calendar.js with the blocks in the page, and embed.js loading them into
 * shadow roots (data-isolate="yes") from a stand-in endpoint. Each page is an
 * iframe of the width named. Chrome runs with an EMPTY profile, so it holds
 * no hyphenation dictionary and hyphens: auto alone breaks nothing at a
 * syllable: whatever does is the soft hyphens.
 *
 *   S  (1280, 430, 390) series="14", World AIDS Day: the month grid (the day
 *      panel at 430 and 390) and the list show the vigil and the Cycle to Zero
 *      ride added through "Also show", and none of the private, draft, hidden
 *      cancelled or other events; the list not the past one.
 *   H  (1280, 700, 430, 390) "Transformaciones": lang="es" still, and every
 *      break inside it is at Trans-for-ma-cio-nes and draws a hyphen. At 700,
 *      where the cell is narrower than the word, it must break. The same word
 *      without its soft hyphens, set beside it, does not break at a syllable
 *      with a hyphen: if it does, this Chrome had a dictionary after all and
 *      the check proved nothing.
 *
 * Every page: no script error and no sideways scroll. PLANTs in plant-31450.php.
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
$sc    = new SFAF_Shortcodes();
$render = function ( $atts ) use ( $sc ) { $b = $sc->render_calendar_block( $atts ); return is_array( $b ) ? (string) $b['html'] : (string) $b; };
$blocks = array(
    'scope' => array(
        'calendar' => $render( array( 'toggle' => 'no', 'view' => 'calendar', 'show_filters' => 'no', 'series' => 14 ) ),
        'list'     => $render( array( 'toggle' => 'no', 'view' => 'list', 'show_filters' => 'no', 'series' => 14, 'count' => 20 ) ),
    ),
    'hy' => array(
        'calendar' => $render( array( 'toggle' => 'no', 'view' => 'calendar', 'show_filters' => 'no' ) ),
        'list'     => $render( array( 'toggle' => 'no', 'view' => 'list', 'show_filters' => 'no', 'count' => 20 ) ),
    ),
);
foreach ( $blocks as $topic => $views ) { foreach ( $views as $v => $html ) { rl( '' !== $html, "the $topic $v block rendered nothing" ); } }
rl( false !== strpos( $blocks['scope']['list'], 'Cycle to Zero ride' ), 'PLANT S: the scoped list block does not hold the ride before the browser sees it' );

$probe = <<<'JS'
(function () {
var out = { errors: window.__errs || [], topic: window.RL_TOPIC, width: window.innerWidth };
function seen(el) { if (!el || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden' && el.getBoundingClientRect().width > 0; }
function applied(sheet) { var r; try { r = sheet.cssRules; } catch (e) { return true; } for (var i = 0; i < r.length; i++) { if (r[i].type !== 3) { break; } if (!r[i].styleSheet || !applied(r[i].styleSheet)) { return false; } } return true; }
function sheetsIn(root) { var l = root.querySelectorAll('link[rel="stylesheet"]'); return l.length > 0 && Array.prototype.every.call(l, function (x) { return !!x.sheet && applied(x.sheet); }); }
function finish() { out.pageWidth = document.documentElement.scrollWidth; parent.postMessage(JSON.stringify(out), '*'); }
function click(el) { el.dispatchEvent(new MouseEvent('click', { bubbles: true, composed: true, cancelable: true, detail: 1 })); }
function texts(root, sel) { return Array.prototype.filter.call(root.querySelectorAll(sel), seen).map(function (x) { var c = x.cloneNode(true); Array.prototype.forEach.call(c.querySelectorAll('.uc-visually-hidden, .uc-new-tab-note, .screen-reader-text'), function (h) { h.remove(); }); return c.textContent.replace(/\u00AD/g, '').replace(/\s+/g, ' ').trim().replace(/ \(opens in a new tab\)$/, ''); }); }
function caret(root, x, y) {
  var p = document.caretPositionFromPoint ? document.caretPositionFromPoint(x, y, root !== document ? { shadowRoots: [root] } : undefined) : null;
  return p ? { node: p.offsetNode, offset: p.offset } : null;
}
/* Where a title breaks, in letters of the word without its soft hyphens, and
   whether each break draws a hyphen: a line with two rects is its text and the
   hyphen Chrome draws after it. */
function breaks(root, el) {
  if (!el) { return null; }
  var t = el.firstChild, r = document.createRange(); r.selectNodeContents(el);
  var rects = Array.prototype.filter.call(r.getClientRects(), function (x) { return x.width > 0; });
  var lines = [];
  rects.forEach(function (x) { var l = lines[lines.length - 1]; if (l && Math.abs(l.top - x.top) < 3) { l.parts.push(x); } else { lines.push({ top: x.top, h: x.height, parts: [x] }); } });
  var raw = t ? t.data : '';
  function clean(k) { return k - (raw.slice(0, k).split('\u00AD').length - 1); }
  var o = { lang: el.getAttribute('lang'), hyphens: getComputedStyle(el).hyphens, raw: raw, text: raw.replace(/\u00AD/g, ''), lines: lines.length, breaks: [], seen: seen(el), w: el.getBoundingClientRect().width };
  for (var i = 1; i < lines.length; i++) {
    var first = lines[i].parts[0], c = caret(root, first.left + 0.5, first.top + first.height / 2);
    var prev = lines[i - 1].parts, last = prev[prev.length - 1];
    o.breaks.push({ at: c && c.node === t ? clean(c.offset) : -1, hyphen: prev.length > 1 && last.width <= 8 });
  }
  return o;
}
/* The same word with no soft hyphens, in the same place, the same width. */
function bare(root, el) {
  if (!el) { return null; }
  var c = el.cloneNode(false); c.textContent = el.textContent.replace(/\u00AD/g, ''); c.setAttribute('data-rl-bare', '1');
  el.parentNode.insertBefore(c, el.nextSibling);
  var b = breaks(root, c); c.remove(); return b;
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
  if (window.RL_TOPIC === 'scope') {
    out.gridTitles = phone ? [] : texts(month.el, '.uc-month-grid .uc-day-event-title');
    out.listTitles = texts(list.el, '.uc-card-title');
    if (phone && cell) { click(cell); }
    setTimeout(function () {
      out.panelTitles = phone ? texts(month.el, '.uc-month-day-panel .uc-day-event-title') : [];
      finish();
    }, 400);
    return;
  }
  var es = month.el.querySelector('.uc-month-grid .uc-day-event-title[lang="es"]');
  out.esAny = !!es;
  out.esGrid = phone ? null : breaks(month.root, es);
  out.esBare = phone ? null : bare(month.root, es);
  if (phone && cell) { click(cell); }
  setTimeout(function () {
    if (phone) {
      var p = month.el.querySelector('.uc-month-day-panel .uc-day-event-title[lang="es"]');
      out.esPanel = breaks(month.root, p);
      out.esBare = bare(month.root, p);
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
    $vars = 'window.RL_MODE=' . json_encode( $mode ) . ';window.RL_TOPIC=' . json_encode( $topic ) . ';window.RL_TODAY=' . json_encode( $GLOBALS['today'] ) . ';';
    $payloads = array();
    foreach ( $views as $v => $html ) {
        $payloads[ $v ] = array( 'html' => $html, 'css_url' => '', 'js_version' => '', 'total' => 0, 'max_pages' => 1, 'has_more' => false, 'card_style' => '' );
    }
    $head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . ( 'light' === $mode ? '<link rel="stylesheet" href="../public/css/calendar.css">' : '' )
        . '<style>html,body{margin:0;padding:0;background:#fff}.page{padding:16px}.page>*+*{margin-top:24px}*{transition:none!important;animation:none!important}</style>'
        . '<script>' . $vars . ( 'shadow' === $mode ? rl_fake( $payloads ) : 'window.__errs=[];window.addEventListener("error",function(e){window.__errs.push(e.message);});window.ucData={ajaxUrl:"",nonce:"n"};' ) . '</script></head><body><div class="page">';
    if ( 'shadow' === $mode ) {
        $f    = 'scope' === $topic ? ' data-series="14" data-filters="none"' : ' data-filters="none"';
        $body = '<div class="sfaf-calendar-embed" data-sfaf-calendar data-isolate="yes" data-view="calendar"' . $f . '></div>'
            . '<div class="sfaf-calendar-embed" data-sfaf-calendar data-isolate="yes" data-view="list" data-count="20"' . $f . '></div>'
            . '</div><script src="../public/js/embed.js"></script>';
    } else {
        $body = $views['calendar'] . $views['list'] . '</div><script src="vendor/jquery-3.7.1.min.js"></script><script src="../public/js/calendar.js"></script>';
    }
    return $head . $body . '<script>' . $probe . '</script></body></html>';
}

$WIDTHS = array( 'scope' => array( 1280, 430, 390 ), 'hy' => array( 1280, 700, 430, 390 ) );
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
    $inner = __DIR__ . '/release-31450-live-' . $name . '.html';
    file_put_contents( $inner, $p['html'] );
    $files[ $name ] = __DIR__ . '/release-31450-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:2400px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args = array_slice( $argv, 1 );
if ( ! in_array( '--run', $args, true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

/* An empty profile: no hyphen-data, so no dictionary for any language. */
$prof = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sfaf-31450-profile-' . getmypid();
@mkdir( $prof, 0777, true );

$SYLLABLES = array( 5, 8, 10, 13 ); // Trans-for-ma-cio-nes
$no_fonts  = ' --host-resolver-rules="MAP fonts.googleapis.com ~NOTFOUND"';
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

    if ( 'scope' === $p['topic'] ) {
        $want  = array( 'World AIDS Day vigil', 'Cycle to Zero ride' );
        $never = array( 'Cycle to Zero private', 'Cycle to Zero draft', 'Cycle to Zero hidden', 'Cycle to Zero social', 'Alpha meetup', 'Transformaciones' );
        $grid  = $phone ? (array) $r['panelTitles'] : (array) $r['gridTitles'];
        $where = $phone ? 'the day panel' : 'the month grid';
        foreach ( $want as $t ) {
            rl( in_array( $t, $grid, true ), "PLANT S: $name: $where scoped to World AIDS Day does not show \"$t\": " . json_encode( $grid ) );
            rl( in_array( $t, (array) $r['listTitles'], true ), "PLANT S: $name: the list scoped to World AIDS Day does not show \"$t\": " . json_encode( $r['listTitles'] ) );
        }
        foreach ( $never as $t ) { rl( ! in_array( $t, $grid, true ) && ! in_array( $t, (array) $r['listTitles'], true ), "PLANT S: $name: \"$t\" is shown in a block scoped to World AIDS Day" ); }
        rl( ! in_array( 'Cycle to Zero past', (array) $r['listTitles'], true ), "$name: the scoped list shows the past event" );
        continue;
    }

    rl( ! empty( $r['esAny'] ), "PLANT H: $name: no grid title is lang=\"es\"" );
    $es = $phone ? $r['esPanel'] : $r['esGrid'];
    rl( is_array( $es ) && $es['seen'] && 'Transformaciones' === $es['text'], "$name: the Spanish title is not on screen: " . json_encode( $es ) );
    if ( ! is_array( $es ) || ! $es['seen'] ) { continue; }
    rl( 'es' === $es['lang'], "PLANT H: $name: the Spanish title is lang=" . json_encode( $es['lang'] ) );
    rl( false !== strpos( (string) $es['raw'], "\u{00AD}" ), "PLANT H: $name: the Spanish title carries no soft hyphen: " . json_encode( $es['raw'] ) );
    foreach ( (array) $es['breaks'] as $b ) {
        rl( in_array( (int) $b['at'], $SYLLABLES, true ) && $b['hyphen'], "PLANT H: $name: \"Transformaciones\" breaks after " . json_encode( substr( 'Transformaciones', 0, max( 0, (int) $b['at'] ) ) ) . ( $b['hyphen'] ? '' : ' with no hyphen' ) . ', not at a syllable with one' );
    }
    if ( 700 === $w ) { rl( count( (array) $es['breaks'] ) > 0, "PLANT H: $name: at 700 the Spanish title does not break in a " . round( $es['w'] ) . 'px column, so nothing was proved' ); }
    $bare = $r['esBare'];
    if ( is_array( $bare ) && $bare['breaks'] ) {
        $hy = array_filter( (array) $bare['breaks'], function ( $b ) use ( $SYLLABLES ) { return $b['hyphen'] && in_array( (int) $b['at'], $SYLLABLES, true ); } );
        rl( ! $hy, "$name: the word without soft hyphens still hyphenates at a syllable, so this Chrome has a dictionary and the check proved nothing: " . json_encode( $bare['breaks'] ) );
    }
}

$rm = function ( $d ) use ( &$rm ) { foreach ( (array) @scandir( $d ) as $f ) { if ( '.' === $f || '..' === $f ) { continue; } is_dir( "$d/$f" ) ? $rm( "$d/$f" ) : @unlink( "$d/$f" ); } @rmdir( $d ); };
$rm( $prof );

echo "\n3.145.0: a series-scoped block and a long title with no dictionary, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " FAILURE(S):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo count( $files ) . " pages: calendar.js and embed.js; the scoped block at 1280, 430 and 390px; the Spanish title at 1280, 700, 430 and 390px with no dictionary.\n";
exit( 0 );
