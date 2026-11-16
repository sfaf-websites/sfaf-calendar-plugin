<?php
/**
 * THE 3.140.1 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31401.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the staff request form's front
 * controller, its Turnstile widget and check, its domain rule and the address
 * it saves, and runs the check that must catch it. Every file is put back from
 * memory. Commit first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$R = 'includes/class-sfaf-request.php';
$T = 'release-31401-test.php';
$F = 'request-form-test.php';

$plants = array(
    'a visit renders nothing' => array( $T, 'PLANT G:', array(
        array( $R, "        self::render_form( self::remembered(), array(), array() );\n        exit;", "        exit;" ),
    ) ),
    'no widget on the form' => array( $T, 'PLANT T: the form does not draw', array(
        array( $R, "                <?php SFAF_Turnstile::field(); ?>\n", "" ),
    ) ),
    'Turnstile never asked' => array( $T, 'PLANT T: no Turnstile answer is not refused', array(
        array( $R, "        if ( ! SFAF_Turnstile::verify() ) {", "        if ( false && ! SFAF_Turnstile::verify() ) {" ),
    ) ),
    'Turnstile asked after the upload' => array( $F, 'Turnstile is not checked first', array(
        array( $R, "        if ( ! SFAF_Turnstile::verify() ) {", "        if ( false ) {" ),
        array( $R, "        if ( '' !== \$upload['error'] ) {\n            \$checked['errors']['uc_image']", "        if ( ! SFAF_Turnstile::verify() ) { return; }\n        if ( '' !== \$upload['error'] ) {\n            \$checked['errors']['uc_image']" ),
    ) ),
    'any domain accepted' => array( $T, 'PLANT S: an outside address is not refused', array(
        array( $R, "if ( '' !== \$clean['email'] && ! self::is_staff_address( \$clean['email'] ) ) {", "if ( false ) {" ),
    ) ),
    'the saved address is not the typed one' => array( $T, 'PLANT S: the request is not one pending event from the typed address', array(
        array( $R, "        \$email = \$checked['clean']['email'];", "        \$email = 'nobody@sfaf.org';" ),
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
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
