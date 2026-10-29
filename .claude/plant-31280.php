<?php
/**
 * THE 3.128.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31280.php
 *
 * Puts back, one at a time, what 3.128.0 changed, and runs the check that must
 * catch it. Every file is put back from memory. Commit first, and never run
 * this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$P    = 'includes/class-sfaf-portal.php';

$plants = array(
    'the range ignores the last day' => array( 'release-31280-test.php', 'PLANT A: the range does not follow the rule', array(
        array( $P, "\"( meta_value >= %s OR post_id IN ( SELECT post_id FROM {\$wpdb->postmeta} WHERE meta_key = '_uc_end_date' AND meta_value >= %s ) )\",", "\"( meta_value >= %s OR %s = '' )\"," ),
    ) ),
    'an empty first day counts as before To' => array( 'release-31280-test.php', 'PLANT A: the range does not follow the rule', array(
        array( $P, "\$wpdb->prepare( \"meta_value <> '' AND meta_value <= %s\", \$to );", "\$wpdb->prepare( 'meta_value <= %s', \$to );" ),
    ) ),
    'Archived takes in today' => array( 'release-31280-test.php', 'PLANT A: the range does not follow the rule', array(
        array( $P, "\$wpdb->prepare( \"meta_value <> '' AND meta_value < %s\", current_time( 'Y-m-d' ) );", "\$wpdb->prepare( \"meta_value <> '' AND meta_value <= %s\", current_time( 'Y-m-d' ) );" ),
    ) ),
    'the range compares through CAST again' => array( 'release-31280-test.php', 'C: the range compares through CAST', array(
        array( $P, "\$wpdb->prepare( \"meta_value <> '' AND meta_value <= %s\", \$to );", "\$wpdb->prepare( \"meta_value <> '' AND CAST(meta_value AS DATE) <= %s\", \$to );" ),
    ) ),
    'Upcoming keeps its join beside a range' => array( 'release-31280-test.php', 'PLANT B: Upcoming with a range still puts', array(
        array( $P, "        if ( ! empty( \$args['upcoming'] ) && ! \$dated ) {", "        if ( ! empty( \$args['upcoming'] ) ) {" ),
    ) ),
    'nothing in range lists everything' => array( 'release-31280-test.php', 'B: a range with nothing in it is not array( 0 )', array(
        array( $P, "            \$q['post__in'] = \$in_range ? \$in_range : array( 0 );", "            \$q['post__in'] = \$in_range;" ),
    ) ),
    'a person\'s own events are dropped for the range' => array( 'release-31280-test.php', 'PLANT B: a person\'s own events are not crossed with the range', array(
        array( $P, "                \$in_range = array_values( array_intersect( \$in_range, array_map( 'intval', \$q['post__in'] ) ) );\n", '' ),
    ) ),
    'the event page lists dates without the weekday' => array( 'release-31280-test.php', 'PLANT D: the', array(
        array( 'includes/sfaf-template-functions.php', "sfaf_ap_date( \$ts, 'short_weekday', sfaf_event_language( \$eid ) )", "sfaf_ap_date( \$ts, 'short' )" ),
    ) ),
    'the event page\'s weekdays all in English' => array( 'release-31280-test.php', 'PLANT D: the es event', array(
        array( 'includes/sfaf-template-functions.php', "sfaf_ap_date( \$ts, 'short_weekday', sfaf_event_language( \$eid ) )", "sfaf_ap_date( \$ts, 'short_weekday' )" ),
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
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
