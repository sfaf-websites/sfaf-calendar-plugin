<?php
/**
 * THE 3.118.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31180.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );

$plants = array(
    'A: the staff form loses the control' => array( 'release-31180-test.php', 'A: the staff form asks who should get RSVP updates', array(
        array( 'includes/class-sfaf-request.php', "                SFAF_Submissions::render_rsvp_updates(\n                    (string) \$email,", "                if ( false ) SFAF_Submissions::render_rsvp_updates(\n                    (string) \$email," ),
    ) ),
    'B: the one more address skips Approve' => array( 'release-31180-test.php', 'B: the requester goes on the list and the one more waits for Approve', array(
        array( 'includes/class-sfaf-request.php', "                update_post_meta( \$event_id, SFAF_Submit::META_NOTIFY_EMAILS, array( strtolower( (string) \$email ), \$c['rsvp_extra'] ) );", "                SFAF_Submissions::add_to_notify_list( \$event_id, \$c['rsvp_extra'] );" ),
    ) ),
    'C: an online event needs a street' => array( 'release-31180-test.php', 'C: an online event needs no street', array(
        array( 'includes/class-sfaf-submit.php', "                'when'    => array( 'venue' => '', 'online' => '' ),", "                'when'    => array( 'venue' => '' )," ),
    ) ),
    'D: Approve offers only with RSVPs on' => array( 'release-31180-test.php', 'D: a community request offers both, before RSVPs are on', array(
        array( 'includes/class-sfaf-submissions.php', "        if ( ! \$addresses || ( ! sfaf_event_takes_rsvps( (int) \$event_id ) && count( \$addresses ) < 2 ) ) {", "        if ( ! \$addresses || ! sfaf_event_takes_rsvps( (int) \$event_id ) ) {" ),
    ) ),
    'L: Your email does not reach the box' => array( 'release-31180-live.php', 'Your email does not fill the first RSVP updates box', array(
        array( 'public/js/portal.js', "        source.addEventListener('input', apply);\n        apply();\n    }\n    function initFollowCheck", "        apply();\n    }\n    function initFollowCheck" ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : ' --verbose';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
