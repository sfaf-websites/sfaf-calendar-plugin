<?php
/**
 * 3.132.0: WHATSAPP'S OWN CONSENT LINE, AND THE SPANISH IN USTED.
 *
 *     php .claude/release-31320-test.php
 *
 *   A. The RSVP form's consent line, in Mark's words: WhatsApp ticked shows
 *      "Send me WhatsApp updates about this event. ..." and its Spanish; Text
 *      ticked keeps "Text me updates about this event. ..." as it was. Both
 *      lines are in the form's words and in calendar.js, and syncTexts()
 *      shows only the ticked box's line. The script half is
 *      release-31310-live.php (PLANT H).
 *   B. The Spanish in usted: the reminder text's cancel line "¿No puede
 *      asistir? Cancele aquí: [link]", and the error for a ticked box with no
 *      phone "Escriba un número de teléfono ..." from the form and the server.
 *      English unchanged.
 *
 * PLANTs in plant-31320.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $ok ) {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) { $fails[] = $label; }
}

$fw = SFAF_Messages::form_words();
$js = (string) file_get_contents( dirname( __DIR__ ) . '/public/js/calendar.js' );

/* ---- A. The consent lines ---- */
$wa_en = 'Send me WhatsApp updates about this event. Message frequency varies. Data rates may apply. Reply STOP to opt out, HELP for help.';
$wa_es = 'Envíenme avisos de este evento por WhatsApp. La frecuencia de los mensajes varía. Pueden aplicarse cargos por datos. Responda STOP para cancelar, HELP para ayuda.';
$sms_en = 'Text me updates about this event. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help.';
$sms_es = 'Envíenme novedades sobre este evento. La frecuencia de los mensajes varía. Pueden aplicarse tarifas de mensajes y datos. Responda STOP para cancelar, HELP para obtener ayuda.';
rt( "A: WhatsApp's consent line in Mark's English", isset( $fw['en']['wa_consent'] ) && $wa_en === $fw['en']['wa_consent'] );
rt( "A: WhatsApp's consent line in Mark's Spanish", isset( $fw['es']['wa_consent'] ) && $wa_es === $fw['es']['wa_consent'] );
rt( 'A: the Text line stays as it was, both languages', $sms_en === $fw['en']['text_consent'] && $sms_es === $fw['es']['text_consent'] );
rt( 'A: the form carries both lines', false !== strpos( $js, '<span id="uc-rsvp-sms-consent" data-uc-w="text_consent">' )
    && false !== strpos( $js, '<span id="uc-rsvp-wa-consent" data-uc-w="wa_consent" hidden>' . $wa_en . '</span>' ) );
rt( "A: only the ticked box's line shows", false !== strpos( $js, "\$('#uc-rsvp-sms-consent').prop('hidden', textChannel() === 'whatsapp');" )
    && false !== strpos( $js, "\$('#uc-rsvp-wa-consent').prop('hidden', textChannel() !== 'whatsapp');" ) );

/* ---- B. Usted ---- */
$v = array( 'name' => 'Grupo', 'time' => '10 a. m.', 'place' => '1035 Market St', 'link' => 'https://resources.sfaf.org/events/900/', 'cancel' => 'https://resources.sfaf.org/?uc_rsvp_cancel=abc' );
$es = SFAF_Texts::words( 'reminder', 'es', $v );
$en = SFAF_Texts::words( 'reminder', 'en', $v );
rt( 'B: the Spanish reminder text cancels in usted', false !== strpos( $es, ' ¿No puede asistir? Cancele aquí: https://resources.sfaf.org/?uc_rsvp_cancel=abc' ) && false === strpos( $es, 'puedes' ) && false === strpos( $es, 'Cancela ' ) );
rt( 'B: the English reminder text is unchanged', false !== strpos( $en, " Can't make it? Cancel here: https://resources.sfaf.org/?uc_rsvp_cancel=abc" ) );
$need_es = 'Escriba un número de teléfono para recibir avisos por texto o WhatsApp.';
rt( 'B: the form says the error in usted', $need_es === $fw['es']['need_phone'] );
rt( 'B: the server says the error in usted', $need_es === SFAF_Messages::label( 'e_need_phone', 'es' ) );
rt( 'B: the English error is unchanged', 'Enter a phone number to get updates by text or WhatsApp.' === $fw['en']['need_phone'] && 'Enter a phone number to get updates by text or WhatsApp.' === SFAF_Messages::label( 'e_need_phone', 'en' ) );
rt( 'B: no tú left in the texts or the form', false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-texts.php' ), 'puedes venir' )
    && false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-messages.php' ), 'Escribe un número' ) );

echo "release-31320: $ran checks\n";
if ( $fails ) {
    foreach ( $fails as $f ) { echo "FAIL  $f\n"; }
    exit( 1 );
}
echo "every check passed.\n";
