<?php
/**
 * THE 3.143.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31430.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each part of "Also show this event in
 * series" and runs the check that must catch it. Every file is put back from
 * memory. Commit first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$SE = 'includes/class-sfaf-series.php';
$TF = 'includes/sfaf-template-functions.php';
$PO = 'includes/class-sfaf-portal.php';
$PJ = 'public/js/portal.js';
$PC = 'public/css/portal.css';
$TP = 'templates/single-uc_event.php';
$T  = 'release-31430-test.php';
$L  = 'release-31430-live.php --run';
$I  = 'embed-identity-test.php';

$plants = array(
    'the own series stored as an extra' => array( $T, 'S: set_also_for_event stored', array(
        array( $SE, "            if ( \$id && \$id !== \$own && ! in_array( \$id, \$keep, true ) && self::exists( \$id ) ) {", "            if ( \$id && ! in_array( \$id, \$keep, true ) && self::exists( \$id ) ) {" ),
    ) ),
    'removing a series leaves it on events' => array( $T, 'S: removing a series did not take it off', array(
        array( $SE, "            delete_post_meta( \$eid, self::META_ALSO, \$term_id );\n", '' ),
    ) ),
    'the save ignores the field' => array( $T, 'V: the save did not store Alpha and Gamma', array(
        array( $PO, "            SFAF_Series::set_also_for_event( \$event_id, \$also );\n", "            \$also = \$also;\n" ),
    ) ),
    'the own series offered' => array( $T, 'F: Edit: the event\'s own series can be picked', array(
        array( $PO, "<label class=\"uc-check\" data-uc-chip-label=\"<?php echo esc_attr( \$term->name ); ?>\"<?php echo \$own ? ' hidden' : ''; ?>>", "<label class=\"uc-check\" data-uc-chip-label=\"<?php echo esc_attr( \$term->name ); ?>\">" ),
    ) ),
    'the picker does not follow Series' => array( $L, 'PLANT F:', array(
        array( $PJ, "        select.addEventListener('change', sync);\n", '' ),
    ) ),
    'nothing extra on the event page' => array( $T, 'P: Alpha\'s dates are not its own and Beta night', array(
        array( $TF, "    \$also = array_values( array_diff( \$also, \$own ) );", "    \$also = array();" ),
    ) ),
    'the extras after the own dates' => array( $L, 'PLANT E:', array(
        array( $TF, "    asort( \$keys );\n    return array_slice(", "    return array_slice(" ),
    ) ),
    'a private event shown in a series' => array( $T, 'P: a private event is listed', array(
        array( $SE, "            SFAF_Privacy::exclude( \$query );\n            SFAF_Cancellation::exclude( \$query );\n        }\n        \$q = new WP_Query( \$query );", "        }\n        \$q = new WP_Query( \$query );" ),
    ) ),
    'shown in Upcoming with a Remove' => array( $T, 'C: the row', array(
        array( $PO, "\$this->render_also_row( \$eid ); ?>", "\$this->render_schedule_row( \$eid, \$term_id, true, \$group ); ?>" ),
    ) ),
    'the tag the extra date\'s purple' => array( $L, 'PLANT S:', array(
        array( $PC, "    background: #E3F7F9; color: #0C666F; font-size: 11px;", "    background: #F1EAF4; color: #7F3A98; font-size: 11px;" ),
    ) ),
    'the event page template changes' => array( $I, 'differs from 3.142.1', array(
        array( $TP, " * Single event template for the SFAF Calendar plugin.\n", " * Single event template for the SFAF Calendar plugin. \n" ),
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
    $parts = explode( ' ', $check, 2 );
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $parts[0] ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
