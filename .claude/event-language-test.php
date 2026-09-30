<?php
/**
 * THE EVENT'S LANGUAGE, THROUGH THE REAL EDITOR SAVE (3.106.0).
 *
 *     php .claude/event-language-test.php
 *
 * SFAF_Portal::save_event_from_post() on wp-kit.php. The rule:
 *
 *   left on what it inherits, never set    stores nothing, follows its series
 *   a choice other than what it inherits   stored as its own
 *   an event that already has its own      the choice is stored, even when it
 *                                          matches the series
 *
 * and sfaf_event_language() reads own, else series, else English. The field is
 * drawn with the series' languages so the form can follow a series change, and
 * the key travels with a repeating event and the group copy.
 */

require __DIR__ . '/wp-kit.php';

$fails  = array();
$checks = 0;
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();

function el( $got, $want, $why ) {
    global $fails, $checks;
    $checks++;
    if ( $got !== $want ) { $fails[] = $why . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}

function el_query( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' === $p->post_type ) { $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; }
    }
    return $out;
}

function el_world() {
    kit_reset();
    $GLOBALS['kit_query']           = 'el_query';
    $GLOBALS['kit_redirect_throws'] = true;
    $GLOBALS['kit_term_desc'][11]   = '<p>Grupo semanal.</p>';
    $GLOBALS['kit_term_desc'][12]   = '<p>Weekly group.</p>';
    update_term_meta( 11, SFAF_Series::META_LANGUAGE, 'es' );
    update_term_meta( 11, SFAF_Series::META_CATEGORIES, array( 12 ) );
    update_term_meta( 11, SFAF_Series::META_ORGANIZERS, array( 11 ) );
    update_term_meta( 12, SFAF_Series::META_CATEGORIES, array( 12 ) );
    update_term_meta( 12, SFAF_Series::META_ORGANIZERS, array( 11 ) );
}

function el_save( $over ) {
    global $portal;
    $_GET  = array();
    $_POST = array_merge( array(
        'event_id' => 0, 'save_mode' => 'publish', 'title' => 'Grupo de apoyo', 'date' => '2026-12-03',
        'start_time' => '10:00', 'end_time' => '11:30', 'uc_organizer_present' => '1',
        'uc_category_present' => '1', 'description' => '', 'uc_online_present' => '1', 'uc_online' => '0',
        'location_mode' => 'custom', 'location_street' => '1035 Market St', 'location_city' => 'San Francisco',
        'series' => '11',
    ), $over );
    $r = kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( new WP_User() ) );
    return (int) $r['id'];
}

el_world();
$a = el_save( array( 'event_language' => 'es' ) );
el( get_post_meta( $a, '_uc_language', true ), '', 'PLANT: left on the Spanish its series gives, nothing is stored' );
el( sfaf_event_language( $a ), 'es', 'and it speaks its series\' Spanish' );

$b = el_save( array( 'event_language' => 'en' ) );
el( get_post_meta( $b, '_uc_language', true ), 'en', 'English chosen in a Spanish series is stored as its own' );
el( sfaf_event_language( $b ), 'en', 'and it speaks English' );

$c = el_save( array( 'series' => '12', 'event_language' => 'es' ) );
el( get_post_meta( $c, '_uc_language', true ), 'es', 'Spanish chosen in an English series is stored' );

$d = el_save( array( 'series' => '', 'event_language' => 'en' ) );
el( get_post_meta( $d, '_uc_language', true ), '', 'no series, left on English: nothing stored' );
el( sfaf_event_language( $d ), 'en', 'and it speaks English' );

/* An event with its own keeps its own, even set to what the series says. */
$_POST = array();
$e = el_save( array( 'series' => '12', 'event_language' => 'es' ) );
$e = el_save( array( 'event_id' => (string) $e, 'series' => '11', 'event_language' => 'es' ) );
el( get_post_meta( $e, '_uc_language', true ), 'es', 'an event with its own stays its own when it matches the series' );

/* A save with no field leaves the value alone. */
$f = el_save( array( 'event_id' => (string) $b ) );
el( get_post_meta( $f, '_uc_language', true ), 'en', 'a save with no language field leaves the stored one alone' );

/* Anything that is not es is English. */
$g = el_save( array( 'series' => '11', 'event_language' => 'fr' ) );
el( get_post_meta( $g, '_uc_language', true ), 'en', 'an unknown language is stored as English' );

/* The field, from source. */
$root = dirname( __DIR__ );
$port = (string) file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$rec  = (string) file_get_contents( $root . '/includes/class-sfaf-recurrence.php' );
el( substr_count( $port, "'_uc_email_required', '_uc_language'" ), 2, 'the key is in both of the editor\'s copy lists' );
el( false !== strpos( $rec, "'_uc_language'" ), true, 'and travels with a repeating event' );
el( false !== strpos( $port, 'data-uc-series-langs' ), true, 'the field carries the series\' languages for the form to follow' );

if ( $fails ) {
    echo 'EVENT LANGUAGE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
printf( "event language: %d checks; stored only when chosen, inherited otherwise, own kept, travels.\n", $checks );
