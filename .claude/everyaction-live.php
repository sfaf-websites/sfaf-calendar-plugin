<?php
/**
 * THE EVERYACTION PANEL IN A REAL BROWSER (3.100.0).
 *
 *     php .claude/everyaction-live.php          writes the page
 *     php .claude/everyaction-live.php --run    writes it and drives Chrome
 *
 * THE PANEL IS THE REAL ONE, cut out of render_settings_page() as the settings
 * screen draws it, with the real admin.css and the real admin/js/everyaction.js.
 *
 * THE ANSWERS ARE THE REAL HANDLERS'. Each press on the page is answered by
 * the payload SFAF_EveryAction's own ajax handler printed, run in PHP against
 * the model hub in everyaction-hub.php. The page's fetch is replaced only to
 * hand those payloads back in order and to record what the page posted. So
 * what is on screen after each press is what the plugin says, drawn by the
 * script that ships.
 *
 * WHAT IT CHECKS: the five fields, their types and what they show; the two
 * buttons; the Auto-Import switch, off by default, and the last fetch and
 * signup-link lines (3.101.0); each press's message, pill and
 * header badge for success and for three failures; the probe's summary and
 * its body; that the page posted the typed password as typed; and that no
 * stored credential appears anywhere in the page, before or after.
 */

require __DIR__ . '/everyaction-hub.php';
require __DIR__ . '/wp-kit.php';

$fails = array();
function el_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

const L_KEY  = 'k3y0000000000000000000000000000000000abc';
const L_PASS = 'p@ss w0rd!x ';
const L_TOK  = 'SESSION-TOKEN-7f3a9c2e';

/* Stored: the key, the username and the password. The hub and the tracker are
 * left unset, so the panel has to show its defaults. */
update_option( SFAF_Credentials::OPTION, array(
    'everyaction_api_key'  => L_KEY,
    'everyaction_username' => 'svc-calendar@sfaf.org',
    'everyaction_password' => L_PASS,
) );

$admin = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
ob_start();
$admin->render_settings_page();
$page = (string) ob_get_clean();
$a = strpos( $page, '<!-- EVERYACTION -->' );
$b = strpos( $page, '<!-- PARDOT' );
if ( false === $a || false === $b ) { echo "FAIL: the panel is not on the settings page\n"; exit( 1 ); }
$panel = substr( $page, $a, $b - $a );

/* The payloads, in the order the page will ask for them. */
function el_run( $action, $post = array() ) {
    $_POST = array_merge( array( 'nonce' => 'n' ), $post );
    $o = new SFAF_EveryAction();
    try { $o->$action(); } catch ( Kit_Json $j ) { return $j->payload; }
    return null;
}
$ok_login = hub_resp( 200, json_encode( array( 'ms_response' => array( 'user' => array( '_token' => L_TOK ) ) ) ) );
$ok_hub   = array(
    'login'   => $ok_login,
    'tracker' => hub_resp( 200, '{"ms_response":{"data":[{"RowId":1},{"RowId":2},{"RowId":3}]}}' ),
    'logout'  => hub_resp( 200, '{}' ),
);
$steps = array();

$GLOBALS['hub'] = $ok_hub;
$steps[] = array( 'press' => 'test', 'payload' => el_run( 'ajax_test' ),
    'msg' => 'Connected. Tracker reachable, 3 rows.', 'pill' => 'Connected', 'badge' => 'Connected' );

$GLOBALS['hub'] = array( 'login' => hub_resp( 200, '{"ms_errors":{"error":{"message":" Login id or Password is Incorrect.","error_code":"AUTHENTICATION_ERROR"}}}' ) );
$steps[] = array( 'press' => 'test', 'payload' => el_run( 'ajax_test' ),
    'msg' => 'Login failed: Login id or Password is Incorrect.', 'pill' => 'Not connected', 'badge' => 'Not connected' );

$GLOBALS['hub'] = array( 'login' => hub_resp( 502, '' ) );
$steps[] = array( 'press' => 'test', 'payload' => el_run( 'ajax_test' ),
    'msg' => 'Login failed: HTTP 502.', 'pill' => 'Not connected', 'badge' => 'Not connected' );

$GLOBALS['hub'] = $ok_hub;
$GLOBALS['hub']['tracker'] = hub_resp( 200, '<!DOCTYPE html><title>Sign in to your account</title>', 'text/html; charset=utf-8' );
$steps[] = array( 'press' => 'test', 'payload' => el_run( 'ajax_test' ),
    'msg' => 'Tracker read failed: the hub answered with a web page (text/html; charset=utf-8), not data.', 'pill' => 'Not connected', 'badge' => 'Not connected' );

$raw = '{"ms_response":{"data":[{"RowId":1,"Series_ID":"A"},{"RowId":2,"Series_ID":"A"}]}}';
$GLOBALS['hub'] = $ok_hub;
$GLOBALS['hub']['tracker'] = hub_resp( 200, $raw );
$steps[] = array( 'press' => 'probe', 'payload' => el_run( 'ajax_probe' ),
    'msg' => 'GET /api/trackers/forms/get_submissions/162570: HTTP 200, application/json; charset=utf-8, ' . strlen( $raw ) . ' bytes, 2 rows at ms_response.data. Logged out. Nothing was imported or changed.',
    'body' => $raw );

/* The refusal the version 2 read gave (3.100.2): the body shown, the refusal said. */
$refused = '{"ms_error":"You don\'t have permission."}';
$GLOBALS['hub'] = $ok_hub;
$GLOBALS['hub']['tracker'] = hub_resp( 200, $refused );
$steps[] = array( 'press' => 'probe', 'payload' => el_run( 'ajax_probe' ),
    'msg' => 'GET /api/trackers/forms/get_submissions/162570: HTTP 200, application/json; charset=utf-8, ' . strlen( $refused ) . ' bytes, no list of rows at ms_response.data. Tracker read failed: You don\'t have permission. Logged out. Nothing was imported or changed.',
    'body' => $refused );

$GLOBALS['hub'] = array( 'login' => hub_resp( 200, '{"ms_errors":{"error":{"message":" Login id or Password is Incorrect."}}}' ) );
$steps[] = array( 'press' => 'probe', 'payload' => el_run( 'ajax_probe' ),
    'msg' => 'Login failed: Login id or Password is Incorrect.', 'body' => null );

foreach ( $steps as $i => $s ) {
    el_check( is_array( $s['payload'] ), "step $i: the handler printed nothing" );
}

$payloads = array_map( function ( $s ) { return $s['payload']; }, $steps );
$presses  = array_map( function ( $s ) { return $s['press']; }, $steps );

$probe = <<<'JS'
(function () {
  var queue = window.EL_PAYLOADS.slice(), posted = [], out = { errors: [], steps: [], fields: {}, posted: posted };
  window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
  window.sfafAdmin = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n' };
  window.fetch = function (url, opts) {
    var got = {}; opts.body.forEach(function (v, k) { got[k] = v; }); posted.push(got);
    var p = queue.shift();
    return Promise.resolve({ status: 200, json: function () { return Promise.resolve(p); } });
  };
  function txt(sel) { var e = document.querySelector(sel); return e ? e.textContent.trim() : null; }
  function shown(sel) { var e = document.querySelector(sel); return !!(e && !e.hidden && e.getClientRects().length); }
  function wait() { return new Promise(function (r) { setTimeout(r, 60); }); }
  window.addEventListener('load', function () {
    /* Opened as a person opens it: the header is what toggles the body. */
    document.querySelector('.uc-everyaction-panel .uc-panel-header').click();
    out.openedBody = shown('.uc-everyaction-panel .uc-panel-body');
    /* The password as a person would type it, to see what is posted. */
    document.querySelector('[name="uc_settings[everyaction_password]"]').value = 'typed pass ';
    ['everyaction_hub_url', 'everyaction_api_key', 'everyaction_username', 'everyaction_password', 'everyaction_tracker_id'].forEach(function (n) {
      var e = document.querySelector('[name="uc_settings[' + n + ']"]');
      out.fields[n] = e ? { type: e.type, value: e.value, label: (document.querySelector('label[for="' + e.id + '"]') || {}).textContent || '' } : null;
    });
    out.buttons = [txt('.uc-everyaction-connect'), txt('.uc-everyaction-probe')];
    var auto = document.querySelector('[name="uc_settings[everyaction_auto_import]"]');
    out.auto = auto ? { type: auto.type, checked: auto.checked, label: (document.querySelector('label[for="' + auto.id + '"]') || {}).textContent || '' } : null;
    out.lastFetch = txt('.uc-everyaction-last-fetch');
    out.links = txt('.uc-everyaction-links');
    out.notBuilt = document.body.textContent.indexOf('Nothing is imported yet') >= 0;
    out.heading = txt('.uc-everyaction-panel h2') + ' / ' + txt('.uc-everyaction-panel .uc-panel-info p');
    var chain = Promise.resolve();
    window.EL_PRESSES.forEach(function (press) {
      chain = chain.then(function () {
        document.querySelector(press === 'test' ? '.uc-everyaction-connect' : '.uc-everyaction-probe').click();
        return wait();
      }).then(function () {
        out.steps.push(press === 'test'
          ? { msg: txt('.uc-everyaction-conn-msg'), msgShown: shown('.uc-everyaction-conn-msg'), pill: txt('.uc-everyaction-pill'), badge: txt('.uc-everyaction-panel-status') }
          : { msg: txt('.uc-everyaction-probe-msg'), msgShown: shown('.uc-everyaction-probe-msg'), body: shown('.uc-everyaction-probe-out') ? document.querySelector('.uc-everyaction-probe-out').textContent : null });
      });
    });
    chain.then(function () {
      out.html = document.documentElement.outerHTML.length;
      out.pageText = document.body.innerText;
      var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
    });
  });
})();
JS;

$root = $GLOBALS['kit_root'];
$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>EveryAction panel</title>'
    . '<link rel="stylesheet" href="file:///' . $root . '/admin/css/admin.css">'
    . '<script>window.EL_PAYLOADS = ' . json_encode( $payloads ) . '; window.EL_PRESSES = ' . json_encode( $presses ) . ';</script>'
    . '<script>' . $probe . '</script>'
    . '</head><body class="wp-admin"><div class="wrap uc-admin-wrap"><form>' . $panel . '</form></div>'
    . '<script src="file:///' . $root . '/admin/js/everyaction.js"></script>'
    . '</body></html>';
$file = __DIR__ . '/everyaction-live.html';
file_put_contents( $file, $html );
echo "wrote the page\n";

el_check( false === strpos( $html, L_KEY ) && false === strpos( $html, L_PASS ) && false === strpos( $html, base64_encode( L_PASS ) ) && false === strpos( $html, L_TOK ),
    'a stored credential is in the page as written' );

if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    if ( $fails ) { echo 'FAIL: ' . implode( '; ', $fails ) . "\n"; exit( 1 ); }
    exit( 0 );
}

$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
$url = 'file:///' . str_replace( '\\', '/', $file );
$dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1200,1600 --virtual-time-budget=8000 --dump-dom "' . $url . '" 2>NUL' );
if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { echo "FAIL: Chrome returned no probe block\n"; exit( 1 ); }
$got = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );

el_check( ! empty( $got['openedBody'] ), 'pressing the header did not open the panel' );
el_check( empty( $got['errors'] ), 'the page threw: ' . implode( '; ', (array) $got['errors'] ) );
el_check( 'EveryAction / Events from Val\'s tracker on the hub' === $got['heading'], 'the heading reads ' . $got['heading'] );
$want_fields = array(
    'everyaction_hub_url'    => array( 'url', 'https://hub.sfaf.org', 'Hub address' ),
    'everyaction_api_key'    => array( 'password', '', 'API key' ),
    'everyaction_username'   => array( 'text', 'svc-calendar@sfaf.org', 'Username' ),
    'everyaction_password'   => array( 'password', 'typed pass ', 'Password' ),
    'everyaction_tracker_id' => array( 'text', '162570', 'Tracker ID' ),
);
foreach ( $want_fields as $n => $w ) {
    $f = isset( $got['fields'][ $n ] ) ? $got['fields'][ $n ] : null;
    el_check( is_array( $f ) && $w[0] === $f['type'] && $w[1] === $f['value'] && $w[2] === trim( $f['label'] ),
        "$n: " . json_encode( $f ) . ', wanted ' . json_encode( $w ) );
}
el_check( array( 'Test connection', 'Probe tracker' ) === $got['buttons'], 'the buttons read ' . json_encode( $got['buttons'] ) );
el_check( false === $got['notBuilt'], 'the "nothing is imported yet" line is still on the panel' );
el_check( is_array( $got['auto'] ) && 'checkbox' === $got['auto']['type'] && false === $got['auto']['checked'] && 'Auto-Import events' === trim( $got['auto']['label'] ),
    'the Auto-Import switch: ' . json_encode( $got['auto'] ) );
el_check( 'Not fetched yet.' === $got['lastFetch'] && 'Not read yet.' === $got['links'], 'the status lines read ' . json_encode( array( $got['lastFetch'], $got['links'] ) ) );

foreach ( $steps as $i => $s ) {
    $r = isset( $got['steps'][ $i ] ) ? $got['steps'][ $i ] : null;
    if ( ! $r ) { $fails[] = "press $i: no reading"; continue; }
    el_check( $s['msg'] === $r['msg'] && $r['msgShown'], "press $i ({$s['press']}): the screen says \"{$r['msg']}\", wanted \"{$s['msg']}\"" );
    if ( 'test' === $s['press'] ) {
        el_check( $s['pill'] === $r['pill'] && $s['badge'] === $r['badge'], "press $i: pill \"{$r['pill']}\" and badge \"{$r['badge']}\", wanted \"{$s['pill']}\" and \"{$s['badge']}\"" );
    } else {
        el_check( $s['body'] === $r['body'], "press $i: the probe body on screen is not the body the hub sent" );
    }
}
$first = isset( $got['posted'][0] ) ? $got['posted'][0] : array();
el_check( 'sfaf_everyaction_test' === ( $first['action'] ?? '' ) && 'typed pass ' === ( $first['password'] ?? '' ) && '' === ( $first['api_key'] ?? 'x' ),
    'the page did not post the typed password as typed with a blank key for "stored": ' . json_encode( array_diff_key( $first, array( 'password' => 1 ) ) ) );
el_check( 'sfaf_everyaction_probe' === ( $got['posted'][4]['action'] ?? '' ), 'the probe button posted the wrong action' );
foreach ( array( L_KEY, L_PASS, base64_encode( L_PASS ), L_TOK ) as $secret ) {
    el_check( false === strpos( (string) $got['pageText'], $secret ) && false === strpos( (string) $dom, $secret ), 'a credential is on the screen after the presses' );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "the panel: five fields with the right types and defaults, both buttons, Auto-Import off, and the two status lines;\n";
echo "Test connection on screen for success, a refused login, an HTTP error and a sign-in page, pill and\n";
echo "badge following; the probe's summary and the body as the hub sent it; and no credential on screen.\n";
exit( 0 );
