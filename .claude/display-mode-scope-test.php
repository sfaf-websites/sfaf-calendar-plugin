<?php
/**
 * WHAT EACH DISPLAY MODE ASKS FOR, ENTERED THE WAY A VISITOR ENTERS IT.
 *
 *     php .claude/display-mode-scope-test.php
 *     php .claude/display-mode-scope-test.php --self-test
 *
 * WHY THIS FILE EXISTS, AND WHY IT IS NOT ANOTHER COMBINED-VIEW TEST.
 *
 * Five releases running, the combined view shipped a fault the suite passed.
 * Look at where each one lived:
 *
 *   3.31.1  both panels hidden          composition, in the block
 *   3.31.2  list crushed to 40px rows   composition, in CSS
 *   3.24.x  grid handed a narrow column composition, in CSS
 *   3.45.1  the redraw drew a second head  composition, in the ajax
 *   3.45.2  the list bound to one month    composition, in the block and the
 *                                          REST route
 *
 * NOT ONE OF THEM WAS IN A RENDERER. render_sidebar(), render_month_grid() and
 * render_events() were correct every time, and .claude/combined-outcome-test.php
 * calls exactly those and so said so, honestly and uselessly. The faults were
 * all at the SEAM: what the entry point hands the renderers, or what the
 * context around them is.
 *
 * So this file calls no renderer directly. Every assertion enters through one
 * of the four doors a visitor actually comes through:
 *
 *   1. render_calendar_block()  the shortcode and the embed's block mode
 *   2. ajax_load_block()        filtering and searching on this site
 *   3. SFAF_Embed::build_payload()  every request from another domain
 *   4. calendar.css             the context a rendered panel lands in
 *
 * and reads what came back. The endpoint's two private methods are reached by
 * reflection deliberately: the alternative is a second copy of what they do,
 * living in a test, which is how a check comes to pass on code that is wrong.
 *
 * WHAT IT CANNOT DO. It does not lay anything out, and section 4 is arithmetic
 * over declared values rather than a browser. See the report at the foot for
 * what that leaves for a person.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );
define( 'SFAF_PLUGIN_URL', 'https://example.org/wp-content/plugins/sfaf-calendar/' );
define( 'SFAF_VERSION', '0.0.0-test' );
date_default_timezone_set( 'America/Los_Angeles' );

$fails = array();
$self  = in_array( '--self-test', $argv, true );

function fail( $msg ) { global $fails; $fails[] = $msg; }

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. The one part that matters is a WP_Query that reads
 * the date clauses: without it every query returns the same events and "the
 * list is not bound to a month" cannot be decided at all.
 * ------------------------------------------------------------------------ */
$GLOBALS['events']  = array();
$GLOBALS['notices'] = array();
set_error_handler( function ( $no, $str ) { $GLOBALS['notices'][] = $str; return true; } );

function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return esc_html( $t ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function __( $t, $d = '' ) { return $t; }
function _e( $t, $d = '' ) { echo $t; }
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
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $c ); }
function wp_unslash( $v ) { return $v; }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : date( $type ); }
function date_i18n( $f, $ts = false ) { return date( $f, false === $ts ? time() : $ts ); }
function home_url( $p = '', $s = null ) { return 'https://example.org' . $p; }
function get_post_type_archive_link( $t ) { return 'https://example.org/events/'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u ); }
function add_query_arg( $a, $v = '', $u = '' ) {
    if ( ! is_array( $a ) ) { $a = array( $a => $v ); } else { $u = $v; }
    $out = $u;
    foreach ( $a as $k => $val ) { $out .= ( false === strpos( $out, '?' ) ? '?' : '&' ) . $k . '=' . rawurlencode( $val ); }
    return $out;
}
function get_option( $n, $d = false ) { return isset( $GLOBALS['options'][ $n ] ) ? $GLOBALS['options'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9\-]+/i', '-', trim( (string) $s ) ) ); }
function wp_strip_all_tags( $t, $b = false ) { return strip_tags( (string) $t ); }
function wp_trim_words( $t, $n = 55, $m = null ) { return $t; }
function checked( $a, $b = true, $echo = true ) { return ''; }
function selected( $a, $b = true, $echo = true ) { return ''; }
function get_query_var( $v, $d = '' ) { return $d; }
function is_user_logged_in() { return false; }
function wp_create_nonce( $a = -1 ) { return 'n'; }
function check_ajax_referer( $a, $q = false, $die = true ) { return 1; }
function shortcode_atts( $pairs, $atts, $sc = '' ) { return array_merge( $pairs, (array) $atts ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function get_the_excerpt( $id = 0 ) { return 'A summary.'; }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();

function get_post_meta( $id, $k, $single = false ) {
    if ( '_uc_event_date' === $k ) { return isset( $GLOBALS['events'][ $id ] ) ? $GLOBALS['events'][ $id ]['date'] : ''; }
    if ( '_uc_start_time' === $k ) { return '18:00'; }
    if ( '_uc_end_time' === $k ) { return '19:30'; }
    return '';
}
function get_the_title( $id = 0 ) { return isset( $GLOBALS['events'][ $id ] ) ? $GLOBALS['events'][ $id ]['title'] : ''; }
function get_the_ID() { return $GLOBALS['current_post']; }
function get_permalink( $id = 0 ) { return '/e/' . (int) $id; }
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
function wp_reset_postdata() {}
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
function sfaf_event_location( $id ) { return sfaf_event_location_short( $id ); }
/* The hybrid line beside the address (3.96.0). Empty here: no event in
 * this world is hybrid, and what is under test is which events a mode asks
 * for, not what a row says about attending. */
function sfaf_event_format_line( $id ) { return ''; }
function sfaf_icon( $n, $a = array() ) { return ''; }
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
function sfaf_ap_time( $t ) { return (string) $t; }
function sfaf_category_shades( $hex ) { return array( 'ink' => '#0C666F', 'tint' => '#F2FCFD', 'media' => '#D5F3F6', 'chip' => '#E3F7F9' ); }
function sfaf_event_category_color( $id ) { return '#0E7680'; }
function sfaf_category_color( $id ) { return '#0E7680'; }
function sfaf_day_event_thumb( $id ) { return '<span class="uc-day-event-thumb"></span>'; }
/* The grid tile is a dot, a title and a time since 3.89.0. The thumb stub above
   stays because the helper is kept for one release; nothing renders it. */
function sfaf_day_event_dot( $id ) { return '<span class="uc-de-dot"></span>'; }
function sfaf_event_image_url( $id ) { return ''; }
function sfaf_normalize_faqs( $f ) { return array(); }
function sfaf_rsvp_count( $id ) { return 0; }
function sfaf_get_rsvp_count( $id ) { return 0; }
function sfaf_flatten_html( $h ) { return trim( strip_tags( (string) $h ) ); }
function sfaf_external_marker( $id ) { return ''; }
function sfaf_event_link_is_external( $id ) { return false; }
function sfaf_source_links_default() { return false; }
function sfaf_add_to_calendar( $id ) { return ''; }
function sfaf_event_byline( $id ) { return ''; }
function sfaf_fundraising_progress( $id ) { return ''; }
function sfaf_event_donate_url( $id ) { return ''; }
function sfaf_action_button( $args = array() ) { return ''; }
function sfaf_category_chips_html( $post_id, $context = 'card' ) { return ''; }
function sfaf_default_category_color() { return '#0E7680'; }
function sfaf_event_source_label( $post_id ) { return ''; }
function sfaf_event_thumbnail( $post_id, $size = 'large' ) { return ''; }
function sfaf_list_card_media( $post_id, $cat_name = '' ) { return '<span class="uc-thumb-ph"></span>'; }
function sfaf_rsvp_spots_text( $post_id ) { return ''; }
function sfaf_series_dates_link( $post_id ) { return ''; }
function sfaf_show_feature( $post_id, $feature ) { return false; }
function sfaf_volunteer_spots_text( $post_id ) { return ''; }

/* The embed context and the link destination are real globals in production;
 * they are flags here for the same reason, so build_payload()'s set/restore
 * behaves as it does live. */
$GLOBALS['embed_context'] = false;
$GLOBALS['source_links']  = null;
function sfaf_is_embed_context() { return (bool) $GLOBALS['embed_context']; }
function sfaf_set_embed_context( $v ) { $GLOBALS['embed_context'] = (bool) $v; }
function sfaf_source_links_flag() { return $GLOBALS['source_links']; }
function sfaf_set_source_links( $v ) { $GLOBALS['source_links'] = $v; }
function sfaf_get_source_links() { return (bool) $GLOBALS['source_links']; }

class SFAF_Privacy { public static function exclude( &$args ) {} public static function is_private( $id ) { return false; } }
class SFAF_Cancellation {
    public static function exclude( &$args ) {}
    public static function is_cancelled( $id ) { return false; }
}
class SFAF_Search { public static function apply( &$a, $s ) {} }
class SFAF_Organizers { public static function phrase( $id ) { return ''; } }
class SFAF_Closures {
    public static function covering( $day ) { return null; }
    public static function in_range( $a, $b ) { return array(); }
    public static function for_range( $a, $b ) { return array(); }
    public static function days_in_range( $a, $b ) { return array(); }
    public static function spans( $from, $to ) { return array(); }
    public static function text( $row ) { return ''; }
    public static function when( $row ) { return ''; }
}
class SFAF_Series {
    const TAXONOMY = 'uc_series';
    public static function for_event( $id ) { return null; }
    public static function resolve( $id ) { return (int) $id; }
    public static function all() { return array(); }
    public static function url( $id ) { return '/s/' . (int) $id; }
}

/**
 * A WP_Query that reads the date clauses, and ANDs them as WP_Query does.
 *
 * Everything else about the query is ignored, which is stated rather than
 * hidden: a stub that silently answered more than it understands is how a test
 * comes to prove nothing.
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

        $per   = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
        $paged = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
        $this->ids = ( $per > 0 ) ? array_slice( $all, ( $paged - 1 ) * $per, $per ) : $all;
        foreach ( $this->ids as $id ) { $this->posts[] = (object) array( 'ID' => $id ); }
        $this->post_count    = count( $this->ids );
        $this->found_posts   = count( $all );
        $this->max_num_pages = ( $per > 0 && $per < count( $all ) ) ? (int) ceil( count( $all ) / $per ) : 1;
    }
    public function have_posts() { return $this->i < count( $this->ids ); }
    public function the_post() { $GLOBALS['current_post'] = $this->ids[ $this->i ]; $this->i++; }
}

/** Just enough of a REST request for normalize_params() to read. */
class WP_REST_Request {
    private $params;
    public function __construct( $params = array() ) { $this->params = $params; }
    public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
}

require_once $root . '/includes/class-sfaf-shortcodes.php';
require_once $root . '/includes/class-sfaf-embed.php';

/* ---------------------------------------------------------------------------
 * The world. Two events left in this month, two in the next, one after that.
 *
 * FIVE UPCOMING AND TWO THIS MONTH is the whole shape of the reported fault, at
 * a size a person can check by hand: Mark had 27 upcoming and was shown the 5
 * that happened to fall in August.
 * ------------------------------------------------------------------------ */
$this_month = date( 'Y-m' );
$next_month = date( 'Y-m', strtotime( 'first day of next month' ) );
$after      = date( 'Y-m', strtotime( 'first day of +2 months' ) );

/* Dated at the end of the month so "today or later" cannot drop them whatever
 * day this runs on, and one day apart so they are distinguishable. */
$eom = (int) date( 't' );
$id  = 100;
foreach ( array(
    array( date( 'Y-m-' ) . str_pad( (string) max( 1, $eom - 1 ), 2, '0', STR_PAD_LEFT ), 'This Month One', 'Strut' ),
    array( date( 'Y-m-t' ),      'This Month Two', '' ),
    array( $next_month . '-05',  'Next Month One', '1035 Market St' ),
    array( $next_month . '-19',  'Next Month Two', '' ),
    array( $after . '-07',       'Later One',      'Strut' ),
) as $e ) {
    $GLOBALS['events'][ $id++ ] = array( 'date' => $e[0], 'title' => $e[1], 'venue' => $e[2] );
}

$upcoming_total  = count( $GLOBALS['events'] );
$this_month_only = 0;
foreach ( $GLOBALS['events'] as $e ) {
    if ( substr( $e['date'], 0, 7 ) === $this_month ) { $this_month_only++; }
}

$sc    = new SFAF_Shortcodes();
$embed = new SFAF_Embed( $sc );

/* --- Reading what came back. -------------------------------------------- */

/** Event ids in the list panel's cards, in order. */
function cards_in( $html ) {
    if ( ! preg_match( '#<div class="uc-view-panel uc-panel-list".*?>(.*?)(?=<div class="uc-view-panel uc-panel-calendar)#s', $html, $m ) ) {
        return array();
    }
    /* The href is matched loosely on purpose: the endpoint absolutizes every
     * URL before it goes out, so the same card is /e/100 on this site and
     * https://example.org/e/100 in a payload, and a reader that only knew the
     * first would have reported an embed as empty rather than as wrong. */
    preg_match_all( '#class="uc-event-card[^>]*>.*?href="[^"]*/e/(\d+)"#s', $m[1], $ids );
    return array_map( 'intval', $ids[1] );
}

/** Event ids in the sidebar's rows. */
function rows_in( $html ) {
    preg_match_all( '#<a class="uc-sidebar-row"[^>]*href="[^"]*/e/(\d+)"#', $html, $m );
    return array_map( 'intval', $m[1] );
}

/** The distinct months a set of event ids falls in. */
function months_of( $ids ) {
    $out = array();
    foreach ( $ids as $eid ) {
        if ( isset( $GLOBALS['events'][ $eid ] ) ) { $out[ substr( $GLOBALS['events'][ $eid ]['date'], 0, 7 ) ] = true; }
    }
    return array_keys( $out );
}

/** How many uc-month-head elements the markup carries. */
function heads_in( $html ) {
    return preg_match_all( '#class="uc-month-head(?:\s|")#', $html );
}

/* =========================================================================
 * 1. THROUGH THE BLOCK, WHICH IS THE DOOR THE FAULT CAME IN BY.
 *
 * Every mode is asked for twice: once with no month attribute, which is the
 * shortcode on this site, and once with the current month filled in, which is
 * what SFAF_Embed::normalize_params() sends on EVERY request because
 * normalize_month() never returns ''. The two must give the same list, because
 * "which month is the grid drawing" is not "what is the list about".
 * ====================================================================== */
foreach ( array( '' => 'no month attribute', $this_month => 'the current month filled in' ) as $month_attr => $label ) {

    /* --- LIST: everything coming up, across every month. ----------------- */
    $block = $sc->render_calendar_block( array( 'view' => 'list', 'month' => $month_attr, 'per_page' => 50 ) );
    $ids   = cards_in( $block['html'] );

    if ( count( $ids ) !== $upcoming_total ) {
        fail( sprintf(
            'the list view with %s rendered %d cards and there are %d upcoming events',
            $label, count( $ids ), $upcoming_total
        ) );
    }
    if ( (int) $block['total'] !== $upcoming_total ) {
        fail( sprintf(
            'the list view with %s counted %d events and there are %d upcoming',
            $label, (int) $block['total'], $upcoming_total
        ) );
    }
    if ( count( months_of( $ids ) ) < 3 ) {
        fail( sprintf(
            'the list view with %s showed %d month(s) of events; it is "everything coming up" and there are 3',
            $label, count( months_of( $ids ) )
        ) );
    }

    /* --- SIDEBAR as a display mode: unchanged, and still spanning. ------- */
    $side = $sc->render_calendar_block( array( 'view' => 'sidebar', 'month' => $month_attr, 'count' => 20 ) );
    if ( count( months_of( rows_in( $side['html'] ) ) ) < 2 ) {
        fail( sprintf(
            'the sidebar display mode with %s stopped spanning months; on its own it is "what is coming up"',
            $label
        ) );
    }

    /* --- CALENDAR: the grid draws the month it was given. ---------------- */
    $grid_month = ( '' === $month_attr ) ? $this_month : $month_attr;
    $cal = $sc->render_calendar_block( array( 'view' => 'calendar', 'month' => $month_attr, 'toggle' => 'no' ) );
    if ( ! preg_match( '/class="uc-month" data-month="([0-9\-]+)"/', $cal['html'], $m ) ) {
        fail( "the calendar view with $label rendered no grid" );
    } elseif ( $m[1] !== $grid_month ) {
        fail( "the calendar view with $label drew {$m[1]} and was asked for $grid_month" );
    }
    if ( 1 !== heads_in( $cal['html'] ) ) {
        fail( sprintf( 'the calendar view with %s carries %d month heads and it needs exactly one', $label, heads_in( $cal['html'] ) ) );
    }
}

/* --- COMBINED: the grid and the sidebar agree, and the list is not there. */
foreach ( array( $this_month, $next_month ) as $month ) {
    $block = $sc->render_calendar_block( array( 'view' => 'combined', 'month' => $month, 'count' => 20 ) );

    if ( 1 !== heads_in( $block['html'] ) ) {
        fail( sprintf(
            'the combined view for %s carries %d month heads: one spans both halves and the grid must not draw its own',
            $month, heads_in( $block['html'] ) )
        );
    }
    if ( ! preg_match( '/class="uc-month" data-month="([0-9\-]+)"/', $block['html'], $m ) || $m[1] !== $month ) {
        fail( "the combined view for $month does not draw that month's grid" );
    }
    $side_ids = rows_in( $block['html'] );
    if ( empty( $side_ids ) ) {
        fail( "the combined view for $month rendered an empty sidebar, and there are events in that month" );
    }
    foreach ( $side_ids as $eid ) {
        if ( substr( $GLOBALS['events'][ $eid ]['date'], 0, 7 ) !== $month ) {
            fail( "the combined view for $month lists an event from " . substr( $GLOBALS['events'][ $eid ]['date'], 0, 7 ) . ' beside that month\'s grid' );
        }
    }
    /* The right-hand column is the sidebar, so no card list is built at all. */
    if ( ! empty( cards_in( $block['html'] ) ) ) {
        fail( "the combined view for $month built the card list as well as the sidebar" );
    }
}

/* =========================================================================
 * 2. THROUGH THE AJAX BLOCK LOADER, which is what filtering and searching on
 *    this site actually call, and which sends the block's displayed month
 *    back with every request.
 * ====================================================================== */
class UC_Ajax_Sent extends Exception {
    public $payload;
    public function __construct( $payload ) { $this->payload = $payload; parent::__construct( 'sent' ); }
}
function wp_send_json( $data, $status = null, $flags = 0 ) { throw new UC_Ajax_Sent( $data ); }
function wp_send_json_success( $data = null, $status = null, $flags = 0 ) { throw new UC_Ajax_Sent( array( 'success' => true, 'data' => $data ) ); }

$_POST = array(
    'nonce'    => 'n',
    'view'     => 'list',
    'month'    => $this_month,
    'per_page' => 50,
);
try {
    $sc->ajax_load_block();
    fail( 'ajax_load_block() returned without sending a response' );
} catch ( UC_Ajax_Sent $sent ) {
    $ids = cards_in( isset( $sent->payload['html'] ) ? $sent->payload['html'] : '' );
    if ( count( $ids ) !== $upcoming_total ) {
        fail( sprintf(
            'filtering the list on this site left %d of %d upcoming events: the redraw sends the displayed month and the list took it as a bound',
            count( $ids ), $upcoming_total
        ) );
    }
    if ( (int) $sent->payload['total'] !== $upcoming_total ) {
        fail( 'the redrawn list reports a total for one month rather than for everything coming up' );
    }
}
$_POST = array();

/* =========================================================================
 * 3. THROUGH THE ENDPOINT, which is where every embedded calendar comes from
 *    and the one door no other harness opens.
 * ====================================================================== */
$ref_params  = new ReflectionMethod( 'SFAF_Embed', 'normalize_params' );
$ref_payload = new ReflectionMethod( 'SFAF_Embed', 'build_payload' );
$ref_ident   = new ReflectionMethod( 'SFAF_Embed', 'cache_identity' );
$ref_params->setAccessible( true );
$ref_payload->setAccessible( true );
$ref_ident->setAccessible( true );

/**
 * One request through the real parameter normalizer and the real payload
 * builder. Nothing here re-implements either.
 */
function endpoint( $query ) {
    global $embed, $ref_params, $ref_payload;
    $params = $ref_params->invoke( $embed, new WP_REST_Request( $query ) );
    return array( 'params' => $params, 'payload' => $ref_payload->invoke( $embed, $params ) );
}

/* --- A plain embedded list, which is what sfaf.org has on it. ------------ */
$r   = endpoint( array( 'view' => 'list', 'per_page' => 50 ) );
$ids = cards_in( $r['payload']['html'] );
if ( count( $ids ) !== $upcoming_total ) {
    fail( sprintf(
        'an embedded list served %d of %d upcoming events. The endpoint fills the month in on every request, so a list that reads it is bound to the current month',
        count( $ids ), $upcoming_total
    ) );
}
if ( count( months_of( $ids ) ) < 3 ) {
    fail( 'an embedded list covers fewer than the 3 months it has events in' );
}
if ( (int) $r['payload']['total'] !== $upcoming_total ) {
    fail( 'an embedded list reports a total for one month rather than for everything coming up' );
}

/* --- An embedded sidebar keeps spanning months. ------------------------- */
$r = endpoint( array( 'view' => 'sidebar', 'count' => 20 ) );
if ( count( months_of( rows_in( $r['payload']['html'] ) ) ) < 2 ) {
    fail( 'an embedded sidebar stopped spanning months' );
}

/* --- Month navigation in the combined mode: three pieces, one head. ------ */
$r = endpoint( array( 'view' => 'combined', 'mode' => 'month', 'month' => $next_month, 'count' => 20 ) );
$p = $r['payload'];

if ( 0 !== heads_in( $p['html'] ) ) {
    fail( 'navigating an embedded combined view returns a grid carrying its own month head, so the spanning one and this one are two navigations on screen at once' );
}
if ( ! isset( $p['head'] ) || '' === $p['head'] ) {
    fail( 'navigating an embedded combined view returns no spanning head, so the month name never moves' );
} elseif ( 1 !== heads_in( $p['head'] ) ) {
    fail( 'the spanning head returned on navigation is not exactly one head' );
}
if ( ! isset( $p['side'] ) || '' === $p['side'] ) {
    fail( 'navigating an embedded combined view returns no sidebar, so the two halves end up on different months' );
} else {
    $side_ids = rows_in( $p['side'] );
    if ( empty( $side_ids ) ) {
        fail( "the redrawn sidebar for $next_month is empty, and there are events in that month" );
    }
    foreach ( $side_ids as $eid ) {
        if ( substr( $GLOBALS['events'][ $eid ]['date'], 0, 7 ) !== $next_month ) {
            fail( 'the redrawn sidebar lists an event from another month than the grid beside it' );
        }
    }
}
/* Every piece is a piece of markup going to another domain, so every piece
 * has to have had its URLs resolved. One of them being missed is invisible
 * until an image is blank on somebody else's page. */
foreach ( array( 'html', 'head', 'side' ) as $piece ) {
    if ( ! isset( $p[ $piece ] ) || '' === $p[ $piece ] ) { continue; }
    if ( preg_match( '# (?:src|href)="/[^/]#', $p[ $piece ] ) ) {
        fail( "the $piece piece of a combined month payload still carries root-relative URLs, which resolve against the host page" );
    }
}

/* --- And an ordinary month navigation is untouched. --------------------- */
$r = endpoint( array( 'view' => 'calendar', 'mode' => 'month', 'month' => $next_month ) );
if ( 1 !== heads_in( $r['payload']['html'] ) ) {
    fail( 'navigating an embedded calendar view returns a grid without its month head, so the month name disappears' );
}
if ( isset( $r['payload']['side'] ) ) {
    fail( 'an ordinary month navigation returns a sidebar, which that mode has nowhere to put' );
}

/*
 * THE CACHE IS PER SHAPE. Two month payloads with different pieces in them
 * must not share an entry, or whichever was built first is served to both.
 */
$ident_cal = $ref_ident->invoke( $embed, endpoint( array( 'view' => 'calendar', 'mode' => 'month', 'month' => $next_month ) )['params'] );
$ident_com = $ref_ident->invoke( $embed, endpoint( array( 'view' => 'combined', 'mode' => 'month', 'month' => $next_month ) )['params'] );
if ( $ident_cal === $ident_com ) {
    fail( 'a combined month payload and a grid month payload share a cache identity, so the first one built is served to both' );
}

/* =========================================================================
 * 4. THE CONTEXT A PANEL LANDS IN, computed rather than eyeballed.
 *
 * The sidebar is a bordered card on its own and NOT a card in the combined
 * mode, where the panel around it carries the padding. Anything inside it that
 * pulls back out through that padding has to pull out by whatever the padding
 * is in the context it is actually in. Written as a literal it is right in one
 * context and wrong in the other, which is how the heading band came to be
 * 28px wider than the rows underneath it.
 *
 * This computes both edges in both contexts from the declared values. It is
 * arithmetic, not layout: it says the numbers agree, not that it looks right.
 * ====================================================================== */
/*
 * COMMENTS COME OUT FIRST, and this file is mostly comments. Half the
 * selectors below appear in prose above their own rule, so a reader working on
 * the raw text finds the sentence about the rule rather than the rule. Every
 * lookup here is on the stripped copy.
 */
$css = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $root . '/public/css/calendar.css' ) );

/**
 * The declared value of one property in the first rule with this selector.
 *
 * THE SELECTOR HAS TO START A LINE. Matching it anywhere makes it a substring
 * search, and `.uc-sidebar {` is a substring of
 * `.uc-calendar .uc-view-panels-combined .uc-sidebar {`, which appears 500
 * lines earlier. So the standalone card read the combined mode's zeroes, both
 * contexts came back identical, and the comparison below passed by describing
 * the same thing twice. The self-test now requires the two to differ.
 */
function decl( $css, $selector, $prop ) {
    $css = "\n" . $css;
    $at  = strpos( $css, "\n" . $selector );
    while ( false !== $at ) {
        $at++;
        $open = strpos( $css, '{', $at );
        if ( false === $open ) { return null; }
        $close = strpos( $css, '}', $open );
        $body  = substr( $css, $open + 1, $close - $open - 1 );
        /* The boundary keeps `margin` from matching inside `margin-top` and
         * keeps `padding` from matching the tail of `--uc-sidebar-pad-x`. */
        if ( preg_match( '/(?:^|;|\{)\s*' . preg_quote( $prop, '/' ) . '\s*:\s*([^;}]+)/', $body, $m ) ) {
            return trim( $m[1] );
        }
        $at = strpos( $css, "\n" . $selector, $close );
    }
    return null;
}

/**
 * Resolve a length that may be a custom property, a calc of one, or a number.
 *
 * Only the two shapes this stylesheet uses: `12px` and
 * `calc(-1 * var(--name))` / `var(--name)`. Anything else returns null and is
 * reported rather than guessed at, because a value this cannot read is a value
 * this cannot check.
 */
function px( $value, $vars ) {
    $value = trim( (string) $value );
    if ( preg_match( '/^-?\d+(?:\.\d+)?px$/', $value ) ) { return (float) $value; }
    if ( preg_match( '/^var\(\s*(--[a-z0-9\-]+)\s*(?:,\s*[^)]+)?\)$/i', $value, $m ) ) {
        return isset( $vars[ $m[1] ] ) ? px( $vars[ $m[1] ], $vars ) : null;
    }
    if ( preg_match( '/^calc\(\s*-1\s*\*\s*(var\([^)]*\))\s*\)$/i', $value, $m ) ) {
        $inner = px( $m[1], $vars );
        return ( null === $inner ) ? null : -$inner;
    }
    return null;
}

/**
 * The four sides of a margin/padding shorthand, as raw strings.
 *
 * SPLIT ON PAREN DEPTH, NOT ON A REGEX. `calc(-1 * var(--x))` contains three
 * spaces of its own, and a lookahead cheap enough to write inline cuts it into
 * "calc(-1", "*" and "var(--x))". That is what this returned first, and the
 * self-test's calc case is what said so.
 */
function sides( $shorthand ) {
    $parts = array();
    $depth = 0;
    $cur   = '';
    foreach ( str_split( trim( (string) $shorthand ) ) as $ch ) {
        if ( '(' === $ch ) { $depth++; }
        if ( ')' === $ch ) { $depth--; }
        if ( 0 === $depth && preg_match( '/\s/', $ch ) ) {
            if ( '' !== $cur ) { $parts[] = $cur; $cur = ''; }
            continue;
        }
        $cur .= $ch;
    }
    if ( '' !== $cur ) { $parts[] = $cur; }
    if ( empty( $parts ) ) { return array( '', '', '', '' ); }

    switch ( count( $parts ) ) {
        case 1: return array( $parts[0], $parts[0], $parts[0], $parts[0] );
        case 2: return array( $parts[0], $parts[1], $parts[0], $parts[1] );
        case 3: return array( $parts[0], $parts[1], $parts[2], $parts[1] );
        default: return array_slice( $parts, 0, 4 );
    }
}

/**
 * The first length of the first layer of a box-shadow.
 *
 * DEPTH-AWARE, LIKE sides(), AND FOR THE SAME REASON. This was a regex that
 * cut at the first space, and `calc(-1 * var(--x, 14px))` holds three of them:
 * it came back as the string "calc(-1", px() could not read that, and the
 * reader below then treated an unreadable value as a row edge of ZERO. The
 * standalone card was reported 14px out of line when it is exactly in line,
 * and the combined mode passed only because zero was the right answer there by
 * luck. A value this cannot read is now a failure, not a default.
 */
function shadow_spread( $shadow ) {
    $layers = array();
    $depth  = 0;
    $cur    = '';
    foreach ( str_split( trim( (string) $shadow ) ) as $ch ) {
        if ( '(' === $ch ) { $depth++; }
        if ( ')' === $ch ) { $depth--; }
        if ( 0 === $depth && ',' === $ch ) { $layers[] = $cur; $cur = ''; continue; }
        $cur .= $ch;
    }
    $layers[] = $cur;
    $first = sides( $layers[0] );
    return $first[0];
}

$head_margin  = decl( $css, '.uc-sidebar .uc-sidebar-heading', 'margin' );
$row_padding  = decl( $css, '.uc-sidebar-row {', 'padding' );
$hover_shadow = decl( $css, '.uc-sidebar-row:hover', 'box-shadow' );

/* The two contexts, and the custom properties in force in each. */
$contexts = array(
    'the sidebar display mode' => array(
        '--uc-sidebar-pad-x' => decl( $css, '.uc-sidebar {', '--uc-sidebar-pad-x' ),
        '--uc-sidebar-pad-t' => decl( $css, '.uc-sidebar {', '--uc-sidebar-pad-t' ),
    ),
    'the combined mode' => array(
        '--uc-sidebar-pad-x' => decl( $css, '.uc-calendar .uc-view-panels-combined .uc-sidebar', '--uc-sidebar-pad-x' ),
        '--uc-sidebar-pad-t' => decl( $css, '.uc-calendar .uc-view-panels-combined .uc-sidebar', '--uc-sidebar-pad-t' ),
    ),
);

$geometry = array();
foreach ( $contexts as $where => $vars ) {
    if ( null === $vars['--uc-sidebar-pad-x'] ) {
        fail( "$where does not declare --uc-sidebar-pad-x, so the heading band's escape is not tied to the padding it is escaping" );
        continue;
    }
    $pad_x = px( $vars['--uc-sidebar-pad-x'], $vars );
    if ( null === $pad_x ) {
        fail( "$where declares a --uc-sidebar-pad-x this checker cannot read: " . $vars['--uc-sidebar-pad-x'] );
        continue;
    }

    $hm = sides( $head_margin );
    $band_left = px( $hm[3], $vars );
    if ( null === $band_left ) {
        fail( 'the heading band\'s left margin cannot be resolved: ' . $hm[3] );
        continue;
    }

    /*
     * Both measured from the sidebar's own content box, positive meaning
     * inwards. The band's margin is negative when it escapes; a row's visible
     * edge is its background, which starts at the content box and is extended
     * by the hover shadow.
     */
    $band_edge = $band_left;
    if ( null === $hover_shadow ) {
        fail( "$where: the row hover declares no box-shadow, so the row's visible edge cannot be compared with the band's" );
        continue;
    }
    $row_edge = px( shadow_spread( $hover_shadow ), $vars );
    if ( null === $row_edge ) {
        fail( "$where: the row hover's escape cannot be read: " . shadow_spread( $hover_shadow ) );
        continue;
    }

    $geometry[ $where ] = array( 'pad' => $pad_x, 'band' => $band_edge, 'row' => $row_edge );

    if ( abs( $band_edge - $row_edge ) > 0.01 ) {
        fail( sprintf(
            'in %s the heading band reaches %.0fpx from the column edge and the rows reach %.0fpx, so the band is %.0fpx wider than the list on each side',
            $where, $band_edge, $row_edge, abs( $band_edge - $row_edge )
        ) );
    }
}

/* And the combined context must zero the padding it says it zeroes, or the
 * numbers above are describing a card that is still a card. */
if ( null === decl( $css, '.uc-calendar .uc-view-panels-combined .uc-sidebar', 'padding' ) ) {
    fail( 'the combined mode no longer zeroes the sidebar card\'s padding, so the panel and the card are both padding it' );
}

/* ---------------------------------------------------------------------------
 * SELF TEST. A checker that cannot fail is a checker that proves nothing, and
 * every case here is a shape this file has NOT already seen pass.
 * ------------------------------------------------------------------------ */
if ( $self ) {
    echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    $bad = 0;

    /* The query stub must actually read its clauses. */
    $bound = new WP_Query( array( 'posts_per_page' => -1, 'meta_query' => array(
        array( 'key' => '_uc_event_date', 'value' => $next_month . '-01', 'compare' => '>=' ),
        array( 'key' => '_uc_event_date', 'value' => $next_month . '-28', 'compare' => '<=' ),
    ) ) );
    $free = new WP_Query( array( 'posts_per_page' => -1 ) );
    if ( $bound->post_count > 0 && $bound->post_count < $free->post_count ) {
        echo "ok       the query stub reads its date clauses\n";
    } else {
        echo "BROKEN:  the query stub answers the same whatever it is asked\n";
        $bad++;
    }

    /* cards_in() must actually find cards, or every count above is zero-vs-zero
     * and passes on any broken build. */
    $probe = $sc->render_calendar_block( array( 'view' => 'list', 'per_page' => 50 ) );
    if ( count( cards_in( $probe['html'] ) ) === $upcoming_total ) {
        echo "ok       cards_in() reads the list panel\n";
    } else {
        echo "BROKEN:  cards_in() found " . count( cards_in( $probe['html'] ) ) . " of $upcoming_total cards; the reader is wrong, not the code\n";
        $bad++;
    }

    /* And it must read the LIST panel only: in the combined mode there is no
     * card list at all, and a reader that picked up sidebar rows instead would
     * have made section 1's combined assertion vacuous. */
    $probe = $sc->render_calendar_block( array( 'view' => 'combined', 'month' => $this_month, 'count' => 20 ) );
    if ( empty( cards_in( $probe['html'] ) ) && ! empty( rows_in( $probe['html'] ) ) ) {
        echo "ok       cards_in() and rows_in() read different panels\n";
    } else {
        echo "BROKEN:  the two readers do not distinguish a card from a sidebar row\n";
        $bad++;
    }

    /* The endpoint must be reachable, or section 3 silently tested nothing. */
    $probe = endpoint( array( 'view' => 'list', 'per_page' => 50 ) );
    if ( isset( $probe['payload']['html'] ) && '' !== $probe['payload']['html'] ) {
        echo "ok       the endpoint's private methods are reachable and render\n";
    } else {
        echo "BROKEN:  build_payload() returned nothing, so section 3 asserts nothing\n";
        $bad++;
    }

    /* px() must read every shape this stylesheet actually uses, INCLUDING the
     * negative calc, which is the one the band's margin is written as and the
     * one a naive reader returns null for. */
    $vars = array( '--a' => '14px' );
    $cases = array(
        array( '14px', 14.0 ),
        array( '0px', 0.0 ),
        array( 'var(--a)', 14.0 ),
        array( 'calc(-1 * var(--a))', -14.0 ),
        array( 'var(--a, 9px)', 14.0 ),
        array( 'var(--missing, 9px)', null ),
        array( '2em', null ),
    );
    $px_ok = true;
    foreach ( $cases as $c ) {
        if ( px( $c[0], $vars ) !== $c[1] ) {
            echo 'BROKEN:  px("' . $c[0] . '") returned ' . var_export( px( $c[0], $vars ), true )
                . ', expected ' . var_export( $c[1], true ) . "\n";
            $px_ok = false;
        }
    }
    if ( $px_ok ) { echo "ok       px() reads plain, var and negative-calc lengths\n"; } else { $bad++; }

    /* sides() must expand every shorthand length, not only the four-part one. */
    $sides_ok = ( sides( '1px 2px 3px' ) === array( '1px', '2px', '3px', '2px' ) )
        && ( sides( '1px' ) === array( '1px', '1px', '1px', '1px' ) )
        && ( sides( 'calc(-1 * var(--a)) calc(-1 * var(--b)) 14px' ) === array( 'calc(-1 * var(--a))', 'calc(-1 * var(--b))', '14px', 'calc(-1 * var(--b))' ) );
    if ( $sides_ok ) {
        echo "ok       sides() expands a shorthand, including one holding calc()\n";
    } else {
        echo "BROKEN:  sides() mis-splits a shorthand, so section 4 reads the wrong edge\n";
        $bad++;
    }

    /* shadow_spread() must survive a calc() carrying both spaces and a comma,
     * which is the shape the hover is written as. A reader that cut at the
     * first space returned "calc(-1" and the caller defaulted to zero, which
     * reported the standalone card 14px out of line when it is exactly in
     * line. Both shapes, because the plain one is what it degrades to. */
    $layered   = 'calc(-1 * var(--a, 14px)) 0 0 var(--uc-bg), var(--a, 14px) 0 0 var(--uc-bg)';
    $shadow_ok = ( shadow_spread( $layered ) === 'calc(-1 * var(--a, 14px))' )
        && ( shadow_spread( '-14px 0 0 #fff, 14px 0 0 #fff' ) === '-14px' )
        && ( px( shadow_spread( $layered ), $vars ) === -14.0 );
    if ( $shadow_ok ) {
        echo "ok       shadow_spread() reads the first length of a multi-layer shadow\n";
    } else {
        echo 'BROKEN:  shadow_spread() returned "' . shadow_spread( $layered )
            . "\"; section 4's row edge is not the row's edge\n";
        $bad++;
    }

    /* The geometry must have been computed in BOTH contexts. One missing is
     * how this passes while the context that is wrong went unread. */
    if ( 2 === count( $geometry ) ) {
        echo "ok       both contexts were computed\n";
    } else {
        echo 'BROKEN:  ' . count( $geometry ) . " of 2 contexts computed; the other was skipped, not checked\n";
        $bad++;
    }

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the harness reads what it claims to read.\n" );
    exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
restore_error_handler();
echo "Display modes, entered through the block and the endpoint\n";
echo str_repeat( '=', 72 ) . "\n";
printf( "world:        %d upcoming events across 3 months, %d of them in %s\n", $upcoming_total, $this_month_only, $this_month );
echo "doors:        render_calendar_block(), ajax_load_block(),\n";
echo "              SFAF_Embed::build_payload(), calendar.css\n";
foreach ( $geometry as $where => $g ) {
    /* Both numbers, not one of them called "both". The line read "both edges
       at -14px" while printing the band's alone, so a disagreement between the
       two would have been reported as agreement in the very line meant to
       show it. */
    printf( "band vs rows: %-26s padding %.0fpx, band %.0fpx, rows %.0fpx\n",
        $where, $g['pad'], $g['band'], $g['row'] );
}
echo "\n";

if ( ! empty( $GLOBALS['notices'] ) ) {
    $unique = array_slice( array_unique( $GLOBALS['notices'] ), 0, 5 );
    echo "PHP notices while rendering (" . count( array_unique( $GLOBALS['notices'] ) ) . " distinct):\n";
    foreach ( $unique as $n ) { echo '  ' . $n . "\n"; }
    echo "\n";
}

if ( empty( $fails ) ) {
    echo "every display mode asked for the events it is about, through every door.\n";
    exit( 0 );
}

echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
