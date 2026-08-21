<?php
/**
 * DOES THE PUBLISH WARNING FIRE ON FIELDS THAT ARE ACTUALLY EMPTY?
 *
 *     php .claude/completeness-test.php
 *     php .claude/completeness-test.php --self-test
 *
 * WHY THIS FILE EXISTS. 3.49.1. A manager opened a pending GoFundMe Pro event,
 * wrote a description, chose a category and chose an organizer, pressed Publish,
 * and was told the event still needed all three.
 *
 * THIS IS THE FOURTH TIME A CHECK HAS READ SOMETHING OTHER THAN WHAT THE
 * INTERFACE WRITES, so nothing here searches source for a string.
 *
 * The live check reads the form through `[name="..."]` selectors taken from
 * SFAF_Sources::completeness_fields(). That list held the PHP key, not the name
 * attribute, and those are the same string for every field EXCEPT the two that
 * take several values: the controls are `category[]` and `organizer[]`, so
 * `[name="category"]` matched nothing at all. The check then took its "not on
 * this form" branch and answered from stored state, which on an unsaved import
 * is empty. The description failed separately and for its own reason: it is a
 * TinyMCE textarea, and TinyMCE does not write back to the textarea until
 * submit, so `.value` was whatever the page loaded with.
 *
 * SO THERE ARE TWO ASSERTIONS HERE AND THEY CATCH DIFFERENT THINGS.
 *
 *   1. THE NAMES ARE REAL. Every input named in completeness_fields() is
 *      rendered by the real manager controls, matched as a literal name
 *      attribute. This is the one that catches `category` against `category[]`,
 *      and it has to come from rendered markup because the mismatch is
 *      invisible in either file on its own.
 *
 *   2. THE ENGINE ANSWERS CORRECTLY. The real JavaScript, sliced out of
 *      portal.js between its markers, is run over a form built from those same
 *      rendered controls. Fill all three and the warning must not fire; empty
 *      each in turn and it must fire naming THAT field and no other.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ . '/' );
define( 'SFAF_PLUGIN_URL', 'https://example.org/wp-content/plugins/sfaf-calendar/' );
define( 'SFAF_VERSION', '0.0.0-test' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
date_default_timezone_set( 'America/Los_Angeles' );

$fails = array();
$self  = in_array( '--self-test', $argv, true );
function fail( $m ) { global $fails; $fails[] = $m; }

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Only enough to render the manager controls.
 * ------------------------------------------------------------------------ */
$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();
$GLOBALS['terms'] = array();

function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url( $t ) { return (string) $t; }
function esc_url_raw( $t, $p = null ) { return (string) $t; }
function esc_textarea( $t ) { return esc_html( $t ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function __( $t, $d = '' ) { return $t; }
function _e( $t, $d = '' ) { echo $t; }
function _n( $s, $p, $n, $d = '' ) { return ( 1 === (int) $n ) ? $s : $p; }
function _x( $t, $c, $d = '' ) { return $t; }
function apply_filters( $tag, $value ) { return $value; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function remove_filter( $h, $c, $p = 10 ) {}
function do_action( $h ) {}
function absint( $n ) { return abs( (int) $n ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( (string) $s ) ) ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#[0-9a-f]{3,6}$/i', (string) $c ) ? $c : ''; }
function wp_unslash( $v ) { return $v; }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : date( $type ); }
function get_option( $n, $d = false ) { return $d; }
function update_option( $n, $v, $a = null ) { return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function home_url( $p = '', $s = null ) { return 'https://example.org' . $p; }
function admin_url( $p = '' ) { return 'https://example.org/wp-admin/' . $p; }
function add_query_arg( $a, $v = '', $u = '' ) {
    if ( ! is_array( $a ) ) { $a = array( $a => $v ); } else { $u = $v; }
    $out = $u;
    foreach ( $a as $k => $val ) { $out .= ( false === strpos( $out, '?' ) ? '?' : '&' ) . $k . '=' . rawurlencode( $val ); }
    return $out;
}
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_email( $e ) { return is_email( trim( (string) $e ) ) ? trim( (string) $e ) : ''; }
function wp_strip_all_tags( $t, $b = false ) { return trim( strip_tags( (string) $t ) ); }
function wp_kses_post( $t ) { return (string) $t; }
function wp_kses( $t, $a, $p = array() ) { return (string) $t; }
function wpautop( $t, $br = true ) { return (string) $t; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
function checked( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' checked' : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' selected' : ''; if ( $e ) { echo $r; } return $r; }
function disabled( $a, $b = true, $e = true ) { return ''; }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { return ''; }
function wp_create_nonce( $a = -1 ) { return 'n'; }
function wp_verify_nonce( $n, $a = -1 ) { return 1; }
function wp_enqueue_media( $a = array() ) {}
function wp_enqueue_editor() {}
function wp_enqueue_script( $h, $s = '', $d = array(), $v = false, $f = false ) {}
function wp_enqueue_style( $h, $s = '', $d = array(), $v = false, $m = 'all' ) {}

/**
 * THE EDITOR, RENDERED AS WordPress RENDERS IT: a <textarea> carrying the
 * name, which TinyMCE then takes over. That textarea is the whole subject of
 * half this file, so it has to be here rather than stubbed away.
 */
function wp_editor( $content, $id, $settings = array() ) {
    printf(
        '<textarea id="%s" name="%s" rows="%d">%s</textarea>',
        esc_attr( $id ),
        esc_attr( isset( $settings['textarea_name'] ) ? $settings['textarea_name'] : $id ),
        (int) ( isset( $settings['textarea_rows'] ) ? $settings['textarea_rows'] : 10 ),
        esc_textarea( $content )
    );
}
function wp_list_pluck( $list, $field ) {
    $out = array();
    foreach ( (array) $list as $item ) { $out[] = is_object( $item ) ? $item->$field : $item[ $field ]; }
    return $out;
}
function get_post_meta( $id, $k, $single = false ) {
    return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : '';
}
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); return true; }
function get_the_title( $id = 0 ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]['post_title'] : ''; }
function get_post_field( $f, $id ) { return isset( $GLOBALS['posts'][ $id ][ $f ] ) ? $GLOBALS['posts'][ $id ][ $f ] : ''; }
function get_post_status( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]['post_status'] : false; }
function get_post_type( $id = 0 ) { return isset( $GLOBALS['posts'][ $id ] ) ? 'uc_event' : false; }
function get_post( $id = 0 ) {
    if ( ! isset( $GLOBALS['posts'][ $id ] ) ) { return null; }
    return (object) array_merge( array( 'ID' => (int) $id, 'post_type' => 'uc_event' ), $GLOBALS['posts'][ $id ] );
}
function get_permalink( $id = 0 ) { return 'https://example.org/e/' . (int) $id; }
function get_the_date( $f = '', $id = 0 ) { return 'U' === $f ? time() : date( 'Y-m-d' ); }
function get_userdata( $id ) { return false; }
function has_post_thumbnail( $id = 0 ) { return false; }
function get_post_thumbnail_id( $id = 0 ) { return 0; }
function wp_get_attachment_image_url( $id, $s = 'thumbnail' ) { return ''; }
function wp_get_post_terms( $id, $tax, $args = array() ) { return array(); }
function wp_get_object_terms( $id, $tax, $args = array() ) {
    return isset( $GLOBALS['terms'][ $id ][ $tax ] ) ? $GLOBALS['terms'][ $id ][ $tax ] : array();
}
function get_terms( $a = array() ) {
    $tax = isset( $a['taxonomy'] ) ? $a['taxonomy'] : '';
    return isset( $GLOBALS['all_terms'][ $tax ] ) ? $GLOBALS['all_terms'][ $tax ] : array();
}
function get_term( $id, $tax = '' ) { return null; }
function get_term_by( $f, $v, $tax ) { return false; }
function get_term_meta( $id, $k, $single = false ) { return ''; }
function taxonomy_exists( $t ) { return true; }
function is_wp_error( $t ) { return ( $t instanceof WP_Error ); }
function sfaf_prime_rsvp_counts( $ids ) {}
function sfaf_get_rsvp_count( $id ) { return 0; }
function sfaf_event_categories( $id ) { return array(); }
function sfaf_ap_date( $d, $f = 'full' ) { return (string) $d; }
function sfaf_ap_time_range( $a, $b ) { return $a . ' to ' . $b; }
function sfaf_icon( $n, $a = array() ) { return ''; }
function sfaf_event_image_url( $id, $s = 'large' ) { return ''; }
function sfaf_event_image_source( $id ) { return 'none'; }
function sfaf_event_location_short( $id ) { return ''; }
function sfaf_fundraising_progress_meta_key() { return '_uc_show_fund_progress'; }
function sfaf_category_shades( $hex ) { return array( 'ink' => '#0C666F', 'tint' => '#E3F7F9', 'media' => '#D5F3F6' ); }
function _prime_post_caches( $ids, $a = true, $b = true ) {}
function sfaf_flatten_html( $html ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $html ) ) ); }
function sfaf_help( $id, $text, $what = '' ) { return ''; }
function sfaf_category_color( $term_id ) { return '#16BECF'; }
function sfaf_category_icon( $term_id ) { return ''; }
function sanitize_html_class( $c, $f = '' ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function wp_upload_dir( $t = null, $c = true ) { return array( 'basedir' => '/tmp', 'baseurl' => 'https://example.org/u', 'error' => false ); }

define( 'TEST_ADMIN_ID', 7 );
function user_can( $user, $cap ) { return true; }
function current_user_can( $cap ) { return true; }
function get_user_meta( $id, $k = '', $single = false ) { return ''; }
function get_users( $args = array() ) { return array(); }
function wp_get_current_user() { return new WP_User( TEST_ADMIN_ID ); }
function get_current_user_id() { return TEST_ADMIN_ID; }
function is_user_logged_in() { return true; }

class WP_Error { public function __construct( $c = '', $m = '' ) {} }
class WP_User {
    public $ID = 0;
    public $display_name = 'Mark';
    public $user_email = 'mark@sfaf.org';
    public $first_name = 'Mark';
    public function __construct( $id = 0 ) { $this->ID = (int) $id; }
}
class WP_Query {
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 1;
    public $post_count = 0;
    public function __construct( $q = array() ) {}
}
class SFAF_Teams { public static function events_for_user( $uid ) { return array(); } }
class SFAF_Reminders { const NOTIFY_EMAILS_META = '_uc_notify_emails'; }
class SFAF_Search { public static function apply( &$q, $s ) {} }
class SFAF_Series {
    const TAXONOMY = 'uc_series';
    public static function all() { return array(); }
    public static function for_event( $id ) { return null; }
    public static function id_for_event( $id ) { return 0; }
    public static function image_url( $tid, $s = 'medium' ) { return ''; }
}
/* Enough of the neighbours for the four manager controls to render. None of
 * them decides whether a field counts as filled, which is all this file asks. */
class SFAF_Media_Folder {
    const FOLDER = 'calendar';
    const FLAG = 'uc_calendar_media';
    public static function has_any() { return false; }
}
class SFAF_Organizers {
    public static function all() { return isset( $GLOBALS['all_terms']['uc_organizer'] ) ? $GLOBALS['all_terms']['uc_organizer'] : array(); }
    public static function for_event( $id ) { return wp_get_object_terms( $id, 'uc_organizer' ); }
    public static function names_for_event( $id ) { return array(); }
    public static function event_count( $tid ) { return 0; }
}
class SFAF_Privacy {
    public static function is_private( $id ) { return false; }
    public static function set( $id, $on ) { return true; }
}

require_once $root . '/includes/class-sfaf-request.php';
require_once $root . '/includes/class-sfaf-uploads.php';
require_once $root . '/includes/class-sfaf-submissions.php';
require_once $root . '/includes/class-sfaf-submit.php';
require_once $root . '/includes/class-sfaf-rich-text.php';
require_once $root . '/includes/class-sfaf-sources.php';
require_once $root . '/includes/class-sfaf-source-gfmp.php';
require_once $root . '/includes/class-sfaf-portal.php';

/* ---------------------------------------------------------------------------
 * The world: one GoFundMe Pro campaign in the import queue, with the two
 * taxonomies holding terms so the checkbox groups have something to render.
 * ------------------------------------------------------------------------ */
$gfmp = new SFAF_Source_GFMP();
SFAF_Sources::register_adapter( $gfmp );
/* THE SLUG IS READ OFF THE ADAPTER, never spelled here. It is "gofundme_pro"
 * and the class is SFAF_Source_GFMP, and writing the short one into this file
 * is how the first run of it watched zero fields and passed. */
define( 'GFMP_SLUG', $gfmp->slug() );

define( 'EVENT_ID', 501 );
$GLOBALS['posts'][ EVENT_ID ] = array(
    'post_title'   => 'Imported campaign',
    'post_content' => '',
    'post_status'  => 'uc_imported',
    'post_author'  => 0,
);
$GLOBALS['meta'][ EVENT_ID ] = array( SFAF_Sources::META_SOURCE => GFMP_SLUG );
$GLOBALS['all_terms'] = array(
    'uc_event_category' => array(
        (object) array( 'term_id' => 11, 'name' => 'Support Groups', 'slug' => 'support-groups' ),
        (object) array( 'term_id' => 12, 'name' => 'Workshops',      'slug' => 'workshops' ),
    ),
    'uc_organizer' => array(
        (object) array( 'term_id' => 21, 'name' => 'Stonewall Project', 'slug' => 'stonewall' ),
    ),
);

/* The adapter has to be REGISTERED, exactly as the plugin registers it at
 * boot. manager_fields_for() asks the adapter, so an unregistered one means
 * zero watched fields and a test that passes by testing nothing. The
 * --self-test asserts the count for that reason. */

$admin  = new WP_User( TEST_ADMIN_ID );
$portal = new SFAF_Portal();

/* =========================================================================
 * 1. EVERY NAME THE CHECK LOOKS FOR IS A NAME THE EDITOR ACTUALLY EMITS.
 *
 * THE ONE ASSERTION THAT CATCHES THE 3.49.1 FAULT. `category` and `category[]`
 * are both perfectly reasonable strings and neither file is wrong on its own;
 * only the rendered control settles which one the browser has to match. So the
 * real renderer is called and the real attributes are read back out of it.
 * ====================================================================== */
$ctx_method = new ReflectionMethod( 'SFAF_Portal', 'manager_panel_context' );
$ctx_method->setAccessible( true );
$ctx = $ctx_method->invoke( $portal, $admin, EVENT_ID, 'editor' );

$render = new ReflectionMethod( 'SFAF_Portal', 'render_manager_control' );
$render->setAccessible( true );

$fields_def = SFAF_Sources::completeness_fields();

/** Every control the real renderer emits for one field: name, type, id. */
function controls_for( $field ) {
    global $render, $portal, $ctx;
    ob_start();
    try {
        $render->invoke( $portal, $field, $ctx );
    } catch ( Throwable $e ) {
        ob_end_clean();
        fail( "rendering the $field control threw: " . $e->getMessage() );
        return array();
    }
    $html = ob_get_clean();

    $out = array();
    if ( preg_match_all( '/<(input|select|textarea)\b([^>]*)>/i', $html, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $tag ) {
            $attrs = $tag[2];
            if ( ! preg_match( '/\bname="([^"]*)"/', $attrs, $n ) ) {
                continue;
            }
            $type = 'textarea';
            if ( 'input' === strtolower( $tag[1] ) ) {
                $type = preg_match( '/\btype="([^"]*)"/', $attrs, $t ) ? strtolower( $t[1] ) : 'text';
            } elseif ( 'select' === strtolower( $tag[1] ) ) {
                $type = 'select-one';
            }
            $out[] = array(
                'name' => $n[1],
                'type' => $type,
                'id'   => preg_match( '/\bid="([^"]*)"/', $attrs, $i ) ? $i[1] : '',
            );
        }
    }
    return $out;
}

/* Only the fields a GoFundMe Pro campaign leaves to a manager, which is what
 * the warning is ever about. Read from the adapter, not listed here. */
$manager_fields = SFAF_Sources::manager_fields_for( GFMP_SLUG );
$watched        = array();
$rendered       = array();

foreach ( $manager_fields as $field ) {
    if ( ! isset( $fields_def[ $field ] ) ) {
        continue; // not a completeness field, e.g. fundraising_progress
    }
    $controls = controls_for( $field );
    $rendered[ $field ] = $controls;
    $names = array_column( $controls, 'name' );

    if ( empty( $controls ) ) {
        fail( "the $field control renders no input at all, so nothing can fill it" );
        continue;
    }

    $matched = array_intersect( $fields_def[ $field ]['inputs'], $names );
    if ( empty( $matched ) ) {
        fail( sprintf(
            'completeness_fields() looks for %s on the %s field, and the editor emits %s. '
                . 'The browser matches on the name attribute, so it finds nothing and falls back to stored state',
            implode( ' or ', array_map( function ( $s ) { return '[name="' . $s . '"]'; }, $fields_def[ $field ]['inputs'] ) ),
            $field,
            implode( ', ', array_unique( $names ) )
        ) );
        continue;
    }

    $watched[] = array(
        'field'  => $field,
        'phrase' => $fields_def[ $field ]['phrase'],
        'inputs' => array_values( $fields_def[ $field ]['inputs'] ),
        'filled' => false, // nothing is saved on a fresh import: this is the trap
    );
}

/* And the derivation back to the POST key must be the one the save reads. */
foreach ( array( 'category[]' => 'category', 'organizer[]' => 'organizer', 'description' => 'description' ) as $name => $key ) {
    if ( SFAF_Sources::post_key_for_input( $name ) !== $key ) {
        fail( "post_key_for_input('$name') does not give '$key', so the two halves of the list disagree" );
    }
}

/* =========================================================================
 * 2. THE REAL ENGINE, OVER THOSE REAL CONTROLS.
 *
 * The JavaScript is sliced out of portal.js between its markers rather than
 * restated here, for the reason the recurrence cross-check slices its engine:
 * a second copy is a copy that passes while the shipped one is broken.
 * ====================================================================== */
$js_src = file_get_contents( $root . '/public/js/portal.js' );
$start  = strpos( $js_src, '/* --- completeness engine start' );
$end    = strpos( $js_src, '/* --- completeness engine end' );
if ( false === $start || false === $end || $end < $start ) {
    fail( 'the completeness engine markers are missing from public/js/portal.js, so nothing was run' );
    $engine = '';
} else {
    $engine = substr( $js_src, $start, $end - $start );
}

$results = array();
if ( '' !== $engine && empty( $fails ) ) {
    $harness = <<<'JS'
/* A DOM only as far as the engine reaches into one: a form that can be asked
 * for controls by name, and controls that answer .value, .checked and .type. */
global.window = global.window || {};

function makeForm(controls) {
    return {
        querySelectorAll: function (sel) {
            var m = /^\[name="(.*)"\]$/.exec(sel);
            if (!m) { return []; }
            return controls.filter(function (c) { return c.name === m[1]; });
        }
    };
}

var SPEC = JSON.parse(require("fs").readFileSync(process.argv[2], "utf8"));
var out = [];

SPEC.cases.forEach(function (kase) {
    var controls = SPEC.controls.map(function (c) {
        var state = kase.state[c.name];
        return {
            name: c.name,
            id: c.id,
            type: c.type,
            disabled: false,
            value: (c.type === 'checkbox' || c.type === 'radio') ? c.value : (state && state.value) || '',
            checked: (c.type === 'checkbox' || c.type === 'radio') ? !!(state && state.checked) : false
        };
    });

    /* TinyMCE, when the case says the editor is running. This is the half that
     * .value cannot answer: the textarea keeps the page-load value while the
     * editor holds what was typed. */
    if (kase.tinymce) {
        global.window.tinymce = {
            get: function (id) {
                var t = kase.tinymce[id];
                if (t === undefined) { return null; }
                return {
                    isHidden: function () { return false; },
                    getContent: function () { return t; }
                };
            }
        };
    } else {
        delete global.window.tinymce;
    }

    out.push({ name: kase.name, missing: missingFields(SPEC.fields, makeForm(controls)) });
});

process.stdout.write(JSON.stringify(out));
JS;

    /* Every control across every watched field, flattened, with a value for
     * the checkbox cases. */
    $all_controls = array();
    foreach ( $watched as $entry ) {
        foreach ( $rendered[ $entry['field'] ] as $c ) {
            if ( ! in_array( $c['name'], $entry['inputs'], true ) ) {
                continue;
            }
            $all_controls[] = array(
                'name'  => $c['name'],
                'type'  => $c['type'],
                'id'    => $c['id'],
                'value' => '11', // a term id, as a real checkbox carries
            );
        }
    }

    /* The states. "filled" means what a manager who has just typed and ticked
     * would have on screen: TinyMCE holding the description, boxes ticked. */
    $filled_state = array();
    $tinymce_full = array();
    foreach ( $all_controls as $c ) {
        $is_editor = ( 'textarea' === $c['type'] && '' !== $c['id'] );

        /*
         * A TinyMCE TEXTAREA STAYS STALE, AND MODELLING THAT IS THE WHOLE
         * POINT OF THIS CASE.
         *
         * TinyMCE keeps the content in its iframe and writes it back to the
         * textarea at submit. So a manager who has just typed a description has
         * the text in the EDITOR and the page-load value, empty, still sitting
         * on the textarea. Giving the textarea the typed value here would let a
         * check that reads `.value` pass, which is exactly the check that
         * shipped: planting that fault against the first version of this file
         * was not caught, and this is the line that fixes it.
         */
        $filled_state[ $c['name'] ] = array(
            'value'   => $is_editor ? '' : 'something',
            'checked' => true,
        );
        if ( $is_editor ) {
            $tinymce_full[ $c['id'] ] = 'A real description, typed into the editor.';
        }
    }

    $cases = array(
        array( 'name' => 'everything filled', 'state' => $filled_state, 'tinymce' => $tinymce_full ),
    );

    /* Then empty exactly one field at a time. */
    foreach ( $watched as $entry ) {
        $state   = $filled_state;
        $tiny    = $tinymce_full;
        foreach ( $all_controls as $c ) {
            if ( ! in_array( $c['name'], $entry['inputs'], true ) ) {
                continue;
            }
            $state[ $c['name'] ] = array( 'value' => '', 'checked' => false );
            if ( '' !== $c['id'] && isset( $tiny[ $c['id'] ] ) ) {
                $tiny[ $c['id'] ] = '';
            }
        }
        $cases[] = array( 'name' => 'only ' . $entry['field'] . ' empty', 'state' => $state, 'tinymce' => $tiny );
    }

    $spec = array( 'fields' => $watched, 'controls' => $all_controls, 'cases' => $cases );

    $tmp = sys_get_temp_dir() . '/sfaf-completeness-' . getmypid() . '.js';
    file_put_contents( $tmp, $engine . "\n" . $harness );

    /* THE SPEC GOES THROUGH A FILE. Passed as an argument it is a JSON
     * string on a Windows command line, and the quotes do not survive the
     * trip: node received { fields :[]} and could not parse it. */
    $spec_file = sys_get_temp_dir() . '/sfaf-completeness-spec-' . getmypid() . '.json';
    file_put_contents( $spec_file, wp_json_encode( $spec ) );
    $cmd = 'node ' . escapeshellarg( $tmp ) . ' ' . escapeshellarg( $spec_file ) . ' 2>&1';
    $raw = shell_exec( $cmd );
    @unlink( $tmp );
    @unlink( $spec_file );

    $results = json_decode( (string) $raw, true );
    if ( ! is_array( $results ) ) {
        fail( 'the engine did not run under node: ' . trim( (string) $raw ) );
        $results = array();
    }
}

/* --- What each case must have answered. -------------------------------- */
$by_name = array();
foreach ( $results as $r ) {
    $by_name[ $r['name'] ] = $r['missing'];
}

if ( isset( $by_name['everything filled'] ) ) {
    if ( ! empty( $by_name['everything filled'] ) ) {
        fail( sprintf(
            'the warning fires on fields that are filled in: it still asks for %s. This is the 3.49.1 fault',
            implode( ', ', $by_name['everything filled'] )
        ) );
    }
} elseif ( empty( $fails ) ) {
    fail( 'the "everything filled" case did not run at all' );
}

foreach ( $watched as $entry ) {
    $key = 'only ' . $entry['field'] . ' empty';
    if ( ! isset( $by_name[ $key ] ) ) {
        continue;
    }
    $got = $by_name[ $key ];

    if ( ! in_array( $entry['phrase'], $got, true ) ) {
        fail( sprintf( 'with %s empty the warning does not ask for it', $entry['field'] ) );
    }
    foreach ( $watched as $other ) {
        if ( $other['field'] === $entry['field'] ) {
            continue;
        }
        if ( in_array( $other['phrase'], $got, true ) ) {
            fail( sprintf(
                'with only %s empty the warning also asks for %s, which is filled in',
                $entry['field'], $other['field']
            ) );
        }
    }
}

/* --- And the sentence itself, which the manager actually reads. --------- */
$portal_src = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
if ( preg_match( '/\$confirm_tpl\s*=\s*\'([^\']*)\'/', $portal_src, $m ) ) {
    $tpl = $m[1];
    if ( false === strpos( $tpl, '%s' ) ) {
        fail( 'the publish confirmation names no fields' );
    }
    if ( false === stripos( $tpl, 'Publish anyway?' ) ) {
        fail( 'the publish confirmation does not offer the choice it is asking about' );
    }
    /* CLAUDE.md §6: a sentence that justifies why the software cannot fill a
     * field in tells nobody what to do or what will happen to them. */
    foreach ( array( 'cannot supply', 'stays empty', 'until somebody' ) as $gone ) {
        if ( false !== stripos( $tpl, $gone ) ) {
            fail( sprintf( 'the publish confirmation still explains itself: "%s"', $gone ) );
        }
    }
} else {
    fail( 'the publish confirmation template could not be found to check its wording' );
}

/* =========================================================================
 * --self-test: the harness must be able to fail.
 * ====================================================================== */
if ( $self ) {
    $bad = 0;
    echo "Self-test: does this file notice when things are wrong?\n\n";

    if ( '' !== $engine && false !== strpos( $engine, 'function controlFilled' ) ) {
        echo "ok       the engine really was sliced out of portal.js\n";
    } else {
        echo "BROKEN:  no engine was sliced, so section 2 ran nothing\n";
        $bad++;
    }

    if ( count( $watched ) >= 3 ) {
        echo "ok       " . count( $watched ) . " manager fields are being watched\n";
    } else {
        echo "BROKEN:  only " . count( $watched ) . " field(s) watched; the fault needed three\n";
        $bad++;
    }

    $has_box = false;
    $has_area = false;
    foreach ( $rendered as $cs ) {
        foreach ( $cs as $c ) {
            if ( 'checkbox' === $c['type'] ) { $has_box = true; }
            if ( 'textarea' === $c['type'] ) { $has_area = true; }
        }
    }
    if ( $has_box ) {
        echo "ok       a real checkbox group was rendered and read\n";
    } else {
        echo "BROKEN:  no checkbox rendered, so the multi-select half is untested\n";
        $bad++;
    }
    if ( $has_area ) {
        echo "ok       a real textarea was rendered, which is the TinyMCE half\n";
    } else {
        echo "BROKEN:  no textarea rendered, so the description half is untested\n";
        $bad++;
    }

    if ( count( $results ) === count( $watched ) + 1 ) {
        echo "ok       every case actually ran under node\n";
    } else {
        echo "BROKEN:  " . count( $results ) . " case(s) ran, expected " . ( count( $watched ) + 1 ) . "\n";
        $bad++;
    }

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the harness reads what it claims to read.\n" );
    exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "The publish warning, asked what it says about a form somebody filled in\n";
echo str_repeat( '=', 74 ) . "\n";
printf( "watched: %d manager fields on a GoFundMe Pro campaign\n", count( $watched ) );
foreach ( $watched as $entry ) {
    $names = array_unique( array_column( $rendered[ $entry['field'] ], 'name' ) );
    printf( "  %-12s looks for %-22s editor emits %s\n",
        $entry['field'], implode( ',', $entry['inputs'] ), implode( ', ', $names ) );
}
echo "\ncases:\n";
foreach ( $results as $r ) {
    printf( "  %-24s warns for: %s\n", $r['name'], $r['missing'] ? implode( ', ', $r['missing'] ) : '(nothing)' );
}
echo "\n";

if ( empty( $fails ) ) {
    echo "the warning names every empty field and no filled one.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
