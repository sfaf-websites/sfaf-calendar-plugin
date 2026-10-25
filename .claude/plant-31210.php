<?php
/**
 * THE 3.121.0 CHECK, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31210.php
 *
 * Puts back the 200-minus-upcoming list and runs release-31210-test.php, which
 * must fail. Every file is put back from memory. Commit first, and never run
 * this while run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'the past list is the first 200 less the upcoming 100 again' => array( 'release-31210-test.php', 'none of them upcoming', array(
        array( 'templates/taxonomy-uc_series.php', "\$past = array_slice( array_reverse( SFAF_Series::events( \$term->term_id, array( 'past' => true, 'limit' => -1, 'public_only' => true ) ) ), 0, 10 );", "\$all  = SFAF_Series::events( \$term->term_id, array( 'limit' => 200, 'public_only' => true ) );\n\$past = array_slice( array_reverse( array_values( array_diff( \$all, \$upcoming ) ) ), 0, 10 );" ),
    ) ),
);

$missed = 0;
$only = isset( $argv[1] ) ? $argv[1] : '';
foreach ( $plants as $name => $p ) {
    if ( '' !== $only && false === strpos( $name, $only ) ) { continue; }
    list( $check, $want, $edits ) = $p;
    $saved = array();
    foreach ( $edits as $e ) {
        list( $file, $from, $to ) = $e;
        $path = $root . '/' . $file;
        if ( ! isset( $saved[ $path ] ) ) { $saved[ $path ] = file_get_contents( $path ); }
        $now = file_get_contents( $path );
        if ( 1 !== substr_count( $now, $from ) ) {
            foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
            echo "ANCHOR NOT UNIQUE for \"$name\" in $file (" . substr_count( $now, $from ) . ")\n";
            exit( 1 );
        }
        file_put_contents( $path, str_replace( $from, $to, $now ) );
    }
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : ' --verbose';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
