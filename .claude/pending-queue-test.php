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
function sfaf_ap_time_range( $a, $b ) { return $a . ' to ' . $b; }
function sfaf_icon( $n, $a = array() ) { return ''; }
function sfaf_event_image_url( $id, $s = 'large' ) { return ''; }
function sfaf_event_image_source( $id ) { return 'none'; }
function sfaf_fundraising_progress_meta_key() { return '_uc_show_fund_progress'; }
function sfaf_source_links_flag() { return false; }
function sfaf_set_source_links( $v ) {}
function _prime_post_caches( $ids, $a = true, $b = true ) {}

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
class SFAF_Sources {
    const STATUS_PENDING  = 'uc_imported';
    const META_REMOVED_AT = '_uc_removed_at';
    public static function provenance( $id ) {
        return array( 'source' => '', 'label' => '', 'external_id' => '', 'source_url' => '', 'image_url' => '', 'timezone' => '', 'imported_at' => 0 );
    }
    public static function queue_ids( $s ) { return array(); }
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

/* ---------------------------------------------------------------------------
 * SELF TEST. Every case is a shape this file has NOT already seen pass.
 * ------------------------------------------------------------------------ */
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
echo "\n";

if ( empty( $fails ) ) {
    echo "a submission from either form reaches the queue somebody actually opens.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
