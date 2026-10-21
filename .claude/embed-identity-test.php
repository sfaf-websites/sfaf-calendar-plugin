<?php
/**
 * THE PUBLIC CALENDAR IS 3.111.0's (3.113.0).
 *
 *     php .claude/embed-identity-test.php            compare with 3.111.0
 *     php .claude/embed-identity-test.php --verbose  and print what differs
 *
 * 3.112.0 to 3.112.2 failed from the office network and their cause was not
 * found, so 3.113.0 is built from 3.111.0 and changes nothing a public visitor
 * or an embedding page receives. This proves it rather than reading the diff:
 *
 *   A  the same probe (embed-identity-probe.php) runs in 3.111.0, exported
 *      from git, and in this tree: every taxonomy, post type, REST route,
 *      rewrite rule, script and stylesheet the plugin registers, the scheduled
 *      tasks and cron intervals, the embed route's body and headers for twelve
 *      snippets, and the [sfaf_calendar] block. Each tree's own version
 *      number is the only thing allowed to differ.
 *   B  every file under public/ and templates/ is byte for byte 3.111.0's,
 *      except embed.js's EMBED_JS_VERSION line, and the caladmin files in
 *      CALADMIN_ONLY, which no public calendar page loads.
 *
 * BASE is the release compared against: 3.113.4's released commit from 3.113.5
 * (3.113.3's, ab3c620, for 3.113.4; 3.113.2's, 60548c9, for 3.113.3; 3.113.1's, c242c72, for 3.113.2; 3.113.0's, 84fd1ab, for 3.113.1). Its public calendar is 3.111.0's apart from the event
 * page's contact email (this check passed against f7450c7 for 3.113.0).
 * When a release is meant to change the public calendar, it says so here, by
 * name, in ALLOWED or ALLOWED_FILES.
 */

const BASE      = '1c03ed2';
const BASE_NAME = '3.113.4';

/* Files under public/ and templates/ a release means to change, as path =>
   why. Probe A still compares every embed answer, so none of these can reach
   the embed unseen. */
const ALLOWED_FILES = array(
    // The RSVP form and its English | Español toggle (3.113.6). calendar.js is
    // the event page's and the calendar block's script; the embed loads embed.js
    // and calendar.css and never this file, and draws no RSVP form.
    'public/js/calendar.js' => 'the RSVP form toggle between English and Español (3.113.6)',
    // None in 3.113.5 or 3.113.4. 3.113.3 named templates/single-uc_event.php, the event
    // page flyer; 3.113.4 changes the flyer in includes/ and not the template.
);

/* Loaded by caladmin and the two public forms, never by the calendar, an event
   page or an embed. sfaf_enqueue_frontend_assets() enqueues neither. */
const CALADMIN_ONLY = array( 'public/css/portal.css', 'public/js/portal.js', 'public/js/caladmin-session.js' );

/* Differences a release means to make, as 'key path' => why. None in 3.113.0 to 3.113.2. */
const ALLOWED = array(
    // The form's words in both languages, handed to calendar.js on a public page
    // (3.113.6). Not in the embed's answers, which probe A compares.
    'localized > sfaf-calendar-public ucData' => 'the RSVP form words in both languages (3.113.6)',
);

$root    = str_replace( '\\', '/', dirname( __DIR__ ) );
$verbose = in_array( '--verbose', $argv, true );
$fails   = array();

/* ---- the base tree, from git ----------------------------------------- */
$tmp = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sfaf-identity-' . getmypid();
@mkdir( $tmp, 0777, true );
$tar = $tmp . '/base.tar';
exec( 'git -C ' . escapeshellarg( $root ) . ' archive --format=tar -o ' . escapeshellarg( $tar ) . ' ' . BASE . ' 2>&1', $o, $rc );
if ( 0 !== $rc || ! is_file( $tar ) ) { echo "could not export " . BASE . ": " . implode( "\n", $o ) . "\n"; exit( 1 ); }
$base = $tmp . '/base';
@mkdir( $base );
$bsdtar = file_exists( 'C:/Windows/System32/tar.exe' ) ? 'C:/Windows/System32/tar.exe' : 'tar';
exec( '"' . $bsdtar . '" -xf ' . escapeshellarg( $tar ) . ' -C ' . escapeshellarg( $base ) . ' 2>&1', $o2, $rc2 );
if ( 0 !== $rc2 || ! is_file( $base . '/sfaf-calendar.php' ) ) { echo "could not unpack " . BASE . ": " . implode( "\n", $o2 ) . "\n"; exit( 1 ); }
copy( __DIR__ . '/embed-identity-probe.php', $base . '/.claude/embed-identity-probe.php' );

function ei_run( $tree ) {
    $json = shell_exec( 'php ' . escapeshellarg( $tree . '/.claude/embed-identity-probe.php' ) . ' 2>&1' );
    $d = json_decode( (string) $json, true );
    if ( ! is_array( $d ) ) { return array( 'error' => substr( (string) $json, 0, 600 ) ); }
    $src = (string) file_get_contents( $tree . '/sfaf-calendar.php' );
    $ver = preg_match( "/define\\( 'SFAF_VERSION', '([^']+)' \\);/", $src, $m ) ? $m[1] : '?';
    /* Each tree's own version, and only that, is not a difference. */
    return json_decode( str_replace( $ver, '{VERSION}', (string) json_encode( $d ) ), true );
}
$was = ei_run( $base );
$now = ei_run( $root );

foreach ( array( 'base' => $was, 'this tree' => $now ) as $who => $d ) {
    if ( isset( $d['error'] ) ) { $fails[] = "A: the probe failed in $who: " . $d['error']; }
    elseif ( ! empty( $d['threw'] ) ) { $fails[] = "A: in $who, a hook threw, so what it registers was not recorded: " . implode( '; ', $d['threw'] ); }
}

/* ---- A: compare, leaf by leaf ------------------------------------------ */
function ei_diff( $a, $b, $path, &$out ) {
    if ( is_array( $a ) && is_array( $b ) ) {
        foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
            ei_diff( array_key_exists( $k, $a ) ? $a[ $k ] : '(absent)', array_key_exists( $k, $b ) ? $b[ $k ] : '(absent)', $path . ' > ' . $k, $out );
        }
        return;
    }
    if ( $a !== $b ) { $out[ ltrim( $path, ' >' ) ] = array( $a, $b ); }
}
$diffs = array();
if ( ! isset( $was['error'] ) && ! isset( $now['error'] ) ) {
    ei_diff( $was, $now, '', $diffs );
    /* Sanity: the probe saw something in both, or two empty trees agree. */
    foreach ( array( 'taxonomies', 'post_types', 'rest', 'scripts', 'cron_tasks', 'embed', 'shortcode' ) as $k ) {
        if ( empty( $now[ $k ] ) ) { $fails[] = "A: the probe recorded no $k, so it compared nothing"; }
    }
    foreach ( (array) $now['embed'] as $case => $r ) {
        if ( ! is_array( $r ) || '' === (string) ( isset( $r['data']['html'] ) ? $r['data']['html'] : '' ) ) { $fails[] = "A: the embed drew nothing for \"$case\": " . json_encode( $r ); }
    }
}
foreach ( $diffs as $path => $pair ) {
    $allowed = false;
    foreach ( ALLOWED as $prefix => $why ) { if ( 0 === strpos( $path, $prefix ) ) { $allowed = true; } }
    if ( $allowed ) { continue; }
    $show = function ( $v ) { $s = is_string( $v ) ? $v : json_encode( $v ); return strlen( $s ) > 160 ? substr( $s, 0, 160 ) . '...' : $s; };
    if ( is_string( $pair[0] ) && is_string( $pair[1] ) && strlen( $pair[0] ) > 160 ) {
        $at = strspn( $pair[0] ^ $pair[1], "\0" );
        $show = function ( $v ) use ( $at ) { return '...' . substr( (string) $v, max( 0, $at - 60 ), 160 ) . '...'; };
    }
    $fails[] = "A: $path differs from " . BASE_NAME . ":\n      was " . $show( $pair[0] ) . "\n      now " . $show( $pair[1] );
}

/* ---- B: the files ------------------------------------------------------- */
function ei_files( $tree ) {
    $out = array();
    foreach ( array( 'public', 'templates' ) as $dir ) {
        if ( ! is_dir( "$tree/$dir" ) ) { continue; }
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$tree/$dir", FilesystemIterator::SKIP_DOTS ) ) as $f ) {
            $rel = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $tree ) + 1 ) );
            $out[ $rel ] = (string) file_get_contents( $f->getPathname() );
        }
    }
    return $out;
}
$fw = ei_files( $base );
$fn = ei_files( $root );
$files_same = 0;
foreach ( array_unique( array_merge( array_keys( $fw ), array_keys( $fn ) ) ) as $rel ) {
    if ( in_array( $rel, CALADMIN_ONLY, true ) || isset( ALLOWED_FILES[ $rel ] ) ) { continue; }
    $a = isset( $fw[ $rel ] ) ? $fw[ $rel ] : null;
    $b = isset( $fn[ $rel ] ) ? $fn[ $rel ] : null;
    if ( null === $a || null === $b ) { $fails[] = "B: $rel is " . ( null === $a ? 'new' : 'gone' ) . ' since ' . BASE_NAME; continue; }
    if ( 'public/js/embed.js' === $rel ) {
        $strip = function ( $s ) { return preg_replace( "/var EMBED_JS_VERSION = '[^']+';/", "var EMBED_JS_VERSION = '{VERSION}';", str_replace( "\r\n", "\n", $s ) ); };
        $a = $strip( $a ); $b = $strip( $b );
    }
    if ( $a !== $b ) { $fails[] = "B: $rel differs from " . BASE_NAME; } else { $files_same++; }
}

/* ---- clean up ----------------------------------------------------------- */
$rm = function ( $d ) use ( &$rm ) { foreach ( (array) @scandir( $d ) as $e ) { if ( '.' === $e || '..' === $e ) { continue; } $p = "$d/$e"; is_dir( $p ) && ! is_link( $p ) ? $rm( $p ) : @unlink( $p ); } @rmdir( $d ); };
$rm( $tmp );

echo "\nThe public calendar against " . BASE_NAME . " (" . BASE . ")\n" . str_repeat( '=', 72 ) . "\n";
if ( ! isset( $now['error'] ) ) {
    printf( "probe: %d taxonomies, %d post types, %d routes, %d rewrite rules, %d scripts, %d styles, %d tasks, %d embed answers, %d shortcode blocks\n",
        count( (array) $now['taxonomies'] ), count( (array) $now['post_types'] ), count( (array) $now['rest'] ), count( (array) $now['rewrite'] ),
        count( (array) $now['scripts'] ), count( (array) $now['styles'] ), count( (array) $now['cron_tasks'] ), count( (array) $now['embed'] ), count( (array) $now['shortcode'] ) );
    printf( "files: %d under public/ and templates/ identical, %d caladmin-only not compared\n", $files_same, count( CALADMIN_ONLY ) );
}
if ( $fails ) {
    echo count( $fails ) . " difference(s):\n  - " . implode( "\n  - ", $verbose ? $fails : array_slice( $fails, 0, 25 ) ) . "\n";
    exit( 1 );
}
echo "Registrations, tasks, every embed answer and header, the shortcode and the public files are " . BASE_NAME . "'s.\n";
exit( 0 );
