<?php
/**
 * WHAT THE PREFILL HANDS OVER FOR A LOCATION (3.105.0).
 *
 *     php .claude/prefill-location-test.php
 *
 * The server half of the location fix. prefill_data() gave the composed line
 * and nothing else, and the editor has no box for a composed line; the JS half
 * is asserted by prefill-image-test.js, which writes these keys into the
 * boxes. This runs the real prefill_data() through wp-kit.php over three last
 * events: one with the four parts and a place name, one stored before the
 * parts existed (a single line, parsed on read), one with only a place name.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function pl_check( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = $label . ': got ' . json_encode( $got ) . ', expected ' . json_encode( $want );
    }
}

function pl_query( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type ) { continue; }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
}
$GLOBALS['kit_query'] = 'pl_query';

function pl_event( $meta ) {
    kit_reset();
    $GLOBALS['kit_query'] = 'pl_query';
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Coffee social' ) );
    update_post_meta( $id, '_uc_event_date', '2026-10-01' );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    wp_set_object_terms( $id, array( 11 ), 'uc_series' );
    return SFAF_Series::prefill_data( 11 );
}

/* 1. The four parts and a name, as the editor saves them now. */
$d = pl_event( array(
    '_uc_location'        => '470 Castro St, San Francisco, CA 94114',
    '_uc_location_name'   => 'Strut',
    '_uc_location_street' => '470 Castro St',
    '_uc_location_city'   => 'San Francisco',
    '_uc_location_state'  => 'CA',
    '_uc_location_zip'    => '94114',
) );
pl_check( 'parts: the mode', $d['location_mode'], 'custom' );
pl_check( 'parts: the place name', $d['location_name'], 'Strut' );
pl_check( 'parts: the four parts', $d['location_parts'], array( 'street' => '470 Castro St', 'city' => 'San Francisco', 'state' => 'CA', 'zip' => '94114' ) );

/* 2. One line, stored before the address was split: parsed, not dropped. */
$d = pl_event( array( '_uc_location' => '1035 Market St, San Francisco, CA 94103' ) );
pl_check( 'one line: the mode', $d['location_mode'], 'custom' );
pl_check( 'one line: the ZIP is parsed out', $d['location_parts']['zip'], '94103' );
pl_check( 'one line: nothing is lost into nowhere', '' !== $d['location_parts']['street'], true );

/* 3. A place name and no address is still a place. */
$d = pl_event( array( '_uc_location_name' => 'Dolores Park' ) );
pl_check( 'name only: offered', $d['location_mode'], 'custom' );
pl_check( 'name only: the name', $d['location_name'], 'Dolores Park' );

/* 4. A venue sends the venue and no parts. */
$d = pl_event( array() );
pl_check( 'nothing at all: nothing offered', $d['location_mode'], '' );

if ( $fails ) {
    echo "PREFILL LOCATION: " . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "prefill location: the name and the four parts are handed over, an old one-line address is parsed, a name alone is a place.\n";
