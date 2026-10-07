<?php
/**
 * PASSWORDS IN CALADMIN (3.109.0).
 *
 *     php .claude/password-pages-test.php
 *
 * The real handlers and pages on wp-kit.php, with WordPress's password
 * functions stood in for by recorders: what they were called with is the
 * assertion, because what they do is WordPress's job.
 *
 *   C.1  "Forgot your password?" under the sign-in form; the forgot page sends
 *        through retrieve_password() for an address with an account and not
 *        for one without, and the page that follows is the same either way,
 *        so it cannot tell anybody who has an account; the email's link is
 *        rewritten to caladmin's reset page; the reset page sets the password
 *        through reset_password() and goes back to the sign-in, refuses two
 *        different passwords, and says an expired link is expired
 *   C.2  Change password on Preferences: a wrong current password, an empty
 *        new one and two that differ each come back as an inline error and
 *        write nothing; the right one writes through wp_update_user(), which
 *        keeps the person signed in, and says so
 *   C.3  no link to the WordPress login or profile anywhere in caladmin
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function pw( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function pw_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
/** Run a POST handler to its redirect; the redirect's URL, or '' if none. */
function pw_redirect( $fn ) {
    $GLOBALS['kit_redirect'] = '';
    $GLOBALS['kit_redirect_throws'] = true;
    try { $fn(); } catch ( KitRedirect $r ) { return $r->getMessage(); } catch ( Throwable $t ) { return 'THREW ' . $t->getMessage(); }
    return '';
}

/* ---- WordPress's password functions, as recorders. ----------------------- */
$GLOBALS['pw_calls'] = array();
$known = new WP_User(); $known->ID = 7; $known->user_login = 'ana'; $known->user_email = 'ana@sfaf.org';
function get_user_by( $field, $value ) { return ( 'email' === $field && 'ana@sfaf.org' === $value ) ? $GLOBALS['known'] : false; }
function sanitize_user( $u ) { return preg_replace( '/[^a-z0-9_.@-]/i', '', (string) $u ); }
function network_site_url( $p = '', $scheme = null ) { return 'https://resources.sfaf.org/' . ltrim( $p, '/' ); }
function retrieve_password( $login ) {
    $key  = 'KEY123';
    $msg  = "Someone has requested a password reset.\r\n\r\n" . network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $login ), 'login' ) . "&wp_lang=en_US\r\n";
    foreach ( isset( $GLOBALS['kit_hooks']['retrieve_password_message'] ) ? $GLOBALS['kit_hooks']['retrieve_password_message'] : array() as $h ) {
        $msg = call_user_func( $h['cb'], $msg, $key, $login, null );
    }
    $GLOBALS['pw_calls'][] = array( 'retrieve_password', $login, $msg );
    return true;
}
function check_password_reset_key( $key, $login ) {
    return ( 'KEY123' === $key && 'ana' === $login ) ? $GLOBALS['known'] : new WP_Error( 'invalid_key', 'Invalid key.' );
}
function reset_password( $user, $pass ) { $GLOBALS['pw_calls'][] = array( 'reset_password', $user->user_login, $pass ); }
function wp_check_password( $pass, $hash, $id = 0 ) { return 'right-one' === $pass; }
function wp_update_user( $arr ) { $GLOBALS['pw_calls'][] = array( 'wp_update_user', $arr ); return $arr['ID']; }
function pw_called( $name ) { return array_values( array_filter( $GLOBALS['pw_calls'], function ( $c ) use ( $name ) { return $c[0] === $name; } ) ); }

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();

/* ---- C.1 ----------------------------------------------------------------- */
$login_html = pw_capture( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_login', $portal ); } );
pw( false !== strpos( $login_html, 'data-uc-forgot-link>Forgot your password?</a>' ) && false !== strpos( $login_html, '/caladmin/forgot' ),
    'C.1: the sign-in page has no "Forgot your password?" link to caladmin\'s forgot page' );

function pw_forgot( $email ) {
    $_POST = array( 'uc_action' => 'forgot_password', 'uc_nonce' => 'nonce', 'user_email' => $email );
    return pw_redirect( function () { kit_call( 'SFAF_Portal', 'process_forgot_password', $GLOBALS['portal'] ); } );
}
$GLOBALS['pw_calls'] = array();
$to_known = pw_forgot( 'ana@sfaf.org' );
$sent     = pw_called( 'retrieve_password' );
$GLOBALS['pw_calls'] = array();
$to_none  = pw_forgot( 'nobody@example.org' );
$none     = pw_called( 'retrieve_password' );
$to_bad   = pw_forgot( 'not an address' );
pw( 1 === count( $sent ) && 'ana' === $sent[0][1], 'C.1: an address with an account did not go through retrieve_password(): ' . json_encode( $sent ) );
pw( 0 === count( $none ), 'C.1: an address with no account sent something' );
pw( '' !== $to_known && $to_known === $to_none && $to_known === $to_bad,
    'PLANT C.1: the page after Send differs with the address, which tells anybody who has an account: ' . json_encode( array( $to_known, $to_none, $to_bad ) ) );
$mail = $sent ? (string) $sent[0][2] : '';
pw( false !== strpos( $mail, 'https://resources.sfaf.org/caladmin/reset?key=KEY123&login=ana' ) && false === strpos( $mail, 'wp-login.php' ),
    'C.1: the reset email\'s link does not lead to caladmin: ' . json_encode( $mail ) );
$_GET = array( 'sent' => '1' );
$forgot_html = pw_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_forgot', $portal ); } );
pw( false !== strpos( $forgot_html, 'If that address has an account, a reset link is on its way.' ), 'C.1: the forgot page does not say the one line after Send' );
pw( 1 === preg_match( '/<input type="email" name="user_email"/', $forgot_html ), 'C.1: the forgot page asks for no email address' );

$_POST = array(); $_GET = array( 'key' => 'KEY123', 'login' => 'ana' );
$reset_html = pw_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_reset', $portal ); } );
pw( false !== strpos( $reset_html, 'name="pass1"' ) && false !== strpos( $reset_html, 'name="pass2"' ) && false === strpos( $reset_html, 'data-uc-reset-expired' ),
    'C.1: a good link does not offer the two password boxes' );
$_GET = array( 'key' => 'OLD', 'login' => 'ana' );
$expired_html = pw_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_reset', $portal ); } );
pw( false !== strpos( $expired_html, 'data-uc-reset-expired' ) && false === strpos( $expired_html, 'name="pass1"' ), 'C.1: an expired link is not said to be expired' );

function pw_reset( $a, $b, $key = 'KEY123' ) {
    $_POST = array( 'uc_action' => 'reset_password', 'uc_nonce' => 'nonce', 'rp_key' => $key, 'rp_login' => 'ana', 'pass1' => $a, 'pass2' => $b );
    $p = $GLOBALS['portal'];
    ( new ReflectionProperty( 'SFAF_Portal', 'login_error' ) )->setValue( $p, '' );
    $to = pw_redirect( function () use ( $p ) { kit_call( 'SFAF_Portal', 'process_reset_password', $p ); } );
    return array( $to, ( new ReflectionProperty( 'SFAF_Portal', 'login_error' ) )->getValue( $p ) );
}
$GLOBALS['pw_calls'] = array();
list( $to, $err ) = pw_reset( 'one', 'two' );
pw( '' === $to && '' !== $err && ! pw_called( 'reset_password' ), 'C.1: two different new passwords were accepted: ' . json_encode( array( $to, $err ) ) );
list( $to, $err ) = pw_reset( 'n3w-pass', 'n3w-pass', 'OLD' );
pw( '' === $to && ! pw_called( 'reset_password' ), 'C.1: an expired key set a password' );
list( $to, $err ) = pw_reset( 'n3w-pass', 'n3w-pass' );
$set = pw_called( 'reset_password' );
pw( 1 === count( $set ) && 'ana' === $set[0][1] && 'n3w-pass' === $set[0][2], 'C.1: the new password did not go through reset_password(): ' . json_encode( $set ) );
pw( 'https://resources.sfaf.org/caladmin?reset=1' === $to, 'C.1: after a reset it does not go back to the caladmin sign-in: ' . $to );
$_GET = array( 'reset' => '1' );
$back = pw_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_login', $portal ); } );
pw( false !== strpos( $back, 'Your password is set. Sign in with it.' ), 'C.1: the sign-in page does not say the password is set' );

/* ---- C.2 ----------------------------------------------------------------- */
function pw_change( $now, $new, $again ) {
    $_POST = array( 'uc_action' => 'change_password', 'uc_nonce' => 'nonce', 'current_password' => $now, 'new_password' => $new, 'confirm_password' => $again );
    $GLOBALS['pw_calls'] = array();
    $to = pw_redirect( function () { kit_call( 'SFAF_Portal', 'dispatch_post', $GLOBALS['portal'], array( 'change_password' ) ); } );
    return array( $to, pw_called( 'wp_update_user' ) );
}
list( $to, $w ) = pw_change( 'wrong-one', 'n3w', 'n3w' );
pw( false !== strpos( $to, 'pw=wrong' ) && ! $w, 'PLANT C.2: a wrong current password was accepted: ' . json_encode( array( $to, $w ) ) );
list( $to, $w ) = pw_change( 'right-one', 'n3w', 'other' );
pw( false !== strpos( $to, 'pw=mismatch' ) && ! $w, 'C.2: two different new passwords were accepted: ' . json_encode( array( $to, $w ) ) );
list( $to, $w ) = pw_change( 'right-one', '', '' );
pw( false !== strpos( $to, 'pw=empty' ) && ! $w, 'C.2: an empty new password was accepted' );
list( $to, $w ) = pw_change( 'right-one', 'n3w', 'n3w' );
pw( false !== strpos( $to, 'msg=password_changed' ) && 1 === count( $w ) && array( 'ID' => 1, 'user_pass' => 'n3w' ) === $w[0][1],
    'C.2: the right password did not change through wp_update_user(): ' . json_encode( array( $to, $w ) ) );
foreach ( array( 'wrong' => 'That is not your current password.', 'mismatch' => 'The new passwords do not match. Type the same one twice.' ) as $code => $line ) {
    $_GET = array( 'pw' => $code );
    $prefs = pw_capture( function () use ( $portal, $user ) { kit_call( 'SFAF_Portal', 'render_preferences', $portal, array( $user ) ); } );
    pw( false !== strpos( $prefs, 'data-uc-password-error>' . $line ), "C.2: Preferences does not say \"$line\" inline" );
    pw( 1 === preg_match( '/name="current_password".*name="new_password".*name="confirm_password"/s', $prefs ), 'C.2: Preferences has no Change password section' );
}

/* ---- C.3 ----------------------------------------------------------------- */
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-portal.php' );
foreach ( array( 'wp-login.php?', 'wp_login_url', 'wp_lostpassword_url', 'profile.php', 'get_edit_profile_url', 'get_edit_user_link', 'user-edit.php' ) as $bad ) {
    $hits = substr_count( $src, $bad );
    // The one wp-login.php in caladmin is the address the reset email's link is rewritten FROM.
    $allowed = ( 'wp-login.php?' === $bad ) ? 1 : 0;
    pw( $hits === $allowed, "C.3: caladmin names $bad $hits time(s)" );
}

$_GET = array(); $_POST = array();
if ( $fails ) {
    echo 'PASSWORDS: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "passwords: Forgot your password? under the sign-in, the same page after Send whoever the address was, the email's link to caladmin, the reset page setting it through WordPress and back to the sign-in, an expired link said; Change password refusing a wrong current password, an empty or mismatched new one, and changing through wp_update_user(); no link to the WordPress login or profile in caladmin.\n";
