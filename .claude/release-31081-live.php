<?php
/**
 * 3.108.1 IN A REAL BROWSER: THE EDITORS' PICTURE, FAQs, NOTICES AND
 * NOTIFICATIONS ROW, ADD AND EDIT, AT 1280px AND 390px.
 *
 *     php .claude/release-31081-live.php            writes the pages, runs the PHP half
 *     php .claude/release-31081-live.php --run      and drives Chrome, checks
 *     php .claude/release-31081-live.php --shots    --run, and writes .claude/screens/editor-31081-*.png
 *
 *   A    the series panel's picture is 160 x 90 at the cards' 12px radius, and
 *        sits inside the card
 *   B.1  no input on either editor takes an image URL; a stored URL still
 *        shows and a save leaves it; Remove clears it
 *   B.2  one block: the preview, the pill on it saying "Event-specific",
 *        "From series" or "Placeholder", Remove on its top-right corner only
 *        while the picture is the event's own, and "Choose a picture" naming
 *        the picture, inherited ones included; Remove falls back to the series
 *        picture, or with no series to the empty tile
 *   C    four FAQs open shut, one open at a time, "+ Add FAQ" appends one
 *        open, a set's rows arrive shut, "New question" for an empty one
 *   D    the three inline notices are one component, measured equal and
 *        against the tokens
 *   E    the two Notifications rows: same width, height, border, radius and
 *        padding, the same panel padding, the label left and the chevron right
 */

require __DIR__ . '/wp-kit.php';
$GLOBALS['kit_thumbs'] = true;

$fails = array();
function rf_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function rf_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); }
    catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}

/* Pictures that decode, each its own colour. 601 is Alpha's, 602 Beta's, 603 untagged. */
$colours = array( 601 => '#0E818C', 602 => '#A30C33', 603 => '#8D54A2' );
foreach ( array( 601 => 11, 602 => 12, 603 => 0 ) as $pid => $sid ) {
    kit_write_post( array( 'ID' => $pid, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Picture ' . $pid ) );
    update_post_meta( $pid, '_wp_attached_file', SFAF_Media_Folder::prefix() . 'pic-' . $pid . '.jpg' );
    $GLOBALS['kit_images'][ $pid ] = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675"><rect width="1200" height="675" fill="' . $colours[ $pid ] . '"/></svg>' );
    if ( $sid ) { $GLOBALS['kit_terms'][ $pid ][ SFAF_Media::TAXONOMY ] = array( $sid ); }
}
update_option( SFAF_FAQ_Sets::OPTION, array(
    'setalpha' => array( 'name' => 'Alpha basics', 'rows' => array( array( 'question' => 'Is it free?', 'answer' => 'Yes.' ), array( 'question' => 'Is there food?', 'answer' => 'Yes.' ) ) ),
) );
$GLOBALS['kit_query'] = function ( $a ) {
    $out    = array();
    $type   = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $series = 0;
    if ( ! empty( $a['tax_query'] ) ) {
        foreach ( $a['tax_query'] as $c ) {
            if ( is_array( $c ) && isset( $c['taxonomy'] ) && 'uc_series' === $c['taxonomy'] ) { $series = (int) ( is_array( $c['terms'] ) ? reset( $c['terms'] ) : $c['terms'] ); }
        }
    }
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( $type !== $p->post_type ) { continue; }
        if ( $series && ! in_array( $series, isset( $GLOBALS['kit_terms'][ $id ]['uc_series'] ) ? $GLOBALS['kit_terms'][ $id ]['uc_series'] : array(), true ) ) { continue; }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
};
function rf_event( $title, $meta, $series ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1 ) );
    foreach ( array_merge( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    if ( $series ) { $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( $series ); }
    return $id;
}

/* Edit: in Alpha, its own picture 603, four FAQs, one with no question. */
$four = array(
    array( 'question' => 'Where do I park?', 'answer' => 'On the street.' ),
    array( 'question' => 'Is it wheelchair accessible?', 'answer' => 'Yes.' ),
    array( 'question' => '', 'answer' => 'An answer still waiting for its question.' ),
    array( 'question' => 'Can I bring a friend?', 'answer' => 'Yes.' ),
);
$e_own    = rf_event( 'Community dinner', array( '_thumbnail_id' => 603, sfaf_faq_meta_key() => $four, '_uc_image_override' => '1' ), 11 );
$e_series = rf_event( 'Alpha lunch', array(), 11 );
$e_none   = rf_event( 'Standalone talk', array( '_thumbnail_id' => 601, '_uc_image_override' => '1' ), 0 );
$url      = 'https://example.org/pictures/old-banner.jpg';
$e_url    = rf_event( 'Old event', array( '_uc_image_url' => $url, '_uc_image_url_typed' => $url, '_uc_image_override' => '1' ), 0 );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
function rf_screen( $id ) {
    return rf_capture( function () use ( $id ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $GLOBALS['portal'], array( $GLOBALS['user'], $id ) ); } );
}
$add  = rf_screen( 0 );
$own  = rf_screen( $e_own );
$ser  = rf_screen( $e_series );
$none = rf_screen( $e_none );
$old  = rf_screen( $e_url );

/* ---- B.1, the server half --------------------------------------------- */
foreach ( array( 'Add' => $add, 'Edit' => $own, 'Edit with a stored URL' => $old ) as $what => $html ) {
    rf_check( 0 === strpos( $html, 'THREW' ) ? false : true, "$what could not be rendered: " . substr( $html, 0, 200 ) );
    rf_check( false === strpos( $html, 'name="image_url"' ) && false === strpos( $html, 'data-uc-image-url' ) && false === stripos( $html, 'Or an image URL' ),
        "PLANT B.1: $what event renders an image URL box" );
}
/* A save that posts no image_url leaves the stored URL; Remove clears it. */
$_POST = array( 'featured_image_id' => '0' );
kit_call( 'SFAF_Portal', 'save_manager_fields_from_post', $portal, array( $user, $e_url, function () { return false; } ) );
rf_check( $url === get_post_meta( $e_url, '_uc_image_url', true ), 'B.1: a save with no image_url posted dropped the stored URL' );
$_POST = array( 'featured_image_id' => '0', 'reset_series_image' => '1' );
kit_call( 'SFAF_Portal', 'save_manager_fields_from_post', $portal, array( $user, $e_url, function () { return false; } ) );
rf_check( '' === (string) get_post_meta( $e_url, '_uc_image_url', true ), 'B.2: Remove (reset_series_image) did not clear the stored URL' );
$_POST = array();

$pages = array(
    'add-desktop'     => array( 'w' => 1280, 'html' => $add ),
    'add-phone'       => array( 'w' => 390,  'html' => $add ),
    'own-desktop'     => array( 'w' => 1280, 'html' => $own ),
    'own-phone'       => array( 'w' => 390,  'html' => $own ),
    'series-desktop'  => array( 'w' => 1280, 'html' => $ser ),
    'series-phone'    => array( 'w' => 390,  'html' => $ser ),
    'none-desktop'    => array( 'w' => 1280, 'html' => $none ),
    'none-phone'      => array( 'w' => 390,  'html' => $none ),
    'url-desktop'     => array( 'w' => 1280, 'html' => $old ),
);

$probe = <<<'JS'
(function () {
var P = window.RV_PAGE, out = { page: P, errors: [] };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
function q(s, r) { return (r || document).querySelector(s); }
function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
function seen(el) { return !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'; }
function rect(el) { if (!el) { return null; } var r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; }
function css(el, props) { if (!el) { return null; } var s = getComputedStyle(el), o = {}; props.forEach(function (p) { o[p] = s[p]; }); return o; }
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }

/* The block's state as the screen shows it. */
function block() {
  var b = q('[data-uc-image-block]'); if (!b) { return null; }
  var pv = q('[data-uc-image-preview]', b), img = q('[data-uc-image-preview-img]', b), pill = q('[data-uc-img-source-tag]', b), rm = q('[data-uc-image-remove]', b);
  return { state: b.getAttribute('data-uc-image-state'), pill: text(pill), removeSeen: seen(rm), imgSeen: seen(img), src: img && !img.hidden ? (img.getAttribute('src') || '').slice(0, 60) : '',
    name: text(q('[data-uc-image-current] .uc-image-current-name', b)), reset: q('[data-uc-image-reset]', b) ? q('[data-uc-image-reset]', b).disabled : null,
    preview: rect(pv), pillBox: rect(pill), removeBox: seen(rm) ? rect(rm) : null, empty: pv ? pv.classList.contains('uc-image-preview-empty') : null,
    pillCss: css(pill, ['fontSize', 'fontWeight', 'color', 'backgroundColor', 'borderTopColor']),
    removeCss: seen(rm) ? css(rm, ['fontSize', 'fontWeight', 'color', 'backgroundColor', 'borderTopColor']) : null,
    previewCss: css(pv, ['backgroundColor', 'borderTopColor', 'borderTopWidth', 'borderLeftWidth']), thumbInSummary: seen(q('.uc-image-current-thumb', b)) };
}
function notice(el) { return el ? css(el, ['backgroundColor', 'color', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'fontSize', 'fontWeight', 'lineHeight', 'borderTopLeftRadius', 'marginTop']) : null; }
function faqs() {
  return qa('[data-uc-card="faqs"] [data-uc-faq-fold]').map(function (r) {
    var t = q('[data-uc-faq-toggle]', r);
    return { head: text(q('[data-uc-faq-head]', r)), open: seen(q('[data-uc-faq-body]', r)), toggle: t ? text(t) : '', exp: t ? t.getAttribute('aria-expanded') : '',
      x: rect(q('.uc-faq-x', r)), xLabel: (q('.uc-faq-x', r) || { getAttribute: function () { return ''; } }).getAttribute('aria-label'), box: rect(r) };
  });
}

window.addEventListener('load', function () { setTimeout(function () {
  /* B.1: every input on the page. */
  out.urlInputs = qa('input').filter(function (i) {
    var n = (i.name || '') + ' ' + (i.id || '') + ' ' + (i.placeholder || '');
    return i.hasAttribute('data-uc-image-url') || /image_?url|image\.jpg/i.test(n) || (i.type === 'url' && /image|picture/i.test(n));
  }).map(function (i) { return i.name || i.id; });

  out.block = block();
  out.faqStart = faqs();
  out.faqHeadCss = css(q('[data-uc-faq-head]'), ['fontSize', 'fontWeight', 'color']);
  out.faqToggleCss = css(q('[data-uc-faq-toggle]'), ['fontSize', 'fontWeight', 'color']);

  /* E: the two Notifications rows. */
  var kinds = q('.uc-notify-kinds'), pick = q('[data-uc-notify-picker] .uc-picker');
  function row(d) {
    if (!d) { return null; }
    var s = q(':scope > summary', d), ch = s ? q('.uc-disclosure-chevron', s) : null, lb = s ? q('.uc-picker-label', s) : null;
    return { box: rect(d), summary: rect(s), chevron: rect(ch), label: rect(lb), labelText: text(lb),
      css: css(d, ['borderTopColor', 'borderTopWidth', 'borderTopLeftRadius', 'backgroundColor']),
      scss: css(s, ['paddingTop', 'paddingLeft', 'paddingRight', 'fontSize', 'fontWeight', 'color']) };
  }
  out.kinds = row(kinds); out.pick = row(pick);
  function panelPad(d) { var pn = d ? q(':scope > .uc-picker-panel', d) : null; return pn ? css(pn, ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft']) : null; }
  out.kindsPanel = panelPad(kinds); out.pickPanel = panelPad(pick);

  /* D: the description's notice, shown as the editor would show it. */
  var ds = q('[data-uc-desc-status]');
  if (ds) { ds.textContent = 'Added to the description.'; ds.hidden = false; out.noticeDesc = notice(ds); out.noticeDescClass = ds.className; ds.hidden = true; }

  if (P.indexOf('add') === 0) {
    /* A, and D for the prefill. */
    var s = q('[data-uc-series-select]');
    s.value = '11'; s.dispatchEvent(new Event('change', { bubbles: true }));
    var th = q('[data-uc-card="series"] .uc-prefill-thumb'), card = q('[data-uc-card="series"]');
    out.thumbEl = !!th;
    setTimeout(function () {
      out.thumb = th && th.complete && th.naturalWidth > 0 ? { box: rect(th), radius: getComputedStyle(th).borderTopLeftRadius } : null;
      out.seriesCard = rect(card);
      out.blockAfterSeries = block();
      var apply = q('[data-uc-prefill-apply]'); if (apply) { apply.click(); }
      var said = q('[data-uc-prefill-said]');
      out.noticePrefill = seen(said) ? notice(said) : null; out.noticePrefillText = text(said);
      /* + Add FAQ on a new event. */
      var addBtn = q('[data-uc-card="faqs"] .uc-repeater-add'); if (addBtn) { addBtn.click(); }
      out.faqAfterAdd = faqs();
      out.faqAddFocus = document.activeElement && document.activeElement.matches('[data-uc-faq-body] input[type="text"]');
      finish();
    }, 400);
    return;
  }

  if (P.indexOf('own') === 0) {
    /* C: one open at a time. */
    var toggles = qa('[data-uc-card="faqs"] [data-uc-faq-toggle]');
    if (toggles[1]) { toggles[1].click(); } out.faqOpen2 = faqs();
    if (toggles[2]) { toggles[2].click(); } out.faqOpen3 = faqs();
    if (toggles[2]) { toggles[2].click(); } out.faqClosedAgain = faqs();
    /* typing changes the head */
    if (toggles[0]) { toggles[0].click(); var qi = q('[data-uc-card="faqs"] [data-uc-faq-fold] [data-uc-faq-body] input[type="text"]'); qi.value = 'Where can I park nearby?'; qi.dispatchEvent(new Event('input', { bubbles: true })); out.faqTyped = faqs()[0].head; toggles[0].click(); }
    /* a set's rows arrive shut, and say so */
    var set = q('[data-uc-faq-set]'); if (set) { set.value = 'setalpha'; set.dispatchEvent(new Event('change', { bubbles: true })); }
    var ap = q('[data-uc-faq-apply]'); if (ap) { ap.click(); }
    setTimeout(function () {
    out.faqAfterSet = faqs();
    var fsaid = q('[data-uc-faq-said]'); out.noticeFaq = seen(fsaid) ? notice(fsaid) : null; out.noticeFaqText = text(fsaid);
    /* B.2: Remove falls back to the series picture. */
    var rm = q('[data-uc-image-remove]'); if (rm) { rm.click(); }
    out.blockAfterRemove = block();
    out.focusAfterRemove = document.activeElement ? document.activeElement.tagName : '';
    /* and choosing again makes it the event's own */
    var r603 = q('[data-uc-image-block] input[data-uc-image-option][value="603"]'); if (r603) { r603.checked = true; r603.dispatchEvent(new Event('change', { bubbles: true })); }
    out.blockAfterChoose = block();
    finish();
    }, 50);
    return;
  }
  if (P.indexOf('series') === 0) {
    var r602 = q('[data-uc-image-block] input[data-uc-image-option][value="602"]'); if (r602) { r602.checked = true; r602.dispatchEvent(new Event('change', { bubbles: true })); }
    out.blockAfterChoose = block();
    var s2 = q('[data-uc-series-select]'); if (s2) { s2.value = '12'; s2.dispatchEvent(new Event('change', { bubbles: true })); }
    out.blockOwnAfterSeries = block();
    finish(); return;
  }
  if (P.indexOf('none') === 0) {
    var rm2 = q('[data-uc-image-remove]'); if (rm2) { rm2.click(); }
    out.blockAfterRemove = block();
    finish(); return;
  }
  finish();
}, 600); });
})();
JS;

$files = array();
foreach ( $pages as $name => $p ) {
    if ( 0 === strpos( $p['html'], 'THREW' ) ) { rf_check( false, "$name could not be rendered: " . $p['html'] ); continue; }
    $inject = '<script>window.RV_PAGE=' . json_encode( $name ) . ';' . $probe . '</script>';
    $inner  = __DIR__ . '/release-31081-live-' . $name . '.html';
    file_put_contents( $inner, preg_replace( '#</body>#i', $inject . '</body>', $p['html'], 1 ) );
    $files[ $name ] = __DIR__ . '/release-31081-live-' . $name . '-frame.html';
    file_put_contents( $files[ $name ], '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="' . basename( $inner ) . '" style="display:block;width:' . (int) $p['w'] . 'px;height:900px;border:0"></iframe>'
        . '<script>window.addEventListener("message",function(e){var p=document.createElement("pre");p.id="out";p.textContent=e.data;document.body.appendChild(p);});</script></body></html>' );
}
$args  = array_slice( $argv, 1 );
$shots = in_array( '--shots', $args, true );
if ( ! in_array( '--run', $args, true ) && ! $shots ) {
    if ( $fails ) { echo 'RELEASE 3.108.1 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
    echo 'wrote ' . count( $files ) . " pages; --run drives them.\n";
    exit( 0 );
}
$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
$g = array();
foreach ( $files as $name => $file ) {
    $w   = $pages[ $name ]['w'];
    $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --run-all-compositor-stages-before-draw --allow-file-access-from-files --window-size=' . max( 600, $w + 20 ) . ',1000 --virtual-time-budget=10000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
    if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { rf_check( false, "$name: Chrome returned no probe block" ); continue; }
    $g[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
    rf_check( empty( $g[ $name ]['errors'] ), "$name threw: " . implode( '; ', (array) $g[ $name ]['errors'] ) );
    rf_check( (int) $g[ $name ]['pageWidth'] <= $w, "$name: the page scrolls sideways, " . $g[ $name ]['pageWidth'] . "px in $w" );
    if ( in_array( '--verbose', $args, true ) ) { echo $name . ': ' . json_encode( $g[ $name ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; }
}
$v = function ( $name, $key ) use ( $g ) { return isset( $g[ $name ][ $key ] ) ? $g[ $name ][ $key ] : null; };

/* DESIGN.md's tokens, as the browser reports them. */
$P_TEXT    = 'rgb(55, 52, 51)';     // --p-text #373433
$P_MUTED   = 'rgb(107, 103, 100)';  // --p-muted #6B6764
$P_PANEL   = 'rgb(255, 255, 255)';  // --p-panel
$P_BG      = 'rgb(245, 246, 247)';  // --p-bg
$P_BORDER  = 'rgb(226, 229, 234)';  // --p-border
$P_STRONG  = 'rgb(140, 141, 142)';  // --p-border-strong
$ACCENT    = 'rgb(14, 118, 128)';   // --uc-accent-text
$OK_TINT   = 'rgb(241, 248, 233)';  // --p-ok-tint #F1F8E9
$OK_INK    = 'rgb(70, 102, 31)';    // --p-ok-ink #46661F
$PIC_NAME  = 'Picture 601';         // Alpha's picture, as the picker names it

foreach ( array_keys( $g ) as $t ) {
    $phone = false !== strpos( $t, 'phone' );

    /* B.1 */
    rf_check( array() === $v( $t, 'urlInputs' ), "PLANT B.1: $t: an input takes an image URL: " . json_encode( $v( $t, 'urlInputs' ) ) );

    /* B.2, the block wherever it is drawn. */
    $b = (array) $v( $t, 'block' );
    if ( $b ) {
        rf_check( false === $b['thumbInSummary'], "$t: Choose a picture draws a second thumbnail" );
        rf_check( array( 'fontSize' => '12px', 'fontWeight' => '400', 'color' => $P_TEXT, 'backgroundColor' => $P_PANEL, 'borderTopColor' => $P_BORDER ) === $b['pillCss'],
            "$t: the pill is not 12px/400 --p-text on --p-panel: " . json_encode( $b['pillCss'] ) );
        if ( $b['pillBox'] && $b['preview'] ) {
            $bw = (int) $b['previewCss']['borderTopWidth'];
            rf_check( 8 + $bw === $b['pillBox']['left'] - $b['preview']['left'] && 8 + $bw === $b['pillBox']['top'] - $b['preview']['top'],
                "$t: the pill is not on the preview's top-left corner: " . json_encode( array( $b['pillBox'], $b['preview'] ) ) );
        }
        if ( $b['removeBox'] ) {
            rf_check( 8 === $b['preview']['right'] - $b['removeBox']['right'] && 8 === $b['removeBox']['top'] - $b['preview']['top'],
                "$t: Remove is not on the preview's top-right corner: " . json_encode( array( $b['removeBox'], $b['preview'] ) ) );
            rf_check( array( 'fontSize' => '13px', 'fontWeight' => '600', 'color' => $P_TEXT, 'backgroundColor' => $P_PANEL, 'borderTopColor' => $P_STRONG ) === $b['removeCss'],
                "$t: Remove is not the small control in DESIGN.md: " . json_encode( $b['removeCss'] ) );
        }
    }

    /* E, wherever both rows are drawn. */
    $k = (array) $v( $t, 'kinds' ); $p = (array) $v( $t, 'pick' );
    if ( $k && $p ) {
        rf_check( $k['box']['w'] === $p['box']['w'] && $k['box']['left'] === $p['box']['left'] && $k['summary']['h'] === $p['summary']['h'] && $k['css'] === $p['css'] && $k['scss'] === $p['scss'],
            "PLANT E: $t: the Notifications rows differ: " . json_encode( array( 'kinds' => $k, 'pick' => $p ) ) );
        rf_check( 0 === strpos( (string) $k['labelText'], 'Emails for this event:' ) && $k['label'] && $k['chevron'] && $k['label']['left'] < $k['chevron']['left']
            && abs( ( $k['summary']['right'] - $k['chevron']['right'] ) - ( $p['summary']['right'] - $p['chevron']['right'] ) ) <= 1,
            "PLANT E: $t: the emails row is not label left, chevron right: " . json_encode( $k ) );
        rf_check( null !== $v( $t, 'kindsPanel' ) && $v( $t, 'kindsPanel' ) === $v( $t, 'pickPanel' ), "PLANT E: $t: the two rows' panels are padded differently: " . json_encode( array( $v( $t, 'kindsPanel' ), $v( $t, 'pickPanel' ) ) ) );
    }

    /* D: the description's notice is the component. */
    if ( null !== $v( $t, 'noticeDesc' ) ) {
        $nd = (array) $v( $t, 'noticeDesc' );
        rf_check( $OK_TINT === $nd['backgroundColor'] && $OK_INK === $nd['color'] && '10px' === $nd['paddingTop'] && '14px' === $nd['paddingLeft'] && '13px' === $nd['fontSize'] && '600' === $nd['fontWeight'] && '8px' === $nd['borderTopLeftRadius'] && '10px' === $nd['marginTop'],
            "PLANT D: $t: the description's notice is not the component: " . json_encode( $nd ) );
    }
}

/* ---- A ------------------------------------------------------------------ */
foreach ( array( 'add-desktop', 'add-phone' ) as $t ) {
    $th = (array) $v( $t, 'thumb' ); $card = (array) $v( $t, 'seriesCard' );
    rf_check( $th && 160 === $th['box']['w'] && 90 === $th['box']['h'] && '12px' === $th['radius'],
        "PLANT A: $t: the series panel's picture is " . json_encode( $th ) . ', not 160 x 90 at 12px' );
    rf_check( $th && $card && $th['box']['right'] <= $card['right'] && $th['box']['left'] >= $card['left'], "$t: the series picture leaves its card" );
    $ab = (array) $v( $t, 'blockAfterSeries' );
    rf_check( 'series' === ( $ab['state'] ?? '' ) && 'From series' === ( $ab['pill'] ?? '' ) && $PIC_NAME === ( $ab['name'] ?? '' ) && false === ( $ab['removeSeen'] ?? null ),
        "PLANT B.2: $t: choosing a series on Add did not show its picture as From series: " . json_encode( $ab ) );
    $b0 = (array) $v( $t, 'block' );
    rf_check( 'Placeholder' === ( $b0['pill'] ?? '' ) && true === ( $b0['empty'] ?? null ) && $P_BG === ( $b0['previewCss']['backgroundColor'] ?? '' ) && 'No picture chosen' === ( $b0['name'] ?? '' ),
        "PLANT B.2: $t: Add event with no series is not the empty tile and Placeholder: " . json_encode( $b0 ) );

    /* D */
    $np = (array) $v( $t, 'noticePrefill' );
    rf_check( $np && $np === $v( $t, 'noticeDesc' ), "PLANT D: $t: the prefill notice and the description notice differ: " . json_encode( array( $np, $v( $t, 'noticeDesc' ) ) ) );
    rf_check( 0 === strpos( (string) $v( $t, 'noticePrefillText' ), 'Filled in ' ), "$t: the prefill notice reads " . json_encode( $v( $t, 'noticePrefillText' ) ) );

    /* C: + Add FAQ appends one open, with the cursor in it. */
    $fa = (array) $v( $t, 'faqAfterAdd' );
    rf_check( 1 === count( $fa ) && true === $fa[0]['open'] && 'New question' === $fa[0]['head'] && 'Close' === $fa[0]['toggle'] && true === $v( $t, 'faqAddFocus' ),
        "PLANT C: $t: + Add FAQ did not append one open row with the cursor in it: " . json_encode( $fa ) );
}

/* ---- B.2 and C on Edit ------------------------------------------------ */
foreach ( array( 'own-desktop', 'own-phone' ) as $t ) {
    $b = (array) $v( $t, 'block' );
    rf_check( 'event' === $b['state'] && 'Event-specific' === $b['pill'] && true === $b['removeSeen'] && 'Picture 603' === $b['name'] && true === $b['reset'],
        "PLANT B.2: $t: an event's own picture does not read Event-specific with Remove: " . json_encode( $b ) );
    $r = (array) $v( $t, 'blockAfterRemove' );
    rf_check( 'series' === $r['state'] && 'From series' === $r['pill'] && false === $r['removeSeen'] && $PIC_NAME === $r['name'] && false === $r['reset'] && true === $r['imgSeen'],
        "PLANT B.2: $t: Remove did not fall back to the series picture: " . json_encode( $r ) );
    rf_check( 'SUMMARY' === $v( $t, 'focusAfterRemove' ), "$t: after Remove focus is on " . $v( $t, 'focusAfterRemove' ) );
    $c = (array) $v( $t, 'blockAfterChoose' );
    rf_check( 'event' === $c['state'] && 'Event-specific' === $c['pill'] && true === $c['removeSeen'] && true === $c['reset'],
        "PLANT B.2: $t: choosing a picture again is not Event-specific: " . json_encode( $c ) );

    /* C */
    $f = (array) $v( $t, 'faqStart' );
    rf_check( 4 === count( $f ) && array( 'Where do I park?', 'Is it wheelchair accessible?', 'New question', 'Can I bring a friend?' ) === array_column( $f, 'head' )
        && array( false, false, false, false ) === array_column( $f, 'open' ) && array( 'Edit', 'Edit', 'Edit', 'Edit' ) === array_column( $f, 'toggle' ) && array( 'false', 'false', 'false', 'false' ) === array_column( $f, 'exp' ),
        "PLANT C: $t: four FAQs do not open shut with their questions: " . json_encode( $f ) );
    foreach ( $f as $i => $row ) {
        rf_check( 'Remove this question' === $row['xLabel'] && $row['x'] && 32 === $row['x']['w'] && 32 === $row['x']['h'] && $row['x']['right'] <= $row['box']['right'],
            "$t: FAQ " . ( $i + 1 ) . "'s remove icon: " . json_encode( $row['x'] ) );
    }
    $o2 = array_column( (array) $v( $t, 'faqOpen2' ), 'open' );
    $o3 = array_column( (array) $v( $t, 'faqOpen3' ), 'open' );
    rf_check( array( false, true, false, false ) === $o2 && array( false, false, true, false ) === $o3 && array( false, false, false, false ) === array_column( (array) $v( $t, 'faqClosedAgain' ), 'open' ),
        "PLANT C: $t: more than one FAQ open, or one would not close: " . json_encode( array( $o2, $o3 ) ) );
    rf_check( 'Where can I park nearby?' === $v( $t, 'faqTyped' ), "$t: the head did not follow the question: " . json_encode( $v( $t, 'faqTyped' ) ) );
    $fs = (array) $v( $t, 'faqAfterSet' );
    rf_check( 6 === count( $fs ) && array( 'Is it free?', 'Is there food?' ) === array( $fs[4]['head'], $fs[5]['head'] ) && false === $fs[4]['open'] && false === $fs[5]['open'],
        "PLANT C: $t: a set's rows did not arrive shut: " . json_encode( $fs ) );
    rf_check( array( 'fontSize' => '14px', 'fontWeight' => '400', 'color' => $P_TEXT ) === $v( $t, 'faqHeadCss' ) && array( 'fontSize' => '13px', 'fontWeight' => '600', 'color' => $ACCENT ) === $v( $t, 'faqToggleCss' ),
        "$t: the FAQ head is not Body type with an accent Edit: " . json_encode( array( $v( $t, 'faqHeadCss' ), $v( $t, 'faqToggleCss' ) ) ) );

    /* D */
    rf_check( $v( $t, 'noticeFaq' ) && $v( $t, 'noticeFaq' ) === $v( $t, 'noticeDesc' ) && 0 === strpos( (string) $v( $t, 'noticeFaqText' ), 'Added 2 questions from' ),
        "PLANT D: $t: the FAQ notice is not the component: " . json_encode( array( $v( $t, 'noticeFaq' ), $v( $t, 'noticeFaqText' ) ) ) );
}
foreach ( array( 'series-desktop', 'series-phone' ) as $t ) {
    $b = (array) $v( $t, 'block' );
    rf_check( 'series' === $b['state'] && 'From series' === $b['pill'] && false === $b['removeSeen'] && $PIC_NAME === $b['name'],
        "PLANT B.2: $t: an inherited picture does not read From series, named: " . json_encode( $b ) );
    $c = (array) $v( $t, 'blockAfterChoose' );
    rf_check( 'event' === $c['state'] && 'Event-specific' === $c['pill'] && true === $c['removeSeen'] && 'Picture 602' === $c['name'],
        "PLANT B.2: $t: choosing a picture did not make it Event-specific: " . json_encode( $c ) );
    $s = (array) $v( $t, 'blockOwnAfterSeries' );
    rf_check( 'event' === $s['state'] && 'Event-specific' === $s['pill'], "PLANT B.2: $t: changing the series overrode a chosen picture: " . json_encode( $s ) );
}
foreach ( array( 'none-desktop', 'none-phone' ) as $t ) {
    $b = (array) $v( $t, 'block' );
    rf_check( 'Event-specific' === $b['pill'] && true === $b['removeSeen'], "PLANT B.2: $t: an own picture with no series: " . json_encode( $b ) );
    $r = (array) $v( $t, 'blockAfterRemove' );
    rf_check( 'none' === $r['state'] && 'Placeholder' === $r['pill'] && false === $r['removeSeen'] && false === $r['imgSeen'] && true === $r['empty'] && 'No picture chosen' === $r['name']
        && $r['preview'] && abs( $r['preview']['w'] * 9 / 16 - $r['preview']['h'] ) <= 1 && $P_BG === $r['previewCss']['backgroundColor'] && $P_BORDER === $r['previewCss']['borderTopColor'],
        "PLANT B.2: $t: Remove with no series did not leave the placeholder tile: " . json_encode( $r ) );
}
$b = (array) $v( 'url-desktop', 'block' );
rf_check( 'event' === ( $b['state'] ?? '' ) && 'Event-specific' === ( $b['pill'] ?? '' ) && true === ( $b['removeSeen'] ?? null ) && 'old-banner.jpg' === ( $b['name'] ?? '' ) && 0 === strpos( (string) ( $b['src'] ?? '' ), 'https://example.org/pictures/old-banner.jpg' ),
    'PLANT B.2: a picture stored as a URL does not still show as the event\'s own, named: ' . json_encode( $b ) );

/* Screenshots for DESIGN.md. */
if ( $shots ) {
    @mkdir( __DIR__ . '/screens' );
    $subjects = array(
        'series-panel' => array( $add, "var s=q('[data-uc-series-select]');s.value='11';s.dispatchEvent(new Event('change',{bubbles:true}));toTop(q('[data-uc-card=\"series\"]'));" ),
        'image-own'    => array( $own, "toTop(q('.uc-image-field'));" ),
        'image-series' => array( $own, "q('[data-uc-image-remove]').click();document.activeElement.blur();toTop(q('.uc-image-field'));" ),
        'faqs-closed'  => array( $own, "toTop(q('[data-uc-card=\"faqs\"]'));" ),
        'faqs-open'    => array( $own, "qa('[data-uc-faq-toggle]')[1].click();toTop(q('[data-uc-card=\"faqs\"]'));" ),
        'notifications'=> array( $own, "toTop(q('[data-uc-card=\"notifications\"]'));" ),
    );
    foreach ( $subjects as $subject => $s ) {
        $js = "<script>function q(s){return document.querySelector(s);}function qa(s){return Array.prototype.slice.call(document.querySelectorAll(s));}"
            . "function toTop(el){window.scrollTo(0,Math.max(0,window.pageYOffset+el.getBoundingClientRect().top-16));}"
            . "window.addEventListener('load',function(){setTimeout(function(){" . $s[1] . "},600);});</script>";
        $inner = __DIR__ . "/release-31081-shot-$subject.html";
        file_put_contents( $inner, preg_replace( '#</body>#i', $js . '</body>', $s[0], 1 ) );
        foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
            $png   = __DIR__ . "/screens/editor-31081-$subject-$wn.png";
            $frame = __DIR__ . "/release-31081-shot-$subject-$wn-frame.html";
            file_put_contents( $frame, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0"><iframe src="' . basename( $inner ) . '" style="display:block;width:' . $w . 'px;height:900px;border:0"></iframe></body></html>' );
            shell_exec( '"' . $chrome . '" --headless --disable-gpu --hide-scrollbars --allow-file-access-from-files --window-size=' . max( 504, $w ) . ',900 --virtual-time-budget=6000 --screenshot="' . $png . '" "file:///' . str_replace( '\\', '/', $frame ) . '" 2>NUL' );
            rf_check( file_exists( $png ), "no screenshot written for $subject $wn" );
        }
    }
}

if ( $fails ) {
    echo 'RELEASE 3.108.1 LIVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "3.108.1 live: the series panel's picture at 160 x 90; no image URL box on either editor, a stored URL kept and shown, Remove clearing it; the picture block's pill, corner Remove and named picture in every state; four FAQs shut, one open at a time, + Add FAQ open, a set's rows shut; the three notices one component; the two Notifications rows alike, on Add and Edit at 1280px and 390px.\n";
