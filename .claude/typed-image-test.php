<?php
/**
 * A TYPED IMAGE URL IS THE EVENT'S OWN PICTURE, WHEREVER IT POINTS (3.104.0).
 *
 *     php .claude/typed-image-test.php
 *
 * Mark's decision, reversing 3.83.0 for one box: a URL a person types into the
 * editor's "Or an image URL" box wins over the series picture even when it
 * points into uploads outside the calendar folder. The folder rule stays for
 * attachments and for any value another writer put in `_uc_image_url`.
 *
 * The URL goes in through the real save (save_manager_fields_from_post(), the
 * one both the editor and the pending row's panel save through), and comes out
 * through the real renderers: the event page's picture, the grid card, the
 * list card, the month grid (whose tile carries the hover preview's picture),
 * and the pending row's own image control.
 */

require __DIR__ . '/wp-kit.php';

$fails  = array();
$checks = 0;
function ti_is( $got, $want, $why ) {
    global $fails, $checks;
    $checks++;
    if ( $got !== $want ) { $fails[] = $why . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function ti_has( $html, $needle, $why ) {
    global $fails, $checks;
    $checks++;
    if ( false === strpos( (string) $html, $needle ) ) { $fails[] = $why . ': ' . $needle . ' is not in it'; }
}
function ti_lacks( $html, $needle, $why ) {
    global $fails, $checks;
    $checks++;
    if ( false !== strpos( (string) $html, $needle ) ) { $fails[] = $why . ': ' . $needle . ' is in it'; }
}

$SERIES = 'https://resources.sfaf.org/wp-content/uploads/calendar/series-coffee.jpg';
$TYPED  = 'https://resources.sfaf.org/wp-content/uploads/2026/09/typed-coffee.jpg';
$OTHER  = 'https://resources.sfaf.org/wp-content/uploads/2026/09/imported-coffee.jpg';

$GLOBALS['kit_query'] = function ( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' === $p->post_type && 'publish' === $p->post_status ) {
            $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
        }
    }
    return $out;
};
update_term_meta( 11, SFAF_Series::META_IMAGE_URL, $SERIES );

function ti_event( $title ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>Coffee.</p>' ) );
    foreach ( array( '_uc_event_date' => '2026-12-03', '_uc_start_time' => '10:00', '_uc_end_time' => '11:30', '_uc_location' => '1035 Market St' ) as $k => $v ) {
        update_post_meta( $id, $k, $v );
    }
    wp_set_object_terms( $id, array( 11 ), 'uc_series' );
    wp_set_object_terms( $id, array( 12 ), 'uc_event_category' );
    return $id;
}

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$unlocked = function ( $f ) { return false; };

/* Typed into the box, through the real save. */
$typed = ti_event( 'Typed' );
$_POST = array( 'featured_image_id' => '0', 'image_url' => $TYPED );
kit_call( 'SFAF_Portal', 'save_manager_fields_from_post', $portal, array( new WP_User(), $typed, $unlocked ) );

/* Written by something other than the box, the way an import would. */
$imported = ti_event( 'Imported' );
update_post_meta( $imported, '_uc_image_url', $OTHER );

/* Typed once, then overwritten by another writer: the exemption is gone. */
$later = ti_event( 'Later' );
$_POST = array( 'featured_image_id' => '0', 'image_url' => $TYPED );
kit_call( 'SFAF_Portal', 'save_manager_fields_from_post', $portal, array( new WP_User(), $later, $unlocked ) );
update_post_meta( $later, '_uc_image_url', $OTHER );

/* Typed, then the box emptied: back to the series picture. */
$cleared = ti_event( 'Cleared' );
$_POST = array( 'featured_image_id' => '0', 'image_url' => $TYPED );
kit_call( 'SFAF_Portal', 'save_manager_fields_from_post', $portal, array( new WP_User(), $cleared, $unlocked ) );
$_POST = array( 'featured_image_id' => '0', 'image_url' => '' );
kit_call( 'SFAF_Portal', 'save_manager_fields_from_post', $portal, array( new WP_User(), $cleared, $unlocked ) );

/* The chain and the editor's own answer. */
ti_is( sfaf_event_image_url( $typed ), $TYPED, 'a typed URL outside the folder wins over the series picture' );
ti_is( sfaf_event_has_own_image( $typed ), true, 'the editor says the event has its own picture' );
ti_is( sfaf_event_image_source( $typed ), 'event', 'the editor labels it the event\'s own' );
ti_is( (string) get_post_meta( $typed, '_uc_image_override', true ), '1', 'the save flags it as an override, so a series picture change skips it' );
ti_is( sfaf_event_image_url( $imported ), $SERIES, 'a value another writer put there still answers to the folder rule' );
ti_is( sfaf_event_has_own_image( $imported ), false, 'and the editor agrees it has no picture of its own' );
ti_is( sfaf_event_image_url( $later ), $SERIES, 'a typed URL later overwritten by another writer loses its exemption' );
ti_is( sfaf_event_image_url( $cleared ), $SERIES, 'emptying the box goes back to the series picture' );
ti_is( (string) get_post_meta( $cleared, SFAF_Portal::META_IMAGE_TYPED, true ), '', 'and clears the record of what was typed' );

/* The event page's picture. */
ti_has( sfaf_event_thumbnail( $typed, 'large' ), $TYPED, 'the event page draws the typed URL' );
ti_lacks( sfaf_event_thumbnail( $typed, 'large' ), $SERIES, 'the event page does not draw the series picture' );

/* The two cards. */
ti_has( sfaf_thumb_media( $typed ), $TYPED, 'the grid card draws the typed URL' );
ti_has( sfaf_list_card_media( $typed ), $TYPED, 'the list card draws the typed URL' );
ti_has( sfaf_list_card_media( $imported ), $SERIES, 'the list card draws the series picture for the imported value' );

/* The month grid: each tile carries the hover preview's picture. */
$sc = ( new ReflectionClass( 'SFAF_Shortcodes' ) )->newInstanceWithoutConstructor();
ob_start();
try {
    $month = $sc->render_month_grid( '2026-12', array() );
} catch ( Throwable $e ) {
    $month = 'THREW: ' . $e->getMessage();
}
$month = (string) $month . ob_get_clean();
ti_has( $month, 'data-uc-pv-title="Typed"', 'the month grid has the typed event\'s tile' );
if ( preg_match( '#data-uc-pv-title="Typed"\s+data-uc-pv-img="([^"]*)"#', $month, $m ) ) {
    ti_is( html_entity_decode( $m[1] ), $TYPED, 'the month tile\'s hover preview shows the typed URL' );
} else {
    ti_is( 'no preview attribute', $TYPED, 'the month tile\'s hover preview shows the typed URL' );
}
if ( preg_match( '#data-uc-pv-title="Imported"\s+data-uc-pv-img="([^"]*)"#', $month, $m ) ) {
    ti_is( html_entity_decode( $m[1] ), $SERIES, 'the imported value\'s hover preview shows the series picture' );
}

/* The pending row's image control is the editor's, and says the same. */
ob_start();
kit_call( 'SFAF_Portal', 'render_manager_control', $portal, array( 'image', kit_call( 'SFAF_Portal', 'manager_panel_context', $portal, array( new WP_User(), $typed, 'queue' ) ) ) );
$control = (string) ob_get_clean();
ti_has( $control, 'value="' . $TYPED . '"', 'the pending row\'s image box keeps the typed URL' );
ti_has( $control, 'src="' . $TYPED . '"', 'the pending row\'s preview shows the typed URL' );

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . ' of ' . $checks . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "A typed image URL: {$checks} checks. It wins over the series picture on the event page, both cards, the\n";
echo "month tile's hover preview and the pending row, and anything else in that key still answers to the folder rule.\n";
exit( 0 );
