<?php
/**
 * 3.133.0 IN A BROWSER: THE RSVP LIST AT EVERY WIDTH.
 *
 *     php .claude/release-31330-live.php --run
 *     php .claude/release-31330-live.php --run --verbose   and print what was measured
 *     php .claude/release-31330-live.php --shots           --run, and write .claude/screens/caladmin-31330-*.png
 *
 * REAL: one event's RSVP list (render_rsvps() with portal.css and portal.js,
 * the sidebar and all), rendered through wp-kit.php, in Chrome at 390, 768,
 * 1024 and 1280px. Thirty-two registrations, among them long names, a long
 * unbroken email address, a cancelled one removed by staff, one checked in,
 * one with no email, and a waitlist; once with Check in by name and once
 * with Enter a count.
 *
 *   A  no registrations row is wider than its card, and nothing in the page
 *      runs past its card or the screen; the page never scrolls sideways
 *   B  every Check in and Remove is inside its card and on screen
 *   C  1280px: the table, every column heading on screen and no column under
 *      72px, one Name column,
 *      the short date "Nov 2, 11:48 am"
 *   D  1024px and under: each person a card, no heading row; Check in on the
 *      name's line and at least 44px tall; Remove at least 44px tall, below
 *      Check in and at the other side of the card
 *   E  Enter a count: no Check in, People attended and Save inside the card
 *   and A to D for the list of every registration, which shares the table
 *
 * PLANT in plant-31330.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
function rw( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rw_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
$GLOBALS['kit_query'] = function ( $a ) { $o = array(); foreach ( $GLOBALS['kit_posts'] as $id => $p ) { if ( ( isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event' ) === $p->post_type ) { $o[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; } } return $o; };
function rw_event( $title, $meta ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '40' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
$byname  = rw_event( 'Community dinner and conversation about harm reduction', array( SFAF_Agreement::META_ON => '1', SFAF_Agreement::META_TEXT => '<p>Photos are taken.</p>' ) );
$counted = rw_event( 'Coffee social', array( SFAF_Checkin::MODE_META => 'count', SFAF_Checkin::ATTEND_META => '14' ) );

function rw_row( $id, $event, $first, $last, $email, $phone, $status, $extra = array() ) {
    return array_merge( array( 'id' => $id, 'event_id' => $event, 'name' => trim( "$first $last" ), 'first_name' => $first, 'last_name' => $last, 'email' => $email,
        'phone' => $phone, 'status' => $status, 'token' => "t$id", 'format' => '', 'created_at' => '2026-11-02 11:48:00', 'cancelled_at' => null,
        'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null, 'agreed_at' => '2026-11-02 11:48:00',
        'text_opt_in' => 0, 'text_channel' => '', 'checked_in_at' => null, 'answers' => '', 'optin' => 0, 'lang' => 'en' ), $extra );
}
$rows = array(
    rw_row( 1, $byname, 'Maximiliano Alejandro', 'Fernández-Castellanos de la Torre', 'maximiliano.alejandro.fernandez.castellanos.delatorre@community-health-partners.example.org', '+52 55 1234 5678', 'confirmed', array( 'text_channel' => 'whatsapp' ) ),
    rw_row( 2, $byname, 'Ana', 'Alvarez-Montenegro', 'ana@example.org', '(415) 555-0101', 'confirmed', array( 'checked_in_at' => '2026-11-20 17:55:00', 'text_channel' => 'sms' ) ),
    rw_row( 3, $byname, 'Dee', '', '', '(415) 555-0100', 'confirmed' ),
    rw_row( 4, $byname, 'Bartholomew', 'Wolfeschlegelsteinhausenbergerdorff', 'bart@example.org', '', 'cancelled', array( 'removed_by' => 1, 'cancelled_at' => '2026-11-03 09:10:00' ) ),
    rw_row( 5, $byname, 'Eve', 'Evans', 'eve@example.org', '', 'waitlisted' ),
    rw_row( 6, $byname, 'Old', 'Year', 'old.year@example.org', '', 'confirmed', array( 'created_at' => '2025-12-30 21:05:00' ) ),
);
for ( $i = 7; $i <= 34; $i++ ) {
    $rows[] = rw_row( $i, $byname, 'Person' . $i, 'Registrant-Longername' . $i, 'person' . $i . '.registrant@example-organization.org', ( $i % 3 ) ? '(415) 555-01' . $i : '', 'confirmed' );
}
$rows[] = rw_row( 40, $counted, 'Gil', 'Gray', 'gil@example.org', '', 'confirmed' );
$rows[] = rw_row( 41, $counted, 'Ximena', 'Quintanilla-Hernández', 'ximena.quintanilla.hernandez.registrant@example-organization.org', '', 'confirmed' );
$GLOBALS['rw_rows'] = $rows;
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
    $rows = $GLOBALS['rw_rows'];
    if ( preg_match( '/event_id\s*=\s*(\d+)/', $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r['event_id'] === (int) $m[1]; } );
    }
    if ( preg_match( "/status\s*=\s*'([a-z_]+)'/", $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['status'] === $m[1]; } );
    } elseif ( preg_match( "/status\s+IN\s*\(([^)]*)\)/i", $sql, $m ) ) {
        preg_match_all( "/'([a-z_]+)'/", $m[1], $in );
        $rows = array_filter( $rows, function ( $r ) use ( $in ) { return in_array( $r['status'], $in[1], true ); } );
    }
    if ( preg_match( '/checked_in_at\s+IS\s+NOT\s+NULL/i', $sql ) ) {
        $rows = array_filter( $rows, function ( $r ) { return null !== $r['checked_in_at']; } );
    }
    if ( preg_match( '/text_channel IN/', $sql ) ) {
        $rows = array_filter( $rows, function ( $r ) { return in_array( $r['text_channel'], array( 'sms', 'whatsapp' ), true ); } );
    }
    if ( 'get_var' === $method ) { return (string) count( $rows ); }
    if ( 'get_results' === $method ) {
        return array_map( function ( $r ) { $r['post_title'] = get_the_title( $r['event_id'] ); $r['event_title'] = ''; return (object) $r; }, array_values( $rows ) );
    }
    if ( 'get_row' === $method ) { $rows = array_values( $rows ); return $rows ? (object) $rows[0] : null; }
    return null;
};
SFAF_Texts::save( array( 'twilio_account_sid' => 'AC' . str_repeat( 'a', 32 ), 'twilio_messaging_sid' => 'MG' . str_repeat( 'b', 32 ) ), 'token0123456789' );

$GLOBALS['portal'] = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user = new WP_User();
function rw_page( $event_id ) {
    return rw_cap( function () use ( $event_id ) { $_GET = $event_id ? array( 'event_id' => $event_id ) : array(); kit_call( 'SFAF_Portal', 'render_rsvps', $GLOBALS['portal'], array( new WP_User() ) ); } );
}
// 'all' is every registration on the site, which shares the table and its Event column.
$html = array( 'byname' => rw_page( $byname ), 'count' => rw_page( $counted ), 'all' => rw_page( 0 ) );
foreach ( $html as $k => $h ) { rw( 0 !== strpos( $h, 'THREW' ), "$k could not be rendered: $h" ); }

$probe = <<<'JS'
(function () {
var P = window.RW_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function name(el) { return el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : '') + ' "' + text(el).slice(0, 30) + '"'; }
/* Inside: el's box lies within box (a card, or the screen), to the pixel. */
function inside(r, box) { return r.left >= box.left - 1 && r.right <= box.right + 1; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }

window.addEventListener('load', function () { setTimeout(function () {
  var W = document.documentElement.clientWidth, screen = { left: 0, right: W };
  out.width = W;
  /* A: anything in a card past its card's edge (the card clips it), or past the screen. */
  var past = [];
  qa('.uc-portal-content .uc-card').forEach(function (card) {
    if (!seen(card)) { return; }
    var box = rect(card);
    qa('*', card).forEach(function (el) {
      if (!seen(el) || el.closest('details:not([open]) > :not(summary)')) { return; }
      var r = el.getBoundingClientRect();
      if (r.width <= 1 || r.height <= 1) { return; }
      if (!inside(r, box) || !inside(r, screen)) { past.push(name(el) + ' ' + Math.round(r.left) + '-' + Math.round(r.right) + ' in ' + box.left + '-' + box.right); }
    });
  });
  out.past = past.slice(0, 10);
  out.pastCount = past.length;
  var rows = qa('[data-uc-rsvp-row]');
  var list = rows.length ? rows[0].closest('.uc-card') : null;
  var lbox = rect(list);
  out.rows = rows.length;
  out.thead = !!q('.uc-rsvp-table thead') && seen(q('.uc-rsvp-table thead'));
  out.heads = qa('.uc-rsvp-table thead th').map(function (th) { return text(th); });
  out.headsOn = qa('.uc-rsvp-table thead th').every(function (th) { return !seen(th) || (inside(th.getBoundingClientRect(), lbox) && inside(th.getBoundingClientRect(), screen)); });
  out.wideRows = rows.filter(function (r) { return seen(r) && !inside(r.getBoundingClientRect(), lbox); }).map(function (r) { return r.getAttribute('data-uc-rsvp-name') + ' ' + rect(r).right + ' > ' + lbox.right; });
  out.contentMax = getComputedStyle(q('.uc-portal-content')).maxWidth;
  /* In the table, a column squeezed to a few letters fits and is still unreadable. */
  out.cramped = W > 1024 ? qa('[data-uc-rsvp-row] > td').filter(function (td) { return seen(td) && td.getBoundingClientRect().width < 72; }).map(function (td) { return td.className + ' ' + Math.round(td.getBoundingClientRect().width) + 'px'; }).slice(0, 6) : [];
  out.listWidth = lbox ? lbox.w : 0;
  out.btn = [];
  rows.forEach(function (r) {
    var ci = q('[data-uc-checkin]', r), rm = q('[data-uc-rsvp-remove]', r), nm = q('.uc-rc-name', r);
    [['checkin', ci], ['remove', rm]].forEach(function (p) {
      if (!p[1]) { return; }
      var b = p[1].getBoundingClientRect();
      if (!seen(p[1]) || !inside(b, lbox) || !inside(b, screen) || b.width < 1) { out.btn.push(p[0] + ' off: ' + r.getAttribute('data-uc-rsvp-name') + ' ' + Math.round(b.left) + '-' + Math.round(b.right) + ' in ' + lbox.left + '-' + lbox.right); }
    });
    if (W <= 1024) {
      if (ci) {
        var c = ci.getBoundingClientRect(), n = nm.getBoundingClientRect();
        if (Math.round(c.height) < 44) { out.btn.push('checkin under 44px: ' + Math.round(c.height)); }
        if (!(c.top < n.bottom && n.top < c.bottom && n.right <= c.left + 1)) { out.btn.push('checkin not beside the name: ' + r.getAttribute('data-uc-rsvp-name')); }
        if (rm) {
          var m = rm.getBoundingClientRect();
          if (!(m.top >= c.bottom)) { out.btn.push('remove not below check in: ' + r.getAttribute('data-uc-rsvp-name')); }
        }
      }
      if (rm) {
        var m2 = rm.getBoundingClientRect(), rr = r.getBoundingClientRect();
        if (Math.round(m2.height) < 44) { out.btn.push('remove under 44px: ' + Math.round(m2.height)); }
        if (Math.abs(m2.right - rr.right) > 2) { out.btn.push('remove not at the far side: ' + r.getAttribute('data-uc-rsvp-name')); }
      }
    }
  });
  out.btn = out.btn.slice(0, 10);
  var first = rows[0];
  out.firstName = first ? text(q('.uc-rc-name', first)) : '';
  var six = rows.filter(function (r) { return r.getAttribute('data-uc-rsvp-name') === 'old year'; })[0];
  out.dates = { now: rows[1] ? text(q('.uc-rc-registered time', rows[1])) : '', old: six ? text(q('.uc-rc-registered time', six)) : '' };
  out.checkins = qa('[data-uc-checkin]').length;
  out.removes = qa('[data-uc-rsvp-remove]').length;
  var att = q('[data-uc-attendance-form]');
  out.attend = att ? { on: inside(att.getBoundingClientRect(), lbox) && inside(q('button', att).getBoundingClientRect(), lbox), value: q('input[name="attendance"]', att).value } : null;
  finish();
}, 600); });
})();
JS;

$widths = array( 390, 768, 1024, 1280 );
$files  = array();
foreach ( $html as $k => $h ) {
    foreach ( $widths as $w ) {
        $name  = "$k-$w";
        $inner = __DIR__ . '/release-31330-live-' . $name . '.html';
        file_put_contents( $inner, preg_replace( '#</body>(?![\s\S]*</body>)#i', '<script>window.RW_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $h, 1 ) );
        $files[ $name ] = array( $w, __DIR__ . '/release-31330-live-' . $name . '-frame.html' );
        file_put_contents( $files[ $name ][1], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
            . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . $w . 'px;height:1000px;border:0"></iframe>'
            . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
    }
}
$args  = array_slice( $argv, 1 );
$shots = in_array( '--shots', $args, true );
if ( ! in_array( '--run', $args, true ) && ! $shots ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $f ) {
    list( $w, $file ) = $f;
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . ( $w + 20 ) . ',1100 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rw( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}

foreach ( $g as $name => $r ) {
    list( $k, $w ) = explode( '-', $name );
    $w = (int) $w;
    rw( empty( $r['errors'] ), "$name threw: " . implode( '; ', (array) $r['errors'] ) );
    rw( (int) $r['pageWidth'] <= $w, "PLANT A: $name: the page scrolls sideways, {$r['pageWidth']}px in $w" );
    rw( array() === $r['wideRows'], "PLANT A: $name: registrations rows wider than their card: " . json_encode( $r['wideRows'] ) );
    rw( 0 === $r['pastCount'], "PLANT A: $name: {$r['pastCount']} thing(s) past their card or the screen: " . json_encode( $r['past'], JSON_UNESCAPED_UNICODE ) );
    rw( array() === $r['btn'], "PLANT B: $name: Check in or Remove: " . json_encode( $r['btn'], JSON_UNESCAPED_UNICODE ) );
    rw( 'none' === $r['contentMax'], "PLANT A: $name: the page keeps its narrow column, max-width " . $r['contentMax'] );
    if ( 'byname' === $k ) {
        rw( 33 === $r['rows'] && 32 === $r['checkins'] && 32 === $r['removes'], "$name: rows, Check in and Remove drawn: " . json_encode( array( $r['rows'], $r['checkins'], $r['removes'] ) ) );
        rw( 'Maximiliano Alejandro Fernández-Castellanos de la Torre' === $r['firstName'], "C: $name: the Name column reads " . json_encode( $r['firstName'], JSON_UNESCAPED_UNICODE ) );
        rw( 'Nov 2, 11:48 am' === $r['dates']['now'] && 'Dec 30, 2025, 9:05 pm' === $r['dates']['old'], "C: $name: Registered reads " . json_encode( $r['dates'] ) );
    } elseif ( 'all' === $k ) {
        rw( 35 === $r['rows'] && 0 === $r['checkins'] && 34 === $r['removes'], "$name: every registration, with Remove and no Check in: " . json_encode( array( $r['rows'], $r['checkins'], $r['removes'] ) ) );
    } else {
        rw( 0 === $r['checkins'] && 2 === $r['removes'], "E: $name: Enter a count draws " . json_encode( array( $r['checkins'], $r['removes'] ) ) );
        rw( $r['attend'] && true === $r['attend']['on'] && '14' === $r['attend']['value'], "E: $name: People attended and Save: " . json_encode( $r['attend'] ) );
    }
    if ( $w > 1024 ) {
        $heads = array_merge( 'all' === $k ? array( 'Event' ) : array(), array( 'Name', 'Email', 'Phone', 'Updates', 'Status', 'Registered', 'Actions' ) );
        rw( true === $r['thead'] && $heads === $r['heads'] && true === $r['headsOn'],
            "PLANT C: $name: the table's headings: " . json_encode( array( $r['thead'], $r['heads'], $r['headsOn'] ) ) );
        rw( array() === $r['cramped'], "PLANT C: $name: columns squeezed under 72px: " . json_encode( $r['cramped'] ) );
    } else {
        rw( false === $r['thead'], "PLANT D: $name: at {$w}px each person is not a card: the heading row shows" );
    }
}

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    foreach ( $files as $name => $f ) {
        $png = __DIR__ . '/screens/caladmin-31330-' . $name . '.png';
        shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --virtual-time-budget=8000 --window-size=' . max( 600, $f[0] ) . ',1000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $f[1] ) . '" 2>NUL' );
    }
}

echo "\n3.133.0: the RSVP list at 390, 768, 1024 and 1280px, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Thirty-three registrations with long names and a long email, by name and by count: the page takes the full width, no row is\nwider than its card and nothing runs past it or the screen; the table with every heading at 1280px, one card a person at 1024px\nand under, Check in beside the name and 44px tall, Remove below it at the far side; Enter a count keeps People attended in.\n";
exit( 0 );
