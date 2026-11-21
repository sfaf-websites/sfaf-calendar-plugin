<?php
/**
 * THE 3.143.2 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31432.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each part of the 3.143.2 bar (no side
 * edges, the two gradient lines, the corners, the weight, the inset, the
 * strip, the banner) and runs the check that must catch it: the static check
 * release-31432-test.php, Chrome's release-31431-live.php, or the embed
 * identity check. Every file is put back from memory. Commit first, and never
 * run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$CS = 'public/css/calendar.css';
$CJ = 'public/js/calendar.js';
$T  = 'release-31432-test.php';
$L  = 'release-31431-live.php --run';
$I  = 'embed-identity-test.php';

$LINE  = 'linear-gradient(90deg, #D1D3D4, #373433 50%, #D1D3D4)';
$STRIP = '.uc-calendar .uc-ann-strip { display: block; box-sizing: border-box; height: 6px; margin: 0 3px 3px; border: 0; border-radius: 2px; --uc-ann-line: ' . $LINE . '; background-color: #FFFAE0; background-image: var(--uc-ann-line), var(--uc-ann-line); background-position: left top, left bottom; background-size: 100% 1px; background-repeat: no-repeat; }';
$STRIP_OLD = '.uc-calendar .uc-ann-strip { display: block; box-sizing: content-box; height: 4px; margin: 0 3px 3px; border: 1px solid #705F00; border-radius: 2px; background: #FFFAE0; }';
$C560 = "@container uc-calendar (max-width: 560px) {\n    .uc-calendar td .uc-ann-bars { display: none; }\n    ";
$M640 = "@media (max-width: 640px) {\n    .uc-calendar td .uc-ann-bars { display: none; }\n    ";

$plants = array(
    'side edges back' => array( $T, 'B: the bar has a border', array(
        array( $CS, "    border: 0; border-radius: 3px;\n    --uc-ann-line:", "    border: 1px solid #705F00; border-radius: 3px;\n    --uc-ann-line:" ),
    ) ),
    'side edges back, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, "    border: 0; border-radius: 3px;\n    --uc-ann-line:", "    border: 1px solid #705F00; border-radius: 3px;\n    --uc-ann-line:" ),
    ) ),
    'a solid line, not a fade' => array( $T, 'B: the bar\'s line is not Light Gray', array(
        array( $CS, "    --uc-ann-line: $LINE;\n", "    --uc-ann-line: linear-gradient(90deg, #373433, #373433);\n" ),
    ) ),
    'a solid line, not a fade, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, "    --uc-ann-line: $LINE;\n", "    --uc-ann-line: linear-gradient(90deg, #373433, #373433);\n" ),
    ) ),
    'the hover takes the lines away' => array( $T, 'B: the hover uses the background shorthand', array(
        array( $CS, "    background-color: #FFF8D1; color: var(--uc-dark);\n}", "    background: #FFF8D1; color: var(--uc-dark);\n}" ),
    ) ),
    'one line only' => array( $T, 'B: the bar does not draw the line top and bottom', array(
        array( $CS, "    background-position: left top, left bottom; background-size: 100% 1px;", "    background-position: left top, left top; background-size: 100% 1px;" ),
    ) ),
    'one line only, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, "    background-position: left top, left bottom; background-size: 100% 1px;", "    background-position: left top, left top; background-size: 100% 1px;" ),
    ) ),
    'square corners, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, "    border: 0; border-radius: 3px;\n    --uc-ann-line:", "    border: 0; border-radius: 0;\n    --uc-ann-line:" ),
    ) ),
    'bold words' => array( $T, 'B: the bar\'s words are not weight 500', array(
        array( $CS, "font-size: 11px; font-weight: 500; line-height: 1.3;", "font-size: 11px; font-weight: 700; line-height: 1.3;" ),
    ) ),
    'bold words, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, "font-size: 11px; font-weight: 500; line-height: 1.3;", "font-size: 11px; font-weight: 700; line-height: 1.3;" ),
    ) ),
    'edge to edge again' => array( $T, 'C: the cell\'s bars are not 2px into', array(
        array( $CS, ".uc-calendar td .uc-ann-bars { margin: 0 -2px 4px; }", ".uc-calendar td .uc-ann-bars { margin: 0 -5px 4px; }" ),
    ) ),
    'edge to edge again, in Chrome' => array( $L, 'PLANT W:', array(
        array( $CS, ".uc-calendar td .uc-ann-bars { margin: 0 -2px 4px; }", ".uc-calendar td .uc-ann-bars { margin: 0 -5px 4px; }" ),
    ) ),
    'the cell bar square again' => array( $T, 'C: the cell\'s bar sets its own radius', array(
        array( $CS, ".uc-calendar td .uc-ann-bar { gap: 4px; padding-left: 3px;", ".uc-calendar td .uc-ann-bar { gap: 4px; border-radius: 0; padding-left: 3px;" ),
    ) ),
    'Pride week wraps at 700, in Chrome' => array( $L, 'PLANT W:', array(
        array( $CS, ".uc-calendar td .uc-ann-bar { gap: 4px; padding-left: 3px; padding-right: 3px; }", ".uc-calendar td .uc-ann-bar { gap: 4px; padding-left: 6px; padding-right: 6px; }" ),
    ) ),
    /* Both copies: the 640px one also applies at 430 and 390 and comes later. */
    'the old strip, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, $C560 . $STRIP, $C560 . $STRIP_OLD ),
        array( $CS, $M640 . $STRIP, $M640 . $STRIP_OLD ),
    ) ),
    'one strip copy old' => array( $T, "S: 1 of the strip's two copies", array(
        array( $CS, $C560 . $STRIP, $C560 . $STRIP_OLD ),
    ) ),
    'the banner its own corners' => array( $T, 'N: the banner sets its own radius', array(
        array( $CS, ".uc-calendar .uc-ann-banner { min-height: 32px; margin: 0 0 12px; padding: 6px 12px; gap: 8px; font-size: 13px; }", ".uc-calendar .uc-ann-banner { min-height: 32px; margin: 0 0 12px; padding: 6px 12px; gap: 8px; border-radius: 8px; font-size: 13px; }" ),
    ) ),
    'the banner its own corners, in Chrome' => array( $L, 'PLANT A:', array(
        array( $CS, ".uc-calendar .uc-ann-banner { min-height: 32px; margin: 0 0 12px; padding: 6px 12px; gap: 8px; font-size: 13px; }", ".uc-calendar .uc-ann-banner { min-height: 32px; margin: 0 0 12px; padding: 6px 12px; gap: 8px; border-radius: 8px; font-size: 13px; }" ),
    ) ),
    'a public file changes' => array( $I, 'differs from 3.143.1', array(
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
