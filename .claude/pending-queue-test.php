<?php
/**
 * DOES A SUBMITTED EVENT REACH THE QUEUE SOMEBODY LOOKS AT?
 *
 *     php .claude/pending-queue-test.php
 *     php .claude/pending-queue-test.php --self-test
 *
 * WHY THIS FILE EXISTS. A community submission on 3.46.0 landed in no list at
 * all. It was reachable only by the link in its own notification email: lose
 * the email and the event is invisible. The status was never wrong. What was
 * wrong was that the queue was scoped by AUTHORSHIP, and both public forms set
 * post_author to 0 on purpose, because nobody was logged in to author them.
 *
 * THIS IS THE SECOND MARKING FAULT ON THESE FORMS AND THEY SHARE A CAUSE.
 * 3.46.0 fixed a badge that asked "does it have a request email" when both
 * forms write one. This is a query that asks "did you write it" of a post
 * nobody wrote. Both are a screen inferring a property from a field that was
 * never meant to answer that question, and both were covered by checks that
 * read the SOURCE and so could not see what came back.
 *
 * SO THIS ASSERTS AN OUTCOME. It loads the real SFAF_Portal, builds posts
 * exactly as the two forms build them, calls the real query_events() with the
 * arguments the real screens pass, and asks WHICH IDS CAME BACK. No assertion
 * in this file searches for a string in any source file.
 *
 * AND SINCE 3.49.0 IT RENDERS THE SCREEN. Section 6 calls render_pending()
 * itself, through the real filter and the real sort, and reads the rows back
 * out of the HTML by their `data-uc-id`. That is a third marking fault's worth
 * of reason: the queue became ONE list with a filter over it, and "is my thing
 * in the list, and is it under the right tab" is a question only the rendered
 * list can answer. Six faults were planted to prove it can fail, including the
 * two real ones above; all six were caught.
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
function checked( $a, $b = true, $e = true ) { return ''; }
function selected( $a, $b = true, $e = true ) { return ''; }
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
function sfaf_event_categories( $id ) { return array(); }
function sfaf_ap_date( $d, $f = 'full' ) { return (string) $d; }
/* The real SFAF_Cron::local_time() formats through this and nothing else, so
   the stub does too. Formatting a date inline here instead put a human format
   outside the one formatter, which is exactly what date-callsite-sweep exists
   to forbid; it caught it, and 3.57.0 misread the catch. */
function sfaf_ap_datetime( $ts ) { return 'a stamped time'; }
function sfaf_ap_time_range( $a, $b ) { return $a . ' to ' . $b; }
function sfaf_icon( $n, $a = array() ) { return ''; }
function sfaf_favicon_links() { echo "<link rel=\"icon\" data-uc-test-favicon />"; }
function sfaf_event_image_url( $id, $s = 'large' ) { return ''; }
function sfaf_event_image_source( $id ) { return 'none'; }
function sfaf_fundraising_progress_meta_key() { return '_uc_show_fund_progress'; }
function sfaf_source_links_flag() { return false; }
function sfaf_set_source_links( $v ) {}
function _prime_post_caches( $ids, $a = true, $b = true ) {}

/* ---------------------------------------------------------------------------
 * What section 6 needs in order to RENDER the screen rather than query it.
 *
 * Everything here is chrome. None of it decides which row lands in which list,
 * which is the whole of what section 6 asserts, so each is the smallest thing
 * that lets the page finish drawing.
 * ------------------------------------------------------------------------ */
function date_i18n( $fmt, $ts = null, $gmt = false ) { return date( $fmt, null === $ts ? time() : (int) $ts ); }
function wp_timezone_string() { return 'America/Los_Angeles'; }
function sfaf_event_location_short( $id ) { return ''; }
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
    public $user_email = 'mark@sfaf.org';
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

class SFAF_Teams {
    public static function events_for_user( $uid ) { return array(); }
}
/* THE REAL META KEY. Section 5 asserts that an address reaches the list the
 * notification system actually reads, so the key has to be the shipping one:
 * a stubbed constant would prove the address reached a string this file made
 * up. Only the constant is needed here, not the sending machinery. */
class SFAF_Reminders {
    const NOTIFY_EMAILS_META = '_uc_notify_emails';
    public static function new_token() { return bin2hex( random_bytes( 16 ) ); }
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
}
/* Privacy is a property of the event, not of the queue. The panel reads it. */
class SFAF_Privacy {
    public static function is_private( $id ) { return false; }
    public static function set( $id, $on ) { return true; }
}
class SFAF_Search {
    public static function apply( &$q, $s ) {}
}
class SFAF_Series { const TAXONOMY = 'uc_series'; }

require_once $root . '/includes/class-sfaf-request.php';
require_once $root . '/includes/class-sfaf-uploads.php';
require_once $root . '/includes/class-sfaf-submissions.php';
require_once $root . '/includes/class-sfaf-submit.php';
require_once $root . '/includes/class-sfaf-portal.php';

/* ---------------------------------------------------------------------------
 * The world: one admin, and one pending event from each public form.
 *
 * post_author 0 IS THE POINT. Both forms set it deliberately, because nobody
 * was logged in to author the post, and PROJECT.md records that as a decision
 * rather than an oversight. So a queue that filters by author excludes both.
 * ------------------------------------------------------------------------ */
$MARK = TEST_ADMIN_ID;
$GLOBALS['posts'] = array(
    /* What SFAF_Request::create_event() writes. */
    101 => array( 'post_title' => 'Staff request', 'post_status' => 'pending', 'post_author' => 0 ),
    /* What SFAF_Submit::create_event() writes. */
    102 => array( 'post_title' => 'Community submission', 'post_status' => 'pending', 'post_author' => 0 ),
    /* And one an admin made themselves, as the control. */
    103 => array( 'post_title' => 'Made in caladmin', 'post_status' => 'pending', 'post_author' => $MARK ),
    /* A published event nobody authored, which is what a submission becomes. */
    104 => array( 'post_title' => 'Approved submission', 'post_status' => 'publish', 'post_author' => 0 ),
);
$GLOBALS['meta'] = array(
    101 => array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+10 days' ) ), SFAF_Request::META_EMAIL => 'a@sfaf.org' ),
    102 => array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+11 days' ) ), SFAF_Request::META_EMAIL => 'b@example.org', SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_COMMUNITY ),
    103 => array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+12 days' ) ) ),
    /* MARKED AS A SUBMISSION, WHICH IS THE WHOLE POINT OF THIS ROW. Without
     * the marker it reads as an ordinary authorless event, and the assertion
     * that a PUBLISHED submission is still an orphan would pass for the wrong
     * reason: it would be testing an event the exemption never looked at. */
    104 => array(
        '_uc_event_date'                 => date( 'Y-m-d', strtotime( '+13 days' ) ),
        SFAF_Request::META_EMAIL         => 'c@example.org',
        SFAF_Submissions::META_KIND      => SFAF_Submissions::KIND_COMMUNITY,
    ),
);

$admin  = new WP_User( $MARK );
$portal = new SFAF_Portal();

$method = new ReflectionMethod( 'SFAF_Portal', 'query_events' );
$method->setAccessible( true );

/** The ids the real query_events() returns for a given set of arguments. */
function ids_for( $args ) {
    global $method, $portal, $admin;
    return $method->invoke( $portal, $admin, $args );
}

/* =========================================================================
 * 1. THE QUEUE, ASKED EXACTLY AS THE SCREEN ASKS IT.
 *
 * THE ARGUMENTS ARE READ OUT OF THE SCREEN, NOT COPIED HERE. A test holding
 * its own copy of them would keep passing while the screen changed underneath
 * it, which is the same "two copies of one thing" fault that has cost this
 * project several releases. render_pending() and the nav badge both call
 * pending_query_args(), and so does this.
 * ====================================================================== */
$args_method = new ReflectionMethod( 'SFAF_Portal', 'pending_query_args' );
$args_method->setAccessible( true );
$queue_args = $args_method->invoke( $portal );

$queue = ids_for( $queue_args );

foreach ( array(
    101 => 'a staff request',
    102 => 'a community submission',
    103 => 'an event an admin made',
) as $id => $what ) {
    if ( ! in_array( $id, $queue, true ) ) {
        fail( "$what is not in the pending queue, so the only way to reach it is the link in its own notification email" );
    }
}

/* And the count beside Pending in the nav has to agree with the list, or a
 * manager reads a number that is not what is waiting. */
if ( count( $queue ) !== 3 ) {
    fail( 'the pending queue holds ' . count( $queue ) . ' of 3 waiting events, so its count cannot be trusted either' );
}

/* =========================================================================
 * 2. AND THE THING THAT MUST NOT CHANGE.
 *
 * "My events" still means authored by me. Widening THAT would be a different
 * fault: a toggle a person reads, quietly meaning something else.
 * ====================================================================== */
$mine = ids_for( array( 'status' => 'pending', 'per_page' => 100, 'scope' => 'mine' ) );
if ( in_array( 101, $mine, true ) || in_array( 102, $mine, true ) ) {
    fail( 'My Events now claims a submission nobody authored, which is not what the label says' );
}
if ( ! in_array( 103, $mine, true ) ) {
    fail( 'My Events lost the event this admin actually made' );
}

$all = ids_for( array( 'status' => 'pending', 'per_page' => 100, 'scope' => 'all' ) );
foreach ( array( 101, 102, 103 ) as $id ) {
    if ( ! in_array( $id, $all, true ) ) {
        fail( "the All scope is missing event $id" );
    }
}

/* =========================================================================
 * 3. AN APPROVED SUBMISSION IS STILL FINDABLE.
 *
 * Approving sets publish and nothing else, so the post keeps author 0 for the
 * rest of its life. If the All view could not find it either, a published
 * community event would be off every list in the portal.
 * ====================================================================== */
$published = ids_for( array( 'status' => 'publish', 'per_page' => 100, 'scope' => 'all' ) );
if ( ! in_array( 104, $published, true ) ) {
    fail( 'an approved submission is in no events view at all, so it is published and unfindable' );
}

/* =========================================================================
 * 4. AND NEITHER SUBMISSION SETS OFF THE ORPHAN ALERT WHILE IT WAITS.
 *
 * THE SAME AUTHOR-0 FACT, READ BY A SECOND SUBSYSTEM. The orphan check asks
 * "does this event have a valid owner", and an authorless post answers no, so
 * every public submission produced a daily email saying its organizer is "a
 * deleted account". That is a false positive by construction: nobody has been
 * GIVEN it yet, which is not the same state as whoever had it having left.
 *
 * The line between them is approval. A pending submission is waiting for
 * somebody to give it an owner. A PUBLISHED one with no owner means somebody
 * looked at it and put it on the calendar anyway, which is exactly the case the
 * alert exists for, so that one must still fire.
 * ====================================================================== */
$orphan = new ReflectionMethod( 'SFAF_Portal', 'event_is_orphaned' );
$orphan->setAccessible( true );

foreach ( array( 101 => 'a staff request', 102 => 'a community submission' ) as $id => $what ) {
    if ( $orphan->invoke( null, $id ) ) {
        fail( "$what awaiting review is reported as an orphaned event, so every submission emails the admins the next morning" );
    }
}

/* Approved, and still nobody's: this one IS an orphan and must stay one. */
if ( ! $orphan->invoke( null, 104 ) ) {
    fail( 'an approved submission with no owner is not reported, so the alert no longer covers the case it exists for' );
}

/* An ordinary pending event whose author was deleted is still an orphan: the
 * exemption is for submissions awaiting review, not for anything pending. */
$GLOBALS['posts'][105] = array( 'post_title' => 'Made by somebody since deleted', 'post_status' => 'pending', 'post_author' => 0 );
$GLOBALS['meta'][105]  = array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+14 days' ) ) );
if ( ! $orphan->invoke( null, 105 ) ) {
    fail( 'a pending event that nobody submitted is exempted from the orphan check, so the exemption is too wide' );
}
unset( $GLOBALS['posts'][105], $GLOBALS['meta'][105] );

/* =========================================================================
 * 5. WHAT APPROVAL ACTUALLY DOES WITH THE SUBMITTER'S ADDRESS.
 *
 * THE TICK PUTS A REAL ADDRESS ON A REAL LIST, and that list is sent registrant
 * names and email addresses. So the assertion is not that a checkbox exists: it
 * is that the address lands where it was said it would land, that approving
 * twice does not land it twice, and that an event with no usable address is
 * offered nothing rather than offered a tick that sends to nowhere.
 * ====================================================================== */
$GLOBALS['meta'][102][SFAF_Request::META_NAME] = 'Jane Smith';

$who = SFAF_Submissions::submitter( 102 );
expect( 'the submitter is named', $who['name'], 'Jane Smith' );
expect( 'and their address is usable', $who['usable'], true );
expect( 'and it is a submission', $who['is_submission'], true );

/* Added once. */
expect( 'the address goes on the list', SFAF_Submissions::add_to_notify_list( 102, $who['email'] ), true );
$listed = (array) get_post_meta( 102, SFAF_Reminders::NOTIFY_EMAILS_META, true );
if ( ! in_array( 'b@example.org', $listed, true ) ) {
    fail( 'the submitter is not on the notification list, so ticking the box sends them nothing' );
}

/* And not twice, however many times Approve is pressed. */
expect( 'a second approval adds nothing', SFAF_Submissions::add_to_notify_list( 102, $who['email'] ), false );
expect( 'and the list still holds one entry', count( (array) get_post_meta( 102, SFAF_Reminders::NOTIFY_EMAILS_META, true ) ), 1 );

/* Case is not a second person. */
expect( 'a differently cased address is the same person', SFAF_Submissions::add_to_notify_list( 102, 'B@Example.ORG' ), false );

/* WHAT MUST NOT REACH THE LIST. Nothing that is not an address, and nothing
 * from an event that has no submitter to speak of. */
foreach ( array( '', 'not-an-address', 'a@b', '<script>' ) as $bad ) {
    if ( SFAF_Submissions::add_to_notify_list( 102, $bad ) ) {
        fail( 'the notification list accepted "' . $bad . '", which is not an address' );
    }
}

/* An event nobody submitted offers neither question. */
$plain = SFAF_Submissions::submitter( 103 );
expect( 'an event an admin made is not a submission', $plain['is_submission'], false );

/* A submission whose stored address is not one: the prompt has to say so
 * rather than offer to send to nothing. */
$GLOBALS['posts'][106] = array( 'post_title' => 'No usable address', 'post_status' => 'pending', 'post_author' => 0 );
$GLOBALS['meta'][106]  = array(
    '_uc_event_date'            => date( 'Y-m-d', strtotime( '+15 days' ) ),
    SFAF_Request::META_EMAIL    => 'whoops at example dot org',
    SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_COMMUNITY,
);
$broken = SFAF_Submissions::submitter( 106 );
expect( 'a stored non-address is still a submission', $broken['is_submission'], true );
expect( 'but it is not usable', $broken['usable'], false );
if ( SFAF_Submissions::add_to_notify_list( 106, $broken['email'] ) ) {
    fail( 'an unusable address was put on the notification list anyway' );
}

/* ---------------------------------------------------------------------------
 * SELF TEST. Every case is a shape this file has NOT already seen pass.
 * ------------------------------------------------------------------------ */
/* =========================================================================
 * 6. ONE LIST, AND EVERY KIND IN EXACTLY THE RIGHT PART OF IT (3.49.0).
 *
 * WHY THIS SECTION RENDERS THE SCREEN INSTEAD OF ASKING IT A QUESTION.
 *
 * Three faults on this queue have now been marking or filtering problems, and
 * not one of them could be seen by reading source:
 *
 *   3.46.0  community submissions badged as staff requests, because the badge
 *           asked "does it have a request email" and both forms write one.
 *   3.47.0  an authorless post matching no list, because the queue filtered by
 *           authorship and both forms set post_author to 0 on purpose.
 *   3.47.0  the same queue scoping itself to the current user.
 *
 * Every one of those shipped with checks in place that proved a string existed
 * somewhere. So this asserts the OUTCOME: it builds one event of each kind,
 * calls the real render_pending() through the real filter and sort, and reads
 * the rows back OUT OF THE HTML. What it asks is the question a person asks:
 * is my thing in this list, and is it under the right tab.
 *
 * `data-uc-kind="dismissed"` IS EXCLUDED and that is not a convenience. The
 * dismissed card is a second list on the same screen, deliberately, and a row
 * appearing there is not a row appearing in the work list. Counting both would
 * make "nowhere else" impossible to state.
 * ====================================================================== */

/* One of each, in the four statuses and kinds this screen can hold. */
$GLOBALS['posts'][201] = array( 'post_title' => 'Imported campaign',   'post_status' => 'uc_imported', 'post_author' => 0 );
$GLOBALS['posts'][202] = array( 'post_title' => 'Dismissed campaign',  'post_status' => 'uc_dismissed', 'post_author' => 0 );
$GLOBALS['meta'][201]  = array(
    '_uc_event_date'         => date( 'Y-m-d', strtotime( '+20 days' ) ),
    SFAF_Sources::META_SOURCE => 'eventbrite',
    '_uc_imported_at'        => time() - 60,
);
$GLOBALS['meta'][202]  = array(
    '_uc_event_date'         => date( 'Y-m-d', strtotime( '+21 days' ) ),
    SFAF_Sources::META_SOURCE => 'eventbrite',
);

/*
 * AND ONE IMPORT WITH NO DATE AT ALL, which is not an edge case: a GoFundMe Pro
 * campaign arrives without one every time, by design, and filling it in is the
 * job. Without a dateless row in this world the "dateless sorts last" assertion
 * below has nothing to be true or false about, and planting that fault proved
 * exactly that: it was the one planted fault this file did not catch.
 */
$GLOBALS['posts'][203] = array( 'post_title' => 'Campaign with no date', 'post_status' => 'uc_imported', 'post_author' => 0 );
$GLOBALS['meta'][203]  = array(
    SFAF_Sources::META_SOURCE => 'gfmp',
    '_uc_imported_at'         => time() - 30,
);

/* The four rows this section is about, and what each one is. 103 is the
 * control: an event an admin set to pending by hand. It is kind 'local', it
 * matches no filter, and it must still be in the unfiltered list. */
$WORLD = array(
    201 => 'import',
    101 => 'staff',
    102 => 'community',
    103 => 'local',
);

/** Render the pending screen with these query args, and give back its HTML. */
function screen_html( $args ) {
    global $portal, $admin;

    $_GET = $args;
    $method = new ReflectionMethod( 'SFAF_Portal', 'render_pending' );
    $method->setAccessible( true );

    ob_start();
    try {
        $method->invoke( $portal, $admin );
    } catch ( Throwable $e ) {
        ob_end_clean();
        fail( 'render_pending() threw: ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        return '';
    }
    $html = ob_get_clean();
    $_GET = array();
    return $html;
}

/**
 * The ids of the rows a person can see in the WORK LIST, in the order drawn.
 *
 * Read out of the rendered markup, not out of any array this file built. The
 * dismissed card is on the same page and is excluded by its kind.
 */
function rows_in( $html ) {
    if ( ! preg_match_all( '/data-uc-id="(\d+)" data-uc-kind="([a-z]+)"/', $html, $m, PREG_SET_ORDER ) ) {
        return array();
    }
    $ids = array();
    foreach ( $m as $hit ) {
        if ( 'dismissed' === $hit[2] ) {
            continue;
        }
        $ids[] = (int) $hit[1];
    }
    return $ids;
}

/* --- The unfiltered list holds every one of them. ---------------------- */
$all = rows_in( screen_html( array() ) );

foreach ( $WORLD as $id => $kind ) {
    if ( ! in_array( $id, $all, true ) ) {
        fail( "a $kind event is not in the unfiltered pending list, so it is in no list a person opens" );
    }
}

/* --- And each filter shows its own kind, and nothing else. ------------- */
foreach ( array( 'import', 'staff', 'community' ) as $filter ) {
    $shown = rows_in( screen_html( array( 'kind' => $filter ) ) );

    foreach ( $WORLD as $id => $kind ) {
        $belongs = ( $kind === $filter );
        $there   = in_array( $id, $shown, true );

        if ( $belongs && ! $there ) {
            fail( "the $filter filter does not show the $kind event, which is the one thing it is for" );
        }
        if ( ! $belongs && $there ) {
            fail( "the $filter filter shows the $kind event, so the badge and the filter disagree about what it is" );
        }
    }
}

/* --- A kind with no filter of its own is still reachable. -------------- */
foreach ( array( 'import', 'staff', 'community' ) as $filter ) {
    if ( in_array( 103, rows_in( screen_html( array( 'kind' => $filter ) ) ), true ) ) {
        fail( "an event an admin made by hand appears under the $filter filter" );
    }
}

/* --- Newest first, by default, because this is a work list. ------------
 *
 * THE PROPERTY, NOT THE PERMUTATION. Asserting an exact order here means
 * writing the comparator out a second time, and then the test agrees with a
 * copy of the code rather than with the requirement. "Newest first" means each
 * row arrived no later than the one above it, and that is what is checked.
 * How ties break is the implementation's business, as long as it is stable. */
$default = rows_in( screen_html( array() ) );
$prev = null;
foreach ( $default as $id ) {
    $at = $portal->pending_received( $id );
    if ( null !== $prev && $at > $prev ) {
        fail( "the queue does not open newest first: $id arrived after the row drawn above it" );
        break;
    }
    $prev = $at;
}

/* And the same list, asked twice, is the same list. An unstable comparator
 * reshuffles equal rows on every reload, which reads as things moving on their
 * own while somebody is working through them. */
if ( $default !== rows_in( screen_html( array() ) ) ) {
    fail( 'the queue drew a different order the second time it was asked for the same view' );
}

/* --- The sort reverses, and reversing it does not drop or add a row. --- */
$oldest = rows_in( screen_html( array( 'orderby' => 'received', 'order' => 'asc' ) ) );
if ( count( $oldest ) !== count( $default ) ) {
    fail( 'reversing the sort changed how many rows there are, from ' . count( $default ) . ' to ' . count( $oldest ) );
} elseif ( $oldest === $default && count( $default ) > 1 ) {
    fail( 'asking for the oldest first returned the same order as the newest first' );
}

/* --- Sorting by event date is offered and actually reorders. ----------- */
$by_date = rows_in( screen_html( array( 'orderby' => 'date', 'order' => 'asc' ) ) );
if ( count( $by_date ) !== count( $default ) ) {
    fail( 'sorting by event date changed how many rows there are' );
}
/* Again the property: dates run forwards, and anything dateless is at the
 * BOTTOM rather than sorted as the year zero. Plenty of imports arrive with no
 * date by design, and a reversed sort that parks all of them at the top pushes
 * every row with a real date off the screen. */
$prev = '';
$seen_dateless = false;
foreach ( $by_date as $id ) {
    $on = (string) get_post_meta( $id, '_uc_event_date', true );
    if ( '' === $on ) {
        $seen_dateless = true;
        continue;
    }
    if ( $seen_dateless ) {
        fail( "sorting by event date put a dateless row above $id, which has one" );
        break;
    }
    if ( '' !== $prev && strcmp( $on, $prev ) < 0 ) {
        fail( "sorting by event date ran backwards at $id" );
        break;
    }
    $prev = $on;
}

/* --- A dismissed import is on the screen, and is NOT in the work list. - */
$page = screen_html( array() );
if ( in_array( 202, rows_in( $page ), true ) ) {
    fail( 'a dismissed import is in the list of things waiting for a decision' );
}
if ( false === strpos( $page, 'data-uc-id="202" data-uc-kind="dismissed"' ) ) {
    fail( 'a dismissed import is nowhere on the screen, so Restore cannot be reached' );
}

/* --- The things the brief said must keep working, on the rows they belong
 *     to. Asserted on the RENDERED row rather than on the source, for the
 *     reason this whole section exists. ----------------------------------- */
$page = screen_html( array() );

/* Publish and Dismiss reach the import; Approve and Reject reach the
 * submissions. A row that lost its verb is a row nobody can act on. */
foreach ( array(
    'import_publish'  => 'Publish, on the imported event',
    'import_dismiss'  => 'Dismiss, on the imported event',
    'approve_event'   => 'Approve, on a submission',
    'reject_event'    => 'Reject, on a submission',
    'fetch_sources'   => 'Fetch updates',
    'import_restore'  => 'Restore, on the dismissed event',
) as $action => $what ) {
    if ( false === strpos( $page, 'value="' . $action . '"' ) ) {
        fail( "$what is gone from the queue" );
    }
}

/* The approval prompt still hangs off the submission rows it was built for. */
foreach ( array( 101, 102 ) as $id ) {
    if ( false === strpos( $page, 'uc-approve-ask-' . $id ) ) {
        fail( "the approval prompt is missing from submission $id" );
    }
}

/* Every tab is drawn, and each carries its own count. */
foreach ( array( 'Everything', 'Imported', 'Staff requests', 'Community submissions' ) as $tab ) {
    if ( false === strpos( $page, $tab ) ) {
        fail( "the '$tab' filter is not on the screen" );
    }
}

/* The kind badges from 3.46.0 are still on the rows, because the filter
 * narrows and the badge identifies: they are not alternatives. */
foreach ( array( 'Staff request', 'Community submission' ) as $badge ) {
    if ( false === strpos( $page, $badge ) ) {
        fail( "the '$badge' badge is gone from the rows" );
    }
}

/* =========================================================================
 * 7. THE LAST AUTOMATIC FETCH, AS RENDERED.
 *
 * The box exists to answer one question for somebody standing at this queue:
 * can I trust that what is in front of me is what is live at the source. Its
 * whole value is in the states nobody sees by looking — a source erroring every
 * quarter of an hour, or nothing having run since yesterday — and those states
 * cannot be reached by using the software, only by waiting for them. So they
 * are set here and the screen is asked what it drew.
 *
 * READ OUT OF THE HTML, for the reason section 6 gives at length: this project
 * has shipped several defects that every source-string check passed. A phrase
 * being in the file is not the phrase being on the page.
 * ====================================================================== */

/*
 * Adapter objects, not names. render_fetch_report() asks an adapter what it is
 * called and what it is waiting for, and a build with sources compiled in but
 * none connected is exactly the state it draws that in. Handing it strings made
 * every case in this section fail on a fatal instead of on its assertion, which
 * is how this stub came to be written properly.
 */
class Stub_Adapter {
    public $name;
    public function __construct( $name ) { $this->name = $name; }
    public function label() { return $this->name; }
    public function inactive_reason() { return 'no API key yet'; }
    public function is_active() { return false; }
}
$BUILT = array( new Stub_Adapter( 'Eventbrite' ), new Stub_Adapter( 'GoFundMe Pro' ) );

SFAF_Sources::$built = $BUILT;

/** The screen, with the fetch task in a given state. */
function fetch_box( $fetch ) {
    SFAF_Cron::$fetch = $fetch;
    return screen_html( array() );
}

/** A task_report() row, defaulted to a healthy run. */
function fetch_row( $over = array() ) {
    return array_merge( array(
        'key'     => 'fetch',
        'on'      => true,
        'off'     => 'Automated fetching is switched off.',
        'last'    => time() - 300,
        'last_ok' => time() - 300,
        'status'  => 'ok',
        'summary' => 'Eventbrite: nothing new. 12 checked.',
        'sources' => array(
            array( 'label' => 'Eventbrite',   'state' => 'ok', 'line' => 'Eventbrite: nothing new. 12 checked.' ),
            array( 'label' => 'GoFundMe Pro', 'state' => 'ok', 'line' => 'GoFundMe Pro: 2 events added. 9 checked.' ),
        ),
    ), $over );
}

/*
 * A QUIET RUN THAT WORKED. The wording rule for this one is explicit in the
 * brief and is the easiest to get wrong: a fetch that found nothing is a fetch
 * that worked, and must not read as a failure.
 */
$page = fetch_box( fetch_row() );
foreach ( array( 'Automatic fetching', 'Eventbrite: nothing new.', 'GoFundMe Pro: 2 events added.' ) as $want ) {
    if ( false === strpos( $page, $want ) ) {
        fail( "a healthy fetch does not show '$want'" );
    }
}
if ( false !== strpos( $page, 'uc-lastrun-alarm' ) ) {
    fail( 'a fetch that ran cleanly is drawn with an alarm on it' );
}

/*
 * ONE SOURCE DOWN. The failure has to be named and it has to be ABOVE the
 * per-source lines: a failure listed fourth among four sources is a failure
 * nobody reads. Position is asserted, not just presence.
 */
$page = fetch_box( fetch_row( array(
    'sources' => array(
        array( 'label' => 'Eventbrite',   'state' => 'ok',     'line' => 'Eventbrite: nothing new. 12 checked.' ),
        array( 'label' => 'GoFundMe Pro', 'state' => 'failed', 'line' => 'GoFundMe Pro: failed. the platform returned 503' ),
    ),
) ) );
if ( false === strpos( $page, 'GoFundMe Pro failed on the last run.' ) ) {
    fail( 'a source that failed is not named at the top of the box' );
}
if ( strpos( $page, 'GoFundMe Pro failed on the last run.' ) > strpos( $page, 'Eventbrite: nothing new.' ) ) {
    fail( 'the failure is below the per-source lines, which is where nobody reads it' );
}
if ( false === strpos( $page, 'uc-fetch-fail' ) ) {
    fail( 'the failed source row is not marked as failed' );
}

/*
 * NOTHING HAS WORKED FOR HOURS. 'last' is fresh and 'last_ok' is old, which is
 * the shape a fetch failing on every pass actually has. Reading the first as
 * the second would draw a healthy box over a queue a day behind the source.
 */
$page = fetch_box( fetch_row( array(
    'last'    => time() - 120,
    'last_ok' => time() - ( 5 * 3600 ),
    'status'  => 'failed',
    'sources' => array(
        array( 'label' => 'Eventbrite', 'state' => 'failed', 'line' => 'Eventbrite: failed. the platform returned 503' ),
    ),
) ) );
if ( false === strpos( $page, 'Nothing has fetched successfully since' ) ) {
    fail( 'a fetch that has not worked for five hours does not say so' );
}

/* An hour has not passed: a recent success must NOT be called stale. The
 * boundary is the whole point of the line, so it is tested from both sides. */
$page = fetch_box( fetch_row( array( 'last_ok' => time() - ( 20 * 60 ) ) ) );
if ( false !== strpos( $page, 'Nothing has fetched successfully since' ) ) {
    fail( 'a fetch that worked twenty minutes ago is reported as stale' );
}

/*
 * SWITCHED OFF IS NOT A FAULT. It is the one state with something to do about
 * it, so it says what to do and carries no alarm.
 */
$page = fetch_box( fetch_row( array( 'on' => false ) ) );
if ( false === strpos( $page, 'Automatic fetching is switched off.' ) ) {
    fail( 'a switched-off fetch does not say so on the queue it would fill' );
}
if ( false !== strpos( $page, 'uc-lastrun-alarm' ) ) {
    fail( 'a fetch that is switched off on purpose is dressed as a fault' );
}

/*
 * A RUN FROM BEFORE 3.57.0 has a summary and no breakdown. It must still show
 * the sentence rather than an empty box, and must not be split on ' | ' — an
 * error string from a platform can contain one.
 */
$page = fetch_box( fetch_row( array(
    'sources' => array(),
    'summary' => 'Eventbrite: failed. upstream said: 503 | retry later',
) ) );
if ( false === strpos( $page, 'upstream said: 503 | retry later' ) ) {
    fail( 'a run logged before the breakdown existed shows nothing at all' );
}

/* And on a build with no sources compiled in there is nothing to report on. */
SFAF_Sources::$built = array();
$page = fetch_box( fetch_row() );
if ( false !== strpos( $page, 'Automatic fetching' ) ) {
    fail( 'a build with no sources still draws a fetch box' );
}
SFAF_Sources::$built = $BUILT;

if ( $self ) {
    echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    $bad = 0;

    /* The stub must filter on STATUS, or "the queue" is every post. */
    $probe = new WP_Query( array( 'post_status' => 'publish' ) );
    if ( 1 === count( $probe->posts ) && 104 === (int) $probe->posts[0]->ID ) {
        echo "ok       the query stub filters on post_status\n";
    } else {
        echo 'BROKEN:  the status filter returned ' . count( $probe->posts ) . " rows; every assertion above is vacuous\n";
        $bad++;
    }

    /* And on AUTHOR, which is the whole subject of this file. */
    $scoped = new WP_Query( array( 'post_status' => array( 'pending' ), 'author' => $MARK ) );
    if ( 1 === count( $scoped->posts ) && 103 === (int) $scoped->posts[0]->ID ) {
        echo "ok       the query stub filters on author\n";
    } else {
        echo 'BROKEN:  the author filter returned ' . count( $scoped->posts ) . " rows, so an author-scoped query cannot be told from an unscoped one\n";
        $bad++;
    }

    /* And the two must be distinguishable, or sections 1 and 2 are the same
     * assertion written twice. */
    $unscoped = new WP_Query( array( 'post_status' => array( 'pending' ) ) );
    if ( count( $unscoped->posts ) > count( $scoped->posts ) ) {
        echo "ok       an unscoped query returns more than an author-scoped one\n";
    } else {
        echo "BROKEN:  scoping by author changes nothing, so this file cannot see the fault it exists for\n";
        $bad++;
    }

    /* THE STUBBED META KEY MUST BE THE SHIPPING ONE. Section 5 asserts an
     * address reaches the list the notification system reads; if this constant
     * drifted from SFAF_Reminders' own, it would be asserting that the address
     * reached a string invented in this file. Read out of the real source
     * rather than trusted, because that is the whole risk of stubbing it. */
    $reminders_src = file_get_contents( $root . '/includes/class-sfaf-reminders.php' );
    if ( preg_match( "/const\s+NOTIFY_EMAILS_META\s*=\s*'([^']+)'/", $reminders_src, $m )
        && $m[1] === SFAF_Reminders::NOTIFY_EMAILS_META ) {
        echo "ok       the stubbed notification meta key matches the shipping one\n";
    } else {
        echo "BROKEN:  NOTIFY_EMAILS_META here is not what SFAF_Reminders declares; section 5 writes to nothing\n";
        $bad++;
    }

    /* The real class really loaded, and the real method really ran. */
    if ( class_exists( 'SFAF_Portal' ) && is_array( ids_for( array( 'status' => 'pending' ) ) ) ) {
        echo "ok       the real SFAF_Portal::query_events() is what is being called\n";
    } else {
        echo "BROKEN:  query_events() is not reachable, so nothing here tests the shipping code\n";
        $bad++;
    }

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the harness reads what it claims to read.\n" );
    exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "The pending queue, asked for the ids it actually returns\n";
echo str_repeat( '=', 72 ) . "\n";
echo "world:   3 events waiting, 2 of them authored by nobody because a public\n";
echo "         form made them, plus one approved submission still authored by nobody\n";
printf( "queue:   %d returned for the arguments render_pending() passes\n", count( $queue ) );
printf( "drawn:   %d rows in the rendered work list, from %d in the unfiltered set\n", count( $all ), count( $WORLD ) );
echo "filters: everything, imported, staff requests, community submissions\n";
echo "\n";

if ( empty( $fails ) ) {
    echo "every kind reaches the one list, and each is under its own filter and no other.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
