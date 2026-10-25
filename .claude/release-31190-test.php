<?php
/**
 * 3.119.0: SERIES DEFAULTS FOR NEW EVENTS.
 *
 *     php .claude/release-31190-test.php
 *
 * The real plugin through wp-kit.php.
 *
 *   A  the series page draws New events (times, venue, cost, age range,
 *      contact), Notifications (teams, people, other addresses, the emails)
 *      and Display, inside the series form
 *   B  the save stores them under their marker, refusing what is not valid;
 *      a form that did not draw them changes nothing
 *   C  Add event's "Fill this event in from" carries them, and the series'
 *      own times and venue win over the most recent event's
 *   D  a request form's new event takes them into what it left empty, and
 *      nothing it filled in itself is overwritten
 *   E  not private, the PDF attachment or registrant questions
 *
 * Fill these in, in Chrome, is release-31190-live.php. PLANTs in plant-31190.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function rt_cap( $fn ) { ob_start(); try { $fn(); } catch ( Throwable $e ) { echo 'THREW ' . $e->getMessage() . ' ' . basename( $e->getFile() ) . ':' . $e->getLine(); } return (string) ob_get_clean(); }
$root   = $GLOBALS['kit_root'];
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$GLOBALS['kit_query'] = function ( $a ) { return array(); };
update_option( SFAF_Teams::OPTION, array( 'marcom' => array( 'name' => 'MarCom', 'users' => array( 1 ) ) ) );
$_GET = array();

/* ======================================================================== A */
$page = rt_cap( function () use ( $portal ) { kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( new WP_User(), 11 ) ); } );
$doc = new DOMDocument(); libxml_use_internal_errors( true ); $doc->loadHTML( '<?xml encoding="utf-8"?>' . $page ); $xp = new DOMXPath( $doc );
$in = function ( $card, $name ) use ( $xp ) { return $xp->query( "//form[@id='uc-series-form']//section[@data-uc-card='$card']//*[@name='$name']" )->length > 0; };
foreach ( array(
    'new-events'    => array( 'series_def_start_h', 'series_def_end_h', 'series_def_venue', 'series_def_cost', 'series_def_age', 'series_def_contact_name', 'series_def_contact_email', 'series_def_contact_phone' ),
    'notifications' => array( 'series_def_notify_teams[]', 'series_def_notify_emails', 'series_def_notify_kinds[]' ),
    'display'       => array( 'series_def_show_rsvp', 'series_def_show_calendar', 'series_def_show_social', 'series_def_show_reminders' ),
) as $card => $names ) {
    foreach ( $names as $n ) { rt( "A: $n is in the $card card of the series form", $in( $card, $n ), true ); }
}
rt( 'A: under one marker', 1 === substr_count( $page, 'name="uc_series_event_defaults_present"' ), true );

/* ======================================================================== B */
$_POST = array(
    'uc_series_event_defaults_present' => '1',
    'series_def_cost' => 'Free', 'series_def_age' => '18+', 'series_def_contact_name' => 'Ana', 'series_def_contact_email' => 'ana@sfaf.org', 'series_def_contact_phone' => '415',
    'series_def_start_h' => '18', 'series_def_start_m' => '30', 'series_def_end_h' => '20', 'series_def_end_m' => '00',
    'series_def_notify_teams' => array( 'marcom', 'nope' ), 'series_def_notify_users' => array( '7' ), 'series_def_notify_emails' => "pal@example.org\nnot an address",
    'series_def_notify_kinds' => array( 'day_before', 'summary', 'cancel_alert' ),
    'series_def_show_rsvp' => '1', 'series_def_show_social' => '1', 'series_def_show_reminders' => '1',
);
sfaf_normalize_time_post( array( 'series_def_start', 'series_def_end' ) );
SFAF_Series::save_event_defaults( 11, $_POST );
$d = SFAF_Series::event_defaults( 11 );
rt( 'B: cost, age, contact', array( $d['cost'], $d['age'], $d['contact_name'], $d['contact_email'], $d['contact_phone'] ), array( 'Free', '18+', 'Ana', 'ana@sfaf.org', '415' ) );
rt( 'B: the times', array( $d['start'], $d['end'] ), array( '18:30', '20:00' ) );
rt( 'B: only a team that exists', $d['notify_teams'], array( 'marcom' ) );
rt( 'B: people', $d['notify_users'], array( 7 ) );
rt( 'B: only real addresses', $d['notify_emails'], array( 'pal@example.org' ) );
rt( 'B: the email left unticked is off', $d['notify_off'], array( 'alert' ) );
rt( 'B: Add to calendar unticked is off', $d['display'], array( 'show_rsvp' => '1', 'show_calendar' => '0', 'show_social' => '1', 'show_reminders' => '1' ) );
SFAF_Series::save_event_defaults( 11, array( 'series_def_cost' => 'Changed' ) );
rt( 'B: a form that did not draw them changes nothing', SFAF_Series::event_defaults( 11 )['cost'], 'Free' );
SFAF_Series::save_event_defaults( 11, array_merge( $_POST, array( 'series_def_age' => 'Over 70' ) ) );
rt( 'B: an age range not on the list is none', SFAF_Series::event_defaults( 11 )['age'], '' );
SFAF_Series::save_event_defaults( 11, $_POST );
$src = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
rt( 'B: the series save stores them, new and edited', 2 === substr_count( $src, "SFAF_Series::save_event_defaults( " ) && 2 === substr_count( $src, "sfaf_normalize_time_post( array( 'series_def_start', 'series_def_end' ) );" ), true );

/* ======================================================================== C */
$last = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Last' ) );
update_post_meta( $last, '_uc_start_time', '10:00' );
update_post_meta( $last, '_uc_end_time', '11:00' );
$GLOBALS['kit_get_posts'] = function ( $a ) use ( $last ) { return array( $last ); };
$GLOBALS['kit_query'] = function ( $a ) use ( $last ) { return ( isset( $a['tax_query'] ) ) ? array( $last ) : array(); };
$p = SFAF_Series::prefill_data( 11 );
rt( 'C: the series\' times win over the last event\'s', array( $p['start_time'], $p['end_time'] ), array( '18:30', '20:00' ) );
rt( 'C: cost, age and contact ride along', array( $p['cost'], $p['age'], $p['contact']['name'] ), array( 'Free', '18+', 'Ana' ) );
rt( 'C: and the list, its emails and the display', array( $p['notify']['teams'], $p['notify_off'], $p['display']['show_calendar'] ), array( array( 'marcom' ), array( 'alert' ), '0' ) );
$js = file_get_contents( $root . '/public/js/portal.js' );
foreach ( array( 'cost', 'age', 'contact', 'notify', 'emails', 'display' ) as $k ) {
    rt( "C: Fill these in offers $k", false !== strpos( $js, "key: '$k', label: " ), true );
}

/* ======================================================================== D */
$e = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'pending', 'post_title' => 'From the form' ) );
update_post_meta( $e, SFAF_Submit::META_COST, '$10' );
update_post_meta( $e, '_uc_start_time', '09:00' );
update_post_meta( $e, SFAF_Reminders::NOTIFY_EMAILS_META, array( 'pat@sfaf.org' ) );
SFAF_Series::apply_event_defaults( $e, 11 );
rt( 'D: a cost the form gave is kept', get_post_meta( $e, SFAF_Submit::META_COST, true ), '$10' );
rt( 'D: a start time the form gave is kept', get_post_meta( $e, '_uc_start_time', true ), '09:00' );
rt( 'D: an empty end time takes the series\'', get_post_meta( $e, '_uc_end_time', true ), '20:00' );
rt( 'D: the age range and contact are taken', array( get_post_meta( $e, SFAF_Submit::META_AGE, true ), get_post_meta( $e, SFAF_Submit::META_CONTACT_NAME, true ) ), array( '18+', 'Ana' ) );
rt( 'D: the list gains the series\' address beside the requester', get_post_meta( $e, SFAF_Reminders::NOTIFY_EMAILS_META, true ), array( 'pat@sfaf.org', 'pal@example.org' ) );
rt( 'D: and its people and team', array( get_post_meta( $e, SFAF_Reminders::NOTIFY_USERS_META, true ), SFAF_Teams::for_event( $e ) ), array( array( 7 ), array( 'marcom' ) ) );
rt( 'D: its emails, with none of its own', get_post_meta( $e, SFAF_Notifications::OFF_META, true ), array( 'alert' ) );
rt( 'D: and the display', get_post_meta( $e, '_uc_show_calendar', true ), '0' );
$e2 = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'pending', 'post_title' => 'Staff yes' ) );
SFAF_Notifications::set_off( $e2, array( 'alert', 'cancel_alert' ) );
update_post_meta( $e2, '_uc_show_calendar', '1' );
SFAF_Series::apply_event_defaults( $e2, 11 );
rt( 'D: emails a form set are kept', get_post_meta( $e2, SFAF_Notifications::OFF_META, true ), array_values( array_intersect( SFAF_Notifications::choosable(), array( 'alert', 'cancel_alert' ) ) ) );
rt( 'D: and a display tick it set', get_post_meta( $e2, '_uc_show_calendar', true ), '1' );
foreach ( array( 'includes/class-sfaf-request.php' => "SFAF_Series::apply_event_defaults( \$event_id, (int) \$c['series'] );", 'includes/class-sfaf-submit.php' => "SFAF_Series::apply_event_defaults( \$event_id, (int) \$series->term_id );" ) as $rel => $call ) {
    rt( "D: $rel applies them", false !== strpos( file_get_contents( $root . '/' . $rel ), $call ), true );
}

/* ======================================================================== E */
rt( 'E: no private, attachment or questions among them', array_intersect( array_keys( SFAF_Series::event_defaults( 11 ) ), array( 'private', 'attachment', 'questions' ) ), array() );

echo "\n3.119.0: series defaults for new events\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. The series page sets what new events start with; Add event offers it in Fill these in, the\nseries' times and venue first; a request form's event takes it into what it left empty.\n";
exit( 0 );
