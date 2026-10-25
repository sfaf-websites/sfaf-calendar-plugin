<?php
/**
 * 3.121.0: THE PUBLIC SERIES PAGE'S PAST DATES ARE PAST.
 *
 *     php .claude/release-31210-test.php
 *
 * The real templates/taxonomy-uc_series.php through wp-kit.php, with a series
 * of 120 upcoming dates and 14 past ones. "Previously in this series" lists
 * the ten newest past dates, newest first, and no upcoming date; "Upcoming
 * dates" is unchanged, the first 100.
 *
 * PLANTs in plant-31210.php.
 */

$GLOBALS['loop_done'] = false;
function get_header() { echo "<!--header-->\n"; }
function get_footer() { echo "\n<!--footer-->"; }
require __DIR__ . '/wp-kit.php';
foreach ( array( 'wpautop' => 'return "<p>".$a[0]."</p>";', '_n' => 'return $a[2]==1?$a[0]:$a[1];' ) as $f => $b ) { if ( ! function_exists( $f ) ) { eval( "function $f( ...\$a ) { $b }" ); } }

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

$today = current_time( 'Y-m-d' );
$dates = array();
for ( $i = -14; $i <= 120; $i++ ) {
    if ( 0 === $i ) { continue; }
    $d  = date( 'Y-m-d', strtotime( $today . ' ' . ( $i > 0 ? '+' : '' ) . $i . ' days' ) );
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Date ' . $d, 'post_name' => 'd' . $i ) );
    update_post_meta( $id, '_uc_event_date', $d );
    $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( 11 );
    $dates[ $id ] = $d;
}
/* events(): the series' dates by the date clauses it asks for, earliest first. */
$GLOBALS['kit_query'] = function ( $a ) use ( $dates, $today ) {
    $mq  = isset( $a['meta_query'] ) ? $a['meta_query'] : array();
    $out = array();
    foreach ( $dates as $id => $d ) {
        if ( isset( $mq['upcoming'] ) && $d < $today ) { continue; }
        if ( isset( $mq['past'] ) && $d >= $today ) { continue; }
        $out[ $id ] = $d;
    }
    asort( $out );
    $ids = array_keys( $out );
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
    return $per > 0 ? array_slice( $ids, 0, $per ) : $ids;
};
$GLOBALS['kit_get_posts'] = $GLOBALS['kit_query'];
$term = SFAF_Series::get( 11 );
$GLOBALS['wp_query_term'] = $term;
if ( ! function_exists( 'get_queried_object' ) ) { function get_queried_object() { return $GLOBALS['wp_query_term']; } }
ob_start();
include $GLOBALS['kit_root'] . '/templates/taxonomy-uc_series.php';
$page = (string) ob_get_clean();

$prev = (string) strstr( $page, 'Previously in this series' );
preg_match_all( '#Date (\d{4}-\d{2}-\d{2})#', $prev, $m );
$past_shown = $m[1];
rt( 'the page renders a Previously list', '' !== $prev, true );
rt( 'ten past dates', count( $past_shown ), 10 );
rt( 'none of them upcoming', array_values( array_filter( $past_shown, function ( $d ) use ( $today ) { return $d >= $today; } ) ), array() );
$want = array();
for ( $i = -1; $i >= -10; $i-- ) { $want[] = date( 'Y-m-d', strtotime( $today . ' ' . $i . ' days' ) ); }
rt( 'the ten newest, newest first', $past_shown, $want );
$tpl = file_get_contents( $GLOBALS['kit_root'] . '/templates/taxonomy-uc_series.php' );
rt( 'past dates are asked for as past', false !== strpos( $tpl, "SFAF_Series::events( \$term->term_id, array( 'past' => true, 'limit' => -1, 'public_only' => true ) )" ), true );

echo "\n3.121.0: the series page's past dates\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. With 120 upcoming dates, Previously lists the ten newest past dates, newest first, and no\nupcoming one.\n";
exit( 0 );
