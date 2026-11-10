<?php
/**
 * 3.138.0: THE QUEUE'S PUBLISH IN GREEN INK, THE MORNING-OF REMINDER'S NOTE,
 * AND LANGUAGE IN WHO GETS IT.
 *
 *     php .claude/release-31380-test.php
 *
 * The real render_notify_box(), render_registrant_mail() and
 * render_text_registrants() on wp-kit.php, and portal.css as written:
 *   - the Notifications card's Morning-of reminder note, under Sent to
 *     registrants, ends with Mark's sentence, exactly
 *   - both recipient lists offer Language: Choose a language, English only,
 *     Spanish only; every row says its language, Spanish for "es" and
 *     English for anything else, as the EN or ES tag does
 *   - .uc-link-ok is the green family's ink, #46661F, not the brand green
 * The browser half, which measures the contrast and presses the filter, is
 * release-31380-live.php; PLANTs in plant-31380.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
$ran   = 0;
function rt( $label, $ok ) {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) { $fails[] = $label; }
}
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
function rt_text( $n ) { return trim( preg_replace( '/\s+/', ' ', $n->textContent ) ); }

kit_reset();
$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+7 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1' ) as $k => $v ) {
    update_post_meta( $ev, $k, $v );
}
$GLOBALS['ev'] = $ev;
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();

/* ---- The Notifications card. ---- */
$want = 'Sent at 6 am on the day, or at midnight when the event starts earlier. Your notification list is copied in. Also sent by text or WhatsApp to anyone who chose it on the RSVP form.';
$card = rt_cap( function () use ( $portal, $ev ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_notify_box', $portal, array( new WP_User(), $ev ) ); } );
rt( 'the Notifications card renders', 0 !== strpos( $card, 'THREW' ) );
$x = rt_x( $card );
$head = $x->query( '//h4[contains(@class,"uc-notify-subhead")][normalize-space(.)="Sent to registrants"]' );
rt( 'Sent to registrants is there', 1 === $head->length );
$rem = $x->query( '//p[@data-uc-notify-always="reminder"]' );
$note = $rem->length ? $x->query( 'following-sibling::p[1][contains(@class,"uc-notify-kind-note")]', $rem->item( 0 ) ) : null;
rt( 'the Morning-of reminder note ends with Mark\'s sentence, exactly', $note && $note->length && $want === rt_text( $note->item( 0 ) ) );
rt( 'and it is under Sent to registrants, not the people above', $rem->length && $head->length
    && 0 === $x->query( 'preceding-sibling::h4[contains(@class,"uc-notify-subhead")][normalize-space(.)="Sent to the people above"]', $rem->item( 0 ) )->length );
$conf = $x->query( '//p[@data-uc-notify-always="confirmation"]/following-sibling::p[1]' );
rt( 'the Confirmation note is unchanged', $conf->length && 'Sent the moment somebody registers. Carries the date, the time, the place, add-to-calendar links, and a link to cancel.' === rt_text( $conf->item( 0 ) ) );

/* ---- Who gets it, both lists. ---- */
SFAF_Texts::save( array( 'twilio_account_sid' => 'AC' . str_repeat( 'a', 32 ), 'twilio_messaging_sid' => 'MG' . str_repeat( 'b', 32 ), 'twilio_whatsapp_from' => '+14155550100',
    'twilio_wa_reminder_en' => 'HX' . str_repeat( 'c', 32 ), 'twilio_wa_reminder_es' => 'HX' . str_repeat( 'd', 32 ), 'twilio_wa_update_en' => 'HX' . str_repeat( 'e', 32 ), 'twilio_wa_update_es' => 'HX' . str_repeat( 'f', 32 ) ), 'token0123456789' );
function rt_row( $id, $first, $email, $phone, $channel, $lang ) {
    return array( 'id' => $id, 'event_id' => $GLOBALS['ev'], 'name' => $first, 'first_name' => $first, 'last_name' => '', 'email' => $email, 'phone' => $phone,
        'status' => 'confirmed', 'token' => 't' . $id, 'format' => '', 'lang' => $lang, 'text_channel' => $channel, 'text_opt_in' => 0, 'created_at' => '2026-10-2' . $id . ' 10:00:00' );
}
$GLOBALS['rt_rows'] = array(
    rt_row( 1, 'Ana', 'ana@example.org', '(415) 555-0101', 'sms', 'en' ),
    rt_row( 2, 'Beto', 'beto@example.org', '+52 55 1234 5678', 'whatsapp', 'es' ),
    rt_row( 3, 'Cruz', 'cruz@example.org', '(415) 555-0103', 'sms', '' ),
);
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false === strpos( $sql, 'uc_rsvps' ) || false !== strpos( $sql, 'uc_rsvp_answers' ) ) { return 'get_results' === $method ? array() : null; }
    $rows = $GLOBALS['rt_rows'];
    if ( 'get_results' === $method ) { return array_map( function ( $r ) { return (object) $r; }, $rows ); }
    if ( 'get_var' === $method ) { return (string) count( $rows ); }
    return null;
};
$me = new WP_User();
foreach ( array( 'email' => 'render_registrant_mail', 'text' => 'render_text_registrants' ) as $kind => $method ) {
    $html = rt_cap( function () use ( $portal, $me, $ev, $method ) { kit_call( 'SFAF_Portal', $method, $portal, array( $me, $ev ) ); } );
    rt( "$kind: the section renders", 0 !== strpos( $html, 'THREW' ) );
    $x   = rt_x( $html );
    $box = $x->query( '//*[@data-uc-rcp][@data-uc-rcp-kind="' . $kind . '"]' );
    rt( "$kind: the recipient list is there", 1 === $box->length );
    if ( ! $box->length ) { continue; }
    $sel = $x->query( './/div[contains(@class,"uc-rcp-tools")]/label[contains(@class,"uc-field")][span[contains(@class,"uc-field-label")][normalize-space(.)="Language"]]/select[@data-uc-rcp-lang]', $box->item( 0 ) );
    rt( "$kind: Language is a filter beside the others, in the tools row", 1 === $sel->length );
    $opts = array();
    if ( $sel->length ) { foreach ( $x->query( './option', $sel->item( 0 ) ) as $o ) { $opts[] = array( $o->getAttribute( 'value' ), rt_text( $o ) ); } }
    rt( "$kind: Choose a language, English only, Spanish only", array( array( '', 'Choose a language' ), array( 'en', 'English only' ), array( 'es', 'Spanish only' ) ) === $opts );
    $langs = array();
    foreach ( $x->query( './/li[@data-uc-rcp-row]', $box->item( 0 ) ) as $li ) {
        $name = $x->query( './/*[contains(@class,"uc-rcp-name")]', $li );
        $langs[ $name->length ? rt_text( $name->item( 0 ) ) : '?' ] = $li->getAttribute( 'data-uc-rcp-lang' );
    }
    rt( "$kind: every row says its language: Ana en, Beto es, Cruz (none stored) en", array( 'Ana' => 'en', 'Beto' => 'es', 'Cruz' => 'en' ) === $langs );
    $tags = array();
    foreach ( $x->query( './/li[@data-uc-rcp-row]//*[@data-uc-lang-tag]', $box->item( 0 ) ) as $t ) { $tags[] = $t->getAttribute( 'data-uc-lang-tag' ); }
    rt( "$kind: the filter agrees with the EN or ES tag on every row", array_values( $langs ) === $tags );
}

/* ---- The queue's links. ---- */
$css = (string) file_get_contents( $GLOBALS['kit_root'] . '/public/css/portal.css' );
rt( '.uc-link-ok is the green family\'s ink', 1 === preg_match( '/\n\.uc-link-ok \{ background: none; border: none; color: var\(--p-ok-ink\);/', $css ) );
rt( '--p-ok-ink is #46661F', false !== strpos( $css, '--p-ok-ink: #46661F;' ) );
rt( 'no green text left on the queue\'s links', 0 === preg_match( '/\.uc-link-ok[^{]*\{[^}]*color: var\(--p-green\)/', $css ) );

echo "\n3.138.0: Publish in green ink, the reminder's note, Language in who gets it\n" . str_repeat( '=', 72 ) . "\n";
echo "release-31380: $ran checks\n";
if ( $fails ) { foreach ( $fails as $f ) { echo "FAIL  $f\n"; } exit( 1 ); }
echo "every check passed.\n";
exit( 0 );
