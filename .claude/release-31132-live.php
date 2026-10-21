<?php
/**
 * 3.113.2 IN A BROWSER: THE LEAVE WARNING, NO FAQS, A SUBMISSION'S THREE
 * BUTTONS AND THE REJECTED AND DISMISSED TAB.
 *
 *     php .claude/release-31132-live.php --run
 *     php .claude/release-31132-live.php --run --verbose   and print what was measured
 *
 * REAL: Edit event, Add event, a pending submission and Pending's Rejected and
 * dismissed tab rendered through wp-kit.php, with the real portal.css,
 * portal.js and caladmin-session.js, in Chrome at 1280px and 390px.
 *
 *   L  the leave warning (window.ucSession.unsaved(), which is what the
 *      beforeunload handler asks): nothing on load; nothing for a click that
 *      changes nothing; yes once a field changes; nothing once it is put back;
 *      nothing once the event form is submitted, or the Delete form; still yes
 *      when another form on the page is submitted. On Add event the same.
 *   F  No FAQs for this event: ticked, the set picker and the rows hide and
 *      the hint shows; unticked, they come back
 *   B  a submission waiting for review: Reject, Save changes, Approve left to
 *      right; Approve green and taller than Save; Save not green; no Cancel
 *      event or Delete
 *   T  the tab: no sideways scroll at 390px; each Delete permanently is bound
 *      to its confirmation
 *
 * The browser draws the leave warning itself, so its words are not checked.
 * PLANTs L, F, B and T in plant-31132.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1, 'pending' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
function rl_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type || ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) ) { continue; }
        if ( 'trash' === $p->post_status && '' === (string) get_post_meta( $id, SFAF_Uploads::META_REJECTED, true ) ) { continue; }
        $out[] = $p;
    }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'ID' ) : $out;
}
$GLOBALS['kit_query'] = 'rl_query'; $GLOBALS['kit_get_posts'] = 'rl_query';
$GLOBALS['kit_caps']['manage_options'] = true;
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();

function rl_event( $status ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Support group', 'post_author' => 1, 'post_content' => '<p>Weekly.</p>' ) );
    foreach ( array( '_uc_event_date' => date( 'Y-m-d', time() + 9 * 86400 ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 11 );
    $GLOBALS['kit_terms'][ $id ]['uc_organizer']      = array( 11 );
    return $id;
}
$ev  = rl_event( 'publish' );
update_post_meta( $ev, sfaf_faq_meta_key(), array( array( 'question' => 'Is it free?', 'answer' => 'Yes.' ) ) );
$sub = rl_event( 'pending' );
update_post_meta( $sub, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_COMMUNITY );
update_post_meta( $sub, SFAF_Request::META_EMAIL, 'pat@example.org' );
update_post_meta( $sub, SFAF_Request::META_NAME, 'Pat' );
foreach ( array( 'A dismissed campaign with a long name that has to wrap on a phone', 'Another one' ) as $t ) {
    $d = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => SFAF_Sources::STATUS_DISMISSED, 'post_title' => $t, 'post_author' => 0, 'post_content' => '' ) );
    update_post_meta( $d, SFAF_Sources::META_SOURCE, 'everyaction' );
}
$rj = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'trash', 'post_title' => 'A rejected request', 'post_author' => 0, 'post_content' => '' ) );
update_post_meta( $rj, SFAF_Uploads::META_REJECTED, '2026-10-19 09:00:00' );
update_post_meta( $rj, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_STAFF );

$render = function ( $fn ) { $h = rl_cap( $fn ); rl( 0 !== strpos( $h, 'THREW' ), "a page did not render: $h" ); return $h; };
$edit    = $render( function () use ( $portal, $ev ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $ev ) ); } );
$add     = $render( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) ); } );
$pending = $render( function () use ( $portal, $sub ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $sub ) ); } );
$closed  = $render( function () use ( $portal ) { $_GET = array( 'view' => 'closed' ); kit_call( 'SFAF_Portal', 'render_pending', $portal, array( new WP_User() ) ); } );
rl( false !== strpos( $edit, 'uc-session-config' ) && false !== strpos( $edit, 'caladmin-session.js' ), 'the editor page loads no caladmin-session.js, so the leave warning cannot be tested' );

$probe = <<<'JS'
(function () {
var S = __SCENARIO__;
var out = { scenario: S, errors: [] };
/* The leave warning (3.113.2) can stall headless Chrome when it closes a page
   the probe changed, and no person is there to answer it. Off for the probe:
   a capture listener on the target fires before the page's own. */
window.addEventListener('beforeunload', function (e) { e.stopImmediatePropagation(); }, true);
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function shown(el) { return !!el && !el.hidden && el.getClientRects().length > 0; }
function wait(ms, fn) { setTimeout(fn, ms); }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
function unsaved() { return !!(window.ucSession && window.ucSession.unsaved && window.ucSession.unsaved()); }
function fire(el, type) { el.dispatchEvent(new Event(type, { bubbles: true })); }
function submit(form) { var f = window.fetch; window.fetch = undefined; form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); window.fetch = f; }

window.addEventListener('load', function () { wait(150, function () {
  var form = q('form[data-uc-leave-warn]');
  var title = form ? form.querySelector('input[name="title"]') : null;
  out.found = !!(form && title && window.ucSession);
  if (!out.found) { finish(); return; }
  if (S === 'leave') {
    out.load = unsaved();
    fire(title, 'click');
    out.clickOnly = unsaved();
    var was = title.value;
    title.value = was + ' changed'; fire(title, 'input');
    out.typed = unsaved();
    title.value = was; fire(title, 'input');
    out.reverted = unsaved();
    title.value = was + ' again'; fire(title, 'input');
    submit(form);
    out.afterSave = unsaved();
  } else if (S === 'other') {
    title.value = title.value + ' changed'; fire(title, 'input');
    var other = null;
    Array.prototype.forEach.call(document.forms, function (f) {
      var a = f.querySelector('input[name="uc_action"]');
      if (!other && f !== form && a && a.value !== 'trash_event' && f.getAttribute('method') === 'post') { other = f; }
    });
    out.otherAction = other ? other.querySelector('input[name="uc_action"]').value : null;
    if (other) { submit(other); }
    out.afterOther = unsaved();
    var del = null;
    Array.prototype.forEach.call(document.forms, function (f) { var a = f.querySelector('input[name="uc_action"]'); if (a && a.value === 'trash_event') { del = f; } });
    out.deleteForm = !!del;
    if (del) { submit(del); }
    out.afterDelete = unsaved();
  } else if (S === 'add') {
    out.load = unsaved();
    title.value = 'A new event'; fire(title, 'input');
    out.typed = unsaved();
    var tick = q('[data-uc-faq-none]'), body = q('[data-uc-faq-body]'), hint = q('[data-uc-faq-none-hint]');
    out.faq = { found: !!(tick && body && hint), before: { body: shown(body), hint: shown(hint) } };
    if (tick) {
      tick.click();
      out.faq.ticked = { body: shown(body), hint: shown(hint), picker: shown(q('.uc-repeater-add', body)) };
      tick.click();
      out.faq.unticked = { body: shown(body), hint: shown(hint) };
    }
  }
  finish();
}); });
})();
JS;

$probe_bar = <<<'JS'
(function () {
var out = { errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
window.addEventListener('load', function () { setTimeout(function () {
  var bar = document.querySelector('[data-uc-editor-bar]');
  out.buttons = [];
  if (bar) {
    Array.prototype.forEach.call(bar.querySelectorAll('button'), function (b) {
      var r = b.getBoundingClientRect(), s = getComputedStyle(b);
      if (!r.width) { return; }
      out.buttons.push({ text: b.textContent.replace(/\s+/g, ' ').trim(), x: Math.round(r.left), y: Math.round(r.top), h: Math.round(r.height), bg: s.backgroundColor });
    });
  }
  out.cancel = !!document.querySelector('[data-uc-dialog-open="uc-cancel-this"]');
  out.del = !!document.querySelector('[data-uc-dialog-open="uc-delete-this"]');
  var purge = document.querySelectorAll('[data-uc-purge]');
  out.purge = purge.length;
  out.purgeBound = Array.prototype.filter.call(purge, function (b) { return b.getAttribute('data-uc-confirm-bound') === '1'; }).length;
  out.pageWidth = document.documentElement.scrollWidth;
  window.parent.postMessage(JSON.stringify(out), '*');
}, 150); });
})();
JS;

$pages = array(
    'leave-desktop'   => array( 'w' => 1280, 'html' => $edit, 'probe' => str_replace( '__SCENARIO__', "'leave'", $probe ) ),
    'other-desktop'   => array( 'w' => 1280, 'html' => $edit, 'probe' => str_replace( '__SCENARIO__', "'other'", $probe ) ),
    'add-desktop'     => array( 'w' => 1280, 'html' => $add,  'probe' => str_replace( '__SCENARIO__', "'add'", $probe ) ),
    'add-phone'       => array( 'w' => 390,  'html' => $add,  'probe' => str_replace( '__SCENARIO__', "'add'", $probe ) ),
    'sub-desktop'     => array( 'w' => 1280, 'html' => $pending, 'probe' => $probe_bar ),
    'sub-phone'       => array( 'w' => 390,  'html' => $pending, 'probe' => $probe_bar ),
    'closed-desktop'  => array( 'w' => 1280, 'html' => $closed, 'probe' => $probe_bar ),
    'closed-phone'    => array( 'w' => 390,  'html' => $closed, 'probe' => $probe_bar ),
);
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31132-live-' . $name . '.html';
    file_put_contents( $inner, str_replace( '</body>', '<script>' . $p['probe'] . '</script></body>', $p['html'] ) );
    $files[ $name ] = __DIR__ . '/release-31132-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=20000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ]['w'], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ]['w'] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}

/* ---- L ---- */
if ( isset( $g['leave-desktop'] ) ) {
    $r = $g['leave-desktop'];
    rl( ! empty( $r['found'] ), 'L: the event form, its title or window.ucSession is missing' );
    rl( false === $r['load'], 'L: unsaved on load, with nothing changed' );
    rl( false === $r['clickOnly'], 'L: unsaved after a click that changed nothing' );
    rl( true === $r['typed'], 'L: a changed title is not unsaved' );
    rl( false === $r['reverted'], 'L: still unsaved once the title was put back' );
    rl( false === $r['afterSave'], 'L: still warns after the event form was submitted' );
}
if ( isset( $g['other-desktop'] ) ) {
    $r = $g['other-desktop'];
    rl( null !== $r['otherAction'], 'L: no other form on the editor to submit, so this case tests nothing' );
    rl( true === $r['afterOther'], 'L: submitting another form (' . json_encode( $r['otherAction'] ) . ') cleared the warning, though the changes were not saved' );
    rl( ! empty( $r['deleteForm'] ) && false === $r['afterDelete'], 'L: Delete still warns about the changes it throws away: ' . json_encode( $r ) );
}
foreach ( array( 'add-desktop', 'add-phone' ) as $n ) {
    if ( ! isset( $g[ $n ] ) ) { continue; }
    $r = $g[ $n ];
    rl( false === $r['load'] && true === $r['typed'], "L $n: Add event is not unsaved after typing, or is on load: " . json_encode( array( $r['load'], $r['typed'] ) ) );
    /* ---- F ---- */
    $f = (array) $r['faq'];
    rl( ! empty( $f['found'] ), "F $n: no No FAQs tick, body or hint" );
    if ( ! empty( $f['found'] ) ) {
        rl( $f['before']['body'] && ! $f['before']['hint'], "F $n: before the tick the rows do not show, or the hint does" );
        rl( ! $f['ticked']['body'] && $f['ticked']['hint'] && ! $f['ticked']['picker'], "F $n: ticked, the rows still show or the hint does not: " . json_encode( $f['ticked'] ) );
        rl( $f['unticked']['body'] && ! $f['unticked']['hint'], "F $n: unticked, the rows did not come back" );
    }
}

/* ---- B ---- */
foreach ( array( 'sub-desktop', 'sub-phone' ) as $n ) {
    if ( ! isset( $g[ $n ] ) ) { continue; }
    $r = $g[ $n ];
    $by = array();
    foreach ( (array) $r['buttons'] as $b ) { $by[ $b['text'] ] = $b; }
    rl( array( 'Approve', 'Reject', 'Save changes' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $by ) ), "B $n: the bar holds " . json_encode( array_keys( $by ) ) );
    rl( ! $r['cancel'] && ! $r['del'], "B $n: Cancel event or Delete is offered on a submission waiting for review" );
    if ( 3 === count( $by ) && isset( $by['Approve'], $by['Reject'], $by['Save changes'] ) ) {
        if ( 'sub-desktop' === $n ) {
            rl( $by['Reject']['x'] < $by['Save changes']['x'] && $by['Save changes']['x'] < $by['Approve']['x'], "B $n: not Reject, Save changes, Approve left to right: " . json_encode( $by ) );
        }
        rl( 'rgb(21, 128, 61)' === $by['Approve']['bg'], "B $n: Approve is not green: " . $by['Approve']['bg'] );
        rl( 'rgb(21, 128, 61)' !== $by['Save changes']['bg'], "B $n: Save changes is still green" );
        /* On a phone every button in the bar has the same padding, Publish too. */
        if ( 'sub-desktop' === $n ) {
            rl( $by['Reject']['y'] === $by['Approve']['y'] - ( $by['Approve']['h'] - $by['Reject']['h'] ) / 2 || abs( ( $by['Reject']['y'] + $by['Reject']['h'] / 2 ) - ( $by['Approve']['y'] + $by['Approve']['h'] / 2 ) ) <= 2, "B $n: the three are not on one line: " . json_encode( $by ) );
        }
        if ( 'sub-desktop' === $n ) rl( $by['Approve']['h'] > $by['Save changes']['h'], "B $n: Approve is not taller than Save changes: " . $by['Approve']['h'] . ' and ' . $by['Save changes']['h'] );
    }
}

/* ---- T ---- */
foreach ( array( 'closed-desktop', 'closed-phone' ) as $n ) {
    if ( ! isset( $g[ $n ] ) ) { continue; }
    $r = $g[ $n ];
    rl( 3 === (int) $r['purge'], "T $n: " . (int) $r['purge'] . ' Delete permanently buttons, not three' );
    rl( (int) $r['purge'] === (int) $r['purgeBound'], "T $n: a Delete permanently is not bound to its confirmation" );
}

echo "\nRelease 3.113.2 in a browser\n" . str_repeat( '=', 72 ) . "\n";
echo count( $g ) . " pages driven.\n";
if ( $fails ) { echo count( $fails ) . " failure(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "The leave warning comes and goes with the changes and a save, No FAQs hides the block, a submission has three buttons in order, and Delete permanently asks first.\n";
exit( 0 );
