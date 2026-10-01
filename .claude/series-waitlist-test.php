<?php
/**
 * DELETING A SERIES THAT HAS A WAITLIST (3.106.3).
 *
 *     php .claude/series-waitlist-test.php
 *
 * The real remove_series route and render_series_remove() on wp-kit.php, with
 * the registrations table answered from rows here and every mail recorded.
 *
 *   with nobody registered and people waiting, Delete is answered with the
 *   cancel-instead offer, which states the two numbers apart and offers to
 *   email the waitlist, and Delete them anyway; cancelling and emailing sends
 *   the waitlist's cancellation, one per person; deleting anyway deletes; with
 *   somebody registered, deleting anyway is refused whatever is posted; the
 *   delete guard and the three reads still count registrations only.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function sw( $got, $want, $why ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $why . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $body, $headers = '', $att = array() ) { $GLOBALS['sw_mail'][] = array( 'to' => $to, 'subject' => $subject ); return true; }
}
if ( ! function_exists( 'wp_trash_post' ) ) { function wp_trash_post( $id ) { if ( isset( $GLOBALS['kit_posts'][ (int) $id ] ) ) { $GLOBALS['kit_posts'][ (int) $id ]->post_status = 'trash'; } return true; } }
if ( ! function_exists( 'wp_delete_term' ) ) { function wp_delete_term( $id, $tax ) { return true; } }
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();

function sw_world( $statuses ) {
    kit_reset();
    $GLOBALS['sw_mail'] = array();
    $GLOBALS['kit_redirect_throws'] = true;
    $ids = array();
    foreach ( array( '2026-11-12', '2026-11-19' ) as $d ) {
        $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Support group', 'post_author' => 1 ) );
        foreach ( array( '_uc_event_date' => $d, '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_rsvp_enabled' => '1', '_uc_capacity' => '1' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
        wp_set_object_terms( $id, array( 11 ), SFAF_Series::TAXONOMY );
        $ids[] = $id;
    }
    $GLOBALS['sw_events'] = $ids;
    $GLOBALS['kit_query'] = function ( $a ) {
        return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? $GLOBALS['sw_events'] : array_map( 'get_post', $GLOBALS['sw_events'] );
    };
    $rows = array();
    $n = 0;
    foreach ( $statuses as $st ) {
        $n++;
        $rows[] = array( 'id' => $n, 'event_id' => $ids[ ( $n - 1 ) % 2 ], 'name' => 'P' . $n, 'first_name' => 'P' . $n, 'last_name' => '',
            'email' => 'p' . $n . '@example.org', 'phone' => '', 'status' => $st, 'token' => 't' . $n, 'format' => '', 'created_at' => '2026-09-20 10:00:00',
            'cancelled_at' => null, 'removed_by' => 0, 'offer_token' => null, 'offered_at' => null, 'offer_expires' => null );
    }
    $GLOBALS['sw_rows'] = $rows;
    $GLOBALS['kit_db'] = function ( $method, $sql ) {
        if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
        $rows = $GLOBALS['sw_rows'];
        if ( preg_match( '/\bevent_id\s*=\s*(\d+)/', $sql, $m ) ) {
            $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r['event_id'] === (int) $m[1]; } );
        }
        if ( preg_match( "/status\s*=\s*'([a-z_]+)'/", $sql, $m ) ) {
            $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['status'] === $m[1]; } );
        } elseif ( preg_match( "/status\s+IN\s*\(([^)]*)\)/i", $sql, $m ) ) {
            preg_match_all( "/'([a-z_]+)'/", $m[1], $in );
            $rows = array_filter( $rows, function ( $r ) use ( $in ) { return in_array( $r['status'], $in[1], true ); } );
        }
        if ( 'get_var' === $method ) { return (string) count( $rows ); }
        if ( 'get_results' === $method ) { return array_map( function ( $r ) { return (object) $r; }, array_values( $rows ) ); }
        return null;
    };
    return $ids;
}
function sw_post( $over ) {
    global $portal;
    $_GET  = array();
    $_POST = array_merge( array( 'uc_action' => 'remove_series', 'series_id' => '11', 'remove_mode' => 'delete_events', 'uc_nonce' => 'nonce' ), $over );
    try { kit_call( 'SFAF_Portal', 'dispatch_post', $portal, array( 'remove_series' ) ); }
    catch ( KitRedirect $r ) { return $r->getMessage(); }
    return '';
}
function sw_render() {
    global $portal, $user;
    $depth = ob_get_level();
    ob_start();
    try { kit_call( 'SFAF_Portal', 'render_series_remove', $portal, array( $user, 11 ) ); }
    catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}
function sw_text( $html ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( $html ) ) ); }

/* ---- Nobody registered, two waiting. ---------------------------------- */
$ids = sw_world( array( 'waitlisted', 'offered' ) );
$to  = sw_post( array() );
sw( false !== strpos( $to, 'series/remove/11' ) && false !== strpos( $to, 'msg=series_has_waitlist' ), true, 'PLANT C: Delete with only a waitlist is answered with the cancel-instead offer: ' . $to );
$html = sw_render();
preg_match( '#<p class="uc-hint" data-uc-cancel-instead-counts[^>]*>(.*?)</p>#s', $html, $m );
sw( isset( $m[1] ) ? sw_text( $m[1] ) : '', 'Nobody is registered, and 2 people are on a waitlist across the events in this series. Cancelling keeps every registration, closes new ones, and stops the reminders.', 'the offer states the two numbers apart' );
sw( false !== strpos( sw_text( $html ), 'Cancel these and email the 2 people' ), true, 'it offers to email the two waiting' );
sw( false !== strpos( $html, 'data-uc-delete-anyway' ), true, 'and, nobody being registered, to delete them anyway' );
sw( SFAF_Announce::has_registrations( $ids[0] ) || SFAF_Announce::has_registrations( $ids[1] ), false, 'the delete guard still counts nobody' );
sw( SFAF_Announce::count_affected( $ids ), array( 'registrations' => 0, 'people' => 0, 'events' => 0 ), 'and the counts read registrations only' );

$to = sw_post( array( 'cancel_instead_confirmed' => '1', 'instead' => 'cancel', 'notify_choice' => 'send', 'cancel_visibility' => 'stay' ) );
sw( SFAF_Cancellation::is_cancelled( $ids[0] ) && SFAF_Cancellation::is_cancelled( $ids[1] ), true, 'cancel and email cancels every event' );
sw( false !== strpos( $to, 'msg=series_cancelled' ) && false !== strpos( $to, 'wl=2' ) && false !== strpos( $to, 'told=0' ), true, 'and reports the waitlist apart: ' . $to );
$sent = array_column( $GLOBALS['sw_mail'], 'to' );
sort( $sent );
sw( $sent, array( 'p1@example.org', 'p2@example.org' ), 'PLANT C: the two waiting are each sent one email' );
sw( isset( $GLOBALS['sw_mail'][0] ) && 0 === strpos( $GLOBALS['sw_mail'][0]['subject'], 'Cancelled: ' ), true, 'the waitlist\'s cancellation' );

$ids = sw_world( array( 'waitlisted' ) );
$to  = sw_post( array( 'cancel_instead_confirmed' => '1', 'instead' => 'delete' ) );
sw( 'trash' === get_post_status( $ids[0] ) || null === get_post( $ids[0] ), true, 'Delete them anyway deletes, nobody being registered: ' . $to );

/* ---- Somebody registered: deleting anyway is refused. ----------------- */
$ids = sw_world( array( 'confirmed', 'waitlisted' ) );
$to  = sw_post( array( 'cancel_instead_confirmed' => '1', 'instead' => 'delete' ) );
sw( false !== strpos( $to, 'msg=series_needs_cancel' ) && 'publish' === get_post_status( $ids[0] ), true, 'with somebody registered, a posted delete is refused: ' . $to );
$to = sw_post( array() );
$html = sw_render();
preg_match( '#<p class="uc-hint" data-uc-cancel-instead-counts[^>]*>(.*?)</p>#s', $html, $m );
sw( isset( $m[1] ) ? sw_text( $m[1] ) : '', '1 person is registered and 1 person is on a waitlist across the events in this series. Cancelling keeps every registration, closes new ones, and stops the reminders.', 'both numbers, apart' );
sw( false === strpos( $html, 'data-uc-delete-anyway' ), true, 'and no Delete them anyway' );

if ( $fails ) {
    echo 'SERIES WAITLIST: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "series waitlist: deleting a series with only a waitlist offers to cancel and email it, or to delete anyway; somebody registered still refuses deletion; the counts and the guard are registrations only.\n";
