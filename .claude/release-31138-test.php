<?php
/**
 * 3.113.8: NO MAP FOR "ONLINE", IMPORTED PICTURES UNCROPPED, EACH PROGRAM'S
 * SIGNUP PAGE, THE SENDER NOTE, AND THE SIDEBAR'S MONTH BUTTONS.
 *
 *     php .claude/release-31138-test.php
 *
 * The real plugin through wp-kit.php.
 *
 *   3  an event whose location is only "Online" (Eventbrite's word for an
 *      online event) gets no Getting there and no map; a real place keeps
 *      both, and so does a hybrid event's place; an online-format event
 *      still has none
 *   4  the event page marks a picture from an import, and only that, to be
 *      shown at its own shape; the rule is the event page's alone
 *   5  an EveryAction event's signup page: its own on the list first, then the
 *      row's Public_URL, then its day on the list on the site its program's
 *      forms are on, 50-Plus when nothing says; a list on either site is a
 *      list, not a page of its own
 *   6  Email Templates says nothing about Bluehost
 *   7  the sidebar's month buttons are the public secondary button, and the
 *      combined view's sidebar draws no card of its own
 *
 * Item 1 is release-31138-live.php, item 2 pacific-time-test.php. The embed is
 * embed-identity-test.php's. PLANTs in plant-31138.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
$root = $GLOBALS['kit_root'];
$css  = file_get_contents( $root . '/public/css/calendar.css' );

/* ======================================================================== 3 */
function rt_event( $meta ) {
    return kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'An event', 'meta_input' => $meta ) );
}
$online_word = rt_event( array( '_uc_location' => 'Online' ) );
$online_long = rt_event( array( '_uc_location' => 'Online Event via Zoom' ) );
$place       = rt_event( array( '_uc_location' => '1035 Market St, San Francisco, CA 94103' ) );
$studio      = rt_event( array( '_uc_location' => 'Edge Studios Online, 50 Main St' ) );
$hybrid      = rt_event( array( '_uc_location' => '1035 Market St, San Francisco, CA 94103', SFAF_Online::META_HYBRID => '1' ) );
$online_mode = rt_event( array( SFAF_Online::META => '1' ) );

rt( '3: "Online" has no Getting there', sfaf_event_map_html( $online_word ), '' );
rt( '3: nor "Online Event via Zoom"', sfaf_event_map_html( $online_long ), '' );
rt( '3: a real place keeps it', false !== strpos( sfaf_event_map_html( $place ), 'Getting there' ), true );
rt( '3: a place with "Online" in its name keeps it', false !== strpos( sfaf_event_map_html( $studio ), 'Getting there' ), true );
rt( '3: a hybrid event keeps its place\'s map', false !== strpos( sfaf_event_map_html( $hybrid ), 'Getting there' ), true );
rt( '3: an online-format event has none', sfaf_event_map_html( $online_mode ), '' );
foreach ( array( 'Online' => true, 'online event' => true, 'Virtual' => true, 'Zoom' => true, 'Online / Zoom' => true, 'Event' => false, 'TBD' => false, 'Strut' => false, '' => false, 'Online Studio' => false ) as $line => $want ) {
    rt( '3: "' . $line . '" names no place', sfaf_location_is_online_only( $line ), $want );
}

/* ======================================================================== 4 */
$tpl = file_get_contents( $root . '/templates/single-uc_event.php' );
rt( '4: the event page asks where the picture came from', false !== strpos( $tpl, "\$own_shape = in_array( sfaf_event_image_source( \$post_id ), array( 'source', 'remote' ), true );" ), true );
rt( '4: and marks only that one', false !== strpos( $tpl, '<div class="uc-single-image<?php echo $own_shape ? \' uc-single-image-own-shape\' : \'\'; ?>">' ), true );
$src_ev = rt_event( array( '_uc_external_image' => 'https://img.evbuc.com/x.jpg' ) );
$own_ev = rt_event( array( '_uc_image_url' => 'https://elsewhere.example/x.jpg' ) );
rt( '4: an import\'s picture is the source\'s', sfaf_event_image_source( $src_ev ), 'source' );
rt( '4: a picture set here is not', sfaf_event_image_source( $own_ev ), 'event' );
rt( '4: uncropped at its own shape', (bool) preg_match( '#\.uc-single \.uc-single-image\.uc-single-image-own-shape \{ aspect-ratio: auto;[^}]*\}\s*\.uc-single \.uc-single-image\.uc-single-image-own-shape img \{ width: auto; max-width: 100%; height: auto;[^}]*object-fit: contain;#', $css ), true );
rt( '4: the rule is the event page\'s only', 2 === substr_count( $css, 'uc-single-image-own-shape' ) && false === strpos( $css, '.uc-calendar .uc-single-image-own-shape' ), true );
rt( '4: the card, grid card and sidebar keep 16:9', false !== strpos( $css, '.uc-calendar .uc-lc-media { display: grid; margin-top: 14px; aspect-ratio: 16 / 9;' ) && false !== strpos( $css, '.uc-sidebar-thumb { flex: 1 1 60px; aspect-ratio: 16 / 9;' ), true );

/* ======================================================================== 5 */
update_option( SFAF_EveryAction::LINKS_OPTION, array( 'at' => time(), 'pairs' => array( '750059402' => 'https://han.sfaf.org/a/50-plus-saturday-am-coffee-social-16' ) ) );
$ad = new SFAF_Source_EveryAction();
$sites = new ReflectionProperty( 'SFAF_Source_EveryAction', 'sites' );
$sites->setAccessible( true );
$sites->setValue( $ad, array( 'HANWALK' => 'han.sfaf.org' ) );
$y = (int) date( 'Y' ) + 1;
function rt_row( $uuid, $sid, $url ) {
    global $y;
    return array( 'UUID' => $uuid, 'Series_ID' => $sid, 'Title' => 'A program', 'Start_Time' => "10/09/$y 05:00 PM +0000", 'End_Time' => "10/09/$y 07:00 PM +0000", 'Public_URL' => $url );
}
$day = '10-09-' . $y;
$e = $ad->normalize( rt_row( '750059402', 'CS50', 'https://han.sfaf.org/a/some-form' ) );
rt( '5: the list\'s own page comes first', $e['source_url'], 'https://han.sfaf.org/a/50-plus-saturday-am-coffee-social-16' );
$e = $ad->normalize( rt_row( '880000001', 'HAN1', 'https://han.sfaf.org/a/han-support-group-3' ) );
rt( '5: then the row\'s own form', $e['source_url'], 'https://han.sfaf.org/a/han-support-group-3' );
rt( '5: and its day is on HAN\'s list', $e['everyaction']['day_url'], 'https://han.sfaf.org/a/asevents?date_start=' . $day . '&date_end=' . $day );
$e = $ad->normalize( rt_row( '880000002', 'HANWALK', '' ) );
rt( '5: no page and no form: nothing to keep', $e['source_url'], '' );
rt( '5: its day on the site its program\'s forms are on', $e['everyaction']['day_url'], 'https://han.sfaf.org/a/asevents?date_start=' . $day . '&date_end=' . $day );
$e = $ad->normalize( rt_row( '880000003', 'CS50', '' ) );
rt( '5: nothing says: 50-Plus, as before', $e['everyaction']['day_url'], 'https://50plus.sfaf.org/a/asevents?date_start=' . $day . '&date_end=' . $day );
$e = $ad->normalize( rt_row( '880000004', 'CS50', 'javascript:alert(1)' ) );
rt( '5: an address that is not a web page is not a form', $e['source_url'], '' );
rt( '5: a day on HAN\'s list is still a list', SFAF_EveryAction::is_list_url( 'https://han.sfaf.org/a/asevents?date_start=01-01-2027&date_end=01-01-2027' ), true );
rt( '5: and a form is not', SFAF_EveryAction::is_list_url( 'https://han.sfaf.org/a/han-support-group-3' ), false );
$ea = file_get_contents( $root . '/includes/class-sfaf-source-everyaction.php' );
rt( '5: the fetch learns each program\'s site from its rows\' forms', false !== strpos( $ea, "\$this->sites[ \$sid ] = \$host;" ), true );

/* ======================================================================== 6 */
$admin = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
ob_start();
$admin->render_settings_page();
$page = (string) ob_get_clean();
rt( '6: the settings page renders', false !== strpos( $page, '<h3>Sender</h3>' ), true );
rt( '6: nothing about Bluehost', false === stripos( $page, 'bluehost' ), true );
rt( '6: nothing about deliverability or spam under Sender', (bool) preg_match( '#<h3>Sender</h3>\s*<p class="description">(?:(?!</p>).)*(deliverability|spam)#s', $page ), false );

/* ======================================================================== 7 */
$sc   = new SFAF_Shortcodes();
$tabs = kit_call( 'SFAF_Shortcodes', 'render_month_tabs', $sc, array( '2026-12', array() ) );
rt( '7: Nov and Jan are both offered', 2 === substr_count( (string) $tabs, '<button type="button"' ), true );
rt( '7: each is the public secondary button', 2 === preg_match_all( '#class="uc-month-tab uc-month-tab-(?:prev|next) uc-actionbtn uc-actionbtn-secondary"#', (string) $tabs ), true );
rt( '7: the combined sidebar draws no card of its own', (bool) preg_match( '#\.uc-calendar \.uc-view-panels-combined \.uc-sidebar \{[^}]*border: 0; padding: 0; background: none;[^}]*box-shadow: none; border-radius: 0;\s*\}#', $css ), true );
rt( '7: the month buttons lay out over the button\'s own inline-flex', (bool) preg_match( '#\.uc-sidebar \.uc-month-tab \{\s*flex: 1 1 0; min-width: 0;\s*display: flex;#', $css ), true );
rt( '7: name and count take the button\'s ink', false !== strpos( $css, '.uc-month-tab-count { font-size: 12.5px; font-weight: 400; color: inherit;' ) && (bool) preg_match( '#\.uc-month-tab-name \{[^}]*color: inherit;#', $css ), true );
rt( '7: the old white box is gone', false === strpos( $css, 'border: 1px solid var(--uc-border); border-radius: 8px; background: #fff;
    font: inherit; text-align: left;' ), true );

echo "\n3.113.8: Online, imported pictures, signup pages, Sender, month buttons\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. \"Online\" draws no map and a place does, hybrid included; an import's picture is shown at its own shape on the\nevent page only; EveryAction events get their own page, their own form, or their program's site; Sender says nothing\nabout Bluehost; and the month buttons are secondary buttons in a sidebar with no card of its own.\n";
exit( 0 );
