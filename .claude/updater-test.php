<?php
/**
 * THE UPDATER, RUN RATHER THAN READ.
 *
 *     php .claude/updater-test.php --self-test
 *     php .claude/updater-test.php
 *
 * WHY THIS EXISTS. 3.72.0 was released correctly and the site did not offer it.
 * Every static question passed: the release was real, the tag was right, the
 * asset was named correctly, `asset_url()` accepted it, and `inject()` would
 * have offered it. What was wrong was that `inject()` never saw any of that,
 * because `latest()` answered from a twelve-hour cache and **nothing in the
 * codebase ever passed the `$force` argument that exists to bypass it**.
 *
 * A DEAD PARAMETER IS INVISIBLE TO EVERY CHECK THIS PROJECT HAD. The linter
 * proves it parses. The callable audit proves the arity matches. Neither can
 * ask whether anybody ever uses it, and "the feature works but nothing reaches
 * it" is the exact shape of the 3.64.1 series-control defect recorded in
 * PROJECT.md 7. So assertion 4 below asks that question directly.
 *
 * WHAT IS STUBBED CANNOT UPHOLD THE RULE UNDER TEST. WordPress is stubbed and
 * the network is stubbed; `SFAF_Updater` is the real file, loaded from source.
 * The transients are a real array that this file reads back afterwards, so
 * "the cache was cleared" is observed rather than asserted about the code.
 */

$self = in_array( '--self-test', array_slice( $argv, 1 ), true );
$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SFAF_VERSION', '3.71.0' );
define( 'SFAF_PLUGIN_DIR', $root . '/' );

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Optional arguments included, for the reason the
 * email render test gives: a stub taking fewer arguments is one the callable
 * audit reads as the definition.
 * ------------------------------------------------------------------------ */
$GLOBALS['transients'] = array();
$GLOBALS['log']        = array();
$GLOBALS['http']       = array();
$GLOBALS['remote']     = null;   // what wp_remote_get() will answer with

function get_site_transient( $key ) {
    $GLOBALS['log'][] = "get:$key";
    return array_key_exists( $key, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $key ] : false;
}
function set_site_transient( $key, $value, $ttl = 0 ) {
    $GLOBALS['log'][] = "set:$key";
    $GLOBALS['transients'][ $key ] = $value;
    return true;
}
function delete_site_transient( $key ) {
    $GLOBALS['log'][] = "del:$key";
    unset( $GLOBALS['transients'][ $key ] );
    return true;
}
function wp_remote_get( $url, $args = array() ) {
    $GLOBALS['http'][] = $url;
    return $GLOBALS['remote'];
}
function is_wp_error( $thing ) { return ( $thing instanceof WP_Error ); }
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; }
function wp_update_plugins( $extra_stats = array() ) { $GLOBALS['log'][] = 'wp_update_plugins'; }
function plugin_basename( $file ) { return 'sfaf-calendar/sfaf-calendar.php'; }
function get_bloginfo( $show = '', $filter = 'raw' ) { return '6.5'; }
function current_user_can( $cap, ...$args ) { return ! empty( $GLOBALS['can'] ); }
function admin_url( $path = '', $scheme = 'admin' ) { return 'https://example.org/wp-admin/' . ltrim( $path, '/' ); }
function self_admin_url( $path = '', $scheme = 'admin' ) { return admin_url( $path, $scheme ); }
function add_query_arg( $key, $value = '', $url = '' ) {
    if ( is_array( $key ) ) {
        $url = ( '' !== $value ) ? $value : '';
        $q   = http_build_query( $key );
    } else {
        $q = rawurlencode( $key ) . '=' . rawurlencode( $value );
    }
    return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $q;
}
function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) { return $url . '&' . $name . '=abc123'; }
function check_admin_referer( $action = -1, $name = '_wpnonce' ) { return 1; }
function wp_die( $m = '', $t = '', $a = array() ) { throw new RuntimeException( 'wp_die: ' . $m ); }
function wp_safe_redirect( $location, $status = 302, $x = '' ) { $GLOBALS['log'][] = 'redirect'; return true; }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function __( $t, $d = '' ) { return $t; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_kses_post( $v ) { return $v; }
function wpautop( $v, $br = true ) { return '<p>' . $v . '</p>'; }
function add_filter( $h, $c, $p = 10, $n = 1 ) { $GLOBALS['hooks'][] = $h; return true; }
function add_action( $h, $c, $p = 10, $n = 1 ) { $GLOBALS['hooks'][] = $h; return true; }
class WP_Error { public $msg; public function __construct( $c = '', $m = '' ) { $this->msg = $m; } }

/* ---------------------------------------------------------------------------
 * The source, optionally with a fault planted in it.
 * ------------------------------------------------------------------------ */
$SRC = $root . '/includes/class-sfaf-updater.php';
$src = file_get_contents( $SRC );
if ( false === $src ) {
    echo "could not read class-sfaf-updater.php\n";
    exit( 1 );
}

/**
 * The holes. Each is a real regression somebody could introduce, and every one
 * must be caught or this file is not evidence.
 */
$PLANTS = array(
    'the forced check stops forcing, which is the 3.72.0 fault exactly'
        => array( 'self::latest( true )', 'self::latest()' ),
    'the forced check stops clearing WordPress\'s cache twice over'
        => array( "wp_update_plugins();\n\n        return \$release;", "\n        return \$release;" ),
    "the forced check stops clearing WordPress's, so its check short-circuits"
        => array( "delete_site_transient( 'update_plugins' );", "// not cleared" ),
    'forget() goes back to update-only, so installing a zip leaves it stale'
        => array( "if ( 'update' !== \$action && 'install' !== \$action ) {", "if ( 'update' !== \$action ) {" ),
    'asset_url() starts accepting the source zipball'
        => array( "/^sfaf-calendar-\\d+\\.\\d+\\.\\d+\\.zip$/", '/\\.zip$/' ),
    'the action link loses its capability check'
        => array( "if ( ! current_user_can( 'update_plugins' ) ) {\n            return \$links;", "if ( false ) {\n            return \$links;" ),
);

$plant_name = '';
if ( $self ) {
    // Run once per plant, each in a fresh process, so one plant cannot mask
    // another and a fatal in one does not take the run with it.
    $failures = array();
    foreach ( array_keys( $PLANTS ) as $name ) {
        $out  = array();
        $code = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ )
            . ' --plant=' . escapeshellarg( $name ) . ' 2>&1', $out, $code );
        $caught = ( 0 !== $code );
        printf( "  %-62s %s\n", substr( $name, 0, 62 ), $caught ? 'caught' : 'MISSED' );
        if ( ! $caught ) { $failures[] = $name; }
    }
    // And the real source must pass, or the plants prove nothing.
    $out = array(); $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $out, $code );
    printf( "  %-62s %s\n", 'the real source, unmodified', ( 0 === $code ) ? 'passes' : 'FAILS' );
    if ( 0 !== $code ) { $failures[] = 'false positive on the real source'; echo implode( "\n", $out ) . "\n"; }

    echo "\n";
    if ( $failures ) {
        echo "self-test FAILED: " . implode( '; ', $failures ) . "\n";
        exit( 1 );
    }
    echo "self-test passed: every planted hole caught, no false positive.\n";
    exit( 0 );
}

foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( 0 === strpos( $arg, '--plant=' ) ) { $plant_name = substr( $arg, 8 ); }
}
if ( '' !== $plant_name ) {
    if ( ! isset( $PLANTS[ $plant_name ] ) ) { echo "no such plant\n"; exit( 1 ); }
    list( $from, $to ) = $PLANTS[ $plant_name ];
    if ( false === strpos( $src, $from ) ) {
        echo "the plant's anchor is not in the source: $plant_name\n";
        exit( 1 );
    }
    $src = str_replace( $from, $to, $src );
}

/* Loaded from a string so a plant never touches the working tree. The class is
   the real one either way: nothing here rewrites it except a named plant. */
eval( '?>' . $src );

/* ------------------------------------------------------------------------- */
$fails = array();
function expect( $what, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $what, var_export( $got, true ), var_export( $want, true ) );
    }
}
function body_ok( $version = '3.72.0', $asset = null ) {
    $asset = ( null === $asset ) ? "sfaf-calendar-$version.zip" : $asset;
    return array(
        'response' => array( 'code' => 200 ),
        'body'     => json_encode( array(
            'tag_name'     => $version,
            'draft'        => false,
            'prerelease'   => false,
            'html_url'     => 'https://github.com/x/y/releases/tag/' . $version,
            'body'         => 'notes',
            'published_at' => '2026-09-10T16:29:15Z',
            'assets'       => array( array(
                'name'                 => $asset,
                'state'                => 'uploaded',
                'browser_download_url' => 'https://github.com/x/y/releases/download/' . $version . '/' . $asset,
            ) ),
        ) ),
    );
}
function reset_world() {
    $GLOBALS['transients'] = array();
    $GLOBALS['log']        = array();
    $GLOBALS['http']       = array();
    $GLOBALS['hooks']      = array();
    $GLOBALS['can']        = true;
}

/* ===========================================================================
 * 1. asset_url() takes the built zip and refuses everything else.
 * ======================================================================== */
$probe = new ReflectionMethod( 'SFAF_Updater', 'asset_url' );
$probe->setAccessible( true );
function asset_of( $probe, $assets ) {
    return $probe->invoke( null, array( 'assets' => $assets ) );
}
expect( 'the built asset is accepted',
    asset_of( $probe, array( array( 'name' => 'sfaf-calendar-3.72.0.zip', 'browser_download_url' => 'U' ) ) ), 'U' );
expect( 'the source zipball is refused',
    asset_of( $probe, array( array( 'name' => '3.72.0.zip', 'browser_download_url' => 'U' ) ) ), '' );
expect( 'a differently named zip is refused',
    asset_of( $probe, array( array( 'name' => 'sfaf-calendar.zip', 'browser_download_url' => 'U' ) ) ), '' );
expect( 'a four-part version is refused',
    asset_of( $probe, array( array( 'name' => 'sfaf-calendar-3.72.0.1.zip', 'browser_download_url' => 'U' ) ) ), '' );
expect( 'an asset with no download url is refused',
    asset_of( $probe, array( array( 'name' => 'sfaf-calendar-3.72.0.zip' ) ) ), '' );
expect( 'no assets at all is refused', asset_of( $probe, array() ), '' );

/* ===========================================================================
 * 2. THE CACHE IS REAL, AND latest() DOES ANSWER FROM IT.
 *
 * This is the behaviour that caused the incident, and it is correct behaviour:
 * the point is that it exists and that only force gets past it.
 * ======================================================================== */
reset_world();
$GLOBALS['remote'] = body_ok( '3.72.0' );
$first = SFAF_Updater::latest();
expect( 'an uncached check fetches', count( $GLOBALS['http'] ), 1 );
expect( 'and returns the released version', $first['version'], '3.72.0' );

// Now poison the cache the way a release published later would find it.
$GLOBALS['transients']['sfaf_updater_release'] = array( 'version' => '3.71.0', 'zip' => 'Z', 'url' => '', 'notes' => '', 'date' => '' );
$GLOBALS['http'] = array();
$stale = SFAF_Updater::latest();
expect( 'an unforced check does NOT fetch', count( $GLOBALS['http'] ), 0 );
expect( 'and hands back the stale version', $stale['version'], '3.71.0' );

$GLOBALS['http'] = array();
$forced = SFAF_Updater::latest( true );
expect( 'a FORCED check fetches', count( $GLOBALS['http'] ), 1 );
expect( 'and gets the real one', $forced['version'], '3.72.0' );

/* ===========================================================================
 * 3. run_check() clears both caches, in the order that makes step 4 run.
 * ======================================================================== */
reset_world();
$GLOBALS['transients']['sfaf_updater_release'] = array( 'version' => '3.71.0' );
$GLOBALS['transients']['update_plugins']       = (object) array( 'last_checked' => time() );
$GLOBALS['remote'] = body_ok( '3.72.0' );

$out = SFAF_Updater::run_check();

expect( 'the forced check found the release', is_array( $out ) ? $out['version'] : null, '3.72.0' );
expect( 'it went to the network exactly once', count( $GLOBALS['http'] ), 1 );

$log = $GLOBALS['log'];
$i_read  = array_search( 'get:sfaf_updater_release', $log, true );
$i_fetch = array_search( 'set:sfaf_updater_release', $log, true );
$i_wp    = array_search( 'del:update_plugins', $log, true );
$i_run   = array_search( 'wp_update_plugins', $log, true );

/* OUR CACHE IS REPLACED, NOT DELETED, and the distinction is the whole reason
   the delete came out of run_check(). A delete in front of the fetch makes an
   UNFORCED latest() fetch too, so it masks the loss of the force argument, and
   the plant for that went uncaught until this was rewritten. What must be true
   is that the stale value was never READ and that the fresh one is in place. */
expect( 'the stale cache was never read', is_int( $i_read ), false );
expect( "WordPress's cache was cleared", is_int( $i_wp ), true );
expect( 'the check was re-run', is_int( $i_run ), true );
expect( "WordPress's is cleared BEFORE its check re-runs", ( is_int( $i_wp ) && is_int( $i_run ) && $i_wp < $i_run ), true );
expect( 'the fetch happens BEFORE the re-check that reads it', ( is_int( $i_fetch ) && is_int( $i_run ) && $i_fetch < $i_run ), true );
expect( 'the fresh answer is in the cache for inject() to read',
    isset( $GLOBALS['transients']['sfaf_updater_release']['version'] )
        ? $GLOBALS['transients']['sfaf_updater_release']['version'] : null,
    '3.72.0' );

/* A failed check must not leave a usable answer behind. */
reset_world();
$GLOBALS['remote'] = array( 'response' => array( 'code' => 403 ), 'body' => '' );
$out = SFAF_Updater::run_check();
expect( 'a rate-limited check returns nothing', $out, null );
expect( 'and caches the empty marker rather than a version',
    isset( $GLOBALS['transients']['sfaf_updater_release']['version'] )
        ? $GLOBALS['transients']['sfaf_updater_release']['version'] : 'MISSING',
    '' );

$u = new SFAF_Updater();

/* ===========================================================================
 * 4. SOMETHING ACTUALLY PASSES $force. THE ASSERTION THAT WAS MISSING.
 *
 * Assertion 3 proves run_check() forces. This proves run_check() is REACHED:
 * a method nothing calls is the same defect one level along, and it is the
 * defect this whole file exists because of.
 * ======================================================================== */
$live = file_get_contents( $SRC );
expect( 'the plugin registers an admin-post handler for the check',
    false !== strpos( $live, "add_action( 'admin_post_' . self::CHECK_ACTION" ), true );
expect( 'the handler calls run_check()',
    (bool) preg_match( '/public function handle_check\(\).*?self::run_check\(\)/s', $live ), true );
expect( 'a link to it is put on the Plugins screen',
    false !== strpos( $live, "add_filter( 'plugin_action_links_' . self::basename()" ), true );

/* AND THE LINK IS GATED, DECIDED BY CALLING IT rather than by reading it.
   The first version of this file asserted only that the filter was registered,
   and the planted removal of the capability check went straight past. A link
   that renders for somebody who cannot press it is a promise the handler then
   refuses, and on a multi-author site that is most of the people looking. */
reset_world();
$GLOBALS['can'] = false;
$denied = $u->action_link( array( 'deactivate' => '<a>Deactivate</a>' ) );
expect( 'no check link for somebody without update_plugins', count( $denied ), 1 );
expect( 'and the links they do have are untouched',
    isset( $denied['deactivate'] ) ? $denied['deactivate'] : null, '<a>Deactivate</a>' );

$GLOBALS['can'] = true;
$allowed = $u->action_link( array( 'deactivate' => '<a>Deactivate</a>' ) );
expect( 'the check link is added for somebody who can', count( $allowed ), 2 );
expect( 'it is first, because it is what they came for',
    false !== strpos( (string) reset( $allowed ), 'Check for updates' ), true );
expect( 'it carries a nonce',
    false !== strpos( (string) reset( $allowed ), '_wpnonce=' ), true );
expect( 'and it points at the check action',
    false !== strpos( (string) reset( $allowed ), 'sfaf_check_updates' ), true );

/* ===========================================================================
 * 5. forget(): which upgrader outcomes clear the cache.
 * ======================================================================== */
function forget_clears( $u, $options ) {
    $GLOBALS['transients']['sfaf_updater_release'] = array( 'version' => '3.71.0' );
    $u->forget( null, $options );
    return ! isset( $GLOBALS['transients']['sfaf_updater_release'] );
}
expect( 'updating a plugin clears it',
    forget_clears( $u, array( 'action' => 'update', 'type' => 'plugin' ) ), true );
expect( 'INSTALLING a plugin clears it, which is uploading a zip by hand',
    forget_clears( $u, array( 'action' => 'install', 'type' => 'plugin' ) ), true );
expect( 'a bulk plugin update clears it',
    forget_clears( $u, array( 'action' => 'update', 'type' => 'plugin', 'bulk' => true, 'plugins' => array( 'a/a.php' ) ) ), true );
expect( 'a THEME update leaves it alone',
    forget_clears( $u, array( 'action' => 'update', 'type' => 'theme' ) ), false );
expect( 'a core update leaves it alone',
    forget_clears( $u, array( 'action' => 'update', 'type' => 'core' ) ), false );
expect( 'options with no type leave it alone',
    forget_clears( $u, array( 'action' => 'update' ) ), false );

/* ===========================================================================
 * 6. inject(): the version comparison, against a real transient object.
 * ======================================================================== */
function injected( $u, $available, $installed ) {
    reset_world();
    $GLOBALS['transients']['sfaf_updater_release'] = array(
        'version' => $available, 'zip' => 'Z', 'url' => 'U', 'notes' => '', 'date' => '',
    );
    // SFAF_VERSION is a constant, so the installed side is exercised by asking
    // version_compare the same question inject() asks.
    if ( $installed !== SFAF_VERSION ) { return 'n/a'; }
    $t = (object) array( 'response' => array(), 'no_update' => array() );
    $t = $u->inject( $t );
    $file = SFAF_Updater::basename();
    if ( isset( $t->response[ $file ] ) )  { return 'offered'; }
    if ( isset( $t->no_update[ $file ] ) ) { return 'up to date'; }
    return 'silent';
}
expect( 'a newer release is offered',        injected( $u, '3.72.0', '3.71.0' ), 'offered' );
expect( 'the same version is not offered',   injected( $u, '3.71.0', '3.71.0' ), 'up to date' );
expect( 'an OLDER release is never offered', injected( $u, '3.70.0', '3.71.0' ), 'up to date' );

/* A cached failure marker must offer nothing at all, not an empty update. */
reset_world();
$GLOBALS['transients']['sfaf_updater_release'] = array( 'version' => '' );
$t = (object) array( 'response' => array(), 'no_update' => array() );
$t = $u->inject( $t );
expect( 'a failed check offers nothing', count( (array) $t->response ), 0 );

/* ------------------------------------------------------------------------- */
if ( $fails ) {
    echo "UPDATER: " . count( $fails ) . " FAILURE(S)\n";
    foreach ( $fails as $f ) { echo "  - $f\n"; }
    exit( 1 );
}
echo "Updater\n";
echo "asset:   the built zip is taken and the source zipball, a bare name and a\n";
echo "         four-part version are all refused\n";
echo "cache:   an unforced check answers from it even when stale, and only\n";
echo "         latest( true ) gets past it\n";
echo "forced:  both caches cleared, in the order that makes WordPress re-check,\n";
echo "         one request to GitHub, and the fresh answer left where inject() reads it\n";
echo "reached: the forced check is registered, linked and called, which is the\n";
echo "         assertion a dead \$force parameter would fail\n";
echo "forget:  update AND install clear it; themes and core do not\n";
echo "inject:  newer offered, same and older never\n";
echo "\n";
echo "the updater asks GitHub when it is told to, and answers from cache when it is not.\n";
