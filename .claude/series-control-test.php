<?php
/**
 * THE SERIES CONTROL ON NEW EVENT, DECIDED BY LOOKING AT WHAT CAME OUT.
 *
 *     php .claude/series-control-test.php
 *     php .claude/series-control-test.php --self-test
 *
 * THE DEFECT THIS EXISTS FOR, AND WHY NOTHING CAUGHT IT FOR 26 RELEASES.
 *
 * render_event_form() called render_series_prefill( $all_series, $cur_series )
 * 102 lines ABOVE the two lines that assign them. Straight-line code, one
 * function, no loop. Both were undefined at the call, PHP passed null, and the
 * callee's `empty()` guard returned. The "Is this part of a series?" card has
 * never appeared on the New Event screen, and the only symptom was two
 * suppressed warnings and a control nobody could find.
 *
 * EVERY STATIC CHECK PASSED, AND WOULD PASS AGAIN. The call is there. The
 * method is there. The arity matches. The file parses. The callable audit is
 * clean. Nothing was wrong except the ORDER of two statements, and order is not
 * a property any of those questions can see.
 *
 * PROJECT.md §7 records three releases of exactly this shape: a test asserting
 * a PROPERTY OF THE CODE believed to imply the outcome, and never the outcome.
 * So the assertions here are written in the words of the outcome and decided by
 * RENDERING THE FORM AND READING WHAT CAME BACK:
 *
 *   1. Rendering New Event, with at least one series in existence, emits a
 *      control named "series".
 *   2. That control is a SINGLE select. Never multiple, never a checkbox list.
 *   3. Rendering New Event with ?series= naming a real term emits that term as
 *      the chosen option.
 *   4. Rendering Edit Event still emits exactly one such control.
 *
 * WHAT IS STUBBED CANNOT UPHOLD THE RULE UNDER TEST, which is the check 3.36.0
 * failed: a stub of wp_update_post() quietly kept the behaviour whose removal
 * was being tested. Here the rule is the order of two statements inside
 * render_event_form(), and that file is loaded and executed from source. No
 * fixture can make an undefined variable defined. SFAF_Series::all() returning
 * three invented terms decides only WHAT is in the list, never whether the list
 * reached the control.
 *
 * --self-test plants both faults in a copy of the source and proves the
 * assertions fail. Restoring the original ordering must fail assertion 1.
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
function expect( $label, $got, $want ) {
    if ( $got !== $want ) {
        fail( $label . ': got ' . var_export( $got, true ) . ', expected ' . var_export( $want, true ) );
    }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 *
 * THE ONE PART THAT MATTERS IS A WP_Query THAT READS post_status AND author.
 * Without both, every query returns the same rows and "the queue excluded it"
 * cannot be decided at all. The self-test proves it filters on each.
 * ------------------------------------------------------------------------ */
$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();

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
function sanitize_email( $e ) {
    $e = trim( (string) $e );
    return is_email( $e ) ? $e : '';
}
function wp_strip_all_tags( $t, $b = false ) { return trim( strip_tags( (string) $t ) ); }
function wp_kses_post( $t ) { return (string) $t; }
function wp_kses( $t, $a, $p = array() ) { return (string) $t; }
function wpautop( $t, $br = true ) { return (string) $t; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
/* ---------------------------------------------------------------------------
 * checked() AND selected() ARE REAL HERE, AND THE STUB THEY REPLACE WOULD HAVE
 * MADE ASSERTION 3 UNDECIDABLE.
 *
 * The version inherited from the queue test returned '' unconditionally, which
 * is harmless when nothing asks which option is chosen and fatal to a test whose
 * whole question is "does ?series=13 arrive as the CHOSEN option". Every option
 * would have come back unmarked and the assertion would have failed against a
 * correct screen, or, written the other way round, passed against a broken one.
 *
 * This is the shape the 3.36.0 stub had: a stand-in that quietly decides the
 * thing under test. Core compares loosely, so this does too, or a term id
 * arriving from a query string as the string "13" would not match the integer
 * 13 it is compared against, and the test would disagree with the browser.
 * ------------------------------------------------------------------------ */
function __checked_selected_helper( $a, $b, $echo, $type ) {
    // phpcs:ignore -- loose, exactly as WordPress does it.
    $out = ( (string) $a === (string) $b || $a == $b ) ? " $type='$type'" : '';
    if ( $echo ) { echo $out; }
    return $out;
}
function checked( $a, $b = true, $e = true ) { return __checked_selected_helper( $a, $b, $e, 'checked' ); }
function selected( $a, $b = true, $e = true ) { return __checked_selected_helper( $a, $b, $e, 'selected' ); }
/* disabled() joins them in 3.97.0: the Accept RSVPs control is rendered
 * disabled on a third-party event, and core's helper takes the same shape. */
function disabled( $a, $b = true, $e = true ) { return __checked_selected_helper( $a, $b, $e, 'disabled' ); }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { return ''; }
function wp_create_nonce( $a = -1 ) { return 'n'; }
function wp_verify_nonce( $n, $a = -1 ) { return 1; }
function wp_enqueue_media( $a = array() ) {}
function wp_enqueue_editor() {}
function wp_editor( $c, $id, $s = array() ) { echo '<textarea>' . esc_textarea( $c ) . '</textarea>'; }
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
/**
 * A post object carrying the three fields the orphan check reads.
 *
 * post_type WAS MISSING and event_is_orphaned() returns false the moment it
 * cannot confirm the type, so section 4 passed while testing nothing at all.
 * The two assertions that then failed are what said so, which is the reason
 * that section asserts a positive case as well as the two negatives.
 */
function get_post( $id = 0 ) {
    if ( ! isset( $GLOBALS['posts'][ $id ] ) ) {
        return null;
    }
    return (object) array_merge(
        array( 'ID' => (int) $id, 'post_type' => 'uc_event' ),
        $GLOBALS['posts'][ $id ]
    );
}
function get_permalink( $id = 0 ) { return 'https://example.org/e/' . (int) $id; }
function get_the_date( $f = '', $id = 0 ) { return 'U' === $f ? time() : date( 'Y-m-d' ); }
function get_userdata( $id ) { return false; }
function has_post_thumbnail( $id = 0 ) { return false; }
function get_post_thumbnail_id( $id = 0 ) { return 0; }
function wp_get_post_terms( $id, $tax, $args = array() ) { return array(); }
function wp_get_attachment_image_url( $id, $s = 'thumbnail' ) { return ''; }
function get_terms( $a = array() ) { return array(); }
function get_term( $id, $tax = '' ) { return null; }
function get_term_by( $f, $v, $tax ) { return false; }
function get_term_meta( $id, $k, $single = false ) { return ''; }
function taxonomy_exists( $t ) { return true; }
function is_wp_error( $t ) { return ( $t instanceof WP_Error ); }
function wp_reset_postdata() {}
function sfaf_prime_rsvp_counts( $ids ) {}
function sfaf_get_rsvp_count( $id ) { return 0; }
/* The real SFAF_Cron::local_time() formats through this and nothing else, so
   the stub does too. Formatting a date inline here instead put a human format
   outside the one formatter, which is exactly what date-callsite-sweep exists
   to forbid; it caught it, and 3.57.0 misread the catch. */
function _prime_post_caches( $ids, $a = true, $b = true ) {}

/* ---------------------------------------------------------------------------
 * What section 6 needs in order to RENDER the screen rather than query it.
 *
 * Everything here is chrome. None of it decides which row lands in which list,
 * which is the whole of what section 6 asserts, so each is the smallest thing
 * that lets the page finish drawing.
 * ------------------------------------------------------------------------ */
function date_i18n( $fmt, $ts = null, $gmt = false ) { return date( $fmt, null === $ts ? time() : (int) $ts ); }
/* No venue, no organizer, no category on any event in this world. The editor
   still draws every one of those controls; they are simply all unchosen. */
function get_the_terms( $post_id, $taxonomy ) { return false; }
function wp_timezone_string() { return 'America/Los_Angeles'; }
function wp_timezone() { return new DateTimeZone( wp_timezone_string() ); }
function wp_date( $format, $ts = null, $tz = null ) { return date( $format, null === $ts ? time() : $ts ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#[0-9a-f]{3,6}$/i', (string) $c ) ? $c : ''; }
function language_attributes( $doctype = 'html' ) { echo 'lang="en-US"'; }
function bloginfo( $show = '' ) { echo 'UTF-8'; }
function human_time_diff( $from, $to = 0 ) { return '1 hour'; }
function wp_get_attachment_image_src( $id, $size = 'thumbnail' ) { return false; }
function get_edit_post_link( $id = 0, $ctx = 'display' ) { return ''; }
function wp_upload_dir( $time = null, $create = true ) {
    return array( 'basedir' => '/tmp/uploads', 'baseurl' => 'https://example.org/uploads', 'error' => false );
}
/* The portal builds its own document and prints by hand whatever the media
 * library and the editor enqueued. Nothing here is enqueued, so these print
 * nothing, which is the correct answer rather than a silenced one. */
function wp_print_styles( $h = '' ) {}
function wp_print_head_scripts() {}
function wp_print_footer_scripts() {}
function wp_print_media_templates() {}
function wp_enqueue_script( $h, $s = '', $d = array(), $v = false, $f = false ) {}
function wp_enqueue_style( $h, $s = '', $d = array(), $v = false, $m = 'all' ) {}
function wp_localize_script( $h, $n, $d ) {}
function wp_add_inline_script( $h, $d, $p = 'after' ) {}
function did_action( $h ) { return 0; }
function wp_logout_url( $r = '' ) { return 'https://example.org/logout'; }
function get_avatar_url( $u, $a = array() ) { return ''; }
function get_avatar( $u, $s = 96, $d = '', $alt = '', $a = array() ) { return ''; }

/*
 * WHO MARK IS. He is a WordPress administrator, which SFAF_Portal turns into
 * the calendar Admin role without any `_uc_calendar_role` meta, exactly as
 * PROJECT.md describes. That matters here: the queue is admin-only, so a
 * harness where nobody is an admin would test the wrong branch of every gate.
 */
define( 'TEST_ADMIN_ID', 7 );
function user_can( $user, $cap ) {
    $id = is_object( $user ) ? (int) $user->ID : (int) $user;
    return ( TEST_ADMIN_ID === $id );
}
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
    public $user_email = 'manager@sfaf.org';
    public function __construct( $id = 0 ) { $this->ID = (int) $id; }
}

/**
 * A WP_Query that reads the two things this file is about.
 *
 * post_status AND author, both honoured. A stub that ignored either would
 * return every row for every query and make each assertion below vacuous,
 * which is exactly the failure this project has already shipped.
 */
class WP_Query {
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 1;
    public $post_count = 0;

    public function __construct( $q = array() ) {
        $status = isset( $q['post_status'] ) ? (array) $q['post_status'] : array( 'publish' );
        $author = isset( $q['author'] ) ? (int) $q['author'] : null;

        foreach ( $GLOBALS['posts'] as $id => $p ) {
            if ( ! in_array( $p['post_status'], $status, true ) ) { continue; }
            if ( null !== $author && (int) $p['post_author'] !== $author ) { continue; }
            $this->posts[] = (object) array( 'ID' => $id );
        }
        $this->found_posts   = count( $this->posts );
        $this->post_count    = count( $this->posts );
        $this->max_num_pages = 1;
    }
}

/* The cancel card asks how many people would be told. Nobody is registered on
   any event here, so the answer is none, and no mail is composed either way. */
class SFAF_Announce {
    public static function registrants( $event_id ) { return array(); }
    public static function has_registrations( $event_id ) { return false; }
    public static function count_affected( $event_ids ) { return 0; }
    public static function cancelled( $event_ids ) { return 0; }
    public static function changed( $event_ids, $changes ) { return 0; }
}
/* Teams grant access; no team exists in this world, so every question about
   them has the same empty answer. None of these emits a control named series. */
class SFAF_Teams {
    public static function events_for_user( $uid ) { return array(); }
    public static function all() { return array(); }
    public static function members( $id ) { return array(); }
    public static function member_count( $id ) { return 0; }
    public static function size( $id ) { return 0; }
    public static function for_event( $id ) { return array(); }
    public static function access_for_event( $id ) { return array(); }
    public static function user_owns_event( $uid, $id ) { return false; }
}
/* THE REAL META KEY. Section 5 asserts that an address reaches the list the
 * notification system actually reads, so the key has to be the shipping one:
 * a stubbed constant would prove the address reached a string this file made
 * up. Only the constant is needed here, not the sending machinery. */
class SFAF_Reminders {
    /* THE REAL KEYS, copied from the shipping class. A key this file made up
       would prove an address reached a string this file invented. */
    const NOTIFY_USERS_META         = '_uc_notify_users';
    const NOTIFY_EMAILS_META        = '_uc_notify_emails';
    const NOTIFY_AUTHOR_OPTOUT_META = '_uc_notify_author_optout';
    public static function new_token() { return bin2hex( random_bytes( 16 ) ); }
    /* The notification card draws itself from these. Sending is not exercised
       here; who would be sent to is a question for alert-recipients-test.php. */
    public static function author_opted_out( $id ) { return false; }
    public static function enabled() { return true; }
    public static function log_for_event( $id ) { return array(); }
    public static function notify_list( $id ) { return array(); }
    public static function reply_to_for( $id ) { return 'events@sfaf.org'; }
    public static function reply_to_source( $id ) { return 'default'; }
}
/**
 * The import side, modelled rather than emptied.
 *
 * IT USED TO ANSWER "NOTHING" TO EVERYTHING, which was enough while this file
 * only asked query_events() a question. Section 6 renders the screen, and an
 * import queue that is always empty would render a list with no imports in it
 * and then assert, truthfully and uselessly, that no import was in the wrong
 * place. So queue_ids() reads the same $GLOBALS['posts'] the WP_Query stub
 * reads, and provenance() reads meta, which is where the real one reads it.
 */
class SFAF_Sources {
    const STATUS_PENDING   = 'uc_imported';
    const STATUS_DISMISSED = 'uc_dismissed';
    const META_REMOVED_AT  = '_uc_removed_at';
    const META_SOURCE      = '_uc_source';

    /*
     * REGISTRATIONS BELONG TO THE SOURCE (3.97.0). Modelled on the same meta
     * this stub already uses for provenance, so an event that reports a source
     * here also reports as registering there, and the two cannot disagree
     * inside this world.
     */
    public static function takes_rsvps_at_source( $id ) {
        return ( '' !== (string) get_post_meta( $id, self::META_SOURCE, true ) );
    }
    public static function registration_url( $id ) {
        if ( ! self::takes_rsvps_at_source( $id ) ) { return ''; }
        return 'https://example.org/campaign/' . (int) $id;
    }

    public static function provenance( $id ) {
        $source = (string) get_post_meta( $id, self::META_SOURCE, true );
        return array(
            'source'      => $source,
            'label'       => ( '' !== $source ) ? ucfirst( $source ) : '',
            'external_id' => '',
            'source_url'  => ( '' !== $source ) ? 'https://example.org/campaign/' . (int) $id : '',
            'image_url'   => '',
            'timezone'    => '',
            'imported_at' => (int) get_post_meta( $id, '_uc_imported_at', true ),
        );
    }

    public static function queue_ids( $status, $limit = 200 ) {
        $ids = array();
        foreach ( $GLOBALS['posts'] as $id => $p ) {
            if ( $p['post_status'] === $status ) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    public static function queue_count( $status ) { return count( self::queue_ids( $status ) ); }
    public static function missing_manager_fields( $id ) { return array(); }
    public static function field_phrase( $fields ) { return implode( ', ', (array) $fields ); }
    public static function active_adapters() { return array(); }
    public static function owned_fields_for( $source ) { return array(); }
    public static function manager_fields_for( $source ) { return array(); }
    public static function adapter( $s ) { return null; }
    public static function is_queued( $id ) { return in_array( get_post_status( $id ), array( self::STATUS_PENDING, self::STATUS_DISMISSED ), true ); }

    /* SETTABLE, AND EMPTY BY DEFAULT. Section 7 needs a build that has sources
       compiled in, because the last-run box does not render on one that has
       none. Every earlier section wants the empty answer it always had. */
    public static $built = array();
    public static function adapters() { return self::$built; }

    /* The live completeness check the editor ships to the browser. It decides
       which fields the save button warns about, and none of them is the series:
       an event with no series is complete, which is the whole reason a control
       for choosing one has to be visible rather than nagged about. */
    public static function completeness_fields( $id ) { return array(); }
    /* 3.99.0: the editor's asterisks. None of them is the series either. */
    public static function publish_fields() { return array(); }
    public static function completeness_payload( $id ) { return array( 'fields' => array() ); }
    public static function field_is_filled( $field, $id ) { return true; }
    public static function field_change_phrase( $a, $b ) { return ''; }
}

/*
 * THE RUN LOG, AS THE PENDING SCREEN SEES IT.
 *
 * Only what render_fetch_last_run() asks of SFAF_Cron. The real class is a
 * thousand lines about scheduling and alerting and none of it is this screen's
 * business: what the screen needs is one task row and two ways of saying a
 * time, and $fetch is that row set by hand so a state that takes a day of real
 * failures to reach can be rendered in a millisecond.
 */
class SFAF_Cron {
    public static $fetch = null;
    public static function task_last( $key ) { return ( 'fetch' === $key ) ? self::$fetch : null; }
    public static function ago( $ts ) { return $ts ? ( round( ( time() - $ts ) / 60 ) . ' mins ago' ) : 'never'; }
    public static function local_time( $ts ) { return $ts ? sfaf_ap_datetime( $ts ) : 'never'; }
}
/* The editor is enqueued by the screen, not exercised by it. */
class SFAF_Rich_Text {
    public static function enqueue() {}
    public static function sanitize( $t ) { return (string) $t; }
    public static function settings_json() { return "{}"; }
    public static function settings() { return array(); }
    /* A real <textarea> carrying the real name, because the reader below counts
       every control on the form and a description that posted nothing would
       quietly change what "one control named series" is being compared against. */
    public static function render( $id, $name, $content = '', $args = array() ) {
        echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">'
            . esc_textarea( $content ) . '</textarea>';
    }
    public static function deferred() { return ''; }
}
/* ---------------------------------------------------------------------------
 * The handful of template helpers the editor calls that the queue never did.
 * All of them are decoration or prose: none emits a form control, so none can
 * change the answer to "how many controls are named series".
 * ------------------------------------------------------------------------ */
function sanitize_html_class( $c, $f = '' ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }

/* The image picker asks whether the calendar folder holds anything, to decide
   which of two notes to print. Neither note is a form control. */
/* The image tags class, which image_picker_atts() asks whether this viewer
 * may upload. Tagging is not exercised here; what is needed is an answer. */
class SFAF_Media {
    const TAXONOMY = 'uc_series';
    const UNTAGGED = 'none';
    public static function can_upload( $user ) { return true; }
    public static function can_tag( $user ) { return true; }
    public static function tags_of( $id ) { return array(); }
    /* THE EVENT EDITOR'S PICTURE CHOOSER IS THIS PICKER FROM 3.94.0, not
     * wp.media. This harness is about the COMPLETENESS banner, not about
     * which pictures are offered, so the stub emits the one thing the checks
     * below need: a control that posts the field. Emitting nothing would make
     * "the image control renders no input at all" true and would be true for
     * the wrong reason. */
    public static function picker( $args = array() ) {
        $name   = isset( $args['name'] ) ? $args['name'] : 'image_id';
        $chosen = isset( $args['chosen'] ) ? (int) $args['chosen'] : 0;
        echo '<details class="uc-picker uc-image-picker" data-uc-image-picker>'
            . '<summary class="uc-picker-toggle">Choose a picture</summary>'
            . '<label class="uc-check uc-picker-option uc-image-option">'
            . '<input type="radio" name="' . esc_attr( $name ) . '" value="' . $chosen . '"'
            . ( $chosen ? ' checked' : '' ) . ' data-uc-image-option /></label>'
            . '</details>';
    }
}
class SFAF_Media_Folder {
    const FOLDER = 'sfaf-calendar';
    const FLAG   = 'uc_in_calendar_folder';
    public static function has_any() { return true; }
}
/* Privacy is a property of the event, not of the queue. The panel reads it. */
class SFAF_Privacy {
    public static function is_private( $id ) { return false; }
    public static function set( $id, $on ) { return true; }
}
class SFAF_Search {
    public static function apply( &$q, $s ) {}
}
/* ---------------------------------------------------------------------------
 * THE SERIES LIST, INVENTED.
 *
 * THIS CANNOT UPHOLD THE RULE UNDER TEST, which is the one thing a stub in this
 * position has to be checked for. The rule is the ORDER of two statements
 * inside render_event_form(). all() returning three terms decides only what is
 * IN the control; it cannot decide whether the control was drawn, because a
 * variable assigned after it is read is undefined whatever this returns. If
 * anything, it makes the test harsher: with three real terms available there is
 * no innocent reason for an empty list.
 * ------------------------------------------------------------------------ */
class SFAF_Series {
    const TAXONOMY = 'uc_series';

    /** term_id => name. Three, so "the list is empty" cannot pass by accident. */
    public static $terms = array(
        11 => 'Monday Support Group',
        12 => 'Positive Force',
        13 => 'Wellness Wednesdays',
    );

    /** post_id => term_id, for the events in the world below. */
    public static $of_event = array();

    public static function all() {
        $out = array();
        foreach ( self::$terms as $id => $name ) {
            $out[] = (object) array( 'term_id' => $id, 'name' => $name, 'slug' => sanitize_title( $name ) );
        }
        return $out;
    }

    public static function get( $term_id ) {
        $term_id = (int) $term_id;
        return isset( self::$terms[ $term_id ] )
            ? (object) array( 'term_id' => $term_id, 'name' => self::$terms[ $term_id ], 'slug' => sanitize_title( self::$terms[ $term_id ] ) )
            : null;
    }

    public static function exists( $term_id ) { return null !== self::get( $term_id ); }

    public static function for_event( $post_id ) {
        $id = isset( self::$of_event[ (int) $post_id ] ) ? (int) self::$of_event[ (int) $post_id ] : 0;
        return $id ? self::get( $id ) : null;
    }

    public static function id_for_event( $post_id ) {
        $t = self::for_event( $post_id );
        return $t ? (int) $t->term_id : 0;
    }

    /** No series in this world carries a video; the editor still asks. */
    public static function video( $term_id ) {
        return '';
    }

    public static function name_for_event( $post_id ) {
        $t = self::for_event( $post_id );
        return $t ? $t->name : '';
    }

    /* What the prefill card offers to copy into the blank form. The shape is
       what matters here, not the values. */
    public static function prefill_data( $term_id ) {
        return array(
            'name'           => self::$terms[ (int) $term_id ] ?? '',
            'description'    => '',
            'location'       => '',
            'start'          => '',
            'end'            => '',
            'categories'     => array(),
            'category_names' => '',
            'organizers'     => array(),
            'organizer_name' => '',
        );
    }

    public static function editable_statuses() { return array( 'publish', 'draft', 'pending' ); }
    public static function events( $term_id, $args = array() ) { return array(); }
    public static function url( $term_id ) { return 'https://example.org/event-series/x/'; }
    public static function default_faq_set( $term_id ) { return ''; }
    public static function image_url( $term_id, $size = 'large' ) { return ''; }
    public static function recurrence_group( $term_id ) { return ''; }
    public static function upcoming_count( $term_id ) { return 0; }
    public static function set_for_event( $post_id, $term_id ) { self::$of_event[ (int) $post_id ] = (int) $term_id; }
}

/* ---------------------------------------------------------------------------
 * REAL WHERE IT CAN BE REAL.
 *
 * These are loaded from source rather than stubbed, because every one of them
 * is logic over the storage already stubbed above, and a hand-written stand-in
 * for a class the editor genuinely calls is a second copy of that class to keep
 * in step. Recurrence in particular decides whether a bulk scope appears, which
 * is one of the things this release must not disturb.
 * ------------------------------------------------------------------------ */
require_once $root . '/includes/sfaf-template-functions.php';
require_once $root . '/includes/class-sfaf-recurrence.php';
require_once $root . '/includes/class-sfaf-categories.php';
require_once $root . '/includes/class-sfaf-organizers.php';
require_once $root . '/includes/class-sfaf-venues.php';
require_once $root . '/includes/class-sfaf-faq-sets.php';
require_once $root . "/includes/class-sfaf-video.php";
require_once $root . '/includes/class-sfaf-online.php';
require_once $root . '/includes/class-sfaf-cancellation.php';
require_once $root . '/includes/class-sfaf-notifications.php';
require_once $root . '/includes/class-sfaf-request.php';
require_once $root . '/includes/class-sfaf-uploads.php';
/* Pictures inside a description (3.96.0): the editor draws the chooser, so
 * the class has to exist for the form to render at all. The real one, which
 * needs nothing at load time. */
require_once $root . '/includes/class-sfaf-desc-images.php';
require_once $root . '/includes/class-sfaf-submissions.php';
require_once $root . '/includes/class-sfaf-submit.php';
require_once $root . '/includes/class-sfaf-portal.php';

/* ===========================================================================
 * THE WORLD. One admin, one existing event, and three series.
 * ======================================================================== */

$MARK = TEST_ADMIN_ID;

$GLOBALS['posts'] = array(
    /* An existing event, so the EDIT path can be rendered too. The edit
       control works today and this release must not disturb it. */
    201 => array( 'post_title' => 'An event that already exists', 'post_status' => 'publish', 'post_author' => $MARK ),
);
$GLOBALS['meta'] = array(
    201 => array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+20 days' ) ) ),
);
SFAF_Series::$of_event = array( 201 => 12 );

$admin  = new WP_User( $MARK );
$portal = new SFAF_Portal();

$render = new ReflectionMethod( 'SFAF_Portal', 'render_event_form' );
$render->setAccessible( true );

/**
 * Render the event editor and hand back the HTML.
 *
 * THE WHOLE POINT OF THIS FUNCTION. Nothing below reads the source of
 * render_event_form(); everything reads what it PRINTED. A grep for
 * "render_series_prefill" would have passed on every release since 3.38.0.
 *
 * @param int   $event_id 0 for New Event.
 * @param array $get      What the query string carries.
 * @return string
 */
function render_form( $event_id = 0, $get = array() ) {
    global $render, $portal, $admin;

    $_GET = $get;

    /* THE FORM OPENS BUFFERS OF ITS OWN. render_event_form() renders the side
       column into one before echoing it, so a throw part way through leaves the
       stack deeper than it was. Unwinding to the depth recorded here, rather
       than calling ob_end_clean() once, is what stops a failed render printing
       half a page over the results. */
    $depth = ob_get_level();
    ob_start();
    try {
        $render->invoke( $portal, $admin, $event_id );
    } catch ( Throwable $e ) {
        while ( ob_get_level() > $depth ) { ob_end_clean(); }
        $_GET = array();
        fail( 'the form could not be rendered at all: ' . get_class( $e ) . ' ' . $e->getMessage()
            . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        return '';
    }
    $html = '';
    while ( ob_get_level() > $depth ) { $html = ob_get_clean() . $html; }
    $_GET = array();
    return $html;
}

/* ---------------------------------------------------------------------------
 * READING THE RENDER.
 *
 * DOMDocument, not a regular expression. "does the page contain name=series"
 * is answered by a string search and answers the wrong question: it would pass
 * on a commented-out control, on the word inside a JSON payload, and on a
 * <select> nested in a card that is never shown. What is being asserted is that
 * a FORM CONTROL exists, and only a parser knows what a form control is.
 * ------------------------------------------------------------------------ */

/** @return DOMElement[] Every element that would post under the given name. */
function controls_named( $html, $name ) {
    if ( '' === trim( $html ) ) { return array(); }
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    // The portal prints a whole document; wrapping keeps a fragment valid too.
    $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
    libxml_clear_errors();

    $out = array();
    foreach ( array( 'select', 'input', 'textarea' ) as $tag ) {
        foreach ( $doc->getElementsByTagName( $tag ) as $el ) {
            $n = $el->getAttribute( 'name' );
            // A multi-select posts as name[]; both spellings are the same field.
            if ( $n === $name || $n === $name . '[]' ) {
                $out[] = $el;
            }
        }
    }
    return $out;
}

/** The value of the chosen <option> in a select, or '' when none is marked. */
function chosen_option( DOMElement $select ) {
    foreach ( $select->getElementsByTagName( 'option' ) as $opt ) {
        if ( $opt->hasAttribute( 'selected' ) ) {
            return $opt->getAttribute( 'value' );
        }
    }
    return '';
}

/* ===========================================================================
 * 1. RENDERING NEW EVENT EMITS A CONTROL NAMED "series".
 *
 * The assertion the defect needed and nobody wrote. Three series exist, so
 * there is no innocent reason for the control to be absent, and the answer
 * comes from the rendered page rather than from the source that produces it.
 * ======================================================================== */

$new = render_form( 0 );

expect( 'the New Event form rendered at all', strlen( $new ) > 1000, true );

$new_controls = controls_named( $new, 'series' );
expect( 'New Event emits exactly one control named "series"', count( $new_controls ), 1 );

if ( 1 === count( $new_controls ) ) {
    $sel = $new_controls[0];

    /* 2. A SINGLE SELECT, NEVER A MULTI-SELECT.
     *
     * One series per event is enforced only in the CONTROL. Both readers take
     * the first term through reset( get_the_terms() ) and set_for_event()
     * writes a single-element array through wp_set_object_terms(), whose
     * default REPLACES. So a multi-select here would let somebody pick two and
     * lose one on save, silently, with nothing logged and nothing to recover
     * from. That is the categories fault of 3.8.0 and the organizers fault of
     * 3.40.0; PROJECT.md §7 names venue and series as the deliberate single
     * case. Three ways of getting it wrong, all checked: the tag, the
     * `multiple` attribute, and the name[] spelling a multi-select posts under.
     */
    expect( 'it is a <select>', $sel->tagName, 'select' );
    expect( 'it is not a multi-select', $sel->hasAttribute( 'multiple' ), false );
    expect( 'it does not post as an array', $sel->getAttribute( 'name' ), 'series' );
    expect( 'it does not take a size, which is a list box',
        $sel->hasAttribute( 'size' ) && '1' !== $sel->getAttribute( 'size' ), false );

    /* Every series, plus the way out of one. A control that offered the terms
     * and no "none" would make joining a series irreversible from this screen. */
    expect( 'every series is offered, with "not part of one" first',
        $sel->getElementsByTagName( 'option' )->length, count( SFAF_Series::$terms ) + 1 );
    expect( 'a new event starts in no series', chosen_option( $sel ), '' );
}

/* The card the control lives in, which is the thing Mark could not find. Named
   through its data attribute rather than its heading: the heading is copy and
   may be rewritten, the hook is what portal.js binds the prefill panel to. */
expect( 'the "Is this part of a series?" card is on the page',
    false !== strpos( $new, 'data-uc-series-prefill' ), true );

/* ===========================================================================
 * 3. ?series= FROM THE SCHEDULE SCREEN ARRIVES AS THE CHOSEN OPTION.
 *
 * The schedule screen's "Create a new event in this series" button carries the
 * term in the query string, and its own comment says "The series arrives
 * already chosen, which is the only thing this screen knows that the editor
 * does not". It arrived nowhere: the validation ran AFTER the dead call and the
 * only other reader is gated on an event id. Same one ordering fault.
 * ======================================================================== */

$prefilled = render_form( 0, array( 'series' => (string) 13 ) );
$pre_ctl   = controls_named( $prefilled, 'series' );

expect( 'the prefilled New Event form emits one series control', count( $pre_ctl ), 1 );
if ( 1 === count( $pre_ctl ) ) {
    expect( 'the term named in ?series= is the chosen option',
        chosen_option( $pre_ctl[0] ), '13' );
}

/* CHECKED, NOT TRUSTED, and this is the half that says so. A query string is
   somebody's to type, so an id naming no term must leave the control on "not
   part of a series" rather than preselecting something that is not there. */
$bogus     = render_form( 0, array( 'series' => '999999' ) );
$bogus_ctl = controls_named( $bogus, 'series' );
if ( 1 === count( $bogus_ctl ) ) {
    expect( 'an id naming no series chooses nothing',
        chosen_option( $bogus_ctl[0] ), '' );
}

/* ===========================================================================
 * 4. THE EDIT SCREEN IS UNDISTURBED.
 *
 * Its control has always worked and this release must not have moved it. One
 * control, still single, and still showing the series the event is actually in
 * — which also proves the two screens did not both start rendering one, which
 * is the failure the "on an edit only" gate exists to prevent: two controls
 * posting the same field, and the second winning.
 * ======================================================================== */

$edit      = render_form( 201 );
$edit_ctls = controls_named( $edit, 'series' );

expect( 'the Edit Event form rendered at all', strlen( $edit ) > 1000, true );
expect( 'Edit Event emits exactly one control named "series"', count( $edit_ctls ), 1 );
if ( 1 === count( $edit_ctls ) ) {
    expect( 'the edit control is a single select', $edit_ctls[0]->hasAttribute( 'multiple' ), false );
    expect( 'it shows the series the event is in', chosen_option( $edit_ctls[0] ), '12' );
}
/* The prefill card is a blank-form convenience and must not appear on an edit,
   where it would offer to overwrite work somebody has already done. */
expect( 'the prefill card is not on the edit screen',
    false !== strpos( $edit, 'data-uc-series-prefill' ), false );

/* ---------------------------------------------------------------------------
 * AND IT IS AT THE TOP ON BOTH (3.72.0).
 *
 * The selector was at the top on New Event and two thirds of the way down on an
 * edit, inside the Schedule card, so the one question that decides what an
 * event looks like was in a different place depending on which screen somebody
 * had open. Both screens ask it first now.
 *
 * THE ANCHOR IS THE SCHEDULE CARD'S HEADING, which is the card the select used
 * to sit in. Its exact markup, not the bare word, because "schedule" appears in
 * links and hints all over this form and a substring match would pass on any of
 * them.
 *
 * DECIDED BY POSITION IN THE RENDER, not by which method emitted it. "Is it
 * before the Schedule card" is the outcome; "render_series_prefill() was
 * called" is the property believed to imply it, and PROJECT.md 7 has three
 * releases of that distinction costing a build.
 * ------------------------------------------------------------------------ */
function before_schedule_card( $html ) {
    $sel  = strpos( $html, 'name="series"' );
    $card = strpos( $html, '>Schedule</h2>' );
    if ( false === $sel ) { return 'no series control in the render'; }
    if ( false === $card ) { return 'no Schedule card in the render'; }
    return ( $sel < $card ) ? 'before' : 'after';
}
expect( 'New Event asks which series before the Schedule card', before_schedule_card( $new ), 'before' );
expect( 'Edit Event asks which series before the Schedule card', before_schedule_card( $edit ), 'before' );

/* ===========================================================================
 * 5. THE HOOKS THE PREFILL WRITES THROUGH ARE ON THE PAGE (3.64.2).
 *
 * WHAT THIS IS HALF OF. "Fill these in" used to write the image into two
 * hidden fields and leave the preview empty and the tag reading "Placeholder",
 * so the button said six things had been filled in and the picture was the one
 * nobody could see. The fix is in portal.js, and
 * .claude/prefill-image-test.js runs that code and reads the result.
 *
 * THAT TEST BUILDS ITS OWN DOM, which is the one thing it cannot prove: a
 * stub can hold any shape at all, including one this form does not render.
 * So the shape is proved HERE, from the real render, and the two files
 * together are the assertion. Rename an attribute and this half fails.
 * ======================================================================== */

/** @return DOMElement[] Every element matching an XPath over the render. */
function nodes_matching( $html, $xpath ) {
    if ( '' === trim( $html ) ) { return array(); }
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
    libxml_clear_errors();
    $out = array();
    foreach ( ( new DOMXPath( $doc ) )->query( $xpath ) as $el ) { $out[] = $el; }
    return $out;
}

$img_fields = nodes_matching( $new, '//*[contains(concat(" ", normalize-space(@class), " "), " uc-image-field ")]' );
expect( 'New Event renders one featured image field', count( $img_fields ), 1 );

/* THE ATTACHMENT ID IS A RADIO NOW, NOT A HIDDEN INPUT (3.94.0), AND THIS
   ASSERTION REVERSED WITH IT.

   The event editor's picture chooser was wp.media writing into a hidden field;
   it is the request form's picker now, and the chosen picture is the checked
   radio. The data-uc-image-id attribute was the hook wp.media wrote through,
   and there is nothing left to write through.

   WHAT THE CHECK WAS PROTECTING IS UNCHANGED and is asserted below instead:
   the field posts featured_image_id. That is the contract the save reads. The
   hidden input was one implementation of it. */
$posts_id = nodes_matching( $new, '//*[contains(concat(" ", normalize-space(@class), " "), " uc-image-field ")]//input[@name="featured_image_id"]' );
expect( 'the image field posts featured_image_id', count( $posts_id ) > 0, true );

foreach ( array(
    'the image URL box'          => '@data-uc-image-url',
    'the preview container'      => '@data-uc-image-preview',
    'the preview <img>'          => '@data-uc-image-preview-img',
    'the source tag'             => '@data-uc-img-source-tag',
) as $what => $attr ) {
    expect( "the image field carries $what",
        count( nodes_matching( $new, '//*[contains(concat(" ", normalize-space(@class), " "), " uc-image-field ")]//*[' . $attr . ']' ) ) > 0,
        true );
}

/* The words the script writes into the tag come from the tag, so there is one
   spelling of "Event-specific" and it is the server's. */
$own_tag = nodes_matching( $new, '//*[@data-uc-img-source-own]' );
expect( 'the tag carries the label the script will write', count( $own_tag ), 1 );
if ( 1 === count( $own_tag ) ) {
    expect( 'and that label is the event-specific one',
        $own_tag[0]->getAttribute( 'data-uc-img-source-own' ), 'Event-specific' );
    expect( 'a new event starts on the placeholder tag',
        trim( $own_tag[0]->textContent ), 'Placeholder' );
}

/* ===========================================================================
 * 6. THE DISPLAY CARD: ADD TO CALENDAR SITS UNDER RSVP (3.64.2).
 *
 * A control whose availability is decided by another belongs beside it, and
 * "beside" is a property of the RENDERED order, not of the array literal that
 * happens to produce it today. So the order comes off the page.
 * ======================================================================== */

$display_boxes = nodes_matching( $new, '//input[@type="checkbox"][starts-with(@name, "show_")]' );
$display_order = array();
foreach ( $display_boxes as $b ) { $display_order[] = $b->getAttribute( 'name' ); }

expect( 'the Display card renders its five ticks in one order',
    $display_order,
    array( 'show_rsvp', 'show_calendar', 'show_donate', 'show_social', 'show_reminders' ) );

/* The greyed-out line moves with the tick it belongs to, and says why before
   it says what happens instead. */
$note = nodes_matching( $new, '//*[@data-uc-calendar-note]' );
expect( 'the calendar note is on the page', count( $note ), 1 );
if ( 1 === count( $note ) ) {
    expect( 'and it names the cause before the consequence',
        0 === strpos( preg_replace( '/\s+/', ' ', trim( $note[0]->textContent ) ),
            'Because this event takes RSVPs, the calendar link goes out' ),
        true );
}

/* ===========================================================================
 * THE READER, PROVED BEFORE IT IS TRUSTED.
 *
 * --self-test. Every assertion above rests on controls_named() and
 * chosen_option() being able to SEE what they are looking for, and an audit
 * that cannot find the thing it is checking reports its absence rather than
 * its mistake. 3.20.0 is the entry in PROJECT.md for that; this file has
 * already made the mistake once, when an inherited selected() stub returned ''
 * unconditionally and would have decided assertion 3 on its own.
 *
 * These feed the reader markup it must and must not match, including the two
 * spellings of a multi-select, which is the shape assertion 2 exists to refuse.
 * ======================================================================== */

if ( $self ) {
    $probe = function ( $label, $got, $want ) {
        printf( "  %s  %-56s got %s want %s\n", $got === $want ? 'ok  ' : 'FAIL', $label,
            var_export( $got, true ), var_export( $want, true ) );
        return $got === $want;
    };

    $ok = true;
    $ok &= $probe( 'finds a plain select',
        count( controls_named( '<select name="series"><option value="1">a</option></select>', 'series' ) ), 1 );
    $ok &= $probe( 'finds a multi-select spelled name[]',
        count( controls_named( '<select name="series[]" multiple></select>', 'series' ) ), 1 );
    $ok &= $probe( 'sees the multiple attribute',
        controls_named( '<select name="series[]" multiple></select>', 'series' )[0]->hasAttribute( 'multiple' ), true );
    $ok &= $probe( 'does not match a different field',
        count( controls_named( '<select name="series_id"></select>', 'series' ) ), 0 );
    $ok &= $probe( 'does not match the word in prose',
        count( controls_named( '<p>choose a series, name="series"</p>', 'series' ) ), 0 );
    $ok &= $probe( 'does not match a commented-out control',
        count( controls_named( '<!-- <select name="series"></select> -->', 'series' ) ), 0 );
    $ok &= $probe( 'finds a checkbox list, which assertion 2 must refuse',
        count( controls_named( '<input type="checkbox" name="series[]" value="1" />', 'series' ) ), 1 );
    $ok &= $probe( 'reads the chosen option',
        chosen_option( controls_named( '<select name="series"><option value="7">a</option><option value="9" selected>b</option></select>', 'series' )[0] ), '9' );
    $ok &= $probe( 'reports nothing chosen when nothing is',
        chosen_option( controls_named( '<select name="series"><option value="7">a</option></select>', 'series' )[0] ), '' );
    /* The stub that would have quietly decided assertion 3. */
    $ok &= $probe( 'selected() marks a matching option',
        '' !== selected( 13, 13, false ), true );
    $ok &= $probe( 'selected() compares a query string to an int',
        '' !== selected( '13', 13, false ), true );
    $ok &= $probe( 'selected() leaves a non-matching option alone',
        '' !== selected( 12, 13, false ), false );

    echo "\n" . ( $ok ? 'the reader can see what it is looking for.' : 'THE READER IS BROKEN.' ) . "\n";
    exit( $ok ? 0 : 1 );
}

/* ======================================================================== */

if ( $fails ) {
    echo "SERIES CONTROL: " . count( $fails ) . " FAILURE" . ( 1 === count( $fails ) ? '' : 'S' ) . "\n";
    foreach ( $fails as $f ) {
        echo "  - $f\n";
    }
    exit( 1 );
}

echo "SERIES CONTROL\n";
echo "  New Event   one <select name=\"series\">, single, " . ( count( SFAF_Series::$terms ) + 1 ) . " options, none chosen\n";
echo "  ?series=13  arrives as the chosen option\n";
echo "  Edit Event  one control, single, showing the series the event is in\n";
echo "  decided by rendering the form and reading what came back.\n";
exit( 0 );
