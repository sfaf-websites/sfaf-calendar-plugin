<?php
/**
 * NO REAL NAME OR LOGIN IN A TRACKED FILE (3.106.2).
 *
 *     php .claude/login-check.php              every tracked file, as on disk
 *     php .claude/login-check.php --self-test  the matcher, on strings
 *
 * This repository is mirrored to a public GitHub repository, so anything
 * tracked is published. The strings it refuses are read from
 * .claude/fixtures/bylines/people.local.json, which git ignores, because a
 * check that carried them would publish them itself: every entry under
 * "names", "logins" and "accounts" (the Windows account folder that absolute
 * file paths spell out). Without that file it refuses rather than passes,
 * since a check with nothing to look for finds nothing.
 *
 * build-zip.sh runs it before anything is built.
 */

$root = dirname( __DIR__ );

/** Every string in $needles found in $text, case-insensitively. */
function lc_hits( $text, $needles ) {
    $out = array();
    foreach ( $needles as $n ) {
        if ( '' !== $n && false !== stripos( $text, $n ) ) { $out[] = $n; }
    }
    return $out;
}

if ( in_array( '--self-test', $argv, true ) ) {
    $n = array( 'Pat Example', 'pexample@sfaf.org', 'pexample' );
    $cases = array(
        array( 'nothing here', array() ),
        array( 'by PAT EXAMPLE', array( 'Pat Example' ) ),
        array( 'href="/collections/author/pexample@sfaf.org"', array( 'pexample@sfaf.org', 'pexample' ) ),
        array( 'file:///C:/Users/pexample/Documents/x.css', array( 'pexample' ) ),
        array( 'Pat Exampleson', array( 'Pat Example' ) ),
    );
    foreach ( $cases as $c ) {
        if ( lc_hits( $c[0], $n ) !== $c[1] ) {
            echo "SELF-TEST FAIL: " . var_export( $c[0], true ) . "\n";
            exit( 1 );
        }
    }
    echo "self-test: names, logins and account folders found in any case, and nothing found where there is nothing.\n";
    exit( 0 );
}

$local = $root . '/.claude/fixtures/bylines/people.local.json';
$p = file_exists( $local ) ? json_decode( (string) file_get_contents( $local ), true ) : null;
$needles = array();
foreach ( array( 'names', 'logins', 'accounts' ) as $k ) {
    if ( is_array( $p ) && isset( $p[ $k ] ) ) { $needles = array_merge( $needles, (array) $p[ $k ] ); }
}
if ( ! $needles ) {
    echo "FAIL: .claude/fixtures/bylines/people.local.json is missing or empty, so there is nothing to check for. It holds {\"names\": [...], \"logins\": [...], \"accounts\": [...]}.\n";
    exit( 1 );
}

$files = array_filter( explode( "\n", (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files' ) ) );
$found = array();
foreach ( $files as $f ) {
    $path = $root . '/' . $f;
    if ( ! is_file( $path ) || filesize( $path ) > 20 * 1024 * 1024 ) { continue; }
    foreach ( lc_hits( (string) file_get_contents( $path ), $needles ) as $h ) {
        $found[ $f ][] = $h;
    }
}
if ( $found ) {
    echo 'FAIL: ' . count( $found ) . " tracked file(s) carry a real name, login or account folder, and this repository is public:\n";
    foreach ( $found as $f => $hs ) { echo "  - $f (" . count( $hs ) . " of the strings)\n"; }
    exit( 1 );
}
echo 'login check: ' . count( $files ) . " tracked files, none carries a real name, login or account folder.\n";
