<?php
/**
 * 3.113.3, THE PARTS A TEST CAN SETTLE WITHOUT A BROWSER.
 *
 *     php .claude/release-31133-test.php
 *
 * Through wp-kit.php and the real routes and renderers.
 *
 *   1  the RSVP card: one choice first in the card, No RSVP | RSVP Here |
 *      External RSVP, no Accept RSVPs tick and no "Where people register";
 *      an existing event opens on the choice that matches what is stored,
 *      and a save of that same choice changes nothing stored; each choice
 *      saves the two answers it stands for; a hybrid event cannot be No RSVP
 *   2  Edit event's bar: Delete a red text link at the far left, Cancel event
 *      outlined, Save changes the one solid button
 *   3  the event page's flyer: the first page and a solid "View flyer (PDF)",
 *      or the card with the same button; never in calendar.css
 *   4  the Notifications card's helper line and the four list emails, word
 *      for word; the creator line not shown for a system account
 *   5  a staff request is "Requested by [name] on [date]", the name a mailto,
 *      with what else they asked under it; a community submission keeps its panel
 *
 * The public calendar is embed-identity-test.php's. PLANTs in plant-31133.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( $to, $s, $b, $h = '', $a = array() ) { return true; } }
/* WordPress's own media lookups, core's logic and not a stub (3.113.4). */
require __DIR__ . '/core-media.php';

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
$GLOBALS['kit_caps']['manage_options'] = true;
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$root   = $GLOBALS['kit_root'];

function rt_event( $status = 'publish', $meta = array(), $author = 1 ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Support group', 'post_author' => $author, 'post_content' => '<p>Weekly.</p>' ) );
    foreach ( array_merge( array( '_uc_event_date' => date( 'Y-m-d', time() + 9 * 86400 ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_location' => '1035 Market Street' ), $meta ) as $k => $v ) {
        update_post_meta( $id, $k, $v );
    }
    $GLOBALS['kit_terms'][ $id ]['uc_event_category'] = array( 1 );
    $GLOBALS['kit_terms'][ $id ]['uc_organizer']      = array( 1 );
    return $id;
}
function rt_edit( $id ) {
    global $portal;
    return rt_cap( function () use ( $portal, $id ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( new WP_User(), $id ) ); } );
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
/* The RSVP card, from its opening tag to its close. */
function rt_card( $html ) {
    return preg_match( '#<section class="uc-bento-card uc-registration-card".*?</section>#s', $html, $m ) ? $m[0] : '';
}
function rt_checked( $card ) {
    return preg_match( '#<input type="radio" name="reg_choice" value="([a-z]+)" data-uc-reg-mode\s+checked#', $card, $m ) ? $m[1] : '';
}

/* ======================================================================== 1 */
$add  = rt_edit( 0 );
$card = rt_card( $add );
rt( '1: Add event renders', 0 === strpos( $add, 'THREW' ) ? $add : 'ok', 'ok' );
rt( '1: the card is titled RSVP', (bool) preg_match( '#<h2 class="uc-bento-title">RSVP</h2>#', $card ), true );
preg_match_all( '#<input type="radio" name="reg_choice" value="([a-z]+)"[^>]*/>\s*<span>([^<]+)</span>#', $card, $opts );
rt( '1: three choices, in order', array_combine( $opts[1], $opts[2] ), array( 'none' => 'No RSVP', 'here' => 'RSVP Here', 'elsewhere' => 'External RSVP' ) );
$first_input = preg_match( '#<(input|select|textarea)\b[^>]*name="([^"]+)"#', substr( $card, strpos( $card, '</h2>' ) ), $fm ) ? $fm[2] : '';
rt( '1: the choice is first in the card', $first_input, 'reg_choice_present' );
rt( '1: no Accept RSVPs tick anywhere on the form', false === strpos( $add, 'name="rsvp_enabled"' ) && false === strpos( $add, 'Accept RSVPs' ), true );
rt( '1: no "Where people register"', false === strpos( $add, 'Where people register' ) && false === strpos( $add, 'Take registrations here' ), true );
rt( '1: a new event starts on No RSVP', rt_checked( $card ), 'none' );
rt( '1: a segmented control, the existing component', false !== strpos( $card, '<div class="uc-seg" role="radiogroup" aria-label="RSVP">' ), true );
rt( '1: the link box is hidden until External RSVP', (bool) preg_match( '#<label class="uc-field uc-reg-url" for="uc-reg-url" data-uc-reg-url hidden>#', $card ), true );

/* Existing events open on the matching choice. */
$ev_on   = rt_event( 'publish', array( '_uc_rsvp_enabled' => '1', '_uc_capacity' => '40' ) );
$ev_off  = rt_event( 'publish', array( '_uc_rsvp_enabled' => '0' ) );
$ev_away = rt_event( 'publish', array( '_uc_rsvp_enabled' => '1', SFAF_Register_Elsewhere::META_MODE => 'elsewhere', SFAF_Register_Elsewhere::META_URL => 'https://example.org/tickets' ) );
$ev_hyb  = rt_event( 'publish', array( '_uc_rsvp_enabled' => '0', SFAF_Online::META_HYBRID => '1' ) );
$c_on   = rt_card( rt_edit( $ev_on ) );
$c_off  = rt_card( rt_edit( $ev_off ) );
$c_away = rt_card( rt_edit( $ev_away ) );
$c_hyb  = rt_card( rt_edit( $ev_hyb ) );
rt( '1: RSVPs on opens on RSVP Here', rt_checked( $c_on ), 'here' );
rt( '1: RSVPs off opens on No RSVP', rt_checked( $c_off ), 'none' );
rt( '1: another site opens on External RSVP', rt_checked( $c_away ), 'elsewhere' );
rt( '1: and shows its link, unhidden', (bool) preg_match( '#data-uc-reg-url>\s*<span class="uc-field-label">Registration link</span>\s*<input type="url" id="uc-reg-url" name="reg_url" value="https://example.org/tickets"#', $c_away ), true );
rt( '1: and hides the RSVP settings', (bool) preg_match( '#<div class="uc-reg-here" data-uc-reg-here hidden>#', $c_away ), true );
rt( '1: RSVP Here shows the RSVP settings', (bool) preg_match( '#<div class="uc-reg-here" data-uc-reg-here>#', $c_on ) && false !== strpos( $c_on, 'name="capacity"' ), true );
rt( '1: a hybrid event opens on RSVP Here', rt_checked( $c_hyb ), 'here' );
rt( '1: with No RSVP disabled and saying why', (bool) preg_match( '#value="none" data-uc-reg-mode\s+disabled />#', $c_hyb ) && (bool) preg_match( '#<span class="uc-hint uc-hint-spec" data-uc-rsvp-hybrid-note>No RSVP is not available for a hybrid event\.</span>#', $c_hyb ), true );

/* Saving the choice an event opened on changes nothing stored. */
$saved = rt_save( $ev_on, array( 'reg_choice_present' => '1', 'reg_choice' => 'here', 'capacity' => '40', 'uc_capacity_present' => '1' ) );
rt( '1: saving RSVP Here saves, with no refusal', $saved, '' );
rt( '1: and RSVPs stay on, no mode stored', array( get_post_meta( $ev_on, '_uc_rsvp_enabled', true ), get_post_meta( $ev_on, SFAF_Register_Elsewhere::META_MODE, true ) ), array( '1', '' ) );
rt_save( $ev_off, array( 'reg_choice_present' => '1', 'reg_choice' => 'none' ) );
rt( '1: saving No RSVP keeps it off', get_post_meta( $ev_off, '_uc_rsvp_enabled', true ), '0' );
rt_save( $ev_away, array( 'reg_choice_present' => '1', 'reg_choice' => 'elsewhere', 'reg_url' => 'https://example.org/tickets' ) );
rt( '1: saving External RSVP keeps the link, the mode and the stored RSVP answer', array(
    get_post_meta( $ev_away, SFAF_Register_Elsewhere::META_MODE, true ),
    get_post_meta( $ev_away, SFAF_Register_Elsewhere::META_URL, true ),
    get_post_meta( $ev_away, '_uc_rsvp_enabled', true ),
), array( 'elsewhere', 'https://example.org/tickets', '1' ) );

/* Each choice saves what it stands for. */
rt_save( $ev_on, array( 'reg_choice_present' => '1', 'reg_choice' => 'none' ) );
rt( '1: No RSVP switches RSVPs off', get_post_meta( $ev_on, '_uc_rsvp_enabled', true ), '0' );
rt( '1: and keeps the capacity', get_post_meta( $ev_on, '_uc_capacity', true ), '40' );
rt_save( $ev_on, array( 'reg_choice_present' => '1', 'reg_choice' => 'here' ) );
rt( '1: RSVP Here switches them on', get_post_meta( $ev_on, '_uc_rsvp_enabled', true ), '1' );
rt_save( $ev_off, array( 'reg_choice_present' => '1', 'reg_choice' => 'elsewhere', 'reg_url' => 'https://example.org/go' ) );
rt( '1: External RSVP stores another site and leaves RSVPs as they were', array(
    SFAF_Register_Elsewhere::is_on( $ev_off ), get_post_meta( $ev_off, '_uc_rsvp_enabled', true ),
), array( true, '0' ) );
rt_save( $ev_away, array( 'reg_choice_present' => '1', 'reg_choice' => 'here', 'reg_url' => 'https://example.org/tickets' ) );
rt( '1: back to RSVP Here from another site', array( SFAF_Register_Elsewhere::is_on( $ev_away ), get_post_meta( $ev_away, '_uc_rsvp_enabled', true ) ), array( false, '1' ) );
rt( '1: a form without the choice changes neither answer', ( function () {
    $id = rt_event( 'publish', array( '_uc_rsvp_enabled' => '1' ) );
    rt_save( $id, array() );
    return get_post_meta( $id, '_uc_rsvp_enabled', true );
} )(), '1' );

/* An imported event keeps its line, and nothing is drawn twice. */
$imp = rt_event( 'publish', array( SFAF_Sources::META_SOURCE => 'eventbrite', SFAF_Sources::META_EXTERNAL_ID => 'EB-1' ) );
$ie  = rt_edit( $imp );
rt( '1: an imported event has no choice', false === strpos( rt_card( $ie ), 'name="reg_choice"' ), true );
$edit_on = rt_edit( $ev_on );
rt( '1: no Accept RSVPs tick on Edit event either', false === strpos( $edit_on, 'name="rsvp_enabled"' ), true );
rt( '1: the RSVP button note names no old control', false === strpos( $edit_on, 'Accept RSVPs' ), true );

/* The script: the three states. */
$js = (string) file_get_contents( $root . '/public/js/portal.js' );
rt( '1: the script shows the settings for RSVP Here only', false !== strpos( $js, "if (here) { here.hidden = choice !== 'here'; }" ), true );
rt( '1: and the link box for External RSVP only', false !== strpos( $js, 'if (urlBox) { urlBox.hidden = !away; }' ) && false !== strpos( $js, "var away = choice === 'elsewhere';" ), true );

/* ======================================================================== 2 */
$bar = preg_match( '#<div class="uc-form-actions uc-form-actions-primary uc-editor-actions uc-editor-bar".*?(?=<div class="uc-dialog-panel" id="uc-cancel-this")#s', $edit_on, $bm ) ? $bm[0] : '';
rt( '2: the bar is found', '' !== $bar, true );
rt( '2: Delete is a red text link', (bool) preg_match( '#<button type="button" data-uc-dialog-open="uc-delete-this" data-uc-saved-action="delete"\s+class="uc-link-danger uc-editor-delete" aria-haspopup="dialog">Delete</button>#', $bar ), true );
rt( '2: Cancel event is the plain button', (bool) preg_match( '#<button type="button" class="uc-btn uc-cancel-open" data-uc-dialog-open="uc-cancel-this"#', $bar ), true );
rt( '2: Save changes is green', (bool) preg_match( '#class="uc-btn uc-btn-go uc-editor-save-main uc-editor-save">Save changes<#', $bar ), true );
preg_match_all( '#class="[^"]*\buc-btn-(go|primary|stop|danger|caution|utility)\b#', $bar, $solid );
rt( '2: one solid button on the bar', $solid[1], array( 'go' ) );
$css = (string) file_get_contents( $root . '/public/css/portal.css' );
rt( '2: Delete at the far left, Save changes on the right', false !== strpos( $css, '.uc-editor-actions .uc-editor-delete  { order: -1; }' ) && false !== strpos( $css, '.uc-editor-actions .uc-editor-save    { order: 3; }' ), true );
rt( '2: set apart', false !== strpos( $css, '.uc-editor-actions .uc-editor-delete  { margin-right: 20px; font-size: 14px; }' ), true );
rt( '2: Cancel event outlined at rest and on hover', false !== strpos( $css, ".uc-editor-actions .uc-cancel-open,\n.uc-editor-actions .uc-cancel-open:hover { background: transparent; }" ), true );

/* ======================================================================== 3 */
kit_write_post( array( 'ID' => 900, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => '', 'post_mime_type' => 'application/pdf' ) );
update_post_meta( 900, '_wp_attached_file', 'calendar-files/autumn-flyer.pdf' );
$GLOBALS['kit_files'][900] = 'https://resources.sfaf.org/wp-content/uploads/calendar-files/autumn-flyer.pdf';
$dir = 'https://resources.sfaf.org/wp-content/uploads/calendar-files/';
/* The metadata WordPress writes for a PDF: every picture under sizes, no top-level file. */
update_post_meta( 900, '_wp_attachment_metadata', array( 'sizes' => array(
    'full'   => array( 'file' => 'autumn-flyer-pdf.jpg', 'width' => 1088, 'height' => 1408, 'mime_type' => 'image/jpeg' ),
    'medium' => array( 'file' => 'autumn-flyer-pdf-232x300.jpg', 'width' => 232, 'height' => 300, 'mime_type' => 'image/jpeg' ),
    'large'  => array( 'file' => 'autumn-flyer-pdf-791x1024.jpg', 'width' => 791, 'height' => 1024, 'mime_type' => 'image/jpeg' ),
), 'filesize' => 1 ) );
$fl = rt_event( 'publish', array( SFAF_Attachments::META_ID => 900 ) );
$f  = SFAF_Attachments::flyer_html( $fl );
rt( '3: the first page as a picture', (bool) preg_match( '#<img src="' . preg_quote( $dir, '#' ) . 'autumn-flyer-pdf-232x300\.jpg" srcset="[^"]*232w, [^"]*791x1024\.jpg 791w" sizes="232px" width="232" height="300" alt=""#', $f ), true );
rt( '3: a solid View flyer (PDF) under it', (bool) preg_match( '#</a><a class="uc-actionbtn uc-actionbtn-primary" href="' . preg_quote( $GLOBALS['kit_files'][900], '#' ) . '" target="_blank" rel="noopener noreferrer"><span class="uc-actionbtn-label">View flyer \(PDF\)</span>#', $f ), true );
rt( '3: no outlined or white button', false === strpos( $f, 'uc-actionbtn-secondary' ), true );
rt( '3: one link for the keyboard', substr_count( $f, 'tabindex="-1" aria-hidden="true"' ), 1 );
update_post_meta( 900, '_wp_attachment_metadata', array( 'filesize' => 1 ) );   // none, and none can be made
$f2 = SFAF_Attachments::flyer_html( $fl );
rt( '3: no preview: the card, its PDF mark and the same button', false !== strpos( $f2, '<div class="uc-flyer-card" aria-hidden="true"><svg' ) && false !== strpos( $f2, '>PDF</text>' ) && false !== strpos( $f2, 'uc-actionbtn-primary' ) && false === strpos( $f2, '<img' ), true );
update_post_meta( $fl, SFAF_Attachments::META_LABEL, 'Route map' );
rt( '3: a typed label is the button', false !== strpos( SFAF_Attachments::flyer_html( $fl ), '<span class="uc-actionbtn-label">Route map</span>' ), true );
rt( '3: nothing without a file', SFAF_Attachments::flyer_html( rt_event() ), '' );
$single = (string) file_get_contents( $root . '/templates/single-uc_event.php' );
rt( '3: the event page draws it, and not the old button', 1 === substr_count( $single, 'SFAF_Attachments::flyer_html( $post_id )' ) && false === strpos( $single, 'button_html' ), true );
rt( '3: the list row keeps its button', false !== strpos( (string) file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' ), "SFAF_Attachments::button_html( \$post_id, 'uc-attachment-row' )" ), true );
rt( '3: nothing in the stylesheet the embed loads', false === strpos( (string) file_get_contents( $root . '/public/css/calendar.css' ), 'uc-flyer' ), true );

/* ======================================================================== 4 */
$k = SFAF_Notifications::kinds();
rt( '4: the four list emails, word for word', array(
    array( $k['alert']['label'], $k['alert']['note'] ),
    array( $k['day_before']['label'], $k['day_before']['note'] ),
    array( $k['summary']['label'], $k['summary']['note'] ),
    array( $k['cancel_alert']['label'], $k['cancel_alert']['note'] ),
), array(
    array( 'Email when someone RSVPs', 'Sent each time someone RSVPs.' ),
    array( 'RSVP count, the day before', 'Sent at 6 am the day before. Gives the number, not the names. Not sent if nobody has RSVPed.' ),
    array( "Who's coming, two hours before", 'The full list of everyone who RSVPed. Not sent if nobody has.' ),
    array( 'Email when someone cancels', 'Sent each time someone cancels, with their name and the new count.' ),
) );
// 3.129.0: the helper line went; the field's label says it.
rt( '4: the helper line', substr_count( $edit_on, 'Everybody on this list gets the emails switched on below.' ) . substr_count( $edit_on, '>Who gets these emails</label>' ), '01' );
rt( '4: the old one is gone', false === strpos( $edit_on, "registrations and reminders." ), true );
rt( '4: the labels are what the card draws', false !== strpos( $edit_on, 'RSVP count, the day before' ) && false !== strpos( $edit_on, 'Who&#039;s coming, two hours before' ), true );
$nobody = new WP_User(); $nobody->ID = 0; $nobody->user_email = '';
$person = new WP_User(); $person->ID = 7; $person->user_email = ''; $person->display_name = 'Sam';
$GLOBALS['kit_users'] = array( $nobody, $person );
$line   = "This event's creator has no usable email address on file.";
$sys    = rt_event( 'pending', array(), 0 );
$real   = rt_event( 'publish', array(), 7 );
$box_s  = rt_cap( function () use ( $portal, $sys ) { kit_call( 'SFAF_Portal', 'render_notify_box', $portal, array( new WP_User(), $sys ) ); } );
$box_r  = rt_cap( function () use ( $portal, $real ) { kit_call( 'SFAF_Portal', 'render_notify_box', $portal, array( new WP_User(), $real ) ); } );
rt( '4: no creator line for a system account', false === strpos( $box_s, $line ) && false !== strpos( $box_s, 'Who gets these emails' ), true );
rt( '4: kept for a real person with no address', false !== strpos( $box_r, $line ), true );
$GLOBALS['kit_users'] = array();

/* ======================================================================== 5 */
$req = rt_event( 'pending', array(
    SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_STAFF,
    SFAF_Request::META_EMAIL    => 'jo@sfaf.org',
    SFAF_Request::META_NAME     => 'Jo Rivera',
    SFAF_Request::META_AT       => '2026-10-19 09:30:00',
    SFAF_Request::META_REPEAT   => 'Every other Tuesday',
    SFAF_Request::META_NOTES    => 'Room 3 if free.',
), 0 );
$rp = rt_cap( function () use ( $portal, $req ) { kit_call( 'SFAF_Portal', 'render_request_panel', $portal, array( $req ) ); } );
rt( '5: one line, the name a mailto, the date', (bool) preg_match( '#<p class="uc-request-who">Requested by <a href="mailto:jo@sfaf\.org">Jo Rivera</a> on ' . preg_quote( sfaf_ap_date( '2026-10-19 09:30:00', 'short_year' ), '#' ) . '</p>#', $rp ), true );
rt( '5: no card and no heading', false === strpos( $rp, 'Requested by a colleague' ) && false === strpos( $rp, 'uc-card' ) && false === strpos( $rp, '<h2' ), true );
rt( '5: what else they asked follows', false !== strpos( $rp, 'Every other Tuesday' ) && false !== strpos( $rp, 'Room 3 if free.' ), true );
$er = rt_edit( $req );
rt( '5: near the top: above the form', false !== strpos( $er, 'Requested by <a href="mailto:jo@sfaf.org">' ) && strpos( $er, 'Requested by <a href' ) < strpos( $er, 'data-uc-card="registration"' ), true );
$com = rt_event( 'pending', array(
    SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_COMMUNITY,
    SFAF_Request::META_EMAIL    => 'pat@example.org',
    SFAF_Request::META_NAME     => 'Pat',
), 0 );
$cp = rt_cap( function () use ( $portal, $com ) { kit_call( 'SFAF_Portal', 'render_request_panel', $portal, array( $com ) ); } );
rt( '5: a community submission keeps its panel', false !== strpos( $cp, '<h2>Submitted by a member of the public</h2>' ), true );

/* ======================================================================== */
echo "\nRelease 3.113.3\n" . str_repeat( '=', 72 ) . "\nran $ran assertions.\n";
if ( $fails ) {
    echo count( $fails ) . " failure(s):\n";
    foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
    exit( 1 );
}
echo "One RSVP choice that stores what it always did, one solid button, the flyer, the Notifications copy and the request line.\n";
