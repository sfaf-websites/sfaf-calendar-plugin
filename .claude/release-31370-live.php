<?php
/**
 * 3.137.0 IN A BROWSER: EVERY BUTTON, MEASURED.
 *
 *     php .claude/release-31370-live.php --run
 *     php .claude/release-31370-live.php --run --verbose   and print every button
 *
 * REAL: every caladmin screen and the two public forms, as caps-sweep-live.php
 * writes them from the real renderers with the real portal.css and portal.js
 * (rewritten first, so they are this release's markup); WP admin's Settings,
 * Automation, Closures and Users screens from SFAF_Admin with the real
 * admin.css; and Edit event's Cancel event dialog, opened. In Chrome at 1280px
 * and 390px.
 *
 *   N  no visible button anywhere wears the old Light Gray face
 *   O  every visible button's words are at least 4.5:1 on what is behind them
 *      (3:1 when they are large), and every boxed button's edge at least 3:1
 *      against the surface it sits on, or its face is
 *   P  each kind is what Mark decided: a plain secondary is R (white, #0E7680
 *      edge and ink); Fetch updates and Refresh from source are R; the red
 *      outline is white with #c0392b; the quiet button white with #6B6764;
 *      Publish N drafts, Set team on N events and Add organizer are yellow;
 *      WP admin's plain button is R and its row x red ink
 *
 * The hover pairs are measured in control-standard-audit.php. PLANT in
 * plant-31370.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h, $a = array() ) { return time() + 600; } }
if ( ! function_exists( 'get_edit_user_link' ) ) { function get_edit_user_link( $id = 0 ) { return 'https://resources.sfaf.org/wp-admin/user-edit.php?user_id=' . (int) $id; } }
if ( ! class_exists( 'WP_User_Query' ) ) { class WP_User_Query { function __construct( $a = array() ) {} function get_results() { return array( wp_get_current_user() ); } function get_total() { return 1; } } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

/* ---- caladmin and the two forms: caps-sweep-live writes them, no Chrome. ---- */
$out = (string) shell_exec( 'php ' . escapeshellarg( __DIR__ . '/caps-sweep-live.php' ) . ' 2>&1' );
rl( false !== strpos( $out, 'wrote ' ), 'caps-sweep-live did not write its pages: ' . substr( $out, -400 ) );
$pages = array();
foreach ( glob( __DIR__ . '/caps-sweep-live-*.html' ) as $f ) {
    $n = basename( $f, '.html' );
    $short = substr( $n, strlen( 'caps-sweep-live-' ) );
    // The public calendar, list and sidebar are the embed's, which did not change.
    if ( 0 === strpos( $short, 'public-' ) && ! in_array( $short, array( 'public-request-form', 'public-submit-form' ), true ) ) { continue; }
    if ( false !== strpos( $short, '-frame' ) ) { continue; }
    if ( false === stripos( (string) file_get_contents( $f ), '<html' ) ) {
        $wrapped = 'release-31370-live-' . $short . '-page.html';
        file_put_contents( __DIR__ . '/' . $wrapped, '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="../public/css/portal.css"></head><body class="uc-portal" style="margin:0"><div class="uc-portal-main"><div class="uc-portal-content">' . file_get_contents( $f ) . '</div></div><script src="../public/js/portal.js"></script></body></html>' );
        $pages[ $short ] = $wrapped;
        continue;
    }
    $pages[ $short ] = basename( $f );
}
rl( count( $pages ) >= 20, 'too few caladmin screens to measure: ' . implode( ', ', array_keys( $pages ) ) );

/* ---- WP admin, with admin.css. ---- */
$admin = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
$wp = array(
    'wp-settings'   => function () use ( $admin ) { $admin->render_settings_page(); },
    'wp-automation' => function () use ( $admin ) { $admin->render_automation_page(); },
    'wp-closures'   => function () use ( $admin ) { $_GET = array(); $admin->render_closures_page(); },
    'wp-users'      => function () use ( $admin ) { $admin->render_users_page(); },
);
foreach ( $wp as $k => $fn ) {
    $h = rl_cap( $fn );
    rl( 0 !== strpos( $h, 'THREW' ), "$k did not render: " . substr( $h, 0, 300 ) );
    file_put_contents( __DIR__ . '/release-31370-live-' . $k . '.html', '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="../admin/css/admin.css"></head><body class="wp-admin" style="margin:0;background:#f0f0f1;font-family:sans-serif">' . $h . '</body></html>' );
    $pages[ $k ] = 'release-31370-live-' . $k . '.html';
}

/* ---- The confirm dialog, from a plain, a red-outline and a green trigger. ---- */
foreach ( array( 'plain' => 'uc-btn', 'outline' => 'uc-btn uc-btn-danger-outline', 'go' => 'uc-btn uc-btn-go' ) as $k => $cls ) {
    file_put_contents( __DIR__ . '/release-31370-live-dialog-' . $k . '.html', '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="../public/css/portal.css"></head><body class="uc-portal" style="margin:0"><div class="uc-portal-main"><div class="uc-portal-content"><div class="uc-card"><form method="post" action="#" onsubmit="return false"><button type="submit" class="' . $cls . '" data-uc-confirm="Are you sure?">Reset to default</button></form></div></div></div><script src="../public/js/portal.js"></script></body></html>' );
    $pages[ 'dialog-' . $k ] = 'release-31370-live-dialog-' . $k . '.html';
}

/* ---- What the probe measures. ---- */
$probe = <<<'JS'
(function () {
var P = window.RL_PAGE, out = { page: P, errors: [], buttons: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity) > 0.9; }
function rgba(s) { var m = String(s).match(/[\d.]+/g); if (!m) { return null; } return [+m[0], +m[1], +m[2], m.length > 3 ? +m[3] : 1]; }
function lum(c) { return [c[0], c[1], c[2]].map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function ratio(a, b) { var x = lum(a), y = lum(b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; }
function behind(el) { for (var n = el; n && n.nodeType === 1; n = n.parentElement) { var c = rgba(getComputedStyle(n).backgroundColor); if (c && c[3] > 0.5) { return c; } } return [255, 255, 255, 1]; }
function opacityChain(el) { var o = 1; for (var n = el; n && n.nodeType === 1; n = n.parentElement) { o *= parseFloat(getComputedStyle(n).opacity); } return o; }
var LINKISH = /uc-link-danger|uc-link-ok|uc-action-link|uc-repeater-remove|uc-chip-remove|uc-help-btn|uc-icon-btn|uc-seg-opt|uc-tpl-token|uc-picker-tab|uc-tab|uc-nav-item|uc-day|uc-scope-btn|uc-disclosure|uc-checkin-mode|button-link|uc-month|uc-view|notice-dismiss|uc-copy-icon|uc-pw-toggle|uc-sl-|uc-email-pill|uc-rcp-|uc-tag-|uc-chip/;
function measure() {
  qa('button, input[type="submit"], input[type="button"], a.uc-btn, a.button, summary.uc-outline-btn, summary.uc-btn').forEach(function (b) {
    if (b.disabled || !seen(b) || opacityChain(b) < 0.9) { return; }
    var cs = getComputedStyle(b), face = rgba(cs.backgroundColor), ink = rgba(cs.color);
    var under = behind(b.parentElement || b), own = face && face[3] > 0.5 ? face : under;
    var size = parseFloat(cs.fontSize), weight = parseInt(cs.fontWeight, 10);
    var large = size >= 24 || (size >= 18.66 && weight >= 700);
    var bw = parseFloat(cs.borderTopWidth), bc = rgba(cs.borderTopColor);
    var cls = (b.getAttribute('class') || '') + (b.tagName === 'SUMMARY' ? ' summary' : '');
    var label = text(b) || b.getAttribute('value') || b.getAttribute('aria-label') || '';
    out.buttons.push({ label: label.slice(0, 50), cls: cls.slice(0, 90), face: cs.backgroundColor, ink: cs.color, edge: bw > 0 ? cs.borderTopColor : 'none',
      text: label ? ratio(ink, own) : null, large: large, linkish: LINKISH.test(cls),
      edgeRatio: (bw > 0 && bc && bc[3] > 0.5) ? ratio(bc, under) : null, faceRatio: ratio(own, under) });
  });
}
window.addEventListener('load', function () { setTimeout(function () {
  qa('.uc-integration-panel').forEach(function (p) { p.classList.add('uc-panel-open'); });
  qa('.uc-btn-utility[disabled]').forEach(function (b) { b.style.transition = 'none'; b.disabled = false; });
  measure();
  out.pageWidth = document.documentElement.scrollWidth;
  // One button that asks first, pressed: the dialog it opens is measured too.
  var ask = qa('[data-uc-confirm].uc-btn').filter(function (b) { return seen(b) && !b.disabled; })[0];
  if (!ask) { return window.parent.postMessage(JSON.stringify(out), '*'); }
  out.asked = { label: text(ask), cls: ask.getAttribute('class') };
  ask.click();
  setTimeout(function () {
    var dlg = document.querySelector('dialog[open]');
    if (dlg) {
      var n = out.buttons.length;
      qa('button', dlg).forEach(function (b) { if (seen(b)) { var cs = getComputedStyle(b); out.buttons.push({ label: 'dialog: ' + text(b), cls: (b.getAttribute('class') || ''), face: cs.backgroundColor, ink: cs.color, edge: parseFloat(cs.borderTopWidth) > 0 ? cs.borderTopColor : 'none', text: ratio(rgba(cs.color), rgba(cs.backgroundColor)[3] > 0.5 ? rgba(cs.backgroundColor) : behind(dlg)), large: false, linkish: false, edgeRatio: parseFloat(cs.borderTopWidth) > 0 ? ratio(rgba(cs.borderTopColor), behind(dlg)) : null, faceRatio: ratio(rgba(cs.backgroundColor)[3] > 0.5 ? rgba(cs.backgroundColor) : behind(dlg), behind(dlg)) }); } });
      out.dialog = out.buttons.length - n;
    }
    window.parent.postMessage(JSON.stringify(out), '*');
  }, 300);
}, 500); });
})();
JS;

/* --only=a,b: just the pages whose names contain one of these, for plant-31370.php. */
$only = array();
foreach ( $argv as $a ) { if ( 0 === strpos( $a, '--only=' ) ) { $only = array_filter( explode( ',', substr( $a, 7 ) ) ); } }
if ( $only ) {
    $pages = array_filter( $pages, function ( $k ) use ( $only ) { foreach ( $only as $o ) { if ( false !== strpos( $k, $o ) ) { return true; } } return false; }, ARRAY_FILTER_USE_KEY );
}
$files = array();
foreach ( $pages as $name => $file ) {
    foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $w => $px ) {
        $inner = __DIR__ . '/release-31370-live-' . $name . '-' . $w . '.html';
        $src = (string) file_get_contents( __DIR__ . '/' . $file );
        $inject = '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script>';
        $src = ( false !== stripos( $src, '</body>' ) ) ? preg_replace( '#</body>#i', $inject . '</body>', $src, 1 ) : $src . $inject;
        file_put_contents( $inner, $src );
        $files[ "$name@$w" ] = array( $px, __DIR__ . '/release-31370-live-' . $name . '-' . $w . '-frame.html' );
        file_put_contents( $files[ "$name@$w" ][1], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
            . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . $px . 'px;height:2400px;border:0"></iframe>'
            . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
    }
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$GREY  = 'rgb(209, 211, 212)';
$WHITE = 'rgb(255, 255, 255)';
$TEAL  = 'rgb(14, 118, 128)';
$RED   = 'rgb(192, 57, 43)';
$MUTED = 'rgb(107, 103, 100)';
$YEL   = 'rgb(255, 217, 0)';
$seen  = array();
$kinds = array( 'R' => 0, 'red outline' => 0, 'quiet' => 0, 'other' => 0 );
$all   = 0;
$notes = array();
$asked = array();
foreach ( $files as $key => $f ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,2500 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $f[1] ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$key: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $r['errors'] ), "$key threw: " . implode( '; ', (array) $r['errors'] ) );
    if ( in_array( '--verbose', $argv, true ) ) { echo $key . ': ' . json_encode( $r['buttons'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    foreach ( (array) $r['buttons'] as $b ) {
        $all++;
        $id = "$key \"{$b['label']}\" ({$b['cls']})";
        rl( $GREY !== $b['face'], "PLANT N: $id still wears the Light Gray face" );
        if ( null !== $b['text'] ) {
            $floor = $b['large'] ? 3.0 : 4.5;
            if ( $b['linkish'] ) {
                if ( $b['text'] + 0.005 < $floor ) { $notes[ "$id words are {$b['text']}:1 ({$b['ink']})" ] = true; }
            } else {
                rl( $b['text'] + 0.005 >= $floor, "PLANT O: $id words are {$b['text']}:1 ({$b['ink']} on {$b['face']}), under $floor" );
            }
        }
        if ( ! $b['linkish'] && null !== $b['edgeRatio'] && $b['faceRatio'] < 1.1 ) {
            rl( $b['edgeRatio'] + 0.005 >= 3, "PLANT O: $id edge is {$b['edgeRatio']}:1 ({$b['edge']}) on its surface, under 3" );
        }
        if ( $WHITE === $b['face'] && $TEAL === $b['edge'] && $TEAL === $b['ink'] ) { $kinds['R']++; }
        elseif ( $WHITE === $b['face'] && $RED === $b['edge'] ) { $kinds['red outline']++; }
        elseif ( $WHITE === $b['face'] && $MUTED === $b['edge'] ) { $kinds['quiet']++; }
        else { $kinds['other']++; }
        // P: the kinds Mark named, wherever they are drawn.
        $cls = ' ' . $b['cls'] . ' ';
        $plain = ( false !== strpos( $cls, ' uc-btn ' ) && ! preg_match( '/uc-btn-(primary|go|danger|stop|caution|source|quiet|danger-outline)\b|uc-checkin-btn|uc-cancel-open|uc-link-|uc-action-link|uc-faq-remove|uc-scope-btn/', $cls ) );
        if ( $plain || false !== strpos( $cls, 'uc-btn-utility' ) ) {
            rl( $WHITE === $b['face'] && $TEAL === $b['edge'] && $TEAL === $b['ink'], "PLANT P: $id is a secondary button and not R: " . json_encode( $b ) );
        }
        if ( false !== strpos( $cls, 'uc-btn-danger-outline' ) || false !== strpos( $cls, 'uc-faq-remove' ) || false !== strpos( $cls, 'uc-button-danger-outline' ) ) {
            rl( $WHITE === $b['face'] && $RED === $b['edge'] && $RED === $b['ink'], "PLANT P: $id is a destructive outline and not red on white: " . json_encode( $b ) );
        }
        if ( false !== strpos( $cls, 'uc-btn-quiet' ) ) {
            rl( $WHITE === $b['face'] && $MUTED === $b['edge'] && $MUTED === $b['ink'], "PLANT P: $id is the quiet button and not #6B6764 on white: " . json_encode( $b ) );
        }
        if ( 0 === strpos( $key, 'wp-' ) && preg_match( '/(^| )button( |$)/', $b['cls'] ) && ! preg_match( '/button-primary|uc-button-danger-outline|uc-repeater-remove|uc-faq-remove|button-link/', $b['cls'] ) ) {
            rl( $WHITE === $b['face'] && $TEAL === $b['edge'] && $TEAL === $b['ink'], "PLANT P: $id is WP admin's plain button and not R: " . json_encode( $b ) );
        }
        if ( 0 === strpos( $key, 'wp-' ) && false !== strpos( $cls, 'uc-repeater-remove' ) && false === strpos( $cls, 'uc-faq-remove' ) ) {
            rl( $RED === $b['ink'], "PLANT P: $id, a row's x, is not red ink: " . json_encode( $b ) );
        }
        foreach ( array( 'Publish ' => 'drafts', 'Set team on' => 'events', 'Add organizer' => '' ) as $start => $end ) {
            if ( 0 === strpos( $b['label'], $start ) && ( '' === $end || false !== strpos( $b['label'], $end ) ) && 0 === strpos( $key, 'caladmin-' ) ) {
                rl( $YEL === $b['face'], "PLANT P: $id should be yellow: " . json_encode( $b ) );
            }
        }
        $seen[ $b['label'] ] = true;
    }
    if ( 0 === strpos( $key, 'dialog-' ) ) {
        $okb = array_values( array_filter( (array) $r['buttons'], function ( $b ) { return 0 === strpos( $b['label'], 'dialog: Reset to default' ); } ) );
        $want = array( 'dialog-plain' => $YEL, 'dialog-outline' => $RED, 'dialog-go' => 'rgb(21, 128, 61)' )[ strtok( $key, '@' ) ];
        rl( isset( $okb[0] ) && $want === $okb[0]['face'], "PLANT P: $key: the dialog's Reset to default is " . json_encode( $okb[0] ?? null ) . ", not $want" );
    }
    if ( ! empty( $r['asked'] ) ) { $asked[] = $key . ': ' . $r['asked']['label'] . ' opened ' . ( empty( $r['dialog'] ) ? 'no dialog' : $r['dialog'] . ' buttons' ); }
}
if ( ! $only ) {
rl( $all > 300, "only $all buttons measured" );
rl( $kinds['R'] > 100, 'only ' . $kinds['R'] . ' R buttons seen' );
rl( count( array_filter( $asked, function ( $a ) { return false !== strpos( $a, 'buttons' ); } ) ) >= 4, 'too few dialogs opened: ' . implode( '; ', $asked ) );
foreach ( array( 'Fetch updates', 'Save Twilio settings', 'Export CSV', 'Remove this question', 'dialog: Cancel' ) as $must ) {
    rl( isset( $seen[ $must ] ), "\"$must\" was not on any page measured" );
}
}

echo "\n3.137.0 in a browser: every button, measured\n" . str_repeat( '=', 72 ) . "\n";
echo "measured $all buttons on " . count( $files ) . " pages: " . json_encode( $kinds ) . "\n";
if ( in_array( '--verbose', $argv, true ) ) { echo "dialogs: " . implode( "\n  ", $asked ) . "\n"; }
if ( $notes ) { echo "NOT CHANGED IN 3.137.0, under the floor (link-shaped):\n  " . implode( "\n  ", array_keys( $notes ) ) . "\n"; }
if ( $fails ) { $u = array_values( array_unique( $fails ) ); foreach ( array_slice( $u, 0, 60 ) as $f ) { echo "FAIL  $f\n"; } echo count( $u ) . " problem(s)\n"; exit( 1 ); }
echo "every check passed.\n";
exit( 0 );
