<?php
/**
 * EMAIL REQUIRED TO REGISTER (3.106.0).
 *
 *     php .claude/email-required-test.php
 *
 * The real SFAF_RSVP::submit() on mail-kit.php, and the real editor field
 * through its source. The rule, sfaf_email_required():
 *
 *   ticked                          no address is refused
 *   not ticked                      no address is taken, as before
 *   an online event                 no address is refused, ticked or not
 *   a hybrid event, joining online  refused, ticked or not
 *   a hybrid event, in person       follows the tick
 *
 * Also asserted: the tick is drawn off by default and on and locked for an
 * online event, its helper text is the brief's sentence, the public button
 * tells the form, and the key travels with a repeating event and through the
 * group copy.
 */

require __DIR__ . '/mail-kit.php';

$fails = array();
function er( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

$rsvp = new SFAF_RSVP();
function er_event( $meta = array() ) {
    $id = mk_post( array( 'post_title' => 'Support group' ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d', strtotime( '+10 days' ) ) );
    update_post_meta( $id, '_uc_start_time', '18:00' );
    update_post_meta( $id, '_uc_rsvp_enabled', '1' );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
function er_reg( $event_id, $format = '' ) {
    global $rsvp;
    $r = $rsvp->submit( array( 'event_id' => $event_id, 'first_name' => 'Robin', 'last_name' => '', 'email' => '',
        'phone' => '', 'optin' => false, 'format' => $format, 'no_email' => true ) );
    return $r['success'];
}

mk_reset();
er( 'PLANT: ticked, no address is refused', er_reg( er_event( array( '_uc_email_required' => '1' ) ) ), false );
er( 'not ticked, no address is taken', er_reg( er_event() ), true );
er( 'an online event refuses no address, even unticked', er_reg( er_event( array( SFAF_Online::META => '1' ) ) ), false );
$hy_off = er_event( array( SFAF_Online::META_HYBRID => '1' ) );
er( 'hybrid in person, unticked: taken', er_reg( $hy_off, SFAF_Online::MODE_IN_PERSON ), true );
er( 'hybrid online, unticked: refused', er_reg( $hy_off, SFAF_Online::MODE_ONLINE ), false );
$hy_on = er_event( array( SFAF_Online::META_HYBRID => '1', '_uc_email_required' => '1' ) );
er( 'hybrid in person, ticked: refused', er_reg( $hy_on, SFAF_Online::MODE_IN_PERSON ), false );

$refused = $rsvp->submit( array( 'event_id' => er_event( array( '_uc_email_required' => '1' ) ), 'first_name' => 'Robin', 'email' => '', 'no_email' => true, 'format' => '' ) );
er( 'the refusal says what to do', $refused['message'], 'This event needs an email address to register.' );

/* The editor, the form and the copy lists, read from source. */
$root = dirname( __DIR__ );
$port = (string) file_get_contents( $root . '/includes/class-sfaf-portal.php' );
er( 'the field is in the shared list beside Accept RSVPs', false !== strpos( $port, "array( 'rsvp_enabled', 'email_required', 'capacity', 'capacity_online' )" ), true );
er( 'the helper text is the brief\'s', false !== strpos( $port, '<span class="uc-hint">Turn this on to remove the option to register without an email address.</span>' ), true );
er( 'it is checked and disabled when locked', (bool) preg_match( '/checked\( \$req_locked \|\| .1. === \(string\) \$g\( ._uc_email_required. \) \)/', $port ) && false !== strpos( $port, 'disabled( $req_locked )' ), true );
er( 'the save writes it on for an online event', false !== strpos( $port, "isset( \$_POST['email_required'] ) || sfaf_email_required_locked( \$event_id )" ), true );
er( 'it travels through the group copy', 2 === substr_count( $port, "'_uc_volunteer_url', '_uc_email_required'," ), true );
er( 'it travels with a repeating event', false !== strpos( (string) file_get_contents( $root . '/includes/class-sfaf-recurrence.php' ), "'_uc_email_required'," ), true );
er( 'the public button tells the form', false !== strpos( (string) file_get_contents( $root . '/includes/sfaf-template-functions.php' ), "'data-uc-email-required' =>" ), true );
er( 'the form hides the box when told', (bool) preg_match( "/else if \(currentEmailRequired\) \{[^}]*noemail-wrap'\)\.attr\('hidden'/s", (string) file_get_contents( $root . '/public/js/calendar.js' ) ), true );

if ( $fails ) {
    echo 'EMAIL REQUIRED: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "email required: ticked refuses no address, online always does, hybrid follows the format; the tick is drawn, locked, saved and carried.\n";
