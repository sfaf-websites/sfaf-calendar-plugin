<?php
/**
 * THE PHP AND JS RECURRENCE ENGINES, RUN AGAINST EACH OTHER.
 *
 * WHY THIS EXISTS. The summary under the repeat control states how many events
 * a save will create, and generation creates real posts, once. The number
 * therefore has to be the number, and it is produced twice: by
 * SFAF_Recurrence in PHP, which does the creating, and by a mirror in
 * public/js/portal.js, which does the telling. Two implementations of one rule
 * drift. This runs both over a matrix of cases and fails on the first
 * disagreement about a date, a label or a sentence.
 *
 * IT RUNS THE SHIPPING SOURCE, NOT A COPY OF IT. The PHP side loads
 * includes/class-sfaf-recurrence.php with a handful of WordPress stubs. The JS
 * side slices public/js/portal.js between the two "recurrence engine" markers
 * and evaluates what it finds, so a change to the engine that forgets this file
 * still gets checked. If the markers are missing the run fails rather than
 * quietly checking nothing.
 *
 * SELF-TEST FIRST. --self-test plants three disagreements (a date, a count and
 * a label) into the JS side and requires all three to be caught. A checker that
 * cannot fail is not evidence, and this repository has shipped two of those.
 *
 * Usage:
 *   php .claude/recurrence-crosscheck.php --self-test
 *   php .claude/recurrence-crosscheck.php [repo-root]
 */

$root = '.';
$self_test = false;
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( '--self-test' === $arg ) { $self_test = true; } else { $root = rtrim( $arg, '/\\' ); }
}
$root = rtrim( $root, '/\\' );

/* ---------------------------------------------------------------------------
 * WordPress, reduced to what the engine actually calls.
 *
 * Only these six. If the engine grows a call to something else, PHP fails with
 * an undefined function rather than this file quietly stubbing more of WP than
 * it needs to and hiding a real dependency.
 * ------------------------------------------------------------------------ */
define( 'ABSPATH', __DIR__ );
define( 'SFAF_XCHECK_TZ', 'America/Los_Angeles' );
date_default_timezone_set( SFAF_XCHECK_TZ );

// SIGNATURES MATCH WORDPRESS'S, optional arguments included. A stub that takes
// fewer arguments than the real function is a stub the callable audit reads as
// the definition, and it then reports every correct call site in the plugin as
// an arity error. That happened the first time this file was written.
function wp_timezone() { return new DateTimeZone( SFAF_XCHECK_TZ ); }
function current_time( $type, $gmt = 0 ) { return date( $type ); }
function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ) {
    return date( $format, false === $timestamp_with_offset ? time() : $timestamp_with_offset );
}
function _n( $single, $plural, $n ) { return ( 1 === (int) $n ) ? $single : $plural; }
function sfaf_ap_time( $t ) { return (string) $t; }
/* THE REAL BEHAVIOUR, NOT A PASS-THROUGH, because pattern_label() now takes its
   day number from here and this file asserts on the words that come out. The
   styles are the ones the recurrence code asks for; the midday anchor is
   sfaf_ap_date()'s, and it is the reason a date-only string does not slip a day. */
function sfaf_ap_date( $when, $style = 'full' ) {
    if ( is_string( $when ) ) {
        $when = ( '' !== trim( $when ) ) ? strtotime( trim( $when ) . ' 12:00:00' ) : false;
    }
    if ( ! $when ) { return ''; }
    $formats = array(
        'full' => 'l, F j, Y', 'day' => 'F j', 'short' => 'M j', 'short_year' => 'M j, Y',
        'month_year' => 'F Y', 'weekday' => 'D', 'month' => 'M', 'daynum' => 'j',
    );
    return date( isset( $formats[ $style ] ) ? $formats[ $style ] : $formats['full'], (int) $when );
}
function sfaf_ap_time_range( $s, $e ) { return trim( $s . '-' . $e ); }

require $root . '/includes/class-sfaf-recurrence.php';

/* ---------------------------------------------------------------------------
 * The JS engine, sliced out of portal.js and run through node.
 * ------------------------------------------------------------------------ */
function js_engine_source( $root ) {
    $js    = file_get_contents( $root . '/public/js/portal.js' );
    $start = strpos( $js, '/* --8<-- recurrence engine start --8<-- */' );
    $end   = strpos( $js, '/* --8<-- recurrence engine end --8<-- */' );
    if ( false === $start || false === $end || $end <= $start ) {
        fwrite( STDERR, "FAIL: the recurrence engine markers are missing from public/js/portal.js.\n" );
        fwrite( STDERR, "Nothing was checked. Restore them around the engine functions.\n" );
        exit( 2 );
    }
    return substr( $js, $start, $end - $start );
}

/**
 * Run the JS engine over the cases and return its answers.
 *
 * @param string $root
 * @param array  $cases
 * @param string $sabotage Extra JS appended to the engine, for the self test.
 * @return array
 */
function run_js( $root, $cases, $sabotage = '' ) {
    $script = js_engine_source( $root ) . "\n" . $sabotage . "\n"
        . "var CASES = " . json_encode( $cases ) . ";\n"
        . "var out = CASES.map(function (c) {\n"
        . "    return {\n"
        . "        dates: ucRecurrenceDates(c.start, c.end, c.spec, c.limit, c.extra),\n"
        . "        label: c.spec ? ucRecurrenceLabel(c.spec, c.start) : '',\n"
        . "        summary: ucRecurrenceSummary(c.start, c.end, c.spec, c.limit, c.extra, c.tail)\n"
        . "    };\n"
        . "});\n"
        . "process.stdout.write(JSON.stringify(out));\n";

    $tmp = sys_get_temp_dir() . '/sfaf-xcheck-' . getmypid() . '.js';
    file_put_contents( $tmp, $script );
    $raw = shell_exec( 'node ' . escapeshellarg( $tmp ) . ' 2>&1' );
    unlink( $tmp );

    $got = json_decode( (string) $raw, true );
    if ( ! is_array( $got ) ) {
        fwrite( STDERR, "FAIL: the JS engine did not run.\n" . $raw . "\n" );
        exit( 2 );
    }
    return $got;
}

/** The PHP engine's answers for the same cases. */
function run_php( $cases ) {
    $out = array();
    foreach ( $cases as $c ) {
        $pattern = $c['pattern'];
        $out[] = array(
            'dates'   => SFAF_Recurrence::dates( $c['start'], $c['end'], $pattern, $c['limit'], $c['extra'] ),
            'label'   => ( '' !== $pattern ) ? SFAF_Recurrence::pattern_label( $pattern, $c['start'] ) : '',
            'summary' => SFAF_Recurrence::summary( $c['start'], $c['end'], $pattern, $c['limit'], $c['extra'], $c['tail'] ),
        );
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * The matrix.
 *
 * Every pattern shape the engine can hold, against start dates chosen to land
 * on the awkward cases: a 31st (monthly overflow), a February in a leap year, a
 * month with five Fridays and one with four, a year boundary.
 *
 * EXTRA DATES ARE CROSSED WITH ALL OF IT rather than tested on their own,
 * because the interesting cases are the interactions: an extra date the pattern
 * already produces (must not double), one before the start (must be dropped),
 * one after the end date (must be kept, since it was chosen explicitly), and a
 * duplicate in the list.
 * ------------------------------------------------------------------------ */
function build_cases() {
    $starts = array(
        '2026-01-31', // month-end overflow
        '2026-02-29', // not a real date; both engines must refuse it identically
        '2024-02-29', // a real leap day
        '2026-07-01', // a Wednesday
        '2026-10-30', // a fifth Friday month
        '2026-12-28', // crosses a year boundary
    );
    $patterns = array(
        '', 'custom',
        'daily', 'daily:3',
        'weekly', 'biweekly', 'weekly:1:2,4', 'weekly:3:1', 'weekly:1:0,3,6',
        'monthly', 'monthly:2',
        'monthly_nth', 'monthly_nth:1:1', 'monthly_nth:-1:5', 'monthly_nth:5:5',
    );
    $ends = array(
        array( '', 12 ),
        array( '', 52 ),
        array( '2026-12-31', 0 ),
        array( '2027-06-30', 0 ),
        array( '', 1 ),
        array( '2026-01-01', 0 ), // an end before the start
    );
    $extras = array(
        array(),
        array( '2026-08-15' ),
        array( '2026-08-15', '2026-09-19' ),
        array( '2026-08-15', '2026-08-15' ),          // duplicate in the list
        array( '2026-07-08' ),                        // a date several patterns also make
        array( '2020-01-01' ),                        // before every start: dropped
        array( '2030-01-01' ),                        // beyond every end: kept
        array( 'not-a-date', '2026-02-31', '' ),      // junk and an impossible day
    );

    $cases = array();
    foreach ( $starts as $start ) {
        foreach ( $patterns as $pattern ) {
            foreach ( $ends as $e ) {
                foreach ( $extras as $extra ) {
                    $cases[] = array(
                        'start'   => $start,
                        'end'     => $e[0],
                        'limit'   => $e[1],
                        'pattern' => $pattern,
                        'spec'    => SFAF_Recurrence::parse_pattern( $pattern ),
                        'extra'   => $extra,
                        'tail'    => ( '' !== $e[0] ) ? ', until ' . $e[0] : ', for a year',
                    );
                }
            }
        }
    }

    // The empty-start case, which is a prompt rather than a count on both sides.
    $cases[] = array(
        'start' => '', 'end' => '', 'limit' => 12, 'pattern' => 'weekly',
        'spec' => SFAF_Recurrence::parse_pattern( 'weekly' ), 'extra' => array(), 'tail' => '',
    );
    return $cases;
}

/** Compare, and describe the first few disagreements. */
function compare( $cases, $php, $js ) {
    $bad = array();
    foreach ( $cases as $i => $c ) {
        foreach ( array( 'dates', 'label', 'summary' ) as $what ) {
            $a = $php[ $i ][ $what ];
            $b = isset( $js[ $i ][ $what ] ) ? $js[ $i ][ $what ] : null;
            if ( 'dates' === $what ) {
                $a = array_values( (array) $a );
                $b = array_values( (array) $b );
            }
            if ( $a !== $b ) {
                $bad[] = array(
                    'case' => $c,
                    'what' => $what,
                    'php'  => $a,
                    'js'   => $b,
                );
            }
        }
    }
    return $bad;
}

function describe( $f ) {
    $c = $f['case'];
    $line = sprintf(
        "  %s  start=%s end=%s limit=%d pattern=%s extra=[%s]\n",
        strtoupper( $f['what'] ),
        $c['start'] ?: '(none)',
        $c['end'] ?: '(none)',
        $c['limit'],
        $c['pattern'] ?: '(none)',
        implode( ',', $c['extra'] )
    );
    $fmt = function ( $v ) {
        if ( is_array( $v ) ) {
            return count( $v ) . ' dates: ' . ( count( $v ) > 8
                ? implode( ',', array_slice( $v, 0, 8 ) ) . ',…'
                : implode( ',', $v ) );
        }
        return (string) $v;
    };
    $line .= '      PHP: ' . $fmt( $f['php'] ) . "\n";
    $line .= '       JS: ' . $fmt( $f['js'] ) . "\n";
    return $line;
}

/* ---------------------------------------------------------------------------
 * WHAT THE ANSWER SHOULD BE, not merely that both engines say the same thing.
 *
 * TWO ENGINES AGREEING ON THE WRONG ANSWER IS THE FAILURE MODE THE MATRIX
 * CANNOT SEE. It compares PHP to JS; if a rule is mirrored faithfully and is
 * wrong, every one of the 4000-odd cases passes. So the rules that were written
 * in prose are asserted here as values: what a date on the start day does, what
 * a duplicate does, what a date beyond the end date does, and what each of the
 * three summary shapes reads like.
 *
 * These run on every invocation, before the matrix, because a matrix that
 * passes against a broken rule is worse than no matrix.
 * ------------------------------------------------------------------------ */
/*
 * THE COUNT IS COUNTED, NOT WRITTEN DOWN. It used to be a literal 20 in the
 * printf below, which is a number that stays right until somebody adds a rule
 * and stops being evidence the moment it is wrong. A hardcoded total also
 * cannot tell "every rule ran and held" from "the block was never reached",
 * which is the failure this line is supposed to rule out.
 */
$GLOBALS['sfaf_behaviour_ran'] = 0;

function assert_behaviour() {
    $fails = array();
    $check = function ( $what, $got, $want ) use ( &$fails ) {
        $GLOBALS['sfaf_behaviour_ran']++;
        if ( $got !== $want ) {
            $fails[] = sprintf(
                "  %s\n      got:  %s\n      want: %s\n",
                $what,
                is_array( $got ) ? '[' . implode( ',', $got ) . ']' : var_export( $got, true ),
                is_array( $want ) ? '[' . implode( ',', $want ) . ']' : var_export( $want, true )
            );
        }
    };

    // --- clean_dates: the four reductions ---------------------------------
    $check( 'a date equal to the start is dropped',
        SFAF_Recurrence::clean_dates( array( '2026-07-01' ), '2026-07-01' ), array() );
    $check( 'a date before the start is dropped',
        SFAF_Recurrence::clean_dates( array( '2026-06-30' ), '2026-07-01' ), array() );
    $check( 'duplicates collapse and the result is sorted',
        SFAF_Recurrence::clean_dates( array( '2026-09-01', '2026-08-01', '2026-09-01' ), '2026-07-01' ),
        array( '2026-08-01', '2026-09-01' ) );
    $check( 'an impossible day is dropped, not rolled forward',
        SFAF_Recurrence::clean_dates( array( '2026-02-31', 'nonsense', '' ), '2026-01-01' ), array() );

    // --- custom: no cadence, the list is everything -----------------------
    $check( 'custom parses to a type of its own',
        SFAF_Recurrence::parse_pattern( 'custom' )['type'], 'custom' );
    $check( 'custom produces no dates of its own',
        SFAF_Recurrence::dates( '2026-07-01', '2026-12-31', 'custom', 52, array() ), array() );
    $check( 'custom returns exactly the dates it was given',
        SFAF_Recurrence::dates( '2026-07-01', '', 'custom', 0, array( '2026-08-04', '2026-07-14' ) ),
        array( '2026-07-14', '2026-08-04' ) );
    $check( 'a custom group has no cadence, so no pattern edit acts on it',
        SFAF_Recurrence::has_cadence( 'custom' ), false );
    $check( 'a real cadence says so', SFAF_Recurrence::has_cadence( 'weekly:2:3' ), true );

    // --- canonical_pattern: two spellings of one schedule ------------------
    // 2026-07-01 is a Wednesday, and it is the 1st, so it is the first
    // Wednesday of its month.
    $check( 'a bare weekly resolves to the anchor\'s own weekday',
        SFAF_Recurrence::canonical_pattern( 'weekly', '2026-07-01' ), 'weekly:1:3' );
    $check( 'biweekly and weekly:2 are one schedule',
        SFAF_Recurrence::canonical_pattern( 'biweekly', '2026-07-01' ), 'weekly:2:3' );
    $check( 'a bare monthly_nth resolves to the anchor\'s ordinal and weekday',
        SFAF_Recurrence::canonical_pattern( 'monthly_nth', '2026-07-01' ), 'monthly_nth:1:3' );
    $check( 'an explicit pattern canonicalises to itself',
        SFAF_Recurrence::canonical_pattern( 'weekly:1:2,4', '2026-07-01' ), 'weekly:1:2,4' );

    // --- plan_from: the anchor's own period is IN the answer ---------------
    // This is the whole difference from dates(), which excludes the seed.
    $check( 'the anchor week is laid out, not skipped',
        SFAF_Recurrence::plan_from( 'weekly:1:2', '2026-07-01', 3 ),
        array( '2026-06-30', '2026-07-07', '2026-07-14' ) );
    $check( 'the floor drops the leading date and the count still comes out',
        SFAF_Recurrence::plan_from( 'weekly:1:2', '2026-07-01', 3, '2026-07-01' ),
        array( '2026-07-07', '2026-07-14', '2026-07-21' ) );
    $check( 'an interval walks in blocks from the anchor\'s week',
        SFAF_Recurrence::plan_from( 'weekly:2:3', '2026-07-01', 3 ),
        array( '2026-07-01', '2026-07-15', '2026-07-29' ) );
    $check( 'two days a week come out in date order',
        SFAF_Recurrence::plan_from( 'weekly:1:2,4', '2026-07-01', 4 ),
        array( '2026-06-30', '2026-07-02', '2026-07-07', '2026-07-09' ) );
    $check( 'the second Tuesday of each month',
        SFAF_Recurrence::plan_from( 'monthly_nth:2:2', '2026-07-01', 3 ),
        array( '2026-07-14', '2026-08-11', '2026-09-08' ) );
    $check( 'the last Friday of each month',
        SFAF_Recurrence::plan_from( 'monthly_nth:-1:5', '2026-07-01', 3 ),
        array( '2026-07-31', '2026-08-28', '2026-09-25' ) );
    $check( 'daily counts from the anchor itself',
        SFAF_Recurrence::plan_from( 'daily:3', '2026-07-01', 3 ),
        array( '2026-07-01', '2026-07-04', '2026-07-07' ) );
    $check( 'custom cannot be laid out, because there is no arithmetic in it',
        SFAF_Recurrence::plan_from( 'custom', '2026-07-01', 3 ), array() );

    // --- extras beside a pattern ------------------------------------------
    // 2026-07-01 is a Wednesday. Weekly to the 29th makes the 8th, 15th, 22nd
    // and 29th.
    $weekly = SFAF_Recurrence::dates( '2026-07-01', '2026-07-29', 'weekly', 0, array() );
    $check( 'the pattern alone', $weekly,
        array( '2026-07-08', '2026-07-15', '2026-07-22', '2026-07-29' ) );

    $check( 'an extra date is merged in date order, not appended',
        SFAF_Recurrence::dates( '2026-07-01', '2026-07-29', 'weekly', 0, array( '2026-07-18' ) ),
        array( '2026-07-08', '2026-07-15', '2026-07-18', '2026-07-22', '2026-07-29' ) );

    $check( 'an extra date the pattern already makes is not created twice',
        SFAF_Recurrence::dates( '2026-07-01', '2026-07-29', 'weekly', 0, array( '2026-07-15' ) ),
        $weekly );

    $check( 'an extra date beyond the end date is kept, because it was chosen',
        SFAF_Recurrence::dates( '2026-07-01', '2026-07-29', 'weekly', 0, array( '2026-11-21' ) ),
        array( '2026-07-08', '2026-07-15', '2026-07-22', '2026-07-29', '2026-11-21' ) );

    // --- the three summary shapes -----------------------------------------
    $check( 'no pattern',
        SFAF_Recurrence::summary( '2026-07-01', '', '', 0, array(), '' ),
        'Does not repeat. One event will be created.' );

    $check( 'custom, counting the event itself as one of the dates',
        SFAF_Recurrence::summary( '2026-07-01', '', 'custom', 0,
            array( '2026-07-14', '2026-08-04', '2026-08-19', '2026-09-02' ), '' ),
        '5 dates. 5 events will be created.' );

    $check( 'custom with nothing chosen prompts instead of counting',
        SFAF_Recurrence::summary( '2026-07-01', '', 'custom', 0, array(), '' ),
        'Add the dates this happens on.' );

    $check( 'a pattern on its own',
        SFAF_Recurrence::summary( '2026-07-01', '2026-12-31', 'weekly', 0, array(), ', until Dec 31 2026' ),
        'Every week on Wednesday, until Dec 31 2026. 27 events will be created.' );

    $check( 'a pattern plus extras names both, and the count covers both',
        SFAF_Recurrence::summary( '2026-07-01', '2026-12-31', 'weekly', 0,
            array( '2026-08-15', '2026-09-19' ), ', until Dec 31 2026' ),
        'Every week on Wednesday, until Dec 31 2026, plus 2 extra dates. 29 events will be created.' );

    $check( 'one extra date is singular',
        SFAF_Recurrence::summary( '2026-07-01', '2026-12-31', 'weekly', 0,
            array( '2026-08-15' ), ', until Dec 31 2026' ),
        'Every week on Wednesday, until Dec 31 2026, plus 1 extra date. 28 events will be created.' );

    // 2026-08-12 is a Wednesday, so the weekly pattern already makes it. It is
    // therefore not an extra date at all, and the sentence must not claim one.
    $check( 'an extra the pattern already covers is neither counted nor announced',
        SFAF_Recurrence::summary( '2026-07-01', '2026-12-31', 'weekly', 0,
            array( '2026-08-12' ), ', until Dec 31 2026' ),
        'Every week on Wednesday, until Dec 31 2026. 27 events will be created.' );

    $check( 'a mixed list announces only the ones that add a date',
        SFAF_Recurrence::summary( '2026-07-01', '2026-12-31', 'weekly', 0,
            array( '2026-08-12', '2026-08-15' ), ', until Dec 31 2026' ),
        'Every week on Wednesday, until Dec 31 2026, plus 1 extra date. 28 events will be created.' );

    return $fails;
}

$behaviour = assert_behaviour();
if ( ! empty( $behaviour ) ) {
    printf( "BEHAVIOUR: %d rule(s) do not hold. The matrix is not run.\n\n", count( $behaviour ) );
    foreach ( $behaviour as $f ) { echo $f; }
    exit( 1 );
}
printf(
    "behaviour: %d rules hold (reductions, custom, canonical forms, plan_from, extras, all three summary shapes)\n",
    (int) $GLOBALS['sfaf_behaviour_ran']
);

/* ------------------------------- self test -------------------------------- */
if ( $self_test ) {
    // Three planted disagreements, one of each kind. Each redefines a function
    // AFTER the engine has defined it, so the engine source itself is untouched.
    $plants = array(
        'a wrong date'  => 'var _d = ucRecurrenceDates; ucRecurrenceDates = function (s, e, sp, l, x) {'
            . ' var r = _d(s, e, sp, l, x); if (r.length > 2) { r[2] = "1999-01-01"; } return r; };',
        'a wrong count' => 'var _s = ucRecurrenceSummary; ucRecurrenceSummary = function (s, e, sp, l, x, t) {'
            . ' return _s(s, e, sp, l, x, t).replace(/(\d+) events will/, function (m, n) {'
            . ' return (Number(n) + 1) + " events will"; }); };',
        'a wrong label' => 'var _l = ucRecurrenceLabel; ucRecurrenceLabel = function (sp, s) {'
            . ' return sp && sp.type === "daily" ? "Every single day" : _l(sp, s); };',
    );

    $cases = build_cases();
    $php   = run_php( $cases );
    $ok    = true;

    foreach ( $plants as $name => $js_sabotage ) {
        $js  = run_js( $root, $cases, $js_sabotage );
        $bad = compare( $cases, $php, $js );
        if ( empty( $bad ) ) {
            fwrite( STDERR, "SELF-TEST FAIL: planted {$name} and the check did not notice.\n" );
            $ok = false;
        } else {
            printf( "  planted %-14s caught (%d disagreement(s))\n", $name, count( $bad ) );
        }
    }

    // And the clean run must be clean, or the plants prove nothing.
    $js  = run_js( $root, $cases );
    $bad = compare( $cases, $php, $js );
    if ( ! empty( $bad ) ) {
        fwrite( STDERR, "SELF-TEST FAIL: the unsabotaged run disagreed, so the plants prove nothing.\n" );
        foreach ( array_slice( $bad, 0, 5 ) as $f ) { fwrite( STDERR, describe( $f ) ); }
        $ok = false;
    } else {
        echo "  clean run agrees, so the three catches above are real\n";
    }

    if ( ! $ok ) { exit( 2 ); }
    echo "self-test passed: 3 planted disagreements found, clean run clean.\n";
    exit( 0 );
}

/* --------------------------------- real run ------------------------------- */
$cases = build_cases();
$php   = run_php( $cases );
$js    = run_js( $root, $cases );
$bad   = compare( $cases, $php, $js );

$with_extra = 0;
$custom     = 0;
foreach ( $cases as $c ) {
    if ( ! empty( $c['extra'] ) ) { $with_extra++; }
    if ( 'custom' === $c['pattern'] ) { $custom++; }
}

printf(
    "Recurrence cross-check\nRoot: %s\ncases: %d   (%d carry extra dates, %d are Custom)\nchecked per case: dates, label, summary\n\n",
    $root, count( $cases ), $with_extra, $custom
);

if ( empty( $bad ) ) {
    echo "the PHP and JS engines agree on every date, every label and every count.\n";
    exit( 0 );
}

printf( "%d DISAGREEMENT(S):\n\n", count( $bad ) );
foreach ( array_slice( $bad, 0, 20 ) as $f ) {
    echo describe( $f );
}
if ( count( $bad ) > 20 ) {
    printf( "\n… and %d more.\n", count( $bad ) - 20 );
}
exit( 1 );
