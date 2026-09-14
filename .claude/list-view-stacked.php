<?php
/**
 * WHAT COLLAPSES IN THE LIST VIEW AT 700px (3.83.0).
 *
 * WHY. At 700px the reported shape is the sidebar as a narrow column on the
 * left, "No upcoming events found." set one letter per line beside it, and a
 * Load More button floating in the middle. One letter per line is a container
 * about one character wide, which is a measurement rather than an impression.
 *
 * The toggle only became reachable in the combined mode in 3.82.0, so this was
 * the first time anybody had opened the list view in the stacked case.
 *
 *     php .claude/list-view-stacked.php --run
 *
 * IT MEASURES DOM STATES, NOT A BUTTON PRESS, AND THAT IS DELIBERATE. embed.js
 * does not enhance server markup: init() calls loadBlock(), which fetches the
 * block from the REST route and replaces the container wholesale, so driving it
 * here would mean standing up an endpoint in order to measure a flex container.
 * The question is what the CSS does with a given DOM, so each state the toggle
 * can leave the block in is set by hand and measured.
 *
 * Two things learned the hard way while writing it, recorded so the next
 * harness does not repeat them:
 *
 *   . embed.js's docblock contains a literal </script>, because it shows
 *     somebody the tag to paste. Inlined, that ends the element and the rest of
 *     the file becomes markup, which reads as a SyntaxError with no line.
 *   . embed.js boots from [data-sfaf-calendar] and takes its endpoints from its
 *     own script tag's src. Inlined it has neither, so it binds nothing.
 */

$root = dirname( __DIR__ );
$out  = $root . '/.claude/list-view-stacked.html';

function lv_card( $title ) {
    return '<article class="uc-event-card">'
         . '<div class="uc-card-media"><span class="uc-thumb-ph"></span></div>'
         . '<div class="uc-card-body"><h3 class="uc-card-title">' . htmlspecialchars( $title ) . '</h3>'
         . '<p class="uc-card-meta">Strut, Tue Sep 15, 6 to 7:30 pm</p></div></article>';
}

function lv_sidebar_row( $title ) {
    return '<a class="uc-sidebar-row" href="#">'
         . '<span class="uc-sidebar-thumb"><span class="uc-thumb-ph"></span></span>'
         . '<span class="uc-sidebar-body"><span class="uc-sidebar-title">' . htmlspecialchars( $title ) . '</span>'
         . '<span class="uc-sidebar-when"><span class="uc-when-date">Tue, Sep 15</span></span></span></a>';
}

$grid_cells = '';
for ( $w = 0; $w < 5; $w++ ) {
    $grid_cells .= '<tr>';
    for ( $d = 0; $d < 7; $d++ ) {
        $grid_cells .= '<td class="uc-day"><span class="uc-day-num">' . ( $w * 7 + $d + 1 ) . '</span></td>';
    }
    $grid_cells .= '</tr>';
}

$sidebar = '<div class="uc-sidebar" data-count="10"><h3 class="uc-sidebar-heading">Upcoming event dates</h3>'
    . '<div class="uc-sidebar-list">' . lv_sidebar_row( 'Strut Drop-in Clinic' ) . lv_sidebar_row( 'El Grupo de Apoyo Latino' ) . '</div>'
    . '<a class="uc-sidebar-all" href="#">See all events</a></div>';

$cards = lv_card( 'Strut Drop-in Clinic' ) . lv_card( 'Cycle to Zero Training Ride' ) . lv_card( 'El Grupo de Apoyo Latino' );

/* The block, in the shape render_events_block() emits for the combined mode
 * after 3.82.0: the toggle in the view bar, then one panels wrapper holding the
 * head, the grid, the sidebar and the list. */
$block = '<div class="uc-calendar uc-view-combined" data-view="combined" data-per-page="12">'
    . '<div class="uc-view-bar">'
    . '<div class="uc-view-toggle" role="group"><button type="button" class="uc-view-btn" data-view="list">L</button>'
    . '<button type="button" class="uc-view-btn active" data-view="combined">C</button></div></div>'
    . '<div class="uc-view-panels uc-view-panels-combined" data-uc-combined="1">'
    . '<div class="uc-combined-head"><div class="uc-month-head"><div class="uc-month-heading">'
    . '<h3 class="uc-month-label">September 2026</h3></div></div></div>'
    . '<div class="uc-view-panel uc-panel-calendar"><div class="uc-month"><div class="uc-month-wrap">'
    . '<table class="uc-month-grid"><thead><tr><th>S</th><th>M</th><th>T</th><th>W</th><th>T</th><th>F</th><th>S</th></tr></thead>'
    . '<tbody>' . $grid_cells . '</tbody></table></div></div></div>'
    . '<div class="uc-view-panel uc-panel-sidebar">' . $sidebar . '</div>'
    . '<div class="uc-view-panel uc-panel-list" hidden><div class="uc-event-list">' . $cards . '</div>'
    . '<div class="uc-pagination uc-pagination-loadmore"><button type="button" class="uc-load-more">Load More</button></div></div>'
    . '</div></div>';

$probe = <<<'JS'
function box(el) {
  var r = el.getBoundingClientRect();
  return { l: Math.round(r.left), w: Math.round(r.width), h: Math.round(r.height) };
}
var L = [];
function say(s) { L.push(s); }
var host = document.getElementById('host');

function pad(s, n) { s = String(s); while (s.length < n) { s += ' '; } return s; }
function lpad(s, n) { s = String(s); while (s.length < n) { s = ' ' + s; } return s; }

function shot(label) {
  var panels = host.querySelector('.uc-view-panels');
  say('=== ' + label + ' ===');
  say('  wrapper ' + (panels.classList.contains('uc-view-panels-combined') ? 'IS combined ' : 'not combined')
      + '   display ' + getComputedStyle(panels).display);
  ['uc-panel-calendar', 'uc-panel-sidebar', 'uc-panel-list'].forEach(function (c) {
    var el = host.querySelector('.' + c);
    var cs = getComputedStyle(el), b = box(el);
    say('  ' + pad(c, 19) + (el.hidden ? 'hidden ' : 'shown  ')
        + lpad(b.w, 5) + 'x' + lpad(b.h, 4)
        + '  left ' + lpad(b.l, 4)
        + '  flex ' + pad(cs.flex, 14) + ' min-width ' + cs.minWidth);
  });
  var list = host.querySelector('.uc-event-list');
  say('  uc-event-list      ' + box(list).w + 'x' + box(list).h);
  var card = host.querySelector('.uc-event-card');
  if (card) {
    var cb = box(card);
    say('  a card             ' + cb.w + 'x' + cb.h
        + (cb.w < 60 ? '   COLLAPSED. This is the one letter per line.' : ''));
  }
  var lm = host.querySelector('.uc-load-more');
  if (lm) { say('  Load More          ' + box(lm).w + 'x' + box(lm).h + '  left ' + box(lm).l); }
  say('');
}

function setState(combinedClass, showList, showSidebar, showCal) {
  var panels = host.querySelector('.uc-view-panels');
  panels.classList.toggle('uc-view-panels-combined', combinedClass);
  host.querySelector('.uc-panel-list').hidden = !showList;
  host.querySelector('.uc-panel-sidebar').hidden = !showSidebar;
  host.querySelector('.uc-panel-calendar').hidden = !showCal;
  host.querySelector('.uc-combined-head').hidden = !showSidebar;
}

window.addEventListener('load', function () {
  setTimeout(function () {
    say('700px container. Each block below is one DOM state the toggle can leave');
    say('the block in, measured. embed.js is not loaded; see the file header.');
    say('');

    setState(true, false, true, true);
    shot('COMBINED, as the block arrives');

    setState(true, true, true, false);
    shot('LIST shown, wrapper STILL combined, sidebar STILL shown');

    setState(true, true, false, false);
    shot('LIST shown, wrapper still combined, sidebar hidden');

    setState(false, true, false, false);
    shot('LIST shown, wrapper class REMOVED, sidebar hidden   (3.82.0 intends this)');

    document.getElementById('out').textContent = L.join('\n');
  }, 60);
});
JS;

$html = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
    . "<title>SFAF Calendar: the list view at 700px</title>\n"
    . "<!-- GENERATED BY .claude/list-view-stacked.php. Do not edit; regenerate. -->\n"
    . "<link rel=\"stylesheet\" href=\"../public/css/calendar.css\">\n"
    . "<style>body{margin:0;padding:20px;font:13px/1.5 system-ui,sans-serif;background:#f4f5f7}"
    . "#host{width:700px}"
    . "pre#out{margin-top:20px;background:#fff;padding:14px;white-space:pre;border:1px solid #ccc;"
    . "font:12px/1.5 ui-monospace,Consolas,monospace}</style>\n</head>\n<body>\n"
    . "<h1>The list view at 700px</h1>\n<div id=\"host\">" . $block . "</div>\n"
    . "<pre id=\"out\">not run</pre>\n"
    . "<script>\n" . $probe . "\n</script>\n</body>\n</html>\n";

file_put_contents( $out, $html );
echo "wrote: $out\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    $url    = 'file:///' . str_replace( '\\', '/', $out );
    $dom    = shell_exec( '"' . $chrome . '" --headless --disable-gpu --window-size=1400,1200 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        echo html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) . "\n";
    } else {
        echo "Chrome returned no probe block.\n";
        exit( 1 );
    }
}
