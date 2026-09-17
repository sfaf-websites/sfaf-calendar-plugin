<?php
/**
 * PLANT THE 3.94.0 FAULT BACK, RUN THE AUDIT, PUT IT RIGHT.
 *
 *     php .claude/plant-form-owner.php
 *
 * THE FIRST PLANT IS THE ONE THAT SHIPPED: the `form=` attribute taken off
 * Publish, which is what 3.94.0 did by not adding it, and what left the button
 * dead through four releases. The audit must fail on it, or the audit is
 * decoration.
 *
 * The rest are the other shapes the same relationship breaks in: a dangling id,
 * a form closed too early, and a button whose type was left to default.
 *
 * Restores every file and compares byte for byte before exiting.
 */

$root = dirname( __DIR__ );

$plants = array(

    'THE 3.94.0 FAULT: Publish loses its form attribute' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => '<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" name="save_mode" value="publish"',
        'to'   => '<button type="submit" name="save_mode" value="publish"',
    ),

    'Submit for Review loses its form attribute' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => '<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" name="save_mode" value="review"',
        'to'   => '<button type="submit" name="save_mode" value="review"',
    ),

    'Save draft loses its form attribute' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => '<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" name="save_mode" value="<?php echo $keep_status ? \'keep\' : \'draft\'; ?>"',
        'to'   => '<button type="submit" name="save_mode" value="<?php echo $keep_status ? \'keep\' : \'draft\'; ?>"',
    ),

    'Delete points at a form id nothing opens' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => '<button type="submit" form="uc-delete-event-<?php echo (int) $event_id; ?>"',
        'to'   => '<button type="submit" form="uc-delete-event-nowhere"',
    ),

    'the menu toggle goes back to having no type, so it is a submit button again' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => '<button type="button" class="uc-portal-menu-btn" id="uc-menu-btn"',
        'to'   => '<button class="uc-portal-menu-btn" id="uc-menu-btn"',
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
        continue;
    }
    $at     = strpos( $src, $p['from'] );
    $broken = substr( $src, 0, $at ) . $p['to'] . substr( $src, $at + strlen( $p['from'] ) );
    file_put_contents( $path, $broken );

    $out  = array();
    $code = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/form-owner-audit.php' ) . ' 2>&1', $out, $code );

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

echo "\n";
if ( $missed ) {
    echo 'PLANTS NOT CAUGHT: ' . count( $missed ) . ' of ' . count( $plants ) . "\n";
    foreach ( $missed as $m ) { echo '  . ' . $m . "\n"; }
    exit( 1 );
}
echo 'caught ' . $caught . ' of ' . count( $plants ) . " planted faults, and every file is restored.\n";
exit( 0 );
