<?php
/**
 * THE 3.132.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31320.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in WhatsApp's consent line or the Spanish in
 * usted, and runs the check that must catch it. Every file is put back from
 * memory. Commit first, and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$X    = 'includes/class-sfaf-texts.php';
$T    = 'release-31320-test.php';
$L    = 'release-31310-live.php';
$C    = 'public/js/calendar.js';
$M    = 'includes/class-sfaf-messages.php';

$plants = array(
    'WhatsApp shows the Text line again' => array( $L, 'PLANT H:', array(
        array( $C, "        \$('#uc-rsvp-sms-consent').prop('hidden', textChannel() === 'whatsapp');\n        \$('#uc-rsvp-wa-consent').prop('hidden', textChannel() !== 'whatsapp');", "" ),
    ) ),
    'both lines show at once' => array( $L, 'PLANT H:', array(
        array( $C, "        \$('#uc-rsvp-sms-consent').prop('hidden', textChannel() === 'whatsapp');", "        \$('#uc-rsvp-sms-consent').prop('hidden', false);" ),
    ) ),
    "the WhatsApp line is not Mark's" => array( $T, "FAIL  A: WhatsApp's consent line in Mark's English", array(
        array( $M, "array( 'Send me WhatsApp updates about this event. Message frequency varies. Data rates may apply.", "array( 'Send me WhatsApp updates about this event. Message frequency varies. Msg & data rates may apply." ),
    ) ),
    "the Spanish WhatsApp line is not Mark's" => array( $T, "FAIL  A: WhatsApp's consent line in Mark's Spanish", array(
        array( $M, "'Envíenme avisos de este evento por WhatsApp. La frecuencia de los mensajes varía. Pueden aplicarse cargos por datos.", "'Envíenme avisos de este evento por WhatsApp. La frecuencia de los mensajes varía. Pueden aplicarse tarifas de datos." ),
    ) ),
    'the WhatsApp line is gone from the form' => array( $L, 'PLANT H:', array(
        array( $C, "data-uc-w=\"wa_consent\" hidden>", "data-uc-w=\"text_consent\" hidden>" ),
    ) ),
    'the Text line changes too' => array( $T, 'FAIL  A: the Text line stays as it was', array(
        array( $M, "'Text me updates about this event. Message frequency varies. Msg & data rates may apply.", "'Send me text updates about this event. Message frequency varies. Msg & data rates may apply." ),
    ) ),
    'the cancel line is back in tú' => array( $T, 'FAIL  B: the Spanish reminder text cancels in usted', array(
        array( $X, "' ¿No puede asistir? Cancele aquí: %s'", "' ¿No puedes venir? Cancela aquí: %s'" ),
    ) ),
    'the form error is back in tú' => array( $L, 'PLANT H:', array(
        array( $M, "                'f_need_phone'     => array( 'Enter a phone number to get updates by text or WhatsApp.', 'Escriba", "                'f_need_phone'     => array( 'Enter a phone number to get updates by text or WhatsApp.', 'Escribe" ),
    ) ),
    'the server error is back in tú' => array( $T, 'FAIL  B: the server says the error in usted', array(
        array( $M, "                'e_need_phone'     => array( 'Enter a phone number to get updates by text or WhatsApp.', 'Escriba", "                'e_need_phone'     => array( 'Enter a phone number to get updates by text or WhatsApp.', 'Escribe" ),
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
