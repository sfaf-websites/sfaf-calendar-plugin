<?php
/**
 * 3.112.1, THE PARTS OF ITEMS 4 AND 5 A TEST CAN SETTLE WITHOUT A BROWSER.
 *
 *     php .claude/release-31121-test.php
 *
 * Through wp-kit.php and the real routes.
 *
 *   4   Cancel event and Delete: one opener each in the action row, one panel
 *       each after it; the panel's submit names its form and comes after Save
 *       draft; Delete is refused, and not offered, on an uncancelled event
 *       with registrations; a cancel on a repeating event asks which dates,
 *       cancels all upcoming when told to and never a past one, and every
 *       cancel redirect carries edit_scope, so the editor does not ask "How
 *       should this save apply?" after it
 *   5   the Tags section: Archive, ", archived", the hint; Delete's dialog
 *       says how many events, counting every status; delete_tag is admins'
 *       only; deleting takes the tag off every series default, forgets its old
 *       slugs and retires the embed cache
 *
 * PLANT 4 and PLANT 5 in plant-31121.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( $to, $s, $b, $h = '', $a = array() ) { return true; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1, 'pending' => 1, 'future' => 1, 'private' => 1, 'trash' => 1 ); } }
$GLOBALS['rt_deleted_terms'] = array();
if ( ! function_exists( 'wp_delete_term' ) ) {
    function wp_delete_term( $id, $tax ) {
        $GLOBALS['rt_deleted_terms'][] = array( (int) $id, $tax );
        foreach ( $GLOBALS['kit_terms'] as $pid => $by ) {
            if ( isset( $by[ $tax ] ) ) { $GLOBALS['kit_terms'][ $pid ][ $tax ] = array_values( array_diff( $by[ $tax ], array( (int) $id ) ) ); }
        }
        $GLOBALS['kit_tax_terms'][ $tax ] = array_values( array_filter( kit_fake_terms( $tax ), function ( $t ) use ( $id ) { return (int) $t->term_id !== (int) $id; } ) );
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

/* ---- A query model with the group, the date and the tag. --------------- */
function rt_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type ) { continue; }
        if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
        $ok = true;
        foreach ( isset( $a['meta_query'] ) ? (array) $a['meta_query'] : array() as $c ) {
            if ( ! is_array( $c ) || empty( $c['key'] ) ) { continue; }
            $v = (string) get_post_meta( $id, $c['key'], true );
            $cmp = isset( $c['compare'] ) ? $c['compare'] : '=';
            if ( '=' === $cmp && $v !== (string) $c['value'] ) { $ok = false; }
            if ( '>=' === $cmp && $v < (string) $c['value'] ) { $ok = false; }
        }
        foreach ( isset( $a['tax_query'] ) ? (array) $a['tax_query'] : array() as $c ) {
            if ( ! is_array( $c ) || empty( $c['taxonomy'] ) ) { continue; }
            $have = isset( $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $c['taxonomy'] ] : array();
            if ( ! array_intersect( array_map( 'intval', (array) $c['terms'] ), array_map( 'intval', $have ) ) ) { $ok = false; }
        }
        if ( $ok ) { $out[] = $p; }
    }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'ID' ) : $out;
}
$GLOBALS['kit_query'] = 'rt_query'; $GLOBALS['kit_get_posts'] = 'rt_query';
$GLOBALS['kit_redirect_throws'] = true;
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();

function rt_event( $date, $group = '', $status = 'publish' ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Support group', 'post_author' => 1, 'post_content' => '<p>Weekly.</p>' ) );
    foreach ( array( '_uc_event_date' => $date, '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    if ( '' !== $group ) { update_post_meta( $id, SFAF_Recurrence::GROUP_META, $group ); }
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
$day = function ( $n ) { return date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' ' . ( $n >= 0 ? '+' : '' ) . $n . ' days' ) ); };

/* ======================================================================== 4 */
$past = rt_event( $day( -7 ), 'g1' );
$g    = array( rt_event( $day( 7 ), 'g1' ), rt_event( $day( 14 ), 'g1' ), rt_event( $day( 21 ), 'g1' ) );
$solo = rt_event( $day( 9 ) );

/* The editor: openers in the row, panels after it. */
$edit = rt_cap( function () use ( $portal, $g ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $g[0] ) ); } );
rt( '4: the editor rendered', 0 === strpos( $edit, 'THREW' ), false );
rt( '4: one Cancel event opener, a button that only opens', preg_match_all( '#<button type="button" class="uc-btn uc-btn-stop uc-cancel-open" data-uc-dialog-open="uc-cancel-this"#', $edit ), 1 );
rt( '4: one Delete opener, a button that only opens', preg_match_all( '#<button type="button" data-uc-dialog-open="uc-delete-this"#', $edit ), 1 );
rt( '4: the cancel panel is drawn', 1 === preg_match_all( '#<div class="uc-dialog-panel" id="uc-cancel-this" data-uc-dialog-panel#', $edit ), true );
rt( '4: the delete panel is drawn', 1 === preg_match_all( '#<div class="uc-dialog-panel" id="uc-delete-this" data-uc-dialog-panel#', $edit ), true );
$save_at = strpos( $edit, 'name="save_mode"' );
$del_at  = strpos( $edit, 'form="uc-delete-event-' . $g[0] . '">Delete</button>' );
rt( '4: Delete is never the first submit: Save draft comes first in the markup', false !== $save_at && false !== $del_at && $save_at < $del_at, true );
rt( '4: the panels sit after the action row', strpos( $edit, 'uc-editor-bar' ) < strpos( $edit, 'id="uc-cancel-this"' ), true );
rt( '4: the cards under the row are gone', false === strpos( $edit, 'uc-cancel-inline' ) && false === strpos( $edit, 'uc-editor-actions-note' ), true );
rt( '4: every button in the panels says its type and its form', (bool) preg_match_all( '#<button(?![^>]*type=)[^>]*>#', substr( $edit, strpos( $edit, 'id="uc-cancel-this"' ) ) ), false );
rt( '4: the saved-action markers stay', substr_count( $edit, 'data-uc-saved-action="cancel"' ) >= 2 && substr_count( $edit, 'data-uc-saved-action="delete"' ) >= 2, true );
rt( '4: a repeating event asks which dates', (bool) preg_match( '#name="cancel_scope" value="all_upcoming"#', $edit ) && false !== strpos( $edit, 'All 3 upcoming dates.' ), true );
rt( '4: the counts for all dates are stamped for the email question', false !== strpos( $edit, 'data-uc-cancel-count-all=' ), true );
$one = rt_cap( function () use ( $portal, $solo ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $solo ) ); } );
rt( '4: a one-off event does not ask which dates', false !== strpos( $one, 'id="uc-cancel-this"' ) && false === strpos( $one, 'name="cancel_scope"' ), true );
$add = rt_cap( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) ); } );
rt( '4: Add event has neither', false === strpos( $add, 'data-uc-dialog-open' ) && false === strpos( $add, 'uc-dialog-panel' ), true );

/* The route: this date, then all upcoming, never the past one. */
$to = rt_post( 'cancel_event', array( 'event_id' => $g[0], 'cancel_visibility' => 'stay', 'cancel_scope' => 'this' ) );
rt( '4: this date only', array_map( array( 'SFAF_Cancellation', 'is_cancelled' ), $g ), array( true, false, false ) );
rt( '4: the redirect carries edit_scope, so the editor does not ask how a save applies', (bool) preg_match( '#[?&]edit_scope=this\b#', $to ), true );
SFAF_Cancellation::set( $g[0], false );
$to = rt_post( 'cancel_event', array( 'event_id' => $g[1], 'cancel_visibility' => 'stay', 'cancel_scope' => 'all_upcoming' ) );
rt( '4: all upcoming dates', array_map( array( 'SFAF_Cancellation', 'is_cancelled' ), $g ), array( true, true, true ) );
rt( '4: never the past one', SFAF_Cancellation::is_cancelled( $past ), false );
rt( '4: the redirect says how many and carries edit_scope', (bool) preg_match( '#[?&]n=3\b#', $to ) && (bool) preg_match( '#[?&]edit_scope=this\b#', $to ), true );
$to = rt_post( 'cancel_event', array( 'event_id' => $g[1], 'uncancel' => '1' ) );
rt( '4: putting it back on carries edit_scope too', (bool) preg_match( '#[?&]edit_scope=this\b#', $to ), true );
$to = rt_post( 'cancel_event', array( 'event_id' => $g[2], 'cancel_visibility_only' => '1', 'cancel_visibility' => 'hide' ) );
rt( '4: Remove from the calendar carries edit_scope too', (bool) preg_match( '#[?&]edit_scope=this\b#', $to ), true );

/* Delete is refused, and not offered, on an uncancelled event with registrations. */
$reg = rt_event( $day( 30 ) );
$GLOBALS['kit_db'] = function ( $method, $sql ) use ( $reg ) {
    if ( false === strpos( $sql, 'uc_rsvps' ) ) { return 'get_results' === $method ? array() : null; }
    $mine = (bool) preg_match( '/\bevent_id\s*(=|IN\s*\()\s*' . $reg . '\b/', $sql );
    if ( 'get_var' === $method ) { return $mine ? '2' : '0'; }
    if ( 'get_results' === $method ) { return $mine ? array( (object) array( 'id' => 1, 'event_id' => $reg, 'email' => 'p@example.org', 'name' => 'P', 'first_name' => 'P', 'last_name' => '', 'status' => 'confirmed', 'token' => 't', 'format' => '' ) ) : array(); }
    return null;
};
$blocked = rt_cap( function () use ( $portal, $reg ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $reg ) ); } );
rt( '4: with registrations, Delete is not offered', false === strpos( $blocked, 'data-uc-dialog-open="uc-delete-this"' ) && false === strpos( $blocked, 'id="uc-delete-this"' ), true );
rt( '4: and the editor says why', false !== strpos( $blocked, 'so it cannot be deleted' ), true );
$to = rt_post( 'trash_event', array( 'event_id' => $reg ) );
rt( '4: and the route still refuses it', false !== strpos( $to, 'msg=delete_needs_cancel' ) && 'trash' !== get_post_status( $reg ), true );
$GLOBALS['kit_db'] = null;
$to = rt_post( 'trash_event', array( 'event_id' => $solo ) );
rt( '4: Delete posts the same route, and deletes', 'trash' === get_post_status( $solo ), true );

/* ======================================================================== 5 */
$GLOBALS['kit_tax_terms']['uc_tag'] = array();
foreach ( array( 31 => array( 'Food provided', 'food' ), 32 => array( 'Giveaways', 'giveaways' ) ) as $id => $nt ) {
    $t = new WP_Term(); $t->term_id = $id; $t->term_taxonomy_id = $id; $t->name = $nt[0]; $t->slug = $nt[1]; $t->taxonomy = 'uc_tag';
    $GLOBALS['kit_tax_terms']['uc_tag'][] = $t;
}
update_term_meta( 32, SFAF_Tags::META_RETIRED, '1' );
$tagged = array( rt_event( $day( 3 ) ), rt_event( $day( -40 ) ), rt_event( $day( 5 ), '', 'draft' ), rt_event( $day( 6 ), '', 'trash' ) );
foreach ( $tagged as $tid ) { $GLOBALS['kit_terms'][ $tid ]['uc_tag'] = array( 31 ); }
rt( '5: Delete counts every status, past, draft and trash', SFAF_Tags::events_tagged( 31 ), 4 );
rt( '5: and none for a tag on nothing', SFAF_Tags::events_tagged( 32 ), 0 );

$series = rt_cap( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_tags', $portal, array( new WP_User() ) ); } );
rt( '5: Archive, not Retire', false !== strpos( $series, '>Archive</button>' ) && false === strpos( $series, '>Retire<' ) && false === stripos( strip_tags( $series ), 'retire' ), true );
rt( '5: ", archived" after the count', false !== strpos( $series, ', archived' ), true );
rt( '5: the hint', false !== strpos( $series, 'An archived tag stays on the events that have it and is not offered for new ones or in the calendar\'s filter.' ), true );
rt( '5: the heading', false !== strpos( $series, 'Label events with anything visitors may want to filter by, such as food provided, giveaways or interpretation.' ), true );
rt( '5: Delete on a tag on events', false !== strpos( $series, 'Delete "Food provided"?</h2>' ) && (bool) preg_match( '#It will be removed from 4 events, including past ones\. This cannot be undone\.#', $series ), true );
rt( '5: Delete on a tag on no events', (bool) preg_match( '#Delete "Giveaways"\?</h2>\s*<p class="uc-dialog-lead">\s*This cannot be undone\.#', $series ), true );
rt( '5: archive instead, under either', substr_count( $series, 'To stop offering it but keep it on past events, archive it instead.' ), 2 );
rt( '5: the buttons: Delete in red and Keep tag', substr_count( $series, 'class="uc-btn uc-btn-danger"' ) === 2 && substr_count( $series, '>Keep tag</button>' ) === 2, true );
rt( '5: Add tag is the primary button in an action row', (bool) preg_match( '#<div class="uc-cat-form-actions">\s*<button type="submit" class="uc-btn uc-btn-sm uc-btn-primary" form="uc-tag-new">Add tag</button>#', $series ), true );
rt( '5: Rename and Archive use the Categories row classes, Delete its link', (bool) preg_match( '#<a class="uc-btn uc-btn-sm"[^>]*>Rename</a>#', $series ) && (bool) preg_match( '#<button type="button" class="uc-link-danger uc-btn-sm"[^>]*>Delete</button>#', $series ), true );

/* Deleting. */
$s1 = 11; $s2 = 12;
update_term_meta( $s1, SFAF_Tags::META_SERIES_DEFAULT, array( 31, 32 ) );
update_term_meta( $s2, SFAF_Tags::META_SERIES_DEFAULT, array( 31 ) );
update_option( SFAF_Slug_Aliases::OPTION, array( 'uc_tag' => array( 'free-food' => 31, 'swag' => 32 ), 'uc_organizer' => array( 'old' => 31 ) ) );
update_option( SFAF_Embed::CACHE_VERSION_OPTION, 5 );
/* An editor: another user id, because get_role() keeps its answer per request. */
$GLOBALS['kit_user_id'] = 9;
$GLOBALS['kit_umeta'][9]['_uc_calendar_role'] = 'editor';
$GLOBALS['kit_refused'] = array( 9 );
$to = rt_post( 'delete_tag', array( 'tag_id' => '31' ) );
rt( '5: delete_tag is refused to an editor', 0 === strpos( $to, 'THREW wp_die' ) && ! $GLOBALS['rt_deleted_terms'], true );
$GLOBALS['kit_user_id'] = 1;
$GLOBALS['kit_refused'] = array();
$to = rt_post( 'delete_tag', array( 'tag_id' => '31' ) );
rt( '5: an admin deletes it', false !== strpos( $to, 'msg=tag_deleted' ) && array( array( 31, 'uc_tag' ) ) === $GLOBALS['rt_deleted_terms'], true );
rt( '5: off every event', array_map( function ( $id ) { return $GLOBALS['kit_terms'][ $id ]['uc_tag']; }, $tagged ), array( array(), array(), array(), array() ) );
rt( '5: off every series default', array( get_term_meta( $s1, SFAF_Tags::META_SERIES_DEFAULT, true ), get_term_meta( $s2, SFAF_Tags::META_SERIES_DEFAULT, true ) ), array( array( 32 ), '' ) );
rt( '5: its old slugs forgotten, nobody else\'s', get_option( SFAF_Slug_Aliases::OPTION ), array( 'uc_tag' => array( 'swag' => 32 ), 'uc_organizer' => array( 'old' => 31 ) ) );
rt( '5: the embed cache retired', (int) get_option( SFAF_Embed::CACHE_VERSION_OPTION ), 6 );
rt( '5: an address naming it reaches nothing', ( new SFAF_Shortcodes() )->clean_active_tags( 'food,free-food' ), '' );
rt( '5: deleting it again says so', false !== strpos( rt_post( 'delete_tag', array( 'tag_id' => '31' ) ), 'msg=tag_delete_failed' ), true );

/* ======================================================================== 9 */
/* "What's included" is "Tags" wherever a person reads it; the names inside do not change. */
rt( '9: the public string, English', SFAF_Messages::label( 'whats_included', 'en' ), 'Tags' );
rt( '9: the public string, Spanish', SFAF_Messages::label( 'whats_included', 'es' ), 'Etiquetas' );
$src_portal = (string) file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' );
$src_admin  = (string) file_get_contents( $GLOBALS['kit_root'] . '/admin/class-sfaf-admin.php' );
$src_ajs    = (string) file_get_contents( $GLOBALS['kit_root'] . '/admin/js/admin.js' );
rt( '9: the editor field is Tags, with + Add tag', false !== strpos( $src_portal, '<span class="uc-field-label">Tags <?php echo $this->field_badge( $state, $label ); ?></span>' ) && false !== strpos( $src_portal, '>+ Add tag</button>' ), true );
rt( '9: the editor\'s empty hint stays', false !== strpos( $src_portal, 'No tags yet. An admin adds them under Series &amp; Categories.' ) || false !== strpos( $src_portal, 'No tags yet. An admin adds them under Series & Categories.' ), true );
rt( '9: the series screen says Default tags', false !== strpos( $src_portal, '>Default tags</span>' ), true );
rt( '9: the generator: Tags, One tag, Which tag', false !== strpos( $src_admin, '<option value="tag">One tag</option>' ) && false !== strpos( $src_admin, '>Which tag</label>' ) && false !== strpos( $src_admin, 'checked /> Tags</label>' ), true );
rt( '9: the generator\'s summary says tags', false !== strpos( $src_ajs, "return r === 'tag' ? 'tags' : r;" ), true );
$every = '';
foreach ( array( 'includes', 'admin', 'templates', 'public/js' ) as $dir ) {
    foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['kit_root'] . '/' . $dir ) ) as $file ) {
        if ( preg_match( '/\.(php|js)$/', $file ) ) { $every .= preg_replace( '#/\*.*?\*/|^\s*//.*$|<\?php //[^?]*\?>#ms', '', (string) file_get_contents( $file ) ); }
    }
}
rt( '9: no "What\'s included" or "thing included" left in anything a person reads', (bool) preg_match( "/what(&#039;|'|\\\\')s included|thing included|Qué incluye/i", $every ), false );
rt( '9: the internal key and the data attributes are unchanged', false !== strpos( $src_portal, "'whats_included'" ) || false !== strpos( (string) file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-tags.php' ), "'whats_included'" ), true );

echo "\nRelease 3.112.1, items 4, 5 and 9\n" . str_repeat( '=', 72 ) . "\n";
echo "ran $ran assertions.\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Cancel event and Delete open panels a dialog lifts, cancel asks which dates and leaves the scope answered, and a tag can be archived or deleted.\n";
exit( 0 );
