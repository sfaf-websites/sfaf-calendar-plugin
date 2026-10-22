<?php
/**
 * EVERY RECORDED TIME IS SHOWN IN PACIFIC TIME, AS WORDPRESS ITSELF FORMATS (3.113.8).
 *
 *     php .claude/pacific-time-test.php
 *
 * WHY THIS HAS ITS OWN WORDPRESS. Every other check stubs date_i18n() as PHP's
 * date() on a Los Angeles clock, which is not what WordPress does. WordPress
 * runs PHP on UTC, and date_i18n() reads a timestamp it is given as one that
 * already carries the site's offset, formatting its UTC wall clock. So a real
 * moment, time(), came out seven or eight hours ahead on the site: "Last run
 * ... at 6:49 pm" at 11:49 am Pacific. The stubs agreed with the code, so
 * nothing caught it. This file models date_i18n() and wp_date() as WordPress
 * 6 writes them, PHP on UTC and the site on America/Los_Angeles, and runs the
 * shipped formatters, sliced from the file every run.
 *
 *   1  a fetch or run time (time()) reads in Pacific time, summer and winter,
 *      including the evening hours that are already tomorrow in UTC
 *   2  a site-clock string (a registration's created_at) reads as written
 *   3  a date alone still reads as its own day
 *   4  the fetch and run screens and the Pending screen's Received date go
 *      through it: SFAF_Cron::local_time(), the EveryAction panel and log,
 *      and wp_date() for Received
 *
 * PLANT 2 in plant-31138.php.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'UTC' );

function wp_timezone() { return new DateTimeZone( 'America/Los_Angeles' ); }
/* wp-includes/functions.php, WordPress 6: wp_date() converts a real moment. */
function wp_date( $format, $timestamp = null, $timezone = null ) {
    $timezone = $timezone ? $timezone : wp_timezone();
    $dt = date_create( '@' . ( null === $timestamp ? time() : (int) $timestamp ) );
    $dt->setTimezone( $timezone );
    return $dt->format( $format );
}
/* And date_i18n(): a given timestamp is "with offset", formatted as UTC wall time. */
function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ) {
    $timestamp = $timestamp_with_offset;
    if ( ! is_numeric( $timestamp ) ) {
        $timestamp = time() + wp_timezone()->getOffset( new DateTime() );
    }
    if ( 'U' === $format ) {
        return $timestamp;
    }
    if ( false === $timestamp_with_offset ) {
        return wp_date( $format );
    }
    $local_time = gmdate( 'Y-m-d H:i:s', (int) $timestamp );
    $datetime   = date_create( $local_time, wp_timezone() );
    return wp_date( $format, $datetime->getTimestamp(), wp_timezone() );
}

$tpl = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
function pt_slice( $src, $name ) {
    if ( ! preg_match( '#\nfunction ' . preg_quote( $name, '#' ) . '\s*\(#', $src, $m, PREG_OFFSET_CAPTURE ) ) {
        return '';
    }
    $start = $m[0][1];
    $depth = 0;
    for ( $i = strpos( $src, '{', $start ), $n = strlen( $src ); $i < $n; $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] && 0 === --$depth ) {
            return substr( $src, $start, $i - $start + 1 );
        }
    }
    return '';
}
$code = '';
foreach ( array( 'sfaf_local_timestamp', 'sfaf_ap_date', 'sfaf_ap_date_es', 'sfaf_ap_time', 'sfaf_ap_meridiem', 'sfaf_ap_time_zone', 'sfaf_ap_zoned', 'sfaf_ap_datetime' ) as $fn ) {
    $s = pt_slice( $tpl, $fn );
    if ( '' === $s ) { echo "Cannot run: $fn was not found in sfaf-template-functions.php\n"; exit( 1 ); }
    $code .= $s . "\n";
}
eval( $code );

$fails = array();
function pt( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function utc( $s ) { return ( new DateTime( $s, new DateTimeZone( 'UTC' ) ) )->getTimestamp(); }

/* ======================================================================== 1 */
pt( '1: Mark\'s "Last run", 18:49 UTC in October', sfaf_ap_datetime( utc( '2026-10-22 18:49' ) ), 'Oct 22, 2026 at 11:49 am' );
pt( '1: an evening run, already tomorrow in UTC', sfaf_ap_datetime( utc( '2026-10-23 03:15' ) ), 'Oct 22, 2026 at 8:15 pm' );
pt( '1: winter, eight hours behind', sfaf_ap_datetime( utc( '2026-12-05 02:00' ) ), 'Dec 4, 2026 at 6 pm' );
pt( '1: with the zone', sfaf_ap_datetime( utc( '2026-10-22 18:49' ), 'short_year', 'en', true ), 'Oct 22, 2026 at 11:49 am PT' );
pt( '1: in Spanish', sfaf_ap_datetime( utc( '2026-10-23 03:15' ), 'short_year', 'es' ), '22 oct. 2026 a las 8:15 p. m.' );

/* ======================================================================== 2 */
pt( '2: a registration at nine in the evening', sfaf_ap_datetime( '2026-10-22 21:00:00' ), 'Oct 22, 2026 at 9 pm' );
pt( '2: and in the morning', sfaf_ap_datetime( '2026-01-15 08:30:00' ), 'Jan 15, 2026 at 8:30 am' );

/* ======================================================================== 3 */
pt( '3: a date alone', sfaf_ap_date( '2026-10-22', 'short_year' ), 'Oct 22, 2026' );
pt( '3: the Received date of an evening import', sfaf_ap_date( wp_date( 'Y-m-d', utc( '2026-10-23 03:15' ) ), 'short_year' ), 'Oct 22, 2026' );

/* ======================================================================== 4 */
$cron   = file_get_contents( $root . '/includes/class-sfaf-cron.php' );
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$admin  = file_get_contents( $root . '/admin/class-sfaf-admin.php' );
$ea     = file_get_contents( $root . '/includes/class-sfaf-everyaction.php' );
pt( '4: SFAF_Cron::local_time() is the formatter', (bool) preg_match( '#function local_time\( \$timestamp \) \{\s*if \( ! \$timestamp \) \{\s*return \'never\';\s*\}\s*return sfaf_ap_datetime\( \(int\) \$timestamp \);#', $cron ), true );
pt( '4: the fetch panels use it', 2 === substr_count( $portal, 'SFAF_Cron::local_time( $fetch[' ), true );
pt( '4: the EveryAction panel and log use the formatter', substr_count( $admin, 'sfaf_ap_datetime( (int) $ea_' ) >= 3 && substr_count( $ea, 'sfaf_ap_datetime( (int) $f[\'at\'] )' ) === 2, true );
pt( '4: Received is the site\'s day', 2 === substr_count( $portal, "sfaf_ap_date( wp_date( 'Y-m-d', \$this->pending_received( \$id ) ), 'short_year' )" ), true );
pt( '4: nothing formats a recorded moment with date_i18n() any more', false === strpos( pt_slice( $tpl, 'sfaf_ap_datetime' ), 'date_i18n' ), true );

echo "\nRecorded times in Pacific time, formatted as WordPress formats them\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "A fetch or run time reads in Pacific time in summer and winter and in the evening; a stored site-clock time reads as written;\na date is its own day; and the run, fetch, EveryAction and Pending screens all go through the one formatter.\n";
exit( 0 );
