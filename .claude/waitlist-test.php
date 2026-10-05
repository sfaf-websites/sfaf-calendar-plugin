<?php
/**
 * THE WAITLIST, RUN (3.106.0).
 *
 *     php .claude/waitlist-test.php
 *
 * The real SFAF_RSVP, SFAF_Waitlist, SFAF_Reminders, SFAF_Notifications and
 * SFAF_Announce on mail-kit.php, whose table model runs the SQL and whose
 * wp_mail() records what was sent. What is read is the row, the count and the
 * mail, never the code.
 *
 *   full means the waitlist, with the position, and a confirmation with no
 *   calendar file; a place released by the link or by Remove is offered to the
 *   first person for that format, 24 hours, or 2 when the event is close;
 *   while an offer is out the place is held; accepting confirms and sends the
 *   normal confirmation; an offer that runs out is expired, told, and passed
 *   on; somebody with no email is named to the notification list with their
 *   phone and the offer moves on; staff can confirm anybody; capacity raised
 *   offers the new places; hybrid keeps a queue per format; a third-party
 *   event and one with RSVPs off never have a waitlist.
 *
 * A.7, THE THREE READS. SFAF_Announce::registrants(), has_registrations(),
 * SFAF_Reminders::recipients() and the count cache must all ask for
 * 'confirmed' and agree, with rows of every waitlist status present. Planted:
 * one of them widened to the waitlist.
 */

require __DIR__ . '/mail-kit.php';

$fails = array();
function wl( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

( new SFAF_Reminders() )->register();   // the cancel alert on uc_rsvp_cancelled
( new SFAF_Waitlist() )->register();    // the offer on uc_rsvp_cancelled
$rsvp = new SFAF_RSVP();

function wl_event( $days, $meta = array() ) {
    $id = mk_post( array( 'post_title' => 'Support group' ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d', strtotime( "+$days days" ) ) );
    update_post_meta( $id, '_uc_start_time', date( 'H:i', strtotime( '+1 hour' ) ) );
    update_post_meta( $id, '_uc_end_time', '23:59' );
    update_post_meta( $id, '_uc_rsvp_enabled', '1' );
    update_post_meta( $id, '_uc_capacity', '2' );
    update_post_meta( $id, SFAF_Reminders::NOTIFY_EMAILS_META, array( 'staff@sfaf.org' ) );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
function wl_reg( $event_id, $first, $email, $format = '', $phone = '' ) {
    global $rsvp;
    return $rsvp->submit( array( 'event_id' => $event_id, 'first_name' => $first, 'last_name' => '', 'email' => $email,
        'phone' => $phone, 'optin' => false, 'format' => $format, 'no_email' => '' === $email ) );
}
function wl_row( $first ) {
    foreach ( $GLOBALS['mk_db']['wp_uc_rsvps'] as $r ) { if ( $r['first_name'] === $first ) { return $r; } }
    return null;
}
function wl_mail( $to, $needle = '' ) {
    return array_values( array_filter( mk_mail_to( $to ), function ( $m ) use ( $needle ) {
        return '' === $needle || false !== strpos( $m['subject'] . $m['text'], $needle );
    } ) );
}

mk_reset();
$E = wl_event( 10 );

/* ---- Full means the waitlist. ------------------------------------------ */
wl( 'Ana registers', wl_reg( $E, 'Ana', 'ana@example.org' )['success'], true );
wl( 'Ben registers', wl_reg( $E, 'Ben', 'ben@example.org' )['success'], true );
$c = wl_reg( $E, 'Cam', 'cam@example.org' );
wl( 'Cam, third of two places, is waitlisted', array( $c['success'], ! empty( $c['waitlisted'] ), $c['position'] ), array( true, true, 1 ) );
wl( 'Cam\'s row is waitlisted', wl_row( 'Cam' )['status'], 'waitlisted' );
$cm = wl_mail( 'cam@example.org' );
wl( 'Cam is sent one message', count( $cm ), 1 );
wl( 'it is the waitlist confirmation, with the position', isset( $cm[0] ) && false !== strpos( $cm[0]['text'], 'You are number 1 on the waitlist' ), true );
wl( 'and no calendar file', isset( $cm[0] ) && false === strpos( $cm[0]['html'] . $cm[0]['text'], 'uc_ics=' ), true );
wl( 'Dee, no email, is waitlisted second', wl_reg( $E, 'Dee', '', '', '(415) 555-0100' )['position'], 2 );
wl( 'Eve is waitlisted third', wl_reg( $E, 'Eve', 'eve@example.org' )['position'], 3 );
wl( 'Cam cannot join twice', wl_reg( $E, 'Cam', 'cam@example.org' )['success'], false );

/* ---- A.7: the waitlist is in no audience, and the three reads agree. ---- */
function wl_reads( $event_id ) {
    return array(
        'count'       => sfaf_get_rsvp_count( $event_id ),
        'registrants' => count( SFAF_Announce::registrants( $event_id ) ),
        // The registrant rows only: recipients() also carries the notification list's copy.
        'recipients'  => count( array_filter( SFAF_Reminders::recipients( $event_id ), function ( $r ) { return 'rsvp' === $r['type']; } ) ),
        'has'         => SFAF_Announce::has_registrations( $event_id ),
    );
}
wl( 'PLANT A.7: the count, the registrants and the reminder recipients agree on the two confirmed', wl_reads( $E ), array( 'count' => 2, 'registrants' => 2, 'recipients' => 2, 'has' => true ) );

/* ---- A place released by the link is offered to the first in line. ------- */
$GLOBALS['mk_mail'] = array();
SFAF_Reminders::cancel_rsvp( $E, 'ana@example.org' );
$cam = wl_row( 'Cam' );
wl( 'Cam is offered the place', $cam['status'], 'offered' );
$hours = ( strtotime( $cam['offer_expires'] ) - strtotime( $cam['offered_at'] ) ) / 3600;
wl( 'for 24 hours, the event being ten days off', (int) round( $hours ), 24 );
$offer = wl_mail( 'cam@example.org', 'A place is open' );
wl( 'Cam is emailed the offer', count( $offer ), 1 );
wl( 'with a confirm link', isset( $offer[0] ) && false !== strpos( $offer[0]['text'], 'uc_rsvp_offer=' . $cam['offer_token'] ), true );
wl( 'the list is told Ana cancelled, as for any self-cancel', count( wl_mail( 'staff@sfaf.org', 'Ana' ) ), 1 );
wl( 'the held place makes the event full', sfaf_format_full( $E, '' ), true );
$f = wl_reg( $E, 'Fay', 'fay@example.org' );
wl( 'so Fay joins the waitlist rather than taking it', ! empty( $f['waitlisted'] ), true );
wl( 'the waitlist is still in no audience', wl_reads( $E ), array( 'count' => 1, 'registrants' => 1, 'recipients' => 1, 'has' => true ) );

/* ---- Accepting. ---------------------------------------------------------- */
$GLOBALS['mk_mail'] = array();
wl( 'Cam accepts', SFAF_Waitlist::accept( $cam['offer_token'] ), 'done' );
wl( 'Cam is registered', wl_row( 'Cam' )['status'], 'confirmed' );
$conf = wl_mail( 'cam@example.org', 'You are registered' );
wl( 'and sent the normal confirmation, with the calendar file', count( $conf ) === 1 && false !== strpos( $conf[0]['html'], 'uc_ics=' ), true );
wl( 'the offer link does not work twice', SFAF_Waitlist::accept( $cam['offer_token'] ), 'invalid' );
wl( 'two registered again', sfaf_get_rsvp_count( $E ), 2 );

/* ---- Remove, and somebody with no email. --------------------------------- */
$GLOBALS['mk_mail'] = array();
SFAF_Reminders::remove_rsvp( (int) wl_row( 'Ben' )['id'], 7 );
wl( 'Dee, who gave no email, is marked for a call', wl_row( 'Dee' )['status'], 'offered_manual' );
$call = wl_mail( 'staff@sfaf.org', 'needs a phone call' );
wl( 'the list is told her name and phone', count( $call ) === 1 && false !== strpos( $call[0]['text'], 'Dee' ) && false !== strpos( $call[0]['text'], '(415) 555-0100' ), true );
wl( 'and the offer moves on to Eve', wl_row( 'Eve' )['status'], 'offered' );

/* ---- An offer that runs out. -------------------------------------------- */
$eve = wl_row( 'Eve' );
$GLOBALS['mk_db']['wp_uc_rsvps'][ $eve['id'] ]['offer_expires'] = date( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
$GLOBALS['mk_mail'] = array();
wl( 'the cron expires one offer', SFAF_Waitlist::run_expiry(), array( 'expired' => 1 ) );
wl( 'Eve\'s row is expired', wl_row( 'Eve' )['status'], 'expired' );
wl( 'Eve is told the offer passed', count( wl_mail( 'eve@example.org', 'has passed' ) ), 1 );
wl( 'and the offer moves on to Fay', wl_row( 'Fay' )['status'], 'offered' );
wl( 'an expired offer cannot be accepted', SFAF_Waitlist::accept( $eve['offer_token'] ), 'gone' );

/* ---- Staff confirm a row with no email. ---------------------------------- */
$GLOBALS['mk_mail'] = array();
wl( 'staff confirm Dee', SFAF_Waitlist::staff_confirm( (int) wl_row( 'Dee' )['id'] ), true );
wl( 'Dee is registered', wl_row( 'Dee' )['status'], 'confirmed' );
wl( 'nothing is mailed to an empty address', count( array_filter( $GLOBALS['mk_mail'], function ( $m ) { return '' === trim( (string) $m['to'] ); } ) ), 0 );

/* ---- Leaving, capacity raised. ------------------------------------------ */
wl( 'Fay leaves the waitlist', SFAF_Waitlist::leave( (int) wl_row( 'Fay' )['id'] ), true );
wl( 'her row is cancelled', wl_row( 'Fay' )['status'], 'cancelled' );
wl_reg( $E, 'Gus', 'gus@example.org' );
wl( 'Gus waits', wl_row( 'Gus' )['status'], 'waitlisted' );
update_post_meta( $E, '_uc_capacity', '3' );
SFAF_Waitlist::advance_all( $E );
wl( 'capacity raised offers Gus the new place', wl_row( 'Gus' )['status'], 'offered' );

/* ---- Close to the event, two hours. -------------------------------------- */
mk_reset();
$soon = wl_event( 0, array( '_uc_capacity' => '1' ) );
wl_reg( $soon, 'Hal', 'hal@example.org' );
wl_reg( $soon, 'Ivy', 'ivy@example.org' );
SFAF_Reminders::cancel_rsvp( $soon, 'hal@example.org' );
$ivy = wl_row( 'Ivy' );
wl( 'an event starting within 24 hours offers for 2', (int) round( ( strtotime( $ivy['offer_expires'] ) - strtotime( $ivy['offered_at'] ) ) / 3600 ), 2 );

/* ---- 0 places, then the limit taken off (3.107.0). ----------------------- */
mk_reset();
$open = wl_event( 10, array( '_uc_capacity' => '0' ) );
wl( 'PLANT B.3: 0 places waitlists the first person', ! empty( wl_reg( $open, 'Nia', 'nia@example.org' )['waitlisted'] ), true );
wl_reg( $open, 'Oz', '', '', '(415) 555-0101' );
wl_reg( $open, 'Pia', 'pia@example.org' );
wl_reg( $open, 'Quin', 'quin@example.org' );
$GLOBALS['mk_mail'] = array();
update_post_meta( $open, '_uc_capacity', '' );
SFAF_Waitlist::advance_all( $open );
wl( 'PLANT LIMIT OFF: emptying the box offers everybody waiting, in order',
    array( wl_row( 'Nia' )['status'], wl_row( 'Oz' )['status'], wl_row( 'Pia' )['status'], wl_row( 'Quin' )['status'] ),
    array( 'offered', 'offered_manual', 'offered', 'offered' ) );
$each = function () {
    return array( count( wl_mail( 'nia@example.org', 'A place is open' ) ), count( wl_mail( 'pia@example.org', 'A place is open' ) ), count( wl_mail( 'quin@example.org', 'A place is open' ) ), count( wl_mail( 'staff@sfaf.org', 'needs a phone call' ) ) );
};
wl( 'PLANT LIMIT OFF: one offer each, through the offer email, and one call for Oz', $each(), array( 1, 1, 1, 1 ) );
SFAF_Waitlist::advance_all( $open );
wl( 'PLANT LIMIT OFF: a second save offers nobody twice', $each(), array( 1, 1, 1, 1 ) );
wl( 'and Nia can accept it', SFAF_Waitlist::accept( wl_row( 'Nia' )['offer_token'] ), 'done' );

/* ---- Hybrid: a queue per format. --------------------------------------- */
mk_reset();
$hy = wl_event( 10, array( SFAF_Online::META_HYBRID => '1', '_uc_capacity' => '1', '_uc_capacity_online' => '1' ) );
wl_reg( $hy, 'Jo', 'jo@example.org', SFAF_Online::MODE_IN_PERSON );
wl( 'in person full: Kit waits', ! empty( wl_reg( $hy, 'Kit', 'kit@example.org', SFAF_Online::MODE_IN_PERSON )['waitlisted'] ), true );
wl( 'online still open: Lee registers', empty( wl_reg( $hy, 'Lee', 'lee@example.org', SFAF_Online::MODE_ONLINE )['waitlisted'] ), true );
SFAF_Reminders::cancel_rsvp( $hy, 'lee@example.org' );
wl( 'an online place freed is not offered to the in-person queue', wl_row( 'Kit' )['status'], 'waitlisted' );
SFAF_Reminders::cancel_rsvp( $hy, 'jo@example.org' );
wl( 'an in-person place freed is', wl_row( 'Kit' )['status'], 'offered' );
wl( 'PLANT A.7: with only an offer out, every read is empty', wl_reads( $hy ), array( 'count' => 0, 'registrants' => 0, 'recipients' => 0, 'has' => false ) );

/* ---- Never a waitlist. -------------------------------------------------- */
mk_reset();
wl( 'RSVPs off: no waitlist', SFAF_Waitlist::applies( wl_event( 10, array( '_uc_rsvp_enabled' => '0' ) ) ), false );
wl( 'no limit: no waitlist', SFAF_Waitlist::applies( wl_event( 10, array( '_uc_capacity' => '' ) ) ), false );
$third = wl_event( 10 );
update_post_meta( $third, SFAF_Sources::META_SOURCE, 'eventbrite' );
update_post_meta( $third, '_uc_source_url', 'https://www.eventbrite.com/e/1' );
wl( 'a third-party event: no waitlist', SFAF_Waitlist::applies( $third ), '' === SFAF_Sources::registration_url( $third ) );

/* ---- A.7 held up by the source too. -------------------------------------- */
$root = dirname( __DIR__ );
$rem  = (string) file_get_contents( $root . '/includes/class-sfaf-reminders.php' );
$ann  = (string) file_get_contents( $root . '/includes/class-sfaf-announce.php' );
$main = (string) file_get_contents( $root . '/sfaf-calendar.php' );
foreach ( array( 'recipients()' => $rem, 'registrants() and has_registrations()' => $ann, 'the count cache' => $main ) as $what => $src ) {
    wl( "PLANT A.7: $what never asks for a waitlist status", (bool) preg_match( "/status\s*(?:=\s*'(?:waitlisted|offered|offered_manual|expired)'|IN\s*\([^)]*'(?:waitlisted|offered|offered_manual|expired)')/", $src ), false );
}

if ( $fails ) {
    echo 'WAITLIST: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "waitlist: full joins the queue with a position; a released or added place is offered in turn, held, accepted or passed on; no email means a call; the three reads count only the confirmed.\n";
