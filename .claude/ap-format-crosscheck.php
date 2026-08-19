<?php
/**
 * THE DIALOG'S FORMATTERS AGREE WITH THE PLUGIN'S.
 *
 *     php .claude/ap-format-crosscheck.php
 *     php .claude/ap-format-crosscheck.php --self-test
 *
 * WHY THERE ARE TWO COPIES AT ALL. The change dialog has to decide, in the
 * browser and before anything is written, whether the date, the time or the
 * location has moved. The server decides the same thing in movable_diff(), by
 * comparing FORMATTED strings, so '18:00' and '6 pm' are one fact and nobody is
 * emailed about the difference. To ask the same question the browser has to
 * format the same way, so apDate() and apTimeRange() in portal.js are a second
 * implementation of sfaf_ap_date() and sfaf_ap_time_range().
 *
 * A SECOND IMPLEMENTATION IS A THING THAT DRIFTS, so it is held to the first
 * one case by case, the same arrangement the recurrence engines have had since
 * 3.14.0. Node runs the real functions, sliced out of portal.js between the
 * uc-ap-format markers, and PHP runs the real ones out of the plugin.
 *
 * WHAT DRIFT WOULD COST, so the severity is written down rather than guessed:
 * the browser and the server would disagree about whether something moved. Ask
 * too often and a manager is asked about a change nobody made, presses send,
 * and nothing goes out, which teaches them the dialog is noise. Ask too rarely
 * and a real move is saved with no question asked and nobody told. The second
 * is the one that matters, and it is silent.
 */

$root = dirname( __DIR__ );
$self = in_array( '--self-test', $argv, true );

/* ---------------------------------------------------------------------------
 * The PHP side, loaded rather than restated.
 * ------------------------------------------------------------------------ */
define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

function date_i18n( $f, $ts = false, $gmt = false ) { return date( $f, false === $ts ? time() : $ts ); }
function wp_timezone() { return new DateTimeZone( date_default_timezone_get() ); }

/*
 * ONLY THE FORMATTERS. sfaf-template-functions.php is a large file with a lot
 * of WordPress in it, so the three functions under test are sliced out by name
 * and evaluated on their own. The slice is taken from the shipped file every
 * run, so this cannot pass against a copy that has stopped matching.
 */
$tpl = file_get_contents( $root . '/includes/sfaf-template-functions.php' );

function slice_function( $src, $name ) {
    if ( ! preg_match( '#\nfunction ' . preg_quote( $name, '#' ) . '\s*\(#', $src, $m, PREG_OFFSET_CAPTURE ) ) {
        return '';
    }
    $start = $m[0][1];
    $open  = strpos( $src, '{', $start );
    $depth = 0;
    for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) {
                return substr( $src, $start, $i - $start + 1 );
            }
        }
    }
    return '';
}

$missing = array();
$code    = '';
foreach ( array( 'sfaf_local_timestamp', 'sfaf_ap_date', 'sfaf_ap_time', 'sfaf_ap_time_range' ) as $fn ) {
    $slice = slice_function( $tpl, $fn );
    if ( '' === $slice ) {
        $missing[] = $fn;
        continue;
    }
    $code .= $slice . "\n";
}
if ( $missing ) {
    echo "Cannot run: these functions were not found in sfaf-template-functions.php:\n";
    foreach ( $missing as $fn ) { echo "  . $fn\n"; }
    echo "They were renamed or moved. Point this file at them.\n";
    exit( 1 );
}
eval( $code );

/* ---------------------------------------------------------------------------
 * The JS side, sliced out of portal.js and run by node.
 * ------------------------------------------------------------------------ */
$js = file_get_contents( $root . '/public/js/portal.js' );
$from = strpos( $js, 'uc-ap-format start' );
$to   = strpos( $js, 'uc-ap-format end' );
if ( false === $from || false === $to || $to < $from ) {
    echo "Cannot run: the uc-ap-format markers are not both in portal.js.\n";
    echo "They bracket the formatters this file compares. Put them back.\n";
    exit( 1 );
}
/*
 * CUT ON THE COMMENT BOUNDARIES, NOT ON THE MARKERS.
 *
 * Both markers live inside comments, so slicing at the marker itself starts the
 * slice halfway through one and node refuses the file. The opening cut goes
 * after that comment closes and the closing cut goes before the next one opens.
 */
$from = strpos( $js, '*/', $from );
if ( false === $from ) {
    echo "Cannot run: the uc-ap-format start marker's comment is unterminated.\n";
    exit( 1 );
}
$from += 2;
$to = strrpos( substr( $js, 0, $to ), '/*' );
if ( false === $to || $to < $from ) {
    echo "Cannot run: the uc-ap-format end marker is not in a comment after the start one.\n";
    exit( 1 );
}
$slice = substr( $js, $from, $to - $from );

/* ---------------------------------------------------------------------------
 * The cases. Every one is a value this dialog can actually be handed.
 * ------------------------------------------------------------------------ */
$dates = array(
    '2026-09-08', '2026-01-01', '2026-12-31', '2026-02-28', '2028-02-29',
    '2026-03-01', '2026-07-04', '2026-11-03', '2027-06-15', '',
);
$times = array(
    array( '18:00', '19:30' ),
    array( '09:00', '10:00' ),
    array( '11:00', '13:00' ),   // am to pm, so both meridiems are said
    array( '00:00', '01:00' ),   // midnight is 12 am
    array( '12:00', '13:00' ),   // noon is 12 pm
    array( '12:30', '12:45' ),
    array( '23:00', '23:59' ),
    array( '13:00', '' ),        // no end
    array( '06:05', '07:05' ),
    array( '', '' ),
    array( '', '19:00' ),
);

$want = array();
foreach ( $dates as $d ) {
    $want[] = array( 'kind' => 'date', 'in' => array( $d ), 'php' => sfaf_ap_date( $d, 'full' ) );
}
foreach ( $times as $t ) {
    $want[] = array( 'kind' => 'range', 'in' => $t, 'php' => sfaf_ap_time_range( $t[0], $t[1] ) );
}

/*
 * A PLANTED DISAGREEMENT, to prove this file can see one. The runner is handed
 * a broken slice and must report every case rather than none.
 */
if ( $self ) {
    $slice = str_replace( "AP_MONTHS[mo - 1]", "AP_MONTHS[(mo - 1 + 1) % 12]", $slice );
}

/* ---------------------------------------------------------------------------
 * Run the JS.
 * ------------------------------------------------------------------------ */
$runner = $slice . "\n"
    . "var cases = " . json_encode( array_map( function ( $c ) {
        return array( 'kind' => $c['kind'], 'in' => $c['in'] );
    }, $want ) ) . ";\n"
    . "var out = cases.map(function (c) {\n"
    . "    return (c.kind === 'date') ? apDate(c.in[0]) : apTimeRange(c.in[0], c.in[1]);\n"
    . "});\n"
    . "process.stdout.write(JSON.stringify(out));\n";

$tmp = sys_get_temp_dir() . '/sfaf-ap-crosscheck-' . getmypid() . '.js';
file_put_contents( $tmp, $runner );
$json = shell_exec( 'node ' . escapeshellarg( $tmp ) );
@unlink( $tmp );

$got = json_decode( (string) $json, true );
if ( ! is_array( $got ) || count( $got ) !== count( $want ) ) {
    echo "Cannot run: node returned nothing usable.\n";
    echo "Is node on PATH? Raw output follows.\n";
    var_dump( $json );
    exit( 1 );
}

/* ---------------------------------------------------------------------------
 * Compare.
 * ------------------------------------------------------------------------ */
$bad = array();
foreach ( $want as $i => $c ) {
    if ( (string) $c['php'] !== (string) $got[ $i ] ) {
        $bad[] = sprintf(
            "%s(%s)\n      PHP: %s\n      JS : %s",
            'date' === $c['kind'] ? 'sfaf_ap_date/apDate' : 'sfaf_ap_time_range/apTimeRange',
            implode( ', ', array_map( function ( $v ) { return "'" . $v . "'"; }, $c['in'] ) ),
            '' === $c['php'] ? '(empty)' : $c['php'],
            '' === $got[ $i ] ? '(empty)' : $got[ $i ]
        );
    }
}

if ( $self ) {
    echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    /* The planted fault shifts every month by one, so every non-empty date case
     * must disagree. The time cases are untouched and must still agree. */
    $date_cases = 0;
    foreach ( $want as $c ) {
        if ( 'date' === $c['kind'] && '' !== $c['php'] ) { $date_cases++; }
    }
    if ( count( $bad ) !== $date_cases ) {
        echo "BROKEN: planted a wrong month in the JS and this file reported "
            . count( $bad ) . " disagreement(s), expecting $date_cases.\n";
        echo "It cannot be trusted to catch a real one.\n";
        exit( 1 );
    }
    echo "ok       a planted month shift is caught on every date case\n";
    echo "ok       the time cases are unaffected and still agree\n";
    echo "\nthe cross-check can see a disagreement.\n";
    exit( 0 );
}

echo "AP formatter cross-check\n";
echo 'checked: ' . count( $want ) . " cases through sfaf_ap_date()/apDate() and\n";
echo "         sfaf_ap_time_range()/apTimeRange(), PHP against the browser\n\n";

if ( $bad ) {
    echo 'DISAGREEMENTS: ' . count( $bad ) . "\n";
    foreach ( $bad as $b ) {
        echo "  . $b\n";
    }
    echo "\nThe dialog decides whether to ASK by comparing formatted values against the\n";
    echo "ones the server stamped. Where these two disagree it asks about a change\n";
    echo "nobody made, or worse, stays quiet about one somebody did.\n";
    exit( 1 );
}
echo "the browser formats a date and a time range exactly as the plugin does.\n";
