<?php
/**
 * THE 3.144.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31440.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in each part of the text replies inbox (the
 * recording, who is told, the five minutes, the auto-reply, answering, the
 * section, the wiring, the embed) and runs the check that must catch it.
 * Every file is put back from memory. Commit first, and never run this while
 * run-all.sh is running.
 */

$root = dirname( __DIR__ );
$IB = 'includes/class-sfaf-text-inbox.php';
$TX = 'includes/class-sfaf-texts.php';
$PT = 'includes/class-sfaf-portal.php';
$PC = 'public/css/portal.css';
$CJ = 'public/js/calendar.js';
$T  = 'release-31440-test.php';
$L  = 'release-31440-live.php --run';
$I  = 'embed-identity-test.php';

$plants = array(
    'a sent message is not recorded' => array( $T, 'R: a confirmation is a row when it goes', array(
        array( $TX, "        if ( \$r['ok'] ) {\n            SFAF_Text_Inbox::sent(", "        if ( false ) {\n            SFAF_Text_Inbox::sent(" ),
    ) ),
    'STOP recorded as a reply' => array( $T, 'R: STOP, START and HELP are never rows', array(
        array( $TX, "        \$row  = self::latest_registration( \$phone );\n", "        \$row  = self::latest_registration( \$phone );\n        SFAF_Text_Inbox::received( \$phone, \$channel, \$body, \$sid );\n" ),
    ) ),
    'a reply not tied to what it answers' => array( $T, 'R: against the last message sent to the number', array(
        array( $IB, "            'reply_to'   => \$last ? (int) \$last->id : 0,", "            'reply_to'   => 0," ),
    ) ),
    'every reply to Replies go to' => array( $T, 'A: to the staff member who sent the message', array(
        array( $IB, "in_array( (string) \$out->kind, array( 'update', 'organizer' ), true )", "in_array( (string) \$out->kind, array(), true )" ),
    ) ),
    'one email per reply' => array( $T, 'A: one email for the three replies', array(
        array( $IB, "        if ( ! wp_next_scheduled( self::ALERT_HOOK, array( \$phone ) ) ) {\n            wp_schedule_single_event( time() + self::BUNDLE, self::ALERT_HOOK, array( \$phone ) );\n        }",
            "        self::alert( \$phone );" ),
    ) ),
    'an alert sent twice' => array( $T, 'A: a second run sends nothing again', array(
        array( $IB, "\" WHERE phone = %s AND direction = 'in' AND alerted = %d ORDER BY id ASC\",", "\" WHERE phone = %s AND direction = 'in' AND alerted > %d ORDER BY id ASC\"," ),
    ) ),
    'no link to the RSVP page' => array( $T, 'A: the email has "Open the RSVP page"', array(
        array( $IB, "            \$html .= SFAF_Email::button( \$link, 'Open the RSVP page' );\n", '' ),
    ) ),
    'an overdue reply waits' => array( $T, 'A: an overdue reply is sent when the next one comes', array(
        array( $IB, "        self::send_overdue();\n", '' ),
    ) ),
    'the old auto-reply' => array( $T, "Y: Mark's line by text", array(
        array( $TX, ": 'Thanks, your message has been passed to the event organizer.';", ": \"This number isn't monitored.\";" ),
    ) ),
    'an auto-reply every time' => array( $T, 'Y: once a day per number', array(
        array( $TX, "        if ( get_transient( \$key ) ) {\n            return '';\n        }\n", '' ),
    ) ),
    'a text answer without the STOP line' => array( $T, 'O: with SFAF: and the STOP line', array(
        array( $IB, "            \$body = SFAF_Texts::sms( \$message, \$lang );", "            \$body = \$message;" ),
    ) ),
    'WhatsApp free text after 24 hours' => array( $T, 'O: WhatsApp after 24 hours: the Spanish update template', array(
        array( $IB, ") < self::WINDOW;", ") < PHP_INT_MAX;" ),
    ) ),
    'an answer to a stopped number' => array( $T, 'O: never to a stopped number', array(
        array( $IB, "        if ( SFAF_Texts::is_stopped( \$phone ) ) {\n            return array( 'ok' => false, 'message' => 'Nothing was sent: this number sent STOP.' );\n        }\n", '' ),
    ) ),
    'threads named by number' => array( $T, 'P: one thread a person, named from the registration', array(
        array( $IB, "                \$n = trim( (string) SFAF_RSVP::display_name( \$reg ) );", "                \$n = '';" ),
    ) ),
    'a stopped number still offered a box' => array( $T, 'P: a stopped number: no box', array(
        array( $PT, "                        <?php if ( \$stopped ) : ?>", "                        <?php if ( false ) : ?>" ),
    ) ),
    'the section not on the page' => array( $T, 'W: the section beside Text registrants', array(
        array( $PT, "\$this->render_text_registrants( \$user, \$event_id ); \$this->render_text_replies( \$user, \$event_id ); }", "\$this->render_text_registrants( \$user, \$event_id ); }" ),
    ) ),
    'the route without the gate' => array( $T, 'W: the route asks the event gate', array(
        array( $PT, "            case 'text_reply_send':\n                \$event_id = isset( \$_POST['event_id'] ) ? (int) \$_POST['event_id'] : 0;\n                \$post     = \$event_id ? get_post( \$event_id ) : null;\n                if ( ! \$post || 'uc_event' !== \$post->post_type || ! \$this->can_edit_event( \$user, \$post ) ) {",
            "            case 'text_reply_send':\n                \$event_id = isset( \$_POST['event_id'] ) ? (int) \$_POST['event_id'] : 0;\n                \$post     = \$event_id ? get_post( \$event_id ) : null;\n                if ( ! \$post || 'uc_event' !== \$post->post_type ) {" ),
    ) ),
    'a long link runs off the card, in Chrome' => array( $L, 'PLANT T:', array(
        array( $PC, ".uc-text-replies .uc-reply-body { display: block; white-space: pre-wrap; overflow-wrap: anywhere; color: var(--p-text); }", ".uc-text-replies .uc-reply-body { display: block; white-space: pre; color: var(--p-text); }" ),
    ) ),
    'the answers not set in, in Chrome' => array( $L, 'PLANT T:', array(
        array( $PC, ".uc-text-replies .uc-reply-out { margin-left: 24px; }\n", '' ),
    ) ),
    'Send not green, in Chrome' => array( $L, 'PLANT T:', array(
        array( $PT, "                                    <button type=\"submit\" class=\"uc-btn uc-btn-go\" data-uc-reply-send", "                                    <button type=\"submit\" class=\"uc-btn\" data-uc-reply-send" ),
    ) ),
    'a public file changes' => array( $I, 'differs from 3.143.2', array(
        array( $CJ, " * SFAF Calendar Frontend JS\n", " * SFAF Calendar Frontend JS \n" ),
    ) ),
);
$missed = 0;
$only = isset( $argv[1] ) ? $argv[1] : '';
foreach ( $plants as $name => $p ) {
    if ( '' !== $only && false === strpos( $name, $only ) ) { continue; }
    list( $check, $want, $edits ) = $p;
    $saved = array();
    foreach ( $edits as $e ) {
        list( $file, $from, $to ) = $e;
        $path = $root . '/' . $file;
        if ( ! isset( $saved[ $path ] ) ) { $saved[ $path ] = file_get_contents( $path ); }
        $now = file_get_contents( $path );
        if ( 1 !== substr_count( $now, $from ) ) {
            foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
            echo "ANCHOR NOT UNIQUE for \"$name\" in $file (" . substr_count( $now, $from ) . ")\n";
            exit( 1 );
        }
        file_put_contents( $path, str_replace( $from, $to, $now ) );
    }
    $parts = explode( ' ', $check, 2 );
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $parts[0] ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
