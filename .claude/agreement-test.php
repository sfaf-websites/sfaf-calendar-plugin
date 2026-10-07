<?php
/**
 * THE REGISTRATION AGREEMENT AND THE TEXT OPT-IN, RUN (3.110.0).
 *
 *     php .claude/agreement-test.php
 *
 * The real SFAF_RSVP::submit(), SFAF_Waitlist and SFAF_Agreement on
 * mail-kit.php. What is read is the answer and the stored row.
 *
 *   B.4  an event that asks refuses a registration that did not agree, and
 *        stores nothing; agreeing stores agreed_at; a waitlist join is asked
 *        the same and stores it too
 *        PLANT B.4: the refusal taken out
 *   B.4  RSVPs off, or a third-party event, never asks, whatever the meta says
 *   B.1  the series text is inherited until the event has its own; the save
 *        keeps the box as the event's own only when it differs; off by default
 *   C    text_opt_in is stored only with a phone to text
 */

require __DIR__ . '/mail-kit.php';
require_once dirname( __DIR__ ) . '/includes/class-sfaf-rich-text.php';
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $h ) { return (string) $h; } }
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $h ) { return (string) $h; } }

$fails = array();
function ag( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

$rsvp = new SFAF_RSVP();
function ag_event( $meta = array() ) {
    $id = mk_post( array( 'post_title' => 'Harm reduction walk' ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d', strtotime( '+10 days' ) ) );
    update_post_meta( $id, '_uc_start_time', '18:00' );
    update_post_meta( $id, '_uc_end_time', '19:00' );
    update_post_meta( $id, '_uc_rsvp_enabled', '1' );
    update_post_meta( $id, '_uc_capacity', '2' );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
function ag_reg( $event_id, $first, $agreed, $phone = '', $text = false ) {
    global $rsvp;
    return $rsvp->submit( array( 'event_id' => $event_id, 'first_name' => $first, 'last_name' => '', 'email' => strtolower( $first ) . '@example.org',
        'phone' => $phone, 'optin' => false, 'format' => '', 'no_email' => false, 'agreed' => $agreed, 'text_opt_in' => $text ) );
}
function ag_row( $first ) {
    foreach ( $GLOBALS['mk_db']['wp_uc_rsvps'] as $r ) { if ( $r['first_name'] === $first ) { return $r; } }
    return null;
}

mk_reset();

/* ---- B.4: refused without agreement ------------------------------------- */
$E = ag_event( array( SFAF_Agreement::META_ON => '1', SFAF_Agreement::META_TEXT => '<p>Photos are taken.</p>' ) );
ag( 'the event asks', SFAF_Agreement::is_on( $E ), true );
$r = ag_reg( $E, 'Ana', false );
ag( 'PLANT B.4: a registration that did not agree is refused', array( $r['success'], $r['message'] ?? '' ), array( false, 'Agree to the event conditions to register.' ) );
ag( 'PLANT B.4: and nothing is stored', ag_row( 'Ana' ), null );
$r = ag_reg( $E, 'Ana', true );
ag( 'agreeing registers', $r['success'], true );
ag( 'the row says when they agreed', (bool) preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) ag_row( 'Ana' )['agreed_at'] ), true );
ag_reg( $E, 'Ben', true );
$r = ag_reg( $E, 'Cam', false );
ag( 'PLANT B.4: a waitlist join that did not agree is refused', $r['success'], false );
$r = ag_reg( $E, 'Cam', true );
ag( 'a waitlist join that agreed joins', array( $r['success'], ! empty( $r['waitlisted'] ) ), array( true, true ) );
ag( 'and its row says when', '' !== (string) ag_row( 'Cam' )['agreed_at'] && null !== ag_row( 'Cam' )['agreed_at'], true );

/* ---- B.4: never on RSVPs off or a third-party event ---------------------- */
$off = ag_event( array( SFAF_Agreement::META_ON => '1', '_uc_rsvp_enabled' => '0' ) );
ag( 'RSVPs off never asks', SFAF_Agreement::is_on( $off ), false );
ag( 'and the form carries nothing', SFAF_Agreement::form_data( $off ), null );
$src = ag_event( array( SFAF_Agreement::META_ON => '1', SFAF_Sources::META_SOURCE => 'eventbrite' ) );
ag( 'a third-party event never asks, with no registration link', SFAF_Agreement::is_on( $src ), false );
update_post_meta( $src, SFAF_Sources::META_SOURCE_URL, 'https://example.org/register' );
ag( 'nor with one', SFAF_Agreement::is_on( $src ), false );
$plain = ag_event();
ag( 'off by default', SFAF_Agreement::is_on( $plain ), false );
$r = ag_reg( $plain, 'Dee', false );
ag( 'an event that does not ask registers without agreement', $r['success'], true );
ag( 'and stores no agreement time', ag_row( 'Dee' )['agreed_at'], null );

/* ---- B.1: the series default --------------------------------------------- */
$S = mk_term( 'uc_series', 'Walks' );
update_term_meta( $S, SFAF_Agreement::SERIES_META, '<p>Series conditions.</p>' );
$child = ag_event( array( SFAF_Agreement::META_ON => '1' ) );
wp_set_object_terms( $child, array( $S ), 'uc_series' );
ag( 'an event with no text of its own shows its series text', SFAF_Agreement::text( $child ), '<p>Series conditions.</p>' );
SFAF_Agreement::save_from_post( $child, array( 'agreement_present' => '1', 'agreement_on' => '1', 'agreement_text' => '<p>Series conditions.</p>' ) );
ag( 'saving the series text unchanged keeps nothing of its own', get_post_meta( $child, SFAF_Agreement::META_TEXT, true ), '' );
update_term_meta( $S, SFAF_Agreement::SERIES_META, '<p>New series conditions.</p>' );
ag( 'so a series change reaches it', SFAF_Agreement::text( $child ), '<p>New series conditions.</p>' );
SFAF_Agreement::save_from_post( $child, array( 'agreement_present' => '1', 'agreement_on' => '1', 'agreement_text' => '<p>Our own.</p><img src="x.png">' ) );
ag( 'an edited box is the event\'s own, with no picture', SFAF_Agreement::text( $child ), '<p>Our own.</p>' );
SFAF_Agreement::save_from_post( $child, array( 'agreement_present' => '1' ) );
ag( 'an unticked box turns it off', SFAF_Agreement::is_on( $child ), false );
SFAF_Agreement::save_from_post( $child, array( 'title' => 'another screen' ) );
ag( 'a screen without the section leaves it alone', get_post_meta( $child, SFAF_Agreement::META_ON, true ), '0' );

/* ---- C: the text opt-in --------------------------------------------------- */
$T = ag_event( array( '_uc_capacity' => '20' ) );
ag_reg( $T, 'Eve', false, '(415) 555-0100', true );
ag_reg( $T, 'Fay', false, '', true );
ag_reg( $T, 'Gil', false, '(415) 555-0101', false );
ag( 'ticked with a phone is stored', (int) ag_row( 'Eve' )['text_opt_in'], 1 );
ag( 'ticked with no phone is not', (int) ag_row( 'Fay' )['text_opt_in'], 0 );
ag( 'unticked is not', (int) ag_row( 'Gil' )['text_opt_in'], 0 );

if ( $fails ) {
    echo 'AGREEMENT: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "agreement: refused without it on the server, waitlist joins included; agreed_at stored; never on RSVPs off or third-party; the series text inherited until edited; texts only with a phone.\n";
