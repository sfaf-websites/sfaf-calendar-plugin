<?php
/**
 * CHECK-IN, RUN (3.110.0).
 *
 *     php .claude/checkin-test.php
 *
 * The real SFAF_Checkin on mail-kit.php's table model.
 *
 *   F.1  Check in stamps the row and moves the attendance figure; a second
 *        press reverts it, clearing the time and taking the figure back
 *        PLANT F.1: the revert leaving the row checked in
 *   F.1  a row that is not registered cannot be checked in
 *   F.1  the mode is remembered per event, "by name" by default; a count is
 *        stored to the same figure; going back to by name recounts
 */

require __DIR__ . '/mail-kit.php';

$fails = array();
function ci( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

$rsvp = new SFAF_RSVP();
function ci_event() {
    $id = mk_post( array( 'post_title' => 'Coffee social' ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d', strtotime( '+1 day' ) ) );
    update_post_meta( $id, '_uc_start_time', '18:00' );
    update_post_meta( $id, '_uc_end_time', '19:00' );
    update_post_meta( $id, '_uc_rsvp_enabled', '1' );
    update_post_meta( $id, '_uc_capacity', '2' );
    return $id;
}
function ci_reg( $event_id, $first ) {
    global $rsvp;
    return $rsvp->submit( array( 'event_id' => $event_id, 'first_name' => $first, 'last_name' => '', 'email' => strtolower( $first ) . '@example.org',
        'phone' => '', 'optin' => false, 'format' => '', 'no_email' => false ) );
}
function ci_row( $first ) {
    foreach ( $GLOBALS['mk_db']['wp_uc_rsvps'] as $r ) { if ( $r['first_name'] === $first ) { return $r; } }
    return null;
}

mk_reset();
$E = ci_event();
ci_reg( $E, 'Ana' );
ci_reg( $E, 'Ben' );
ci_reg( $E, 'Cam' );   // third of two: waiting
ci( 'Cam is waiting', ci_row( 'Cam' )['status'], 'waitlisted' );

ci( 'by name is the default', SFAF_Checkin::mode( $E ), 'name' );
ci( 'nobody attended yet', SFAF_Checkin::attendance( $E ), null );

$a = SFAF_Checkin::toggle( (int) ci_row( 'Ana' )['id'] );
ci( 'Check in answers checked, with the time, and the count', array( $a['checked'], '' !== $a['at'], $a['count'] ), array( true, true, 1 ) );
ci( 'the row is stamped', null !== ci_row( 'Ana' )['checked_in_at'] && '' !== (string) ci_row( 'Ana' )['checked_in_at'], true );
ci( 'the attendance figure moves', SFAF_Checkin::attendance( $E ), 1 );
SFAF_Checkin::toggle( (int) ci_row( 'Ben' )['id'] );
ci( 'two checked in', array( SFAF_Checkin::checked_in_count( $E ), SFAF_Checkin::attendance( $E ) ), array( 2, 2 ) );

$a = SFAF_Checkin::toggle( (int) ci_row( 'Ana' )['id'] );
ci( 'PLANT F.1: a second press reverts', $a['checked'], false );
ci( 'PLANT F.1: the time is cleared', empty( ci_row( 'Ana' )['checked_in_at'] ), true );
ci( 'PLANT F.1: and the figure goes back', array( $a['count'], SFAF_Checkin::attendance( $E ) ), array( 1, 1 ) );

ci( 'a waiting row cannot be checked in', SFAF_Checkin::toggle( (int) ci_row( 'Cam' )['id'] ), null );
ci( 'and is left alone', empty( ci_row( 'Cam' )['checked_in_at'] ), true );
ci( 'nor a row that does not exist', SFAF_Checkin::toggle( 424242 ), null );

SFAF_Checkin::set_mode( $E, 'count' );
ci( 'the mode is remembered', SFAF_Checkin::mode( $E ), 'count' );
SFAF_Checkin::set_count( $E, 14 );
ci( 'a count is the same figure', SFAF_Checkin::attendance( $E ), 14 );
SFAF_Checkin::set_count( $E, -3 );
ci( 'never below nought', SFAF_Checkin::attendance( $E ), 0 );
$other = ci_event();
ci( 'per event', SFAF_Checkin::mode( $other ), 'name' );
SFAF_Checkin::set_mode( $E, 'name' );
ci( 'back to by name, the figure is the check-ins', SFAF_Checkin::attendance( $E ), 1 );

if ( $fails ) {
    echo 'CHECK-IN: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "check-in: a press stamps the row and the attendance figure, a second press reverts both; only registered rows; the mode remembered per event; a count stored to the same figure.\n";
