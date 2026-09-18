<?php
/**
 * PLANT A FAULT IN THE RSVP FORMAT CONTROL, RUN THE CHECK, PUT IT BACK.
 *
 *     php .claude/plant-rsvp-format.php
 *
 * THE ONE THE BRIEF NAMES IS THE FIRST. A segment without its radio is the
 * shape this whole control is built to avoid: it would still look right, still
 * respond to a click if somebody wired one, and would be unreachable by
 * keyboard, absent from the accessibility tree, and silent when the form was
 * submitted. Every part of that is invisible to a screenshot.
 *
 * Restores every file and compares byte for byte before exiting.
 */

$root = dirname( __DIR__ );

$plants = array(

    /* ---- THE RADIO UNDER THE SEGMENT. ---------------------------------- */
    'A SEGMENT WITHOUT ITS RADIO' => array(
        'file' => 'public/js/calendar.js',
        'from' => "                '<input type=\"radio\" name=\"uc_rsvp_format\" value=\"' + f + '\"' +\n                (isFull ? ' disabled' : '') + ' />' +",
        'to'   => "",
    ),

    'the radios are hidden in the way that removes them' => array(
        'file' => 'public/css/calendar.css',
        'from' => "    opacity: 0; cursor: pointer;\n}\n.uc-rsvp-form .uc-segment-label {",
        'to'   => "    display: none; cursor: pointer;\n}\n.uc-rsvp-form .uc-segment-label {",
    ),

    'the radios are hidden with visibility, which is the same removal' => array(
        'file' => 'public/css/calendar.css',
        'from' => "    opacity: 0; cursor: pointer;\n}\n.uc-rsvp-form .uc-segment-label {",
        'to'   => "    visibility: hidden; cursor: pointer;\n}\n.uc-rsvp-form .uc-segment-label {",
    ),

    'the radio stops covering its segment' => array(
        'file' => 'public/css/calendar.css',
        'from' => "    position: absolute; inset: 0;\n    width: 100%; height: 100%; margin: 0; padding: 0;",
        'to'   => "    position: absolute; inset: auto;\n    width: 1px; height: 1px; margin: 0; padding: 0;",
    ),

    /* ---- THE NAME AND THE VALUES, which are what the server compares. --- */
    'the radios lose the name the server reads' => array(
        'file' => 'public/js/calendar.js',
        'from' => "name=\"uc_rsvp_format\" value=\"' + f + '\"",
        'to'   => "name=\"format_choice\" value=\"' + f + '\"",
    ),

    /* ---- THE MEASURED PAIR. -------------------------------------------- */
    'the chosen segment stops filling' => array(
        'file' => 'public/css/calendar.css',
        'from' => ".uc-rsvp-form .uc-segment input:checked + .uc-segment-label {\n    background: var(--uc-teal-text); color: #fff;\n}",
        'to'   => ".uc-rsvp-form .uc-segment input:checked + .uc-segment-label {\n    font-weight: 700;\n}",
    ),

    'the chosen segment fills with a colour that is not the measured one' => array(
        'file' => 'public/css/calendar.css',
        'from' => "    background: var(--uc-teal-text); color: #fff;\n}\n/* INSET, because the boundary is on the container",
        'to'   => "    background: var(--uc-teal); color: #fff;\n}\n/* INSET, because the boundary is on the container",
    ),

    'the unchosen segment stops being teal on white' => array(
        'file' => 'public/css/calendar.css',
        'from' => "    color: var(--uc-teal-text); background: #fff;\n    transition: background-color 0.15s, color 0.15s;",
        'to'   => "    color: var(--uc-secondary); background: #fff;\n    transition: background-color 0.15s, color 0.15s;",
    ),

    /* ---- ONE BOUNDARY, AND THE FULL WIDTH. ----------------------------- */
    'the pair loses its single boundary' => array(
        'file' => 'public/css/calendar.css',
        'from' => "    border: 1px solid var(--uc-control-edge);\n    border-radius: 8px; overflow: hidden;",
        'to'   => "    border: 0;\n    border-radius: 0; overflow: hidden;",
    ),

    'the control stops filling the row' => array(
        'file' => 'public/css/calendar.css',
        'from' => ".uc-rsvp-form .uc-segmented {\n    display: flex; width: 100%;",
        'to'   => ".uc-rsvp-form .uc-segmented {\n    display: inline-flex; width: auto;",
    ),

    'the longer word decides the segment widths' => array(
        'file' => 'public/css/calendar.css',
        'from' => ".uc-rsvp-form .uc-segment { position: relative; flex: 1 1 0; display: flex; }",
        'to'   => ".uc-rsvp-form .uc-segment { position: relative; flex: 0 0 auto; display: flex; }",
    ),

    /* ---- AND THE GAP THE SWEEP EXISTS FOR. ----------------------------- */
    'the opt-in checkbox loses the gap between it and its label' => array(
        'file' => 'public/css/calendar.css',
        'from' => ".uc-rsvp-form .uc-rsvp-optin { display: flex; align-items: flex-start; gap: 8px;",
        'to'   => ".uc-rsvp-form .uc-rsvp-optin { display: flex; align-items: flex-start;",
    ),
);

$caught = 0;
$missed = array();
$good   = array();

foreach ( $plants as $p ) {
    if ( ! isset( $good[ $p['file'] ] ) ) {
        $good[ $p['file'] ] = file_get_contents( $root . '/' . $p['file'] );
    }
}

foreach ( $plants as $name => $p ) {
    $path = $root . '/' . $p['file'];
    $src  = $good[ $p['file'] ];

    if ( false === strpos( $src, $p['from'] ) ) {
        $missed[] = $name . '  [the plant no longer matches the source, so it proved nothing]';
        echo '  MISSED: ' . $name . "\n";
        continue;
    }
    $at     = strpos( $src, $p['from'] );
    $broken = substr( $src, 0, $at ) . $p['to'] . substr( $src, $at + strlen( $p['from'] ) );
    if ( $broken === $src ) {
        $missed[] = $name . '  [the swap changed nothing]';
        echo '  MISSED: ' . $name . "\n";
        continue;
    }
    file_put_contents( $path, $broken );

    $out  = array();
    $code = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/rsvp-format-live.php' ) . ' --run 2>&1', $out, $code );

    file_put_contents( $path, $src );

    if ( 0 !== $code ) {
        $caught++;
        echo '  caught: ' . $name . "\n";
    } else {
        $missed[] = $name;
        echo '  MISSED: ' . $name . "\n";
    }
}

foreach ( $good as $rel => $src ) {
    file_put_contents( $root . '/' . $rel, $src );
    if ( file_get_contents( $root . '/' . $rel ) !== $src ) {
        echo 'RESTORE FAILED for ' . $rel . ". Do not build.\n";
        exit( 1 );
    }
}

/* Rebuild the page from the restored sources. */
exec( 'php ' . escapeshellarg( $root . '/.claude/rsvp-format-live.php' ) . ' 2>&1' );

echo "\n";
if ( $missed ) {
    echo 'PLANTS NOT CAUGHT: ' . count( $missed ) . ' of ' . count( $plants ) . "\n";
    foreach ( $missed as $m ) { echo '  . ' . $m . "\n"; }
    exit( 1 );
}
echo 'caught ' . $caught . ' of ' . count( $plants ) . " planted faults, and every file is restored.\n";
exit( 0 );
