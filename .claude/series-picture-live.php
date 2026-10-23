<?php
/**
 * THE SERIES SCREEN'S PICTURE, IN A REAL BROWSER (3.113.8).
 *
 *     php .claude/series-picture-live.php          writes the page
 *     php .claude/series-picture-live.php --run    writes it and drives Chrome
 *
 * The real render_series_edit() through wp-kit.php, with the real portal.css
 * and portal.js. What is read off the page:
 *
 *   the event editor's picture block, bound: the series picture in the
 *   preview with Remove on it; only "No picture" and this series' picture in
 *   the list; Remove empties the preview and turns the reset on; choosing the
 *   picture again puts it back and turns the reset off; "+ Upload a picture"
 *   is the outlined control; no wp.media button and no URL box.
 *
 * The render and the save are release-31138-test.php's.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function sp_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

function sp_query( $a ) {
    $out = array();
    $ids = array_keys( $GLOBALS['kit_posts'] );
    rsort( $ids );
    foreach ( $ids as $id ) {
        $p = $GLOBALS['kit_posts'][ $id ];
        if ( ( isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event' ) !== $p->post_type ) { continue; }
        if ( 'attachment' === $p->post_type ) {
            $file = isset( $GLOBALS['kit_meta'][ $id ]['_wp_attached_file'] ) ? (string) $GLOBALS['kit_meta'][ $id ]['_wp_attached_file'] : '';
            $ok = true;
            foreach ( (array) ( isset( $a['meta_query'] ) ? $a['meta_query'] : array() ) as $c ) {
                if ( is_array( $c ) && isset( $c['key'], $c['compare'] ) && '_wp_attached_file' === $c['key'] && 'REGEXP' === strtoupper( $c['compare'] ) ) {
                    $ok = $ok && (bool) preg_match( '#' . $c['value'] . '#', $file );
                }
            }
            foreach ( (array) ( isset( $a['tax_query'] ) ? $a['tax_query'] : array() ) as $t ) {
                if ( ! is_array( $t ) || ! isset( $t['taxonomy'] ) ) { continue; }
                $on = isset( $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] : array();
                $ok = $ok && ( ( isset( $t['operator'] ) && 'NOT EXISTS' === $t['operator'] ) ? ! $on : (bool) array_intersect( array_map( 'intval', (array) $t['terms'] ), $on ) );
            }
            if ( ! $ok ) { continue; }
        }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
}
$GLOBALS['kit_query']     = 'sp_query';
$GLOBALS['kit_get_posts'] = 'sp_query';
function sp_picture( $id, $file, $series = 0 ) {
    kit_write_post( array( 'ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => '' ) );
    update_post_meta( $id, '_wp_attached_file', $file );
    $GLOBALS['kit_images'][ $id ] = 'https://resources.sfaf.org/wp-content/uploads/' . $file;
    if ( $series ) { $GLOBALS['kit_terms'][ $id ][ SFAF_Media::TAXONOMY ] = array( $series ); }
}
sp_picture( 8101, 'calendar/coffee-social.jpg', 11 );
sp_picture( 8102, 'calendar/another-series.jpg', 12 );
sp_picture( 8103, 'calendar-other/one-off.jpg' );
update_term_meta( 11, SFAF_Series::META_IMAGE_ID, 8101 );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$_GET = array();
ob_start();
kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( new WP_User(), 11 ) );
$html = (string) ob_get_clean();

$probe = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function seen(el) { return !!el && el.getBoundingClientRect().height > 0 && getComputedStyle(el).visibility !== 'hidden' && !el.closest('[hidden]'); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
window.addEventListener('load', function () { setTimeout(function () {
  var block = document.querySelector('.uc-image-field [data-uc-image-block]');
  out.block = !!block;
  if (block) {
    var img = block.querySelector('[data-uc-image-preview-img]');
    var reset = block.querySelector('[data-uc-image-reset]');
    var pill = block.querySelector('[data-uc-img-source-tag]');
    var remove = block.querySelector('[data-uc-image-remove]');
    var picker = block.querySelector('[data-uc-image-picker]');
    function state() {
      return { state: block.getAttribute('data-uc-image-state'), img: seen(img) ? (img.getAttribute('src') || '') : 'HIDDEN',
               pill: text(pill), remove: seen(remove), reset: reset ? (reset.disabled ? 'off' : 'on') : 'none',
               name: text(picker && picker.querySelector('[data-uc-image-current]')) };
    }
    out.atLoad = state();
    if (picker) { picker.open = true; }
    out.rows = Array.prototype.filter.call(block.querySelectorAll('[data-uc-image-option]'), function (r) { return seen(r.closest('label')); })
      .map(function (r) { return r.value + (r.checked ? '*' : ''); });
    if (remove) { remove.click(); }
    out.afterRemove = state();
    var row = block.querySelector('[data-uc-image-option][value="8101"]');
    if (row) { row.checked = true; row.dispatchEvent(new Event('change', { bubbles: true })); }
    out.afterChoose = state();
    var up = block.querySelector('input[type="file"][data-uc-event-upload]');
    var lab = up && up.closest('label');
    out.upload = lab ? { name: up.name, text: text(lab), seen: seen(lab), border: getComputedStyle(lab).borderTopStyle } : null;
  }
  out.mediaButtons = document.querySelectorAll('.uc-choose-image').length;
  out.urlBox = !!document.querySelector('[name="series_image_url"]');
  out.pageWidth = document.documentElement.scrollWidth;
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}, 400); });
})();
JS;

$page = preg_replace( '#</body>#i', '<script>' . $probe . '</script></body>', $html, 1 );
if ( $page === $html ) { $page .= '<script>' . $probe . '</script>'; }
$file = __DIR__ . '/series-picture-live.html';
file_put_contents( $file, $page );
echo "wrote the page\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $url = 'file:///' . str_replace( '\\', '/', $file );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1200,2400 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        sp_check( false, 'Chrome returned no probe block' );
    } else {
        $g = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        if ( in_array( '--verbose', $argv, true ) ) { echo json_encode( $g, JSON_UNESCAPED_SLASHES ) . "\n"; }
        $pic = 'https://resources.sfaf.org/wp-content/uploads/calendar/coffee-social.jpg';
        sp_check( empty( $g['errors'] ), 'the page threw: ' . implode( '; ', (array) $g['errors'] ) );
        sp_check( ! empty( $g['block'] ), 'no picture block on the series screen' );
        $want = array( 'state' => 'event', 'img' => $pic, 'pill' => 'Series picture', 'remove' => true, 'reset' => 'off', 'name' => 'coffee-social.jpg' );
        sp_check( isset( $g['atLoad'] ) && $want === $g['atLoad'], 'at load the preview is not the series picture with Remove: ' . json_encode( isset( $g['atLoad'] ) ? $g['atLoad'] : null ) );
        sp_check( isset( $g['rows'] ) && array( '0', '8101*' ) === $g['rows'], 'the list is not "No picture" and this series\' picture: ' . json_encode( isset( $g['rows'] ) ? $g['rows'] : null ) );
        $none = array( 'state' => 'none', 'img' => 'HIDDEN', 'pill' => 'Placeholder', 'remove' => false, 'reset' => 'on', 'name' => 'No picture chosen' );
        sp_check( isset( $g['afterRemove'] ) && $none === $g['afterRemove'], 'Remove does not empty the preview and turn the reset on: ' . json_encode( isset( $g['afterRemove'] ) ? $g['afterRemove'] : null ) );
        sp_check( isset( $g['afterChoose'] ) && $want === $g['afterChoose'], 'choosing the picture again does not put it back: ' . json_encode( isset( $g['afterChoose'] ) ? $g['afterChoose'] : null ) );
        $u = isset( $g['upload'] ) ? $g['upload'] : null;
        sp_check( $u && 'uc_series_picture' === $u['name'] && '+ Upload a picture' === $u['text'] && $u['seen'] && 'solid' === $u['border'], 'the upload is not the outlined "+ Upload a picture": ' . json_encode( $u ) );
        sp_check( isset( $g['mediaButtons'] ) && 0 === $g['mediaButtons'], 'a WordPress media button is still on the screen' );
        sp_check( isset( $g['urlBox'] ) && false === $g['urlBox'], 'the image URL box is still on the screen' );
        sp_check( isset( $g['pageWidth'] ) && $g['pageWidth'] <= 1200, 'the page scrolls sideways: ' . ( isset( $g['pageWidth'] ) ? $g['pageWidth'] : '' ) );
    }
}

echo "\nTHE SERIES SCREEN'S PICTURE (3.113.8)\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "the event editor's picture block, bound on the series screen: this series' picture only, Remove and choose\nturn the reset on and off, and the upload is the outlined control.\n";
exit( 0 );
