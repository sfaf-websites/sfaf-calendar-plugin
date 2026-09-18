<?php
/**
 * WHICH FILTERS A BLOCK OFFERS, AND WHICH ROWS COME BACK WHEN ONE IS USED.
 *
 *     php .claude/filter-toggles-test.php
 *     php .claude/filter-toggles-test.php --self-test
 *
 * WHY THIS FILE EXISTS. Six faults in the combined view and three in the
 * pending queue have now been things a source-string check could not see, so
 * nothing here searches source for a string. Every assertion renders a real
 * block through render_calendar_block() and reads what came back.
 *
 * THE WP_Query STUB HONOURS tax_query, AND THAT IS THE POINT OF IT. The other
 * harnesses in this directory read the date clauses and ignore the taxonomy
 * ones, which is correct for what they ask. It is useless here: "filtering runs
 * on the server" cannot be decided at all by a query that returns the same rows
 * whatever taxonomy it was handed. The --self-test proves it discriminates.
 *
 * WHAT IS ASSERTED:
 *
 *   1. The second-level series row from 3.11.0, entered as a visitor enters it.
 *   2. Each toggle combination shows exactly the controls it asked for, in
 *      every display mode.
 *   3. Choosing a filter narrows the ROWS, through the query, not by hiding.
 *   4. A scoped block cannot be widened by a hand-written parameter.
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
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
$GLOBALS['events']     = array();
$GLOBALS['terms']      = array();   // taxonomy => slug => term object
$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();

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

/**
 * get_terms(), honouring taxonomy AND object_ids.
 *
 * available_groups() derives the second level by asking which series terms sit
 * on the events the block currently contains, through `object_ids`. A stub
 * ignoring that would return every series on the site and the row would look
 * right while being wrong, which is the exact class of fault this file exists
 * to catch.
 */
/*
 * ADDED IN 3.85.0. group_organizer_map() asks which organizers each group's
 * events name, so the filter bar now reaches get_posts() and the taxonomy. No
 * events here, so every group comes back with an empty organizer list, which is
 * the "always shown" case and is exactly what this file's toggles care about.
 * who-picker-test.php is where the narrowing itself is asserted.
 */
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
function get_posts( $args = array() ) { return array(); }
function get_terms( $args = array() ) {
    $tax = isset( $args['taxonomy'] ) ? $args['taxonomy'] : '';
    $all = isset( $GLOBALS['terms'][ $tax ] ) ? array_values( $GLOBALS['terms'][ $tax ] ) : array();

    if ( isset( $args['object_ids'] ) ) {
        $ids  = array_map( 'intval', (array) $args['object_ids'] );
        $keep = array();
        foreach ( $all as $term ) {
            foreach ( $ids as $id ) {
                if ( isset( $GLOBALS['events'][ $id ] ) && in_array( $term->slug, (array) $GLOBALS['events'][ $id ][ $tax ], true ) ) {
                    $keep[] = $term;
                    break;
                }
            }
        }
        $all = $keep;
    }

    if ( ! empty( $args['hide_empty'] ) ) {
        $used = array();
        foreach ( $GLOBALS['events'] as $e ) {
            foreach ( (array) $e[ $tax ] as $slug ) { $used[ $slug ] = true; }
        }
        $all = array_values( array_filter( $all, function ( $t ) use ( $used ) { return isset( $used[ $t->slug ] ); } ) );
    }

    usort( $all, function ( $a, $b ) { return strcasecmp( $a->name, $b->name ); } );
    return $all;
}
function wp_get_post_terms( $id, $tax, $args = array() ) {
    $out = array();
    foreach ( (array) ( isset( $GLOBALS['events'][ $id ][ $tax ] ) ? $GLOBALS['events'][ $id ][ $tax ] : array() ) as $slug ) {
        if ( isset( $GLOBALS['terms'][ $tax ][ $slug ] ) ) { $out[] = $GLOBALS['terms'][ $tax ][ $slug ]; }
    }
    return $out;
}
function wp_get_object_terms( $id, $tax, $args = array() ) { return wp_get_post_terms( $id, is_array( $tax ) ? $tax[0] : $tax, $args ); }
function taxonomy_exists( $t ) { return true; }
function get_term_by( $field, $value, $tax ) {
    if ( 'slug' === $field && isset( $GLOBALS['terms'][ $tax ][ $value ] ) ) { return $GLOBALS['terms'][ $tax ][ $value ]; }
    foreach ( (array) ( isset( $GLOBALS['terms'][ $tax ] ) ? $GLOBALS['terms'][ $tax ] : array() ) as $t ) {
        if ( 'id' === $field && (int) $t->term_id === (int) $value ) { return $t; }
    }
    return false;
}
function get_term( $id, $tax = '' ) { return get_term_by( 'id', $id, $tax ); }
function get_term_meta( $id, $k, $single = false ) { return ''; }
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
function sfaf_event_location_short( $id ) { return ''; }
function sfaf_event_location( $id ) { return ''; }
/* The hybrid line beside the address (3.96.0). Empty here for the reason
 * the location above is empty: this harness is about which rows appear. */
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
/* The description resolver (3.98.0). The event's own words, then its series',
 * then nothing. These harnesses have no series, so the event's own content is
 * the whole of it here. */
function sfaf_event_description_html( $id ) { $p = get_post( $id ); return ( $p && isset( $p->post_content ) ) ? (string) $p->post_content : ''; }
function sfaf_event_description_text( $id ) { return sfaf_flatten_html( sfaf_event_description_html( $id ) ); }
function sfaf_event_description_is_series( $id ) { return false; }

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
 * A WP_Query that reads the date clauses AND the taxonomy ones.
 *
 * tax_query is ANDed across clauses and ORed within one, which is what
 * WP_Query does and what "two categories means either" depends on. Without it
 * every assertion about which rows come back would be vacuous.
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

        $tax = array();
        foreach ( (array) ( isset( $args['tax_query'] ) ? $args['tax_query'] : array() ) as $k => $clause ) {
            if ( ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) ) { continue; }
            $tax[] = array(
                'taxonomy' => $clause['taxonomy'],
                'terms'    => array_map( 'strval', (array) $clause['terms'] ),
                'field'    => isset( $clause['field'] ) ? $clause['field'] : 'slug',
            );
        }

        $matched = array();
        foreach ( $GLOBALS['events'] as $id => $e ) {
            if ( null !== $lo && $e['date'] < $lo ) { continue; }
            if ( null !== $hi && $e['date'] > $hi ) { continue; }

            $ok = true;
            foreach ( $tax as $clause ) {
                $have = (array) ( isset( $e[ $clause['taxonomy'] ] ) ? $e[ $clause['taxonomy'] ] : array() );
                if ( 'term_id' === $clause['field'] ) {
                    $ids = array();
                    foreach ( $have as $slug ) {
                        if ( isset( $GLOBALS['terms'][ $clause['taxonomy'] ][ $slug ] ) ) {
                            $ids[] = (string) $GLOBALS['terms'][ $clause['taxonomy'] ][ $slug ]->term_id;
                        }
                    }
                    $have = $ids;
                }
                if ( ! array_intersect( $clause['terms'], $have ) ) { $ok = false; break; }
            }
            if ( ! $ok ) { continue; }

            $matched[ $id ] = $e['date'];
        }
        asort( $matched );
        $all = array_keys( $matched );

        $per   = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
        $paged = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
        $this->ids = ( $per > 0 ) ? array_slice( $all, ( $paged - 1 ) * $per, $per ) : $all;

        /*
         * `fields => 'ids'` MAKES posts AN ARRAY OF INTS, and modelling that is
         * not a nicety. available_groups() does array_map( 'intval', $q->posts )
         * on the result, so a stub always handing back objects makes that line
         * emit a notice and derive nothing, and the second-level row then looks
         * broken in the harness while being perfectly correct in the product.
         * The first run of this file did exactly that.
         */
        if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
            $this->posts = array_map( 'intval', $this->ids );
        } else {
            foreach ( $this->ids as $id ) { $this->posts[] = (object) array( 'ID' => $id ); }
        }
        $this->post_count    = count( $this->ids );
        $this->found_posts   = count( $all );
        $this->max_num_pages = ( $per > 0 && $per < count( $all ) ) ? (int) ceil( count( $all ) / $per ) : 1;
    }
    public function have_posts() { return $this->i < count( $this->ids ); }
    public function the_post() { $GLOBALS['current_post'] = $this->ids[ $this->i ]; $this->i++; }
}

class WP_REST_Request {
    private $params;
    public function __construct( $params = array() ) { $this->params = $params; }
    public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
}

require_once $root . '/includes/class-sfaf-shortcodes.php';
require_once $root . '/includes/class-sfaf-embed.php';

/* ---------------------------------------------------------------------------
 * The world.
 *
 * TWO CATEGORIES, THREE SERIES, TWO ORGANIZERS, chosen so every assertion has
 * a row that must come back and a row that must not.
 * ------------------------------------------------------------------------ */
function term( $tax, $slug, $name, $id ) {
    $GLOBALS['terms'][ $tax ][ $slug ] = (object) array(
        'term_id' => $id, 'slug' => $slug, 'name' => $name, 'taxonomy' => $tax, 'count' => 1, 'description' => '',
    );
}
term( 'uc_event_category', 'support-groups', 'Support Groups', 11 );
term( 'uc_event_category', 'workshops',      'Workshops',      12 );
term( 'uc_organizer',      'stonewall',      'Stonewall Project', 21 );
term( 'uc_organizer',      'strut',          'Strut',             22 );
term( 'uc_series',         'monday-group',   'Monday Group',   31 );
term( 'uc_series',         'thursday-group', 'Thursday Group', 32 );
term( 'uc_series',         'craft-night',    'Craft Night',    33 );

/*
 * EVERY EVENT IS IN ONE FUTURE MONTH, and both halves of that matter.
 *
 * FUTURE, because the upcoming clause drops anything before today and the
 * second-level row derives its pills from the events the block CONTAINS. The
 * first draft of this file dated events across today, so one of the two series
 * was silently dropped and the row looked half broken when it was correct.
 *
 * ONE MONTH, because the calendar and combined views are bound to a month, and
 * events split across two would make "which rows came back" depend on which
 * month the assertion happened to ask for.
 */
$MONTH = date( 'Y-m', strtotime( 'first day of next month' ) );
$day   = function ( $n ) use ( $MONTH ) {
    return $MONTH . '-' . str_pad( (string) max( 1, $n ), 2, '0', STR_PAD_LEFT );
};

/* id => [title, date, categories, organizers, series] */
$WORLD = array(
    101 => array( 'Monday drop-in',    $day( 20 ), array( 'support-groups' ), array( 'stonewall' ), array( 'monday-group' ) ),
    102 => array( 'Thursday drop-in',  $day( 21 ), array( 'support-groups' ), array( 'stonewall' ), array( 'thursday-group' ) ),
    103 => array( 'Craft workshop',    $day( 22 ), array( 'workshops' ),      array( 'strut' ),     array( 'craft-night' ) ),
    104 => array( 'Unseries workshop', $day( 23 ), array( 'workshops' ),      array( 'strut' ),     array() ),
);
foreach ( $WORLD as $id => $row ) {
    $GLOBALS['events'][ $id ] = array(
        'title'             => $row[0],
        'date'              => $row[1],
        'uc_event_category' => $row[2],
        'uc_organizer'      => $row[3],
        'uc_series'         => $row[4],
    );
}

$sc = new SFAF_Shortcodes();

/** The event ids a rendered block actually shows, read out of its markup. */
function rows_in( $html ) {
    $ids = array();
    foreach ( $GLOBALS['events'] as $id => $e ) {
        if ( false !== strpos( $html, '>' . esc_html( $e['title'] ) . '<' ) ) { $ids[] = (int) $id; }
    }
    sort( $ids );
    return $ids;
}

/** Render a block, with the visitor's choices passed as the entry point takes them. */
function block( $args ) {
    global $sc, $MONTH;
    $out = $sc->render_calendar_block( array_merge( array( 'view' => 'list', 'per_page' => 50, 'month' => $MONTH, 'count' => 50 ), $args ) );
    /* render_calendar_block() returns the payload the ajax route and the embed
     * both send, not a string. The markup a visitor sees is its html. */
    return is_array( $out ) ? (string) $out['html'] : (string) $out;
}

/* Which controls a rendered block is offering, read out of its markup. */
/*
 * THE TWO ROWS ARE ONE CONTROL FROM 3.85.0, and this detector is rewritten to
 * assert the new arrangement rather than relaxed to accept either. It used to
 * look for `data-uc-organizer`, a single <select>, and `data-uc-groups`, a
 * separate disclosure below the bar. Both are gone: organizers and groups share
 * one popover, so each row is now detected by its own SECTION inside it.
 *
 * That is a stronger claim than the old one, not a weaker one. The old check
 * passed as long as a control existed anywhere in the markup; this one requires
 * the right checkbox NAME, which is what the server actually reads from the
 * query string, so a control that renders and posts nothing the server knows
 * about fails here.
 */
function controls_in( $html ) {
    return array(
        'category'  => ( false !== strpos( $html, 'uc-filter-btn' ) ),
        'organizer' => ( false !== strpos( $html, 'name="uc_org[]"' ) ),
        'series'    => ( false !== strpos( $html, 'name="uc_group[]"' ) ),
    );
}

/** Every combination of the three toggles, as the attribute carries them. */
function combinations() {
    $rows = array( 'category', 'organizer', 'series' );
    $out  = array();
    for ( $mask = 0; $mask < 8; $mask++ ) {
        $on = array();
        foreach ( $rows as $bit => $row ) {
            if ( $mask & ( 1 << $bit ) ) { $on[] = $row; }
        }
        $out[] = $on;
    }
    return $out;
}

/* =========================================================================
 * 1. THE SECOND-LEVEL SERIES ROW.
 *
 * It was reported missing. It is not: it renders, it is wired to a handler and
 * it has styles. Until 3.50.0 it appeared only AFTER a category was chosen,
 * which is what a bar showing categories only looks like before the first
 * click, and it derives its pills from the series carried by the events the
 * block contains, so a category whose events have no series offers none.
 *
 * Both facts are asserted here so neither can be mistaken for the other again.
 * ====================================================================== */
$picked = block( array( 'filters' => 'category,series', 'active_category' => 'support-groups' ) );
if ( ! preg_match_all( '/name="uc_group\[\]"\s+value="([^"]+)"/', $picked, $m ) ) {
    fail( 'no series pills at all with a category chosen, which is what 3.11.0 built' );
} else {
    sort( $m[1] );
    if ( array( 'monday-group', 'thursday-group' ) !== $m[1] ) {
        fail( 'the series pills under Support Groups are ' . implode( ',', $m[1] ) . ', expected monday-group,thursday-group' );
    }
}

/* A category whose events carry no series offers no pills, and that is the
 * data condition rather than a fault. Workshops holds one series and one event
 * with none, so exactly one pill is correct. */
$ws = block( array( 'filters' => 'category,series', 'active_category' => 'workshops' ) );
preg_match_all( '/name="uc_group\[\]"\s+value="([^"]+)"/', $ws, $mw );
if ( array( 'craft-night' ) !== $mw[1] ) {
    fail( 'Workshops offers ' . implode( ',', $mw[1] ) . ' rather than only craft-night' );
}

/* AND THE ROW NO LONGER NEEDS A CATEGORY FIRST (3.50.0). This is the case the
 * per-row toggles exist for: an organizer-scoped block offering series only. */
$org_only = block( array( 'organizer' => 'stonewall', 'filters' => 'series' ) );
$c        = controls_in( $org_only );
if ( ! $c['series'] ) {
    fail( 'a block offering the series row alone renders no series row, so the organizer case cannot work' );
}
if ( $c['category'] ) {
    fail( 'a block offering the series row alone also renders category buttons' );
}
preg_match_all( '/name="uc_group\[\]"\s+value="([^"]+)"/', $org_only, $mo );
sort( $mo[1] );
if ( array( 'monday-group', 'thursday-group' ) !== $mo[1] ) {
    fail( 'the organizer-scoped series row offers ' . implode( ',', $mo[1] ) . ', expected that organizer\'s two' );
}

/* =========================================================================
 * 2. EVERY COMBINATION, IN EVERY DISPLAY MODE.
 *
 * EIGHT COMBINATIONS TIMES FOUR MODES, asserted rather than sampled. The
 * sidebar is the one that differs and it differs by design: it has no filter
 * bar in any configuration, so it must offer nothing whatever it is asked for.
 * ====================================================================== */
$MODES = array( 'list', 'calendar', 'combined', 'sidebar' );

foreach ( $MODES as $mode ) {
    foreach ( combinations() as $on ) {
        $attr = empty( $on ) ? 'none' : implode( ',', $on );
        $html = block( array( 'view' => $mode, 'filters' => $attr ) );
        $got  = controls_in( $html );

        foreach ( array( 'category', 'organizer', 'series' ) as $row ) {
            $want = ( 'sidebar' === $mode ) ? false : in_array( $row, $on, true );
            if ( $got[ $row ] !== $want ) {
                fail( sprintf(
                    '%s view, filters="%s": the %s control is %s and should be %s',
                    $mode, $attr, $row,
                    $got[ $row ] ? 'there' : 'missing',
                    $want ? 'there' : 'missing'
                ) );
            }
        }
    }
}

/*
 * THE ORDER, WHICH IS NOW TWO CLAIMS RATHER THAN ONE (3.85.0).
 *
 * Organizers and groups used to be two controls in one row, so their order was
 * their order in the bar. They are one control now, with the two lists stacked
 * inside its panel under headings, so the claim splits:
 *
 *   . the category pills still come BEFORE the combined control, because the
 *     pills are the first-level filter and are the one thing readable without
 *     opening anything;
 *   . inside the panel, Organizers still comes before Groups, which is the
 *     same reading order the two controls had.
 *
 * Both are asserted, so neither half can be reversed unnoticed. This is the
 * arrangement the merge produced rather than a relaxation of the old check:
 * the old one could not have said anything about what is inside a panel.
 */
$all_on = block( array( 'filters' => 'category,organizer,series' ) );
$pos    = array(
    'category' => strpos( $all_on, 'uc-filter-btn' ),
    'who'      => strpos( $all_on, 'data-uc-who' ),
);
if ( false === $pos['who'] ) {
    fail( 'the combined organizers and groups control is not in the bar at all' );
} elseif ( ! ( false !== $pos['category'] && $pos['category'] < $pos['who'] ) ) {
    fail( 'the category pills no longer come before the organizers and groups control' );
}

$heading_org = strpos( $all_on, '>Organizers<' );
$heading_grp = strpos( $all_on, '>Groups<' );
if ( false === $heading_org || false === $heading_grp ) {
    fail( 'the panel no longer carries both headings, so thirty-four names read as one undifferentiated list' );
} elseif ( $heading_org > $heading_grp ) {
    fail( 'Groups now comes before Organizers inside the panel, reversing the reading order the two controls had' );
}

/*
 * NOTHING IS HIDDEN FOR BEING REDUNDANT, AND THIS IS THE ASSERTION THAT SAYS SO.
 *
 * A block scoped to one organizer that asks for the organizer row gets the
 * organizer row, and a block scoped to one category that asks for the category
 * row gets that. It is tempting for the plugin to decide a control with one
 * option is furniture and drop it; whoever generated the block can see the page
 * it is going on and this code cannot, so the choice stands.
 *
 * Planting the "helpful" version of this was NOT caught by the combinations
 * above, because every one of those blocks is unscoped, which is why this case
 * is here as well as those.
 */
$scoped = array(
    'organizer' => array( array( 'organizer' => 'stonewall', 'filters' => 'organizer' ), 'organizer' ),
    'category'  => array( array( 'category' => 'workshops', 'filters' => 'category' ), 'category' ),
    'series'    => array( array( 'organizer' => 'strut', 'filters' => 'series' ), 'series' ),
);
foreach ( $scoped as $label => $case ) {
    list( $args, $row ) = $case;
    $got = controls_in( block( $args ) );
    if ( ! $got[ $row ] ) {
        fail( sprintf(
            'a block scoped to one %s asked for the %s row and did not get it: the plugin decided the choice was redundant',
            $label, $row
        ) );
    }
}

/*
 * THE ATTRIBUTE LISTS THE ROWS IN THE CANONICAL ORDER, so the snippet a person
 * pastes reads the same way the bar renders. filter_rows_available() is the one
 * list both come off; asserting the string keeps it canonical rather than
 * merely a set.
 */
$attr_block = block( array( 'filters' => 'series,category,organizer' ) );
if ( preg_match( '/data-filters="([^"]*)"/', $attr_block, $fm ) ) {
    if ( 'category,organizer,series' !== $fm[1] ) {
        fail( 'data-filters reads "' . $fm[1] . '", expected the canonical category,organizer,series' );
    }
} else {
    fail( 'the block carries no data-filters attribute, so a redraw cannot keep its toggles' );
}

/* And "none" is carried explicitly, because an absent attribute means the old
 * default rather than nothing. */
if ( preg_match( '/data-filters="([^"]*)"/', block( array( 'filters' => 'none' ) ), $nm ) ) {
    if ( 'none' !== $nm[1] ) {
        fail( 'a block with no rows carries data-filters="' . $nm[1] . '" rather than "none"' );
    }
}
/* An absent attribute is the old behaviour, which is what every block already
 * pasted on sfaf.org carries. */
$legacy_on  = controls_in( block( array() ) );
$legacy_off = controls_in( block( array( 'show_filters' => 'no' ) ) );
foreach ( array( 'category', 'organizer', 'series' ) as $row ) {
    if ( ! $legacy_on[ $row ] ) {
        fail( "a block with no filters attribute lost its $row row, so every pasted snippet changed" );
    }
    if ( $legacy_off[ $row ] ) {
        fail( "show_filters=\"no\" still renders the $row row" );
    }
}

/* =========================================================================
 * 3. FILTERING NARROWS THE ROWS, THROUGH THE QUERY.
 *
 * The rows are read out of the rendered block. A filter that hid rows in the
 * browser would leave them in this markup, so "did the query narrow" and "did
 * something get hidden" cannot be confused here.
 * ====================================================================== */
$cases = array(
    'no choice'            => array( array(), array( 101, 102, 103, 104 ) ),
    'category chosen'      => array( array( 'active_category' => 'support-groups' ), array( 101, 102 ) ),
    'organizer chosen'     => array( array( 'active_organizer' => 'strut' ), array( 103, 104 ) ),
    'series chosen'        => array( array( 'active_groups' => 'monday-group' ), array( 101 ) ),
    'two series chosen'    => array( array( 'active_groups' => 'monday-group,craft-night' ), array( 101, 103 ) ),
    'category + organizer' => array( array( 'active_category' => 'workshops', 'active_organizer' => 'strut' ), array( 103, 104 ) ),
);
foreach ( $cases as $label => $case ) {
    list( $args, $want ) = $case;
    $got = rows_in( block( array_merge( array( 'filters' => 'category,organizer,series' ), $args ) ) );
    if ( $got !== $want ) {
        fail( sprintf( '%s: rows %s, expected %s', $label, implode( ',', $got ), implode( ',', $want ) ) );
    }
}

/* The count beside the list has to agree with the query, not with the page. */
$narrowed = $sc->render_calendar_block( array(
    'view' => 'list', 'per_page' => 50, 'month' => $MONTH, 'count' => 50,
    'filters' => 'category', 'active_category' => 'support-groups',
) );
if ( 2 !== (int) $narrowed['total'] ) {
    fail( 'the total for a narrowed block is ' . (int) $narrowed['total'] . ', expected 2' );
}

/* =========================================================================
 * 4. A SCOPED BLOCK CANNOT BE WIDENED BY A PARAMETER.
 *
 * Every one of these is a hand-written value naming something real that the
 * block was not scoped to. The answer is the block's own events, never the
 * wider set and never nothing.
 * ====================================================================== */
$widen = array(
    'category scope, another category asked for' => array(
        array( 'category' => 'support-groups', 'active_category' => 'workshops' ),
        array( 101, 102 ),
    ),
    'organizer scope, another organizer asked for' => array(
        array( 'organizer' => 'stonewall', 'active_organizer' => 'strut' ),
        array( 101, 102 ),
    ),
    'category scope, a series outside it asked for' => array(
        array( 'category' => 'support-groups', 'active_groups' => 'craft-night' ),
        array( 101, 102 ),
    ),
    'organizer scope, a series outside it asked for' => array(
        array( 'organizer' => 'strut', 'active_groups' => 'monday-group' ),
        array( 103, 104 ),
    ),
);
foreach ( $widen as $label => $case ) {
    list( $args, $want ) = $case;
    $got = rows_in( block( array_merge( array( 'filters' => 'category,organizer,series' ), $args ) ) );
    if ( $got !== $want ) {
        fail( sprintf( 'SCOPE WIDENED: %s gave rows %s, expected %s', $label, implode( ',', $got ), implode( ',', $want ) ) );
    }
}

/* And the clamp holds with the filter row switched OFF, which is the case a
 * hand-written parameter is actually aimed at: no control on screen to have
 * produced it. */
$no_rows = rows_in( block( array(
    'category' => 'support-groups', 'filters' => 'none', 'active_category' => 'workshops',
) ) );
if ( array( 101, 102 ) !== $no_rows ) {
    fail( 'a block offering no filters was widened by a parameter: rows ' . implode( ',', $no_rows ) );
}

/* =========================================================================
 * --self-test: prove the harness can fail before believing it.
 * ====================================================================== */
if ( $self ) {
    $bad = 0;
    echo "Self-test: does this file notice when things are wrong?\n\n";

    /* THE ONE THAT MATTERS. A WP_Query ignoring tax_query would return the same
     * rows for every assertion in sections 3 and 4, and both would pass while
     * testing nothing at all. */
    $q_all = new WP_Query( array( 'posts_per_page' => 50 ) );
    $q_cat = new WP_Query( array(
        'posts_per_page' => 50,
        'tax_query'      => array( array( 'taxonomy' => 'uc_event_category', 'field' => 'slug', 'terms' => array( 'workshops' ) ) ),
    ) );
    if ( count( $q_all->posts ) > count( $q_cat->posts ) && 2 === count( $q_cat->posts ) ) {
        echo "ok       the query stub really filters on tax_query\n";
    } else {
        echo "BROKEN:  tax_query is ignored, so sections 3 and 4 prove nothing\n";
        $bad++;
    }

    /* And on the date clause, which is what drops a past event and which the
     * first draft of this file got wrong. */
    $q_past = new WP_Query( array(
        'posts_per_page' => 50,
        'meta_query'     => array( array( 'key' => '_uc_event_date', 'compare' => '>=', 'value' => '2999-01-01' ) ),
    ) );
    if ( 0 === count( $q_past->posts ) ) {
        echo "ok       the query stub really filters on the date clause\n";
    } else {
        echo "BROKEN:  the date clause is ignored\n";
        $bad++;
    }

    /* fields => ids, which available_groups() depends on. */
    $q_ids = new WP_Query( array( 'posts_per_page' => 50, 'fields' => 'ids' ) );
    if ( ! empty( $q_ids->posts ) && is_int( $q_ids->posts[0] ) ) {
        echo "ok       fields => ids gives ints, as available_groups() expects\n";
    } else {
        echo "BROKEN:  fields => ids does not give ints; the series row cannot derive\n";
        $bad++;
    }

    /* The blocks really render something. */
    $probe = block( array( 'filters' => 'category,organizer,series' ) );
    if ( strlen( $probe ) > 500 && 4 === count( rows_in( $probe ) ) ) {
        echo "ok       a block renders and all four events are readable in it\n";
    } else {
        echo "BROKEN:  the block did not render its events, so rows_in() reads nothing\n";
        $bad++;
    }

    /* Every event is in the future, or the second level derives from a subset. */
    $today = date( 'Y-m-d' );
    $past  = 0;
    foreach ( $GLOBALS['events'] as $e ) {
        if ( $e['date'] < $today ) { $past++; }
    }
    if ( 0 === $past ) {
        echo "ok       every event in the world is upcoming\n";
    } else {
        echo "BROKEN:  $past event(s) are in the past; the series row will derive from fewer\n";
        $bad++;
    }

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the harness reads what it claims to read.\n" );
    exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "Filter rows offered, and the rows that come back when one is used\n";
echo str_repeat( '=', 74 ) . "\n";
echo "world:    4 events, 2 categories, 2 organizers, 3 series, one event in no series\n";
echo "modes:    list, calendar, combined, sidebar\n";
printf( "checked:  %d toggle combinations in each of the %d modes\n", count( combinations() ), count( $MODES ) );
echo "clamped:  a scope cannot be widened by active_category, active_organizer or active_groups\n\n";

$show = block( array( 'filters' => 'category,organizer,series' ) );
$c    = controls_in( $show );
printf( "all on:   category %s  organizer %s  series %s\n",
    $c['category'] ? 'yes' : 'no', $c['organizer'] ? 'yes' : 'no', $c['series'] ? 'yes' : 'no' );
echo "\n";

if ( empty( $fails ) ) {
    echo "every combination offers exactly its own rows, and every filter narrows through the query.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f2 ) { echo '  - ' . $f2 . "\n"; }
exit( 1 );
