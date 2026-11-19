<?php
/**
 * 3.142.1 IN A BROWSER: SAVING AN ANNOUNCEMENT REALLY SAVES, AND SAYS SO.
 *
 *     php .claude/release-31421-live.php --run
 *     php .claude/release-31421-live.php --run --verbose   and print every step
 *
 * On the live site (3.142.0) pressing save on Closures and announcements
 * looked like a refresh: no message, nothing seen to be saved. WordPress's
 * common.js moves every div.notice that is not .inline to under the page's
 * first heading, and the save's redirect lands at #uc-announcements, below
 * it, so the message was always above the window; a refused save also came
 * back with the form empty.
 *
 * REAL: Chrome, HTTP to `php -S` running this file, the real form from
 * SFAF_Admin::render_closures_page() with the real admin.css, a real
 * multipart POST with real files (a transparent PNG icon, a 1200x675 picture,
 * an SVG icon), the real SFAF_Admin::handle_announcement_actions() with
 * PHP's own $_FILES and is_uploaded_file(), the real redirect, and the next
 * page drawn from what the save stored. WordPress's notice move is
 * common.js's own rule (7.0.1, lines 1087 to 1090), in plain DOM below.
 * media_handle_upload() is the one stand-in: it keeps the file and returns
 * an attachment id. The server takes 1M in one POST, so a too-large save can
 * be sent. At 1280, 430 and 390px, each from an empty site.
 *
 *   A  a full save (title, dates, description, link, PNG icon, picture):
 *      "Announcement saved." in the window and inside the announcements
 *      section, the row says Icon, Description, Image, Link, and the stored
 *      announcement has the icon and picture as attachments
 *   B  an SVG icon saves, stored as text
 *   C  Edit, a new title, no files: saved, the picture kept
 *   R  refused: a 1200x600 picture, a missing title, a PNG icon that is not
 *      square, and a save over the server's limit. Each says why, in the
 *      window, inside the section; what was typed is still in the form; the
 *      file to choose again is named; nothing is stored
 *   F  ?msg=ann_failed with no reason stored still says it was not saved
 *
 * Without fileinfo on this machine SFAF_Uploads reads types with
 * getimagesize(); the site uses finfo. PLANT in plant-31421.php.
 */

/* ======================================================================
 * THE SERVER. This same file answers under `php -S`.
 * ==================================================================== */
if ( 'cli-server' === PHP_SAPI ) {
    $dir  = getenv( 'RL_DIR' );
    $port = getenv( 'RL_PORT' );
    $path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
    $root = dirname( __DIR__ );
    if ( '/admin/css/admin.css' === $path ) {
        header( 'Content-Type: text/css' );
        readfile( $root . '/admin/css/admin.css' );
        return true;
    }
    if ( 0 === strpos( $path, '/fixture/' ) || '/drive.html' === $path ) {
        $f = $dir . '/' . basename( $path );
        if ( ! is_file( $f ) ) { http_response_code( 404 ); return true; }
        header( 'Content-Type: ' . ( '.html' === substr( $f, -5 ) ? 'text/html; charset=utf-8' : 'application/octet-stream' ) );
        header( 'Cache-Control: no-store' );
        readfile( $f );
        return true;
    }
    if ( 0 === strpos( $path, '/uploads/' ) ) {
        $f = $dir . '/uploads/' . basename( $path );
        if ( ! is_file( $f ) ) { http_response_code( 404 ); return true; }
        header( 'Content-Type: image/png' );
        readfile( $f );
        return true;
    }
    if ( '/wp-admin/edit.php' !== $path ) { http_response_code( 404 ); return true; }

    rl_boot();
    $state = json_decode( (string) @file_get_contents( $dir . '/state.json' ), true );
    $GLOBALS['kit_options'] = $state['options'];
    $GLOBALS['kit_trans']   = $state['trans'];
    $GLOBALS['kit_images']  = $state['images'];
    $GLOBALS['kit_files']   = $state['images'];
    $GLOBALS['rl_state']    = $state;
    $GLOBALS['kit_redirect_throws'] = true;
    $keep = function () use ( $dir ) {
        $s = $GLOBALS['rl_state'];
        $s['options'] = $GLOBALS['kit_options'];
        $s['trans']   = $GLOBALS['kit_trans'];
        $s['images']  = $GLOBALS['kit_images'];
        file_put_contents( $dir . '/state.json', json_encode( $s ) );
    };
    file_put_contents( $dir . '/log.txt', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND );

    $admin = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
    try {
        // admin_init, as WordPress runs it on every admin request.
        $admin->handle_closure_actions();
        $admin->handle_announcement_actions();
    } catch ( KitRedirect $r ) {
        $keep();
        $to = str_replace( 'https://resources.sfaf.org/', 'http://127.0.0.1:' . $port . '/', rl_url( $r->getMessage() ) );
        header( 'Location: ' . $to, true, 302 );
        return true;
    }
    ob_start();
    $admin->render_closures_page();
    $page = ob_get_clean();
    $keep();
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'Cache-Control: no-store' );
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="/admin/css/admin.css"></head>'
        . '<body class="wp-admin" style="margin:0;background:#f0f0f1;font-family:sans-serif"><div id="wpwrap"><div id="wpcontent">'
        . $page . '</div></div>'
        . '<script>'
        /* WordPress 7.0.1, wp-admin/js/common.js 1087 to 1090:
             if ( ! $headerEnd.length ) { $headerEnd = $( '.wrap h1, .wrap h2' ).first(); }
             $( 'div.updated, div.error, div.notice' ).not( '.inline, .below-h2' ).insertAfter( $headerEnd ); */
        . 'document.addEventListener("DOMContentLoaded",function(){'
        . 'var end=document.querySelector(".wp-header-end")||document.querySelector(".wrap h1, .wrap h2");if(!end){return;}'
        . 'var move=[].filter.call(document.querySelectorAll("div.updated, div.error, div.notice"),function(n){return !n.matches(".inline, .below-h2");});'
        . 'var ref=end;move.forEach(function(n){ref.parentNode.insertBefore(n,ref.nextSibling);ref=n;});});'
        . '</script></body></html>';
    return true;
}

/* wp-kit's add_query_arg() starts a second query string where WordPress's
   adds to the first; put the URL back as WordPress would build it. */
function rl_url( $u ) {
    $at = strpos( $u, '?' );
    return false === $at ? $u : substr( $u, 0, $at + 1 ) . str_replace( '?', '&', substr( $u, $at + 1 ) );
}

function rl_boot() {
    require_once __DIR__ . '/wp-kit.php';
    if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
    if ( ! function_exists( 'size_format' ) ) {
        function size_format( $bytes, $decimals = 0 ) {
            $units = array( 'TB' => 1099511627776, 'GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024, 'B' => 1 );
            foreach ( $units as $u => $mag ) { if ( (float) $bytes >= $mag ) { return number_format( $bytes / $mag, $decimals ) . ' ' . $u; } }
            return false;
        }
    }
    if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
        function wp_convert_hr_to_bytes( $value ) {
            $value = strtolower( trim( $value ) ); $bytes = (int) $value;
            if ( false !== strpos( $value, 'g' ) ) { $bytes *= 1073741824; } elseif ( false !== strpos( $value, 'm' ) ) { $bytes *= 1048576; } elseif ( false !== strpos( $value, 'k' ) ) { $bytes *= 1024; }
            return min( $bytes, PHP_INT_MAX );
        }
    }
    if ( ! function_exists( 'media_handle_upload' ) ) {
        /* The stand-in: keep the file as WordPress would, return its id. */
        function media_handle_upload( $field, $post_id, $data = array(), $overrides = array() ) {
            $f = isset( $_FILES[ $field ] ) ? $_FILES[ $field ] : null;
            if ( ! $f || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) { return new WP_Error( 'upload', 'no file' ); }
            $ext = strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) );
            $ok  = false;
            foreach ( array_keys( isset( $overrides['mimes'] ) ? $overrides['mimes'] : array() ) as $exts ) { if ( in_array( $ext, explode( '|', $exts ), true ) ) { $ok = true; } }
            if ( ! $ok ) { return new WP_Error( 'upload', 'type' ); }
            $s  = &$GLOBALS['rl_state'];
            $id = (int) $s['next_att']++;
            @mkdir( getenv( 'RL_DIR' ) . '/uploads' );
            move_uploaded_file( $f['tmp_name'], getenv( 'RL_DIR' ) . '/uploads/att' . $id . '.' . $ext );
            $GLOBALS['kit_images'][ $id ] = 'http://127.0.0.1:' . getenv( 'RL_PORT' ) . '/uploads/att' . $id . '.' . $ext;
            $GLOBALS['kit_files'][ $id ]  = $GLOBALS['kit_images'][ $id ];
            $s['stored'][] = array( 'id' => $id, 'field' => $field, 'name' => (string) $f['name'] );
            return $id;
        }
    }
}

/* ======================================================================
 * THE CHECK.
 * ==================================================================== */
$args = array_slice( $argv, 1 );
if ( ! in_array( '--run', $args, true ) ) { echo "--run drives Chrome against a local server.\n"; exit( 0 ); }
$verbose = in_array( '--verbose', $args, true );
$chrome  = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

rl_boot();
$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

function rl_png( $w, $h, $pad = 0 ) {
    $chunk = function ( $t, $d ) { return pack( 'N', strlen( $d ) ) . $t . $d . pack( 'N', crc32( $t . $d ) ); };
    $raw = str_repeat( "\0" . str_repeat( "\0\0\0\0", $w ), $h );
    return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, 6, 0, 0, 0 ) )
        . $chunk( 'IDAT', gzcompress( $raw ) ) . ( $pad ? $chunk( 'tEXt', 'x' . "\0" . str_repeat( 'a', $pad ) ) : '' ) . $chunk( 'IEND', '' );
}

$today = current_time( 'Y-m-d' );
$plus2 = date( 'Y-m-d', strtotime( $today . ' +2 days' ) );
$plus9 = date( 'Y-m-d', strtotime( $today . ' +9 days' ) );

/* Closures already on the screen, as on the site, so the announcements sit a
   screen or more below the heading. */
kit_reset();
for ( $i = 1; $i <= 8; $i++ ) {
    SFAF_Closures::save( '', 'Closed day ' . $i, date( 'Y-m-d', strtotime( $today . ' +' . ( 20 + $i * 3 ) . ' days' ) ), '', '', array() );
}
rl( count( SFAF_Closures::all() ) >= 8, 'the closures to fill the screen were not stored' );
$seed = $GLOBALS['kit_options'];

$base = sys_get_temp_dir() . '/sfaf-31421-' . getmypid();
@mkdir( $base );
$steps_js = file_get_contents( __FILE__, false, null, __COMPILER_HALT_OFFSET__ );

$widths = array( 1280, 430, 390 );
foreach ( $widths as $w ) {
    $dir = $base . '/w' . $w;
    @mkdir( $dir );
    file_put_contents( $dir . '/state.json', json_encode( array( 'options' => $seed, 'trans' => array(), 'images' => array(), 'next_att' => 900, 'stored' => array() ) ) );
    file_put_contents( $dir . '/log.txt', '' );
    file_put_contents( $dir . '/icon.png', rl_png( 64, 64 ) );
    file_put_contents( $dir . '/wideicon.png', rl_png( 80, 40 ) );
    file_put_contents( $dir . '/pic.png', rl_png( 1200, 675 ) );
    file_put_contents( $dir . '/widepic.png', rl_png( 1200, 600 ) );
    file_put_contents( $dir . '/huge.png', rl_png( 1200, 675, 1300000 ) );
    file_put_contents( $dir . '/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="#FFD900"/></svg>' );
    $port = 18000 + ( getmypid() % 900 ) * 3 + array_search( $w, $widths, true );
    $vars = array( '{{W}}' => (string) $w, '{{TODAY}}' => $today, '{{PLUS2}}' => $plus2, '{{PLUS9}}' => $plus9 );
    file_put_contents( $dir . '/drive.html', strtr( $steps_js, $vars ) );

    $env = array_merge( getenv(), array( 'RL_DIR' => $dir, 'RL_PORT' => (string) $port ) );
    $srv = proc_open( array( PHP_BINARY, '-d', 'post_max_size=1M', '-d', 'upload_max_filesize=1M', '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, __FILE__ ),
        array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $dir . '/srv.txt', 'a' ), 2 => array( 'file', $dir . '/srv.txt', 'a' ) ), $pipes, $dir, $env );
    $up = false;
    for ( $i = 0; $i < 50 && ! $up; $i++ ) { usleep( 100000 ); $up = (bool) @fsockopen( '127.0.0.1', $port, $e1, $e2, 0.2 ); }
    rl( $up, "$w: the local server did not start" );

    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --no-first-run --no-default-browser-check'
        . ' --host-resolver-rules="MAP fonts.googleapis.com ~NOTFOUND" --window-size=' . max( 600, $w + 40 ) . ',1000'
        . ' --virtual-time-budget=45000 --dump-dom "http://127.0.0.1:' . $port . '/drive.html" 2>NUL' );
    proc_terminate( $srv );
    proc_close( $srv );

    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
        rl( false, "$w: Chrome returned no probe block; server log: " . substr( (string) @file_get_contents( $dir . '/srv.txt' ), -400 ) );
        continue;
    }
    $out = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( ! is_array( $out ) ) { rl( false, "$w: the probe block is not JSON" ); continue; }
    foreach ( (array) $out['errs'] as $e ) { rl( false, "$w: the page threw: $e" ); }
    $by = array();
    foreach ( (array) $out['steps'] as $s ) {
        $by[ $s['name'] ] = $s;
        if ( $verbose ) { echo "$w " . str_pad( $s['name'], 12 ) . ' ' . json_encode( $s ) . "\n"; }
    }
    $need = function ( $name ) use ( $by, $w ) {
        if ( ! isset( $by[ $name ] ) ) { rl( false, "$w $name: the step never ran" ); return null; }
        return $by[ $name ];
    };
    /* The message: shown, in the window, inside the announcements section. */
    $said = function ( $name, $want ) use ( $need, $w ) {
        $s = $need( $name );
        if ( ! $s ) { return null; }
        rl( false !== strpos( (string) $s['notice'], $want ), "$w $name: no message saying \"$want\" (messages: \"" . $s['notice'] . '")' );
        if ( '' !== (string) $s['notice'] ) {
            rl( $s['inWindow'], "$w $name: the message is outside the window (top " . $s['noticeTop'] . ', window ' . $s['winH'] . ')' );
            rl( $s['inSection'], "$w $name: the message is not in the announcements section" );
        }
        rl( $s['scrolled'], "$w $name: the page did not land at the announcements, so the window test cannot tell" );
        return $s;
    };

    $s = $need( 'load' );
    if ( $s ) { rl( $s['form'], "$w load: no announcement form" ); rl( '' === $s['notice'], "$w load: a message on a fresh page: " . $s['notice'] ); }

    $s = $said( 'A-full', 'Announcement saved.' );
    if ( $s ) {
        rl( false !== strpos( $s['url'], 'msg=ann_saved' ), "$w A-full: the save did not redirect as saved: " . $s['url'] );
        rl( false !== strpos( $s['rows'], 'Pride week' ) && false !== strpos( $s['rows'], 'Icon, Description, Image, Link' ), "$w A-full: the row is not Pride week with Icon, Description, Image, Link: " . $s['rows'] );
        rl( '' === $s['title'], "$w A-full: the form is not ready for the next announcement" );
    }
    $s = $said( 'B-svg', 'Announcement saved.' );
    if ( $s ) { rl( false !== strpos( $s['rows'], 'Testing day' ), "$w B-svg: no Testing day row: " . $s['rows'] ); }
    $s = $said( 'C-edit', 'Announcement saved.' );
    if ( $s ) { rl( (bool) preg_match( '/Pride week, renamed Icon, Description, Image, Link/', $s['rows'] ), "$w C-edit: the renamed row does not keep its icon and picture: " . $s['rows'] ); }

    $s = $said( 'R-picture', '1200 by 675 pixels' );
    if ( $s ) {
        rl( false !== strpos( $s['notice'], 'Choose the image again.' ), "$w R-picture: the message does not say to choose the image again: " . $s['notice'] );
        rl( 'Refused one' === $s['title'] && 'Kept words.' === $s['desc'] && $plus9 === $s['start'], "$w R-picture: what was typed is gone (title \"" . $s['title'] . '", description "' . $s['desc'] . '")' );
    }
    $s = $said( 'R-title', 'Give the announcement a title.' );
    if ( $s ) { rl( 'Kept words.' === $s['desc'], "$w R-title: the description is gone" ); }
    $s = $said( 'R-icon', 'Icons must be a square PNG or SVG' );
    if ( $s ) { rl( false !== strpos( $s['notice'], 'Choose the icon again.' ), "$w R-icon: the message does not say to choose the icon again: " . $s['notice'] ); }
    $said( 'R-huge', 'larger than this site takes in one save (1 MB)' );
    $said( 'F-lost', 'The announcement was not saved. Try it again.' );

    /* What the site stored. */
    $st   = json_decode( (string) file_get_contents( $dir . '/state.json' ), true );
    $rows = isset( $st['options'][ SFAF_Announcements::OPTION ] ) ? (array) $st['options'][ SFAF_Announcements::OPTION ] : array();
    $titles = array();
    foreach ( $rows as $r ) { $titles[] = $r['title']; }
    sort( $titles );
    rl( array( 'Pride week, renamed', 'Testing day' ) === $titles, "$w: the stored announcements are not exactly the two saved: " . implode( ' | ', $titles ) );
    foreach ( $rows as $r ) {
        if ( 'Pride week, renamed' === $r['title'] ) {
            rl( $r['icon_id'] >= 900 && $r['image_id'] >= 900 && $r['icon_id'] !== $r['image_id'], "$w: the full save's icon and picture are not stored attachments (icon {$r['icon_id']}, image {$r['image_id']})" );
            rl( $today === $r['start'] && $plus2 === $r['end'] && 'https://sfaf.org/pride' === $r['link'] && 'Walk with us.' === $r['desc'], "$w: the full save's dates, link or description are wrong: " . json_encode( $r ) );
        }
        if ( 'Testing day' === $r['title'] ) {
            rl( false !== strpos( (string) $r['icon_svg'], '<svg' ) && 0 === (int) $r['icon_id'], "$w: the SVG icon is not stored as text" );
        }
    }
    $names = array();
    foreach ( (array) $st['stored'] as $a ) { $names[] = $a['field'] . ':' . $a['name']; }
    rl( array( 'ann_icon:icon.png', 'ann_image:pic.png' ) === $names, "$w: the Media Library got something other than the full save's two files: " . implode( ', ', $names ) );
}

if ( $fails ) {
    echo 'FAIL ' . count( $fails ) . ":\n  " . implode( "\n  ", $fails ) . "\n";
    exit( 1 );
}
echo 'saving an announcement at ' . implode( ', ', $widths ) . 'px: full, SVG and edit saves stored with their files and say so in the window; a wrong picture, no title, a wide icon, too large and a lost reason each say why, keep what was typed, store nothing' . "\n";
exit( 0 );

__halt_compiler();<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>drive</title>
<style>body{margin:0}iframe{border:0;display:block}</style></head><body>
<iframe id="f" width="{{W}}" height="700" src="about:blank"></iframe>
<script>
(function () {
  var errs = [], steps = [];
  var f = document.getElementById('f');
  var URL0 = '/wp-admin/edit.php?post_type=uc_event&page=uc-closures';
  function win() { return f.contentWindow; }
  function doc() { return f.contentDocument; }
  function go(url) { return new Promise(function (ok) { f.onload = function () { setTimeout(ok, 300); }; f.src = url; }); }
  function submitted() { return new Promise(function (ok) { f.onload = function () { setTimeout(ok, 300); }; }); }
  function bytes(name) { return fetch('/fixture/' + name).then(function (r) { return r.arrayBuffer(); }); }
  function form() { return doc().getElementById('uc_ann_title') ? doc().getElementById('uc_ann_title').form : null; }
  function put(id, files) {
    var w = win(), dt = new w.DataTransfer();
    files.forEach(function (x) { dt.items.add(new w.File([x.buf], x.name, { type: x.type })); });
    doc().getElementById(id).files = dt.files;
  }
  function fill(v) {
    var d = doc();
    d.getElementById('uc_ann_title').value = v.title;
    d.getElementById('uc_ann_start').value = v.start || '';
    d.getElementById('uc_ann_end').value = v.end || '';
    d.getElementById('uc_ann_desc').value = v.desc || '';
    d.getElementById('uc_ann_link').value = v.link || '';
  }
  function press() {
    var p = submitted();
    form().querySelector('button[type="submit"]').click();
    return p;
  }
  function look(name) {
    var d = doc(), w = win();
    var anchor = d.getElementById('uc-announcements');
    var shown = [].filter.call(d.querySelectorAll('div.notice'), function (n) { return n.offsetHeight > 0; });
    var text = shown.map(function (n) { return n.textContent.trim(); }).join(' | ');
    var r = shown.length ? shown[0].getBoundingClientRect() : null;
    var h1 = d.querySelector('.wrap h1');
    var rows = [].map.call(d.querySelectorAll('.uc-admin-table tbody tr'), function (tr) { return tr.textContent.replace(/\s+/g, ' ').trim(); }).join(' || ');
    var fm = form();
    setTimeout(dump, 0);
    steps.push({
      name: name, url: w.location.href, notice: text,
      inWindow: !!r && r.top >= 0 && r.bottom <= w.innerHeight, noticeTop: r ? Math.round(r.top) : null, winH: w.innerHeight,
      inSection: !!(shown.length && anchor && (anchor.compareDocumentPosition(shown[0]) & 4) && (!fm || (shown[0].compareDocumentPosition(fm) & 4))),
      scrolled: !!h1 && h1.getBoundingClientRect().bottom < 0,
      form: !!fm, rows: rows,
      title: fm ? d.getElementById('uc_ann_title').value : null,
      desc: fm ? d.getElementById('uc_ann_desc').value : null,
      start: fm ? d.getElementById('uc_ann_start').value : null
    });
  }
  window.addEventListener('error', function (e) { errs.push(String(e.message)); });
  function dump() {
    var p = document.getElementById('out');
    if (!p) { p = document.createElement('pre'); p.id = 'out'; document.body.appendChild(p); }
    p.textContent = JSON.stringify({ steps: steps, errs: errs });
  }

  var files = {};
  Promise.all(['icon.png', 'wideicon.png', 'pic.png', 'widepic.png', 'huge.png', 'icon.svg'].map(function (n) {
    return bytes(n).then(function (b) { files[n] = b; });
  })).then(function () {
    return go(URL0 + '#uc-announcements');
  }).then(function () {
    win().addEventListener('error', function (e) { errs.push(String(e.message)); });
    look('load');
    fill({ title: 'Pride week', start: '{{TODAY}}', end: '{{PLUS2}}', desc: 'Walk with us.', link: 'https://sfaf.org/pride' });
    put('uc_ann_icon', [{ buf: files['icon.png'], name: 'icon.png', type: 'image/png' }]);
    put('uc_ann_image', [{ buf: files['pic.png'], name: 'pic.png', type: 'image/png' }]);
    return press();
  }).then(function () {
    look('A-full');
    fill({ title: 'Testing day', start: '{{PLUS9}}' });
    put('uc_ann_icon', [{ buf: files['icon.svg'], name: 'icon.svg', type: 'image/svg+xml' }]);
    return press();
  }).then(function () {
    look('B-svg');
    var edit = [].filter.call(doc().querySelectorAll('.uc-admin-table a.button'), function (a) {
      return /Edit/.test(a.textContent) && /Pride week/.test(a.closest('tr').textContent);
    })[0];
    return go(edit ? edit.getAttribute('href').replace(/^https:\/\/resources\.sfaf\.org/, '').replace(/^([^?]*\?)(.*)$/, function (m, a, b) { return a + b.replace(/\?/g, '&'); }) : URL0);
  }).then(function () {
    doc().getElementById('uc_ann_title').value = 'Pride week, renamed';
    return press();
  }).then(function () {
    look('C-edit');
    return go(URL0);
  }).then(function () {
    fill({ title: 'Refused one', start: '{{PLUS9}}', desc: 'Kept words.' });
    put('uc_ann_image', [{ buf: files['widepic.png'], name: 'widepic.png', type: 'image/png' }]);
    return press();
  }).then(function () {
    look('R-picture');
    return go(URL0);
  }).then(function () {
    fill({ title: '', start: '{{PLUS9}}', desc: 'Kept words.' });
    doc().getElementById('uc_ann_title').removeAttribute('required');
    return press();
  }).then(function () {
    look('R-title');
    return go(URL0);
  }).then(function () {
    fill({ title: 'Wide icon', start: '{{PLUS9}}' });
    put('uc_ann_icon', [{ buf: files['wideicon.png'], name: 'wideicon.png', type: 'image/png' }]);
    return press();
  }).then(function () {
    look('R-icon');
    return go(URL0);
  }).then(function () {
    fill({ title: 'Too large', start: '{{PLUS9}}' });
    put('uc_ann_image', [{ buf: files['huge.png'], name: 'huge.png', type: 'image/png' }]);
    return press();
  }).then(function () {
    look('R-huge');
    return go(URL0 + '&msg=ann_failed&from=lost#uc-announcements');
  }).then(function () {
    look('F-lost');
  }).catch(function (e) {
    errs.push('driver: ' + (e && e.message ? e.message : String(e)));
  }).then(dump);
})();
</script></body></html>
