<?php
/**
 * SCHEDULE FOR A LATER DATE (3.110.0).
 *
 *     php .claude/scheduled-publish-test.php
 *
 * The real save_event_from_post() on wp-kit.php, posting what the action bar
 * posts beside Publish, and the real public builders over the result.
 *
 *   1  Publish with the tick and a later time stores the event as 'future'
 *      with that date: WordPress's cron publishes it then
 *   2  the publish rule runs at scheduling time: an event missing what a
 *      publish needs is held as a draft, not scheduled
 *   3  a time that has passed schedules nothing and says so
 *   4  a scheduled event saved with the tick on keeps its schedule; saved with
 *      the tick cleared it is a draft; published without the tick it goes live
 *      now, dated now, so WordPress does not schedule it again
 *   5  the REST events route, the card list and the month grid never return a
 *      scheduled event, and do return a published one
 */

require __DIR__ . '/wp-kit.php';
$GLOBALS['kit_organizers'] = true;

$fails = array();
function sp( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$GLOBALS['sp_seen'] = array();
$GLOBALS['kit_query'] = function ( $a ) {
    $out    = array();
    $type   = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( ! in_array( $p->post_type, (array) $type, true ) ) { continue; }
        if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
        $GLOBALS['sp_seen'][ $id ] = true;
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
};

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();
$later  = gmdate( 'Y-m-d', time() + 3 * DAY_IN_SECONDS );
$past   = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

function sp_save( $event_id, $mode, $extra ) {
    $_GET  = array();
    $_POST = array_merge( array(
        'event_id' => $event_id, 'save_mode' => $mode, 'title' => 'Garden party', 'date' => '2026-12-05',
        'start_time' => '14:00', 'end_time' => '16:00', 'uc_organizer_present' => '1', 'organizer' => array( '11' ),
        'uc_category_present' => '1', 'category' => array( '11' ), 'description' => '<p>Bring a hat.</p>',
        'uc_online_present' => '1', 'uc_online' => '0', 'location_mode' => 'custom', 'location_street' => '1035 Market St', 'location_city' => 'San Francisco',
        'schedule_present' => '1',
    ), $extra );
    $GLOBALS['kit_redirect_throws'] = true;
    $r = kit_call( 'SFAF_Portal', 'save_event_from_post', $GLOBALS['portal'], array( $GLOBALS['user'] ) );
    $_POST = array();
    return $r;
}
$on = function ( $date, $time = '10:00' ) { return array( 'schedule_on' => '1', 'schedule_date' => $date, 'schedule_time' => $time ); };

/* 1 */
$r  = sp_save( 0, 'publish', $on( $later ) );
$id = (int) $r['id'];
sp( 'future' === get_post_status( $id ) && "$later 10:00:00" === get_post( $id )->post_date && 'scheduled' === $r['msg'],
    'G.1: Publish with the tick did not store a scheduled event at that time: ' . json_encode( array( get_post_status( $id ), get_post( $id )->post_date, $r['msg'] ) ) );

/* 2 */
$r2 = sp_save( 0, 'publish', array_merge( $on( $later ), array( 'category' => array() ) ) );
sp( 'future' !== get_post_status( (int) $r2['id'] ) && 'publish_needs' === $r2['msg'],
    'G.1: an event the publish rule holds was scheduled anyway: ' . json_encode( array( get_post_status( (int) $r2['id'] ), $r2['msg'] ) ) );

/* 3 */
$r3 = sp_save( 0, 'publish', $on( $past ) );
sp( 'draft' === get_post_status( (int) $r3['id'] ) && 'schedule_time_past' === $r3['msg'],
    'G.1: a time that has passed did something other than say so: ' . json_encode( array( get_post_status( (int) $r3['id'] ), $r3['msg'] ) ) );

/* 3, the words: the flash map is one array literal, so a key written twice
   keeps only its last line. 'schedule_past' was the series screen's before it
   was this one's, and the past-time message never showed. */
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-portal.php' );
$fs  = strpos( $src, '    private function flash() {' );
$map = substr( $src, $fs, strpos( $src, "
        );", $fs ) - $fs );
preg_match_all( "/^\s*'([a-z_]+)'\s*=>/m", $map, $keys );
$dups = array_keys( array_filter( array_count_values( $keys[1] ), function ( $n ) { return $n > 1; } ) );
sp( count( $keys[1] ) > 20 && array() === $dups, 'G.1: the flash map names a key twice, so the first line never shows: ' . json_encode( $dups ) );
sp( false !== strpos( $map, "'schedule_time_past' => 'That date and time has passed" ), 'G.1: the past-time message is not the one the flash map shows' );

/* 4 */
$r4 = sp_save( $id, 'keep', $on( $later ) );
sp( 'future' === get_post_status( $id ) && "$later 10:00:00" === get_post( $id )->post_date, 'G.2: saving a scheduled event with the tick on lost its schedule' );
$r5 = sp_save( $id, 'keep', $on( $later, '15:30' ) );
sp( 'future' === get_post_status( $id ) && "$later 15:30:00" === get_post( $id )->post_date, 'G.2: a new time on a scheduled event was not kept' );
$id2 = (int) sp_save( 0, 'publish', $on( $later ) )['id'];
sp_save( $id2, 'keep', array() );
sp( 'draft' === get_post_status( $id2 ), 'G.2: a scheduled event saved with the tick cleared is ' . get_post_status( $id2 ) . ', not a draft' );
$id3 = (int) sp_save( 0, 'publish', $on( $later ) )['id'];
sp_save( $id3, 'publish', array() );
sp( 'publish' === get_post_status( $id3 ) && strtotime( get_post( $id3 )->post_date ) <= time() + 5,
    'G.2: a scheduled event published now kept a future date, which WordPress would schedule again: ' . get_post( $id3 )->post_date );

/* 5 */
$GLOBALS['sp_seen'] = array();
class SP_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } }
if ( ! class_exists( 'WP_REST_Response' ) ) { class WP_REST_Response { public $data; function __construct( $d = null ) { $this->data = $d; } } }
$sc = ( new ReflectionClass( 'SFAF_Shortcodes' ) )->newInstanceWithoutConstructor();
$ran = 0;
try { sfaf_rest_get_events( new SP_Request( array( 'per_page' => 50, 'page' => 1 ) ) ); $ran++; } catch ( Throwable $t ) { sp( false, 'the REST events route threw: ' . $t->getMessage() ); }
try { new WP_Query( kit_call( 'SFAF_Shortcodes', 'build_query_args', $sc, array( 50, 1, array() ) ) ); $ran++; } catch ( Throwable $t ) { sp( false, 'the card list threw: ' . $t->getMessage() ); }
try { kit_call( 'SFAF_Shortcodes', 'month_grid_data', $sc, array( '2026-12', array() ) ); $ran++; } catch ( Throwable $t ) { sp( false, 'the month grid threw: ' . $t->getMessage() ); }
sp( 3 === $ran, "only $ran of the three public builders ran" );
sp( isset( $GLOBALS['sp_seen'][ $id3 ] ), 'the published event is missing from the public builders, so their silence about the scheduled one proves nothing' );
sp( ! isset( $GLOBALS['sp_seen'][ $id ] ), 'PLANT G.2: a scheduled event appeared in a public list, feed or embed payload before it went live' );

if ( $fails ) {
    echo 'SCHEDULED PUBLISH: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "scheduled publish: Publish with the tick stores the event as scheduled at that time, the publish rule holds it first, a past time is said; the schedule is kept, re-timed, cleared to a draft, or published now and dated now; the REST events route, the card list and the month grid never return it before it goes live.\n";
