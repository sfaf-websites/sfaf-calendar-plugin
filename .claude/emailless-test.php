<?php
/**
 * REGISTERING WITHOUT AN EMAIL (3.102.0).
 *
 *     php .claude/emailless-test.php
 *
 * The real SFAF_RSVP::submit(), its uc_rsvp_submitted routing, the real
 * reminder pass and ledger, over mail-kit.php. What is asserted: the tick is
 * the only way past the email check and it saves no address; two such people
 * are two registrations and two ledger rows; nothing is ever sent to them and
 * the ledger says so; the list is still told and the counts still include
 * them; the summary names them with no address; joining online is refused.
 */

require __DIR__ . '/mail-kit.php';

$fails = array();
function check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function same( $label, $got, $want ) { check( $got === $want, sprintf( '%s: got %s, wanted %s', $label, var_export( $got, true ), var_export( $want, true ) ) ); }

$rsvp = new SFAF_RSVP();
$rsvp->register();   // hooks route_submission onto uc_rsvp_submitted

function event( $mode = '' ) {
    $org = mk_user( 'org@sfaf.org', 'Org', 'contributor' );
    $id  = mk_post( array( 'post_title' => 'Support group', 'post_author' => $org ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d' ) );
    update_post_meta( $id, '_uc_start_time', '00:30' );   // before 6 am: its reminder is due from midnight
    update_post_meta( $id, '_uc_end_time', '23:30' );
    update_post_meta( $id, '_uc_rsvp_enabled', '1' );
    update_post_meta( $id, SFAF_Reminders::NOTIFY_EMAILS_META, array( 'staff@sfaf.org' ) );
    if ( SFAF_Online::MODE_ONLINE === $mode ) { update_post_meta( $id, SFAF_Online::META, '1' ); }
    if ( SFAF_Online::MODE_HYBRID === $mode ) { update_post_meta( $id, SFAF_Online::META_HYBRID, '1' ); }
    return $id;
}
function reg( $event_id, $first, $email, $no_email, $format = '' ) {
    global $rsvp;
    return $rsvp->submit( array( 'event_id' => $event_id, 'first_name' => $first, 'last_name' => '', 'email' => $email,
        'phone' => '', 'optin' => true, 'format' => $format, 'no_email' => $no_email ) );
}
function to_blank() { return array_filter( $GLOBALS['mk_mail'], function ( $m ) { return '' === trim( (string) $m['to'] ); } ); }

/* ===========================================================================
 * 1. THE TICK, AND ONLY THE TICK, GETS PAST THE EMAIL CHECK.
 * ======================================================================== */
mk_reset();
$E = event();

$r = reg( $E, 'Robin', '', false );
same( 'no email and no tick: refused', $r['success'], false );

$r = reg( $E, 'Robin', 'half-typed@exa', true );
same( 'the tick: registered', $r['success'], true );
same( 'the answer says there is no email', $r['no_email'], true );
$rows = array_values( $GLOBALS['mk_db']['wp_uc_rsvps'] );
same( 'what was left in the field is not saved', $rows[0]['email'], '' );

$r = reg( $E, 'Sam', '', true );
same( 'a second person without an email is a second registration, not a duplicate', $r['success'], true );
reg( $E, 'Alex', 'alex@example.org', false );
same( 'counts include everybody', sfaf_get_rsvp_count( $E ), 3 );
same( 'no opt-in recorded without an address', SFAF_Optins::$recorded, array( 'alex@example.org' ) );

/* ===========================================================================
 * 2. NOTHING GOES TO THEM; THE LIST IS STILL TOLD.
 * ======================================================================== */
same( 'no message was addressed to nobody', count( to_blank() ), 0 );
same( 'the one person with an address got their confirmation', count( mk_mail_to( 'alex@example.org' ) ), 1 );
same( 'the list heard about all three registrations', count( mk_mail_to( 'staff@sfaf.org' ) ), 3 );
$alert = mk_mail_to( 'staff@sfaf.org' )[0];
check( false === strpos( $alert['text'], 'Email:' ), 'the alert for somebody with no address prints an empty Email line' );

/* ===========================================================================
 * 3. THE REMINDER: A LEDGER ROW EACH, NEVER A SEND.
 * ======================================================================== */
$GLOBALS['mk_mail'] = array();
$res = SFAF_Reminders::send_for_event( $E );
same( 'two registered without an email', $res['no_address'], 2 );
same( 'sent to the one registrant and the list', array( $res['sent'], $res['failed'], $res['already'] ), array( 3, 0, 0 ) );
same( 'still nothing to nobody', count( to_blank() ), 0 );
$ledger = mk_ledger();
$blank  = array_values( array_filter( $ledger, function ( $x ) { return '' === $x['email']; } ) );
same( 'two rows, one per person, not one row for both', count( $blank ), 2 );
check( 2 === count( $blank ) && $blank[0]['recipient_hash'] !== $blank[1]['recipient_hash'], 'the two emailless registrants share a ledger key' );
same( 'both recorded as not sent for want of an address', array_column( $blank, 'result' ), array( 'no_address', 'no_address' ) );
check( 2 === count( $blank ) && ! in_array( hash( 'sha256', '' ), array_column( $blank, 'recipient_hash' ), true ), 'an emailless key is the hash of an empty address' );
$again = SFAF_Reminders::send_for_event( $E );
same( 'a second pass sends nothing new', array( $again['sent'], $again['no_address'], $again['already'] ), array( 0, 0, 5 ) );

/* ===========================================================================
 * 4. THE SUMMARY NAMES THEM WITH NO ADDRESS; NOBODY CAN BE CANCELLED BY ''.
 * ======================================================================== */
$sum = SFAF_Notifications::build( 'summary', $E );
check( false !== strpos( $sum['text'], '- Robin, no email' ), 'the summary text does not name Robin without an address' );
check( false !== strpos( $sum['html'], '>No email<' ), 'the summary table has no word where the address would be' );
check( false === strpos( $sum['text'], '<>' ), 'the summary prints an empty address' );
same( 'cancelling by an empty address releases nothing', SFAF_Reminders::cancel_rsvp( $E, '' ), false );
same( 'and the count is unchanged', sfaf_get_rsvp_count( $E ), 3 );

/* ===========================================================================
 * 5. NOT FOR JOINING ONLINE.
 * ======================================================================== */
mk_reset();
$ON = event( SFAF_Online::MODE_ONLINE );
$r  = reg( $ON, 'Robin', '', true );
same( 'an online event: refused', $r['success'], false );
check( false !== strpos( (string) $r['message'], 'meeting link' ), 'the refusal does not say why: ' . $r['message'] );

$HY = event( SFAF_Online::MODE_HYBRID );
same( 'hybrid, online chosen: refused', reg( $HY, 'Robin', '', true, SFAF_Online::MODE_ONLINE )['success'], false );
same( 'hybrid, in person chosen: registered', reg( $HY, 'Robin', '', true, SFAF_Online::MODE_IN_PERSON )['success'], true );

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $x ) { echo '  . ' . $x . "\n"; }
    exit( 1 );
}
echo "without an email: only the tick gets past the check and nothing typed is kept; two such people are two\n";
echo "registrations with two ledger keys from their rows; no message is ever addressed to them and the ledger\n";
echo "says why; the list is told and the count includes them; the summary names them with no address; an empty\n";
echo "address cancels nothing; joining online, alone or on a hybrid event, is refused.\n";
exit( 0 );
