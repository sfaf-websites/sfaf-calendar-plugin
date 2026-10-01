<?php
/**
 * QUESTIONS FOR REGISTRANTS, RUN (3.106.2).
 *
 *     php .claude/questions-test.php
 *
 * The real SFAF_Questions, SFAF_RSVP, SFAF_Waitlist and SFAF_Notifications on
 * mail-kit.php. What is read is the row, the answers table and the mail.
 *
 *   the editor's POST is cleaned: five questions at most, empty text and
 *   option-less questions dropped, ids kept through an edit; a required
 *   question unanswered is REFUSED BY THE SERVER whatever the form sent; an
 *   option that is not the question's is ignored, a radio keeps one; In person
 *   only is asked of in-person registrants on a hybrid event and of everybody
 *   on any other; answers are stored with the words as answered and the
 *   additional info; a waitlisted person's answers are on their row and in the
 *   alert when they are confirmed; the alert carries them, the two-hour
 *   summary does not; the totals count CONFIRMED registrants only; a removed
 *   question or option leaves the table and the strip, and stays in Details;
 *   the key travels with the group, the duplicate and the recurrence copy.
 */

require __DIR__ . '/mail-kit.php';

$fails = array();
function qt( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
( new SFAF_Reminders() )->register();
( new SFAF_Waitlist() )->register();
$rsvp = new SFAF_RSVP();
$rsvp->register();   // the alert and confirmation, on uc_rsvp_submitted

function qt_event( $meta = array() ) {
    $id = mk_post( array( 'post_title' => 'Support group' ) );
    foreach ( array_merge( array(
        '_uc_event_date' => date( 'Y-m-d', strtotime( '+10 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_rsvp_enabled' => '1', '_uc_capacity' => '2', SFAF_Reminders::NOTIFY_EMAILS_META => array( 'staff@sfaf.org' ),
    ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
function qt_reg( $event, $first, $email, $answers = array(), $more = array(), $format = '' ) {
    global $rsvp;
    return $rsvp->submit( array( 'event_id' => $event, 'first_name' => $first, 'last_name' => '', 'email' => $email,
        'phone' => '', 'optin' => false, 'format' => $format, 'no_email' => false, 'answers' => $answers, 'answers_more' => $more ) );
}
function qt_row( $first ) {
    foreach ( $GLOBALS['mk_db']['wp_uc_rsvps'] as $r ) { if ( $r['first_name'] === $first ) { return $r; } }
    return null;
}

/* ---- The editor's POST. -------------------------------------------------- */
mk_reset();
$E = qt_event();
$post = array( 'uc_questions_present' => '1', 'uc_questions' => array(
    array( 'id' => '', 'text' => 'Dietary needs', 'style' => 'any', 'required' => '1', 'options' => array(
        array( 'text' => 'Vegetarian' ), array( 'text' => 'Allergy', 'more' => '1' ), array( 'text' => '' ) ) ),
    array( 'text' => 'Bringing a guest?', 'style' => 'one', 'options' => array( array( 'text' => 'Yes' ), array( 'text' => 'No' ) ) ),
    array( 'text' => '', 'options' => array( array( 'text' => 'orphan' ) ) ),
    array( 'text' => 'No options', 'options' => array() ),
    array( 'text' => 'Parking', 'in_person' => '1', 'options' => array( array( 'text' => 'Car' ), array( 'text' => 'Bike' ) ) ),
) );
SFAF_Questions::save_from_post( $E, $post );
$qs = SFAF_Questions::for_event( $E );
qt( 'empty text and option-less questions are dropped', array_map( function ( $q ) { return $q['text']; }, $qs ), array( 'Dietary needs', 'Bringing a guest?', 'Parking' ) );
qt( 'an empty option is dropped', count( $qs[0]['options'] ), 2 );
qt( 'style, required and additional info are kept', array( $qs[0]['style'], $qs[0]['required'], $qs[0]['options'][1]['more'], $qs[1]['style'] ), array( 'any', true, true, 'one' ) );
$six = array( 'uc_questions_present' => '1', 'uc_questions' => array_fill( 0, 7, array( 'text' => 'Q', 'options' => array( array( 'text' => 'A' ) ) ) ) );
$S = qt_event();
SFAF_Questions::save_from_post( $S, $six );
qt( 'five questions at most', count( SFAF_Questions::for_event( $S ) ), 5 );
// An edit keeps the ids, so answers keep pointing at what they answered.
$again = $post;
$again['uc_questions'] = array( array( 'id' => $qs[0]['id'], 'text' => 'Dietary needs (edited)', 'options' => array(
    array( 'id' => $qs[0]['options'][0]['id'], 'text' => 'Vegetarian' ), array( 'id' => $qs[0]['options'][1]['id'], 'text' => 'Allergy', 'more' => '1' ) ) ) ) + $post['uc_questions'];
$T = qt_event();
SFAF_Questions::save_from_post( $T, $again );
qt( 'an edited question keeps its id', SFAF_Questions::for_event( $T )[0]['id'], $qs[0]['id'] );
SFAF_Questions::save_from_post( $T, array() );
qt( 'a form that did not draw the section changes nothing', count( SFAF_Questions::for_event( $T ) ), 3 );

/* ---- The server enforces what the form asked. --------------------------- */
$q1 = $qs[0]; $q2 = $qs[1];
$GLOBALS['mk_mail'] = array();
$r = qt_reg( $E, 'Ana', 'ana@example.org', array() );
qt( 'PLANT E.3: a required question unanswered is refused by the server', array( $r['success'], $r['message'] ), array( false, 'Answer every required question to register.' ) );
qt( 'and nobody is registered', qt_row( 'Ana' ), null );
$r = qt_reg( $E, 'Ana', 'ana@example.org',
    array( $q1['id'] => array( $q1['options'][1]['id'], 'o0000000000' ), $q2['id'] => array( $q2['options'][0]['id'], $q2['options'][1]['id'] ) ),
    array( $q1['id'] => array( $q1['options'][1]['id'] => 'Peanuts' ) ) );
qt( 'answered, Ana registers', $r['success'], true );
$ana = SFAF_Questions::answers_for( (int) qt_row( 'Ana' )['id'] );
qt( 'the answers are stored with their words and additional info; a stray option is ignored; a radio keeps one',
    $ana, array( 'Dietary needs' => array( array( 'Allergy', 'Peanuts' ) ), 'Bringing a guest?' => array( array( 'Yes', '' ) ) ) );
qt( 'Parking, In person only on an event that is not hybrid, is asked of everybody', SFAF_Questions::asked( $qs[2], $E, '' ), true );
$alert = array_values( array_filter( mk_mail_to( 'staff@sfaf.org' ), function ( $m ) { return false !== strpos( $m['subject'], 'New registration' ); } ) );
qt( 'the alert carries her answers under her name, with the additional info',
    isset( $alert[0] ) && false !== strpos( $alert[0]['text'], "Dietary needs: Allergy (Peanuts)\nBringing a guest?: Yes" ) && false !== strpos( $alert[0]['html'], 'Peanuts' ), true );
$sum = SFAF_Notifications::build( 'summary', $E, null, array() );
qt( 'the two-hour summary does not', $sum && false === strpos( $sum['html'] . $sum['text'], 'Peanuts' ) && false === strpos( $sum['text'], 'Dietary needs' ), true );

/* ---- Hybrid: In person only follows the format. -------------------------- */
mk_reset();
$H = qt_event( array( SFAF_Online::META_HYBRID => '1', '_uc_capacity_online' => '10' ) );
SFAF_Questions::save_from_post( $H, array( 'uc_questions_present' => '1', 'uc_questions' => array(
    array( 'text' => 'Parking', 'required' => '1', 'in_person' => '1', 'options' => array( array( 'text' => 'Car' ) ) ) ) ) );
$hq = SFAF_Questions::for_event( $H )[0];
qt( 'online, the in-person question is not asked, even required', qt_reg( $H, 'Lee', 'lee@example.org', array(), array(), SFAF_Online::MODE_ONLINE )['success'], true );
qt( 'in person, it is', qt_reg( $H, 'Jo', 'jo@example.org', array(), array(), SFAF_Online::MODE_IN_PERSON )['success'], false );
qt( 'answered, in person registers', qt_reg( $H, 'Jo', 'jo@example.org', array( $hq['id'] => array( $hq['options'][0]['id'] ) ), array(), SFAF_Online::MODE_IN_PERSON )['success'], true );
$tot = SFAF_Questions::totals( $H );
qt( 'an in-person question counts against the in-person registrants', array( $tot[0]['answered'], $tot[0]['of'] ), array( 1, 1 ) );

/* ---- The waitlist answers at join, and the answers follow them. --------- */
mk_reset();
$W = qt_event( array( '_uc_capacity' => '1' ) );
SFAF_Questions::save_from_post( $W, array( 'uc_questions_present' => '1', 'uc_questions' => array(
    array( 'text' => 'Dietary needs', 'required' => '1', 'options' => array( array( 'text' => 'Vegan' ), array( 'text' => 'None' ) ) ) ) ) );
$wq = SFAF_Questions::for_event( $W )[0];
$vegan = array( $wq['id'] => array( $wq['options'][0]['id'] ) );
$none  = array( $wq['id'] => array( $wq['options'][1]['id'] ) );
qt( 'Ana registers', qt_reg( $W, 'Ana', 'ana@example.org', $none )['success'], true );
qt( 'Cam, unanswered, cannot join the waitlist either', qt_reg( $W, 'Cam', 'cam@example.org', array() )['success'], false );
$c = qt_reg( $W, 'Cam', 'cam@example.org', $vegan );
qt( 'Cam, answered, joins the waitlist', ! empty( $c['waitlisted'] ), true );
qt( 'his answers are on his row', SFAF_Questions::answers_for( (int) qt_row( 'Cam' )['id'] ), array( 'Dietary needs' => array( array( 'Vegan', '' ) ) ) );
$t = SFAF_Questions::totals( $W );
qt( 'PLANT E.5: the totals count confirmed registrants only', array( $t[0]['answered'], $t[0]['of'], $t[0]['options'] ),
    array( 1, 1, array( array( 'Vegan', 0 ), array( 'None', 1 ) ) ) );
qt( 'the line reads as the brief writes it', SFAF_Questions::total_line( $t[0] ), 'Dietary needs: 1 of 1 answered. Vegan 0, None 1' );
$GLOBALS['mk_mail'] = array();
SFAF_Reminders::cancel_rsvp( $W, 'ana@example.org' );
SFAF_Waitlist::accept( qt_row( 'Cam' )['offer_token'] );
qt( 'Cam is confirmed on the same row', qt_row( 'Cam' )['status'], 'confirmed' );
$alert = array_values( array_filter( mk_mail_to( 'staff@sfaf.org' ), function ( $m ) { return false !== strpos( $m['subject'], 'New registration' ); } ) );
qt( 'the alert for Cam carries the answers he gave when he joined', isset( $alert[0] ) && false !== strpos( $alert[0]['text'], 'Dietary needs: Vegan' ), true );
$t = SFAF_Questions::totals( $W );
qt( 'now he counts, and Ana, cancelled, does not', array( $t[0]['answered'], $t[0]['options'] ), array( 1, array( array( 'Vegan', 1 ), array( 'None', 0 ) ) ) );

/* ---- Removing keeps the answers and hides them from the strip. ---------- */
SFAF_Questions::save_from_post( $W, array( 'uc_questions_present' => '1', 'uc_questions' => array(
    array( 'id' => $wq['id'], 'text' => 'Dietary needs', 'options' => array( array( 'id' => $wq['options'][1]['id'], 'text' => 'None' ) ) ),
    array( 'text' => 'New question', 'options' => array( array( 'text' => 'A' ) ) ) ) ) );
$t = SFAF_Questions::totals( $W );
qt( 'a removed option leaves the strip, and its answer is no longer counted', array( $t[0]['answered'], $t[0]['options'] ), array( 0, array( array( 'None', 0 ) ) ) );
qt( 'the answer stays in the table and in Details', SFAF_Questions::answers_for_rows( array( (int) qt_row( 'Cam' )['id'] ) ),
    array( (int) qt_row( 'Cam' )['id'] => array( 'Dietary needs' => array( array( 'Vegan', '' ) ) ) ) );
SFAF_Questions::save_from_post( $W, array( 'uc_questions_present' => '1', 'uc_questions' => array() ) );
qt( 'with every question removed the strip is empty', SFAF_Questions::totals( $W ), array() );
qt( 'and the answers are still there', count( $GLOBALS['mk_db']['wp_uc_rsvp_answers'] ), 2 );

/* ---- It travels, and the source keeps it from third-party events. ------- */
$root = dirname( __DIR__ );
$portal = (string) file_get_contents( $root . '/includes/class-sfaf-portal.php' );
qt( 'it travels with the group and the duplicate', substr_count( $portal, 'SFAF_Questions::META,' ), 2 );
qt( 'and with the recurrence copy', false !== strpos( (string) file_get_contents( $root . '/includes/class-sfaf-recurrence.php' ), "'_uc_questions'," ), true );
qt( 'the editor offers it only where the event takes its registrations here',
    (bool) preg_match( "/if \\( ! \\\$ctx\\['imported'\\] \\) \\{[^}]*'questions'/s", $portal ), true );

if ( $fails ) {
    echo 'QUESTIONS: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "questions: cleaned on save, required answers enforced by the server, in-person ones by format; stored on the row, carried through the waitlist into the alert; totals count the confirmed only; removing keeps answers out of the strip and in Details.\n";
