<?php
/**
 * 3.120.0: THE PUBLIC EVENT PAGE: ONE PLACE NAME, NO SECOND REGISTER LINK,
 * AND A SPANISH EVENT'S LABELS IN BOTH LANGUAGES.
 *
 *     php .claude/release-31200-test.php
 *
 * The real templates/single-uc_event.php through wp-kit.php.
 *
 *   A  a typed place's name shows once; the map link and Getting there show
 *      the address, and the map still searches for name and address
 *   B  a community submission's registration address is not a second
 *      "Register for this event" link
 *   C  on a Spanish event the page's own labels read "English / Español";
 *      an English event's page is unchanged; typed content is as typed
 *   D  outside the event page (a card, the embed) the same helpers say English
 *
 * The embed is embed-identity-test.php's. PLANTs in plant-31200.php.
 */

$GLOBALS['loop_done'] = false;
function have_posts() { return ! $GLOBALS['loop_done']; }
function the_post() { $GLOBALS['loop_done'] = true; $GLOBALS['kit_current'] = $GLOBALS['ev']; }
function get_header() { echo "<!--header-->\n"; }
function get_footer() { echo "\n<!--footer-->"; }
require __DIR__ . '/wp-kit.php';
foreach ( array( 'wpautop' => 'return "<p>".$a[0]."</p>";', 'number_format_i18n' => 'return (string)$a[0];', '_n' => 'return $a[2]==1?$a[0]:$a[1];' ) as $f => $b ) { if ( ! function_exists( $f ) ) { eval( "function $f( ...\$a ) { $b }" ); } }
if ( ! function_exists( 'the_title' ) ) { function the_title() { echo get_the_title( get_the_ID() ); } }
if ( ! function_exists( 'the_content' ) ) { function the_content() { echo get_post_field( 'post_content', get_the_ID() ); } }

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function rt_page( $lang ) {
    $ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Drop-in Group', 'post_name' => 'drop-in-' . $lang, 'post_content' => '<p>Escrito por la organizadora.</p>' ) );
    $GLOBALS['ev'] = $ev;
    foreach ( array( '_uc_event_date' => '2026-11-05', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_cost' => 'Free', '_uc_age_restriction' => '18+',
        '_uc_rsvp_enabled' => '1', '_uc_rsvp_url' => 'https://example.org/rsvp', '_uc_language' => $lang,
        '_uc_location_name' => 'Strut', '_uc_location' => '470 Castro St, San Francisco, CA 94114' ) as $k => $v ) {
        update_post_meta( $ev, $k, $v );
    }
    update_post_meta( $ev, '_uc_faqs', array( array( 'question' => 'Q?', 'answer' => 'A.' ) ) );
    wp_set_object_terms( $ev, array( 11 ), 'uc_series' );
    $GLOBALS['kit_query'] = function ( $a ) use ( $ev ) { return array( $ev ); };
    $GLOBALS['loop_done'] = false;
    ob_start();
    include $GLOBALS['kit_root'] . '/templates/single-uc_event.php';
    return array( $ev, (string) ob_get_clean() );
}

list( $en_id, $en ) = rt_page( 'en' );
list( $es_id, $es ) = rt_page( 'es' );
$sidebar = (string) strstr( $en, 'uc-single-sidebar' );

/* ======================================================================== A */
rt( 'A: the place name shows once in the sidebar', substr_count( strip_tags( $sidebar ), 'Strut' ), 1 );
rt( 'A: the map link shows the address alone', (bool) preg_match( '#class="uc-fact-maplink"[^>]*>470 Castro St, San Francisco, CA 94114</a>#', $en ), true );
rt( 'A: and searches for name and address', false !== strpos( $en, 'Strut%2C+470+Castro' ) || false !== strpos( $en, 'Strut%2C%20470%20Castro' ) || false !== strpos( $en, urlencode( 'Strut, 470 Castro St' ) ), true );
$map = sfaf_event_map_html( $en_id );
rt( 'A: Getting there shows the address alone', (bool) preg_match( '#rel="noopener noreferrer">470 Castro St, San Francisco, CA 94114</a>#', $map ), true );
rt( 'A: the page names Strut once in all', substr_count( $en, '>Strut<' ) + substr_count( $en, 'Strut, 470' ), 1 );

/* ======================================================================== B */
rt( 'B: no second Register for this event link', false === strpos( $en, 'Register for this event' ) && false === strpos( $en, 'https://example.org/rsvp' ), true );

/* ======================================================================== C */
foreach ( array( 'Cost / Costo', 'Ages / Edades', 'Getting there / Cómo llegar', 'Todos los eventos', 'Preguntas frecuentes', 'Share: / Compartir:' ) as $w ) {
    rt( "C: the Spanish page reads \"$w\"", false !== strpos( $es, $w ), true );
}
rt( 'C: and its RSVP button', false !== strpos( $es, 'Register / Inscribirse' ) || false !== strpos( $es, 'Join the waitlist / Únase a la lista de espera' ), true );
rt( 'C: what the organizer typed is as typed', false !== strpos( $es, 'Escrito por la organizadora.' ), true );
rt( 'C: the English page has no Spanish', false === strpos( $en, ' / Costo' ) && false === strpos( $en, 'Cómo llegar' ) && false === strpos( $en, 'Preguntas frecuentes' ), true );
rt( 'C: and its labels as before', false !== strpos( $en, '<span class="uc-fact-label">Cost</span>' ) && false !== strpos( $en, '&larr; All Events</a>' ), true );
rt( 'C: the page clears its event at the end', sfaf_bilingual_page(), 0 );

/* ======================================================================== D */
rt( 'D: a card says English for the same Spanish event', sfaf_page_label( $es_id, 'cost' ), 'Cost' );
sfaf_bilingual_page( $es_id );
rt( 'D: and the page says both', sfaf_page_label( $es_id, 'cost' ), 'Cost / Costo' );
rt( 'D: an English event on its page says English', sfaf_page_label( $en_id, 'cost' ), 'Cost' );
sfaf_bilingual_page( 0 );
rt( 'D: Register on, both sides filled', ( function () use ( $es_id ) { sfaf_bilingual_page( $es_id ); $l = sfaf_page_label( $es_id, 'register_on', 'Eventbrite' ); sfaf_bilingual_page( 0 ); return $l; } )(), 'Register on Eventbrite / Inscribirse en Eventbrite' );

echo "\n3.120.0: the public event page\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. A typed place is named once, the second register link is gone, and a Spanish event's page reads\nits labels in both languages while everything else stays English.\n";
exit( 0 );
