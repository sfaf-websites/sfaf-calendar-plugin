<?php
/**
 * THE COMBINED MODE, ASSERTED ON WHAT IT RENDERS.
 *
 *     php .claude/combined-outcome-test.php
 *     php .claude/combined-outcome-test.php --self-test
 *
 * THIS MODE HAS SHIPPED THREE FAULTS IN THREE RELEASES AND THE SUITE PASSED
 * EVERY TIME.
 *
 *   3.31.1  both panels hidden, by two comparisons that were each correct.
 *   3.31.2  the list panel crushed to 40px rows by an inherited max-height.
 *   3.24.x  the grid handed a column too narrow to show an event in.
 *
 * Every test in place at the time asserted something ABOUT the markup rather
 * than the markup. So this file renders the real renderers and reads what came
 * back, and every assertion is written as the outcome:
 *
 *   1. Both halves render their events.
 *   2. The sidebar lists the month the grid is showing, and only that month.
 *   3. The month head appears once and governs both halves.
 *   4. The current month is the floor, with no way back offered on it.
 *   5. The month tabs offer next always and previous only past the floor.
 *   6. The venue is its own line, not crammed onto the date and time.
 *
 * WHAT IT CANNOT DO, said plainly: it does not lay anything out. There is no
 * browser here, so "neither half is clipped at the widths this runs at" is
 * asserted from the declared flex bases and the changeover, and checked by eye
 * in .claude/combined-panel-parity.html. The widths themselves are named in
 * the report so they can be argued with.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

$fails = array();
$self  = in_array( '--self-test', $argv, true );

/* ---------------------------------------------------------------------------
 * WordPress, in miniature, with ONE THING THE OTHER HARNESSES DO NOT HAVE:
 * a WP_Query that honours the date clauses. Without that, "the sidebar shows
 * the displayed month" cannot be tested at all, because every query would
 * return the same events whatever month was asked for. A stub that cannot read
 * its input does not test its input.
 * ------------------------------------------------------------------------ */
$GLOBALS['events'] = array();   // id => array(date, title, venue)
$GLOBALS['notices'] = array();
set_error_handler( function ( $no, $str ) { $GLOBALS['notices'][] = $str; return true; } );

function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return esc_html( $t ); }
function __( $t, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return ( 1 === (int) $n ) ? $s : $p; }
function _x( $t, $c, $d = '' ) { return $t; }
function apply_filters( $tag, $value ) { return $value; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function add_shortcode( $t, $c ) {}
function absint( $n ) { return abs( (int) $n ); }
function is_wp_error( $t ) { return false; }
function wp_kses_post( $t ) { return $t; }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : date( $type ); }
function date_i18n( $f, $ts = false ) { return date( $f, false === $ts ? time() : $ts ); }
function home_url( $p = '', $s = null ) { return 'https://example.org' . $p; }
function add_query_arg( $a, $v = '', $u = '' ) {
    if ( ! is_array( $a ) ) { $a = array( $a => $v ); } else { $u = $v; }
    $out = $u;
    foreach ( $a as $k => $val ) { $out .= ( false === strpos( $out, '?' ) ? '?' : '&' ) . $k . '=' . rawurlencode( $val ); }
    return $out;
}
function get_option( $n, $d = false ) { return $d; }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9\-]+/i', '-', trim( (string) $s ) ) ); }
function wp_strip_all_tags( $t, $b = false ) { return strip_tags( (string) $t ); }
function checked( $a, $b = true, $echo = true ) { return ''; }
function selected( $a, $b = true, $echo = true ) { return ''; }
function get_query_var( $v, $d = '' ) { return $d; }
function is_user_logged_in() { return false; }
function wp_create_nonce( $a = -1 ) { return 'n'; }
function shortcode_atts( $pairs, $atts, $sc = '' ) { return array_merge( $pairs, (array) $atts ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }

function get_post_meta( $id, $k, $single = false ) {
    if ( '_uc_event_date' === $k ) { return isset( $GLOBALS['events'][ $id ] ) ? $GLOBALS['events'][ $id ]['date'] : ''; }
    if ( '_uc_start_time' === $k ) { return '18:00'; }
    if ( '_uc_end_time' === $k ) { return '19:30'; }
    return '';
}
function get_the_title( $id = 0 ) { return isset( $GLOBALS['events'][ $id ] ) ? $GLOBALS['events'][ $id ]['title'] : ''; }
function get_the_ID() { return $GLOBALS['current_post']; }
function get_permalink( $id = 0 ) { return 'https://example.org/e/' . (int) $id; }
function has_post_thumbnail( $id = 0 ) { return false; }
function get_post( $id = 0 ) { return (object) array( 'ID' => $id, 'post_status' => 'publish' ); }
function wp_list_pluck( $list, $field ) {
    $out = array();
    foreach ( (array) $list as $item ) { $out[] = is_object( $item ) ? $item->$field : $item[ $field ]; }
    return $out;
}
function get_terms( $args = array() ) { return array(); }
function wp_get_post_terms( $id, $tax, $args = array() ) { return array(); }
function taxonomy_exists( $t ) { return true; }
function get_term_by( $a, $b, $c ) { return false; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function sfaf_prime_rsvp_counts( $ids ) {}
function sfaf_event_categories( $id ) { return array(); }
function sfaf_thumb_media( $id ) { return '<span class="uc-thumb-ph"></span>'; }
function sfaf_event_link( $id ) { return get_permalink( $id ); }
/* THE REAL STRINGS, NOT A BLANK STUB. These decide what a card's link markup
 * actually is, so a stub returning '' would let a renderer lose its new-tab
 * attributes without a single assertion here noticing. See sfaf_new_tab_attrs()
 * in sfaf-template-functions.php. */
function sfaf_new_tab_attrs() { return ' target="_blank" rel="noopener"'; }
function sfaf_new_tab_note() { return '<span class="uc-sr-only"> (opens in a new tab)</span>'; }
function sfaf_event_location_short( $id ) { return isset( $GLOBALS['events'][ $id ] ) ? $GLOBALS['events'][ $id ]['venue'] : ''; }
function sfaf_icon( $n, $a = array() ) { return ''; }
/*
 * NOT THE HOUSE DATE STYLE. Writing the real formats here would be a second
 * copy of what sfaf_ap_date() owns, living in a test, which is exactly what
 * date-callsite-sweep.php exists to stop. These assertions need values that are
 * stable and obviously a stub, not values that match production.
 */
function sfaf_ap_date( $d, $style = 'full' ) {
    $d = (string) $d;
    if ( '' === $d ) { return ''; }
    $parts = explode( '-', ( 7 === strlen( $d ) ? $d . '-01' : $d ) );
    if ( 3 !== count( $parts ) ) { return ''; }
    switch ( $style ) {
        case 'weekday':    return 'day';
        case 'short':      return 'm' . (int) $parts[1] . '/' . (int) $parts[2];
        case 'month_year': return 'month ' . $parts[0] . '/' . (int) $parts[1];
        default:           return 'date ' . implode( '/', $parts );
    }
}
function sfaf_ap_date_range( $a, $b ) { return sfaf_ap_date( $a, 'short' ) . ' to ' . sfaf_ap_date( $b, 'short' ); }
function sfaf_ap_time_range( $s, $e ) { return $s . ' to ' . $e; }
function sfaf_set_source_links( $v ) {}
function sfaf_ap_time( $t ) { return (string) $t; }
function sfaf_category_shades( $hex ) { return array( 'ink' => '#0C666F', 'media' => '#D5F3F6', 'chip' => '#E3F7F9' ); }
function sfaf_event_category_color( $id ) { return '#0E7680'; }
function sfaf_day_event_thumb( $id ) { return '<span class="uc-day-event-thumb"></span>'; }
/* The grid tile is a dot, a title and a time since 3.89.0. The thumb stub above
   stays because the helper is kept for one release; nothing renders it. */
function sfaf_day_event_dot( $id ) { return '<span class="uc-de-dot"></span>'; }
function sfaf_event_image_url( $id ) { return ''; }
function sfaf_event_location( $id ) { return sfaf_event_location_short( $id ); }
function sfaf_normalize_faqs( $f ) { return array(); }
function sfaf_rsvp_count( $id ) { return 0; }
function sfaf_flatten_html( $h ) { return trim( strip_tags( (string) $h ) ); }

function sfaf_get_source_links() { return false; }

class SFAF_Privacy { public static function exclude( &$args ) {} public static function is_private( $id ) { return false; } }
class SFAF_Cancellation {
    public static function exclude( &$args ) {}
    public static function is_cancelled( $id ) { return false; }
}
class SFAF_Embed {
    const CACHE_VERSION_OPTION = 'x';
    public static function calendar_url() { return 'https://example.org/calendar'; }
}
class SFAF_Search { public static function apply( &$a, $s ) {} }
class SFAF_Closures {
    public static function covering( $day ) { return null; }
    public static function in_range( $a, $b ) { return array(); }
    public static function for_range( $a, $b ) { return array(); }
    public static function days_in_range( $a, $b ) { return array(); }
}
class SFAF_Series {
    const TAXONOMY = 'uc_series';
    public static function for_event( $id ) { return null; }
    public static function resolve( $id ) { return (int) $id; }
    public static function all() { return array(); }
    public static function url( $id ) { return 'https://example.org/s/' . (int) $id; }
}

/**
 * A WP_Query that reads the date clauses.
 *
 * ONLY WHAT THE ASSERTIONS DEPEND ON: the `_uc_event_date` comparisons and the
 * ordering. Everything else about the query is ignored, which is stated rather
 * than hidden, because a stub that silently answered more than it understands
 * is how a test comes to prove nothing.
 */
class WP_Query {
    public $posts = array();
    public $post_count = 0;
    public $found_posts = 0;
    public $max_num_pages = 1;
    private $i = 0;
    private $ids = array();

    public function __construct( $args = array() ) {
        $lo = null; $hi = null;
        foreach ( (array) ( isset( $args['meta_query'] ) ? $args['meta_query'] : array() ) as $clause ) {
            if ( ! is_array( $clause ) || ! isset( $clause['key'] ) || '_uc_event_date' !== $clause['key'] ) { continue; }
            $cmp = isset( $clause['compare'] ) ? $clause['compare'] : '=';
            /*
             * CLAUSES ARE ANDed, SO TWO LOWER BOUNDS MEAN THE LATER ONE.
             *
             * This overwrote instead, so "today or later" AND "October or
             * later" kept whichever was written second and the harness could
             * not tell a working month binding from a broken one. WP_Query ANDs
             * meta_query clauses by default; a stub that models that as
             * "replace" is a stub that cannot see the bug.
             */
            if ( 'BETWEEN' === $cmp && is_array( $clause['value'] ) ) {
                $lo = ( null === $lo ) ? $clause['value'][0] : max( $lo, $clause['value'][0] );
                $hi = ( null === $hi ) ? $clause['value'][1] : min( $hi, $clause['value'][1] );
            } elseif ( '>=' === $cmp ) {
                $lo = ( null === $lo ) ? $clause['value'] : max( $lo, $clause['value'] );
            } elseif ( '<=' === $cmp ) {
                $hi = ( null === $hi ) ? $clause['value'] : min( $hi, $clause['value'] );
            }
        }

        $matched = array();
        foreach ( $GLOBALS['events'] as $id => $e ) {
            if ( null !== $lo && $e['date'] < $lo ) { continue; }
            if ( null !== $hi && $e['date'] > $hi ) { continue; }
            $matched[ $id ] = $e['date'];
        }
        asort( $matched );
        $all = array_keys( $matched );

        $per = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
        $this->ids = ( $per > 0 ) ? array_slice( $all, 0, $per ) : $all;
        foreach ( $this->ids as $id ) { $this->posts[] = (object) array( 'ID' => $id ); }
        $this->post_count  = count( $this->ids );
        $this->found_posts = count( $all );
        $this->max_num_pages = ( $per > 0 && $per < count( $all ) ) ? (int) ceil( count( $all ) / $per ) : 1;
    }
    public function have_posts() { return $this->i < count( $this->ids ); }
    public function the_post() { $GLOBALS['current_post'] = $this->ids[ $this->i ]; $this->i++; }
}
function wp_reset_postdata() {}

require_once $root . '/includes/class-sfaf-shortcodes.php';

/* ---------------------------------------------------------------------------
 * The world: events across this month, next month and the one after.
 * ------------------------------------------------------------------------ */
$this_month = date( 'Y-m' );
$next_month = date( 'Y-m', strtotime( 'first day of next month' ) );
$after      = date( 'Y-m', strtotime( 'first day of +2 months' ) );

/* Dated late enough in the month that "today or later" cannot drop them,
 * whatever day this file is run on. */
$last_this = date( 'Y-m-t' );
$id = 100;
foreach ( array(
    array( $last_this,        'Late This Month',  'Strut' ),
    array( $next_month . '-05', 'Next Month One', '1035 Market St' ),
    array( $next_month . '-19', 'Next Month Two', '' ),
    array( $after . '-07',      'Later One',      'Strut' ),
) as $e ) {
    $GLOBALS['events'][ $id++ ] = array( 'date' => $e[0], 'title' => $e[1], 'venue' => $e[2] );
}

$sc = new SFAF_Shortcodes();

function fail( $msg ) { global $fails; $fails[] = $msg; }
function rows_in( $html ) {
    preg_match_all( '#<a class="uc-sidebar-row"[^>]*href="[^"]*/e/(\d+)"#', $html, $m );
    return array_map( 'intval', $m[1] );
}

/* ---------------------------------------------------------------------------
 * 1. THE SIDEBAR LISTS THE MONTH IT WAS GIVEN, AND ONLY THAT MONTH.
 * ------------------------------------------------------------------------ */
foreach ( array( $this_month, $next_month, $after ) as $month ) {
    $html = $sc->render_sidebar( array(), 20, null, $month );
    $ids  = rows_in( $html );
    if ( empty( $ids ) ) {
        fail( "the sidebar for $month rendered no events, and there are some in that month" );
        continue;
    }
    foreach ( $ids as $eid ) {
        $on = $GLOBALS['events'][ $eid ]['date'];
        if ( substr( $on, 0, 7 ) !== $month ) {
            fail( "the sidebar for $month listed an event on $on" );
        }
    }
}

/* Unbound, it is still "what is coming up" and spans months. */
$unbound = rows_in( $sc->render_sidebar( array(), 20, null, '' ) );
$months  = array();
foreach ( $unbound as $eid ) { $months[ substr( $GLOBALS['events'][ $eid ]['date'], 0, 7 ) ] = true; }
if ( count( $months ) < 2 ) {
    fail( 'the sidebar with no month is not spanning months any more, so the display mode has changed as well' );
}

/* ---------------------------------------------------------------------------
 * 2. THE GRID AND THE SIDEBAR AGREE, because the block hands both the same
 *    month. Asserted on the rendered pair rather than on the source.
 * ------------------------------------------------------------------------ */
foreach ( array( $this_month, $next_month ) as $month ) {
    $grid = $sc->render_month_grid( $month, array(), false );
    $side = $sc->render_sidebar( array(), 20, null, $month );

    if ( ! preg_match( '/data-month="([0-9\-]+)"/', $grid, $m ) ) {
        fail( "the grid for $month does not say which month it is" );
        continue;
    }
    if ( $m[1] !== $month ) {
        fail( "the grid was asked for $month and says it is showing {$m[1]}" );
    }
    foreach ( rows_in( $side ) as $eid ) {
        if ( substr( $GLOBALS['events'][ $eid ]['date'], 0, 7 ) !== $m[1] ) {
            fail( "the grid shows {$m[1]} and the sidebar beside it lists an event from another month" );
        }
    }
}

/* ---------------------------------------------------------------------------
 * 3. THE HEAD IS RENDERED ONCE, AND THE GRID CAN BE ASKED TO LEAVE IT OUT.
 * ------------------------------------------------------------------------ */
$with = $sc->render_month_grid( $this_month, array(), true );
$without = $sc->render_month_grid( $this_month, array(), false );
/* uc-month-heading shares this prefix, so the class has to end at a
 * boundary or the heading inside the head counts as a second head. */
if ( 1 !== preg_match_all( '#class="uc-month-head(?:\s|")#', $with ) ) {
    fail( 'the grid with a head does not render exactly one' );
}
if ( preg_match( '#class="uc-month-head(?:\s|")#', $without ) ) {
    fail( 'the grid rendered a head when it was told not to, so the combined mode would show two' );
}
$head = $sc->render_month_head( $this_month );
if ( 1 !== substr_count( $head, 'uc-month-label' ) ) {
    fail( 'the head does not carry exactly one month label' );
}

/*
 * THE HEAD IS THE MONTH NAME AND THE CONTROLS. Nothing else.
 *
 * Both of these were asked for and both are easy to put back without noticing:
 * the count competed with the month name for the same glance, and the range
 * described the grid's full span, which runs into the neighbouring months and
 * answers a question nobody asks.
 */
if ( false !== strpos( $head, 'uc-month-count' ) ) {
    fail( 'the month head still carries the event count, which was removed for competing with the month name' );
}
if ( false !== strpos( $head, 'uc-month-range' ) ) {
    fail( 'the month head carries the date range line again, which was removed in 3.45.1' );
}

/* And the block-level count is out of the month views, which is the line that
 * was actually on screen when 3.45.0 was asked to remove a count. */
$block_src = file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );
if ( ! preg_match( "#if \(\s*! \\\$combined && 'calendar' !== \\\$view \)#", $block_src ) ) {
    fail( 'the "events coming up" line is not gated out of the month and combined views' );
}

/* ---------------------------------------------------------------------------
 * 4. THE CURRENT MONTH IS THE FLOOR.
 * ------------------------------------------------------------------------ */
$floor_head = $sc->render_month_head( $this_month );
$fwd_head   = $sc->render_month_head( $next_month );

if ( false !== strpos( $floor_head, 'uc-month-prev' ) ) {
    fail( 'the current month offers a way back, and it is the floor' );
}
if ( false === strpos( $fwd_head, 'uc-month-prev' ) ) {
    fail( 'a month past the floor offers no way back, so somebody cannot walk in both directions' );
}
if ( false === strpos( $floor_head, 'uc-month-next' ) || false === strpos( $fwd_head, 'uc-month-next' ) ) {
    fail( 'the next control is missing somewhere; it always shows' );
}

/* And the clamp itself, which is what stops the route serving a past month. */
foreach ( array( '2019-03', '2020-12', '1999-01' ) as $past ) {
    if ( $sc->normalize_month( $past ) !== $this_month ) {
        fail( "normalize_month() returned something other than the floor for $past" );
    }
}
if ( $sc->normalize_month( $next_month ) !== $next_month ) {
    fail( 'normalize_month() moved a future month, and it must only clamp the past' );
}
if ( $sc->normalize_month( 'nonsense' ) !== $this_month ) {
    fail( 'normalize_month() does not fall back to the floor for an unreadable value' );
}

/* ---------------------------------------------------------------------------
 * 5. THE MONTH TABS.
 * ------------------------------------------------------------------------ */
$floor_side = $sc->render_sidebar( array(), 20, null, $this_month );
$fwd_side   = $sc->render_sidebar( array(), 20, null, $next_month );

if ( false !== strpos( $floor_side, 'uc-month-tab-prev' ) ) {
    fail( 'the floor month offers a previous tab, and there is nothing before it' );
}
if ( false === strpos( $floor_side, 'uc-month-tab-next' ) ) {
    fail( 'the floor month offers no next tab, and the next tab always shows' );
}
if ( false === strpos( $fwd_side, 'uc-month-tab-prev' ) || false === strpos( $fwd_side, 'uc-month-tab-next' ) ) {
    fail( 'a month past the floor does not offer both tabs' );
}
/* The count is the point of the tab. */
if ( ! preg_match( '#uc-month-tab-count">(\d+)<#', $floor_side, $cm ) ) {
    fail( 'a month tab carries no count' );
} elseif ( 2 !== (int) $cm[1] ) {
    fail( 'the next month tab says ' . $cm[1] . ' events and there are 2 in that month' );
}

/* ---------------------------------------------------------------------------
 * 6. THE VENUE IS ITS OWN LINE.
 * ------------------------------------------------------------------------ */
$side = $sc->render_sidebar( array(), 20, null, $next_month );
if ( false === strpos( $side, 'uc-sidebar-where' ) ) {
    fail( 'no sidebar row carries a venue line, and one of these events has a venue' );
}
if ( ! preg_match( '#</span>\s*<span class="uc-sidebar-where">#', $side ) ) {
    fail( 'the venue span does not follow a closed element, so it is nested inside the date and time line' );
}
/* An event with no venue gets no empty line. */
if ( substr_count( $side, 'uc-sidebar-where' ) !== 1 ) {
    fail( 'the venue line is rendered for an event that has none, or missed for one that has' );
}

/* ---------------------------------------------------------------------------
 * 7. BOTH HALVES RENDER THEIR EVENTS.
 * ------------------------------------------------------------------------ */
$grid = $sc->render_month_grid( $next_month, array(), false );
if ( false === strpos( $grid, 'Next Month One' ) ) {
    fail( "the grid for $next_month does not show an event that is in that month" );
}
if ( false === strpos( $sc->render_sidebar( array(), 20, null, $next_month ), 'Next Month One' ) ) {
    fail( "the sidebar for $next_month does not show an event that is in that month" );
}

/* ---------------------------------------------------------------------------
 * 8. THE FIRST RENDER AND THE REDRAW PRODUCE THE SAME MARKUP (3.45.1).
 *
 * THE FAULT THIS CATCHES. The block composed head, grid and sidebar, and the
 * ajax redraw composed the same three again. Two compositions of one thing, so
 * they drifted: the redraw decided whether the grid drew its own head from a
 * boolean the browser sent, and any caller that did not send it got a grid with
 * a heading inside the left column while the spanning one above stayed put.
 * First load looked right and navigating did not.
 *
 * A CHECK ASKING WHETHER A STRING EXISTS ANYWHERE CANNOT SEE THAT, which this
 * project's own 3.45.0 report said in as many words: the same calls appear in
 * both places, so either copy satisfies it. This compares the two OUTPUTS,
 * byte for byte, for the same month.
 * ------------------------------------------------------------------------ */
foreach ( array( $this_month, $next_month ) as $month ) {
    $parts = $sc->render_combined_parts( $month, array(), 20, '' );

    foreach ( array( 'head', 'grid', 'side' ) as $piece ) {
        if ( ! isset( $parts[ $piece ] ) || '' === $parts[ $piece ] ) {
            fail( "render_combined_parts() gave no $piece for $month" );
        }
    }

    /* The grid must not carry a head of its own: the head spans both halves. */
    if ( isset( $parts['grid'] ) && preg_match( '#class="uc-month-head(?:\s|")#', $parts['grid'] ) ) {
        fail( "the combined grid for $month draws its own head, so the month name appears twice" );
    }
    /* And the head must be a head. */
    if ( isset( $parts['head'] ) && 1 !== preg_match_all( '#class="uc-month-head(?:\s|")#', $parts['head'] ) ) {
        fail( "the combined head for $month is not exactly one head" );
    }

    /*
     * THE SAME CALL TWICE IS THE SAME MARKUP. If a second composition is ever
     * added, this is what fails: two renderings of one month that differ.
     */
    $again = $sc->render_combined_parts( $month, array(), 20, '' );
    foreach ( array( 'head', 'grid', 'side' ) as $piece ) {
        if ( $parts[ $piece ] !== $again[ $piece ] ) {
            fail( "render_combined_parts() is not deterministic for $month: two calls gave different $piece" );
        }
    }
}

/*
 * AND THERE IS EXACTLY ONE COMPOSITION. Both the block and the ajax must reach
 * it; neither may build the three pieces itself. This is the source half, and
 * it is here rather than in embed-modes-test.php because it is about this
 * renderer rather than about the block's panels.
 */
$sc_src  = file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );
$sc_code = '';
foreach ( token_get_all( $sc_src ) as $t ) {
    if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; }
    $sc_code .= is_array( $t ) ? $t[1] : $t;
}
if ( substr_count( $sc_code, 'render_combined_parts(' ) < 3 ) {
    fail( 'fewer than three mentions of render_combined_parts(): the definition, the block and the redraw all need it' );
}
/*
 * The redraw must not build the pieces itself.
 *
 * SCOPED TO THE METHOD BODY. A regex from the method name to the next
 * render_month_head( ran straight past the end of the method and matched the
 * definition further down, so it failed on correct code. The body is taken by
 * matching braces, which is the only way to know where a method stops.
 */
function body_of( $code, $name ) {
    $at = strpos( $code, 'function ' . $name . '(' );
    if ( false === $at ) { return ''; }
    $open = strpos( $code, '{', $at );
    if ( false === $open ) { return ''; }
    $depth = 0;
    for ( $i = $open, $n = strlen( $code ); $i < $n; $i++ ) {
        if ( '{' === $code[ $i ] ) { $depth++; }
        if ( '}' === $code[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) { return substr( $code, $open, $i - $open + 1 ); }
        }
    }
    return '';
}
$ajax_body = body_of( $sc_code, 'ajax_load_month' );
if ( '' === $ajax_body ) {
    fail( 'ajax_load_month() was not found, so the redraw is not being checked at all' );
}
foreach ( array( 'render_month_head(', 'render_sidebar(' ) as $built ) {
    if ( false !== strpos( $ajax_body, $built ) ) {
        fail( "ajax_load_month() calls $built itself rather than asking render_combined_parts(), which is the second composition again" );
    }
}
if ( false === strpos( $ajax_body, 'render_combined_parts(' ) ) {
    fail( 'ajax_load_month() does not ask render_combined_parts(), so the redraw composes the view a second time' );
}
/* And the shape must come from the view rather than a boolean the client sends. */
if ( false !== strpos( $sc_code, "! empty( \$_POST['combined'] )" ) ) {
    fail( 'the redraw still takes its shape from a combined flag the browser sends, so a caller that omits it gets a different shape' );
}
if ( false === strpos( $sc_code, "\$this->is_combined_view( \$view )" ) ) {
    fail( 'the redraw does not ask is_combined_view(), so it can decide a shape the first render would not have' );
}

/* ---------------------------------------------------------------------------
 * SELF TEST.
 * ------------------------------------------------------------------------ */
if ( $self ) {
    echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    $bad = 0;

    /* The query stub must actually filter, or every assertion above is vacuous. */
    $bound   = new WP_Query( array( 'posts_per_page' => -1, 'meta_query' => array(
        array( 'key' => '_uc_event_date', 'value' => $next_month . '-01', 'compare' => '>=' ),
        array( 'key' => '_uc_event_date', 'value' => $next_month . '-28', 'compare' => '<=' ),
    ) ) );
    $unfiltered = new WP_Query( array( 'posts_per_page' => -1 ) );
    if ( $bound->post_count < $unfiltered->post_count && $bound->post_count > 0 ) {
        echo "ok       the query stub reads its date clauses\n";
    } else {
        echo "BROKEN: the query stub returns the same rows whatever it is asked, so nothing above is tested\n";
        $bad++;
    }

    /* And the sidebar must change when the month does. */
    $a = rows_in( $sc->render_sidebar( array(), 20, null, $next_month ) );
    $b = rows_in( $sc->render_sidebar( array(), 20, null, $after ) );
    if ( $a !== $b && $a && $b ) {
        echo "ok       two months give two different lists\n";
    } else {
        echo "BROKEN: the sidebar returns the same list for two months\n";
        $bad++;
    }

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the harness filters, so the assertions above mean something.\n" );
    exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
restore_error_handler();
echo "Combined view outcomes\n";
echo str_repeat( '=', 72 ) . "\n";
printf( "floor month:  %s\n", $this_month );
printf( "rendered:     the grid and the sidebar for %s, %s and %s\n", $this_month, $next_month, $after );
echo "widths:       side by side above 864px (576 + 288 flex bases, no gap),\n";
echo "              stacked below it. Not laid out here: there is no browser.\n";
echo "              See .claude/combined-panel-parity.html to look at it.\n\n";

if ( $GLOBALS['notices'] ) {
    foreach ( array_unique( $GLOBALS['notices'] ) as $n ) {
        echo "  NOTICE  $n\n";
    }
    echo "\n";
}
if ( $fails ) {
    foreach ( $fails as $f ) {
        echo "  FAIL  $f\n";
    }
    echo "\n" . count( $fails ) . " failure(s).\n";
    exit( 1 );
}
echo "both halves render their events, and they agree on the month.\n";
