<?php
/**
 * THE 3.130.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31300.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in what 3.130.0 built, and runs the check
 * that must catch it. Every file is put back from memory. Commit first, and
 * never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$X    = 'includes/class-sfaf-texts.php';
$P    = 'includes/class-sfaf-portal.php';
$R    = 'includes/class-sfaf-reminders.php';
$S    = 'includes/class-sfaf-rsvp.php';
$C    = 'public/js/calendar.js';
$M    = 'includes/class-sfaf-messages.php';
$T    = 'release-31300-test.php';

$plants = array(
    'the token is stored as typed' => array( $T, 'FAIL  A: the token is stored sealed', array(
        array( $X, "            SFAF_Credentials::set( self::TOKEN, SFAF_Mailchimp::seal( \$token ) );", "            SFAF_Credentials::set( self::TOKEN, \$token );" ),
    ) ),
    'an error from Twilio carries the token' => array( $T, 'FAIL  A: an error from Twilio never carries the token', array(
        array( $X, "        return '' !== \$token ? str_replace( \$token, '[token]', \$text ) : \$text;", "        return \$text;" ),
    ) ),
    'the token box shows the token' => array( $T, 'FAIL  A: the token box is a password box', array(
        array( $X, "<input type=\"password\" id=\"uc-tw-token\" name=\"twilio_token\" value=\"\"", "<input type=\"text\" id=\"uc-tw-token\" name=\"twilio_token\" value=\"<?php echo esc_attr( self::token() ); ?>\"" ),
    ) ),
    'WhatsApp goes on without its templates' => array( $T, 'FAIL  A: three templates of four leave WhatsApp off', array(
        array( $X, "            if ( 1 !== preg_match( '/^HX[0-9a-f]{32}\$/i', self::setting( \$key ) ) ) {\n                return false;", "            if ( false ) {\n                return false;" ),
    ) ),
    'a channel is kept without a number' => array( $T, 'FAIL  B: a channel without a number is dropped', array(
        array( $X, "        return ( '' !== trim( (string) \$phone ) && in_array(", "        return ( in_array(" ),
    ) ),
    'the form opens on Text, not Neither' => array( $T, 'FAIL  B: three radios, Neither chosen', array(
        array( $C, "value=\"\" checked /><span data-uc-w=\"by_none\">", "value=\"\" /><span data-uc-w=\"by_none\">" ),
    ) ),
    "the consent line is not Mark's" => array( $T, "FAIL  B: Mark's consent line", array(
        array( $M, "'Text me updates about this event. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help.'", "'Text me updates about this event. Reply STOP to opt out.'" ),
    ) ),
    'the insert loses a format' => array( $T, "FAIL  B: the insert's formats match", array(
        array( $S, "        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );", "        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );" ),
    ) ),
    'a reminder text is not claimed first' => array( $T, 'FAIL  C: a second run sends nothing again', array(
        array( $X, "            \$claim = SFAF_Reminders::claim_key( \$event_id, 'text|' . \$reach['phone'], \$reach['phone'], \$reach['state'] );\n            if ( ! \$claim ) {", "            \$claim = SFAF_Reminders::claim_key( \$event_id, 'text|' . \$reach['phone'] . microtime(), \$reach['phone'], \$reach['state'] );\n            if ( ! \$claim ) {" ),
    ) ),
    'a stopped number still gets messages' => array( $T, 'FAIL  C: two texts go', array(
        array( $X, "        } elseif ( self::is_stopped( \$phone ) ) {", "        } elseif ( false ) {" ),
    ) ),
    'a Spanish registrant gets English' => array( $T, 'FAIL  C: the Spanish reminder for the one who chose Spanish', array(
        array( $X, "        \$l = isset( \$row->lang ) ? (string) \$row->lang : '';", "        \$l = 'en';" ),
    ) ),
    'the reminder run does not text' => array( $T, 'FAIL  F: the reminder run texts after the emails', array(
        array( $R, "            \$result['texts'] = SFAF_Texts::send_reminders( \$event_id );", "            \$result['texts'] = array();" ),
    ) ),
    'Text registrants counts who it cannot reach' => array( $T, 'FAIL  D: the button counts only those it can reach', array(
        array( $P, "        \$to      = count( array_filter( \$plan, function ( \$p ) { return in_array( \$p['state'], array( 'sms', 'whatsapp' ), true ); } ) );", "        \$to      = count( \$plan );" ),
    ) ),
    "WhatsApp isn't active yet is not said" => array( $T, "FAIL  D: the page says WhatsApp isn't active yet", array(
        array( $P, "                <?php if ( \$wa_off ) : ?>\n                    <p class=\"uc-notice\" data-uc-tr-wa-off>", "                <?php if ( false ) : ?>\n                    <p class=\"uc-notice\" data-uc-tr-wa-off>" ),
    ) ),
    'Text registrants is not logged' => array( $T, 'FAIL  D: the send is logged', array(
        array( $X, "        update_post_meta( (int) \$event_id, self::LOG_META, \$log );", "" ),
    ) ),
    'the route skips the event gate' => array( $T, 'FAIL  D: the route asks the event gate', array(
        array( $P, "            case 'text_registrants_send':\n                \$event_id = isset( \$_POST['event_id'] ) ? (int) \$_POST['event_id'] : 0;\n                \$post     = \$event_id ? get_post( \$event_id ) : null;\n                if ( ! \$post || 'uc_event' !== \$post->post_type || ! \$this->can_edit_event( \$user, \$post ) ) {", "            case 'text_registrants_send':\n                \$event_id = isset( \$_POST['event_id'] ) ? (int) \$_POST['event_id'] : 0;\n                \$post     = \$event_id ? get_post( \$event_id ) : null;\n                if ( ! \$post || 'uc_event' !== \$post->post_type ) {" ),
    ) ),
    'PARAR is not a stop word' => array( $T, 'FAIL  E: PARAR on WhatsApp', array(
        array( $X, ", 'OPTOUT', 'PARAR' );", ", 'OPTOUT' );" ),
    ) ),
    'the automatic reply is not once a day' => array( $T, 'FAIL  E: once a day per number', array(
        array( $X, "        if ( get_transient( \$key ) ) {\n            return '';", "        if ( false ) {\n            return '';" ),
    ) ),
    'HELP gets the not-monitored line' => array( $T, 'FAIL  E: HELP is answered', array(
        array( $X, "    const HELP_WORDS  = array( 'HELP', 'INFO', 'AYUDA' );", "    const HELP_WORDS  = array();" ),
    ) ),
    'an unsigned request is answered' => array( $T, 'FAIL  E: an unsigned request is refused', array(
        array( $X, "        if ( '' === (string) \$token || '' === (string) \$sig ) {\n            return false;", "        if ( '' === (string) \$sig ) {\n            return true;" ),
    ) ),
    'the signature sorts nothing' => array( $T, "FAIL  E: Twilio's example signature", array(
        array( $X, "        ksort( \$params, SORT_STRING );", "" ),
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
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
