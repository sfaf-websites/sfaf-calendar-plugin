<?php
/**
 * 3.110.3: WHERE PICTURES LIVE, THE UPLOAD RULE, THE ONE-OFF UPLOAD'S EMAIL,
 * A SUBMITTED PICTURE AT APPROVAL, AND SET TEAM.
 *
 *     php .claude/pictures-31103-test.php
 *
 *   B    every event picture is exactly 1200 by 675, 500KB or less, JPEG, PNG
 *        or WebP; anything else gets the one sentence. picture_rule_error() is
 *        the rule, inspect() asks it, and every size line says it.
 *        PLANT B: a 1201-pixel-wide file accepted
 *   C.2  a picture uploaded for an event sends websites@ one email, on the
 *        first save that carries it; a second save sends nothing
 *        PLANT C.2: a second email on a second save
 *   A, D the editor's picker offers the series' pictures and then Other
 *        images, and never a submitted file; the approval dialog asks where
 *        a submitted picture goes; bulk Publish holds one back
 *        PLANT D: a submitted picture reaching the picker before approval
 *   E    Set team writes to the ticked events this person may assign on, and
 *        leaves the rest alone
 *        PLANT E: a bulk Set team touching an event the person may not edit
 *
 * The browser half, at 1280px, 430px and 390px, is release-31103-live.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $body, $headers = '', $files = array() ) {
        $GLOBALS['pt_mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body, 'files' => $files );
        return true;
    }
}
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'pending' => 1, 'draft' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
if ( ! function_exists( 'get_post_mime_type' ) ) { function get_post_mime_type( $id ) { return 'image/jpeg'; } }
if ( ! function_exists( 'wp_delete_object_term_relationships' ) ) { function wp_delete_object_term_relationships( $id, $tax ) { unset( $GLOBALS['kit_terms'][ (int) $id ][ $tax ] ); } }

$fails = array();
function pt( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function pt_capture( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}

/* ---- A query that answers the meta and tax questions the plugin asks. ---- */
function pt_meta_ok( $id, $clause ) {
    if ( isset( $clause['relation'] ) || ( is_array( $clause ) && isset( $clause[0] ) ) ) {
        $or = isset( $clause['relation'] ) && 'OR' === strtoupper( $clause['relation'] );
        $any = false; $all = true;
        foreach ( $clause as $k => $c ) {
            if ( 'relation' === $k ) { continue; }
            $r = pt_meta_ok( $id, $c ); $any = $any || $r; $all = $all && $r;
        }
        return $or ? $any : $all;
    }
    $key  = $clause['key'];
    $has  = isset( $GLOBALS['kit_meta'][ (int) $id ][ $key ] );
    $val  = $has ? $GLOBALS['kit_meta'][ (int) $id ][ $key ] : null;
    $cmp  = isset( $clause['compare'] ) ? strtoupper( $clause['compare'] ) : '=';
    $want = isset( $clause['value'] ) ? $clause['value'] : null;
    $str  = is_array( $val ) ? serialize( $val ) : (string) $val;
    switch ( $cmp ) {
        case 'EXISTS':     return $has;
        case 'NOT EXISTS': return ! $has;
        case 'REGEXP':     return $has && (bool) preg_match( '#' . $want . '#', $str );
        case 'LIKE':       return $has && false !== strpos( $str, (string) $want );
        case 'IN':         return $has && in_array( $str, array_map( 'strval', (array) $want ), true );
        case '>=':         return $has && $str >= (string) $want;
        default:           return $has && $str === (string) $want;
    }
}
function pt_query( $a ) {
    $out  = array();
    $type = isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event';
    $stat = isset( $a['post_status'] ) ? (array) $a['post_status'] : array();
    $ids  = array_keys( $GLOBALS['kit_posts'] );
    rsort( $ids ); // newest first: a higher id is a later post here
    foreach ( $ids as $id ) {
        $p = $GLOBALS['kit_posts'][ $id ];
        if ( $type !== $p->post_type ) { continue; }
        if ( $stat && ! in_array( 'any', $stat, true ) && ! in_array( $p->post_status, $stat, true ) ) { continue; }
        if ( ! empty( $a['meta_query'] ) && ! pt_meta_ok( $id, $a['meta_query'] ) ) { continue; }
        if ( ! empty( $a['tax_query'] ) ) {
            $ok = true;
            foreach ( $a['tax_query'] as $k => $t ) {
                if ( 'relation' === $k || ! is_array( $t ) || ! isset( $t['taxonomy'] ) ) { continue; }
                $on = isset( $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] : array();
                if ( isset( $t['operator'] ) && 'NOT EXISTS' === $t['operator'] ) { $ok = $ok && ! $on; }
                else { $ok = $ok && (bool) array_intersect( array_map( 'intval', (array) $t['terms'] ), $on ); }
            }
            if ( ! $ok ) { continue; }
        }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
    return $per > 0 ? array_slice( $out, 0, $per ) : $out;
}
$GLOBALS['kit_query']     = 'pt_query';
$GLOBALS['kit_get_posts'] = 'pt_query';
$GLOBALS['kit_thumbs']    = true;

function pt_picture( $id, $file, $series = 0 ) {
    kit_write_post( array( 'ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => '' ) );
    update_post_meta( $id, '_wp_attached_file', $file );
    $GLOBALS['kit_images'][ $id ] = 'https://resources.sfaf.org/wp-content/uploads/' . $file;
    if ( $series ) { $GLOBALS['kit_terms'][ $id ][ SFAF_Media::TAXONOMY ] = array( $series ); }
}

/* =========================================================================
 * B. THE UPLOAD RULE
 * ====================================================================== */
pt( 'Pictures must be 1200 by 675 pixels and under 500KB.' === SFAF_Uploads::RULE_MESSAGE, 'B: the one sentence is not the brief\'s' );
$cases = array(
    array( IMAGETYPE_JPEG, 500000, 1200, 675, true,  'a 1200 by 675 JPEG under 500KB' ),
    array( IMAGETYPE_PNG,  512000, 1200, 675, true,  'a PNG of exactly 500KB' ),
    array( IMAGETYPE_WEBP, 1000,   1200, 675, true,  'a WebP' ),
    array( IMAGETYPE_JPEG, 500000, 1201, 675, false, 'PLANT B: a 1201-pixel-wide file' ),
    array( IMAGETYPE_JPEG, 500000, 1199, 675, false, 'a 1199-pixel-wide file' ),
    array( IMAGETYPE_JPEG, 500000, 1200, 676, false, 'a 676-pixel-tall file' ),
    array( IMAGETYPE_JPEG, 512001, 1200, 675, false, 'a file one byte over 500KB' ),
    array( IMAGETYPE_GIF,  1000,   1200, 675, false, 'a GIF' ),
    array( 0,              1000,   1200, 675, false, 'a file no reader could name' ),
);
foreach ( $cases as $c ) {
    $said = SFAF_Uploads::picture_rule_error( $c[0], $c[1], $c[2], $c[3] );
    pt( $c[4] ? '' === $said : SFAF_Uploads::RULE_MESSAGE === $said,
        ( $c[4] ? 'B: refused ' : '' ) . $c[5] . ( $c[4] ? '' : ' was accepted' ) . ', said ' . json_encode( $said ) );
}
$up = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-uploads.php' );
$inspect = substr( $up, strpos( $up, 'public static function inspect(' ) );
$inspect = substr( $inspect, 0, strpos( $inspect, 'public static function store(' ) );
pt( false !== strpos( $inspect, 'self::picture_rule_error(' ), 'B: inspect() does not ask picture_rule_error()' );
pt( (bool) preg_match( "/\\\$picture = \\( 'description' !== \\\$rule \\);/", $inspect ), 'B: the picture rule is not the default of inspect()' );
/* Every caller of inspect() is the picture rule but the description one. */
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $f ) {
    $src = file_get_contents( $f );
    // A call, not prose: its first argument is a string or a variable.
    if ( ! preg_match_all( '/SFAF_Uploads::inspect\(\s*([\'$][^;]*)\);/', $src, $m ) ) { continue; }
    foreach ( $m[1] as $args ) {
        $desc = false !== strpos( $args, "'description'" );
        pt( $desc === ( 'class-sfaf-desc-images.php' === basename( $f ) ), 'B: ' . basename( $f ) . ' calls inspect(' . trim( $args ) . ')' );
    }
}

/* The size lines: both public forms' fields, the Images screen, the editor. */
$field  = pt_capture( function () { SFAF_Submissions::image_field(); } );
$extras = pt_capture( function () { SFAF_Submissions::extra_images_field(); } );
pt( false !== strpos( $field, SFAF_Uploads::RULE_MESSAGE ) && false !== strpos( $field, 'accept="image/jpeg,image/png,image/webp"' ), 'B: the forms\' picture field does not say the rule or accepts GIF: ' . $field );
pt( false !== strpos( $extras, SFAF_Uploads::RULE_MESSAGE ), 'B: the forms\' other pictures do not say the rule' );

/* =========================================================================
 * A and D. THE PICKER, THE IMAGES SCREEN, THE APPROVAL DIALOG
 * ====================================================================== */
pt_picture( 801, 'calendar/alpha-clinic.jpg', 11 );
pt_picture( 802, 'calendar/beta-walk.jpg', 12 );
pt_picture( 803, 'calendar-other/one-off-flyer.jpg' );
pt_picture( 804, 'calendar-submissions/submission-1.jpg' );

$pending = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'pending', 'post_title' => 'Submitted bake sale', 'post_author' => 0 ) );
update_post_meta( $pending, SFAF_Submit::META_IMAGE, 804 );
update_post_meta( $pending, '_uc_event_date', date( 'Y-m-d', strtotime( '+9 days' ) ) );
$GLOBALS['kit_terms'][ $pending ]['uc_series'] = array( 11 );

$live = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Upcoming clinic', 'post_author' => 1 ) );
update_post_meta( $live, '_uc_event_date', date( 'Y-m-d', strtotime( '+3 days' ) ) );
update_post_meta( $live, '_thumbnail_id', 803 );
$past = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Last year', 'post_author' => 1 ) );
update_post_meta( $past, '_uc_event_date', '2025-01-10' );
update_post_meta( $past, '_thumbnail_id', 801 );
SFAF_Media::forget_active();

$picker = pt_capture( function () {
    SFAF_Media::picker( array( 'name' => 'featured_image_id', 'series' => 11, 'series_fixed' => 11, 'series_follow' => true, 'groups' => true ) );
} );
pt( false === strpos( $picker, 'value="804"' ), 'PLANT D: a submitted picture is in the editor\'s picker' );
pt( false !== strpos( $picker, 'value="801"' ) && false !== strpos( $picker, 'value="802"' ) && false !== strpos( $picker, 'value="803"' ),
    'A: the picker does not carry the series pictures and Other images: ' . $picker );
$at = function ( $needle ) use ( $picker ) { $p = strpos( $picker, $needle ); return false === $p ? -1 : $p; };
pt( $at( 'data-uc-image-group-head="series"' ) < $at( 'value="801"' ) && $at( 'value="801"' ) < $at( 'data-uc-image-group-head="other"' )
    && $at( 'data-uc-image-group-head="other"' ) < $at( 'value="803"' ), 'A: the series group does not come first and Other images second' );
pt( (bool) preg_match( '/data-uc-image-active="1"[^>]*>\s*<input type="radio" name="featured_image_id" value="803"/', $picker ), 'A: an Other image an upcoming published event uses is not marked active' );
pt( (bool) preg_match( '/data-uc-image-active="0"[^>]*data-uc-image-series=" 11 ">\s*<input type="radio" name="featured_image_id" value="801"/', $picker ), 'A: a picture only a past event uses is marked active' );
pt( false !== strpos( $picker, 'data-uc-filter-text="one-off-flyer.jpg"' ), 'A: the search does not match on the file name' );
pt( false === strpos( $picker, 'data-uc-image-show-all' ), 'A: All calendar images is still offered' );
pt( false !== strpos( $picker, 'Show active images only' ), 'A: no active-only tick' );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$admin  = new WP_User();

/* The Images screen, every place and its filters. */
foreach ( array( '' => array( 801, 802, 803, 804 ), 'series' => array( 801, 802 ), 'other' => array( 803 ), 'submitted' => array( 804 ) ) as $tag => $want ) {
    $h = pt_capture( function () use ( $portal, $admin, $tag ) { $_GET = array( 'tag' => $tag ); kit_call( 'SFAF_Portal', 'render_media', $portal, array( $admin ) ); } );
    preg_match_all( '/data-uc-media-place="(\w+)"[^>]*>\s*(?:<[^>]*>\s*)*?<img class="uc-media-thumb" src="[^"]*\/([^\/"]+)"/', $h, $m );
    $got = array();
    foreach ( array( 801, 802, 803, 804 ) as $id ) {
        if ( false !== strpos( $h, basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ) ) ) { $got[] = $id; }
    }
    pt( $want === $got, 'A.3: Images, ' . ( '' === $tag ? 'All images' : $tag ) . ' shows ' . json_encode( $got ) . ' rather than ' . json_encode( $want ) );
}
$sub = pt_capture( function () use ( $portal, $admin ) { $_GET = array( 'tag' => 'submitted' ); kit_call( 'SFAF_Portal', 'render_media', $portal, array( $admin ) ); } );
pt( false !== strpos( $sub, 'Submitted bake sale</a>, waiting for approval.' ), 'A.3: a submitted picture does not name the pending event it came with' );
$act = pt_capture( function () use ( $portal, $admin ) { $_GET = array( 'active' => '1' ); kit_call( 'SFAF_Portal', 'render_media', $portal, array( $admin ) ); } );
pt( false !== strpos( $act, 'one-off-flyer.jpg' ) && false === strpos( $act, 'alpha-clinic.jpg' ), 'A.3: Show active images only on the Images screen' );
$srch = pt_capture( function () use ( $portal, $admin ) { $_GET = array( 'q' => 'beta' ); kit_call( 'SFAF_Portal', 'render_media', $portal, array( $admin ) ); } );
pt( false !== strpos( $srch, 'beta-walk.jpg' ) && false === strpos( $srch, 'alpha-clinic.jpg' ), 'A.3: the Images screen\'s search' );
$all = pt_capture( function () use ( $portal, $admin ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_media', $portal, array( $admin ) ); } );
pt( false !== strpos( $all, 'Move to Other images' ) && false !== strpos( $all, '>Move to series pictures<' ), 'A.3: an admin cannot move a picture between the two places' );

/* The approval dialog asks where the picture goes, with the event's series. */
$who = array( 'is_submission' => true, 'usable' => false, 'name' => '', 'email' => '' );
$ask = pt_capture( function () use ( $portal, $pending, $who ) { kit_call( 'SFAF_Portal', 'render_approve_ask', $portal, array( $pending, $who ) ); } );
pt( false !== strpos( $ask, 'name="picture_place" value="series"' ) && false !== strpos( $ask, 'name="picture_place" value="other"' ), 'D: the approval dialog does not ask where the picture goes' );
pt( (bool) preg_match( '/<option value="11"\s+selected/', $ask ), 'D: the event\'s own series is not chosen in the dialog' );
pt( false !== strpos( $ask, 'form="uc-approve-' . $pending . '"' ), 'D: the picture question does not post with Approve' );
$none = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'pending', 'post_title' => 'No picture', 'post_author' => 0 ) );
$ask0 = pt_capture( function () use ( $portal, $none, $who ) { kit_call( 'SFAF_Portal', 'render_approve_ask', $portal, array( $none, $who ) ); } );
pt( false === strpos( $ask0, 'picture_place' ), 'D: a pending event with no picture is asked about one' );

/* Approve: a series answer with no series is refused before anything is
   published, and bulk Publish holds a submission with a picture. */
$psrc = file_get_contents( dirname( __DIR__ ) . '/includes/class-sfaf-portal.php' );
$case = substr( $psrc, strpos( $psrc, "case 'approve_event':" ), 6000 );
pt( strpos( $case, "'approve_picture_series'" ) < strpos( $case, '$this->publish_one( $event_id )' ), 'D: the series answer is checked after the event is published' );
pt( false !== strpos( $case, 'SFAF_Media::move( $pic_id, $pic_place, $pic_series )' ) && false !== strpos( $case, 'set_post_thumbnail( $event_id, $pic_id )' ), 'D: approval does not move the picture and attach it' );
$bulk = substr( $psrc, strpos( $psrc, 'private function pending_bulk_apply' ), 4000 );
pt( (bool) preg_match( "/case 'publish':\s*(?:\/\*.*?\*\/\s*)?if \( SFAF_Media::submitted_picture\( \\\$id \) \)/s", $bulk ), 'D: bulk Publish does not hold a submission with a picture' );

/* =========================================================================
 * C.2. ONE EMAIL PER PICTURE
 * ====================================================================== */
$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'draft', 'post_title' => 'Strut art show', 'post_author' => 1 ) );
update_post_meta( $ev, '_thumbnail_id', 803 );
update_post_meta( 803, SFAF_Media::META_UPLOAD, array( 'by' => 1, 'at' => strtotime( '2026-10-09 14:30:00' ), 'event' => $ev ) );
$GLOBALS['pt_mail'] = array();
SFAF_Media::notify_event_upload( $ev );
SFAF_Media::notify_event_upload( $ev );
pt( 1 === count( $GLOBALS['pt_mail'] ), 'PLANT C.2: ' . count( $GLOBALS['pt_mail'] ) . ' emails for one picture saved twice' );
$m = isset( $GLOBALS['pt_mail'][0] ) ? $GLOBALS['pt_mail'][0] : array( 'to' => '', 'subject' => '', 'body' => '' );
pt( 'websites@sfaf.org' === $m['to'], 'C.2: the email goes to ' . $m['to'] );
pt( false !== strpos( $m['subject'], 'Strut art show' ), 'C.2: the subject does not name the event' );
pt( false !== strpos( $m['body'], 'Admin' ) && false !== strpos( $m['body'], '/caladmin/events/edit/' . $ev ) && false !== strpos( $m['body'], 'one-off-flyer.jpg' ),
    'C.2: the email does not carry who, the caladmin link and the picture' );
pt( false !== strpos( $m['body'], sfaf_ap_datetime( strtotime( '2026-10-09 14:30:00' ) ) ), 'C.2: the email does not say when, in the house date format' );
/* A picture chosen from a folder, not uploaded for an event, sends nothing. */
$ev2 = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'draft', 'post_title' => 'Chosen one', 'post_author' => 1 ) );
update_post_meta( $ev2, '_thumbnail_id', 801 );
$GLOBALS['pt_mail'] = array();
SFAF_Media::notify_event_upload( $ev2 );
pt( 0 === count( $GLOBALS['pt_mail'] ), 'C.2: a picture chosen from the picker sent an email' );

/* The save route asks for the email once, after everything. */
$save = substr( $psrc, strpos( $psrc, 'private function save_event_from_post' ), 60000 );
pt( false !== strpos( $save, '$pic_refused = $this->save_event_upload( $user, $event_id, $is_locked );' ) && false !== strpos( $save, 'SFAF_Media::notify_event_upload( $event_id );' ),
    'C: the event save does not take the upload and ask for the email' );
$sup = substr( $psrc, strpos( $psrc, 'private function save_event_upload' ), 3000 );
pt( false !== strpos( $sup, "SFAF_Uploads::inspect( 'uc_event_picture' )" ) && false !== strpos( $sup, "'upload_to_other'" ), 'C: the event upload skips the rule or lands outside Other images' );

/* =========================================================================
 * E. SET TEAM
 * ====================================================================== */
$GLOBALS['kit_umeta'] = array( 3 => array( '_uc_calendar_role' => 'editor' ), 5 => array( '_uc_calendar_role' => 'editor' ) );
$GLOBALS['kit_refused'] = array( 3, 5 );
$GLOBALS['kit_redirect_throws'] = true;
$mine   = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Mine', 'post_author' => 3 ) );
$theirs = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Not mine', 'post_author' => 1 ) );
$ed = new WP_User(); $ed->ID = 3; $GLOBALS['kit_user_id'] = 3;
$_POST = array( 'bulk_access_present' => '1', 'bulk_access_people' => array( '5' ) );
$GLOBALS['kit_redirect'] = null;
try { kit_call( 'SFAF_Portal', 'bulk_team_from_post', $portal, array( $ed, array( $mine, $theirs ) ) ); } catch ( KitRedirect $r ) { /* the answer */ }
pt( array( 5 ) === SFAF_Access::people( $mine ), 'E: Set team did not reach the editor\'s own event: ' . json_encode( SFAF_Access::people( $mine ) ) );
pt( array() === SFAF_Access::people( $theirs ), 'PLANT E: Set team touched an event the editor may not edit' );
pt( false !== strpos( (string) $GLOBALS['kit_redirect'], 'did=1' ) && false !== strpos( (string) $GLOBALS['kit_redirect'], 'refused=1' ), 'E: the flash does not count one done and one skipped: ' . $GLOBALS['kit_redirect'] );
/* Not offered to a contributor, who may not assign Team and access at all. */
$GLOBALS['kit_umeta'][7] = array( '_uc_calendar_role' => 'contributor' );
$GLOBALS['kit_refused'][] = 7;
$co = new WP_User(); $co->ID = 7;
$plan = kit_call( 'SFAF_Portal', 'bulk_plan', $portal, array( $co, array() ) );
pt( false === $plan['team'], 'E: Set team is offered to a contributor' );
$plan = kit_call( 'SFAF_Portal', 'bulk_plan', $portal, array( $ed, array() ) );
pt( true === $plan['team'], 'E: Set team is not offered to an editor' );

if ( $fails ) {
    echo 'PICTURES 3.110.3: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "pictures 3.110.3: the rule is 1200 by 675 and 500KB everywhere but a description, and says one sentence; the picker offers the series and then Other images and never a submission; Images shows three places with search and the active tick; approval asks where a submitted picture goes; one email per uploaded picture; Set team reaches only events the person may assign on.\n";
