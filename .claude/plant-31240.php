<?php
/**
 * THE 3.124.0 CHECKS, MADE TO FAIL ON PURPOSE.
 *
 *     php .claude/plant-31240.php            every plant
 *     php .claude/plant-31240.php "G:"       only those whose name has "G:"
 *
 * Puts one fault in at a time and runs release-31240-test.php, or
 * release-31240-live.php --run for the ones only a browser sees, which must
 * fail with the line named. Every file is put back from memory. Commit first,
 * and never run this while run-all.sh is running.
 */

$root = dirname( __DIR__ );
$M    = 'includes/class-sfaf-mailchimp.php';
$O    = 'includes/class-sfaf-optins.php';
$P    = 'includes/class-sfaf-portal.php';
$W    = 'includes/class-sfaf-waitlist.php';
$J    = 'public/js/calendar.js';
$PJ   = 'public/js/portal.js';
$T    = 'test';
$L    = 'live';

$plants = array(
    'A: the card is drawn without a connection' => array( $T, 'A: Add event: no Newsletter sign-up card without a connection', array(
        array( $P, "    private function render_mailchimp_card( \$event_id, \$term_id, \$series ) {\n        if ( ! SFAF_Mailchimp::active() ) {\n            return;\n        }", "    private function render_mailchimp_card( \$event_id, \$term_id, \$series ) {\n        echo '<section class=\"uc-bento-card\" data-uc-card=\"mailchimp\" data-uc-mailchimp=\"organizer[]\" hidden><h2 class=\"uc-bento-title\">Newsletter sign-up</h2></section>';\n        if ( ! SFAF_Mailchimp::active() ) {\n            return;\n        }" ),
    ) ),
    'B: the key is stored as typed' => array( $T, 'B: the key is stored encrypted', array(
        array( $M, "        SFAF_Credentials::set( self::key_name( \$id ), self::seal( \$key ) );\n        return self::key( \$id ) === \$key;", "        SFAF_Credentials::set( self::key_name( \$id ), 'mc1:' . \$key );\n        return true;" ),
    ) ),
    'B: the panel shows the key' => array( $T, 'B: the panel never prints the key', array(
        array( $M, "placeholder=\"<?php echo esc_attr( '' !== \$c['id'] ? self::key_hint( \$c['id'] ) . ' Leave blank to keep it.' : 'Paste the API key' ); ?>\"", "placeholder=\"<?php echo esc_attr( '' !== \$c['id'] ? self::key( \$c['id'] ) : 'Paste the API key' ); ?>\"" ),
    ) ),
    'B: two connections share an organizer' => array( $T, 'B: an organizer has one connection', array(
        array( $M, "        if ( \$taken ) {\n            return array( 'ok' => false,", "        if ( false ) {\n            return array( 'ok' => false," ),
    ) ),
    'C: the sign-up tick is ignored' => array( $T, 'C: an event with the sign-up unticked offers none', array(
        array( $M, "            if ( \$c && self::event_choice( \$event_id, \$id )['show'] ) {", "            if ( \$c ) {" ),
    ) ),
    'C: any posted id is kept' => array( $T, 'C: a posted id the event did not offer is dropped', array(
        array( $M, "            if ( in_array( \$l['id'], \$posted, true ) ) {\n                \$out[] = \$l['id'];\n            }\n        }\n        return \$out;", "            \$out[] = \$l['id'];\n        }\n        return array_values( array_unique( array_merge( \$out, array_filter( \$posted ) ) ) );" ),
    ) ),
    'D: the save writes blocks it did not draw' => array( $T, 'D: anything not drawn is kept', array(
        array( $M, "        foreach ( \$offered as \$id ) {\n            if ( ! self::get( \$id ) ) {", "        foreach ( array_keys( \$given ) as \$id ) {\n            if ( false ) {" ),
    ) ),
    'D: drawing the card asks Mailchimp' => array( $T, 'D: drawing the card makes no request to Mailchimp', array(
        array( $M, "        \$have  = get_option( 'sfaf_mc_meta_' . \$id, array() );", "        \$have  = self::meta( \$id );" ),
    ) ),
    'D: the event name becomes a tag' => array( $T, 'D: the event\'s name is never a tag', array(
        array( $M, "        return array( 'audience' => \$audience, 'tags' => self::clean_tags( array_merge( \$c['tags'], \$s['tags'], \$e['tags'] ) ) );", "        return array( 'audience' => \$audience, 'tags' => self::clean_tags( array_merge( \$c['tags'], \$s['tags'], \$e['tags'], array( get_the_title( \$event_id ) ) ) ) );" ),
    ) ),
    'D: the series audience is skipped' => array( $T, 'D: the series\' audience before the connection\'s', array(
        array( $M, "        \$audience = '' !== \$e['audience'] ? \$e['audience'] : ( '' !== \$s['audience'] ? \$s['audience'] : \$c['audience'] );", "        \$audience = '' !== \$e['audience'] ? \$e['audience'] : \$c['audience'];" ),
    ) ),
    'E: the SFAF box starts ticked' => array( $T, 'E: no box is drawn ticked', array(
        array( $J, "'<input type=\"checkbox\" id=\"uc-rsvp-optin\" value=\"1\" />' +", "'<input type=\"checkbox\" id=\"uc-rsvp-optin\" value=\"1\" checked />' +" ),
        array( $J, "            \$('#uc-rsvp-optin').prop('checked', false);\n", "" ),
    ) ),
    'E: the old email line' => array( $T, 'E: the email line is Mark\'s', array(
        array( $J, ">We use your email for this event, and only add it to a list if you tick a box below.</p>", ">We use your email only for this event: your confirmation, a reminder, and any changes. It is never shared or added to a list.</p>" ),
    ) ),
    'F: no email still signs up' => array( $T, 'F: no email, nothing recorded or queued', array(
        array( $O, "        if ( '' === trim( \$email ) || '' === (string) \$ticks ) {\n            return 0;\n        }", "        if ( '' === (string) \$ticks ) {\n            return 0;\n        }\n        \$email = '' === trim( \$email ) ? 'nobody@example.org' : \$email;" ),
    ) ),
    'F: a waitlist join counts at once' => array( $T, 'F: and nothing is recorded or queued yet', array(
        array( $W, "        \$id       = (int) \$wpdb->insert_id;\n        \$position = self::position( \$id );", "        \$id       = (int) \$wpdb->insert_id;\n        SFAF_Optins::record_ticks( \$data + array( 'rsvp_id' => \$id ), isset( \$data['lists'] ) ? \$data['lists'] : '' );\n        \$position = self::position( \$id );" ),
    ) ),
    'F: a confirmed waitlist sign-up is lost' => array( $T, 'F: confirmed, both are recorded', array(
        array( $W, "        SFAF_Optins::record_ticks( array( 'event_id' => (int) \$row->event_id ) + (array) \$person, isset( \$row->lists ) ? (string) \$row->lists : '' );\n", "" ),
    ) ),
    'F: the language is not recorded' => array( $T, 'F: each with the event, the date, the language and the registration', array(
        array( $O, "            'lang'         => isset( \$extra['lang'] ) && in_array( \$extra['lang'], array( 'en', 'es' ), true ) ? \$extra['lang'] : '',", "            'lang'         => ''," ),
    ) ),
    'G: an unsubscribed person is signed up again' => array( $T, 'G: unsubscribed: never re-subscribed', array(
        array( $M, "        if ( in_array( \$status, array( 'unsubscribed', 'cleaned' ), true ) ) {", "        if ( false ) {" ),
    ) ),
    'G: a subscriber\'s details are overwritten' => array( $T, 'G: already subscribed: their details are not sent', array(
        array( $M, "        if ( in_array( \$status, array( 'archived', 'transactional' ), true ) ) {", "        if ( in_array( \$status, array( 'archived', 'transactional', 'subscribed' ), true ) ) {" ),
    ) ),
    'G: an archived contact is left out' => array( $T, 'G: archived: added back', array(
        array( $M, "        if ( in_array( \$status, array( 'archived', 'transactional' ), true ) ) {", "        if ( in_array( \$status, array( 'transactional' ), true ) ) {" ),
    ) ),
    'G: failures are retried for ever' => array( $T, 'G: after five tries it has failed', array(
        array( $M, "        if ( \$attempt > count( \$waits ) ) {", "        if ( false ) {" ),
        array( $M, "            'next_at'    => gmdate( 'Y-m-d H:i:s', time() + \$waits[ \$attempt - 1 ] ),", "            'next_at'    => gmdate( 'Y-m-d H:i:s', time() + \$waits[ min( \$attempt, count( \$waits ) ) - 1 ] )," ),
    ) ),
    'G: the error keeps the key' => array( $T, 'G: the message has no key in it', array(
        array( $M, "            'last_error' => self::scrub( substr( (string) \$error, 0, 250 ) ),\n        ), 'retry', \$error );", "            'last_error' => substr( (string) \$error, 0, 250 ),\n        ), 'retry', \$error );" ),
        array( $M, "            \$err = trim( ( isset( \$data['title'] ) ? \$data['title'] . ': ' : '' ) . ( isset( \$data['detail'] ) ? \$data['detail'] : 'HTTP ' . \$code ) );\n        }\n        return array( 'ok' => \$ok, 'code' => \$code, 'body' => \$data, 'error' => self::scrub( \$err, \$key ) );", "            \$err = trim( ( isset( \$data['title'] ) ? \$data['title'] . ': ' : '' ) . ( isset( \$data['detail'] ) ? \$data['detail'] : 'HTTP ' . \$code ) );\n        }\n        return array( 'ok' => \$ok, 'code' => \$code, 'body' => \$data, 'error' => \$err );" ),
    ) ),
    'H: no List column' => array( $T, 'H: List and Language columns', array(
        array( $P, "<thead><tr><th>Email</th><th>Name</th><th>List</th><th>Consented</th><th>Language</th><th>Form</th><th>Event</th></tr></thead>", "<thead><tr><th>Email</th><th>Name</th><th>Consented</th><th>Form</th><th>Event</th></tr></thead>" ),
    ) ),
    /* Only a browser sees these. */
    'live A: a team box starts ticked' => array( $L, 'PLANT A: ', array(
        array( $J, "'<input type=\"checkbox\" name=\"uc_rsvp_list\" value=\"' + esc(l.id) + '\" />' +", "'<input type=\"checkbox\" name=\"uc_rsvp_list\" value=\"' + esc(l.id) + '\" checked />' +" ),
    ) ),
    'live B: the team box stays English' => array( $L, 'PLANT B: ', array(
        array( $J, "        \$('#uc-rsvp-lists [data-uc-team]').each(function () {", "        \$('#uc-rsvp-lists [data-uc-team-off]').each(function () {" ),
    ) ),
    'live C: the group is roomy' => array( $L, 'PLANT C: ', array(
        array( $J, "'.uc-rsvp-modal-overlay .uc-rsvp-stay{border:0;padding:0;margin:2px 0 0;min-width:0}' +", "'.uc-rsvp-modal-overlay .uc-rsvp-stay{border:0;padding:16px 0;margin:16px 0 0;min-width:0}' +" ),
    ) ),
    'live D: the teams are not posted' => array( $L, 'PLANT D: ', array(
        array( $J, "                lists:      lists,\n", "" ),
    ) ),
    'live E: the card ignores the organizers' => array( $L, 'PLANT E: ', array(
        array( $PJ, "                    var show = b.hasAttribute('data-uc-mc-always') || orgs.some(function (o) { return on.indexOf(o) !== -1; });", "                    var show = true;" ),
    ) ),
    'live F: a typed tag is not added' => array( $L, 'PLANT F: ', array(
        array( $PJ, "            if (e.key === 'Enter') { e.preventDefault(); addTag(); }", "            if (e.key === 'Enter') { e.preventDefault(); }" ),
    ) ),
    'live F: the search finds nothing' => array( $L, 'PLANT F: card desktop: the search shows', array(
        array( $PJ, "all().forEach(function (b) { b.closest('label').hidden = q !== '' && b.value.toLowerCase().indexOf(q) === -1; });", "all().forEach(function (b) { b.closest('label').setAttribute('data-x', q); });" ),
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
    $cmd = 'live' === $check ? escapeshellarg( __DIR__ . '/release-31240-live.php' ) . ' --run' : escapeshellarg( __DIR__ . '/release-31240-test.php' );
    $out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && php ' . $cmd . ' 2>&1' );
    foreach ( $saved as $sp => $orig ) { file_put_contents( $sp, $orig ); }
    $caught = ( false !== strpos( (string) $out, $want ) && false !== strpos( (string) $out, ' failed:' ) );
    echo ( $caught ? 'caught  ' : 'MISSED  ' ) . $name . "\n";
    if ( ! $caught ) { $missed++; echo '        ' . str_replace( "\n", "\n        ", trim( substr( (string) $out, -1500 ) ) ) . "\n"; }
}
echo $missed ? "$missed PLANT(S) MISSED\n" : "every plant caught.\n";
exit( $missed ? 1 : 0 );
