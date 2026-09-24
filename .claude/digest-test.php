<?php
/**
 * THE DIGEST AND THE DAY-BEFORE COUNT (3.102.0).
 *
 *     php .claude/digest-test.php
 *
 * Runs the real SFAF_Digest and SFAF_Notifications over mail-kit.php, with the
 * event gate lifted out of SFAF_Portal. What is asserted is who receives what:
 * which events each person's digest lists, which carry a link to the
 * registrations, that a filter narrows, that nothing names a registrant, that
 * an empty digest or count is never sent, and that the ledger refuses a second
 * send of either.
 */

require __DIR__ . '/mail-kit.php';

/* A source with its label, so an imported event says where it registers. */
class Test_Eventbrite extends SFAF_Source_Adapter {
    public function slug() { return 'eventbrite'; }
    public function label() { return 'Eventbrite'; }
    public function is_active() { return true; }
    public function fetch() { return array( 'items' => array() ); }
    public function normalize( $i ) { return null; }
}
SFAF_Sources::register_adapter( new Test_Eventbrite() );

$fails = array();
function check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function same( $label, $got, $want ) { check( $got === $want, sprintf( '%s: got %s, wanted %s', $label, var_export( $got, true ), var_export( $want, true ) ) ); }

$today    = date( 'Y-m-d' );
$tomorrow = date( 'Y-m-d', strtotime( '+1 day' ) );
$rsvp_url = function ( $id ) { return 'https://resources.example.org/caladmin/rsvps?event_id=' . (int) $id; };

/* ---- The world. Users are made again after every reset, with the same ids. ---- */
function people() {
    global $ADMIN, $EDITOR, $CON_A, $CON_B, $CON_C, $GONE, $V1, $V2, $S1, $S2, $O1, $O2;
    $V1 = mk_term( 'uc_venue', 'Strut' );       $V2 = mk_term( 'uc_venue', 'Maxfield\'s' );
    $S1 = mk_term( 'uc_series', 'Coffee' );     $S2 = mk_term( 'uc_series', 'Meal' );
    $O1 = mk_term( 'uc_organizer', 'Aging' );   $O2 = mk_term( 'uc_organizer', 'Stonewall' );
    $ADMIN  = mk_user( 'ana@sfaf.org', 'Ana', 'admin' );
    $EDITOR = mk_user( 'eli@sfaf.org', 'Eli', 'editor' );
    $CON_A  = mk_user( 'cam@sfaf.org', 'Cam', 'contributor' );    // organizes E1
    $CON_B  = mk_user( 'dee@sfaf.org', 'Dee', 'contributor' );    // in the team that owns E2
    $CON_C  = mk_user( 'fay@sfaf.org', 'Fay', 'contributor' );    // owns nothing
    $GONE   = mk_user( 'gus@sfaf.org', 'Gus' );                   // no calendar role, organizes E3
}
people();


function world() {
    global $today, $tomorrow, $ADMIN, $CON_A, $CON_B, $GONE, $V1, $V2, $S1, $S2, $O1, $O2, $E;
    mk_reset();
    people();
    $GLOBALS['mk_opts'][ SFAF_Teams::OPTION ] = array( 't1' => array( 'name' => 'Programs', 'users' => array( $CON_B ) ) );
    $ev = function ( $title, $author, $date, $venue, $series, $org, $extra = array() ) {
        $id = mk_post( array_merge( array( 'post_title' => $title, 'post_author' => $author ), isset( $extra['post'] ) ? $extra['post'] : array() ) );
        update_post_meta( $id, '_uc_event_date', $date );
        update_post_meta( $id, '_uc_start_time', '18:00' );
        update_post_meta( $id, '_uc_end_time', '19:30' );
        update_post_meta( $id, '_uc_rsvp_enabled', '1' );
        if ( $venue )  { wp_set_object_terms( $id, $venue, 'uc_venue' ); }
        if ( $series ) { wp_set_object_terms( $id, $series, 'uc_series' ); }
        if ( $org )    { wp_set_object_terms( $id, $org, 'uc_organizer' ); }
        foreach ( isset( $extra['meta'] ) ? $extra['meta'] : array() as $k => $v ) { update_post_meta( $id, $k, $v ); }
        return $id;
    };
    $E = array();
    $E[1] = $ev( 'Coffee social', $CON_A, $today, $V1, $S1, $O1 );
    $E[2] = $ev( 'Community meal', $ADMIN, $today, $V2, $S2, $O2, array( 'meta' => array( SFAF_Teams::ACCESS_META => array( 't1' ) ) ) );
    $E[3] = $ev( 'Walking group', $GONE, $today, $V1, 0, 0 );
    $E[4] = $ev( 'Cancelled thing', $ADMIN, $today, $V1, 0, 0, array( 'meta' => array( '_uc_cancelled' => '1', '_uc_cancelled_visibility' => 'stay' ) ) );
    $E[5] = $ev( 'Imported gala', $ADMIN, $today, 0, 0, 0, array( 'meta' => array( SFAF_Sources::META_SOURCE => 'eventbrite' ) ) );
    $E[6] = $ev( 'Tomorrow\'s talk', $ADMIN, $tomorrow, $V2, 0, 0 );
    $E[7] = $ev( 'A draft', $ADMIN, $today, $V1, 0, 0, array( 'post' => array( 'post_status' => 'draft' ) ) );
    mk_rsvp( $E[1], 'Alexandra', 'alexandra@example.org' );
    mk_rsvp( $E[1], 'Jordan', 'jordan@example.org' );
    mk_rsvp( $E[2], 'Morgan', 'morgan@example.org' );
}
function titles( $ids ) { return array_map( 'get_the_title', $ids ); }
$none = array( 'frequency' => 'daily', 'venues' => array(), 'series' => array(), 'organizers' => array() );

/* ===========================================================================
 * 1. WHO SEES WHAT: THE EVENT GATE, AND NOTHING ELSE.
 * ======================================================================== */
world();
same( 'admin: every published event today, not the cancelled, the draft or tomorrow\'s',
    titles( SFAF_Digest::events_for( $ADMIN, $today, $today, $none ) ), array( 'Coffee social', 'Community meal', 'Walking group', 'Imported gala' ) );
same( 'editor: the same', titles( SFAF_Digest::events_for( $EDITOR, $today, $today, $none ) ), array( 'Coffee social', 'Community meal', 'Walking group', 'Imported gala' ) );
same( 'contributor: their own', titles( SFAF_Digest::events_for( $CON_A, $today, $today, $none ) ), array( 'Coffee social' ) );
same( 'contributor: their team\'s', titles( SFAF_Digest::events_for( $CON_B, $today, $today, $none ) ), array( 'Community meal' ) );
same( 'contributor: nothing of anybody else\'s', SFAF_Digest::events_for( $CON_C, $today, $today, $none ), array() );
same( 'weekly reaches tomorrow', in_array( $E[6], SFAF_Digest::events_for( $ADMIN, $today, date( 'Y-m-d', strtotime( '+6 days' ) ), $none ), true ), true );

/* ===========================================================================
 * 2. FILTERS: NOTHING TICKED IS NO FILTER, GROUPS NARROW TOGETHER.
 * ======================================================================== */
$f = function ( $over ) use ( $none ) { return array_merge( $none, $over ); };
same( 'venue Strut', titles( SFAF_Digest::events_for( $ADMIN, $today, $today, $f( array( 'venues' => array( $V1 ) ) ) ) ), array( 'Coffee social', 'Walking group' ) );
same( 'venue Strut and series Coffee', titles( SFAF_Digest::events_for( $ADMIN, $today, $today, $f( array( 'venues' => array( $V1 ), 'series' => array( $S1 ) ) ) ) ), array( 'Coffee social' ) );
same( 'organizer Stonewall', titles( SFAF_Digest::events_for( $ADMIN, $today, $today, $f( array( 'organizers' => array( $O2 ) ) ) ) ), array( 'Community meal' ) );
same( 'either venue', titles( SFAF_Digest::events_for( $ADMIN, $today, $today, $f( array( 'venues' => array( $V1, $V2 ) ) ) ) ), array( 'Coffee social', 'Community meal', 'Walking group' ) );
same( 'a filter never widens what the gate allows', titles( SFAF_Digest::events_for( $CON_A, $today, $today, $f( array( 'venues' => array( $V2 ) ) ) ) ), array() );

/* ===========================================================================
 * 3. THE MESSAGE: THE RSVP LINK ASKS THE GATE, NO NAMES, ZONED TIMES.
 * ======================================================================== */
$b = SFAF_Digest::build( $ADMIN, SFAF_Digest::events_for( $ADMIN, $today, $today, $none ), 'daily' );
check( is_array( $b ), 'the admin\'s digest built nothing' );
$both = $b['html'] . "\n" . $b['text'];
foreach ( array( 1, 2, 3 ) as $i ) {
    check( false !== strpos( $b['text'], $rsvp_url( $E[ $i ] ) ), "the admin's digest has no registrations link for " . get_the_title( $E[ $i ] ) );
}
check( false === strpos( $both, $rsvp_url( $E[5] ) ), 'an imported event got a registrations link here, where it takes none' );
check( false !== strpos( $b['text'], 'Registration is on Eventbrite' ), 'the imported event does not say where it registers' );
check( false !== strpos( $b['text'], '2 registered' ), 'the count is not in the digest' );
foreach ( array( 'Alexandra', 'Jordan', 'Morgan', 'alexandra@example.org' ) as $who ) {
    check( false === strpos( $both, $who ), "the digest names a registrant: $who" );
}
check( (bool) preg_match( '/6.7:30 pm PT/u', $b['text'] ), 'the time in the digest has no zone' );
same( 'one yellow button', substr_count( str_replace( ' ', '', $b['html'] ), 'background-color:#FFD900' ), 1 );
check( strlen( $b['text'] ) > 80 && false !== strpos( $b['text'], '940 Howard Street' ), 'the digest has no proper plain text part' );

/* The link is asked of the gate again, whatever list build() is handed. */
$refused = SFAF_Digest::build( $CON_C, array( $E[2] ), 'daily' );
check( is_array( $refused ) && false === strpos( $refused['html'] . $refused['text'], '/caladmin/rsvps' ), 'a registrations link went to somebody the gate refuses' );
$allowed = SFAF_Digest::build( $CON_B, array( $E[2] ), 'daily' );
check( is_array( $allowed ) && false !== strpos( $allowed['text'], $rsvp_url( $E[2] ) ), 'a team member lost the link their team gives them' );

/* ===========================================================================
 * 4. SENDING: ONCE, AND NEVER EMPTY.
 * ======================================================================== */
world();
$period = array( 'key' => 'daily:' . $today, 'start' => $today, 'end' => $today );
SFAF_Digest::save( $ADMIN, array( 'frequency' => 'daily' ) );
same( 'first send', SFAF_Digest::send_for_user( $ADMIN, $period, 'daily' ), 'sent' );
same( 'the same period again is refused by the ledger', SFAF_Digest::send_for_user( $ADMIN, $period, 'daily' ), 'already' );
same( 'one message, not two', count( mk_mail_to( 'ana@sfaf.org' ) ), 1 );
$row = mk_ledger();
check( 1 === count( $row ) && 'digest' === $row[0]['recipient_type'] && 0 === (int) $row[0]['event_id'] && 'sent' === $row[0]['result'], 'the digest\'s ledger row is not one sent row on event 0' );
check( false !== strpos( mk_mail_to( 'ana@sfaf.org' )[0]['text'], 'Your events today' ), 'the sent digest has no plain text part' );

same( 'nothing to list: not sent', SFAF_Digest::send_for_user( $CON_C, $period, 'daily' ), 'empty' );
same( 'nothing to list: no mail', count( mk_mail_to( 'fay@sfaf.org' ) ), 0 );
same( 'nothing to list: no ledger row', count( mk_ledger() ), 1 );

/* The run: subscribers only, calendar access required, once a period. */
world();
SFAF_Digest::save( $ADMIN, array( 'frequency' => 'daily' ) );
SFAF_Digest::save( $CON_A, array( 'frequency' => 'daily', 'venues' => array( $V1 ) ) );
SFAF_Digest::save( $GONE, array( 'frequency' => 'daily' ) );
SFAF_Digest::save( $EDITOR, array( 'frequency' => 'none' ) );
same( 'subscribers: daily and weekly people with calendar access', SFAF_Digest::subscribers(), array( $ADMIN, $CON_A ) );
if ( (int) date( 'G' ) >= SFAF_Reminders::DUE_HOUR ) {
    SFAF_Digest::run();
    SFAF_Digest::run();
    same( 'the run sends the admin one digest', count( mk_mail_to( 'ana@sfaf.org' ) ), 1 );
    same( 'the run sends the contributor one digest', count( mk_mail_to( 'cam@sfaf.org' ) ), 1 );
    same( 'nobody without calendar access gets one', count( mk_mail_to( 'gus@sfaf.org' ) ), 0 );
    same( 'nobody who chose none gets one', count( mk_mail_to( 'eli@sfaf.org' ) ), 0 );
}

/* When. */
$tz = wp_timezone();
same( 'daily, 5:59', SFAF_Digest::period( 'daily', new DateTime( '2026-10-06 05:59', $tz ) ), null );
same( 'daily, 6:00', SFAF_Digest::period( 'daily', new DateTime( '2026-10-06 06:00', $tz ) ), array( 'key' => 'daily:2026-10-06', 'start' => '2026-10-06', 'end' => '2026-10-06' ) );
same( 'weekly on a Tuesday', SFAF_Digest::period( 'weekly', new DateTime( '2026-10-06 09:00', $tz ) ), null );
same( 'weekly on a Monday', SFAF_Digest::period( 'weekly', new DateTime( '2026-10-05 06:10', $tz ) ), array( 'key' => 'weekly:2026-10-05', 'start' => '2026-10-05', 'end' => '2026-10-11' ) );
same( 'none', SFAF_Digest::period( 'none', new DateTime( '2026-10-05 07:00', $tz ) ), null );

/* ===========================================================================
 * 5. PREFERENCES: STORED PER USER, ONLY REAL TERMS, READ ONLY ELSEWHERE.
 * ======================================================================== */
world();
$saved = SFAF_Digest::save( $CON_A, array( 'frequency' => 'weekly', 'venues' => array( $V1, 999999, $S1 ), 'series' => array( $S2 ) ) );
same( 'only terms of each group are kept', array( $saved['venues'], $saved['series'] ), array( array( $V1 ), array( $S2 ) ) );
same( 'an unknown frequency is none', SFAF_Digest::save( $CON_C, array( 'frequency' => 'hourly' ) )['frequency'], 'none' );
same( 'the Users line', SFAF_Digest::describe( $CON_A ), 'Weekly digest. Venues: Strut. Series: Meal.' );
same( 'no digest, no line', SFAF_Digest::describe( $CON_C ), '' );
check( ! isset( $GLOBALS['mk_meta'][ $E[1] ][ SFAF_Digest::META ] ), 'a preference was written onto an event' );

/* ===========================================================================
 * 6. THE DAY BEFORE.
 * ======================================================================== */
world();
$D = mk_post( array( 'post_title' => 'Tomorrow\'s supper', 'post_author' => $CON_A ) );
update_post_meta( $D, '_uc_event_date', $tomorrow );
update_post_meta( $D, '_uc_start_time', '18:00' );
update_post_meta( $D, '_uc_end_time', '19:30' );
update_post_meta( $D, '_uc_rsvp_enabled', '1' );
update_post_meta( $D, SFAF_Reminders::NOTIFY_EMAILS_META, array( 'typed@example.org' ) );

$r0 = SFAF_Notifications::send_day_before_for_event( $D );
same( 'nobody registered: skipped', $r0['skipped'], 'nobody registered' );
same( 'nobody registered: no mail', count( $GLOBALS['mk_mail'] ), 0 );
same( 'nobody registered: not marked done, so a later registration still counts', get_post_meta( $D, SFAF_Notifications::DAY_BEFORE_DONE_META, true ), '' );

mk_rsvp( $D, 'Robin', 'robin@example.org' );
mk_rsvp( $D, 'Sam', 'sam@example.org' );
$r1 = SFAF_Notifications::send_day_before_for_event( $D );
same( 'sent to the organizer and the typed address', array( $r1['sent'], $r1['recipients'] ), array( 2, 2 ) );
$org   = mk_mail_to( 'cam@sfaf.org' );
$typed = mk_mail_to( 'typed@example.org' );
check( 1 === count( $org ) && false !== strpos( $org[0]['html'], $rsvp_url( $D ) ), 'the organizer, whom the gate allows, did not get the registrations link' );
check( 1 === count( $typed ) && false === strpos( $typed[0]['html'] . $typed[0]['text'], '/caladmin' ), 'a typed address got a caladmin link' );
check( false !== strpos( $typed[0]['text'], '2 registered' ), 'the count is not in the day-before message' );
foreach ( array( 'Robin', 'Sam', 'robin@example.org' ) as $who ) {
    check( false === strpos( $org[0]['html'] . $org[0]['text'] . $typed[0]['html'], $who ), "the day-before message names a registrant: $who" );
}
check( (bool) preg_match( '/6.7:30 pm PT/u', $typed[0]['text'] ), 'the day-before time has no zone' );

$r2 = SFAF_Notifications::send_day_before_for_event( $D );
same( 'a second pass is refused by the ledger', array( $r2['sent'], $r2['already'] ), array( 0, 2 ) );
same( 'still one each', array( count( mk_mail_to( 'cam@sfaf.org' ) ), count( mk_mail_to( 'typed@example.org' ) ) ), array( 1, 1 ) );
check( in_array( 'day_before', array_column( mk_ledger(), 'recipient_type' ), true ), 'no day_before rows in the ledger' );

/* It does not refuse the morning-of reminder for the same person and event. */
$claim = SFAF_Reminders::claim_key( $D, 'cam@sfaf.org', 'cam@sfaf.org', 'notify' );
check( null !== $claim, 'the day-before row blocked the same person\'s morning-of reminder' );

/* Switched off per event, like the others. */
same( 'a kind like the others', isset( SFAF_Notifications::kinds()['day_before'] ), true );
SFAF_Notifications::set_off( $D, array( 'day_before' ) );
same( 'switched off', SFAF_Notifications::on( $D, 'day_before' ), false );

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $x ) { echo '  . ' . $x . "\n"; }
    exit( 1 );
}
echo "digest: the event gate decides every list (admin and editor all, contributor own and team, nobody without a role);\n";
echo "filters narrow per group and never widen; the registrations link is asked of the gate per event; no names;\n";
echo "zoned times; one yellow button; sent once per period, never empty. day before: count only, link by the gate,\n";
echo "nothing when nobody registered, the ledger refuses a second pass and does not block the morning-of reminder.\n";
exit( 0 );
