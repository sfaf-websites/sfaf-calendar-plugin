<?php
/**
 * THE BUILD GATE REFUSES A RELEASE WHOSE FOUR VERSIONS DISAGREE (3.101.0).
 *
 *     php .claude/version-check-test.php
 *
 * Runs .claude/version-check.php, the script build-zip.sh runs, against small
 * trees made here: all four agreeing, and each one of the four disagreeing in
 * turn. A gate that passes a disagreement in any one place is the fault.
 */

$checker = __DIR__ . '/version-check.php';
$fails   = array();

function tree( $v ) {
    $dir = sys_get_temp_dir() . '/sfaf-vc-' . getmypid() . '-' . mt_rand();
    mkdir( $dir . '/public/js', 0777, true );
    file_put_contents( $dir . '/sfaf-calendar.php', "<?php\n/**\n * Plugin Name: SFAF Calendar\n * Version: {$v['header']}\n */\ndefine( 'SFAF_VERSION', '{$v['define']}' );\n" );
    file_put_contents( $dir . '/readme.txt', "=== SFAF Calendar ===\nStable tag: {$v['readme']}\n" );
    file_put_contents( $dir . '/public/js/embed.js', "(function () {\n    var EMBED_JS_VERSION = '{$v['embed']}';\n})();\n" );
    return $dir;
}
function gate( $dir, $version ) {
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $GLOBALS['checker'] ) . ' ' . escapeshellarg( $dir ) . ' ' . escapeshellarg( $version ), $out, $rc );
    return array( $rc, implode( "\n", $out ) );
}

$all = array( 'header' => '9.9.9', 'define' => '9.9.9', 'readme' => '9.9.9', 'embed' => '9.9.9' );
list( $rc, $out ) = gate( tree( $all ), '9.9.9' );
if ( 0 !== $rc ) { $fails[] = "four agreeing places were refused: $out"; }

foreach ( array_keys( $all ) as $place ) {
    $v = $all;
    $v[ $place ] = '9.9.8';
    list( $rc, $out ) = gate( tree( $v ), '9.9.9' );
    if ( 1 !== $rc ) { $fails[] = "a disagreeing $place passed the gate (exit $rc)"; }
    if ( 1 !== substr_count( $out, 'FAIL:' ) ) { $fails[] = "a disagreeing $place was not named alone: $out"; }
}

/* And the build script really runs this checker, rather than its own copy. */
$build = (string) file_get_contents( __DIR__ . '/build-zip.sh' );
if ( false === strpos( $build, 'php "$ROOT/.claude/version-check.php" "$ROOT" "$VERSION" || exit 1' ) ) {
    $fails[] = 'build-zip.sh does not run version-check.php and stop on its refusal';
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "the build gate passes four agreeing places, refuses each of the four disagreeing alone, and build-zip.sh runs it.\n";
exit( 0 );
