<?php
/**
 * IS THE COMBINED MODE'S RIGHT-HAND COLUMN THE SIDEBAR MODE?
 *
 *     php .claude/combined-panel-parity.php
 *     chrome --headless --disable-gpu --virtual-time-budget=8000 \
 *            --dump-dom file:///.../.claude/combined-panel-parity.html
 *
 * WHY THIS EXISTS. The combined mode composes the month grid and the sidebar
 * rather than adding renderers of its own, so any difference between its right
 * column and a standalone sidebar is a bug by definition. Three builds running,
 * the tests here asserted things ABOUT that claim and not the claim itself:
 *
 *   3.31.0  asserted both panels are built and neither is marked hidden. The
 *           browser then hid both and the mode rendered no events at all.
 *   3.31.1  added a test that counts the events a visitor can see. It passed
 *           while every card in the panel was a 40px strip with no title, no
 *           image, no meta and no button, because the cards were there and were
 *           visible. Counting is not looking.
 *   3.31.2  compared the rendered card against the same card in list mode, which
 *           was the right shape of test aimed at the wrong panel: the column
 *           should never have held a list card at all.
 *
 * 3.32.0 made the right column the sidebar, so this compares against the sidebar
 * mode. Same test, different target, and the target is the point: the sidebar was
 * designed for a narrow column and is already correct at this width, so "the same
 * as standalone" is the whole specification.
 *
 * THE MARKUP IS THE RENDERER'S OWN. This stubs enough WordPress to call
 * SFAF_Shortcodes::render_sidebar() for real, once, and puts that one string in
 * both places. A hand-written fixture cannot catch a divergence affecting an
 * element the fixture author forgot.
 *
 * TWO WIDTHS, because the mode has two shapes. 770px is sfaf.org, where it
 * stacks; 1000px is side by side, where the panel is narrower than the block. The
 * standalone host at each width is set to the width the panel actually measured,
 * so the two are compared at the same width rather than at the same page width.
 *
 * It writes .claude/combined-panel-parity.html, generated and committed so it can
 * be opened in a browser without running PHP. Regenerate rather than editing.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

/*
 * NOTICES ARE COLLECTED, NOT PRINTED, AND THAT IS NOT TIDINESS.
 *
 * The markup is built inside ob_start(), so a warning raised while it renders is
 * captured into the markup rather than shown on the terminal: the first run of
 * this file put "Warning: Undefined variable $cat_name" inside twenty-four cards
 * and the page compared them happily, because both sides had the same corrupt
 * string in them. Swallowing them here keeps the fixture clean, and printing the
 * list at the end means they are still reported rather than lost. That warning
 * was a real defect and is fixed in 3.32.0; this stays, because it is how the
 * next one gets noticed.
 */
error_reporting( E_ALL );
$GLOBALS['php_notices'] = array();
set_error_handler( function ( $no, $str, $file = '', $line = 0 ) {
    $GLOBALS['php_notices'][] = basename( (string) $file ) . ':' . (int) $line . '  ' . $str;
    return true;
} );

/* =========================================================================
 * WordPress, in miniature. Only what the sidebar renderer and its helpers reach.
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
function has_post_thumbnail( $id = 0 ) { return ! empty( $GLOBALS['posts'][ $id ]['thumb'] ); }
function get_the_post_thumbnail_url( $id = 0, $size = 'full' ) {
    return isset( $GLOBALS['posts'][ $id ]['thumb'] ) ? $GLOBALS['posts'][ $id ]['thumb'] : false;
}
function get_term_meta( $id, $key = '', $single = false ) {
    return isset( $GLOBALS['term_meta'][ $id ][ $key ] ) ? $GLOBALS['term_meta'][ $id ][ $key ] : '';
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

/*
 * THE QUERY, YIELDING THE FIXTURE EVENTS.
 *
 * render_sidebar() goes through render_events(), which runs a WP_Query and walks
 * it, so a WP_Query returning nothing renders the sidebar's empty state and the
 * page would compare two identical "No upcoming dates scheduled just now."
 * boxes and pass. The stub yields the ids in $GLOBALS['query_ids'] and reports a
 * found_posts larger than the page, which is the real shape: a sidebar shows its
 * count and links out to the rest.
 */
function sfaf_prime_rsvp_counts( $event_ids ) {}
function wp_reset_postdata() {}
function get_the_ID() { return $GLOBALS['current_post']; }

class WP_Error {
    public function get_error_message() { return 'error'; }
}
class WP_Query {
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 1;
    private $i = 0;
    private $ids = array();

    public function __construct( $args = array() ) {
        $per = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
        $all = isset( $GLOBALS['query_ids'] ) ? $GLOBALS['query_ids'] : array();
        $this->ids = ( $per > 0 ) ? array_slice( $all, 0, $per ) : $all;
        foreach ( $this->ids as $id ) {
            $this->posts[] = (object) array( 'ID' => $id );
        }
        // Deliberately more than the page: the count line says "coming up" and
        // the sidebar shows a fixed number of them.
        $this->found_posts   = isset( $GLOBALS['query_total'] ) ? (int) $GLOBALS['query_total'] : count( $all );
        $this->max_num_pages = ( $per > 0 ) ? (int) ceil( $this->found_posts / $per ) : 1;
    }
    public function have_posts() { return $this->i < count( $this->ids ); }
    public function the_post() { $GLOBALS['current_post'] = $this->ids[ $this->i ]; $this->i++; }
}

/*
 * The plugin's own classes, stubbed rather than loaded. Loading them drags in
 * their hooks and their queries and none of that decides what a row looks like.
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
    public static function resolve( $raw ) { return 0; }
}
class SFAF_Categories {
    public static function icon( $term_id, $name = '' ) { return 'calendar'; }
}
class SFAF_Privacy {
    public static function is_private( $post_id ) { return false; }
    public static function exclude( $args ) { return $args; }
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
}
class SFAF_Source_GFMP {
    public static function raised_amount( $post_id ) { return 0; }
}

require_once $root . '/includes/sfaf-template-functions.php';
require_once $root . '/includes/class-sfaf-shortcodes.php';

/* =========================================================================
 * The fixture: ten upcoming dates, one of them with no image
 * ====================================================================== */

$GLOBALS['terms'] = array(
    7 => array( 'term_id' => 7, 'name' => 'Support Groups', 'slug' => 'support-groups', 'taxonomy' => 'uc_event_category' ),
    9 => array( 'term_id' => 9, 'name' => 'SFAF', 'slug' => 'sfaf', 'taxonomy' => 'uc_organizer' ),
);

$titles = array(
    'Community advisory board, September session',
    'Volunteer orientation',
    'Positive Force peer group',
    'Elevate Young Men of Color',
    'Strut drop-in counselling',
    'Black Brothers Esteem weekly meeting',
    'Trans Thrive community lunch',
    'Syringe access and disposal training',
    'HIV testing, Castro site',
    'Stonewall Project intake',
);

$GLOBALS['posts']      = array();
$GLOBALS['meta']       = array();
$GLOBALS['post_terms'] = array();
$GLOBALS['query_ids']  = array();

foreach ( $titles as $n => $title ) {
    $id = 101 + $n;
    $GLOBALS['posts'][ $id ] = array(
        'title'   => $title,
        'excerpt' => 'An open session, with interpretation and food provided.',
        'content' => 'An open session.',
    );
    // Every third event has no image, which is the placeholder tile's case and
    // roughly the real proportion for imported events.
    if ( 0 !== $n % 3 ) {
        $GLOBALS['posts'][ $id ]['thumb'] = 'data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACwAAAAAAQABAAACAkQBADs=';
    }
    $GLOBALS['meta'][ $id ] = array(
        '_uc_event_date' => sprintf( '2026-09-%02d', 3 + $n * 2 ),
        '_uc_start_time' => '18:00',
        '_uc_end_time'   => '19:30',
        '_uc_location'   => '470 Castro St, San Francisco',
    );
    $GLOBALS['post_terms'][ $id . '|uc_event_category' ] = array( 7 );
    $GLOBALS['post_terms'][ $id . '|uc_organizer' ]      = array( 9 );
    $GLOBALS['query_ids'][] = $id;
}
$GLOBALS['query_total'] = 29;

$shortcodes = new SFAF_Shortcodes();

/*
 * ONE STRING, PUT IN BOTH PLACES. render_sidebar() is what the sidebar display
 * mode returns and what the combined mode's right panel now wraps, so calling it
 * once and using the result twice is the same guarantee the product makes.
 */
/*
 * groups IS A CSV STRING, NOT AN ARRAY, which is what effective_groups() returns
 * and what the block puts in $filters. Passing array() here raised "Array to
 * string conversion" out of slug_list(), which the notice collector caught: the
 * fixture was wrong, not the renderer.
 */
$filters = array(
    'category'  => '',
    'organizer' => '',
    'series'    => 0,
    'venue'     => '',
    's'         => '',
    'groups'    => '',
);
$sidebar = $shortcodes->render_sidebar( $filters, SFAF_Shortcodes::sidebar_count( 0 ), null );

if ( '' === trim( $sidebar ) || false === strpos( $sidebar, 'uc-sidebar-row' ) ) {
    echo "FAIL: the sidebar renderer produced no rows, so there is nothing to compare\n";
    exit( 1 );
}

/*
 * The left panel is representative rather than rendered: the comparison is about
 * the RIGHT panel, and the grid is here to occupy the left column so the flex
 * line, the panel widths and the container naming are the real ones. Building it
 * for real would need the month query and would not change any measurement this
 * page takes.
 */
$grid = '<div class="uc-view-panel uc-panel-calendar"><div class="uc-month">'
    . '<table class="uc-month-grid"><tr>'
    . '<td class="uc-day"><span class="uc-day-num">1</span>'
    . '<div class="uc-day-events"><div class="uc-day-event">'
    . '<a href="#"><span class="uc-day-event-title">Community advisory board</span></a>'
    . '</div></div></td>'
    . '<td>2</td><td>3</td><td>4</td><td>5</td><td>6</td><td>7</td>'
    . '</tr></table><div class="uc-month-day-panel"></div>'
    . '</div></div>';

/* =========================================================================
 * The page
 * ====================================================================== */

/*
 * A NOWDOC, NOT A HEREDOC, AND THE DIFFERENCE COST A ROUND OF DEBUGGING.
 * <<<HTML interpolates, so every backslash-n in the page's JavaScript became a
 * real newline and every L.join('...') was an unterminated string literal. The
 * page then parsed as nothing, printed 'measuring...' forever and looked like a
 * slow render rather than a broken one. <<<'HTML' interprets nothing; the markup
 * goes in by placeholder below.
 */
$page = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SFAF Calendar: combined mode sidebar parity</title>
<!--
  GENERATED BY .claude/combined-panel-parity.php. Do not edit; regenerate.

  The same sidebar markup, from SFAF_Shortcodes::render_sidebar(), standalone and
  inside the combined mode's right panel, at two widths: 770px where the mode
  stacks, which is sfaf.org, and 1000px where it sits beside the grid. Every
  element is compared between the two: presence, computed display, rendered box,
  and whether anything is clipped.

      chrome --headless --disable-gpu --virtual-time-budget=8000 \
             --dump-dom file:///.../.claude/combined-panel-parity.html
-->
<link rel="stylesheet" href="../public/css/calendar.css">
<style>
  body { margin: 0; padding: 20px; font: 13px/1.5 system-ui, sans-serif; background: #f4f5f7; color: #373433; }
  h1 { font-size: 17px; margin: 0 0 4px; }
  h2 { font-size: 13px; margin: 26px 0 8px; text-transform: uppercase; letter-spacing: .06em; color: #6B7280; }
  p.note { color: #6B7280; margin: 0 0 10px; max-width: 78ch; }
  .host { outline: 1px dashed #bbb; margin: 0 0 24px; }
  pre#out { margin-top: 22px; background: #fff; padding: 14px; border: 1px solid #ccc;
            font: 12px/1.5 ui-monospace, Consolas, monospace; overflow-x: auto; }
</style>
</head>
<body>

<h1>Combined mode sidebar parity</h1>
<p class="note">The right-hand column of the combined mode is the sidebar display mode. Both
blocks below hold the SAME markup string from SFAF_Shortcodes::render_sidebar(). The standalone
host at each width is resized by script to the width the combined panel actually measured, so
the two are compared at the same width rather than at the same page width.</p>

<h2>770px, where the mode stacks. This is sfaf.org.</h2>
<div class="host" style="width:770px" id="host-770">
  <div class="uc-calendar uc-view-combined" data-view="combined">
    <div class="uc-view-panels uc-view-panels-combined">
      __GRID__
      <div class="uc-view-panel uc-panel-sidebar" id="panel-770">__SIDEBAR__</div>
    </div>
  </div>
</div>
<div class="host" id="alone-770"><div id="alone-770-inner">__SIDEBAR__</div></div>

<h2>1000px, where the two sit side by side</h2>
<div class="host" style="width:1000px" id="host-1000">
  <div class="uc-calendar uc-view-combined" data-view="combined">
    <div class="uc-view-panels uc-view-panels-combined">
      __GRID__
      <div class="uc-view-panel uc-panel-sidebar" id="panel-1000">__SIDEBAR__</div>
    </div>
  </div>
</div>
<div class="host" id="alone-1000"><div id="alone-1000-inner">__SIDEBAR__</div></div>

<pre id="out">measuring...</pre>

<script>
window.addEventListener('load', function () {
  try {
  var fails = [];
  var L = [];

  function label(el) {
    return el.tagName.toLowerCase() +
      (el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : '');
  }

  /* THE STANDALONE HOST IS SIZED TO THE PANEL IT IS BEING COMPARED WITH.
     Comparing a sidebar in a 1000px host against one in a 344px panel would
     report every element as different and none of it would mean anything. */
  function align(panelId, hostId) {
    var panel = document.getElementById(panelId);
    var host = document.getElementById(hostId);
    var w = panel.getBoundingClientRect().width;
    host.style.width = w + 'px';
    return w;
  }

  function compare(name, panelId, hostId) {
    var w = align(panelId, hostId);
    var inPanel = document.getElementById(panelId).querySelector('.uc-sidebar');
    var alone = document.getElementById(hostId).querySelector('.uc-sidebar');

    L.push('');
    L.push(name.toUpperCase());
    L.push('  panel ' + Math.round(w) + 'px, standalone host resized to match');

    if (!inPanel || !alone) {
      fails.push(name + ': one side has no .uc-sidebar at all');
      return;
    }

    var pw = inPanel.getBoundingClientRect().width;
    var aw = alone.getBoundingClientRect().width;
    L.push('  sidebar card ' + Math.round(pw) + 'px in the panel, ' + Math.round(aw) + 'px standalone');
    if (Math.abs(pw - aw) > 1) {
      fails.push(name + ': the sidebar card is ' + Math.round(pw) + 'px in the combined panel and ' +
                 Math.round(aw) + 'px standalone');
    }

    /* EVERY ELEMENT, IN DOCUMENT ORDER, not a list of the ones somebody thought
       of. The two subtrees come from one string, so the walks must agree. */
    var a = Array.prototype.slice.call(alone.querySelectorAll('*'));
    var b = Array.prototype.slice.call(inPanel.querySelectorAll('*'));
    L.push('  elements: ' + a.length + ' standalone, ' + b.length + ' in the panel');
    if (a.length !== b.length) {
      fails.push(name + ': ' + a.length + ' elements standalone against ' + b.length +
                 ' in the panel, so the markup itself diverged');
    }

    var n = Math.min(a.length, b.length);
    var geometry = 0, display = 0, identity = 0, fonts = 0;
    for (var i = 0; i < n; i++) {
      if (label(a[i]) !== label(b[i])) {
        if (identity < 3) {
          fails.push(name + ': element ' + i + ' is ' + label(a[i]) + ' standalone and ' +
                     label(b[i]) + ' in the panel');
        }
        identity++;
        continue;
      }
      var ca = getComputedStyle(a[i]), cb = getComputedStyle(b[i]);
      if (ca.display !== cb.display || ca.visibility !== cb.visibility) {
        if (display < 4) {
          fails.push(name + ': ' + label(a[i]) + ' computes display:' + cb.display + '/visibility:' +
                     cb.visibility + ' in the panel and display:' + ca.display + '/visibility:' +
                     ca.visibility + ' standalone');
        }
        display++;
        continue;
      }
      /* FONT AND COLOUR TOO, not only the box. Nesting .uc-sidebar inside
         .uc-calendar exposes it to every .uc-calendar-scoped rule in the
         stylesheet, and a rule that repaints a heading or a link changes nothing
         about its size. That is the divergence a geometry-only comparison would
         have waved through. */
      if (ca.fontFamily !== cb.fontFamily || ca.fontSize !== cb.fontSize ||
          ca.fontWeight !== cb.fontWeight || ca.color !== cb.color ||
          ca.textDecorationLine !== cb.textDecorationLine) {
        if (fonts < 4) {
          fails.push(name + ': ' + label(a[i]) + ' is typeset differently in the panel (' +
                     cb.fontSize + '/' + cb.fontWeight + ' ' + cb.color + ') than standalone (' +
                     ca.fontSize + '/' + ca.fontWeight + ' ' + ca.color + ')');
        }
        fonts++;
      }
      var ra = a[i].getBoundingClientRect(), rb = b[i].getBoundingClientRect();
      if (Math.abs(ra.width - rb.width) > 1 || Math.abs(ra.height - rb.height) > 1) {
        if (geometry < 6) {
          fails.push(name + ': ' + label(a[i]) + ' renders ' + Math.round(rb.width) + 'x' +
                     Math.round(rb.height) + ' in the panel and ' + Math.round(ra.width) + 'x' +
                     Math.round(ra.height) + ' standalone');
        }
        geometry++;
      }
    }
    if (geometry > 6) { fails.push(name + ': and ' + (geometry - 6) + ' further element(s) differ in size'); }
    if (display > 4) { fails.push(name + ': and ' + (display - 4) + ' further element(s) differ in display'); }
    if (fonts > 4) { fails.push(name + ': and ' + (fonts - 4) + ' further element(s) are typeset differently'); }

    L.push('  differences: ' + geometry + ' in size, ' + display + ' in display, ' + identity +
           ' in identity, ' + fonts + ' in type');

    /* THE NAMED PARTS. The sweep above catches all of these; they are spelled out
       because "the See all events link is missing" is worth more to a reader than
       "element 41 is 0x0". */
    [['.uc-sidebar-heading', 'the heading'],
     ['.uc-sidebar-list', 'the list'],
     ['.uc-sidebar-row', 'the rows'],
     ['.uc-sidebar-thumb', 'the thumbnails'],
     ['.uc-sidebar-title', 'the titles'],
     ['.uc-sidebar-when', 'the date and time line'],
     ['.uc-sidebar-all', 'the See all events link']].forEach(function (pair) {
      var want = alone.querySelectorAll(pair[0]).length;
      var got = inPanel.querySelectorAll(pair[0]).length;
      if (!want) {
        fails.push(name + ': ' + pair[1] + ' is absent from BOTH, so this page is not testing it');
        return;
      }
      if (want !== got) {
        fails.push(name + ': ' + pair[1] + ' appears ' + got + ' times in the panel and ' + want + ' standalone');
        return;
      }
      var bad = 0;
      Array.prototype.forEach.call(inPanel.querySelectorAll(pair[0]), function (el) {
        var r = el.getBoundingClientRect();
        if (r.width < 1 || r.height < 1 || getComputedStyle(el).display === 'none') { bad++; }
      });
      if (bad) {
        fails.push(name + ': ' + bad + ' of ' + got + ' ' + pair[1] + ' render at no size in the panel');
        return;
      }
      L.push('    ok  ' + pair[1] + ' (' + got + ')');
    });

    /* NOTHING CONSTRAINS THE HEIGHT AND NOTHING SCROLLS. The panel, the card and
       the list, which are the three boxes that could squash a row. scrollHeight
       against clientHeight asks the symptom rather than the mechanism: it catches
       a max-height, a fixed height or a flex constraint and needs to know none of
       them. getComputedStyle().height gives the used pixel height and never
       'auto', so testing it for 'auto' is a test that always fails. */
    var panel = document.getElementById(panelId);
    [[panel, 'the panel'], [inPanel, 'the sidebar card'],
     [inPanel.querySelector('.uc-sidebar-list'), 'the list']].forEach(function (pair) {
      var el = pair[0];
      if (!el) { return; }
      if (getComputedStyle(el).maxHeight !== 'none') {
        fails.push(name + ': ' + pair[1] + ' has max-height: ' + getComputedStyle(el).maxHeight);
      }
      if (el.scrollHeight > el.clientHeight + 1) {
        fails.push(name + ': ' + pair[1] + ' is ' + el.clientHeight + 'px around ' + el.scrollHeight +
                   'px of content, so something is constraining its height');
      }
    });
    Array.prototype.forEach.call(panel.querySelectorAll('*'), function (el) {
      var cs = getComputedStyle(el);
      if (cs.overflowY === 'auto' || cs.overflowY === 'scroll' || cs.overflowX === 'auto' || cs.overflowX === 'scroll') {
        fails.push(name + ': ' + label(el) + ' in the right panel is a scroll region');
      }
    });

    /* AND NO LIST CARD, NO PAGINATION, NO LOAD MORE. The point of the change is
       that these are not in this mode at all, so their absence is asserted rather
       than assumed from the markup looking right. */
    [['.uc-event-card', 'a list card'], ['.uc-event-list', 'a card list'],
     ['.uc-pagination', 'pagination'], ['.uc-load-more', 'a Load more button'],
     ['.uc-infinite-sentinel', 'an infinite-scroll sentinel']].forEach(function (pair) {
      var found = document.getElementById(panelId.replace('panel-', 'host-')).querySelectorAll(pair[0]).length;
      if (found) {
        fails.push(name + ': the combined block still contains ' + pair[1] + ' (' + found + ')');
      }
    });

    return { w: w, cardW: pw };
  }

  var stacked = compare('stacked at 770px', 'panel-770', 'alone-770');
  var side = compare('side by side at 1000px', 'panel-1000', 'alone-1000');

  /* The two shapes must genuinely be two shapes, or the page is testing one
     thing twice and reporting it as two. */
  if (stacked && side && Math.abs(stacked.w - side.w) < 1) {
    fails.push('both hosts produced a ' + Math.round(stacked.w) + 'px panel, so the stacked and ' +
               'side-by-side cases are not being distinguished');
  }

  L.push('');
  L.push(fails.length ? 'FAIL: ' + Array.from(new Set(fails)).join(' | ')
                      : 'ok: the right panel of the combined mode is the sidebar mode, element for ' +
                        'element, at 770px stacked and at 1000px side by side, with no height ' +
                        'constraint, no scroll region, no list card and no pagination in the block');
  document.getElementById('out').textContent = L.join('\n');
  } catch (e) {
    /* A page that throws must SAY it threw. An earlier version printed
       "measuring..." and nothing else, which reads exactly like a page that has
       not finished rather than one that fell over. */
    document.getElementById('out').textContent = 'THE PAGE THREW: ' + (e && e.message) + '\n' + (e && e.stack);
  }
});
</script>

</body>
</html>
HTML;

$page = str_replace( array( '__SIDEBAR__', '__GRID__' ), array( $sidebar, $grid ), $page );

$out = __DIR__ . '/combined-panel-parity.html';
file_put_contents( $out, $page );

preg_match_all( '/class="uc-sidebar-row"/', $sidebar, $rows );
preg_match_all( '/uc-thumb-ph/', $sidebar, $phs );

echo "Combined mode sidebar parity\n";
printf( "rendered: one sidebar from SFAF_Shortcodes::render_sidebar(), %d rows, %d of them the\n",
    count( $rows[0] ), count( $phs[0] ) );
echo "          category placeholder tile, " . strlen( $sidebar ) . " bytes, used in both places\n";
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
} else {
    echo "notices:  none\n";
}

echo "\nnow render it and read the block at the bottom of the page:\n";
echo "  chrome --headless --disable-gpu --virtual-time-budget=8000 --dump-dom \\\n";
echo "         file:///" . str_replace( '\\', '/', $out ) . "\n";
exit( 0 );
