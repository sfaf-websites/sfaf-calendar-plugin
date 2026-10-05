<?php
/**
 * AN EMPTY BOX IS UNLIMITED AND 0 IS NO PLACES (3.107.0).
 *
 *     php .claude/capacity-zero-test.php
 *
 * Through wp-kit.php, so the real functions answer:
 *
 *   the rule      sfaf_capacity_limited(), sfaf_format_full(), sfaf_event_full()
 *                 and SFAF_Waitlist::applies() for '', '0' and '5', on an
 *                 ordinary event and per format on a hybrid one. 0 is full
 *                 from the first person, so the button says Join the waitlist.
 *   the save      the editor's box stores what was typed: empty stays empty,
 *                 0 stays 0, anything else is a whole number or empty.
 *   the migration every stored 0 emptied, nothing else touched, the option
 *                 written; and a second run, after somebody types 0, leaves
 *                 that 0 alone. Without the guard it would undo the choice.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function cz( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function cz_event( $meta ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Dinner', 'post_author' => 1 ) );
    foreach ( array_merge( array( '_uc_rsvp_enabled' => '1' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    return $id;
}

/* ---- The rule. ---- */
$none = cz_event( array( '_uc_capacity' => '' ) );
$zero = cz_event( array( '_uc_capacity' => '0' ) );
$five = cz_event( array( '_uc_capacity' => '5' ) );
$gone = cz_event( array() );   // no meta at all, as an event made before capacity existed
cz( ! sfaf_capacity_limited( $none ) && ! sfaf_capacity_limited( $gone ), 'PLANT B.3: an empty or missing capacity is read as a limit' );
cz( sfaf_capacity_limited( $zero ) && sfaf_capacity_limited( $five ), 'PLANT B.3: 0 or 5 is not read as a limit' );
cz( ! sfaf_format_full( $none, '' ) && ! sfaf_event_full( $none ), 'PLANT B.3: an unlimited event is full' );
cz( sfaf_format_full( $zero, '' ) && sfaf_event_full( $zero ), 'PLANT B.3: a capacity of 0 is not full from the first person' );
cz( ! sfaf_format_full( $five, '' ), 'PLANT B.3: 5 places with nobody registered is full' );
cz( SFAF_Waitlist::applies( $zero ) && ! SFAF_Waitlist::applies( $none ), 'PLANT B.3: the waitlist does not apply to 0 places, or applies to no limit' );

$hyb = cz_event( array( SFAF_Online::META_HYBRID => '1', '_uc_capacity' => '0', '_uc_capacity_online' => '' ) );
cz( SFAF_Online::is_hybrid( $hyb ), 'the hybrid event is not hybrid; the fixture is wrong' );
cz( sfaf_format_full( $hyb, SFAF_Online::MODE_IN_PERSON ) && ! sfaf_format_full( $hyb, SFAF_Online::MODE_ONLINE ) && ! sfaf_event_full( $hyb ),
    'PLANT B.3: hybrid with 0 in person and no online limit is not "in person full, online open"' );

/* The public button's label, from the same question. */
$label = function ( $id ) { return sfaf_event_full( $id ) ? 'Join the waitlist' : 'RSVP'; };
cz( 'Join the waitlist' === $label( $zero ) && 'RSVP' === $label( $none ), 'PLANT B.3: the button label is wrong for 0 or empty' );

/* ---- The save. ---- */
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$saved  = cz_event( array( '_uc_capacity' => '9' ) );
$unlocked = function () { return false; };
foreach ( array( '' => '', '0' => '0', ' 12 ' => '12', '-3' => '0', 'lots' => '' ) as $typed => $want ) {
    $_POST = array( 'capacity' => $typed );
    kit_call( 'SFAF_Portal', 'save_rsvp_settings_from_post', $portal, array( new WP_User(), $saved, $unlocked ) );
    $got = get_post_meta( $saved, '_uc_capacity', true );
    cz( $want === $got, 'PLANT B.3: typing ' . json_encode( $typed ) . ' stored ' . json_encode( $got ) . ', wanted ' . json_encode( $want ) );
}
$_POST = array();

/* Emptying the box asks the waitlist for offers, as a raise does, and saving the
 * same number does not. Seen through the query advance() makes for the queue. */
$asked = function ( $from, $to ) use ( $portal, $unlocked ) {
    $id = cz_event( array( '_uc_capacity' => $from ) );
    $GLOBALS['cz_sql'] = array();
    $GLOBALS['kit_db'] = function ( $method, $sql ) { $GLOBALS['cz_sql'][] = $sql; return 'get_results' === $method ? array() : null; };
    $_POST = array( 'capacity' => $to );
    kit_call( 'SFAF_Portal', 'save_rsvp_settings_from_post', $portal, array( new WP_User(), $id, $unlocked ) );
    $_POST = array();
    $GLOBALS['kit_db'] = null;
    foreach ( $GLOBALS['cz_sql'] as $q ) { if ( false !== strpos( $q, 'SELECT * FROM wp_uc_rsvps WHERE event_id = ' . $id ) ) { return true; } }
    return false;
};
cz( $asked( '5', '' ), 'PLANT LIMIT OFF: emptying the capacity box does not offer places to the waitlist' );
cz( $asked( '5', '8' ), 'PLANT LIMIT OFF: raising the capacity does not offer places to the waitlist' );
cz( ! $asked( '5', '5' ), 'saving the same capacity asks the waitlist for offers' );

/* ---- The two public request forms follow the same rule (3.107.0). ---- */
foreach ( array( 'SFAF_Request' => array( 'rsvp' => '1' ), 'SFAF_Submit' => array() ) as $form => $base ) {
    foreach ( array( '' => '', '0' => 0, '25' => 25 ) as $typed => $want ) {
        $r = call_user_func( array( $form, 'validate' ), array_merge( $base, array( 'capacity' => $typed ) ) );
        cz( ! isset( $r['errors']['capacity'] ) && $want === $r['clean']['capacity'],
            "PLANT FORMS: $form reads " . json_encode( $typed ) . ' as ' . json_encode( $r['clean']['capacity'] ?? null ) . ', wanted ' . json_encode( $want ) );
    }
}
/* And what each writes. create_event() is long, so its capacity lines are run
 * on their own, sliced from the source as written. */
foreach ( array( 'includes/class-sfaf-request.php', 'includes/class-sfaf-submit.php' ) as $rel ) {
    $src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
    $ok  = preg_match( "/\n(\s*)if \( '' !== \(string\) \\\$c\['capacity'\] \) \{\n\s*update_post_meta\( \\\$event_id, '_uc_capacity', \(string\) \(int\) \\\$c\['capacity'\] \);\n\s*\}/", $src, $m );
    cz( 1 === $ok, "PLANT FORMS: $rel does not write the capacity as typed, 0 included" );
    if ( $ok ) {
        foreach ( array( '' => null, 0 => '0', 25 => '25' ) as $given => $want ) {
            $event_id = kit_write_post( array( 'post_type' => 'uc_event' ) );
            $c = array( 'capacity' => $given );
            eval( $m[0] . ';' );
            $got = array_key_exists( '_uc_capacity', get_post_meta( $event_id ) ) ? get_post_meta( $event_id, '_uc_capacity', true ) : null;
            cz( $want === $got, "PLANT FORMS: $rel stored " . json_encode( $got ) . ' for ' . json_encode( $given ) );
        }
    }
}

/* ---- The migration. ---- */
kit_reset();
$GLOBALS['kit_query'] = function ( $a ) {
    $key = $a['meta_query'][0]['key'];
    $out = array();
    foreach ( $GLOBALS['kit_meta'] as $id => $m ) { if ( array_key_exists( $key, $m ) ) { $out[] = (int) $id; } }
    return $out;
};
$m0  = cz_event( array( '_uc_capacity' => '0' ) );
$m00 = cz_event( array( '_uc_capacity' => ' 0 ' ) );
$m7  = cz_event( array( '_uc_capacity' => '7' ) );
$me  = cz_event( array( '_uc_capacity' => '' ) );
$mo  = cz_event( array( SFAF_Online::META_HYBRID => '1', '_uc_capacity' => '4', '_uc_capacity_online' => '0' ) );
$n   = sfaf_migrate_capacity_zero();
cz( 3 === $n, "PLANT B.3: the migration emptied $n values, wanted 3" );
cz( '' === get_post_meta( $m0, '_uc_capacity', true ) && '' === get_post_meta( $m00, '_uc_capacity', true ), 'PLANT B.3: a stored 0 was not emptied' );
cz( '7' === get_post_meta( $m7, '_uc_capacity', true ) && '' === get_post_meta( $me, '_uc_capacity', true ), 'PLANT B.3: the migration touched a value that was not 0' );
cz( '4' === get_post_meta( $mo, '_uc_capacity', true ) && '' === get_post_meta( $mo, '_uc_capacity_online', true ), 'PLANT B.3: the online 0 was not emptied, or the in-person 4 was' );
$opt = get_option( 'sfaf_capacity_zero_migration' );
cz( is_array( $opt ) && 3 === $opt['touched'], 'PLANT B.3: the migration did not record itself: ' . json_encode( $opt ) );
update_post_meta( $m7, '_uc_capacity', '0' );   // somebody chooses "no places" after the update
sfaf_migrate_capacity_zero();
cz( '0' === get_post_meta( $m7, '_uc_capacity', true ), 'PLANT B.3: a second run emptied a 0 typed after the update' );
cz( '12' === SFAF_DB_VERSION, 'the schema is ' . SFAF_DB_VERSION . ', wanted 12, so the migration would never run on update' );

if ( $fails ) {
    echo 'CAPACITY: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "capacity: empty is unlimited, 0 is waitlist-only from the first person, the save stores what was typed, and the one-time migration empties every old 0 and nothing else, once.\n";
