<?php
/**
 * THE 3.143.1 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31431.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each part of 3.143.1 (the series filter,
 * the announcement colors, the grid's hyphens) and runs the check that must
 * catch it. The edge, the strip and the cell's margin changed in 3.143.2, and
 * their plants are in plant-31432.php. Every file is put back from memory.
 * Commit first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$SC = 'includes/class-sfaf-shortcodes.php';
$CS = 'public/css/calendar.css';
$CJ = 'public/js/calendar.js';
$T  = 'release-31431-test.php';
$L  = 'release-31431-live.php --run';
$I  = 'embed-identity-test.php';

$plants = array(
    'the list ignores Also show' => array( $T, "Q: World AIDS Day's list is", array(
        array( $SC, "        \$also = SFAF_Series::also_in( \$term_ids );\n", "        \$also = array();\n" ),
    ) ),
    'the month grid ignores Also show' => array( $T, "Q: World AIDS Day's month grid does not show the ride", array(
        array( $SC, "        if ( ! empty( \$filters['groups'] ) ) {\n            \$this->apply_groups( \$args, \$filters['groups'] );\n        }",
            "        if ( ! empty( \$filters['groups'] ) ) {\n            \$args['tax_query'][] = array( 'taxonomy' => SFAF_Series::TAXONOMY, 'field' => 'slug', 'terms' => explode( ',', \$filters['groups'] ) );\n        }" ),
    ) ),
    'the filter ignores Also show, in Chrome' => array( $L, 'PLANT F:', array(
        array( $SC, "        \$also = SFAF_Series::also_in( \$term_ids );\n", "        \$also = array();\n" ),
    ) ),
    'a series reached only through Also show not offered' => array( $T, 'G: World AIDS Day, reached only through the ride, is not offered', array(
        array( $SC, "        foreach ( SFAF_Series::also_map( \$q->posts ) as \$also ) {", "        foreach ( array() as \$also ) {" ),
    ) ),
    'the counts miss Also show' => array( $T, 'G: World AIDS Day counts', array(
        array( $SC, "        foreach ( SFAF_Series::also_map( \$ids ) as \$eid => \$also ) {", "        foreach ( array() as \$eid => \$also ) {" ),
    ) ),
    'every title in English' => array( $T, 'L: Transformaciones is lang="en"', array(
        array( $SC, "<span class=\"uc-day-event-title\" lang=\"<?php echo esc_attr( sfaf_event_language( \$id ) ); ?>\">", "<span class=\"uc-day-event-title\" lang=\"en\">" ),
    ) ),
    'every title in English, in Chrome' => array( $L, 'PLANT H:', array(
        array( $SC, "<span class=\"uc-day-event-title\" lang=\"<?php echo esc_attr( sfaf_event_language( \$id ) ); ?>\">", "<span class=\"uc-day-event-title\" lang=\"en\">" ),
    ) ),
    'no hyphens, in Chrome' => array( $L, 'PLANT H:', array(
        array( $CS, "    -webkit-hyphens: auto; hyphens: auto;\n    font-weight: 600;", "    font-weight: 600;" ),
    ) ),
    'the bar dark gray again' => array( $T, "K: the bar is not Yellow's tint", array(
        array( $CS, "    background-color: #FFFAE0; color: var(--uc-dark);\n", "    background-color: var(--uc-dark); color: var(--uc-yellow);\n" ),
    ) ),
    'the bar dark gray again, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, "    background-color: #FFFAE0; color: var(--uc-dark);\n", "    background-color: var(--uc-dark); color: var(--uc-yellow);\n" ),
    ) ),
    'the hover dark gray' => array( $T, "K: the bar's hover is not #FFF8D1", array(
        array( $CS, "    background-color: #FFF8D1; color: var(--uc-dark);\n}", "    background-color: #24211F; color: var(--uc-yellow);\n}" ),
    ) ),
    'the banner its own colors' => array( $T, 'K: the banner sets its own colors', array(
        array( $CS, ".uc-calendar .uc-ann-banner { min-height: 32px;", ".uc-calendar .uc-ann-banner { background: var(--uc-dark); min-height: 32px;" ),
    ) ),
    'a public file changes' => array( $I, 'differs from 3.143.1', array(
        array( $CJ, " * SFAF Calendar Frontend JS\n", " * SFAF Calendar Frontend JS \n" ),
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
