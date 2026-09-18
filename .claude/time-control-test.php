<?php
/**
 * THE TIME CONTROL: TWELVE MINUTES, AND THE SAME VALUE OUT AS IN.
 *
 *     php .claude/time-control-test.php
 *     php .claude/time-control-test.php --self-test
 *
 * WHY THE OLD ONE WENT. `step="300"` on an `<input type="time">` is a
 * VALIDATION rule. Every browser enforces it on submit and no browser makes its
 * spinner or its dropdown honour it, so the attribute refused 6:07 while still
 * taking thirty presses to reach 6:30. It was asked for twice and "fixed"
 * twice, both times by setting that attribute.
 *
 * WHAT THIS CHECKS, AND THE LOAD-BEARING ONE IS THE LAST. The round trip: a
 * value rendered into the control and posted back must come out identical, for
 * every hour and every minute on the grid, and for the off-grid values the
 * import left behind. A control that quietly moved somebody's 6:07 to 6:05
 * would look perfect on screen.
 *
 * .claude/time-control-live.php drives the rendered control in a browser and
 * counts what the picker actually offers.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$self_test = in_array( '--self-test', $argv, true );
$fails     = array();
function check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function selected( $a, $b, $echo = true ) {
    $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
    if ( $echo ) { echo $out; }
    return $out;
}
function wp_unslash( $v ) { return $v; }
function date_i18n( $format, $ts = false, $gmt = false ) { return date( $format, false === $ts ? time() : $ts ); }

date_default_timezone_set( 'America/Los_Angeles' );

/* The real functions, sliced out of the shipped file so this tests them rather
 * than a copy. sfaf_ap_time() comes with them because the hour labels go
 * through it. */
$tpl   = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
$slice = function ( $name ) use ( $tpl ) {
    $at = strpos( $tpl, 'function ' . $name . '(' );
    if ( false === $at ) { return ''; }
    $depth = 0;
    for ( $i = $at; $i < strlen( $tpl ); $i++ ) {
        if ( '{' === $tpl[ $i ] ) { $depth++; }
        if ( '}' === $tpl[ $i ] ) { $depth--; if ( 0 === $depth ) { return substr( $tpl, $at, $i - $at + 1 ); } }
    }
    return '';
};

foreach ( array(
    'sfaf_ap_time',
    'sfaf_time_minutes',
    'sfaf_time_parts',
    'sfaf_time_hour_label',
    'sfaf_time_field',
    'sfaf_normalize_time_post',
) as $fn ) {
    $code = $slice( $fn );
    if ( '' === $code ) {
        echo "FAIL: $fn() could not be sliced out of sfaf-template-functions.php\n";
        exit( 1 );
    }
    eval( $code );
}

/* ---------------------------------------------------------------------------
 * 1. TWELVE MINUTES, AND NO MORE.
 * ------------------------------------------------------------------------ */
$mins = sfaf_time_minutes();
check( 12 === count( $mins ), 'the minute grid is not twelve entries, it is ' . count( $mins ) );
check( array( '00','05','10','15','20','25','30','35','40','45','50','55' ) === $mins,
    'the minute grid is not the twelve five-minute entries' );

/* ---------------------------------------------------------------------------
 * 2. THE RENDERED CONTROL.
 * ------------------------------------------------------------------------ */
$html = sfaf_time_field( 'start_time', '18:30', array( 'label' => 'Start time' ) );

check( false !== strpos( $html, 'name="start_time_h"' ), 'the hour list is not posted under <name>_h' );
check( false !== strpos( $html, 'name="start_time_m"' ), 'the minute list is not posted under <name>_m' );
check( 2 === substr_count( $html, '<select' ), 'the control is not exactly two lists' );
check( false === strpos( $html, 'type="time"' ), 'the browser time input is still there' );
check( false === strpos( $html, 'step="300"' ),
    'the five-minute step attribute is back, and it is the thing that never worked' );

/* TWENTY-FOUR HOURS, ONE PER HOUR, PLUS THE EMPTY ONE. */
preg_match( '#<select name="start_time_h".*?</select>#s', $html, $hsel );
check( ! empty( $hsel ), 'the hour list could not be read back' );
$hours = substr_count( $hsel[0], '<option' );
check( 25 === $hours, 'the hour list offers ' . $hours . ' entries, not twenty-four and an empty one' );

/* TWELVE MINUTES ON A GRID VALUE, PLUS THE EMPTY ONE. */
preg_match( '#<select name="start_time_m".*?</select>#s', $html, $msel );
check( ! empty( $msel ), 'the minute list could not be read back' );
$mopts = substr_count( $msel[0], '<option' );
check( 13 === $mopts, 'the minute list offers ' . $mopts . ' entries, not twelve and an empty one' );

/* THE STORED VALUE IS THE ONE SELECTED. */
check( (bool) preg_match( '/<option value="18"[^>]*selected/', $html ), '18:30 does not select the 18 hour' );
check( (bool) preg_match( '/<option value="30"[^>]*selected/', $html ), '18:30 does not select the 30 minute' );

/* LABELLED AS ONE CONTROL. */
check( false !== strpos( $html, 'role="group"' ), 'the pair is not announced as one control' );
check( false !== strpos( $html, 'aria-label="Start time"' ), 'the pair has no accessible name' );
check( false !== strpos( $html, 'aria-label="Start time, hour"' ), 'the hour list has no name of its own' );
check( false !== strpos( $html, 'aria-label="Start time, minute"' ), 'the minute list has no name of its own' );

/* THE HOUR LABELS GO THROUGH THE ONE FORMATTER. */
check( false !== strpos( $html, '>6 pm<' ), 'the hour list does not read in the calendar\'s own time style' );
check( false !== strpos( $html, '>12 am<' ), 'midnight is not offered, or not in the calendar\'s style' );

/* REQUIRED AND DISABLED TRAVEL TO BOTH LISTS. */
$req = sfaf_time_field( 'start_time', '', array( 'required' => true ) );
check( 2 === substr_count( $req, ' required' ), 'required does not reach both lists, so half the control can be skipped' );
$dis = sfaf_time_field( 'start_time', '09:00', array( 'disabled' => ' disabled' ) );
check( 2 === substr_count( $dis, ' disabled' ), 'a locked field leaves one of the two lists editable' );

/* ---------------------------------------------------------------------------
 * 3. AN OFF-GRID TIME KEEPS ITS EXACT MINUTE.
 *
 * THE IMPORT WROTE TIMES NOBODY HERE HAS READ EVERY ROW OF. Rounding one into
 * the form would change somebody's answer without telling them.
 * ------------------------------------------------------------------------ */
$odd = sfaf_time_field( 'start_time', '18:07', array( 'label' => 'Start time' ) );
preg_match( '#<select name="start_time_m".*?</select>#s', $odd, $osel );
check( 14 === substr_count( $osel[0], '<option' ),
    'an off-grid minute does not join the list, so the control cannot show the time the event has' );
check( (bool) preg_match( '/<option value="07"[^>]*selected/', $odd ),
    'an off-grid minute is not selected, so the form shows a time the event does not have' );
check( false !== strpos( $odd, '(current)' ),
    'the off-grid minute is not marked, so it reads as one of the fives' );
/* AND IT IS THE ONLY EXTRA ONE, read out of the MINUTE list alone. The hour
 * list carries value="06" and value="08" of its own, so searching the whole
 * control finds them and reports an extra minute that is not there. */
check( false === strpos( $osel[0], 'value="06"' ) && false === strpos( $osel[0], 'value="08"' ),
    'more than the event\'s own off-grid minute was added to the list' );

/* ---------------------------------------------------------------------------
 * 4. THE ROUND TRIP. Rendered in, posted back, and identical.
 *
 * THE LOAD-BEARING ONE. A control that moved somebody's 6:07 to 6:05 would look
 * perfect on screen and would be changing an answer nobody gave.
 * ------------------------------------------------------------------------ */
$trip = function ( $value ) {
    $parts = sfaf_time_parts( $value );
    $_POST = array( 'start_time_h' => $parts['h'], 'start_time_m' => $parts['m'] );
    sfaf_normalize_time_post( array( 'start_time' ) );
    return isset( $_POST['start_time'] ) ? $_POST['start_time'] : '(unset)';
};

$cases = array();
for ( $h = 0; $h < 24; $h++ ) {
    foreach ( sfaf_time_minutes() as $m ) {
        $cases[] = sprintf( '%02d:%02d', $h, $m );
    }
}
/* And the off-grid ones the import can have left. */
foreach ( array( '06:07', '18:07', '00:01', '23:59', '12:33' ) as $odd_case ) {
    $cases[] = $odd_case;
}

$bad = array();
foreach ( $cases as $value ) {
    $back = $trip( $value );
    if ( $back !== $value ) {
        $bad[] = $value . ' came back as ' . $back;
    }
}
check( empty( $bad ),
    'the round trip changed ' . count( $bad ) . ' of ' . count( $cases ) . ' times: ' . implode( '; ', array_slice( $bad, 0, 4 ) ) );

/* ---------------------------------------------------------------------------
 * 5. THE EMPTY AND THE NONSENSE CASES.
 * ------------------------------------------------------------------------ */
$_POST = array( 'start_time_h' => '', 'start_time_m' => '' );
sfaf_normalize_time_post( array( 'start_time' ) );
check( '' === $_POST['start_time'], 'an empty hour does not clear the time, so an optional end time cannot be removed' );

/* AN HOUR WITH NO MINUTE IS THE HOUR EXACTLY. Refusing "6" and demanding "6:00"
 * would be refusing the obvious reading. */
$_POST = array( 'start_time_h' => '06', 'start_time_m' => '' );
sfaf_normalize_time_post( array( 'start_time' ) );
check( '06:00' === $_POST['start_time'], 'an hour with no minute is not read as the hour exactly' );

/* OUT OF RANGE IS NO TIME, not a wrapped one. */
foreach ( array( array( '25', '00' ), array( '12', '99' ), array( 'abc', 'def' ) ) as $junk ) {
    $_POST = array( 'start_time_h' => $junk[0], 'start_time_m' => $junk[1] );
    sfaf_normalize_time_post( array( 'start_time' ) );
    check( '' === $_POST['start_time'],
        'an out-of-range time became "' . $_POST['start_time'] . '" rather than nothing' );
}

/* A REQUEST THAT DID NOT COME FROM THIS CONTROL IS UNTOUCHED. */
$_POST = array( 'start_time' => '14:45' );
sfaf_normalize_time_post( array( 'start_time' ) );
check( '14:45' === $_POST['start_time'],
    'a posted start_time with no hour/minute pair beside it was overwritten' );

/* ---------------------------------------------------------------------------
 * 6. ALL TWELVE CONTROLS WERE REPLACED.
 * ------------------------------------------------------------------------ */
$files = array(
    'includes/class-sfaf-portal.php'      => 6,
    'includes/class-sfaf-post-types.php'  => 2,
    'includes/class-sfaf-request.php'     => 2,
    'includes/class-sfaf-submit.php'      => 2,
);
$total = 0;
foreach ( $files as $rel => $want ) {
    $src = file_get_contents( $root . '/' . $rel );
    $got = substr_count( $src, 'sfaf_time_field(' );
    $total += $got;
    check( $got === $want, $rel . ' draws ' . $got . ' time controls, not ' . $want );
    /* Comments are stripped, because two of these files EXPLAIN that they used
     * to use an input and why they stopped. */
    $code = '';
    foreach ( token_get_all( $src ) as $t ) {
        if ( is_array( $t ) ) {
            if ( T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) { continue; }
            $code .= $t[1];
        } else { $code .= $t; }
    }
    check( false === strpos( $code, 'type="time"' ),
        $rel . ' still renders a browser time input' );
}
check( 12 === $total, 'there are ' . $total . ' time controls, not twelve' );

/* AND THE FOLD RUNS BEFORE ANY OF THEM IS READ. */
$main = file_get_contents( $root . '/sfaf-calendar.php' );
check( false !== strpos( $main, "add_action( 'init', 'sfaf_normalize_time_post', 0 );" ),
    'the hour/minute pair is never folded back, so every save reads an empty start_time' );

/* ---------------------------------------------------------------------------
 * THE SELF-TEST.
 * ------------------------------------------------------------------------ */
if ( $self_test ) {
    $probe = array();
    if ( 288 > count( $cases ) ) { $probe[] = 'the round trip covers fewer than every hour and minute on the grid'; }
    /* The reader really does notice a changed value. */
    $_POST = array( 'start_time_h' => '18', 'start_time_m' => '30' );
    sfaf_normalize_time_post( array( 'start_time' ) );
    if ( '18:30' !== $_POST['start_time'] ) { $probe[] = 'the fold does not produce H:i at all'; }
    if ( '18:30' === '18:31' ) { $probe[] = 'unreachable'; }
    /* And an off-grid render really does gain an entry. */
    $a = sfaf_time_field( 'x', '10:00' );
    $b = sfaf_time_field( 'x', '10:03' );
    if ( substr_count( $b, '<option' ) <= substr_count( $a, '<option' ) ) {
        $probe[] = 'an off-grid value adds no entry, so section 3 asserts nothing';
    }
    if ( $probe ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $probe as $p ) { echo '  . ' . $p . "\n"; }
        exit( 1 );
    }
    echo 'self-test passed: ' . count( $cases ) . " round trips, and an off-grid value is visible to the reader.\n";
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "twelve controls, twelve minute entries, and " . count( $cases ) . " values that come back\n";
echo "exactly as they went in, including the off-grid ones the import left.\n";
exit( 0 );
