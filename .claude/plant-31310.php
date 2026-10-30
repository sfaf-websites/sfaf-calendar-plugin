<?php
/**
 * THE 3.131.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31310.php [part of a plant's name]
 *
 * Plants, one at a time, a fault in the reminder's cancel link, and runs the
 * check that must catch it. Every file is put back from memory. Commit first,
 * and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$X    = 'includes/class-sfaf-texts.php';
$T    = 'release-31310-test.php';
$L    = 'release-31310-live.php';
$C    = 'public/js/calendar.js';
$J    = 'public/js/portal.js';
$P    = 'includes/class-sfaf-portal.php';
$M    = 'includes/class-sfaf-messages.php';
$R    = 'includes/class-sfaf-registrant-mail.php';

$plants = array(
    '{{5}} is the event page, not the cancel link' => array( $T, "FAIL  A: {{5}} is Beto's own cancel link", array(
        array( $X, "'5' => ! empty( \$v['cancel'] ) ? \$v['cancel'] : \$v['link'] )", "'5' => \$v['link'] )" ),
    ) ),
    'a registration with no token sends an empty {{5}}' => array( $T, 'FAIL  A: without a token, {{5}} is never empty', array(
        array( $X, "'5' => ! empty( \$v['cancel'] ) ? \$v['cancel'] : \$v['link'] )", "'5' => \$v['cancel'] )" ),
    ) ),
    'everyone gets the same link' => array( $T, "FAIL  A: {{5}} is Cruz's own, not Beto's", array(
        array( $X, "        \$token = isset( \$row->token ) ? trim( (string) \$row->token ) : '';", "        \$token = str_repeat( 'b', 32 );" ),
    ) ),
    'the text has no cancel line' => array( $T, 'FAIL  B: the English reminder ends with the cancel line', array(
        array( $X, "            if ( ! empty( \$v['cancel'] ) ) {\n                \$s .= \$es", "            if ( false ) {\n                \$s .= \$es" ),
    ) ),
    "the English line is not Mark's" => array( $T, 'FAIL  B: the English reminder ends with the cancel line', array(
        array( $X, "\" Can't make it? Cancel here: %s\"", "\" To cancel: %s\"" ),
    ) ),
    "the Spanish line is not Mark's" => array( $T, "FAIL  B: the Spanish reminder in Mark's words", array(
        array( $X, "' ¿No puedes venir? Cancela aquí: %s'", "' ¿No puede venir? Cancele aquí: %s'" ),
    ) ),
    'the update gets a fourth variable' => array( $T, 'FAIL  C: the update template keeps three variables', array(
        array( $X, ": array( '1' => \$v['name'], '2' => self::ended( \$v['message'] ), '3' => \$v['reply'] );", ": array( '1' => \$v['name'], '2' => self::ended( \$v['message'] ), '3' => \$v['reply'], '4' => \$v['cancel'] );" ),
    ) ),
    /* ---- F. The RSVP form ---- */
    'the choice waits for a number again' => array( $L, 'PLANT F:', array(
        array( $C, "            \$('#uc-rsvp-text-wrap').prop('hidden', !currentTexts.length);", "            \$('#uc-rsvp-text-wrap').prop('hidden', true);" ),
    ) ),
    'the phone keeps its old label' => array( $L, "PLANT F:", array(
        array( $C, "attr('data-uc-w', currentTexts.length ? 'phone_texts' : 'phone')", "attr('data-uc-w', 'phone')" ),
    ) ),
    'both boxes can be ticked' => array( $L, 'Text then WhatsApp leaves', array(
        array( $C, "            if (this.checked) { \$('input[name=\"uc-rsvp-text-channel\"]').not(this).prop('checked', false); }", "            if (false) { \$('input[name=\"uc-rsvp-text-channel\"]').not(this).prop('checked', false); }" ),
    ) ),
    'a box ticked with no number is sent' => array( $L, 'WhatsApp with no number', array(
        array( $C, "        if (textChannel() && !phoneUsable(phone)) {", "        if (false) {" ),
    ) ),
    'the confirmation screen says nothing' => array( $L, 'the confirmation screen says', array(
        array( $C, ".replace('%s', response.text_phone)).show();", ".replace('%s', response.text_phone));" ),
    ) ),
    'the consent line shows before a box is ticked' => array( $L, 'it opens with', array(
        array( $C, "        \$('#uc-rsvp-text-consent').prop('hidden', !on);", "        \$('#uc-rsvp-text-consent').prop('hidden', false);" ),
    ) ),
    'a number with nothing ticked is refused' => array( $T, 'FAIL  F: a number with nothing ticked is never refused', array(
        array( 'includes/class-sfaf-texts.php', "        return in_array( (string) \$channel, self::form_channels(), true ) && '' === self::e164( \$phone );", "        return '' === self::e164( \$phone );" ),
    ) ),
    "the phone's label is not Mark's" => array( $T, "FAIL  F: 'phone_texts' in Mark's words", array(
        array( $M, "'Phone number, for event updates by text or WhatsApp (optional)', 'Teléfono", "'Phone number for texts (optional)', 'Teléfono" ),
    ) ),
    /* ---- G. Who gets it ---- */
    'searching unticks who it hides' => array( $L, 'PLANT G:', array(
        array( $J, "                    r.hidden = waitOff(r) || !hit;", "                    r.hidden = waitOff(r) || !hit; if (!hit) { boxOf(r).checked = false; }" ),
    ) ),
    'a filter adds to the ticks instead of setting them' => array( $L, "Didn't get the logged send", array(
        array( $J, "rows.forEach(function (r) { var b = boxOf(r); if (b && !b.disabled) { b.checked = test(r); } });", "rows.forEach(function (r) { var b = boxOf(r); if (b && !b.disabled) { b.checked = b.checked || test(r); } });" ),
    ) ),
    'Send stays on with nobody ticked' => array( $L, 'Clear all:', array(
        array( $J, "                    send.disabled = !n;", "                    send.disabled = false;" ),
    ) ),
    'the new list reuses the 3.129.0 initialiser name' => array( $L, 'PLANT G:', array(
        array( $J, "    function initRecipientList() {", "    function initRecipients() {" ),
    ) ),
    'a text send ignores the ticks' => array( $T, 'FAIL  G: a send reaches only who is ticked', array(
        array( 'includes/class-sfaf-texts.php', "            if ( null !== \$keep && ! isset( \$keep[ (int) \$p['row']->id ] ) ) {\n                continue;\n            }\n", "" ),
    ) ),
    'an email send ignores the ticks' => array( $T, 'FAIL  G: an email send keeps only who is ticked', array(
        array( $R, "        if ( null !== \$only ) {\n            \$keep = array_flip(", "        if ( false ) {\n            \$keep = array_flip(" ),
    ) ),
    'the text log forgets who received it' => array( $T, 'FAIL  G: the text log keeps who received it', array(
        array( 'includes/class-sfaf-texts.php', "            'ids'      => \$ids,\n", "" ),
    ) ),
    'nobody ticked sends to everybody' => array( $T, 'FAIL  G: nobody ticked is an empty list', array(
        array( $P, "        return array_values( array_unique( array_filter( array_map( 'intval', \$ids ) ) ) );", "        return \$ids ? array_values( array_unique( array_filter( array_map( 'intval', \$ids ) ) ) ) : null;" ),
    ) ),
    'Text registrants lists everybody' => array( $T, 'FAIL  G: Text registrants lists only who chose a channel', array(
        array( $P, "                'how_key' => \$p['state'], 'reach' => in_array( \$p['state'], array( 'sms', 'whatsapp' ), true ), 'wait' => false,", "                'how_key' => \$p['state'], 'reach' => false, 'wait' => false," ),
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
