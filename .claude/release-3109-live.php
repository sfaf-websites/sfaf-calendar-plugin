<?php
/**
 * 3.109.0 IN A REAL BROWSER: PRIVATE IN THE DISPLAY CARD, THE PASSWORD PAGES,
 * AND THE TWO LOGOS, AT 1280px AND 390px.
 *
 *     php .claude/release-3109-live.php            writes the pages
 *     php .claude/release-3109-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-3109-live.php --shots    --run, and writes .claude/screens/caladmin-3109-*.png
 *
 * The logos load from resources.sfaf.org, as they do on the site: a page that
 * cannot reach it fails here rather than measuring alt text.
 *
 *   A    "Make this event private" is in the Display card, right after Follow
 *        the series, on Add event and Edit event; Other details is gone from an
 *        event with nothing left for it, and holds only the listing details on
 *        one that has them
 *   C    the sign-in, forgot and reset pages are one layout, the card the same
 *        width on all three; "Forgot your password?" sits under the form;
 *        Preferences has Change password with its three boxes and Save
 *   D.1  the wide logo above the sign-in form, loaded, alt text, centred, 320px
 *        at 1280px and the form's whole width at 390px, 24px above the heading
 *   D.2  the stacked logo on a white tile at the top of the sidebar: --p-panel,
 *        12px radius, 12px padding, the logo the tile's inner width, "Calendar
 *        Admin" under the tile; at 390px with the menu opened
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rn_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rn_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
if ( ! function_exists( 'sanitize_user' ) ) {
    function sanitize_user( $u ) { return preg_replace( '/[^a-z0-9_.@-]/i', '', (string) $u ); }
}
if ( ! function_exists( 'check_password_reset_key' ) ) {
    function check_password_reset_key( $key, $login ) { return ( 'KEY123' === $key ) ? new WP_User() : new WP_Error( 'invalid_key', 'Invalid key.' ); }
}

$GLOBALS['kit_query'] = function ( $a ) { $o = array(); foreach ( $GLOBALS['kit_posts'] as $id => $p ) { if ( ( isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event' ) === $p->post_type ) { $o[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; } } return $o; };
$plain = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Dinner', 'post_author' => 1 ) );
update_post_meta( $plain, '_uc_event_date', '2026-11-20' );
$listed = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Submitted walk', 'post_author' => 1 ) );
update_post_meta( $listed, '_uc_event_date', '2026-11-21' );
update_post_meta( $listed, SFAF_Submit::META_COST, 'Free' );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function rn_page( $method, $args = array(), $get = array() ) {
    return rn_capture( function () use ( $method, $args, $get ) { $_GET = $get; kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args ); } );
}
$html = array(
    'signin' => rn_page( 'render_login' ),
    'forgot' => rn_page( 'render_forgot', array(), array( 'sent' => '1' ) ),
    'reset'  => rn_page( 'render_reset', array(), array( 'key' => 'KEY123', 'login' => 'ana' ) ),
    'add'    => rn_page( 'render_event_form', array( $user, 0 ) ),
    'edit'   => rn_page( 'render_event_form', array( $user, $plain ) ),
    'listed' => rn_page( 'render_event_form', array( $user, $listed ) ),
    'prefs'  => rn_page( 'render_preferences', array( $user ), array( 'pw' => 'wrong' ) ),
);
$pages = array();
foreach ( $html as $k => $h ) {
    foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
        if ( 'listed' === $k && 'phone' === $wn ) { continue; }
        $pages[ "$k-$wn" ] = array( 'w' => $w, 'html' => $h );
    }
}

$probe = <<<'JS'
(function () {
var P = window.RV_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  /* C and D.1: the sign-in layout. */
  var card = q('.uc-login-card');
  if (card) {
    var logo = q('[data-uc-login-logo]'), form = q('.uc-login-form'), h1 = q('.uc-login-card h1');
    out.card = rect(card); out.form = rect(form); out.h1 = rect(h1);
    out.logo = logo ? { box: rect(logo), alt: logo.getAttribute('alt'), loaded: logo.complete && logo.naturalWidth > 0, src: logo.getAttribute('src') } : null;
    out.forgot = seen(q('[data-uc-forgot-link]')) ? { box: rect(q('[data-uc-forgot-link]')), text: text(q('[data-uc-forgot-link]')) } : null;
    out.sent = text(q('[data-uc-forgot-sent]'));
    out.boxes = qa('.uc-login-form input:not([type="hidden"])').map(function (i) { return i.name; });
    finish(); return;
  }
  /* D.2: the sidebar. */
  function sidebar() {
    var tile = q('[data-uc-sidebar-logo]'), img = tile ? q('img', tile) : null, cs = tile ? getComputedStyle(tile) : null;
    return tile ? { tile: rect(tile), img: rect(img), alt: img.getAttribute('alt'), loaded: img.complete && img.naturalWidth > 0,
      bg: cs.backgroundColor, radius: cs.borderTopLeftRadius, pad: [cs.paddingTop, cs.paddingRight, cs.paddingBottom, cs.paddingLeft],
      label: rect(q('.uc-portal-brandtext')), labelText: text(q('.uc-portal-brandtext')), sidebar: rect(q('#uc-sidebar')) } : null;
  }
  var menu = document.getElementById('uc-menu-btn');
  var phone = window.innerWidth < 721;
  if (phone && menu) { menu.click(); }
  setTimeout(function () {
    out.side = sidebar();
    out.brandmark = !!q('.uc-portal-brandmark');
    /* A: the Display card. */
    var display = q('[data-uc-card="display"]');
    if (display) {
      var ticks = qa('.uc-check', display).map(function (l) { return text(l).replace(/\s*\?$/, ''); });
      out.displayTicks = ticks;
      out.privateIn = !!q('input[name="uc_private"][type="checkbox"]', display);
      out.privateCount = qa('input[name="uc_private"][type="checkbox"]').length;
    }
    var other = q('[data-uc-card="other-details"]');
    out.other = other ? qa('input[name], select[name], textarea[name]', other).map(function (i) { return i.name; }).filter(function (n, i, a) { return a.indexOf(n) === i; }) : null;
    /* C.2 */
    var pwf = q('[data-uc-password-form]');
    out.password = pwf ? { boxes: qa('input[type="password"]', pwf).map(function (i) { return i.name; }), save: text(q('button[type="submit"]', pwf)),
      error: text(q('[data-uc-password-error]', pwf)), title: text(q('h2', pwf)) } : null;
    finish();
  }, phone ? 400 : 0);
}, 600); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rn_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inner = __DIR__ . '/release-3109-live-' . $name . '.html';
    file_put_contents( $inner, preg_replace( '#</body>#i', '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $p['html'], 1 ) );
    $files[ $name ] = __DIR__ . '/release-3109-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args  = array_slice( $argv, 1 );
$shots = in_array( '--shots', $args, true );
if ( ! in_array( '--run', $args, true ) && ! $shots ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
$g = array();
foreach ( $files as $name => $file ) {
    $w   = $pages[ $name ]['w'];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w + 20 ) . ',1000 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rn_check( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rn_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rn_check( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };
$P_PANEL = 'rgb(255, 255, 255)';
$ALT     = 'San Francisco AIDS Foundation';

/* ---- C and D.1 ----------------------------------------------------------- */
foreach ( array( 'desktop', 'phone' ) as $wn ) {
    $sign = "signin-$wn";
    $logo = (array) $v( $sign, 'logo' ); $form = (array) $v( $sign, 'form' ); $card = (array) $v( $sign, 'card' ); $h1 = (array) $v( $sign, 'h1' );
    rn_check( $logo && true === $logo['loaded'] && $ALT === $logo['alt'] && SFAF_Portal::LOGO_WIDE === $logo['src'], "D.1: $sign: the logo is not the SFAF logo, loaded, with its alt text: " . json_encode( $logo ) );
    if ( $logo && $form && $card ) {
        $want = 'desktop' === $wn ? 320 : $form['w'];
        rn_check( $want === $logo['box']['w'], "D.1: $sign: the logo is " . $logo['box']['w'] . "px wide, not $want" . ( 'desktop' === $wn ? '' : ' (the form\'s width)' ) );
        rn_check( abs( ( $logo['box']['left'] + $logo['box']['right'] ) - ( $card['left'] + $card['right'] ) ) <= 1, "D.1: $sign: the logo is not centred in the card" );
        rn_check( $h1 && 24 === $h1['top'] - $logo['box']['bottom'], "D.1: $sign: the logo is " . ( $h1 ? $h1['top'] - $logo['box']['bottom'] : '?' ) . 'px above the heading, not 24' );
        rn_check( $logo['box']['bottom'] <= $form['top'], "D.1: $sign: the logo is not above the form" );
    }
    $fl = (array) $v( $sign, 'forgot' );
    rn_check( $fl && 'Forgot your password?' === $fl['text'] && $form && $fl['box']['top'] >= $form['bottom'], "C.1: $sign: \"Forgot your password?\" is not under the form: " . json_encode( $fl ) );
    foreach ( array( 'forgot', 'reset' ) as $other ) {
        $oc = (array) $v( "$other-$wn", 'card' ); $ol = (array) $v( "$other-$wn", 'logo' );
        rn_check( $oc && $card && $oc['w'] === $card['w'] && $ol && $ol['box']['w'] === $logo['box']['w'], "C.3: $other-$wn is not the sign-in page's layout: " . json_encode( array( $oc, $card ) ) );
    }
    rn_check( 'If that address has an account, a reset link is on its way.' === $v( "forgot-$wn", 'sent' ) && array( 'user_email' ) === $v( "forgot-$wn", 'boxes' ), "C.1: forgot-$wn: " . json_encode( array( $v( "forgot-$wn", 'sent' ), $v( "forgot-$wn", 'boxes' ) ) ) );
    rn_check( array( 'pass1', 'pass2' ) === $v( "reset-$wn", 'boxes' ), "C.1: reset-$wn has the boxes " . json_encode( $v( "reset-$wn", 'boxes' ) ) );

    /* D.2 */
    foreach ( array( 'add', 'edit', 'prefs' ) as $screen ) {
        $t = "$screen-$wn";
        $s = (array) $v( $t, 'side' );
        rn_check( $s && true === $s['loaded'] && $ALT === $s['alt'] && $P_PANEL === $s['bg'] && '12px' === $s['radius'] && array( '12px', '12px', '12px', '12px' ) === $s['pad'],
            "D.2: $t: the sidebar logo is not on a white 12px tile: " . json_encode( $s ) );
        if ( $s ) {
            rn_check( $s['img']['w'] === $s['tile']['w'] - 24, "D.2: $t: the logo is not the tile's inner width: " . json_encode( array( $s['img'], $s['tile'] ) ) );
            rn_check( 'Calendar Admin' === $s['labelText'] && $s['label']['top'] >= $s['tile']['bottom'], "D.2: $t: \"Calendar Admin\" is not under the tile" );
            rn_check( $s['tile']['left'] >= $s['sidebar']['left'] && $s['tile']['right'] <= $s['sidebar']['right'], "D.2: $t: the tile is outside the sidebar" );
        }
        rn_check( false === $v( $t, 'brandmark' ), "D.2: $t: the yellow mark is still drawn" );
    }

    /* A */
    foreach ( array( 'add', 'edit' ) as $screen ) {
        $t = "$screen-$wn";
        $ticks = (array) $v( $t, 'displayTicks' );
        $at = array_search( 'Follow the series', $ticks, true );
        rn_check( true === $v( $t, 'privateIn' ) && 1 === $v( $t, 'privateCount' ) && false !== $at && isset( $ticks[ $at + 1 ] ) && 0 === strpos( $ticks[ $at + 1 ], 'Make this event private' ),
            "PLANT A: $t: Make this event private is not in the Display card right after Follow the series: " . json_encode( array( $ticks, $v( $t, 'privateCount' ) ) ) );
        rn_check( null === $v( $t, 'other' ), "A.3: $t: Other details is drawn with " . json_encode( $v( $t, 'other' ) ) );
    }

    /* C.2 */
    $pwd = (array) $v( "prefs-$wn", 'password' );
    rn_check( $pwd && 'Change password' === $pwd['title'] && array( 'current_password', 'new_password', 'confirm_password' ) === $pwd['boxes'] && 'Save' === $pwd['save']
        && 'That is not your current password.' === $pwd['error'], "C.2: prefs-$wn: " . json_encode( $pwd ) );
}
$lo = $v( 'listed-desktop', 'other' );
rn_check( is_array( $lo ) && ! in_array( 'uc_private', $lo, true ) && count( $lo ) > 0, 'A.3: an event with listing details lost Other details, or it still holds the private tick: ' . json_encode( $lo ) );

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( array( 'signin', 'forgot', 'prefs', 'edit' ) as $k ) {
        foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
            $png   = __DIR__ . "/screens/caladmin-3109-$k-$wn.png";
            $inner = __DIR__ . "/release-3109-live-$k-$wn.html";
            $frame = __DIR__ . "/release-3109-shot-$k-$wn-frame.html";
            file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="' . basename( $inner ) . '" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe></body></html>' );
            shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $w ) . ',900 --virtual-time-budget=8000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
            rn_check( file_exists( $png ), "no screenshot written for $k $wn" );
        }
    }
}

if ( $fails ) {
    echo 'RELEASE 3.109.0 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.109.0 live: Make this event private in the Display card after Follow the series on Add and Edit, Other details only where something is left for it; the sign-in, forgot and reset pages one layout with Forgot your password? under the form; Change password on Preferences; the wide logo 320px and the form's width over the sign-in, the stacked logo on a white tile in the sidebar, at 1280px and 390px.\n";
