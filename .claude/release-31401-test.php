<?php
/**
 * 3.140.1: THE STAFF REQUEST LINK OPENS THE FORM, AND TURNSTILE GUARDS IT.
 *
 *     php .claude/release-31401-test.php
 *
 * The real SFAF_Request front controller, maybe_render(), on wp-kit.php. It
 * ends every request with exit, so each case runs in a child process of this
 * file (--child=<case>) and reports what it printed, what it inserted and
 * what it mailed.
 *
 *   G  a plain visit to /?uc_event_request=1 is the form: the address field,
 *      posting to the same URL, and no address page, no link, no token; an
 *      old emailed link and an old address page posted from an open tab both
 *      get the form too, and nothing is mailed
 *   T  with both Turnstile keys the form draws the widget; a submission with
 *      no Turnstile answer is refused with the community form's words, and
 *      nothing is inserted or mailed; one Cloudflare passes is saved
 *   S  a good submission is a pending event carrying the TYPED address, and
 *      the copy goes to that address; an address outside sfaf.org is refused
 *      out loud with nothing inserted or mailed
 *   P  the community form, SFAF_Submit, is byte for byte 3.140.0's
 *
 * PLANTs in plant-31401.php.
 */

$root = dirname( __DIR__ );

/* ---- the child: one request through maybe_render(), then exit ---------- */
$child = '';
foreach ( $argv as $a ) {
    if ( 0 === strpos( $a, '--child=' ) ) { $child = substr( $a, 8 ); }
}
if ( '' !== $child ) {
    require __DIR__ . '/wp-kit.php';
    if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
    if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }
    if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = '' ) { return 'kit-salt'; } }
    if ( ! function_exists( 'wp_mail' ) ) {
        function wp_mail( $to, $subject, $body, $headers = '', $att = array() ) {
            $GLOBALS['rt_mail'][] = array( 'to' => is_array( $to ) ? implode( ',', $to ) : (string) $to, 'subject' => (string) $subject );
            return true;
        }
    }
    if ( ! function_exists( 'wp_remote_post' ) ) {
        function wp_remote_post( $url, $args = array() ) {
            $GLOBALS['rt_http'][] = $url;
            return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'success' => ! empty( $GLOBALS['rt_cf_pass'] ) ) ) );
        }
    }
    if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; } }
    if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; } }

    kit_reset();
    $GLOBALS['rt_mail'] = array();
    $GLOBALS['rt_http'] = array();
    $GLOBALS['rt_cf_pass'] = false;

    $good = array(
        'uc_request_action' => 'submit_request',
        'requester_name'    => 'Pat Reed',
        'requester_email'   => 'Pat.Reed@SFAF.org',
        'title'             => 'Board Social',
        'description'       => 'An evening for the board.',
        'date'              => date( 'Y-m-d', strtotime( '+9 days' ) ),
        'start_time'        => '18:00',
        'end_time'          => '19:30',
        'repeat'            => 'none',
        'organizer'         => array( '11' ),
    );
    $keys = array( 'turnstile_site_key' => '0xSITE', 'turnstile_secret_key' => '0xSECRET' );

    $_GET  = array( 'uc_event_request' => '1' );
    $_POST = array();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    switch ( $child ) {
        case 'get':
            break;
        case 'old-link':
            $_GET['uc_token'] = str_repeat( 'a', 32 );
            break;
        case 'old-start':
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = array( 'uc_request_action' => 'send_link', 'email' => 'pat@sfaf.org' );
            break;
        case 'get-keys':
            $GLOBALS['kit_options']['sfaf_credentials'] = $keys;
            break;
        case 'no-answer':
            $GLOBALS['kit_options']['sfaf_credentials'] = $keys;
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = $good;
            break;
        case 'cf-fails':
            $GLOBALS['kit_options']['sfaf_credentials'] = $keys;
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = $good + array( 'cf-turnstile-response' => 'tok-from-widget' );
            break;
        case 'cf-passes':
            $GLOBALS['kit_options']['sfaf_credentials'] = $keys;
            $GLOBALS['rt_cf_pass'] = true;
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = $good + array( 'cf-turnstile-response' => 'tok-from-widget' );
            break;
        case 'good':
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = $good;
            break;
        case 'outsider':
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = array_merge( $good, array( 'requester_email' => 'pat@example.org' ) );
            break;
    }

    register_shutdown_function( function () {
        $posts = array();
        foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
            if ( 'uc_event' !== $p->post_type ) { continue; }
            $posts[] = array(
                'status' => $p->post_status,
                'email'  => isset( $GLOBALS['kit_meta'][ $id ][ SFAF_Request::META_EMAIL ] ) ? $GLOBALS['kit_meta'][ $id ][ SFAF_Request::META_EMAIL ] : '',
            );
        }
        echo "\n@@RT@@" . json_encode( array( 'posts' => $posts, 'mail' => $GLOBALS['rt_mail'], 'http' => $GLOBALS['rt_http'] ) );
    } );
    ob_start();
    SFAF_Request::maybe_render();
    echo "@@NOEXIT@@";
    exit;
}

/* ---- the parent ---------------------------------------------------------- */
$fails = array();
$ran   = 0;
function rt( $ok, $why ) { global $fails, $ran; $ran++; if ( ! $ok ) { $fails[] = $why; } }
function rt_run( $case ) {
    $out = (string) shell_exec( 'php ' . escapeshellarg( __FILE__ ) . ' --child=' . $case . ' 2>&1' );
    $at  = strrpos( $out, '@@RT@@' );
    $res = false === $at ? null : json_decode( substr( $out, $at + 6 ), true );
    return array(
        'html'  => false === $at ? $out : substr( $out, 0, $at ),
        'posts' => is_array( $res ) ? $res['posts'] : null,
        'mail'  => is_array( $res ) ? $res['mail'] : null,
        'http'  => is_array( $res ) ? $res['http'] : null,
    );
}
function rt_brief( $r ) { return substr( trim( preg_replace( '/\s+/', ' ', strip_tags( $r['html'] ) ) ), 0, 300 ); }
function rt_is_form( $h ) {
    return false !== strpos( $h, 'name="requester_email"' )
        && false !== strpos( $h, 'value="submit_request"' )
        && false !== strpos( $h, 'action="https://resources.sfaf.org/?uc_event_request=1"' );
}

/* G. The link is the form. */
$g = rt_run( 'get' );
rt( rt_is_form( $g['html'] ), 'PLANT G: a visit to the link is not the form: ' . rt_brief( $g ) );
rt( false === strpos( $g['html'], 'Send me the link' ) && false === strpos( $g['html'], 'value="send_link"' ), 'G: the address page is still drawn' );
rt( false === strpos( $g['html'], 'name="uc_token"' ), 'G: the form still carries a token' );
rt( false === strpos( $g['html'], '@@NOEXIT@@' ), 'G: maybe_render() returned instead of ending the request' );
rt( 1 === preg_match( '/<input type="email" name="requester_email"[^>]*\brequired\b/', $g['html'] ), 'G: the address field is not required' );
rt( array() === $g['mail'], 'G: a visit mailed somebody' );
foreach ( array( 'old-link' => 'an old emailed link', 'old-start' => 'the old address page, posted' ) as $case => $what ) {
    $r = rt_run( $case );
    rt( rt_is_form( $r['html'] ) && false === strpos( $r['html'], 'expired' ), "G: $what does not get the form: " . rt_brief( $r ) );
    rt( array() === $r['mail'], "G: $what mailed somebody" );
}

/* T. Turnstile. */
$r = rt_run( 'get' );
rt( false === strpos( $r['html'], 'cf-turnstile' ), 'T: the widget is drawn with no keys, so nothing would check it' );
$r = rt_run( 'get-keys' );
rt( false !== strpos( $r['html'], '<div class="cf-turnstile" data-sitekey="0xSITE">' ), 'PLANT T: the form does not draw the Turnstile widget' );
rt( false !== strpos( $r['html'], 'challenges.cloudflare.com/turnstile/v0/api.js' ), 'T: the widget script is not loaded' );
$ts = strpos( $r['html'], 'cf-turnstile' );
$sb = strpos( $r['html'], 'Send the request' );
rt( false !== $ts && false !== $sb && $ts < $sb && $ts > strpos( $r['html'], 'name="notes"' ), 'T: the widget is not between the notes and the send button' );
foreach ( array( 'no-answer' => 'no Turnstile answer', 'cf-fails' => 'an answer Cloudflare refuses' ) as $case => $what ) {
    $r = rt_run( $case );
    rt( false !== strpos( $r['html'], 'That did not pass the robot check. Tick the box again and resend.' ), "PLANT T: $what is not refused: " . rt_brief( $r ) );
    rt( array() === $r['posts'], "PLANT T: $what was inserted" );
    rt( array() === $r['mail'], "T: $what mailed somebody" );
    rt( false !== strpos( $r['html'], 'value="Board Social"' ), "T: $what threw away what was typed" );
}
$r = rt_run( 'cf-passes' );
rt( 1 === count( (array) $r['posts'] ) && array( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' ) === $r['http'], 'T: a request Cloudflare passes was not saved, or was not asked about: ' . rt_brief( $r ) );

/* S. Saving, and who it is from. */
$r = rt_run( 'good' );
rt( array( array( 'status' => 'pending', 'email' => 'pat.reed@sfaf.org' ) ) === $r['posts'], 'PLANT S: the request is not one pending event from the typed address: ' . json_encode( $r['posts'] ) . ' ' . rt_brief( $r ) );
$to = array();
foreach ( (array) $r['mail'] as $m ) { $to[] = $m['to']; }
rt( in_array( 'pat.reed@sfaf.org', $to, true ), 'S: the copy did not go to the typed address: ' . json_encode( $to ) );
rt( false !== strpos( $r['html'], 'That is with the team' ), 'S: the done page is not drawn' );
$r = rt_run( 'outsider' );
rt( false !== strpos( $r['html'], 'That is not an sfaf.org address.' ), 'PLANT S: an outside address is not refused: ' . rt_brief( $r ) );
rt( array() === $r['posts'] && array() === $r['mail'], 'PLANT S: an outside address was saved or mailed' );

/* P. The community form is untouched. */
$base = trim( (string) shell_exec( 'cd ' . escapeshellarg( $root ) . ' && git rev-parse --verify --quiet 1da835af 2>&1' ) );
if ( '' !== $base ) {
    $diff = trim( (string) shell_exec( 'cd ' . escapeshellarg( $root ) . ' && git diff 1da835af -- includes/class-sfaf-submit.php 2>&1' ) );
    rt( '' === $diff, 'P: the community form changed since 3.140.0' );
}

echo "3.140.1: the staff request link opens the form\n";
echo str_repeat( '=', 72 ) . "\n";
if ( $fails ) {
    foreach ( $fails as $f ) { echo "FAIL  $f\n"; }
    echo count( $fails ) . " of $ran failed\n";
    exit( 1 );
}
echo "$ran passed\n";
exit( 0 );
