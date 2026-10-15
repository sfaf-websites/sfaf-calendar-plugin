<?php
/**
 * EVERY ACTION THAT CHANGES WHAT AN EMBED SHOWS MOVES THE CACHE (3.112.1, item 3).
 *
 *     php .claude/cache-flush-test.php
 *
 * The embed's ten-minute cache is retired by SFAF_Embed::flush_cache(), which
 * adds one to the generation in sfaf_embed_cache_version. It runs from hooks
 * core fires on a save, a status change, a meta or term write. Here every
 * action runs through its real caladmin route (dispatch_post() or the editor's
 * save, on wp-kit.php with kit_fire_hooks, so the hooks core would fire do
 * fire), and the generation must have moved:
 *
 *   cancel        the editor, the series removal screen's "cancel instead",
 *                 a closed day's Cancel it instead
 *   reinstate     the editor
 *   publish       the editor, the events list's bulk Publish, the schedule's
 *                 Publish, the pending queue's Approve
 *   unpublish     the editor's Save draft, the pending queue's Reject
 *   hide          the editor's private tick, and "Remove from the calendar"
 *                 on a cancelled event
 *   and, found on the way: the events list's bulk category, and a tag
 *   archived or restored
 *
 * Before 3.112.1 cancel, reinstate, Remove from the calendar, the bulk
 * category and a tag's archive flag moved nothing: each is a meta or term
 * write no hook was watching. PLANT 3 in plant-31121.php takes the
 * cancellation keys out of the watched list.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( $to, $s, $b, $h = '', $a = array() ) { return true; } }
if ( ! function_exists( 'wp_delete_term' ) ) { function wp_delete_term( $id, $tax ) { return true; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1, 'pending' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }

$fails = array();
$rows  = array();
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$option = ( new ReflectionClassConstant( 'SFAF_Embed', 'CACHE_VERSION_OPTION' ) )->getValue();

function cf_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type ) { continue; }
        if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
        if ( ! empty( $a['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', (array) $a['post__in'] ), true ) ) { continue; }
        $ok = true;
        foreach ( isset( $a['tax_query'] ) ? (array) $a['tax_query'] : array() as $c ) {
            if ( ! is_array( $c ) || empty( $c['taxonomy'] ) ) { continue; }
            $have = isset( $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] : array();
            if ( 'term_id' === ( isset( $c['field'] ) ? $c['field'] : 'term_id' ) && ! array_intersect( array_map( 'intval', (array) $c['terms'] ), $have ) ) { $ok = false; }
        }
        if ( $ok ) { $out[] = $p; }
    }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'ID' ) : $out;
}

/** A world with hooks firing and the embed listening, and one complete event. */
function cf_world() {
    kit_reset();
    $GLOBALS['kit_hooks']           = array();
    $GLOBALS['kit_fire_hooks']      = true;
    $GLOBALS['kit_redirect_throws'] = true;
    $GLOBALS['kit_query']           = 'cf_query';
    $GLOBALS['kit_get_posts']       = 'cf_query';
    $GLOBALS['kit_db']              = null;
    ( new SFAF_Embed( new SFAF_Shortcodes() ) )->register();
}
function cf_event( $status = 'publish', $series = 0 ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Support group', 'post_author' => 1, 'post_content' => '<p>Weekly.</p>' ) );
    foreach ( array( '_uc_event_date' => date( 'Y-m-d', strtotime( '+9 days' ) ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street, San Francisco' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    wp_set_object_terms( $id, array( 11 ), 'uc_event_category' );
    wp_set_object_terms( $id, array( 11 ), 'uc_organizer' );
    if ( $series ) { wp_set_object_terms( $id, array( $series ), SFAF_Series::TAXONOMY ); }
    return $id;
}
function cf_gen() { global $option; return (int) get_option( $option, 0 ); }
/** A caladmin POST through dispatch_post(), as the form sends it. */
function cf_post( $action, $fields ) {
    global $portal;
    $_GET  = array();
    $_POST = array_merge( array( 'uc_action' => $action, 'uc_nonce' => 'nonce' ), $fields );
    try { kit_call( 'SFAF_Portal', 'dispatch_post', $portal, array( $action ) ); }
    catch ( KitRedirect $r ) { return $r->getMessage(); }
    catch ( Throwable $t ) { return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    return '';
}
/** The editor's save, as Save draft or Publish sends it. */
function cf_save( $id, $over ) {
    global $portal;
    $_GET  = array();
    $_POST = array_merge( array(
        'event_id' => $id, 'save_mode' => 'publish', 'title' => 'Support group', 'date' => date( 'Y-m-d', strtotime( '+9 days' ) ),
        'start_time' => '18:00', 'end_time' => '19:30', 'uc_organizer_present' => '1', 'organizer' => array( '11' ),
        'uc_category_present' => '1', 'category' => array( '11' ), 'description' => '<p>Weekly.</p>',
        'uc_online_present' => '1', 'uc_online' => '0',
        'location_mode' => 'custom', 'location_street' => '1035 Market Street', 'location_city' => 'San Francisco',
    ), $over );
    try { kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( new WP_User() ) ); }
    catch ( KitRedirect $r ) { return $r->getMessage(); }
    catch ( Throwable $t ) { return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    return '';
}
/**
 * Run one action and say whether it did what it says and moved the cache.
 *
 * @param string   $label
 * @param callable $setup  Builds the world; returns the event id.
 * @param callable $act    Runs the route; returns what it answered.
 * @param callable $done   Whether the action took effect.
 */
function cf_case( $label, $setup, $act, $done ) {
    global $fails, $rows;
    cf_world();
    $id     = $setup();
    $before = cf_gen();
    $said   = $act( $id );
    $took   = (bool) $done( $id );
    $moved  = cf_gen() > $before;
    if ( 0 === strpos( (string) $said, 'THREW' ) ) { $fails[] = "$label: the route threw: $said"; }
    if ( ! $took ) { $fails[] = "$label: the action did not take effect, so this case tests nothing (the route answered " . json_encode( $said ) . ')'; }
    if ( ! $moved ) { $fails[] = "$label: the embed cache was left in place"; }
    $rows[] = sprintf( '  %-62s %s', $label, $moved ? 'flushed' : 'LEFT CACHED' );
}

$cancelled = function ( $id ) { return SFAF_Cancellation::is_cancelled( $id ); };

/* ---- cancel ----------------------------------------------------------- */
cf_case( 'cancel, from the editor', function () { return cf_event(); },
    function ( $id ) { return cf_post( 'cancel_event', array( 'event_id' => $id, 'cancel_visibility' => 'stay' ) ); }, $cancelled );
cf_case( 'cancel and remove from the calendar, from the editor', function () { return cf_event(); },
    function ( $id ) { return cf_post( 'cancel_event', array( 'event_id' => $id, 'cancel_visibility' => 'hide' ) ); },
    function ( $id ) { return SFAF_Cancellation::is_hidden( $id ); } );
cf_case( 'cancel, from a closed day ("Cancel it instead")', function () { return cf_event(); },
    function ( $id ) { return cf_post( 'cancel_event', array( 'event_id' => $id, 'cancel_visibility' => 'stay', 'closure_id' => 'c1', 'closed_back' => 'list' ) ); }, $cancelled );
cf_case( 'cancel, from the series removal screen ("cancel instead")',
    function () {
        $id = cf_event( 'publish', 11 );
        /* One person registered, so Delete is answered with Cancel instead. */
        $GLOBALS['kit_db'] = function ( $method, $sql ) use ( $id ) {
            if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
            $row = (object) array( 'id' => 1, 'event_id' => $id, 'name' => 'P', 'first_name' => 'P', 'last_name' => '', 'email' => 'p@example.org', 'phone' => '',
                'status' => 'confirmed', 'token' => 't', 'format' => '', 'created_at' => '2026-09-20 10:00:00', 'cancelled_at' => null, 'removed_by' => 0,
                'offer_token' => null, 'offered_at' => null, 'offer_expires' => null );
            $want = preg_match( "/status\s*=\s*'([a-z_]+)'/", $sql, $m ) ? $m[1] : 'confirmed';
            $rows = ( 'confirmed' === $want ) ? array( $row ) : array();
            if ( 'get_var' === $method ) { return (string) count( $rows ); }
            return 'get_results' === $method ? $rows : null;
        };
        return $id;
    },
    function ( $id ) { return cf_post( 'remove_series', array( 'series_id' => '11', 'remove_mode' => 'delete_events', 'instead' => 'cancel', 'cancel_instead_confirmed' => '1', 'cancel_visibility' => 'stay' ) ); },
    $cancelled );

/* ---- reinstate -------------------------------------------------------- */
cf_case( 'reinstate, from the editor', function () { $id = cf_event(); SFAF_Cancellation::set( $id, true, 'stay' ); return $id; },
    function ( $id ) { return cf_post( 'cancel_event', array( 'event_id' => $id, 'uncancel' => '1' ) ); },
    function ( $id ) { return ! SFAF_Cancellation::is_cancelled( $id ); } );

/* ---- hide ------------------------------------------------------------- */
cf_case( 'remove from the calendar, on a cancelled event', function () { $id = cf_event(); SFAF_Cancellation::set( $id, true, 'stay' ); return $id; },
    function ( $id ) { return cf_post( 'cancel_event', array( 'event_id' => $id, 'cancel_visibility_only' => '1', 'cancel_visibility' => 'hide' ) ); },
    function ( $id ) { return SFAF_Cancellation::is_hidden( $id ); } );
cf_case( 'make private, from the editor', function () { return cf_event(); },
    function ( $id ) { return cf_save( $id, array( 'uc_private' => '1' ) ); },
    function ( $id ) { return SFAF_Privacy::is_private( $id ); } );
cf_case( 'make public again, from the editor', function () { $id = cf_event(); SFAF_Privacy::set( $id, true ); return $id; },
    function ( $id ) { return cf_save( $id, array( 'uc_private' => '0' ) ); },
    function ( $id ) { return ! SFAF_Privacy::is_private( $id ); } );

/* ---- publish ---------------------------------------------------------- */
$published = function ( $id ) { return 'publish' === get_post_status( $id ); };
cf_case( 'publish, from the editor', function () { return cf_event( 'draft' ); },
    function ( $id ) { return cf_save( $id, array( 'save_mode' => 'publish' ) ); }, $published );
cf_case( 'publish, from the events list (bulk Publish)', function () { return cf_event( 'draft' ); },
    function ( $id ) { return cf_post( 'bulk_events', array( 'uc_bulk_present' => '1', 'uc_do' => 'publish', 'bulk_ids' => array( (string) $id ) ) ); }, $published );
cf_case( 'publish, from the schedule (series screen)', function () { return cf_event( 'draft', 11 ); },
    function ( $id ) { return cf_post( 'schedule_publish', array( 'series_id' => '11' ) ); }, $published );
cf_case( 'publish, from the pending queue (Approve)', function () { $id = cf_event( 'pending' ); update_post_meta( $id, '_uc_submission_kind', 'staff' ); return $id; },
    function ( $id ) { return cf_post( 'approve_event', array( 'event_id' => $id ) ); }, $published );

/* ---- unpublish -------------------------------------------------------- */
cf_case( 'unpublish, from the editor (Save draft)', function () { return cf_event(); },
    function ( $id ) { return cf_save( $id, array( 'save_mode' => 'draft' ) ); },
    function ( $id ) { return 'draft' === get_post_status( $id ); } );
cf_case( 'unpublish, from the pending queue (Reject)', function () { $id = cf_event( 'pending' ); update_post_meta( $id, '_uc_submission_kind', 'staff' ); return $id; },
    function ( $id ) { return cf_post( 'reject_event', array( 'event_id' => $id ) ); },
    function ( $id ) { return 'trash' === get_post_status( $id ); } );

/* ---- found on the way ------------------------------------------------- */
cf_case( 'category, from the events list (bulk)', function () { return cf_event(); },
    function ( $id ) { return cf_post( 'bulk_events', array( 'uc_bulk_present' => '1', 'bulk_category' => '12', 'bulk_ids' => array( (string) $id ) ) ); },
    function ( $id ) { return in_array( 12, (array) $GLOBALS['kit_terms'][ $id ]['uc_event_category'], true ); } );
cf_case( 'a tag archived (Series & Categories > Tags)', function () { return cf_event(); },
    function ( $id ) { return cf_post( 'retire_tag', array( 'tag_id' => '11', 'tag_retire' => '1' ) ); },
    function ( $id ) { return SFAF_Tags::is_retired( 11 ); } );
cf_case( 'a tag restored', function () { $id = cf_event(); update_term_meta( 11, SFAF_Tags::META_RETIRED, '1' ); return $id; },
    function ( $id ) { return cf_post( 'retire_tag', array( 'tag_id' => '11' ) ); },
    function ( $id ) { return ! SFAF_Tags::is_retired( 11 ); } );

/* A term write that changes nothing leaves the cache alone (3.112.2): core fires
   set_object_terms on every write, and an import or a save rewriting the same
   terms would otherwise empty every embed's cache each time. */
cf_world();
$same   = cf_event();
$before = cf_gen();
wp_set_object_terms( $same, array( 11 ), 'uc_event_category' );
if ( cf_gen() !== $before ) { $fails[] = 'the embed cache moved on a term write that changed nothing'; }
$rows[] = sprintf( '  %-62s %s', 'the same category written again', cf_gen() === $before ? 'left alone' : 'FLUSHED' );

echo "\nThe embed cache, action by action, through each route\n" . str_repeat( '=', 72 ) . "\n";
echo implode( "\n", $rows ) . "\n";
if ( $fails ) { echo "\n" . count( $fails ) . " problem(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "\nevery one moves the generation, so no embed keeps the old answer.\n";
exit( 0 );
