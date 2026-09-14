<?php
/**
 * DOES THE COMBINED VIEW'S RIGHT COLUMN ENCLOSE ITS CONTENTS? MEASURED.
 *
 * WHY THIS EXISTS, AND WHY IT IS A BROWSER RATHER THAN A SWEEP. 3.80.0 answered
 * a report about this card by reading the stylesheet: nothing caps a height,
 * nothing is positioned out of flow, so nothing can overflow, and what reads as
 * a card closing early is two hairlines with a paragraph between them. Then the
 * fix shipped and the report came back.
 *
 * A stylesheet is not rendered output. Every check this project has on these two
 * files reads markup or rules, so a fault that lives only in GEOMETRY reaches
 * Mark's screen with the suite green, and that is written down in PROJECT.md as
 * a known gap. This closes it for this one card, with real layout numbers out of
 * a real engine.
 *
 *     php .claude/sidebar-enclosure.php          build the page
 *     php .claude/sidebar-enclosure.php --run    build it and run Chrome
 *
 * WHAT IT BUILDS. The combined view's real shape, from the real stylesheet, in
 * the two states that matter: a sidebar WITH events and a sidebar with NONE.
 * Mark's screenshot is the empty one, because every imported event is still a
 * draft.
 *
 * WHAT IT MEASURES, all of it against the question actually asked:
 *
 *   1. Is any part of the right column outside the shared card's border box?
 *      That is "See all events sits outside the card", stated as geometry.
 *   2. Where does the card's painted edge stop, against where its contents end?
 *      That is "the card is drawn shorter than what is in it".
 *   3. What radius does each corner of the heading band actually compute to?
 *      That is "the top corners are not rounded", stated as pixels.
 *   4. Does the band reach the card's own edge, or stop inside the panel's
 *      padding? That is whether 3.80.0's escape landed at all.
 */

$root = dirname( __DIR__ );
$out  = $root . '/.claude/sidebar-enclosure.html';

/* ---------------------------------------------------------------------------
 * The markup, written to match the renderers rather than guessed at. Each block
 * names the method it comes from so a divergence can be found and fixed.
 * ------------------------------------------------------------------------ */

/* SFAF_Shortcodes::render_sidebar(), the list branch. */
function se_row( $title, $when, $where ) {
    return '<a class="uc-sidebar-row" href="#">'
         . '<span class="uc-sidebar-thumb"><span class="uc-thumb-ph"></span></span>'
         . '<span class="uc-sidebar-body">'
         . '<span class="uc-sidebar-title">' . htmlspecialchars( $title ) . '</span>'
         . '<span class="uc-sidebar-when"><span class="uc-when-date">' . htmlspecialchars( $when ) . '</span></span>'
         . '<span class="uc-sidebar-where">' . htmlspecialchars( $where ) . '</span>'
         . '</span></a>';
}

/* SFAF_Shortcodes::render_month_tabs(). */
function se_tabs() {
    return '<div class="uc-month-tabs">'
         . '<button type="button" class="uc-month-tab" data-goto="2026-08">'
         . '<span class="uc-month-tab-name">August</span><span class="uc-month-tab-count">3</span></button>'
         . '<button type="button" class="uc-month-tab" data-goto="2026-10">'
         . '<span class="uc-month-tab-name">October</span><span class="uc-month-tab-count">0</span></button>'
         . '</div>';
}

function se_sidebar( $empty ) {
    $body = '';
    if ( $empty ) {
        $body = '<p class="uc-sidebar-empty">Nothing scheduled this month.</p>';
    } else {
        $body .= se_row( 'Strut Drop-in Clinic', 'Tue, Sep 15', 'Strut' );
        $body .= se_row( 'Cycle to Zero Training Ride', 'Thu, Sep 17', '1035 Market St' );
        $body .= se_row( 'El Grupo de Apoyo Latino', 'Fri, Sep 18', 'Strut' );
    }
    return '<div class="uc-sidebar" data-count="10">'
         . '<h3 class="uc-sidebar-heading">Upcoming event dates</h3>'
         . '<div class="uc-sidebar-list">' . $body . '</div>'
         . se_tabs()
         . '<a class="uc-sidebar-all" href="#">See all events &rarr;</a>'
         . '</div>';
}

/* SFAF_Shortcodes::render_month_head() and the grid, cut to what affects height. */
function se_grid() {
    $cells = '';
    for ( $w = 0; $w < 5; $w++ ) {
        $cells .= '<tr>';
        for ( $d = 0; $d < 7; $d++ ) {
            $cells .= '<td class="uc-day"><span class="uc-day-num">' . ( $w * 7 + $d + 1 ) . '</span></td>';
        }
        $cells .= '</tr>';
    }
    return '<div class="uc-month">'
         . '<div class="uc-month-wrap"><table class="uc-month-grid"><thead><tr>'
         . '<th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>'
         . '</tr></thead><tbody>' . $cells . '</tbody></table></div></div>';
}

function se_head() {
    return '<div class="uc-month-head">'
         . '<div class="uc-month-heading"><h3 class="uc-month-label">September 2026</h3></div>'
         . '<div class="uc-month-nav-group">'
         . '<button type="button" class="uc-month-nav uc-month-prev" aria-label="Previous month">'
         . '<svg class="sfaf-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 5 7 7-7 7"/></svg></button>'
         . '<button type="button" class="uc-month-nav uc-month-today">Today</button>'
         . '<button type="button" class="uc-month-nav uc-month-next" aria-label="Next month">'
         . '<svg class="sfaf-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 5 7 7-7 7"/></svg></button>'
         . '</div></div>';
}

function se_combined( $empty ) {
    return '<div class="uc-calendar uc-view-combined" data-view="combined">'
         . '<div class="uc-view-panels uc-view-panels-combined">'
         . '<div class="uc-combined-head">' . se_head() . '</div>'
         . '<div class="uc-view-panel uc-panel-calendar">' . se_grid() . '</div>'
         . '<div class="uc-view-panel uc-panel-sidebar">' . se_sidebar( $empty ) . '</div>'
         . '</div></div>';
}

$probe = <<<'JS'
function box(el) {
  var r = el.getBoundingClientRect();
  return { l: Math.round(r.left), t: Math.round(r.top),
           r: Math.round(r.right), b: Math.round(r.bottom),
           w: Math.round(r.width), h: Math.round(r.height) };
}
function radii(el) {
  var s = getComputedStyle(el);
  return [s.borderTopLeftRadius, s.borderTopRightRadius,
          s.borderBottomRightRadius, s.borderBottomLeftRadius].join(' / ');
}
var lines = [];
['full','empty','narrow'].forEach(function (key) {
  var host = document.getElementById('host-' + key);
  var card = host.querySelector('.uc-view-panels-combined');
  var panel = host.querySelector('.uc-panel-sidebar');
  var side = host.querySelector('.uc-sidebar');
  var band = host.querySelector('.uc-sidebar-heading');
  var all  = host.querySelector('.uc-sidebar-all');
  var last = all;

  var cb = box(card), pb = box(panel), sb = box(side), bb = box(band), ab = box(all);
  var cs = getComputedStyle(card);

  lines.push('=== ' + (key === 'empty' ? 'EMPTY SIDEBAR (Mark\'s screenshot)' : 'SIDEBAR WITH EVENTS') + ' ===');
  lines.push('  shared card      ' + cb.w + 'x' + cb.h + '  top ' + cb.t + ' bottom ' + cb.b);
  lines.push('  sidebar panel    ' + pb.w + 'x' + pb.h + '  top ' + pb.t + ' bottom ' + pb.b);
  lines.push('  sidebar box      ' + sb.w + 'x' + sb.h + '  top ' + sb.t + ' bottom ' + sb.b);
  lines.push('  heading band     ' + bb.w + 'x' + bb.h + '  left ' + bb.l + ' right ' + bb.r);
  lines.push('  See all events   ' + ab.w + 'x' + ab.h + '  top ' + ab.t + ' bottom ' + ab.b);
  lines.push('  band radii       ' + radii(band));
  lines.push('  card radii       ' + radii(card));

  // 1. Is anything of the column outside the card's border box?
  var outside = (ab.b > cb.b) || (ab.t < cb.t) || (ab.l < cb.l) || (ab.r > cb.r);
  lines.push('  See all inside the card?           ' + (outside ? 'NO, it is outside' : 'yes'));

  // 2. Does the card's painted edge reach past the last thing in the column?
  lines.push('  card bottom minus link bottom      ' + (cb.b - ab.b) + 'px'
             + (cb.b - ab.b < 0 ? '   NEGATIVE: the card stops above its last row' : ''));

  // 3. Does the band reach the card's own edge on the right?
  //    The card has a 1px border, so flush means right edge == card right - 1.
  var gap = cb.r - 1 - bb.r;
  lines.push('  band right edge to card edge       ' + gap + 'px'
             + (gap > 1 ? '   the band stops inside the panel padding' : '   flush'));

  // 4. Does the sidebar itself paint a border anywhere?
  var ss = getComputedStyle(side);
  lines.push('  sidebar border-width               ' + ss.borderTopWidth + ' ' + ss.borderRightWidth
             + ' ' + ss.borderBottomWidth + ' ' + ss.borderLeftWidth);
  lines.push('  sidebar background                 ' + ss.backgroundColor);

  // 5. Every hairline drawn in the column, top to bottom, which is what a
  //    person reads as the edges of a box.
  var rules = [];
  host.querySelectorAll('.uc-sidebar, .uc-sidebar *').forEach(function (el) {
    var s = getComputedStyle(el);
    ['Top','Bottom'].forEach(function (side2) {
      var w = parseFloat(s['border' + side2 + 'Width']);
      if (w > 0 && s['border' + side2 + 'Style'] !== 'none') {
        var b = box(el);
        rules.push({ y: (side2 === 'Top' ? b.t : b.b), what: el.className.split(' ')[0] + ' ' + side2.toLowerCase() });
      }
    });
  });
  rules.sort(function (a, b) { return a.y - b.y; });
  // EVERY CHILD OF THE SIDEBAR AGAINST THE PANEL, which is the question:
  // do some escape the padding and others not.
  lines.push('  children against the panel content box:');
  var pcs = getComputedStyle(panel);
  var inner = { l: pb.l + parseFloat(pcs.paddingLeft) + parseFloat(pcs.borderLeftWidth),
                r: pb.r - parseFloat(pcs.paddingRight) - parseFloat(pcs.borderRightWidth) };
  lines.push('      panel content box  left ' + Math.round(inner.l) + '  right ' + Math.round(inner.r));
  Array.prototype.forEach.call(side.children, function (ch) {
    var b = box(ch);
    lines.push('      ' + (ch.className || ch.tagName).split(' ')[0].padEnd(22) +
      ' left ' + b.l + ' right ' + b.r + ' width ' + b.w +
      ((b.l < Math.round(inner.l) - 1 || b.r > Math.round(inner.r) + 1) ? '   ESCAPES' : ''));
  });
  lines.push('  hairlines in the column, by y:');
  rules.forEach(function (r) { lines.push('      y=' + r.y + '  ' + r.what); });
  lines.push('');
});
document.getElementById('out').textContent = lines.join('\n');
JS;

$full  = se_combined( false );
$empty = se_combined( true );

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: does the combined view's right column enclose itself</title>
<!--
  GENERATED BY .claude/sidebar-enclosure.php. Do not edit; regenerate.

      chrome --headless --disable-gpu --virtual-time-budget=8000 \\
             --dump-dom file:///.../.claude/sidebar-enclosure.html
-->
<link rel="stylesheet" href="../public/css/calendar.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; color: #373433; }
  h1 { font-size: 17px; margin: 0 0 4px; }
  h2 { font-size: 13px; margin: 26px 0 8px; text-transform: uppercase; letter-spacing: .06em; color: #6B7280; }
  .host { width: 1000px; margin: 0 0 24px; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; overflow-x: auto; }
</style>
</head>
<body>
<h1>Does the combined view's right column enclose its contents?</h1>

<h2>With events</h2>
<div class="host" id="host-full">$full</div>

<h2>With nothing scheduled, which is the reported state</h2>
<div class="host" id="host-empty">$empty</div>

<h2>Stacked, at 700px, where the sidebar is under the grid</h2>
<div class="host" id="host-narrow" style="width:700px">$empty</div>

<pre id="out">not run</pre>
<script>
$probe
</script>
</body>
</html>
HTML;

file_put_contents( $out, $html );
echo "wrote: $out\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) {
        echo "Chrome not found at $chrome. Open the file by hand.\n";
        exit( 0 );
    }
    $url = 'file:///' . str_replace( '\\', '/', $out );
    $cmd = '"' . $chrome . '" --headless --disable-gpu --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL';
    $dom = shell_exec( $cmd );
    if ( preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        echo html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) . "\n";
    } else {
        echo "Chrome returned no probe block.\n";
        exit( 1 );
    }
}
