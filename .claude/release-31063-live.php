<?php
/**
 * 3.106.3 IN A REAL BROWSER: THE QUESTIONS SECTION REDRAWN, AND THE SERIES
 * REMOVAL SCREEN OFFERING TO TELL A WAITLIST, AT 1280px AND 390px.
 *
 *     php .claude/release-31063-live.php          writes the pages
 *     php .claude/release-31063-live.php --run    writes them, drives Chrome, checks
 *
 * The real event editor and series removal screen through wp-kit.php with the
 * real portal.css and portal.js. Every value is read off the laid-out page and
 * compared with the DESIGN.md token it should be.
 *
 *   q1-*     one question, four options: its own card under Location, the
 *            standard 16px between cards, the helper inside; the question a
 *            sub-card on --p-bg with --p-border; its top row the box, then
 *            Required, In person only and a 32px remove button labelled
 *            "Remove question"; Pick any / Pick one as the segmented control;
 *            options as grip, box, tick and a "Remove option" button with no
 *            words; "+ Add option" in the link colour lined up with the option
 *            boxes; "+ Add question" outlined, at its own width; Up and Down on
 *            a grip move the option and renumber the names; the focus ring is
 *            the accent; at 390px the box takes its own line and nothing
 *            overflows.
 *   q3-*     three questions, the same, and every sub-card inside the card.
 *   add-*    Add event opens on one blank question, Pick any chosen.
 *   series-* the removal screen with only a waitlist: both numbers, email the
 *            waitlist, and Delete them anyway.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function rt_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rt_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); }
    catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}
$GLOBALS['kit_query'] = function ( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' === $p->post_type ) { $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; }
    }
    return $out;
};
function rt_event( $meta ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Community dinner', 'post_author' => 1 ) );
    foreach ( array_merge( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '20' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
$opt = function ( $i, $t, $more = false ) { return array( 'id' => sprintf( 'o%010d', $i ), 'text' => $t, 'more' => $more ); };
$one = array( array( 'id' => 'qaaaaaaaaaa', 'text' => 'Any dietary needs?', 'style' => 'any', 'required' => true, 'in_person' => false,
    'options' => array( $opt( 1, 'Vegetarian' ), $opt( 2, 'Vegan' ), $opt( 3, 'Gluten free' ), $opt( 4, 'Allergy', true ) ) ) );
$three = array_merge( $one, array(
    array( 'id' => 'qbbbbbbbbbb', 'text' => 'Will you park on site?', 'style' => 'one', 'required' => false, 'in_person' => true, 'options' => array( $opt( 5, 'Yes' ), $opt( 6, 'No' ) ) ),
    array( 'id' => 'qcccccccccc', 'text' => 'How did you hear about this event?', 'style' => 'any', 'required' => false, 'in_person' => false, 'options' => array( $opt( 7, 'A friend' ), $opt( 8, 'Instagram' ), $opt( 9, 'SFAF newsletter' ) ) ),
) );
$e1 = rt_event( array( SFAF_Online::META_HYBRID => '1', '_uc_capacity_online' => '50', SFAF_Questions::META => $one ) );
$e3 = rt_event( array( SFAF_Online::META_HYBRID => '1', '_uc_capacity_online' => '50', SFAF_Questions::META => $three ) );

/* The series removal screen with only a waitlist, as the route leaves it. */
$GLOBALS['kit_trans'][ 'sfaf_series_delete_blocked_' . (int) $GLOBALS['kit_user_id'] ] = array(
    'series_id' => 11, 'counts' => array( 'registrations' => 0, 'people' => 0, 'events' => 0 ), 'waiting' => 2, 'events' => array( $e1 ) );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function rt_screen( $method, $args ) {
    return rt_capture( function () use ( $method, $args ) { $_GET = array(); kit_call( 'SFAF_Portal', $method, $GLOBALS['portal'], $args ); } );
}
$q1     = rt_screen( 'render_event_form', array( $user, $e1 ) );
$q3     = rt_screen( 'render_event_form', array( $user, $e3 ) );
$add    = rt_screen( 'render_event_form', array( $user, 0 ) );
$series = rt_screen( 'render_series_remove', array( $user, 11 ) );
$pages = array(
    'q1-desktop'     => array( 'w' => 1280, 'html' => $q1 ),
    'q1-phone'       => array( 'w' => 390,  'html' => $q1 ),
    'q3-desktop'     => array( 'w' => 1280, 'html' => $q3 ),
    'q3-phone'       => array( 'w' => 390,  'html' => $q3 ),
    'add-desktop'    => array( 'w' => 1280, 'html' => $add ),
    'add-phone'      => array( 'w' => 390,  'html' => $add ),
    'series-desktop' => array( 'w' => 1280, 'html' => $series ),
    'series-phone'   => array( 'w' => 390,  'html' => $series ),
);

$probe = <<<'JS'
(function () {
var P = window.RT_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; }
function rgb(s) { var m = String(s).match(/\d+(\.\d+)?/g); return m ? m.slice(0, 4).map(Number) : null; }
function lum(c) { return [0, 1, 2].map(function (i) { var v = c[i] / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (a, v, i) { return a + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
function behind(el) {
  for (var n = el; n && n.nodeType === 1; n = n.parentNode) { var c = rgb(getComputedStyle(n).backgroundColor); if (c && (c.length < 4 || c[3] > 0)) { return c; } }
  return [255, 255, 255];
}
function contrast(el) { var a = lum(rgb(getComputedStyle(el).color)), b = lum(behind(el)); return Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100; }
function box(el) {
  if (!el) { return null; }
  var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
  return { text: text(el), seen: seen(el), top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom),
    w: Math.round(r.width), h: Math.round(r.height), color: cs.color, bg: cs.backgroundColor, border: cs.borderTopColor + ' ' + cs.borderTopWidth,
    radius: cs.borderTopLeftRadius, pad: cs.paddingTop + ' ' + cs.paddingLeft, size: cs.fontSize, weight: cs.fontWeight, contrast: seen(el) ? contrast(el) : null };
}
function finish() {
  out.pageWidth = document.documentElement.scrollWidth;
  if (window.parent !== window) { window.parent.postMessage(JSON.stringify(out), '*'); return; }
  var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
}
window.addEventListener('load', function () { setTimeout(function () {
  if (P.indexOf('series-') === 0) {
    var c = document.querySelector('[data-uc-cancel-instead-counts]');
    out.counts = text(c);
    out.buttons = Array.prototype.map.call(document.querySelectorAll('[data-uc-cancel-instead] button'), text);
    out.card = box(document.querySelector('[data-uc-cancel-instead]'));
    return finish();
  }
  /* 3.107.0: the questions are in the Registration card, which shows only
   * the Accept RSVPs tick until it is ticked. Tick it, as a person would. */
  var acc = document.querySelector('[data-uc-rsvp-accept-check] input');
  if (acc && !acc.checked && !acc.disabled) { acc.click(); }
  /* The card is Registration from 3.107.0; the questions are its section. */
  var card = document.querySelector('[data-uc-card="registration"]');
  out.card = box(card);
  if (!card) { return finish(); }
  var cs = getComputedStyle(card);
  out.cardStyle = { border: cs.borderTopColor + ' ' + cs.borderTopWidth, pad: cs.paddingTop, radius: cs.borderTopLeftRadius, bg: cs.backgroundColor };
  out.title = text(card.querySelector('[data-uc-questions-card] > h3'));
  out.hint = box(card.querySelector('.uc-questions-hint'));
  var prev = card.previousElementSibling, next = card.nextElementSibling;
  out.gapAbove = prev ? Math.round(card.getBoundingClientRect().top - prev.getBoundingClientRect().bottom) : null;
  out.gapBelow = next ? Math.round(next.getBoundingClientRect().top - card.getBoundingClientRect().bottom) : null;
  out.prevTitle = prev ? text(prev.querySelector('h2')) : '';
  var qs = card.querySelectorAll('[data-uc-q]');
  out.questions = qs.length;
  out.subcards = Array.prototype.map.call(qs, function (q) {
    var s = getComputedStyle(q), r = q.getBoundingClientRect(), cr = card.getBoundingClientRect();
    return { bg: s.backgroundColor, border: s.borderTopColor + ' ' + s.borderTopWidth, radius: s.borderTopLeftRadius, inside: r.left >= cr.left && r.right <= cr.right,
      overflow: Array.prototype.some.call(q.querySelectorAll('*'), function (n) { var b = n.getBoundingClientRect(); return b.width > 0 && b.right > r.right + 0.5; }) };
  });
  var q = qs[0];
  var tbox = q.querySelector('.uc-q-text input'), req = q.querySelector('[data-uc-q-name="required"]').closest('label'), ip = q.querySelector('[data-uc-q-inperson]'), rm = q.querySelector('[data-uc-q-remove]');
  out.top = { text: box(tbox), req: box(req), ip: box(ip), rm: box(rm), rmLabel: rm.getAttribute('aria-label'), rmType: rm.getAttribute('type'), rmWords: rm.textContent.trim(), rowW: Math.round(q.querySelector('.uc-q-top').getBoundingClientRect().width) };
  out.seg = Array.prototype.map.call(q.querySelectorAll('.uc-q-style .uc-seg-opt'), function (l) { return { text: text(l), checked: l.querySelector('input').checked }; });
  out.segBox = box(q.querySelector('.uc-q-style'));
  out.segOn = box(q.querySelector('.uc-q-style input:checked + span'));
  var opts = q.querySelectorAll('[data-uc-o]');
  out.options = opts.length;
  out.optRows = Array.prototype.map.call(opts, function (o) {
    var b = o.querySelector('[data-uc-o-remove]'), g = o.querySelector('[data-uc-o-grip]');
    return { grip: box(g), gripTag: g.tagName, gripLabel: g.getAttribute('aria-label'), input: box(o.querySelector('input[type="text"]')), rm: box(b), rmLabel: b.getAttribute('aria-label'), rmWords: b.textContent.trim(), h: Math.round(o.getBoundingClientRect().height) };
  });
  out.addOpt = box(q.querySelector('[data-uc-o-add]'));
  out.addQ = box(card.querySelector('[data-uc-q-add]'));
  out.addQBorder = getComputedStyle(card.querySelector('[data-uc-q-add]')).borderTopColor;
  /* The focus ring, from the keyboard. */
  rm.focus();
  var f = getComputedStyle(rm);
  out.focusRing = { style: f.outlineStyle, color: f.outlineColor, width: f.outlineWidth, active: document.activeElement === rm };
  /* Up and Down on a grip. */
  if (opts.length > 1) {
    var g2 = opts[1].querySelector('[data-uc-o-grip]');
    g2.focus();
    g2.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true }));
    var now = q.querySelectorAll('[data-uc-o]');
    out.afterUp = { first: now[0].querySelector('input[type="text"]').value, firstName: now[0].querySelector('input[type="text"]').name, focusKept: document.activeElement === g2 };
  }
  return finish();
}, 500); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rt_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inject = '<script>window.RT_PAGE=' . json_encode( $name ) . ';' . $probe . '</script>';
    $html = ( false !== stripos( $p['html'], '</body>' ) ) ? preg_replace( '#</body>#i', $inject . '</body>', $p['html'], 1 ) : $p['html'] . $inject;
    $files[ $name ] = __DIR__ . '/release-31063-live-' . $name . '.html';
    file_put_contents( $files[ $name ], $html );
    if ( $p['w'] < 504 ) {
        $inner = basename( $files[ $name ] );
        $files[ $name ] = __DIR__ . '/release-31063-live-' . $name . '-frame.html';
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
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rt_check( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rt_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rt_check( (int) $g[ $name ]['pageWidth'] <= $w, "PLANT A.8: $name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

/* DESIGN.md's tokens, as the browser reports them. */
$P_BG     = 'rgb(245, 246, 247)';  // --p-bg #F5F6F7
$P_BORDER = 'rgb(226, 229, 234)';  // --p-border #E2E5EA
$P_MUTED  = 'rgb(107, 103, 100)';  // --p-muted #6B6764
$ACCENT   = 'rgb(14, 118, 128)';   // --uc-accent-text #0E7680
$WHITE    = 'rgb(255, 255, 255)';

foreach ( array( 'q1-desktop', 'q1-phone', 'q3-desktop', 'q3-phone', 'add-desktop', 'add-phone' ) as $t ) {
    $phone = false !== strpos( $t, 'phone' );
    $want  = 0 === strpos( $t, 'q3' ) ? 3 : 1;
    rt_check( 'Questions for registrants' === $v( $t, 'title' ), "$t: the card is not titled Questions for registrants: " . json_encode( $v( $t, 'title' ) ) );
    $cs = (array) $v( $t, 'cardStyle' );
    rt_check( isset( $cs['border'] ) && "$P_BORDER 1px" === $cs['border'] && '18px' === $cs['pad'] && '12px' === $cs['radius'], "$t: the card is not the standard card: " . json_encode( $cs ) );
    rt_check( 'Location' === preg_replace( '/\W+$/', '', (string) $v( $t, 'prevTitle' ) ) && 16 === $v( $t, 'gapAbove' ) && 16 === $v( $t, 'gapBelow' ), "$t: the Registration card holding it is not under Location with 16px either side: " . json_encode( array( $v( $t, 'prevTitle' ), $v( $t, 'gapAbove' ), $v( $t, 'gapBelow' ) ) ) );
    $h = (array) $v( $t, 'hint' );
    rt_check( isset( $h['text'] ) && 'Ask registrants anything you need to know before the event.' === $h['text'] && $P_MUTED === $h['color'] && '12px' === $h['size'], "$t: the helper is not inside, at the helper step: " . json_encode( $h ) );
    rt_check( $want === $v( $t, 'questions' ), "$t: " . json_encode( $v( $t, 'questions' ) ) . " questions, wanted $want" );
    foreach ( (array) $v( $t, 'subcards' ) as $i => $sc ) {
        rt_check( $P_BG === $sc['bg'] && "$P_BORDER 1px" === $sc['border'] && '8px' === $sc['radius'], "PLANT A.8: $t: question " . ( $i + 1 ) . ' is not a sub-card on --p-bg with --p-border: ' . json_encode( $sc ) );
        rt_check( $sc['inside'] && ! $sc['overflow'], "PLANT A.8: $t: question " . ( $i + 1 ) . ' overflows: ' . json_encode( $sc ) );
    }
    $top = (array) $v( $t, 'top' );
    rt_check( isset( $top['rmLabel'] ) && 'Remove question' === $top['rmLabel'] && 'button' === $top['rmType'] && '' === $top['rmWords'] && ( $phone ? 44 : 32 ) === $top['rm']['w'] && ( $phone ? 44 : 32 ) === $top['rm']['h'], "$t: the question's remove is not a " . ( $phone ? 44 : 32 ) . "px button labelled Remove question (44px on a phone since 3.110.0): " . json_encode( $top['rm'] ?? null ) );
    rt_check( isset( $top['rm']['color'] ) && $P_MUTED === $top['rm']['color'] && $top['rm']['contrast'] >= 3, "$t: the remove icon is not --p-muted at 3:1: " . json_encode( $top['rm'] ?? null ) );
    if ( isset( $top['text'], $top['req'], $top['rm'] ) ) {
        if ( $phone ) {
            rt_check( $top['req']['top'] >= $top['text']['bottom'] && $top['text']['w'] >= $top['rowW'] - 1, "PLANT A.8: $t: at 390px the box does not take its own line with the ticks under it: " . json_encode( $top ) );
            rt_check( abs( ( $top['rm']['top'] + $top['rm']['h'] / 2 ) - ( $top['req']['top'] + $top['req']['h'] / 2 ) ) <= 6, "$t: at 390px the remove is not on the ticks' line: " . json_encode( array( $top['req'], $top['rm'] ) ) );
        } else {
            rt_check( abs( $top['req']['top'] - $top['text']['top'] ) <= 12 && $top['req']['left'] > $top['text']['right'] && $top['rm']['left'] > $top['req']['right'], "$t: the box, Required and the remove are not one row in that order: " . json_encode( $top ) );
            // The box takes every pixel the ticks and the remove leave, less the 16px gap.
            rt_check( $top['text']['w'] >= $top['rowW'] - ( $top['rm']['right'] - $top['req']['left'] ) - 16 - 1, "$t: the question box does not take the width the ticks leave: " . json_encode( $top ) );
        }
        rt_check( ! $top['ip']['seen'] || abs( $top['ip']['top'] - $top['req']['top'] ) <= 2, "$t: In person only is not on Required's row" );
    }
    $seg = (array) $v( $t, 'seg' );
    rt_check( array( 'Pick any', 'Pick one' ) === array_column( $seg, 'text' ), "$t: the answer style reads " . json_encode( array_column( $seg, 'text' ) ) );
    $on = (array) $v( $t, 'segOn' );
    rt_check( isset( $on['bg'] ) && $ACCENT === $on['bg'] && $WHITE === $on['color'], "$t: the chosen answer style is not white on the accent: " . json_encode( $on ) );
    $rows = (array) $v( $t, 'optRows' );
    foreach ( $rows as $k => $r ) {
        rt_check( 'Remove option' === $r['rmLabel'] && '' === $r['rmWords'] && ( $phone ? 44 : 32 ) === $r['rm']['w'], "$t: option " . ( $k + 1 ) . "'s remove is not a wordless " . ( $phone ? 44 : 32 ) . "px Remove option button (44px on a phone since 3.110.0)" );
        rt_check( 'BUTTON' === $r['gripTag'] && $r['grip']['left'] < $r['input']['left'], "$t: option " . ( $k + 1 ) . "'s handle is not a button left of the box" );
        rt_check( $phone || $r['h'] <= 40, "$t: option " . ( $k + 1 ) . ' is ' . $r['h'] . 'px tall, not a tight row' );
        rt_check( $r['rm']['right'] <= $r['input']['right'] + 220 && $r['rm']['right'] >= $r['input']['right'] - 1, "$t: option " . ( $k + 1 ) . "'s remove is not at the row's right-hand end" );
    }
    $ao = (array) $v( $t, 'addOpt' );
    rt_check( isset( $ao['text'] ) && '+ Add option' === $ao['text'] && $ACCENT === $ao['color'] && $WHITE !== $ao['bg'] && isset( $rows[0] ) && abs( $ao['left'] - $rows[0]['input']['left'] ) <= 1,
        "$t: + Add option is not a link-coloured text lined up with the option boxes: " . json_encode( array( $ao, isset( $rows[0] ) ? $rows[0]['input']['left'] : null ) ) );
    $aq = (array) $v( $t, 'addQ' );
    rt_check( isset( $aq['text'] ) && '+ Add question' === $aq['text'] && $ACCENT === $aq['color'] && $ACCENT . ' 1px' === explode( ' 1px', $v( $t, 'addQBorder' ) )[0] . ' 1px'
        && $aq['w'] < $g[ $t ]['card']['w'] / 2 && abs( $aq['left'] - ( $g[ $t ]['card']['left'] + 19 ) ) <= 1, "$t: + Add question is not an outlined accent button at its own width on the left: " . json_encode( array( $aq, $v( $t, 'addQBorder' ) ) ) );
    $fr = (array) $v( $t, 'focusRing' );
    rt_check( isset( $fr['style'] ) && 'solid' === $fr['style'] && $ACCENT === $fr['color'] && '2px' === $fr['width'] && $fr['active'], "$t: a remove button's focus ring is not the 2px accent: " . json_encode( $fr ) );
}
foreach ( array( 'q1-desktop', 'q1-phone' ) as $t ) {
    rt_check( 4 === $v( $t, 'options' ), "$t: four options wanted" );
    $up = (array) $v( $t, 'afterUp' );
    rt_check( isset( $up['first'] ) && 'Vegan' === $up['first'] && 'uc_questions[0][options][0][text]' === $up['firstName'] && $up['focusKept'], "$t: Up on the second grip did not move it first, renumbered, keeping focus: " . json_encode( $up ) );
}
foreach ( array( 'add-desktop', 'add-phone' ) as $t ) {
    $seg = (array) $v( $t, 'seg' );
    rt_check( 1 === $v( $t, 'questions' ) && isset( $seg[0]['checked'] ) && true === $seg[0]['checked'], "$t: Add event does not open on one blank question with Pick any chosen" );
}
foreach ( array( 'series-desktop', 'series-phone' ) as $t ) {
    rt_check( 'Nobody is registered, and 2 people are on a waitlist across the events in this series. Cancelling keeps every registration, closes new ones, and stops the reminders.' === $v( $t, 'counts' ), "$t: the counts read " . json_encode( $v( $t, 'counts' ) ) );
    rt_check( array( 'Cancel these and email the 2 people', 'Cancel these without telling them', 'Delete them anyway' ) === $v( $t, 'buttons' ), "$t: the buttons read " . json_encode( $v( $t, 'buttons' ) ) );
}

if ( $fails ) {
    echo 'RELEASE 3.106.3 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.106.3 live: the questions card, its sub-cards, segmented style, wordless icon buttons, links and focus ring, wrapping at 390px with nothing over; and the series removal screen offering the waitlist, at 1280px and 390px.\n";
