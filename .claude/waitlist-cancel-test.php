<?php
/**
 * THE WAITLIST IS TOLD WHEN AN EVENT IS CANCELLED (3.106.1).
 *
 *     php .claude/waitlist-cancel-test.php
 *
 * The real SFAF_Announce, SFAF_Notifications, SFAF_Messages, SFAF_Series and
 * SFAF_Waitlist on mail-kit.php. What is read is the mail that went out.
 *
 *   waitlisted and offered rows with an address get the waitlist's own message;
 *   offered_manual (no address), expired and cancelled rows get nothing; the
 *   message lists the series' next three published dates, in order, with a
 *   link to each, leaving out the cancelled, the private, the draft, the past
 *   and today's already begun; no series, or nothing ahead, is the message
 *   without dates; no cancel link and no donate line; Spanish follows the
 *   event; one email per address, whether somebody waits for two cancelled
 *   dates or holds a place at one and waits for another; a change or a
 *   reinstatement reaches nobody on the waitlist.
 *
 * A.3, THE AUDIENCES. registrants(), has_registrations(), the reminder
 * recipients and the count still agree on the confirmed only, with every
 * waitlist status present, and the source of the first two names no waiting
 * status. Planted in plant-one.php: the waitlist widened into registrants(),
 * the waitlist sent twice to somebody holding a place, and the waitlist read
 * dropped from the run.
 */

require __DIR__ . '/mail-kit.php';

$fails = array();
function wc( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function wc_event( $title, $days, $series = 0, $meta = array(), $status = 'publish' ) {
    $id = mk_post( array( 'post_title' => $title, 'post_status' => $status ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d', strtotime( "$days days" ) ) );
    update_post_meta( $id, '_uc_start_time', '18:00' );
    update_post_meta( $id, '_uc_end_time', '19:30' );
    update_post_meta( $id, '_uc_rsvp_enabled', '1' );
    update_post_meta( $id, '_uc_capacity', '2' );
    if ( $series ) { wp_set_object_terms( $id, array( $series ), SFAF_Series::TAXONOMY ); }
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
function wc_mail( $to ) { return mk_mail_to( $to ); }
function wc_all( $m ) { return isset( $m['subject'] ) ? $m['subject'] . "\n" . $m['text'] . "\n" . $m['html'] : ''; }
function wc_reads( $event_id ) {
    return array(
        'count'       => sfaf_get_rsvp_count( $event_id ),
        'registrants' => count( SFAF_Announce::registrants( $event_id ) ),
        'recipients'  => count( array_filter( SFAF_Reminders::recipients( $event_id ), function ( $r ) { return 'rsvp' === $r['type']; } ) ),
        'has'         => SFAF_Announce::has_registrations( $event_id ),
    );
}

/* ---- The world: a series with dates ahead, two of them cancelled. -------- */
mk_reset();
$S = mk_term( SFAF_Series::TAXONOMY, 'Support group' );
$A = wc_event( 'Support group', '+10', $S );
$B = wc_event( 'Support group', '+12', $S );
$past    = wc_event( 'Support group', '-7', $S );
$begun   = wc_event( 'Support group', '+0', $S, array( '_uc_start_time' => date( 'H:i', strtotime( '-1 hour' ) ), '_uc_end_time' => '23:59' ) );
$C       = wc_event( 'Support group', '+17', $S );
$draft   = wc_event( 'Support group', '+20', $S, array(), 'draft' );
$off     = wc_event( 'Support group', '+24', $S, array( SFAF_Cancellation::META => '1', SFAF_Cancellation::VISIBILITY_META => 'stay' ) );
$private = wc_event( 'Support group', '+31', $S, array( SFAF_Privacy::META => '1' ) );
$F       = wc_event( 'Support group', '+38', $S );
$G       = wc_event( 'Support group', '+45', $S );
$H       = wc_event( 'Support group', '+52', $S );

mk_rsvp( $A, 'Ana', 'ana@example.org' );
mk_rsvp( $A, 'Ben', 'ben@example.org' );
mk_rsvp( $A, 'Cam', 'cam@example.org', 'waitlisted' );
mk_rsvp( $A, 'Dee', 'dee@example.org', 'offered' );
mk_rsvp( $A, 'Eli', '', 'offered_manual' );
mk_rsvp( $A, 'Fay', 'fay@example.org', 'expired' );
mk_rsvp( $A, 'Gus', 'gus@example.org', 'cancelled' );
mk_rsvp( $A, 'Ivy', 'ivy@example.org', 'waitlisted' );
mk_rsvp( $B, 'Ivy', 'IVY@example.org', 'waitlisted' );   // the same person, waiting for both
mk_rsvp( $B, 'Ana', 'ana@example.org', 'waitlisted' );   // a place at A, waiting for B
mk_rsvp( $B, 'Jo', 'jo@example.org' );

$before = wc_reads( $A );
wc( 'A.3: before cancelling, the reads agree on the two confirmed', $before, array( 'count' => 2, 'registrants' => 2, 'recipients' => 2, 'has' => true ) );

/* A change and a reinstatement reach nobody waiting. */
$GLOBALS['mk_mail'] = array();
SFAF_Announce::changed( array( $A ), array( $A => array( 'Time' => array( 'from' => '5–6 pm', 'to' => '6–7:30 pm' ) ) ) );
SFAF_Announce::reinstated( array( $A ) );
foreach ( array( 'cam', 'dee', 'ivy' ) as $who ) {
    wc( "A.3: a change or a reinstatement sends $who nothing", count( wc_mail( "$who@example.org" ) ), 0 );
}
wc( 'A.3: a change and a reinstatement still reach Ben', count( wc_mail( 'ben@example.org' ) ), 2 );

/* Cancel both, as the series cancel does: the meta first, then the run. */
$GLOBALS['mk_mail'] = array();
SFAF_Cancellation::set( $A, true, 'stay' );
SFAF_Cancellation::set( $B, true, 'stay' );
update_post_meta( $A, '_uc_cancelled_reason', 'The room flooded.' );
update_post_meta( $A, SFAF_Cancellation::MESSAGE_META, 'Bring your folder to the next one.' );
$told = SFAF_Announce::cancelled( array( $A, $B ) );

wc( 'registrants told: Ana and Ben and Jo', $told['sent'], 3 );
wc( 'waitlist told: Cam, Dee and Ivy', $told['waitlist'], 3 );

$cam = wc_mail( 'cam@example.org' );
wc( 'PLANT A.3: Cam, waitlisted, is sent one message', count( $cam ), 1 );
$t = isset( $cam[0] ) ? $cam[0]['text'] : '';
wc( 'it is the waitlist\'s message', false !== strpos( $t, 'you were on the waitlist' ), true );
wc( 'its subject names the event', isset( $cam[0] ) ? $cam[0]['subject'] : '', 'Cancelled: Support group' );
wc( 'it carries the public reason', false !== strpos( $t, 'The room flooded.' ), true );
wc( 'and not the note written for registrants', false === strpos( wc_all( $cam[0] ?? array() ), 'Bring your folder' ), true );
wc( 'it lists the next dates under their label', false !== strpos( $t, "Next dates\n" ), true );
$links = array();
preg_match_all( '#https://resources\.example\.org/events/(\d+)/#', $t, $m );
wc( 'the next three published dates, in order: C, F, G', array_map( 'intval', $m[1] ), array( $C, $F, $G ) );
wc( 'each with its date and time', false !== strpos( $t, sfaf_ap_date( get_post_meta( $C, '_uc_event_date', true ), 'full' ) . ', ' . sfaf_ap_time_range( '18:00', '19:30', 'zone' ) . ': https://resources.example.org/events/' . $C . '/' ), true );
wc( 'and each a link in the HTML', isset( $cam[0] ) && false !== strpos( $cam[0]['html'], 'href="https://resources.example.org/events/' . $F . '/"' ), true );
wc( 'no cancel link', false === strpos( wc_all( $cam[0] ?? array() ), 'uc_rsvp_cancel=' ), true );
wc( 'no donate line', false === strpos( wc_all( $cam[0] ?? array() ), SFAF_DONATE_DEFAULT ) && false === strpos( $t, 'Support this work' ), true );

wc( 'Dee, offered, is sent the waitlist\'s message', count( wc_mail( 'dee@example.org' ) ) === 1 && false !== strpos( wc_mail( 'dee@example.org' )[0]['text'], 'you were on the waitlist' ), true );
wc( 'Fay, whose offer expired, is sent nothing', count( wc_mail( 'fay@example.org' ) ), 0 );
wc( 'Gus, who left, is sent nothing', count( wc_mail( 'gus@example.org' ) ), 0 );
wc( 'Eli, with no address, is sent nothing and nothing goes to an empty address', count( array_filter( $GLOBALS['mk_mail'], function ( $x ) { return '' === trim( (string) $x['to'] ); } ) ), 0 );

$ivy = wc_mail( 'ivy@example.org' );
wc( 'PLANT A.3: Ivy, waiting for both dates, is sent one message', count( $ivy ), 1 );
$it = isset( $ivy[0] ) ? $ivy[0]['text'] : '';
wc( 'naming both dates', false !== strpos( $it, sfaf_ap_date( get_post_meta( $A, '_uc_event_date', true ), 'full' ) ) && false !== strpos( $it, sfaf_ap_date( get_post_meta( $B, '_uc_event_date', true ), 'full' ) ), true );
wc( 'and neither cancelled date offered as a next date', false === strpos( $it, '/events/' . $A . '/' ) && false === strpos( $it, '/events/' . $B . '/' ), true );

$ana = wc_mail( 'ana@example.org' );
wc( 'PLANT A.3: Ana, a place at one and waiting for the other, is sent one message', count( $ana ), 1 );
wc( 'the registrants\' message, naming both dates', isset( $ana[0] ) && false !== strpos( $ana[0]['text'], '2 dates are cancelled' ), true );
wc( 'Ben is sent the registrants\' message', count( wc_mail( 'ben@example.org' ) ) === 1 && false !== strpos( wc_mail( 'ben@example.org' )[0]['text'], 'Support group is cancelled' ) && false === strpos( wc_mail( 'ben@example.org' )[0]['text'], 'waitlist' ), true );

wc( 'PLANT A.3: after cancelling, the reads still agree on the confirmed only', wc_reads( $A ), $before );
wc( 'and the delete guard still counts registrations only', SFAF_Announce::count_affected( array( $A, $B ) ), array( 'registrations' => 3, 'people' => 3, 'events' => 2 ) );

/* ---- Nothing ahead in the series, and no series: without dates. --------- */
mk_reset();
$S2   = mk_term( SFAF_Series::TAXONOMY, 'Workshop' );
$lone = wc_event( 'Workshop', '+10', $S2 );
wc_event( 'Workshop', '-3', $S2 );
wc_event( 'Workshop', '+15', $S2, array( SFAF_Cancellation::META => '1' ) );
$none = wc_event( 'Open house', '+10' );
mk_rsvp( $lone, 'Kit', 'kit@example.org', 'waitlisted' );
mk_rsvp( $none, 'Lee', 'lee@example.org', 'waitlisted' );
SFAF_Cancellation::set( $lone, true );
SFAF_Cancellation::set( $none, true );
SFAF_Announce::cancelled( array( $lone ) );
SFAF_Announce::cancelled( array( $none ) );
foreach ( array( 'kit' => 'nothing ahead in the series', 'lee' => 'no series' ) as $who => $why ) {
    $mm = wc_mail( "$who@example.org" );
    wc( "$why: one message", count( $mm ), 1 );
    $tx = isset( $mm[0] ) ? $mm[0]['text'] : '';
    wc( "$why: the message without dates", false !== strpos( $tx, 'you were on the waitlist' ) && false === strpos( $tx, 'Next dates' ) && false === strpos( $tx, 'next dates' ), true );
}

/* ---- Spanish follows the event. ----------------------------------------- */
mk_reset();
$S3 = mk_term( SFAF_Series::TAXONOMY, 'Grupo de apoyo' );
$es = wc_event( 'Grupo de apoyo', '+10', $S3, array( '_uc_language' => 'es' ) );
wc_event( 'Grupo de apoyo', '+17', $S3 );
mk_rsvp( $es, 'Mia', 'mia@example.org', 'waitlisted' );
SFAF_Cancellation::set( $es, true );
SFAF_Announce::cancelled( array( $es ) );
$mia = wc_mail( 'mia@example.org' );
wc( 'Spanish: the event\'s language', isset( $mia[0] ) && false !== strpos( $mia[0]['text'], 'usted estaba en la lista de espera' ) && false !== strpos( $mia[0]['text'], "Próximas fechas\n" ) && 'Cancelado: Grupo de apoyo' === $mia[0]['subject'], true );

/* ---- A.3 held up by the source: the two reads name no waiting status. ---- */
$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-announce.php' );
foreach ( array( 'registrants', 'has_registrations' ) as $fn ) {
    $body = '';
    $toks = token_get_all( $src );
    for ( $i = 0, $n = count( $toks ); $i < $n; $i++ ) {
        if ( is_array( $toks[ $i ] ) && T_FUNCTION === $toks[ $i ][0] ) {
            $j = $i + 1;
            while ( $j < $n && ( ! is_array( $toks[ $j ] ) || T_STRING !== $toks[ $j ][0] ) ) { $j++; }
            if ( $j < $n && $fn === $toks[ $j ][1] ) {
                $depth = 0; $open = false;
                for ( $k = $j; $k < $n; $k++ ) {
                    $tx = is_array( $toks[ $k ] ) ? $toks[ $k ][1] : $toks[ $k ];
                    if ( '{' === $tx || ( is_array( $toks[ $k ] ) && in_array( $toks[ $k ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) { $depth++; $open = true; }
                    if ( '}' === $tx ) { $depth--; }
                    $body .= $tx;
                    if ( $open && 0 === $depth ) { break; }
                }
                break;
            }
        }
    }
    wc( "A.3: $fn() was found in the source", '' !== $body, true );
    wc( "PLANT A.3: $fn() asks for 'confirmed' and nothing waiting", (bool) preg_match( "/status\s*=\s*'confirmed'/", $body ) && ! preg_match( '/waitlist|waiting_statuses|offered/', $body ), true );
}

if ( $fails ) {
    echo 'WAITLIST CANCEL: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "waitlist cancel: waiting and offered rows are told, once per address, with the series' next three dates or without; no cancel link, no donate line; every other audience and count stays confirmed.\n";
