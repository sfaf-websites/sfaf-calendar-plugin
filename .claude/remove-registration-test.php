<?php
/**
 * REMOVING A REGISTRATION FROM THE LIST BEHAVES AS A SELF-CANCEL DOES (3.105.0).
 *
 *     php .claude/remove-registration-test.php
 *
 * On mail-kit.php, where the registrations table, the reminder ledger, the
 * hook and the mail are all real enough to count. The brief's rule, asserted
 * against the cancel link's own behaviour rather than restated:
 *
 *   the place is released, and the count says so
 *   the cancel alert goes to the list, naming the person removed
 *   the reminder ledger is not touched
 *   somebody with no email can be removed, alone, and nobody else with them
 *   who removed it and when are on the row
 *
 * The gate is in remove-registration-gate-test.php, on wp-kit.php, because the
 * route and the list are the portal's and this kit does not load the portal.
 */

require __DIR__ . '/mail-kit.php';

$fails = array();
function rr_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rr_same( $label, $got, $want ) { rr_check( $got === $want, sprintf( '%s: got %s, wanted %s', $label, var_export( $got, true ), var_export( $want, true ) ) ); }

$reminders = new SFAF_Reminders();
$reminders->register();   // hooks the cancel alert onto uc_rsvp_cancelled, as the plugin does

mk_reset();
$org = mk_user( 'org@sfaf.org', 'Org', 'contributor' );
$E   = mk_post( array( 'post_title' => 'Support group', 'post_author' => $org ) );
update_post_meta( $E, '_uc_event_date', date( 'Y-m-d' ) );
update_post_meta( $E, '_uc_start_time', '00:30' );
update_post_meta( $E, '_uc_end_time', '23:30' );
update_post_meta( $E, '_uc_rsvp_enabled', '1' );
update_post_meta( $E, SFAF_Reminders::NOTIFY_EMAILS_META, array( 'staff@sfaf.org' ) );

$alex  = mk_rsvp( $E, 'Alex', 'alex@example.org' );
$robin = mk_rsvp( $E, 'Robin', '' );
$sam   = mk_rsvp( $E, 'Sam', '' );
$kim   = mk_rsvp( $E, 'Kim', 'kim@example.org' );
rr_same( 'four places taken', sfaf_get_rsvp_count( $E ), 4 );

/* The reminder has gone out, so there is a ledger to leave alone. */
SFAF_Reminders::send_for_event( $E );
$ledger_before = mk_ledger();
rr_check( count( $ledger_before ) > 0, 'the setup wrote no ledger rows, so "left alone" would prove nothing' );

/* ---- 1. A self-cancel, as the reference. ------------------------------ */
$GLOBALS['mk_mail'] = array();
rr_same( 'the cancel link releases Kim', SFAF_Reminders::cancel_rsvp( $E, 'kim@example.org' ), true );
$self_alert = mk_mail_to( 'staff@sfaf.org' );
rr_same( 'a self-cancel tells the list once', count( $self_alert ), 1 );
rr_same( 'three places left', sfaf_get_rsvp_count( $E ), 3 );
rr_same( 'a self-cancel records nobody as the remover', (string) $GLOBALS['mk_db']['wp_uc_rsvps'][ $kim ]['removed_by'], '0' );

/* ---- 2. Robin, who gave no email, removed by staff user 7. ------------- */
$GLOBALS['mk_mail'] = array();
rr_same( 'Remove releases Robin', SFAF_Reminders::remove_rsvp( $robin, 7 ), true );
$row = $GLOBALS['mk_db']['wp_uc_rsvps'][ $robin ];
rr_same( 'Robin\'s row is cancelled, as a self-cancel leaves it', $row['status'], 'cancelled' );
rr_check( '' !== (string) $row['cancelled_at'], 'the row does not record when it was removed' );
rr_same( 'the row records who removed it', (string) $row['removed_by'], '7' );
rr_same( 'Sam, who also gave no email, is still registered', $GLOBALS['mk_db']['wp_uc_rsvps'][ $sam ]['status'], 'confirmed' );
rr_same( 'the count goes down by one, as a self-cancel moves it', sfaf_get_rsvp_count( $E ), 2 );

$alert = mk_mail_to( 'staff@sfaf.org' );
rr_same( 'the list is told once, as for a self-cancel', count( $alert ), 1 );
if ( $alert && $self_alert ) {
    rr_check( false !== strpos( $alert[0]['text'], 'Robin' ), 'the alert does not name Robin, the person removed' );
    rr_check( false === strpos( $alert[0]['text'], 'Sam' ), 'the alert names Sam, who shares Robin\'s empty address and was not removed' );
    // Same message, different person: the subject shape is the self-cancel's.
    rr_same( 'the alert is the self-cancel message', preg_replace( '/Kim|Robin/', 'X', $alert[0]['subject'] ), preg_replace( '/Kim|Robin/', 'X', $self_alert[0]['subject'] ) );
}
rr_same( 'nothing is mailed to the person removed', count( mk_mail_to( '' ) ), 0 );

/* ---- 3. The reminder ledger is left alone. ---------------------------- */
rr_same( 'the ledger is exactly what it was', mk_ledger(), $ledger_before );

/* ---- 4. Removing twice does nothing the second time. ------------------- */
$GLOBALS['mk_mail'] = array();
rr_same( 'a row already cancelled is not removed again', SFAF_Reminders::remove_rsvp( $robin, 7 ), false );
rr_same( 'and nobody is told twice', count( $GLOBALS['mk_mail'] ), 0 );
rr_same( 'a row that does not exist is not removed', SFAF_Reminders::remove_rsvp( 9999, 7 ), false );

/* ---- 5. An address row, by staff. ------------------------------------- */
rr_same( 'Remove releases Alex too', SFAF_Reminders::remove_rsvp( $alex, 7 ), true );
rr_same( 'one place left, Sam\'s', sfaf_get_rsvp_count( $E ), 1 );

if ( $fails ) {
    echo 'REMOVE REGISTRATION: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "remove registration: the place is released and the list told as for a self-cancel, the ledger is untouched, an emailless row goes alone, and who and when are on the row.\n";
