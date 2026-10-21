<?php
/**
 * 3.113.7 IN A BROWSER: THE EVERYACTION FETCH LOG ON THE PANEL.
 *
 *     php .claude/release-31137-live.php --run
 *     php .claude/release-31137-live.php --run --verbose   and print what was measured
 *     php .claude/release-31137-live.php --run --shots     and save a picture of each
 *
 * The real panel, cut out of render_settings_page() through wp-kit.php, with
 * the real admin.css and everyaction.js, holding a stored fetch of 40 rows with
 * long titles. At 1280px and 782px, WordPress's narrow admin width:
 *
 *   - the log is folded, and opens on its summary
 *   - open, its table scrolls inside its own box, so the panel and the page
 *     never scroll sideways, and the box is no taller than 480px
 *   - the outcome, third, is in view without scrolling sideways
 *   - the Last fetch line above it reads in one paragraph
 *
 * PLANTs 2L in plant-31137.php.
 */

require __DIR__ . '/wp-kit.php';
$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$at    = mktime( 16, 5, 0, 10, 21, 2026 );
$start = mktime( 10, 0, 0, 10, 9, 2027 );
$rows  = array();
for ( $i = 0; $i < 40; $i++ ) {
    $rows[] = array( 'uuid' => (string) ( 750059398 + $i ), 'title' => '50-Plus Saturday AM Coffee Social at Maxfield\'s House of Caffeine, row ' . ( $i + 1 ),
        'start_raw' => '10/09/2027 05:00 PM +0000', 'end_raw' => '10/09/2027 07:00 PM +0000', 'start_local' => $start, 'end_local' => $start + 7200,
        'outcome' => 0 === $i % 5 ? 'refused' : 'unchanged', 'why' => 0 === $i % 5 ? 'its end time has passed' : '', 'post_id' => 500 + $i );
}
update_option( SFAF_EveryAction::FETCH_OPTION, array( 'at' => $at, 'rows' => 40, 'new' => 0, 'updated' => 0, 'error' => '', 'unchanged' => 32, 'refused' => 8 ) );
update_option( SFAF_EveryAction::LOG_OPTION, array( 'at' => $at, 'error' => '', 'rows' => $rows ) );

$admin = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
ob_start();
$admin->render_settings_page();
$page = (string) ob_get_clean();
$a = strpos( $page, '<!-- EVERYACTION -->' );
$b = strpos( $page, '<!-- PARDOT' );
if ( false === $a || false === $b ) { echo "FAIL: the panel is not on the settings page\n"; exit( 1 ); }
$panel = substr( $page, $a, $b - $a );

$probe = <<<'JS'
(function () {
  var out = { errors: [] };
  window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
  window.sfafAdmin = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n' };
  function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
  window.addEventListener('load', function () {
    document.querySelector('.uc-everyaction-panel .uc-panel-header').click();
    var d = document.querySelector('.uc-everyaction-log');
    out.folded = !!d && !d.open;
    out.summary = d ? d.querySelector('summary').textContent.trim() : null;
    if (d) { d.querySelector('summary').click(); }
    var box = document.querySelector('.uc-everyaction-log-scroll'), table = d ? d.querySelector('table') : null;
    out.open = !!d && d.open;
    out.panel = rect(document.querySelector('.uc-everyaction-panel'));
    out.box = rect(box);
    out.table = rect(table);
    out.boxScrolls = !!box && box.scrollWidth > box.clientWidth;
    out.rows = d ? d.querySelectorAll('tbody tr').length : 0;
    var oc = d ? d.querySelector('tbody tr td:nth-child(3)') : null;
    out.outcomeCell = rect(oc);
    out.outcomeText = oc ? oc.textContent.trim() : null;
    out.line = (document.querySelector('.uc-everyaction-last-fetch') || {}).textContent;
    out.pageWidth = document.documentElement.scrollWidth;
    out.vw = window.innerWidth;
    var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
  });
})();
JS;

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>EveryAction fetch log</title>'
    . '<link rel="stylesheet" href="../admin/css/admin.css">'
    . '<script>' . $probe . '</script>'
    . '</head><body class="wp-admin"><div class="wrap uc-admin-wrap"><form>' . $panel . '</form></div>'
    . '<script src="../admin/js/everyaction.js"></script>'
    . '</body></html>';
$file = __DIR__ . '/release-31137-live.html';
file_put_contents( $file, $html );
$frames = array();
foreach ( array( 'desktop' => 1280, 'narrow' => 782 ) as $wn => $w ) {
    $frames[ $wn ] = array( 'w' => $w, 'file' => __DIR__ . '/release-31137-live-' . $wn . '-frame.html' );
    file_put_contents( $frames[ $wn ]['file'], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $file ) . '" style="display:block;width:' . $w . 'px;height:1400px;border:0"></iframe>'
        . '<script>window.addEventListener("load",function(){setTimeout(function(){var f=document.querySelector("iframe").contentDocument;var p=f.getElementById("out");var q=document.createElement("pre");q.id="out";q.textContent=p?p.textContent:"";document.body.appendChild(q);},400);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo "wrote the page; --run drives it.\n"; exit( 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

foreach ( $frames as $wn => $f ) {
    $url = 'file:///' . str_replace( '\\', '/', $f['file'] );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1300,1500 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) || '' === trim( $m[1] ) ) { rl( false, "$wn: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $argv, true ) ) { echo "$wn: " . json_encode( $r, JSON_UNESCAPED_SLASHES ) . "\n"; }
    if ( in_array( '--shots', $argv, true ) ) {
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . $f['w'] . ',1400 --virtual-time-budget=8000 --screenshot="' . __DIR__ . '/release-31137-shot-' . $wn . '.png" "' . $url . '" 2>NUL' );
    }
    rl( empty( $r['errors'] ), "$wn threw: " . implode( '; ', (array) $r['errors'] ) );
    rl( $r['folded'] && 'Rows in the last fetch (40)' === $r['summary'], "2L: $wn: the log is not folded under its summary: " . json_encode( array( $r['folded'], $r['summary'] ) ) );
    rl( $r['open'] && 40 === $r['rows'], "$wn: the log did not open on its 40 rows" );
    rl( $r['outcomeCell'] && $r['outcomeCell']['right'] <= $r['box']['right'] && 'Not imported: its end time has passed' === $r['outcomeText'], "2L: $wn: the outcome is not in view without scrolling: " . json_encode( array( $r['outcomeCell'], $r['box'], $r['outcomeText'] ) ) );
    rl( (int) $r['pageWidth'] <= (int) $r['vw'], "2L: $wn: the page scrolls sideways, " . $r['pageWidth'] . 'px in ' . $r['vw'] );
    rl( $r['box'] && $r['panel'] && $r['box']['right'] <= $r['panel']['right'] && $r['box']['left'] >= $r['panel']['left'], "2L: $wn: the log's box leaves the panel: " . json_encode( array( $r['box'], $r['panel'] ) ) );
    rl( $r['boxScrolls'], "2L: $wn: the wide table does not scroll inside its box" );
    rl( $r['box'] && $r['box']['h'] <= 482, "2L: $wn: the box is " . $r['box']['h'] . 'px tall, over 480' );
    rl( false !== strpos( (string) $r['line'], '40 rows read, 0 created, 0 updated, 32 unchanged, 8 not imported.' ), "$wn: the Last fetch line reads " . json_encode( $r['line'] ) );
}

echo "\n3.113.7: the EveryAction fetch log, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "The last fetch's 40 rows are folded under the Last fetch line and scroll inside their own box, at 1280px and 782px.\n";
exit( 0 );
