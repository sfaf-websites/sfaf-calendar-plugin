<?php
/**
 * ONE COPY OF EACH ADMIN ALERT GOES TO ASANA (3.112.1, item 7).
 *
 *     php .claude/asana-copy-test.php
 *
 * The five messages calendar admins are sent, each driven through its own
 * sender on wp-kit.php with wp_mail() recorded:
 *
 *   request  SFAF_Request::notify_admins()
 *   submit   SFAF_Submit::notify_admins()
 *   picture  SFAF_Media::notify_event_upload()
 *   orphans  SFAF_Orphans::send() and send_all_clear()
 *   cron     SFAF_Cron::send_alert() and send_recovery()
 *
 * Each must send EXACTLY ONE message to the Asana address, after the normal
 * ones, with the same subject and body; the request and submit copies are the
 * alert list's version (the editor link), the picture keeps its attachment,
 * the cron copy is plain text. None with the message's tick off, none with the
 * address empty, and never a Cc or Bcc header. Then the sanitiser: every key
 * written on every save, an unticked box with the form's marker is off.
 *
 * PLANT 7 in plant-31121.php sends the copy inside the request loop.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'wp_specialchars_decode' ) ) { function wp_specialchars_decode( $s, $q = 0 ) { return htmlspecialchars_decode( (string) $s, ENT_QUOTES ); } }
$GLOBALS['ac_mail'] = array();
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $body, $headers = '', $att = array() ) {
        $GLOBALS['ac_mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => (array) $headers, 'att' => (array) $att );
        return true;
    }
}

$fails = array();
$ran   = 0;
function ac( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . json_encode( $got ) . ', wanted ' . json_encode( $want ); }
}
$ASANA = SFAF_Asana::DEFAULT_ADDRESS;

/* Two calendar admins, for the messages that go to every admin. */
$GLOBALS['kit_users'] = array();
foreach ( array( 7 => 'ana@sfaf.org', 8 => 'ben@sfaf.org' ) as $uid => $em ) {
    $u = new WP_User(); $u->ID = $uid; $u->user_email = $em; $u->display_name = 'Admin ' . $uid; $GLOBALS['kit_users'][] = $u;
    $GLOBALS['kit_umeta'][ $uid ]['_uc_calendar_role'] = 'admin';
}

$event = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Harm reduction drop-in', 'post_author' => 1 ) );
update_post_meta( $event, '_uc_event_date', '2026-11-12' );

$c_request = array( 'title' => 'Harm reduction drop-in', 'date' => '2026-11-12', 'start' => '18:00', 'end' => '19:30', 'name' => 'A colleague',
    'repeat' => 'none', 'pattern' => '', 'venue' => 0, 'venue_other' => '', 'rsvp' => false, 'capacity' => '' );
$c_submit = array( 'title' => 'Community walk', 'date' => '2026-11-14', 'start' => '10:00', 'end' => '12:00', 'location' => 'Dolores Park',
    'cost' => 'free', 'cost_other' => '', 'age' => '', 'age_other' => '', 'venue_url' => '', 'rsvp_url' => '',
    'submitter_name' => 'A neighbour', 'submitter_email' => 'neighbour@example.org', 'submitter_emails' => array( 'neighbour@example.org' ),
    'contact_name' => 'A neighbour', 'contact_email' => 'neighbour@example.org', 'contact_phone' => '' );
$series = new WP_Term(); $series->term_id = 11; $series->name = 'Alpha'; $series->slug = 'alpha';

/* A picture uploaded through the editor, with a file on disk. */
$GLOBALS['kit_thumbs'] = true;
$pic_file = tempnam( sys_get_temp_dir(), 'ac' );
file_put_contents( $pic_file, 'not really a picture' );
$att = kit_write_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'walk.jpg' ) );
$GLOBALS['kit_attached'][ $att ] = $pic_file;

$senders = array(
    'request' => function () use ( $event, $c_request ) { kit_call( 'SFAF_Request', 'notify_admins', null, array( $event, $c_request, 'colleague@sfaf.org' ) ); },
    'submit'  => function () use ( $event, $c_submit, $series ) { kit_call( 'SFAF_Submit', 'notify_admins', null, array( $event, $c_submit, $series ) ); },
    'picture' => function () use ( $event, $att ) {
        set_post_thumbnail( $event, $att );
        update_post_meta( $att, SFAF_Media::META_UPLOAD, array( 'by' => 7, 'at' => time() ) );
        delete_post_meta( $att, SFAF_Media::META_UPLOAD_SENT );
        SFAF_Media::notify_event_upload( $event );
    },
    'orphans' => function () use ( $event ) { kit_call( 'SFAF_Orphans', 'send', null, array( array( array( 'id' => $event, 'title' => 'Harm reduction drop-in', 'status' => 'publish', 'who' => 'Former colleague' ) ) ) ); },
    'orphans all-clear' => function () { kit_call( 'SFAF_Orphans', 'send_all_clear', null, array( 1 ) ); },
    'cron'    => function () { kit_call( 'SFAF_Cron', 'send_alert', null, array( array( 'state' => 'failing', 'message' => 'Three runs failed in a row.' ), time() - 3600, true ) ); },
    'cron recovery' => function () { kit_call( 'SFAF_Cron', 'send_recovery', null, array( array( 'state' => 'ok', 'message' => 'A run completed.' ), time() - 3600 ) ); },
);
$tick_of = array( 'request' => 'request', 'submit' => 'submit', 'picture' => 'picture', 'orphans' => 'orphans', 'orphans all-clear' => 'orphans', 'cron' => 'cron', 'cron recovery' => 'cron' );

function ac_run( $fn ) { $GLOBALS['ac_mail'] = array(); $fn(); return $GLOBALS['ac_mail']; }
function ac_to( $mail, $addr ) { return array_values( array_filter( $mail, function ( $m ) use ( $addr ) { return 0 === strcasecmp( $m['to'], $addr ); } ) ); }

foreach ( $senders as $name => $fn ) {
    /* Settings never saved: the default address, every tick on. */
    $GLOBALS['kit_options']['uc_settings'] = array( 'cron_alert_email' => 'ops@sfaf.org' );
    $mail   = ac_run( $fn );
    $copies = ac_to( $mail, $ASANA );
    $others = array_values( array_filter( $mail, function ( $m ) use ( $ASANA ) { return 0 !== strcasecmp( $m['to'], $ASANA ); } ) );
    ac( "$name: exactly one copy to Asana", count( $copies ), 1 );
    ac( "$name: the normal recipients still get theirs", count( $others ) > 0, true );
    if ( 1 === count( $copies ) && $others ) {
        $copy  = $copies[0];
        $first = $others[0];
        ac( "$name: the copy comes after the normal sends", array_search( $copy, $mail, true ) === count( $mail ) - 1, true );
        ac( "$name: same subject", $copy['subject'], $first['subject'] );
        ac( "$name: same body", $copy['body'], $first['body'] );
        ac( "$name: no Cc or Bcc on any message", (bool) preg_grep( '/^\s*(cc|bcc)\s*:/i', array_merge( ...array_map( function ( $m ) { return $m['headers']; }, $mail ) ) ), false );
        if ( 'picture' === $name ) { ac( 'picture: the copy keeps its attachment', $copy['att'], array( $GLOBALS['pic_file'] ) ); }
        if ( 0 === strpos( $name, 'cron' ) ) { ac( "$name: the copy is plain text", (bool) preg_grep( '#Content-Type: text/plain#', $copy['headers'] ), true ); }
    }
    /* The tick off: none. */
    $GLOBALS['kit_options']['uc_settings'] = array( 'cron_alert_email' => 'ops@sfaf.org', SFAF_Asana::KEY_PREFIX . $tick_of[ $name ] => '0' );
    ac( "$name: none with its tick off", count( ac_to( ac_run( $fn ), $ASANA ) ), 0 );
    /* Another message's tick off: still one. */
    $other = ( 'request' === $tick_of[ $name ] ) ? 'cron' : 'request';
    $GLOBALS['kit_options']['uc_settings'] = array( 'cron_alert_email' => 'ops@sfaf.org', SFAF_Asana::KEY_PREFIX . $other => '0' );
    ac( "$name: one with another message's tick off", count( ac_to( ac_run( $fn ), $ASANA ) ), 1 );
    /* The address saved empty: none, to anyone. */
    $GLOBALS['kit_options']['uc_settings'] = array( 'cron_alert_email' => 'ops@sfaf.org', SFAF_Asana::KEY_ADDRESS => '' );
    $mail = ac_run( $fn );
    ac( "$name: none with the address empty", count( $mail ) === count( $others ) && ! ac_to( $mail, $ASANA ), true );
    /* A different address is the one used. */
    $GLOBALS['kit_options']['uc_settings'] = array( 'cron_alert_email' => 'ops@sfaf.org', SFAF_Asana::KEY_ADDRESS => 'tasks@example.org' );
    ac( "$name: one to a changed address", count( ac_to( ac_run( $fn ), 'tasks@example.org' ) ), 1 );
}

/* The request and submit copies are the alert list's version: the editor link. */
$GLOBALS['kit_options']['uc_settings'] = array();
foreach ( array( 'request', 'submit' ) as $name ) {
    $copy = ac_to( ac_run( $senders[ $name ] ), $ASANA );
    ac( "$name: the copy links to the editor", $copy && false !== strpos( $copy[0]['body'], 'events/edit/' . $event ), true );
}
/* Already a normal recipient: that is its one copy. */
$GLOBALS['kit_options']['uc_settings'] = array( 'submission_alert_emails' => $ASANA . ', websites@sfaf.org' );
ac( 'request: the Asana address on the alert list gets one message, not two', count( ac_to( ac_run( $senders['request'] ), $ASANA ) ), 1 );

/* ---- The sanitiser. ------------------------------------------------------ */
$GLOBALS['kit_options']['uc_settings'] = array();
$out = array();
SFAF_Asana::sanitize( array( SFAF_Asana::KEY_PRESENT => '1', SFAF_Asana::KEY_ADDRESS => ' tasks@example.org ', SFAF_Asana::KEY_PREFIX . 'request' => '1' ), $out );
$want = array( SFAF_Asana::KEY_ADDRESS => 'tasks@example.org' );
foreach ( array_keys( SFAF_Asana::messages() ) as $k ) { $want[ SFAF_Asana::KEY_PREFIX . $k ] = 'request' === $k ? '1' : '0'; }
ac( 'sanitise: from the form, every key, an unticked box off', $out, $want );
$out = array();
SFAF_Asana::sanitize( array( SFAF_Asana::KEY_PRESENT => '1', SFAF_Asana::KEY_ADDRESS => 'not an address' ), $out );
ac( 'sanitise: a bad address is stored empty', $out[ SFAF_Asana::KEY_ADDRESS ], '' );
$out = array();
SFAF_Asana::sanitize( array(), $out );
ac( 'sanitise: a save not from the form keeps the defaults, and writes every key', count( $out ) === 1 + count( SFAF_Asana::messages() ) && $ASANA === $out[ SFAF_Asana::KEY_ADDRESS ] && ! in_array( '0', $out, true ), true );
$admin_src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-sfaf-admin.php' );
ac( 'sanitise: sanitize_settings() calls it', false !== strpos( $admin_src, 'SFAF_Asana::sanitize( $input, $out );' ), true );
ac( 'SFAF_Email::send() has no Asana in it', false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-email.php' ), 'Asana' ), true );

@unlink( $pic_file );
echo "\nThe Asana copy of the five admin messages\n" . str_repeat( '=', 72 ) . "\n";
echo "ran $ran assertions over seven sends.\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "each sends exactly one copy to Asana after its own, none with its tick off or the address empty.\n";
exit( 0 );
