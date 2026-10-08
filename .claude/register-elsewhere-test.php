<?php
/**
 * REGISTER ON ANOTHER SITE (3.110.1).
 *
 *     php .claude/register-elsewhere-test.php
 *
 * The real save_event_from_post() and sfaf_rsvp_block() on wp-kit.php, posting
 * what the Registration card posts.
 *
 *   C.2  with "Register on another site" chosen, Accept RSVPs, Email required,
 *        Capacity and Questions are ignored on save, whatever the form posted
 *        PLANT C.2: the settings saved while another site is chosen
 *   C.3  switching back to "Take registrations here" finds them as they were
 *   C.2  the event page's button opens the link in a new tab with rel noopener
 *        and the hidden "(opens in a new tab)"; no RSVP form, no waitlist,
 *        no agreement; a registration posted anyway is refused
 *   C.1  the series default mode and link are inherited until the event sets
 *        its own; another site with no link anywhere stays here
 *        an imported event keeps "Register on [platform]" and never asks
 */

require __DIR__ . '/wp-kit.php';
$GLOBALS['kit_organizers'] = true;
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }

$fails = array();
function re( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }

$GLOBALS['kit_query'] = function ( $a ) { $o = array(); foreach ( $GLOBALS['kit_posts'] as $id => $p ) { if ( 'uc_event' === $p->post_type ) { $o[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p; } } return $o; };
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$user   = new WP_User();

function re_save( $event_id, $extra ) {
    $_GET  = array();
    $_POST = array_merge( array(
        'event_id' => $event_id, 'save_mode' => 'publish', 'title' => 'Garden party', 'date' => '2026-12-05',
        'start_time' => '14:00', 'end_time' => '16:00', 'uc_organizer_present' => '1', 'organizer' => array( '11' ),
        'uc_category_present' => '1', 'category' => array( '11' ), 'description' => '<p>Bring a hat.</p>',
        'uc_online_present' => '1', 'uc_online' => '0', 'location_mode' => 'custom', 'location_street' => '1035 Market St', 'location_city' => 'San Francisco',
        'reg_mode_present' => '1', 'uc_rsvp_toggle_present' => '1', 'uc_email_required_present' => '1', 'show_rsvp' => '1',
    ), $extra );
    $GLOBALS['kit_redirect_throws'] = true;
    $r = kit_call( 'SFAF_Portal', 'save_event_from_post', $GLOBALS['portal'], array( $GLOBALS['user'] ) );
    $_POST = array();
    return $r;
}
$m = function ( $id, $k ) { return (string) get_post_meta( $id, $k, true ); };

/* ---- Take registrations here: the settings save. ------------------------- */
$r  = re_save( 0, array( 'reg_mode' => 'here', 'rsvp_enabled' => '1', 'email_required' => '1', 'capacity' => '25' ) );
$id = (int) $r['id'];
re( $id > 0, 'the event did not save at all: ' . json_encode( $r ) );
re( '1' === $m( $id, '_uc_rsvp_enabled' ) && '25' === $m( $id, '_uc_capacity' ) && '1' === $m( $id, '_uc_email_required' ),
    'here: the registration settings were not saved: ' . json_encode( array( $m( $id, '_uc_rsvp_enabled' ), $m( $id, '_uc_capacity' ), $m( $id, '_uc_email_required' ) ) ) );
re( false === SFAF_Register_Elsewhere::is_on( $id ) && 'here' === SFAF_Register_Elsewhere::mode( $id ), 'here: the event is not taking registrations here' );

/* ---- Another site: everything else is left alone. ------------------------- */
$r = re_save( $id, array( 'reg_mode' => 'elsewhere', 'reg_url' => 'https://www.eventbrite.com/e/garden-party', 'capacity' => '3' ) );
re( SFAF_Register_Elsewhere::is_on( $id ) && 'https://www.eventbrite.com/e/garden-party' === SFAF_Register_Elsewhere::url( $id ), 'elsewhere: the choice and the link were not stored' );
re( '1' === $m( $id, '_uc_rsvp_enabled' ) && '25' === $m( $id, '_uc_capacity' ) && '1' === $m( $id, '_uc_email_required' ),
    'PLANT C.2: RSVP settings were saved while another site is chosen: ' . json_encode( array( $m( $id, '_uc_rsvp_enabled' ), $m( $id, '_uc_capacity' ), $m( $id, '_uc_email_required' ) ) ) );

/* The RSVP list's own settings form goes through the same save. */
$_POST = array( 'uc_rsvp_toggle_present' => '1', 'capacity' => '9', 'uc_email_required_present' => '1' );
kit_call( 'SFAF_Portal', 'save_rsvp_settings_from_post', $portal, array( $user, $id, function () { return false; } ) );
$_POST = array();
re( '25' === $m( $id, '_uc_capacity' ) && '1' === $m( $id, '_uc_rsvp_enabled' ), 'PLANT C.2: the RSVP list\'s settings form saved while another site is chosen' );

/* ---- The event page. ------------------------------------------------------ */
$html = sfaf_rsvp_block( $id );
re( false !== strpos( $html, 'href="https://www.eventbrite.com/e/garden-party"' ) && false !== strpos( $html, 'target="_blank"' )
    && (bool) preg_match( '/rel="[^"]*noopener/', $html ) && false !== strpos( $html, '(opens in a new tab)' ),
    'C.2: the Register button does not open the link in a new tab with noopener and the hidden note: ' . $html );
re( false === strpos( $html, 'uc-rsvp-btn' ) && false === strpos( $html, 'Join the waitlist' ), 'C.2: the RSVP form button is still drawn' );
update_post_meta( $id, SFAF_Agreement::META_ON, '1' );
re( false === SFAF_Agreement::is_on( $id ), 'C.2: the agreement still asks on an event registering elsewhere' );
re( false === SFAF_Waitlist::applies( $id ), 'C.2: the waitlist still applies' );
re( false === sfaf_event_takes_rsvps( $id ), 'C.2: the event still says it takes registrations here' );
$res = ( new SFAF_RSVP() )->submit( array( 'event_id' => $id, 'first_name' => 'Ana', 'last_name' => '', 'email' => 'ana@example.org', 'phone' => '', 'optin' => false, 'format' => '', 'no_email' => false, 'agreed' => true ) );
re( empty( $res['success'] ), 'C.2: a registration posted anyway was accepted: ' . json_encode( $res ) );

/* ---- Back here: as they were. --------------------------------------------- */
re_save( $id, array( 'reg_mode' => 'here', 'rsvp_enabled' => '1', 'email_required' => '1', 'capacity' => '25' ) );
re( false === SFAF_Register_Elsewhere::is_on( $id ) && '25' === $m( $id, '_uc_capacity' ) && '1' === $m( $id, '_uc_rsvp_enabled' ), 'C.3: switching back did not find the settings as they were' );
re( false !== strpos( sfaf_rsvp_block( $id ), 'uc-rsvp-btn' ), 'C.3: switching back did not bring the RSVP button back' );

/* ---- Another site with no link stays here. -------------------------------- */
re_save( $id, array( 'reg_mode' => 'elsewhere', 'reg_url' => 'not a link', 'rsvp_enabled' => '1', 'capacity' => '25' ) );
re( false === SFAF_Register_Elsewhere::is_on( $id ), 'C.1: another site with no usable link stopped registrations here' );

/* ---- The series default. -------------------------------------------------- */
$S = 12;   // one of the kit's two series
$_POST = array( 'series_reg_mode_present' => '1', 'series_reg_mode' => 'elsewhere', 'series_reg_url' => 'https://tickets.example.org/series' );
SFAF_Register_Elsewhere::save_series_from_post( $S, $_POST );
$_POST = array();
$e2 = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Second party', 'post_author' => 1 ) );
update_post_meta( $e2, '_uc_rsvp_enabled', '1' );
wp_set_object_terms( $e2, array( $S ), 'uc_series' );
re( SFAF_Register_Elsewhere::is_on( $e2 ) && 'https://tickets.example.org/series' === SFAF_Register_Elsewhere::url( $e2 ), 'C.1: an event with nothing of its own did not inherit the series link' );
update_post_meta( $e2, SFAF_Register_Elsewhere::META_MODE, 'here' );
re( false === SFAF_Register_Elsewhere::is_on( $e2 ), 'C.1: an event that chose here still followed the series' );

/* ---- An imported event. --------------------------------------------------- */
$imp = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Imported', 'post_author' => 1 ) );
update_post_meta( $imp, SFAF_Sources::META_SOURCE, 'eventbrite' );
update_post_meta( $imp, SFAF_Sources::META_SOURCE_URL, 'https://www.eventbrite.com/e/imported' );
update_post_meta( $imp, SFAF_Register_Elsewhere::META_MODE, 'elsewhere' );
update_post_meta( $imp, SFAF_Register_Elsewhere::META_URL, 'https://elsewhere.example.org/' );
re( false === SFAF_Register_Elsewhere::is_on( $imp ), 'an imported event answered to the another-site choice' );
re( false !== strpos( sfaf_rsvp_block( $imp ), 'https://www.eventbrite.com/e/imported' ), 'an imported event lost its own Register at source button' );

if ( $fails ) {
    echo 'REGISTER ELSEWHERE: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "register elsewhere: the settings left alone on another site and found as they were on the way back; a new-tab Register button with noopener and the note; no form, waitlist or agreement, and a registration refused; the series default inherited; an imported event untouched.\n";
