<?php
/**
 * THE 3.133.0 CHECK, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31330.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the RSVP list's layout, and runs
 * release-31330-live.php, which must catch it. Every file is put back from
 * memory. Commit first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$C    = 'public/css/portal.css';
$P    = 'includes/class-sfaf-portal.php';
$L    = 'release-31330-live.php';

$plants = array(
    'the page keeps its narrow column' => array( $L, 'PLANT A:', array(
        array( $C, ".uc-portal-content.uc-portal-content-wide { max-width: none; }\n", '' ),
    ) ),
    'the table runs off its card, as before 3.133.0' => array( $L, 'PLANT A:', array(
        array( $C, ".uc-rsvp-table td { padding: 10px 8px; }\n", ".uc-rsvp-table td { padding: 10px 8px; white-space: nowrap; }\n" ),
    ) ),
    'a long email runs out of its card' => array( $L, 'PLANT A:', array(
        array( $C, ".uc-rsvp-table .uc-rc-email,\n", '' ),
        array( $C, "    .uc-rsvp-table tr[data-uc-rsvp-row] > .uc-rc-phone { flex: 1 1 100%; font-size: 14px; color: var(--p-muted); overflow-wrap: anywhere; }", "    .uc-rsvp-table tr[data-uc-rsvp-row] > .uc-rc-phone { flex: 1 1 100%; font-size: 14px; color: var(--p-muted); overflow-wrap: normal; }" ),
    ) ),
    'a tablet gets the table' => array( $L, 'PLANT D:', array(
        array( $C, "@media (max-width: 1024px) {\n    .uc-rsvp-table thead { display: none; }", "@media (max-width: 600px) {\n    .uc-rsvp-table thead { display: none; }" ),
    ) ),
    'Check in is under 44px' => array( $L, 'PLANT B:', array(
        array( $C, ".uc-checkin-btn { min-height: 48px; min-width: 128px;", ".uc-checkin-btn { max-height: 32px; min-width: 128px;" ),
        array( $C, "    .uc-rsvp-table .uc-rsvp-actions .uc-btn,\n    .uc-rsvp-table .uc-rsvp-actions button { min-height: 44px;", "    .uc-rsvp-table .uc-rsvp-actions .uc-btn,\n    .uc-rsvp-table .uc-rsvp-actions button { min-height: 0;" ),
    ) ),
    'Remove sits beside Check in' => array( $L, 'PLANT B:', array(
        array( $C, ".uc-rc-tools { order: 8; flex: 1 1 100%;", ".uc-rc-tools { order: 1; flex: 0 0 auto;" ),
    ) ),
    'the event column is squeezed' => array( $L, 'PLANT C:', array(
        array( $C, ".uc-rsvp-table-all th.uc-rc-event { width: 16%; }\n", ".uc-rsvp-table-all th.uc-rc-event { width: 1%; }\n" ),
    ) ),
    'first and last name in two columns again' => array( $L, 'PLANT C:', array(
        array( $P, "<th class=\"uc-rc-name\">Name</th>", "<th class=\"uc-rc-name\">First name</th><th>Last name</th>" ),
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
