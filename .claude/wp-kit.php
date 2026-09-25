<?php
/**
 * A MINIATURE WORDPRESS THAT LOADS THE WHOLE PLUGIN (3.99.0).
 *
 *     require __DIR__ . '/wp-kit.php';   // then call anything in the plugin
 *
 * WHY THE WHOLE PLUGIN AND NOT A SLICE. The other live pages slice one function
 * out of one file, which is right when the question is about that function.
 * The questions in 3.99.0 are about a FORM: every label, every attribute and
 * every validator refusal, drawn by the real renderer from the real list. A
 * slice would have to retype everything around it, and a retyped copy agrees
 * with itself forever.
 *
 * WHAT IT IS. The WordPress functions the renderers and the event save call,
 * answering from a small in-memory store: posts, meta, terms. Anything the
 * plugin calls that is not defined here is at the foot of this file as a stub
 * returning null, one line each, found by running the renderers and the save
 * until nothing was missing. A new undefined function is a fatal naming it,
 * which is the cue to add one line there.
 *
 * WHAT IT IS NOT. A database, hooks, a request, a user system. do_action and
 * apply_filters do nothing, so a behaviour that lives in a hook is not here.
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING );
$GLOBALS['kit_root'] = str_replace( '\\', '/', dirname( __DIR__ ) );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', $GLOBALS['kit_root'] . '/' ); }
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'WEEK_IN_SECONDS', 604800 ); define( 'YEAR_IN_SECONDS', 31536000 ); define( 'MONTH_IN_SECONDS', 2592000 );
define( 'OBJECT', 'OBJECT' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'ARRAY_N', 'ARRAY_N' );
date_default_timezone_set( 'America/Los_Angeles' );

/* ---- The store. Reset with kit_reset(). ---- */
function kit_reset() {
    $GLOBALS['kit_posts'] = array();   // id => WP_Post
    $GLOBALS['kit_meta']  = array();   // id => key => value
    $GLOBALS['kit_terms'] = array();   // id => taxonomy => int[]
    $GLOBALS['kit_next']  = 500;
    $GLOBALS['kit_trans'] = array();
    $GLOBALS['kit_organizers'] = true; // whether the site has any organizers
    $GLOBALS['kit_options'] = array();   // name => value
    $GLOBALS['kit_term_meta'] = array(); // term id => key => value (3.103.0)
    $GLOBALS['kit_term_desc'] = array(); // term id => description (3.103.0)
    $GLOBALS['kit_query'] = null;         // callable( args ) => WP_Post[], or null for no rows (3.103.0)
}
kit_reset();

class WP_Error { public $m; public $c; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } function get_error_code() { return $this->c; } }
class WP_Post { public $ID = 0; public $post_title = ''; public $post_type = 'uc_event'; public $post_status = 'draft'; public $post_content = ''; public $post_author = 0; public $post_name = ''; public $post_parent = 0; public $post_date = '2026-09-01 00:00:00'; }
class WP_User { public $ID = 1; public $user_email = 'admin@sfaf.org'; public $display_name = 'Admin'; public $roles = array( 'administrator' ); function has_cap( $c ) { return true; } function exists() { return true; } }
class WP_Term { public $term_id; public $name; public $slug; public $taxonomy; public $count = 0; public $description = ''; public $parent = 0; public $term_taxonomy_id; }
class WP_Query { public $posts = array(); public $found_posts = 0; public $max_num_pages = 0; function __construct( $a = array() ) { if ( ! empty( $GLOBALS['kit_query'] ) ) { $this->posts = (array) call_user_func( $GLOBALS['kit_query'], $a ); $this->found_posts = count( $this->posts ); } } function have_posts() { return false; } }
class wpdb {
    public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy'; public $term_relationships = 'wp_term_relationships';
    public $users = 'wp_users'; public $usermeta = 'wp_usermeta'; public $options = 'wp_options'; public $insert_id = 0;
    function __call( $n, $a ) {
        if ( 'prepare' === $n ) { return (string) $a[0]; }
        if ( in_array( $n, array( 'get_results', 'get_col' ), true ) ) { return array(); }
        return null;
    }
}
$GLOBALS['wpdb'] = new wpdb();

/* ---- Escaping, strings, i18n. ---- */
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_js( $t ) { return addslashes( (string) $t ); }
function esc_url_raw( $t ) { return (string) $t; }
function esc_attr_e( $t, $d = '' ) { echo esc_attr( $t ); }
function esc_html_e( $t, $d = '' ) { echo esc_html( $t ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function __( $t, $d = '' ) { return $t; }
function _e( $t, $d = '' ) { echo $t; }
function _n( $s, $p, $n, $d = '' ) { return ( 1 === (int) $n ) ? $s : $p; }
function _x( $t, $c, $d = '' ) { return $t; }
function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $t ) ); }
function sanitize_text_field( $t ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $t ) ) ); }
function sanitize_textarea_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); }
function sanitize_email( $t ) { return trim( (string) $t ); }
function wp_unslash( $v ) { return $v; }
function wp_kses_post( $t ) { return (string) $t; }
function wp_kses( $t, $a = array() ) { return (string) $t; }
function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
function absint( $n ) { return abs( (int) $n ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function wp_parse_args( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); }
function wp_list_pluck( $l, $f ) { $o = array(); foreach ( (array) $l as $i ) { $o[] = is_object( $i ) ? $i->$f : $i[ $f ]; } return $o; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function map_deep( $v, $cb ) { return is_array( $v ) ? array_map( function ( $x ) use ( $cb ) { return map_deep( $x, $cb ); }, $v ) : call_user_func( $cb, $v ); }

/* ---- Plugin loading, URLs, hooks. ---- */
function plugin_dir_path( $f ) { return rtrim( str_replace( '\\', '/', dirname( $f ) ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return 'file:///' . $GLOBALS['kit_root'] . '/'; }
function plugin_basename( $f ) { return 'sfaf-calendar/sfaf-calendar.php'; }
/* HOOKS ARE RECORDED, NOT RUN (3.104.0). do_action() still does nothing, so
 * loading the plugin fires no listener. kit_run_hook() runs ONE recorded
 * callback the way WordPress does: an action with no arguments hands its
 * callback an empty string, sliced to accepted_args. Calling a callback by
 * hand with no argument is the one call WordPress never makes, and it hid a
 * fault for six releases (sfaf_normalize_time_post, 3.98.0 to 3.104.0). */
function add_action( $h = '', $cb = null, $p = 10, $n = 1 ) { $GLOBALS['kit_hooks'][ $h ][] = array( 'cb' => $cb, 'p' => (int) $p, 'n' => (int) $n ); return true; }
function add_filter( $h = '', $cb = null, $p = 10, $n = 1 ) { return add_action( $h, $cb, $p, $n ); }
function remove_action() {} function remove_filter() {} function do_action() {}
function kit_run_hook( $hook, $callback, $args = array() ) {
    if ( empty( $args ) ) { $args = array( '' ); }
    foreach ( isset( $GLOBALS['kit_hooks'][ $hook ] ) ? $GLOBALS['kit_hooks'][ $hook ] : array() as $h ) {
        if ( $h['cb'] === $callback ) {
            return call_user_func_array( $h['cb'], array_slice( $args, 0, $h['n'] ) );
        }
    }
    throw new RuntimeException( "kit_run_hook: nothing registered on $hook as " . ( is_string( $callback ) ? $callback : 'a callback' ) );
}
function apply_filters( $tag, $value ) { return $value; }
function add_shortcode() {} function register_activation_hook() {} function register_deactivation_hook() {} function register_uninstall_hook() {}
function home_url( $p = '' ) { return 'https://resources.sfaf.org' . $p; }
function site_url( $p = '' ) { return 'https://resources.sfaf.org' . $p; }
function admin_url( $p = '' ) { return 'https://resources.sfaf.org/wp-admin/' . $p; }
function add_query_arg( $a, $b = '', $c = '' ) { return is_array( $a ) ? ( $b . '?' . http_build_query( $a ) ) : ( $c . '?' . $a . '=' . $b ); }
function get_option( $n, $d = false ) { return ( isset( $GLOBALS['kit_options'] ) && array_key_exists( $n, $GLOBALS['kit_options'] ) ) ? $GLOBALS['kit_options'][ $n ] : $d; }
function update_option( $n, $v = null, $a = null ) { $GLOBALS['kit_options'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $d = '', $a = null ) { if ( isset( $GLOBALS['kit_options'][ $n ] ) ) { return false; } $GLOBALS['kit_options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['kit_options'][ $n ] ); return true; }
function get_bloginfo( $s = '' ) { return 'SFAF'; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { $o = '<input type="hidden" name="' . $n . '" value="nonce" />'; if ( $e ) { echo $o; } return $o; }
function selected( $a, $b = true, $e = true ) { $o = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $e ) { echo $o; } return $o; }
function checked( $a, $b = true, $e = true ) { $o = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $e ) { echo $o; } return $o; }
function disabled( $a, $b = true, $e = true ) { $o = ( (string) $a === (string) $b ) ? ' disabled="disabled"' : ''; if ( $e ) { echo $o; } return $o; }
function wp_die( $m = '' ) { throw new RuntimeException( 'wp_die: ' . ( is_string( $m ) ? $m : '' ) ); }
/* A redirect ends a request with exit, which would end the test too. With
 * kit_redirect_throws set it throws instead, so a POST route can be run to its
 * redirect and the test goes on (3.103.0). */
class KitRedirect extends RuntimeException {}
function wp_safe_redirect( $u ) { $GLOBALS['kit_redirect'] = $u; if ( ! empty( $GLOBALS['kit_redirect_throws'] ) ) { throw new KitRedirect( (string) $u ); } return true; }
function wp_verify_nonce( $n, $a = -1 ) { return 1; }
function wp_update_term( $id, $tax, $a = array() ) { if ( isset( $a['description'] ) ) { $GLOBALS['kit_term_desc'][ (int) $id ] = $a['description']; } return array( 'term_id' => (int) $id ); }
function wp_redirect( $u ) { $GLOBALS['kit_redirect'] = $u; return true; }

/* ---- Time. ---- */
function current_time( $t = 'mysql', $g = 0 ) {
    if ( 'timestamp' === $t ) { return time(); }
    $machine = ( 'mysql' === $t ) ? 'Y-m-d H:i:s' : $t; // storage and comparison, never shown
    return date( $machine );
}
function date_i18n( $f, $ts = false, $g = false ) { return date( $f, false === $ts ? time() : $ts ); }
function wp_date( $f, $ts = null, $tz = null ) { return date( $f, null === $ts ? time() : $ts ); }
function wp_timezone() { return new DateTimeZone( 'America/Los_Angeles' ); }
function wp_timezone_string() { return 'America/Los_Angeles'; }

/* ---- Users. One administrator. ---- */
function wp_get_current_user() { return new WP_User(); }
function get_current_user_id() { return 1; }
function get_userdata( $id ) { return new WP_User(); }
function current_user_can( $c ) { return true; }
function user_can( $u, $c ) { return true; }
function is_user_logged_in() { return true; }
function get_users( $a = array() ) { return array(); }
function get_user_meta( $id, $k = '', $s = false ) { return $s ? '' : array(); }
function get_avatar_url( $a = '', $b = array() ) { return ''; }

/* ---- Posts and meta, from the store. ---- */
function get_post( $id = null ) { $id = is_object( $id ) ? $id->ID : (int) $id; return isset( $GLOBALS['kit_posts'][ $id ] ) ? $GLOBALS['kit_posts'][ $id ] : null; }
function get_post_status( $id = null ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function get_post_type( $id = null ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_post_field( $f, $id = null ) { $p = get_post( $id ); return ( $p && isset( $p->$f ) ) ? $p->$f : ''; }
function get_the_title( $id = 0 ) { return get_post_field( 'post_title', $id ); }
function get_permalink( $id = 0 ) { return 'https://resources.sfaf.org/events/' . (int) ( is_object( $id ) ? $id->ID : $id ) . '/'; }
function get_posts( $a = array() ) { return array(); }
function kit_write_post( $arr ) {
    $id = ! empty( $arr['ID'] ) ? (int) $arr['ID'] : ++$GLOBALS['kit_next'];
    $p  = get_post( $id );
    if ( ! $p ) { $p = new WP_Post(); $p->ID = $id; }
    foreach ( $arr as $k => $v ) { if ( 'ID' !== $k && property_exists( $p, $k ) ) { $p->$k = $v; } }
    $GLOBALS['kit_posts'][ $id ] = $p;
    $GLOBALS['kit_writes'][] = array( 'id' => $id, 'status' => $p->post_status );
    return $id;
}
function wp_insert_post( $arr, $err = false ) { return kit_write_post( $arr ); }
function wp_update_post( $arr, $err = false ) { return kit_write_post( is_object( $arr ) ? (array) $arr : $arr ); }
function get_post_meta( $id, $k = '', $s = false ) {
    $m = isset( $GLOBALS['kit_meta'][ (int) $id ] ) ? $GLOBALS['kit_meta'][ (int) $id ] : array();
    if ( '' === $k ) { return $m; }
    if ( ! array_key_exists( $k, $m ) ) { return $s ? '' : array(); }
    return $s ? $m[ $k ] : array( $m[ $k ] );
}
function update_post_meta( $id, $k, $v, $prev = '' ) { $GLOBALS['kit_meta'][ (int) $id ][ $k ] = $v; return true; }
function add_post_meta( $id, $k, $v, $u = false ) { if ( $u && isset( $GLOBALS['kit_meta'][ (int) $id ][ $k ] ) ) { return false; } $GLOBALS['kit_meta'][ (int) $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k, $v = '' ) { unset( $GLOBALS['kit_meta'][ (int) $id ][ $k ] ); return true; }
function get_term_meta( $id, $k = '', $s = false ) { if ( isset( $GLOBALS['kit_term_meta'][ (int) $id ][ $k ] ) ) { $v = $GLOBALS['kit_term_meta'][ (int) $id ][ $k ]; return $s ? $v : array( $v ); } return $s ? '' : array(); }
function update_term_meta( $id, $k, $v, $p = '' ) { $GLOBALS['kit_term_meta'][ (int) $id ][ $k ] = $v; return true; }
function delete_term_meta( $id, $k, $v = '' ) { unset( $GLOBALS['kit_term_meta'][ (int) $id ][ $k ] ); return true; }
function has_post_thumbnail( $id = 0 ) { return false; }
function get_post_thumbnail_id( $id = 0 ) { return 0; }
function set_post_thumbnail( $id, $t ) { return true; }
function delete_post_thumbnail( $id ) { return true; }
function wp_get_attachment_image_url( $i, $s = '' ) { return ''; }
function wp_get_attachment_url( $i ) { return ''; }
function get_attached_file( $i ) { return ''; }
function get_transient( $k ) { return isset( $GLOBALS['kit_trans'][ $k ] ) ? $GLOBALS['kit_trans'][ $k ] : false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['kit_trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['kit_trans'][ $k ] ); return true; }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir(), 'baseurl' => 'https://x/uploads', 'path' => sys_get_temp_dir(), 'url' => 'https://x' ); }
function wp_editor( $content, $id, $s = array() ) { echo '<textarea name="' . esc_attr( isset( $s['textarea_name'] ) ? $s['textarea_name'] : $id ) . '" id="' . esc_attr( $id ) . '">' . esc_textarea( $content ) . '</textarea>'; }

/* ---- Terms. Two of everything, unless a test says the site has none. ---- */
function kit_fake_terms( $tax ) {
    if ( 'uc_organizer' === $tax && empty( $GLOBALS['kit_organizers'] ) ) { return array(); }
    $o = array();
    foreach ( array( 11 => 'Alpha', 12 => 'Beta' ) as $id => $n ) {
        $t = new WP_Term(); $t->term_id = $id; $t->term_taxonomy_id = $id; $t->name = $n; $t->slug = strtolower( $n ); $t->taxonomy = (string) $tax;
        if ( isset( $GLOBALS['kit_term_desc'][ $id ] ) && 'uc_series' === $tax ) { $t->description = $GLOBALS['kit_term_desc'][ $id ]; }
        $o[] = $t;
    }
    return $o;
}
function get_terms( $a = array(), $b = null ) {
    $tax = is_array( $a ) ? ( isset( $a['taxonomy'] ) ? $a['taxonomy'] : 'x' ) : $a;
    if ( is_array( $tax ) ) { $tax = reset( $tax ); }
    $r = kit_fake_terms( $tax );
    if ( is_array( $a ) && isset( $a['fields'] ) && 'ids' === $a['fields'] ) { return wp_list_pluck( $r, 'term_id' ); }
    return $r;
}
function get_term( $id, $tax = '' ) {
    foreach ( array( 'uc_event_category', 'uc_organizer', 'uc_series', 'uc_venue', (string) $tax ) as $t ) {
        foreach ( kit_fake_terms( $t ) as $term ) { if ( (int) $term->term_id === (int) $id && ( '' === $tax || $t === $tax ) ) { return $term; } }
    }
    return null;
}
function get_term_by( $f, $v, $tax = '' ) { foreach ( kit_fake_terms( $tax ) as $t ) { if ( ( 'slug' === $f && $t->slug === $v ) || ( 'id' === $f && (int) $t->term_id === (int) $v ) ) { return $t; } } return false; }
function term_exists( $t, $tax = '' ) { return get_term( $t, $tax ) ? array( 'term_id' => (int) $t ) : null; }
function wp_get_post_terms( $id, $tax = '', $a = array() ) {
    $ids = isset( $GLOBALS['kit_terms'][ (int) $id ][ $tax ] ) ? $GLOBALS['kit_terms'][ (int) $id ][ $tax ] : array();
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { return $ids; }
    $out = array(); foreach ( $ids as $tid ) { $t = get_term( $tid, $tax ); if ( $t ) { $out[] = $t; } } return $out;
}
function wp_get_object_terms( $id, $tax, $a = array() ) { return wp_get_post_terms( is_array( $id ) ? reset( $id ) : $id, is_array( $tax ) ? reset( $tax ) : $tax, $a ); }
function get_the_terms( $id, $tax ) { $t = wp_get_post_terms( $id, $tax ); return $t ? $t : false; }
function wp_set_object_terms( $id, $terms, $tax, $append = false ) { $GLOBALS['kit_terms'][ (int) $id ][ $tax ] = array_values( array_map( 'intval', (array) $terms ) ); return $GLOBALS['kit_terms'][ (int) $id ][ $tax ]; }
function wp_set_post_terms( $id, $terms, $tax, $append = false ) { return wp_set_object_terms( $id, $terms, $tax, $append ); }
function get_term_link( $t, $tax = '' ) { return 'https://resources.sfaf.org/term/' . ( is_object( $t ) ? $t->slug : $t ) . '/'; }

/* ---- Everything else the renderers and the save call, answering null. ---- */
foreach ( array(
    'status_header', 'language_attributes', 'bloginfo', 'wp_print_styles', 'wp_print_head_scripts',
    'wp_print_footer_scripts', 'sanitize_html_class', 'wp_enqueue_media', 'wp_enqueue_editor',
    'sanitize_hex_color', 'wp_logout_url', 'wp_print_media_templates', 'submit_button', 'rest_url', 'settings_fields',
    'get_the_date',
) as $kit_fn ) {
    if ( ! function_exists( $kit_fn ) ) { eval( 'function ' . $kit_fn . '( ...$a ) { return null; }' ); }
}

require $GLOBALS['kit_root'] . '/sfaf-calendar.php';

/** A private method, called. */
function kit_call( $class, $method, $obj = null, $args = array() ) {
    $m = new ReflectionMethod( $class, $method );
    $m->setAccessible( true );
    return $m->invokeArgs( $obj, $args );
}
