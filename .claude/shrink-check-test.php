<?php
/**
 * THE SHRINK GATE, PLANTED (3.105.0).
 *
 *     php .claude/shrink-check-test.php
 *
 * Runs .claude/shrink-check.php, the script build-zip.sh runs, against a small
 * git repository made here, and plants the fault it exists for: a shipped file
 * emptied. Then the same file emptied and COMMITTED, which is how a build here
 * is actually made. Then the override, which must let through only the file it
 * names. A gate that passes an empty file in any of these is the fault.
 */

$checker = __DIR__ . '/shrink-check.php';
$fails   = array();

function sc_run( $cmd, $dir ) {
    exec( 'git -C ' . escapeshellarg( $dir ) . ' ' . $cmd . ' 2>&1', $out, $rc );
    return $rc;
}
function sc_gate( $dir, $extra = '' ) {
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $GLOBALS['checker'] ) . ' ' . escapeshellarg( $dir ) . ' ' . $extra . ' 2>&1', $out, $rc );
    return array( $rc, implode( "\n", $out ) );
}
function sc_repo() {
    $dir = sys_get_temp_dir() . '/sfaf-shrink-' . getmypid() . '-' . mt_rand();
    mkdir( $dir . '/includes', 0777, true );
    mkdir( $dir . '/.claude', 0777, true );
    $body = "<?php\n" . str_repeat( "// a line of a real class\n", 40 );
    file_put_contents( $dir . '/includes/class-a.php', $body );
    file_put_contents( $dir . '/includes/class-b.php', $body );
    file_put_contents( $dir . '/.claude/not-shipped.php', $body );
    sc_run( 'init -q', $dir );
    sc_run( 'config user.email t@t', $dir );
    sc_run( 'config user.name t', $dir );
    sc_run( 'add -A', $dir );
    sc_run( 'commit -q -m one', $dir );
    return $dir;
}

/* Nothing changed: passes. */
$d = sc_repo();
list( $rc, $out ) = sc_gate( $d );
if ( 0 !== $rc ) { $fails[] = "an untouched tree was refused: $out"; }

/* THE PLANT: a shipped file emptied in the working tree. */
file_put_contents( $d . '/includes/class-a.php', '' );
list( $rc, $out ) = sc_gate( $d );
if ( 1 !== $rc || false === strpos( $out, 'includes/class-a.php' ) ) { $fails[] = "an emptied file was not refused by name: rc=$rc $out"; }

/* The override names that file, and lets through that file only. */
list( $rc, $out ) = sc_gate( $d, '--allow-shrink=includes/class-a.php' );
if ( 0 !== $rc ) { $fails[] = "the named override did not let the named file through: $out"; }
file_put_contents( $d . '/includes/class-b.php', "<?php\n" );
list( $rc, $out ) = sc_gate( $d, '--allow-shrink=includes/class-a.php' );
if ( 1 !== $rc || false === strpos( $out, 'class-b.php' ) ) { $fails[] = "an override for one file waived another: $out"; }

/* An unknown flag is an error, not a waiver. */
list( $rc, $out ) = sc_gate( $d, '--allow-all' );
if ( 2 !== $rc ) { $fails[] = "an unknown flag was not refused: rc=$rc"; }

/* Committed, then built: the way builds are made here. */
$d2 = sc_repo();
file_put_contents( $d2 . '/includes/class-a.php', '' );
sc_run( 'commit -q -am two', $d2 );
list( $rc, $out ) = sc_gate( $d2 );
if ( 1 !== $rc || false === strpos( $out, 'before the last commit' ) ) { $fails[] = "an emptied file that was committed was not refused: rc=$rc $out"; }

/* Deleted counts as zero lines. */
$d3 = sc_repo();
unlink( $d3 . '/includes/class-b.php' );
list( $rc, $out ) = sc_gate( $d3 );
if ( 1 !== $rc || false === strpos( $out, 'class-b.php' ) ) { $fails[] = "a deleted shipped file was not refused: $out"; }

/* Just under half is still a build: 41 lines down to 21 is fine, to 20 is not. */
$d4 = sc_repo();
file_put_contents( $d4 . '/includes/class-a.php', "<?php\n" . str_repeat( "// kept\n", 20 ) );
list( $rc, $out ) = sc_gate( $d4 );
if ( 0 !== $rc ) { $fails[] = "a file keeping more than half was refused: $out"; }
file_put_contents( $d4 . '/includes/class-a.php', "<?php\n" . str_repeat( "// kept\n", 19 ) );
list( $rc, $out ) = sc_gate( $d4 );
if ( 1 !== $rc ) { $fails[] = "a file keeping less than half passed: $out"; }

/* A file that does not ship is not the gate's business. */
$d5 = sc_repo();
file_put_contents( $d5 . '/.claude/not-shipped.php', '' );
list( $rc, $out ) = sc_gate( $d5 );
if ( 0 !== $rc ) { $fails[] = "a file outside the zip was refused: $out"; }

/* And build-zip.sh calls it, with the arguments passed through. */
$zip = (string) file_get_contents( __DIR__ . '/build-zip.sh' );
if ( false === strpos( $zip, 'shrink-check.php' ) ) { $fails[] = 'build-zip.sh does not run the shrink check'; }
if ( ! preg_match( '/shrink-check\.php" "\$ROOT" "\$@"|shrink-check\.php" "\$ROOT" \$SHRINK_ARGS/', $zip ) ) { $fails[] = 'build-zip.sh does not pass --allow-shrink through'; }

if ( $fails ) {
    echo "FAIL\n  " . implode( "\n  ", $fails ) . "\n";
    exit( 1 );
}
echo "shrink gate: an emptied file is refused, committed or not; the override lets through only the file it names.\n";
