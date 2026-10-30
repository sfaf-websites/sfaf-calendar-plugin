<?php
/**
 * 3.113.6, THE ENGLISH | ESPAÑOL TOGGLE ON THE RSVP FORM, RUN.
 *
 *     php .claude/release-31136-test.php
 *
 * The real SFAF_RSVP, SFAF_Waitlist, SFAF_Reminders, SFAF_Notifications,
 * SFAF_Announce and SFAF_Messages on mail-kit.php, whose table model runs the
 * SQL and whose wp_mail() records what was sent. A message's language is read
 * from its dates, which every registrant message carries and which the one
 * formatter writes as "12 de noviembre de 2026" in Spanish.
 *
 *   1  the form's language is stored with the registration and the waitlist
 *      join; a form without the toggle stores '' and follows the event
 *   2  what the server refuses, it refuses in the form's language
 *   3  the confirmation, the morning-of reminder, the waitlist confirmation,
 *      the confirmation on being added from it, and the cancellation, change
 *      and back-on notices speak the registration's language, one date or
 *      several; the staff copy and alert stay English
 *   4  Email registrants keeps the event's language
 *   5  the cancel page: the registration's language, through the reminder's
 *      ledger link too
 *   6  the form's words: both languages, every key, nothing empty, no em dash
 *   7  the button carries the event's language; calendar.js sends the choice
 *      and keeps its styles out of calendar.css
 *
 * The form in a browser is release-31136-live.php's; the embed is
 * embed-identity-test.php's. PLANTs in plant-31136.php.
 */

require __DIR__ . '/mail-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
$root = dirname( __DIR__ );

( new SFAF_Reminders() )->register();
( new SFAF_Waitlist() )->register();
$rsvp = new SFAF_RSVP();
$rsvp->register();                       // the confirmation and alert, on uc_rsvp_submitted

function rt_event( $days, $meta = array() ) {
    $id = mk_post( array( 'post_title' => 'Support group' ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d', strtotime( "+$days days" ) ) );
    update_post_meta( $id, '_uc_start_time', '18:00' );
    update_post_meta( $id, '_uc_end_time', '19:30' );
    update_post_meta( $id, '_uc_rsvp_enabled', '1' );
    update_post_meta( $id, '_uc_capacity', '2' );
    update_post_meta( $id, SFAF_Reminders::NOTIFY_EMAILS_META, array( 'staff@sfaf.org' ) );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
function rt_reg( $event_id, $first, $email, $lang ) {
    global $rsvp;
    $d = array( 'event_id' => $event_id, 'first_name' => $first, 'last_name' => '', 'email' => $email,
        'phone' => '', 'optin' => false, 'format' => '', 'no_email' => false );
    if ( null !== $lang ) { $d['lang'] = $lang; }
    return $rsvp->submit( $d );
}
function rt_row( $first ) {
    foreach ( $GLOBALS['mk_db']['wp_uc_rsvps'] as $r ) { if ( $r['first_name'] === $first ) { return $r; } }
    return null;
}
/** 'es', 'en', or '?' from the dates in a message. */
function rt_lang_of( $m ) {
    $t = $m['subject'] . "\n" . $m['text'];
    $es = (bool) preg_match( '/\d{1,2} de [a-z]+ de 20\d\d/u', $t );
    $en = (bool) preg_match( '/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday), [A-Z][a-z]+\.? \d{1,2}/', $t );
    return $es && ! $en ? 'es' : ( $en && ! $es ? 'en' : '?' );
}
function rt_langs( $to ) { return array_map( 'rt_lang_of', mk_mail_to( $to ) ); }

/* ======================================================================== 1 */
mk_reset();
$E = rt_event( 10 );                                     // an English event
rt( '1: Spanish picked on an English event registers', rt_reg( $E, 'Ana', 'ana@example.org', 'es' )['success'], true );
rt( '1: and the row keeps es', rt_row( 'Ana' )['lang'], 'es' );
rt( '1: English picked keeps en', array( rt_reg( $E, 'Ben', 'ben@example.org', 'en' )['success'], rt_row( 'Ben' )['lang'] ), array( true, 'en' ) );
$w = rt_reg( $E, 'Cam', 'cam@example.org', 'es' );
rt( '1: a waitlist join keeps the language too', array( ! empty( $w['waitlisted'] ), rt_row( 'Cam' )['lang'] ), array( true, 'es' ) );
mk_reset();
$E2 = rt_event( 10 );
rt( '1: a form without the toggle stores nothing', array( rt_reg( $E2, 'Dee', 'dee@example.org', null )['success'], rt_row( 'Dee' )['lang'] ), array( true, '' ) );
rt( '1: and anything but en or es is nothing', array( rt_reg( $E2, 'Eve', 'eve@example.org', 'fr' )['success'], rt_row( 'Eve' )['lang'] ), array( true, '' ) );

/* ======================================================================== 2 */
mk_reset();
$E = rt_event( 10 );
rt( '2: a missing name, refused in Spanish', rt_reg( $E, '', 'x@example.org', 'es' )['message'], 'Escriba su nombre y su correo electrónico.' );
rt( '2: and in English', rt_reg( $E, '', 'x@example.org', 'en' )['message'], 'First name and email are required.' );
rt_reg( $E, 'Ana', 'ana@example.org', 'es' );
rt( '2: twice, refused in Spanish', rt_reg( $E, 'Ana', 'ana@example.org', 'es' )['message'], 'Ya se inscribió en este evento.' );
$SE = rt_event( 10, array( '_uc_language' => 'es' ) );
rt( '2: no toggle on a Spanish event refuses in Spanish', rt_reg( $SE, '', 'x@example.org', null )['message'], 'Escriba su nombre y su correo electrónico.' );
rt( '2: the success message too', rt_reg( $E, 'Ivy', 'ivy@example.org', 'es' )['message'], '¡Su inscripción está confirmada! Esperamos verle pronto.' );

/* ======================================================================== 3 */
mk_reset();
$E = rt_event( 10 );                                     // English event
rt_reg( $E, 'Ana', 'ana@example.org', 'es' );
rt_reg( $E, 'Ben', 'ben@example.org', '' );
rt( '3: Ana\'s confirmation is Spanish', rt_langs( 'ana@example.org' ), array( 'es' ) );
rt( '3: Ben, no toggle, gets the event\'s English', rt_langs( 'ben@example.org' ), array( 'en' ) );
$alert = mk_mail_to( 'staff@sfaf.org' );
rt( '3: the staff alert stays English', $alert ? rt_lang_of( end( $alert ) ) : 'none', 'en' );

$SE = rt_event( 12, array( '_uc_language' => 'es' ) );   // Spanish event
rt_reg( $SE, 'Cal', 'cal@example.org', 'en' );
rt( '3: English picked on a Spanish event is English', rt_langs( 'cal@example.org' ), array( 'en' ) );

/* The morning-of reminder, today's event. */
mk_reset();
$T = rt_event( 0 );
update_post_meta( $T, '_uc_start_time', date( 'H:i', strtotime( '+3 hours' ) ) );
update_post_meta( $T, '_uc_end_time', '23:59' );
rt_reg( $T, 'Ana', 'ana@example.org', 'es' );
rt_reg( $T, 'Ben', 'ben@example.org', 'en' );
$GLOBALS['mk_mail'] = array();
SFAF_Reminders::send_for_event( $T );
rt( '3: Ana\'s reminder is Spanish', rt_langs( 'ana@example.org' ), array( 'es' ) );
rt( '3: Ben\'s is English', rt_langs( 'ben@example.org' ), array( 'en' ) );
rt( '3: the staff copy stays English', rt_langs( 'staff@sfaf.org' ), array( 'en' ) );

/* The waitlist, and being added from it. */
mk_reset();
$E = rt_event( 10 );
rt_reg( $E, 'Ana', 'ana@example.org', 'en' );
rt_reg( $E, 'Ben', 'ben@example.org', 'en' );
rt_reg( $E, 'Cam', 'cam@example.org', 'es' );
rt( '3: Cam\'s waitlist confirmation is Spanish', rt_langs( 'cam@example.org' ), array( 'es' ) );
$GLOBALS['mk_mail'] = array();
SFAF_Reminders::cancel_rsvp( $E, 'ana@example.org' );
rt( '3: Cam, added from the waitlist, is confirmed in Spanish', rt_langs( 'cam@example.org' ), array( 'es' ) );

/* Cancelled, changed, back on; one date and several. */
mk_reset();
$A = rt_event( 10 );
$B = rt_event( 17 );
rt_reg( $A, 'Ana', 'ana@example.org', 'es' );
rt_reg( $A, 'Ben', 'ben@example.org', 'en' );
rt_reg( $B, 'Ana', 'ana@example.org', 'es' );
$GLOBALS['mk_mail'] = array();
SFAF_Announce::changed( array( $A ), array( $A => array( 'Time' => array( 'from' => '5–6 pm', 'to' => '6–7:30 pm' ) ) ) );
rt( '3: the change notice, Spanish for Ana', rt_langs( 'ana@example.org' ), array( 'es' ) );
rt( '3: and English for Ben', rt_langs( 'ben@example.org' ), array( 'en' ) );
$GLOBALS['mk_mail'] = array();
SFAF_Cancellation::set( $A, true, 'stay' );
SFAF_Cancellation::set( $B, true, 'stay' );
SFAF_Announce::cancelled( array( $A, $B ) );
rt( '3: two dates cancelled, one Spanish message for Ana', rt_langs( 'ana@example.org' ), array( 'es' ) );
rt( '3: one date, English for Ben', rt_langs( 'ben@example.org' ), array( 'en' ) );
$GLOBALS['mk_mail'] = array();
SFAF_Cancellation::set( $A, false, 'stay' );
SFAF_Announce::reinstated( array( $A ) );
rt( '3: back on, Spanish for Ana', rt_langs( 'ana@example.org' ), array( 'es' ) );

/* The waitlist told the event is off. */
mk_reset();
$E = rt_event( 10 );
rt_reg( $E, 'Ana', 'ana@example.org', 'en' );
rt_reg( $E, 'Ben', 'ben@example.org', 'en' );
rt_reg( $E, 'Cam', 'cam@example.org', 'es' );
$GLOBALS['mk_mail'] = array();
SFAF_Cancellation::set( $E, true, 'stay' );
SFAF_Announce::cancelled( array( $E ) );
rt( '3: the waitlist\'s cancellation notice is Spanish for Cam', rt_langs( 'cam@example.org' ), array( 'es' ) );

/* ======================================================================== 4 */
mk_reset();
$E = rt_event( 10 );
rt_reg( $E, 'Ana', 'ana@example.org', 'es' );
$row  = (object) rt_row( 'Ana' );
$msg  = SFAF_Notifications::build( 'registrant_message', $E, $row, array( 'subject' => 'Room change', 'body' => '<p>We moved to room 4.</p>' ) );
rt( '4: Email registrants stays in the event\'s language', rt_lang_of( array( 'subject' => $msg['subject'], 'text' => $msg['text'] ) ), 'en' );
rt( '4: and shows the words as typed', false !== strpos( $msg['text'], 'We moved to room 4.' ) && 'Room change' === $msg['subject'], true );

/* ======================================================================== 5 */
mk_reset();
$E = rt_event( 10 );
rt_reg( $E, 'Ana', 'ana@example.org', 'es' );
rt_reg( $E, 'Ben', 'ben@example.org', '' );
rt( '5: the cancel page speaks the registration\'s language', SFAF_Reminders::registrant_lang( (object) rt_row( 'Ana' ) ), 'es' );
rt( '5: none picked, the event\'s', SFAF_Reminders::registrant_lang( (object) rt_row( 'Ben' ) ), 'en' );
rt( '5: through the reminder ledger, which keeps no language', SFAF_Reminders::registrant_lang( (object) array( 'event_id' => $E, 'email' => 'ana@example.org' ) ), 'es' );
rt( '5: the cancel link asks it', false !== strpos( (string) file_get_contents( $root . '/includes/class-sfaf-reminders.php' ), "\$lang     = self::registrant_lang( \$row );" ), true );

/* ======================================================================== 6 */
$fw = SFAF_Messages::form_words();
rt( '6: both languages', array_keys( $fw ), array( 'en', 'es' ) );
rt( '6: the same keys in each', array_keys( $fw['en'] ), array_keys( $fw['es'] ) );
$empty = array(); $dash = array(); $same = array();
foreach ( array( 'en', 'es' ) as $l ) { foreach ( $fw[ $l ] as $k => $v ) { if ( '' === trim( $v ) ) { $empty[] = "$l.$k"; } if ( false !== strpos( $v, "\u{2014}" ) ) { $dash[] = "$l.$k"; } } }
foreach ( $fw['en'] as $k => $v ) { if ( $v === $fw['es'][ $k ] && ! in_array( $k, array( 'email_ph', 'by_whatsapp' ), true ) ) { $same[] = $k; } }
rt( '6: nothing empty', $empty, array() );
rt( '6: no em dash', $dash, array() );
rt( '6: every Spanish word is Spanish', $same, array() );
foreach ( array( 'heading', 'first', 'last', 'email', 'email_use', 'noemail', 'phone', 'optin', 'submit', 'cancel', 'attend', 'in_person', 'online',
    'registered', 'registered_name', 'on_waitlist', 'position', 'inbox', 'q_heading', 'q_more', 'q_required', 'agreement_heading', 'agreement_tick',
    'agreement_confirm', 'agreement_cancel', 'text_consent' ) as $k ) {
    rt( "6: the form has \"$k\"", isset( $fw['en'][ $k ], $fw['es'][ $k ] ), true );
}
rt( '6: one %s where a value goes in', array( substr_count( $fw['es']['position'], '%s' ), substr_count( $fw['es']['registered_name'], '%s' ) ), array( 1, 1 ) );

/* ======================================================================== 7 */
$tf  = (string) file_get_contents( $root . '/includes/sfaf-template-functions.php' );
$js  = (string) file_get_contents( $root . '/public/js/calendar.js' );
$cal = (string) file_get_contents( $root . '/public/css/calendar.css' );
$pl  = (string) file_get_contents( $root . '/sfaf-calendar.php' );
rt( '7: the Register button carries the event\'s language', false !== strpos( $tf, "'data-uc-lang'    => sfaf_event_language( \$post_id )," ), true );
rt( '7: the page carries the words', false !== strpos( $pl, "'rsvpWords' => SFAF_Messages::form_words()," ), true );
rt( '7: the form sends the choice', false !== strpos( $js, "                lang:         currentLang\n" ), true );
// 3.126.0: through startLang(), which falls back to the button's language
// when the browser's is neither English nor Spanish (release-31260-live.php).
rt( '7: the form opens on the button\'s language', false !== strpos( $js, "currentLang = startLang(\$(this).attr('data-uc-lang'));" )
    && false !== strpos( $js, "return (eventLang === 'es') ? 'es' : 'en';" ), true );
rt( '7: the toggle is two pressed-state buttons', false !== strpos( $js, '<button type="button" lang="en" data-uc-lang-pick="en" aria-pressed="true">English</button>' )
    && false !== strpos( $js, '<button type="button" lang="es" data-uc-lang-pick="es" aria-pressed="false">Español</button>' ), true );
rt( '7: its styles are not in calendar.css, which the embed loads', false === strpos( $cal, 'uc-rsvp-lang' ), true );
rt( '7: schema 15 adds the column', ( preg_match( "/define\( 'SFAF_DB_VERSION', '(\d+)' \);/", $pl, $dbv ) && (int) $dbv[1] >= 15 ) && false !== strpos( $pl, "        lang varchar(5) NOT NULL DEFAULT '',\n" ), true );

echo "\nRelease 3.113.6\n" . str_repeat( '=', 72 ) . "\nran $ran assertions.\n";
if ( $fails ) {
    echo count( $fails ) . " failure(s):\n";
    foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
    exit( 1 );
}
echo "The form's language is kept with each registration and every message to that person speaks it; Email registrants keeps the event's.\n";
