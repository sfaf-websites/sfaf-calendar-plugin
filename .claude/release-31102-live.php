<?php
/**
 * 3.110.2 IN A REAL BROWSER: THE PHONE PASS OVER THE REST OF CALADMIN, THE
 * USERS TABLE, AND EMAIL REGISTRANTS' SEND.
 *
 *     php .claude/release-31102-live.php            writes the pages
 *     php .claude/release-31102-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31102-live.php --shots    --run, and writes .claude/screens/caladmin-31102-*.png
 *
 *   F    every screen 3.110.0 did not cover, at 390px and 430px: nothing past
 *        the right edge, no sideways scroll, no tap target under 44px; a table
 *        that does not fit becomes one-column cards below 600px
 *        PLANT F: a table overflowing at 390px
 *   B.1  the Users table: alphabetical by last name; every Save off until its
 *        row changes, and off again when the change is put back
 *        PLANT B.1: Save enabled with no change
 *   B.2  Approval and Contributor categories on a contributor's row only; a
 *        role changed to Contributor shows them, to Editor hides them
 *        PLANT B.2: Contributor categories shown for an Editor
 *   B.3  + Add member in the outlined style
 *   B.4  at 390px a user row is two lines: who, then the controls
 *   A.2  Send in the Publish green; its envelope slides in over 150ms on
 *        focus, and appears without a transition when motion is reduced
 *
 * The screens: dashboard, events list, pending queue, series list and a
 * series, Users and Teams (one screen), Images, Venues, Organizers, FAQ
 * Sets, Email Opt-ins, Email Templates, Preferences, sign-in, forgot and
 * reset, the RSVP list, and the tour on Add event. There is no Settings
 * screen in caladmin; the plugin's settings are WordPress admin's.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'sanitize_user' ) ) { function sanitize_user( $u ) { return preg_replace( '/[^a-z0-9_.@-]/i', '', (string) $u ); } }
if ( ! function_exists( 'check_password_reset_key' ) ) { function check_password_reset_key( $key, $login ) { return new WP_User(); } }

$fails = array();
function rq( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rq_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

/* ---- The world. -------------------------------------------------------- */
$GLOBALS['kit_query'] = function ( $a ) {
    $o = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( ( isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event' ) !== $p->post_type ) { continue; }
        if ( ! empty( $a['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', $a['post__in'] ), true ) ) { continue; }
        $o[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $o;
};
function rq_event( $title, $status, $meta = array() ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => $title, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+6 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market Street, San Francisco', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    wp_set_object_terms( $id, array( 11 ), 'uc_event_category' );
    wp_set_object_terms( $id, array( 11 ), 'uc_series' );
    return $id;
}
$walk = rq_event( 'Harm reduction walk through the Tenderloin and back again', 'publish' );
rq_event( 'Coffee social', 'publish' );
rq_event( 'Support group for people newly diagnosed', 'pending' );
rq_event( 'Imported gala dinner', 'draft' );
$GLOBALS['rq_rows'] = array();
foreach ( array( array( 'Ana', 'Alvarez-Montenegro', 'ana.alvarez.montenegro@example.org' ), array( 'Ben', 'Brooks', 'ben@example.org' ), array( 'Dee', 'Diaz', '' ) ) as $i => $r ) {
    $GLOBALS['rq_rows'][] = array( 'id' => $i + 1, 'event_id' => $walk, 'name' => $r[0] . ' ' . $r[1], 'first_name' => $r[0], 'last_name' => $r[1], 'email' => $r[2],
        'phone' => '', 'status' => 'confirmed', 'token' => 't' . $i, 'format' => '', 'created_at' => '2026-09-2' . $i . ' 10:00:00', 'cancelled_at' => null,
        'removed_by' => 0, 'agreed_at' => null, 'text_opt_in' => 0, 'checked_in_at' => null, 'answers' => '', 'optin' => 0, 'post_title' => 'Harm reduction walk', 'event_title' => '' );
}
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
    $rows = $GLOBALS['rq_rows'];
    if ( 'get_var' === $method ) { return (string) count( $rows ); }
    if ( 'get_results' === $method ) { return array_map( function ( $r ) { return (object) $r; }, $rows ); }
    return null;
};

/* Calendar users: an editor, two contributors (one auto-publish). Sorted by
   last name they read Adams, Martinez, Young. */
function rq_user( $id, $name, $email, $role, $last, $approval = '' ) {
    $u = new WP_User(); $u->ID = $id; $u->display_name = $name; $u->user_email = $email; $u->user_login = 'u' . $id;
    $GLOBALS['kit_users'][] = $u;
    $GLOBALS['kit_umeta'][ $id ] = array( '_uc_calendar_role' => $role, 'last_name' => $last );
    if ( '' !== $approval ) { $GLOBALS['kit_umeta'][ $id ]['_uc_calendar_approval'] = $approval; }
    $GLOBALS['kit_refused'][] = $id;
}
$GLOBALS['kit_users'] = array(); $GLOBALS['kit_umeta'] = array();
rq_user( 3, 'Ben Young', 'ben.young@sfaf.org', 'editor', 'Young' );
rq_user( 2, 'Zoe Adams', 'zoe.adams@sfaf.org', 'contributor', 'Adams' );
rq_user( 4, 'Ana Martinez', 'a.martinez.longaddress@sfaf.org', 'contributor', 'Martinez', 'auto' );
$GLOBALS['kit_options']['sfaf_teams'] = array( 'prog' => array( 'id' => 'prog', 'name' => 'Programa Latino', 'users' => array( 2, 3 ), 'created' => 1, 'updated' => 1 ) );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$admin  = new WP_User();
function rq_page( $method, $args = array(), $get = array() ) {
    return rq_capture( function () use ( $method, $args, $get ) { $_GET = $get; kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args ); } );
}
$html = array(
    'dashboard'  => rq_page( 'render_dashboard', array( $admin ) ),
    'events'     => rq_page( 'render_events', array( $admin ) ),
    'pending'    => rq_page( 'render_pending', array( $admin ) ),
    'serieslist' => rq_page( 'render_series_list', array( $admin ) ),
    'series'     => rq_page( 'render_series_edit', array( $admin, 11 ) ),
    'users'      => rq_page( 'render_users', array( $admin ) ),
    'images'     => rq_page( 'render_media', array( $admin ) ),
    'venues'     => rq_page( 'render_venues', array( $admin ) ),
    'organizers' => rq_page( 'render_organizers', array( $admin ) ),
    'faqsets'    => rq_page( 'render_faq_sets', array( $admin ) ),
    'optins'     => rq_page( 'render_optins', array( $admin ) ),
    'templates'  => rq_page( 'render_email_templates', array( $admin ) ),
    'prefs'      => rq_page( 'render_preferences', array( $admin ) ),
    'signin'     => rq_page( 'render_login' ),
    'forgot'     => rq_page( 'render_forgot', array(), array( 'sent' => '1' ) ),
    'reset'      => rq_page( 'render_reset', array(), array( 'key' => 'K', 'login' => 'ana' ) ),
    'rsvps'      => rq_page( 'render_rsvps', array( $admin ), array( 'event_id' => $walk ) ),
    'tour'       => rq_page( 'render_event_form', array( $admin, 0 ) ),
);
$pages = array();
foreach ( $html as $k => $h ) {
    foreach ( array( 'phone' => 390, 'wide' => 430, 'desktop' => 1280 ) as $wn => $w ) {
        if ( 'desktop' === $wn && ! in_array( $k, array( 'users', 'rsvps' ), true ) ) { continue; }
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
function shut(el) { var d = el.closest('details:not([open])'); return !!d && !(el.tagName === 'SUMMARY' && el.parentNode === d) && !(el.closest('summary') && el.closest('summary').parentNode === d); }
function seen(el) { if (!el || !el.getClientRects().length || shut(el)) { return false; } var cs = getComputedStyle(el); return cs.visibility !== 'hidden' && cs.display !== 'none' && +cs.opacity !== 0; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function name(el) { return el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : '') + (el.name ? '[' + el.name + ']' : '') + ' "' + (text(el) || el.getAttribute('aria-label') || el.value || '').slice(0, 28) + '"'; }
var phone = window.innerWidth < 600;
function small(root) {
  var bad = [];
  qa('a.uc-btn, button, select, textarea, summary, input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), label', root).forEach(function (el) {
    if (!seen(el)) { return; }
    if (el.tagName === 'LABEL' && !q('input[type="checkbox"], input[type="radio"]', el)) { return; }
    if (el.closest('.uc-tpl-editor, [hidden], #uc-sidebar, .uc-portal-sidebar')) { return; }
    var r = el.getBoundingClientRect(); if (!r.width || !r.height) { return; }
    var h = r.height, a = getComputedStyle(el, '::after');
    if (a.content !== 'none' && a.position === 'absolute') { h = r.height - parseFloat(a.top) - parseFloat(a.bottom); }
    if (Math.round(h) < 44) { bad.push(name(el) + ' ' + Math.round(h) + 'px'); }
  });
  return bad;
}
function past(root) {
  var w = document.documentElement.clientWidth, bad = [];
  qa('*', root).forEach(function (el) {
    if (!seen(el) || el.closest('[hidden], #uc-sidebar, .uc-portal-sidebar')) { return; }
    var r = el.getBoundingClientRect(); if (r.width && r.right > w + 1) { bad.push(name(el) + ' right ' + Math.round(r.right)); }
  });
  return bad.slice(0, 8);
}
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  var main = q('.uc-portal-main') || q('.uc-login-wrap') || document.body;
  /* The tour on Add event: started, then measured in its panel. */
  if (P.indexOf('tour-') === 0) {
    var start = q('.uc-tour-link, [data-uc-tour-start]');
    if (start) { start.click(); }
    setTimeout(function () {
      var panel = q('.uc-tour-panel, [data-uc-tour-panel], .uc-tour [role="dialog"], dialog.uc-tour');
      out.tour = panel ? { box: rect(panel), small: phone ? small(panel) : [], past: past(panel) } : null;
      finish();
    }, 400);
    return;
  }
  out.small = phone ? small(main) : [];
  out.past = past(main);
  /* B: the Users table. */
  var table = q('[data-uc-users-table]');
  if (table) {
    var rows = qa('[data-uc-user-row]', table);
    out.order = rows.map(function (r) { return text(q('.uc-user-id strong', r)); });
    out.saveOff = rows.map(function (r) { return q('[data-uc-user-save]', r).disabled; });
    out.roles = rows.map(function (r) { var s = q('[data-uc-user-role]', r); return { name: text(q('.uc-user-id strong', r)), role: s ? s.value : 'wp', approval: seen(q('[data-uc-user-approval]', r)), cats: seen(q('[data-uc-user-cats-toggle]', r)) }; });
    var young = rows.filter(function (r) { return text(q('.uc-user-id strong', r)) === 'Ben Young'; })[0];
    if (young) {
      var role = q('[data-uc-user-role]', young), save = q('[data-uc-user-save]', young);
      role.value = 'contributor'; role.dispatchEvent(new Event('change', { bubbles: true }));
      out.toContrib = { save: save.disabled, approval: seen(q('[data-uc-user-approval]', young)), cats: seen(q('[data-uc-user-cats-toggle]', young)) };
      role.value = 'editor'; role.dispatchEvent(new Event('change', { bubbles: true }));
      out.backToEditor = { save: save.disabled, approval: seen(q('[data-uc-user-approval]', young)), cats: seen(q('[data-uc-user-cats-toggle]', young)) };
    }
    if (phone) {
      out.wrap = rows.map(function (r) {
        var id = rect(q('.uc-user-id', r)), ctl = rect(q('.uc-user-role', r)) || rect(q('.uc-user-actions', r)), act = rect(q('.uc-user-actions', r));
        return { twoLines: ctl.top >= id.bottom - 1, inside: act.right <= document.documentElement.clientWidth };
      });
    }
    var add = q('.uc-team-add-toggle');
    if (add) { var ca = getComputedStyle(add); out.addMember = { text: text(add), border: ca.borderTopWidth + ' ' + ca.borderTopStyle, bg: ca.backgroundColor }; }
    var filter = q('[data-uc-user-filter]');
    if (filter) { filter.value = 'martinez'; filter.dispatchEvent(new Event('input', { bubbles: true })); out.filtered = rows.filter(function (r) { return !r.hidden; }).map(function (r) { return text(q('.uc-user-id strong', r)); }); filter.value = ''; filter.dispatchEvent(new Event('input', { bubbles: true })); }
  }
  /* A.2: Send. */
  var send = q('[data-uc-rm-send]');
  if (send) {
    var details = send.closest('details'); if (details) { details.open = true; }
    var icon = q('.uc-rm-send-icon', send);
    out.send = { bg: getComputedStyle(send).backgroundColor, iconSvg: !!q('svg', icon), restW: Math.round(icon.getBoundingClientRect().width),
      transition: getComputedStyle(icon).transitionDuration, reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches };
    /* Headless Chrome does not run a CSS transition to its end, so the length
       is read above and the end state with the transition held off. */
    icon.style.transition = 'none';
    send.focus();
    setTimeout(function () { out.send.focusW = Math.round(icon.getBoundingClientRect().width); out.send.focusVisible = send.matches(':focus-visible'); finish(); }, 400);
    return;
  }
  finish();
}, 700); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rq( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inner = __DIR__ . '/release-31102-live-' . $name . '.html';
    file_put_contents( $inner, preg_replace( '#</body>(?![\s\S]*</body>)#i', '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $p['html'], 1 ) );
    $files[ $name ] = __DIR__ . '/release-31102-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args  = array_slice( $argv, 1 );
$shots = in_array( '--shots', $args, true );
if ( ! in_array( '--run', $args, true ) && ! $shots ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
$drive = function ( $file, $w, $extra = '' ) use ( $chrome ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files ' . $extra . ' --window-size=' . max( 600, $w + 20 ) . ',1000 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    return preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ? json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true ) : null;
};
$g = array();
foreach ( $files as $name => $file ) {
    $w = $pages[ $name ]['w'];
    $g[ $name ] = $drive( $file, $w );
    if ( null === $g[ $name ] ) { rq( false, "$name: Chrome returned no probe block" ); continue; }
    rq( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rq( (int) $g[ $name ]['pageWidth'] <= $w, "PLANT F: $name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( isset( $g[ $name ]['past'] ) ) { rq( array() === $g[ $name ]['past'], "PLANT F: $name: runs past the screen: " . json_encode( $g[ $name ]['past'] ) ); }
    if ( isset( $g[ $name ]['small'] ) ) { rq( array() === $g[ $name ]['small'], "F: $name: tap targets under 44px: " . json_encode( $g[ $name ]['small'] ) ); }
    if ( 0 === strpos( $name, 'tour-' ) ) {
        $t = (array) $g[ $name ]['tour'];
        rq( $t && array() === $t['small'] && array() === $t['past'], "F: $name: the tour panel: " . json_encode( $t ) );
    }
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}
$reduced = $drive( $files['rsvps-desktop'], 1280, '--force-prefers-reduced-motion' );
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

/* ---- B ------------------------------------------------------------------- */
foreach ( array( 'desktop', 'wide', 'phone' ) as $wn ) {
    $t = "users-$wn";
    rq( array( 'Zoe Adams', 'Ana Martinez', 'Ben Young' ) === array_values( array_filter( (array) $v( $t, 'order' ), function ( $n ) { return 'Admin' !== $n; } ) ), "B.1: $t: not alphabetical by last name: " . json_encode( $v( $t, 'order' ) ) );
    rq( ! in_array( false, (array) $v( $t, 'saveOff' ), true ) && $v( $t, 'saveOff' ), "PLANT B.1: $t: a Save is on before anything changed: " . json_encode( $v( $t, 'saveOff' ) ) );
    foreach ( (array) $v( $t, 'roles' ) as $r ) {
        $c = ( 'contributor' === $r['role'] );
        rq( $c === $r['approval'] && $c === $r['cats'], "PLANT B.2: $t: {$r['name']} ({$r['role']}) shows Approval " . json_encode( $r['approval'] ) . ' and Contributor categories ' . json_encode( $r['cats'] ) );
    }
    $tc = (array) $v( $t, 'toContrib' ); $te = (array) $v( $t, 'backToEditor' );
    rq( $tc && false === $tc['save'] && true === $tc['approval'] && true === $tc['cats'], "B.2: $t: changing a role to Contributor does not show its controls and turn Save on: " . json_encode( $tc ) );
    rq( $te && true === $te['save'] && false === $te['approval'] && false === $te['cats'], "PLANT B.1: $t: putting the role back does not hide them and turn Save off: " . json_encode( $te ) );
    rq( array( 'Ana Martinez' ) === $v( $t, 'filtered' ), "B.1: $t: the search box does not narrow to Ana Martinez: " . json_encode( $v( $t, 'filtered' ) ) );
    $am = (array) $v( $t, 'addMember' );
    rq( $am && '+ Add member' === $am['text'] && 0 === strpos( $am['border'], '1px solid' ), "B.3: $t: + Add member is not the outlined control: " . json_encode( $am ) );
}
foreach ( array( 'phone', 'wide' ) as $wn ) {
    foreach ( (array) $v( "users-$wn", 'wrap' ) as $i => $w ) {
        rq( true === $w['twoLines'] && true === $w['inside'], "B.4: users-$wn: row " . ( $i + 1 ) . ' is not two lines with everything inside: ' . json_encode( $w ) );
    }
}

/* ---- A.2 ----------------------------------------------------------------- */
$sd = (array) $v( 'rsvps-desktop', 'send' );
rq( $sd && 'rgb(21, 128, 61)' === $sd['bg'] && true === $sd['iconSvg'] && 0 === $sd['restW'] && (bool) preg_match( '/^0\.15s(, 0\.15s)*$/', (string) $sd['transition'] ) && 16 === $sd['focusW'],
    'A.2: Send is not Publish green with its envelope sliding in over 150ms on focus: ' . json_encode( $sd ) );
$sr = $reduced && isset( $reduced['send'] ) ? $reduced['send'] : array();
rq( $sr && true === $sr['reduce'] && '0s' === $sr['transition'] && 16 === $sr['focusW'], 'A.2: with motion reduced the envelope still moves, or does not appear: ' . json_encode( $sr ) );

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( $pages as $name => $p ) {
        if ( false === strpos( $name, '-phone' ) && ! in_array( $name, array( 'users-desktop' ), true ) ) { continue; }
        $png   = __DIR__ . "/screens/caladmin-31102-$name.png";
        $frame = __DIR__ . "/release-31102-shot-$name-frame.html";
        file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="release-31102-live-' . $name . '.html" style="display:block;width:' . $p['w'] . 'px;height:1400px;border:0"></iframe></body></html>' );
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $p['w'] ) . ',1400 --virtual-time-budget=8000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
        rq( file_exists( $png ), "no screenshot written for $name" );
    }
}

if ( $fails ) {
    echo 'RELEASE 3.110.2 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.110.2 live: every remaining caladmin screen at 390px and 430px with nothing off the edge and no target under 44px, the tour on Add event included; the Users table alphabetical, its Save off until a row changes, Approval and categories on contributors only, two lines on a phone, + Add member outlined; Send green with its envelope sliding in, and still when motion is reduced.\n";
