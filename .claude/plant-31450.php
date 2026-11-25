<?php
/**
 * THE 3.145.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31450.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each part of 3.145.0 (a series-scoped
 * block showing the events also shown in it, the grid titles' soft hyphens,
 * the embed) and runs the check that must catch it. Every file is put back
 * from memory. Commit first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$SC = 'includes/class-sfaf-shortcodes.php';
$TF = 'includes/sfaf-template-functions.php';
$CJ = 'public/js/calendar.js';
$T  = 'release-31450-test.php';
$L  = 'release-31450-live.php --run';
$I  = 'embed-identity-test.php';

$scope_body = "        \$this->apply_series_terms( \$args, array( \$term_id ), array(\n            'taxonomy' => SFAF_Series::TAXONOMY,\n            'field'    => 'term_id',\n            'terms'    => \$term_id,\n        ) );\n";
$scope_old  = "        \$args['tax_query'][] = array(\n            'taxonomy' => SFAF_Series::TAXONOMY,\n            'field'    => 'term_id',\n            'terms'    => \$term_id,\n        );\n";
$month_call = "        // number in an existing snippet keeps meaning what it always meant.\n        \$this->apply_series_scope( \$args, SFAF_Series::resolve( \$filters['series'] ) );\n";
$month_old  = "        // number in an existing snippet keeps meaning what it always meant.\n        if ( SFAF_Series::resolve( \$filters['series'] ) > 0 ) { \$args['tax_query'][] = array( 'taxonomy' => SFAF_Series::TAXONOMY, 'field' => 'term_id', 'terms' => SFAF_Series::resolve( \$filters['series'] ) ); }\n";
$title      = "<?php echo esc_html( sfaf_soft_hyphens( get_the_title( \$id ) ) ); ?></span>";

$plants = array(
    'the scope its own series only' => array( $T, 'S: a block scoped to World AIDS Day lists', array(
        array( $SC, $scope_body, $scope_old ),
    ) ),
    'the scope its own series only, in Chrome' => array( $L, 'PLANT S: ', array(
        array( $SC, $scope_body, $scope_old ),
    ) ),
    'the month grid scoped to its own series only' => array( $T, 'S: the scoped month grid does not show the ride', array(
        array( $SC, $month_call, $month_old ),
    ) ),
    'no soft hyphens in the grid' => array( $T, 'T: Transformaciones is not Trans-for-ma-cio-nes in the grid', array(
        array( $SC, $title, "<?php echo esc_html( get_the_title( \$id ) ); ?></span>" ),
    ) ),
    'no soft hyphens in the grid, in Chrome' => array( $L, 'PLANT H: ', array(
        array( $SC, $title, "<?php echo esc_html( get_the_title( \$id ) ); ?></span>" ),
    ) ),
    'the lang dropped' => array( $T, 'T: Transformaciones lost lang="es"', array(
        array( $SC, "<span class=\"uc-day-event-title\" lang=\"<?php echo esc_attr( sfaf_event_language( \$id ) ); ?>\">$title", "<span class=\"uc-day-event-title\" lang=\"en\">$title" ),
    ) ),
    'a break inside a consonant pair moved' => array( $T, 'H: "Transformaciones" is', array(
        array( $TF, "            \$at   = in_array( \$pair, \$onsets, true ) ? \$j - 2 : \$j - 1;", "            \$at   = \$j - 2;" ),
    ) ),
    'two letters left at the start' => array( $T, 'H: "Acompa', array(
        array( $TF, "        if ( \$at >= 3 && \$n - \$at >= 3 &&", "        if ( \$at >= 2 && \$n - \$at >= 2 &&" ),
    ) ),
    'capitals broken' => array( $T, 'H: "HIV\/AIDS AWARENESS" is', array(
        array( $TF, "    if ( ! preg_match( '/\\p{Ll}/u', \$word ) ) {", "    if ( false ) {" ),
    ) ),
    'an entity broken' => array( $T, '&thetasym;" is "Cele-bra-ci', array(
        array( $TF, "'/((?<![&\\p{L}])\\p{L}{8,})/u'", "'/(\\p{L}{8,})/u'" ),
    ) ),
    'a public file changes' => array( $I, 'differs from 3.144.2', array(
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
