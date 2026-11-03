<?php
/**
 * THE 3.134.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31340.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the confirmation by text or WhatsApp, the
 * "SFAF:" and STOP wrapper, ended events, registering on a private event or
 * the Terms link, and runs the check that must catch it. Every file is put
 * back from memory. Commit first, and never run this while run-all.sh is
 * running.
 */

$root = dirname( __DIR__ );
$X    = 'includes/class-sfaf-texts.php';
$R    = 'includes/class-sfaf-rsvp.php';
$W    = 'includes/class-sfaf-waitlist.php';
$F    = 'includes/sfaf-template-functions.php';
$P    = 'includes/class-sfaf-portal.php';
$S    = 'templates/single-uc_event.php';
$C    = 'public/js/calendar.js';
$T    = 'release-31340-test.php';
$L    = 'release-31340-live.php';

$plants = array(
    "the confirmation is not in Mark's words" => array( $T, "FAIL  A: the English text in Mark's words", array(
        array( $X, "\"You're registered for %s on %s at %s, %s. Details: %s\"", "\"You're registered for %s on %s at %s at %s. Details: %s\"" ),
    ) ),
    'the Spanish confirmation is not Mark\'s' => array( $T, "FAIL  A: the Spanish text in Mark's words", array(
        array( $X, "'Está registrado para %s el %s a las %s, en %s. Detalles: %s'", "'Estás registrado para %s el %s a las %s, en %s. Detalles: %s'" ),
    ) ),
    'a registration is not texted' => array( $T, 'FAIL  A: a registration that chose Text is texted its confirmation', array(
        array( $R, "            SFAF_Texts::send_confirmation( (object) array(", "            if ( false ) SFAF_Texts::send_confirmation( (object) array(" ),
    ) ),
    'a waitlist place is not texted' => array( $T, 'FAIL  A: a waitlist sign-up is texted once confirmed into the event', array(
        array( $W, "            SFAF_Texts::send_confirmation( \$row );", "" ),
    ) ),
    'the date has no weekday' => array( $T, 'FAIL  A: the date has its weekday', array(
        array( $X, "sfaf_ap_date( \$day, 'full', \$lang )", "sfaf_ap_date( \$day, 'day_year', \$lang )" ),
    ) ),
    '{{6}} is the event page' => array( $T, "FAIL  A: {{6}} is the person's own cancel link", array(
        array( $X, "'5' => \$v['link'], '6' => ! empty( \$v['cancel'] ) ? \$v['cancel'] : \$v['link'] );", "'5' => \$v['link'], '6' => \$v['link'] );" ),
    ) ),
    'the confirmation ignores the site switch' => array( $T, 'FAIL  A: confirmations off for the site, no text', array(
        array( $X, "if ( empty( \$row->event_id ) || ! SFAF_RSVP::confirmations_enabled() || ", "if ( empty( \$row->event_id ) || " ),
    ) ),
    'WhatsApp confirmations go without their two' => array( $T, 'FAIL  B: so nothing goes by WhatsApp', array(
        array( $X, "        if ( 'whatsapp' === \$reach['state'] && 'confirmation' === \$kind && ! self::whatsapp_confirm_on() ) {", "        if ( false ) {" ),
    ) ),
    'the panel lacks the two boxes' => array( $T, 'FAIL  B: the panel has the two confirmation boxes', array(
        array( $X, "<?php foreach ( \$tpl_confirm as \$k => \$label ) :", "<?php foreach ( array() as \$k => \$label ) :" ),
    ) ),
    'one confirmation SID is enough' => array( $T, 'FAIL  B: one of the two is not enough', array(
        array( $X, "            if ( ! preg_match( '/^HX[0-9a-f]{32}\$/i', self::setting( \$key ) ) ) {", "            if ( false ) {" ),
    ) ),
    'a text without "SFAF:"' => array( $T, 'FAIL  C: the English reminder', array(
        array( $X, "            \$text = 'SFAF: ' . \$text;", "" ),
    ) ),
    'the old Spanish ending' => array( $T, 'FAIL  C: in Spanish, "para cancelar"', array(
        array( $X, "    const STOP_LINE_ES = 'Responda STOP para cancelar.';", "    const STOP_LINE_ES = 'Responda STOP para no recibir más.';" ),
    ) ),
    'STOP added twice' => array( $T, 'FAIL  C: never twice', array(
        array( $X, "        if ( \$stop !== substr( \$text, -strlen( \$stop ) ) ) {", "        if ( true ) {" ),
    ) ),
    'the automatic reply on WhatsApp is wrapped' => array( $T, 'FAIL  C: the automatic reply on WhatsApp is unchanged', array(
        array( $X, "        return 'whatsapp' === \$channel ? \$auto : self::sms( \$auto, \$lang );", "        return self::sms( \$auto, \$lang );" ),
    ) ),
    'the automatic reply by text is not wrapped' => array( $T, 'FAIL  C: the automatic reply by text', array(
        array( $X, "        return 'whatsapp' === \$channel ? \$auto : self::sms( \$auto, \$lang );", "        return \$auto;" ),
    ) ),
    'ended ignores the end time' => array( $T, 'FAIL  D: before its end it has not ended', array(
        array( $F, "            \$ends = new DateTime( \$last . ' ' . \$end, \$tz );", "            \$ends = new DateTime( \$date . ' ' . \$start, \$tz );" ),
    ) ),
    'past midnight is the same day' => array( $T, 'FAIL  D: an end past midnight is the next day', array(
        array( $F, "                \$ends->modify( '+1 day' );", "" ),
    ) ),
    'the server takes an ended event' => array( $T, 'FAIL  D: the server refuses an ended event', array(
        array( $R, "        if ( sfaf_event_has_ended( \$data['event_id'] ) ) {", "        if ( false ) {" ),
    ) ),
    'the ended page keeps Register' => array( $L, 'PLANT I:', array(
        array( $S, "echo \$ended ? sfaf_event_ended_block( \$post_id ) : sfaf_rsvp_block( \$post_id );", "echo sfaf_rsvp_block( \$post_id );" ),
    ) ),
    'the ended page keeps Add to Calendar' => array( $T, 'FAIL  E: no Add to Calendar', array(
        array( $S, "( \$ended ? '' : sfaf_add_to_calendar( \$post_id ) )", "sfaf_add_to_calendar( \$post_id )" ),
    ) ),
    'an ended date is listed as upcoming' => array( $T, 'FAIL  E: not this event, and not one that has ended too', array(
        array( $F, "        return (int) \$id !== (int) \$post_id && ! sfaf_event_has_ended( \$id );", "        return (int) \$id !== (int) \$post_id;" ),
    ) ),
    'the dates are listed twice' => array( $T, 'FAIL  E: not listed twice', array(
        array( $S, "                    if ( ! \$ended ) {\n                        echo sfaf_series_list_html( \$post_id );", "                    if ( true ) {\n                        echo sfaf_series_list_html( \$post_id );" ),
    ) ),
    'the dates have no weekday' => array( $L, 'PLANT I:', array(
        array( $F, "sfaf_ap_date( \$d, 'full', \$lang )", "sfaf_ap_date( \$d, 'day_year', \$lang )" ),
    ) ),
    'staff on a draft hear "could not be found"' => array( $T, 'FAIL  F: staff on a draft are told', array(
        array( $R, "                return array( 'success' => false, 'message' => SFAF_Messages::label( 'e_not_live', \$say ) );", "                return array( 'success' => false, 'message' => SFAF_Messages::label( 'e_no_event', \$say ) );" ),
    ) ),
    'a visitor is told the draft exists' => array( $T, 'FAIL  F: anybody else, "could not be found", as before', array(
        array( $R, "class_exists( 'SFAF_Preview' ) && SFAF_Preview::can_preview( \$event ) ) {", "class_exists( 'SFAF_Preview' ) ) {" ),
    ) ),
    'the card does not say when the link works' => array( $T, 'FAIL  F: and says the link works once the event is published', array(
        array( $P, "            <?php if ( \$is_private && SFAF_Preview::not_live( \$event_id ) ) : ?>", "            <?php if ( false ) : ?>" ),
    ) ),
    'no Terms link' => array( $L, 'PLANT H:', array(
        array( $C, "<span aria-hidden=\"true\"> &middot; </span><a id=\"uc-rsvp-terms\"", "<span aria-hidden=\"true\"> &middot; </span><a hidden id=\"uc-rsvp-terms\"" ),
    ) ),
    'Terms goes to the wrong page' => array( $T, 'FAIL  G: the Terms link is in the consent line', array(
        array( $C, "id=\"uc-rsvp-terms\" href=\"https://www.sfaf.org/calendar/events-calendar-terms-conditions/\"", "id=\"uc-rsvp-terms\" href=\"https://www.sfaf.org/terms/\"" ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : '';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
