<?php
/**
 * 3.135.0 IN A BROWSER: THE DIALOGS' COLOURS, TEXT REGISTRANTS' COUNT AND
 * WHATSAPP PANEL, THE LANGUAGE TAGS AND THE EVENTS LIST'S RSVP BUTTON.
 *
 *     php .claude/release-31350-live.php --run
 *     php .claude/release-31350-live.php --run --verbose   and print what was measured
 *
 * REAL: Edit event (render_event_form()), Text registrants and the Events
 * list (events_table()), rendered through wp-kit.php with the real portal.css
 * and portal.js, in Chrome at 1280px and 390px.
 *
 *   J  Cancel event with people registered: "Cancel and email them" red
 *      (#c0392b) on the first step and on the second
 *   K  Text registrants: 0 of 600 to start; typing counts the characters and
 *      the pieces as SFAF_Texts::pieces() does, one á making them shorter;
 *      "What can I send by WhatsApp?" closed, then open and on screen; EN and
 *      ES beside the names; Send asks in green (#15803D), as Publish does
 *   L  the Events list: "12 RSVPs" and "View RSVPs" grey secondary buttons,
 *      on one line
 *
 * PLANT in plant-31350.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
function rl_event( $title, $meta = array() ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+7 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
$GLOBALS['kit_query'] = function ( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' === $p->post_type ) { $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; }
    }
    return $out;
};

/* ---- Twilio and WhatsApp on; three who chose a channel, one in Spanish. ---- */
SFAF_Texts::save( array( 'twilio_account_sid' => 'AC' . str_repeat( 'a', 32 ), 'twilio_messaging_sid' => 'MG' . str_repeat( 'b', 32 ), 'twilio_whatsapp_from' => '+14155550100',
    'twilio_wa_reminder_en' => 'HX' . str_repeat( 'c', 32 ), 'twilio_wa_reminder_es' => 'HX' . str_repeat( 'd', 32 ), 'twilio_wa_update_en' => 'HX' . str_repeat( 'e', 32 ), 'twilio_wa_update_es' => 'HX' . str_repeat( 'f', 32 ) ), 'token0123456789' );
$ev = rl_event( 'Support group', array( '_uc_email_replyto' => 'events@sfaf.org' ) );
function rl_row( $id, $first, $phone, $channel, $lang ) {
    return array( 'id' => $id, 'event_id' => $GLOBALS['ev'], 'name' => $first, 'first_name' => $first, 'last_name' => '', 'email' => strtolower( $first ) . '@example.org', 'phone' => $phone,
        'status' => 'confirmed', 'token' => 't' . $id, 'format' => '', 'lang' => $lang, 'text_channel' => $channel, 'text_opt_in' => 0, 'created_at' => '2026-10-2' . $id . ' 10:00:00',
        'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null );
}
$GLOBALS['rl_rows'] = array(
    rl_row( 1, 'Ana', '(415) 555-0101', 'sms', 'en' ),
    rl_row( 2, 'Beto', '+52 55 1234 5678', 'whatsapp', 'es' ),
    rl_row( 3, 'Cruz', '(415) 555-0103', 'sms', 'es' ),
);
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
    $rows = $GLOBALS['rl_rows'];
    if ( preg_match( '/\bevent_id\s*=\s*(\d+)/', $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r['event_id'] === (int) $m[1]; } );
    }
    if ( preg_match( "/status\s*=\s*'([a-z_]+)'/", $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['status'] === $m[1]; } );
    } elseif ( preg_match( "/status\s+IN\s*\(([^)]*)\)/i", $sql, $m ) ) {
        preg_match_all( "/'([a-z_]+)'/", $m[1], $in );
        $rows = array_filter( $rows, function ( $r ) use ( $in ) { return in_array( $r['status'], $in[1], true ); } );
    }
    if ( preg_match( '/text_channel IN/', $sql ) ) {
        $rows = array_filter( $rows, function ( $r ) { return in_array( $r['text_channel'], array( 'sms', 'whatsapp' ), true ); } );
    }
    if ( 'get_var' === $method ) { return (string) count( $rows ); }
    if ( 'get_results' === $method ) {
        return array_map( function ( $r ) { $r['post_title'] = get_the_title( $r['event_id'] ); $r['event_title'] = ''; return (object) $r; }, array_values( $rows ) );
    }
    return null;
};
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$GLOBALS['portal'] = $portal;
$me = wp_get_current_user();

function rl_page( $body ) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="../public/css/portal.css"></head><body class="uc-portal" style="margin:0">'
        . '<div class="uc-portal-main"><div class="uc-portal-content">' . $body . '</div></div>'
        . '<script src="../public/js/portal.js"></script>';
}
$edit = rl_cap( function () use ( $portal, $me, $ev ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $me, $ev ) ); } );
$tr   = rl_cap( function () use ( $portal, $me, $ev ) { kit_call( 'SFAF_Portal', 'render_text_registrants', $portal, array( $me, $ev ) ); } );
$two  = rl_event( 'Book club' );
$store =& sfaf_rsvp_count_store();
$store[ $ev ] = 12; $store[ $two ] = 0;
$list = rl_cap( function () use ( $portal, $me, $ev, $two ) { kit_call( 'SFAF_Portal', 'events_table', $portal, array( array( $ev, $two ), $me ) ); } );
foreach ( array( 'edit' => $edit, 'tr' => $tr, 'list' => $list ) as $k => $h ) { rl( 0 !== strpos( $h, 'THREW' ), "$k did not render: " . substr( $h, 0, 300 ) ); }
$tr = str_replace( array( '<details class="uc-card', 'data-uc-disclosure' ), array( '<details open class="uc-card', 'data-uc-disclosure-off' ), $tr );

/* What the count must say, from the server's own arithmetic. */
$wrap = SFAF_Texts::sms_wrap( $ev );
$want = function ( $msg ) use ( $wrap ) {
    $m = SFAF_Texts::ended( $msg );
    return max( array_map( function ( $w ) use ( $m ) { return SFAF_Texts::pieces( $w[0] . $m . $w[1] ); }, $wrap ) );
};
$typed = array(
    'short' => 'Doors open at 9:45 at the side entrance.',
    'long'  => trim( str_repeat( 'Bring water and a light jacket. ', 9 ) ),
    'acute' => 'Habrá café.',
);
$expect = array();
foreach ( $typed as $k => $t ) { $expect[ $k ] = array( 'chars' => count( preg_split( '//u', $t, -1, PREG_SPLIT_NO_EMPTY ) ) . ' of 600 characters.', 'pieces' => 'By text, about ' . $want( $t ) . ( 1 === $want( $t ) ? ' piece.' : ' pieces.' ) ); }
rl( $want( $typed['acute'] ) > $want( 'Habra cafe.' ) || 2 <= $want( $typed['acute'] ), 'the accented message is not shorter pieces; the check proves nothing' );

$probe = <<<'JS'
(function () {
var P = window.RL_PAGE, T = window.RL_TYPED, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function bg(el) { return el ? getComputedStyle(el).backgroundColor : ''; }
function onScreen(el) { var r = el.getBoundingClientRect(); return r.left >= -0.5 && r.right <= document.documentElement.clientWidth + 0.5; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  if (P.indexOf('edit') === 0) {
    var form = q('form[data-uc-confirm-cancel]:not([data-uc-confirm-reinstate])');
    if (!form) { out.noForm = true; return finish(); }
    var d = form.closest('details'); if (d) { d.open = true; }
    form.querySelector('button[type="submit"]').click();
    return setTimeout(function () {
      var dlg = q('dialog.uc-notify-modal[open]');
      var send = dlg ? qa('button', dlg).filter(function (b) { return /^Cancel and email/.test(text(b)) && seen(b); })[0] : null;
      out.first = send ? { label: text(send), bg: bg(send) } : null;
      if (send) { send.click(); }
      var two = dlg ? qa('.uc-notify-step button', dlg).filter(function (b) { return /^Cancel and email/.test(text(b)) && seen(b); })[0] : null;
      out.second = two ? { label: text(two), bg: bg(two) } : null;
      finish();
    }, 300);
  }
  if (P.indexOf('tr') === 0) {
    var box = q('[data-uc-tr-message]'), chars = q('[data-uc-tr-chars]'), pieces = q('[data-uc-tr-pieces]');
    out.start = { chars: text(chars), pieces: text(pieces), seen: seen(chars) };
    out.typed = {};
    Object.keys(T).forEach(function (k) { box.value = T[k]; box.dispatchEvent(new Event('input', { bubbles: true })); out.typed[k] = { chars: text(chars), pieces: text(pieces) }; });
    out.under = !!(box.getBoundingClientRect().bottom <= q('[data-uc-tr-count]').getBoundingClientRect().top + 0.5);
    var help = q('[data-uc-tr-wa-help]'), sum = help ? q('summary', help) : null, body = help ? q('.uc-tr-wa-help-body', help) : null;
    out.help = { summary: text(sum), summarySeen: seen(sum), bodyClosed: !!(help && help.open) };
    if (sum) { sum.click(); }
    out.help.bodyOpen = !!(help && help.open && seen(body) && body.getBoundingClientRect().height > 40); out.help.onScreen = body ? onScreen(body) : false;
    out.help.under = !!(help && q('[data-uc-tr-count]').getBoundingClientRect().bottom <= help.getBoundingClientRect().top + 0.5);
    out.tags = qa('li[data-uc-rcp-row]').map(function (li) { var t = q('[data-uc-lang-tag]', li); return text(q('.uc-rcp-name', li)) + ':' + (t && seen(t) ? text(q('[aria-hidden]', t)) : ''); });
    box.value = T.short; box.dispatchEvent(new Event('input', { bubbles: true }));
    var send = q('[data-uc-tr-send]');
    send.click();
    return setTimeout(function () {
      var dlg = q('dialog.uc-confirm-modal[open]');
      var ok = dlg ? q('button[value="ok"]', dlg) : null;
      out.confirm = ok ? { label: text(ok), bg: bg(ok) } : null;
      finish();
    }, 300);
  }
  out.buttons = qa('a[data-uc-rsvp-count-btn]').map(function (a) { var rg = document.createRange(); rg.selectNodeContents(a); var tops = {}; Array.prototype.forEach.call(rg.getClientRects(), function (c) { tops[Math.round(c.top)] = 1; }); return { label: text(a), bg: bg(a), oneLine: 1 === Object.keys(tops).length, h: Math.round(a.getBoundingClientRect().height), seen: seen(a) }; });
  finish();
}, 500); });
})();
JS;

$pages = array(
    'edit-desktop' => array( 1280, rl_page( $edit ) ),
    'edit-phone'   => array( 390, rl_page( $edit ) ),
    'tr-desktop'   => array( 1280, rl_page( $tr ) ),
    'tr-phone'     => array( 390, rl_page( $tr ) ),
    'list-desktop' => array( 1280, rl_page( $list ) ),
    'list-phone'   => array( 390, rl_page( $list ) ),
);
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31350-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] . '<script>window.RL_PAGE=' . json_encode( $name ) . ';window.RL_TYPED=' . json_encode( $typed ) . ';' . $probe . '</script></body></html>' );
    $files[ $name ] = __DIR__ . '/release-31350-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p[0] . 'px;height:1600px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1700 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ][0], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ][0] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}

$RED   = 'rgb(192, 57, 43)';    // #c0392b
$GREEN = 'rgb(21, 128, 61)';    // #15803D
$GREY  = 'rgb(209, 211, 212)';  // --p-btn-face #D1D3D4
foreach ( $g as $name => $r ) {
    if ( 0 === strpos( $name, 'edit' ) ) {
        rl( isset( $r['first']['bg'] ) && $RED === $r['first']['bg'], "PLANT J: $name: the first step's send is " . json_encode( $r['first'] ?? null ) );
        rl( isset( $r['second']['bg'] ) && $RED === $r['second']['bg'], "PLANT J: $name: the second step's send is " . json_encode( $r['second'] ?? null ) );
        continue;
    }
    if ( 0 === strpos( $name, 'tr' ) ) {
        rl( '0 of 600 characters.' === $r['start']['chars'] && $r['start']['seen'], "PLANT K: $name: the count starts " . json_encode( $r['start'] ) );
        foreach ( $expect as $k => $e ) {
            rl( isset( $r['typed'][ $k ] ) && $e === $r['typed'][ $k ], "PLANT K: $name: typing the $k message reads " . json_encode( $r['typed'][ $k ] ?? null, JSON_UNESCAPED_UNICODE ) . ', wanted ' . json_encode( $e ) );
        }
        rl( $r['under'], "$name: the count is not under the box" );
        rl( 'What can I send by WhatsApp?' === $r['help']['summary'] && $r['help']['summarySeen'] && ! $r['help']['bodyClosed'] && $r['help']['bodyOpen'] && $r['help']['onScreen'] && $r['help']['under'],
            "PLANT K: $name: the WhatsApp panel " . json_encode( $r['help'] ) );
        rl( array( 'Ana:EN', 'Beto:ES', 'Cruz:ES' ) === $r['tags'], "PLANT K: $name: the tags read " . json_encode( $r['tags'] ) );
        rl( isset( $r['confirm']['bg'] ) && $GREEN === $r['confirm']['bg'] && 'Send' === $r['confirm']['label'], "PLANT K: $name: Send asks with " . json_encode( $r['confirm'] ?? null ) );
        continue;
    }
    $labels = array_column( (array) $r['buttons'], 'label' );
    rl( array( '12 RSVPs', 'View RSVPs' ) === $labels, "PLANT L: $name: the buttons read " . json_encode( $labels ) );
    foreach ( (array) $r['buttons'] as $b ) {
        rl( $GREY === $b['bg'] && $b['oneLine'] && $b['seen'], "PLANT L: $name: a button is " . json_encode( $b ) );
    }
}

echo "\n3.135.0 in a browser: the dialogs, Text registrants' count and panel, the tags, the RSVP button\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { foreach ( $fails as $f ) { echo "FAIL  $f\n"; } exit( 1 ); }
echo 'every check passed, on ' . count( $g ) . " pages.\n";
exit( 0 );
