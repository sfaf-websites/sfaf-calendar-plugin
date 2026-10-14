<?php
/**
 * EVERY CALADMIN POST WITH A BAD TOKEN REACHES THE SESSION-ENDED PAGE (3.110.5).
 *
 *     php .claude/session-ended-test.php
 *
 * The actions are read out of dispatch_post() with the tokenizer, every
 * `case '...'`, so a route added next month is in this list without anybody
 * remembering to add it. Each is posted through the real handle(), through
 * wp-kit.php, three ways:
 *
 *   a stale token, signed in       the page, and Sign in going straight back
 *   no token at all, signed in     the same
 *   signed out                     the page, and Sign in going to the sign-in
 *                                  with the screen as redirect_to
 *
 * and for each: our own document (body.uc-portal, which is what hides the
 * language switcher), the sentence, Sign in to the screen with ?uc_restore=1,
 * nothing acted on (no redirect, no wp_die, no JSON), and never "Security
 * check failed". Then the two exports, a background request (JSON in the same
 * words), the sign-in's return address, the endpoint caladmin-session.js asks,
 * and a source sweep for the old sentence as a string anywhere in the portal.
 *
 * PLANT A.3 puts wp_die( 'Security check failed.' ) back on the token check.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }

$fails = array();
$ran   = 0;
function se_check( $ok, $why ) { global $fails, $ran; $ran++; if ( ! $ok ) { $fails[] = $why; } }

$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-portal.php' );

/* ---- The actions, from dispatch_post() itself ----------------------------- */
$tokens  = token_get_all( $src );
$actions = array();
$inside  = false; $depth = 0; $armed = false;
for ( $i = 0, $n = count( $tokens ); $i < $n; $i++ ) {
    $t = $tokens[ $i ];
    if ( ! $inside ) {
        if ( is_array( $t ) && T_FUNCTION === $t[0] ) {
            for ( $j = $i + 1; $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0]; $j++ ) {}
            if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] && 'dispatch_post' === $tokens[ $j ][1] ) { $armed = true; }
        }
        if ( $armed && '{' === $t ) { $inside = true; $depth = 1; }
        continue;
    }
    $c = is_array( $t ) ? $t[1] : $t;
    if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) { $depth++; }
    if ( '}' === $t ) { $depth--; if ( 0 === $depth ) { break; } }
    if ( is_array( $t ) && T_CASE === $t[0] ) {
        for ( $j = $i + 1; $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0]; $j++ ) {}
        if ( is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0] ) { $actions[] = trim( $tokens[ $j ][1], "'\"" ); }
    }
}
$actions = array_values( array_unique( $actions ) );
se_check( count( $actions ) >= 40, 'the tokenizer found only ' . count( $actions ) . ' actions in dispatch_post(), so the sweep below is not the whole list' );
foreach ( array( 'save_event', 'bulk_events', 'save_team', 'delete_team', 'checkin_toggle', 'save_preferences' ) as $known ) {
    se_check( in_array( $known, $actions, true ) || false === strpos( $src, "case '$known'" ), "the action list is missing $known" );
}

/* ---- One post through handle() --------------------------------------------- */
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function se_post( $route, $post, $nonce_bad, $logged_out, $get = array(), $method = 'POST' ) {
    global $portal;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['QUERY_STRING']   = http_build_query( $get );
    $_SERVER['HTTP_REFERER']   = '';
    $_POST = $post; $_GET = $get; $_REQUEST = array_merge( $get, $post );
    $GLOBALS['kit_nonce_bad']        = $nonce_bad;
    $GLOBALS['kit_logged_out']       = $logged_out;
    $GLOBALS['kit_redirect_throws']  = true;
    $GLOBALS['kit_redirect']         = null;
    $d = ob_get_level(); ob_start();
    $threw = '';
    try { $portal->handle( $route ); }
    catch ( KitRedirect $e ) { $threw = 'REDIRECT ' . $e->getMessage(); }
    catch ( KitJson $e ) { $threw = 'JSON ' . json_encode( $e->payload ); }
    catch ( Throwable $e ) { $threw = 'THREW ' . $e->getMessage(); }
    $html = '';
    while ( ob_get_level() > $d ) { $html = ob_get_clean() . $html; }
    $GLOBALS['kit_nonce_bad'] = false; $GLOBALS['kit_logged_out'] = false; $GLOBALS['kit_redirect_throws'] = false;
    return array( $html, $threw );
}
function se_signin_href( $html ) {
    if ( ! preg_match( '#<a [^>]*href="([^"]*)"[^>]*data-uc-session-signin#', $html, $m ) ) { return null; }
    return html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
}
$save_line = SFAF_Portal::SESSION_ENDED['save'];
se_check( 'Your session had ended, so that change was not saved. Sign in and your form will be restored.' === $save_line, 'the sentence is not the one asked for: ' . $save_line );

$screen = 'https://resources.sfaf.org/caladmin/events/edit/12';
$modes  = array(
    'stale token'    => array( array( 'uc_nonce' => 'stale' ), true, false ),
    'no token'       => array( array(), true, false ),
    'signed out'     => array( array( 'uc_nonce' => 'stale' ), false, true ),
);
$bad = array();
foreach ( $actions as $a ) {
    foreach ( $modes as $mode => $m ) {
        list( $extra, $nonce_bad, $out ) = $m;
        list( $html, $threw ) = se_post( 'events/edit/12', array_merge( array( 'uc_action' => $a, '_wp_http_referer' => '/caladmin/events/edit/12?msg=saved' ), $extra ), $nonce_bad, $out );
        if ( in_array( $a, array( 'login', 'forgot_password', 'reset_password' ), true ) ) { continue; }
        $why = array();
        if ( '' !== $threw ) { $why[] = $threw; }
        if ( false === strpos( $html, '<!DOCTYPE html>' ) || false === strpos( $html, '<body class="uc-portal">' ) ) { $why[] = 'not caladmin\'s own document'; }
        if ( false === strpos( $html, 'data-uc-session-ended>' . esc_html( $save_line ) . '</p>' ) ) { $why[] = 'no session-ended sentence'; }
        if ( false !== stripos( $html, 'Security check failed' ) ) { $why[] = 'says Security check failed'; }
        if ( false !== strpos( $html, 'uc-portal-nav' ) ) { $why[] = 'drew a screen as well'; }
        $href = se_signin_href( $html );
        if ( $out ) {
            $want = 'https://resources.sfaf.org/caladmin?redirect_to=' . rawurlencode( $screen . '?uc_restore=1' );
            if ( $href !== $want ) { $why[] = 'Sign in goes to ' . var_export( $href, true ); }
        } elseif ( $href !== $screen . '?uc_restore=1' ) {
            $why[] = 'Sign in goes to ' . var_export( $href, true );
        }
        if ( $why ) { $bad[] = "$a ($mode): " . implode( '; ', $why ); }
        $ran++;
    }
}
se_check( ! $bad, 'PLANT A.3: ' . count( $bad ) . " post(s) do not reach the session-ended page:\n      " . implode( "\n      ", array_slice( $bad, 0, 12 ) ) );

/* A post that did not come from caladmin goes back to the screen it was sent to. */
list( $html ) = se_post( 'series/edit/4', array( 'uc_action' => 'save_series', 'uc_nonce' => 'x', '_wp_http_referer' => 'https://elsewhere.example/caladmin/x' ), true, false );
se_check( se_signin_href( $html ) === 'https://resources.sfaf.org/caladmin/series/edit/4?uc_restore=1', 'a foreign referer was followed: ' . var_export( se_signin_href( $html ), true ) );
list( $html ) = se_post( 'series', array( 'uc_action' => 'save_series', 'uc_nonce' => 'x', '_wp_http_referer' => '/caladminx/evil' ), true, false );
se_check( se_signin_href( $html ) === 'https://resources.sfaf.org/caladmin/series?uc_restore=1', 'a lookalike address was followed: ' . var_export( se_signin_href( $html ), true ) );

/* A background request gets the same words as JSON, not a page. */
list( $html, $threw ) = se_post( 'rsvps', array( 'uc_action' => 'registrant_mail_preview', 'uc_nonce' => 'x', 'uc_ajax' => '1' ), true, false );
se_check( 0 === strpos( $threw, 'JSON ' ) && false !== strpos( $threw, '"success":false' ) && false !== strpos( $threw, json_encode( $save_line ) ) && false !== strpos( $threw, '"status":403' ), 'PLANT A.3: a background post with a bad token: ' . $threw );

/* The two exports. */
foreach ( array( 'rsvps/export', 'optins/export' ) as $route ) {
    foreach ( array( 'stale' => array( true, false ), 'signed out' => array( false, true ) ) as $mode => $m ) {
        list( $html, $threw ) = se_post( $route, array(), $m[0], $m[1], array( '_wpnonce' => 'x' ), 'GET' );
        se_check( '' === $threw && false !== strpos( $html, SFAF_Portal::SESSION_ENDED['download'] ) && false === stripos( $html, 'Security check failed' ),
            "PLANT A.3: $route ($mode) does not reach the session-ended page: " . ( $threw ? $threw : substr( strip_tags( $html ), 0, 160 ) ) );
    }
}

/* Signed out on a screen: the sign-in comes back to it. */
list( $html ) = se_post( 'events/edit/12', array(), false, true, array( 'uc_restore' => '1' ), 'GET' );
se_check( false !== strpos( $html, 'name="redirect_to" value="' . esc_attr( $screen . '?uc_restore=1' ) . '"' ), 'the sign-in on a screen does not carry the screen as redirect_to' );
list( $html ) = se_post( '', array(), false, true, array(), 'GET' );
se_check( false !== strpos( $html, 'name="uc_action" value="login"' ) && false === strpos( $html, 'name="redirect_to"' ), 'the plain sign-in carries a redirect_to' );

/* Where a sign-in may send somebody. */
foreach ( array(
    'https://resources.sfaf.org/caladmin/events/edit/12?uc_restore=1' => 'https://resources.sfaf.org/caladmin/events/edit/12?uc_restore=1',
    'https://resources.sfaf.org/caladmin'                            => 'https://resources.sfaf.org/caladmin',
    'https://elsewhere.example/caladmin/events'                      => '',
    'https://resources.sfaf.org/caladminx'                           => '',
    'https://resources.sfaf.org/wp-admin/'                           => '',
) as $asked => $want ) {
    $_REQUEST = array( 'redirect_to' => $asked );
    $got = kit_call( 'SFAF_Portal', 'login_return', $portal );
    se_check( $want === $got, "login_return( $asked ) is " . var_export( $got, true ) );
}
$_REQUEST = array();

/* The endpoint the browser asks. */
function se_ajax( $post, $nonce_bad, $out ) {
    global $portal;
    $_POST = $post;
    $GLOBALS['kit_nonce_bad'] = $nonce_bad; $GLOBALS['kit_logged_out'] = $out;
    try { $portal->ajax_session(); $r = 'NO ANSWER'; } catch ( KitJson $e ) { $r = $e->payload; } catch ( Throwable $e ) { $r = 'THREW ' . $e->getMessage(); }
    $GLOBALS['kit_nonce_bad'] = false; $GLOBALS['kit_logged_out'] = false;
    return $r;
}
se_check( array( 'success' => true, 'data' => array( 'signed_in' => false, 'valid' => false ) ) === se_ajax( array( 'check' => 'save_event', 'nonce' => 'n' ), false, true ), 'signed out is not answered as signed out' );
se_check( array( 'success' => true, 'data' => array( 'signed_in' => true, 'valid' => false ) ) === se_ajax( array( 'check' => 'save_event', 'nonce' => 'n' ), true, false ), 'a stale token is not answered as stale' );
se_check( array( 'success' => true, 'data' => array( 'signed_in' => true, 'valid' => true ) ) === se_ajax( array( 'check' => 'save_event', 'nonce' => 'n' ), false, false ), 'a good token is not answered as good' );
$hooks = array_keys( isset( $GLOBALS['kit_hooks'] ) ? $GLOBALS['kit_hooks'] : array() );
se_check( false !== strpos( $src, "add_action( 'wp_ajax_' . self::SESSION_ACTION" ) && false !== strpos( $src, "add_action( 'wp_ajax_nopriv_' . self::SESSION_ACTION" ), 'the endpoint is not registered for signed-in and signed-out requests' );

/* The script is on every caladmin page, ahead of the footer scripts and portal.js. */
list( $html ) = se_post( '', array(), false, true, array(), 'GET' );
$at_cfg = strpos( $html, 'id="uc-session-config"' ); $at_js = strpos( $html, 'caladmin-session.js' ); $at_portal = strpos( $html, 'public/js/portal.js' );
se_check( false !== $at_cfg && false !== $at_js && false !== $at_portal && $at_cfg < $at_js && $at_js < $at_portal, 'caladmin-session.js and its settings are not printed ahead of portal.js' );
$foot = substr( $src, strpos( $src, 'private function foot()' ), 3000 );
se_check( strpos( $foot, 'caladmin-session.js' ) < strpos( $foot, 'wp_print_footer_scripts' ), 'caladmin-session.js is printed after the footer scripts, so TinyMCE reads the fields before a restore' );

/* The old sentence, as a string, anywhere in the portal. */
$left = array();
foreach ( $tokens as $t ) {
    if ( is_array( $t ) && in_array( $t[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ), true ) && false !== stripos( $t[1], 'Security check failed' ) ) { $left[] = 'line ' . $t[2]; }
}
se_check( ! $left, 'PLANT A.3: "Security check failed" is still a string in the portal: ' . implode( ', ', $left ) );

if ( $fails ) {
    echo 'SESSION ENDED: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo 'session ended: ' . count( $actions ) . ' caladmin actions, each with a stale token, no token and signed out, reach the one page; the exports, a background post, the sign-in\'s return and the endpoint agree; ' . $ran . " checks.\n";
exit( 0 );
