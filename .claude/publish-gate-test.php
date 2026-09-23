<?php
/**
 * THE EDITOR PUBLISHES ONLY A COMPLETE EVENT, AND A DRAFT NEEDS ONLY A TITLE (3.99.0).
 *
 *     php .claude/publish-gate-test.php
 *
 * EVERY CASE IS THE REAL SAVE. save_event_from_post() runs against the
 * miniature WordPress in wp-kit.php, and what is asserted is what it WROTE:
 * the status the event ended up with and the message the editor will show.
 * Not whether a list contains a key, and not whether a method returns a
 * string, because both of those can be true while the save does something
 * else. 3.40.0 is the release that learned that about this screen.
 *
 * WHAT A PERSON STILL HAS TO CHECK is in TESTING.md: the screen after a held
 * publish, and a publish from the pending queue, which is not this path.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();

/** A complete publish, which each case then takes something away from. */
function gate_post( $over = array(), $drop = array() ) {
    $p = array(
        'event_id'             => 0,
        'save_mode'            => 'publish',
        'title'                => 'Drop-in group',
        'date'                 => '2026-12-01',
        'start_time'           => '18:00',
        'end_time'             => '19:30',
        'uc_organizer_present' => '1',
        'organizer'            => array( 11 ),
        'uc_category_present'  => '1',
        'category'             => array( 12 ),
        'description'          => '<p>A weekly peer group.</p>',
        'uc_online_present'    => '1',
        'uc_online'            => '0',
        'location_mode'        => 'custom',
        'location_street'      => '470 Castro St',
        'location_city'        => 'San Francisco',
    );
    foreach ( $drop as $k ) { unset( $p[ $k ] ); }
    return array_merge( $p, $over );
}

/** Run one save and report what it did. */
function gate_save( $portal, $post ) {
    $_POST = $post;
    $_GET  = array();
    $GLOBALS['kit_writes'] = array();
    $r = kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( new WP_User() ) );
    $id = (int) $r['id'];
    return array(
        'msg'    => $r['msg'],
        'needs'  => isset( $r['needs'] ) ? $r['needs'] : '',
        'status' => $id ? get_post_status( $id ) : '',
        'wrote'  => count( $GLOBALS['kit_writes'] ),
        'id'     => $id,
    );
}

/** The flash the editor shows for a result. */
function gate_flash( $portal, $r ) {
    $_GET = array( 'msg' => $r['msg'], 'needs' => $r['needs'] );
    ob_start();
    kit_call( 'SFAF_Portal', 'flash', $portal );
    return trim( html_entity_decode( strip_tags( (string) ob_get_clean() ), ENT_QUOTES, 'UTF-8' ) );
}

function gate_is( $got, $want, $why ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $why . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

/** An event already stored, for the cases about existing ones. */
function gate_existing( $status, $meta = array(), $terms = array(), $content = '<p>Words.</p>' ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Stored event', 'post_content' => $content ) );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    foreach ( $terms as $tax => $ids ) { wp_set_object_terms( $id, $ids, $tax ); }
    return $id;
}

/* ---- 1. A complete event publishes. ---- */
kit_reset();
$r = gate_save( $portal, gate_post() );
gate_is( $r['status'], 'publish', 'a complete event did not publish' );
gate_is( $r['needs'], '', 'a complete event was said to need something' );

/* ---- 2. The one the brief names: no category, and it does not go out. ---- */
kit_reset();
$r = gate_save( $portal, gate_post( array(), array( 'category' ) ) );
gate_is( $r['status'], 'draft', 'a publish with no category went out' );
gate_is( $r['msg'], 'publish_needs', 'a held publish did not say so' );
gate_is( $r['needs'], 'category', 'the held publish named the wrong fields' );
gate_is( gate_flash( $portal, $r ), 'Saved, and not published. Add a category, then publish.', 'the flash for one missing field' );

/* ---- 3. Several at once, all named, in the list's order, in one message. ---- */
kit_reset();
$r = gate_save( $portal, gate_post( array( 'description' => '<p> </p>', 'start_time' => '' ),
    array( 'end_time', 'organizer', 'location_street', 'location_city' ) ) );
gate_is( $r['status'], 'draft', 'a publish missing four things went out' );
gate_is( $r['needs'], 'start_time,end_time,organizer,description,location', 'the missing fields, in order' );
gate_is( gate_flash( $portal, $r ),
    'Saved, and not published. Add a start time, an end time, an organizer, a description, and a location, then publish.',
    'the flash names every missing field in one sentence' );

/* ---- 4. Markup is not a description. ---- */
kit_reset();
$r = gate_save( $portal, gate_post( array( 'description' => '<p><br></p>' ) ) );
gate_is( $r['needs'], 'description', 'an empty paragraph counted as a description' );

/* ---- 5. Online answers "where", and so does hybrid. ---- */
kit_reset();
$r = gate_save( $portal, gate_post( array( 'uc_online' => '1' ), array( 'location_street', 'location_city' ) ) );
gate_is( $r['status'], 'publish', 'an online event with no address was held for a location' );
kit_reset();
$r = gate_save( $portal, gate_post( array( 'uc_hybrid' => '1' ), array( 'location_street', 'location_city' ) ) );
gate_is( $r['status'], 'publish', 'a hybrid event with no address was held for a location' );

/* ---- 6. A venue answers "where". ---- */
kit_reset();
$r = gate_save( $portal, gate_post( array( 'location_mode' => 'venue', 'venue' => 11 ), array( 'location_street', 'location_city' ) ) );
gate_is( $r['status'], 'publish', 'an event at a venue was held for a location' );

/* ---- 7. A draft needs only a title. Nothing else refuses or holds it. ---- */
kit_reset();
$r = gate_save( $portal, array( 'event_id' => 0, 'save_mode' => 'draft', 'title' => 'Just a name', 'description' => '' ) );
gate_is( $r['status'], 'draft', 'a draft with only a title was not saved as a draft' );
gate_is( $r['msg'], 'saved', 'a draft with no description was held or refused' );
gate_is( $r['wrote'] > 0, true, 'a draft with no description wrote nothing' );

/* ---- 8. Submitting for review is not publishing either. ---- */
kit_reset();
$r = gate_save( $portal, gate_post( array( 'save_mode' => 'review' ), array( 'category', 'description' ) ) );
gate_is( $r['status'], 'pending', 'submitting for review was held as if it were a publish' );

/* ---- 9. An event already published is saved as it is (the hundred). ---- */
kit_reset();
$id = gate_existing( 'publish', array( '_uc_event_date' => '2026-12-01' ) );
$r = gate_save( $portal, gate_post( array( 'event_id' => $id, 'save_mode' => 'keep' ), array( 'category', 'organizer', 'uc_organizer_present' ) ) );
gate_is( $r['status'], 'publish', 'an already-published event missing a category was taken off the calendar' );
gate_is( $r['msg'] === 'publish_needs', false, 'saving an already-published event said it was held' );

/* ---- 10. A pending event being published is held, and stays pending. ---- */
kit_reset();
$id = gate_existing( 'pending' );
$r = gate_save( $portal, gate_post( array( 'event_id' => $id ), array( 'category' ) ) );
gate_is( $r['status'], 'pending', 'a held publish of a pending event did not leave it pending' );
gate_is( $r['needs'], 'category', 'the pending event was held for the wrong thing' );

/* ---- 11. Unticking every organizer on an event that had some is refused. ---- */
kit_reset();
$id = gate_existing( 'draft', array(), array( 'uc_organizer' => array( 11 ) ) );
$r = gate_save( $portal, gate_post( array( 'event_id' => $id ), array( 'organizer' ) ) );
gate_is( $r['msg'], 'organizer_required', 'unticking every organizer was not refused' );
gate_is( $r['wrote'], 0, 'the refused save wrote something' );

/* ---- 12. A field the form did not show is read from storage. ---- */
kit_reset();
$id = gate_existing( 'draft', array(), array( 'uc_event_category' => array( 12 ), 'uc_organizer' => array( 11 ) ) );
$r = gate_save( $portal, gate_post( array( 'event_id' => $id ), array( 'uc_category_present', 'category', 'uc_organizer_present', 'organizer' ) ) );
gate_is( $r['status'], 'publish', 'a stored category and organizer were ignored because the form did not show them' );

/* ---- 13. The list the marks and the hold both read. ---- */
gate_is( array_keys( SFAF_Sources::publish_fields() ),
    array( 'title', 'date', 'start_time', 'end_time', 'organizer', 'category', 'description', 'location' ),
    'publish_fields() is not the list the brief gives' );

/* ---- 14. requirement() still answers its old five-argument callers. ---- */
gate_is( SFAF_Organizers::requirement( array(), array(), true, 'publish', '' ), 'hold', 'no organizers on a new publish' );
gate_is( SFAF_Organizers::requirement( array(), array( 11 ), false, 'publish', 'draft' ), 'refuse', 'unticking every organizer' );
gate_is( SFAF_Organizers::requirement( array( 11 ), array(), true, 'publish', '', array( 'category' ) ), 'hold', 'an organizer and no category' );
gate_is( SFAF_Organizers::requirement( null, array(), true, 'draft', '', array( 'category' ) ), 'ok', 'a draft missing a category' );
gate_is( SFAF_Organizers::requirement( null, array(), false, 'publish', 'publish', array( 'category' ) ), 'ok', 'one of the hundred' );

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "every case below is the real save, and what it wrote:\n";
echo "  a complete event publishes; one missing a category is saved as a draft and not published,\n";
echo "  several missing are all named in one message, in order; markup alone is not a description;\n";
echo "  online, hybrid and a venue each answer where; a draft with only a title saves and is not held;\n";
echo "  review is not publish; an already-published event is saved as it is; a pending one stays pending;\n";
echo "  unticking every organizer is still refused; a field the form did not show is read from storage.\n";
exit( 0 );
