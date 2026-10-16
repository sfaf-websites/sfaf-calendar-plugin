<?php
/**
 * 3.113.0, THE PARTS A TEST CAN SETTLE WITHOUT A BROWSER.
 *
 *     php .claude/release-3113-test.php
 *
 * Through wp-kit.php and the real routes.
 *
 *   4   Cancel event and Delete, carried from 3.112.1: one opener each in the
 *       action row, one panel each after it; the panel's submit names its
 *       form and comes after Save draft; Delete is refused, and not offered,
 *       on an uncancelled event with registrations; a cancel on a repeating
 *       event asks which dates, cancels all upcoming when told to and never a
 *       past one, and every cancel redirect carries edit_scope
 *   N   the Notifications card: "Who hears about this event" and "Emails for
 *       this event" each a subhead, a status line and a secondary button
 *       (Choose people, Choose emails) that is the <summary> of its panel, on
 *       Add event and Edit event; the status lines say what is chosen and
 *       what is off, and "Nobody else yet." is the card's one empty state
 *
 * The public calendar is embed-identity-test.php's. PLANT 4 and PLANT N in
 * plant-3113.php.
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


/* ======================================================================== N */
/* Two calendar people, and an event with nothing chosen. */
$GLOBALS['kit_users'] = array();
foreach ( array( 21 => 'Ana Ruiz', 22 => 'Ben Young' ) as $uid => $name ) {
    $u = new WP_User(); $u->ID = $uid; $u->display_name = $name; $u->user_email = strtolower( strtok( $name, ' ' ) ) . '@sfaf.org'; $u->user_login = strtolower( strtok( $name, ' ' ) );
    $GLOBALS['kit_users'][] = $u;
    $GLOBALS['kit_umeta'][ $uid ]['_uc_calendar_role'] = 'editor';
}
$ev = rt_event( $day( 12 ) );
$card = function ( $id ) use ( $portal ) {
    return rt_cap( function () use ( $portal, $id ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_notify_box', $portal, array( new WP_User(), $id ) ); } );
};
$section = function ( $html, $subhead ) {
    $at = strpos( $html, '<h4 class="uc-notify-subhead">' . $subhead . '</h4>' );
    if ( false === $at ) { return ''; }
    $end = strpos( $html, '<div class="uc-notify-section"', $at + 1 );
    return substr( $html, $at, false === $end ? null : $end - $at );
};
$status = function ( $html, $attr ) {
    return preg_match( '#<p class="uc-notify-status" ' . $attr . '[^>]*>([^<]*)</p>#', $html, $m ) ? $m[1] : null;
};

$h = $card( $ev );
rt( 'N: the card rendered', 0 === strpos( $h, 'THREW' ), false );
$who    = $section( $h, 'Who hears about this event' );
$emails = $section( $h, 'Emails for this event' );
rt( 'N: Who hears about this event is a subhead', '' !== $who, true );
rt( 'N: Emails for this event is a subhead', '' !== $emails, true );
rt( 'N: the people status line, nothing chosen', $status( $who, 'data-uc-picker-count' ), 'Nobody else yet.' );
rt( 'N: the emails status line, all on', $status( $emails, 'data-uc-notify-kinds-status' ), 'All ' . count( SFAF_Notifications::kinds() ) . ' are on.' );
rt( 'N: Choose people is the secondary button and the summary of its panel',
    (bool) preg_match( '#<details class="uc-notify-choose" data-uc-picker>\s*<summary class="uc-btn uc-btn-sm uc-notify-choose-btn" data-uc-picker-toggle>\s*<span>Choose people</span>#', $who ), true );
rt( 'N: Choose emails is the secondary button and the summary of its panel',
    (bool) preg_match( '#<details class="uc-notify-choose uc-notify-kinds" data-uc-disclosure\s*>\s*<summary class="uc-btn uc-btn-sm uc-notify-choose-btn"[^>]*>\s*<span>Choose emails</span>#', $emails ), true );
rt( 'N: neither is the primary', false === strpos( $who . $emails, 'uc-notify-choose-btn uc-btn-primary' ) && 2 === substr_count( $h, 'uc-btn uc-btn-sm uc-notify-choose-btn"' ), true );
rt( 'N: the status sits above its button', strpos( $who, 'data-uc-picker-count' ) < strpos( $who, 'Choose people' ) && strpos( $emails, 'data-uc-notify-kinds-status' ) < strpos( $emails, 'Choose emails' ), true );
rt( 'N: the old rows are gone', false === strpos( $h, 'Choose who else gets it' ) && false === strpos( $h, 'Emails for this event:' ) && false === strpos( $h, 'uc-picker-label' ), true );
rt( 'N: one empty state on the card', substr_count( $h, 'Nobody else yet.' ), 1 );
rt( 'N: the emails panel is shut with every email on', false === strpos( $emails, 'data-uc-disclosure open' ), true );
rt( 'N: both panels still hold their controls', substr_count( $who, 'name="notify_users[]"' ) >= 1 && substr_count( $emails, 'name="notify_kinds[]"' ) === count( SFAF_Notifications::choosable() ), true );

/* Somebody chosen, an email off, a typed address. */
update_post_meta( $ev, SFAF_Reminders::NOTIFY_USERS_META, array( 21, 22 ) );
SFAF_Notifications::set_off( $ev, array( 'summary' ) );
$h = $card( $ev );
$who = $section( $h, 'Who hears about this event' ); $emails = $section( $h, 'Emails for this event' );
$total = count( SFAF_Reminders::notify_list( $ev ) );
rt( 'N: the people status line names what is chosen and the total', $status( $who, 'data-uc-picker-count' ), '2 individuals. ' . $total . ' ' . ( 1 === $total ? 'person' : 'people' ) . ' in total.' );
rt( 'N: the emails status line counts what is off', $status( $emails, 'data-uc-notify-kinds-status' ), '1 switched off, ' . ( count( SFAF_Notifications::kinds() ) - 1 ) . ' on.' );
rt( 'N: the emails panel opens with one off, as it did', (bool) preg_match( '#data-uc-disclosure open>#', $emails ), true );
rt( 'N: no empty state with people chosen', substr_count( $h, 'Nobody else yet.' ), 0 );
update_post_meta( $ev, SFAF_Reminders::NOTIFY_USERS_META, array() );
update_post_meta( $ev, SFAF_Reminders::NOTIFY_EMAILS_META, array( 'board@example.org' ) );
$h = $card( $ev );
$total = count( SFAF_Reminders::notify_list( $ev ) );
rt( 'N: a typed address alone gives the total, not the empty state', $status( $section( $h, 'Who hears about this event' ), 'data-uc-picker-count' ), $total . ' ' . ( 1 === $total ? 'person' : 'people' ) . ' in total.' );

/* Add event offers the same (CLAUDE.md 7): no post yet, everything empty. */
$add_card = $card( 0 );
rt( 'N: Add event has both, as Edit does', '' !== $section( $add_card, 'Who hears about this event' ) && '' !== $section( $add_card, 'Emails for this event' )
    && false !== strpos( $add_card, '<span>Choose people</span>' ) && false !== strpos( $add_card, '<span>Choose emails</span>' ), true );
rt( 'N: Add event says nobody else yet and all on', array( $status( $add_card, 'data-uc-picker-count' ), $status( $add_card, 'data-uc-notify-kinds-status' ) ),
    array( 'Nobody else yet.', 'All ' . count( SFAF_Notifications::kinds() ) . ' are on.' ) );

/* The script writes the same words. */
$js = (string) file_get_contents( $GLOBALS['kit_root'] . '/public/js/portal.js' );
rt( 'N: portal.js recounts the emails status in the same words', false !== strpos( $js, "status.textContent = off ? (off + ' switched off, ' + (total - off) + ' on.') : ('All ' + total + ' are on.');" ) && false !== strpos( $js, "run('notifyKinds', initNotifyKinds);" ), true );
rt( 'N: portal.js writes the empty state where the server does', false !== strpos( $js, "countEl.textContent = typedCount() ? inTotal : 'Nobody else yet.';" ), true );

echo "\nRelease 3.113.0: the dialogs and the Notifications card\n" . str_repeat( '=', 72 ) . "\n";
echo "ran $ran assertions.\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Cancel event and Delete open panels a dialog lifts, cancel asks which dates, and both Notifications rows are a subhead, a status line and a button.\n";
exit( 0 );
