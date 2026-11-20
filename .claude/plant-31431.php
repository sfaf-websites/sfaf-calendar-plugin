<?php
/**
 * THE 3.143.1 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31431.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each part of 3.143.1 (the series filter,
 * the announcement colors, the cell's bar, the grid's hyphens) and runs the
 * check that must catch it. Every file is put back from memory. Commit
 * first, and never run this while run-all.sh is running.
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
        array( $CS, "    background: #FFFAE0; color: var(--uc-dark);\n    font-family: var(--uc-font-heading); font-size: 11px;", "    background: var(--uc-dark); color: var(--uc-yellow);\n    font-family: var(--uc-font-heading); font-size: 11px;" ),
    ) ),
    'the bar dark gray again, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, "    background: #FFFAE0; color: var(--uc-dark);\n    font-family: var(--uc-font-heading); font-size: 11px;", "    background: var(--uc-dark); color: var(--uc-yellow);\n    font-family: var(--uc-font-heading); font-size: 11px;" ),
    ) ),
    'no edge' => array( $T, "K: the bar is not Yellow's tint", array(
        array( $CS, "    border: 1px solid #705F00; border-radius: 4px;\n    background: #FFFAE0;", "    border: 0; border-radius: 4px;\n    background: #FFFAE0;" ),
    ) ),
    'the hover dark gray' => array( $T, "K: the bar's hover is not #FFF8D1", array(
        array( $CS, "    background: #FFF8D1; color: var(--uc-dark);\n}", "    background: #24211F; color: var(--uc-yellow);\n}" ),
    ) ),
    'the strip dark, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, "@container uc-calendar (max-width: 560px) {\n    .uc-calendar td .uc-ann-bars { display: none; }\n    .uc-calendar .uc-ann-strip { display: block; box-sizing: content-box; height: 4px; margin: 0 3px 3px; border: 1px solid #705F00; border-radius: 2px; background: #FFFAE0; }",
            "@container uc-calendar (max-width: 560px) {\n    .uc-calendar td .uc-ann-bars { display: none; }\n    .uc-calendar .uc-ann-strip { display: block; height: 4px; margin: 0 3px 3px; border-radius: 2px; background: var(--uc-dark); }" ),
    ) ),
    'the bar inside the padding, in Chrome' => array( $L, 'PLANT W:', array(
        array( $CS, ".uc-calendar td .uc-ann-bars { margin: 0 -5px 4px; }\n", '' ),
    ) ),
    'the banner its own colors' => array( $T, 'K: the banner sets its own colors', array(
        array( $CS, ".uc-calendar .uc-ann-banner { min-height: 32px;", ".uc-calendar .uc-ann-banner { background: var(--uc-dark); min-height: 32px;" ),
    ) ),
    'a public file changes' => array( $I, 'differs from 3.143.0', array(
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
