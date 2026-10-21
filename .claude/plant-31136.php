<?php
/**
 * THE 3.113.6 CHECKS, EACH MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31136.php [part of a plant name]
 *
 * One fault per plant, written into the real file; the check is run, and every
 * file is put back from memory, not from git. An anchor that is not there, or
 * is there twice, stops the run. Commit first, and never run this while
 * run-all.sh is running.
 *
 * E is the proof that the embed is unchanged; 3L is the form in Chrome, so
 * those plants take half a minute each.
 */

$root = dirname( __DIR__ );

$plants = array(
    'E: the toggle\'s styles go into calendar.css' => array( 'embed-identity-test.php', 'B: public/css/calendar.css differs', array(
        array( 'public/css/calendar.css', "/* SFAF Calendar Frontend Styles */\n", "/* SFAF Calendar Frontend Styles */\n.uc-rsvp-lang { display: flex; }\n" ),
    ) ),
    'E: an embed answer changes' => array( 'embed-identity-test.php', 'A: ', array(
        array( 'includes/class-sfaf-embed.php', "\$response->header( 'Cache-Control', 'public, max-age=' . \$browser_ttl );", "\$response->header( 'Cache-Control', 'public, max-age=' . ( \$browser_ttl + 1 ) );" ),
    ) ),
    '1: the language is not stored' => array( 'release-31136-test.php', '1: and the row keeps es', array(
        array( 'includes/class-sfaf-rsvp.php', "            'lang'        => \$data['lang'],\n", '' ),
    ) ),
    '1: the waitlist join drops it' => array( 'release-31136-test.php', '1: a waitlist join keeps the language too', array(
        array( 'includes/class-sfaf-waitlist.php', "            'lang'       => isset( \$data['lang'] ) ? (string) \$data['lang'] : '',\n        );\n        // The agreement", "        );\n        // The agreement" ),
    ) ),
    '1: anything is stored' => array( 'release-31136-test.php', '1: and anything but en or es is nothing', array(
        array( 'includes/class-sfaf-rsvp.php', "in_array( \$data['lang'], array( 'en', 'es' ), true ) ? \$data['lang'] : '';", "\$data['lang'];" ),
    ) ),
    '2: refusals stay English' => array( 'release-31136-test.php', '2: a missing name, refused in Spanish', array(
        array( 'includes/class-sfaf-rsvp.php', "'message' => SFAF_Messages::label( 'e_required', \$say ) );", "'message' => 'First name and email are required.' );" ),
    ) ),
    '3: messages ask the event, not the person' => array( 'release-31136-test.php', "3: Ana's confirmation is Spanish", array(
        array( 'includes/class-sfaf-notifications.php', "        if ( in_array( \$picked, array( 'en', 'es' ), true ) ) {\n            return \$picked;\n        }\n", '' ),
    ) ),
    '3: the confirmation person has no language' => array( 'release-31136-test.php', "3: Ana's confirmation is Spanish", array(
        array( 'includes/class-sfaf-rsvp.php', "            'lang'       => isset( \$data['lang'] ) ? (string) \$data['lang'] : '',\n        );", "        );" ),
    ) ),
    '3: the reminder forgets it' => array( 'release-31136-test.php', "3: Ana's reminder is Spanish", array(
        array( 'includes/class-sfaf-reminders.php', "            'lang'     => (string) \$lang,\n", '' ),
    ) ),
    '3: added from the waitlist, in the event\'s language' => array( 'release-31136-test.php', '3: Cam, added from the waitlist, is confirmed in Spanish', array(
        array( 'includes/class-sfaf-waitlist.php', "            'lang' => isset( \$row->lang ) ? (string) \$row->lang : '',\n", '' ),
    ) ),
    '3: several dates speak the first date\'s' => array( 'release-31136-test.php', '3: two dates cancelled, one Spanish message for Ana', array(
        array( 'includes/class-sfaf-announce.php', "in_array( \$picked, array( 'en', 'es' ), true ) ? \$picked : sfaf_event_language( \$event_ids[0] );", "sfaf_event_language( \$event_ids[0] );" ),
    ) ),
    '3: the staff copy follows the person' => array( 'release-31136-test.php', '3: the staff copy stays English', array(
        array( 'includes/class-sfaf-notifications.php', "\$lang  = \$staff ? 'en' : self::lang_for( \$event_id, \$context, \$person );", "\$lang  = self::lang_for( \$event_id, \$context, \$person ); \$lang = \$staff ? 'es' : \$lang;" ),
    ) ),
    '4: Email registrants follows the person' => array( 'release-31136-test.php', "4: Email registrants stays in the event's language", array(
        array( 'includes/class-sfaf-notifications.php', "        \$lang   = self::lang_for( \$event_id, \$context );\n", "        \$lang   = self::lang_for( \$event_id, \$context, \$person );\n" ),
    ) ),
    '5: the ledger link speaks the event\'s' => array( 'release-31136-test.php', '5: through the reminder ledger, which keeps no language', array(
        array( 'includes/class-sfaf-reminders.php', "        if ( ! isset( \$row->lang ) && '' !== (string) \$row->email ) {", "        if ( false ) {" ),
    ) ),
    '6: a Spanish word left in English' => array( 'release-31136-test.php', '6: every Spanish word is Spanish', array(
        array( 'includes/class-sfaf-messages.php', "'f_phone'          => array( 'Phone (optional)', 'Teléfono (opcional)' ),", "'f_phone'          => array( 'Phone (optional)', 'Phone (optional)' )," ),
    ) ),
    '3L: the form does not post the language' => array( 'release-31136-live.php', 'did not post the language picked', array(
        array( 'public/js/calendar.js', "                lang:         currentLang\n", "                lang:         'en'\n" ),
    ) ),
    '3L: the toggle changes nothing' => array( 'release-31136-live.php', 'does not change the words at once', array(
        array( 'public/js/calendar.js', "            setLang(\$(this).attr('data-uc-lang-pick'));", "            currentLang = \$(this).attr('data-uc-lang-pick');" ),
    ) ),
    '3L: the toggle in the flow, making the form taller' => array( 'release-31136-live.php', 'the toggle makes the form', array(
        array( 'public/js/calendar.js', "'.uc-rsvp-modal-overlay .uc-rsvp-lang{position:absolute;top:-28px;right:-12px;", "'.uc-rsvp-modal-overlay .uc-rsvp-lang{position:static;" ),
    ) ),
    '3L: the toggle is a tiny target' => array( 'release-31136-live.php', 'under 24', array(
        array( 'public/js/calendar.js', "button{margin:0;padding:4px 6px;", "button{margin:0;padding:0 6px;" ),
    ) ),
    '3L: the answer stays in the opening language' => array( 'release-31136-live.php', 'the answer is not all in Spanish', array(
        array( 'public/js/calendar.js', "                    \$('#uc-rsvp-success-sub').text(W('see_you', 'We look forward to seeing you.'));\n                    if (response.waitlisted) {", "                    if (response.waitlisted) {" ),
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
    $args = ( false !== strpos( $check, '-live' ) ) ? ' --run' : ' --verbose';
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . escapeshellarg( __DIR__ . '/' . $check ) . $args . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
