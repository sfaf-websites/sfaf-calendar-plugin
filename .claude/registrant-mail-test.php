<?php
/**
 * EMAIL REGISTRANTS, RUN (3.110.0).
 *
 *     php .claude/registrant-mail-test.php
 *
 * The real SFAF_Registrant_Mail, SFAF_Notifications, SFAF_Messages and
 * SFAF_Email on mail-kit.php, whose wp_mail() records what was sent.
 *
 *   D.2  everybody registered with an email is sent one message, once per
 *        address; the waitlist only when ticked; a row with no email is sent
 *        nothing and is named in what the send returns
 *        PLANT D.2: a message handed to a row with no email
 *   D.2  the tokens are filled per person; the event's Reply-To; Preview is
 *        the first recipient's message and sends nothing
 *   D.3  the log, newest first: who, when, the subject, how many
 *   D.4  the event's language for the wrapper, no images
 */

require __DIR__ . '/mail-kit.php';

$fails = array();
function rm( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

$rsvp = new SFAF_RSVP();
function rm_event( $meta = array() ) {
    $id = mk_post( array( 'post_title' => 'Harm reduction walk' ) );
    update_post_meta( $id, '_uc_event_date', date( 'Y-m-d', strtotime( '+10 days' ) ) );
    update_post_meta( $id, '_uc_start_time', '18:00' );
    update_post_meta( $id, '_uc_end_time', '19:00' );
    update_post_meta( $id, '_uc_rsvp_enabled', '1' );
    update_post_meta( $id, '_uc_capacity', '3' );
    update_post_meta( $id, '_uc_email_replyto', 'walks@sfaf.org' );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
function rm_reg( $event_id, $first, $last, $email, $phone = '' ) {
    global $rsvp;
    return $rsvp->submit( array( 'event_id' => $event_id, 'first_name' => $first, 'last_name' => $last, 'email' => $email,
        'phone' => $phone, 'optin' => false, 'format' => '', 'no_email' => '' === $email ) );
}

mk_reset();
$E = rm_event();
rm_reg( $E, 'Ana', 'Alvarez', 'ana@example.org' );
rm_reg( $E, 'Ben', 'Brooks', 'ben@example.org' );
rm_reg( $E, 'Dee', 'Diaz', '', '(415) 555-0100' );
rm_reg( $E, 'Eve', 'Evans', 'eve@example.org' );      // fourth of three: waiting
function rm_row( $first ) { foreach ( $GLOBALS['mk_db']['wp_uc_rsvps'] as $r ) { if ( $r['first_name'] === $first ) { return $r; } } return null; }
rm( 'Eve is waiting', rm_row( 'Eve' )['status'], 'waitlisted' );
/* The same address on a second row, as a household registering twice. */
$dup = rm_row( 'Ana' );
$dup['id'] = 99; $dup['first_name'] = 'Ana2'; $dup['token'] = 'dup';
$GLOBALS['mk_db']['wp_uc_rsvps'][99] = $dup;

$subject = 'Meeting point for {title}';
$body    = "Hi {first_name},\n\nWe meet at {location} on {date} at {time}. Details: {event_link}";

/* ---- Preview ---------------------------------------------------------------- */
$GLOBALS['mk_mail'] = array();
$p = SFAF_Registrant_Mail::preview( $E, $subject, $body, false );
rm( 'Preview sends nothing', count( $GLOBALS['mk_mail'] ), 0 );
rm( 'Preview is the first recipient\'s', $p['to'], 'ana@example.org' );
rm( 'with the subject filled', $p['subject'], 'Meeting point for Harm reduction walk' );
rm( 'and the first name', false !== strpos( $p['text'], 'Hi Ana,' ), true );
rm( 'no token left unfilled', (bool) preg_match( '/\{[a-z_]+\}/', $p['subject'] . $p['text'] . $p['html'] ), false );
rm( 'no images', false === stripos( $p['html'], '<img' ) || false === strpos( $p['html'], 'x.png' ), true );
rm( 'an unknown token is caught before sending', SFAF_Registrant_Mail::unknown_tokens( 'Hi {first_name} {nickname}' ), array( 'nickname' ) );

/* ---- Who it reaches ------------------------------------------------------ */
$rc = SFAF_Registrant_Mail::recipients( $E, true );
rm( 'PLANT D.2: no row without an email is a recipient', array_values( array_filter( array_map( function ( $r ) { return (string) $r->email; }, $rc['to'] ), function ( $e ) { return '' === $e; } ) ), array() );
rm( 'and it is counted as unreachable', count( $rc['unreachable'] ), 1 );

/* ---- Send, registered only ----------------------------------------------- */
$GLOBALS['mk_mail'] = array();
$out = SFAF_Registrant_Mail::send( $E, $subject, $body, false, 1 );
$to  = array_map( function ( $m ) { return $m['to']; }, $GLOBALS['mk_mail'] );
rm( 'everybody registered with an email, once each', $to, array( 'ana@example.org', 'ben@example.org' ) );
rm( 'PLANT D.2: nothing goes to a row with no email', count( array_filter( $to, function ( $t ) { return '' === trim( (string) $t ); } ) ), 0 );
rm( 'the count sent', $out['sent'], 2 );
rm( 'the row with no email is named', $out['unreachable'], array( 'Dee Diaz' ) );
rm( 'the waitlist is not sent it', count( mk_mail_to( 'eve@example.org' ) ), 0 );
$ben = mk_mail_to( 'ben@example.org' );
rm( 'Ben is greeted by his own name', isset( $ben[0] ) && false !== strpos( $ben[0]['text'], 'Hi Ben,' ), true );
rm( 'with the event\'s Reply-To', isset( $ben[0] ) && in_array( 'Reply-To: walks@sfaf.org', (array) $ben[0]['headers'], true ), true );

/* ---- Send, with the waitlist ---------------------------------------------- */
$GLOBALS['mk_mail'] = array();
$out = SFAF_Registrant_Mail::send( $E, 'Second', 'Hi {first_name}', true, 1 );
rm( 'ticked, the waitlist is sent it too', array_map( function ( $m ) { return $m['to']; }, $GLOBALS['mk_mail'] ), array( 'ana@example.org', 'ben@example.org', 'eve@example.org' ) );

/* ---- The log ---------------------------------------------------------------- */
$log = SFAF_Registrant_Mail::log( $E );
rm( 'two sends logged, newest first', array( count( $log ), $log[0]['subject'], $log[1]['subject'] ), array( 2, 'Second', $subject ) );
rm( 'who and how many', array( $log[0]['by'], $log[0]['count'], $log[1]['count'] ), array( 1, 3, 2 ) );
rm( 'and when', (bool) preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $log[0]['at'] ), true );

/* ---- The event's language --------------------------------------------------- */
$ES = rm_event( array( '_uc_language' => 'es' ) );
rm_reg( $ES, 'Luz', 'Lopez', 'luz@example.org' );
$en = SFAF_Registrant_Mail::preview( $E, 'Hola', 'Hola {first_name}', false );
$es = SFAF_Registrant_Mail::preview( $ES, 'Hola', 'Hola {first_name}', false );
rm( 'a Spanish event\'s wrapper is not the English one', false !== strpos( $es['html'], '>Fecha<' ) && false === strpos( $es['html'], '>Date<' ) && false !== strpos( $en['html'], '>Date<' ), true );

/* ---- 3.110.2: the layout ------------------------------------------------- */
$rich = "Hi {first_name},\n\nBring <strong>water</strong> and read <a href=\"https://www.sfaf.org/walk\">the route</a>.<script>x</script>";
$pv = SFAF_Registrant_Mail::preview( $E, 'Meeting point for {title}', SFAF_Registrant_Mail::clean_body( $rich ), false );
$h  = $pv['html'];
rm( 'A.1: the subject is the heading', false !== strpos( $h, SFAF_Email::heading( 'Meeting point for Harm reduction walk' ) ), true );
rm( 'A.1: the body\'s first paragraph is a paragraph, not the heading', (bool) preg_match( '#<p[^>]*>Hi Ana,</p>#', $h ), true );
rm( 'A.1: bold is kept', false !== strpos( $h, '<strong>water</strong>' ), true );
rm( 'A.1: the link is kept', (bool) preg_match( '#<a href="https://www.sfaf.org/walk"[^>]*>the route</a>#', $h ), true );
rm( 'A.1: nothing else is', false === strpos( $h, '<script>' ), true );
rm( 'A.1: the plain-text part gives the link its address', false !== strpos( $pv['text'], 'the route (https://www.sfaf.org/walk)' ), true );
$at_body    = strpos( $h, 'Hi Ana,' );
$at_details = strpos( $h, '>Event<' );
$at_button  = strpos( $h, 'See the event page' );
rm( 'A.1: body, then the details, then See the event page', false !== $at_body && false !== $at_details && false !== $at_button && $at_body < $at_details && $at_details < $at_button, true );
rm( 'A.1: See the event page is the button', (bool) preg_match( '#<a[^>]+href="[^"]*"[^>]*>[^<]*See the event page#', $h ) && false !== stripos( substr( $h, max( 0, $at_button - 600 ), 600 ), 'background' ), true );

/* ---- 3.110.2: the event team and the organizer's copy -------------------- */
mk_reset();
$ORG  = mk_user( 'mark@sfaf.org', 'Pat Example', 'admin' );
$T1   = mk_user( 'tia@sfaf.org', 'Tia Team', 'contributor' );
$T2   = mk_user( 'ana@example.org', 'Ana Alvarez', 'contributor' );   // also registered: counted once, as a participant
$NAMD = mk_user( 'ned@sfaf.org', 'Ned Named', 'editor' );
update_option( 'sfaf_teams', array( 'prog' => array( 'id' => 'prog', 'name' => 'Programs', 'users' => array( $T1, $T2 ), 'created' => 1, 'updated' => 1 ) ) );
$F = mk_post( array( 'post_title' => 'Harm reduction walk', 'post_author' => $ORG ) );
foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+10 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:00', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '3' ) as $k => $v ) { update_post_meta( $F, $k, $v ); }
SFAF_Access::set( $F, array( 'prog' ), array( $NAMD ) );
rm_reg( $F, 'Ana', 'Alvarez', 'ana@example.org' );
rm_reg( $F, 'Ben', 'Brooks', 'ben@example.org' );

$to_of = function () { return array_map( function ( $m ) { return strtolower( $m['to'] ); }, $GLOBALS['mk_mail'] ); };
$GLOBALS['mk_mail'] = array();
$out = SFAF_Registrant_Mail::send( $F, 'Hello', 'Hi {first_name}', false, $ORG, false );
$to  = $to_of();
rm( 'A.4: without the team, the participants and the organizer, once each', $to, array( 'ana@example.org', 'ben@example.org', 'mark@sfaf.org' ) );
rm( 'A.4: the line', SFAF_Registrant_Mail::said( $out ), 'Sent to 2 participants and a copy to Pat Example.' );

$GLOBALS['mk_mail'] = array();
$out = SFAF_Registrant_Mail::send( $F, 'Hello', 'Hi {first_name}', false, $ORG, true );
$to  = $to_of();
sort( $to );
rm( 'A.3: with the team, its members and the named person, each address once', $to, array( 'ana@example.org', 'ben@example.org', 'mark@sfaf.org', 'ned@sfaf.org', 'tia@sfaf.org' ) );
rm( 'A.3: the line counts them apart', SFAF_Registrant_Mail::said( $out ), 'Sent to 2 participants, 2 team members, and a copy to Pat Example.' );
$tia = mk_mail_to( 'tia@sfaf.org' );
rm( 'A.3: a team member is greeted by their own first name', isset( $tia[0] ) && false !== strpos( $tia[0]['text'], 'Hi Tia' ), true );
$log = SFAF_Registrant_Mail::log( $F );
rm( 'A.3: the log counts them apart', array( $log[0]['sent'], $log[0]['team'], $log[0]['organizer'], $log[0]['count'] ), array( 2, 2, 'Pat Example', 5 ) );

/* The organizer already a recipient: on the team, so no second copy. */
update_option( 'sfaf_teams', array( 'prog' => array( 'id' => 'prog', 'name' => 'Programs', 'users' => array( $T1, $ORG ), 'created' => 1, 'updated' => 1 ) ) );
$GLOBALS['mk_mail'] = array();
$out = SFAF_Registrant_Mail::send( $F, 'Hello', 'Hi {first_name}', false, $ORG, true );
rm( 'PLANT A.4: the organizer, already on the team, gets one copy and not two', count( mk_mail_to( 'mark@sfaf.org' ) ), 1 );
rm( 'PLANT A.4: and is counted as a team member, not a copy', SFAF_Registrant_Mail::said( $out ), 'Sent to 2 participants and 3 team members.' );
/* The organizer registered for their own event: one copy, as a participant. */
update_option( 'sfaf_teams', array() );
SFAF_Access::set( $F, array(), array() );
rm_reg( $F, 'Mark', 'Sapoznikov', 'mark@sfaf.org' );
$GLOBALS['mk_mail'] = array();
$out = SFAF_Registrant_Mail::send( $F, 'Hello', 'Hi {first_name}', false, $ORG, false );
rm( 'PLANT A.4: the organizer, registered, gets one copy', count( mk_mail_to( 'mark@sfaf.org' ) ), 1 );
rm( 'PLANT A.4: the line names no copy', SFAF_Registrant_Mail::said( $out ), 'Sent to 3 participants.' );

if ( $fails ) {
    echo 'REGISTRANT MAIL: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "registrant mail: one message per address to everybody registered, the waitlist when ticked, nothing to a row with no email and its name returned; tokens filled per person; the event's Reply-To; Preview sends nothing; the log newest first; the event's language.\n";
