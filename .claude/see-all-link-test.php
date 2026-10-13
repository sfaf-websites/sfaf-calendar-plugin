<?php
/**
 * "SEE ALL EVENTS" GOES TO THE CALENDAR HOME (3.110.4), AND THE REST OF 3.110.4.
 *
 *     php .claude/see-all-link-test.php
 *
 * Runs the real renderers through wp-kit.php. The sidebar's link read the
 * post type archive whatever Calendar home URL said, and carried the taxonomy
 * names, which no calendar block reads. Asserted on the rendered href.
 *
 *   PLANT S.1  SFAF_Embed::calendar_url() skips the setting
 *   PLANT S.2  the sidebar carries uc_organizer to the calendar home, not uc_org
 *   PLANT S.3  a rejected event's submitted pictures survive its deletion
 *   PLANT S.4  an approved event's filed picture is deleted with it
 */

$GLOBALS['st_deleted'] = array();
function wp_delete_attachment( $id, $force = false ) { $GLOBALS['st_deleted'][] = (int) $id; return true; }

require __DIR__ . '/wp-kit.php';

$fails = array();
function st_check( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = $label . ': got ' . json_encode( $got ) . ', expected ' . json_encode( $want );
    }
}
function st_href( $html ) {
    return preg_match( '/class="uc-sidebar-all" href="([^"]*)"/', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
}

$sc   = new SFAF_Shortcodes();
$home = 'https://www.sfaf.org/calendar/';

/* ---- The setting is read. ---- */
$GLOBALS['kit_options']['uc_settings'] = array( 'calendar_home_url' => $home );
st_check( 'PLANT S.1: calendar_url() with the setting', SFAF_Embed::calendar_url(), $home );

st_check( 'S: the sidebar with no filter', st_href( $sc->render_sidebar( array(), 5 ) ), $home );
st_check( 'PLANT S.2: an organizer sidebar', st_href( $sc->render_sidebar( array( 'organizer' => 'alpha' ), 5 ) ), $home . '?uc_org=alpha' );
st_check( 'S: a category sidebar', st_href( $sc->render_sidebar( array( 'category' => 'beta' ), 5 ) ), $home . '?uc_cat=beta' );
st_check( 'S: a series sidebar carries the slug as uc_group', st_href( $sc->render_sidebar( array( 'series' => 11 ), 5 ) ), $home . '?uc_group=alpha' );
st_check( 'S: a venue has no public key and is not carried', st_href( $sc->render_sidebar( array( 'venue' => 'castro' ), 5 ) ), $home );
st_check( 'S: the combined mode\'s sidebar, bound to a month', st_href( $sc->render_sidebar( array( 'organizer' => 'alpha' ), 5, null, '2026-11' ) ), $home . '?uc_org=alpha' );

/* ---- No setting: the archive, with the query vars it reads. ---- */
$GLOBALS['kit_options']['uc_settings'] = array();
st_check( 'S: calendar_url() with no setting', SFAF_Embed::calendar_url(), 'https://resources.sfaf.org/events/' );
st_check( 'S: an organizer sidebar with no setting', st_href( $sc->render_sidebar( array( 'organizer' => 'alpha' ), 5 ) ), 'https://resources.sfaf.org/events/?uc_organizer=alpha' );

/* ---- A rejected submission's pictures go with it. ---- */
function st_picture( $file ) {
    $id = kit_write_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
    update_post_meta( $id, '_wp_attached_file', $file );
    return $id;
}
$sub   = st_picture( 'calendar-submissions/submission-1.jpg' );
$extra = st_picture( 'calendar-submissions/submission-2.jpg' );
$filed = st_picture( 'calendar/strut.jpg' );

$rejected = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'trash' ) );
update_post_meta( $rejected, SFAF_Submit::META_IMAGE, $sub );
update_post_meta( $rejected, SFAF_Submit::META_IMAGE_EXTRA, array( $extra, $filed ) );
update_post_meta( $rejected, SFAF_Media::META_REJECTED, '2026-10-13 10:00:00' );
SFAF_Media::delete_rejected_pictures( $rejected );
st_check( 'PLANT S.3: a rejected event\'s submitted pictures, and nothing filed', $GLOBALS['st_deleted'], array( $extra, $sub ) );

$GLOBALS['st_deleted'] = array();
$approved = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish' ) );
update_post_meta( $approved, SFAF_Submit::META_IMAGE, st_picture( 'calendar-submissions/submission-3.jpg' ) );
SFAF_Media::delete_rejected_pictures( $approved );
st_check( 'PLANT S.4: an event nobody rejected keeps its pictures', $GLOBALS['st_deleted'], array() );

$src = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' );
$at  = strpos( $src, "case 'reject_event':" );
$end = strpos( $src, 'break;', $at );
$arm = substr( $src, $at, $end - $at );
st_check( 'S: Reject marks the event before it trashes it',
    false !== strpos( $arm, 'SFAF_Media::META_REJECTED' ) && strpos( $arm, 'SFAF_Media::META_REJECTED' ) < strpos( $arm, 'wp_trash_post( $event_id )' ), true );

/* ---- The pending queue's note, and the forms' size line. ---- */
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
ob_start();
kit_call( 'SFAF_Portal', 'render_pending_bulk', $portal, array( array( 1 ) ) );
$bulk = ob_get_clean();
st_check( 'S: the queue says pictures are approved one at a time',
    false !== strpos( $bulk, 'Submissions with a picture are approved one at a time, from their own row.' ), true );

ob_start();
SFAF_Submissions::image_field();
$field = ob_get_clean();
st_check( 'S: the upload box states the rule before a file is chosen',
    (bool) preg_match( '/<input type="file"[^>]*>\s*<span class="uc-hint">\s*Pictures must be 1200 by 675 pixels and under 500KB\./', $field ), true );
foreach ( array( 'class-sfaf-request.php', 'class-sfaf-submit.php' ) as $f ) {
    st_check( 'S: ' . $f . ' no longer says somebody will size it',
        false === strpos( file_get_contents( $GLOBALS['kit_root'] . '/includes/' . $f ), 'size it for the calendar' ), true );
}

if ( $fails ) {
    echo "FAIL\n  " . implode( "\n  ", $fails ) . "\n";
    exit( 1 );
}
echo "OK: See all events goes to the calendar home with uc_cat, uc_org or uc_group; rejected pictures go with the event; the queue note and the upload rule are on screen.\n";
