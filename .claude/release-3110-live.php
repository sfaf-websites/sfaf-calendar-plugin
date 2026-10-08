<?php
/**
 * 3.110.0 IN A REAL BROWSER: WHO CAN FIND THIS EVENT, THE AGREEMENT DIALOG,
 * THE TEXT OPT-IN, THE RSVP LIST WITH CHECK-IN, AND THE PHONE PASS.
 *
 *     php .claude/release-3110-live.php            writes the pages
 *     php .claude/release-3110-live.php --run      writes them, drives Chrome, checks
 *     php .claude/release-3110-live.php --shots    --run, and writes .claude/screens/caladmin-3110-*.png
 *
 *   A.1  "Who can find this event" is its own card on Add and Edit, holding the
 *        tick; the link box and Copy are drawn only on a saved private event,
 *        and Copy confirms "Copied"
 *   B.2  the event page's RSVP card with the agreement on: Register Now opens
 *        the dialog, Confirm RSVP is off until the tick is on, Esc closes it
 *        with nothing sent and focus back on Register Now, Confirm sends
 *        agreed=1; an event without it sends straight away
 *   C    "Text me about this event" shows once a phone is typed, and its tick
 *        is sent as text_opt_in
 *   D    Email registrants is a closed accordion on the RSVP list
 *   F    the RSVP list by name: Check in turns into "Checked in [time]", the
 *        search box narrows the rows; by count: People attended and Save
 *   H    Add event, Edit event, the RSVP list and the event page with the
 *        dialog open, at 390px and 430px: nothing wider than the screen, no
 *        tap target under 44px tall, the RSVP list one column with each
 *        name and its button on one row
 *
 * The tap-target sweep reads every button, field, select, summary and
 * tick-box label that is on screen. A tick box is measured by its label,
 * which is what a thumb presses.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
function rx_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rx_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

$GLOBALS['kit_query'] = function ( $a ) { $o = array(); foreach ( $GLOBALS['kit_posts'] as $id => $p ) { if ( ( isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event' ) === $p->post_type ) { $o[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; } } return $o; };
function rx_event( $title, $meta ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
$plain   = rx_event( 'Dinner', array() );
$private = rx_event( 'Board dinner', array( '_uc_private' => '1' ) );
$agree   = rx_event( 'Harm reduction walk', array( SFAF_Agreement::META_ON => '1', SFAF_Agreement::META_TEXT => '<p>Photos and video are taken at this event and may appear on sfaf.org.</p><p>Wear closed shoes.</p>' ) );
$open    = rx_event( 'Harm reduction walk', array( SFAF_Agreement::META_ON => '1', SFAF_Agreement::META_TEXT => '<p>Photos and video are taken at this event and may appear on sfaf.org.</p><p>Wear closed shoes.</p>' ) );
$counted = rx_event( 'Coffee social', array( SFAF_Checkin::MODE_META => 'count', SFAF_Checkin::ATTEND_META => '14' ) );

/* The registrations table: four on the walk, one of them checked in, one
   waiting, one with texts on and no email. */
function rx_row( $id, $event, $first, $last, $email, $phone, $status, $extra = array() ) {
    return array_merge( array( 'id' => $id, 'event_id' => $event, 'name' => "$first $last", 'first_name' => $first, 'last_name' => $last, 'email' => $email,
        'phone' => $phone, 'status' => $status, 'token' => "t$id", 'format' => '', 'created_at' => '2026-09-2' . $id . ' 10:00:00', 'cancelled_at' => null,
        'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null, 'agreed_at' => '2026-09-2' . $id . ' 10:00:00',
        'text_opt_in' => 0, 'checked_in_at' => null, 'answers' => '', 'optin' => 0 ), $extra );
}
$GLOBALS['rx_rows'] = array(
    rx_row( 1, $agree, 'Ana', 'Alvarez-Montenegro', 'ana@example.org', '(415) 555-0101', 'confirmed', array( 'checked_in_at' => '2026-11-20 17:55:00', 'text_opt_in' => 1 ) ),
    rx_row( 2, $agree, 'Ben', 'Brooks', 'ben@example.org', '', 'confirmed' ),
    rx_row( 3, $agree, 'Dee', 'Diaz', '', '(415) 555-0100', 'confirmed', array( 'text_opt_in' => 1 ) ),
    rx_row( 4, $agree, 'Eve', 'Evans', 'eve@example.org', '', 'waitlisted' ),
    rx_row( 5, $counted, 'Gil', 'Gray', 'gil@example.org', '', 'confirmed' ),
);
$GLOBALS['kit_db'] = function ( $method, $sql ) {
    if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
    $rows = $GLOBALS['rx_rows'];
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
    if ( preg_match( '/text_opt_in\s*=\s*1/', $sql ) ) {
        $rows = array_filter( $rows, function ( $r ) { return 1 === (int) $r['text_opt_in']; } );
    }
    if ( 'get_var' === $method ) { return (string) count( $rows ); }
    if ( 'get_results' === $method ) {
        return array_map( function ( $r ) { $r['post_title'] = get_the_title( $r['event_id'] ); $r['event_title'] = ''; return (object) $r; }, array_values( $rows ) );
    }
    if ( 'get_row' === $method ) { $rows = array_values( $rows ); return $rows ? (object) $rows[0] : null; }
    return null;
};
update_post_meta( $agree, SFAF_Registrant_Mail::LOG_META, array( array( 'by' => 1, 'at' => '2026-10-01 09:30:00', 'subject' => 'Meeting point moved to the Castro', 'count' => 2 ) ) );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function rx_page( $method, $args = array(), $get = array() ) {
    return rx_capture( function () use ( $method, $args, $get ) { $_GET = $get; kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args ); } );
}
/* The event page's sidebar card in the wrappers the template gives it, as
   release-3106-live.php draws it. */
function rx_single( $id ) {
    $body = '<div class="uc-single"><div class="uc-single-inner"><div class="uc-single-grid"><div class="uc-single-main"><h1>' . esc_html( get_the_title( $id ) ) . '</h1></div>'
        . '<aside class="uc-single-sidebar"><div class="uc-single-card">' . sfaf_rsvp_block( $id ) . '</div></aside></div></div></div>';
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="../public/css/calendar.css"></head><body style="margin:0;background:#fff">' . $body
        . '<script src="vendor/jquery-3.7.1.min.js"></script><script>window.ucData={ajaxUrl:"",nonce:"n"};</script>'
        . '<script src="../public/js/calendar.js"></script></body></html>';
}

$html = array(
    'add'       => rx_page( 'render_event_form', array( $user, 0 ) ),
    'edit'      => rx_page( 'render_event_form', array( $user, $plain ) ),
    'private'   => rx_page( 'render_event_form', array( $user, $private ) ),
    'rsvps'     => rx_page( 'render_rsvps', array( $user ), array( 'event_id' => $agree ) ),
    'rsvpcount' => rx_page( 'render_rsvps', array( $user ), array( 'event_id' => $counted ) ),
    'public'    => rx_single( $open ),
    'publicwait' => rx_single( $agree ),
    'publicoff' => rx_single( $plain ),
);
$pages = array();
foreach ( $html as $k => $h ) {
    foreach ( array( 'desktop' => 1280, 'phone' => 390, 'wide' => 430 ) as $wn => $w ) {
        if ( in_array( $k, array( 'private', 'rsvpcount', 'publicoff', 'publicwait' ), true ) && 'desktop' !== $wn ) { continue; }
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
function name(el) { return (el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : '') + (el.name ? '[' + el.name + ']' : '') + ' "' + (text(el) || el.getAttribute('aria-label') || el.value || '').slice(0, 30) + '"'); }
/* H: everything a thumb presses, inside root, that is under 44px tall. */
function small(root) {
  var bad = [];
  qa('button, select, textarea, summary, input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), label', root).forEach(function (el) {
    if (!seen(el)) { return; }
    if (el.tagName === 'LABEL' && !q('input[type="checkbox"], input[type="radio"]', el)) { return; }
    if (el.closest('.uc-tpl-editor, [hidden], .uc-tour, #uc-sidebar')) { return; }
    var r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) { return; }
    var h = r.height, a = getComputedStyle(el, '::after');
    if (a.content !== 'none' && a.position === 'absolute') { h = r.height - parseFloat(a.top) - parseFloat(a.bottom); }
    if (Math.round(h) < 44) { bad.push(name(el) + ' ' + Math.round(r.height) + 'px'); }
  });
  return bad;
}
/* H: anything poking past the right edge of the screen. */
function past() {
  var w = document.documentElement.clientWidth, bad = [];
  qa('main *, .uc-portal-main *, .uc-single *, dialog *').forEach(function (el) {
    if (!seen(el) || el.closest('[hidden]')) { return; }
    var r = el.getBoundingClientRect();
    if (r.width && r.right > w + 1) { bad.push(name(el) + ' right ' + Math.round(r.right)); }
  });
  return bad.slice(0, 8);
}
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
var phone = window.innerWidth < 600;

window.addEventListener('load', function () { setTimeout(function () {
  /* ---- B and C: the event page. ---- */
  var reg = q('.uc-rsvp-btn');
  if (reg && window.jQuery) {
    var sent = [];
    jQuery.ajax = function (o) { sent.push(o && o.data ? o.data : null); return { done: function () { return this; }, fail: function () { return this; } }; };
    reg.click();
    jQuery('#uc-rsvp-first-name').val('Lee');
    jQuery('#uc-rsvp-last-name').val('Ramirez');
    jQuery('#uc-rsvp-email').val('lee@example.org');
    out.textBefore = seen(q('#uc-rsvp-text-wrap'));
    var ph = q('#uc-rsvp-phone');
    if (ph) { ph.value = '(415) 555-0199'; ph.dispatchEvent(new Event('input', { bubbles: true })); }
    out.textAfter = seen(q('#uc-rsvp-text-wrap'));
    out.textLabel = text(q('#uc-rsvp-text-label'));
    var tb = q('#uc-rsvp-text'); if (tb) { tb.checked = true; }
    q('#uc-rsvp-submit-btn').click();
    var d = q('#uc-agree');
    out.formHeading = text(q('#uc-rsvp-heading'));
    out.dialog = d ? { open: d.open, modal: d.matches(':modal'), heading: text(q('#uc-agree-heading')), body: text(q('#uc-agree-text')),
      tick: text(q('#uc-agree-tick-label')), confirm: text(q('#uc-agree-confirm')), cancel: text(q('#uc-agree-cancel')),
      confirmOff: q('#uc-agree-confirm').disabled, focus: document.activeElement ? document.activeElement.id : '',
      labelledby: d.getAttribute('aria-labelledby'), sentWhileOpen: sent.length, box: rect(q('.uc-agree', d)) } : null;
    if (d && d.open) {
      out.dialogSmall = small(d);
      out.dialogPast = past();
      /* Tab stays inside. */
      var inside = [];
      for (var i = 0; i < 4; i++) { d.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true })); inside.push(d.contains(document.activeElement)); }
      out.tabInside = inside.every(Boolean);
      if (window.RV_SHOT) { finish(); return; }
      d.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      out.afterEsc = { open: d.open, sent: sent.length, focus: document.activeElement ? document.activeElement.id : '' };
      q('#uc-rsvp-submit-btn').click();
      var t = q('#uc-agree-tick'); t.checked = true; t.dispatchEvent(new Event('change', { bubbles: true }));
      out.confirmOnAfterTick = !q('#uc-agree-confirm').disabled;
      q('#uc-agree-confirm').click();
      out.afterConfirm = { open: d.open, sent: sent.length, agreed: sent.length ? sent[sent.length - 1].agreed : null, text: sent.length ? sent[sent.length - 1].text_opt_in : null };
    } else {
      out.sentNoDialog = sent.length ? { agreed: sent[0].agreed, text: sent[0].text_opt_in } : null;
    }
    out.small = small(q('.uc-rsvp-modal-overlay.active') || document);
    finish(); return;
  }

  /* ---- caladmin ---- */
  var priv = q('[data-uc-card="privacy"]');
  if (priv) {
    var display = q('[data-uc-card="display"]');
    var hd = q('h2, h3', priv) ? q('h2, h3', priv).cloneNode(true) : null;
    if (hd) { qa('button, .uc-help-body', hd).forEach(function (n) { n.remove(); }); }
    out.privacy = { heading: text(hd), tick: !!q('input[name="uc_private"][type="checkbox"]', priv), afterDisplay: !!display && display.nextElementSibling === priv,
      link: q('[data-uc-private-link]', priv) ? q('[data-uc-private-link]', priv).value : null, readonly: !!q('[data-uc-private-link][readonly]', priv),
      copy: text(q('[data-uc-copy]', priv)), help: text(q('.uc-help, .uc-card-help, p', priv)) };
    var cb = q('[data-uc-copy]', priv);
    if (cb) {
      try { Object.defineProperty(navigator, 'clipboard', { value: { writeText: function () { return Promise.resolve(); } }, configurable: true }); } catch (e) {}
      cb.click();
    }
  }
  var rows = qa('[data-uc-rsvp-row]');
  if (rows.length || q('[data-uc-attendance-form], form [name="attendance"]')) {
    var strip = q('.uc-rsvp-counts');
    out.counts = strip ? text(strip) : null;
    out.rows = rows.map(function (r) {
      var nm = q('td:nth-child(2)', r), b = q('[data-uc-checkin]', r);
      return { name: r.getAttribute('data-uc-rsvp-name'), texts: !!q('[data-uc-texts-mark]', r), btn: b ? text(b) : null,
        nameBox: rect(nm), btnBox: rect(b), rowBox: rect(r), others: qa('.uc-rsvp-actions button:not([data-uc-checkin])', r).map(function (o) { return rect(o).h; }) };
    });
    var mail = q('details.uc-registrant-mail');
    out.mail = mail ? { open: mail.open, summary: text(q('summary', mail)), log: qa('[data-uc-rm-log] tbody tr').length, send: !!q('[data-uc-confirm]', mail) } : null;
    var seg = qa('.uc-seg-opt-btn').map(function (b) { return text(b) + (b.getAttribute('aria-pressed') === 'true' || b.classList.contains('is-on') ? '*' : ''); });
    out.mode = seg;
    out.attend = q('input[name="attendance"]') ? { value: q('input[name="attendance"]').value, save: text(q('button[type="submit"]', q('input[name="attendance"]').form)) } : null;
    /* Check in, with the server's answer stood in for. */
    window.fetch = function () { return Promise.resolve({ json: function () { return { success: true, data: { checked: true, label: 'Checked in 6:05 pm', count: 2 } }; } }); };
    var first = qa('[data-uc-checkin-form]').filter(function (f) { return text(q('[data-uc-checkin]', f)) === 'Check in'; })[0];
    if (first) { first.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
    var filter = q('[data-uc-rsvp-filter]');
    out.filter = !!filter;
    setTimeout(function () {
      out.afterCheckin = first ? text(q('[data-uc-checkin]', first)) : null;
      out.countAfter = text(q('[data-uc-checked-count]'));
      if (filter) { filter.value = 'ben'; filter.dispatchEvent(new Event('input', { bubbles: true })); out.filtered = qa('[data-uc-rsvp-row]').filter(function (r) { return !r.hidden; }).map(function (r) { return r.getAttribute('data-uc-rsvp-name'); }); filter.value = ''; filter.dispatchEvent(new Event('input', { bubbles: true })); }
      out.small = phone ? small(q('.uc-portal-main') || document) : [];
      out.past = phone ? past() : [];
      finish();
    }, 50);
    return;
  }
  setTimeout(function () {
    if (priv) { out.copied = text(q('[data-uc-copy]', priv)); }
    out.small = phone ? small(q('.uc-portal-main') || document) : [];
    out.past = phone ? past() : [];
    finish();
  }, 50);
}, 600); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rx_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inner = __DIR__ . '/release-3110-live-' . $name . '.html';
    file_put_contents( $inner, preg_replace( '#</body>(?![\s\S]*</body>)#i', '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body>', $p['html'], 1 ) );
    $files[ $name ] = __DIR__ . '/release-3110-live-' . $name . '-frame.html';
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
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rx_check( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rx_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rx_check( (int) $g[ $name ]['pageWidth'] <= $w, "H: $name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

/* ---- A.1 ----------------------------------------------------------------- */
foreach ( array( 'add', 'edit', 'private' ) as $k ) {
    $t  = "$k-desktop";
    $pv = (array) $v( $t, 'privacy' );
    rx_check( $pv && 'Who can find this event' === $pv['heading'] && true === $pv['tick'] && true === $pv['afterDisplay'],
        "A.1: $t: Who can find this event is not its own card after Display with the tick in it: " . json_encode( $pv ) );
}
rx_check( null === ( (array) $v( 'add-desktop', 'privacy' ) )['link'] && null === ( (array) $v( 'edit-desktop', 'privacy' ) )['link'],
    'PLANT A.1: the private link is drawn before the event is saved private: ' . json_encode( array( $v( 'add-desktop', 'privacy' ), $v( 'edit-desktop', 'privacy' ) ) ) );
$pp = (array) $v( 'private-desktop', 'privacy' );
rx_check( $pp && is_string( $pp['link'] ) && false !== strpos( $pp['link'], 'http' ) && true === $pp['readonly'] && 'Copy' === $pp['copy'],
    'A.1: a saved private event does not show its link read-only with Copy: ' . json_encode( $pp ) );
rx_check( 'Copied' === $v( 'private-desktop', 'copied' ), 'A.1: Copy does not confirm "Copied": ' . json_encode( $v( 'private-desktop', 'copied' ) ) );

/* ---- B and C ------------------------------------------------------------- */
foreach ( array( 'desktop', 'phone', 'wide', 'wait' ) as $wn ) {
    $t = 'wait' === $wn ? 'publicwait-desktop' : "public-$wn";
    rx_check( ( 'wait' === $wn ? 'Join the waitlist' : 'Register for this Event' ) === $v( $t, 'formHeading' ), "B.3: $t: the form is headed " . json_encode( $v( $t, 'formHeading' ) ) );
    $d = (array) $v( $t, 'dialog' );
    rx_check( $d && true === $d['open'] && true === $d['modal'] && 'Before you register' === $d['heading'] && 'uc-agree-heading' === $d['labelledby']
        && false !== strpos( $d['body'], 'Photos and video are taken' ) && 'I have read and agree to the event conditions' === $d['tick']
        && 'Confirm RSVP' === $d['confirm'] && 'Cancel' === $d['cancel'] && true === $d['confirmOff'] && 'uc-agree-tick' === $d['focus'] && 0 === $d['sentWhileOpen'],
        "B.2: $t: Register Now did not open the agreement as a modal dialog, Confirm off, focus on the tick, nothing sent: " . json_encode( $d ) );
    rx_check( true === $v( $t, 'tabInside' ), "B.2: $t: Tab leaves the dialog" );
    $e = (array) $v( $t, 'afterEsc' );
    rx_check( $e && false === $e['open'] && 0 === $e['sent'] && 'uc-rsvp-submit-btn' === $e['focus'], "B.2: $t: Esc did not close with nothing sent and focus back on Register Now: " . json_encode( $e ) );
    rx_check( true === $v( $t, 'confirmOnAfterTick' ), "B.2: $t: ticking does not turn Confirm RSVP on" );
    $c = (array) $v( $t, 'afterConfirm' );
    rx_check( $c && false === $c['open'] && 1 === $c['sent'] && '1' === $c['agreed'] && '1' === $c['text'], "B.2: $t: Confirm RSVP did not send the form once with agreed=1 and text_opt_in=1: " . json_encode( $c ) );
    rx_check( false === $v( $t, 'textBefore' ) && true === $v( $t, 'textAfter' ) && 'Text me about this event' === $v( $t, 'textLabel' ),
        "C: $t: the text opt-in is not hidden until a phone is typed: " . json_encode( array( $v( $t, 'textBefore' ), $v( $t, 'textAfter' ), $v( $t, 'textLabel' ) ) ) );
    if ( in_array( $wn, array( 'phone', 'wide' ), true ) ) {
        rx_check( array() === $v( $t, 'dialogSmall' ), "H: $t: tap targets under 44px in the dialog: " . json_encode( $v( $t, 'dialogSmall' ) ) );
        rx_check( array() === $v( $t, 'dialogPast' ), "H: $t: the dialog runs past the screen: " . json_encode( $v( $t, 'dialogPast' ) ) );
        rx_check( array() === $v( $t, 'small' ), "H: $t: tap targets under 44px on the RSVP form: " . json_encode( $v( $t, 'small' ) ) );
    }
}
$s = (array) $v( 'publicoff-desktop', 'sentNoDialog' );
rx_check( null === $v( 'publicoff-desktop', 'dialog' ) || ! ( (array) $v( 'publicoff-desktop', 'dialog' ) )['open'], 'B.4: an event without the agreement opened the dialog' );
rx_check( $s && '' === $s['agreed'], 'B.2: an event without the agreement did not send straight away: ' . json_encode( $s ) );

/* ---- D and F ------------------------------------------------------------- */
foreach ( array( 'desktop', 'phone', 'wide' ) as $wn ) {
    $t    = "rsvps-$wn";
    $rows = (array) $v( $t, 'rows' );
    $by   = array(); foreach ( $rows as $r ) { $by[ $r['name'] ] = $r; }
    rx_check( isset( $by['ana alvarez-montenegro'], $by['ben brooks'], $by['dee diaz'] ), "F: $t: the registered rows are not all drawn: " . json_encode( array_keys( $by ) ) );
    if ( isset( $by['ana alvarez-montenegro'] ) ) {
        rx_check( 0 === strpos( (string) $by['ana alvarez-montenegro']['btn'], 'Checked in' ) && true === $by['ana alvarez-montenegro']['texts'], "F/C: $t: Ana is not shown checked in with the Texts mark: " . json_encode( $by['ana alvarez-montenegro'] ) );
    }
    if ( isset( $by['ben brooks'] ) ) {
        rx_check( false === $by['ben brooks']['texts'], "C: $t: Ben did not opt in and carries the Texts mark" );
    }
    rx_check( 'Checked in 6:05 pm' === $v( $t, 'afterCheckin' ) && '2' === $v( $t, 'countAfter' ), "F.1: $t: Check in did not turn into the time and move the count: " . json_encode( array( $v( $t, 'afterCheckin' ), $v( $t, 'countAfter' ) ) ) );
    rx_check( true === $v( $t, 'filter' ) && array( 'ben brooks' ) === $v( $t, 'filtered' ), "F.2: $t: the search box does not narrow the rows to Ben: " . json_encode( $v( $t, 'filtered' ) ) );
    $counts = (string) $v( $t, 'counts' );
    rx_check( false !== strpos( $counts, 'registered' ) && false !== strpos( $counts, 'checked in' ) && false !== strpos( $counts, 'waitlisted' ) && false !== strpos( $counts, 'text' ),
        "F.2/C: $t: the counts strip does not carry registered, checked in, waitlisted and want texts: $counts" );
    $m = (array) $v( $t, 'mail' );
    rx_check( $m && false === $m['open'] && 'Email registrants Send a message to everyone registered.' === $m['summary'] &&   /* the panel's muted line since 3.110.1 */ 1 === $m['log'] && true === $m['send'], "D.1: $t: Email registrants is not a closed accordion with its log and Send: " . json_encode( $m ) );
    if ( 'desktop' !== $wn ) {
        foreach ( $rows as $r ) {
            if ( ! $r['btnBox'] ) { continue; }
            rx_check( $r['btnBox']['h'] >= 44 && min( array_merge( array( 44 ), $r['others'] ) ) >= 44, "F.2: $t: {$r['name']}'s buttons are under 44px: " . json_encode( array( $r['btnBox']['h'], $r['others'] ) ) );
            rx_check( $r['nameBox'] && $r['btnBox']['top'] < $r['nameBox']['bottom'] && $r['nameBox']['top'] < $r['btnBox']['bottom'] && $r['nameBox']['right'] <= $r['btnBox']['left'],
                "F.2: $t: {$r['name']}'s name and button are not on one row: " . json_encode( array( $r['nameBox'], $r['btnBox'] ) ) );
        }
    }
}
rx_check( array( 'Check in by name', 'Enter a count*' ) === $v( 'rsvpcount-desktop', 'mode' ), 'F.1: the count event does not show Enter a count chosen: ' . json_encode( $v( 'rsvpcount-desktop', 'mode' ) ) );
$at = (array) $v( 'rsvpcount-desktop', 'attend' );
rx_check( $at && '14' === $at['value'] && 'Save' === $at['save'], 'F.1: People attended is not a box holding 14 with Save: ' . json_encode( $at ) );

/* ---- H: the editors and the list at 390 and 430 -------------------------- */
foreach ( array( 'add', 'edit', 'rsvps' ) as $k ) {
    foreach ( array( 'phone', 'wide' ) as $wn ) {
        $t = "$k-$wn";
        rx_check( array() === $v( $t, 'small' ), "H: $t: tap targets under 44px: " . json_encode( $v( $t, 'small' ) ) );
        rx_check( array() === $v( $t, 'past' ), "H: $t: runs past the screen: " . json_encode( $v( $t, 'past' ) ) );
    }
}

if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    $set = array( 'private' => array( 'desktop' => 1280 ), 'add' => array( 'phone' => 390 ), 'rsvps' => array( 'desktop' => 1280, 'phone' => 390 ), 'public' => array( 'desktop' => 1280, 'phone' => 390, 'wide' => 430 ) );
    foreach ( $set as $k => $widths ) {
        foreach ( $widths as $wn => $w ) {
            $png   = __DIR__ . "/screens/caladmin-3110-$k-$wn.png";
            $src   = __DIR__ . "/release-3110-live-$k-$wn.html";
            $inner = __DIR__ . "/release-3110-shot-$k-$wn.html";
            /* The public page is shot with its dialog open: the probe stops there. */
            /* No transitions on a screenshot: the dialog was caught mid-fade. */
            file_put_contents( $inner, str_replace( array( 'window.RV_PAGE=', '</head>' ), array( 'window.RV_SHOT=1;window.RV_PAGE=', '<style>*,*::before,*::after,::backdrop{transition:none!important;animation:none!important}</style></head>' ), file_get_contents( $src ) ) );
            $frame = __DIR__ . "/release-3110-shot-$k-$wn-frame.html";
            file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="' . basename( $inner ) . '" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe></body></html>' );
            shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $w ) . ',900 --virtual-time-budget=8000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
            rx_check( file_exists( $png ), "no screenshot written for $k $wn" );
        }
    }
}

if ( $fails ) {
    echo 'RELEASE 3.110.0 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.110.0 live: Who can find this event is its own card with the link and Copy only once saved private; the agreement dialog opens from Register Now, modal, Confirm off until ticked, Esc sends nothing, Confirm sends agreed=1; the text opt-in appears with a phone; the RSVP list checks in, filters and counts, with Email registrants closed; Add, Edit, the RSVP list and the event page with the dialog have no overflow and no tap target under 44px at 390px and 430px.\n";
