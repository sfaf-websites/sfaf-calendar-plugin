<?php
/**
 * THE 3.139.0 CHECK, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31390.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in Replies go to's chevron, its floating
 * list or its flip upward, and runs release-31390-live.php, which must catch
 * it. Every file is put back from memory. Commit first, and never run this
 * while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$C = 'public/css/portal.css';
$J = 'public/js/portal.js';
$L = 'release-31390-live.php';

$plants = array(
    'a plain text box again' => array( $L, 'PLANT C:', array(
        array( $C, ".uc-portal .uc-replyto-search input[role=\"combobox\"] {\n    padding-right: 38px;", ".uc-portal .uc-replyto-search input[role=\"combobox-off\"] {\n    padding-right: 38px;" ),
    ) ),
    'the list back inside the card' => array( $L, 'PLANT F:', array(
        array( $J, "            ul.classList.add('uc-sl-float');\n            if (canPop) { ul.setAttribute('popover', 'manual'); }", "" ),
    ) ),
    'the list under the save bar' => array( $L, 'PLANT F:', array(
        array( $J, "            if (canPop) { ul.setAttribute('popover', 'manual'); }", "            if (canPop) { ul.setAttribute('data-popover-off', 'manual'); }" ),
        array( $C, "    position: fixed; z-index: 1000; inset: auto; right: auto; bottom: auto;", "    position: fixed; z-index: 1; inset: auto; right: auto; bottom: auto;" ),
    ) ),
    'never opens upward' => array( $L, 'PLANT U:', array(
        array( $J, "                var up = want > below && above > below;", "                var up = false;" ),
    ) ),
    'always opens upward' => array( $L, 'PLANT U:', array(
        array( $J, "                var up = want > below && above > below;", "                var up = true;" ),
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
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . ' --run 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
