<?php
/**
 * 3.106.2 IN A REAL BROWSER: QUESTIONS FOR REGISTRANTS IN THE EDITOR, ON THE
 * PUBLIC FORM AND ON THE REGISTRATIONS LIST, THE NOTIFICATIONS CARD ON ADD
 * EVENT, AND THE CANCEL DIALOG COUNTING THE WAITLIST, AT DESKTOP AND 390px.
 *
 *     php .claude/release-31062-live.php          writes the pages
 *     php .claude/release-31062-live.php --run    writes them, drives Chrome, checks
 *
 * The real renderers through wp-kit.php with the real portal.css, portal.js,
 * calendar.css and calendar.js. Every check is read off the page after Chrome
 * has laid it out and the scripts have run, and every colour is compared with
 * the DESIGN.md token it should be.
 *
 *   editor-*     the Questions for registrants section sits in the RSVP
 *                settings; Add question adds one with its fields named in
 *                order and hides at five; Add option and Remove renumber; In
 *                person only shows with the hybrid tick; the Notifications
 *                card is on Add event and Edit event alike.
 *   form-*       Additional information after the format choice and before
 *                the name and email; In person only appears when In person is
 *                picked; Additional info beside its option; a required
 *                question stops the form; the answers are posted; Spanish
 *                heading and label on a Spanish event.
 *   rsvps-*      the totals strip above the list only when the event asks
 *                questions; Details opens and closes each person's answers.
 *   cancel-*     an event with nobody registered and two waiting asks about
 *                email and says both numbers.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rq_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rq_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); }
    catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}

/* ---- The world. -------------------------------------------------------- */
$GLOBALS['kit_query'] = function ( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' === $p->post_type ) { $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; }
    }
    return $out;
};
function rq_event( $title, $meta ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>Words.</p>', 'post_author' => 1 ) );
    foreach ( array_merge( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
$QS = array(
    array( 'id' => 'qaaaaaaaaaa', 'text' => 'Any dietary needs?', 'style' => 'any', 'required' => true, 'in_person' => false, 'options' => array(
        array( 'id' => 'oaaaaaaaaa1', 'text' => 'Vegetarian', 'more' => false ),
        array( 'id' => 'oaaaaaaaaa2', 'text' => 'Allergy', 'more' => true ) ) ),
    array( 'id' => 'qbbbbbbbbbb', 'text' => 'Will you park on site?', 'style' => 'one', 'required' => false, 'in_person' => true, 'options' => array(
        array( 'id' => 'obbbbbbbbb1', 'text' => 'Yes', 'more' => false ),
        array( 'id' => 'obbbbbbbbb2', 'text' => 'No', 'more' => false ) ) ),
);
$hyb   = rq_event( 'Community dinner', array( SFAF_Online::META_HYBRID => '1', '_uc_capacity_online' => '50', SFAF_Questions::META => $QS ) );
$plain = rq_event( 'Coffee social', array( SFAF_Questions::META => array( $QS[0] ) ) );
$es    = rq_event( 'Cena comunitaria', array( '_uc_language' => 'es', SFAF_Questions::META => array( $QS[0] ) ) );
$none  = rq_event( 'Book club', array() );
$wait  = rq_event( 'Support group', array( '_uc_capacity' => '2' ) );

$row = function ( $id, $event, $first, $last, $status, $email ) {
    return array( 'id' => $id, 'event_id' => $event, 'name' => trim( "$first $last" ), 'first_name' => $first, 'last_name' => $last, 'email' => $email,
        'phone' => '', 'status' => $status, 'token' => 't' . $id, 'format' => '', 'created_at' => '2026-09-2' . ( $id % 9 ) . ' 10:00:00',
        'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null );
};
$GLOBALS['rq_rows'] = array(
    $row( 1, $plain, 'Ana', 'Alvarez', 'confirmed', 'ana@example.org' ),
    $row( 2, $plain, 'Ben', 'Brooks', 'confirmed', 'ben@example.org' ),
    $row( 3, $plain, 'Cam', 'Chen', 'waitlisted', 'cam@example.org' ),
    $row( 4, $none, 'Dee', 'Diaz', 'confirmed', 'dee@example.org' ),
    $row( 5, $wait, 'Eve', 'Evans', 'waitlisted', 'eve@example.org' ),
    $row( 6, $wait, 'Fay', 'Fox', 'offered', 'fay@example.org' ),
);
$ans = function ( $id, $rsvp, $q, $o, $qt, $ot, $more ) use ( $plain ) {
    return array( 'id' => $id, 'rsvp_id' => $rsvp, 'event_id' => $plain, 'question_id' => $q, 'option_id' => $o, 'question_text' => $qt, 'option_text' => $ot, 'more_text' => $more, 'created_at' => '2026-09-20 10:00:00' );
};
$GLOBALS['rq_answers'] = array(
    $ans( 1, 1, 'qaaaaaaaaaa', 'oaaaaaaaaa2', 'Any dietary needs?', 'Allergy', 'Peanuts' ),
    $ans( 2, 2, 'qaaaaaaaaaa', 'oaaaaaaaaa1', 'Any dietary needs?', 'Vegetarian', '' ),
    $ans( 3, 3, 'qaaaaaaaaaa', 'oaaaaaaaaa1', 'Any dietary needs?', 'Vegetarian', '' ),
);

/* Answers the SQL the code under test runs, from the rows above. */
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    $answers = false !== strpos( $sql, 'uc_rsvp_answers' );
    if ( ! $answers && false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
    $rows = $answers ? $GLOBALS['rq_answers'] : $GLOBALS['rq_rows'];
    if ( preg_match( '/\bevent_id\s*=\s*(\d+)/', $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r['event_id'] === (int) $m[1]; } );
    }
    if ( preg_match( "/rsvp_id\s+IN\s*\(([^)]*)\)/i", $sql, $m ) ) {
        preg_match_all( "/\d+/", $m[1], $in );
        $rows = array_filter( $rows, function ( $r ) use ( $in ) { return in_array( (string) $r['rsvp_id'], $in[0], true ); } );
    }
    if ( preg_match( "/status\s*=\s*'([a-z_]+)'/", $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['status'] === $m[1]; } );
    } elseif ( preg_match( "/status\s+IN\s*\(([^)]*)\)/i", $sql, $m ) ) {
        preg_match_all( "/'([a-z_]+)'/", $m[1], $in );
        $rows = array_filter( $rows, function ( $r ) use ( $in ) { return in_array( $r['status'], $in[1], true ); } );
    }
    if ( 'get_var' === $method ) { return (string) count( $rows ); }
    if ( 'get_results' === $method ) {
        return array_map( function ( $r ) { if ( isset( $r['first_name'] ) ) { $r['post_title'] = get_the_title( $r['event_id'] ); $r['event_title'] = ''; } return (object) $r; }, array_values( $rows ) );
    }
    return null;
};

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function rq_screen( $method, $args, $get = array() ) {
    return rq_capture( function () use ( $method, $args, $get ) { $_GET = $get; kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args ); } );
}
/* The event page's RSVP card, in the wrappers the template gives it. */
function rq_single( $id ) {
    $body = '<div class="uc-single"><div class="uc-single-inner"><div class="uc-single-grid"><div class="uc-single-main"></div>'
        . '<aside class="uc-single-sidebar"><div class="uc-single-card">' . sfaf_rsvp_block( $id ) . '</div></aside></div></div></div>';
    return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\">"
        . "<link rel=\"stylesheet\" href=\"../public/css/calendar.css\"></head><body style=\"margin:0;background:#fff\">" . $body
        . "<script src=\"vendor/jquery-3.7.1.min.js\"></script><script>window.ucData={ajaxUrl:''};</script>"
        . "<script src=\"../public/js/calendar.js\"></script></body></html>";
}

$add    = rq_screen( 'render_event_form', array( $user, 0 ) );
$edit   = rq_screen( 'render_event_form', array( $user, $hyb ) );
$rsq    = rq_screen( 'render_rsvps', array( $user ), array( 'event_id' => $plain ) );
$rsn    = rq_screen( 'render_rsvps', array( $user ), array( 'event_id' => $none ) );
$cancel = rq_screen( 'render_event_form', array( $user, $wait ) );
$pages = array(
    'editor-add-desktop'  => array( 'w' => 1280, 'html' => $add ),
    'editor-add-phone'    => array( 'w' => 390,  'html' => $add ),
    'editor-edit-desktop' => array( 'w' => 1280, 'html' => $edit ),
    'editor-edit-phone'   => array( 'w' => 390,  'html' => $edit ),
    'form-desktop'        => array( 'w' => 1280, 'html' => rq_single( $hyb ) ),
    'form-phone'          => array( 'w' => 390,  'html' => rq_single( $hyb ) ),
    'form-es'             => array( 'w' => 1280, 'html' => rq_single( $es ) ),
    'form-none'           => array( 'w' => 1280, 'html' => rq_single( $none ) ),
    'rsvps-q-desktop'     => array( 'w' => 1280, 'html' => $rsq ),
    'rsvps-q-phone'       => array( 'w' => 390,  'html' => $rsq ),
    'rsvps-none-desktop'  => array( 'w' => 1280, 'html' => $rsn ),
    'cancel-desktop'      => array( 'w' => 1280, 'html' => $cancel ),
);

$probe = <<<'JS'
(function () {
var P = window.RQ_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; }
function rgb(s) { var m = String(s).match(/\d+(\.\d+)?/g); return m ? m.slice(0, 4).map(Number) : null; }
function lum(c) { return [0, 1, 2].map(function (i) { var v = c[i] / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function behind(el) {
  for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
    var cs = getComputedStyle(n), c = rgb(cs.backgroundColor);
    if (c && (c.length < 4 || c[3] > 0)) { return c; }
  }
  return [255, 255, 255];
}
function contrast(el) {
  var a = lum(rgb(getComputedStyle(el).color)), b = lum(behind(el));
  return Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100;
}
function box(el) {
  if (!el) { return null; }
  var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
  return { text: text(el), seen: seen(el), top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom),
    w: Math.round(r.width), h: Math.round(r.height), color: cs.color, bg: cs.backgroundColor,
    size: cs.fontSize, weight: cs.fontWeight, contrast: seen(el) ? contrast(el) : null };
}
function finish() {
  out.pageWidth = document.documentElement.scrollWidth;
  if (window.parent !== window) { window.parent.postMessage(JSON.stringify(out), '*'); return; }
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}
function names(q) { return Array.prototype.map.call(q.querySelectorAll('input[name]'), function (i) { return i.name; }); }

window.addEventListener('load', function () { setTimeout(function () {
  if (P.indexOf('editor-') === 0) {
    var root = document.querySelector('[data-uc-questions]');
    out.notifications = text(document.querySelector('[data-uc-notifications-card] h2'));
    if (!root) { return finish(); }
    out.inLocation = !!root.closest('.uc-bento-card') && /Location/.test(text(root.closest('.uc-bento-card').querySelector('h2')));
    out.afterCapacity = (function () { var c = document.querySelector('[data-uc-capacity-row]'); return !!c && !!(c.compareDocumentPosition(root) & Node.DOCUMENT_POSITION_FOLLOWING); })();
    out.label = box(root.querySelector(':scope > .uc-field-label'));
    out.hint = box(root.querySelector(':scope > .uc-hint'));
    var list = root.querySelector('[data-uc-q-list]');
    var qs = function () { return list.querySelectorAll(':scope > [data-uc-q]'); };
    out.start = qs().length;
    out.inPersonSeen = Array.prototype.map.call(root.querySelectorAll('[data-uc-q-inperson]'), seen);
    var add = root.querySelector('[data-uc-q-add]');
    add.click();
    var last = qs()[qs().length - 1];
    out.added = { count: qs().length, names: names(last) };
    out.questionBox = box(last);
    out.optionRow = box(last.querySelector('[data-uc-o]'));
    last.querySelector('[data-uc-o-add]').click();
    out.optionAdded = names(last.querySelectorAll('[data-uc-o]')[1]);
    while (qs().length < 5 && seen(add)) { add.click(); }
    out.atFive = { count: qs().length, addSeen: seen(add) };
    qs()[0].querySelector('[data-uc-q-remove]').click();
    out.afterRemove = { count: qs().length, firstNames: names(qs()[0]).slice(0, 2), addSeen: seen(add) };
    var hy = document.querySelector('[data-uc-hybrid-toggle]');
    if (hy) {
      hy.checked = !hy.checked; hy.dispatchEvent(new Event('change', { bubbles: true }));
      out.inPersonAfterToggle = Array.prototype.map.call(root.querySelectorAll('[data-uc-q-inperson]'), seen);
    }
    return finish();
  }
  if (P.indexOf('form-') === 0) {
    var posted = null;
    jQuery.ajax = function (opts) { posted = opts.data; return { fail: function () {} }; };
    document.querySelector('.uc-rsvp-btn').click();
    return setTimeout(function () {
      var box0 = document.querySelector('#uc-rsvp-questions');
      out.shown = seen(box0);
      if (!out.shown) { return finish(); }
      out.heading = box(box0.querySelector('.uc-rsvp-q-heading'));
      out.legends = Array.prototype.map.call(box0.querySelectorAll('.uc-rsvp-q'), function (f) { return { text: text(f.querySelector('legend')), seen: seen(f) }; });
      var fmt = document.querySelector('#uc-rsvp-format'), email = document.querySelector('#uc-rsvp-email'), first = document.querySelector('#uc-rsvp-first-name');
      out.order = { afterFormat: !!(fmt.compareDocumentPosition(box0) & Node.DOCUMENT_POSITION_FOLLOWING), beforeName: !!(box0.compareDocumentPosition(first) & Node.DOCUMENT_POSITION_FOLLOWING), beforeEmail: !!(box0.compareDocumentPosition(email) & Node.DOCUMENT_POSITION_FOLLOWING) };
      out.more = box(box0.querySelector('.uc-rsvp-q-more-label'));
      out.moreInput = box(box0.querySelector('.uc-rsvp-q-more input'));
      out.choice = box(box0.querySelector('.uc-rsvp-q-choice'));
      out.legendBox = box(box0.querySelector('.uc-rsvp-q > legend'));
      out.modal = box(document.querySelector('.uc-rsvp-modal'));
      var ip = document.querySelector('#uc-rsvp-format input[value="in_person"]');
      if (ip) { ip.checked = true; ip.dispatchEvent(new Event('change', { bubbles: true })); out.afterInPerson = Array.prototype.map.call(box0.querySelectorAll('.uc-rsvp-q'), seen); }
      document.querySelector('#uc-rsvp-first-name').value = 'Lee';
      document.querySelector('#uc-rsvp-email').value = 'lee@example.org';
      document.querySelector('#uc-rsvp-submit-btn').click();
      out.requiredError = text(document.querySelector('#uc-rsvp-error'));
      out.postedWithout = posted;
      var allergy = box0.querySelector('input[value="oaaaaaaaaa2"]');
      allergy.checked = true;
      box0.querySelector('.uc-rsvp-q-more input[data-o="oaaaaaaaaa2"]').value = 'Peanuts';
      document.querySelector('#uc-rsvp-submit-btn').click();
      out.posted = posted ? { answers: posted.answers, more: posted.answers_more, format: posted.format } : null;
      finish();
    }, 400);
  }
  if (P.indexOf('rsvps-') === 0) {
    var strip = document.querySelector('[data-uc-q-totals]');
    out.strip = strip ? Array.prototype.map.call(strip.querySelectorAll('li'), text) : null;
    out.stripBox = box(strip);
    var counts = document.querySelector('.uc-rsvp-counts'), card = document.querySelector('.uc-card .uc-table');
    out.stripAbove = strip && card ? !!(strip.compareDocumentPosition(card) & Node.DOCUMENT_POSITION_FOLLOWING) && !!(counts.compareDocumentPosition(strip) & Node.DOCUMENT_POSITION_FOLLOWING) : null;
    var btns = document.querySelectorAll('[data-uc-rsvp-details]');
    out.detailButtons = btns.length;
    if (btns.length) {
      var b = btns[0], row = document.getElementById(b.getAttribute('aria-controls'));
      out.detailsBtn = box(b);
      out.closed = { rowSeen: seen(row), expanded: b.getAttribute('aria-expanded') };
      b.click();
      out.opened = { rowSeen: seen(row), expanded: b.getAttribute('aria-expanded'), text: text(row), dt: box(row.querySelector('dt')), more: box(row.querySelector('.uc-rsvp-answer-more')) };
      b.click();
      out.reclosed = { rowSeen: seen(row), expanded: b.getAttribute('aria-expanded') };
    }
    return finish();
  }
  if (P.indexOf('cancel-') === 0) {
    var form = document.querySelector('form[data-uc-confirm-cancel]:not([data-uc-confirm-reinstate])');
    var c = form ? form.querySelector('[data-uc-cancel-count]') : null;
    out.countText = text(c);
    out.attrs = c ? { people: c.getAttribute('data-uc-cancel-count'), waiting: c.getAttribute('data-uc-cancel-waiting') } : null;
    var d = form.closest('details'); if (d) { d.open = true; }
    form.querySelector('button[type="submit"]').click();
    return setTimeout(function () {
      var dlg = document.querySelector('dialog.uc-notify-modal[open]');
      out.dialog = dlg ? { lead: text(dlg.querySelector('.uc-confirm-msg')), buttons: Array.prototype.map.call(dlg.querySelectorAll('button'), text) } : null;
      finish();
    }, 300);
  }
  finish();
}, 500); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rq_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inject = '<script>window.RQ_PAGE=' . json_encode( $name ) . ';' . $probe . '</script>';
    $html = ( false !== stripos( $p['html'], '</body>' ) ) ? preg_replace( '#</body>#i', $inject . '</body>', $p['html'], 1 ) : $p['html'] . $inject;
    $files[ $name ] = __DIR__ . '/release-31062-live-' . $name . '.html';
    file_put_contents( $files[ $name ], $html );
    if ( $p['w'] < 504 ) {
        $inner = basename( $files[ $name ] );
        $files[ $name ] = __DIR__ . '/release-31062-live-' . $name . '-frame.html';
        file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
            . '<iframe src="' . $inner . '" style="display:block;width:' . (int) $p['w'] . 'px;height:2900px;border:0"></iframe>'
            . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
    }
}

if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    echo 'wrote ' . count( $files ) . " pages; --run drives them.\n";
    exit( $fails ? 1 : 0 );
}
$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
$g = array();
foreach ( $files as $name => $file ) {
    $w   = $pages[ $name ]['w'];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w ) . ',3000 --virtual-time-budget=10000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rq_check( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rq_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rq_check( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

/* DESIGN.md's tokens, as the browser reports them. */
$P_TEXT  = 'rgb(55, 52, 51)';     // --p-text #373433
$P_MUTED = 'rgb(107, 103, 100)';  // --p-muted #6B6764
$TEAL    = 'rgb(14, 118, 128)';   // --uc-accent-text #0E7680
$UC_TEXT = 'rgb(26, 29, 33)';     // --uc-text #1A1D21
$UC_SEC  = 'rgb(107, 114, 128)';  // --uc-secondary #6B7280
$P_BG    = 'rgb(245, 246, 247)';  // --p-bg #F5F6F7

/* ---- The editor. ---- */
foreach ( array( 'editor-add-desktop', 'editor-add-phone', 'editor-edit-desktop', 'editor-edit-phone' ) as $t ) {
    $add = 0 === strpos( $t, 'editor-add' );
    rq_check( 'Notifications' === $v( $t, 'notifications' ), "PLANT D: $t: no Notifications card: " . json_encode( $v( $t, 'notifications' ) ) );
    rq_check( true === $v( $t, 'inLocation' ) && true === $v( $t, 'afterCapacity' ), "$t: Questions for registrants is not with the RSVP settings, under Capacity" );
    $l = (array) $v( $t, 'label' ); $h = (array) $v( $t, 'hint' );
    rq_check( isset( $l['text'] ) && 'Questions for registrants' === $l['text'] && $P_TEXT === $l['color'] && '13px' === $l['size'] && '600' === $l['weight'], "$t: the section label is not the field label step in --p-text: " . json_encode( $l ) );
    rq_check( isset( $h['text'] ) && 'Ask registrants anything you need to know before the event.' === $h['text'] && $P_MUTED === $h['color'] && '12px' === $h['size'], "$t: the helper is not the helper step in --p-muted: " . json_encode( $h ) );
    rq_check( $add ? 0 === $v( $t, 'start' ) : 2 === $v( $t, 'start' ), "$t: starts with " . json_encode( $v( $t, 'start' ) ) . ' questions' );
    $a = (array) $v( $t, 'added' ); $n = $add ? 0 : 2;
    rq_check( isset( $a['count'] ) && $n + 1 === $a['count'] && in_array( "uc_questions[$n][text]", $a['names'], true ) && in_array( "uc_questions[$n][options][0][text]", $a['names'], true ),
        "$t: Add question did not add one named in order: " . json_encode( $a ) );
    rq_check( in_array( "uc_questions[$n][options][1][more]", (array) $v( $t, 'optionAdded' ), true ), "$t: Add option is not numbered after the first: " . json_encode( $v( $t, 'optionAdded' ) ) );
    rq_check( array( 'count' => 5, 'addSeen' => false ) === $v( $t, 'atFive' ), "$t: Add question does not stop at five: " . json_encode( $v( $t, 'atFive' ) ) );
    $r = (array) $v( $t, 'afterRemove' );
    rq_check( isset( $r['count'] ) && 4 === $r['count'] && true === $r['addSeen'] && 'uc_questions[0][id]' === $r['firstNames'][0], "$t: removing a question does not renumber or bring Add back: " . json_encode( $r ) );
    $qb = (array) $v( $t, 'questionBox' );
    rq_check( isset( $qb['right'] ) && $qb['right'] <= $pages[ $t ]['w'], "$t: a question runs off the screen" );
}
rq_check( array( true, true ) === $v( 'editor-edit-desktop', 'inPersonSeen' ), 'editor-edit-desktop: In person only is not shown on a hybrid event: ' . json_encode( $v( 'editor-edit-desktop', 'inPersonSeen' ) ) );
rq_check( ! in_array( true, (array) $v( 'editor-edit-desktop', 'inPersonAfterToggle' ), true ), 'editor-edit-desktop: In person only stays when hybrid is unticked' );
rq_check( in_array( true, (array) $v( 'editor-add-desktop', 'inPersonAfterToggle' ), true ), 'editor-add-desktop: In person only does not appear when hybrid is ticked' );

/* ---- The public form. ---- */
foreach ( array( 'form-desktop', 'form-phone' ) as $t ) {
    rq_check( true === $v( $t, 'shown' ), "$t: the questions are not on the form" );
    $hd = (array) $v( $t, 'heading' );
    rq_check( isset( $hd['text'] ) && 'Additional information' === $hd['text'] && $UC_TEXT === $hd['color'] && '16px' === $hd['size'] && $hd['contrast'] >= 4.5, "$t: the heading is not the subhead in --uc-text: " . json_encode( $hd ) );
    rq_check( array( 'afterFormat' => true, 'beforeName' => true, 'beforeEmail' => true ) === $v( $t, 'order' ), "$t: the section is not after the format and before the name and email: " . json_encode( $v( $t, 'order' ) ) );
    $lg = (array) $v( $t, 'legends' );
    rq_check( array( 'Any dietary needs? *', 'Will you park on site?' ) === array_column( $lg, 'text' ), "$t: the questions read " . json_encode( array_column( $lg, 'text' ) ) );
    rq_check( array( true, false ) === array_column( $lg, 'seen' ), "$t: the in-person question shows before In person is picked: " . json_encode( array_column( $lg, 'seen' ) ) );
    rq_check( array( true, true ) === $v( $t, 'afterInPerson' ), "$t: picking In person does not show its question" );
    $mo = (array) $v( $t, 'more' );
    rq_check( isset( $mo['text'] ) && 'Additional info' === $mo['text'] && $UC_SEC === $mo['color'] && $mo['contrast'] >= 4.5, "$t: the Additional info label is not --uc-secondary: " . json_encode( $mo ) );
    $ch = (array) $v( $t, 'choice' ); $mi = (array) $v( $t, 'moreInput' );
    rq_check( isset( $ch['weight'] ) && '400' === $ch['weight'] && $UC_TEXT === $ch['color'], "$t: an option's words are not body weight in --uc-text: " . json_encode( $ch ) );
    rq_check( isset( $mi['right'], $g[ $t ]['modal']['right'] ) && $mi['right'] <= $g[ $t ]['modal']['right'], "$t: the Additional info line runs out of the modal" );
    rq_check( 'Answer every required question to register.' === $v( $t, 'requiredError' ) && null === $v( $t, 'postedWithout' ), "$t: an unanswered required question did not stop the form: " . json_encode( array( $v( $t, 'requiredError' ), $v( $t, 'postedWithout' ) ) ) );
    $po = (array) $v( $t, 'posted' );
    rq_check( isset( $po['answers'] ) && '{"qaaaaaaaaaa":["oaaaaaaaaa2"]}' === $po['answers'] && '{"qaaaaaaaaaa":{"oaaaaaaaaa2":"Peanuts"}}' === $po['more'], "$t: the answers were not posted: " . json_encode( $po ) );
}
$hs = (array) $v( 'form-es', 'heading' ); $ms = (array) $v( 'form-es', 'more' );
rq_check( isset( $hs['text'], $ms['text'] ) && 'Información adicional' === $hs['text'] && 'Más información' === $ms['text'], 'form-es: the heading and label are not Spanish on a Spanish event: ' . json_encode( array( $hs, $ms ) ) );
rq_check( false === $v( 'form-none', 'shown' ), 'form-none: an event with no questions shows the section' );

/* ---- The registrations list. ---- */
foreach ( array( 'rsvps-q-desktop', 'rsvps-q-phone' ) as $t ) {
    rq_check( array( 'Any dietary needs?: 2 of 2 answered. Vegetarian 1, Allergy 1' ) === $v( $t, 'strip' ), "$t: the strip reads " . json_encode( $v( $t, 'strip' ) ) );
    rq_check( true === $v( $t, 'stripAbove' ), "$t: the strip is not between the counts and the list" );
    $sb = (array) $v( $t, 'stripBox' );
    rq_check( isset( $sb['color'] ) && $P_TEXT === $sb['color'] && '14px' === $sb['size'] && '400' === $sb['weight'], "$t: the strip is not body in --p-text: " . json_encode( $sb ) );
    rq_check( 2 === $v( $t, 'detailButtons' ), "$t: " . json_encode( $v( $t, 'detailButtons' ) ) . ' Details buttons, wanted one per confirmed person who answered' );
    $db = (array) $v( $t, 'detailsBtn' );
    rq_check( isset( $db['text'] ) && 'Details' === $db['text'] && $TEAL === $db['color'] && $db['contrast'] >= 4.5, "$t: Details is not a quiet action in --uc-accent-text: " . json_encode( $db ) );
    rq_check( array( 'rowSeen' => false, 'expanded' => 'false' ) === $v( $t, 'closed' ), "$t: Details is not closed by default" );
    $op = (array) $v( $t, 'opened' );
    rq_check( isset( $op['rowSeen'] ) && true === $op['rowSeen'] && 'true' === $op['expanded'] && false !== strpos( $op['text'], 'Allergy' ) && false !== strpos( $op['text'], 'Peanuts' ), "$t: Details did not open the answers and the additional info: " . json_encode( $op ) );
    rq_check( isset( $op['more']['color'] ) && $P_MUTED === $op['more']['color'], "$t: the additional info is not --p-muted" );
    rq_check( array( 'rowSeen' => false, 'expanded' => 'false' ) === $v( $t, 'reclosed' ), "$t: Details did not close again" );
}
rq_check( null === $v( 'rsvps-none-desktop', 'strip' ) && 0 === $v( 'rsvps-none-desktop', 'detailButtons' ), 'rsvps-none-desktop: an event with no questions shows a strip or Details' );

/* ---- The cancel dialog. ---- */
rq_check( array( 'people' => '0', 'waiting' => '2' ) === $v( 'cancel-desktop', 'attrs' ), 'PLANT C: cancel-desktop: the waitlist is not counted on the card: ' . json_encode( $v( 'cancel-desktop', 'attrs' ) ) );
rq_check( 'Nobody is registered. 2 people are on the waitlist. You will be asked whether to email them.' === $v( 'cancel-desktop', 'countText' ), 'cancel-desktop: the card reads ' . json_encode( $v( 'cancel-desktop', 'countText' ) ) );
$dl = (array) $v( 'cancel-desktop', 'dialog' );
rq_check( isset( $dl['lead'] ) && 0 === strpos( $dl['lead'], 'Nobody is registered. 2 people are on the waitlist.' ) && in_array( 'Cancel and email them all', $dl['buttons'], true ),
    'PLANT C: cancel-desktop: the dialog does not ask to email the waitlist: ' . json_encode( $dl ) );

if ( $fails ) {
    echo 'RELEASE 3.106.2 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.106.2 live: the questions in the editor, on the form and on the registrations list, the Notifications card on Add event, and the cancel dialog counting the waitlist, read in Chrome at desktop and 390px.\n";
