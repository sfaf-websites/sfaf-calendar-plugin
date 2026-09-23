<?php
/**
 * DOES PRESSING A CATEGORY PILL SUBMIT THE FILTER FORM?
 *
 *     php .claude/filter-submit-live.php          writes the two pages
 *     php .claude/filter-submit-live.php --run    writes them and drives Chrome
 *
 * THE FAULT THIS EXISTS FOR (3.98.1). On sfaf.org, clicking a category pill in
 * the embedded calendar filtered the list for a moment and then reloaded the
 * page with the category gone. The pills are `<button>` elements with no
 * `type`, which makes them submit buttons; 3.85.0 put a real GET form round the
 * filter bar so the calendar works with script off, and neither script took the
 * default away. So every press did both things: the script filtered in place,
 * and the browser submitted the form on top of it. The Organizers and groups
 * control was never affected, because changing a <select> submits nothing.
 *
 * WHY A BROWSER AND NOT A READ. Everything about this is a DEFAULT. There is no
 * `type="submit"` to grep for, no submit handler to find and no navigation
 * written anywhere: the fault is the absence of one line in two files, and the
 * only place the absence is visible is a running page. The committed audits all
 * came back clean on it, and `form-owner-audit.php` came back clean because it
 * was asking whether the button had a form, which it did.
 *
 * WHAT IS REAL HERE AND WHAT IS NOT.
 * ---------------------------------------------------------------------------
 * REAL: the filter bar markup, rendered by SFAF_Shortcodes::render_calendar_block()
 * through a miniature WordPress, so the buttons, their attributes and the form
 * round them are the shipped ones. REAL: public/js/embed.js and
 * public/js/calendar.js, loaded whole, binding their own handlers. REAL: the
 * clicks, dispatched on the elements a person would aim at.
 *
 * STUBBED: the transport. embed.js fetches its block over XMLHttpRequest, so
 * that is answered from the rendered markup rather than from a server; without
 * it `bindFilters()` never runs, because embed.js binds the pills only after a
 * block has landed. calendar.js posts to admin-ajax, so `$.ajax` is replaced
 * and the call recorded. Neither stub touches the thing under test.
 *
 * TWO PAGES, ONE PER SCRIPT. Both scripts bind to `.uc-filter-btn` and loading
 * them together would give every pill two handlers and prove nothing about
 * either. The same fault has reached one script and not the other four times
 * (see embed-filters-test.php), so both are driven and both are reported.
 *
 * WHAT IS ASSERTED, per control:
 *
 *   1. A PRESS DOES NOT SUBMIT. Every `submit` event is recorded and then
 *      prevented, so the run continues; the event firing at all is the fault.
 *      Navigation is watched separately, through beforeunload and through
 *      HTMLFormElement.prototype.submit, because a script can navigate without
 *      a submit event.
 *
 *   2. THE NO-SCRIPT ADDRESS IS RIGHT. `form.requestSubmit(pill)` asks the
 *      browser for the submission a press WOULD make, with the pill as the
 *      submitter and the page's own handlers untouched, and
 *      `new FormData(form, submitter)` is the browser's own serialisation of
 *      it. So the query string a script-less visitor would be sent to is read
 *      off the real form rather than reasoned about.
 *
 * The pages are written to .claude/filter-submit-live.html (embed.js) and
 * .claude/filter-submit-live-cal.html (calendar.js), generated and committed so
 * they can be opened by hand. Regenerate rather than editing.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

$fails = array();
function fs_check( $ok, $why ) {
	global $fails;
	if ( ! $ok ) { $fails[] = $why; }
}

/*
 * Notices are collected rather than printed, for the reason
 * combined-panel-parity.php gives: the markup is built inside an output buffer,
 * so a warning raised while it renders lands INSIDE the fixture and is compared
 * happily by everything downstream.
 */
error_reporting( E_ALL );
$GLOBALS['php_notices'] = array();
set_error_handler( function ( $no, $str, $file = '', $line = 0 ) {
	$GLOBALS['php_notices'][] = basename( (string) $file ) . ':' . (int) $line . '  ' . $str;
	return true;
} );

/* =========================================================================
 * WordPress, in miniature. Only what the filter bar and its helpers reach.
 * ====================================================================== */

$GLOBALS['meta']  = array();
$GLOBALS['posts'] = array();
$GLOBALS['terms'] = array();

function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function __( $t, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return ( 1 === (int) $n ) ? $s : $p; }
function _x( $t, $c, $d = '' ) { return $t; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); }
function apply_filters( $tag, $value ) { return $value; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function add_shortcode( $t, $c ) {}
function is_wp_error( $t ) { return ( $t instanceof WP_Error ); }
function wp_kses_post( $t ) { return $t; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function absint( $n ) { return abs( (int) $n ); }
function current_time( $type = 'mysql', $gmt = 0 ) {
	return ( 'timestamp' === $type || 'U' === $type ) ? time() : date( 'Y-m-d H:i:s' );
}
function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $t ) ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); }
function home_url( $path = '' ) { return 'https://resources.sfaf.org' . $path; }
function admin_url( $path = '' ) { return 'https://resources.sfaf.org/wp-admin/' . $path; }
function add_query_arg( $args, $url = '' ) {
	$q = is_array( $args ) ? http_build_query( $args ) : (string) $args;
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . $q;
}
function get_option( $name, $default = false ) {
	return isset( $GLOBALS['options'][ $name ] ) ? $GLOBALS['options'][ $name ] : $default;
}
function get_permalink( $id = 0, $leavename = false ) {
	return 'https://resources.sfaf.org/events/event-' . (int) $id . '/';
}
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : '';
}
function get_post( $id = null ) {
	return isset( $GLOBALS['posts'][ $id ] ) ? (object) $GLOBALS['posts'][ $id ] : null;
}
function get_the_title( $id = 0 ) {
	return isset( $GLOBALS['posts'][ $id ]['title'] ) ? $GLOBALS['posts'][ $id ]['title'] : '';
}
function get_the_excerpt( $id = 0 ) { return ''; }
function get_the_content( $more = null, $strip = false, $id = 0 ) { return ''; }
function wp_trim_words( $text, $words = 55, $more = null ) { return (string) $text; }
function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $item ) {
		$out[] = is_object( $item ) ? $item->$field : $item[ $field ];
	}
	return $out;
}
function get_term( $id, $tax = '' ) {
	return isset( $GLOBALS['terms'][ $id ] ) ? (object) $GLOBALS['terms'][ $id ] : null;
}
function get_term_by( $field, $value, $tax = '' ) {
	foreach ( $GLOBALS['terms'] as $t ) {
		if ( isset( $t[ $field ] ) && $t[ $field ] === $value ) { return (object) $t; }
	}
	return false;
}
function get_term_link( $term, $tax = '' ) {
	$slug = is_object( $term ) ? $term->slug : (string) $term;
	return 'https://resources.sfaf.org/series/' . $slug . '/';
}
/*
 * THE FILTER BAR IS BUILT OUT OF THIS ONE, which is why it answers properly
 * here and returns an empty array in combined-panel-parity.php: that file is
 * comparing cards and this one is the only thing the pills come from. Ordered
 * by name, because the renderer asks for that and a fixture that ignored the
 * request would be proving something about a list nobody ships.
 */
function get_terms( $args = array() ) {
	$want = isset( $args['taxonomy'] ) ? (array) $args['taxonomy'] : array();
	$out  = array();
	foreach ( $GLOBALS['terms'] as $t ) {
		if ( $want && ! in_array( $t['taxonomy'], $want, true ) ) { continue; }
		$out[] = (object) $t;
	}
	usort( $out, function ( $a, $b ) { return strcmp( $a->name, $b->name ); } );
	return $out;
}
function wp_get_post_terms( $id, $tax = '', $args = array() ) {
	$key = $id . '|' . $tax;
	if ( ! isset( $GLOBALS['post_terms'][ $key ] ) ) { return array(); }
	$out = array();
	foreach ( $GLOBALS['post_terms'][ $key ] as $tid ) {
		$out[] = (object) $GLOBALS['terms'][ $tid ];
	}
	return $out;
}
/*
 * who_counts() asks these two: the ids in scope, then every organizer and
 * series term on them with the event each came from. Answering them is what
 * makes the Organizers and groups control render with real rows, which is the
 * other control this page has to press.
 */
function get_posts( $args = array() ) { return isset( $GLOBALS['query_ids'] ) ? $GLOBALS['query_ids'] : array(); }
function wp_get_object_terms( $ids, $taxonomies, $args = array() ) {
	$out = array();
	foreach ( (array) $ids as $id ) {
		foreach ( (array) $taxonomies as $tax ) {
			$key = $id . '|' . $tax;
			if ( ! isset( $GLOBALS['post_terms'][ $key ] ) ) { continue; }
			foreach ( $GLOBALS['post_terms'][ $key ] as $tid ) {
				$term            = (object) $GLOBALS['terms'][ $tid ];
				$term->object_id = $id;
				$out[]           = $term;
			}
		}
	}
	return $out;
}
function has_post_thumbnail( $id = 0 ) { return false; }
function get_post_thumbnail_id( $id = 0 ) { return 0; }
function wp_get_attachment_image_url( $id, $size = 'full' ) { return ''; }
function wp_get_attachment_url( $id ) { return ''; }
function attachment_url_to_postid( $url ) { return 0; }
function get_attached_file( $id ) { return ''; }
function get_the_post_thumbnail_url( $id = 0, $size = 'full' ) { return false; }
function get_term_meta( $id, $key = '', $single = false ) {
	return isset( $GLOBALS['term_meta'][ $id ][ $key ] ) ? $GLOBALS['term_meta'][ $id ][ $key ] : '';
}
/* The who picker builds its panel id from this, so the ids on the page are as
 * unrepeatable as the shipped ones. Nothing here reads them back. */
function wp_rand( $min = 0, $max = 0 ) { return mt_rand( (int) $min, (int) $max ?: 99999 ); }
function checked( $checked, $current = true, $echo = true ) {
	$out = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
	if ( $echo ) { echo $out; }
	return $out;
}
function selected( $selected, $current = true, $echo = true ) {
	$out = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
	if ( $echo ) { echo $out; }
	return $out;
}
function disabled( $disabled, $current = true, $echo = true ) {
	$out = ( (string) $disabled === (string) $current ) ? ' disabled="disabled"' : '';
	if ( $echo ) { echo $out; }
	return $out;
}
function get_current_user_id() { return 0; }
function is_user_logged_in() { return false; }
function current_user_can( $cap ) { return false; }
function date_i18n( $format, $ts = false ) { return date( $format, false === $ts ? time() : $ts ); }
function wp_date( $format, $ts = null ) { return date( $format, null === $ts ? time() : $ts ); }
function wp_timezone() { return new DateTimeZone( 'America/Los_Angeles' ); }
function get_post_type( $id = 0 ) { return 'uc_event'; }
function wp_cache_get( $k, $g = '' ) { return false; }
function wp_cache_set( $k, $v, $g = '', $e = 0 ) { return true; }
function sfaf_prime_rsvp_counts( $event_ids ) {}
function wp_reset_postdata() {}
function get_the_ID() { return $GLOBALS['current_post']; }

class WP_Error {
	public function get_error_message() { return 'error'; }
}
/*
 * NO EVENTS COME BACK, AND THAT IS THE POINT. This page is about the bar, and
 * a card drags in the venue, the RSVP counts, the source provenance and the
 * picture chain, every one of which would have to be stubbed to answer a
 * question nobody is asking here. The list renders its empty state, the bar
 * renders in full, and combined-panel-parity.php is where cards are proved.
 */
class WP_Query {
	public $posts = array();
	public $found_posts = 0;
	public $max_num_pages = 1;
	public function __construct( $args = array() ) {}
	public function have_posts() { return false; }
	public function the_post() {}
}

class SFAF_Venues {
	public static function id_for_event( $post_id ) { return 0; }
	public static function display( $venue_id ) { return ''; }
	public static function get( $venue_id ) { return null; }
	public static function parse_address( $raw ) { return array(); }
}
class SFAF_Series {
	const TAXONOMY = 'uc_series';
	public static function id_for_event( $post_id ) { return 0; }
	public static function for_event( $post_id ) { return null; }
	public static function name_for_event( $post_id ) { return ''; }
	public static function image_url( $series_id ) { return ''; }
	public static function url( $series_id ) { return ''; }
	public static function events( $series_id, $args = array() ) { return array(); }
	public static function resolve( $raw ) { return 0; }
}
class SFAF_Categories {
	public static function icon( $term_id, $name = '' ) { return 'calendar'; }
}
class SFAF_Privacy {
	public static function is_private( $post_id ) { return false; }
	public static function exclude( $args ) { return $args; }
}
class SFAF_Cancellation {
	public static function is_cancelled( $post_id ) { return false; }
	public static function is_hidden( $post_id ) { return false; }
	public static function exclude( $args ) { return $args; }
}
class SFAF_Closures {
	public static function spans() { return array(); }
}
class SFAF_Organizers {
	public static function phrase( $post_id ) { return ''; }
}
class SFAF_Embed {
	public static function is_embed_request() { return false; }
	public static function calendar_url() { return 'https://resources.sfaf.org/events/'; }
}
class SFAF_Credentials {
	public static function get( $key, $default = '' ) { return $default; }
}
class SFAF_Sources {
	public static function provenance( $post_id ) { return array(); }
	public static function takes_rsvps_at_source( $post_id ) { return false; }
}
class SFAF_Source_GFMP {
	public static function raised_amount( $post_id ) { return 0; }
}

require_once $root . '/includes/class-sfaf-online.php';
require_once $root . '/includes/sfaf-template-functions.php';
require_once $root . '/includes/class-sfaf-shortcodes.php';

/* =========================================================================
 * THE FIXTURE: three categories, two organizers, two groups.
 *
 * Three categories rather than one, because "All Events" and "the pill that is
 * already active" are separate cases from "a pill that is not", and the report
 * names each pill by its slug.
 * ====================================================================== */

$GLOBALS['terms'] = array(
	7  => array( 'term_id' => 7,  'name' => 'Support Groups',  'slug' => 'support-groups',  'taxonomy' => 'uc_event_category' ),
	8  => array( 'term_id' => 8,  'name' => 'Testing',         'slug' => 'testing',         'taxonomy' => 'uc_event_category' ),
	11 => array( 'term_id' => 11, 'name' => 'Volunteering',    'slug' => 'volunteering',    'taxonomy' => 'uc_event_category' ),
	9  => array( 'term_id' => 9,  'name' => 'SFAF',            'slug' => 'sfaf',            'taxonomy' => 'uc_organizer' ),
	10 => array( 'term_id' => 10, 'name' => 'Strut',           'slug' => 'strut',           'taxonomy' => 'uc_organizer' ),
	21 => array( 'term_id' => 21, 'name' => 'Positive Force',  'slug' => 'positive-force',  'taxonomy' => 'uc_series' ),
	22 => array( 'term_id' => 22, 'name' => 'Programa Latino', 'slug' => 'programa-latino', 'taxonomy' => 'uc_series' ),
);

$GLOBALS['query_ids']  = array( 101, 102 );
$GLOBALS['post_terms'] = array(
	'101|uc_organizer' => array( 9 ),
	'101|uc_series'    => array( 21 ),
	'102|uc_organizer' => array( 10 ),
	'102|uc_series'    => array( 22 ),
);

$shortcodes = new SFAF_Shortcodes();
$block      = $shortcodes->render_calendar_block( array(
	'filters' => 'category,organizer,series',
	'view'    => 'list',
	'toggle'  => 'no',
) );
$markup = $block['html'];

/* ---------------------------------------------------------------------------
 * THE FIXTURE HAS TO CONTAIN THE THING UNDER TEST, or the page passes by
 * rendering nothing. Checked here rather than in the browser, because a missing
 * bar in the browser reads as "no submit happened", which is a pass.
 * ------------------------------------------------------------------------ */
fs_check( false !== strpos( $markup, 'uc-filter-form' ),
	'the rendered block has no filter form in it, so this page proves nothing' );
fs_check( substr_count( $markup, 'uc-filter-btn' ) >= 4,
	'the rendered block has fewer than four category pills, so the fixture is not the bar' );
fs_check( false !== strpos( $markup, 'data-uc-who' ),
	'the rendered block has no Organizers and groups control, so the dropdown is never pressed' );
fs_check( false !== strpos( $markup, 'uc-search' ),
	'the rendered block has no search field' );

/* =========================================================================
 * THE PROBE, shared by both pages.
 *
 * `run()` is called with a name for the script under test. Everything it
 * measures is read off the document rather than off either script's internals,
 * so the two runs answer the same questions about the same markup.
 * ====================================================================== */
$probe = <<<'JS'
var lines = [];
function say(s) { lines.push(s); }

function report() {
  document.getElementById('out').textContent = lines.join('\n');
}

/* ---- WATCHING FOR A NAVIGATION, three ways. ---------------------------
 *
 * A submit event is the one this fault raises, and it is caught in the CAPTURE
 * phase on the window so nothing downstream can hide it, then prevented so the
 * run continues past the first failure and reports on every control rather
 * than on the first one.
 *
 * The other two are there because a submit event is not the only way a page
 * goes somewhere: a script can call form.submit(), which fires no event at
 * all, or set location. Each is recorded and none is allowed to happen. */
window.__submits = [];
window.__navs    = [];
window.__allowSubmit = false;
window.__lastForm = null;

window.addEventListener('submit', function (e) {
  window.__lastForm = e.target;
  if (window.__allowSubmit) {
    /* THE NO-SCRIPT READING. requestSubmit(button) asks the browser for the
       submission that button would make, and FormData(form, submitter) is the
       browser's own serialisation of it, the submitter's name and value
       included. So what is read here is the address a visitor without script
       would actually be sent to. */
    window.__wouldSend = new URLSearchParams(new FormData(e.target, e.submitter)).toString();
  } else {
    window.__submits.push((e.submitter && e.submitter.textContent || '?').trim());
  }
  e.preventDefault();
}, true);

window.addEventListener('beforeunload', function () { window.__navs.push('unload'); });
HTMLFormElement.prototype.submit = function () { window.__navs.push('form.submit()'); };

function since(n) { return window.__submits.length - n; }

/* ---- THE CONTROLS, FOUND THE WAY A PERSON FINDS THEM. ------------------ */
function pills() {
  return Array.prototype.slice.call(document.querySelectorAll('.uc-filter-btn'));
}
function theForm() { return document.querySelector('.uc-filter-form'); }

function pressed(el, label) {
  var subs = window.__submits.length;
  var navs = window.__navs.length;
  el.click();
  var s = since(subs), n = window.__navs.length - navs;
  say('  ' + label
      + '  submits ' + s
      + ', navigations ' + n
      + (s || n ? '   <- THE PAGE WAS ABOUT TO RELOAD' : ''));
  return s + n;
}

function run(which) {
  say(which);
  say('');

  var form = theForm();
  say('the bar is inside a GET form   ' + (form ? form.method.toLowerCase() : 'THERE IS NO FORM'));
  say('pills on the bar               ' + pills().length);
  say('');

  /* ---- 1. EVERY PILL, PRESSED. ------------------------------------- */
  say('pressing each pill:');
  var bad = 0;
  pills().forEach(function (p) {
    bad += pressed(p, (p.getAttribute('data-category') || '?'));
  });
  say('');

  /* ---- 2. THE SEARCH FIELD. Typing, and Enter. ---------------------- */
  say('the search field:');
  var box = document.querySelector('.uc-search');
  if (!box) {
    say('  THERE IS NO SEARCH FIELD');
    bad++;
  } else {
    var subs = window.__submits.length, navs = window.__navs.length;
    box.focus();
    box.value = 'strut';
    box.dispatchEvent(new Event('input', { bubbles: true }));
    box.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true, cancelable: true }));
    var s = since(subs), n = window.__navs.length - navs;
    say('  Enter in the box              submits ' + s + ', navigations ' + n
        + (s || n ? '   <- THE PAGE WAS ABOUT TO RELOAD' : ''));
    bad += s + n;
  }
  say('');

  /* ---- 3. THE ORGANIZERS AND GROUPS CONTROL. ------------------------ */
  say('the Organizers and groups control:');
  var who = document.querySelector('[data-uc-who]');
  var boxes = document.querySelectorAll('[data-uc-who] input[type="checkbox"]');
  if (!who || !boxes.length) {
    say('  THE CONTROL IS NOT ON THE PAGE');
    bad++;
  } else {
    who.open = true;
    var subs = window.__submits.length, navs = window.__navs.length;
    boxes[0].click();
    var s = since(subs), n = window.__navs.length - navs;
    say('  ticking the first name        submits ' + s + ', navigations ' + n
        + (s || n ? '   <- THE PAGE WAS ABOUT TO RELOAD' : ''));
    bad += s + n;
  }
  say('');

  say('controls that would have navigated  ' + bad);
  say('');

  /* ---- 4. THE NO-SCRIPT ADDRESS. ------------------------------------
   *
   * requestSubmit() does not dispatch a click, so neither script's handler
   * runs and neither can influence the answer. What comes back is the query
   * string the browser itself would build for a press of that button. */
  say('what a press would send with script off:');
  if (!form) {
    say('  NO FORM, so nothing would be sent at all');
    bad++;
  } else {
    pills().forEach(function (p) {
      window.__allowSubmit = true;
      window.__wouldSend = '(nothing)';
      form.requestSubmit(p);
      window.__allowSubmit = false;
      say('  ' + (p.getAttribute('data-category') || '?') + '  ->  ?' + window.__wouldSend);
    });
  }
  say('');
  say('script errors    ' + (window.__errs.length ? window.__errs.join(' | ') : 'none'));
  report();
}
JS;

/* =========================================================================
 * PAGE ONE: embed.js.
 *
 * XMLHttpRequest is replaced before embed.js runs. embed.js binds the pills in
 * bind(), which runs in loadBlock()'s callback and nowhere else, so a page that
 * simply contains the markup would have no handlers on it and would pass this
 * test while the fault was live. The block has to ARRIVE.
 * ====================================================================== */
/*
 * INLINED, AND THE CLOSING TAG IN ITS OWN DOCBLOCK HAS TO BE BROKEN UP.
 *
 * embed.js opens with the snippet a host site pastes, `</script>` and all, and
 * an HTML parser ends a <script> element at the first `</script` it sees
 * whatever the JavaScript around it is doing. Inlined raw, the file was cut in
 * half inside a comment: the page reported a syntax error, embed.js never ran,
 * nothing was bound, and every control then measured zero navigations, which
 * reads as a pass. A test that fails by passing is the worst shape there is, so
 * this is done here and the escape is invisible to JavaScript.
 */
$embed_js = str_replace( '</script', '<\\/script', file_get_contents( $root . '/public/js/embed.js' ) );
$payload  = json_encode( array(
	'html'       => $markup,
	'css_url'    => '',
	'js_version' => '',
	'total'      => 0,
	'max_pages'  => 1,
	'has_more'   => false,
	'card_style' => '',
) );

$embed_html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: does a category pill submit the filter form? (embed.js)</title>
<!-- GENERATED BY .claude/filter-submit-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/calendar.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; white-space: pre-wrap; }
  * { transition: none !important; animation: none !important; }
</style>
</head>
<body>
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
<h1>embed.js</h1>

<div class="sfaf-calendar-embed" data-sfaf-calendar data-filters="category,organizer,series"
     data-view="list" data-toggle="no"></div>

<pre id="out">not run</pre>

<script>
/* The endpoint, answered from the rendered block. Everything else on
   XMLHttpRequest is left alone, so the cron nudge and anything added later
   still behave as they would. */
(function () {
  var PAYLOAD = $payload;
  var Real = window.XMLHttpRequest;
  function Fake() {
    this.readyState = 0; this.status = 0; this.responseText = '';
    this.onreadystatechange = null;
  }
  Fake.prototype.open = function (m, url) { this._url = url; };
  Fake.prototype.setRequestHeader = function () {};
  Fake.prototype.send = function () {
    var self = this;
    setTimeout(function () {
      self.readyState = 4;
      self.status = 200;
      self.responseText = JSON.stringify(PAYLOAD);
      if (self.onreadystatechange) { self.onreadystatechange(); }
      if (self.onload) { self.onload(); }
    }, 0);
  };
  Fake.prototype.abort = function () {};
  window.XMLHttpRequest = Fake;
})();
</script>

<script>
$embed_js
</script>

<script>
$probe
window.addEventListener('load', function () {
  setTimeout(function () { run('embed.js, on the real embed markup'); }, 250);
});
</script>
</body>
</html>
HTML;

file_put_contents( $root . '/.claude/filter-submit-live.html', $embed_html );
echo "wrote: .claude/filter-submit-live.html\n";

/* =========================================================================
 * PAGE TWO: calendar.js.
 *
 * This one renders the block into the page, because calendar.js binds from the
 * document and the markup is server-rendered on the calendar site. jQuery is
 * vendored, for the reason rsvp-format-live.php gives: a page that fetches it
 * over the network is a page that fails when the network does.
 * ====================================================================== */
$jq = $root . '/.claude/vendor/jquery-3.7.1.min.js';
if ( ! file_exists( $jq ) ) {
	echo "jQuery is missing at .claude/vendor/jquery-3.7.1.min.js\n";
	echo "Fetch it once: curl -o .claude/vendor/jquery-3.7.1.min.js https://code.jquery.com/jquery-3.7.1.min.js\n";
	exit( 1 );
}
$cal_js = file_get_contents( $root . '/public/js/calendar.js' );
$jq_src = file_get_contents( $jq );

$cal_html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: does a category pill submit the filter form? (calendar.js)</title>
<!-- GENERATED BY .claude/filter-submit-live.php. Do not edit; regenerate. -->
<link rel="stylesheet" href="../public/css/calendar.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; white-space: pre-wrap; }
  * { transition: none !important; animation: none !important; }
</style>
</head>
<body>
<script>window.__errs=[];window.addEventListener("error",function(e){window.__errs.push((e.message||"?")+" @ "+(e.filename||"").split("/").pop()+":"+e.lineno);});</script>
<h1>calendar.js</h1>

$markup

<pre id="out">not run</pre>

<script>
$jq_src
</script>
<script>
/* What wp_localize_script() hands the real page, and the redraw the chips ask
   for, captured rather than sent. */
var ucData = { ajaxUrl: 'https://example.org/wp-admin/admin-ajax.php', nonce: 'testnonce' };
jQuery.ajax = function () {
  return { done: function () { return this; }, fail: function () { return this; }, always: function () { return this; } };
};
</script>
<script>
$cal_js
</script>
<script>
$probe
window.addEventListener('load', function () {
  setTimeout(function () { run('calendar.js, on the same markup'); }, 250);
});
</script>
</body>
</html>
HTML;

file_put_contents( $root . '/.claude/filter-submit-live-cal.html', $cal_html );
echo "wrote: .claude/filter-submit-live-cal.html\n";

foreach ( $GLOBALS['php_notices'] as $n ) {
	echo 'notice while rendering: ' . $n . "\n";
}

/* =========================================================================
 * THE COMPANION CHECKS, decided from the source.
 *
 * The browser proves the press does not navigate. These prove the two things
 * that make that true, so a fix removed from one script and not the other is
 * reported by name rather than only by a page going red.
 * ====================================================================== */
$emb = file_get_contents( $root . '/public/js/embed.js' );
$cal = file_get_contents( $root . '/public/js/calendar.js' );

fs_check( (bool) preg_match( "/addEventListener\('click', function \(e\) \{\s*(\/\*.*?\*\/\s*)?e\.preventDefault\(\);/s", $emb ),
	'embed.js binds the pills with a handler that does not prevent the default' );
fs_check( (bool) preg_match( "/on\('click', '\.uc-filter-btn', function \(e\) \{\s*(\/\*.*?\*\/\s*)?e\.preventDefault\(\);/s", $cal ),
	'calendar.js binds the pills with a handler that does not prevent the default' );

if ( in_array( '--run', array_slice( $argv, 1 ), true ) ) {
	$chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
	if ( ! file_exists( $chrome ) ) {
		echo "Chrome not found at $chrome\n";
		exit( 1 );
	}

	$pages = array(
		'embed.js'    => $root . '/.claude/filter-submit-live.html',
		'calendar.js' => $root . '/.claude/filter-submit-live-cal.html',
	);

	foreach ( $pages as $which => $file ) {
		$url = 'file:///' . str_replace( '\\', '/', $file );
		$dom = shell_exec( '"' . $chrome . '" --headless --disable-gpu --window-size=1200,900 '
			. '--virtual-time-budget=12000 --dump-dom "' . $url . '" 2>NUL' );
		if ( ! preg_match( '#<pre id="out">(.*?)</pre>#s', (string) $dom, $m ) ) {
			fs_check( false, $which . ': Chrome returned no probe block, so nothing was measured' );
			continue;
		}
		$report = html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
		echo $report . "\n\n";

		fs_check( false !== strpos( $report, 'script errors    none' ),
			$which . ': the script threw while the bar was being driven' );

		/* THE ONE THAT MATTERS. Nothing on this bar may take the page
		 * anywhere, and the count is the whole answer. */
		fs_check( false !== strpos( $report, 'controls that would have navigated  0' ),
			$which . ': pressing something on the filter bar submits the form, so the block '
			. 'filters in place and then the page reloads on top of it' );

		/* AND THE BAR WAS ACTUALLY THERE. A page that rendered nothing
		 * navigates nowhere and would pass the line above. */
		fs_check( false !== strpos( $report, 'the bar is inside a GET form   get' ),
			$which . ': the filter bar is not inside a GET form, so there is no no-script path at all' );
		if ( preg_match( '/pills on the bar               (\d+)/', $report, $pm ) ) {
			fs_check( (int) $pm[1] >= 4,
				$which . ': fewer than four pills were driven, so the run proved almost nothing' );
		} else {
			fs_check( false, $which . ': the pill count could not be read' );
		}

		/* THE NO-SCRIPT ADDRESS, read off the real form. A press carries
		 * the slug of the pill pressed, and "All Events" carries an empty
		 * uc_cat, which is how "no category" is spelled everywhere else. */
		fs_check( (bool) preg_match( '/  all  ->  \?(.*)/', $report, $am ) && false !== strpos( $am[1], 'uc_cat=' ),
			$which . ': All Events would send no uc_cat at all, so with script off it cannot clear the category' );
		if ( isset( $am ) ) {
			fs_check( ! preg_match( '/uc_cat=[^&]/', $am[1] ),
				$which . ': All Events would send a category, so with script off it filters instead of clearing' );
		}
		foreach ( array( 'support-groups', 'testing', 'volunteering' ) as $slug ) {
			fs_check( false !== strpos( $report, '  ' . $slug . '  ->  ?' ) &&
				(bool) preg_match( '/  ' . preg_quote( $slug, '/' ) . '  ->  \?[^\n]*uc_cat=' . preg_quote( $slug, '/' ) . '(&|$)/', $report ),
				$which . ': with script off, pressing ' . $slug . ' would not carry uc_cat=' . $slug
				. ' in the address, so the filter does not survive the reload' );
		}
		/* ONE ANSWER TO ONE QUESTION. A hidden field and a submitter with
		 * the same name would both be sent, and the address would hold two
		 * categories with the result decided by which came last. */
		if ( preg_match_all( '/->  \?([^\n]*)/', $report, $qm ) ) {
			foreach ( $qm[1] as $q ) {
				fs_check( 1 === substr_count( $q, 'uc_cat=' ),
					$which . ': a press would send uc_cat more than once, so the address holds two answers '
					. 'to one question: ?' . $q );
			}
		}
	}
}

echo "\n";
if ( $fails ) {
	echo 'FAIL  filter-submit-live       ' . count( $fails ) . " problem(s):\n";
	foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
	exit( 1 );
}
echo "PASS  filter-submit-live       every control on the filter bar filters in place, and a\n";
echo "                               pill press with script off carries its own category.\n";
exit( 0 );
