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

if ( $fails ) {
    echo 'NOTIFICATIONS CARD: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "notifications card: Add event and Edit event draw the same card with the same fields, and a new event saves the recipients, the ticks and the reply-to.\n";
