<?php
/**
 * PLANT A FAULT IN THE EXTRA-PICTURES PATH, RUN THE TEST, PUT IT BACK.
 *
 *     php .claude/plant-extras.php
 *
 * Each plant is an exact string swap that is checked for having happened, for
 * the reason plant-video.php records: a substitution that silently matches
 * nothing leaves the source untouched and the test passes for the one reason
 * that proves nothing.
 *
 * Every file is restored and compared byte for byte before this exits, because
 * a planted fault left in the tree is a planted fault that ships.
 */

$root = dirname( __DIR__ );

$plants = array(

    'a third extra picture is accepted' => array(
        'file' => 'includes/class-sfaf-submit.php',
        'from' => "    const MAX_EXTRA = 2;",
        'to'   => "    const MAX_EXTRA = 3;",
    ),

    'the extras are never stored on the event' => array(
        'file' => 'includes/class-sfaf-submit.php',
        'from' => "        update_post_meta( \$event_id, self::META_IMAGE_EXTRA, array_values( array_map( 'intval', \$ids ) ) );",
        'to'   => "        // stored nowhere",
    ),

    'a refused extra takes the good one down with it' => array(
        'file' => 'includes/class-sfaf-submit.php',
        'from' => "            if ( '' !== \$up['error'] ) {\n                \$errors[ \$field ] = \$up['error'];\n                continue;\n            }",
        'to'   => "            if ( '' !== \$up['error'] ) {\n                return array( 'ids' => array(), 'warnings' => array(), 'errors' => array( \$field => \$up['error'] ) );\n            }",
    ),

    'the size warning no longer travels with an extra' => array(
        'file' => 'includes/class-sfaf-submit.php',
        'from' => "            \$warnings[] = (string) \$up['warning'];",
        'to'   => "            \$warnings[] = '';",
    ),

    'the community form stops deleting its extras when the submission is rejected' => array(
        'file' => 'includes/class-sfaf-submit.php',
        'from' => "            foreach ( \$extra['ids'] as \$extra_id ) {\n                wp_delete_attachment( \$extra_id, true );\n            }\n            self::render_form( \$series, \$checked['clean'], \$checked['errors'] );",
        'to'   => "            self::render_form( \$series, \$checked['clean'], \$checked['errors'] );",
    ),

    'the staff form stops deleting its extras when the request is rejected' => array(
        'file' => 'includes/class-sfaf-request.php',
        'from' => "            foreach ( \$extra['ids'] as \$extra_id ) {\n                wp_delete_attachment( \$extra_id, true );\n            }\n            self::render_form( \$token, \$email, \$checked['clean'], \$checked['errors'] );",
        'to'   => "            self::render_form( \$token, \$email, \$checked['clean'], \$checked['errors'] );",
    ),

    'the staff form stops offering the control' => array(
        'file' => 'includes/class-sfaf-request.php',
        'from' => "            <?php SFAF_Submissions::extra_images_field( \$extra_errors ); ?>",
        'to'   => "",
    ),

    'the extras are drawn on only one of the two surfaces' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "            \$this->render_submitted_extras( \$event_id, 'medium' );",
        'to'   => "            // not drawn here",
    ),

    'an extra submitted picture is set as the event thumbnail' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "        \$ids = SFAF_Submit::extras( \$event_id );",
        'to'   => "        \$ids = SFAF_Submit::extras( \$event_id );\n        if ( \$ids ) { update_post_meta( \$event_id, '_thumbnail_id', (int) \$ids[0] ); }",
    ),

    'the typed image URL is deleted in a third place' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "        \$notes = SFAF_Submit::extra_notes( \$event_id );",
        'to'   => "        \$notes = SFAF_Submit::extra_notes( \$event_id );\n        delete_post_meta( \$event_id, '_uc_image_url' );",
    ),

    'the picker starts listing the submissions folder extras' => array(
        'file' => 'includes/class-sfaf-media.php',
        'from' => "<?php",
        'to'   => "<?php\n/* planted: _uc_submitted_image_extra */",
    ),
);

$caught = 0;
$missed = array();
$good   = array();

// Read every file once, up front, so a restore never depends on a later read.
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
    // Replace the FIRST occurrence only, so a plant cannot quietly hit two places.
    $at     = strpos( $src, $p['from'] );
    $broken = substr( $src, 0, $at ) . $p['to'] . substr( $src, $at + strlen( $p['from'] ) );
    if ( $broken === $src ) {
        $missed[] = $name . '  [the swap changed nothing]';
        continue;
    }
    file_put_contents( $path, $broken );

    $out  = array();
    $code = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/extra-images-test.php' ) . ' 2>&1', $out, $code );

    file_put_contents( $path, $src );

    if ( 0 !== $code ) {
        $caught++;
        echo '  caught: ' . $name . "\n";
    } else {
        $missed[] = $name;
        echo '  MISSED: ' . $name . "\n";
    }
}

// Everything back exactly as it was, or do not build.
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
