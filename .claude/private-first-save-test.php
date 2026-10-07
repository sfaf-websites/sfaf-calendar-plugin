<?php
/**
 * A PRIVATE EVENT MADE ON ADD EVENT IS PRIVATE FROM ITS FIRST WRITE (3.109.0).
 *
 *     php .claude/private-first-save-test.php
 *
 * The real save_event_from_post() on wp-kit.php, posting what Add event posts
 * with "Make this event private" ticked, once with Publish and once with Save
 * draft. wp-kit records every write to the post: its address and whether the
 * private meta was on it at that moment. wp-kit models meta_input landing with
 * the row, as wp_insert_post() does before any status hook, and with
 * kit_slugs on it gives an insert with no address the one WordPress would,
 * made from the title.
 *
 *   1  every write of the new event, the first included, carries a token
 *      address (32 hex characters) and the private meta; the readable address
 *      is remembered for later and is never the event's address
 *   2  the same save with the tick off makes a readable address, so the check
 *      in 1 is not passing because nothing ever has one
 *   3  the public builders, run for real over every event saved here: the REST
 *      events route (the feed the embed and the sites read), the list behind
 *      the event cards, and the month grid behind the embed's calendar. The
 *      kit's query honours the privacy clause, so the private events must be
 *      missing and the public ones present
 */

require __DIR__ . '/wp-kit.php';
$GLOBALS['kit_slugs']      = true;
$GLOBALS['kit_organizers'] = true;

$fails = array();
function pf( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

/* The privacy clause, evaluated: NOT EXISTS, EXISTS, =, != and IN on
   _uc_private, nested AND and OR. Every other key passes, so a date window or
   a category cannot be what hides an event here. */
function pf_meta_ok( $id, $clause ) {
    if ( ! is_array( $clause ) ) { return true; }
    if ( isset( $clause['key'] ) && ! is_array( $clause['key'] ) ) {
        if ( '_uc_private' !== $clause['key'] ) { return true; }
        $has = isset( $GLOBALS['kit_meta'][ $id ]['_uc_private'] );
        $val = $has ? (string) $GLOBALS['kit_meta'][ $id ]['_uc_private'] : null;
        $cmp = isset( $clause['compare'] ) ? strtoupper( $clause['compare'] ) : '=';
        if ( 'NOT EXISTS' === $cmp ) { return ! $has; }
        if ( 'EXISTS' === $cmp ) { return $has; }
        if ( ! $has ) { return false; }
        if ( '!=' === $cmp ) { return $val !== (string) $clause['value']; }
        if ( 'IN' === $cmp ) { return in_array( $val, array_map( 'strval', (array) $clause['value'] ), true ); }
        return $val === (string) $clause['value'];
    }
    $rel  = isset( $clause['relation'] ) ? strtoupper( $clause['relation'] ) : 'AND';
    $subs = array();
    foreach ( $clause as $k => $sub ) { if ( 'relation' !== $k && is_array( $sub ) ) { $subs[] = $sub; } }
    if ( ! $subs ) { return true; }
    foreach ( $subs as $sub ) {
        $ok = pf_meta_ok( $id, $sub );
        if ( 'OR' === $rel && $ok ) { return true; }
        if ( 'AND' === $rel && ! $ok ) { return false; }
    }
    return 'AND' === $rel;
}
$GLOBALS['pf_seen'] = array();
$GLOBALS['kit_query'] = function ( $a ) {
    $out  = array();
    $type = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( ! in_array( $p->post_type, (array) $type, true ) ) { continue; }
        if ( isset( $a['meta_query'] ) && ! pf_meta_ok( $id, $a['meta_query'] ) ) { continue; }
        $GLOBALS['pf_seen'][ $id ] = true;
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
};

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();

function pf_save( $mode, $title, $private ) {
    $_GET  = array();
    $_POST = array(
        'event_id' => 0, 'save_mode' => $mode, 'title' => $title, 'date' => '2026-12-03',
        'start_time' => '18:00', 'end_time' => '20:00', 'uc_organizer_present' => '1', 'organizer' => array( '11' ),
        'uc_category_present' => '1', 'description' => '<p>An evening.</p>',
        'uc_online_present' => '1', 'uc_online' => '0', 'location_mode' => 'custom', 'location_street' => '1035 Market St', 'location_city' => 'San Francisco',
        'uc_private' => $private ? '1' : '0',
    );
    $GLOBALS['kit_writes']          = array();
    $GLOBALS['kit_redirect_throws'] = true;
    $r  = kit_call( 'SFAF_Portal', 'save_event_from_post', $GLOBALS['portal'], array( $GLOBALS['user'] ) );
    $id = (int) $r['id'];
    $writes = array_values( array_filter( $GLOBALS['kit_writes'], function ( $w ) use ( $id ) { return (int) $w['id'] === $id; } ) );
    $_POST = array();
    return array( $id, $writes );
}

$saved = array();
foreach ( array( 'publish' => 'Donor reception', 'draft' => 'Board dinner' ) as $mode => $title ) {
    list( $id, $writes ) = pf_save( $mode, $title, true );
    $saved[ $id ] = $title;
    pf( $id > 0 && count( $writes ) > 0, "PLANT A.2: $mode: no event was saved" );
    foreach ( $writes as $n => $w ) {
        pf( 1 === preg_match( '/^[0-9a-f]{32}$/', $w['name'] ) && '1' === $w['private'],
            "PLANT A.2: $mode: write " . ( $n + 1 ) . ' of ' . count( $writes ) . ' gave the event the address "' . $w['name'] . '" with private "' . $w['private'] . '"' );
    }
    pf( SFAF_Privacy::is_private( $id ), "PLANT A.2: $mode: the saved event is not private" );
    pf( sanitize_title( $title ) === (string) get_post_meta( $id, SFAF_Privacy::PREV_SLUG_META, true ), "$mode: the readable address was not remembered for later" );
    pf( sanitize_title( $title ) !== get_post( $id )->post_name, "PLANT A.2: $mode: the event's address is the readable one" );
}

/* 2: the same save, unticked, makes a readable address. */
list( $pub, $pub_writes ) = pf_save( 'publish', 'Open house', false );
pf( $pub_writes && 'open-house' === $pub_writes[0]['name'] && '' === $pub_writes[0]['private'],
    'the same save with the tick off did not give a readable address, so the check above proves nothing: ' . json_encode( $pub_writes ) );

/* 3: every public builder, for real. */
$GLOBALS['pf_seen'] = array();
class PF_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } }
if ( ! class_exists( 'WP_REST_Response' ) ) { class WP_REST_Response { public $data; function __construct( $d = null ) { $this->data = $d; } } }
$built = array();
try { sfaf_rest_get_events( new PF_Request( array( 'per_page' => 50, 'page' => 1 ) ) ); $built[] = 'REST events'; } catch ( Throwable $t ) { pf( false, 'the REST events route threw: ' . $t->getMessage() ); }
$sc = ( new ReflectionClass( 'SFAF_Shortcodes' ) )->newInstanceWithoutConstructor();
try { new WP_Query( kit_call( 'SFAF_Shortcodes', 'build_query_args', $sc, array( 50, 1, array() ) ) ); $built[] = 'the card list'; } catch ( Throwable $t ) { pf( false, 'the card list threw: ' . $t->getMessage() ); }
try { kit_call( 'SFAF_Shortcodes', 'month_grid_data', $sc, array( '2026-12', array() ) ); $built[] = 'the month grid'; } catch ( Throwable $t ) { pf( false, 'the month grid threw: ' . $t->getMessage() ); }

pf( 3 === count( $built ), 'not every public builder ran: ' . implode( ', ', $built ) );
pf( isset( $GLOBALS['pf_seen'][ $pub ] ), 'the public event is missing from the public builders, so their silence about the private ones proves nothing' );
foreach ( $saved as $id => $title ) {
    pf( ! isset( $GLOBALS['pf_seen'][ $id ] ), "PLANT A.2: $title, private, appeared in a public list, feed or embed payload" );
}

if ( $fails ) {
    echo 'PRIVATE FROM THE FIRST SAVE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "private from the first save: Add event with the tick, Publish and Save draft, gives a token address and the private meta on every write from the first; unticked it gives a readable one; the REST events route, the card list and the month grid leave the private events out and show the public one.\n";
