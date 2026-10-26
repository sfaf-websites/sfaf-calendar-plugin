<?php
/**
 * 3.118.0: WHO SHOULD GET RSVP UPDATES ON BOTH FORMS, APPROVE OFFERS THEM,
 * AND ONLINE EVENTS ON THE COMMUNITY FORM.
 *
 *     php .claude/release-31180-test.php
 *
 * The real plugin through wp-kit.php.
 *
 *   A  one control on both forms: "Who should get RSVP updates?", the
 *      requester's address read-only and one more; no email switches; the
 *      community form's five-address repeater is one "Your email"
 *   B  the staff form: with yes, the requester is on the list and the one
 *      more address waits for Approve; with no, nothing is kept
 *   C  the community form: the one more address is kept for Approve; an
 *      online event needs no street and is saved online, with no place
 *   D  Approve offers the request's addresses that are not on the list yet:
 *      the staff request's one more address, the community form's addresses
 *      even before RSVPs are on, nothing once all are listed
 *
 * The community form in Chrome is release-31180-live.php. PLANTs in plant-31180.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function rt_cap( $fn ) { ob_start(); try { $fn(); } catch ( Throwable $e ) { echo 'THREW ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine(); } return (string) ob_get_clean(); }
$root = $GLOBALS['kit_root'];
$S    = 'SFAF_Submissions';
$GLOBALS['kit_query'] = function ( $a ) { return array(); };

/* ======================================================================== A */
$staff = rt_cap( function () { kit_call( 'SFAF_Request', 'render_form', null, array( 'tok', 'pat@sfaf.org', array(), array() ) ); } );
$series = SFAF_Series::get( 11 );
$comm  = rt_cap( function () use ( $series ) { kit_call( 'SFAF_Submit', 'render_form', null, array( $series, array(), array() ) ); } );
foreach ( array( 'staff' => $staff, 'community' => $comm ) as $w => $h ) {
    rt( "A: the $w form asks who should get RSVP updates", false !== strpos( $h, '<span class="uc-field-label" id="uc-rsvp-updates-label">Who should get RSVP updates?</span>' ), true );
    rt( "A: with one more address", 1 === substr_count( $h, 'name="rsvp_updates_extra"' ), true );
    rt( "A: and no email switches on the $w form", false === strpos( $h, 'notify_off' ) && false === strpos( $h, 'Emails for this event' ), true );
}
rt( 'A: the staff form shows the requester read-only', (bool) preg_match( '#<input type="email" value="pat@sfaf.org" readonly tabindex="-1"\s+aria-labelledby="uc-rsvp-updates-label" />#', $staff ), true );
// The line Mark gave in 3.122.0 replaces the 3.118.0 hint.
rt( 'A: and says what they get', false !== strpos( $staff, 'They&#039;ll get the RSVP count the day before and the list of who&#039;s coming two hours before.' ), true );
rt( 'A: the old yes line is gone', false === strpos( $staff, 'With yes, you get two emails once it is published' ), true );
rt( 'A: the community form shows the address typed above', false !== strpos( $comm, 'data-uc-rsvp-updates-you placeholder="Your email, from above"' ) && false !== strpos( $comm, 'data-uc-rsvp-updates-source' ), true );
rt( 'A: one "Your email", no repeater', 1 === substr_count( $comm, 'name="submitter_email[]"' ) && false === strpos( $comm, '+ Add email' ) && false === strpos( $comm, 'anybody else who should get RSVPs' ), true );
rt( 'A: the community form has an online tick', (bool) preg_match( '#<input type="checkbox" name="online" value="1" data-uc-reveal="uc-where-place" data-uc-reveal-when="unchecked"\s*/>\s*This is an online event#', $comm ), true );

/* ======================================================================== B */
$r = SFAF_Request::validate( array( 'rsvp' => '1', 'rsvp_updates_extra' => ' Sam@Example.org ' ) );
rt( 'B: the one more address is read with yes', $r['clean']['rsvp_extra'], 'sam@example.org' );
$r = SFAF_Request::validate( array( 'rsvp' => '0', 'rsvp_updates_extra' => 'sam@example.org' ) );
rt( 'B: and not with no', $r['clean']['rsvp_extra'], '' );
$r = SFAF_Request::validate( array( 'rsvp' => '1', 'rsvp_updates_extra' => 'not an address' ) );
rt( 'B: a wrong one is refused', isset( $r['errors']['rsvp_updates_extra'] ), true );
$rq = file_get_contents( $root . '/includes/class-sfaf-request.php' );
$at = strpos( $rq, "        if ( \$c['rsvp'] ) {\n            SFAF_Submissions::add_to_notify_list( \$event_id, \$email );" );
rt( 'B: the requester goes on the list and the one more waits for Approve', false !== $at && false !== strpos( substr( $rq, $at, 900 ), "update_post_meta( \$event_id, SFAF_Submit::META_NOTIFY_EMAILS, array( strtolower( (string) \$email ), \$c['rsvp_extra'] ) );" ), true );
rt( 'B: the requester keeps the two emails', false !== strpos( substr( $rq, $at, 900 ), 'array_diff( SFAF_Notifications::choosable(), self::RSVP_EMAILS )' ) && false !== strpos( $rq, "const RSVP_EMAILS = array( 'day_before', 'summary' );" ), true );

/* ======================================================================== C */
$r = SFAF_Submit::validate( array( 'submitter_email' => array( 'me@example.org', 'old@example.org' ), 'rsvp_updates_extra' => 'pal@example.org' ) );
rt( 'C: the submitter, then the one more', $r['clean']['submitter_emails'], array( 'me@example.org', 'pal@example.org' ) );
$r = SFAF_Submit::validate( array( 'submitter_email' => array( 'me@example.org' ), 'rsvp_updates_extra' => 'me@example.org' ) );
rt( 'C: the same address twice is once', $r['clean']['submitter_emails'], array( 'me@example.org' ) );
$r = SFAF_Submit::validate( array( 'submitter_email' => array( 'me@example.org' ), 'rsvp_updates_extra' => 'nope' ) );
rt( 'C: a wrong one is refused', isset( $r['errors']['rsvp_updates_extra'] ), true );
$r = SFAF_Submit::validate( array( 'submitter_email' => array( 'me@example.org' ), 'online' => '1', 'venue' => '12' ) );
rt( 'C: an online event needs no street', isset( $r['errors']['street'] ), false );
rt( 'C: and takes no venue', $r['clean']['venue'], 0 );
$r = SFAF_Submit::validate( array( 'submitter_email' => array( 'me@example.org' ) ) );
rt( 'C: an event in person still needs one', isset( $r['errors']['street'] ), true );
$sb = file_get_contents( $root . '/includes/class-sfaf-submit.php' );
rt( 'C: the online event is saved online', false !== strpos( $sb, "        if ( ! empty( \$c['online'] ) ) {\n            SFAF_Online::set( \$event_id, true );\n        }" ), true );

/* ======================================================================== D */
function rt_req( $kind, $email, $extra = array(), $listed = array(), $rsvp = false ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'pending', 'post_title' => 'A request' ) );
    update_post_meta( $id, SFAF_Submissions::META_KIND, $kind );
    update_post_meta( $id, SFAF_Request::META_EMAIL, $email );
    if ( $extra ) { update_post_meta( $id, SFAF_Submit::META_NOTIFY_EMAILS, array_merge( array( $email ), $extra ) ); }
    if ( $listed ) { update_post_meta( $id, SFAF_Reminders::NOTIFY_EMAILS_META, $listed ); }
    if ( $rsvp ) { update_post_meta( $id, '_uc_rsvp_enabled', '1' ); }
    return $id;
}
$e = rt_req( SFAF_Submissions::KIND_STAFF, 'pat@sfaf.org', array( 'sam@example.org' ), array( 'pat@sfaf.org' ), true );
rt( 'D: a staff request offers its one more address', $S::approval_offers( $e ), array( 'sam@example.org' ) );
$e = rt_req( SFAF_Submissions::KIND_COMMUNITY, 'me@example.org', array( 'pal@example.org' ) );
rt( 'D: a community request offers both, before RSVPs are on', $S::approval_offers( $e ), array( 'me@example.org', 'pal@example.org' ) );
$e = rt_req( SFAF_Submissions::KIND_COMMUNITY, 'me@example.org' );
rt( 'D: the submitter alone, with no RSVPs, offers nothing', $S::approval_offers( $e ), array() );
$e = rt_req( SFAF_Submissions::KIND_COMMUNITY, 'me@example.org', array(), array(), true );
rt( 'D: with RSVPs on, the submitter', $S::approval_offers( $e ), array( 'me@example.org' ) );
$e = rt_req( SFAF_Submissions::KIND_STAFF, 'pat@sfaf.org', array( 'sam@example.org' ), array( 'pat@sfaf.org', 'sam@example.org' ), true );
rt( 'D: nothing once all are listed', $S::approval_offers( $e ), array() );
$p = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
rt( 'D: the dialog and the save ask the same rule', false !== strpos( $p, '$offers   = SFAF_Submissions::approval_offers( $event_id );' ) && false !== strpos( $p, 'foreach ( SFAF_Submissions::approval_offers( $event_id ) as $addr ) {' ), true );

echo "\n3.118.0: RSVP updates on both forms, Approve offers them, online on the community form\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. Both forms ask who should get RSVP updates with one control, the one more address waits for\nApprove, which offers every address not yet listed, and an online event needs no street.\n";
exit( 0 );
