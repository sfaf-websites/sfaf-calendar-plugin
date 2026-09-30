<?php
/**
 * EVERY PIECE OF TEXT THAT RENDERS IN CAPITALS, READ OFF THE RENDERED PAGE (3.105.0).
 *
 *     php .claude/caps-sweep-live.php          writes the pages
 *     php .claude/caps-sweep-live.php --run    writes them, reads them in Chrome, reports
 *     php .claude/caps-sweep-live.php --list   the same, and prints every instance
 *
 * WHY IN A BROWSER. Capitals here are never in the source: they come from
 * text-transform, and text-transform INHERITS. The instance that was reported,
 * the help text under "Is this part of a series", has no rule of its own
 * saying uppercase; it is a help panel that opens inside a card's title and
 * takes the title's treatment. A grep for "uppercase" finds the title's rule
 * and says nothing about what sits inside it. So every element with text of
 * its own is asked for its COMPUTED text-transform, hidden ones included,
 * since a help panel is hidden until somebody opens it.
 *
 * WHAT COUNTS AS A HEADING. The text of an h1 to h6, or a table header. Those
 * may be in capitals: the card heading is 14px uppercase on its band by
 * design (DESIGN.md, the type scale). Anything else in capitals is reported:
 * a label, a help panel, a count, a button, a sentence.
 *
 * WHAT IS ALLOWED, BY NAME, AND WHY. A few non-heading treatments are
 * deliberate and each is recorded where it is set: the word "Closed" on a
 * closed day, the weekday and month on a date badge, the word "Cancelled"
 * above a cancelled event, the role under the name in caladmin's sidebar, the
 * filter bar's group label. They are listed below with the rule's own
 * reasoning, and the report prints them as found so nobody has to take the
 * list's word for it. Anything NOT on the list fails the sweep.
 */

require __DIR__ . '/wp-kit.php';

$ALLOWED = array(
    'uc-closed-word'        => 'the word Closed on a closed day, DESIGN.md "the closure hatch": the word is the fact',
    'uc-lc-dow'             => 'the weekday on the list card\'s date badge',
    'uc-compact-month'      => 'the month on the compact list\'s date badge',
    'uc-lc-cancelled'       => 'the word Cancelled above a cancelled event, PROJECT.md 4',
    'uc-portal-me-role'     => 'the access level under the name in caladmin\'s sidebar',
    'uc-groups-label'       => 'the filter bar\'s group label',
    'uc-who-heading'        => 'each column\'s section heading in the organizers and groups panel, on its measured band (calendar.css, 3.93.0): a heading in role, drawn as a <p>',
);

$fails = array();
function cs_fail( $why ) { global $fails; $fails[] = $why; }

/* ---- A world with something on every screen. -------------------------- */
function cs_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type || ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) ) { continue; }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
}
$GLOBALS['kit_query'] = 'cs_query';
$GLOBALS['kit_term_desc'][11] = '<p>Coffee and conversation.</p>';
$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Coffee social', 'post_content' => '<p>Words.</p>' ) );
foreach ( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '10:00', '_uc_end_time' => '11:30', '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $ev, $k, $v ); }
wp_set_object_terms( $ev, array( 11 ), 'uc_series' );
wp_set_object_terms( $ev, array( 11 ), 'uc_event_category' );
$imp = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'uc_imported', 'post_title' => 'Imported walk' ) );
update_post_meta( $imp, '_uc_event_date', '2026-11-26' );
SFAF_Closures::save( '', 'Thanksgiving', '2026-11-26', '2026-11-27', 'Call ahead', array( array( 'venue' => 11, 'from' => '10:00', 'to' => '14:00' ) ) );

/* The registrations table, for the RSVP list. */
$GLOBALS['cs_rows'] = array(
    array( 'id' => 1, 'event_id' => $ev, 'event_title' => '', 'name' => 'Robin Ruiz', 'first_name' => 'Robin', 'last_name' => 'Ruiz', 'email' => 'robin@example.org',
        'phone' => '', 'status' => 'confirmed', 'token' => '', 'format' => '', 'created_at' => '2026-09-20 10:00:00', 'cancelled_at' => null, 'removed_by' => 0 ),
);
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( 'get_results' === $method && false !== strpos( $sql, 'uc_rsvps' ) && false !== strpos( $sql, 'LEFT JOIN' ) ) {
        return array_map( function ( $r ) { $r['post_title'] = 'Coffee social'; return (object) $r; }, $GLOBALS['cs_rows'] );
    }
    return 'get_results' === $method ? array() : null;
};

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function cs_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); }
    catch ( Throwable $t ) {
        while ( ob_get_level() > $depth ) { ob_end_clean(); }
        return 'THREW ' . get_class( $t ) . ': ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine();
    }
    $html = '';
    while ( ob_get_level() > $depth ) { $html = ob_get_clean() . $html; }
    return $html;
}
function cs_screen( $method, $args, $get = array() ) {
    global $portal;
    return cs_capture( function () use ( $method, $args, $get ) {
        $_GET = $get;
        kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args );
    } );
}

$pages = array(
    'caladmin-dashboard'   => cs_screen( 'render_dashboard', array( $user ) ),
    'caladmin-events'      => cs_screen( 'render_events', array( $user ) ),
    'caladmin-new-event'   => cs_screen( 'render_event_form', array( $user, 0 ) ),
    'caladmin-edit-event'  => cs_screen( 'render_event_form', array( $user, $ev ) ),
    'caladmin-series'      => cs_screen( 'render_series_list', array( $user ) ),
    'caladmin-series-edit' => cs_screen( 'render_series_edit', array( $user, 11 ) ),
    'caladmin-pending'     => cs_screen( 'render_pending', array( $user ) ),
    'caladmin-rsvps'       => cs_screen( 'render_rsvps', array( $user ), array( 'event_id' => $ev ) ),
    'caladmin-venues'      => cs_screen( 'render_venues', array( $user ) ),
    'caladmin-organizers'  => cs_screen( 'render_organizers', array( $user ) ),
    'caladmin-faq-sets'    => cs_screen( 'render_faq_sets', array( $user ) ),
    'caladmin-users'       => cs_screen( 'render_users', array( $user ) ),
    'caladmin-media'       => cs_screen( 'render_media', array( $user ) ),
    'caladmin-preferences' => cs_screen( 'render_preferences', array( $user ) ),
    'caladmin-optins'      => cs_screen( 'render_optins', array( $user ) ),
    'caladmin-email-templates' => cs_screen( 'render_email_templates', array( $user ) ),
);

/* The public calendar, in the stylesheet it loads, in each view. */
$sc = new SFAF_Shortcodes();
function cs_public( $body ) {
    if ( 0 === strpos( $body, 'THREW' ) ) { return $body; }
    return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><link rel=\"stylesheet\" href=\"../public/css/calendar.css\"></head><body>"
        . $body . '</body></html>';
}
foreach ( array( 'calendar' => 'calendar', 'list' => 'list' ) as $name => $view ) {
    $block = cs_capture( function () use ( $sc, $view ) {
        $r = $sc->render_calendar_block( array( 'view' => $view, 'month' => '2026-11', 'toggle' => 'yes' ) );
        echo is_array( $r ) ? $r['html'] : $r;
    } );
    $pages[ 'public-' . $name ] = cs_public( $block );
}
$pages['public-sidebar'] = cs_public( cs_capture( function () use ( $sc ) { echo $sc->render_sidebar( array(), 10, null, '2026-11' ); } ) );
$pages['public-closure-card'] = cs_public( '<div class="uc-calendar">' . $sc->render_closure_card( SFAF_Closures::all()[0] ) . '</div>' );

/* Both public forms, in the document they are served in. */
$pages['public-request-form'] = cs_capture( function () {
    SFAF_Submissions::page_open( 'Request an event' );
    kit_call( 'SFAF_Request', 'render_form', null, array( 'tok', 'someone@sfaf.org', array(), array() ) );
    SFAF_Submissions::page_close();
} );
$pages['public-submit-form'] = cs_capture( function () {
    SFAF_Submissions::page_open( 'Submit an event' );
    kit_call( 'SFAF_Submit', 'render_form', null, array( (object) array( 'slug' => 'cycle-to-zero', 'name' => 'Cycle to Zero', 'term_id' => 11 ), array(), array() ) );
    SFAF_Submissions::page_close();
} );

foreach ( $pages as $name => $html ) {
    if ( 0 === strpos( $html, 'THREW' ) ) {
        cs_fail( "$name could not be rendered, so it was not swept: $html" );
        unset( $pages[ $name ] );
    }
}

$probe = <<<'JS'
(function () {
var out = { page: window.CS_PAGE, errors: [], caps: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function path(el) {
  var p = [];
  for (var n = el; n && n.nodeType === 1 && p.length < 4; n = n.parentNode) {
    p.unshift(n.tagName.toLowerCase() + (n.className && typeof n.className === 'string' ? '.' + n.className.trim().split(/\s+/).join('.') : ''));
  }
  return p.join(' > ');
}
window.addEventListener('load', function () { setTimeout(function () {
  Array.prototype.forEach.call(document.body.querySelectorAll('*'), function (el) {
    if (el.closest('script, style, template, noscript, #out')) { return; }
    var own = '';
    Array.prototype.forEach.call(el.childNodes, function (n) { if (n.nodeType === 3) { own += n.textContent; } });
    own = own.replace(/\s+/g, ' ').trim();
    if (!/[a-z]/.test(own)) { return; }
    var cs = getComputedStyle(el);
    if (cs.textTransform !== 'uppercase' && cs.fontVariantCaps.indexOf('small-caps') < 0) { return; }
    /* A heading's own text: the element itself, anything inside a table
       header, or a plain wrapper inside a heading. Help panels, hints,
       labels, controls and sentences inside a heading are NOT its text,
       which is the whole of what this sweep is for. */
    var heading = /^(H[1-6]|TH)$/.test(el.tagName) || !!el.closest('th')
      || (!!el.closest('h1, h2, h3, h4, h5, h6') && !el.closest('.uc-help-body, .uc-hint, .uc-muted, .uc-field-label, label, button, p, small, select, a'));
    var classes = [];
    for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
      if (typeof n.className === 'string') { classes = classes.concat(n.className.trim().split(/\s+/)); }
    }
    out.caps.push({ text: own.slice(0, 70), heading: heading, path: path(el), classes: classes,
      shown: el.getClientRects().length > 0 });
  });
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}, 300); });
})();
JS;

$files = array();
foreach ( $pages as $name => $html ) {
    $inject = '<script>window.CS_PAGE=' . json_encode( $name ) . ';' . $probe . '</script>';
    $page = ( false !== stripos( $html, '</body>' ) ) ? preg_replace( '#</body>#i', $inject . '</body>', $html, 1 ) : $html . $inject;
    $files[ $name ] = __DIR__ . '/caps-sweep-live-' . $name . '.html';
    file_put_contents( $files[ $name ], $page );
}
echo 'wrote ' . count( $files ) . " pages\n";

$run  = in_array( '--run', $argv, true ) || in_array( '--list', $argv, true );
$list = in_array( '--list', $argv, true );
if ( $run ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $found = array( 'heading' => 0, 'allowed' => array(), 'fault' => array() );
    foreach ( $files as $name => $file ) {
        $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1200,2400 --virtual-time-budget=6000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
        if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { cs_fail( "$name: Chrome returned no probe block" ); continue; }
        $got = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        if ( ! empty( $got['errors'] ) ) { cs_fail( "$name threw: " . implode( '; ', $got['errors'] ) ); }
        foreach ( $got['caps'] as $c ) {
            if ( $c['heading'] ) { $found['heading']++; continue; }
            $why = '';
            foreach ( $ALLOWED as $cls => $reason ) { if ( in_array( $cls, $c['classes'], true ) ) { $why = $cls; break; } }
            $line = sprintf( '%-22s %-9s "%s"  [%s]', $name, $c['shown'] ? 'shown' : 'hidden', $c['text'], $c['path'] );
            if ( '' !== $why ) { $found['allowed'][] = $line . '  allowed: ' . $why; }
            else { $found['fault'][] = $line; cs_fail( 'non-heading text in capitals: ' . $line ); }
        }
    }
    if ( $list ) {
        echo "\nHeadings in capitals, by design: {$found['heading']}\n";
        echo "\nAllowed by name (" . count( $found['allowed'] ) . "):\n  " . implode( "\n  ", $found['allowed'] ) . "\n";
        echo "\nNOT ALLOWED (" . count( $found['fault'] ) . "):\n  " . implode( "\n  ", $found['fault'] ) . "\n";
    }
}

if ( $fails ) {
    echo 'CAPS SWEEP: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", array_slice( $fails, 0, 60 ) ) . "\n";
    exit( 1 );
}
echo $run ? "caps sweep: every page read in Chrome; nothing but a heading, or a treatment allowed by name, renders in capitals.\n" : "pages written; --run reads them.\n";
