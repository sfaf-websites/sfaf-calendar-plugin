<?php
/**
 * THE EVERYACTION IMPORT, AGAINST A MODEL HUB AND THE REAL PUBLIC LIST (3.101.0).
 *
 *     php .claude/everyaction-import-test.php
 *
 * WHAT IS REAL AND WHAT IS NOT.
 *
 *   Real: SFAF_Sources whole (run_all, run_adapter, find_existing, import,
 *   update, the removal step), SFAF_EveryAction, the adapter, SFAF_Series,
 *   SFAF_Venues, SFAF_Credentials, and sfaf_event_takes_rsvps() lifted from
 *   the template functions. The public list pages in fixtures/ are the real
 *   markup, saved 2026-09-24.
 *
 *   Modelled: WordPress's store (posts, meta, terms, options, and a WP_Query
 *   that filters on status and on meta clauses the way the real one does,
 *   including what 'any' leaves out), and the hub (everyaction-hub.php).
 *
 *   NOT a captured probe: fixtures/everyaction-tracker.json is built from the
 *   row shape the brief states, because no probe body was ever recorded. It
 *   says so in its own _about.
 *
 * THE DATES MOVE WITH THE CLOCK. The fixture's {Y} is next year, so every row
 * is upcoming whenever this runs, and October and November of one year put the
 * clock change between two rows. The "ended earlier today" row is made from
 * now; it is only wrong in the first minute after midnight Pacific.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ . '/' );
date_default_timezone_set( 'America/Los_Angeles' );
error_reporting( E_ALL );

$fails = array();
function check( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function same( $label, $got, $want ) {
    check( $got === $want, sprintf( '%s: got %s, wanted %s', $label, var_export( $got, true ), var_export( $want, true ) ) );
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
function world_reset() {
    $GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['next'] = 500;
    $GLOBALS['terms'] = array(); $GLOBALS['tmeta'] = array(); $GLOBALS['obj_terms'] = array(); $GLOBALS['next_term'] = 900;
    $GLOBALS['opt'] = array();
}
world_reset();

class WP_Error {
    private $c; private $m;
    public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
    public function get_error_message() { return $this->m; }
    public function get_error_code() { return $this->c; }
}
class WP_Term { public $term_id; public $name; public $slug; public $taxonomy; public $description = ''; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function apply_filters( $t, $v ) { return $v; }
function add_action() {} function add_filter() {} function do_action() {}
function __( $t, $d = '' ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $t ) { return (string) $t; }
function wp_kses_post( $t ) { return (string) $t; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $k ) ); }
function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function wp_list_pluck( $l, $f ) { $o = array(); foreach ( (array) $l as $i ) { $o[] = is_object( $i ) ? $i->$f : $i[ $f ]; } return $o; }
function add_query_arg( $args, $url ) {
    return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}
function wp_timezone() { return new DateTimeZone( 'America/Los_Angeles' ); }
function wp_timezone_string() { return 'America/Los_Angeles'; }
function current_time( $t, $g = 0 ) { return 'mysql' === $t ? date( 'Y-m-d H:i:s' ) : time(); }
function get_current_user_id() { return 1; }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opt'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $d = '', $a = null ) { if ( isset( $GLOBALS['opt'][ $n ] ) ) { return false; } $GLOBALS['opt'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['opt'][ $n ] ); return true; }

function get_post_meta( $id, $k = '', $s = false ) { return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ (int) $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ (int) $id ][ $k ] ); return true; }
function get_post( $id = 0 ) {
    $id = (int) ( is_object( $id ) ? $id->ID : $id );
    return isset( $GLOBALS['posts'][ $id ] ) ? (object) array_merge( array( 'ID' => $id ), $GLOBALS['posts'][ $id ] ) : null;
}
function get_post_status( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]['post_status'] : false; }
function get_post_field( $f, $id ) { return isset( $GLOBALS['posts'][ $id ][ $f ] ) ? $GLOBALS['posts'][ $id ][ $f ] : ''; }
function get_the_title( $id = 0 ) { return get_post_field( 'post_title', $id ); }
function wp_insert_post( $a, $e = false ) {
    $id = $GLOBALS['next']++;
    $GLOBALS['posts'][ $id ] = array(
        'post_type'    => isset( $a['post_type'] ) ? $a['post_type'] : 'post',
        'post_status'  => isset( $a['post_status'] ) ? $a['post_status'] : 'draft',
        'post_title'   => isset( $a['post_title'] ) ? $a['post_title'] : '',
        'post_content' => isset( $a['post_content'] ) ? $a['post_content'] : '',
    );
    return $id;
}
function wp_update_post( $a, $e = false ) {
    $id = (int) $a['ID'];
    foreach ( array( 'post_status', 'post_title', 'post_content' ) as $f ) {
        if ( isset( $a[ $f ] ) && isset( $GLOBALS['posts'][ $id ] ) ) { $GLOBALS['posts'][ $id ][ $f ] = $a[ $f ]; }
    }
    return $id;
}

/**
 * WP_Query: post_type, post_status (a list, or 'any' as WordPress means it:
 * every status except trash and those registered exclude_from_search, which
 * both queue statuses are), and meta clauses ANDed. That is all this code asks.
 */
class WP_Query {
    public $posts = array();
    public function __construct( $a ) {
        $st  = isset( $a['post_status'] ) ? $a['post_status'] : 'publish';
        $out = array();
        foreach ( $GLOBALS['posts'] as $id => $p ) {
            if ( isset( $a['post_type'] ) && $p['post_type'] !== $a['post_type'] ) { continue; }
            if ( 'any' === $st ) {
                if ( in_array( $p['post_status'], array( 'trash', 'uc_imported', 'uc_dismissed' ), true ) ) { continue; }
            } elseif ( ! in_array( $p['post_status'], (array) $st, true ) ) {
                continue;
            }
            $ok = true;
            foreach ( isset( $a['meta_query'] ) ? $a['meta_query'] : array() as $k => $c ) {
                if ( 'relation' === $k || ! is_array( $c ) ) { continue; }
                $have = isset( $GLOBALS['meta'][ $id ][ $c['key'] ] ) ? (string) $GLOBALS['meta'][ $id ][ $c['key'] ] : null;
                if ( null === $have || ( isset( $c['value'] ) && $have !== (string) $c['value'] ) ) { $ok = false; }
            }
            if ( $ok ) { $out[] = (int) $id; }
        }
        if ( isset( $a['posts_per_page'] ) && $a['posts_per_page'] > 0 ) { $out = array_slice( $out, 0, (int) $a['posts_per_page'] ); }
        $this->posts = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? $out : array_map( 'get_post', $out );
    }
}

/* Terms. */
function wp_insert_term( $name, $tax, $args = array() ) {
    foreach ( $GLOBALS['terms'] as $t ) {
        if ( $t->taxonomy === $tax && $t->name === $name ) { return new WP_Error( 'term_exists', 'A term with the name provided already exists.' ); }
    }
    $t = new WP_Term(); $t->term_id = $GLOBALS['next_term']++; $t->name = $name; $t->slug = sanitize_title( $name ); $t->taxonomy = $tax;
    $GLOBALS['terms'][ $t->term_id ] = $t;
    return array( 'term_id' => $t->term_id, 'term_taxonomy_id' => $t->term_id );
}
function get_term( $id, $tax = '' ) { $id = (int) $id; return ( isset( $GLOBALS['terms'][ $id ] ) && ( '' === $tax || $GLOBALS['terms'][ $id ]->taxonomy === $tax ) ) ? $GLOBALS['terms'][ $id ] : null; }
function get_term_by( $f, $v, $tax = '' ) {
    foreach ( $GLOBALS['terms'] as $t ) {
        if ( $t->taxonomy === $tax && ( ( 'name' === $f && $t->name === $v ) || ( 'slug' === $f && $t->slug === $v ) || ( 'id' === $f && $t->term_id === (int) $v ) ) ) { return $t; }
    }
    return false;
}
function term_exists( $t, $tax = '' ) { return get_term( $t, $tax ) ? array( 'term_id' => (int) $t ) : null; }
function get_terms( $a = array() ) {
    $out = array();
    foreach ( $GLOBALS['terms'] as $t ) {
        if ( isset( $a['taxonomy'] ) && $t->taxonomy !== $a['taxonomy'] ) { continue; }
        if ( isset( $a['meta_key'] ) && (string) get_term_meta( $t->term_id, $a['meta_key'], true ) !== (string) $a['meta_value'] ) { continue; }
        $out[] = $t;
    }
    if ( ! empty( $a['number'] ) ) { $out = array_slice( $out, 0, (int) $a['number'] ); }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'term_id' ) : $out;
}
function get_term_meta( $id, $k = '', $s = false ) { return isset( $GLOBALS['tmeta'][ (int) $id ][ $k ] ) ? $GLOBALS['tmeta'][ (int) $id ][ $k ] : ''; }
function update_term_meta( $id, $k, $v ) { $GLOBALS['tmeta'][ (int) $id ][ $k ] = $v; return true; }
function wp_set_object_terms( $id, $terms, $tax, $append = false ) { $GLOBALS['obj_terms'][ (int) $id ][ $tax ] = array_values( array_map( 'intval', (array) $terms ) ); return $GLOBALS['obj_terms'][ (int) $id ][ $tax ]; }
function wp_get_object_terms( $id, $tax, $a = array() ) {
    $ids = isset( $GLOBALS['obj_terms'][ (int) $id ][ $tax ] ) ? $GLOBALS['obj_terms'][ (int) $id ][ $tax ] : array();
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { return $ids; }
    return array_values( array_filter( array_map( 'get_term', $ids ) ) );
}
function get_the_terms( $id, $tax ) { $t = wp_get_object_terms( $id, $tax ); return $t ? $t : false; }

/* What the framework calls and nothing here is testing. */
function sfaf_get_faqs( $id ) { return array(); }
function sfaf_faq_meta_key() { return '_uc_faqs'; }
function sfaf_flatten_html( $h ) { return trim( strip_tags( (string) $h ) ); }
function sfaf_show_feature( $id, $f ) { return true; }
function sfaf_event_location( $id ) {
    $line = (string) get_post_meta( $id, '_uc_location', true ); $name = (string) get_post_meta( $id, '_uc_location_name', true );
    return '' === $name ? $line : ( '' === $line ? $name : $name . ', ' . $line );
}

/* ---------------------------------------------------------------------------
 * The plugin, the real classes, and two readers lifted from source.
 * ------------------------------------------------------------------------ */
require __DIR__ . '/everyaction-hub.php';
foreach ( array( 'class-sfaf-credentials', 'class-sfaf-sources', 'class-sfaf-series', 'class-sfaf-venues', 'class-sfaf-everyaction', 'class-sfaf-source-everyaction' ) as $f ) {
    require_once $root . '/includes/' . $f . '.php';
}

function lift( $src, $needle ) {
    $at = strpos( $src, $needle );
    if ( false === $at ) { echo "FAIL: could not find $needle in the source, so this test is reading nothing.\n"; exit( 1 ); }
    $depth = 0;
    for ( $i = strpos( $src, '{', $at ); $i < strlen( $src ); $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] && 0 === --$depth ) { return substr( $src, $at, $i - $at + 1 ); }
    }
    echo "FAIL: $needle never closes.\n"; exit( 1 );
}
$tpl = file_get_contents( $root . '/includes/sfaf-template-functions.php' );
eval( lift( $tpl, 'function sfaf_location_part_keys(' ) );
eval( lift( $tpl, 'function sfaf_event_takes_rsvps(' ) );

SFAF_Sources::register_adapter( new SFAF_Source_EveryAction() );

/* ---------------------------------------------------------------------------
 * The hub and the list.
 * ------------------------------------------------------------------------ */
const TOK = 'SESSION-TOKEN-ea101';
$Y = (int) date( 'Y' ) + 1;

$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/everyaction-tracker.json' ), true );
$ROWS = json_decode( str_replace( '{Y}', (string) $Y, json_encode( $fixture['rows'] ) ), true );
$LIST = array(
    1 => file_get_contents( __DIR__ . '/fixtures/asevents-page-1.html' ),
    2 => file_get_contents( __DIR__ . '/fixtures/asevents-page-15.html' ),
);

/** A tracker time, "MM/DD/YYYY hh:mm AM +0000", for a moment given in Pacific time. */
function utc_stamp( $pacific ) {
    $d = new DateTime( $pacific, new DateTimeZone( 'America/Los_Angeles' ) );
    $d->setTimezone( new DateTimeZone( 'UTC' ) );
    return $d->format( 'm/d/Y h:i A' ) . ' +0000';
}
function past_rows() {
    $today = date( 'Y-m-d' );
    return array(
        array( 'UUID' => '999000101', 'Series_ID' => 'OLD', 'Title' => 'Yesterday\'s social',
               'Start_Time' => utc_stamp( 'yesterday 10:00' ), 'End_Time' => utc_stamp( 'yesterday 12:00' ), 'Location_Name' => '' ),
        array( 'UUID' => '999000102', 'Series_ID' => 'OLD', 'Title' => 'This morning\'s early walk',
               'Start_Time' => utc_stamp( $today . ' 00:00:10' ), 'End_Time' => utc_stamp( $today . ' 00:00:40' ), 'Location_Name' => '' ),
    );
}

/** The tracker answers from $GLOBALS['tracker_pages']: page => rows, 'bare' for no page. */
function hub_with( $pages ) {
    $GLOBALS['tracker_pages'] = $pages;
    $GLOBALS['hub'] = array(
        'login'   => hub_resp( 200, json_encode( array( 'ms_response' => array( 'user' => array( '_token' => TOK ) ) ) ) ),
        'tracker' => function ( $args, $url ) {
            parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
            $k = isset( $q['page'] ) ? (int) $q['page'] : 'bare';
            $rows = isset( $GLOBALS['tracker_pages'][ $k ] ) ? $GLOBALS['tracker_pages'][ $k ] : array();
            return hub_resp( 200, json_encode( array( 'ms_response' => array( 'data' => $rows ) ) ) );
        },
        'logout'  => hub_resp( 200, '{}' ),
        'list'    => function ( $args, $url ) {
            parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
            $n = isset( $q['pn'] ) ? (int) $q['pn'] : 1;
            $pages = $GLOBALS['list_pages'];
            // Past its last page the real list shows page one again.
            return hub_resp( 200, isset( $pages[ $n ] ) ? $pages[ $n ] : $pages[1], 'text/html; charset=utf-8' );
        },
    );
}
function creds() {
    update_option( SFAF_Credentials::OPTION, array(
        'everyaction_hub_url' => 'https://hub.example.org', 'everyaction_api_key' => 'k3y0000000000000000000000000000000000abc',
        'everyaction_username' => 'svc-calendar@sfaf.org', 'everyaction_password' => 'p@ss w0rd!x ', 'everyaction_tracker_id' => '162570',
    ) );
}
function ea_posts() {
    $out = array();
    foreach ( $GLOBALS['posts'] as $id => $p ) {
        if ( 'everyaction' === get_post_meta( $id, SFAF_Sources::META_SOURCE, true ) ) { $out[ (string) get_post_meta( $id, SFAF_Sources::META_EXTERNAL_ID, true ) ][] = $id; }
    }
    return $out;
}
function by_uuid( $uuid ) { $p = ea_posts(); return isset( $p[ $uuid ] ) ? $p[ $uuid ][0] : 0; }
function run_ea( $trigger = 'manual' ) {
    $GLOBALS['hub_seen'] = array();
    foreach ( SFAF_Sources::run_all( $trigger ) as $r ) {
        if ( 'everyaction' === $r['slug'] ) { return $r; }
    }
    return null;
}
function hub_paths() {
    return array_map( function ( $s ) { return $s['method'] . ' ' . parse_url( $s['url'], PHP_URL_HOST ) . parse_url( $s['url'], PHP_URL_PATH ) . ( parse_url( $s['url'], PHP_URL_QUERY ) ? '?' . parse_url( $s['url'], PHP_URL_QUERY ) : '' ); }, $GLOBALS['hub_seen'] );
}

/* ===========================================================================
 * 1. THE TIMES ARE UTC, AND THE CLOCK CHANGE IS BETWEEN TWO OF THEM.
 * ======================================================================== */
$oct = SFAF_Source_EveryAction::to_local( "10/09/$Y 05:00 PM +0000" );
$nov = SFAF_Source_EveryAction::to_local( "11/13/$Y 06:00 PM +0000" );
same( 'October, 5 pm UTC', $oct ? $oct->format( 'Y-m-d H:i' ) : null, "$Y-10-09 10:00" );
same( 'November, 6 pm UTC', $nov ? $nov->format( 'Y-m-d H:i' ) : null, "$Y-11-13 10:00" );
$late = SFAF_Source_EveryAction::to_local( "10/28/$Y 01:00 AM +0000" );
same( 'a UTC date after midnight is the evening before here', $late ? $late->format( 'Y-m-d H:i' ) : null, "$Y-10-27 18:00" );
same( '12 AM is midnight', SFAF_Source_EveryAction::to_local( "01/05/$Y 12:15 AM +0000" )->format( 'H:i' ), '16:15' );
same( '12 PM is noon', SFAF_Source_EveryAction::to_local( "07/05/$Y 12:15 PM +0000" )->format( 'H:i' ), '05:15' );
same( 'a shape it does not know is refused, not guessed', SFAF_Source_EveryAction::to_local( "$Y-10-09T17:00:00Z" ), null );

/* ===========================================================================
 * 2. THE FIRST FETCH, BY HAND, WITH AUTO-IMPORT OFF.
 * ======================================================================== */
world_reset();
creds();
$GLOBALS['list_pages'] = $LIST;
hub_with( array( 'bare' => array_merge( $ROWS, past_rows() ) ) );

$r = run_ea( 'manual' );
check( is_array( $r ), 'EveryAction did not run on a manual fetch' );
same( 'first fetch: rows read', $r['fetched'], 7 );
same( 'first fetch: created', $r['new'], 5 );
same( 'first fetch: refused as past (yesterday, and earlier today)', $r['refused'], 2 );
same( 'first fetch: nothing failed', array( $r['failed'], $r['invalid'], $r['error'] ), array( 0, 0, '' ) );
check( ! by_uuid( '999000101' ) && ! by_uuid( '999000102' ), 'a row whose end time has passed was imported' );
same( 'the hub calls: login, one read, logout, then the list twice', hub_paths(), array(
    'POST hub.example.org/api/login.json', 'GET hub.example.org/api/trackers/forms/get_submissions/162570', 'POST hub.example.org/api/logout',
    'GET 50plus.sfaf.org/a/asevents', 'GET 50plus.sfaf.org/a/asevents?pn=2',
) );

$c1 = by_uuid( '750059398' ); $c2 = by_uuid( '750059399' ); $am = by_uuid( '750059054' ); $meal = by_uuid( '750059773' ); $walk = by_uuid( '999000002' );
foreach ( array( $c1, $c2, $am, $meal, $walk ) as $id ) {
    same( "#$id waits in the pending queue", get_post_status( $id ), 'uc_imported' );
}
same( 'October: date and times, Pacific', array( get_post_meta( $c1, '_uc_event_date' ), get_post_meta( $c1, '_uc_start_time' ), get_post_meta( $c1, '_uc_end_time' ) ), array( "$Y-10-09", '10:00', '12:00' ) );
same( 'November: the same wall clock across the change', array( get_post_meta( $c2, '_uc_event_date' ), get_post_meta( $c2, '_uc_start_time' ) ), array( "$Y-11-13", '10:00' ) );
same( 'the meal: the evening before, Pacific', array( get_post_meta( $meal, '_uc_event_date' ), get_post_meta( $meal, '_uc_start_time' ), get_post_meta( $meal, '_uc_end_time' ) ), array( "$Y-10-27", '18:00', '20:00' ) );
same( 'the timezone stored', get_post_meta( $c1, SFAF_Sources::META_TIMEZONE ), 'America/Los_Angeles' );

/* The signup links, from the real list's markup. */
same( 'first entry on the list', get_post_meta( $c1, SFAF_Sources::META_SOURCE_URL ), 'https://han.sfaf.org/a/50-plus-saturday-am-coffee-social-12' );
same( 'fourth entry on the list, its own link and not the first', get_post_meta( $c2, SFAF_Sources::META_SOURCE_URL ), 'https://han.sfaf.org/a/50-plus-saturday-am-coffee-social-13' );
same( 'the other coffee social', get_post_meta( $am, SFAF_Sources::META_SOURCE_URL ), 'https://han.sfaf.org/a/saturday-am-coffee-social-8' );
same( 'the meal', get_post_meta( $meal, SFAF_Sources::META_SOURCE_URL ), 'https://han.sfaf.org/a/monthly-community-meal' );
same( 'not on the list: the list, on its day', get_post_meta( $walk, SFAF_Sources::META_SOURCE_URL ), "https://50plus.sfaf.org/a/asevents?date_start=12-04-$Y&date_end=12-04-$Y" );
same( 'the list: thirteen pairs from two pages', count( SFAF_EveryAction::links() ), 13 );
same( 'a pair from the short last page', SFAF_EveryAction::matched_url( '750059489' ), 'https://han.sfaf.org/a/50-plus-saturday-am-coffee-social-103' );
$run = SFAF_EveryAction::links_run();
same( 'the panel\'s match count: four of five upcoming', array( $run['matched'], $run['upcoming'] ), array( 4, 5 ) );

/* The place. */
same( 'place name: before the first comma', get_post_meta( $c1, '_uc_location_name' ), 'Maxfield\'s House of Caffeine' );
same( 'the address from its four parts', array( get_post_meta( $c1, '_uc_location_street' ), get_post_meta( $c1, '_uc_location_city' ), get_post_meta( $c1, '_uc_location_state' ), get_post_meta( $c1, '_uc_location_zip' ), get_post_meta( $c1, '_uc_location' ) ),
    array( '398 Dolores St', 'San Francisco', 'CA', '94110', '398 Dolores St, San Francisco, CA 94110' ) );
same( 'no parts: the rest of the composed string is the address', array( get_post_meta( $meal, '_uc_location_name' ), get_post_meta( $meal, '_uc_location' ) ), array( 'SFAF', '1035 Market St, San Francisco, CA 94103' ) );

/* The description is seeded once, and only when the tracker has one. */
same( 'the meal\'s description, from the tracker', get_post_field( 'post_content', $meal ), 'Dinner with Friends, our community meal.' );
same( 'an empty tracker description writes nothing', get_post_field( 'post_content', $c1 ), '' );

/* The series. */
$s1 = SFAF_Series::id_for_event( $c1 );
check( $s1 && $s1 === SFAF_Series::id_for_event( $c2 ), 'two rows with one Series_ID are not in one series' );
same( 'the series is named from the program', $s1 ? SFAF_Series::get( $s1 )->name : '', '50-Plus Saturday AM Coffee Social' );
check( SFAF_Series::id_for_event( $am ) && SFAF_Series::id_for_event( $am ) !== $s1, 'a second Series_ID joined the first series' );
same( 'four programs, four series', count( get_terms( array( 'taxonomy' => 'uc_series' ) ) ), 4 );

/* Visibility, and the report. */
check( (bool) preg_grep( '/1 row is marked "Internal" at the source/', $r['notes'] ), 'the Internal row was not said in the report: ' . implode( ' | ', $r['notes'] ) );
check( false !== strpos( SFAF_Sources::summarize( $r ), '5 events added' ), 'the summary: ' . SFAF_Sources::summarize( $r ) );
$f = SFAF_EveryAction::last_fetch();
same( 'the panel\'s last fetch', array( $f['rows'], $f['new'], $f['updated'], $f['error'] ), array( 7, 5, 0, '' ) );

/* Registration stays at the source. */
update_post_meta( $c1, '_uc_rsvp_enabled', '1' );
same( 'an imported EveryAction event takes no RSVP here', sfaf_event_takes_rsvps( $c1 ), false );
same( 'its button goes to its signup page', SFAF_Sources::registration_url( $c1 ), 'https://han.sfaf.org/a/50-plus-saturday-am-coffee-social-12' );

/* ===========================================================================
 * 3. THE SECOND FETCH: UPDATE BY UUID, NEVER DUPLICATE, NEVER TOUCH A MANAGER'S.
 * ======================================================================== */
wp_update_post( array( 'ID' => $c1, 'post_status' => 'publish' ) );
wp_update_post( array( 'ID' => $am, 'post_content' => 'Written by a manager.' ) );
$mine = wp_insert_term( 'A series a manager chose', 'uc_series' );
SFAF_Series::set_for_event( $c2, $mine['term_id'] );

$again = $ROWS;
$again[0]['Title'] = '50-Plus Coffee Social, renamed';      // same UUID, new title
$again[1]['Start_Time'] = "11/13/$Y 07:00 PM +0000";         // same UUID, moved an hour
$again[2]['Description'] = 'The source changed its words.';
$again[] = array_merge( $ROWS[4], array( 'UUID' => '999000003', 'Start_Time' => "12/11/$Y 06:30 PM +0000", 'End_Time' => "12/11/$Y 08:00 PM +0000" ) ); // same title, new UUID
hub_with( array( 'bare' => $again ) );

$r2 = run_ea( 'manual' );
same( 'second fetch: one new row, the same title under a new UUID', $r2['new'], 1 );
same( 'second fetch: two updated', $r2['updated'], 2 );
foreach ( ea_posts() as $uuid => $ids ) {
    same( "one event for UUID $uuid", count( $ids ), 1 );
}
same( 'renamed at the source, renamed here, same event', array( by_uuid( '750059398' ), get_the_title( $c1 ) ), array( $c1, '50-Plus Coffee Social, renamed' ) );
same( 'a refresh never changes whether an event is live', get_post_status( $c1 ), 'publish' );
same( 'moved an hour at the source', get_post_meta( $c2, '_uc_start_time' ), '11:00' );
same( 'a manager\'s description survives the source changing its own', get_post_field( 'post_content', $am ), 'Written by a manager.' );
same( 'a series a manager chose survives', SFAF_Series::id_for_event( $c2 ), (int) $mine['term_id'] );
check( by_uuid( '999000003' ) && by_uuid( '999000003' ) !== $walk, 'a second event with the walk\'s title was merged into the first' );
same( 'a new occurrence joins its program\'s series', SFAF_Series::id_for_event( by_uuid( '999000003' ) ), SFAF_Series::id_for_event( $walk ) );

/* A third run of the same thing changes nothing at all. */
$r3 = run_ea( 'manual' );
same( 'third fetch: nothing new, nothing updated', array( $r3['new'], $r3['updated'], $r3['unchanged'] ), array( 0, 0, 6 ) );

/* ===========================================================================
 * 4. A VENUE A PERSON CHOSE IS NOT JOINED BY TEXT AGAIN.
 * ======================================================================== */
$venue = wp_insert_term( 'Maxfield\'s', 'uc_venue' );
SFAF_Venues::set_for_event( $meal, $venue['term_id'] );
foreach ( array_merge( array( '_uc_location', '_uc_location_name' ), array_values( sfaf_location_part_keys() ) ) as $k ) { delete_post_meta( $meal, $k ); }
run_ea( 'manual' );
same( 'after promotion, the fetch leaves the event pointing at the venue only', array( get_post_meta( $meal, '_uc_location' ), get_post_meta( $meal, '_uc_location_name' ) ), array( '', '' ) );

/* ===========================================================================
 * 5. NOTHING FROM THE HUB UNPUBLISHES NOTHING. A ROW GONE FROM A WHOLE LIST DOES.
 * ======================================================================== */
hub_with( array( 'bare' => array() ) );
$adapter = SFAF_Sources::adapter( 'everyaction' );
$said = $adapter->fetch();
same( 'the adapter\'s own verdict on an empty tracker: not complete', $said['complete'], false );
$r5 = run_ea( 'manual' );
same( 'an empty tracker: nothing unpublished', $r5['unpublished'], 0 );
same( 'an empty tracker: the live event is still live', get_post_status( $c1 ), 'publish' );
check( '' !== $r5['removal_skip'], 'the removal step did not say why it was skipped' );

hub_with( array( 'bare' => array_slice( $again, 1 ) ) );   // everything but the published one
$r6 = run_ea( 'manual' );
same( 'a whole list without it: the published event is taken off the calendar', array( $r6['unpublished'], get_post_status( $c1 ) ), array( 1, 'draft' ) );

/* ===========================================================================
 * 6. PAGES. THE PARAMETER IS NOT DOCUMENTED, SO IT IS CHECKED, NOT TRUSTED.
 * ======================================================================== */
function synth( $from, $n ) {
    $out = array();
    for ( $i = $from; $i < $from + $n; $i++ ) { $out[] = array( 'UUID' => (string) ( 880000000 + $i ), 'Title' => "Row $i" ); }
    return $out;
}
$cfg = SFAF_EveryAction::config();

hub_with( array( 'bare' => synth( 0, 100 ), 1 => synth( 0, 100 ), 2 => synth( 100, 30 ) ) );
$read = SFAF_EveryAction::read_rows( $cfg, TOK );
same( 'pages from 1: every row once', array( count( $read['rows'] ), $read['complete'], $read['reads'] ), array( 130, true, 3 ) );

hub_with( array( 'bare' => synth( 0, 100 ), 1 => synth( 100, 100 ), 2 => synth( 200, 5 ) ) );
$read = SFAF_EveryAction::read_rows( $cfg, TOK );
same( 'pages from 0: nothing skipped', array( count( $read['rows'] ), $read['complete'] ), array( 205, true ) );

hub_with( array( 'bare' => synth( 0, 100 ), 1 => synth( 0, 100 ), 2 => synth( 0, 100 ), 3 => synth( 0, 100 ) ) );
$read = SFAF_EveryAction::read_rows( $cfg, TOK );
same( 'a hub that ignores the page: stops, and says the list may be incomplete', array( count( $read['rows'] ), $read['complete'], $read['reads'] ), array( 100, false, 3 ) );
check( '' !== $read['reason'], 'an ignored page parameter gave no reason' );

hub_with( array( 'bare' => synth( 0, 100 ), 1 => synth( 0, 100 ), 2 => array() ) );
$read = SFAF_EveryAction::read_rows( $cfg, TOK );
same( 'exactly a hundred rows, then an empty page: complete', array( count( $read['rows'] ), $read['complete'] ), array( 100, true ) );

/* ===========================================================================
 * 7. THE LIST: WHERE IT ENDS, WHAT IT KEEPS, AND WHAT A FAILED READ DOES.
 * ======================================================================== */
world_reset();
creds();
$GLOBALS['list_pages'] = array( 1 => $LIST[1], 2 => $LIST[1] );   // two full pages alike: the list going round
hub_with( array() );
$GLOBALS['hub_seen'] = array();
$links = SFAF_EveryAction::read_links();
same( 'a list that goes back to page one: read stops, no loop', array( $links['ok'], $links['pages'], count( $links['pairs'] ) ), array( true, 2, 10 ) );

$GLOBALS['list_pages'] = $LIST;
SFAF_EveryAction::refresh_links();
same( 'a good read keeps thirteen', count( SFAF_EveryAction::links() ), 13 );
$GLOBALS['hub']['list'] = hub_resp( 503, '<html>Down</html>', 'text/html' );
$bad = SFAF_EveryAction::refresh_links();
same( 'a failed read keeps the last good set', array( $bad['ok'], count( SFAF_EveryAction::links() ) ), array( false, 13 ) );
check( false !== strpos( $bad['message'], 'HTTP 503' ), 'a failed read did not say what failed: ' . $bad['message'] );

/* An event on its day's list gets its own page when the list shows it, and
 * keeps its own page when the list stops showing it. */
$GLOBALS['list_pages'] = $LIST;
hub_with( array( 'bare' => $ROWS ) );
run_ea( 'manual' );
$walk = by_uuid( '999000002' );
check( SFAF_EveryAction::is_list_url( get_post_meta( $walk, SFAF_Sources::META_SOURCE_URL ) ), 'the walk did not start on its day\'s list' );
$GLOBALS['list_pages'] = array( 1 => str_replace( 'data-event-id="750059402"', 'data-event-id="999000002"', $LIST[1] ), 2 => $LIST[2] );
update_option( SFAF_EveryAction::LINKS_RUN_OPTION, array() );
$daily = SFAF_EveryAction::run_links();
same( 'the daily read gives the walk its own page', get_post_meta( $walk, SFAF_Sources::META_SOURCE_URL ), 'https://han.sfaf.org/a/50-plus-saturday-am-coffee-social-16' );
same( 'the daily read is paced to once a day', SFAF_EveryAction::run_links()['summary'], 'Read within the last day already.' );
$GLOBALS['list_pages'] = $LIST;
SFAF_EveryAction::refresh_links();
run_ea( 'manual' );
same( 'gone from the list: the walk keeps its own page', get_post_meta( $walk, SFAF_Sources::META_SOURCE_URL ), 'https://han.sfaf.org/a/50-plus-saturday-am-coffee-social-16' );

/* ===========================================================================
 * 8. AUTO-IMPORT GATES THE RUNNER, NOT THE BUTTON.
 * ======================================================================== */
$held = run_ea( 'scheduled' );
same( 'scheduled, Auto-Import off: held, and the hub not called', array( ! empty( $held['held'] ), count( $GLOBALS['hub_seen'] ) ), array( true, 0 ) );
same( 'what the log says', SFAF_Sources::summarize( $held ), 'EveryAction: Auto-Import is off. A manual fetch still reads it.' );
update_option( 'uc_settings', array( 'everyaction_auto_import' => '1' ) );
$ran = run_ea( 'scheduled' );
check( empty( $ran['held'] ) && count( $GLOBALS['hub_seen'] ) >= 3, 'scheduled, Auto-Import on: EveryAction did not run' );
update_option( 'uc_settings', array() );
same( 'no hub login: not active at all', SFAF_Sources::adapter( 'everyaction' )->is_active(), true );
delete_option( SFAF_Credentials::OPTION );
same( 'no hub login: not active at all', SFAF_Sources::adapter( 'everyaction' )->is_active(), false );

/* ===========================================================================
 * 9. NO CREDENTIAL IN ANYTHING STORED.
 * ======================================================================== */
$stored = json_encode( array( $GLOBALS['opt'][ SFAF_EveryAction::FETCH_OPTION ], $GLOBALS['opt'][ SFAF_EveryAction::LINKS_RUN_OPTION ], $GLOBALS['meta'] ) );
foreach ( array( 'k3y0000000000000000000000000000000000abc', 'p@ss w0rd!x', TOK ) as $secret ) {
    check( false === strpos( $stored, $secret ), 'a credential reached stored data' );
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "EveryAction import: UTC times land at 10 am Pacific either side of the clock change; past rows are refused;\n";
echo "a first manual fetch fills the queue with signup links from the real list, place names, series by Series_ID\n";
echo "and a seeded description; a second fetch updates by UUID and never by title, duplicates nothing and leaves a\n";
echo "manager's description, series and venue alone; an empty tracker unpublishes nothing; undocumented pages are\n";
echo "checked, not trusted; the list stops when it goes round; Auto-Import gates only the runner; no credential is stored.\n";
exit( 0 );
