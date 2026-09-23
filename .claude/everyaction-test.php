<?php
/**
 * THE EVERYACTION PANEL AGAINST A MODEL HUB (3.100.0).
 *
 *     php .claude/everyaction-test.php
 *
 * THE HUB IS A STUB, AND EVERY SHAPE IT CAN ANSWER IN IS PLAYED. wp_remote_*
 * are defined here before the plugin loads, so SFAF_EveryAction's real code
 * talks to a hub that logs in, refuses, errors, sends a sign-in page, or
 * cannot be reached, as each case says. What is asserted is what the panel
 * would print, the exact requests the hub received, and what was stored.
 *
 * THREE PROMISES ARE CHECKED ON EVERY CASE, NOT ONCE:
 *   no credential reaches anything the screen shows, the status option, or
 *   PHP's error log;
 *   the password is stored as typed and base64-encoded only on the wire;
 *   a settings save, with the fields blank or absent, keeps what is stored.
 */

require __DIR__ . '/everyaction-hub.php';

/* ---- PHP's error log, to a file this test reads. ---- */
$GLOBALS['ea_log'] = tempnam( sys_get_temp_dir(), 'ealog' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $GLOBALS['ea_log'] );

require __DIR__ . '/wp-kit.php';

/* get_option / update_option, backed by a store, for the credential and status options. */
$fails = array();
function ea( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

/* The credentials, invented, and shaped to catch mistakes: the password has a
 * space inside and one at the end, which a trim would take. */
const KEY  = 'k3y0000000000000000000000000000000000abc';
const USER = 'svc-calendar@sfaf.org';
const PASS = 'p@ss w0rd!x ';
const TOK  = 'SESSION-TOKEN-7f3a9c2e';

function ea_secrets() { return array( KEY, PASS, rtrim( PASS ), base64_encode( PASS ), TOK ); }
function ea_leaks( $text ) {
    $found = array();
    foreach ( ea_secrets() as $s ) { if ( '' !== $s && false !== strpos( (string) $text, $s ) ) { $found[] = strlen( $s ) . '-char secret'; } }
    return $found;
}

function ea_store_creds() {
    update_option( SFAF_Credentials::OPTION, array(
        'everyaction_hub_url'    => 'https://hub.example.org',
        'everyaction_api_key'    => KEY,
        'everyaction_username'   => USER,
        'everyaction_password'   => PASS,
        'everyaction_tracker_id' => '162570',
    ) );
}

function ea_ok_hub( $tracker_body = null ) {
    $GLOBALS['hub'] = array(
        'login'   => hub_resp( 200, json_encode( array( 'ms_response' => array( 'user' => array( '_token' => TOK, 'id' => 9 ) ) ) ) ),
        'tracker' => hub_resp( 200, null !== $tracker_body ? $tracker_body : json_encode( array( 'ms_response' => array( 'total_count' => 57, 'entries' => array( array( 'id' => 1, 'name' => 'Coffee' ) ) ) ) ) ),
        'logout'  => hub_resp( 200, '{}' ),
    );
}

/** Run the Test connection handler as the screen would, and return what it printed. */
function ea_ajax( $action, $post = array() ) {
    $_POST = array_merge( array( 'nonce' => 'n' ), $post );
    $GLOBALS['hub_seen'] = array();
    $obj = new SFAF_EveryAction();
    try {
        $obj->$action();
    } catch ( Kit_Json $j ) {
        return $j->payload;
    }
    return null;
}

/* Options in the kit: a real store, so the two options can be read back. */
$GLOBALS['kit_options'] = array();

function ea_case( $name, $hub, $want_msg, $want_ok, $calls ) {
    $GLOBALS['kit_options'] = array();
    ea_store_creds();
    $GLOBALS['hub'] = $hub;
    $p = ea_ajax( 'ajax_test' );
    ea( is_array( $p ), "$name: the handler printed nothing" );
    if ( ! is_array( $p ) ) { return; }
    ea( $want_ok === $p['success'], "$name: success was " . var_export( $p['success'], true ) );
    ea( $want_msg === $p['data']['message'], "$name: said \"{$p['data']['message']}\", wanted \"$want_msg\"" );
    $paths = array_map( function ( $s ) { return $s['method'] . ' ' . parse_url( $s['url'], PHP_URL_PATH ); }, $GLOBALS['hub_seen'] );
    ea( $paths === $calls, "$name: the hub saw [" . implode( ', ', $paths ) . '], wanted [' . implode( ', ', $calls ) . ']' );
    $leak = ea_leaks( json_encode( $p ) . json_encode( get_option( SFAF_EveryAction::STATUS_OPTION ) ) );
    ea( empty( $leak ), "$name: a credential reached the screen or the status option: " . implode( ', ', $leak ) );
    $st = get_option( SFAF_EveryAction::STATUS_OPTION );
    ea( is_array( $st ) && $st['message'] === $want_msg, "$name: the status option does not hold the outcome" );
}

$LOGIN   = 'POST /api/login.json';
$TRACKER = 'GET /api/v2/trackers/162570/fetch-all-entries';
$LOGOUT  = 'POST /api/logout';

/* ---- 1. Success, and exactly what the hub was sent. ---- */
ea_ok_hub();
ea_case( 'success', $GLOBALS['hub'], 'Connected. Tracker reachable, 57 rows.', true, array( $LOGIN, $TRACKER, $LOGOUT ) );
$seen = $GLOBALS['hub_seen'];
$login_body = json_decode( $seen[0]['args']['body'], true );
ea( 'application/json' === $seen[0]['args']['headers']['Content-Type'], 'the login is not sent as JSON' );
ea( isset( $login_body['ms_request']['user'] ) && array_keys( $login_body['ms_request']['user'] ) === array( 'api_key', 'username', 'password' ), 'the login body is not ms_request.user with api_key, username, password' );
ea( KEY === $login_body['ms_request']['user']['api_key'] && USER === $login_body['ms_request']['user']['username'], 'the login carries the wrong key or username' );
ea( base64_encode( PASS ) === $login_body['ms_request']['user']['password'], 'the password on the wire is not base64 of the password as typed' );
ea( '_felix_session_id=' . TOK === $seen[1]['args']['headers']['Cookie'], 'the tracker read does not carry the session cookie' );
ea( false !== strpos( $seen[1]['url'], 'limit=1' ), 'Test connection does not ask the tracker for one row' );
ea( '_felix_session_id=' . TOK === $seen[2]['args']['headers']['Cookie'], 'the logout does not carry the session cookie' );
ea( PASS === SFAF_Credentials::raw( 'everyaction_password' ), 'the stored password is not the password as typed' );

/* ---- 2. A hub that gives no total. ---- */
ea_ok_hub( json_encode( array( 'ms_response' => array( 'entries' => array( array( 'id' => 1 ) ) ) ) ) );
ea_case( 'no total', $GLOBALS['hub'], 'Connected. Tracker reachable, 1 row in the reply; the hub gave no total.', true, array( $LOGIN, $TRACKER, $LOGOUT ) );

/* ---- 3. Every way the login can fail. ---- */
ea_case( 'login refused', array( 'login' => hub_resp( 200, '{"ms_errors":{"transaction_id":null,"error":{"message":" Login id or Password is Incorrect.","error_code":"AUTHENTICATION_ERROR"}}}' ) ),
    'Login failed: Login id or Password is Incorrect.', false, array( $LOGIN ) );
ea_case( 'login 500', array( 'login' => hub_resp( 500, '' ) ), 'Login failed: HTTP 500.', false, array( $LOGIN ) );
ea_case( 'login without a token', array( 'login' => hub_resp( 200, '{"ms_response":{"user":{}}}' ) ),
    'Login failed: the hub answered without a session token.', false, array( $LOGIN ) );
ea_case( 'hub unreachable', array( 'login' => function () { return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: hub.example.org' ); } ),
    'Login failed: could not reach the hub (cURL error 6: Could not resolve host: hub.example.org).', false, array( $LOGIN ) );

/* ---- 4. Every way the tracker read can fail, and the logout still happens. ---- */
ea_ok_hub();
$h = $GLOBALS['hub']; $h['tracker'] = hub_resp( 401, '{"ms_errors":{"error":{"message":"Unauthorized access"}}}' );
ea_case( 'tracker refused', $h, 'Tracker read failed: Unauthorized access.', false, array( $LOGIN, $TRACKER, $LOGOUT ) );
$h['tracker'] = hub_resp( 403, '' );
ea_case( 'tracker 403', $h, 'Tracker read failed: HTTP 403.', false, array( $LOGIN, $TRACKER, $LOGOUT ) );
$h['tracker'] = hub_resp( 200, '<!DOCTYPE html><title>Sign in to your account</title>', 'text/html; charset=utf-8' );
ea_case( 'a sign-in page', $h, 'Tracker read failed: the hub answered with a web page (text/html; charset=utf-8), not data.', false, array( $LOGIN, $TRACKER, $LOGOUT ) );

/* ---- 5. A failed logout after a good read is said, not hidden. ---- */
ea_ok_hub();
$h = $GLOBALS['hub']; $h['logout'] = hub_resp( 500, '' );
ea_case( 'logout failed', $h, 'Connected. Tracker reachable, 57 rows. Logout failed: HTTP 500.', true, array( $LOGIN, $TRACKER, $LOGOUT ) );

/* ---- 6. A hub that echoes the credentials back never gets them onto the screen. ---- */
ea_case( 'echoing hub', array( 'login' => hub_resp( 200, json_encode( array( 'ms_errors' => array( 'error' => array( 'message' => 'Bad login for key ' . KEY . ' with ' . base64_encode( PASS ) ) ) ) ) ) ),
    'Login failed: Bad login for key [hidden, 40 characters] with [hidden, ' . strlen( base64_encode( PASS ) ) . ' characters].', false, array( $LOGIN ) );

/* ---- 7. Nothing to try with. ---- */
$GLOBALS['kit_options'] = array();
$GLOBALS['hub_seen'] = array();
$p = ea_ajax( 'ajax_test' );
ea( 'Not tried. Fill in the API key, the username, the password first.' === $p['data']['message'], 'with nothing stored, the test says: ' . $p['data']['message'] );
ea( empty( $GLOBALS['hub_seen'] ), 'with nothing stored, the hub was called anyway' );

/* ---- 8. The screen's own fields win over storage, and blank means stored. ---- */
$GLOBALS['kit_options'] = array();
ea_store_creds();
ea_ok_hub();
$p = ea_ajax( 'ajax_test', array( 'hub' => 'https://hub.example.org/', 'api_key' => '', 'username' => 'typed-user', 'password' => '', 'tracker' => '999' ) );
$lb = json_decode( $GLOBALS['hub_seen'][0]['args']['body'], true );
ea( 'typed-user' === $lb['ms_request']['user']['username'], 'a typed username was not used' );
ea( KEY === $lb['ms_request']['user']['api_key'] && base64_encode( PASS ) === $lb['ms_request']['user']['password'], 'a blank key or password did not fall back to the stored one' );
ea( false !== strpos( $GLOBALS['hub_seen'][1]['url'], '/api/v2/trackers/999/' ), 'a typed tracker ID was not used' );

/* ---- 9. The probe: the body as sent, the count, and nothing written. ---- */
$GLOBALS['kit_options'] = array();
ea_store_creds();
$raw = '{"ms_response":{"entries":[{"id":1,"Series ID":"A"},{"id":2,"Series ID":"A"}],"note":"echo ' . TOK . '"}}';
ea_ok_hub( $raw );
$before = json_encode( $GLOBALS['kit_options'] );
$p = ea_ajax( 'ajax_probe' );
ea( true === $p['success'], 'the probe did not answer' );
$d = $p['data'];
ea( str_replace( TOK, '[hidden, ' . strlen( TOK ) . ' characters]', $raw ) === $d['body'], 'the probe did not print the body as the hub sent it, with only the session token masked' );
ea( 2 === $d['rows'] && 'ms_response.entries' === $d['where'], 'the probe counted ' . var_export( $d['rows'], true ) . ' rows at ' . $d['where'] );
ea( false === strpos( $GLOBALS['hub_seen'][1]['url'], 'limit' ), 'the probe asked for fewer rows than the first page' );
ea( 'POST /api/logout' === $GLOBALS['hub_seen'][2]['method'] . ' ' . parse_url( $GLOBALS['hub_seen'][2]['url'], PHP_URL_PATH ), 'the probe did not log out' );
ea( $before === json_encode( $GLOBALS['kit_options'] ), 'the probe wrote something' );
ea( empty( ea_leaks( json_encode( $p ) ) ), 'the probe printed a credential' );
$GLOBALS['hub']['login'] = hub_resp( 200, '{"ms_errors":{"error":{"message":" Login id or Password is Incorrect."}}}' );
$p = ea_ajax( 'ajax_probe' );
ea( 'Login failed: Login id or Password is Incorrect.' === $p['data']['error'], 'a refused probe says: ' . $p['data']['error'] );

/* ---- 10. A settings save keeps what is stored, and stores the password as typed. ---- */
$GLOBALS['kit_options'] = array();
$admin = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
$out = $admin->sanitize_settings( array(
    'everyaction_hub_url' => 'https://hub.sfaf.org', 'everyaction_api_key' => KEY, 'everyaction_username' => USER,
    'everyaction_password' => PASS, 'everyaction_tracker_id' => '162570',
) );
ea( KEY === SFAF_Credentials::get( 'everyaction_api_key' ), 'a settings save did not store the API key' );
ea( PASS === SFAF_Credentials::raw( 'everyaction_password' ), 'a settings save did not store the password exactly as typed' );
ea( base64_encode( PASS ) !== SFAF_Credentials::raw( 'everyaction_password' ), 'the password is stored encoded' );
ea( '162570' === SFAF_Credentials::get( 'everyaction_tracker_id' ) && USER === SFAF_Credentials::get( 'everyaction_username' ), 'the username or tracker ID was not stored' );
ea( empty( ea_leaks( json_encode( $out ) ) ), 'a credential went into uc_settings, which is rebuilt on every save' );
$admin->sanitize_settings( array( 'everyaction_api_key' => '', 'everyaction_password' => '', 'everyaction_username' => USER ) );
ea( KEY === SFAF_Credentials::get( 'everyaction_api_key' ) && PASS === SFAF_Credentials::raw( 'everyaction_password' ), 'a save with the write-only fields blank dropped a stored credential' );
$admin->sanitize_settings( array( 'display_per_page' => '12' ) );
ea( KEY === SFAF_Credentials::get( 'everyaction_api_key' ) && PASS === SFAF_Credentials::raw( 'everyaction_password' ), 'a save of some other setting dropped a stored credential' );

/* ---- 11. The panel as the screen draws it, with credentials stored. ---- */
ea_store_creds();
update_option( SFAF_EveryAction::STATUS_OPTION, array( 'connected' => true, 'message' => 'Connected. Tracker reachable, 57 rows.', 'checked_at' => time() ) );
ob_start();
try { $admin->render_settings_page(); } catch ( Throwable $t ) { echo 'RENDER FAILED: ' . $t->getMessage(); }
$page = (string) ob_get_clean();
ea( false === strpos( $page, 'RENDER FAILED' ), 'the settings page did not render: ' . substr( $page, strpos( $page, 'RENDER FAILED' ), 200 ) );
ea( empty( ea_leaks( $page ) ), 'a stored credential is on the settings page: ' . implode( ', ', ea_leaks( $page ) ) );
$a = strpos( $page, '<!-- EVERYACTION -->' ); $b = strpos( $page, '<!-- PARDOT' );
$panel = ( false !== $a && false !== $b ) ? substr( $page, $a, $b - $a ) : '';
ea( '' !== $panel, 'the EveryAction panel is not on the settings page, between Eventbrite and Pardot' );
file_put_contents( __DIR__ . '/everyaction-panel.html', $panel );

/* ---- 12. And none of it reached PHP's error log. ---- */
$log = (string) file_get_contents( $GLOBALS['ea_log'] );
ea( empty( ea_leaks( $log ) ), 'a credential reached the error log' );
@unlink( $GLOBALS['ea_log'] );

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "against a model hub: success, no total, four login failures, three tracker failures, a failed logout,\n";
echo "an echoing hub and nothing stored each print the message they should, and call the hub as they should;\n";
echo "the probe prints the body as sent and writes nothing; a settings save keeps every credential and\n";
echo "stores the password as typed; and no credential reaches the screen, the status option or the log.\n";
exit( 0 );
