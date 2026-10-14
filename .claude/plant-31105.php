<?php
/**
 * THE 3.110.5 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31105.php [part of a plant name]
 *
 * A.2 a stale post going out: the check's "no" ignored, and the check never
 * asked at all. A.3 the bare message rendered: wp_die( 'Security check
 * failed.' ) back on the token check, and a signed-out post falling through
 * to the plain sign-in.
 *
 * Each plant is written into the real file, the check is run, and every file
 * is put back from memory. A replacement whose anchor is not there exactly
 * once stops the run: a plant that did not apply proves nothing. Never run
 * this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$php  = 'includes/class-sfaf-portal.php';
$js   = 'public/js/caladmin-session.js';
$live = 'release-31105-live.php --run';

$plants = array(
    'A.2: the check says no and the post goes anyway' => array( $live, 'PLANT A.2', array(
        array( $js, "            if (!d || (d.signed_in !== false && d.valid !== false)) { go_on(); return; }", "            go_on(); return;" ),
    ) ),
    'A.2: the post is never held for the check' => array( $live, 'PLANT A.2', array(
        array( $js, "    document.addEventListener('submit', function (e) {", "    document.addEventListener('submit-unheard', function (e) {" ),
    ) ),
    'A.3: the bare message on a stale token' => array( 'session-ended-test.php', 'PLANT A.3', array(
        array( $php, "        if ( ! wp_verify_nonce( \$nonce, 'uc_portal_' . \$action ) ) {\n            return \$this->session_ended( 'save' );", "        if ( ! wp_verify_nonce( \$nonce, 'uc_portal_' . \$action ) ) {\n            wp_die( 'Security check failed.' );" ),
    ) ),
    'A.3: a signed-out post drops to the plain sign-in' => array( 'session-ended-test.php', 'PLANT A.3', array(
        array( $php, "                return \$this->session_ended( 'save' );\n            }\n            return false;", "                return false;\n            }\n            return false;" ),
    ) ),
    'A.3: an export with a stale token dies bare' => array( 'session-ended-test.php', 'PLANT A.3', array(
        array( $php, "        if ( ! is_user_logged_in() || ! wp_verify_nonce( \$nonce, 'uc_portal_export' ) ) {\n            return \$this->session_ended( 'download' );\n        }\n\n        \$event_id", "        if ( ! is_user_logged_in() || ! wp_verify_nonce( \$nonce, 'uc_portal_export' ) ) {\n            wp_die( 'Security check failed.' );\n        }\n\n        \$event_id" ),
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
            echo "ANCHOR NOT UNIQUE for \"$name\" in $file\n";
            exit( 1 );
        }
        file_put_contents( $path, str_replace( $from, $to, $now ) );
    }
    $out = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/' . strtok( $check, ' ' ) ) . ( false !== strpos( $check, '--run' ) ? ' --run' : '' ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( (string) $out ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
