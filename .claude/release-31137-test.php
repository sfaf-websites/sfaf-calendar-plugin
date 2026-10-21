<?php
/**
 * 3.113.7, THE EVERYACTION FETCH LOG ON THE PANEL.
 *
 *     php .claude/release-31137-test.php
 *
 * The real settings screen through wp-kit.php, with a stored fetch and its log
 * as SFAF_EveryAction writes them. What a fetch puts in the log is
 * everyaction-import-test.php's section 10, run against the model hub.
 *
 *   1  the Last fetch line adds the silent outcomes after created and updated,
 *      and says nothing extra when there are none
 *   2  the rows are under it, folded: UUID, title, Start_Time and End_Time as
 *      received, both times as read, and the outcome in words; what came from
 *      the tracker is escaped
 *   3  no log, no fold; the log is stored without autoload
 *
 * The embed is embed-identity-test.php's. PLANTs in plant-31137.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
$root = $GLOBALS['kit_root'];

function rt_panel() {
    $admin = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
    ob_start();
    $admin->render_settings_page();
    $page = (string) ob_get_clean();
    $a = strpos( $page, '<!-- EVERYACTION -->' );
    $b = strpos( $page, '<!-- PARDOT' );
    return ( false === $a || false === $b ) ? '' : substr( $page, $a, $b - $a );
}

$at = mktime( 16, 5, 0, 10, 21, 2026 );
update_option( SFAF_EveryAction::FETCH_OPTION, array( 'at' => $at, 'rows' => 6, 'new' => 1, 'updated' => 1, 'error' => '',
    'unchanged' => 1, 'refused' => 1, 'dismissed' => 0, 'trashed' => 1, 'deleted_skip' => 0, 'duplicate' => 1, 'invalid' => 0, 'failed' => 0 ) );
$start = mktime( 10, 0, 0, 10, 9, 2027 );
update_option( SFAF_EveryAction::LOG_OPTION, array( 'at' => $at, 'error' => '', 'rows' => array(
    array( 'uuid' => '750059398', 'title' => 'Coffee <script>alert(1)</script> social', 'start_raw' => '10/09/2027 05:00 PM +0000', 'end_raw' => '10/09/2027 07:00 PM +0000', 'start_local' => $start, 'end_local' => $start + 7200, 'outcome' => 'added', 'why' => '', 'post_id' => 501 ),
    array( 'uuid' => '750059399', 'title' => 'Walk', 'start_raw' => '10/16/2027 05:00 PM +0000', 'end_raw' => '', 'start_local' => $start + 604800, 'end_local' => 0, 'outcome' => 'updated', 'why' => 'Title, Date', 'post_id' => 502 ),
    array( 'uuid' => '750059400', 'title' => 'Picnic', 'start_raw' => '10/23/2027 05:00 PM +0000', 'end_raw' => '10/23/2027 07:00 PM +0000', 'start_local' => $start, 'end_local' => $start, 'outcome' => 'unchanged', 'why' => '', 'post_id' => 503 ),
    array( 'uuid' => '999000101', 'title' => 'Yesterday', 'start_raw' => '10/20/2026 05:00 PM +0000', 'end_raw' => '10/20/2026 07:00 PM +0000', 'start_local' => $start, 'end_local' => $start, 'outcome' => 'refused', 'why' => 'its end time has passed', 'post_id' => 0 ),
    array( 'uuid' => '750059401', 'title' => 'Bingo', 'start_raw' => '10/30/2027 05:00 PM +0000', 'end_raw' => '10/30/2027 07:00 PM +0000', 'start_local' => $start, 'end_local' => $start, 'outcome' => 'trashed', 'why' => '', 'post_id' => 504 ),
    array( 'uuid' => '750059399', 'title' => 'Walk', 'start_raw' => '2027-10-16T17:00:00Z', 'end_raw' => 'soon', 'start_local' => 0, 'end_local' => 0, 'outcome' => 'duplicate', 'why' => 'sent twice by the tracker', 'post_id' => 0 ),
) ) );

$p = rt_panel();
rt( 'the panel is on the settings page', '' !== $p, true );

/* ======================================================================== 1 */
rt( '1: the Last fetch line counts the silent outcomes', (bool) preg_match( '#<p class="uc-everyaction-last-fetch">[^<]*: 6 rows read, 1 created, 1 updated, 1 unchanged, 1 not imported, 1 in the trash here, 1 listed twice\.</p>#', $p ), true );
rt( '1: and none it did not have', false === strpos( $p, 'dismissed here' ) && false === strpos( $p, 'deleted here for good' ), true );
rt( '1: nothing silent, nothing added', preg_replace( '/^.*?: /', '', SFAF_EveryAction::fetch_line( array( 'at' => $at, 'rows' => 2, 'new' => 2, 'updated' => 0, 'error' => '' ) ) ), '2 rows read, 2 created, 0 updated.' );
rt( '1: a failed fetch says so', 0 === strpos( SFAF_EveryAction::fetch_line( array( 'at' => $at, 'rows' => 0, 'new' => 0, 'updated' => 0, 'error' => 'Login failed.' ) ), 'Failed ' ), true );
rt( '1: never fetched', SFAF_EveryAction::fetch_line( array() ), 'Not fetched yet.' );

/* ======================================================================== 2 */
rt( '2: folded, with the count', (bool) preg_match( '#<details class="uc-everyaction-log">\s*<summary>Rows in the last fetch \(6\)</summary>#', $p ), true );
rt( '2: the columns', (bool) preg_match( '#<th scope="col">UUID</th><th scope="col">Title</th><th scope="col">Outcome</th><th scope="col">Start_Time as received</th><th scope="col">End_Time as received</th><th scope="col">Start, read as</th><th scope="col">End, read as</th>#', $p ), true );
rt( '2: a row per logged row', preg_match_all( '#<tr data-uc-ea-outcome="#', $p ), 6 );
rt( '2: in order, by outcome', preg_match_all( '#<tr data-uc-ea-outcome="([a-z_]+)"#', $p, $m ) ? $m[1] : array(), array( 'added', 'updated', 'unchanged', 'refused', 'trashed', 'duplicate' ) );
rt( '2: the times as received', false !== strpos( $p, '<td><code>10/09/2027 05:00 PM +0000</code></td>' ), true );
rt( '2: and as read', false !== strpos( $p, '<td>' . esc_html( sfaf_ap_datetime( $start ) ) . '</td>' ), true );
rt( '2: no End_Time is none', false !== strpos( $p, '<td><code></code></td>' ) && false !== strpos( $p, '<td>none</td>' ), true );
rt( '2: a time not understood says so', 2 === substr_count( $p, '<td>not understood</td>' ), true );
foreach ( array( 'Added', 'Updated: Title, Date', 'Unchanged', 'Not imported: its end time has passed', 'In the trash here, left alone', 'Listed twice; the first was used' ) as $w ) {
    rt( "2: the outcome reads \"$w\"", false !== strpos( $p, '<td>' . esc_html( $w ) . '</td>' ), true );
}
rt( '2: a title from the tracker is escaped', false === strpos( $p, '<script>alert(1)</script>' ) && false !== strpos( $p, 'Coffee &lt;script&gt;' ), true );
foreach ( array( 'dismissed' => 'Dismissed here, left alone', 'deleted_skip' => 'Deleted here for good, not made again', 'failed' => 'Could not be saved: x' ) as $o => $w ) {
    rt( "2: $o reads \"$w\"", SFAF_EveryAction::outcome_label( array( 'outcome' => $o, 'why' => 'failed' === $o ? 'x' : '', 'uuid' => '1' ) ), $w );
}
rt( '2: a status other than dismissed is named', SFAF_EveryAction::outcome_label( array( 'outcome' => 'dismissed', 'why' => 'private', 'uuid' => '1' ) ), 'Left alone: it is private here' );

/* ======================================================================== 3 */
delete_option( SFAF_EveryAction::LOG_OPTION );
rt( '3: no log, no fold', false === strpos( rt_panel(), 'uc-everyaction-log' ), true );
$src = (string) file_get_contents( $root . '/includes/class-sfaf-everyaction.php' );
rt( '3: the log is stored without autoload', false !== strpos( $src, "            'rows'  => \$rows,\n        ), false );" ), true );
rt( '3: nothing for the embed: the stylesheet it loads is untouched', false === strpos( (string) file_get_contents( $root . '/public/css/calendar.css' ), 'everyaction' ), true );

echo "\nRelease 3.113.7\n" . str_repeat( '=', 72 ) . "\nran $ran assertions.\n";
if ( $fails ) {
    echo count( $fails ) . " failure(s):\n";
    foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
    exit( 1 );
}
echo "The EveryAction panel's Last fetch line counts the silent outcomes, and the last fetch is under it row by row.\n";
