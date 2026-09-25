<?php
/**
 * PUBLISH WITH BOTH TIMES SET, PRESSED IN A BROWSER (3.104.0).
 *
 *     php .claude/publish-times-live.php          writes the pages
 *     php .claude/publish-times-live.php --run    writes them, drives Chrome, runs the saves
 *
 * THE FAULT THIS EXISTS FOR. From 3.98.0 a time is two selects posting
 * `<name>_h` and `<name>_m`, folded back into `<name>` by
 * sfaf_normalize_time_post() on `init`. WordPress calls an action that carries
 * no arguments with an empty string, so the function got '' where it expected
 * its default list and folded nothing. Every check called it by hand with no
 * argument, the one call WordPress never makes, and every check that saved an
 * event posted `start_time` directly, which no browser does. A complete new
 * event was held for "a start time and an end time" from 3.99.0.
 *
 * SO NOTHING HERE IS TYPED BY HAND. The real editor and both public forms are
 * rendered with the real portal.js, Chrome sets the times through the controls
 * and presses the real button, and what the browser would POST is captured.
 * That POST is then run through the normalizer AS WORDPRESS RUNS IT, from its
 * own registration on `init` with the argument WordPress passes
 * (kit_run_hook()), and then through the real save or the real validator.
 *
 * Asserted: a new event with both times publishes; an existing event with both
 * times stays published on Save changes and keeps the NEW times; a start with
 * no end is held, naming only the end time; Save draft stores both times; both
 * public forms read both times.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function pt_check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function pt_capture( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

/* The existing event: published, complete, 10 to 11 am. */
$existing = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Stored group', 'post_content' => '<p>Words.</p>' ) );
foreach ( array( '_uc_event_date' => '2026-12-01', '_uc_start_time' => '10:00', '_uc_end_time' => '11:00', '_uc_location' => '470 Castro St' ) as $k => $v ) {
    update_post_meta( $existing, $k, $v );
}
wp_set_object_terms( $existing, array( 12 ), 'uc_event_category' );
wp_set_object_terms( $existing, array( 11 ), 'uc_organizer' );

$_GET = array();
$editor_new = pt_capture( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) ); } );
$editor_old = pt_capture( function () use ( $portal, $existing ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $existing ) ); } );
$request    = pt_capture( function () { kit_call( 'SFAF_Request', 'render_form', null, array( 'tok', 'someone@sfaf.org', array(), array() ) ); } );
$submit     = pt_capture( function () { kit_call( 'SFAF_Submit', 'render_form', null, array( (object) array( 'slug' => 'cycle-to-zero', 'name' => 'Cycle to Zero', 'term_id' => 11 ), array(), array() ) ); } );

/*
 * Each case: which page, what to set through the controls, and which button to
 * press. 'press' => '' means read the form without pressing, for the public
 * forms, whose other required fields would stop a real submit before it fired.
 */
$complete = array( 'title' => 'Drop-in group', 'date' => '2026-12-01', 'category[]' => '12', 'organizer[]' => '11',
    'description' => '<p>A weekly group.</p>', 'location_mode' => 'custom', 'location_street' => '470 Castro St', 'location_city' => 'San Francisco' );
$cases = array(
    'new-publish'  => array( 'html' => $editor_new, 'set' => $complete + array( 'start_time_h' => '18', 'start_time_m' => '00', 'end_time_h' => '19', 'end_time_m' => '30' ), 'press' => 'publish' ),
    'old-keep'     => array( 'html' => $editor_old, 'set' => array( 'start_time_h' => '18', 'start_time_m' => '00', 'end_time_h' => '19', 'end_time_m' => '30' ), 'press' => 'keep' ),
    'new-no-end'   => array( 'html' => $editor_new, 'set' => $complete + array( 'start_time_h' => '18', 'start_time_m' => '00', 'end_time_h' => '', 'end_time_m' => '' ), 'press' => 'publish' ),
    'new-draft'    => array( 'html' => $editor_new, 'set' => array( 'title' => 'Half done', 'start_time_h' => '09', 'start_time_m' => '15', 'end_time_h' => '10', 'end_time_m' => '45' ), 'press' => 'draft' ),
    'request-form' => array( 'html' => $request, 'set' => array( 'start_time_h' => '18', 'start_time_m' => '00', 'end_time_h' => '19', 'end_time_m' => '30' ), 'press' => '' ),
    'submit-form'  => array( 'html' => $submit, 'set' => array( 'start_time_h' => '18', 'start_time_m' => '00', 'end_time_h' => '19', 'end_time_m' => '30' ), 'press' => '' ),
);

$probe = <<<'JS'
(function () {
var C = window.PT_CASE, out = { errors: [], posted: null, pressed: false };
window.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
window.confirm = function () { return true; };
function pairs(fd) { var a = []; fd.forEach(function (v, k) { if (typeof v === 'string') { a.push([k, v]); } }); return a; }
document.addEventListener('submit', function (e) {
  var fd; try { fd = new FormData(e.target, e.submitter); } catch (x) { fd = new FormData(e.target); }
  out.posted = pairs(fd); out.pressed = true; e.preventDefault();
});
function set(form, name, value) {
  var els = form.querySelectorAll('[name="' + name + '"]');
  if (!els.length) { out.errors.push('no control named ' + name); return; }
  Array.prototype.forEach.call(els, function (el) {
    if (el.type === 'checkbox' || el.type === 'radio') {
      if (el.value === value) { el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); }
    } else {
      el.value = value;
      if (el.value !== value) { out.errors.push(name + ' would not take ' + value); }
      el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
}
window.addEventListener('load', function () { setTimeout(function () {
  var marker = document.querySelector('select[name="start_time_h"]');
  var form = marker ? marker.form : null;
  if (!form) { out.errors.push('the time control is in no form'); }
  else {
    Object.keys(C.set).forEach(function (k) { set(form, k, C.set[k]); });
    if (C.press) {
      var btn = document.querySelector('button[name="save_mode"][value="' + C.press + '"]');
      if (!btn) { out.errors.push('no ' + C.press + ' button'); } else { btn.click(); }
    } else {
      out.posted = pairs(new FormData(form));
    }
  }
  setTimeout(function () { var pre = document.createElement('pre'); pre.id = 'out'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre); }, 400);
}, 400); });
})();
JS;

$files = array();
foreach ( $cases as $name => $c ) {
    $page = str_replace( '</body>', '<script>window.PT_CASE=' . json_encode( array( 'set' => $c['set'], 'press' => $c['press'] ) ) . ';' . $probe . '</script></body>', $c['html'] );
    $files[ $name ] = __DIR__ . '/publish-times-live-' . $name . '.html';
    file_put_contents( $files[ $name ], $page );
}
echo 'wrote ' . count( $files ) . " pages\n";

/* The POST the browser sent, as PHP would receive it. */
function pt_post( $pairs ) {
    $post = array();
    foreach ( (array) $pairs as $p ) {
        list( $k, $v ) = $p;
        if ( '[]' === substr( $k, -2 ) ) { $post[ substr( $k, 0, -2 ) ][] = $v; } else { $post[ $k ] = $v; }
    }
    return $post;
}

/* One request: the normalizer from its registration, as WordPress runs it. */
function pt_request( $pairs ) {
    $_POST = pt_post( $pairs );
    $_GET  = array();
    kit_run_hook( 'init', 'sfaf_normalize_time_post' );
}

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
    $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    if ( ! file_exists( $chrome ) ) { echo "Chrome not found at $chrome\n"; exit( 1 ); }
    $got = array();
    foreach ( $files as $name => $file ) {
        $dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --allow-file-access-from-files --window-size=1200,3000 --virtual-time-budget=8000 --dump-dom "file:///' . str_replace( '\\', '/', $file ) . '" 2>NUL' );
        if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) { pt_check( false, "$name: Chrome returned no probe block" ); continue; }
        $got[ $name ] = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
        pt_check( empty( $got[ $name ]['errors'] ), "$name: " . implode( '; ', (array) $got[ $name ]['errors'] ) );
        pt_check( is_array( $got[ $name ]['posted'] ), "$name: nothing was posted" );
        if ( '' !== $cases[ $name ]['press'] ) {
            pt_check( ! empty( $got[ $name ]['pressed'] ), "$name: pressing the button did not submit the form" );
        }
    }

    /* The browser never posts a composed time: only the pairs. */
    $sent = isset( $got['new-publish']['posted'] ) ? pt_post( $got['new-publish']['posted'] ) : array();
    pt_check( ! isset( $sent['start_time'] ) && '18' === ( $sent['start_time_h'] ?? '' ), 'the editor posts ' . json_encode( array_intersect_key( $sent, array_flip( array( 'start_time', 'start_time_h', 'start_time_m' ) ) ) ) );

    $save = function ( $name ) use ( $portal, $got ) {
        pt_request( isset( $got[ $name ]['posted'] ) ? $got[ $name ]['posted'] : array() );
        $r = kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( new WP_User() ) );
        return $r + array( 'status' => get_post_status( (int) $r['id'] ), 'start' => get_post_meta( (int) $r['id'], '_uc_start_time', true ), 'end' => get_post_meta( (int) $r['id'], '_uc_end_time', true ) );
    };

    $r = $save( 'new-publish' );
    pt_check( 'publish' === $r['status'], 'a new event with both times set is not published: ' . $r['status'] . ', needs=' . ( $r['needs'] ?? '' ) );
    pt_check( '18:00' === $r['start'] && '19:30' === $r['end'], "the new event stored {$r['start']} to {$r['end']}" );

    $r = $save( 'old-keep' );
    pt_check( 'publish' === $r['status'], 'an existing event on Save changes is not published: ' . $r['status'] );
    pt_check( '18:00' === $r['start'] && '19:30' === $r['end'], "Save changes kept the old times: {$r['start']} to {$r['end']}" );

    $r = $save( 'new-no-end' );
    pt_check( 'draft' === $r['status'], 'a start with no end was not held: ' . $r['status'] );
    pt_check( 'end_time' === ( $r['needs'] ?? '' ), 'a start with no end should need the end time only, needs=' . ( $r['needs'] ?? '' ) );
    $_GET = array( 'msg' => $r['msg'], 'needs' => $r['needs'] ?? '' );
    ob_start(); kit_call( 'SFAF_Portal', 'flash', $portal ); $flash = trim( html_entity_decode( strip_tags( (string) ob_get_clean() ), ENT_QUOTES, 'UTF-8' ) );
    pt_check( 'Saved, and not published. Add an end time, then publish.' === $flash, 'the held message reads: ' . $flash );

    $r = $save( 'new-draft' );
    pt_check( 'draft' === $r['status'] && '09:15' === $r['start'] && '10:45' === $r['end'], "Save draft: {$r['status']}, {$r['start']} to {$r['end']}" );

    foreach ( array( 'request-form' => 'SFAF_Request', 'submit-form' => 'SFAF_Submit' ) as $name => $class ) {
        pt_request( isset( $got[ $name ]['posted'] ) ? $got[ $name ]['posted'] : array() );
        $v = $class::validate( $_POST );
        pt_check( '18:00' === ( $v['clean']['start'] ?? '' ) && '19:30' === ( $v['clean']['end'] ?? '' ), "$class read " . ( $v['clean']['start'] ?? '?' ) . ' to ' . ( $v['clean']['end'] ?? '?' ) );
        pt_check( ! isset( $v['errors']['start_time'] ) && ! isset( $v['errors']['end_time'] ), "$class refused a time it was given: " . json_encode( array_intersect_key( (array) ( $v['errors'] ?? array() ), array_flip( array( 'start_time', 'end_time' ) ) ) ) );
    }
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "Times set through the controls in Chrome, posted as pairs, folded the way WordPress runs the hook:\n";
echo "a complete new event publishes, Save changes keeps the new times, no end is held for the end alone,\n";
echo "Save draft stores both, and both public forms read both.\n";
exit( 0 );
