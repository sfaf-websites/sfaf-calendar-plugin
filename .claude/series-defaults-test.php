<?php
/**
 * SERIES DEFAULTS, THE PENDING BAR, AND ONE PUBLISH RULE (3.103.0).
 *
 *     php .claude/series-defaults-test.php
 *
 * EVERY CASE IS THE REAL CODE. The whole plugin runs against the miniature
 * WordPress in wp-kit.php: the real editor save, the real form create_event()
 * methods, the real EveryAction after_save(), and the real POST routes for
 * Approve and the pending bar, run to their redirect. What is asserted is what
 * they WROTE: the terms on the event and the status it ended with.
 *
 * THE WORLD. The kit has two of every taxonomy, ids 11 and 12. Series 11
 * ("Alpha") is the coffee social: default category 12, default organizer 11,
 * and a description. Series 12 has no defaults and no description.
 *
 * WHAT A PERSON STILL HAS TO CHECK is TESTING.md: the walk on the real site.
 */

require __DIR__ . '/wp-kit.php';

$fails  = array();
$checks = 0;
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();

function sd_is( $got, $want, $why ) {
    global $fails, $checks;
    $checks++;
    if ( $got !== $want ) { $fails[] = $why . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

/* Every WP_Query the code under test runs, answered from the store: the
 * post type, the status and a uc_series tax_query, which is all these ask. */
function sd_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $series = 0;
    foreach ( isset( $a['tax_query'] ) ? (array) $a['tax_query'] : array() as $clause ) {
        if ( is_array( $clause ) && isset( $clause['taxonomy'] ) && 'uc_series' === $clause['taxonomy'] ) {
            $series = (int) ( is_array( $clause['terms'] ) ? reset( $clause['terms'] ) : $clause['terms'] );
        }
    }
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type ) { continue; }
        if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
        if ( $series && ! in_array( $series, wp_get_post_terms( $id, 'uc_series', array( 'fields' => 'ids' ) ), true ) ) { continue; }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
}

function sd_world() {
    kit_reset();
    $GLOBALS['kit_query']           = 'sd_query';
    $GLOBALS['kit_redirect_throws'] = true;
    $GLOBALS['kit_term_desc'][11]   = '<p>Coffee and conversation, every week.</p>';
    update_term_meta( 11, SFAF_Series::META_CATEGORIES, array( 12 ) );
    update_term_meta( 11, SFAF_Series::META_ORGANIZERS, array( 11 ) );
}

function sd_event( $status, $terms = array(), $meta = array(), $content = '' ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Coffee social', 'post_content' => $content ) );
    $meta = array_merge( array( '_uc_event_date' => '2026-12-03', '_uc_start_time' => '10:00', '_uc_end_time' => '11:30', '_uc_location' => '1035 Market St' ), $meta );
    foreach ( $meta as $k => $v ) {
        if ( null !== $v ) { update_post_meta( $id, $k, $v ); }
    }
    foreach ( $terms as $tax => $ids ) { wp_set_object_terms( $id, $ids, $tax ); }
    return $id;
}

function sd_terms( $id, $tax ) {
    $t = wp_get_post_terms( $id, $tax, array( 'fields' => 'ids' ) );
    sort( $t );
    return $t;
}

/** Run a POST route to its redirect. Returns the redirect URL. */
function sd_route( $portal, $action, $post ) {
    $_POST = $post + array( 'uc_nonce' => 'nonce' );
    $_GET  = array();
    try {
        kit_call( 'SFAF_Portal', 'dispatch_post', $portal, array( $action ) );
    } catch ( KitRedirect $r ) {
        return $r->getMessage();
    }
    return '';
}

/* =========================================================================
 * A. The defaults.
 * ====================================================================== */

/* The series screen stores them, clears them under the marker, and leaves them
 * alone without it. */
sd_world();
$_POST = array( 'series_id' => 12, 'series_name' => 'Beta', 'uc_series_defaults_present' => '1',
    'series_categories' => array( '11', '99' ), 'series_organizers' => array( '12' ) );
kit_call( 'SFAF_Portal', 'save_series_from_post', $portal, array( new WP_User() ) );
sd_is( SFAF_Series::default_categories( 12 ), array( 11 ), 'the series screen stores the ticked categories, and only ones that exist' );
sd_is( SFAF_Series::default_organizers( 12 ), array( 12 ), 'the series screen stores the ticked organizers' );
$_POST = array( 'series_id' => 12, 'series_name' => 'Beta' );
kit_call( 'SFAF_Portal', 'save_series_from_post', $portal, array( new WP_User() ) );
sd_is( SFAF_Series::default_categories( 12 ), array( 11 ), 'a save without the marker leaves the defaults alone' );
$_POST = array( 'series_id' => 12, 'series_name' => 'Beta', 'uc_series_defaults_present' => '1' );
kit_call( 'SFAF_Portal', 'save_series_from_post', $portal, array( new WP_User() ) );
sd_is( SFAF_Series::default_categories( 12 ), array(), 'every box unticked under the marker clears the default' );

/* Joining fills what is empty and nothing else. */
sd_world();
$bare = sd_event( 'draft' );
$own  = sd_event( 'draft', array( 'uc_event_category' => array( 11 ) ) );
SFAF_Series::join( $bare, 11 );
SFAF_Series::join( $own, 11 );
sd_is( sd_terms( $bare, 'uc_event_category' ), array( 12 ), 'an event with no category receives the series category' );
sd_is( sd_terms( $bare, 'uc_organizer' ), array( 11 ), 'an event with no organizer receives the series organizer' );
sd_is( sd_terms( $own, 'uc_event_category' ), array( 11 ), 'an event with its own category KEEPS IT: the default never overwrites' );
sd_is( sd_terms( $own, 'uc_organizer' ), array( 11 ), 'the same event, empty organizer, is still filled' );
sd_is( SFAF_Series::filled_from( $bare, 'category' ), 'Alpha', 'the event records that its category came from the series' );
sd_is( SFAF_Series::filled_from( $own, 'category' ), '', 'an event\'s own category is not reported as the series\'' );

/* Saving an event already in the series is not joining it. */
wp_set_object_terms( $bare, array(), 'uc_event_category' );
SFAF_Series::join( $bare, 11 );
sd_is( sd_terms( $bare, 'uc_event_category' ), array(), 'staying in the same series does not refill a category somebody cleared' );

/* A later change to the defaults does not reach events already in the series. */
sd_world();
$in = sd_event( 'publish' );
SFAF_Series::join( $in, 11 );
$_POST = array( 'series_id' => 11, 'series_name' => 'Alpha', 'uc_series_defaults_present' => '1',
    'series_categories' => array( '11' ), 'series_organizers' => array( '12' ) );
kit_call( 'SFAF_Portal', 'save_series_from_post', $portal, array( new WP_User() ) );
sd_is( sd_terms( $in, 'uc_event_category' ), array( 12 ), 'changing the series default does not rewrite an event already in it' );
sd_is( sd_terms( $in, 'uc_organizer' ), array( 11 ), 'nor its organizer' );
$later = sd_event( 'draft' );
SFAF_Series::join( $later, 11 );
sd_is( sd_terms( $later, 'uc_event_category' ), array( 11 ), 'an event joining after the change gets the new default' );

/* The import: EveryAction's after_save puts a new row into its series. */
sd_world();
$ea  = sd_event( 'uc_imported' );
$src = ( new ReflectionClass( 'SFAF_Source_EveryAction' ) )->newInstanceWithoutConstructor();
$src->after_save( $ea, array( 'title' => 'Coffee social', 'everyaction' => array( 'series_id' => 'S-1' ) ), true );
sd_is( sd_terms( $ea, 'uc_series' ), array( 11 ), 'the import put the row in its series' );
sd_is( sd_terms( $ea, 'uc_event_category' ), array( 12 ), 'an import into a series receives the series category' );
sd_is( sd_terms( $ea, 'uc_organizer' ), array( 11 ), 'an import into a series receives the series organizer' );

/* The staff request form: its own categories win, an empty organizer is filled. */
sd_world();
$c = array( 'title' => 'Coffee social', 'description' => '', 'date' => '2026-12-03', 'start' => '10:00', 'end' => '11:30',
    'venue' => 0, 'venue_other' => '', 'venue_name' => '', 'categories' => array( 11 ), 'series' => 11, 'organizer' => array(),
    'image' => 0, 'capacity' => 0, 'faqs' => array(), 'name' => 'Val', 'notes' => '', 'pattern' => '', 'pattern_dates' => array(),
    'pattern_limit' => 0, 'repeat' => '', 'repeat_until' => '', 'rsvp' => '', 'teams' => array(), 'video' => '' );
$req = kit_call( 'SFAF_Request', 'create_event', null, array( $c, 'val@sfaf.org', 0 ) );
sd_is( sd_terms( $req, 'uc_event_category' ), array( 11 ), 'the request form keeps the requester\'s own category' );
sd_is( sd_terms( $req, 'uc_organizer' ), array( 11 ), 'the request form fills an empty organizer from the series' );
$c['categories'] = array(); $c['organizer'] = array( 12 );
$req2 = kit_call( 'SFAF_Request', 'create_event', null, array( $c, 'val@sfaf.org', 0 ) );
sd_is( sd_terms( $req2, 'uc_event_category' ), array( 12 ), 'the request form fills an empty category from the series' );
sd_is( sd_terms( $req2, 'uc_organizer' ), array( 12 ), 'the request form keeps the requester\'s own organizer' );

/* The community form: nothing of its own, so both are filled. */
sd_world();
$series_term = SFAF_Series::get( 11 );
$c = array( 'title' => 'Coffee social', 'description' => '', 'date' => '2026-12-03', 'start' => '10:00', 'end' => '11:30',
    'location' => '', 'venue' => 0, 'venue_name' => '', 'street' => '', 'city' => '', 'state' => '', 'zip' => '',
    'cost' => '', 'cost_other' => '', 'age' => '', 'age_other' => '', 'contact_name' => '', 'contact_email' => '',
    'contact_phone' => '', 'rsvp_url' => '', 'venue_url' => '', 'capacity' => 0, 'faqs' => array(), 'image' => 0,
    'notes' => '', 'submitter_email' => 'x@example.org', 'submitter_emails' => array(), 'submitter_name' => 'X' );
$sub = kit_call( 'SFAF_Submit', 'create_event', null, array( $c, $series_term, 0 ) );
sd_is( sd_terms( $sub, 'uc_event_category' ), array( 12 ), 'a community submission receives the series category' );
sd_is( sd_terms( $sub, 'uc_organizer' ), array( 11 ), 'a community submission receives the series organizer' );

/* The editor: giving an event the series fills it, and the note says so. */
function sd_editor_post( $over = array() ) {
    return array_merge( array(
        'event_id' => 0, 'save_mode' => 'publish', 'title' => 'Coffee social', 'date' => '2026-12-03',
        'start_time' => '10:00', 'end_time' => '11:30', 'uc_organizer_present' => '1',
        'uc_category_present' => '1', 'description' => '', 'uc_online_present' => '1', 'uc_online' => '0',
        'location_mode' => 'custom', 'location_street' => '1035 Market St', 'location_city' => 'San Francisco',
        'series' => '11',
    ), $over );
}
sd_world();
$_POST = sd_editor_post(); $_GET = array();
$r  = kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( new WP_User() ) );
$ed = (int) $r['id'];
sd_is( sd_terms( $ed, 'uc_event_category' ), array( 12 ), 'the editor: an event given the series with no category receives the default' );
sd_is( sd_terms( $ed, 'uc_organizer' ), array( 11 ), 'the editor: and the default organizer' );
sd_is( get_post_status( $ed ), 'publish', 'the editor publishes a series-filled event: category, organizer and description all come from the series' );
ob_start();
kit_call( 'SFAF_Portal', 'render_manager_control', $portal, array( 'category', kit_call( 'SFAF_Portal', 'manager_panel_context', $portal, array( new WP_User(), $ed, 'editor' ) ) ) );
$html = (string) ob_get_clean();
sd_is( (bool) preg_match( '#data-uc-from-series="category">Filled in from the Alpha series\.#', $html ), true, 'the editor shows the one-line note under a category that came from the series' );

/* The same save in a series with no description is held for it. */
sd_world();
$_POST = sd_editor_post( array( 'series' => '12', 'category' => array( 11 ), 'organizer' => array( 11 ) ) ); $_GET = array();
$r = kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( new WP_User() ) );
sd_is( get_post_status( (int) $r['id'] ), 'draft', 'the editor holds an event with no description in a series with none' );

/* =========================================================================
 * C. One publish rule: Approve, the pending bar, both bulk publishes.
 * ====================================================================== */

/* Approve. */
sd_world();
$no_desc = sd_event( 'pending', array( 'uc_event_category' => array( 11 ), 'uc_organizer' => array( 11 ), 'uc_series' => array( 12 ) ) );
$url = sd_route( $portal, 'approve_event', array( 'event_id' => $no_desc ) );
sd_is( get_post_status( $no_desc ), 'pending', 'Approve does not publish an event with no description and no series description' );
sd_is( false !== strpos( $url, 'msg=approve_held' ) && false !== strpos( $url, 'held=1' ), true, 'Approve says it held the event: ' . $url );
$held = get_transient( 'sfaf_held_1' );
sd_is( isset( $held[ $no_desc ] ) ? $held[ $no_desc ] : '', 'needs a description', 'and names what it needs' );

$series_desc = sd_event( 'pending', array( 'uc_event_category' => array( 11 ), 'uc_organizer' => array( 11 ), 'uc_series' => array( 11 ) ) );
sd_route( $portal, 'approve_event', array( 'event_id' => $series_desc ) );
sd_is( get_post_status( $series_desc ), 'publish', 'Approve publishes an event whose description is its series\'' );

/* The held list is drawn with the title and the reason. */
$GLOBALS['kit_trans']['sfaf_held_1'] = array( $no_desc => 'needs a description' );
$_GET = array( 'held' => '1' );
ob_start(); kit_call( 'SFAF_Portal', 'render_held', $portal ); $drawn = (string) ob_get_clean();
sd_is( (bool) preg_match( '#data-uc-held="' . $no_desc . '">\s*<a [^>]*>Coffee social</a>: needs a description#', $drawn ), true, 'the held list names the event and why' );

/* The pending bar. Three rows waiting; the ticks name two. */
sd_world();
$a = sd_event( 'uc_imported' );
$b = sd_event( 'uc_imported' );
$x = sd_event( 'uc_imported' );
$url = sd_route( $portal, 'pending_bulk', array( 'uc_pending_bulk_present' => '1', 'uc_do' => 'categories',
    'pending_ids' => array( $a, $b ), 'bulk_categories' => array( '11' ) ) );
sd_is( sd_terms( $a, 'uc_event_category' ), array( 11 ), 'Set categories reaches a ticked row' );
sd_is( sd_terms( $b, 'uc_event_category' ), array( 11 ), 'Set categories reaches the other ticked row' );
sd_is( sd_terms( $x, 'uc_event_category' ), array(), 'Set categories DOES NOT reach the row nobody ticked' );
sd_is( false !== strpos( $url, 'did=2' ), true, 'the bar reports how many rows it changed: ' . $url );

sd_route( $portal, 'pending_bulk', array( 'uc_pending_bulk_present' => '1', 'uc_do' => 'organizers',
    'pending_ids' => array( $a ), 'bulk_organizers' => array( '12' ) ) );
sd_is( sd_terms( $a, 'uc_organizer' ), array( 12 ), 'Set organizers reaches the ticked row' );
sd_is( sd_terms( $b, 'uc_organizer' ), array(), 'Set organizers does not reach an unticked row' );

/* Set series, then Publish: the walk. $x has no category of its own; $a does. */
$url = sd_route( $portal, 'pending_bulk', array( 'uc_pending_bulk_present' => '1', 'uc_do' => 'series',
    'pending_ids' => array( $a, $x ), 'bulk_series' => '11' ) );
sd_is( sd_terms( $x, 'uc_event_category' ), array( 12 ), 'Set series fills a missing category from the defaults' );
sd_is( sd_terms( $x, 'uc_organizer' ), array( 11 ), 'Set series fills a missing organizer from the defaults' );
sd_is( sd_terms( $a, 'uc_event_category' ), array( 11 ), 'Set series leaves a row\'s own category alone' );
sd_is( sd_terms( $a, 'uc_organizer' ), array( 12 ), 'Set series leaves a row\'s own organizer alone' );
sd_is( sd_terms( $b, 'uc_series' ), array(), 'Set series does not reach an unticked row' );

$url = sd_route( $portal, 'pending_bulk', array( 'uc_pending_bulk_present' => '1', 'uc_do' => 'publish',
    'pending_ids' => array( $a, $b, $x ) ) );
sd_is( get_post_status( $a ), 'publish', 'bulk Publish publishes a complete row' );
sd_is( get_post_status( $x ), 'publish', 'bulk Publish publishes a row the series filled' );
sd_is( get_post_status( $b ), 'uc_imported', 'bulk Publish holds a row with no organizer and no description' );
$held = get_transient( 'sfaf_held_1' );
sd_is( isset( $held[ $b ] ) ? $held[ $b ] : '', 'needs an organizer and a description', 'and names the row and why' );
sd_is( false !== strpos( $url, 'did=2' ) && false !== strpos( $url, 'held=1' ), true, 'Publish reports two done and a held row: ' . $url );

/* A row with no category, alone. */
sd_world();
$nocat = sd_event( 'uc_imported', array( 'uc_organizer' => array( 11 ) ), array(), '<p>Words.</p>' );
sd_route( $portal, 'pending_bulk', array( 'uc_pending_bulk_present' => '1', 'uc_do' => 'publish', 'pending_ids' => array( $nocat ) ) );
sd_is( get_post_status( $nocat ), 'uc_imported', 'bulk Publish does not publish a row with no category' );

/* Dismiss takes imports; a submission is named, not touched. */
sd_world();
$imp  = sd_event( 'uc_imported' );
$subm = sd_event( 'pending' );
sd_route( $portal, 'pending_bulk', array( 'uc_pending_bulk_present' => '1', 'uc_do' => 'dismiss', 'pending_ids' => array( $imp, $subm ) ) );
sd_is( get_post_status( $imp ), 'uc_dismissed', 'Dismiss dismisses a ticked import' );
sd_is( get_post_status( $subm ), 'pending', 'Dismiss leaves a submission waiting' );
$held = get_transient( 'sfaf_held_1' );
sd_is( isset( $held[ $subm ] ), true, 'and names it' );

/* A row that is not in the queue is never reached, whatever the form says. */
sd_world();
$live = sd_event( 'publish' );
sd_route( $portal, 'pending_bulk', array( 'uc_pending_bulk_present' => '1', 'uc_do' => 'categories',
    'pending_ids' => array( $live ), 'bulk_categories' => array( '12' ) ) );
sd_is( sd_terms( $live, 'uc_event_category' ), array(), 'a published event named in the POST is not changed by the pending bar' );

/* Both older bulk publishes ask the same rule, through publish_skip_reason(). */
sd_world();
$d1 = sd_event( 'draft', array( 'uc_organizer' => array( 11 ) ), array(), '<p>Words.</p>' );
sd_is( SFAF_Series::publish_skip_reason( $d1, '2026-09-25' ), 'needs a category', 'the events list and schedule bulk publish hold a draft with no category' );
$d2 = sd_event( 'draft', array( 'uc_organizer' => array( 11 ), 'uc_event_category' => array( 11 ), 'uc_series' => array( 11 ) ) );
sd_is( SFAF_Series::publish_skip_reason( $d2, '2026-09-25' ), '', 'and let one through whose description is the series\'' );

/* The row's tick carries the rule's answer, so the bar's Publish counts only
 * what it would publish. */
sd_world();
$ok_row   = sd_event( 'uc_imported', array( 'uc_organizer' => array( 11 ), 'uc_event_category' => array( 11 ) ), array(), '<p>Words.</p>' );
$held_row = sd_event( 'uc_imported' );
ob_start();
kit_call( 'SFAF_Portal', 'pending_row', $portal, array( $ok_row, array( 'kind' => 'import', 'shape' => 'import' ) ) );
kit_call( 'SFAF_Portal', 'pending_row', $portal, array( $held_row, array( 'kind' => 'import', 'shape' => 'import' ) ) );
$rows = (string) ob_get_clean();
sd_is( (bool) preg_match( '#name="pending_ids\[\]" value="' . $ok_row . '"\s+form="uc-pending-bulk" data-uc-tick-one\s+aria-label#', $rows ), true, 'a complete row\'s tick carries no block' );
sd_is( (bool) preg_match( '#value="' . $held_row . '"\s+form="uc-pending-bulk" data-uc-tick-one\s+data-uc-tick-block="needs #', $rows ), true, 'an incomplete row\'s tick carries the reason' );

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . ' of ' . $checks . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "Series defaults, the pending bar and the publish rule: {$checks} checks, all by running the real code.\n";
echo "Defaults fill only what is empty, at every join, once; the bar reaches only ticked rows; every publish holds an incomplete event and names it.\n";
exit( 0 );
