<?php
/**
 * THE PREFERENCES SCREEN IN A REAL BROWSER (3.102.0).
 *
 *     php .claude/preferences-live.php          writes the page
 *     php .claude/preferences-live.php --run    writes it and drives Chrome
 *
 * The real render_preferences(), through wp-kit.php, with the real portal.css.
 * What is read off the rendered page: the navigation entry and that it is the
 * current one, the three answers with None chosen, a fieldset per filter group
 * with its tick boxes at a normal size and a gap, what the form posts, and that
 * nothing on it could name another person.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function pl_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
ob_start();
kit_call( 'SFAF_Portal', 'render_preferences', $portal, array( new WP_User() ) );
$html = (string) ob_get_clean();

/* Structural, before any browser: the form speaks only for its own person. */
pl_check( false !== strpos( $html, 'name="uc_action" value="save_preferences"' ), 'the form does not post save_preferences' );
pl_check( false === strpos( $html, 'name="user_id"' ), 'the Preferences form carries a user id, so it could name somebody else' );

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
window.addEventListener('load', function () { setTimeout(function () {
  function css(el, p) { return el ? getComputedStyle(el).getPropertyValue(p).trim() : 'ABSENT'; }
  var form = document.querySelector('form.uc-prefs');
  var nav = document.querySelector('.uc-nav-item[aria-current="page"]');
  out.h1 = (document.querySelector('.uc-page-head h1') || {}).textContent || '';
  out.navCurrent = nav ? nav.textContent.replace(/\s+/g, ' ').trim() : '';
  out.navLast = (function () { var a = document.querySelectorAll('.uc-portal-nav .uc-nav-item'); return a.length ? a[a.length - 1].textContent.replace(/\s+/g, ' ').trim() : ''; })();
  var radios = form ? form.querySelectorAll('input[type="radio"][name="digest_frequency"]') : [];
  out.radios = Array.prototype.map.call(radios, function (r) { return r.value + (r.checked ? '*' : ''); });
  out.legends = Array.prototype.map.call(form ? form.querySelectorAll('fieldset > legend') : [], function (l) { return l.textContent.trim(); });
  out.groups = {};
  ['venues', 'series', 'organizers'].forEach(function (g) {
    var boxes = form.querySelectorAll('input[type="checkbox"][name="digest_' + g + '[]"]');
    var b = boxes[0];
    out.groups[g] = {
      count: boxes.length,
      ticked: Array.prototype.filter.call(boxes, function (x) { return x.checked; }).length,
      size: b ? Math.round(b.getBoundingClientRect().width) + 'x' + Math.round(b.getBoundingClientRect().height) : 'none',
      gap: b ? Math.round(b.closest('label').getBoundingClientRect().right > 0 ? (function () {
        var lab = b.closest('label'); var r = document.createRange(); r.selectNodeContents(lab);
        var t = Array.prototype.filter.call(lab.childNodes, function (n) { return n.nodeType === 3 && n.textContent.trim(); })[0];
        if (!t) { return -1; } r.selectNodeContents(t); return r.getBoundingClientRect().left - b.getBoundingClientRect().right; })() : -1) : -1
    };
  });
  var fs = form.querySelectorAll('fieldset');
  out.fieldsetGap = fs.length > 1 ? Math.round(fs[1].getBoundingClientRect().top - fs[0].getBoundingClientRect().bottom) : -1;
  var radio = radios[0];
  out.radioSize = radio ? Math.round(radio.getBoundingClientRect().width) + 'x' + Math.round(radio.getBoundingClientRect().height) : 'none';
  var btn = form.querySelector('button[type="submit"]');
  out.button = btn ? btn.textContent.trim() + ' / ' + css(btn, 'background-color') : 'none';
  out.hints = Array.prototype.map.call(form.querySelectorAll('.uc-hint'), function (p) { return p.textContent.replace(/\s+/g, ' ').trim(); });
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}, 300); });
})();
JS;

$page = preg_replace( '#</body>#i', '<script>' . $probe . '</script></body>', $html, 1 );
if ( $page === $html ) { $page .= '<script>' . $probe . '</script>'; }
$file = __DIR__ . '/preferences-live.html';
file_put_contents( $file, $page );
echo "wrote the page\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $url = 'file:///' . str_replace( '\\', '/', $file );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1200,1600 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { echo "FAIL: Chrome returned no probe block\n"; exit( 1 ); }
    $got = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    echo json_encode( $got, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

    pl_check( empty( $got['errors'] ), 'the page threw: ' . implode( '; ', (array) $got['errors'] ) );
    pl_check( 'Preferences' === trim( $got['h1'] ), 'the heading reads ' . $got['h1'] );
    pl_check( 'Preferences' === $got['navCurrent'], 'Preferences is not the current navigation entry: ' . $got['navCurrent'] );
    pl_check( 'Preferences' === $got['navLast'], 'Preferences is not last in the navigation: ' . $got['navLast'] );
    pl_check( array( 'none*', 'daily', 'weekly' ) === $got['radios'], 'the three answers with None chosen: ' . json_encode( $got['radios'] ) );
    pl_check( array( 'How often', 'Venues', 'Series', 'Organizers' ) === $got['legends'], 'the fieldsets: ' . json_encode( $got['legends'] ) );
    foreach ( array( 'venues', 'series', 'organizers' ) as $g ) {
        $x = $got['groups'][ $g ];
        pl_check( 2 === $x['count'] && 0 === $x['ticked'], "$g: " . json_encode( $x ) );
        pl_check( in_array( $x['size'], array( '13x13', '14x14', '15x15', '16x16', '18x18' ), true ), "$g: a tick box is not a normal size: {$x['size']}" );
        pl_check( $x['gap'] >= 4, "$g: no gap between the tick box and its word ({$x['gap']}px)" );
    }
    pl_check( in_array( $got['radioSize'], array( '13x13', '14x14', '15x15', '16x16', '18x18' ), true ), 'the radios are not a normal size: ' . $got['radioSize'] );
    pl_check( $got['fieldsetGap'] >= 10, 'the fieldsets sit against each other: ' . $got['fieldsetGap'] . 'px' );
    pl_check( 0 === strpos( $got['button'], 'Save preferences / ' ), 'the save button: ' . $got['button'] );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "Preferences: last in the navigation and current, three answers with None chosen, a fieldset per filter\n";
echo "group with normal-sized tick boxes and a gap, and a form that posts only its own person's choices.\n";
exit( 0 );
