<?php
/**
 * 3.134.0 IN A BROWSER: THE TERMS LINK, AND AN ENDED EVENT'S PAGE.
 *
 *     php .claude/release-31340-live.php --run
 *     php .claude/release-31340-live.php --run --verbose   and print what was measured
 *
 * REAL: the event page's RSVP form (sfaf_rsvp_block() with the real
 * calendar.css and calendar.js), and the whole event page
 * (templates/single-uc_event.php) for an ended event in a series with two
 * dates to come, rendered through wp-kit.php, in Chrome at 1280px and 390px.
 *
 *   H  Text ticked, then WhatsApp: beside the privacy policy, "Terms",
 *      shown, opening the terms page in a new tab; Español: "Términos"
 *   I  the ended page: "This event has ended." shown in the sidebar card,
 *      no Register button and no Add to Calendar; the two dates shown, each
 *      a link with its weekday, inside the card and on screen; the page
 *      never scrolls sideways
 *
 * PLANT in plant-31340.php.
 */

$GLOBALS['loop_done'] = false;
function have_posts() { return ! $GLOBALS['loop_done']; }
function the_post() { $GLOBALS['loop_done'] = true; $GLOBALS['kit_current'] = $GLOBALS['page_ev']; }
function get_header() { echo ''; }
function get_footer() { echo ''; }
require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'the_title' ) ) { function the_title() { echo get_the_title( get_the_ID() ); } }
if ( ! function_exists( 'the_content' ) ) { function the_content() { echo get_post_field( 'post_content', get_the_ID() ); } }

$fails = array();
function rl( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rl_event( $title, $meta = array() ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+7 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30',
        '_uc_location' => '1035 Market St', '_uc_rsvp_enabled' => '1' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}
$head = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
    . '<link rel="stylesheet" href="../public/css/calendar.css"></head><body style="margin:0;background:#fff">';
$words = 'window.ucData={ajaxUrl:"",nonce:"n",rsvpWords:' . wp_json_encode( SFAF_Messages::form_words() ) . '};';
$tail = '<script src="vendor/jquery-3.7.1.min.js"></script><script>' . $words . '</script><script src="../public/js/calendar.js"></script>';

/* ---- H: the form, Twilio on. ---- */
SFAF_Texts::save( array( 'twilio_account_sid' => 'AC' . str_repeat( 'a', 32 ), 'twilio_messaging_sid' => 'MG' . str_repeat( 'b', 32 ) ), 'token0123456789' );
$ev   = rl_event( 'Support group' );
$form = $head . '<div class="uc-single"><div class="uc-single-card">' . sfaf_rsvp_block( $ev ) . '</div></div>' . $tail;

/* ---- I: an ended event in a series with two dates to come. ---- */
$past = rl_event( 'Support group', array( '_uc_event_date' => '2026-01-06' ) );
$soon = rl_event( 'Support group', array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+6 days' ) ) ) );
$next = rl_event( 'Support group', array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+13 days' ) ), '_uc_start_time' => '18:30' ) );
foreach ( array( $past, $soon, $next ) as $id ) { wp_set_object_terms( $id, array( 11 ), 'uc_series' ); }
$GLOBALS['kit_query'] = function ( $a ) use ( $past, $soon, $next ) { return array( $past, $soon, $next ); };
$GLOBALS['page_ev'] = $past;
ob_start();
include $GLOBALS['kit_root'] . '/templates/single-uc_event.php';
$ended = $head . (string) ob_get_clean() . $tail;
$want_dates = array( get_permalink( $soon ), get_permalink( $next ) );

$probe = <<<'JS'
(function () {
var P = window.RL_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function vt(el) { var w = el && el.querySelector('.uc-w2'); return w ? text(w.firstElementChild) : text(el); }
function seen(el) { if (!el || el.closest('[hidden]') || !el.getClientRects().length) { return false; } var cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; }
function inside(a, b) { var r = a.getBoundingClientRect(), c = b.getBoundingClientRect(); return r.left >= c.left - 0.5 && r.right <= c.right + 0.5 && r.right <= document.documentElement.clientWidth + 0.5; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
function tick(el, on) { el.checked = on; el.dispatchEvent(new Event('change', { bubbles: true })); }
function links() {
  var p = q('#uc-rsvp-privacy'), t = q('#uc-rsvp-terms');
  return { privacy: seen(p) ? vt(p) : '', terms: seen(t) ? vt(t) : '', href: t ? t.getAttribute('href') : '', target: t ? t.getAttribute('target') : '',
    sameLine: !!(p && t && seen(p) && seen(t) && Math.abs(p.getBoundingClientRect().bottom - t.getBoundingClientRect().bottom) < 30 && t.getBoundingClientRect().left >= 0),
    afterPrivacy: !!(p && t && (p.compareDocumentPosition(t) & Node.DOCUMENT_POSITION_FOLLOWING)) };
}
window.addEventListener('load', function () { setTimeout(function () {
  if (P.indexOf('form') === 0) {
    q('.uc-rsvp-btn').click();
    var sms = q('input[name="uc-rsvp-text-channel"][value="sms"]'), wa = q('input[name="uc-rsvp-text-channel"][value="whatsapp"]');
    out.closed = links();
    tick(sms, true); out.sms = links();
    tick(sms, false); tick(wa, true); out.wa = links();
    var es = q('[data-uc-lang-pick="es"]'); if (es) { es.click(); }
    out.es = links();
    finish();
    return;
  }
  var card = q('.uc-single-sidebar .uc-single-card'), block = q('[data-uc-ended]');
  out.line = block ? (seen(q('.uc-ended-line', block)) ? text(q('.uc-ended-line', block)) : '') : '';
  out.register = qa('.uc-rsvp-btn').length;
  out.addcal = qa('.uc-addcal').length;
  out.dates = qa('[data-uc-ended-dates] a').map(function (a) { return { href: a.getAttribute('href'), text: text(a), shown: seen(a), inCard: !!card && inside(a, card), h: Math.round(a.getBoundingClientRect().height) }; });
  out.lineInCard = !!(block && card && inside(block, card));
  finish();
}, 300); });
})();
JS;

$pages = array(
    'form-desktop'  => array( 1280, $form ),
    'form-phone'    => array( 390, $form ),
    'ended-desktop' => array( 1280, $ended ),
    'ended-phone'   => array( 390, $ended ),
);
$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31340-live-' . $name . '.html';
    file_put_contents( $inner, $p[1] . '<script>window.RL_PAGE=' . json_encode( $name ) . ';' . $probe . '</script></body></html>' );
    $files[ $name ] = __DIR__ . '/release-31340-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p[0] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
if ( ! in_array( '--run', array_slice( $argv, 1 ), true ) ) { echo 'wrote ' . count( $files ) . " pages; --run drives them.\n"; exit( $fails ? 1 : 0 ); }
$chrome = getenv( 'SFAF_CHROME' ) ?: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }

$g = array();
foreach ( $files as $name => $file ) {
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=1300,1000 --virtual-time-budget=15000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rl( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rl( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rl( (int) $g[ $name ]['pageWidth'] <= $pages[ $name ][0], "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . 'px in ' . $pages[ $name ][0] );
    if ( in_array( '--verbose', $argv, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}

$terms = 'https://www.sfaf.org/calendar/events-calendar-terms-conditions/';
foreach ( $g as $name => $r ) {
    if ( 0 === strpos( $name, 'form' ) ) {
        rl( '' === $r['closed']['terms'], "$name: Terms shows before Text or WhatsApp is ticked: " . json_encode( $r['closed'] ) );
        foreach ( array( 'sms' => 'Text', 'wa' => 'WhatsApp' ) as $k => $label ) {
            $l = $r[ $k ];
            rl( 'Privacy policy' === $l['privacy'] && 'Terms' === $l['terms'] && $terms === $l['href'] && '_blank' === $l['target'] && $l['afterPrivacy'] && $l['sameLine'],
                "PLANT H: $name: $label ticked, the links read " . json_encode( $l ) );
        }
        rl( 'Política de privacidad' === $r['es']['privacy'] && 'Términos' === $r['es']['terms'], "PLANT H: $name: in Spanish the links read " . json_encode( $r['es'], JSON_UNESCAPED_UNICODE ) );
        continue;
    }
    rl( 'This event has ended.' === $r['line'] && $r['lineInCard'], "PLANT I: $name: the line reads " . json_encode( $r['line'] ) . ( $r['lineInCard'] ? '' : ', outside its card' ) );
    rl( 0 === $r['register'] && 0 === $r['addcal'], "PLANT I: $name: Register " . $r['register'] . ', Add to Calendar ' . $r['addcal'] );
    rl( 2 === count( $r['dates'] ) && $want_dates === array_column( $r['dates'], 'href' ), "PLANT I: $name: the dates link " . json_encode( array_column( (array) $r['dates'], 'href' ) ) );
    foreach ( (array) $r['dates'] as $d ) {
        rl( $d['shown'] && $d['inCard'] && 1 === preg_match( '/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday), /', $d['text'] ),
            "PLANT I: $name: a date is " . json_encode( $d ) );
        rl( $d['h'] >= 24, "$name: a date link is " . $d['h'] . 'px tall' );
    }
}

echo "\n3.134.0: the Terms link and an ended event's page, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Terms beside the privacy policy with Text or WhatsApp ticked, in both languages; an ended event's page says so, takes no\nregistration and lists the series' next dates by weekday inside its card, at 1280px and 390px.\n";
exit( 0 );
