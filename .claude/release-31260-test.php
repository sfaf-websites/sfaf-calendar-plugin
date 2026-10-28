<?php
/**
 * 3.126.0: THE PUBLIC SERIES PAGE'S DATES CARRY THE DAY OF THE WEEK, AND THE
 * RSVP FORM'S TOGGLE IS A PILL THAT OPENS ON THE BROWSER'S LANGUAGE.
 *
 *     php .claude/release-31260-test.php
 *
 * The real templates/taxonomy-uc_series.php through wp-kit.php, with a series
 * of two upcoming and two past dates, one of each a Spanish event:
 *
 *   - Upcoming dates read "Wed, Oct 28", Previously "Wed, Oct 28, 2026"
 *   - a Spanish event's read "mié., 28 oct." and "mié., 28 oct. 2026"
 *   - sfaf_ap_date()'s two new styles on fixed days, both languages
 *
 * and, read from calendar.js (release-31260-live.php measures them in Chrome):
 *
 *   - the toggle is a globe and a pill holding the two buttons
 *   - the form opens on startLang(), which takes the browser's first language
 *     when it is en or es and the event's otherwise
 *   - its styles are still not in calendar.css, which the embed loads
 *
 * PLANTs in plant-31260.php.
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

/* ---- the formatter on fixed days ------------------------------------- */
rt( 'short_weekday, English', sfaf_ap_date( '2026-10-28', 'short_weekday' ), 'Wed, Oct 28' );
rt( 'short_weekday_year, English', sfaf_ap_date( '2026-10-28', 'short_weekday_year' ), 'Wed, Oct 28, 2026' );
rt( 'short_weekday, Spanish', sfaf_ap_date( '2026-10-28', 'short_weekday', 'es' ), 'mié., 28 oct.' );
rt( 'short_weekday_year, Spanish', sfaf_ap_date( '2026-10-28', 'short_weekday_year', 'es' ), 'mié., 28 oct. 2026' );
rt( 'a Saturday in Spanish', sfaf_ap_date( '2026-08-01', 'short_weekday', 'es' ), 'sáb., 1 ago.' );
rt( 'a Sunday in English', sfaf_ap_date( '2026-11-01', 'short_weekday' ), 'Sun, Nov 1' );

/* ---- the series page ------------------------------------------------- */
$today = current_time( 'Y-m-d' );
$dates = array();
$langs = array();
foreach ( array( 3 => 'en', 4 => 'es', -3 => 'en', -4 => 'es' ) as $i => $lang ) {
    $d  = date( 'Y-m-d', strtotime( $today . ' ' . ( $i > 0 ? '+' : '' ) . $i . ' days' ) );
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Date ' . $d, 'post_name' => 'd' . $i ) );
    update_post_meta( $id, '_uc_event_date', $d );
    update_post_meta( $id, '_uc_language', $lang );
    $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( 11 );
    $dates[ $id ] = $d;
    $langs[ $d ]  = $lang;
}
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
$GLOBALS['wp_query_term'] = SFAF_Series::get( 11 );
if ( ! function_exists( 'get_queried_object' ) ) { function get_queried_object() { return $GLOBALS['wp_query_term']; } }
ob_start();
include $GLOBALS['kit_root'] . '/templates/taxonomy-uc_series.php';
$page = (string) ob_get_clean();

/* The oracle: written out here, not read from the formatter. */
function want_date( $d, $lang, $year ) {
    $t = strtotime( $d . ' 12:00:00' );
    if ( 'es' !== $lang ) { return date( $year ? 'D, M j, Y' : 'D, M j', $t ); }
    $dias = array( 'dom.', 'lun.', 'mar.', 'mié.', 'jue.', 'vie.', 'sáb.' );
    $mes  = array( 1 => 'ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.' );
    return $dias[ (int) date( 'w', $t ) ] . ', ' . date( 'j', $t ) . ' ' . $mes[ (int) date( 'n', $t ) ] . ( $year ? ' ' . date( 'Y', $t ) : '' );
}
$up   = (string) strstr( (string) strstr( $page, 'Upcoming dates' ), 'Previously in this series', true );
$prev = (string) strstr( $page, 'Previously in this series' );
foreach ( array( 'Upcoming' => array( $up, false ), 'Previously' => array( $prev, true ) ) as $where => $w ) {
    preg_match_all( '#<span class="uc-series-date">([^<]*)</span>\s*<span class="uc-series-title">Date (\d{4}-\d{2}-\d{2})</span>#', $w[0], $m, PREG_SET_ORDER );
    rt( "$where lists two dates", count( $m ), 2 );
    foreach ( $m as $row ) {
        rt( "$where: the " . $langs[ $row[2] ] . ' event on ' . $row[2], html_entity_decode( $row[1], ENT_QUOTES, 'UTF-8' ), want_date( $row[2], $langs[ $row[2] ], $w[1] ) );
    }
}

/* ---- the toggle, from the source ------------------------------------- */
$js  = file_get_contents( $GLOBALS['kit_root'] . '/public/js/calendar.js' );
$cal = file_get_contents( $GLOBALS['kit_root'] . '/public/css/calendar.css' );
rt( 'the toggle is a globe then a pill holding both buttons', 1 === preg_match( '#class="uc-rsvp-lang-globe".*?<span class="uc-rsvp-lang-pill">\' \+\s*\'<button type="button" lang="en" data-uc-lang-pick="en" aria-pressed="true">English</button>\' \+\s*\'<button type="button" lang="es" data-uc-lang-pick="es" aria-pressed="false">Español</button>\' \+\s*\'</span>#s', $js ), true );
rt( 'the | between them is gone', false === strpos( $js, '<span aria-hidden="true">|</span>' ), true );
rt( 'the form opens on startLang()', false !== strpos( $js, "currentLang = startLang(\$(this).attr('data-uc-lang'));" ), true );
rt( 'the pill styles are not in calendar.css, which the embed loads', false === strpos( $cal, 'uc-rsvp-lang' ), true );

echo "\n3.126.0: the series page's weekdays and the RSVP form's language pill\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. The series page reads \"Wed, Oct 28\" and \"Wed, Oct 28, 2026\", a Spanish event's in Spanish; the toggle is a\nglobe and a pill, and the form opens on startLang().\n";
exit( 0 );
