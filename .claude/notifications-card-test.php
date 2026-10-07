<?php
/**
 * THE NOTIFICATIONS CARD ON ADD EVENT AND EDIT EVENT (3.106.2).
 *
 *     php .claude/notifications-card-test.php
 *
 * The real render_event_form() and save_event_from_post() on wp-kit.php.
 *
 *   both screens draw a card headed Notifications, and it holds the same
 *   fields by name: the creator line, the recipient picker, anyone else, the
 *   message ticks and the reply-to; on Add event the creator is whoever is
 *   adding it; a new event saved from Add event carries the chosen recipients,
 *   the creator's opt-out, the extra address and the ticks.
 */

require __DIR__ . '/wp-kit.php';

$fails  = array();
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function nc( $got, $want, $why ) {
    global $fails;
    if ( $got !== $want ) { $fails[] = $why . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function nc_capture( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $depth ) { ob_end_clean(); } return 'THREW ' . $t->getMessage(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}
/** The card headed Notifications: its heading and every field name in it. */
function nc_card( $html ) {
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    $x = new DOMXPath( $doc );
    $cards = $x->query( '//section[@data-uc-notifications-card]' );
    if ( 1 !== $cards->length ) { return array( 'count' => $cards->length ); }
    $card = $cards->item( 0 );
    $names = array();
    foreach ( $x->query( './/input[@name] | .//select[@name] | .//textarea[@name]', $card ) as $f ) {
        $names[] = $f->getAttribute( 'name' );
    }
    $names = array_values( array_unique( $names ) );
    sort( $names );
    return array(
        'count'   => 1,
        'heading' => trim( $x->query( './/h2', $card )->item( 0 )->textContent ),
        'names'   => $names,
        'text'    => preg_replace( '/\s+/', ' ', $card->textContent ),
    );
}

kit_reset();
$user = new WP_User();
$add  = nc_card( nc_capture( function () use ( $portal, $user ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $user, 0 ) ); } ) );
$id   = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Coffee social', 'post_author' => $user->ID ) );
update_post_meta( $id, '_uc_event_date', '2026-11-20' );
$edit = nc_card( nc_capture( function () use ( $portal, $user, $id ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $user, $id ) ); } ) );

nc( $add['count'], 1, 'PLANT D: Add event draws the Notifications card' );
nc( $edit['count'], 1, 'Edit event draws it' );
nc( isset( $add['heading'] ) ? $add['heading'] : '', 'Notifications', 'headed Notifications on Add event' );
nc( isset( $edit['heading'] ) ? $edit['heading'] : '', 'Notifications', 'and on Edit event' );
nc( isset( $add['names'] ) ? $add['names'] : array(), isset( $edit['names'] ) ? $edit['names'] : array( 'edit not drawn' ), 'PLANT D: the two screens render the same fields' );
// notify_users[] and notify_emails[] are drawn only when there are people and addresses to tick.
foreach ( array( 'notify_list_present', 'notify_author', 'notify_email_new', 'uc_notify_kinds_present', 'notify_kinds[]', 'event_replyto' ) as $must ) {
    nc( in_array( $must, isset( $add['names'] ) ? $add['names'] : array(), true ), true, "Add event's card has $must" );
}
nc( isset( $add['text'] ) && false !== strpos( $add['text'], 'Send to ' . $user->display_name ), true, 'on Add event the creator line names whoever is adding it' );

/* A new event saved from Add event carries what was chosen. */
$_GET  = array();
$_POST = array(
    'event_id' => 0, 'save_mode' => 'publish', 'title' => 'Support group', 'date' => '2026-12-03',
    'start_time' => '10:00', 'end_time' => '11:30', 'uc_organizer_present' => '1', 'uc_category_present' => '1',
    'description' => '', 'uc_online_present' => '1', 'uc_online' => '0', 'location_mode' => 'custom',
    'location_street' => '1035 Market St', 'location_city' => 'San Francisco',
    'notify_list_present' => '1', 'notify_users' => array( '7' ), 'notify_emails' => array( 'cohost@example.org' ),
    'uc_notify_kinds_present' => '1', 'notify_kinds' => array( 'confirmation', 'alert' ),
    'event_replyto' => 'events@example.org',
);
$GLOBALS['kit_redirect_throws'] = true;
$r   = kit_call( 'SFAF_Portal', 'save_event_from_post', $portal, array( $user ) );
$new = (int) $r['id'];
nc( $new > 0, true, 'the new event is saved' );
nc( array_map( 'intval', (array) get_post_meta( $new, SFAF_Reminders::NOTIFY_USERS_META, true ) ), array( 7 ), 'PLANT D: it carries the chosen recipient' );
nc( (array) get_post_meta( $new, SFAF_Reminders::NOTIFY_EMAILS_META, true ), array( 'cohost@example.org' ), 'and the extra address' );
nc( SFAF_Reminders::author_opted_out( $new ), true, 'and the creator, unticked, is off the list' );
nc( SFAF_Notifications::on( $new, 'alert' ) && ! SFAF_Notifications::on( $new, 'reminder' ), true, 'and the message ticks as chosen' );
nc( (string) get_post_meta( $new, '_uc_email_replyto', true ), 'events@example.org', 'and the reply-to' );

/* ===========================================================================
 * THE WHOLE PAGE, NOT ONLY THIS CARD (3.109.0).
 *
 * CLAUDE.md 7: every setting or option that shapes the event is on Add event
 * as well as Edit event. Actions on a saved event may be Edit-only, and each
 * carries data-uc-saved-action naming which; the series prefill is Add-only
 * and carries data-uc-add-only. Those names are the whole allow-list. Every
 * other control in the content column has to be on both screens, compared by
 * the card it is in and its kind, name and value, or a button's or link's words.
 *
 * Compared with an existing draft, whose save buttons are Add event's, and
 * with a published event, whose Save changes stands in for Save draft and
 * Publish; on that one the save buttons are left out and Save changes is
 * required instead. Both events are in a series and FAQ sets exist, so every
 * conditional control that the page can draw is drawn.
 * ======================================================================== */
$SAVED_ACTIONS = array( 'manage-series', 'edit-schedule', 'cancel', 'delete', 'faq-form', 'access', 'rsvp-list', 'private-link' );
$ADD_ONLY      = array( 'series-prefill' );

function nc_controls( $html, $skip_attr, $skip_saves = false ) {
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    $x    = new DOMXPath( $doc );
    $main = $x->query( '//*[contains(concat(" ", normalize-space(@class), " "), " uc-portal-content ")]' )->item( 0 );
    if ( ! $main ) { return array( 'keys' => array( 'NO CONTENT COLUMN' ), 'marks' => array() ); }
    $keys = array(); $marks = array();
    foreach ( $x->query( './/*[@data-uc-saved-action] | .//*[@data-uc-add-only]', $main ) as $m ) {
        $marks[] = $m->hasAttribute( 'data-uc-saved-action' ) ? 'saved:' . $m->getAttribute( 'data-uc-saved-action' ) : 'add:' . $m->getAttribute( 'data-uc-add-only' );
    }
    foreach ( $x->query( './/input|.//select|.//textarea|.//button|.//a|.//summary', $main ) as $n ) {
        $card = ''; $skip = false;
        for ( $p = $n; $p && XML_ELEMENT_NODE === $p->nodeType; $p = $p->parentNode ) {
            if ( '' !== $skip_attr && $p->hasAttribute( $skip_attr ) ) { $skip = true; break; }
            if ( '' === $card && $p->hasAttribute( 'data-uc-card' ) ) { $card = $p->getAttribute( 'data-uc-card' ); }
        }
        if ( $skip ) { continue; }
        $tag  = strtolower( $n->nodeName );
        $type = $n->getAttribute( 'type' );
        // The save buttons, and the schedule beside Publish (3.110.0), follow the event's status.
        if ( $skip_saves && ( 'save_mode' === $n->getAttribute( 'name' ) || 0 === strpos( $n->getAttribute( 'name' ), 'schedule_' ) ) ) { continue; }
        if ( in_array( $tag, array( 'input', 'select', 'textarea' ), true ) ) {
            $key = $tag . '[' . $type . '] ' . $n->getAttribute( 'name' ) . ( in_array( $type, array( 'radio', 'checkbox' ), true ) ? '=' . $n->getAttribute( 'value' ) : '' );
        } else {
            $key = $tag . ' "' . trim( preg_replace( '/\s+/', ' ', $n->textContent ) ) . '"';
        }
        $keys[ $card . ' | ' . $key ] = true;
    }
    return array( 'keys' => array_keys( $keys ), 'marks' => array_values( array_unique( $marks ) ) );
}

kit_reset();
update_option( SFAF_FAQ_Sets::OPTION, array( 'set1' => array( 'name' => 'Alpha basics', 'rows' => array( array( 'question' => 'Is it free?', 'answer' => 'Yes.' ) ) ) ) );
$GLOBALS['kit_query'] = function ( $a ) { $o = array(); foreach ( $GLOBALS['kit_posts'] as $pid => $p ) { if ( ( isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event' ) === $p->post_type ) { $o[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $pid : $p; } } return $o; };
$pages = array();
foreach ( array( 'draft', 'publish' ) as $st ) {
    $eid = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $st, 'post_title' => 'Dinner ' . $st, 'post_author' => $user->ID ) );
    foreach ( array( '_uc_event_date' => '2026-11-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_rsvp_enabled' => '1' ) as $k => $v ) { update_post_meta( $eid, $k, $v ); }
    $GLOBALS['kit_terms'][ $eid ]['uc_series'] = array( 11 );
    $pages[ $st ] = $eid;
}
$_GET = array();
$add_html = nc_capture( function () use ( $portal, $user ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $user, 0 ) ); } );
foreach ( $pages as $st => $eid ) {
    $edit_html = nc_capture( function () use ( $portal, $user, $eid ) { kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $user, $eid ) ); } );
    $saves     = ( 'publish' === $st );
    $a = nc_controls( $add_html, 'data-uc-add-only', $saves );
    $e = nc_controls( $edit_html, 'data-uc-saved-action', $saves );
    $edit_only = array_values( array_diff( $e['keys'], $a['keys'] ) );
    $add_only  = array_values( array_diff( $a['keys'], $e['keys'] ) );
    nc( $edit_only, array(), "PLANT B.3: on Edit event ($st) and not on Add event, outside the saved-event actions" );
    nc( $add_only, array(), "PLANT B.3: on Add event and not on Edit event ($st), outside the series prefill" );
    foreach ( array_merge( $a['marks'], $e['marks'] ) as $mark ) {
        list( $kind, $name ) = explode( ':', $mark, 2 );
        nc( in_array( $name, 'saved' === $kind ? $SAVED_ACTIONS : $ADD_ONLY, true ), true, "PLANT B.3: \"$mark\" is not an action CLAUDE.md lets differ" );
    }
    nc( in_array( 'saved:cancel', $e['marks'], true ) && in_array( 'saved:delete', $e['marks'], true ) && in_array( 'saved:access', $e['marks'], true ), true,
        "Edit event ($st) carries its Cancel, Delete and Who can edit markers, so the exclusion above is excluding something: " . json_encode( $e['marks'] ) );
    if ( $saves ) {
        nc( false !== strpos( $edit_html, '>Save changes</button>' ), true, 'a published event saves with Save changes' );
    }
}

if ( $fails ) {
    echo 'NOTIFICATIONS CARD: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "notifications card: Add event and Edit event draw the same card with the same fields, and a new event saves the recipients, the ticks and the reply-to. The whole page: every control on one editor is on the other, outside the saved-event actions and the series prefill (3.109.0).\n";
