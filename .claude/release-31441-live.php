<?php
/**
 * 3.144.1 IN A BROWSER: THE REPLY BADGES, THE NEW REPLIES, THE ERROR LOOK.
 *
 *     php .claude/release-31441-live.php --run
 *     php .claude/release-31441-live.php --run --verbose   and print what was measured
 *
 * REAL: the sidebar (chrome_open()), the Events list (events_table()), the
 * Replies section (render_text_replies()) and the flash (flash()), through
 * wp-kit.php and text-inbox-kit.php with the real portal.css and portal.js,
 * in Chrome at 1280, 430 and 390px. The other error classes are drawn as the
 * markup their renderers emit, since what is measured is portal.css.
 *
 *   B  the badge on the sidebar's Events and beside the RSVP count: white
 *      figures on #AD1C0D, at least 4.5:1, round, on one line, inside its
 *      row and on screen; beside the pill, centred on it within 4px
 *   N  each new reply on #FFFAE0 with a #FFD900 edge and "New" in #705F00,
 *      at least 4.5:1, inside the card; a reply already seen and an answer
 *      not highlighted
 *   E  every error: #FDE9E7, a 1px #F04937 edge on all four sides, #373433
 *      words at least 4.5:1, inside the page; warnings not red; the public
 *      form's field error unchanged
 *
 * Every page: no script error and no sideways scroll. PLANT in plant-31441.php.
 */

require __DIR__ . '/wp-kit.php';
require __DIR__ . '/text-inbox-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

$db = ri_fresh();
update_post_meta( 900, '_uc_rsvp_enabled', '1' );
SFAF_Texts::send_update( 900, 'Doors open at 9:45', 7 );
SFAF_Texts::answer( '+14155550101', 'Is there parking?' );
SFAF_Text_Inbox::reply( 900, '+14155550101', 'Yes, on Market St.', 8 );
$me = wp_get_current_user();
// Ana's first reply already seen by this person; the next two are new.
SFAF_Text_Inbox::mark_seen( $me->ID, 900, 2 === count( $db->msgs ) ? 0 : (int) $db->msgs[ count( $db->msgs ) - 2 ]->id );
SFAF_Texts::answer( '+14155550101', 'And is it step-free? The last place had stairs.' );
SFAF_Texts::answer( '+14155550101', 'Thanks' );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$chrome = rl_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'chrome_open', $portal, array( $me, 'dashboard', false ) ); } );
$side   = preg_match( '#<aside class="uc-portal-sidebar".*?</aside>#s', $chrome, $sm ) ? $sm[0] : '';
rl( '' !== $side, 'the sidebar did not render: ' . substr( $chrome, 0, 300 ) );
$list   = rl_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'events_table', $portal, array( array( 900, 901 ), $me ) ); } );
$_GET   = array();
$rp     = rl_cap( function () use ( $portal, $me ) { kit_call( 'SFAF_Portal', 'render_text_replies', $portal, array( $me, 900 ) ); } );
$_GET   = array( 'msg' => 'category_failed' );
$fl     = rl_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'flash', $portal, array() ); } );
$_GET   = array( 'msg' => 'publish_needs' );
$fw     = rl_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'flash', $portal, array() ); } );
$_GET   = array();
foreach ( array( 'Events list' => $list, 'Replies' => $rp, 'error flash' => $fl, 'warning flash' => $fw ) as $k => $h ) { rl( '' !== trim( $h ) && 0 !== strpos( $h, 'THREW' ), "$k did not render: " . substr( $h, 0, 300 ) ); }

$errors = '<div class="uc-card" data-rl-errors>' . $fl
    . '<p class="uc-action-error" data-rl-err="action">The event was not saved. Check your connection and save again.</p>'
    . '<p class="uc-notice uc-notice-error" role="alert" data-rl-err="notice">The preview could not be made.</p>'
    . '<label class="uc-field"><span class="uc-field-label">Email address for replies</span><input type="email" class="uc-invalid" value="nope" />'
    . '<p class="uc-field-error" role="alert" data-rl-err="field">Not an email address, so it was not saved: nope</p></label>'
    . '<p class="uc-hint uc-hint-error" role="alert" data-rl-err="hint">PDFs must be under 5MB.</p>'
    . '<p class="uc-login-error" role="alert" data-rl-err="login">Your session had ended, so that change was not saved. Sign in and your form will be restored.</p>'
    . '<ul class="uc-fetch-report"><li class="uc-fetch-fail" data-rl-err="fetch"><span class="uc-fetch-line">EveryAction: failed.</span></li></ul>'
    . '<p class="uc-lastrun-alarm" data-rl-err="alarm">Nothing has fetched for over an hour.</p>'
    . '<p class="uc-field-error" data-rl-empty hidden></p>'
    . $fw
    . '<div class="uc-flash uc-flash-attention" data-rl-warn="restore">Restore what you were typing?</div>'
    . '<div class="uc-flash uc-flash-warn" data-rl-warn="warn">Two sources disagree.</div>'
    . '<p class="uc-hint uc-hint-warn" data-rl-warn="hint">This changes every upcoming date.</p>'
    . '</div>';

function rl_page( $body, $class = 'uc-portal' ) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="../public/css/portal.css"></head><body class="' . $class . '" style="margin:0">'
        . $body
        . '<script src="../public/js/portal.js"></script>';
}
$admin  = '<div class="uc-portal-layout">' . $side . '<main class="uc-portal-main"><div class="uc-portal-content">'
    . '<div class="uc-card">' . $list . '</div>' . $rp . $errors . '</div></main></div>';
$public = '<div class="uc-request-wrap"><div class="uc-card"><label class="uc-field"><span class="uc-field-label">Email</span><input type="email" class="uc-invalid" value="x" />'
    . '<p class="uc-field-error" role="alert" data-rl-public>Enter a real email address.</p></label></div></div>';

$probe = <<<'JS'
(function () {
var out = { page: window.RL_PAGE, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function rgb(s) { var m = String(s).match(/\d+(\.\d+)?/g); return m ? m.slice(0, 3).map(Number) : null; }
function lum(c) { return c.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function ratio(a, b) { var x = lum(a), y = lum(b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; }
function box(el) { var r = el.getBoundingClientRect(); return { l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, h: r.height }; }
function lines(el) { var rg = document.createRange(); rg.selectNodeContents(el); var tops = {}; Array.prototype.forEach.call(rg.getClientRects(), function (c) { if (c.width > 1) { tops[Math.round(c.top + c.height / 2)] = 1; } }); return Object.keys(tops).length; }
function badge(b) {
  var cs = getComputedStyle(b), r = box(b), vis = b.querySelector('.uc-visually-hidden'), fig = b.firstChild ? String(b.firstChild.nodeValue || '').trim() : '';
  return { text: text(b), fig: fig, bg: cs.backgroundColor, color: cs.color, contrast: ratio(rgb(cs.color), rgb(cs.backgroundColor)), radius: cs.borderTopLeftRadius,
    seen: seen(b), h: r.h, w: r.w, l: r.l, r: r.r, t: r.t, b: r.b, cy: r.t + r.h / 2, lines: 1, underline: cs.textDecorationLine,
    onScreen: r.l >= -0.5 && r.r <= document.documentElement.clientWidth + 0.5, hiddenText: vis ? text(vis) : '' };
}
function finish() { out.pageWidth = document.documentElement.scrollWidth; out.vw = document.documentElement.clientWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  if (window.RL_PAGE.indexOf('public') === 0) {
    var pe = q('[data-rl-public]'), pc = getComputedStyle(pe);
    out.publicErr = { color: pc.color, bg: pc.backgroundColor, border: pc.borderTopWidth + ' ' + pc.borderTopStyle };
    return finish();
  }
  var nav = q('.uc-portal-nav');
  var navItem = nav ? qa('a.uc-nav-item', nav).filter(function (a) { return /\/caladmin\/events$/.test(a.getAttribute('href')); })[0] : null;
  var nb = navItem ? q('[data-uc-replies-badge]', navItem) : null;
  out.navBadges = nav ? qa('[data-uc-replies-badge]', nav).length : 0;
  out.nav = nb ? badge(nb) : null;
  if (nb) { var ir = box(navItem); out.nav.inItem = out.nav.l >= ir.l - 0.5 && out.nav.r <= ir.r + 0.5; out.nav.cyInItem = Math.abs(out.nav.cy - (ir.t + ir.h / 2)) <= 2;
    // On a phone the sidebar is a drawer, off screen until opened.
    out.nav.sidebarShown = seen(navItem) && ir.l >= -0.5 && ir.r <= document.documentElement.clientWidth + 0.5; out.nav.itemOff = ir.l; }
  out.rows = qa('a[data-uc-replies-badge]').map(function (a) {
    var b = badge(a), td = a.closest('td'), pill = td ? q('[data-uc-rsvp-pill]', td) : null;
    b.href = a.getAttribute('href');
    b.inCell = !!td && box(a).l >= box(td).l - 0.5 && box(a).r <= box(td).r + 0.5;
    if (pill) { var p = box(pill); b.pillDy = Math.abs((p.t + p.h / 2) - b.cy); b.afterPill = b.l >= p.r; b.gap = b.l - p.r; }
    return b;
  });
  var card = q('details[data-uc-text-replies]');
  out.repliesOpen = !!(card && card.open);
  var cb = card ? box(card) : null;
  out.msgs = qa('li[data-uc-reply-msg]').map(function (li) {
    var cs = getComputedStyle(li), tag = q('.uc-reply-new-tag', li), tc = tag ? getComputedStyle(tag) : null, r = box(li), meta = q('.uc-reply-meta', li);
    return { dir: li.getAttribute('data-uc-reply-msg'), body: text(q('.uc-reply-body', li)), isNew: li.hasAttribute('data-uc-reply-new'), bg: cs.backgroundColor,
      border: cs.borderTopWidth + ' ' + cs.borderTopStyle + ' ' + cs.borderTopColor, tag: tag ? text(tag) : '', tagColor: tc ? tc.color : '',
      tagContrast: tc ? ratio(rgb(tc.color), rgb(cs.backgroundColor)) : 0, metaContrast: meta ? ratio(rgb(getComputedStyle(meta).color), rgb(cs.backgroundColor === 'rgba(0, 0, 0, 0)' ? 'rgb(255,255,255)' : cs.backgroundColor)) : 0,
      inside: cb ? (r.l >= cb.l - 0.5 && r.r <= cb.r + 0.5) : false, seen: seen(li) };
  });
  out.errs = qa('[data-rl-errors] .uc-flash-error, [data-rl-err]').map(function (e) {
    var cs = getComputedStyle(e), r = box(e);
    return { what: e.getAttribute('data-rl-err') || 'flash', text: text(e).slice(0, 40), bg: cs.backgroundColor, color: cs.color, contrast: ratio(rgb(cs.color), rgb(cs.backgroundColor)),
      borders: [cs.borderTopWidth, cs.borderRightWidth, cs.borderBottomWidth, cs.borderLeftWidth].join(' '), styles: [cs.borderTopStyle, cs.borderRightStyle, cs.borderBottomStyle, cs.borderLeftStyle].join(' '),
      edge: [cs.borderTopColor, cs.borderRightColor, cs.borderBottomColor, cs.borderLeftColor], line: e.querySelector('.uc-fetch-line') ? getComputedStyle(e.querySelector('.uc-fetch-line')).color : '',
      seen: seen(e), onScreen: r.l >= -0.5 && r.r <= document.documentElement.clientWidth + 0.5 };
  });
  var empty = q('[data-rl-empty]'); out.emptyShown = empty ? seen(empty) : null;
  out.warns = qa('[data-rl-errors] .uc-flash-attention, [data-rl-warn]').map(function (e) { var cs = getComputedStyle(e); return { what: e.getAttribute('data-rl-warn') || 'publish_needs', bg: cs.backgroundColor, color: cs.color, edge: cs.borderTopColor }; });
  finish();
}, 500); });
})();
JS;

$pages = array();
foreach ( array( 1280, 430, 390 ) as $w ) { $pages[ "admin-$w" ] = array( $w, rl_page( $admin ) ); }
$pages['public-390'] = array( 390, rl_page( $public, 'uc-portal uc-request-page' ) );
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31441-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] . '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body></html>' );
    $files[ $name ] = __DIR__ . '/release-31441-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p[0] . 'px;height:3200px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$RED   = 'rgb(173, 28, 13)';   // --p-error-ink #AD1C0D
$WHITE = 'rgb(255, 255, 255)';
$TINT  = 'rgb(253, 233, 231)'; // --p-error-tint #FDE9E7
$EDGE  = 'rgb(240, 73, 55)';   // --p-danger #F04937
$INK   = 'rgb(55, 52, 51)';    // --p-text #373433
$YTINT = 'rgb(255, 250, 224)'; // Yellow's tint #FFFAE0
$YEDGE = 'rgb(255, 217, 0)';   // #FFD900
$YINK  = 'rgb(112, 95, 0)';    // Yellow's ink #705F00

foreach ( $files as $name => $file ) {
    $w   = $pages[ $name ][0];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,3300 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $r = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    rl( empty( $r['errors'] ), "$name threw: " . implode( '; ', (array) $r['errors'] ) );
    rl( (int) $r['pageWidth'] <= $w, "PLANT E: $name: the page scrolls sideways, " . $r['pageWidth'] . "px in $w" );

    if ( 0 === strpos( $name, 'public' ) ) {
        $pe = $r['publicErr'];
        rl( 'rgb(185, 28, 28)' === $pe['color'] && 'rgba(0, 0, 0, 0)' === $pe['bg'] && 0 === strpos( $pe['border'], '0px' ), "PLANT E: $name: the public form's field error changed: " . json_encode( $pe ) );
        continue;
    }

    /* B. The badges. */
    rl( 1 === (int) $r['navBadges'], "PLANT B: $name: the sidebar has " . (int) $r['navBadges'] . ' reply badges, not one on Events' );
    $nb = $r['nav'];
    rl( $nb && '2' === $nb['fig'] && '2 new replies' === $nb['text'] && $RED === $nb['bg'] && $WHITE === $nb['color'] && $nb['contrast'] >= 4.5 && '99px' === $nb['radius'], "PLANT B: $name: the sidebar badge is " . json_encode( $nb ) );
    rl( $nb && $nb['seen'] && $nb['inItem'] && $nb['cyInItem'] && $nb['h'] <= 24, "PLANT B: $name: the sidebar badge lays out as " . json_encode( $nb ) );
    if ( $nb && $nb['sidebarShown'] ) { rl( $nb['onScreen'], "PLANT B: $name: the sidebar badge is off screen: " . json_encode( $nb ) ); }
    if ( $w >= 1000 ) { rl( $nb && $nb['sidebarShown'], "$name: the sidebar is not on screen at $w" ); }
    $rows = (array) $r['rows'];
    rl( 1 === count( $rows ), "PLANT B: $name: the Events list has " . count( $rows ) . ' reply badges, not one beside Coffee & Conversation' );
    foreach ( $rows as $b ) {
        rl( '2' === $b['fig'] && $RED === $b['bg'] && $WHITE === $b['color'] && $b['contrast'] >= 4.5 && '99px' === $b['radius'] && 'none' === $b['underline'], "PLANT B: $name: the list badge is " . json_encode( $b ) );
        rl( $b['seen'] && $b['onScreen'] && $b['inCell'] && $b['h'] <= 24 && $b['w'] < 60, "PLANT B: $name: the list badge lays out as " . json_encode( $b ) );
        rl( isset( $b['pillDy'] ) && $b['pillDy'] <= 4 && $b['afterPill'] && $b['gap'] <= 12, "PLANT B: $name: the list badge is not beside the RSVP count: " . json_encode( $b ) );
        rl( false !== strpos( $b['href'], 'replies=1' ) && false !== strpos( $b['href'], '#uc-replies' ), "$name: the list badge goes to " . $b['href'] );
    }

    /* N. The new replies. */
    rl( ! empty( $r['repliesOpen'] ), "$name: Replies is not open with new replies" );
    $msgs = (array) $r['msgs'];
    $flags = array_map( function ( $x ) { return $x['dir'] . ( $x['isNew'] ? ':new' : '' ); }, $msgs );
    // 3.144.2: the Text registrants message the first reply answered comes first.
    rl( array( 'sent', 'in', 'out', 'in:new', 'in:new' ) === $flags, "$name: the messages are " . json_encode( $flags ) );
    foreach ( $msgs as $x ) {
        rl( $x['seen'] && $x['inside'], "PLANT N: $name: " . json_encode( $x['body'] ) . ' is not inside the card' );
        if ( $x['isNew'] ) {
            rl( $YTINT === $x['bg'] && 0 === strpos( $x['border'], '1px solid ' . $YEDGE ) && 'New' === $x['tag'] && $YINK === $x['tagColor'] && $x['tagContrast'] >= 4.5 && $x['metaContrast'] >= 4.5, "PLANT N: $name: a new reply is " . json_encode( $x ) );
        } else {
            rl( 'rgba(0, 0, 0, 0)' === $x['bg'] && '' === $x['tag'], "PLANT N: $name: " . json_encode( $x['body'] ) . ' is highlighted though not new: ' . json_encode( $x ) );
        }
    }

    /* E. The errors. */
    $errs = (array) $r['errs'];
    rl( 8 === count( $errs ), "$name: " . count( $errs ) . ' errors drawn, not 8: ' . json_encode( array_column( $errs, 'what' ) ) );
    foreach ( $errs as $e ) {
        rl( $TINT === $e['bg'] && $INK === $e['color'] && $e['contrast'] >= 4.5 && '1px 1px 1px 1px' === $e['borders'] && 'solid solid solid solid' === $e['styles'] && array( $EDGE, $EDGE, $EDGE, $EDGE ) === $e['edge'],
            "PLANT E: $name: the {$e['what']} error is " . json_encode( $e ) );
        rl( $e['seen'] && $e['onScreen'], "$name: the {$e['what']} error is not on screen" );
        if ( 'fetch' === $e['what'] ) { rl( $INK === $e['line'], "PLANT E: $name: the fetch failure's line is " . $e['line'] ); }
    }
    rl( false === $r['emptyShown'], "$name: an empty error box shows" );
    foreach ( (array) $r['warns'] as $wr ) {
        rl( $TINT !== $wr['bg'] && $EDGE !== $wr['edge'], "PLANT E: $name: the {$wr['what']} warning is red: " . json_encode( $wr ) );
    }
    rl( 4 === count( (array) $r['warns'] ), "$name: " . count( (array) $r['warns'] ) . ' warnings drawn, not 4' );
}

echo "\n3.144.1 in a browser: reply badges, new replies, the error look\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " FAILURE(S):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo count( $files ) . " pages: caladmin at 1280, 430 and 390px, and a public form at 390px.\n";
exit( 0 );
