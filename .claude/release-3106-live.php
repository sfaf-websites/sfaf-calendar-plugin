<?php
/**
 * 3.106.0 IN A REAL BROWSER: THE EMAIL TEMPLATES SCREEN, THE VOLUNTEER BUTTON,
 * THE WAITLIST BUTTON AND THE RSVP LIST'S WAITLIST, AT DESKTOP AND PHONE WIDTH.
 *
 *     php .claude/release-3106-live.php          writes the pages
 *     php .claude/release-3106-live.php --run    writes them, drives Chrome, checks
 *
 * The real renderers through wp-kit.php with the real portal.css, portal.js,
 * calendar.css and calendar.js. Every check is read off the page after Chrome
 * has laid it out and the scripts have run, and every colour is compared with
 * the DESIGN.md token it should be.
 *
 *   tpl-*      the list, the language, the preview: choosing a message or a
 *              language changes the subject and the preview with no page load;
 *              Edit opens chip editors; one Backspace arms a chip and a second
 *              removes it; the token bar offers only the tokens the message
 *              can fill and inserts a chip; Save posts the text with tokens in
 *              braces, and the real route stores it; Reset asks, then posts.
 *   vol-*      Volunteer for this event: Register's shape, green-ink fill at
 *              4.5:1 or better, a new tab with noopener and the hidden note.
 *   vol-empty  no address: no element, and the card is no taller than without.
 *   wait-*     a full event's button and form say Join the waitlist; on a
 *              hybrid event with in person full, only that format does.
 *   rsvps-*    the counts, the Waitlist card, positions, the pills in their
 *              measured colours, Confirm and Remove on every row, and no
 *              sideways scroll of the page.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rl_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); }
    catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}

/* ---- The world. -------------------------------------------------------- */
function rl_query( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' === $p->post_type ) { $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; }
    }
    return $out;
}
$GLOBALS['kit_query'] = 'rl_query';

function rl_event( $title, $meta ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
$vol   = rl_event( 'Coffee social', array( '_uc_volunteer_url' => 'https://www.sfaf.org/volunteer/coffee', '_uc_capacity' => '20' ) );
$novol = rl_event( 'Coffee social', array( '_uc_capacity' => '20' ) );
$full  = rl_event( 'Support group', array( '_uc_capacity' => '2' ) );
$hyb   = rl_event( 'Hybrid talk', array( SFAF_Online::META_HYBRID => '1', '_uc_capacity' => '2', '_uc_capacity_online' => '50' ) );

/* The registrations table: the full event has two confirmed; the hybrid
   event's in person places are both taken and online is empty. */
$GLOBALS['rl_rows'] = array(
    array( 'id' => 1, 'event_id' => $full, 'name' => 'Ana Alvarez', 'first_name' => 'Ana', 'last_name' => 'Alvarez', 'email' => 'ana@example.org', 'phone' => '', 'status' => 'confirmed', 'token' => 'a', 'format' => '', 'created_at' => '2026-09-20 10:00:00', 'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null ),
    array( 'id' => 2, 'event_id' => $full, 'name' => 'Ben Brooks', 'first_name' => 'Ben', 'last_name' => 'Brooks', 'email' => 'ben@example.org', 'phone' => '', 'status' => 'confirmed', 'token' => 'b', 'format' => '', 'created_at' => '2026-09-21 10:00:00', 'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null ),
    array( 'id' => 3, 'event_id' => $full, 'name' => 'Cam Chen', 'first_name' => 'Cam', 'last_name' => 'Chen', 'email' => 'cam@example.org', 'phone' => '', 'status' => 'offered', 'token' => 'c', 'format' => '', 'created_at' => '2026-09-22 10:00:00', 'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => 'ot', 'offered_at' => '2026-09-29 10:00:00', 'offer_expires' => '2026-10-01 10:00:00' ),
    array( 'id' => 4, 'event_id' => $full, 'name' => 'Dee Diaz', 'first_name' => 'Dee', 'last_name' => 'Diaz', 'email' => '', 'phone' => '(415) 555-0100', 'status' => 'offered_manual', 'token' => 'd', 'format' => '', 'created_at' => '2026-09-23 10:00:00', 'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null ),
    array( 'id' => 5, 'event_id' => $full, 'name' => 'Eve Evans', 'first_name' => 'Eve', 'last_name' => 'Evans', 'email' => 'eve@example.org', 'phone' => '', 'status' => 'waitlisted', 'token' => 'e', 'format' => '', 'created_at' => '2026-09-24 10:00:00', 'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null ),
    array( 'id' => 6, 'event_id' => $full, 'name' => 'Fay Fox', 'first_name' => 'Fay', 'last_name' => 'Fox', 'email' => 'fay@example.org', 'phone' => '', 'status' => 'expired', 'token' => 'f', 'format' => '', 'created_at' => '2026-09-19 10:00:00', 'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => '2026-09-28 10:00:00' ),
    array( 'id' => 7, 'event_id' => $hyb, 'name' => 'Gil Gray', 'first_name' => 'Gil', 'last_name' => 'Gray', 'email' => 'gil@example.org', 'phone' => '', 'status' => 'confirmed', 'token' => 'g', 'format' => 'in_person', 'created_at' => '2026-09-20 10:00:00', 'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null ),
    array( 'id' => 8, 'event_id' => $hyb, 'name' => 'Hal Hunt', 'first_name' => 'Hal', 'last_name' => 'Hunt', 'email' => 'hal@example.org', 'phone' => '', 'status' => 'confirmed', 'token' => 'h', 'format' => 'in_person', 'created_at' => '2026-09-20 11:00:00', 'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null ),
);

/* Answers the SQL the code under test runs, from the rows above, by reading
   the prepared statement: the event, the status and the format it names. */
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
    $rows = $GLOBALS['rl_rows'];
    if ( preg_match( '/event_id\s*=\s*(\d+)/', $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r['event_id'] === (int) $m[1]; } );
    }
    if ( preg_match( "/status\s*=\s*'([a-z_]+)'/", $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['status'] === $m[1]; } );
    } elseif ( preg_match( "/status\s+IN\s*\(([^)]*)\)/i", $sql, $m ) ) {
        preg_match_all( "/'([a-z_]+)'/", $m[1], $in );
        $rows = array_filter( $rows, function ( $r ) use ( $in ) { return in_array( $r['status'], $in[1], true ); } );
    }
    if ( preg_match( "/format\s*=\s*'([a-z_]*)'/", $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['format'] === $m[1]; } );
    }
    if ( preg_match( "/offer_expires\s*>\s*'([^']+)'/", $sql, $m ) ) {
        $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['offer_expires'] && $r['offer_expires'] > $m[1]; } );
    }
    if ( 'get_var' === $method ) { return (string) count( $rows ); }
    if ( 'get_results' === $method ) {
        return array_map( function ( $r ) { $r['post_title'] = get_the_title( $r['event_id'] ); $r['event_title'] = ''; return (object) $r; }, array_values( $rows ) );
    }
    return null;
};

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function rl_screen( $method, $args, $get = array() ) {
    return rl_capture( function () use ( $method, $args, $get ) { $_GET = $get; kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args ); } );
}
/* The event page's sidebar card, in the wrappers the template gives it, so
   the cascade the button meets is the one on the site. */
function rl_single( $id ) {
    $body = '<div class="uc-single"><div class="uc-single-inner"><div class="uc-single-grid"><div class="uc-single-main"></div>'
        . '<aside class="uc-single-sidebar"><div class="uc-single-card">'
        . sfaf_rsvp_block( $id ) . sfaf_social_share_buttons( $id ) . sfaf_volunteer_block( $id )
        . '</div></aside></div></div></div>';
    return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width\">"
        . "<link rel=\"stylesheet\" href=\"../public/css/calendar.css\"></head><body style=\"margin:0;background:#fff\">" . $body
        . "<script src=\"vendor/jquery-3.7.1.min.js\"></script><script>window.ucData={ajaxUrl:''};</script>"
        . "<script src=\"../public/js/calendar.js\"></script></body></html>";
}

$tpl   = rl_screen( 'render_email_templates', array( $user ) );
$rsvps = rl_screen( 'render_rsvps', array( $user ), array( 'event_id' => $full ) );
$pages = array(
    'tpl-desktop'  => array( 'w' => 1280, 'html' => $tpl ),
    'tpl-phone'    => array( 'w' => 390,  'html' => $tpl ),
    'vol-desktop'  => array( 'w' => 1280, 'html' => rl_single( $vol ) ),
    'vol-phone'    => array( 'w' => 390,  'html' => rl_single( $vol ) ),
    'vol-empty'    => array( 'w' => 1280, 'html' => rl_single( $novol ) ),
    'wait-desktop' => array( 'w' => 1280, 'html' => rl_single( $full ) ),
    'wait-phone'   => array( 'w' => 390,  'html' => rl_single( $full ) ),
    'wait-hybrid'  => array( 'w' => 1280, 'html' => rl_single( $hyb ) ),
    'rsvps-desktop' => array( 'w' => 1280, 'html' => $rsvps ),
    'rsvps-phone'   => array( 'w' => 390,  'html' => $rsvps ),
);

$probe = <<<'JS'
(function () {
var P = window.RL_PAGE, W = window.RL_W, out = { page: P, errors: [], posted: null, dialogs: [] };
window.__noReload = 'still here';
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; }
function rgb(s) { var m = String(s).match(/\d+(\.\d+)?/g); return m ? m.slice(0, 4).map(Number) : null; }
function lum(c) { return [0, 1, 2].map(function (i) { var v = c[i] / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function behind(el) {
  for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
    var cs = getComputedStyle(n), c = rgb(cs.backgroundColor);
    if (cs.backgroundImage !== 'none') { return 'PATTERN'; }
    if (c && (c.length < 4 || c[3] > 0)) { return c; }
  }
  return [255, 255, 255];
}
function contrast(el) {
  var fg = rgb(getComputedStyle(el).color), bg = behind(el);
  if (bg === 'PATTERN') { return 'on a pattern'; }
  var a = lum(fg), b = lum(bg);
  return Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100;
}
function box(el) {
  if (!el) { return null; }
  var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
  return { text: text(el), seen: seen(el), top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom),
    w: Math.round(r.width), h: Math.round(r.height), color: cs.color, bg: cs.backgroundColor, radius: cs.borderRadius,
    font: cs.fontSize + ' ' + cs.fontWeight + ' ' + cs.fontFamily.split(',')[0], pad: cs.padding, tt: cs.textTransform, contrast: seen(el) ? contrast(el) : null };
}
function pairs(fd) { var a = []; fd.forEach(function (v, k) { if (typeof v === 'string') { a.push([k, v]); } }); return a; }
document.addEventListener('submit', function (e) {
  if (e.target.closest('dialog') || e.target.method === 'dialog') { return; }
  if (e.defaultPrevented) { return; }
  var fd; try { fd = new FormData(e.target, e.submitter); } catch (x) { fd = new FormData(e.target); }
  out.posted = pairs(fd); e.preventDefault();
});
function answer(value, then) {
  var d = document.querySelector('dialog.uc-confirm-modal[open]');
  if (!d) { then(); return; }
  out.dialogs.push(text(d.querySelector('.uc-confirm-msg')));
  var delivered = false;
  d.addEventListener('close', function () { delivered = true; });
  d.querySelector('button[value="' + value + '"]').click();
  setTimeout(function () {
    /* Headless does not deliver this close under a virtual time budget; see
       closure-live.php. Sent here, and counted. */
    if (!delivered && !d.open && d.isConnected) { out.synthClose = (out.synthClose || 0) + 1; d.dispatchEvent(new Event('close')); }
    setTimeout(then, 150);
  }, 150);
}
function key(el, k) { el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true })); }
function finish() {
  out.pageWidth = document.documentElement.scrollWidth; out.viewport = window.innerWidth;
  out.stillHere = window.__noReload;
  /* A phone page runs in a frame of the phone's width, because headless
     Chrome will not open a window narrower than 504px; the frame reports to
     the page around it, which is the one Chrome dumps. */
  if (window.parent !== window) { window.parent.postMessage(JSON.stringify(out), '*'); return; }
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}

window.addEventListener('load', function () { setTimeout(function () {
  if (P.indexOf('tpl-') === 0) {
    var root = document.querySelector('[data-uc-tpl]');
    var subj = function () { return text(root.querySelector('[data-uc-tpl-subject]')); };
    var src = function () { return root.querySelector('[data-uc-tpl-preview]').getAttribute('srcdoc') || ''; };
    out.nav = text(document.querySelector('a[href*="email-templates"]'));
    out.first = { subject: subj(), srcLen: src().length, pressed: text(root.querySelector('[aria-pressed="true"]')) };
    out.list = box(root.querySelector('.uc-tpl-list'));
    out.main = box(root.querySelector('.uc-tpl-main'));
    out.preview = box(root.querySelector('[data-uc-tpl-preview]'));
    out.pick = box(root.querySelector('.uc-tpl-pick[aria-pressed="true"]'));
    out.variantIndent = (function () {
      var v = root.querySelector('.uc-tpl-variant'), g = root.querySelector('.uc-tpl-group');
      return v && g ? Math.round(v.getBoundingClientRect().left - g.getBoundingClientRect().left) : null;
    })();
    out.picks = root.querySelectorAll('[data-uc-tpl-pick]').length;
    root.querySelector('[data-uc-tpl-pick="waitlist|default"]').click();
    out.waitlist = { subject: subj(), srcHasPosition: src().indexOf('number 3') !== -1, pressed: text(root.querySelector('[aria-pressed="true"]')) };
    var lang = root.querySelector('[data-uc-tpl-lang]');
    lang.value = 'es'; lang.dispatchEvent(new Event('change', { bubbles: true }));
    out.spanish = { subject: subj(), srcSpanish: src().indexOf('lista de espera') !== -1 };
    root.querySelector('[data-uc-tpl-edit]').click();
    var intro = root.querySelector('[data-uc-tpl-editor="intro"]');
    out.editors = root.querySelectorAll('[data-uc-tpl-editor]').length;
    out.textareasHidden = Array.prototype.every.call(root.querySelectorAll('[data-uc-tpl-text]'), function (t) { return !seen(t); });
    out.tokensShown = Array.prototype.filter.call(root.querySelectorAll('[data-uc-tpl-token]'), seen).map(function (b) { return b.getAttribute('data-uc-tpl-token'); });
    var chipEl = intro.querySelector('[data-token]');
    out.chip = box(chipEl);
    out.chipEditable = chipEl ? chipEl.getAttribute('contenteditable') : null;
    out.chipsAtStart = intro.querySelectorAll('[data-token]').length;
    /* The caret just after the first chip; Backspace twice. */
    var r = document.createRange(); r.setStartAfter(chipEl); r.collapse(true);
    var sel = window.getSelection(); intro.focus(); sel.removeAllRanges(); sel.addRange(r);
    key(intro, 'Backspace');
    out.afterOne = { chips: intro.querySelectorAll('[data-token]').length, armed: intro.querySelectorAll('.is-armed').length,
      armedEdge: intro.querySelector('.is-armed') ? getComputedStyle(intro.querySelector('.is-armed')).borderTopColor : '' };
    key(intro, 'Backspace');
    out.afterTwo = { chips: intro.querySelectorAll('[data-token]').length };
    /* A selection across a chip cannot be typed over. */
    var r2 = document.createRange(); r2.selectNodeContents(intro); sel.removeAllRanges(); sel.addRange(r2);
    var ev = new InputEvent('beforeinput', { inputType: 'insertText', data: 'x', bubbles: true, cancelable: true });
    intro.dispatchEvent(ev);
    out.overChipRefused = ev.defaultPrevented;
    /* The token bar puts a chip at the caret. */
    var r3 = document.createRange(); r3.selectNodeContents(intro); r3.collapse(false); sel.removeAllRanges(); sel.addRange(r3);
    root.querySelector('[data-uc-tpl-token="position"]').click();
    out.afterToken = { chips: intro.querySelectorAll('[data-token]').length, box: root.querySelector('[data-uc-tpl-text="intro"]').value };
    out.tokenBtn = box(root.querySelector('[data-uc-tpl-token="position"]'));
    out.editor = box(intro);
    root.querySelector('[data-uc-tpl-form] button[value="save"]').click();
    out.saved = out.posted; out.posted = null;
    root.querySelector('[data-uc-tpl-reset]').click();
    return setTimeout(function () { answer('ok', function () { out.reset = out.posted; finish(); }); }, 200);
  }
  if (P.indexOf('vol-') === 0 || P.indexOf('wait-') === 0) {
    out.card = box(document.querySelector('.uc-single-card'));
    out.share = box(document.querySelector('.uc-single-card .uc-share, .uc-single-card [class*="share"]'));
    out.vol = box(document.querySelector('.uc-actionbtn-volunteer'));
    out.volWrap = document.querySelectorAll('.uc-volunteer').length;
    var v = document.querySelector('.uc-actionbtn-volunteer');
    if (v) {
      out.volAttrs = { target: v.getAttribute('target'), rel: v.getAttribute('rel'), href: v.getAttribute('href') };
      var note = Array.prototype.filter.call(v.querySelectorAll('*'), function (n) { return /opens in a new tab/.test(n.textContent); })[0];
      out.volNote = note ? { text: text(note), w: Math.round(note.getBoundingClientRect().width), h: Math.round(note.getBoundingClientRect().height) } : null;
    }
    var reg = document.querySelector('.uc-rsvp-btn');
    out.reg = box(reg);
    if (P.indexOf('wait-') !== 0 || !reg) { return finish(); }
    reg.click();
    return setTimeout(function () {
      out.modalHeading = text(document.querySelector('#uc-rsvp-heading'));
      out.modalSubmit = text(document.querySelector('#uc-rsvp-submit-btn'));
      out.submitBox = box(document.querySelector('#uc-rsvp-submit-btn'));
      if (P === 'wait-hybrid') {
        var pick = function (f) { var i = document.querySelector('#uc-rsvp-format input[value="' + f + '"]'); if (i) { i.disabled = false; i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); } return !!i; };
        out.inPerson = pick('in_person') ? { heading: text(document.querySelector('#uc-rsvp-heading')), submit: text(document.querySelector('#uc-rsvp-submit-btn')) } : null;
        out.online = pick('online') ? { heading: text(document.querySelector('#uc-rsvp-heading')), submit: text(document.querySelector('#uc-rsvp-submit-btn')) } : null;
      }
      finish();
    }, 400);
  }
  if (P.indexOf('rsvps-') === 0) {
    out.counts = text(document.querySelector('.uc-rsvp-counts'));
    var wl = document.querySelector('[data-uc-waitlist]');
    out.card = box(wl);
    out.heading = wl ? text(wl.querySelector('h2')) : '';
    out.rows = wl ? Array.prototype.map.call(wl.querySelectorAll('[data-uc-waitlist-row]'), function (tr) {
      var tds = tr.querySelectorAll('td');
      return { pos: text(tds[0]), name: text(tds[1]), pill: box(tr.querySelector('.uc-pill')), confirm: seen(tr.querySelector('[data-uc-waitlist-confirm]')), remove: seen(tr.querySelector('[data-uc-waitlist-remove]')) };
    }) : [];
    return finish();
  }
  finish();
}, 500); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rl_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inject = '<script>window.RL_PAGE=' . json_encode( $name ) . ';window.RL_W=' . (int) $p['w'] . ';' . $probe . '</script>';
    $html = ( false !== stripos( $p['html'], '</body>' ) ) ? preg_replace( '#</body>#i', $inject . '</body>', $p['html'], 1 ) : $p['html'] . $inject;
    $files[ $name ] = __DIR__ . '/release-3106-live-' . $name . '.html';
    file_put_contents( $files[ $name ], $html );
    if ( $p['w'] < 504 ) {
        $inner = basename( $files[ $name ] );
        $files[ $name ] = __DIR__ . '/release-3106-live-' . $name . '-frame.html';
        file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
            . '<iframe src="' . $inner . '" style="display:block;width:' . (int) $p['w'] . 'px;height:2900px;border:0"></iframe>'
            . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
    }
}
echo 'wrote ' . count( $files ) . " pages\n";

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $g = array();
    foreach ( $files as $name => $file ) {
        $w   = $pages[ $name ]['w'];
        $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w ) . ',3000 --virtual-time-budget=10000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
        if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl_check( false, "$name: Chrome returned no probe block" ); continue; }
        $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        rl_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
        rl_check( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
        if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
    }
    $v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

    /* DESIGN.md's tokens, as the browser reports them. */
    $TEAL_INK = 'rgb(14, 118, 128)';    // --uc-accent-text #0E7680
    $BAND     = 'rgb(232, 249, 250)';   // --uc-band #E8F9FA
    $GREEN    = 'rgb(70, 102, 31)';     // --uc-green-ink #46661F
    $DANGER   = 'rgb(192, 57, 43)';     // --p-danger-text #c0392b
    $AMBER    = 'rgb(146, 64, 14)';     // the system amber #92400E
    $AMBER_BG = 'rgb(255, 251, 235)';   // on #FFFBEB

    /* ---- D. The Templates screen. ---- */
    foreach ( array( 'tpl-desktop', 'tpl-phone' ) as $t ) {
        $first = (array) $v( $t, 'first' );
        rl_check( 'Email Templates' === $v( $t, 'nav' ), "$t: the sidebar has no Email Templates entry: " . json_encode( $v( $t, 'nav' ) ) );
        rl_check( isset( $first['subject'] ) && 0 === strpos( $first['subject'], 'You are registered for' ) && $first['srcLen'] > 500, "$t: the first message and its preview are not drawn: " . json_encode( $first ) );
        rl_check( (int) $v( $t, 'picks' ) >= 20, "$t: the list has " . (int) $v( $t, 'picks' ) . ' entries' );
        rl_check( (int) $v( $t, 'variantIndent' ) >= 12, "$t: variants are not indented under their message: " . json_encode( $v( $t, 'variantIndent' ) ) );
        $wl = (array) $v( $t, 'waitlist' );
        rl_check( isset( $wl['subject'] ) && 'You are on the waitlist for Coffee and Conversation' === $wl['subject'] && $wl['srcHasPosition'], "$t: choosing Waitlist does not change the subject and preview: " . json_encode( $wl ) );
        $es = (array) $v( $t, 'spanish' );
        rl_check( isset( $es['subject'] ) && 'Está en la lista de espera de Coffee and Conversation' === $es['subject'] && $es['srcSpanish'], "$t: choosing Spanish does not change the subject and preview: " . json_encode( $es ) );
        rl_check( 'still here' === $v( $t, 'stillHere' ), "$t: switching reloaded the page" );
        rl_check( 3 === $v( $t, 'editors' ) && true === $v( $t, 'textareasHidden' ), "$t: Edit does not replace the three boxes with chip editors" );
        $chip = (array) $v( $t, 'chip' );
        rl_check( isset( $chip['color'] ) && $TEAL_INK === $chip['color'] && $BAND === $chip['bg'], "$t: a chip is not --uc-accent-text on --uc-band: " . json_encode( $chip ) );
        rl_check( isset( $chip['contrast'] ) && $chip['contrast'] >= 4.5, "$t: a chip measures " . json_encode( isset( $chip['contrast'] ) ? $chip['contrast'] : null ) );
        rl_check( 'false' === $v( $t, 'chipEditable' ), "$t: a chip can be typed into" );
        $n  = (int) $v( $t, 'chipsAtStart' );
        $a1 = (array) $v( $t, 'afterOne' );
        rl_check( isset( $a1['chips'] ) && $n === $a1['chips'] && 1 === $a1['armed'] && $DANGER === $a1['armedEdge'], "PLANT: $t: one Backspace did not arm the chip, in --p-danger-text: " . json_encode( $a1 ) );
        rl_check( $n - 1 === (int) $v( $t, 'afterTwo' )['chips'], "$t: a second Backspace did not remove the chip" );
        rl_check( true === $v( $t, 'overChipRefused' ), "$t: typing over a selection holding a chip is allowed" );
        $shown = (array) $v( $t, 'tokensShown' );
        rl_check( in_array( 'position', $shown, true ) && in_array( 'cancel_link', $shown, true ) && ! in_array( 'confirm_link', $shown, true ) && ! in_array( 'meeting_link', $shown, true ) && ! in_array( 'expiry', $shown, true ),
            "$t: the token bar for Waitlist offers " . json_encode( $shown ) );
        $at = (array) $v( $t, 'afterToken' );
        rl_check( isset( $at['chips'] ) && $n === $at['chips'] && false !== strpos( $at['box'], '{position}' ), "$t: the token bar did not insert a chip into the text that posts: " . json_encode( $at ) );
        $tb = (array) $v( $t, 'tokenBtn' );
        rl_check( isset( $tb['h'] ) && $tb['h'] >= 32, "$t: a token button is " . ( isset( $tb['h'] ) ? $tb['h'] : '?' ) . 'px tall' );
        $saved = array_column( (array) $v( $t, 'saved' ), 1, 0 );
        rl_check( isset( $saved['tpl_do'], $saved['tpl_key'], $saved['tpl_lang'], $saved['tpl_intro'] ) && 'save' === $saved['tpl_do'] && 'waitlist' === $saved['tpl_key'] && 'es' === $saved['tpl_lang'] && false !== strpos( $saved['tpl_intro'], '{position}' ),
            "$t: Save did not post the Spanish waitlist text with its tokens: " . json_encode( $saved ) );
        rl_check( array( 'Put this message back to the shipped text? Your edits to it are removed.' ) === (array) $v( $t, 'dialogs' ), "$t: Reset did not ask once: " . json_encode( $v( $t, 'dialogs' ) ) );
        $reset = array_column( (array) $v( $t, 'reset' ), 1, 0 );
        rl_check( isset( $reset['tpl_do'] ) && 'reset' === $reset['tpl_do'], "$t: Reset did not post tpl_do=reset" );
    }
    $ld = (array) $v( 'tpl-desktop', 'list' ); $md = (array) $v( 'tpl-desktop', 'main' );
    rl_check( isset( $ld['right'], $md['left'] ) && $md['left'] > $ld['right'] && abs( $md['top'] - $ld['top'] ) < 8, 'tpl-desktop: the list and the preview are not side by side' );
    $lp = (array) $v( 'tpl-phone', 'list' ); $mp = (array) $v( 'tpl-phone', 'main' );
    rl_check( isset( $lp['bottom'], $mp['top'] ) && $mp['top'] >= $lp['bottom'] && abs( $mp['left'] - $lp['left'] ) < 4, 'tpl-phone: the list and the preview do not stack, list first' );
    $pd = (array) $v( 'tpl-desktop', 'pick' );
    rl_check( isset( $pd['color'] ) && $TEAL_INK === $pd['color'] && $BAND === $pd['bg'] && $pd['contrast'] >= 4.5, 'tpl-desktop: the chosen message is not --uc-accent-text on --uc-band: ' . json_encode( $pd ) );

    /* The real route stores what the browser posted, and reset removes it. */
    $GLOBALS['kit_redirect_throws'] = true;
    $_POST = $saved + array( 'uc_nonce' => 'nonce' ); $_GET = array();
    try { kit_call( 'SFAF_Portal', 'dispatch_post', $portal, array( 'save_email_template' ) ); } catch ( KitRedirect $r ) { $went = $r->getMessage(); }
    $stored = get_option( 'sfaf_email_waitlist_es' );
    rl_check( is_array( $stored ) && isset( $stored['default']['intro'] ) && false !== strpos( $stored['default']['intro'], '{position}' ), 'the posted Save did not store the Spanish waitlist text: ' . json_encode( $stored ) );
    rl_check( isset( $went ) && false !== strpos( $went, 'msg=tpl_saved' ), 'the Save went to ' . ( isset( $went ) ? $went : 'nowhere' ) );
    $_POST = $reset + array( 'uc_nonce' => 'nonce' );
    try { kit_call( 'SFAF_Portal', 'dispatch_post', $portal, array( 'save_email_template' ) ); } catch ( KitRedirect $r ) { /* back to the screen */ }
    rl_check( false === get_option( 'sfaf_email_waitlist_es', false ), 'the posted Reset did not remove the stored text' );

    /* ---- E. The Volunteer button. ---- */
    foreach ( array( 'vol-desktop', 'vol-phone' ) as $t ) {
        $vb = (array) $v( $t, 'vol' ); $rb = (array) $v( $t, 'reg' );
        rl_check( isset( $vb['text'] ) && 0 === strpos( $vb['text'], 'Volunteer for this event' ) && $vb['seen'], "PLANT E: $t: no Volunteer button: " . json_encode( $vb ) );
        rl_check( isset( $vb['h'], $rb['h'] ) && $vb['h'] === $rb['h'] && $vb['radius'] === $rb['radius'] && $vb['font'] === $rb['font'] && $vb['pad'] === $rb['pad'],
            "PLANT E: $t: the Volunteer button is not Register's shape: " . json_encode( array( 'vol' => $vb, 'reg' => $rb ) ) );
        // Width is the label's, as Register's is: RSVP and Volunteer for this event are different lengths.
        rl_check( isset( $vb['right'] ) && $vb['right'] <= $pages[ $t ]['w'], "$t: the Volunteer button runs off the screen" );
        rl_check( isset( $vb['bg'] ) && $GREEN === $vb['bg'] && 'rgb(255, 255, 255)' === $vb['color'], "PLANT E: $t: the Volunteer button is not white on --uc-green-ink: " . json_encode( $vb ) );
        rl_check( isset( $vb['tt'] ) && 'none' === $vb['tt'], "$t: the Volunteer button renders in " . ( isset( $vb['tt'] ) ? $vb['tt'] : '?' ) );
        rl_check( isset( $vb['contrast'] ) && $vb['contrast'] >= 4.5, "$t: the Volunteer button measures " . json_encode( isset( $vb['contrast'] ) ? $vb['contrast'] : null ) );
        rl_check( isset( $vb['top'], $g[ $t ]['share']['bottom'] ) && $vb['top'] > $g[ $t ]['share']['bottom'], "$t: the Volunteer button is not under the share buttons" );
        $at = (array) $v( $t, 'volAttrs' );
        rl_check( isset( $at['target'] ) && '_blank' === $at['target'] && false !== strpos( (string) $at['rel'], 'noopener' ) && 'https://www.sfaf.org/volunteer/coffee' === $at['href'],
            "$t: the Volunteer link does not open the page in a new tab with noopener: " . json_encode( $at ) );
        $note = (array) $v( $t, 'volNote' );
        rl_check( isset( $note['text'] ) && '(opens in a new tab)' === $note['text'] && $note['w'] <= 1 && $note['h'] <= 1, "$t: the new tab note is missing or visible: " . json_encode( $note ) );
    }
    rl_check( 0 === $v( 'vol-empty', 'volWrap' ), 'PLANT E: vol-empty: an event with no address draws a Volunteer element' );
    $cd = (array) $v( 'vol-desktop', 'card' ); $ce = (array) $v( 'vol-empty', 'card' ); $vd = (array) $v( 'vol-desktop', 'vol' );
    rl_check( isset( $cd['h'], $ce['h'], $vd['h'] ) && abs( $ce['h'] - ( $cd['h'] - $vd['h'] - 16 ) ) <= 1, 'vol-empty: the card keeps space for the missing button: ' . json_encode( array( 'with' => isset( $cd['h'] ) ? $cd['h'] : null, 'without' => isset( $ce['h'] ) ? $ce['h'] : null, 'button' => isset( $vd['h'] ) ? $vd['h'] : null ) ) );

    /* ---- A. The waitlist button and form. ---- */
    foreach ( array( 'wait-desktop', 'wait-phone' ) as $t ) {
        $rb = (array) $v( $t, 'reg' );
        rl_check( isset( $rb['text'] ) && 'Join the waitlist' === $rb['text'] && $rb['seen'], "PLANT A: $t: a full event's button reads " . json_encode( isset( $rb['text'] ) ? $rb['text'] : null ) );
        rl_check( isset( $rb['contrast'] ) && $rb['contrast'] >= 4.5, "$t: the waitlist button measures " . json_encode( isset( $rb['contrast'] ) ? $rb['contrast'] : null ) );
        rl_check( 'Join the waitlist' === $v( $t, 'modalHeading' ) && 'Join the waitlist' === $v( $t, 'modalSubmit' ), "$t: the form does not say Join the waitlist: " . json_encode( array( $v( $t, 'modalHeading' ), $v( $t, 'modalSubmit' ) ) ) );
        $sb = (array) $v( $t, 'submitBox' );
        rl_check( isset( $sb['right'] ) && $sb['right'] <= $pages[ $t ]['w'], "$t: the form's button runs off the screen" );
    }
    rl_check( 'Join the waitlist' === $v( 'vol-desktop', 'reg' )['text'] ? false : true, 'vol-desktop: an event with room says Join the waitlist' );
    $hr = (array) $v( 'wait-hybrid', 'reg' );
    rl_check( isset( $hr['text'] ) && 'RSVP' === $hr['text'], 'wait-hybrid: a hybrid event with online open does not say RSVP: ' . json_encode( isset( $hr['text'] ) ? $hr['text'] : null ) );
    rl_check( array( 'heading' => 'Join the waitlist', 'submit' => 'Join the waitlist' ) === $v( 'wait-hybrid', 'inPerson' ), 'wait-hybrid: picking the full in person does not offer the waitlist: ' . json_encode( $v( 'wait-hybrid', 'inPerson' ) ) );
    rl_check( array( 'heading' => 'Register for this Event', 'submit' => 'Register Now' ) === $v( 'wait-hybrid', 'online' ), 'wait-hybrid: picking online with room does not offer Register: ' . json_encode( $v( 'wait-hybrid', 'online' ) ) );

    /* ---- A. The RSVP list. ---- */
    foreach ( array( 'rsvps-desktop', 'rsvps-phone' ) as $t ) {
        $c = (string) $v( $t, 'counts' );
        rl_check( false !== strpos( $c, '2 registered' ) && false !== strpos( $c, '3 waitlisted' ), "$t: the counts read " . json_encode( $c ) );
        rl_check( 'Waitlist' === $v( $t, 'heading' ), "$t: no Waitlist card" );
        $rows = (array) $v( $t, 'rows' );
        rl_check( array( 'Fay Fox', 'Cam Chen', 'Dee Diaz', 'Eve Evans' ) === array_column( $rows, 'name' ), "$t: the waitlist rows read " . json_encode( array_column( $rows, 'name' ) ) );
        rl_check( array( "\xE2\x80\x93", '1', '2', '3' ) === array_column( $rows, 'pos' ), "$t: the positions read " . json_encode( array_column( $rows, 'pos' ) ) );
        foreach ( $rows as $r ) {
            rl_check( 'none' === $r['pill']['tt'], "$t: " . $r['name'] . '\'s pill renders in ' . $r['pill']['tt'] );
            rl_check( $r['confirm'] && $r['remove'], "$t: " . $r['name'] . '\'s row has no Confirm or no Remove' );
            rl_check( is_numeric( $r['pill']['contrast'] ) && $r['pill']['contrast'] >= 4.5, "$t: " . $r['name'] . '\'s pill measures ' . json_encode( $r['pill']['contrast'] ) );
        }
        $by = array_column( $rows, 'pill', 'name' );
        /* 3.110.0: no offers any more. A row left mid-offer by an older release reads
           Waiting and wears the waiting pill until the first cron run puts it back in the queue. */
        foreach ( array( 'Cam Chen', 'Dee Diaz' ) as $legacy ) {
            rl_check( isset( $by[ $legacy ], $by['Eve Evans'] ) && 'Waiting' === $by[ $legacy ]['text'] && $by['Eve Evans']['color'] === $by[ $legacy ]['color'] && $by['Eve Evans']['bg'] === $by[ $legacy ]['bg'],
                "$t: $legacy, left mid-offer, does not read Waiting with the waiting pill: " . json_encode( array( isset( $by[ $legacy ] ) ? $by[ $legacy ] : null, isset( $by['Eve Evans'] ) ? $by['Eve Evans'] : null ) ) );
        }
    }
}

if ( $fails ) {
    echo 'RELEASE 3.106.0 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo in_array( '--run', $argv, true )
    ? "3.106.0 live: the Templates screen, the Volunteer button, the waitlist button and form, and the RSVP list's waitlist, read in Chrome at desktop and phone width.\n"
    : "pages written; --run drives them.\n";
