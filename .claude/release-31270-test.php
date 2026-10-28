<?php
/**
 * 3.127.0: LITESPEED CACHE IS TOLD WHAT TO DROP, AND HOW OFTEN.
 *
 *     php .claude/release-31270-test.php            the checks, and the counts
 *
 * WHAT IS REAL AND WHAT IS NOT.
 *
 *   Real: SFAF_Embed (its register() and every flush callback) and
 *   SFAF_LiteSpeed, from includes/.
 *
 *   Modelled: a hook table that runs callbacks the way WordPress does, an
 *   options and posts store, and the hooks core fires for each write
 *   (wp_insert_post, wp_update_post, update_post_meta, wp_trash_post,
 *   wp_untrash_post, wp_delete_post, update_option). wp-kit's do_action runs
 *   nothing, so it cannot count what a write sets off. LiteSpeed Cache is a
 *   listener on litespeed_purge that keeps what it is given, merged per
 *   request, as Purge::_add() does into one X-LiteSpeed-Purge header.
 *
 * Each action is one request. For each: whether the embed's own cache moved,
 * how many times LiteSpeed was called, and the tags of the one purge it sends.
 * Then the same without LiteSpeed Cache: no call, and the embed's own cache
 * moves exactly as before. And SFAF_LiteSpeed is named only in SFAF_Embed's
 * flush callbacks, so nothing on the route that answers the embed changes.
 *
 * PLANTs in plant-31270.php.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
error_reporting( E_ALL );

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

/* ---- a hook table, run as WordPress runs it --------------------------- */
$GLOBALS['hooks'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][ $p ][] = array( $cb, $n ); return true; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { return add_action( $h, $cb, $p, $n ); }
function has_action( $h, $cb = false ) { return ! empty( $GLOBALS['hooks'][ $h ] ); }
function do_action( $h, ...$args ) {
    if ( empty( $GLOBALS['hooks'][ $h ] ) ) { return; }
    if ( empty( $args ) ) { $args = array( '' ); }
    ksort( $GLOBALS['hooks'][ $h ] );
    foreach ( $GLOBALS['hooks'][ $h ] as $list ) {
        foreach ( $list as $e ) { call_user_func_array( $e[0], array_slice( $args, 0, $e[1] ) ); }
    }
}
function apply_filters( $h, $v ) { return $v; }

/* ---- the store, and the hooks core fires on each write ----------------- */
class WP_Post { public $ID; public $post_type = 'uc_event'; public $post_status = 'draft'; }
$GLOBALS['opt']   = array();
$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();
$GLOBALS['next']  = 100;
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $n ] : $d; }
function update_option( $n, $v ) {
    $old = get_option( $n );
    $GLOBALS['opt'][ $n ] = $v;
    do_action( 'update_option_' . $n, $old, $v, $n );
    return true;
}
function get_post( $id ) { return isset( $GLOBALS['posts'][ (int) $id ] ) ? $GLOBALS['posts'][ (int) $id ] : null; }
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_post_status( $id ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function wp_insert_post( $status, $meta = array() ) {
    $p = new WP_Post();
    $p->ID = ++$GLOBALS['next'];
    $p->post_status = $status;
    $GLOBALS['posts'][ $p->ID ] = $p;
    foreach ( $meta as $k => $v ) { $GLOBALS['meta'][ $p->ID ][ $k ] = $v; do_action( 'added_post_meta', 1, $p->ID, $k, $v ); }
    do_action( 'transition_post_status', $status, 'new', $p );
    do_action( 'save_post_uc_event', $p->ID, $p, false );
    return $p->ID;
}
function wp_update_post( $id, $status ) {
    $p = get_post( $id );
    $old = $p->post_status;
    $p->post_status = $status;
    do_action( 'transition_post_status', $status, $old, $p );
    do_action( 'save_post_uc_event', $id, $p, true );
}
function update_post_meta( $id, $k, $v ) {
    $had = isset( $GLOBALS['meta'][ $id ] ) && array_key_exists( $k, $GLOBALS['meta'][ $id ] );
    if ( $had && $GLOBALS['meta'][ $id ][ $k ] === $v ) { return false; }
    $GLOBALS['meta'][ $id ][ $k ] = $v;
    do_action( $had ? 'updated_post_meta' : 'added_post_meta', 1, $id, $k, $v );
    return true;
}
function wp_trash_post( $id ) { wp_update_post( $id, 'trash' ); do_action( 'trashed_post', $id ); }
function wp_untrash_post( $id ) { wp_update_post( $id, 'draft' ); do_action( 'untrashed_post', $id ); }
function wp_delete_post( $id ) { $p = get_post( $id ); unset( $GLOBALS['posts'][ $id ] ); do_action( 'deleted_post', $id, $p ); }

/* What a save writes: the editor writes every field it shows, changed or not. */
$EDITOR_META = array( '_uc_event_date', '_uc_start_time', '_uc_end_time', '_uc_location', '_uc_capacity', '_uc_rsvp_enabled', '_uc_show_rsvp',
    '_uc_show_social', '_uc_show_calendar', '_uc_show_reminders', '_uc_faqs', '_uc_image_url', '_uc_language', '_uc_online', '_uc_contact_email',
    '_uc_questions', '_uc_notify_team', '_uc_access_team', '_uc_registration_link', '_uc_cost' );

/* ---- the plugin's two classes, and the stand-ins for the two constants -- */
class SFAF_Shortcodes {}
class SFAF_Closures { const OPTION = 'sfaf_closures'; }
class SFAF_Series { const TAXONOMY = 'uc_series'; }
class SFAF_Cancellation { const META = '_uc_cancelled'; const VISIBILITY_META = '_uc_cancelled_visibility'; }
require $root . '/includes/class-sfaf-litespeed.php';
require $root . '/includes/class-sfaf-embed.php';
foreach ( array( 'SFAF_Closures' => 'class-sfaf-closures.php', 'SFAF_Series' => 'class-sfaf-series.php', 'SFAF_Cancellation' => 'class-sfaf-cancellation.php' ) as $c => $f ) {
    $src = file_get_contents( $root . '/includes/' . $f );
    foreach ( ( new ReflectionClass( $c ) )->getConstants() as $k => $v ) {
        rt( "the stand-in $c::$k is the plugin's", 1 === preg_match( '/const ' . $k . '\s*=\s*\'' . preg_quote( $v, '/' ) . '\';/', $src ), true );
    }
}

/* ---- one request: a fresh world, the embed listening ------------------- */
$GLOBALS['ls'] = null;
function request( $litespeed ) {
    $GLOBALS['hooks'] = array();
    $GLOBALS['ls']    = array( 'calls' => 0, 'tags' => array() );
    SFAF_LiteSpeed::reset();
    ( new SFAF_Embed( new SFAF_Shortcodes() ) )->register();
    if ( $litespeed ) {
        add_action( 'litespeed_purge', function ( $tags ) {
            $GLOBALS['ls']['calls']++;
            $GLOBALS['ls']['tags'] = array_values( array_unique( array_merge( $GLOBALS['ls']['tags'], (array) $tags ) ) );
        } );
    }
}
function gen() { return (int) get_option( SFAF_Embed::CACHE_VERSION_OPTION, 1 ); }
function event( $status ) {
    $saved = $GLOBALS['hooks'];
    $GLOBALS['hooks'] = array();
    $id = wp_insert_post( $status, array( '_uc_event_date' => '2026-11-04', '_uc_start_time' => '18:00' ) );
    $GLOBALS['hooks'] = $saved;
    return $id;
}

$counts = array();
/**
 * @param string   $label
 * @param callable $setup Before the request: returns what $act needs.
 * @param callable $act   The request.
 * @param array|null $want The tags LiteSpeed must be sent, by name; '{id}' is the event.
 *                        null for the bulk cases, which are checked by count below.
 */
function measure( $label, $setup, $act, $want ) {
    global $counts;
    foreach ( array( true, false ) as $ls ) {
        request( $ls );
        $id     = $setup();
        $before = gen();
        $act( $id );
        $moved  = gen() - $before;
        rt( "$label" . ( $ls ? '' : ', without LiteSpeed' ) . ': the embed cache moved', $moved > 0, true );
        if ( ! $ls ) {
            rt( "$label, without LiteSpeed: nothing was sent", $GLOBALS['ls']['calls'], 0 );
            $counts[ $label ]['flushes_without'] = $moved;
            continue;
        }
        if ( null !== $want ) {
            $w = array();
            foreach ( $want as $t ) { $w[] = str_replace( '{id}', (string) $id, $t ); }
            $got = $GLOBALS['ls']['tags'];
            sort( $got ); sort( $w );
            rt( "$label: the tags LiteSpeed drops", $got, $w );
        }
        $GLOBALS['last_tags'] = $GLOBALS['ls']['tags'];
        $counts[ $label ] = array( 'flushes' => $moved, 'calls' => $GLOBALS['ls']['calls'], 'tags' => count( $GLOBALS['ls']['tags'] ) );
    }
    rt( "$label: the embed cache moves as often with LiteSpeed as without", $counts[ $label ]['flushes'], $counts[ $label ]['flushes_without'] );
}

$edit = function ( $id ) use ( $EDITOR_META ) {
    wp_update_post( $id, get_post_status( $id ) );
    foreach ( $EDITOR_META as $k ) { update_post_meta( $id, $k, $k . ' new' ); }
};

measure( 'a typical save of a published event (the editor, 20 fields)', function () { return event( 'publish' ); }, $edit, array( 'REST', 'Po.{id}' ) );
measure( 'a save that changes only the title', function () { return event( 'publish' ); }, function ( $id ) { wp_update_post( $id, 'publish' ); }, array( 'REST', 'Po.{id}' ) );
measure( 'a save of a draft', function () { return event( 'draft' ); }, $edit, array( 'REST' ) );
measure( 'publish a draft', function () { return event( 'draft' ); }, function ( $id ) use ( $edit ) { wp_update_post( $id, 'publish' ); $edit( $id ); }, array( 'REST', 'Po.{id}' ) );
measure( 'back to draft', function () { return event( 'publish' ); }, function ( $id ) { wp_update_post( $id, 'draft' ); }, array( 'REST', 'Po.{id}' ) );
measure( 'cancel, stay on the calendar', function () { return event( 'publish' ); },
    function ( $id ) { update_post_meta( $id, '_uc_cancelled', '1' ); update_post_meta( $id, '_uc_cancelled_visibility', 'stay' ); update_post_meta( $id, '_uc_cancelled_at', 1 ); },
    array( 'REST', 'Po.{id}' ) );
measure( 'reinstate', function () { $id = event( 'publish' ); $GLOBALS['meta'][ $id ]['_uc_cancelled'] = '1'; return $id; },
    function ( $id ) { update_post_meta( $id, '_uc_cancelled', '' ); }, array( 'REST', 'Po.{id}' ) );
measure( 'remove from the calendar, on a cancelled event', function () { $id = event( 'publish' ); $GLOBALS['meta'][ $id ]['_uc_cancelled'] = '1'; $GLOBALS['meta'][ $id ]['_uc_cancelled_visibility'] = 'stay'; return $id; },
    function ( $id ) { update_post_meta( $id, '_uc_cancelled_visibility', 'hide' ); }, array( 'REST', 'Po.{id}' ) );
measure( 'trash a published event', function () { return event( 'publish' ); }, 'wp_trash_post', array( 'REST', 'Po.{id}' ) );
measure( 'restore from the trash', function () { $id = event( 'publish' ); $GLOBALS['posts'][ $id ]->post_status = 'trash'; return $id; }, 'wp_untrash_post', array( 'REST', 'Po.{id}' ) );
measure( 'delete a published event for good', function () { return event( 'publish' ); }, 'wp_delete_post', array( 'REST', 'Po.{id}' ) );
measure( 'delete a trashed event for good', function () { $id = event( 'publish' ); $GLOBALS['posts'][ $id ]->post_status = 'trash'; return $id; }, 'wp_delete_post', array( 'REST' ) );
measure( 'a closure added', function () { return 0; }, function () { update_option( SFAF_Closures::OPTION, array( 'c1' => array() ) ); }, array( 'REST' ) );
measure( 'a series renamed', function () { return 0; }, function () { do_action( 'edited_term', 11, 11, SFAF_Series::TAXONOMY ); }, array( 'REST' ) );
measure( 'an RSVP', function () { return 0; }, function () { do_action( 'uc_rsvp_submitted', 1 ); }, array( 'REST' ) );

/* Bulk Publish of 25 drafts: one request. */
measure( 'bulk Publish, 25 drafts', function () { $ids = array(); for ( $i = 0; $i < 25; $i++ ) { $ids[] = event( 'draft' ); } return $ids; },
    function ( $ids ) { foreach ( $ids as $id ) { wp_update_post( $id, 'publish' ); } }, null );
$bulk_ids = $GLOBALS['last_tags'];

/* A full import: one run, 287 new drafts (as the first EveryAction import
   made) and 40 published events it updates, 12 fields each. */
$import = function ( $pub ) {
    for ( $i = 0; $i < 287; $i++ ) {
        $id = wp_insert_post( 'draft', array() );
        foreach ( array( '_uc_event_date', '_uc_start_time', '_uc_end_time', '_uc_location', '_uc_image_url', '_uc_capacity', '_uc_f1', '_uc_f2', '_uc_f3', '_uc_f4', '_uc_f5', '_uc_f6' ) as $k ) { update_post_meta( $id, $k, 'v' ); }
    }
    foreach ( $pub as $id ) {
        wp_update_post( $id, 'publish' );
        foreach ( array( '_uc_event_date', '_uc_start_time', '_uc_end_time', '_uc_location', '_uc_image_url', '_uc_capacity', '_uc_f1', '_uc_f2', '_uc_f3', '_uc_f4', '_uc_f5', '_uc_f6' ) as $k ) { update_post_meta( $id, $k, $k . ' changed' ); }
    }
};
measure( 'a full import: 287 new drafts, 40 published events updated', function () { $ids = array(); for ( $i = 0; $i < 40; $i++ ) { $ids[] = event( 'publish' ); } return $ids; },
    $import, null );
$import_tags = $GLOBALS['last_tags'];

/* The bulk and import cases name their own tags: REST and each published one. */
foreach ( array( 'bulk Publish, 25 drafts' => array( $bulk_ids, 25 ), 'a full import: 287 new drafts, 40 published events updated' => array( $import_tags, 40 ) ) as $label => $c ) {
    rt( "$label: REST and one Po. tag per published event", array( in_array( 'REST', $c[0], true ), count( preg_grep( '/^Po\.\d+$/', $c[0] ) ), count( $c[0] ) ), array( true, $c[1], $c[1] + 1 ) );
    rt( "$label: LiteSpeed was called once per tag, never twice for one", $counts[ $label ]['calls'], $c[1] + 1 );
}

/* ---- nothing on the embed's route names LiteSpeed ----------------------- */
$tokens = token_get_all( file_get_contents( $root . '/includes/class-sfaf-embed.php' ) );
$fn = ''; $depth = 0; $fn_depth = -1; $where = array(); $next_is_name = false;
foreach ( $tokens as $t ) {
    if ( is_array( $t ) ) {
        if ( T_FUNCTION === $t[0] ) { $next_is_name = true; continue; }
        if ( $next_is_name && T_STRING === $t[0] ) { $fn = $t[1]; $fn_depth = $depth; $next_is_name = false; continue; }
        if ( T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) { $depth++; continue; }
        if ( T_STRING === $t[0] && 'SFAF_LiteSpeed' === $t[1] ) { $where[] = $fn; }
        continue;
    }
    if ( '{' === $t ) { $depth++; }
    if ( '}' === $t ) { $depth--; if ( $depth === $fn_depth ) { $fn = ''; } }
}
rt( 'SFAF_LiteSpeed is named in SFAF_Embed', count( $where ) > 0, true );
rt( 'only inside flush_cache and its callbacks', array_values( array_filter( $where, function ( $f ) { return 0 !== strpos( $f, 'flush_cache' ); } ) ), array() );
$ls_src = file_get_contents( $root . '/includes/class-sfaf-litespeed.php' );
rt( 'never litespeed_purge_post or litespeed_purge_all', 0 === preg_match( "/do_action\(\s*'litespeed_purge_(post|all)/", $ls_src ), true );

echo "\n3.127.0: LiteSpeed Cache told what to drop\n" . str_repeat( '=', 72 ) . "\n";
printf( "  %-62s %8s %7s %5s\n", 'one request', 'flushes', 'calls', 'tags' );
foreach ( $counts as $label => $c ) { printf( "  %-62s %8d %7d %5d\n", $label, $c['flushes'], $c['calls'], $c['tags'] ); }
echo "  flushes: the embed's own cache generation moved; calls: litespeed_purge; tags: in the one purge LiteSpeed sends.\n\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. Every write that moves the embed's own cache drops LiteSpeed's copies of the embed (REST), and a published\nevent's own page (Po.<id>); each tag once per request, one purge; nothing is sent without LiteSpeed Cache.\n";
exit( 0 );
