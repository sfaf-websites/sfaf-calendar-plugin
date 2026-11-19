<?php
/**
 * THE 3.142.1 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31421.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the announcement save and runs the check
 * that must catch it. The first two put back 3.142.0's notices, the fault on
 * the live site. Every file is put back from memory. Commit first, and never
 * run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$AD = 'admin/class-sfaf-admin.php';
$CS = 'public/css/calendar.css';
$L  = 'release-31421-live.php --run';
$I  = 'embed-identity-test.php';

$plants = array(
    'the saved message above the window, as in 3.142.0' => array( $L, 'A-full: the message is outside the window', array(
        array( $AD, '<div class="notice notice-success inline uc-ann-notice" role="status"><p>Announcement saved.</p></div>', '<div class="notice notice-success"><p>Announcement saved.</p></div>' ),
    ) ),
    'the refusal above the window, as in 3.142.0' => array( $L, 'R-picture: the message is outside the window', array(
        array( $AD, '<div class="notice notice-error inline uc-ann-notice" role="alert"><p><?php echo esc_html( $err ); ?></p></div>', '<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>' ),
    ) ),
    'a refused save empties the form' => array( $L, 'R-picture: what was typed is gone', array(
        array( $AD, "            set_transient( 'sfaf_ann_error_' . get_current_user_id(), array( 'message' => \$message, 'fields' => \$kept ), 60 );", "            set_transient( 'sfaf_ann_error_' . get_current_user_id(), array( 'message' => \$message, 'fields' => array() ), 60 );" ),
    ) ),
    'the file to choose again not named' => array( $L, 'R-icon: the message does not say to choose the icon again', array(
        array( $AD, "            if ( \$again ) {\n                \$message .= ' Choose the '", "            if ( false ) {\n                \$message .= ' Choose the '" ),
    ) ),
    'a too-large save draws as nothing' => array( $L, 'R-huge: no message', array(
        array( $AD, "            if ( \$this->announcement_post_lost() ) {", "            if ( false ) {" ),
    ) ),
    'a lost reason is silent' => array( $L, 'F-lost: no message', array(
        array( $AD, "        if ( 'ann_failed' === \$msg && '' === \$err ) {", "        if ( false ) {" ),
    ) ),
    'the picture never stored' => array( $L, 'the full save\'s icon and picture are not stored attachments', array(
        array( $AD, "            \$fields['image_id'] = \$att;\n", '' ),
    ) ),
    'the PNG icon never stored' => array( $L, 'the full save\'s icon and picture are not stored attachments', array(
        array( $AD, "            \$fields['icon_id']  = \$att;\n", '' ),
    ) ),
    'a refused save leaves a file behind' => array( $L, 'the Media Library got something other than', array(
        array( $AD, "        \$image_new = false;\n        if ( ! empty( \$_FILES['ann_image'] )", "        if ( ! empty( \$_FILES['ann_image'] ) && UPLOAD_ERR_OK === (int) \$_FILES['ann_image']['error'] ) { \$this->announcement_store( 'ann_image', array( 'png' => 'image/png' ) ); }\n        \$image_new = false;\n        if ( ! empty( \$_FILES['ann_image'] )" ),
    ) ),
    'the embed changes' => array( $I, 'differs from 3.142.0', array(
        array( $CS, "@container uc-calendar (max-width: 560px) {
    .uc-calendar td .uc-ann-bars { display: none; }", "@container uc-calendar (max-width: 560px) {
    .uc-calendar td .uc-ann-bars { display: none;  }" ),
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
