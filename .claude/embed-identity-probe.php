<?php
/**
 * WHAT ONE PLUGIN TREE SHOWS THE PUBLIC, AS JSON (3.113.0).
 *
 *     php <tree>/.claude/embed-identity-probe.php
 *
 * Not a check. embed-identity-test.php copies this file into a tree exported
 * from git and into nothing else, runs it there and here, and compares the two
 * outputs. It runs in whichever tree holds it, because wp-kit.php loads the
 * plugin from the folder above its own.
 *
 * It records:
 *   - every taxonomy, post type, REST route, rewrite rule and tag, script and
 *     stylesheet the plugin registers on init, rest_api_init and
 *     wp_enqueue_scripts, with their arguments
 *   - the scheduled tasks and the cron intervals
 *   - the embed route's answer, body and headers, for every mode and view a
 *     snippet can ask for, over the same 60 events
 *   - the [sfaf_calendar] block, list and grid
 */

$GLOBALS['EI'] = array( 'taxonomies' => array(), 'post_types' => array(), 'rest' => array(), 'rewrite' => array(), 'scripts' => array(), 'styles' => array() );

function ei_args( $a ) {
    $out = array();
    foreach ( (array) $a as $k => $v ) {
        if ( $v instanceof Closure ) { $out[ $k ] = 'closure'; }
        elseif ( is_array( $v ) ) { $out[ $k ] = ( isset( $v[0] ) && is_object( $v[0] ) ) ? get_class( $v[0] ) . '::' . $v[1] : ei_args( $v ); }
        elseif ( is_object( $v ) ) { $out[ $k ] = get_class( $v ); }
        else { $out[ $k ] = $v; }
    }
    return $out;
}
function register_taxonomy( $t, $o, $a = array() ) { $GLOBALS['EI']['taxonomies'][ $t ] = array( 'object' => $o, 'args' => ei_args( $a ) ); return true; }
function register_post_type( $t, $a = array() ) { $GLOBALS['EI']['post_types'][ $t ] = ei_args( $a ); return (object) array( 'name' => $t ); }
function register_rest_route( $ns, $r, $a = array(), $o = false ) { $GLOBALS['EI']['rest'][ $ns . $r ] = ei_args( $a ); return true; }
function add_rewrite_rule( $re, $q, $after = 'bottom' ) { $GLOBALS['EI']['rewrite'][] = array( $re, $q, $after ); }
function add_rewrite_tag( $t, $re, $q = '' ) { $GLOBALS['EI']['rewrite'][] = array( 'tag', $t, $re, $q ); }
function add_rewrite_endpoint( $n, $p, $q = true ) { $GLOBALS['EI']['rewrite'][] = array( 'endpoint', $n, $p, $q ); }
function wp_register_script( $h, $s = '', $d = array(), $v = false, $f = false ) { $GLOBALS['EI']['scripts'][ $h ] = array( $s, $d, $v ); return true; }
function wp_enqueue_script( $h, $s = '', $d = array(), $v = false, $f = false ) { $GLOBALS['EI']['scripts'][ $h ] = array( $s, $d, $v ); }
function wp_register_style( $h, $s = '', $d = array(), $v = false, $m = 'all' ) { $GLOBALS['EI']['styles'][ $h ] = array( $s, $d, $v ); return true; }
function wp_enqueue_style( $h, $s = '', $d = array(), $v = false, $m = 'all' ) { $GLOBALS['EI']['styles'][ $h ] = array( $s, $d, $v ); }

require __DIR__ . '/wp-kit.php';
foreach ( array( 'wpautop' => 'return $s;', 'force_balance_tags' => 'return $s;', 'get_http_origin' => 'return "";' ) as $fn => $body ) {
    if ( ! function_exists( $fn ) ) { eval( "function $fn( \$s = '' ) { $body }" ); }
}
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $m = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', trim( (string) $t ) ), 0, $n ) ); } }
/* What init and rest_api_init call that wp-kit has no need of elsewhere. */
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h, $a = array() ) { return 1; } }
if ( ! function_exists( 'wp_get_schedule' ) ) { function wp_get_schedule( $h, $a = array() ) { return 'sfaf_quarter_hour'; } }
/* Registration calls recorded by name and arguments, so a difference between
   two trees in any of them is a difference in the output. */
foreach ( array( 'register_post_status', 'register_meta', 'register_post_meta', 'register_term_meta', 'add_post_type_support',
    'remove_post_type_support', 'add_image_size', 'register_block_type', 'flush_rewrite_rules', 'add_role', 'remove_role',
    'wp_register_script_module' ) as $ei_fn ) {
    if ( ! function_exists( $ei_fn ) ) { eval( 'function ' . $ei_fn . '( ...$a ) { $GLOBALS["EI"]["registered"][] = "' . $ei_fn . ' " . json_encode( ei_args( $a ) ); return true; }' ); }
}
foreach ( array( 'wp_schedule_event', 'wp_schedule_single_event', 'wp_unschedule_event', 'wp_clear_scheduled_hook' ) as $ei_fn ) {
    if ( ! function_exists( $ei_fn ) ) { eval( 'function ' . $ei_fn . '( ...$a ) { $GLOBALS["EI"]["scheduled"][] = "' . $ei_fn . ' " . json_encode( $a ); return true; }' ); }
}
if ( ! function_exists( 'register_taxonomy_for_object_type' ) ) { function register_taxonomy_for_object_type( $t, $o ) { $GLOBALS['EI']['taxonomies'][ $t ]['also'][] = $o; return true; } }
if ( ! function_exists( '_n_noop' ) ) { function _n_noop( $s, $p, $d = null ) { return array( 0 => $s, 1 => $p, 'singular' => $s, 'plural' => $p, 'context' => null, 'domain' => $d ); } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }
if ( ! function_exists( 'has_filter' ) ) { function has_filter( $h, $cb = false ) { return false; } }
if ( ! function_exists( 'delete_post_meta_by_key' ) ) { function delete_post_meta_by_key( $k ) { return true; } }
if ( ! function_exists( 'taxonomy_exists' ) ) { function taxonomy_exists( $t ) { return isset( $GLOBALS['EI']['taxonomies'][ $t ] ); } }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( $h, $n, $d ) { $GLOBALS['EI']['localized'][ $h . ' ' . $n ] = array_keys( (array) $d ); return true; } }
if ( ! function_exists( 'wp_add_inline_script' ) ) { function wp_add_inline_script( $h, $d, $p = 'after' ) { $GLOBALS['EI']['inline'][ $h ] = $d; return true; } }
if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'future' => 1, 'draft' => 1, 'pending' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } function get_route() { return '/sfaf-calendar/v1/embed'; } function get_method() { return 'GET'; } function get_header( $k ) { return null; } }
    class WP_REST_Response { private $d; private $h = array(); function __construct( $d = null, $s = 200 ) { $this->d = $d; } function header( $k, $v ) { $this->h[ $k ] = $v; } function get_headers() { return $this->h; } function set_headers( $h ) { $this->h = $h; } function get_data() { return $this->d; } }
}

/*
 * The hooks that register what the public reaches, by index and in priority
 * order, because a callback on init adds more callbacks to init. The schema is
 * marked current first, so init does not try to build tables. What threw is
 * recorded rather than hidden: a probe that silently registers nothing would
 * find two trees identical.
 */
update_option( 'sfaf_db_version', SFAF_DB_VERSION );
$out_threw = array();
function ei_fire( $hook, $args, &$threw ) {
    $done = array();
    for ( $pass = 0; $pass < 5; $pass++ ) {
        $hs = isset( $GLOBALS['kit_hooks'][ $hook ] ) ? $GLOBALS['kit_hooks'][ $hook ] : array();
        uasort( $hs, function ( $a, $b ) { return $a['p'] - $b['p']; } );
        $ran = false;
        foreach ( $hs as $i => $h ) {
            if ( isset( $done[ $i ] ) ) { continue; }
            $done[ $i ] = true; $ran = true;
            try { $args[0] = call_user_func_array( $h['cb'], array_slice( $args, 0, max( 1, $h['n'] ) ) ) ?? $args[0]; }
            catch ( Throwable $e ) {
                $name = is_array( $h['cb'] ) ? ( is_object( $h['cb'][0] ) ? get_class( $h['cb'][0] ) : $h['cb'][0] ) . '::' . $h['cb'][1] : ( is_string( $h['cb'] ) ? $h['cb'] : 'closure' );
                $threw[] = "$hook $name: " . $e->getMessage();
            }
        }
        if ( ! $ran ) { break; }
    }
    return $args[0];
}
foreach ( array( 'init', 'rest_api_init', 'wp_enqueue_scripts' ) as $hook ) { ei_fire( $hook, array( '' ), $out_threw ); }
$out = $GLOBALS['EI'];
$out['threw'] = $out_threw;
$out['cron_tasks'] = array_map( function ( $t ) { return array( $t['label'], $t['plain'] ); }, SFAF_Cron::tasks() );
$out['cron_schedules'] = ei_fire( 'cron_schedules', array( array() ), $out_threw );

/* The same 60 events in both trees. */
function ei_query( $a ) {
    $type   = isset( $a['post_type'] ) ? (array) $a['post_type'] : array( 'uc_event' );
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $rows = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( ! in_array( $p->post_type, $type, true ) ) { continue; }
        if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
        if ( ! empty( $a['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', (array) $a['post__in'] ), true ) ) { continue; }
        $rows[] = $p;
    }
    usort( $rows, function ( $x, $y ) { return strcmp( $GLOBALS['kit_meta'][ $x->ID ]['_uc_event_date'] . $x->ID, $GLOBALS['kit_meta'][ $y->ID ]['_uc_event_date'] . $y->ID ); } );
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : 10;
    if ( $per > 0 ) { $page = max( 1, isset( $a['paged'] ) ? (int) $a['paged'] : 1 ); $rows = array_slice( $rows, ( $page - 1 ) * $per, $per ); }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $rows, 'ID' ) : $rows;
}
$GLOBALS['kit_query'] = 'ei_query'; $GLOBALS['kit_get_posts'] = 'ei_query'; $GLOBALS['kit_loop'] = true;
$GLOBALS['kit_db'] = function ( $m, $sql ) { return ( 'get_results' === $m || 'get_col' === $m ) ? array() : null; };
$today = current_time( 'Y-m-d' );
for ( $i = 0; $i < 60; $i++ ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Event ' . $i, 'post_author' => 1, 'post_content' => '<p>Words about event ' . $i . '.</p>' ) );
    $meta = array(
        '_uc_event_date'      => date( 'Y-m-d', strtotime( $today . ' ' . ( ( $i * 7 ) % 75 - 10 ) . ' days' ) ),
        '_uc_start_time'      => sprintf( '%02d:00', 9 + $i % 9 ),
        '_uc_end_time'        => sprintf( '%02d:30', 10 + $i % 9 ),
        '_uc_location'        => 0 === $i % 3 ? '' : ( 1035 + $i % 5 ) . ' Market Street',
        '_uc_rsvp_enabled'    => $i % 2 ? '1' : '',
        '_uc_age_restriction' => array( '', '18+', '21 and over', 'All ages' )[ $i % 4 ],
        '_uc_cost'            => $i % 4 ? '' : 'Free',
    );
    foreach ( $meta as $k => $v ) { $GLOBALS['kit_meta'][ $id ][ $k ] = $v; }
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 11 + $i % 2 );
    $GLOBALS['kit_terms'][ $id ]['uc_organizer']      = array( 11 + ( $i >> 1 ) % 2 );
    $GLOBALS['kit_terms'][ $id ]['uc_series']         = array( 11 + $i % 2 );
}

$embed = new SFAF_Embed( new SFAF_Shortcodes() );
$sfaf  = array( 'filters' => 'category,organizer,series', 'per_page' => 12, 'view' => 'combined', 'toggle' => 'yes', 'source_links' => 'no', 'page' => 1, 'mode' => 'block' );
$cases = array(
    'sfaf.org/calendar'      => $sfaf,
    'no filters attribute'   => array( 'page' => 1, 'mode' => 'block' ),
    'list'                   => array_merge( $sfaf, array( 'view' => 'list' ) ),
    'calendar'               => array_merge( $sfaf, array( 'view' => 'calendar' ) ),
    'sidebar'                => array_merge( $sfaf, array( 'view' => 'sidebar', 'count' => 4 ) ),
    'compact'                => array_merge( $sfaf, array( 'view' => 'list', 'layout' => 'compact' ) ),
    'page 2 of the list'     => array_merge( $sfaf, array( 'mode' => 'items', 'page' => 2 ) ),
    'next month'             => array_merge( $sfaf, array( 'mode' => 'month', 'month' => date( 'Y-m', strtotime( $today . ' +1 month' ) ) ) ),
    'a chosen category'      => array_merge( $sfaf, array( 'active_category' => 'alpha' ) ),
    'a search'               => array_merge( $sfaf, array( 's' => 'Event 1' ) ),
    'a pinned event'         => array_merge( $sfaf, array( 'pin' => '7' ) ),
    'isolated'               => array_merge( $sfaf, array( 'isolate' => 'yes' ) ),
);
$out['embed'] = array();
foreach ( $cases as $name => $p ) {
    $GLOBALS['kit_trans'] = array();
    mt_srand( 1 ); // wp_rand() names the filter panel; the same seed, the same name in both trees.
    try {
        $r = $embed->handle_request( new WP_REST_Request( $p ) );
        $out['embed'][ $name ] = array( 'data' => $r->get_data(), 'headers' => $r->get_headers() );
    } catch ( Throwable $t ) {
        $out['embed'][ $name ] = 'THREW ' . $t->getMessage();
    }
}

$sc = new SFAF_Shortcodes();
$out['shortcode'] = array();
foreach ( array( 'list' => array(), 'calendar' => array( 'view' => 'calendar' ), 'combined' => array( 'view' => 'combined', 'toggle' => 'yes' ) ) as $name => $atts ) {
    ob_start();
    mt_srand( 1 );
    try { $html = $sc->render_calendar_block( $atts ); } catch ( Throwable $t ) { $html = 'THREW ' . $t->getMessage(); }
    $out['shortcode'][ $name ] = ob_get_clean() . $html;
}

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
