<?php
/**
 * 3.110.5 IN A REAL BROWSER: NOTHING TYPED INTO CALADMIN IS LOST TO AN
 * EXPIRED SIGN-IN.
 *
 *     php .claude/release-31105-live.php            writes the pages
 *     php .claude/release-31105-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31105-live.php --shots    --run, and writes .claude/screens/caladmin-31105-*.png
 *
 * The real Edit event screen through wp-kit.php, with the real
 * caladmin-session.js, portal.js and portal.css. The server's answer to "is
 * this person signed in, is this form's token good" is stood in for by a
 * fetch the page is given before the script starts.
 *
 *   A.2  signed out: Save posts nothing, the draft holds what was typed, and
 *        the browser is sent to the sign-in with the screen and uc_restore=1
 *        as redirect_to; a stale token: nothing posts and the browser goes
 *        straight back to the screen with uc_restore=1; signed in with a good
 *        token: the post goes, once, with the button that was pressed and the
 *        draft named; the check unreachable: the post goes
 *   A.1  ?uc_restore=1 with a draft: the fields hold it at load, the line says
 *        so, the address loses uc_restore; without it, "Restore what you were
 *        typing?" with Restore (back with uc_restore=1) and Discard (gone, and
 *        the draft with it), measured at 1280px and 390px
 *   A.3  the session-ended page at 1280px and 390px: the sentence, Sign in,
 *        nothing wider than the screen, Sign in at least 44px tall
 *
 * PLANT A.2 lets a stale post go out; plant-31105.php carries it.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() {} }

$fails = array();
function sl_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function sl_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

$id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Dinner', 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
foreach ( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market St' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$route  = new ReflectionProperty( 'SFAF_Portal', 'route' );
$route->setAccessible( true );
$route->setValue( $portal, 'events/edit/' . $id );
$editor = sl_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $GLOBALS['id'] ) ); } );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'uc_action' => 'save_event', 'uc_nonce' => 'stale', '_wp_http_referer' => '/caladmin/events/edit/' . $id );
$GLOBALS['kit_nonce_bad'] = true;
$ended = sl_capture( function () use ( $portal, $id ) { $portal->handle( 'events/edit/' . $id ); } );
$GLOBALS['kit_nonce_bad'] = false;
$_POST = array();

$key  = 'ucDraft:events/edit/' . $id . '|save_event|0';
$seed = array( 'key' => $key, 'f' => array( array( 'n' => 'title', 'i' => 0, 'v' => 'Restored title' ), array( 'n' => 'description', 'i' => 0, 'v' => '<p>Restored words</p>' ) ) );
$no   = array( 'success' => true, 'data' => array( 'signed_in' => false, 'valid' => false ) );
$old  = array( 'success' => true, 'data' => array( 'signed_in' => true, 'valid' => false ) );
$yes  = array( 'success' => true, 'data' => array( 'signed_in' => true, 'valid' => true ) );
$pages = array(
    'expired'       => array( 'w' => 1280, 'html' => $editor, 'cfg' => array( 'kind' => 'flow', 'answer' => $no ) ),
    'stale'         => array( 'w' => 1280, 'html' => $editor, 'cfg' => array( 'kind' => 'flow', 'answer' => $old ) ),
    'good'          => array( 'w' => 1280, 'html' => $editor, 'cfg' => array( 'kind' => 'flow', 'answer' => $yes ) ),
    'down'          => array( 'w' => 1280, 'html' => $editor, 'cfg' => array( 'kind' => 'flow', 'answer' => 'down' ) ),
    'restore'       => array( 'w' => 1280, 'html' => $editor, 'cfg' => array( 'kind' => 'restore', 'seed' => $seed ), 'q' => '?uc_restore=1' ),
    'prompt-desktop' => array( 'w' => 1280, 'html' => $editor, 'cfg' => array( 'kind' => 'prompt', 'seed' => $seed, 'press' => 1 ) ),
    'prompt-phone'  => array( 'w' => 390, 'html' => $editor, 'cfg' => array( 'kind' => 'prompt', 'seed' => $seed ) ),
    'ended-desktop' => array( 'w' => 1280, 'html' => $ended, 'cfg' => array( 'kind' => 'ended' ) ),
    'ended-phone'   => array( 'w' => 390, 'html' => $ended, 'cfg' => array( 'kind' => 'ended' ) ),
);

$head = <<<'JS'
(function () {
  var S = window.SL;
  window.SL_errors = []; window.SL_calls = [];
  window.addEventListener('error', function (e) { window.SL_errors.push(String(e.message)); });
  try { localStorage.clear(); } catch (e) {}
  if (S.seed) { localStorage.setItem(S.seed.key, JSON.stringify({ t: Date.now(), f: S.seed.f })); }
  if (S.answer !== undefined) {
    window.fetch = function (url, o) {
      window.SL_calls.push(String(o && o.body || ''));
      if (S.answer === 'down') { return Promise.reject(new Error('down')); }
      return Promise.resolve({ json: function () { return Promise.resolve(S.answer); } });
    };
  }
  /* What the restore put in the fields before anything else started. */
  document.addEventListener('DOMContentLoaded', function () {
    var t = document.querySelector('form[id^="uc-event-form-"] [name="title"]');
    window.SL_atStart = t ? t.value : null;
  });
})();
JS;

$tail = <<<'JS'
(function () {
var S = window.SL, out = { page: S.name };
function q(s, r) { return (r || document).querySelector(s); }
function rect(el) { if (!el) { return null; } var b = el.getBoundingClientRect(); return { left: Math.round(b.left), top: Math.round(b.top), right: Math.round(b.right), bottom: Math.round(b.bottom), w: Math.round(b.width), h: Math.round(b.height) }; }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : null; }
function finish() { out.errors = window.SL_errors; out.calls = window.SL_calls; out.pageWidth = document.documentElement.scrollWidth; parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  var went = []; if (window.ucSession) { window.ucSession.go = function (u) { went.push(u); }; }
  out.hasApi = !!window.ucSession;
  var form = q('form[id^="uc-event-form-"]');
  if (S.kind === 'flow') {
    out.posted = 0; out.held = 0; out.resubmitted = [];
    HTMLFormElement.prototype.requestSubmit = function (s) {
      out.resubmitted.push(s ? s.value : '(none)');
      this.dispatchEvent(new SubmitEvent('submit', { cancelable: true, bubbles: true, submitter: s || null }));
    };
    window.addEventListener('submit', function (e) { if (e.defaultPrevented) { out.held++; } else { out.posted++; e.preventDefault(); } });
    var t = q('[name="title"]', form);
    t.value = 'Typed title'; t.dispatchEvent(new Event('input', { bubbles: true }));
    var btn = q('button[form="' + form.id + '"][name="save_mode"]');
    form.dispatchEvent(new SubmitEvent('submit', { cancelable: true, bubbles: true, submitter: btn }));
    setTimeout(function () {
      out.went = went;
      var d = null; try { d = JSON.parse(localStorage.getItem(S.key) || 'null'); } catch (e) {}
      out.draftTitle = d && d.f ? (d.f.filter(function (f) { return f.n === 'title'; })[0] || {}).v : null;
      out.draftNonce = d && d.f ? d.f.some(function (f) { return /nonce|^uc_action$/.test(f.n); }) : null;
      var k = q('[name="uc_draft_key"]', form); out.draftKey = k ? k.value : null;
      finish();
    }, 300);
    return;
  }
  if (S.kind === 'restore') {
    out.atStart = window.SL_atStart;
    out.title = q('[name="title"]', form).value;
    out.desc = q('[name="description"]', form).value;
    out.line = text(q('[data-uc-draft-restored]'));
    out.search = window.location.search;
    out.prompt = !!q('[data-uc-draft-prompt]');
    out.kept = !!localStorage.getItem(S.key);
    finish();
    return;
  }
  if (S.kind === 'prompt') {
    var bar = q('[data-uc-draft-prompt]'), yes = q('[data-uc-draft-restore]'), no = q('[data-uc-draft-discard]');
    out.bar = rect(bar); out.words = text(q('.uc-draft-prompt-text')); out.yes = rect(yes); out.no = rect(no);
    out.yesText = text(yes); out.noText = text(no);
    out.main = rect(q('.uc-portal-main')); out.topbar = rect(q('.uc-portal-topbar'));
    out.title = form ? q('[name="title"]', form).value : null;
    out.firstAfterBar = bar && bar.nextElementSibling ? bar.nextElementSibling.className : null;
    if (S.press && !S.shot && yes) { yes.click(); out.went = went; }
    if (no) { no.click(); }
    out.goneAfterDiscard = !q('[data-uc-draft-prompt]');
    out.keptAfterDiscard = !!localStorage.getItem(S.key);
    finish();
    return;
  }
  var card = q('.uc-login-card'), msg = q('[data-uc-session-ended]'), sign = q('[data-uc-session-signin]');
  out.card = rect(card); out.msg = text(msg); out.sign = rect(sign); out.signText = text(sign);
  out.msgBox = rect(msg); out.logo = rect(q('[data-uc-login-logo]'));
  out.vw = window.innerWidth;
  out.bodyClass = document.body.className;
  finish();
}, 600); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { sl_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $cfg   = array_merge( $p['cfg'], array( 'name' => $name, 'key' => $key ) );
    $inner = __DIR__ . '/release-31105-live-' . $name . '.html';
    $page  = preg_replace( '#<head>#i', '<head><script>window.SL=' . json_encode( $cfg ) . ';' . $head . '</script>', $p['html'], 1 );
    $page  = preg_replace( '#</body>(?![\s\S]*</body>)#i', '<script>' . $tail . '</script></body>', $page, 1 );
    file_put_contents( $inner, $page );
    $files[ $name ] = __DIR__ . '/release-31105-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . ( isset( $p['q'] ) ? $p['q'] : '' ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
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
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w + 20 ) . ',1000 --virtual-time-budget=12000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { sl_check( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    sl_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    sl_check( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}
$v = function ( $name, $k ) use ( $g ) { return isset( $g[ $name ][ $k ] ) ? $g[ $name ][ $k ] : null; };
$base = 'file:///' . str_replace( '\\', '/', __DIR__ ) . '/release-31105-live-';

/* ---- A.2 ---------------------------------------------------------------- */
$back = $base . 'expired.html?uc_restore=1';
sl_check( true === $v( 'expired', 'hasApi' ), 'A.2: caladmin-session.js did not start on the editor' );
sl_check( 0 === $v( 'expired', 'posted' ) && array() === $v( 'expired', 'resubmitted' ),
    'PLANT A.2: signed out, the post went out anyway: ' . json_encode( array( $v( 'expired', 'posted' ), $v( 'expired', 'resubmitted' ) ) ) );
$went = (array) $v( 'expired', 'went' );
sl_check( 1 === count( $went ) && 0 === strpos( (string) $went[0], 'https://resources.sfaf.org/caladmin?redirect_to=' )
    && rawurldecode( substr( (string) $went[0], strlen( 'https://resources.sfaf.org/caladmin?redirect_to=' ) ) ) === $back,
    'PLANT A.2: signed out, the browser is not sent to the sign-in with the screen as redirect_to: ' . json_encode( $went ) );
sl_check( 'Typed title' === $v( 'expired', 'draftTitle' ) && false === $v( 'expired', 'draftNonce' ), 'A.1: the draft does not hold what was typed, or holds the token: ' . json_encode( array( $v( 'expired', 'draftTitle' ), $v( 'expired', 'draftNonce' ) ) ) );
$calls = (array) $v( 'expired', 'calls' );
sl_check( 1 === count( $calls ) && false !== strpos( $calls[0], 'action=sfaf_caladmin_session' ) && false !== strpos( $calls[0], 'check=save_event' ) && false !== strpos( $calls[0], 'nonce=' ),
    'A.2: the check did not ask about this form and its token: ' . json_encode( $calls ) );

sl_check( 0 === $v( 'stale', 'posted' ) && array() === $v( 'stale', 'resubmitted' ), 'PLANT A.2: a stale token, the post went out anyway' );
sl_check( array( $base . 'stale.html?uc_restore=1' ) === $v( 'stale', 'went' ), 'A.2: a stale token while signed in does not go straight back to the screen: ' . json_encode( $v( 'stale', 'went' ) ) );

sl_check( array( 'keep' ) === $v( 'good', 'resubmitted' ) && 1 === count( (array) $v( 'good', 'calls' ) ) && array() === $v( 'good', 'went' ),
    'A.2: signed in with a good token, the post did not go once with the button pressed: ' . json_encode( array( $v( 'good', 'resubmitted' ), $v( 'good', 'calls' ), $v( 'good', 'went' ) ) ) );
sl_check( $key === $v( 'good', 'draftKey' ), 'A.1: the post does not name its draft, so a save cannot clear it: ' . json_encode( $v( 'good', 'draftKey' ) ) );
sl_check( array( 'keep' ) === $v( 'down', 'resubmitted' ) && array() === $v( 'down', 'went' ), 'A.2: with the check unreachable the post was held: ' . json_encode( array( $v( 'down', 'resubmitted' ), $v( 'down', 'went' ) ) ) );

/* ---- A.1 ---------------------------------------------------------------- */
sl_check( 'Restored title' === $v( 'restore', 'atStart' ), 'A.1: the restore was not in the fields when the page started: ' . json_encode( $v( 'restore', 'atStart' ) ) );
sl_check( 'Restored title' === $v( 'restore', 'title' ) && '<p>Restored words</p>' === $v( 'restore', 'desc' ), 'A.1: ?uc_restore=1 did not put the draft back: ' . json_encode( array( $v( 'restore', 'title' ), $v( 'restore', 'desc' ) ) ) );
sl_check( 'What you were typing is back. Save to keep it.' === $v( 'restore', 'line' ) && false === $v( 'restore', 'prompt' ), 'A.1: the restore does not say so, or asks as well: ' . json_encode( array( $v( 'restore', 'line' ), $v( 'restore', 'prompt' ) ) ) );
sl_check( '' === $v( 'restore', 'search' ) && true === $v( 'restore', 'kept' ), 'A.1: the address kept uc_restore, or the draft went before a save: ' . json_encode( array( $v( 'restore', 'search' ), $v( 'restore', 'kept' ) ) ) );

foreach ( array( 'prompt-desktop' => 1280, 'prompt-phone' => 390 ) as $p => $w ) {
    $bar = (array) $v( $p, 'bar' ); $yes = (array) $v( $p, 'yes' ); $no = (array) $v( $p, 'no' ); $main = (array) $v( $p, 'main' );
    sl_check( 'Restore what you were typing?' === $v( $p, 'words' ) && 'Restore' === $v( $p, 'yesText' ) && 'Discard' === $v( $p, 'noText' ), "A.1: $p: the prompt does not read Restore what you were typing? with Restore and Discard: " . json_encode( array( $v( $p, 'words' ), $v( $p, 'yesText' ), $v( $p, 'noText' ) ) ) );
    sl_check( 'Dinner' === $v( $p, 'title' ), "A.1: $p: the draft was put in before anybody pressed Restore" );
    sl_check( $bar && $main && $bar['left'] >= $main['left'] && $bar['right'] <= min( $w, $main['right'] ) && $bar['top'] >= (int) ( (array) $v( $p, 'topbar' ) )['bottom'],
        "A.1: $p: the prompt is not inside the page under the top bar: " . json_encode( array( $bar, $main ) ) );
    sl_check( $yes && $no && $yes['right'] <= $bar['right'] && $no['right'] <= $bar['right'] && $yes['h'] >= 36 && $no['h'] >= 36, "A.1: $p: the buttons run out of the prompt, or are under 36px: " . json_encode( array( $yes, $no, $bar ) ) );
    if ( 390 === $w ) { sl_check( $yes['h'] >= 44 && $no['h'] >= 44, "A.1: $p: a button is under 44px on a phone: " . json_encode( array( $yes['h'], $no['h'] ) ) ); }
    sl_check( true === $v( $p, 'goneAfterDiscard' ) && false === $v( $p, 'keptAfterDiscard' ), "A.1: $p: Discard did not take the prompt and the draft away" );
}
sl_check( array( $base . 'prompt-desktop.html?uc_restore=1' ) === $v( 'prompt-desktop', 'went' ), 'A.1: Restore does not reload the screen with uc_restore=1: ' . json_encode( $v( 'prompt-desktop', 'went' ) ) );

/* ---- A.3 ---------------------------------------------------------------- */
foreach ( array( 'ended-desktop' => 1280, 'ended-phone' => 390 ) as $p => $w ) {
    $card = (array) $v( $p, 'card' ); $sign = (array) $v( $p, 'sign' );
    sl_check( 'Your session had ended, so that change was not saved. Sign in and your form will be restored.' === $v( $p, 'msg' ), "A.3: $p: the sentence: " . json_encode( $v( $p, 'msg' ) ) );
    sl_check( 'Sign in' === $v( $p, 'signText' ) && $sign && $sign['h'] >= 44, "A.3: $p: Sign in is missing or under 44px: " . json_encode( $sign ) );
    sl_check( $card && $card['left'] >= 0 && $card['right'] <= $w && $sign['left'] >= $card['left'] && $sign['right'] <= $card['right'], "A.3: $p: the card or the button runs off the screen: " . json_encode( array( $card, $sign ) ) );
    sl_check( false !== strpos( (string) $v( $p, 'bodyClass' ), 'uc-portal' ), "A.3: $p: not caladmin's own page" );
}

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( array( 'prompt-desktop' => 1280, 'prompt-phone' => 390, 'ended-desktop' => 1280, 'ended-phone' => 390 ) as $k => $w ) {
        $png   = __DIR__ . "/screens/caladmin-31105-$k.png";
        $src   = __DIR__ . "/release-31105-live-$k.html";
        $inner = __DIR__ . "/release-31105-shot-$k.html";
        file_put_contents( $inner, str_replace( array( 'window.SL={', '</head>' ), array( 'window.SL={"shot":1,', '<style>*,*::before,*::after{transition:none!important;animation:none!important}</style></head>' ), str_replace( "if (no) { no.click(); }", "if (no && !S.shot) { no.click(); }", file_get_contents( $src ) ) ) );
        $frame = __DIR__ . "/release-31105-shot-$k-frame.html";
        file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="' . basename( $inner ) . '" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe></body></html>' );
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $w ) . ',900 --virtual-time-budget=8000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
        sl_check( file_exists( $png ), "no screenshot written for $k" );
    }
}

if ( $fails ) {
    echo 'RELEASE 3.110.5 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.110.5 live: signed out or with a stale token Save posts nothing and goes to the sign-in or back to the screen with the draft kept; a good token posts once; ?uc_restore=1 fills the fields before anything starts; the prompt and the session-ended page fit at 1280px and 390px.\n";
exit( 0 );
