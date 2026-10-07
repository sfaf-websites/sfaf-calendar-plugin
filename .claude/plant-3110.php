<?php
/**
 * THE 3.110.0 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-3110.php [part of a plant name]
 *
 * A.1 the private link drawn before the event is saved private; B.4 a
 * registration accepted without agreement; D.2 a row with no email taken as
 * a recipient; E.1 a second person added when one place opens; F.1 the
 * second press not reverting a check-in; G.2 a public list asking for
 * scheduled events.
 *
 * A plant is one or more replacements, each written into the real file; the
 * check is run and every file is put back from memory, not from git, so an
 * uncommitted change cannot be lost. A replacement whose anchor is not there,
 * or is there twice, stops the run: a plant that did not apply proves nothing.
 * Never run this while run-all.sh is running.
 */

$root  = dirname( __DIR__ );
$php   = 'includes/class-sfaf-portal.php';
$live  = 'release-3110-live.php --run';

$plants = array(
    'A.1: the link drawn before the event is saved private' => array( $live, 'PLANT A.1', array(
        array( $php, '            <?php if ( $is_private && $card ) : ?>', '            <?php if ( $card ) : ?>' ),
    ) ),
    'B.4: a registration accepted without agreement' => array( 'agreement-test.php', 'PLANT B.4', array(
        array( 'includes/class-sfaf-rsvp.php', "            if ( empty( \$data['agreed'] ) ) {", "            if ( false ) {" ),
    ) ),
    'D.2: a row with no email taken as a recipient' => array( 'registrant-mail-test.php', 'PLANT D.2', array(
        array( 'includes/class-sfaf-registrant-mail.php', "                \$none[] = \$r;\n                continue;", "                \$none[] = \$r;" ),
    ) ),
    'E.1: one place open, two people added' => array( 'waitlist-test.php', 'PLANT E.1', array(
        array( 'includes/class-sfaf-waitlist.php', '            if ( self::free_places( $event_id, $format ) <= 0 ) {', '            if ( self::free_places( $event_id, $format ) < 0 ) {' ),
    ) ),
    'F.1: the second press does not revert' => array( 'checkin-test.php', 'PLANT F.1', array(
        array( 'includes/class-sfaf-checkin.php', "        \$now = empty( \$row->checked_in_at ) ? current_time( 'mysql' ) : null;", "        \$now = current_time( 'mysql' );" ),
    ) ),
    'G.2: the card list asks for scheduled events' => array( 'private-events-test.php', 'PLANT G.2', array(
        array( 'includes/class-sfaf-shortcodes.php', "            'post_status' => 'publish',\n            'meta_key'    => '_uc_event_date',", "            'post_status' => array( 'publish', 'future' ),\n            'meta_key'    => '_uc_event_date'," ),
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
