<?php
/**
 * THE 3.110.1 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31101.php [part of a plant name]
 *
 * A.1 a second search box on the RSVP list; B.5 an editor outside a team
 * reaching registration data, by the gate, by the unscoped export and by the
 * alert's link; C.2 RSVP settings saved while another site is chosen.
 *
 * A plant is one or more replacements, each written into the real file; the
 * check is run and every file is put back from memory, not from git, so an
 * uncommitted change cannot be lost. A replacement whose anchor is not there,
 * or is there twice, stops the run: a plant that did not apply proves nothing.
 * Never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$php  = 'includes/class-sfaf-portal.php';
$live = 'release-31101-live.php --run';

$plants = array(
    'A.1: a second search box on the RSVP list' => array( $live, 'PLANT A.1', array(
        array( $php, '            <p class="uc-rsvp-counts" data-uc-rsvp-counts>', '            <input type="search" placeholder="Find by name" autocomplete="off" />' . "\n" . '            <p class="uc-rsvp-counts" data-uc-rsvp-counts>' ),
    ) ),
    'B.5: the gate gives an editor every event again' => array( 'team-access-test.php', 'PLANT B.5', array(
        array( $php, "        if ( 'admin' === self::get_role( \$user_id ) ) {\n            return true;\n        }", "        if ( self::user_can_view_all( \$user_id ) ) {\n            return true;\n        }" ),
    ) ),
    'B.5: the unscoped export back on admin-or-editor' => array( 'team-access-test.php', 'PLANT B.5', array(
        array( $php, "        } elseif ( ! \$this->is_admin_role( \$user ) ) {\n            // Every registration on the calendar: an admin's alone (3.110.1).\n            wp_die( 'Denied' );", "        } elseif ( ! \$this->can_view_all( \$user ) ) {\n            wp_die( 'Denied' );" ),
    ) ),
    'B.5: the alert links an editor outside the team to the list' => array( 'team-access-test.php', 'PLANT B.5', array(
        array( 'includes/class-sfaf-notifications.php', "SFAF_Portal::user_can_edit_event( (int) \$entry['user_id'], (int) \$event_id ) );", "SFAF_Portal::user_can_view_all( (int) \$entry['user_id'] ) );" ),
    ) ),
    'C.2: RSVP settings saved while another site is chosen' => array( 'register-elsewhere-test.php', 'PLANT C.2', array(
        array( $php, "        if ( SFAF_Register_Elsewhere::is_on( \$event_id ) ) {\n            return;\n        }", "" ),
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
