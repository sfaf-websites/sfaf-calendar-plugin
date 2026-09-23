<?php
/**
 * PLANT A FAULT IN THE TIME CONTROL, RUN THE CHECKS, PUT IT BACK.
 *
 *     php .claude/plant-time-control.php
 *
 * TWO CHECKS, BECAUSE THE CONTROL HAS TWO HALVES THAT FAIL DIFFERENTLY.
 * time-control-test.php runs the renderer and the round trip; time-control-
 * live.php opens the rendered control in a browser and counts what the picker
 * offers. A fault in the grid shows in both; a fault in the layout or the
 * focus order shows only in the second.
 *
 * THE ONE THE BRIEF NAMES IS THE FIRST: a minute off the five-minute grid
 * reaching the picker. That is the whole point of replacing the control, and it
 * is invisible to anything that does not count the entries.
 *
 * Restores every file and compares byte for byte before exiting.
 */

$root = dirname( __DIR__ );

$plants = array(

    /* ---- THE GRID. ------------------------------------------------------ */
    'THE PICKER OFFERS A MINUTE OFF THE FIVE-MINUTE GRID' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    for ( \$m = 0; \$m < 60; \$m += 5 ) {",
        'to'   => "    for ( \$m = 0; \$m < 60; \$m += 1 ) {",
    ),

    'the grid loses an entry' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    for ( \$m = 0; \$m < 60; \$m += 5 ) {",
        'to'   => "    for ( \$m = 0; \$m < 55; \$m += 5 ) {",
    ),

    'the grid steps by ten' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    for ( \$m = 0; \$m < 60; \$m += 5 ) {",
        'to'   => "    for ( \$m = 0; \$m < 60; \$m += 10 ) {",
    ),

    /* ---- THE OFF-GRID VALUE THE IMPORT LEFT. --------------------------- */
    /* ROUNDED SILENTLY is the failure this control exists to avoid: the event
     * keeps 6:07, the form shows 6:05, and the next save writes 6:05. */
    'an imported off-grid time is silently rounded away' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    if ( \$off ) {\n        \$minutes[] = \$parts['m'];\n        sort( \$minutes );\n    }",
        'to'   => "    if ( \$off ) {\n        \$parts['m'] = sprintf( '%02d', 5 * (int) round( (int) \$parts['m'] / 5 ) );\n    }",
    ),

    'the off-grid minute stops being marked' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "                    echo ( \$off && \$mv === \$parts['m'] ) ? esc_html( ' (current)' ) : '';",
        'to'   => "",
    ),

    /* ---- THE ROUND TRIP. ----------------------------------------------- */
    'the fold drops the minute' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "        \$_POST[ \$name ] = sprintf( '%02d:%02d', \$hi, \$mi );",
        'to'   => "        \$_POST[ \$name ] = sprintf( '%02d:00', \$hi );",
    ),

    'the fold swaps the hour and the minute' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "        \$_POST[ \$name ] = sprintf( '%02d:%02d', \$hi, \$mi );",
        'to'   => "        \$_POST[ \$name ] = sprintf( '%02d:%02d', \$mi, \$hi );",
    ),

    'an out-of-range hour is accepted' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "        if ( \$hi < 0 || \$hi > 23 || \$mi < 0 || \$mi > 59 ) {",
        'to'   => "        if ( false ) {",
    ),

    'the fold overwrites a start_time nothing posted a pair for' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "        if ( ! isset( \$_POST[ \$name . '_h' ] ) ) {\n            continue;\n        }",
        'to'   => "",
    ),

    /* ---- AND IT NEVER RUNS AT ALL. ------------------------------------- */
    'the fold is never hooked, so every save reads an empty time' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "add_action( 'init', 'sfaf_normalize_time_post', 0 );",
        'to'   => "",
    ),

    /* ---- THE CONTROL'S SHAPE. ------------------------------------------ */
    'the two lists stop being one labelled control' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    <span class=\"uc-timepick\" role=\"group\" aria-label=\"<?php echo esc_attr( \$args['label'] ); ?>\">",
        'to'   => "    <span class=\"uc-timepick\">",
    ),

    'the minute list loses its own name' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "                aria-label=\"<?php echo esc_attr( \$args['label'] . ', minute' ); ?>\"<?php echo \$req . \$dis; ?>>",
        'to'   => "                <?php echo \$req . \$dis; ?>>",
    ),

    'a locked field leaves one list editable' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "                class=\"uc-timepick-m\"\n                aria-label=\"<?php echo esc_attr( \$args['label'] . ', minute' ); ?>\"<?php echo \$req . \$dis; ?>>",
        'to'   => "                class=\"uc-timepick-m\"\n                aria-label=\"<?php echo esc_attr( \$args['label'] . ', minute' ); ?>\"<?php echo \$req; ?>>",
    ),

    'the two lists stack instead of sitting side by side' => array(
        'file' => 'public/css/portal.css',
        'from' => ".uc-timepick { display: grid; grid-template-columns: 2fr 1fr; gap: 8px; align-items: center; }",
        'to'   => ".uc-timepick { display: block; }",
    ),

    'the minute list becomes the wider of the two' => array(
        'file' => 'public/css/portal.css',
        'from' => ".uc-timepick { display: grid; grid-template-columns: 2fr 1fr; gap: 8px; align-items: center; }",
        'to'   => ".uc-timepick { display: grid; grid-template-columns: 1fr 2fr; gap: 8px; align-items: center; }",
    ),

    /* ---- AND ONE CONTROL LEFT BEHIND. ---------------------------------- */
    'one of the twelve is still a browser time input' => array(
        'file' => 'includes/class-sfaf-submit.php',
        'from' => "<?php echo sfaf_time_field( 'end_time', \$v( 'end' ), array( 'label' => 'End time', 'required' => SFAF_Submissions::is_required( \$req, 'end_time', \$req_vals ) ) ); ?>",
        'to'   => "<input type=\"time\" name=\"end_time\" required value=\"<?php echo esc_attr( \$v( 'end' ) ); ?>\" step=\"300\" />",
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

    /* EITHER CHECK CATCHING IT IS ENOUGH: the two ask different questions and
     * a fault only has to be visible to one of them. */
    $out = array(); $a = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/time-control-test.php' ) . ' 2>&1', $out, $a );
    $b = 0;
    if ( 0 === $a ) {
        $out = array();
        exec( 'php ' . escapeshellarg( $root . '/.claude/time-control-live.php' ) . ' --run 2>&1', $out, $b );
    }

    file_put_contents( $path, $src );

    if ( 0 !== $a || 0 !== $b ) {
        $caught++;
        echo '  caught: ' . $name . '  (' . ( 0 !== $a ? 'renderer' : 'browser' ) . ")\n";
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
exec( 'php ' . escapeshellarg( $root . '/.claude/time-control-live.php' ) . ' 2>&1' );

echo "\n";
if ( $missed ) {
    echo 'PLANTS NOT CAUGHT: ' . count( $missed ) . ' of ' . count( $plants ) . "\n";
    foreach ( $missed as $m ) { echo '  . ' . $m . "\n"; }
    exit( 1 );
}
echo 'caught ' . $caught . ' of ' . count( $plants ) . " planted faults, and every file is restored.\n";
exit( 0 );
