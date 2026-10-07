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
 *   calendar file; since 3.110.0 a place released by the link or by Remove,
 *   or added by raising or emptying the capacity, goes straight to the first
 *   person for that format, one place one person, with the normal
 *   confirmation and the waitlist line; somebody with no email is added too
 *   and the notification list is told they could not be told; staff can
 *   confirm anybody; the offers left from before are put back in the queue
 *   once, by the cron, and the new rule run over them; hybrid keeps a queue
 *   per format; a third-party event and one with RSVPs off never have a
 *   waitlist.
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
( new SFAF_Waitlist() )->register();    // the next person in, on uc_rsvp_cancelled
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

/* ---- A place released by the link goes straight to the first in line (3.110.0). */
$GLOBALS['mk_mail'] = array();
SFAF_Reminders::cancel_rsvp( $E, 'ana@example.org' );
wl( 'Cam is registered at once', wl_row( 'Cam' )['status'], 'confirmed' );
$conf = wl_mail( 'cam@example.org', 'You are registered' );
wl( 'and sent the normal confirmation, with the calendar file', count( $conf ) === 1 && false !== strpos( $conf[0]['html'], 'uc_ics=' ), true );
wl( 'which says a place opened from the waitlist', isset( $conf[0] ) && false !== strpos( $conf[0]['text'], 'You were on the waitlist and a place has opened up.' ), true );
wl( 'no offer email any more', count( wl_mail( 'cam@example.org', 'A place is open' ) ), 0 );
wl( 'PLANT E.1: one place opened, one person added: Dee still waits', wl_row( 'Dee' )['status'], 'waitlisted' );
wl( 'the list is told Ana cancelled, as for any self-cancel', count( wl_mail( 'staff@sfaf.org', 'Ana' ) ), 1 );
wl( 'two registered again', sfaf_get_rsvp_count( $E ), 2 );
wl( 'the event is full again', sfaf_format_full( $E, '' ), true );
$f = wl_reg( $E, 'Fay', 'fay@example.org' );
wl( 'so Fay joins the waitlist behind Dee and Eve', array( ! empty( $f['waitlisted'] ), $f['position'] ), array( true, 3 ) );
wl( 'the waitlist is still in no audience', wl_reads( $E ), array( 'count' => 2, 'registrants' => 2, 'recipients' => 2, 'has' => true ) );

/* ---- Remove, and somebody with no email. --------------------------------- */
$GLOBALS['mk_mail'] = array();
SFAF_Reminders::remove_rsvp( (int) wl_row( 'Ben' )['id'], 7 );
wl( 'Dee, who gave no email, is added all the same', wl_row( 'Dee' )['status'], 'confirmed' );
$call = wl_mail( 'staff@sfaf.org', 'was added and needs a call' );
wl( 'the list is told she was added and could not be told, with her phone', count( $call ) === 1 && false !== strpos( $call[0]['text'], 'Dee' ) && false !== strpos( $call[0]['text'], 'could not be told' ) && false !== strpos( $call[0]['text'], '(415) 555-0100' ), true );
wl( 'nothing is mailed to an empty address', count( array_filter( $GLOBALS['mk_mail'], function ( $m ) { return '' === trim( (string) $m['to'] ); } ) ), 0 );
wl( 'PLANT E.1: and Eve still waits', wl_row( 'Eve' )['status'], 'waitlisted' );

/* ---- Staff confirm, leaving, capacity raised. ----------------------------- */
$GLOBALS['mk_mail'] = array();
wl( 'staff confirm Eve over the limit', SFAF_Waitlist::staff_confirm( (int) wl_row( 'Eve' )['id'] ), true );
wl( 'Eve is registered', wl_row( 'Eve' )['status'], 'confirmed' );
$ec = wl_mail( 'eve@example.org', 'You are registered' );
wl( 'and her confirmation has no waitlist line, staff having chosen her', isset( $ec[0] ) && false === strpos( $ec[0]['text'], 'You were on the waitlist' ), true );
wl( 'Fay leaves the waitlist', SFAF_Waitlist::leave( (int) wl_row( 'Fay' )['id'] ), true );
wl( 'her row is cancelled', wl_row( 'Fay' )['status'], 'cancelled' );
wl_reg( $E, 'Gus', 'gus@example.org' );
wl_reg( $E, 'Hud', 'hud@example.org' );
wl( 'Gus and Hud wait', array( wl_row( 'Gus' )['status'], wl_row( 'Hud' )['status'] ), array( 'waitlisted', 'waitlisted' ) );
update_post_meta( $E, '_uc_capacity', '4' );
SFAF_Waitlist::advance_all( $E );
wl( 'PLANT E.1: one place more adds Gus and only Gus', array( wl_row( 'Gus' )['status'], wl_row( 'Hud' )['status'] ), array( 'confirmed', 'waitlisted' ) );

/* ---- 0 places, then the limit taken off (3.107.0). ----------------------- */
mk_reset();
$open = wl_event( 10, array( '_uc_capacity' => '0' ) );
wl( 'PLANT B.3: 0 places waitlists the first person', ! empty( wl_reg( $open, 'Nia', 'nia@example.org' )['waitlisted'] ), true );
wl_reg( $open, 'Oz', '', '', '(415) 555-0101' );
wl_reg( $open, 'Pia', 'pia@example.org' );
$GLOBALS['mk_mail'] = array();
update_post_meta( $open, '_uc_capacity', '' );
SFAF_Waitlist::advance_all( $open );
wl( 'PLANT LIMIT OFF: emptying the box adds everybody waiting',
    array( wl_row( 'Nia' )['status'], wl_row( 'Oz' )['status'], wl_row( 'Pia' )['status'] ), array( 'confirmed', 'confirmed', 'confirmed' ) );
$each = function () {
    return array( count( wl_mail( 'nia@example.org', 'You are registered' ) ), count( wl_mail( 'pia@example.org', 'You are registered' ) ), count( wl_mail( 'staff@sfaf.org', 'was added and needs a call' ) ) );
};
wl( 'PLANT LIMIT OFF: one confirmation each, and one call for Oz', $each(), array( 1, 1, 1 ) );
SFAF_Waitlist::advance_all( $open );
wl( 'PLANT LIMIT OFF: a second save adds nobody twice', $each(), array( 1, 1, 1 ) );

/* ---- The offers from before 3.110.0, on the first cron run. -------------- */
mk_reset();
delete_option( SFAF_Waitlist::MIGRATED_OPTION );
$old = wl_event( 10, array( '_uc_capacity' => '1' ) );
wl_reg( $old, 'Rae', 'rae@example.org' );
wl_reg( $old, 'Sam', 'sam@example.org' );
wl_reg( $old, 'Tia', 'tia@example.org' );
// Rae cancels and Sam is mid-offer, as an older release left them.
$GLOBALS['mk_db']['wp_uc_rsvps'][ wl_row( 'Rae' )['id'] ]['status'] = 'cancelled';
$GLOBALS['mk_db']['wp_uc_rsvps'][ wl_row( 'Sam' )['id'] ]['status'] = 'offered';
$GLOBALS['mk_mail'] = array();
wl( 'the cron turns the one old offer back into the queue', SFAF_Waitlist::migrate(), array( 'migrated' => 1 ) );
wl( 'and the new rule adds Sam to the open place', wl_row( 'Sam' )['status'], 'confirmed' );
wl( 'Tia waits behind', wl_row( 'Tia' )['status'], 'waitlisted' );
wl( 'Sam is sent the confirmation with the waitlist line', count( wl_mail( 'sam@example.org', 'You were on the waitlist' ) ), 1 );
wl( 'the pass runs once', SFAF_Waitlist::migrate(), array( 'migrated' => 0 ) );

/* ---- Hybrid: a queue per format. --------------------------------------- */
mk_reset();
$hy = wl_event( 10, array( SFAF_Online::META_HYBRID => '1', '_uc_capacity' => '1', '_uc_capacity_online' => '1' ) );
wl_reg( $hy, 'Jo', 'jo@example.org', SFAF_Online::MODE_IN_PERSON );
wl( 'in person full: Kit waits', ! empty( wl_reg( $hy, 'Kit', 'kit@example.org', SFAF_Online::MODE_IN_PERSON )['waitlisted'] ), true );
wl( 'online still open: Lee registers', empty( wl_reg( $hy, 'Lee', 'lee@example.org', SFAF_Online::MODE_ONLINE )['waitlisted'] ), true );
SFAF_Reminders::cancel_rsvp( $hy, 'lee@example.org' );
wl( 'an online place freed is not given to the in-person queue', wl_row( 'Kit' )['status'], 'waitlisted' );
SFAF_Reminders::cancel_rsvp( $hy, 'jo@example.org' );
wl( 'an in-person place freed is', wl_row( 'Kit' )['status'], 'confirmed' );
wl( 'PLANT A.7: every read counts Kit, and only Kit', wl_reads( $hy ), array( 'count' => 1, 'registrants' => 1, 'recipients' => 1, 'has' => true ) );

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
echo "waitlist: full joins the queue with a position; a released or added place goes straight to the next person with the normal confirmation and the waitlist line, one place one person; no email is added and the list told; the old offers return to the queue once; the three reads count only the confirmed.\n";
