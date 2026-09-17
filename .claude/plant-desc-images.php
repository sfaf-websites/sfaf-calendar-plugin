<?php
/**
 * PLANT A FAULT IN THE DESCRIPTION-PICTURES PATH, RUN THE TEST, PUT IT BACK.
 *
 *     php .claude/plant-desc-images.php
 *
 * THE TWO THE BRIEF NAMES ARE THE FIRST TWO: a description picture appearing in
 * the featured picker, and a calendar-folder picture appearing in the Insert
 * image chooser. Both are folder-overlap faults and both are planted as the
 * rename that would actually cause them, rather than as a query change, because
 * the rename is how it would really happen.
 *
 * Restores every file and compares byte for byte before exiting.
 */

$root = dirname( __DIR__ );

$plants = array(

    /* ---- THE FOLDERS OVERLAP. ------------------------------------------ */
    'a description picture appears in the featured picker, because the folder became a child' => array(
        'file' => 'includes/class-sfaf-desc-images.php',
        'from' => "    const FOLDER = 'calendar-descriptions';",
        'to'   => "    const FOLDER = 'calendar/descriptions';",
    ),

    'a calendar-folder picture appears in the Insert image chooser' => array(
        'file' => 'includes/class-sfaf-desc-images.php',
        'from' => "                    'value'   => '^' . preg_quote( self::prefix() ),",
        'to'   => "                    'value'   => 'calendar',",
    ),

    'the chooser matches its folder anywhere in the path rather than at the front' => array(
        'file' => 'includes/class-sfaf-desc-images.php',
        'from' => "        return ( 0 === strpos( \$file, self::prefix() ) );",
        'to'   => "        return ( false !== strpos( \$file, self::prefix() ) );",
    ),

    /* ---- A PASTED PICTURE SURVIVES. ------------------------------------ */
    'a pasted picture survives the save' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "\$postarr['post_content'] = SFAF_Desc_Images::sanitize_description( wp_unslash( \$_POST['description'] ?? '' ) );",
        'to'   => "\$postarr['post_content'] = wp_kses_post( wp_unslash( \$_POST['description'] ?? '' ) );",
    ),

    'the dropper fails open when it cannot find the uploads URL' => array(
        'file' => 'includes/class-sfaf-desc-images.php',
        'from' => "                if ( '' === \$ours ) {\n                    // No uploads URL to compare against: keep nothing rather\n                    // than keep everything. A pass that fails open is not a pass.\n                    return '';\n                }",
        'to'   => "                if ( '' === \$ours ) {\n                    return \$m[0];\n                }",
    ),

    'an img with no src is kept, as a broken image in the middle of the prose' => array(
        'file' => 'includes/class-sfaf-desc-images.php',
        'from' => "                if ( ! preg_match( '#\\ssrc\\s*=\\s*([\\'\"])(.*?)\\1#i', \$m[0], \$src ) ) {\n                    return '';\n                }",
        'to'   => "                if ( ! preg_match( '#\\ssrc\\s*=\\s*([\\'\"])(.*?)\\1#i', \$m[0], \$src ) ) {\n                    return \$m[0];\n                }",
    ),

    'the pass drops pictures before it sanitises' => array(
        'file' => 'includes/class-sfaf-desc-images.php',
        'from' => "        return self::keep_only_ours( wp_kses_post( (string) \$html ) );",
        'to'   => "        return wp_kses_post( self::keep_only_ours( (string) \$html ) );",
    ),

    /* ---- THE BUTTON REACHES A PUBLIC FORM. ----------------------------- */
    'the Insert image chooser moves into the shared rich text control, so both public forms get it' => array(
        'file' => 'includes/class-sfaf-rich-text.php',
        'from' => "    public static function settings() {",
        'to'   => "    /* planted: SFAF_Desc_Images */\n    public static function settings() {",
    ),

    'the chooser is offered on a source-owned description' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "        if ( 'locked' !== \$state ) {\n            \$this->description_image_chooser();\n        }",
        'to'   => "        \$this->description_image_chooser();",
    ),

    /* ---- THE UPLOAD ENDPOINT. ------------------------------------------ */
    'the upload endpoint stops checking the calendar role' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "        if ( ! \$this->can_create( \$user ) ) {\n            wp_send_json_error( array( 'message' => 'You do not have permission to add pictures.' ), 403 );\n        }",
        'to'   => "",
    ),

    'the upload endpoint stops checking its nonce' => array(
        'file' => 'includes/class-sfaf-portal.php',
        'from' => "        if ( ! check_ajax_referer( SFAF_Desc_Images::ACTION, 'nonce', false ) ) {\n            wp_send_json_error( array( 'message' => 'This page has been open a while and its security token expired. Reload and try again.' ), 403 );\n        }\n        \$user = wp_get_current_user();\n        if ( ! \$this->can_create( \$user ) ) {",
        'to'   => "        \$user = wp_get_current_user();\n        if ( ! \$this->can_create( \$user ) ) {",
    ),

    'the upload skips the one guard between a file and the disk' => array(
        'file' => 'includes/class-sfaf-desc-images.php',
        'from' => "        \$seen = SFAF_Uploads::inspect( \$field );",
        'to'   => "        \$seen = array( 'ok' => true, 'error' => '', 'warning' => '' );",
    ),

    'the upload trusts its own filter instead of confirming where the file went' => array(
        'file' => 'includes/class-sfaf-desc-images.php',
        'from' => "        if ( ! self::holds( \$attachment_id ) ) {\n            wp_delete_attachment( \$attachment_id, true );",
        'to'   => "        if ( false ) {\n            wp_delete_attachment( \$attachment_id, true );",
    ),

    /* ---- THE SHAPE. ---------------------------------------------------- */
    'the event page and the editor frame stop agreeing about the corners' => array(
        'file' => 'public/css/calendar.css',
        'from' => "    border-radius: 14px;\n    float: none;\n}",
        'to'   => "    border-radius: 10px;\n    float: none;\n}",
    ),

    'the picture is squashed, because the height is no longer freed' => array(
        'file' => 'public/css/editor-content.css',
        'from' => "    height: auto;\n    margin: 1.2em 0;",
        'to'   => "    margin: 1.2em 0;",
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
    exec( 'php ' . escapeshellarg( $root . '/.claude/desc-images-test.php' ) . ' 2>&1', $out, $code );

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
