<?php
/**
 * THE IMPORT PLAN, RUN THROUGH THE PLUGIN'S OWN RECURRENCE ENGINE.
 *
 * There is no WordPress and no database in this build environment, so the half
 * of a dry run that reads the live site has to happen on the live site. This is
 * the other half: every date this import would create, produced by
 * SFAF_Recurrence itself rather than by a second implementation of it.
 *
 * A SELF-TEST FIRST, because a checker that cannot fail is not evidence.
 * --self-test asserts the four seed dates and two nth-weekday series that the
 * report below rests on, against dates worked out by hand.
 *
 *     php .claude/import/dryrun.php --self-test
 *     php .claude/import/dryrun.php [YYYY-MM-DD]
 */

$args  = array_slice( $argv, 1 );
$self  = in_array( '--self-test', $args, true );
$today = '';
foreach ( $args as $a ) {
    if ( '--self-test' !== $a ) {
        $today = $a;
    }
}

/* ---------------------------------------------------------------------------
 * WordPress, reduced to what SFAF_Recurrence::dates() actually calls.
 * ------------------------------------------------------------------------ */
define( 'ABSPATH', __DIR__ );
define( 'SFAF_IMPORT_TZ', 'America/Los_Angeles' );
date_default_timezone_set( SFAF_IMPORT_TZ );

function wp_timezone() {
    return new DateTimeZone( SFAF_IMPORT_TZ );
}
function current_time( $type, $gmt = 0 ) {
    return date( $type );
}
function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ) {
    return date( $format, false === $timestamp_with_offset ? time() : $timestamp_with_offset );
}
function _n( $single, $plural, $n ) {
    return ( 1 === (int) $n ) ? $single : $plural;
}
function sfaf_ap_time( $t ) {
    return (string) $t;
}
function sfaf_ap_date( $when, $style = 'full' ) {
    if ( is_string( $when ) ) {
        $when = ( '' !== trim( $when ) ) ? strtotime( trim( $when ) . ' 12:00:00' ) : false;
    }
    if ( ! $when ) {
        return '';
    }
    $formats = array(
        'full'  => 'l, F j, Y', 'day' => 'F j', 'short' => 'M j', 'short_year' => 'M j, Y',
        'month_year' => 'F Y', 'weekday' => 'D', 'month' => 'M', 'daynum' => 'j',
    );
    return date( isset( $formats[ $style ] ) ? $formats[ $style ] : $formats['full'], (int) $when );
}
function sfaf_ap_time_range( $s, $e ) {
    return trim( $s . '-' . $e );
}

$root = dirname( dirname( __DIR__ ) );
require $root . '/includes/class-sfaf-recurrence.php';
require __DIR__ . '/schedule.php';

$plan = require __DIR__ . '/plan.php';

if ( '' === $today ) {
    $today = date( 'Y-m-d' );
}
$horizon = $plan['horizon'];

/* ---------------------------------------------------------------------------
 * The self-test. Six answers worked out by hand, from a 2026 calendar.
 * ------------------------------------------------------------------------ */
if ( $self ) {
    $fails = 0;
    $check = function ( $label, $got, $want ) use ( &$fails ) {
        $ok = ( $got === $want );
        printf( "%-58s %s\n", $label, $ok ? 'ok' : "FAIL got=" . json_encode( $got ) . " want=" . json_encode( $want ) );
        if ( ! $ok ) {
            $fails++;
        }
    };
    // 2026-09-03 is a Thursday.
    $check( 'first Saturday on or after 2026-09-03',
        sfaf_import_first_date( 'weekly:1:6', '2026-09-03' ), '2026-09-05' );
    $check( 'first Wednesday on or after 2026-09-03',
        sfaf_import_first_date( 'weekly:1:3', '2026-09-03' ), '2026-09-09' );
    $check( 'first Thursday on or after 2026-09-03 is that day',
        sfaf_import_first_date( 'weekly:1:4', '2026-09-03' ), '2026-09-03' );
    $check( 'third Monday on or after 2026-09-03',
        sfaf_import_first_date( 'monthly_nth:3:1', '2026-09-03' ), '2026-09-21' );
    $check( 'first Wednesday of the month, on or after 2026-09-03',
        sfaf_import_first_date( 'monthly_nth:1:3', '2026-09-03' ), '2026-10-07' );
    $check( 'third Tuesday on or after 2026-09-03',
        sfaf_import_first_date( 'monthly_nth:3:2', '2026-09-03' ), '2026-09-15' );
    $check( 'third Wednesdays, 2026-09-03 to 2026-12-31',
        sfaf_import_nth_series( 3, 3, '2026-09-03', '2026-12-31' ),
        array( '2026-09-16', '2026-10-21', '2026-11-18', '2026-12-16' ) );
    // A month whose fifth Friday does not exist.
    $check( 'no fifth Friday in September 2026',
        sfaf_import_nth_of_month( 2026, 9, 5, 5 ), '' );
    $check( 'last Friday of September 2026',
        sfaf_import_nth_of_month( 2026, 9, -1, 5 ), '2026-09-25' );
    echo "\n" . ( $fails ? "FAILED: {$fails}\n" : "self-test passed.\n" );
    exit( $fails ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * The plan, dated.
 * ------------------------------------------------------------------------ */

$total_events = 0;
$total_posts  = 0;
$by_case      = array( 'list' => 0, 'rule' => 0, 'none' => 0 );
$matched      = array();
$unmatched    = array();

echo "IMPORT DRY RUN\n";
echo str_repeat( '=', 78 ) . "\n";
echo "today:   {$today}\n";
echo "horizon: {$horizon}\n";
echo 'plan:    ' . $plan['generated'] . "\n\n";

echo "ORGANIZERS TO RESOLVE OR CREATE\n" . str_repeat( '-', 78 ) . "\n";
foreach ( $plan['organizers'] as $name => $aliases ) {
    printf( "  %-36s %s\n", $name, $aliases ? 'also matches: ' . implode( '; ', $aliases ) : '(no other name)' );
}

echo "\nVENUES TO RESOLVE OR CREATE\n" . str_repeat( '-', 78 ) . "\n";
foreach ( $plan['venues'] as $name => $parts ) {
    printf( "  %-36s %s\n", $name, trim( implode( ', ', array_filter( $parts ) ) ) );
}

echo "\nSERIES AND EVENTS\n" . str_repeat( '-', 78 ) . "\n";
$last_org = '';
foreach ( $plan['series'] as $s ) {
    if ( $s['organizer'] !== $last_org ) {
        echo "\n" . strtoupper( $s['organizer'] ) . "\n";
        $last_org = $s['organizer'];
    }
    echo "  series: {$s['name']}\n";
    foreach ( $s['events'] as $e ) {
        $total_events++;
        $by_case[ $e['case'] ]++;
        $d     = sfaf_import_plan_dates( $e, $today, $horizon );
        $count = ( '' === $d['seed'] ) ? 0 : 1 + count( $d['dates'] );
        $total_posts += $count;

        if ( '' !== $e['source'] ) {
            $matched[] = array( $e['title'], $e['source'] );
        } else {
            $unmatched[] = $e['title'];
        }

        printf( "    - %-52s  %s\n", $e['title'], $e['case'] );
        printf( "      time %s  venue %s  cats %s  image %s  desc %d chars\n",
            ( '' !== $e['start'] ? $e['start'] . '-' . $e['end'] : 'none' ),
            ( $e['online'] ? 'ONLINE' : ( '' !== $e['venue'] ? $e['venue'] : 'none' ) ),
            ( $e['cats'] ? implode( ' + ', array_map( function ( $c ) { return $c[0]; }, $e['cats'] ) ) : 'none' ),
            ( '' !== $e['image'] ? 'yes' : 'no' ),
            strlen( wp_strip_tags_local( $e['content'] ) )
        );
        if ( $count ) {
            $all = array_merge( array( $d['seed'] ), $d['dates'] );
            printf( "      %d dates: %s%s\n", $count,
                implode( ' ', array_slice( $all, 0, 8 ) ),
                count( $all ) > 8 ? ' ... ' . end( $all ) : '' );
            if ( $d['extra'] ) {
                printf( "      of those, %d arrive as chosen dates: %s\n",
                    count( $d['extra'] ), implode( ' ', $d['extra'] ) );
            }
        } else {
            echo "      no dates\n";
        }
        if ( '' !== $d['note'] ) {
            echo "      !! {$d['note']}\n";
        }
    }
}

function wp_strip_tags_local( $html ) {
    return trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $html ) ) );
}

/* ---------------------------------------------------------------------------
 * WHICH IMPORTED DESCRIPTIONS ARE NOT REAL COPY.
 *
 * THE THRESHOLD IS 130 CHARACTERS OF TEXT, and it is unambiguous here rather
 * than arbitrary: the longest thing that is not a description is 66 characters
 * ("For more details, please refer to the Stonewall Services Schedule.") and
 * the shortest thing that is one is 240. Nothing in this import falls between
 * them, so no judgement is being made about a borderline case.
 * ------------------------------------------------------------------------ */
echo "\nDESCRIPTIONS THAT ARE NOT REAL COPY\n" . str_repeat( '-', 78 ) . "\n";
$empty = array();
$stub  = array();
foreach ( $plan['series'] as $s ) {
    foreach ( $s['events'] as $e ) {
        if ( '' === $e['source'] ) {
            continue; // nothing matched, so there was nothing to import.
        }
        $text = wp_strip_tags_local( $e['content'] );
        if ( '' === $text ) {
            $empty[] = $e['title'];
        } elseif ( strlen( $text ) < 130 ) {
            $stub[] = array( $e['title'], $text );
        }
    }
}
echo "  matched but the export's description is empty:\n";
foreach ( $empty as $t ) {
    echo "    - {$t}\n";
}
echo "  matched, imported, and a placeholder:\n";
foreach ( $stub as $row ) {
    printf( "    - %-30s %s\n", $row[0], $row[1] );
}

echo "\n" . str_repeat( '=', 78 ) . "\n";
printf( "series: %d\n", count( $plan['series'] ) );
printf( "events named on the list: %d  (dates from the list %d, from a live rule %d, no dates %d)\n",
    $total_events, $by_case['list'], $by_case['rule'], $by_case['none'] );
printf( "event posts this would create: %d\n", $total_posts );
printf( "matched to an export row: %d   unmatched: %d\n", count( $matched ), count( $unmatched ) );
