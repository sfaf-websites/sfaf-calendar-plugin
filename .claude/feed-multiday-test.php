<?php
/**
 * THE /events FEED CARRIES A MULTI-DAY EVENT'S WHOLE RANGE (3.112.1, 2b).
 *
 *     php .claude/feed-multiday-test.php
 *
 * The key-gated feed's rows come from SFAF_Sync::event_to_array(), which until
 * 3.112.1 sent a multi-day event as its first day only. Three keys are ADDED:
 * multi_day, last_date and days. Nothing is removed or renamed, so a consumer
 * written before them reads exactly what it read before; this fails if any
 * key of the 3.112.0 row is missing or `date` stops being the first day.
 *
 * PLANT 2b in plant-31121.php sends the first day as last_date.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
function fm( $label, $got, $want ) { global $fails; if ( $got !== $want ) { $fails[] = $label . ': got ' . json_encode( $got ) . ', wanted ' . json_encode( $want ); } }

/* The keys a 3.112.0 row carried, in that release's order. */
$before = array( 'id', 'source_id', 'source_site', 'title', 'slug', 'content', 'excerpt', 'status', 'date', 'start_time', 'end_time',
    'location', 'recurrence', 'capacity', 'rsvp_enabled', 'gofundme_url', 'gofundme_goal', 'series_id', 'series_parent', 'series_name',
    'series_image', 'recurrence_group', 'show', 'faq', 'categories', 'category_colors', 'organizer', 'venue', 'terms', 'image', 'permalink' );

$multi = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Three-day conference', 'post_author' => 1 ) );
foreach ( array( '_uc_event_date' => '2026-11-12', '_uc_start_time' => '09:00', '_uc_end_time' => '17:00', '_uc_multi_day' => '1', '_uc_last_date' => '2026-11-14' ) as $k => $v ) { update_post_meta( $multi, $k, $v ); }
$one = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'One evening', 'post_author' => 1 ) );
foreach ( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30' ) as $k => $v ) { update_post_meta( $one, $k, $v ); }
/* The tick with no later End date is not a multi-day event. */
$half = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Ticked, no end', 'post_author' => 1 ) );
foreach ( array( '_uc_event_date' => '2026-11-21', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_multi_day' => '1', '_uc_last_date' => '2026-11-21' ) as $k => $v ) { update_post_meta( $half, $k, $v ); }

$sync = new SFAF_Sync();
$rows = array( 'multi' => $sync->event_to_array( $multi ), 'one' => $sync->event_to_array( $one ), 'half' => $sync->event_to_array( $half ) );

foreach ( $rows as $name => $row ) {
    fm( "$name: every 3.112.0 key is still there", array_values( array_diff( $before, array_keys( $row ) ) ), array() );
}
fm( 'multi: date is still the first day', $rows['multi']['date'], '2026-11-12' );
fm( 'multi: multi_day', $rows['multi']['multi_day'], true );
fm( 'multi: last_date', $rows['multi']['last_date'], '2026-11-14' );
fm( 'multi: days', $rows['multi']['days'], array( '2026-11-12', '2026-11-13', '2026-11-14' ) );
fm( 'multi: times unchanged', array( $rows['multi']['start_time'], $rows['multi']['end_time'] ), array( '09:00', '17:00' ) );
fm( 'one: multi_day', $rows['one']['multi_day'], false );
fm( 'one: last_date is its date', $rows['one']['last_date'], '2026-11-20' );
fm( 'one: days', $rows['one']['days'], array( '2026-11-20' ) );
fm( 'ticked with no later End date: not multi-day', array( $rows['half']['multi_day'], $rows['half']['last_date'], $rows['half']['days'] ), array( false, '2026-11-21', array( '2026-11-21' ) ) );

/* The feed hands back event_to_array()'s rows unchanged. */
$boot = file_get_contents( dirname( __DIR__ ) . '/sfaf-calendar.php' );
fm( 'the /events feed still builds its rows with event_to_array()', (bool) preg_match( '#function sfaf_rest_get_events[\s\S]{0,2600}?\$events\[\] = \$sync->event_to_array\( \$post->ID \);#', $boot ), true );

echo "\nThe /events feed and a multi-day event\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "a row carries multi_day, last_date and days, and every key it carried in 3.112.0.\n";
exit( 0 );
