<?php
/**
 * 3.112.1 IN A BROWSER: THE CENTRED DIALOGS (items 4 and 5d).
 *
 *     php .claude/release-31121-live.php --run
 *
 * REAL: Edit event (an event in a repeating group of three) and Series &
 * Categories with two tags, rendered through wp-kit.php, with the real
 * portal.css and portal.js, in Chrome at 1280px and 390px.
 *
 *   - Cancel event, Delete and a tag's Delete each open a modal <dialog>
 *     centred in the window, holding the panel; Keep closes it and puts the
 *     panel back where it was, hidden; a click on the dim closes it too
 *   - the cancel dialog asks which dates, and with nobody to tell, its own
 *     Cancel this event submits the cancel form with no second question
 *   - Delete's submit belongs to the delete form while it is in the dialog
 *   - WITH NO SCRIPT the panels are on the page, Keep is hidden, and every
 *     submit is there to press
 *
 * PLANT 4L in plant-31121.php leaves the panels visible with the script on.
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
        $ok = true;
        foreach ( isset( $a['meta_query'] ) ? (array) $a['meta_query'] : array() as $c ) {
            if ( ! is_array( $c ) || empty( $c['key'] ) ) { continue; }
            $v = (string) get_post_meta( $id, $c['key'], true );
            $cmp = isset( $c['compare'] ) ? $c['compare'] : '=';
            if ( ( '=' === $cmp && $v !== (string) $c['value'] ) || ( '>=' === $cmp && $v < (string) $c['value'] ) ) { $ok = false; }
        }
        foreach ( isset( $a['tax_query'] ) ? (array) $a['tax_query'] : array() as $c ) {
            if ( ! is_array( $c ) || empty( $c['taxonomy'] ) ) { continue; }
            $have = isset( $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] : array();
            if ( ! array_intersect( array_map( 'intval', (array) $c['terms'] ), array_map( 'intval', $have ) ) ) { $ok = false; }
        }
        if ( $ok ) { $out[] = $p; }
    }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'ID' ) : $out;
}
$GLOBALS['kit_query'] = 'rl_query'; $GLOBALS['kit_get_posts'] = 'rl_query';
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$ids = array();
foreach ( array( 7, 14, 21 ) as $n ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => 1, 'post_content' => '<p>Weekly.</p>' ) );
    foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+' . $n . ' days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street', SFAF_Recurrence::GROUP_META => 'g1' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $ids[] = $id;
}
$GLOBALS['kit_tax_terms']['uc_tag'] = array();
foreach ( array( 31 => array( 'Food provided', 'food' ), 32 => array( 'Giveaways', 'giveaways' ) ) as $tid => $nt ) {
    $t = new WP_Term(); $t->term_id = $tid; $t->term_taxonomy_id = $tid; $t->name = $nt[0]; $t->slug = $nt[1]; $t->taxonomy = 'uc_tag';
    $GLOBALS['kit_tax_terms']['uc_tag'][] = $t;
}
$GLOBALS['kit_terms'][ $ids[0] ]['uc_tag'] = array( 31 );

$edit   = rl_cap( function () use ( $portal, $ids ) { $_GET = array( 'edit_scope' => 'this' ); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $ids[0] ) ); } );
$series = rl_cap( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_series_list', $portal, array( new WP_User() ) ); } );
rl( 0 !== strpos( $edit, 'THREW' ), "Edit event did not render: $edit" );
rl( 0 !== strpos( $series, 'THREW' ), "Series & Categories did not render: $series" );

$probe = <<<'JS'
(function () {
var out = { errors: [], steps: {} };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
window.__submitted = [];
HTMLFormElement.prototype.requestSubmit = function () { window.__submitted.push(this.id || '(no id)'); };
HTMLFormElement.prototype.submit = function () { window.__submitted.push(this.id || '(no id)'); };
function q(s) { return document.querySelector(s); }
function shown(el) { return !!el && !el.hidden && el.getClientRects().length > 0; }
function openDialog() { return q('dialog[open]'); }
function centred(d) {
  var r = d.getBoundingClientRect();
  return { dx: Math.round(Math.abs((r.left + r.right) / 2 - window.innerWidth / 2)), dy: Math.round(Math.abs((r.top + r.bottom) / 2 - window.innerHeight / 2)),
    w: Math.round(r.width), h: Math.round(r.height), vw: window.innerWidth, vh: window.innerHeight };
}
function finish() { out.pageWidth = document.documentElement.scrollWidth; window.parent.postMessage(JSON.stringify(out), '*'); }
function wait(ms, fn) { setTimeout(fn, ms); }
var mode = window.RL_MODE;
window.addEventListener('load', function () { wait(300, function () {
  var panels = Array.prototype.slice.call(document.querySelectorAll('[data-uc-dialog-panel]'));
  out.panels = panels.map(function (p) { return { id: p.id, shown: shown(p) }; });
  out.keeps = Array.prototype.slice.call(document.querySelectorAll('[data-uc-dialog-close]')).map(function (b) { return shown(b); });
  out.submits = Array.prototype.slice.call(document.querySelectorAll('[data-uc-dialog-panel] button[type="submit"]')).map(function (b) { return { text: b.textContent.trim(), shown: shown(b), form: b.form ? b.form.id : '' }; });
  if (mode === 'nojs') { finish(); return; }
  var openers = Array.prototype.slice.call(document.querySelectorAll('[data-uc-dialog-open]'));
  var i = 0;
  (function next() {
    if (i >= openers.length) { finish(); return; }
    var btn = openers[i++];
    var id = btn.getAttribute('data-uc-dialog-open');
    var panel = document.getElementById(id);
    var home = panel ? panel.parentNode : null;
    btn.click();
    wait(150, function () {
      var d = openDialog();
      var s = out.steps[id] = { opened: !!d, holds: !!(d && d.contains(panel)), shown: shown(panel), dialogs: document.querySelectorAll('dialog').length };
      if (!d) { next(); return; }
      s.box = centred(d);
      s.title = (panel.querySelector('.uc-dialog-title') || {}).textContent;
      s.sizes = [parseFloat(getComputedStyle(panel.querySelector('.uc-dialog-title')).fontSize), parseFloat(getComputedStyle(panel.querySelector('.uc-dialog-lead')).fontSize)];
      s.lead = ((panel.querySelector('.uc-dialog-lead') || {}).textContent || '').replace(/\s+/g, ' ').trim();
      s.keep = shown(panel.querySelector('[data-uc-dialog-close]'));
      s.focusKeep = document.activeElement === panel.querySelector('[data-uc-dialog-close]');
      s.scope = panel.querySelectorAll('input[name="cancel_scope"]').length;
      var sub = panel.querySelector('button[type="submit"]');
      s.submit = sub ? { text: sub.textContent.trim(), form: sub.form ? sub.form.id : '', cls: sub.className } : null;
      /* Keep closes it and puts the panel home, hidden. */
      panel.querySelector('[data-uc-dialog-close]').click();
      wait(150, function () {
        s.closedByKeep = !openDialog();
        s.home = panel.parentNode === home && panel.hidden;
        /* Open again, and the dim closes it. */
        btn.click();
        wait(150, function () {
          var d2 = openDialog();
          if (d2) { d2.dispatchEvent(new MouseEvent('click', { bubbles: true })); }
          wait(150, function () {
            s.closedByDim = !!d2 && !openDialog();
            if (id !== 'uc-cancel-this') { next(); return; }
            /* Nobody registered: the dialog's own submit sends the form, no second question. */
            btn.click();
            wait(150, function () {
              var sub2 = panel.querySelector('button[type="submit"]');
              window.__submitted = [];
              sub2.click();
              wait(250, function () {
                s.submittedOnce = window.__submitted.slice();
                s.dialogsAfterSubmit = document.querySelectorAll('dialog').length;
                s.notifyDialogs = document.querySelectorAll('dialog.uc-notify-modal').length;
                var k3 = panel.querySelector('[data-uc-dialog-close]'); if (k3 && !k3.hidden) { k3.click(); }
                wait(100, next);
              });
            });
          });
        });
      });
    });
  })();
}); });
})();
JS;

function rl_page( $html, $probe, $mode ) {
    if ( 'nojs' === $mode ) { $html = preg_replace( '#<script[^>]*src="[^"]*portal\.js[^"]*"[^>]*></script>#', '', $html ); }
    return str_replace( '</body>', '<script>window.RL_MODE=' . json_encode( $mode ) . ';' . $probe . '</script></body>', $html );
}
$pages = array();
foreach ( array( 'edit' => $edit, 'series' => $series ) as $name => $html ) {
    foreach ( array( 'js', 'nojs' ) as $mode ) {
        foreach ( array( 'desktop' => 1280, 'phone' => 390 ) as $wn => $w ) {
            if ( 'nojs' === $mode && 'phone' === $wn ) { continue; }
            $pages[ "$name-$mode-$wn" ] = array( 'w' => $w, 'html' => rl_page( $html, $probe, $mode ) );
        }
    }
}
rl( false !== strpos( $edit, 'portal.js' ), 'the editor page loads no portal.js, so the script half cannot be tested' );

$files = array();
foreach ( $pages as $name => $p ) {
    $inner = __DIR__ . '/release-31121-live-' . $name . '.html';
    file_put_contents( $inner, $p['html'] );
    $files[ $name ] = __DIR__ . '/release-31121-live-' . $name . '-frame.html';
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

$want_panels = array( 'edit' => array( 'uc-cancel-this', 'uc-delete-this' ), 'series' => array( 'uc-tag-delete-31', 'uc-tag-delete-32' ) );
foreach ( $g as $name => $r ) {
    list( $page, $mode ) = explode( '-', $name );
    $ids_seen = array_map( function ( $p ) { return $p['id']; }, (array) $r['panels'] );
    rl( ! array_diff( $want_panels[ $page ], $ids_seen ), "$name: the panels are not all there: " . json_encode( $ids_seen ) );
    if ( 'nojs' === $mode ) {
        foreach ( $r['panels'] as $p ) { rl( $p['shown'], "$name: with no script, {$p['id']} is not on the page" ); }
        rl( ! in_array( true, (array) $r['keeps'], true ), "$name: with no script, a Keep button that only closes a dialog is showing" );
        foreach ( $r['submits'] as $s ) { rl( $s['shown'] && '' !== $s['form'], "$name: with no script, \"{$s['text']}\" is not there to press, or belongs to no form" ); }
        continue;
    }
    foreach ( $r['panels'] as $p ) { rl( ! $p['shown'], "$name: {$p['id']} shows before its button is pressed" ); }
    foreach ( $want_panels[ $page ] as $id ) {
        $s = isset( $r['steps'][ $id ] ) ? $r['steps'][ $id ] : null;
        if ( ! $s ) { rl( false, "$name: $id was never opened" ); continue; }
        rl( $s['opened'] && $s['holds'] && $s['shown'], "$name: $id did not open in a dialog" );
        if ( ! $s['opened'] ) { continue; }
        rl( $s['box']['dx'] <= 2 && ( $s['box']['h'] >= $s['box']['vh'] - 40 || $s['box']['dy'] <= 2 ), "$name: $id is not centred: " . json_encode( $s['box'] ) );
        rl( $s['box']['w'] <= $s['box']['vw'] - 24, "$name: $id is wider than the window: " . json_encode( $s['box'] ) );
        rl( $s['keep'] && $s['focusKeep'], "$name: $id has no Keep, or Keep does not have the focus" );
        rl( $s['closedByKeep'] && $s['home'], "$name: Keep did not close $id and put it back, hidden" );
        rl( $s['closedByDim'], "$name: a click on the dim did not close $id" );
        rl( $s['sizes'][0] > $s['sizes'][1], "$name: $id's title is not larger than its lead: " . json_encode( $s['sizes'] ) );
        rl( 1 === $s['dialogs'], "$name: $id opened " . $s['dialogs'] . ' dialogs' );
    }
    if ( 'edit' === $page ) {
        $c = $r['steps']['uc-cancel-this'];
        rl( 'Cancel this event?' === trim( (string) $c['title'] ), "$name: the cancel dialog's title is " . json_encode( $c['title'] ) );
        rl( 2 === $c['scope'], "$name: the cancel dialog does not ask which dates on a repeating event" );
        rl( array( 'uc-cancel-form-' . $ids[0] ) === $c['submittedOnce'] && 0 === $c['notifyDialogs'],
            "$name: with nobody to tell, Cancel this event did not submit once with no second question: " . json_encode( array( $c['submittedOnce'], $c['notifyDialogs'] ) ) );
        $d = $r['steps']['uc-delete-this'];
        rl( 'uc-delete-event-' . $ids[0] === $d['submit']['form'] && false !== strpos( $d['submit']['cls'], 'uc-btn-danger' ), "$name: Delete in its dialog does not belong to the delete form, or is not red" );
        rl( false !== strpos( $d['lead'], 'This cannot be undone.' ), "$name: the delete dialog does not say it cannot be undone" );
    } else {
        $t = $r['steps']['uc-tag-delete-31'];
        rl( 'Delete "Food provided"?' === trim( (string) $t['title'] ), "$name: the tag dialog's title is " . json_encode( $t['title'] ) );
        rl( false !== strpos( $t['lead'], 'It will be removed from 1 event, including past ones. This cannot be undone.' ), "$name: the tag dialog says " . json_encode( $t['lead'] ) );
        rl( 'Delete' === $t['submit']['text'] && 'uc-tag-delete-form-31' === $t['submit']['form'], "$name: the tag dialog's Delete does not belong to its form" );
    }
}

echo "\n3.112.1: the centred dialogs, in Chrome\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Cancel event, Delete and a tag's Delete open centred dialogs at 1280px and 390px, close on Keep and on the dim,\nand with no script every panel and its submit are on the page.\n";
exit( 0 );
