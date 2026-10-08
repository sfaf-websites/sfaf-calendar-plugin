<?php
/**
 * 3.110.1 IN A REAL BROWSER: THE RSVP LIST'S ORDER, TEAM AND ACCESS ON BOTH
 * EDITORS, REGISTER ON ANOTHER SITE, AND ALL EVENTS FOR AN EDITOR.
 *
 *     php .claude/release-31101-live.php            writes the pages
 *     php .claude/release-31101-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-31101-live.php --shots    --run, and writes .claude/screens/caladmin-31101-*.png
 *
 *   A.1  one search box, "Search name or email", and it narrows the rows as
 *        you type, by name or by email; its Search button is hidden
 *        PLANT A.1: a second search box on the page
 *   A.2  Email registrants is the Registration settings panel's component,
 *        under it: same classes, same circle chevron, bold name, muted line,
 *        same fill
 *   A.3  check-in sits inside the registrations card, under its heading, at
 *        the card's left edge, above the table
 *   A.4  top to bottom: heading and buttons, the "for this event only" line,
 *        Registration settings, Email registrants, the search box, the counts
 *        strip, the registrations card; at 1280px, 430px and 390px, with
 *        nothing past the right edge
 *   B    Team and access on Add event and Edit event; All events for an
 *        editor puts other people's events in a public table whose title
 *        opens the public page and that has no count and no way in
 *   C    "Register on another site" hides Accept RSVPs and the rest and shows
 *        the link box; the RSVP list says registrations are taken there; the
 *        event page's Register opens the link in a new tab
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
function rz( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rz_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

$GLOBALS['kit_query'] = function ( $a ) {
    $o = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( ( isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event' ) !== $p->post_type ) { continue; }
        if ( ! empty( $a['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', $a['post__in'] ), true ) ) { continue; }
        if ( ! empty( $a['author'] ) && (int) $p->post_author !== (int) $a['author'] ) { continue; }
        $o[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $o;
};
function rz_event( $title, $author, $meta ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => $author, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+6 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
$walk  = rz_event( 'Harm reduction walk', 1, array() );
$away  = rz_event( 'Gala dinner', 1, array( SFAF_Register_Elsewhere::META_MODE => 'elsewhere', SFAF_Register_Elsewhere::META_URL => 'https://www.eventbrite.com/e/gala-dinner' ) );
$mine  = rz_event( 'Coffee social', 3, array() );

function rz_row( $id, $event, $first, $last, $email, $status, $extra = array() ) {
    return array_merge( array( 'id' => $id, 'event_id' => $event, 'name' => "$first $last", 'first_name' => $first, 'last_name' => $last, 'email' => $email,
        'phone' => '', 'status' => $status, 'token' => "t$id", 'format' => '', 'created_at' => '2026-09-2' . $id . ' 10:00:00', 'cancelled_at' => null,
        'removed_by' => 0, 'agreed_at' => null, 'text_opt_in' => 0, 'checked_in_at' => null, 'answers' => '', 'optin' => 0 ), $extra );
}
$GLOBALS['rz_rows'] = array(
    rz_row( 1, $walk, 'Ana', 'Alvarez', 'ana@example.org', 'confirmed' ),
    rz_row( 2, $walk, 'Ben', 'Brooks', 'ben@example.org', 'confirmed' ),
    rz_row( 3, $walk, 'Dee', 'Diaz', 'dee.d@example.net', 'confirmed' ),
    rz_row( 4, $walk, 'Eve', 'Evans', 'eve@example.org', 'waitlisted' ),
);
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
    $rows = $GLOBALS['rz_rows'];
    if ( preg_match( '/event_id\s*=\s*(\d+)/', $sql, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r['event_id'] === (int) $m[1]; } ); }
    if ( preg_match( "/status\s*=\s*'([a-z_]+)'/", $sql, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['status'] === $m[1]; } ); }
    elseif ( preg_match( "/status\s+IN\s*\(([^)]*)\)/i", $sql, $m ) ) { preg_match_all( "/'([a-z_]+)'/", $m[1], $in ); $rows = array_filter( $rows, function ( $r ) use ( $in ) { return in_array( $r['status'], $in[1], true ); } ); }
    if ( 'get_var' === $method ) { return (string) count( $rows ); }
    if ( 'get_results' === $method ) { return array_map( function ( $r ) { $r['post_title'] = get_the_title( $r['event_id'] ); $r['event_title'] = ''; return (object) $r; }, array_values( $rows ) ); }
    if ( 'get_row' === $method ) { $rows = array_values( $rows ); return $rows ? (object) $rows[0] : null; }
    return null;
};
update_post_meta( $walk, SFAF_Registrant_Mail::LOG_META, array( array( 'by' => 1, 'at' => '2026-10-01 09:30:00', 'subject' => 'Meeting point', 'count' => 3 ) ) );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$admin  = new WP_User();
function rz_page( $method, $args = array(), $get = array() ) {
    return rz_capture( function () use ( $method, $args, $get ) { $_GET = $get; kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args ); } );
}
$html = array(
    'rsvps'   => rz_page( 'render_rsvps', array( $admin ), array( 'event_id' => $walk ) ),
    'away'    => rz_page( 'render_rsvps', array( $admin ), array( 'event_id' => $away ) ),
    'add'     => rz_page( 'render_event_form', array( $admin, 0 ) ),
    'edit'    => rz_page( 'render_event_form', array( $admin, $walk ) ),
);
// The event page's card for the event registering elsewhere.
$html['public'] = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="../public/css/calendar.css"></head><body style="margin:0;background:#fff">'
    . '<div class="uc-single"><div class="uc-single-inner"><div class="uc-single-grid"><div class="uc-single-main"><h1>Gala dinner</h1></div><aside class="uc-single-sidebar"><div class="uc-single-card">'
    . sfaf_rsvp_block( $away ) . '</div></aside></div></div></div></body></html>';
// All events, as an editor (user 3) who created Coffee social and nothing else.
$GLOBALS['kit_umeta']   = array( 3 => array( '_uc_calendar_role' => 'editor' ) );
$GLOBALS['kit_refused'] = array( 3 );
$GLOBALS['kit_user_id'] = 3;
$ed = new WP_User(); $ed->ID = 3; $ed->display_name = 'Eli';
$html['allevents'] = rz_page( 'render_events', array( $ed ), array( 'scope' => 'all' ) );

$pages = array();
foreach ( $html as $k => $h ) {
    foreach ( array( 'desktop' => 1280, 'wide' => 430, 'phone' => 390 ) as $wn => $w ) {
        if ( in_array( $k, array( 'away', 'public', 'allevents', 'edit' ), true ) && 'desktop' !== $wn ) { continue; }
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
function seen(el) { if (!el || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.visibility !== 'hidden' && cs.display !== 'none'; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { top: Math.round(r.top + window.scrollY), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom + window.scrollY), w: Math.round(r.width), h: Math.round(r.height) }; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
window.addEventListener('load', function () { setTimeout(function () {
  /* A: the RSVP list. */
  var settings = q('details.uc-rsvp-settings:not(.uc-registrant-mail)');
  var mail = q('details.uc-registrant-mail');
  if (settings || q('[data-uc-reg-elsewhere]')) {
    out.searchBoxes = qa('input[type="search"]').filter(seen).length;
    out.searchLabel = (q('input[type="search"]') || {}).placeholder || '';
    var bar = q('form.uc-filters-bar');
    out.searchButtonShown = bar ? seen(q('button[type="submit"]', bar)) : null;
    var card = qa('.uc-card').filter(function (c) { return q('[data-uc-rsvp-row]', c); })[0];
    var order = [
      ['head', q('.uc-page-head')], ['line', qa('p.uc-hint').filter(function (p) { return text(p).indexOf('Registrations for this event only') === 0; })[0]],
      ['settings', settings], ['mail', mail], ['search', bar], ['counts', q('[data-uc-rsvp-counts]')], ['card', card]
    ];
    out.order = order.map(function (o) { var r = rect(o[1]); return [o[0], r ? r.top : null]; });
    out.elsewhere = q('[data-uc-reg-elsewhere]') ? { text: text(q('[data-uc-reg-elsewhere]')), href: q('[data-uc-reg-elsewhere] a').getAttribute('href'), target: q('[data-uc-reg-elsewhere] a').getAttribute('target') } : null;
    if (settings && mail) {
      var s1 = q('summary', settings), s2 = q('summary', mail);
      var c1 = getComputedStyle(settings), c2 = getComputedStyle(mail);
      out.panel = {
        sameClasses: settings.className.split(/\s+/).every(function (c) { return mail.classList.contains(c); }),
        chevron: !!q('.uc-disclosure-chevron', s1) && !!q('.uc-disclosure-chevron', s2),
        chevronSame: getComputedStyle(q('.uc-disclosure-chevron', s1)).borderRadius === getComputedStyle(q('.uc-disclosure-chevron', s2)).borderRadius,
        bg: [c1.backgroundColor, c2.backgroundColor], name: text(q('strong', s2)), line: text(q('.uc-muted', s2)), closed: !mail.open,
        gap: rect(mail).top - rect(settings).bottom
      };
    }
    if (card) {
      var ck = q('[data-uc-checkin-bar]', card), head = q('.uc-card-head', card), table = q('table', card);
      var cr = card.getBoundingClientRect(), pad = parseFloat(getComputedStyle(card).paddingLeft);
      out.checkin = ck ? { inCard: true, belowHead: rect(ck).top >= rect(head).bottom, aboveTable: rect(ck).bottom <= rect(table).top,
        leftEdge: Math.round(ck.getBoundingClientRect().left - cr.left - pad) } : null;
      out.checkinOutside = qa('[data-uc-checkin-bar]').filter(function (b) { return !card.contains(b); }).length;
    }
    var input = q('input[data-uc-rsvp-filter]');
    if (input) {
      function visible() { return qa('[data-uc-rsvp-row]').filter(function (r) { return !r.hidden; }).map(function (r) { return r.getAttribute('data-uc-rsvp-name'); }); }
      input.value = 'ben'; input.dispatchEvent(new Event('input', { bubbles: true })); out.byName = visible();
      input.value = 'example.net'; input.dispatchEvent(new Event('input', { bubbles: true })); out.byEmail = visible();
      input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); out.cleared = visible().length;
    }
  }
  /* B and C on the editors. */
  var access = q('[data-uc-card="access"]');
  if (access) {
    out.access = { title: text(q('h2', access)).replace(/\s*\?.*$/, ''), teams: !!q('[data-uc-access-teams], .uc-muted', access), people: !!q('[data-uc-access-people], [data-uc-access-readonly], .uc-access-group', access) };
    var reg = q('[data-uc-registration]');
    var away = q('[data-uc-reg-mode][value="elsewhere"]', reg);
    if (away) {
      out.regBefore = { here: seen(q('[data-uc-reg-here]', reg)), url: seen(q('[data-uc-reg-url]', reg)), rsvpTick: seen(q('input[name="rsvp_enabled"]', reg)) };
      away.checked = true; away.dispatchEvent(new Event('change', { bubbles: true }));
      out.regAway = { here: seen(q('[data-uc-reg-here]', reg)), url: seen(q('[data-uc-reg-url]', reg)), rsvpTick: seen(q('input[name="rsvp_enabled"]', reg)), capacity: seen(q('input[name="capacity"]', reg)) };
      var back = q('[data-uc-reg-mode][value="here"]', reg); back.checked = true; back.dispatchEvent(new Event('change', { bubbles: true }));
      out.regBack = { here: seen(q('[data-uc-reg-here]', reg)), url: seen(q('[data-uc-reg-url]', reg)) };
    }
  }
  /* C on the event page. */
  var btn = q('.uc-card-rsvp-elsewhere a');
  if (btn) { out.publicBtn = { href: btn.getAttribute('href'), target: btn.getAttribute('target'), rel: btn.getAttribute('rel'), note: text(btn), form: !!q('.uc-rsvp-btn') }; }
  /* B: All events for an editor. */
  var pub = q('table.uc-table-public');
  if (pub) {
    out.allEvents = { heading: text(q('.uc-public-heading')), publicRows: qa('tbody tr', pub).map(function (r) { var c = q('td', r).cloneNode(true); qa('.uc-visually-hidden', c).forEach(function (n) { n.remove(); }); return text(c); }),
      links: qa('a[data-uc-public-link]', pub).map(function (a) { return [a.getAttribute('href'), a.getAttribute('target'), a.getAttribute('rel')]; }),
      wayIn: qa('a', pub).filter(function (a) { return /caladmin\/(rsvps|events\/edit)/.test(a.getAttribute('href') || ''); }).length,
      counts: pub.querySelectorAll('th').length, mineRows: qa('table.uc-table:not(.uc-table-public) tbody tr').map(function (r) { return text(r).slice(0, 30); }) };
  }
  var past = [], w = document.documentElement.clientWidth;
  qa('.uc-portal-main *').forEach(function (el) {
    if (!seen(el) || el.closest('[hidden], details:not([open]) > :not(summary)')) { return; }
    var r = el.getBoundingClientRect(); if (r.width && r.right > w + 1) { past.push(el.tagName + '.' + el.className + ' ' + Math.round(r.right)); }
  });
  out.past = past.slice(0, 6);
  finish();
}, 600); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rz( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inner = __DIR__ . '/release-31101-live-' . $name . '.html';
    $doc = $p['html'];
    if ( 0 === strpos( $name, 'public' ) ) { $doc = str_replace( '</body>', '<script src="vendor/jquery-3.7.1.min.js"></script><script>window.ucData={ajaxUrl:"",nonce:"n"};</script><script src="../public/js/calendar.js"></script></body>', $doc ); }
    file_put_contents( $inner, preg_replace( '#</body>(?![\s\S]*</body>)#i', '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $doc, 1 ) );
    $files[ $name ] = __DIR__ . '/release-31101-live-' . $name . '-frame.html';
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
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rz( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rz( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rz( (int) $g[ $name ]['pageWidth'] <= $w, "A.4: $name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    rz( array() === (array) $g[ $name ]['past'], "A.4: $name: runs past the screen: " . json_encode( $g[ $name ]['past'] ) );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

/* ---- A ------------------------------------------------------------------- */
foreach ( array( 'desktop', 'wide', 'phone' ) as $wn ) {
    $t = "rsvps-$wn";
    rz( 1 === $v( $t, 'searchBoxes' ), "PLANT A.1: $t: " . json_encode( $v( $t, 'searchBoxes' ) ) . ' search boxes, not one' );
    rz( 'Search name or email…' === $v( $t, 'searchLabel' ) && false === $v( $t, 'searchButtonShown' ), "A.1: $t: the one box is not Search name or email with its button hidden: " . json_encode( array( $v( $t, 'searchLabel' ), $v( $t, 'searchButtonShown' ) ) ) );
    rz( array( 'ben brooks' ) === $v( $t, 'byName' ) && array( 'dee diaz' ) === $v( $t, 'byEmail' ) && 3 === $v( $t, 'cleared' ),
        "A.1: $t: typing does not narrow by name and by email: " . json_encode( array( $v( $t, 'byName' ), $v( $t, 'byEmail' ), $v( $t, 'cleared' ) ) ) );
    $pn = (array) $v( $t, 'panel' );
    rz( $pn && true === $pn['sameClasses'] && true === $pn['chevron'] && true === $pn['chevronSame'] && $pn['bg'][0] === $pn['bg'][1]
        && 'Email registrants' === $pn['name'] && 'Send a message to everyone registered.' === $pn['line'] && true === $pn['closed'],
        "A.2: $t: Email registrants is not the Registration settings panel's component: " . json_encode( $pn ) );
    $order = (array) $v( $t, 'order' );
    $tops  = array_column( $order, 1 );
    $names = array_column( $order, 0 );
    $sorted = $tops; sort( $sorted );
    rz( ! in_array( null, $tops, true ) && $tops === $sorted && count( array_unique( $tops ) ) === count( $tops ), "A.4: $t: not in the order " . implode( ', ', $names ) . ': ' . json_encode( $order ) );
    $ck = (array) $v( $t, 'checkin' );
    rz( $ck && true === $ck['belowHead'] && true === $ck['aboveTable'] && abs( $ck['leftEdge'] ) <= 1 && 0 === $v( $t, 'checkinOutside' ),
        "A.3: $t: check-in is not in the registrations card under its heading, left aligned, above the table: " . json_encode( array( $ck, $v( $t, 'checkinOutside' ) ) ) );
}

/* ---- B ------------------------------------------------------------------- */
foreach ( array( 'add-desktop', 'edit-desktop', 'add-phone' ) as $t ) {
    $ac = (array) $v( $t, 'access' );
    rz( $ac && 'Team and access' === $ac['title'], "B: $t: there is no Team and access card: " . json_encode( $ac ) );
}
$ae = (array) $v( 'allevents-desktop', 'allEvents' );
rz( $ae && 'Other events' === $ae['heading'] && in_array( 'Harm reduction walk', $ae['publicRows'], true ) && 0 === $ae['wayIn'],
    'PLANT B.5: All events for an editor gives other people\'s events a way in: ' . json_encode( $ae ) );
rz( $ae && in_array( array( get_permalink( $walk ), '_blank', 'noopener' ), $ae['links'], true ), 'B.3: other people\'s published events do not open their public page: ' . json_encode( $ae ) );
rz( $ae && in_array( 'Coffee social', array_map( function ( $s ) { return trim( substr( $s, 0, 13 ) ); }, $ae['mineRows'] ), true ) && ! in_array( 'Coffee social', $ae['publicRows'], true ),
    'B.3: the editor\'s own event is not in their own table: ' . json_encode( $ae ) );

/* ---- C ------------------------------------------------------------------- */
foreach ( array( 'add-desktop', 'edit-desktop', 'add-phone' ) as $t ) {
    $b = (array) $v( $t, 'regBefore' ); $a = (array) $v( $t, 'regAway' ); $k = (array) $v( $t, 'regBack' );
    rz( $b && true === $b['here'] && false === $b['url'] && true === $b['rsvpTick'], "C.1: $t: Take registrations here is not the opening state: " . json_encode( $b ) );
    rz( $a && false === $a['here'] && true === $a['url'] && false === $a['rsvpTick'] && false === $a['capacity'], "C.2: $t: Register on another site does not hide the settings and show the link box: " . json_encode( $a ) );
    rz( $k && true === $k['here'] && false === $k['url'], "C.3: $t: switching back does not bring the settings back: " . json_encode( $k ) );
}
$el = (array) $v( 'away-desktop', 'elsewhere' );
rz( $el && 0 === strpos( $el['text'], 'Registrations are taken on another site' ) && 'https://www.eventbrite.com/e/gala-dinner' === $el['href'] && '_blank' === $el['target'],
    'C.2: the RSVP list does not say registrations are taken on another site, with the link: ' . json_encode( $el ) );
$pb = (array) $v( 'public-desktop', 'publicBtn' );
rz( $pb && 'https://www.eventbrite.com/e/gala-dinner' === $pb['href'] && '_blank' === $pb['target'] && false !== strpos( (string) $pb['rel'], 'noopener' )
    && false !== strpos( $pb['note'], '(opens in a new tab)' ) && false === $pb['form'], 'C.2: the event page\'s Register is not the new-tab link: ' . json_encode( $pb ) );

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( array( 'rsvps' => array( 'desktop' => 1280, 'wide' => 430, 'phone' => 390 ), 'allevents' => array( 'desktop' => 1280 ), 'away' => array( 'desktop' => 1280 ), 'edit' => array( 'desktop' => 1280 ) ) as $k => $widths ) {
        foreach ( $widths as $wn => $w ) {
            $png   = __DIR__ . "/screens/caladmin-31101-$k-$wn.png";
            $frame = __DIR__ . "/release-31101-shot-$k-$wn-frame.html";
            file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="release-31101-live-' . $k . '-' . $wn . '.html" style="display:block;width:' . $w . 'px;height:1400px;border:0"></iframe></body></html>' );
            shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $w ) . ',1400 --virtual-time-budget=8000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
            rz( file_exists( $png ), "no screenshot written for $k $wn" );
        }
    }
}

if ( $fails ) {
    echo 'RELEASE 3.110.1 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.110.1 live: the RSVP list in the brief's order at 1280px, 430px and 390px, one search box narrowing by name or email, Email registrants as the settings panel's component, check-in inside the registrations card; Team and access on both editors; All events for an editor with other people's events public only; Register on another site hiding the settings, saying so on the list, and opening the link in a new tab.\n";
