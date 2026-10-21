<?php
/**
 * A CACHED embed.js CAN NO LONGER HOLD A HOST PAGE ON AN OLD CALENDAR (3.112.1).
 *
 *     php .claude/embed-loader-live.php --run
 *
 * After 3.112.0 was installed, sfaf.org/calendar showed "The events calendar
 * could not be loaded just now" until the cache was bypassed, and the leading
 * explanation was a cached 3.111.0 embed.js. From 3.112.1 embed.js is a loader
 * with no release logic in it; it asks the embed route for the version and
 * loads embed-app.js?ver=<it>.
 *
 * REAL: Chrome, its disk cache in a profile kept between loads, HTTP on two
 * local origins (a host page and the calendar site, so every request crosses
 * origins as it does from sfaf.org), the shipped loader and app, the route's
 * answers from SFAF_Embed::handle_request() through wp-kit.php, calendar.css.
 * The host pages hold the snippet as the generator writes it, unpinned and
 * pinned (?ver=2.9.0, as before 2.10.1), each with a plain block and an
 * isolated one.
 *
 * SINCE 3.112.2 the loader reads public/js/embed-version.js?t=<5-minute bucket>,
 * a static file, and B's second load is five minutes later on the page's clock.
 *
 * WHAT "THE PREVIOUS RELEASE" IS. Its embed.js is served with a year's
 * max-age, so Chrome keeps it, and the site is then moved to this release:
 *
 *   A  3.112.0's own embed.js (the whole calendar) is the cached file. The
 *      site moves on. That copy is still what the page runs, because nothing
 *      a server sends can replace a file a browser chose to keep: this
 *      transition happens once. What is asserted is that it still draws
 *      against this release's route.
 *   B  A loader-era release is the cached one: the loader, and its app at
 *      ?ver=3.112.0, both kept for a year. The site moves to this release.
 *      The loader is NOT fetched again, and the calendar that draws is this
 *      release's: window.sfafCalendarEmbed.version is SFAF_VERSION, the app
 *      was fetched at ?ver=SFAF_VERSION, nothing warns that it is stale.
 *      This is the guarantee from now on.
 *
 * Every load: every block draws (in its shadow root when isolated), no error
 * box, no script error, and the version is asked once per page, never in a
 * loop.
 */

/* ======================================================================
 * THE SERVERS. The same file answers both origins under `php -S`.
 * ==================================================================== */
if ( 'cli-server' === PHP_SAPI ) {
    $dir   = getenv( 'EL_DIR' );
    $state = json_decode( (string) @file_get_contents( $dir . '/state.json' ), true );
    $role  = getenv( 'EL_ROLE' );
    $uri   = $_SERVER['REQUEST_URI'];
    $path  = parse_url( $uri, PHP_URL_PATH );
    parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $q );
    file_put_contents( $dir . '/log.txt', $role . ' ' . $uri . "\n", FILE_APPEND );
    $send = function ( $type, $body, $cache ) {
        header( 'Content-Type: ' . $type );
        if ( $cache ) { header( 'Cache-Control: ' . $cache ); }
        header( 'Access-Control-Allow-Origin: *' );
        echo $body;
        return true;
    };
    if ( 'host' === $role ) {
        $f = $dir . '/' . basename( $path );
        if ( is_file( $f ) ) { return $send( 'text/html; charset=utf-8', file_get_contents( $f ), 'no-store' ); }
        http_response_code( 404 );
        return true;
    }
    $root = dirname( __DIR__ );
    $year = 'public, max-age=31536000';
    if ( '/wp-content/plugins/sfaf-calendar/public/js/embed.js' === $path ) {
        if ( 'old' === $state['phase'] ) { return $send( 'application/javascript', file_get_contents( $dir . '/embed-3.112.0.js' ), $year ); }
        if ( 'prev' === $state['phase'] ) { return $send( 'application/javascript', file_get_contents( $root . '/public/js/embed.js' ), $year ); }
        return $send( 'application/javascript', file_get_contents( $root . '/public/js/embed.js' ), 'public, max-age=300' );
    }
    if ( '/wp-content/plugins/sfaf-calendar/public/js/embed-version.js' === $path ) {
        /* What the loader reads since 3.112.2, kept five minutes as the
           shipped .htaccess asks; the previous release named 3.112.0. */
        $v = ( 'current' === $state['phase'] ) ? $state['current'] : '3.112.0';
        return $send( 'application/javascript', "window.sfafCalendarEmbedVersion = '" . $v . "';\n", 'public, max-age=300' );
    }
    if ( '/wp-content/plugins/sfaf-calendar/public/js/embed-app.js' === $path ) {
        $app = file_get_contents( $root . '/public/js/embed-app.js' );
        $ver = isset( $q['ver'] ) ? (string) $q['ver'] : '';
        if ( '' !== $ver && $ver !== $state['current'] ) {
            /* The previous release's app: the same code, saying it is that release. */
            $app = preg_replace( "/var EMBED_JS_VERSION = '[^']+';/", "var EMBED_JS_VERSION = '" . addslashes( $ver ) . "';", $app );
        }
        return $send( 'application/javascript', $app, '' !== $ver ? $year : 'public, max-age=300' );
    }
    if ( preg_match( '#^/wp-content/plugins/sfaf-calendar/(public/(css|images)/[A-Za-z0-9._/-]+)$#', $path, $m ) && is_file( $root . '/' . $m[1] ) ) {
        $ext = pathinfo( $m[1], PATHINFO_EXTENSION );
        $types = array( 'css' => 'text/css', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'jpg' => 'image/jpeg', 'gif' => 'image/gif' );
        return $send( isset( $types[ $ext ] ) ? $types[ $ext ] : 'application/octet-stream', file_get_contents( $root . '/' . $m[1] ), 'public, max-age=300' );
    }
    $route = ( '/wp-json/sfaf-calendar/v1/embed' === $path ) ? true : ( isset( $q['rest_route'] ) && '/sfaf-calendar/v1/embed' === $q['rest_route'] );
    if ( $route ) {
        if ( 'current' !== $state['phase'] && ! empty( $q['embed_version'] ) ) {
            /* The previous release, asked by its own loader. */
            return $send( 'application/json', json_encode( array( 'embed_version' => '3.112.0' ) ), 'no-store' );
        }
        el_boot();
        $req = new WP_REST_Request( $q );
        $res = $GLOBALS['el_embed']->handle_request( $req );
        $data = $res->get_data();
        if ( 'current' !== $state['phase'] && isset( $data['js_version'] ) ) { $data['js_version'] = '3.112.0'; }
        foreach ( $res->get_headers() as $k => $v ) { header( $k . ': ' . $v ); }
        /* The previous release's payloads are not kept: the route allows a
           browser a minute with an unnarrowed one, and B reloads well inside
           it, so the current load would read the previous release's version. */
        if ( 'current' !== $state['phase'] ) { header( 'Cache-Control: no-store' ); }
        header( 'Content-Type: application/json' );
        echo json_encode( $data );
        return true;
    }
    http_response_code( 404 );
    return true;
}

/** The plugin, with seven events, and the REST classes the route uses. */
function el_boot() {
    require __DIR__ . '/wp-kit.php';
    if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
    if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1 ); } }
    if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
    if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }
    if ( ! function_exists( 'get_http_origin' ) ) { function get_http_origin() { return isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : ''; } }
    if ( ! class_exists( 'WP_REST_Request' ) ) {
        eval( 'class WP_REST_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } function get_route() { return "/sfaf-calendar/v1/embed"; } function get_method() { return "GET"; } }' );
        eval( 'class WP_REST_Response { private $d; private $h = array(); function __construct( $d = null, $s = 200 ) { $this->d = $d; } function header( $k, $v ) { $this->h[ $k ] = $v; } function get_headers() { return $this->h; } function set_headers( $h ) { $this->h = $h; } function get_data() { return $this->d; } }' );
    }
    $GLOBALS['kit_query'] = $GLOBALS['kit_get_posts'] = function ( $a ) {
        $out = array();
        foreach ( $GLOBALS['kit_posts'] as $p ) {
            if ( 'uc_event' === $p->post_type && 'publish' === $p->post_status ) { $out[] = $p; }
        }
        usort( $out, function ( $x, $y ) { return strcmp( (string) get_post_meta( $x->ID, '_uc_event_date', true ), (string) get_post_meta( $y->ID, '_uc_event_date', true ) ); } );
        if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { $out = wp_list_pluck( $out, 'ID' ); }
        $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
        return $per > 0 ? array_slice( $out, 0, $per ) : $out;
    };
    $GLOBALS['kit_loop'] = true;
    foreach ( array( 'Coffee social', 'Harm reduction drop-in', 'Autumn walk', 'Queer book club', 'Strut open house', 'Cycle to Zero ride briefing', 'Volunteer orientation' ) as $i => $t ) {
        $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $t, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
        foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+' . ( $i + 1 ) . ' days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
        $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 11 );
    }
    $GLOBALS['el_embed'] = new SFAF_Embed( new SFAF_Shortcodes() );
}

/* ======================================================================
 * THE CHECK.
 * ==================================================================== */
$args = array_slice( $argv, 1 );
if ( ! in_array( '--run', $args, true ) ) { echo "--run starts two local servers and drives Chrome.\n"; exit( 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$fails = array();
function el( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$root = dirname( __DIR__ );
preg_match( "/define\\( 'SFAF_VERSION', '([^']+)' \\);/", file_get_contents( $root . '/sfaf-calendar.php' ), $vm );
$current = $vm[1];
$dir = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sfaf-embed-loader-' . getmypid();
@mkdir( $dir, 0777, true );

/* 3.112.0's embed.js, the whole calendar, from the release commit. */
$old = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show d908b16:public/js/embed.js' );
if ( ! $old || false === strpos( $old, "EMBED_JS_VERSION = '3.112.0'" ) ) { echo "could not read 3.112.0's embed.js from git\n"; exit( 1 ); }
file_put_contents( $dir . '/embed-3.112.0.js', $old );

$port_host = 18400 + ( getmypid() % 500 );
$port_cal  = $port_host + 600;
$cal       = 'http://127.0.0.1:' . $port_cal;

$probe = <<<'JS'
window.__warn = []; window.__errs = [];
(function () {
  var w = console.warn; console.warn = function () { window.__warn.push(Array.prototype.join.call(arguments, ' ')); return w.apply(console, arguments); };
  window.addEventListener('error', function (e) { window.__errs.push(String(e.message)); });
  var tries = 0;
  function drawn(b) {
    var scope = b.getAttribute('data-isolate') === 'yes' ? b.shadowRoot : b;
    return !!(scope && scope.querySelector('.uc-calendar, .uc-upcoming-widget, .uc-sidebar') && scope.querySelector('.uc-event-card, .uc-sidebar-row, .uc-day-event'));
  }
  (function wait() {
    var blocks = Array.prototype.slice.call(document.querySelectorAll('[data-sfaf-calendar]'));
    var all = blocks.length && blocks.every(drawn);
    if (!all && tries++ < 150) { setTimeout(wait, 100); return; }
    setTimeout(function () {
      var out = {
        blocks: blocks.map(function (b) { var s = b.getAttribute('data-isolate') === 'yes' ? b.shadowRoot : b;
          return { isolated: b.getAttribute('data-isolate') === 'yes', drawn: drawn(b), shadow: !!b.shadowRoot,
            error: !!(s && s.querySelector('.sfaf-embed-error')) }; }),
        version: window.sfafCalendarEmbed ? (window.sfafCalendarEmbed.version || null) : 'none',
        warn: window.__warn, errs: window.__errs
      };
      var p = document.createElement('pre'); p.id = 'out'; p.textContent = JSON.stringify(out); document.body.appendChild(p);
    }, 400);
  })();
})();
JS;
foreach ( array( 'plain' => '', 'pinned' => '?ver=2.9.0' ) as $name => $pin ) {
    $snippet = '<div class="sfaf-calendar-embed" data-sfaf-calendar data-view="list" data-per-page="4"></div>' . "\n"
        . '<script src="' . $cal . '/wp-content/plugins/sfaf-calendar/public/js/embed.js' . $pin . '" async></script>' . "\n"
        . '<div class="sfaf-calendar-embed" data-sfaf-calendar data-view="list" data-per-page="4" data-isolate="yes"></div>' . "\n"
        . '<script src="' . $cal . '/wp-content/plugins/sfaf-calendar/public/js/embed.js' . $pin . '" async></script>';
    file_put_contents( $dir . '/host-' . $name . '.tpl', '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Host</title><script>{{CLOCK}}' . $probe . '</script></head><body><h1>A host page</h1>' . $snippet . '</body></html>' );
}

/* Two servers, one per origin. */
$servers = array();
foreach ( array( 'host' => $port_host, 'cal' => $port_cal ) as $role => $port ) {
    $env = array_merge( getenv(), array( 'EL_DIR' => $dir, 'EL_ROLE' => $role ) );
    $servers[ $role ] = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, __FILE__ ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $dir . '/srv-' . $role . '.txt', 'a' ), 2 => array( 'file', $dir . '/srv-' . $role . '.txt', 'a' ) ), $pipes, $dir, $env );
}
$up = false;
for ( $i = 0; $i < 50 && ! $up; $i++ ) {
    usleep( 100000 );
    $up = @fsockopen( '127.0.0.1', $port_cal, $e1, $e2, 0.2 ) && @fsockopen( '127.0.0.1', $port_host, $e1, $e2, 0.2 );
}
el( $up, 'the two local servers did not start' );

$set = function ( $phase ) use ( $dir, $current ) {
    file_put_contents( $dir . '/state.json', json_encode( array( 'phase' => $phase, 'current' => $current ) ) );
    file_put_contents( $dir . '/log.txt', '' );
};
$load = function ( $profile, $page, $skew_ms = 0 ) use ( $chrome, $dir, $port_host ) {
    /* The page's clock, moved on by $skew_ms: the loader's five-minute bucket
       is read from Date.now(), so this is the time passing between loads. */
    $clock = $skew_ms ? '(function(){var n=Date.now;Date.now=function(){return n()+' . (int) $skew_ms . ';};})();' : '';
    file_put_contents( $dir . '/host-' . $page . '.html', str_replace( '{{CLOCK}}', $clock, file_get_contents( $dir . '/host-' . $page . '.tpl' ) ) );
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --no-first-run --no-default-browser-check --user-data-dir="' . $dir . '/profile-' . $profile . '"'
        . ' --window-size=1000,1400 --virtual-time-budget=20000 --dump-dom "http://127.0.0.1:' . $port_host . '/host-' . $page . '.html" 2>NUL' );
    $log = (string) @file_get_contents( $dir . '/log.txt' );
    file_put_contents( $dir . '/log.txt', '' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { return array( null, $log ); }
    return array( json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true ), $log );
};
$drew = function ( $label, $g ) {
    if ( ! $g ) { el( false, "$label: Chrome returned no probe block" ); return; }
    el( 2 === count( $g['blocks'] ), "$label: " . count( $g['blocks'] ) . ' blocks on the page, not 2' );
    foreach ( $g['blocks'] as $i => $b ) {
        $what = $b['isolated'] ? 'the isolated block' : 'the plain block';
        el( $b['drawn'], "$label: $what did not draw" );
        el( ! $b['error'], "$label: $what shows the could-not-be-loaded box" );
        el( $b['isolated'] === $b['shadow'], "$label: $what is " . ( $b['shadow'] ? '' : 'not ' ) . 'in a shadow root' );
    }
    el( ! $g['errs'], "$label threw: " . implode( '; ', (array) $g['errs'] ) );
};
$count = function ( $log, $needle ) { return substr_count( $log, $needle ); };
$report = array();

foreach ( array( 'plain', 'pinned' ) as $page ) {
    /* ---- A: 3.112.0's whole-calendar embed.js is the cached file. ---- */
    $set( 'old' );
    list( $g, $log ) = $load( "a-$page", $page );
    $drew( "A $page, on 3.112.0", $g );
    el( $count( $log, '/public/js/embed.js' ) >= 1, "A $page: the first load never fetched embed.js, so nothing was cached" );
    $set( 'current' );
    list( $g, $log ) = $load( "a-$page", $page );
    $drew( "A $page, the site on $current with 3.112.0's embed.js still cached", $g );
    $refetched = $count( $log, '/public/js/embed.js' );
    el( 0 === $refetched, "A $page: Chrome fetched embed.js again, so this load did not test a cached copy" );
    el( $g && 'none' !== $g['version'] && null === $g['version'], "A $page: expected 3.112.0's own script to be running from cache, found " . json_encode( $g ? $g['version'] : null ) );
    $report[] = "A $page: 3.112.0's cached embed.js still draws both blocks against the $current route.";

    /* ---- B: a loader-era release is the cached one. ---- */
    $set( 'prev' );
    list( $g, $log ) = $load( "b-$page", $page );
    $drew( "B $page, on the previous release", $g );
    el( $g && '3.112.0' === $g['version'], "B $page: the previous release's app did not run first, found " . json_encode( $g ? $g['version'] : null ) );
    el( 1 === $count( $log, 'embed-app.js?ver=3.112.0' ), "B $page: the previous app was fetched " . $count( $log, 'embed-app.js?ver=3.112.0' ) . ' times on its first load' );
    $set( 'current' );
    /* Five minutes later: the next bucket, so the version file is asked anew. */
    list( $g, $log ) = $load( "b-$page", $page, 300000 );
    $drew( "B $page, the site on $current with the previous loader cached", $g );
    el( 0 === $count( $log, '/public/js/embed.js' ), "B $page: Chrome fetched the loader again, so this load did not test a cached loader" );
    el( 0 === $count( $log, 'embed-app.js?ver=3.112.0' ), "B $page: the previous release's app was asked for again" );
    el( 1 === $count( $log, 'embed-app.js?ver=' . $current ), "B $page: embed-app.js?ver=$current was fetched " . $count( $log, 'embed-app.js?ver=' . $current ) . ' times, not once' );
    el( $g && $current === $g['version'], "B $page: the calendar that drew is " . json_encode( $g ? $g['version'] : null ) . ", not $current" );
    el( $g && ! $g['warn'], "B $page: a stale-script warning: " . json_encode( $g ? $g['warn'] : null ) );
    /* Asked once per page, however many snippets the page holds: no loop. */
    el( 1 === $count( $log, 'embed-version.js?t=' ), "B $page: the version file was asked " . $count( $log, 'embed-version.js?t=' ) . ' times on one page load' );
    /* And PHP was asked nothing but the calendar's own payloads. */
    el( 0 === $count( $log, 'embed_version' ), "B $page: the loader asked the embed route for the version" );
    $report[] = "B $page: the cached loader loaded embed-app.js?ver=$current; that release drew both blocks, unwarned.";
}

foreach ( $servers as $s ) { proc_terminate( $s ); proc_close( $s ); }
/* The profiles are a few MB each; leave nothing behind. */
shell_exec( 'cmd /c rmdir /s /q "' . str_replace( '/', '\\', $dir ) . '" 2>NUL' );

echo "\nThe embed loader against a cached previous release, in Chrome over HTTP\n" . str_repeat( '=', 72 ) . "\n";
foreach ( $report as $r ) { echo $r . "\n"; }
if ( $fails ) { echo "\n" . count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "\nA cached embed.js no longer holds a host page on an old calendar, pinned or not, isolated or not.\n";
exit( 0 );
