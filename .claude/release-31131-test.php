<?php
/**
 * 3.113.1, THE PARTS A TEST CAN SETTLE WITHOUT A BROWSER.
 *
 *     php .claude/release-31131-test.php
 *
 * Through wp-kit.php and the real routes.
 *
 *   1  Pending's Dismissed fold: SUPERSEDED in 3.113.2 by the Rejected and
 *      dismissed tab, so only its absence is checked here
 *   2  the Approve dialog offers "Send ... registrations" only when the event
 *      takes RSVPs, and the route ignores the tick on one that does not
 *   3  the confirmation and the morning-of reminder are always sent: no tick,
 *      "Always sent", an old "off" ignored, never stored again; the other four
 *      keep their ticks and the status line counts them
 *   4  + Add category and + Add organizer carry a chevron, and portal.css
 *      turns it and presses the button on aria-expanded="true"
 *   5  the event page's contact email is a mailto link, escaped
 *   6  no "Pick up to" and no limit on how many teams are kept
 *
 * The public calendar is embed-identity-test.php's. The geometry of 1 and 4
 * is release-31131-live.php's. PLANTs in plant-31131.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( $to, $s, $b, $h = '', $a = array() ) { return true; } }

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . json_encode( $got ) . ', wanted ' . json_encode( $want ); }
}
function rt_cap( $fn ) { ob_start(); try { $fn(); } catch ( Throwable $t ) { ob_end_clean(); return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); } return ob_get_clean(); }
function rt_query( $a ) {
    $status = isset( $a['post_status'] ) ? (array) $a['post_status'] : array( 'publish' );
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' !== $p->post_type ) { continue; }
        if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
        $out[] = $p;
    }
    return ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? wp_list_pluck( $out, 'ID' ) : $out;
}
$GLOBALS['kit_query'] = 'rt_query'; $GLOBALS['kit_get_posts'] = 'rt_query';
$GLOBALS['kit_redirect_throws'] = true;
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function rt_event( $status = 'publish' ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Support group', 'post_author' => 1, 'post_content' => '<p>Weekly.</p>' ) );
    foreach ( array( '_uc_event_date' => date( 'Y-m-d', time() + 9 * 86400 ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    /* What the publish rule asks for, so Approve can approve. */
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

/* ======================================================================== 1 */
/* SUPERSEDED BY 3.113.2: Dismissed is a tab on Pending, "Rejected and
   dismissed", and the fold is gone. release-31132-test.php checks the tab. */
rt( '1: the fold is gone (3.113.2)', false === strpos( (string) file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' ), 'uc-dismissed-fold' ), true );

/* ======================================================================== 2 */
$sub = rt_event( 'pending' );
update_post_meta( $sub, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_COMMUNITY );
update_post_meta( $sub, SFAF_Request::META_EMAIL, 'pat@example.org' );
update_post_meta( $sub, SFAF_Request::META_NAME, 'Pat' );
$who = SFAF_Submissions::submitter( $sub );
rt( '2: the event is a submission with an address', ! empty( $who['is_submission'] ) && ! empty( $who['usable'] ), true );
$ask = function () use ( $portal, $sub, $who ) { return rt_cap( function () use ( $portal, $sub, $who ) { kit_call( 'SFAF_Portal', 'render_approve_ask', $portal, array( $sub, $who ) ); } ); };

update_post_meta( $sub, '_uc_rsvp_enabled', '0' );
$off = $ask();
rt( '2: RSVP off: the dialog draws', false !== strpos( $off, 'Submitted by' ), true );
rt( '2: RSVP off: no registrations tick', false === strpos( $off, 'name="notify_submitter"' ) && false === strpos( $off, 'registrations for this event' ), true );
rt( '2: RSVP off: and no note about them', false === strpos( $off, 'They get an email each time somebody registers' ), true );
rt( '2: RSVP off: the published notice tick stays', false !== strpos( $off, 'name="tell_submitter"' ), true );
update_post_meta( $sub, '_uc_rsvp_enabled', '1' );
$on = $ask();
rt( '2: RSVP on: the tick, ticked', (bool) preg_match( '#<input type="checkbox" name="notify_submitter" value="1" checked#', $on ), true );
rt( '2: RSVP on: named', false !== strpos( $on, 'Send Pat registrations for this event' ), true );

/* The route: the tick posted on an RSVP-off event adds nobody. */
update_post_meta( $sub, '_uc_rsvp_enabled', '0' );
$GLOBALS['kit_caps']['manage_options'] = true;
$r = rt_post( 'approve_event', array( 'event_id' => $sub, 'notify_submitter' => '1' ) );
rt( '2: RSVP off: Approve approves', get_post( $sub )->post_status, 'publish' );
rt( '2: RSVP off: and the posted tick lists nobody', array_values( array_filter( (array) get_post_meta( $sub, SFAF_Reminders::NOTIFY_EMAILS_META, true ) ) ), array() );
$sub2 = rt_event( 'pending' );
update_post_meta( $sub2, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_COMMUNITY );
update_post_meta( $sub2, SFAF_Request::META_EMAIL, 'pat@example.org' );
update_post_meta( $sub2, SFAF_Request::META_NAME, 'Pat' );
update_post_meta( $sub2, '_uc_rsvp_enabled', '1' );
rt_post( 'approve_event', array( 'event_id' => $sub2, 'notify_submitter' => '1' ) );
rt( '2: RSVP on: the tick lists the submitter', (array) get_post_meta( $sub2, SFAF_Reminders::NOTIFY_EMAILS_META, true ), array( 'pat@example.org' ) );

/* ======================================================================== 3 */
rt( '3: the two always sent', SFAF_Notifications::ALWAYS, array( 'confirmation', 'reminder' ) );
rt( '3: the four with ticks', SFAF_Notifications::choosable(), array( 'alert', 'day_before', 'summary', 'cancel_alert' ) );
$ev = rt_event();
update_post_meta( $ev, SFAF_Notifications::OFF_META, array( 'confirmation', 'reminder' ) );   // stored before 3.113.1
rt( '3: an old "off" on the confirmation is ignored', SFAF_Notifications::on( $ev, 'confirmation' ), true );
rt( '3: and on the reminder', SFAF_Notifications::on( $ev, 'reminder' ), true );
rt( '3: and not counted', SFAF_Notifications::off_count( $ev ), 0 );
SFAF_Notifications::set_off( $ev, array( 'reminder', 'summary' ) );
rt( '3: set_off stores only a choosable kind', get_post_meta( $ev, SFAF_Notifications::OFF_META, true ), array( 'summary' ) );
rt( '3: the summary is off', SFAF_Notifications::on( $ev, 'summary' ), false );
update_post_meta( $ev, SFAF_Notifications::OFF_META, array( 'reminder', 'summary' ) );
$card = rt_cap( function () use ( $portal, $ev ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_notify_box', $portal, array( new WP_User(), $ev ) ); } );
rt( '3: the card rendered', 0 === strpos( $card, 'THREW' ) ? $card : 'ok', 'ok' );
rt( '3: four ticks', substr_count( $card, 'name="notify_kinds[]"' ), 4 );
foreach ( array( 'confirmation', 'reminder' ) as $k ) {
    rt( "3: $k has no tick", false === strpos( $card, 'name="notify_kinds[]" value="' . $k . '"' ), true );
    rt( "3: $k is listed as always sent", (bool) preg_match( '#data-uc-notify-always="' . $k . '">\s*[^<]+<span class="uc-pill uc-notify-always-tag">Always sent</span>#', $card ), true );
}
rt( '3: the labels are the kinds\' own', false !== strpos( $card, 'Confirmation to the person who registers' ) && false !== strpos( $card, 'Morning-of reminder to everybody registered' ), true );
rt( '3: the status line counts one off of six', preg_match( '#data-uc-notify-kinds-status[^>]*>([^<]*)<#', $card, $m ) ? $m[1] : null, '1 switched off, 5 on.' );
/* The save: the form offers four, so the always two cannot be stored off. */
$_POST = array( 'uc_notify_kinds_present' => '1', 'notify_kinds' => array( 'alert' ) );
$offered = SFAF_Notifications::choosable();
SFAF_Notifications::set_off( $ev, array_diff( $offered, array( 'alert' ) ) );
rt( '3: unticking all but one stores three off', get_post_meta( $ev, SFAF_Notifications::OFF_META, true ), array( 'day_before', 'summary', 'cancel_alert' ) );
rt( '3: and the save route asks choosable()', false !== strpos( (string) file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' ), "\$offered = SFAF_Notifications::choosable();" ), true );
$js = (string) file_get_contents( $GLOBALS['kit_root'] . '/public/js/portal.js' );
rt( '3: portal.js writes the same words', false !== strpos( $js, "(off + ' switched off, ' + (total - off) + ' on.')" ), true );

/* ======================================================================== 4 */
$form = rt_cap( function () use ( $portal ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), 0 ) ); } );
foreach ( array( 'category', 'organizer' ) as $w ) {
    rt( "4: + Add $w carries a chevron", (bool) preg_match( '#data-uc-chips-toggle\s+aria-expanded="false"><span>\+ Add ' . $w . '</span>\s*<span class="uc-disclosure-chevron" aria-hidden="true">#', $form ), true );
}
$css = (string) file_get_contents( $GLOBALS['kit_root'] . '/public/css/portal.css' );
rt( '4: the chevron turns on aria-expanded', false !== strpos( $css, '.uc-chips-add .uc-btn[aria-expanded="true"] .uc-disclosure-chevron { transform: rotate(-90deg); }' ), true );
rt( '4: and the button is pressed', false !== strpos( $css, ".uc-chips-add .uc-btn[aria-expanded=\"true\"] {\n    background: var(--p-btn-face-hover); border-color: var(--uc-accent-text);\n    box-shadow: inset 0 2px 3px rgba(55, 52, 51, 0.22); }" ), true );
rt( '4: portal.js still sets aria-expanded', false !== strpos( $js, "toggle.setAttribute('aria-expanded', open ? 'true' : 'false');" ), true );

/* ======================================================================== 5 */
$c = rt_event();
update_post_meta( $c, SFAF_Submit::META_CONTACT_NAME, 'Sam <b>Lee</b>' );
update_post_meta( $c, SFAF_Submit::META_CONTACT_EMAIL, 'sam@example.org' );
update_post_meta( $c, SFAF_Submit::META_CONTACT_PHONE, '415-555-0100' );
rt( '5: the three fields, the email linked, the rest escaped', sfaf_event_public_contact_html( $c ),
    'Sam &lt;b&gt;Lee&lt;/b&gt;, <a href="mailto:sam@example.org">sam@example.org</a>, 415-555-0100' );
rt( '5: the plain line for the emails is unchanged', sfaf_event_public_contact( $c ), 'Sam <b>Lee</b>, sam@example.org, 415-555-0100' );
$c2 = rt_event();
update_post_meta( $c2, SFAF_Submit::META_CONTACT, 'info@example.org' );
rt( '5: the 3.46.0 box that is one address is linked', sfaf_event_public_contact_html( $c2 ), '<a href="mailto:info@example.org">info@example.org</a>' );
$c3 = rt_event();
update_post_meta( $c3, SFAF_Submit::META_CONTACT, 'Call the front desk' );
rt( '5: a box with no address is text', sfaf_event_public_contact_html( $c3 ), 'Call the front desk' );
rt( '5: no contact is nothing', sfaf_event_public_contact_html( rt_event() ), '' );
$tpl = (string) file_get_contents( $GLOBALS['kit_root'] . '/templates/single-uc_event.php' );
rt( '5: the event page prints it', false !== strpos( $tpl, '<span class="uc-fact-label">Contact</span> <?php echo sfaf_event_public_contact_html( $post_id ); ?>' ), true );

/* ======================================================================== 6 */
$src = (string) file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' );
rt( '6: no "Pick up to"', false === strpos( $src, 'Pick up to' ) && false === strpos( $src, 'data-uc-access-max' ), true );
rt( '6: no limit left in the plugin', defined( 'SFAF_Teams::MAX_PER_EVENT' ), false );
$GLOBALS['kit_options'][ SFAF_Teams::OPTION ] = array(
    'a' => array( 'id' => 'a', 'name' => 'A', 'users' => array() ),
    'b' => array( 'id' => 'b', 'name' => 'B', 'users' => array() ),
    'c' => array( 'id' => 'c', 'name' => 'C', 'users' => array() ),
);
$t = rt_event();
SFAF_Teams::set_access_for_event( $t, array( 'a', 'b', 'c', 'a' ) );
rt( '6: three teams are kept, a repeat once', SFAF_Teams::access_for_event( $t ), array( 'a', 'b', 'c' ) );

echo "\nRelease 3.113.1\n" . str_repeat( '=', 72 ) . "\n";
echo "ran $ran assertions.\n";
if ( $fails ) { echo count( $fails ) . " failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "Dismissed is a tab now (3.113.2), Approve asks about registrations only with RSVPs, two emails always go, the add buttons show their state, the contact email links and teams have no limit.\n";
exit( 0 );
