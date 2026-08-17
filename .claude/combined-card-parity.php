<?php
/**
 * IS A CARD IN THE COMBINED MODE THE SAME CARD AS IN LIST MODE?
 *
 *     php .claude/combined-card-parity.php
 *     chrome --headless --disable-gpu --virtual-time-budget=6000 \
 *            --dump-dom file:///.../.claude/combined-card-parity.html
 *
 * WHY THIS EXISTS. The combined mode composes the list renderer and the month
 * grid renderer rather than adding a third, so any difference between a card in
 * the combined mode and the same card in list mode is a bug by definition. Two
 * builds running, the tests here have asserted things ABOUT that claim and never
 * the claim itself:
 *
 *   3.31.0  asserted both panels are built and neither is marked hidden. The
 *           browser then hid both, and the mode rendered no events at all.
 *   3.31.1  added a test that counts the events a visitor can see. It passed
 *           while every card in the panel was a 40px strip with no title, no
 *           image, no meta and no button, because the cards were there and were
 *           visible. Counting is not looking.
 *
 * So this renders THE SAME CARD MARKUP in both contexts, in a browser, at the
 * width sfaf.org uses, and compares every element in it: presence, computed
 * display, and rendered box. Anything that differs fails, whether or not anybody
 * thought of it in advance.
 *
 * THE MARKUP IS THE RENDERER'S OWN. This file stubs enough WordPress to call
 * SFAF_Shortcodes::render_event_card() for real, so the page contains the
 * elements the card actually has rather than the ones a fixture author
 * remembered. That is the part a hand-written fixture cannot do: a divergence
 * affecting an element the fixture happened to omit is invisible to it.
 *
 * It writes .claude/combined-card-parity.html, which is generated and committed
 * so it can be opened in a browser without running PHP first. Regenerate it
 * rather than editing it.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

/*
 * NOTICES ARE COLLECTED, NOT PRINTED, AND THAT IS NOT TIDINESS.
 *
 * The card is built inside ob_start(), so a warning raised while it renders is
 * captured into the markup rather than shown on the terminal: the first run of
 * this file put "Warning: Undefined variable $cat_name" inside twenty-four cards
 * and the page compared them happily, because both panels had the same corrupt
 * string in them. Swallowing them here keeps the fixture clean, and printing the
 * list at the end means they are still reported rather than lost.
 */
error_reporting( E_ALL );
$GLOBALS['php_notices'] = array();
set_error_handler( function ( $no, $str, $file = '', $line = 0 ) {
    $GLOBALS['php_notices'][] = basename( (string) $file ) . ':' . (int) $line . '  ' . $str;
    return true;
} );

/* =========================================================================
 * WordPress, in miniature. Only what the card renderer and its helpers reach.
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
function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $t ) ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
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
function get_the_excerpt( $id = 0 ) {
    return isset( $GLOBALS['posts'][ $id ]['excerpt'] ) ? $GLOBALS['posts'][ $id ]['excerpt'] : '';
}
function get_the_content( $more = null, $strip = false, $id = 0 ) {
    return isset( $GLOBALS['posts'][ $id ]['content'] ) ? $GLOBALS['posts'][ $id ]['content'] : '';
}
function wp_trim_words( $text, $words = 55, $more = null ) {
    $parts = preg_split( '/\s+/', trim( strip_tags( (string) $text ) ) );
    if ( count( $parts ) <= $words ) { return implode( ' ', $parts ); }
    return implode( ' ', array_slice( $parts, 0, $words ) ) . '...';
}
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
function get_terms( $args = array() ) { return array(); }
function wp_get_post_terms( $id, $tax = '', $args = array() ) {
    $key = $id . '|' . $tax;
    if ( ! isset( $GLOBALS['post_terms'][ $key ] ) ) { return array(); }
    $out = array();
    foreach ( $GLOBALS['post_terms'][ $key ] as $tid ) {
        $out[] = (object) $GLOBALS['terms'][ $tid ];
    }
    return $out;
}
function has_post_thumbnail( $id = 0 ) {
    return ! empty( $GLOBALS['posts'][ $id ]['thumb'] );
}
function get_the_post_thumbnail_url( $id = 0, $size = 'full' ) {
    return isset( $GLOBALS['posts'][ $id ]['thumb'] ) ? $GLOBALS['posts'][ $id ]['thumb'] : false;
}
function get_the_post_thumbnail( $id = 0, $size = 'full', $attr = array() ) {
    if ( empty( $GLOBALS['posts'][ $id ]['thumb'] ) ) { return ''; }
    $cls = isset( $attr['class'] ) ? $attr['class'] : '';
    $alt = isset( $attr['alt'] ) ? $attr['alt'] : '';
    return '<img class="' . esc_attr( $cls ) . '" src="' . esc_url( $GLOBALS['posts'][ $id ]['thumb'] ) . '" alt="' . esc_attr( $alt ) . '" />';
}
function sanitize_title( $t ) {
    return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' );
}
function get_term_meta( $id, $key = '', $single = false ) {
    return isset( $GLOBALS['term_meta'][ $id ][ $key ] ) ? $GLOBALS['term_meta'][ $id ][ $key ] : '';
}
function update_term_meta( $id, $key, $value ) { $GLOBALS['term_meta'][ $id ][ $key ] = $value; return true; }
function get_current_user_id() { return 0; }
function is_user_logged_in() { return false; }
function current_user_can( $cap ) { return false; }
function wp_date( $format, $ts = null ) { return date( $format, null === $ts ? time() : $ts ); }
function date_i18n( $format, $ts = false ) { return date( $format, false === $ts ? time() : $ts ); }
function wp_timezone() { return new DateTimeZone( 'America/Los_Angeles' ); }
function get_post_type( $id = 0 ) { return 'uc_event'; }
function wp_cache_get( $k, $g = '' ) { return false; }
function wp_cache_set( $k, $v, $g = '', $e = 0 ) { return true; }

class WP_Error {
    public function get_error_message() { return 'error'; }
}
class WP_Query {
    public $posts = array();
    public function __construct( $args = array() ) {}
}

/*
 * The plugin's own classes, stubbed rather than loaded. Loading them drags in
 * their hooks and their queries and none of that decides what a card looks like;
 * these are the four the card's helpers reach through.
 */
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
}
class SFAF_Categories {
    public static function icon( $term_id, $name = '' ) { return 'calendar'; }
}
class SFAF_Privacy {
    public static function is_private( $post_id ) { return false; }
}
class SFAF_Embed {
    public static function is_embed_request() { return false; }
}
class SFAF_Credentials {
    public static function get( $key, $default = '' ) { return $default; }
}
class SFAF_Sources {
    public static function provenance( $post_id ) { return array(); }
}
class SFAF_Source_GFMP {
    public static function raised_amount( $post_id ) { return 0; }
}

require_once $root . '/includes/sfaf-template-functions.php';
require_once $root . '/includes/class-sfaf-shortcodes.php';

/* =========================================================================
 * Two events, chosen so the card renders every part it has
 * ====================================================================== */

$GLOBALS['terms'] = array(
    7 => array( 'term_id' => 7, 'name' => 'Support Groups', 'slug' => 'support-groups', 'taxonomy' => 'uc_event_category' ),
    9 => array( 'term_id' => 9, 'name' => 'SFAF', 'slug' => 'sfaf', 'taxonomy' => 'uc_organizer' ),
);

$GLOBALS['post_terms'] = array(
    '101|uc_event_category' => array( 7 ),
    '101|uc_organizer'      => array( 9 ),
    '102|uc_event_category' => array( 7 ),
    '102|uc_organizer'      => array( 9 ),
);

$GLOBALS['posts'] = array(
    101 => array(
        'title'   => 'Community advisory board, September session',
        'excerpt' => 'An open meeting for anyone receiving services, with interpretation and food provided.',
        'content' => 'An open meeting for anyone receiving services.',
        'thumb'   => 'data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACwAAAAAAQABAAACAkQBADs=',
    ),
    102 => array(
        'title'   => 'Volunteer orientation',
        'excerpt' => 'A short session covering what volunteers do and how shifts are scheduled.',
        'content' => 'A short session.',
    ),
);

$GLOBALS['meta'] = array(
    101 => array(
        '_uc_event_date'  => '2026-09-24',
        '_uc_start_time'  => '18:00',
        '_uc_end_time'    => '19:30',
        '_uc_venue_name'  => 'Strut',
        '_uc_location'    => '470 Castro St, San Francisco',
    ),
    102 => array(
        '_uc_event_date' => '2026-10-02',
        '_uc_start_time' => '10:00',
        '_uc_end_time'   => '11:30',
        '_uc_address'    => 'Online',
    ),
);

$shortcodes = new SFAF_Shortcodes();
$render     = new ReflectionMethod( 'SFAF_Shortcodes', 'render_event_card' );
$render->setAccessible( true );

$cards = '';
foreach ( array( 101, 102, 101, 102, 101, 102, 101, 102, 101, 102, 101, 102 ) as $id ) {
    $cards .= $render->invoke( $shortcodes, $id );
}

/*
 * TWELVE CARDS, WHICH IS THE PER-PAGE DEFAULT AND ALSO THE POINT.
 *
 * The fault this file was written for needed enough cards to exceed a height cap
 * before it appeared: with four cards the panel was under the cap and every card
 * was correct. A parity page rendering one card of each kind would have passed
 * while the live page was strips. The count is what makes the page a test.
 */

if ( '' === trim( $cards ) ) {
    echo "FAIL: the renderer produced no markup, so there is nothing to compare\n";
    exit( 1 );
}

/* =========================================================================
 * The page
 * ====================================================================== */

/*
 * A NOWDOC, NOT A HEREDOC, AND THE DIFFERENCE COST A ROUND OF DEBUGGING.
 * <<<HTML interpolates, so every backslash-n in the page's JavaScript became a
 * real newline and every L.join('...') was an unterminated string literal. The
 * page then parsed as nothing, printed 'measuring...' forever and looked like a
 * slow render rather than a broken one. <<<'HTML' interprets nothing; the cards
 * go in by a placeholder below.
 */
$page = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: combined mode card parity</title>
<!--
  GENERATED BY .claude/combined-card-parity.php. Do not edit; regenerate.

  The same card markup, from SFAF_Shortcodes::render_event_card(), in a list-mode
  block and in a combined-mode block, both 770px wide, which is what sfaf.org's
  template gives the block and cannot be widened. Every element of every card is
  compared between the two: presence, computed display, and rendered box.

  Run it headless and read the block at the bottom:

      chrome --headless --disable-gpu --virtual-time-budget=6000 \
             --dump-dom file:///.../.claude/combined-card-parity.html
-->
<link rel="stylesheet" href="../public/css/calendar.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; color: #373433; }
  h1 { font-size: 17px; margin: 0 0 4px; }
  h2 { font-size: 13px; margin: 26px 0 8px; text-transform: uppercase; letter-spacing: .06em; color: #6B7280; }
  p.note { color: #6B7280; margin: 0 0 10px; max-width: 78ch; }
  /* 770px, and nothing else about the host page. The point of comparison is the
     two blocks, so the container they sit in has to be the same for both. */
  .host { width: 770px; outline: 1px dashed #bbb; margin: 0 0 24px; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; overflow-x: auto; }
</style>
</head>
<body>

<h1>Combined mode card parity</h1>
<p class="note">The same twelve cards, rendered by SFAF_Shortcodes::render_event_card(), in a
list-mode block and in a combined-mode block. Both hosts are 770px, which is the width
sfaf.org's template gives the block. At that width the combined mode stacks, so its list panel
is full width and every card in it should be identical to the card beside it in list mode.</p>

<h2>List mode</h2>
<div class="host">
  <div class="uc-calendar uc-view-list" data-view="list">
    <div class="uc-view-panels">
      <div class="uc-view-panel uc-panel-list">
        <div class="uc-event-list" id="list-mode">__CARDS__</div>
      </div>
      <div class="uc-view-panel uc-panel-calendar" hidden></div>
    </div>
  </div>
</div>

<h2>Combined mode</h2>
<div class="host">
  <div class="uc-calendar uc-view-combined" data-view="combined">
    <div class="uc-view-panels uc-view-panels-combined">
      <div class="uc-view-panel uc-panel-calendar">
        <div class="uc-month">
          <table class="uc-month-grid"><tr>
            <td class="uc-day"><span class="uc-day-num">1</span>
              <div class="uc-day-events"><div class="uc-day-event">
                <a href="#"><span class="uc-day-event-title">Community advisory board</span></a>
              </div></div>
            </td>
            <td>2</td><td>3</td><td>4</td><td>5</td><td>6</td><td>7</td>
          </tr></table>
          <div class="uc-month-day-panel"></div>
        </div>
      </div>
      <div class="uc-view-panel uc-panel-list">
        <div class="uc-event-list" id="combined-mode">__CARDS__</div>
      </div>
    </div>
  </div>
</div>

<pre id="out">measuring...</pre>

<script>
window.addEventListener('load', function () {
  try {
  var fails = [];
  var L = [];

  var listRoot = document.getElementById('list-mode');
  var combRoot = document.getElementById('combined-mode');

  /* THE PANEL ITSELF FIRST. Stacked, the combined list panel is the full 770px,
     exactly as the list mode's panel is. A panel that is not the same width
     makes every comparison below meaningless rather than failing honestly, so it
     is checked before anything is compared inside it. */
  var lp = document.querySelector('.uc-view-list .uc-panel-list');
  var cp = document.querySelector('.uc-view-combined .uc-panel-list');
  var lpw = lp.getBoundingClientRect().width;
  var cpw = cp.getBoundingClientRect().width;
  L.push('list panel     ' + Math.round(lpw) + 'px');
  L.push('combined panel ' + Math.round(cpw) + 'px  (stacked, so it should match)');
  if (Math.abs(lpw - cpw) > 0.5) {
    fails.push('the combined list panel is ' + Math.round(cpw) + 'px where list mode is ' +
               Math.round(lpw) + 'px, so the two are not being compared at the same width');
  }

  /* EVERY ELEMENT, IN DOCUMENT ORDER, NOT A LIST OF THE ONES SOMEBODY THOUGHT OF.
     The markup in the two panels is the same string, so the two walks must
     produce the same sequence. Comparing the sequences catches a divergence in
     an element nobody would have put on a checklist, which is the whole reason
     this is a comparison rather than an inspection. */
  function walk(root) {
    return Array.prototype.slice.call(root.querySelectorAll('*'));
  }
  var a = walk(listRoot);
  var b = walk(combRoot);

  L.push('elements per panel: ' + a.length + ' list, ' + b.length + ' combined');
  if (a.length !== b.length) {
    fails.push('the two panels contain different numbers of elements (' + a.length + ' vs ' +
               b.length + '), so the markup itself diverged');
  }

  function label(el) {
    return el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : '');
  }

  var n = Math.min(a.length, b.length);
  var geometry = 0, display = 0, identity = 0;
  for (var i = 0; i < n; i++) {
    if (label(a[i]) !== label(b[i])) {
      if (identity < 3) {
        fails.push('element ' + i + ' is ' + label(a[i]) + ' in list mode and ' + label(b[i]) + ' in combined');
      }
      identity++;
      continue;
    }
    var ra = a[i].getBoundingClientRect(), rb = b[i].getBoundingClientRect();
    var ca = getComputedStyle(a[i]), cb = getComputedStyle(b[i]);

    if (ca.display !== cb.display || ca.visibility !== cb.visibility) {
      if (display < 4) {
        fails.push(label(a[i]) + ' computes display:' + cb.display + '/visibility:' + cb.visibility +
                   ' in combined and display:' + ca.display + '/visibility:' + ca.visibility + ' in list mode');
      }
      display++;
      continue;
    }
    /* One pixel of tolerance, because subpixel layout differs harmlessly between
       two positions on a page. A card that has been squashed from 300px to 40px
       is not within a pixel of anything. */
    if (Math.abs(ra.width - rb.width) > 1 || Math.abs(ra.height - rb.height) > 1) {
      if (geometry < 6) {
        fails.push(label(a[i]) + ' renders ' + Math.round(rb.width) + 'x' + Math.round(rb.height) +
                   ' in combined and ' + Math.round(ra.width) + 'x' + Math.round(ra.height) + ' in list mode');
      }
      geometry++;
    }
  }
  if (geometry > 6) { fails.push('and ' + (geometry - 6) + ' further element(s) differ in size'); }
  if (display > 4) { fails.push('and ' + (display - 4) + ' further element(s) differ in display'); }

  /* THE NAMED CHECKS. The sweep above would catch all of these, and they are
     spelled out anyway: a failure that says "the title is not visible" is worth
     more to whoever reads it than one that says element 14 is 0x0. */
  function named(sel, what) {
    var la = listRoot.querySelectorAll(sel), lb = combRoot.querySelectorAll(sel);
    if (lb.length !== la.length) {
      fails.push(what + ': ' + lb.length + ' in combined, ' + la.length + ' in list mode');
      return;
    }
    if (!la.length) {
      fails.push(what + ' is absent from BOTH panels, so this page is not testing it');
      return;
    }
    for (var i = 0; i < lb.length; i++) {
      var r = lb[i].getBoundingClientRect();
      if (r.width < 1 || r.height < 1) {
        fails.push(what + ' renders ' + Math.round(r.width) + 'x' + Math.round(r.height) + ' in combined');
        return;
      }
      if (getComputedStyle(lb[i]).display === 'none') {
        fails.push(what + ' is display:none in combined');
        return;
      }
    }
    L.push('  ok  ' + what + ' (' + lb.length + ')');
  }

  L.push('');
  L.push('NAMED PARTS OF THE CARD');
  named('.uc-event-card', 'the card itself');
  named('.uc-lc-head', 'the card header');
  named('.uc-card-title', 'the title');
  named('.uc-lc-media', 'the image slot');
  named('.uc-card-meta', 'the meta block');
  named('.uc-lc-foot', 'the footer');
  named('.uc-lc-actions', 'the button row');

  /* NOTHING IS CLIPPED OUT OF ITS OWN CARD. The card is overflow: hidden, which
     is what turned the squashed cards into strips: every part was still in the
     DOM, still "visible", and simply outside the box. A presence check cannot
     see that and this can. */
  /* THE CARD MUST BE THE THING DOING THE CLIPPING. .uc-lc-media is itself
     overflow: hidden with an aspect-ratio, so the image inside it reaches past
     the card's bottom edge on paper and is cropped by its own frame long before
     that, in both modes. Counting it reported twelve clips that were never
     visible to anybody. So an element only counts when the nearest ancestor that
     clips is the card, which is what "clipped out of its card" means. */
  function clipsAt(el, card) {
    for (var p = el.parentElement; p && p !== card.parentElement; p = p.parentElement) {
      var o = getComputedStyle(p);
      if (o.overflow !== 'visible' || o.overflowY !== 'visible') { return p; }
    }
    return null;
  }
  function clippedIn(root) {
    var out = [];
    Array.prototype.forEach.call(root.querySelectorAll('.uc-event-card'), function (card) {
      var cr = card.getBoundingClientRect();
      Array.prototype.forEach.call(card.querySelectorAll('*'), function (el) {
        var r = el.getBoundingClientRect();
        if (r.height > 0 && r.bottom > cr.bottom + 0.5 && clipsAt(el, card) === card) {
          out.push(label(el) + ' by ' + Math.round(r.bottom - cr.bottom) + 'px');
        }
      });
    });
    return out;
  }
  /* MEASURED IN BOTH PANELS, AND THE COMPARISON IS WHAT FAILS. A card that
     clips something in list mode clips it here too, and that is the list card's
     business rather than this mode's. Only a clip the combined mode adds is a
     parity failure. Counting only this panel made the first run of this page
     report twelve clips that list mode had as well. */
  var clipC = clippedIn(combRoot);
  var clipL = clippedIn(listRoot);
  if (clipC.length > clipL.length) {
    fails.push((clipC.length - clipL.length) + ' element(s) are laid out below the bottom edge of ' +
               'their own card in the combined mode and not in list mode, and the card is overflow: ' +
               'hidden, so they are clipped rather than shown: ' +
               Array.from(new Set(clipC)).slice(0, 4).join(', '));
  }
  var clipped = clipC.length;

  /* AND THE CARD IS A CARD RATHER THAN A STRIP. An absolute floor, so this page
     still says something if both panels are broken the same way and the
     comparison above therefore passes. */
  Array.prototype.forEach.call(combRoot.querySelectorAll('.uc-event-card'), function (card, i) {
    var h = card.getBoundingClientRect().height;
    if (h < 120 && i === 0) {
      fails.push('a card in the combined mode is ' + Math.round(h) + 'px tall; the list card carries a ' +
                 'header, a title, meta and a footer and cannot fit in that');
    }
  });

  var cardH = combRoot.querySelector('.uc-event-card').getBoundingClientRect().height;
  var listH = listRoot.querySelector('.uc-event-card').getBoundingClientRect().height;
  L.push('');
  L.push('first card: ' + Math.round(listH) + 'px in list mode, ' + Math.round(cardH) + 'px in combined');
  L.push('compared: ' + n + ' elements, ' + geometry + ' differing in size, ' + display +
         ' in display, ' + identity + ' in identity');
  L.push('clipped by their own card: ' + clipL.length + ' in list mode, ' + clipC.length + ' in combined' +
         (clipC.length ? '  (' + Array.from(new Set(clipC)).join(', ') + ')' : ''));

  L.push('');
  L.push(fails.length ? 'FAIL: ' + Array.from(new Set(fails)).join(' | ')
                      : 'ok: every element of every card renders identically in the combined mode and in list mode at 770px');
  document.getElementById('out').textContent = L.join('\n');
  } catch (e) {
    /* A page that throws must SAY it threw. The first run of this printed
       "measuring..." and nothing else, which reads exactly like a page that has
       not finished rather than one that fell over. */
    document.getElementById('out').textContent = 'THE PAGE THREW: ' + (e && e.message) + '\n' + (e && e.stack);
  }
});
</script>

</body>
</html>
HTML;

$page = str_replace( '__CARDS__', $cards, $page );

$out = __DIR__ . '/combined-card-parity.html';
file_put_contents( $out, $page );

echo "Combined mode card parity\n";
printf( "rendered: %d cards from SFAF_Shortcodes::render_event_card(), %d bytes of markup\n",
    12, strlen( $cards ) );
echo "wrote:    " . $out . "\n";
if ( $GLOBALS['php_notices'] ) {
    /*
     * REPORTED AND NOT ASSERTED. These are raised by the renderer itself and are
     * nothing to do with the parity question, so failing on them here would block
     * a build over a defect this file was not written to police. They are printed
     * because a notice raised inside ob_start() is otherwise invisible: it lands
     * in the markup instead of on the terminal.
     */
    $seen = array_unique( $GLOBALS['php_notices'] );
    printf( "\nPHP notices raised by the renderer, %d occurrence(s), %d distinct:\n",
        count( $GLOBALS['php_notices'] ), count( $seen ) );
    foreach ( $seen as $n ) { echo '  . ' . $n . "\n"; }
    echo "  these are the renderer's own and are reported rather than asserted here\n";
}

echo "\nnow render it and read the block at the bottom of the page:\n";
echo "  chrome --headless --disable-gpu --virtual-time-budget=6000 --dump-dom \\\n";
echo "         file:///" . str_replace( '\\', '/', $out ) . "\n";
exit( 0 );
