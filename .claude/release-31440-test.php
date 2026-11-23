<?php
/**
 * 3.144.0: THE TEXT REPLIES INBOX.
 *
 *     php .claude/release-31440-test.php
 *
 * The real SFAF_Texts, SFAF_Text_Inbox, SFAF_Email and the RSVP page's
 * Replies section, on wp-kit.php, with the tables they write modelled here,
 * every request to Twilio recorded instead of sent, and every email and
 * scheduled run caught.
 *
 *   R  Recording: a confirmation, a reminder and a Text registrants message
 *      are rows when they go, with their kind, body and sender; a reply is a
 *      row against the last message sent to the number; STOP, START and HELP
 *      are never rows; a stranger's reply is kept with no event.
 *   A  The alert: a reply to Text registrants goes to the staff member who
 *      sent it; a reply to a confirmation or a reminder to Replies go to; a
 *      sender whose account has gone, to Replies go to; a stranger, to the
 *      site default. Name, phone, the message and the RSVP page's link in
 *      each. Replies within five minutes in one email; a second run sends
 *      nothing; one scheduled run per number; an overdue one sent when the
 *      next reply comes.
 *   Y  The auto-reply: Mark's line in English and Spanish, by text with
 *      "SFAF:" and the STOP line, once a day per number.
 *   O  Answering from the RSVP page: by text through the Messaging Service;
 *      by WhatsApp as typed within 24 hours of their last WhatsApp message,
 *      and after that as the approved update template; never to a stopped
 *      number, or to one that has not replied about this event; each answer
 *      a row that a further reply goes back to its sender through.
 *   P  The section: threads named from the registration, newest first,
 *      the replies and the answers in order, the box's hint saying how it
 *      will arrive, nothing to answer a stopped number with; absent with
 *      Twilio off and no replies.
 *   W  The wiring: schema 20 and the table, the alert hook, the route behind
 *      the event gate, the section beside Text registrants for whoever may
 *      edit the event.
 *
 * Browser: release-31440-live.php. PLANTs in plant-31440.php.
 */

require __DIR__ . '/wp-kit.php';
require __DIR__ . '/text-inbox-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $ok ) { global $fails, $ran; $ran++; if ( ! $ok ) { $fails[] = $label; } }
function rt_cap( $fn ) {
    $d = ob_get_level();
    ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; }
    return $h;
}
function rt_x( $html ) {
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    libxml_clear_errors();
    return new DOMXPath( $doc );
}
function rt_text( $n ) { return trim( preg_replace( '/\s+/', ' ', $n ? $n->textContent : '' ) ); }


$in = function ( $from, $body ) { return SFAF_Texts::answer( $from, $body ); };

/* =====================================================================
 * R. Recording
 * =================================================================== */
$db = ri_fresh();
// Current time in the kit is the real clock; the rows take the hand clock.
SFAF_Texts::send_confirmation( $db->rsvps[0] );
rt( 'R: a confirmation is a row when it goes, kind confirmation, by nobody', 1 === count( $db->msgs ) && 'out' === $db->msgs[0]->direction && 'confirmation' === $db->msgs[0]->kind && 0 === (int) $db->msgs[0]->by_user && '+14155550101' === $db->msgs[0]->phone && 900 === (int) $db->msgs[0]->event_id && 1 === (int) $db->msgs[0]->rsvp_id );
rt( 'R: its body is the text as sent', 0 === strpos( $db->msgs[0]->body, "SFAF: You're registered for Coffee & Conversation" ) );
rt( 'R: its Twilio id is kept', 'SM1' === $db->msgs[0]->twilio_sid );
SFAF_Texts::send_reminders( 900 );
$kinds = array_map( function ( $r ) { return $r->kind . ':' . $r->channel; }, $db->msgs );
rt( 'R: the reminders are rows, by text and by WhatsApp: ' . json_encode( $kinds ), in_array( 'reminder:sms', $kinds, true ) && in_array( 'reminder:whatsapp', $kinds, true ) );
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7 );
$ups = array_values( array_filter( $db->msgs, function ( $r ) { return 'update' === $r->kind; } ) );
rt( 'R: a Text registrants message is a row for each person, sent by its sender', 2 === count( $ups ) && 7 === (int) $ups[0]->by_user && 7 === (int) $ups[1]->by_user );
$GLOBALS['tw_answer'] = array( 'code' => 400, 'body' => '{"message":"no"}' );
$before = count( $db->msgs );
SFAF_Texts::send_update( 900, 'This fails', 7 );
rt( 'R: a message Twilio refused is not a row', $before === count( $db->msgs ) );
$GLOBALS['tw_answer'] = array( 'code' => 201, 'body' => '{"sid":"SM2"}' );

$out_before = count( $db->msgs );
foreach ( array( 'STOP', 'START', 'HELP', 'stop', 'Ayuda' ) as $w ) { $in( '+14155550101', $w ); $in( 'whatsapp:+14155550102', $w ); }
rt( 'R: STOP, START and HELP are never rows', $out_before === count( $db->msgs ) );
rt( 'R: and schedule nothing', array() === $GLOBALS['ri_cron'] );
$in( '+14155550101', 'Is there parking?' );
$r = $db->msgs[ count( $db->msgs ) - 1 ];
rt( 'R: a reply is a row: in, its words, its number and channel', 'in' === $r->direction && 'Is there parking?' === $r->body && '+14155550101' === $r->phone && 'sms' === $r->channel );
$last_out = SFAF_Text_Inbox::last_sent( '+14155550101' );
rt( 'R: against the last message sent to the number, its event and registration', (int) $r->reply_to === (int) $last_out->id && 'update' === $last_out->kind && 900 === (int) $r->event_id && 1 === (int) $r->rsvp_id );
$in( '+14155559999', 'Who is this?' );
$s = $db->msgs[ count( $db->msgs ) - 1 ];
rt( 'R: a stranger\'s reply is kept, with no event', 'in' === $s->direction && 0 === (int) $s->event_id && 0 === (int) $s->reply_to );
$in( '+14155550103', 'Hola' );
$c = $db->msgs[ count( $db->msgs ) - 1 ];
rt( 'R: a registrant nothing was sent to: their latest registration\'s event', 901 === (int) $c->event_id && 3 === (int) $c->rsvp_id && 0 === (int) $c->reply_to );

/* =====================================================================
 * A. The alert
 * =================================================================== */
$db = ri_fresh();
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7 );
$in( '+14155550101', 'Is there parking?' );
/* 3.144.1: the first reply is emailed at once and opens the window; the
   window's follow-up carries the rest. release-31441-test.php has the timing. */
rt( 'A: one run scheduled for the number, five minutes on', 1 === count( $GLOBALS['ri_cron'] ) && SFAF_Text_Inbox::ALERT_HOOK === $GLOBALS['ri_cron'][0]['hook'] && array( '+14155550101' ) === $GLOBALS['ri_cron'][0]['args'] && abs( $GLOBALS['ri_cron'][0]['at'] - ( time() + 300 ) ) <= 2 );
rt( 'A: the first reply is emailed straight away (3.144.1)', 1 === count( $GLOBALS['ri_mail'] ) );
$in( '+14155550101', 'And is it step-free?' );
$in( '+14155550101', 'Thanks' );
rt( 'A: further replies schedule no second run', 1 === count( $GLOBALS['ri_cron'] ) );
rt( 'A: and wait for the follow-up', 1 === count( $GLOBALS['ri_mail'] ) );
$m = $GLOBALS['ri_mail'][0];
rt( 'A: to the staff member who sent the message: ' . $m['to'], 'sam@sfaf.org' === $m['to'] );
rt( 'A: the subject names them and the event: ' . $m['subject'], 'Reply from Ana Ruiz: Coffee & Conversation' === $m['subject'] );
foreach ( array( 'Ana Ruiz', '(415) 555-0101', 'Is there parking?', 'Open the RSVP page', 'replies=1', 'event_id=900', '#uc-replies', 'text (415) 555-0101 from your own phone' ) as $want ) {
    rt( "A: the email has \"$want\"", false !== strpos( html_entity_decode( $m['body'], ENT_QUOTES ), $want ) );
}
SFAF_Text_Inbox::follow_up( '+14155550101' );
rt( 'A: one email for the two after it', 2 === count( $GLOBALS['ri_mail'] ) );
$m = $GLOBALS['ri_mail'][1];
rt( 'A: the follow-up\'s subject names them, the count and the event: ' . $m['subject'], '2 more replies from Ana Ruiz: Coffee & Conversation' === $m['subject'] && 'sam@sfaf.org' === $m['to'] );
foreach ( array( 'And is it step-free?', 'Thanks', 'Open the RSVP page' ) as $want ) {
    rt( "A: the follow-up has \"$want\"", false !== strpos( html_entity_decode( $m['body'], ENT_QUOTES ), $want ) );
}
rt( 'A: the replies in the order they came', strpos( $m['body'], 'And is it step-free?' ) < strpos( $m['body'], 'Thanks' ) && false === strpos( $m['body'], 'Is there parking?' ) );
SFAF_Text_Inbox::alert( '+14155550101' );
rt( 'A: a second run sends nothing again', 2 === count( $GLOBALS['ri_mail'] ) );

$db = ri_fresh();
SFAF_Texts::send_confirmation( $db->rsvps[0] );
$in( '+14155550101', 'See you there' );
SFAF_Text_Inbox::alert( '+14155550101' );
rt( 'A: a reply to the confirmation goes to Replies go to', 1 === count( ri_mail_to( 'events@sfaf.org' ) ) );
rt( 'A: one reply: "Reply from"', 'Reply from Ana Ruiz: Coffee & Conversation' === $GLOBALS['ri_mail'][0]['subject'] );

$db = ri_fresh();
SFAF_Texts::send_reminders( 900 );
$in( 'whatsapp:+14155550102', 'Llego tarde' );
SFAF_Text_Inbox::alert( '+14155550102' );
rt( 'A: a reply to the reminder goes to Replies go to', 1 === count( ri_mail_to( 'events@sfaf.org' ) ) );
rt( 'A: by WhatsApp, said so, with WhatsApp in the own-phone line', false !== strpos( $GLOBALS['ri_mail'][0]['body'], 'replied by WhatsApp' ) && false !== strpos( html_entity_decode( $GLOBALS['ri_mail'][0]['body'], ENT_QUOTES ), 'message (415) 555-0102 on WhatsApp from your own phone' ) );

$db = ri_fresh();
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 99 );
$in( '+14155550101', 'ok' );
SFAF_Text_Inbox::alert( '+14155550101' );
rt( 'A: a sender whose account has gone: Replies go to', 1 === count( ri_mail_to( 'events@sfaf.org' ) ) );

$db = ri_fresh();
$in( '+14155559999', 'Who is this?' );
SFAF_Text_Inbox::alert( '+14155559999' );
rt( 'A: a stranger: the site default', 1 === count( ri_mail_to( 'calendar@sfaf.org' ) ) );
rt( 'A: with no RSVP page to link, said so', false === strpos( $GLOBALS['ri_mail'][0]['body'], 'Open the RSVP page' ) && false !== strpos( $GLOBALS['ri_mail'][0]['body'], 'matches no registration' ) );

/* Overdue: the run never came. */
$db = ri_fresh();
SFAF_Texts::send_confirmation( $db->rsvps[0] );
$in( '+14155550101', 'First' );
$db->msgs[ count( $db->msgs ) - 1 ]->created_at = date( 'Y-m-d H:i:s', time() - 900 );
$in( '+14155559999', 'Someone else' );
rt( 'A: an overdue reply is sent when the next one comes', 1 === count( ri_mail_to( 'events@sfaf.org' ) ) && false !== strpos( $GLOBALS['ri_mail'][0]['body'], 'First' ) );

/* =====================================================================
 * Y. The auto-reply
 * =================================================================== */
$db = ri_fresh();
$a = $in( '+14155550101', 'Is there parking?' );
rt( "Y: Mark's line by text, with SFAF: and the STOP line: $a", 'SFAF: Thanks, your message has been passed to the event organizer. Reply STOP to opt out.' === $a );
rt( 'Y: once a day per number', '' === $in( '+14155550101', 'Hello?' ) );
$es = $in( 'whatsapp:+14155550102', 'Hola' );
rt( "Y: in Spanish by WhatsApp, as written: $es", 'Gracias, su mensaje se ha enviado al organizador del evento.' === $es );
rt( 'Y: the old line is gone', false === strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-texts.php' ), "This number isn't monitored" ) );

/* =====================================================================
 * O. Answering from the RSVP page
 * =================================================================== */
$db = ri_fresh();
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7 );
$in( '+14155550101', 'Is there parking?' );
$GLOBALS['tw_calls'] = array();
$r = SFAF_Text_Inbox::reply( 900, '+14155550101', 'Yes, on Market St', 8 );
$b = isset( $GLOBALS['tw_calls'][0] ) ? $GLOBALS['tw_calls'][0]['args']['body'] : array();
rt( 'O: by text, through the Messaging Service: ' . json_encode( $r ), $r['ok'] && 1 === count( $GLOBALS['tw_calls'] ) && 'MG' . str_repeat( 'b', 32 ) === $b['MessagingServiceSid'] && '+14155550101' === $b['To'] );
rt( 'O: with SFAF: and the STOP line: ' . ( isset( $b['Body'] ) ? $b['Body'] : '' ), 'SFAF: Yes, on Market St Reply STOP to opt out.' === $b['Body'] );
rt( 'O: said, naming them and how: ' . $r['message'], 'Sent to Ana Ruiz by text.' === $r['message'] );
$o = $db->msgs[ count( $db->msgs ) - 1 ];
rt( 'O: the answer is a row, kind organizer, by its sender', 'out' === $o->direction && 'organizer' === $o->kind && 8 === (int) $o->by_user && 900 === (int) $o->event_id && 1 === (int) $o->rsvp_id );
$GLOBALS['ri_mail'] = array();
$in( '+14155550101', 'Thanks!' );
SFAF_Text_Inbox::alert( '+14155550101' );
rt( 'O: a reply to the answer goes to whoever answered', 1 === count( ri_mail_to( 'lee@sfaf.org' ) ) );

SFAF_Texts::send_reminders( 900 );
$in( 'whatsapp:+14155550102', 'Llego tarde' );
$GLOBALS['tw_calls'] = array();
$r = SFAF_Text_Inbox::reply( 900, '+14155550102', 'No problem', 8 );
$b = $GLOBALS['tw_calls'][0]['args']['body'];
rt( 'O: WhatsApp within 24 hours: as typed', $r['ok'] && 'whatsapp:+14155550102' === $b['To'] && 'whatsapp:+14155550100' === $b['From'] && 'No problem' === $b['Body'] && ! isset( $b['ContentSid'] ) );
foreach ( $db->msgs as $m ) { if ( 'in' === $m->direction && '+14155550102' === $m->phone ) { $m->created_at = date( 'Y-m-d H:i:s', time() - 86400 - 60 ); } }
$GLOBALS['tw_calls'] = array();
$r = SFAF_Text_Inbox::reply( 900, '+14155550102', 'Still here', 8 );
$b = $GLOBALS['tw_calls'][0]['args']['body'];
$v = json_decode( $b['ContentVariables'], true );
rt( 'O: WhatsApp after 24 hours: the Spanish update template', $r['ok'] && ri_hx( 'f' ) === $b['ContentSid'] && ! isset( $b['Body'] ) );
rt( 'O: its variables: the event, the answer, Replies go to: ' . $b['ContentVariables'], 'Coffee & Conversation' === $v['1'] && 'Still here.' === $v['2'] && 'events@sfaf.org' === $v['3'] );
rt( 'O: said so: ' . $r['message'], 'Sent to Beto Luna by WhatsApp, as an event update.' === $r['message'] );

$db2 = ri_fresh( false );
SFAF_Texts::send_confirmation( $db2->rsvps[0] );
$db2->msgs[] = (object) array( 'id' => 50, 'event_id' => 900, 'rsvp_id' => 2, 'phone' => '+14155550102', 'channel' => 'whatsapp', 'direction' => 'in', 'kind' => 'reply', 'body' => 'hi', 'by_user' => 0, 'reply_to' => 0, 'twilio_sid' => '', 'alerted' => 1, 'created_at' => date( 'Y-m-d H:i:s', time() - 90000 ) );
$GLOBALS['tw_calls'] = array();
$r = SFAF_Text_Inbox::reply( 900, '+14155550102', 'Hello', 8 );
rt( 'O: WhatsApp off, past the window: nothing sent, said why: ' . $r['message'], ! $r['ok'] && array() === $GLOBALS['tw_calls'] && false !== strpos( $r['message'], "WhatsApp isn't active yet" ) );

$db = ri_fresh();
$in( '+14155550101', 'Hi' );
SFAF_Texts::record_stop( '+14155550101', 'sms', 'STOP' );
$GLOBALS['tw_calls'] = array();
$r = SFAF_Text_Inbox::reply( 900, '+14155550101', 'Hello', 8 );
rt( 'O: never to a stopped number', ! $r['ok'] && array() === $GLOBALS['tw_calls'] && false !== strpos( $r['message'], 'STOP' ) );
$r = SFAF_Text_Inbox::reply( 900, '+14155550199', 'Hello', 8 );
rt( 'O: never to a number that has not replied about this event', ! $r['ok'] && array() === $GLOBALS['tw_calls'] );
$r = SFAF_Text_Inbox::reply( 901, '+14155550101', 'Hello', 8 );
rt( 'O: nor about another event', ! $r['ok'] && array() === $GLOBALS['tw_calls'] );
SFAF_Texts::clear_stop( '+14155550101' );
$r = SFAF_Text_Inbox::reply( 900, '+14155550101', '   ', 8 );
rt( 'O: an empty answer sends nothing', ! $r['ok'] && array() === $GLOBALS['tw_calls'] && 'Write the reply first.' === $r['message'] );

/* =====================================================================
 * P. The section
 * =================================================================== */
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$me     = new WP_User();
$me->ID = 1;
$db = ri_fresh();
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7 );
$in( '+14155550101', 'Is there parking?' );
SFAF_Text_Inbox::reply( 900, '+14155550101', 'Yes, on Market St', 8 );
SFAF_Texts::send_reminders( 900 );
$in( 'whatsapp:+14155550102', 'Llego tarde' );
$html = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } );
rt( 'P: the section renders', false === strpos( $html, 'THREW' ) && false !== strpos( $html, 'data-uc-text-replies' ) );
$x = rt_x( $html );
rt( 'P: it is the Text registrants component, headed Replies', 1 === $x->query( '//details[@id="uc-replies" and contains(@class,"uc-card") and contains(@class,"uc-rsvp-settings")]' )->length && 'Replies' === rt_text( $x->query( '//summary//strong' )->item( 0 ) ) );
rt( 'P: "2 people replied by text or WhatsApp."', '2 people replied by text or WhatsApp.' === rt_text( $x->query( '//*[@data-uc-replies-count]' )->item( 0 ) ) );
$heads = array(); foreach ( $x->query( '//section[@data-uc-reply-thread]/h3' ) as $h ) { $heads[] = rt_text( $h ); }
rt( 'P: one thread a person, named from the registration, newest first: ' . json_encode( $heads ), array( 'Beto Luna', 'Ana Ruiz' ) === $heads );
$ana = $x->query( '//section[@data-uc-reply-thread="+14155550101"]' )->item( 0 );
$msgs = array(); foreach ( $x->query( './/li[@data-uc-reply-msg]', $ana ) as $li ) { $msgs[] = $li->getAttribute( 'data-uc-reply-msg' ) . ':' . rt_text( $x->query( './/*[contains(@class,"uc-reply-body")]', $li )->item( 0 ) ); }
rt( 'P: the reply and the answer, in order, nothing else sent to them: ' . json_encode( $msgs ), array( 'in:Is there parking?', 'out:SFAF: Yes, on Market St Reply STOP to opt out.' ) === $msgs );
rt( 'P: the answer says who sent it', false !== strpos( rt_text( $x->query( './/li[@data-uc-reply-msg="out"]//*[contains(@class,"uc-reply-meta")]', $ana )->item( 0 ) ), 'Lee Lead' ) );
rt( 'P: the number and the channel', '(415) 555-0101, Text' === rt_text( $x->query( './/*[contains(@class,"uc-reply-who")]', $ana )->item( 0 ) ) );
$form = $x->query( './/form[@data-uc-reply-form]', $ana )->item( 0 );
rt( 'P: a box to answer, posting text_reply_send with the number and the event', $form && 1 === $x->query( './/input[@name="uc_action" and @value="text_reply_send"]', $form )->length
    && 1 === $x->query( './/input[@name="reply_phone" and @value="+14155550101"]', $form )->length && 1 === $x->query( './/input[@name="event_id" and @value="900"]', $form )->length
    && 1 === $x->query( './/textarea[@name="reply_message" and @required]', $form )->length && 1 === $x->query( './/input[@name="uc_nonce"]', $form )->length );
rt( 'P: the text hint', false !== strpos( rt_text( $x->query( './/*[@data-uc-reply-hint]', $ana )->item( 0 ) ), 'by text as "SFAF:"' ) );
$beto = $x->query( '//section[@data-uc-reply-thread="+14155550102"]' )->item( 0 );
rt( 'P: WhatsApp within the window: as typed', 'It arrives by WhatsApp as typed.' === rt_text( $x->query( './/*[@data-uc-reply-hint]', $beto )->item( 0 ) ) );
rt( 'P: Send asks first, in green', 1 === $x->query( './/button[@data-uc-reply-send and contains(@class,"uc-btn-go") and @data-uc-confirm]', $ana )->length );
foreach ( $db->msgs as $m ) { if ( 'in' === $m->direction && '+14155550102' === $m->phone ) { $m->created_at = date( 'Y-m-d H:i:s', time() - 86400 - 60 ); } }
SFAF_Texts::record_stop( '+14155550101', 'sms', 'STOP' );
$x = rt_x( rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } ) );
rt( 'P: past the window: the update template, said so', false !== strpos( rt_text( $x->query( '//section[@data-uc-reply-thread="+14155550102"]//*[@data-uc-reply-hint]' )->item( 0 ) ), 'over 24 hours ago, so this goes as an event update: "Update about Coffee & Conversation:"' ) );
rt( 'P: a stopped number: no box, said so', 0 === $x->query( '//section[@data-uc-reply-thread="+14155550101"]//form' )->length && 1 === $x->query( '//section[@data-uc-reply-thread="+14155550101"]//*[@data-uc-reply-stopped]' )->length );
$GLOBALS['kit_trans'][ 'sfaf_reply_said_' . $me->ID ] = array( 'ok' => true, 'message' => 'Sent to Ana Ruiz by text.' );
$x = rt_x( rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } ) );
rt( 'P: after a send: open, and what happened', 1 === $x->query( '//details[@id="uc-replies" and @open]' )->length && 'Sent to Ana Ruiz by text.' === rt_text( $x->query( '//*[@data-uc-reply-said]' )->item( 0 ) ) );
$db = ri_fresh();
$x = rt_x( rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } ) );
rt( 'P: texts on and no replies: the section says so', 1 === $x->query( '//*[@data-uc-replies-none]' )->length && 'Nobody has replied by text or WhatsApp.' === rt_text( $x->query( '//*[@data-uc-replies-count]' )->item( 0 ) ) );
kit_reset();
$GLOBALS['wpdb'] = new Ri_Db();
kit_write_post( array( 'ID' => 900, 'post_title' => 'Coffee', 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
$h = rt_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } );
rt( 'P: Twilio off and no replies: no section', '' === trim( $h ) );

/* =====================================================================
 * W. The wiring
 * =================================================================== */
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/sfaf-calendar.php' );
rt( 'W: schema 20', false !== strpos( $main, "define( 'SFAF_DB_VERSION', '20' );" ) );
rt( 'W: the table, with what a thread and an alert need', (bool) preg_match( '/\$msgs\s*=\s*\$wpdb->prefix \. \'uc_text_messages\';\s*\$sql\[\] = "CREATE TABLE \$msgs \((.*?)\) \$charset;";/s', $main, $tm )
    && 12 === count( array_filter( array( 'id bigint', 'event_id bigint', 'rsvp_id bigint', 'phone varchar', 'channel varchar', 'direction varchar', 'kind varchar', 'body text', 'by_user bigint', 'reply_to bigint', 'twilio_sid varchar', 'alerted bigint' ), function ( $c ) use ( $tm ) { return false !== strpos( $tm[1], $c ); } ) )
    && false !== strpos( $tm[1], 'KEY phone_dir (phone, direction)' ) && false !== strpos( $tm[1], 'KEY event_id (event_id)' ) );
rt( 'W: the inbox is loaded and registered', false !== strpos( $main, "class-sfaf-text-inbox.php" ) && false !== strpos( $main, 'SFAF_Text_Inbox::register();' ) );
$pt = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
rt( 'W: the route asks the event gate', (bool) preg_match( "/case 'text_reply_send':\s*\\\$event_id = [^;]*;\s*\\\$post\s*= [^;]*;\s*if \( ! \\\$post \|\| 'uc_event' !== \\\$post->post_type \|\| ! \\\$this->can_edit_event\( \\\$user, \\\$post \) \) \{\s*wp_die\( 'Denied' \);/", $pt ) );
rt( 'W: the section beside Text registrants, for whoever may edit the event', false !== strpos( $pt, '$this->render_text_registrants( $user, $event_id ); $this->render_text_replies( $user, $event_id ); }' ) );
$tx = file_get_contents( $root . '/includes/class-sfaf-texts.php' );
rt( 'W: a reply is recorded after STOP, START and HELP have been dealt with', ( $p1 = strpos( $tx, 'self::HELP_WORDS, true ) ) {' ) ) && ( $p2 = strpos( $tx, 'SFAF_Text_Inbox::received(' ) ) && $p2 > $p1 );

echo "\n3.144.0: the text replies inbox\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran FAILED:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran checks passed.\n";
exit( 0 );
