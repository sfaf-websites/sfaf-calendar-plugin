<?php
/**
 * DOES EVERY TIME CONTROL ASK FOR FIVE MINUTE STEPS?
 *
 *     php .claude/time-step-test.php --self-test
 *     php .claude/time-step-test.php
 *
 * WHY THIS EXISTS. Five minute increments were specified, built in 3.72.0 and
 * reported as still one minute after 3.73.0 installed. They had not been lost:
 * every `<input type="time">` in the plugin carries sfaf_time_step_attr() and
 * always has. What nothing proved was that the NEXT time control would, and a
 * rule that lives in twelve hand-written call sites is a rule that lasts until
 * somebody writes the thirteenth.
 *
 * WHAT IT CHECKS. Every `type="time"` in the shipped PHP is followed on the
 * same line by a sfaf_time_step_attr() call. Same line rather than nearby,
 * because that is how all twelve are written and a looser rule would pass an
 * attribute that landed on the wrong input.
 *
 * WHAT IT DOES NOT CHECK, said plainly: whether a browser then steps by five.
 * `step` is an instruction to the spinner, the picker list and validation, and
 * there is no browser here. It also cannot check the two deliberate absences,
 * which are not absences of the CALL: sfaf_time_step_attr() returns an empty
 * string when the control already holds a time off the boundary, so an
 * imported 6:07 stays editable. That decision is in the function, tested
 * below, and not repeated at any call site.
 *
 * @package SFAF_Calendar
 */

$root = dirname( __DIR__ );
$self = in_array( '--self-test', array_slice( $argv, 1 ), true );

/* ---------------------------------------------------------------------------
 * 1. Every time control in the tree.
 * ------------------------------------------------------------------------ */
function sfaf_time_controls( $root ) {
    $found = array();
    $it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
    foreach ( $it as $file ) {
        if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
            continue;
        }
        $path = str_replace( '\\', '/', $file->getPathname() );
        /* The shipped tree only. `Old Calendar Files` is the archive of
         * released zips and `.build-stage` is scratch; a rule about what this
         * plugin renders has nothing to say about either, and a checker that
         * reports them is a checker nobody runs twice. */
        foreach ( array( '/.claude/', '/vendor/', '/.build-stage/', '/Old Calendar Files/', '/.git/', '/node_modules/' ) as $skip ) {
            if ( false !== strpos( $path, $skip ) ) {
                continue 2;
            }
        }
        $lines = file( $path );
        foreach ( $lines as $n => $line ) {
            if ( false === strpos( $line, 'type="time"' ) || false === strpos( $line, '<input' ) ) {
                continue;
            }
            /* A comment ABOUT a time control is not a time control, and two of
             * them explain this very rule. */
            $lead = ltrim( $line );
            if ( 0 === strpos( $lead, '*' ) || 0 === strpos( $lead, '//' ) || 0 === strpos( $lead, '/*' ) ) {
                continue;
            }
            $found[] = array(
                'file'    => str_replace( str_replace( '\\', '/', $root ) . '/', '', $path ),
                'line'    => $n + 1,
                'stepped' => ( false !== strpos( $line, 'sfaf_time_step_attr(' ) ),
            );
        }
    }
    return $found;
}

/* ---------------------------------------------------------------------------
 * 2. The function's own rule, which is where the two absences are decided.
 * ------------------------------------------------------------------------ */
function sfaf_step_rule_cases() {
    return array(
        // value        expected
        array( '',      ' step="300"' ), // an empty control always steps
        array( '18:00', ' step="300"' ),
        array( '18:05', ' step="300"' ),
        array( '18:30', ' step="300"' ),
        array( '6:07',  '' ),            // off the boundary: no step, still editable
        array( '09:13', '' ),
        array( 'later', '' ),            // not a time at all
    );
}

/* The library guards on ABSPATH, as every file here does. Nothing in it runs
 * at load beyond defining functions, so this is the whole of the WordPress
 * that sfaf_time_step_attr() needs. */
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}
require_once $root . '/includes/sfaf-template-functions.php';

$fails = array();

/* The function. */
foreach ( sfaf_step_rule_cases() as $case ) {
    list( $value, $want ) = $case;
    $got = sfaf_time_step_attr( $value );
    if ( $got !== $want ) {
        $fails[] = sprintf(
            'sfaf_time_step_attr(%s) gave "%s", expected "%s"',
            var_export( $value, true ),
            $got,
            $want
        );
    }
}

/* The call sites. */
$controls = sfaf_time_controls( $root );
$bare     = array_values( array_filter( $controls, function ( $c ) { return ! $c['stepped']; } ) );

if ( $self ) {
    /* A checker that cannot fail is not evidence. Plant a bare control and
     * require it caught, without writing anything into the tree. */
    $planted = array_merge( $controls, array(
        array( 'file' => 'includes/planted.php', 'line' => 1, 'stepped' => false ),
    ) );
    $caught = count( array_filter( $planted, function ( $c ) { return ! $c['stepped']; } ) ) === count( $bare ) + 1;
    printf( "  %-56s%s\n", 'a time control written with no step attribute', $caught ? 'caught' : 'MISSED' );

    $rule = ( ' step="300"' === sfaf_time_step_attr( '' ) && '' === sfaf_time_step_attr( '6:07' ) );
    printf( "  %-56s%s\n", 'the off-boundary rule still holds', $rule ? 'holds' : 'BROKEN' );

    $clean = empty( $bare ) && empty( $fails );
    printf( "  %-56s%s\n", 'the real tree, unmodified', $clean ? 'passes' : 'FAILS' );

    echo "\n";
    if ( ! $caught || ! $rule || ! $clean ) {
        echo "self-test FAILED\n";
        exit( 1 );
    }
    echo "self-test passed: a bare control is caught and the real tree is clean.\n";
    exit( 0 );
}

foreach ( $bare as $c ) {
    $fails[] = $c['file'] . ':' . $c['line'] . ' is a time control with no sfaf_time_step_attr()';
}

if ( $fails ) {
    echo 'TIME STEPS: ' . count( $fails ) . " FAILURE(S)\n";
    foreach ( $fails as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}

printf( "Time controls\n" );
printf( "  %d time control(s), every one asking for five minute steps\n", count( $controls ) );
echo "  a control already holding an off-boundary time is deliberately left alone,\n";
echo "  so an imported 6:07 stays editable and stays sayable\n";
echo "\n";
echo "step is the spinner, the picker list and validation. A typed 6:07 is refused\n";
echo "by the browser before the form posts, and the server's clean_time() is\n";
echo "unchanged, which is what keeps the stored value editable.\n";
