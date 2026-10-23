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
 *   8  the series screen draws the event editor's picture block with this
 *      series' pictures only and + Upload a picture; a save keeps a URL it
 *      never showed, Remove clears both; the upload is tagged and filed
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

/* ======================================================================== 8 */
/* The picture queries, answered: the folder by its path, the series by its tag. */
function rt8_query( $a ) {
    $out = array();
    $ids = array_keys( $GLOBALS['kit_posts'] );
    rsort( $ids );
    foreach ( $ids as $id ) {
        $p = $GLOBALS['kit_posts'][ $id ];
        if ( ( isset( $a['post_type'] ) ? $a['post_type'] : 'uc_event' ) !== $p->post_type ) { continue; }
        if ( 'attachment' === $p->post_type ) {
            $file = isset( $GLOBALS['kit_meta'][ $id ]['_wp_attached_file'] ) ? (string) $GLOBALS['kit_meta'][ $id ]['_wp_attached_file'] : '';
            $ok = true;
            foreach ( (array) ( isset( $a['meta_query'] ) ? $a['meta_query'] : array() ) as $k => $c ) {
                if ( is_array( $c ) && isset( $c['key'] ) && '_wp_attached_file' === $c['key'] && isset( $c['compare'] ) && 'REGEXP' === strtoupper( $c['compare'] ) ) {
                    $ok = $ok && (bool) preg_match( '#' . $c['value'] . '#', $file );
                }
            }
            foreach ( (array) ( isset( $a['tax_query'] ) ? $a['tax_query'] : array() ) as $k => $t ) {
                if ( ! is_array( $t ) || ! isset( $t['taxonomy'] ) ) { continue; }
                $on = isset( $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] ) ? $GLOBALS['kit_terms'][ $id ][ $t['taxonomy'] ] : array();
                $ok = $ok && ( ( isset( $t['operator'] ) && 'NOT EXISTS' === $t['operator'] ) ? ! $on : (bool) array_intersect( array_map( 'intval', (array) $t['terms'] ), $on ) );
            }
            if ( ! $ok ) { continue; }
        }
        $out[] = ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) ? (int) $id : $p;
    }
    return $out;
}
$GLOBALS['kit_query']     = 'rt8_query';
$GLOBALS['kit_get_posts'] = 'rt8_query';
function rt8_picture( $id, $file, $series = 0 ) {
    kit_write_post( array( 'ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => '' ) );
    update_post_meta( $id, '_wp_attached_file', $file );
    $GLOBALS['kit_images'][ $id ] = 'https://resources.sfaf.org/wp-content/uploads/' . $file;
    if ( $series ) { $GLOBALS['kit_terms'][ $id ][ SFAF_Media::TAXONOMY ] = array( $series ); }
}
rt8_picture( 8101, 'calendar/coffee-social.jpg', 11 );
rt8_picture( 8102, 'calendar/another-series.jpg', 12 );
rt8_picture( 8103, 'calendar-other/one-off.jpg' );

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function rt8_screen( $portal, $term ) {
    $_GET = array();
    ob_start();
    kit_call( 'SFAF_Portal', 'render_series_edit', $portal, array( new WP_User(), $term ) );
    return (string) ob_get_clean();
}
update_term_meta( 11, SFAF_Series::META_IMAGE_ID, 8101 );
$s = rt8_screen( $portal, 11 );
$block = (string) strstr( $s, 'data-uc-image-block' );
$block = substr( $block, 0, (int) strpos( $block, '<span class="uc-field-label">Description' ) );
rt( '8: the series screen draws the event editor\'s picture block', '' !== $block, true );
rt( '8: no WordPress media box', false === strpos( $s, 'uc-choose-image' ), true );
rt( '8: no image URL box', false === strpos( $s, 'Or enter image URL' ) && false === strpos( $s, 'name="series_image_url"' ), true );
rt( '8: this series\' picture is offered', false !== strpos( $block, 'name="series_image_id" value="8101"' ), true );
rt( '8: another series\' picture is not', false === strpos( $block, 'value="8102"' ), true );
rt( '8: nor Other images', false === strpos( $block, 'value="8103"' ) && false === strpos( $block, 'Other images' ), true );
rt( '8: the preview shows the series picture', (bool) preg_match( '#data-uc-image-preview-img(?! hidden)#', $block ) && false !== strpos( $block, 'calendar/coffee-social.jpg' ), true );
rt( '8: Remove is on it', (bool) preg_match( '#data-uc-image-remove>Remove#', $block ), true );
rt( '8: a save with no row on keeps the stored picture', false !== strpos( $block, '<input type="hidden" name="series_image_id" value="8101" />' ), true );
rt( '8: "+ Upload a picture", under the picture rule', (bool) preg_match( '#<span>\+ Upload a picture</span>\s*<input type="file" name="uc_series_picture"[^>]*data-uc-event-upload\s+data-uc-rule-width="1200"\s+data-uc-rule-height="675"\s+data-uc-rule-bytes="512000"#', $block ), true );
rt( '8: the form can carry the file', (bool) preg_match( '#<form method="post" action="[^"]*series/edit/11"[^>]*enctype="multipart/form-data"#', $s ), true );

update_term_meta( 11, SFAF_Series::META_IMAGE_ID, 8102 );
$s = rt8_screen( $portal, 11 );
rt( '8: a picture chosen from outside the series stays chosen', (bool) preg_match( '#name="series_image_id" value="8102"\s+checked#', $s ), true );
rt( '8: and says so', (bool) preg_match( '#data-uc-image-outside>Not in this series#', $s ), true );

$s = rt8_screen( $portal, 0 );
rt( '8: a new series has no pictures yet, and can upload one', false !== strpos( $s, 'No pictures for this series yet.' ) && false !== strpos( $s, 'name="uc_series_picture"' ), true );

/* The save. */
function rt8_save( $portal, $post ) {
    $_POST = array_merge( array( 'series_id' => '11', 'series_name' => 'Coffee social' ), $post );
    $_FILES = array();
    return kit_call( 'SFAF_Portal', 'save_series_from_post', $portal, array( new WP_User() ) );
}
update_term_meta( 11, SFAF_Series::META_IMAGE_ID, 8101 );
update_term_meta( 11, SFAF_Series::META_IMAGE_URL, 'https://elsewhere.example/old.jpg' );
rt8_save( $portal, array( 'series_image_id' => '8101' ) );
rt( '8: a save without the URL box keeps a stored URL', get_term_meta( 11, SFAF_Series::META_IMAGE_URL, true ), 'https://elsewhere.example/old.jpg' );
rt( '8: and the picture', (int) get_term_meta( 11, SFAF_Series::META_IMAGE_ID, true ), 8101 );
rt8_save( $portal, array( 'series_image_id' => '0', 'reset_series_image' => '1' ) );
rt( '8: Remove clears the picture', get_term_meta( 11, SFAF_Series::META_IMAGE_ID, true ), '' );
rt( '8: and the URL', get_term_meta( 11, SFAF_Series::META_IMAGE_URL, true ), '' );

$psrc = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$up   = substr( $psrc, strpos( $psrc, 'private function save_series_upload' ), 2600 );
rt( '8: the upload answers to the picture rule', false !== strpos( $up, "SFAF_Uploads::inspect( 'uc_series_picture' )" ), true );
rt( '8: lands in the calendar folder', false !== strpos( $up, "'upload_to_series'" ) && false !== strpos( $up, 'SFAF_Media_Folder::holds( $att )' ), true );
rt( '8: is tagged to this series and becomes its picture', false !== strpos( $up, 'SFAF_Media::add_tag( array( $att ), $term_id );' ) && false !== strpos( $up, 'update_term_meta( $term_id, SFAF_Series::META_IMAGE_ID, $att );' ), true );
rt( '8: and sends no event-upload notice', false === strpos( $up, 'META_UPLOAD' . ', array' ), true );
$dirs = SFAF_Media_Folder::upload_to_series( array( 'basedir' => '/u', 'baseurl' => 'https://x/u', 'subdir' => '/2026/10', 'path' => '', 'url' => '' ) );
rt( '8: upload_to_series() is the calendar folder', $dirs['path'] . ' ' . $dirs['url'], '/u/calendar https://x/u/calendar' );

echo "\n3.113.8: Online, imported pictures, signup pages, Sender, month buttons, the series picture\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. \"Online\" draws no map and a place does, hybrid included; an import's picture is shown at its own shape on the\nevent page only; EveryAction events get their own page, their own form, or their program's site; Sender says nothing\nabout Bluehost; and the month buttons are secondary buttons in a sidebar with no card of its own.\n";
exit( 0 );
