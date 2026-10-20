<?php
/**
 * 3.113.2, THE PARTS A TEST CAN SETTLE WITHOUT A BROWSER.
 *
 *     php .claude/release-31132-test.php
 *
 * Through wp-kit.php and the real routes and renderers.
 *
 *   1  "Events need an organizer" reports upcoming events only: one whose
 *      last day has gone is left out, today and undated are kept; the
 *      all-clear says nothing about the events having been given anybody
 *   2  Add event and Edit event carry data-uc-leave-warn, and both scripts
 *      raise the leave warning (the behaviour is release-31132-live.php's)
 *   3  deleting every FAQ saves, in caladmin and in WP admin; "No FAQs for
 *      this event" stores no rows and keeps a series' default set off, on
 *      Add and on a move into a series; a set applied by hand clears it
 *   4  a submission waiting for review shows Approve (green), Reject and a
 *      plain Save changes, and no Cancel event or Delete; Pending has a
 *      Rejected and dismissed tab; Delete permanently takes only those two,
 *      and an imported one is recorded so a fetch never makes it again
 *   5  the staff request form asks "Do you want RSVPs for this event?" and
 *      no team question; a request goes on the MarCom team, never with the
 *      requester added; a yes lists the requester for the count the day
 *      before and the list two hours before, and nothing else
 *
 * The public calendar is embed-identity-test.php's. PLANTs in plant-31132.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( $to, $s, $b, $h = '', $a = array() ) { return true; } }
/* wp-kit has no delete: this one runs what before_delete_post has, then
   removes the post, which is what WordPress does. */
if ( ! function_exists( 'wp_delete_post' ) ) {
    function wp_delete_post( $id, $force = false ) {
        foreach ( isset( $GLOBALS['kit_hooks']['before_delete_post'] ) ? $GLOBALS['kit_hooks']['before_delete_post'] : array() as $h ) {
            call_user_func( $h['cb'], (int) $id );
        }
        unset( $GLOBALS['kit_posts'][ (int) $id ], $GLOBALS['kit_meta'][ (int) $id ] );
        return true;
    }
}

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . json_encode( $got ) . ', wanted ' . json_encode( $want ); }
}
function rt_cap( $fn ) { ob_start(); try { $fn(); } catch ( Throwable $t ) { ob_end_clean(); return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); } return ob_get_clean(); }
function rt_meta_match( $id, $clause ) {
    if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) { return true; }
    $has = isset( $GLOBALS['kit_meta'][ $id ] ) && array_key_exists( $clause['key'], $GLOBALS['kit_meta'][ $id ] );
    if ( isset( $clause['compare'] ) && 'EXISTS' === $clause['compare'] ) { return $has; }
    if ( isset( $clause['value'] ) ) { return $has && (string) $GLOBALS['kit_meta'][ $id ][ $clause['key'] ] === (string) $clause['value']; }
    return true;
}
function rt_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type ) { continue; }
        if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
        $ok = true;
        foreach ( isset( $a['meta_query'] ) ? (array) $a['meta_query'] : array() as $k => $c ) {
            if ( 'relation' !== $k && ! rt_meta_match( $id, $c ) ) { $ok = false; }
        }
        if ( $ok ) { $out[] = $p; }
    }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'ID' ) : $out;
}
$GLOBALS['kit_query'] = 'rt_query'; $GLOBALS['kit_get_posts'] = 'rt_query';
$GLOBALS['kit_redirect_throws'] = true;
$GLOBALS['kit_caps']['manage_options'] = true;
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$root   = $GLOBALS['kit_root'];
$code   = function ( $rel ) use ( $root ) { return preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/' . $rel ) ); };

function rt_event( $status = 'publish', $date = null, $author = 1 ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Support group', 'post_author' => $author, 'post_content' => '<p>Weekly.</p>' ) );
    foreach ( array( '_uc_event_date' => null === $date ? date( 'Y-m-d', time() + 9 * 86400 ) : $date, '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street' ) as $k => $v ) {
        if ( '' !== $v ) { update_post_meta( $id, $k, $v ); }
    }
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 1 );
    $GLOBALS['kit_terms'][ $id ]['uc_organizer']      = array( 1 );
    return $id;
}
function rt_post( $action, $fields ) {
    global $portal;
    $_GET  = array();
    $_POST = array_merge( array( 'uc_action' => $action, 'uc_nonce' => 'nonce' ), $fields );
    try { kit_call( 'SFAF_Portal', 'dispatch_post', $portal, array( $action ) ); }
    catch ( KitRedirect $r ) { return $r->getMessage(); }
    catch ( Throwable $t ) { return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    return '';
}
function rt_save( $id, $over ) {
    global $portal;
    $_GET  = array();
    $_POST = array_merge( array(
        'event_id' => $id, 'save_mode' => 'keep', 'title' => 'Support group', 'date' => date( 'Y-m-d', strtotime( '+9 days' ) ),
        'start_time' => '18:00', 'end_time' => '19:30', 'uc_organizer_present' => '1', 'organizer' => array( '1' ),
        'uc_category_present' => '1', 'category' => array( '1' ), 'description' => '<p>Weekly.</p>',
        'uc_online_present' => '1', 'uc_online' => '0',
        'location_mode' => 'custom', 'location_street' => '1035 Market Street', 'location_city' => 'San Francisco',
    ), $over );
    try { kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( new WP_User() ) ); }
    catch ( KitRedirect $r ) { return $r->getMessage(); }
    catch ( Throwable $t ) { return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    return '';
}
$rows2 = array( array( 'question' => 'Is it free?', 'answer' => 'Yes.' ), array( 'question' => 'Parking?', 'answer' => 'Street only.' ) );

/* ======================================================================== 1 */
$today  = SFAF_Sources::today();
$past   = rt_event( 'publish', date( 'Y-m-d', strtotime( $today . ' -3 days' ) ), 0 );
$now    = rt_event( 'publish', $today, 0 );
$soon   = rt_event( 'draft', date( 'Y-m-d', strtotime( $today . ' +5 days' ) ), 0 );
$nodate = rt_event( 'publish', '', 0 );
$multi  = rt_event( 'publish', date( 'Y-m-d', strtotime( $today . ' -2 days' ) ), 0 );
update_post_meta( $multi, '_uc_end_date', date( 'Y-m-d', strtotime( $today . ' +1 day' ) ) );
$trashed = rt_event( 'trash', date( 'Y-m-d', strtotime( $today . ' +5 days' ) ), 0 );
$dismiss = rt_event( SFAF_Sources::STATUS_DISMISSED, date( 'Y-m-d', strtotime( $today . ' +5 days' ) ), 0 );
$orph = wp_list_pluck( SFAF_Portal::orphaned_events(), 'id' );
sort( $orph );
$want = array( $now, $soon, $nodate, $multi ); sort( $want );
rt( '1: upcoming events only, today, undated and a multi-day still running kept', $orph, $want );
rt( '1: a past event is left out', in_array( $past, $orph, true ), false );
rt( '1: trash and Dismissed are left out', in_array( $trashed, $orph, true ) || in_array( $dismiss, $orph, true ), false );
$orphans_code = $code( 'includes/class-sfaf-orphans.php' );
rt( '1: the all-clear does not say they were given an organizer', false === strpos( $orphans_code, 'been given' ) && false !== strpos( $orphans_code, 'Every upcoming event has an organizer' ), true );
foreach ( array_keys( $GLOBALS['kit_posts'] ) as $id ) { unset( $GLOBALS['kit_posts'][ $id ] ); }

/* ======================================================================== 2 */
$ev2  = rt_event();
$add  = rt_cap( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) ); } );
$edit = rt_cap( function () use ( $portal, $ev2 ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $ev2 ) ); } );
rt( '2: Add event rendered', 0 === strpos( $add, 'THREW' ) ? $add : 'ok', 'ok' );
rt( '2: Edit event rendered', 0 === strpos( $edit, 'THREW' ) ? $edit : 'ok', 'ok' );
rt( '2: Add event\'s form carries data-uc-leave-warn', (bool) preg_match( '#<form method="post" id="uc-event-form-0"[^>]*data-uc-leave-warn>#', $add ), true );
rt( '2: Edit event\'s form carries it', (bool) preg_match( '#<form method="post" id="uc-event-form-' . $ev2 . '"[^>]*data-uc-leave-warn>#', $edit ), true );
rt( '2: on one form only', substr_count( $edit, 'data-uc-leave-warn' ), 1 );
$sess = (string) file_get_contents( $root . '/public/js/caladmin-session.js' );
rt( '2: caladmin warns on beforeunload', false !== strpos( $sess, "window.addEventListener('beforeunload'" ) && false !== strpos( $sess, "form[data-uc-leave-warn]" ), true );
$adm = (string) file_get_contents( $root . '/admin/js/admin.js' );
rt( '2: WP admin warns on beforeunload for the meta boxes', false !== strpos( $adm, "window.addEventListener('beforeunload'" ) && false !== strpos( $adm, '#uc_event_details' ), true );

/* ======================================================================== 3 */
$f1 = rt_event();
update_post_meta( $f1, sfaf_faq_meta_key(), $rows2 );
$r = rt_save( $f1, array( 'uc_faq_has_manual' => '1' ) );
rt( '3: the save ran', 0 === strpos( $r, 'THREW' ) ? $r : 'ok', 'ok' );
rt( '3: caladmin: every FAQ deleted and saved stays deleted', sfaf_get_faqs( $f1 ), array() );
rt( '3: and the marker ships on an ordinary event', (bool) preg_match( '#<input type="hidden" name="uc_faq_has_manual" value="1" />#', $edit ), true );
rt( '3: Add event has it too', false !== strpos( $add, 'name="uc_faq_has_manual"' ), true );
rt( '3: the tick is on Add and Edit', false !== strpos( $add, 'name="uc_faq_none"' ) && false !== strpos( $edit, 'No FAQs for this event' ), true );
update_post_meta( $f1, sfaf_faq_meta_key(), $rows2 );
rt_save( $f1, array( 'uc_faq_has_manual' => '1', 'uc_faqs' => array( array( 'question' => 'Kept?', 'answer' => 'Yes.' ) ) ) );
rt( '3: a save with rows keeps exactly those rows', wp_list_pluck( sfaf_get_faqs( $f1 ), 'question' ), array( 'Kept?' ) );
rt_save( $f1, array( 'uc_faq_has_manual' => '1', 'uc_faq_none' => '1', 'uc_faqs' => array( array( 'question' => 'Ignored?', 'answer' => 'Yes.' ) ) ) );
rt( '3: No FAQs stores no rows, whatever was posted', sfaf_get_faqs( $f1 ), array() );
rt( '3: and is stored', SFAF_FAQ_Sets::is_none( $f1 ), true );
$ticked = rt_cap( function () use ( $portal, $f1 ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $f1 ) ); } );
rt( '3: the tick draws ticked', (bool) preg_match( '#name="uc_faq_none" value="1" data-uc-faq-none\s+checked#', $ticked ), true );
rt( '3: and the rows and the set picker are hidden', (bool) preg_match( '#<div class="uc-faq-body" data-uc-faq-body hidden>#', $ticked ), true );
rt_save( $f1, array( 'uc_faq_has_manual' => '1', 'uc_faqs' => array( array( 'question' => 'Back?', 'answer' => 'Yes.' ) ) ) );
rt( '3: unticked, rows save again', array( SFAF_FAQ_Sets::is_none( $f1 ), wp_list_pluck( sfaf_get_faqs( $f1 ), 'question' ) ), array( false, array( 'Back?' ) ) );
rt( '3: no marker, no write: a form without the block leaves the rows', ( function () use ( $f1 ) { rt_save( $f1, array() ); return wp_list_pluck( sfaf_get_faqs( $f1 ), 'question' ); } )(), array( 'Back?' ) );

/* The series default: a set, a series that names it. */
update_option( SFAF_FAQ_Sets::OPTION, array( 'set1' => array( 'id' => 'set1', 'name' => 'Group basics', 'rows' => $rows2, 'created' => 1, 'updated' => 1 ) ) );
$set_ok = SFAF_FAQ_Sets::get( 'set1' );
rt( '3: the fixture set resolves', is_array( $set_ok ) && 2 === count( $set_ok['rows'] ), true );
$sid = 11;   // the kit's series Alpha
update_term_meta( $sid, SFAF_Series::META_FAQ_SET, 'set1' );
rt( '3: the series names the set', SFAF_Series::default_faq_set( $sid ), 'set1' );
$f2 = rt_event();
rt_save( $f2, array( 'uc_faq_has_manual' => '1', 'series' => (string) $sid ) );
rt( '3: moving into a series with no FAQs of its own takes the default', count( sfaf_get_faqs( $f2 ) ), 2 );
$f3 = rt_event();
rt_save( $f3, array( 'uc_faq_has_manual' => '1', 'uc_faq_none' => '1', 'series' => (string) $sid ) );
rt( '3: with No FAQs, moving into it adds nothing', sfaf_get_faqs( $f3 ), array() );
rt( '3: apply_series_default() refuses it too', SFAF_FAQ_Sets::apply_series_default( $f3, $sid ), false );
$f4 = rt_event();
rt_save( $f4, array( 'uc_faq_has_manual' => '1', 'series' => (string) $sid, 'uc_faqs' => array( array( 'question' => 'Own?', 'answer' => 'Yes.' ) ) ) );
rt( '3: an event with its own rows keeps only those', wp_list_pluck( sfaf_get_faqs( $f4 ), 'question' ), array( 'Own?' ) );
SFAF_FAQ_Sets::apply( $f3, 'set1', 'append' );
rt( '3: a set applied by hand clears No FAQs', SFAF_FAQ_Sets::is_none( $f3 ), false );

/* WP admin's box and save. */
$pt = ( new ReflectionClass( 'SFAF_Post_Types' ) )->newInstanceWithoutConstructor();
$f5 = rt_event();
update_post_meta( $f5, sfaf_faq_meta_key(), $rows2 );
$box = rt_cap( function () use ( $pt, $f5 ) { $pt->render_faq_meta_box( get_post( $f5 ) ); } );
rt( '3: WP admin: the box draws', 0 === strpos( $box, 'THREW' ) ? $box : 'ok', 'ok' );
rt( '3: WP admin: the marker ships on an ordinary event', false !== strpos( $box, 'name="uc_faq_has_manual"' ), true );
rt( '3: WP admin: and the tick', false !== strpos( $box, 'name="uc_faq_none"' ), true );
$_POST = array( 'uc_event_nonce' => 'n', 'uc_faq_has_manual' => '1' );
$saved = rt_cap( function () use ( $pt, $f5 ) { $pt->save_meta( $f5 ); } );
rt( '3: WP admin: the save ran', 0 === strpos( $saved, 'THREW' ) ? $saved : 'ok', 'ok' );
rt( '3: WP admin: every FAQ deleted and saved stays deleted', sfaf_get_faqs( $f5 ), array() );
update_post_meta( $f5, sfaf_faq_meta_key(), $rows2 );
$_POST = array( 'uc_event_nonce' => 'n', 'uc_faq_has_manual' => '1', 'uc_faq_none' => '1', 'uc_faqs' => $rows2 );
rt_cap( function () use ( $pt, $f5 ) { $pt->save_meta( $f5 ); } );
rt( '3: WP admin: No FAQs stores none', array( sfaf_get_faqs( $f5 ), SFAF_FAQ_Sets::is_none( $f5 ) ), array( array(), true ) );
$_POST = array();

/* ======================================================================== 4 */
$sub = rt_event( 'pending' );
update_post_meta( $sub, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_COMMUNITY );
update_post_meta( $sub, SFAF_Request::META_EMAIL, 'pat@example.org' );
update_post_meta( $sub, SFAF_Request::META_NAME, 'Pat' );
$ed = rt_cap( function () use ( $portal, $sub ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $sub ) ); } );
rt( '4: the pending submission renders', 0 === strpos( $ed, 'THREW' ) ? $ed : 'ok', 'ok' );
$bar = preg_match( '#<div class="uc-form-actions uc-form-actions-primary uc-editor-actions uc-editor-bar"(.*?)</div>\s*<\?php|<div class="uc-form-actions uc-form-actions-primary uc-editor-actions uc-editor-bar".*?(?=<form method="post" action="[^"]*" id="uc-approve-)#s', $ed, $m ) ? $m[0] : '';
rt( '4: the action bar is found', '' !== $bar, true );
preg_match_all( '#<button\b[^>]*>(.*?)</button>#s', $bar, $btns );
$labels = array_map( function ( $b ) { return trim( strip_tags( $b ) ); }, $btns[1] );
rt( '4: three buttons: Save changes, Approve, Reject', $labels, array( 'Save changes', 'Approve', 'Reject' ) );
rt( '4: Approve is green and the main action', (bool) preg_match( '#class="uc-btn uc-btn-go uc-editor-approve"[^>]*>Approve<#', $bar ), true );
rt( '4: Save changes is plain', (bool) preg_match( '#class="uc-btn uc-editor-save">Save changes<#', $bar ), true );
rt( '4: Save is still first in the markup, so Enter saves', strpos( $bar, 'Save changes' ) < strpos( $bar, '>Approve<' ), true );
rt( '4: no Cancel event or Delete on the page', false === strpos( $ed, 'data-uc-dialog-open="uc-cancel-this"' ) && false === strpos( $ed, 'data-uc-dialog-open="uc-delete-this"' ) && false === strpos( $ed, 'uc_portal_trash_event' ), true );
$css = (string) file_get_contents( $root . '/public/css/portal.css' );
rt( '4: Reject, Save, Approve left to right', false !== strpos( $css, '.uc-editor-actions .uc-editor-reject  { order: 2; }' ) && false !== strpos( $css, '.uc-editor-actions .uc-editor-approve { order: 4; }' ), true );
rt( '4: Approve is the size of the main action', false !== strpos( $css, ".uc-editor-actions .uc-editor-publish,\n.uc-editor-actions .uc-editor-approve,\n.uc-editor-actions .uc-editor-save-main {" ), true );
/* Approved, both come back. */
$pub = rt_event( 'publish' );
$pe  = rt_cap( function () use ( $portal, $pub ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $pub ) ); } );
rt( '4: a published event keeps Cancel event and Delete', false !== strpos( $pe, 'data-uc-dialog-open="uc-cancel-this"' ) && false !== strpos( $pe, 'data-uc-dialog-open="uc-delete-this"' ), true );

/* The tab. */
$dis = rt_event( SFAF_Sources::STATUS_DISMISSED );
update_post_meta( $dis, SFAF_Sources::META_SOURCE, 'everyaction' );
update_post_meta( $dis, SFAF_Sources::META_EXTERNAL_ID, 'UUID-1' );
$rej = rt_event( 'trash' );
update_post_meta( $rej, SFAF_Uploads::META_REJECTED, '2026-10-19 09:00:00' );
$plain_trash = rt_event( 'trash' );
$q = rt_cap( function () use ( $portal ) { $_GET = array( 'view' => 'closed' ); kit_call( 'SFAF_Portal', 'render_pending', $portal, array( new WP_User() ) ); } );
rt( '4: the tab renders', 0 === strpos( $q, 'THREW' ) ? $q : 'ok', 'ok' );
rt( '4: the tab is active', (bool) preg_match( '#<a class="uc-view-tab uc-view-tab-active" data-uc-closed-tab\s+href="[^"]*view=closed"\s*aria-current="page">\s*Rejected and dismissed\s*<span class="uc-tab-count">2</span>#', $q ), true );
rt( '4: the dismissed row is in it', false !== strpos( $q, 'data-uc-id="' . $dis . '" data-uc-kind="dismissed"' ), true );
rt( '4: the rejected row is in it', false !== strpos( $q, 'data-uc-id="' . $rej . '" data-uc-kind="rejected"' ), true );
rt( '4: something trashed another way is not', false === strpos( $q, 'data-uc-id="' . $plain_trash . '"' ), true );
rt( '4: each row has Delete permanently, asked first', substr_count( $q, 'value="pending_purge"' ) === 2 && 2 === preg_match_all( '#data-uc-purge\s+data-uc-confirm="Delete &quot;Support group&quot; permanently\? Nothing puts it back\.#', $q ), true );
rt( '4: the work list is not drawn under it', false === strpos( $q, 'Waiting for a decision' ), true );
$w = rt_cap( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_pending', $portal, array( new WP_User() ) ); } );
rt( '4: the work list has the tab, inactive, and no dismissed row', false !== strpos( $w, '<a class="uc-view-tab" data-uc-closed-tab' ) && false === strpos( $w, 'data-uc-kind="dismissed"' ), true );

/* The route. */
SFAF_Sources::register_deleted_record();
rt( '4: refused on a published event', array( false !== strpos( rt_post( 'pending_purge', array( 'event_id' => $pub ) ), 'pending?view=closed&msg=purge_refused' ), null !== get_post( $pub ) ), array( true, true ) );
rt( '4: refused on something trashed another way', null !== get_post( $plain_trash ) && false !== strpos( rt_post( 'pending_purge', array( 'event_id' => $plain_trash ) ), 'purge_refused' ) && null !== get_post( $plain_trash ), true );
rt( '4: a rejected one is deleted', array( false !== strpos( rt_post( 'pending_purge', array( 'event_id' => $rej ) ), 'pending?view=closed&msg=purged' ), get_post( $rej ) ), array( true, null ) );
rt( '4: a dismissed import is deleted', array( false !== strpos( rt_post( 'pending_purge', array( 'event_id' => $dis ) ), 'msg=purged' ), get_post( $dis ) ), array( true, null ) );
rt( '4: and its id is kept, so a fetch never makes it again', SFAF_Sources::was_deleted( 'everyaction', 'UUID-1' ), true );
rt( '4: the run skips a kept id', false !== strpos( $code( 'includes/class-sfaf-sources.php' ), "if ( ! \$existing && self::was_deleted( \$event['external_source'], \$event['external_id'] ) ) {" ), true );
rt( '4: the hook is registered at boot', false !== strpos( $code( 'sfaf-calendar.php' ), 'SFAF_Sources::register_deleted_record();' ), true );
$GLOBALS['kit_caps']['manage_options'] = false;
$GLOBALS['kit_refused'] = array( 1 );
$deny = rt_post( 'pending_purge', array( 'event_id' => $plain_trash ) );
rt( '4: an editor is refused', null !== get_post( $plain_trash ) && false === strpos( $deny, 'purged' ), true );
$GLOBALS['kit_refused'] = array();
$GLOBALS['kit_caps']['manage_options'] = true;

/* ======================================================================== 5 */
update_option( SFAF_Teams::OPTION, array(
    'aaa' => array( 'name' => 'Clinic', 'users' => array( 2 ) ),
    'mc7' => array( 'name' => 'MarCom team', 'users' => array( 3 ) ),
) );
rt( '5: MarCom is found by name', SFAF_Request::marcom_team(), 'mc7' );
$c = array( 'title' => 'Board social', 'description' => '', 'date' => date( 'Y-m-d', strtotime( '+9 days' ) ), 'start' => '18:00', 'end' => '19:30',
    'venue' => 0, 'venue_other' => '1035 Market Street', 'venue_name' => '', 'categories' => array(), 'series' => 0, 'organizer' => array(),
    'image' => 0, 'capacity' => '', 'faqs' => array(), 'name' => 'Tess', 'notes' => '', 'pattern' => '', 'pattern_dates' => array(),
    'pattern_limit' => 0, 'repeat' => 'none', 'repeat_until' => '', 'rsvp' => false, 'video' => '' );
$no = kit_call( 'SFAF_Request', 'create_event', null, array( $c, 'tess@sfaf.org', 0 ) );
rt( '5: a request goes on the MarCom team', SFAF_Teams::access_for_event( $no ), array( 'mc7' ) );
rt( '5: the requester is on no team', SFAF_Teams::for_user( 3 ) === SFAF_Teams::for_user( 3 ) && ! in_array( 'tess@sfaf.org', array_map( 'strval', (array) get_option( SFAF_Teams::OPTION ) ), true ), true );
rt( '5: RSVP no: not taking RSVPs, nobody listed, nothing switched off', array( (string) get_post_meta( $no, '_uc_rsvp_enabled', true ), get_post_meta( $no, SFAF_Reminders::NOTIFY_EMAILS_META, true ), get_post_meta( $no, SFAF_Notifications::OFF_META, true ) ), array( '', '', '' ) );
$c['rsvp'] = true;
$yes = kit_call( 'SFAF_Request', 'create_event', null, array( $c, 'tess@sfaf.org', 0 ) );
rt( '5: RSVP yes: takes RSVPs', (string) get_post_meta( $yes, '_uc_rsvp_enabled', true ), '1' );
rt( '5: the requester is on the notification list', (array) get_post_meta( $yes, SFAF_Reminders::NOTIFY_EMAILS_META, true ), array( 'tess@sfaf.org' ) );
$on = array();
foreach ( SFAF_Notifications::choosable() as $k ) { if ( SFAF_Notifications::stored_on( $yes, $k ) ) { $on[] = $k; } }
rt( '5: the two emails on, the alerts off', $on, array( 'day_before', 'summary' ) );
rt( '5: and they are the two named', array( SFAF_Notifications::kinds()['day_before']['label'], SFAF_Notifications::kinds()['summary']['label'] ),
    /* Renamed in 3.113.3. */
    array( 'RSVP count, the day before', "Who's coming, two hours before" ) );
update_option( SFAF_Teams::OPTION, array( 'aaa' => array( 'name' => 'Clinic', 'users' => array( 2 ) ) ) );
$none = kit_call( 'SFAF_Request', 'create_event', null, array( $c, 'tess@sfaf.org', 0 ) );
rt( '5: with no MarCom team, no team is set', SFAF_Teams::access_for_event( $none ), array() );

/* Approve does not offer a tick that cannot take them off. */
update_post_meta( $yes, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_STAFF );
update_post_meta( $yes, SFAF_Request::META_NAME, 'Tess' );
update_post_meta( $yes, SFAF_Request::META_EMAIL, 'tess@sfaf.org' );
$who = SFAF_Submissions::submitter( $yes );
$ask = rt_cap( function () use ( $portal, $yes, $who ) { kit_call( 'SFAF_Portal', 'render_approve_ask', $portal, array( $yes, $who ) ); } );
rt( '5: Approve draws', false !== strpos( $ask, 'Submitted by' ), true );
rt( '5: no registrations tick for somebody already listed', false === strpos( $ask, 'name="notify_submitter"' ), true );

/* The form. */
$req_code = $code( 'includes/class-sfaf-request.php' );
rt( '5: the question is asked', false !== strpos( $req_code, 'Do you want RSVPs for this event?' ), true );
rt( '5: as Yes and No', (bool) preg_match( '#<input type="radio" name="rsvp" value="1".*?/>\s*Yes\s*</label>\s*<label class="uc-check">\s*<input type="radio" name="rsvp" value="0"#s', $req_code ), true );
rt( '5: the old tick is gone', false === strpos( $req_code, 'People need to RSVP' ), true );
rt( '5: no team question', false === strpos( $req_code, 'Who should be able to edit' ) && false === strpos( $req_code, 'request_teams' ), true );
rt( '5: the confirmation no longer says nothing else happens', false === strpos( $req_code, 'Nothing else happens automatically' ), true );

echo "\nRelease 3.113.2\n" . str_repeat( '=', 72 ) . "\nran $ran assertions.\n";
if ( $fails ) { echo count( $fails ) . " failure(s):\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Upcoming orphans only, the leave warning on both editors, deleted FAQs stay deleted and No FAQs holds, three buttons on a submission, Rejected and dismissed with Delete permanently that is never re-imported, and requests on MarCom with the two RSVP emails.\n";
exit( 0 );
