<?php
/**
 * PLANT A FAULT, RUN THE TEST, PUT IT BACK.
 *
 *     php .claude/plant-source-rsvps.php
 *
 * THE THREE THE BRIEF NAMES ARE FIRST: a source event saving with RSVPs on, a
 * hand-made event locked by mistake, and Enter in the title field reaching
 * Delete. The second is the one worth the most care, because it fails by taking
 * a working feature away from somebody who was using it rather than by leaving
 * one switched on.
 *
 * Restores every file and compares byte for byte before exiting.
 */

$root = dirname( __DIR__ );

$plants = array(

    /* ---- THE REFUSAL. --------------------------------------------------- */
    'a source event saves with RSVPs on' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "            if ( SFAF_Sources::takes_rsvps_at_source( \$event_id ) ) {\n                update_post_meta( \$event_id, '_uc_rsvp_enabled', '0' );\n            } else {\n                update_post_meta( \$event_id, '_uc_rsvp_enabled', isset( \$_POST['rsvp_enabled'] ) ? '1' : '0' );\n            }",
        'to'   => "            update_post_meta( \$event_id, '_uc_rsvp_enabled', isset( \$_POST['rsvp_enabled'] ) ? '1' : '0' );",
    ),

    'the save SKIPS a source event rather than forcing it off' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "            if ( SFAF_Sources::takes_rsvps_at_source( \$event_id ) ) {\n                update_post_meta( \$event_id, '_uc_rsvp_enabled', '0' );\n            } else {",
        'to'   => "            if ( SFAF_Sources::takes_rsvps_at_source( \$event_id ) ) {\n                // left alone\n            } else {",
    ),

    'a hand-made event is locked by mistake' => array(
        'file' => 'includes/class-sfaf-sources.php',
        'from' => "        return ( '' !== (string) get_post_meta( (int) \$post_id, self::META_SOURCE, true ) );",
        'to'   => "        return ( '' !== (string) get_post_meta( (int) \$post_id, '_uc_gofundme_url', true ) )\n            || ( '' !== (string) get_post_meta( (int) \$post_id, self::META_SOURCE, true ) );",
    ),

    'the reader stops refusing, so a stored 1 leaks a form' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    if ( class_exists( 'SFAF_Sources' ) && SFAF_Sources::takes_rsvps_at_source( \$post_id ) ) {\n        return false;\n    }",
        'to'   => "",
    ),

    'the control can render ticked on a source event' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "<?php checked( ! \$at_source && '1' === (string) \$g( '_uc_rsvp_enabled' ) ); ?>",
        'to'   => "<?php checked( \$g( '_uc_rsvp_enabled' ), '1' ); ?>",
    ),

    'the control stops being disabled' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "                           <?php disabled( \$at_source ); ?> />",
        'to'   => " />",
    ),

    'the capacity box is drawn on a source event' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "                if ( \$event_id && SFAF_Sources::takes_rsvps_at_source( \$event_id ) ) {\n                    break;\n                }",
        'to'   => "",
    ),

    /* ---- THE EVENT PAGE. ------------------------------------------------ */
    'the source link is built below the guard that returns first' => array(
        'file' => 'includes/sfaf-template-functions.php',
        'from' => "    \$at_source = SFAF_Sources::registration_url( \$post_id );",
        'to'   => "    \$at_source = '';",
    ),

    /* ---- THE ONE-TIME PASS. --------------------------------------------- */
    'the migration queries a key that does not exist' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "            array( 'key' => SFAF_Sources::META_SOURCE, 'compare' => 'EXISTS' ),",
        'to'   => "            array( 'key' => '_uc_source', 'compare' => 'EXISTS' ),",
    ),

    'the schema version is not bumped, so the pass never runs' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "define( 'SFAF_DB_VERSION', '8' );",
        'to'   => "define( 'SFAF_DB_VERSION', '7' );",
    ),

    'the pass stops reporting what it touched' => array(
        'file' => 'sfaf-calendar.php',
        'from' => "    update_option( 'sfaf_source_rsvp_migration', array(\n        'touched' => \$touched,",
        'to'   => "    if ( false ) { update_option( 'sfaf_source_rsvp_migration', array(\n        'touched' => \$touched,",
    ),

    'the count is drawn inside the branch a site with sources never reaches' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "        \$this->render_source_rsvp_migration_note();\n\n        \$key     = 'sfaf_fetch_report_' . \$user->ID;",
        'to'   => "        \$key     = 'sfaf_fetch_report_' . \$user->ID;",
    ),

    /* ---- THE KEYBOARD. -------------------------------------------------- */
    'Enter in the title field reaches Delete, because Delete became a submit of the event form' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "        <button type=\"submit\" form=\"uc-delete-event-<?php echo (int) \$event_id; ?>\"\n                class=\"uc-btn uc-btn-stop uc-editor-delete\"",
        'to'   => "        <button type=\"submit\"\n                class=\"uc-btn uc-btn-stop uc-editor-delete\"",
    ),

    'the row is reordered in the markup instead of in CSS, putting Delete first' => array(
        'file' => 'public/css/portal.css',
        'from' => ".uc-editor-actions .uc-editor-delete  { order: 1; }",
        'to'   => "",
    ),

    /* ---- THE COLOURS. --------------------------------------------------- */
    'Save draft goes back to green and Publish to yellow' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "class=\"uc-btn uc-btn-primary uc-editor-save\"",
        'to'   => "class=\"uc-btn uc-btn-go uc-editor-save\"",
    ),

    'Publish stops being the largest control in the row' => array(
        'file' => 'public/css/portal.css',
        'from' => "    padding: 13px 30px;\n    font-size: 15px;",
        'to'   => "    font-size: 15px;",
    ),

    'Delete loses its confirmation' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "                data-uc-confirm=\"Delete this event? Nothing puts it back.\">Delete</button>",
        'to'   => ">Delete</button>",
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
    if ( $broken === $src ) {
        $missed[] = $name . '  [the swap changed nothing]';
        continue;
    }
    file_put_contents( $path, $broken );

    $out  = array();
    $code = 0;
    exec( 'php ' . escapeshellarg( $root . '/.claude/source-rsvps-test.php' ) . ' 2>&1', $out, $code );

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
