<?php
/**
 * 3.142.0: ANNOUNCEMENTS ON THE CALENDAR.
 *
 *     php .claude/release-31420-test.php
 *
 * The real store, the real renderers and the real admin screen on wp-kit.php.
 * The bar, the card and the banner are measured in Chrome by release-31420-live.
 *
 *   S  the store: title and a date required; a range; an end before the start
 *      refused; the link http or https only; every field read back; removal
 *   I  the icon rule: a square SVG with nothing that runs or fetches; a square
 *      PNG with an alpha channel, 512px or smaller, under 100KB
 *   G  the month grid: a bar on every day an announcement covers, under the
 *      day's number, icon then title; spoken in the cell's label; a button only
 *      when there is a card to open; the day is not marked closed; the phone
 *      strip beside it
 *   L  the list: a banner above the first event on or after its first day,
 *      with its dates; none outside the list's dates
 *   D  the sidebar: today's announcements only
 *   J  the card's script is one block, byte for byte the same in calendar.js
 *      and embed.js; both start it; both day panels carry the day's bars
 *   A  the screen: "Closures and announcements" in the menu and the heading;
 *      closures still there; the form's fields; a save and a removal through
 *      the real handler; a refused save stores nothing
 *   E  the embed's cache is flushed when the option changes
 *
 * PLANTs in plant-31420.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return $s; } }
if ( ! function_exists( 'get_post_stati' ) ) { function get_post_stati( $a = array() ) { return array( 'publish' => 1, 'draft' => 1 ); } }
if ( ! function_exists( 'wp_trim_words' ) ) { function wp_trim_words( $t, $n = 55, $more = '' ) { return (string) $t; } }
if ( ! function_exists( 'force_balance_tags' ) ) { function force_balance_tags( $t ) { return $t; } }

$root  = dirname( __DIR__ );
$fails = array();
$n     = 0;
function rt( $label, $ok ) { global $fails, $n; $n++; if ( ! $ok ) { $fails[] = $label; } }
function rt_cap( $fn ) {
    $d = ob_get_level(); ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = ''; while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; } return $h;
}
function rt_x( $html ) { $d = new DOMDocument(); libxml_use_internal_errors( true ); $d->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' ); return new DOMXPath( $d ); }
function rt_q( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) { if ( 'uc_event' === $p->post_type && 'publish' === $p->post_status ) { $out[] = $p; } }
    usort( $out, function ( $x, $y ) { return strcmp( (string) get_post_meta( $x->ID, '_uc_event_date', true ), (string) get_post_meta( $y->ID, '_uc_event_date', true ) ); } );
    if ( isset( $a['fields'] ) && 'ids' === $a['fields'] ) { $out = wp_list_pluck( $out, 'ID' ); }
    $per = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : -1;
    return $per > 0 ? array_slice( $out, 0, $per ) : $out;
}
/** A PNG from its bytes, so no image library is needed. Colour type 6 is RGBA, 2 is RGB. */
function rt_png( $path, $w, $h, $type = 6, $pad = 0 ) {
    $chunk = function ( $t, $d ) { return pack( 'N', strlen( $d ) ) . $t . $d . pack( 'N', crc32( $t . $d ) ); };
    $px  = ( 6 === $type ) ? "\0\0\0\0" : "\0\0\0";
    $raw = str_repeat( "\0" . str_repeat( $px, $w ), $h );
    $png = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, $type, 0, 0, 0 ) )
        . $chunk( 'IDAT', gzcompress( $raw ) ) . ( $pad ? $chunk( 'tEXt', 'x' . "\0" . str_repeat( 'a', $pad ) ) : '' ) . $chunk( 'IEND', '' );
    file_put_contents( $path, $png );
    return $path;
}

$today = current_time( 'Y-m-d' );
$day   = function ( $n ) { return date( 'Y-m-d', strtotime( ( $n >= 0 ? '+' : '' ) . $n . ' days' ) ); };
$GLOBALS['kit_query'] = 'rt_q'; $GLOBALS['kit_get_posts'] = 'rt_q'; $GLOBALS['kit_loop'] = true;
$GLOBALS['kit_images'][ 77 ] = 'https://resources.sfaf.org/wp-content/uploads/pic.png';
$GLOBALS['kit_images'][ 78 ] = 'https://resources.sfaf.org/wp-content/uploads/icon.png';

$SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="#FFD900"/></svg>';

/* ==== S: the store ===================================================== */
$e = SFAF_Announcements::save( '', array( 'title' => '', 'start' => $today ) );
rt( 'S: a save with no title is not refused', is_wp_error( $e ) );
$e = SFAF_Announcements::save( '', array( 'title' => 'No date', 'start' => '' ) );
rt( 'S: a save with no date is not refused', is_wp_error( $e ) );
$e = SFAF_Announcements::save( '', array( 'title' => 'Backwards', 'start' => $day( 3 ), 'end' => $day( 1 ) ) );
rt( 'S: an end before the start is not refused', is_wp_error( $e ) && 'The last day is before the first day.' === $e->get_error_message() );
$e = SFAF_Announcements::save( '', array( 'title' => 'Bad link', 'start' => $today, 'link' => 'javascript:alert(1)' ) );
rt( 'S: a javascript: link is not refused', is_wp_error( $e ) );
$e = SFAF_Announcements::save( '', array( 'title' => 'Bad date', 'start' => '2026-02-31' ) );
rt( 'S: February 31 is not refused', is_wp_error( $e ) );
rt( 'S: a refused save stored something', array() === SFAF_Announcements::all() );

$pride = SFAF_Announcements::save( '', array( 'title' => 'Pride week', 'start' => $today, 'end' => $day( 1 ), 'desc' => 'Come by the booth.',
    'link' => 'https://sfaf.org/pride', 'icon_svg' => $SVG, 'image_id' => 77 ) );
$move  = SFAF_Announcements::save( '', array( 'title' => 'Office move', 'start' => $day( 3 ) ) );
$far   = SFAF_Announcements::save( '', array( 'title' => 'Far off', 'start' => $day( 60 ), 'icon_id' => 78 ) );
rt( 'S: valid saves did not return ids', is_string( $pride ) && is_string( $move ) && is_string( $far ) );
$p = SFAF_Announcements::get( $pride );
rt( 'S: a saved announcement does not read back every field: ' . json_encode( $p ),
    $p && 'Pride week' === $p['title'] && $today === $p['start'] && $day( 1 ) === $p['end'] && 'Come by the booth.' === $p['desc']
    && 'https://sfaf.org/pride' === $p['link'] && $SVG === $p['icon_svg'] && 0 === $p['icon_id'] && 77 === $p['image_id'] );
$m = SFAF_Announcements::get( $move );
rt( 'S: a single day does not end on its start', $m && $m['start'] === $m['end'] );
rt( 'S: the order is not by first day', array( 'Pride week', 'Office move', 'Far off' ) === array_column( SFAF_Announcements::all(), 'title' ) );
rt( 'S: covering() misses a covered day', array( 'Pride week' ) === array_column( SFAF_Announcements::covering( $day( 1 ) ), 'title' ) );
rt( 'S: covering() answers for an uncovered day', array() === SFAF_Announcements::covering( $day( 2 ) ) );
rt( 'S: an announcement writes a closure', array() === SFAF_Closures::all() && ! SFAF_Closures::is_closed( $today ) );
rt( 'S: the SVG icon is not drawn as a data: URI', 0 === strpos( SFAF_Announcements::icon_src( $p ), 'data:image/svg+xml;base64,' ) );
rt( 'S: a PNG icon is not drawn from the Media Library', 'https://resources.sfaf.org/wp-content/uploads/icon.png' === SFAF_Announcements::icon_src( SFAF_Announcements::get( $far ) ) );
$en = "\xE2\x80\x93";
rt( 'S: the dates do not read short: ' . SFAF_Announcements::when( $p, false ),
    sfaf_ap_date( $today, 'short' ) . $en . ( substr( $today, 0, 7 ) === substr( $day( 1 ), 0, 7 ) ? sfaf_ap_date( $day( 1 ), 'daynum' ) : sfaf_ap_date( $day( 1 ), 'short' ) ) === SFAF_Announcements::when( $p, false ) );
$tmp = SFAF_Announcements::save( '', array( 'title' => 'Gone soon', 'start' => $day( 5 ) ) );
rt( 'S: removal did not remove', true === SFAF_Announcements::delete( $tmp ) && null === SFAF_Announcements::get( $tmp ) );
rt( 'S: removing a missing one is not refused', is_wp_error( SFAF_Announcements::delete( 'nope' ) ) );
/* A key save() writes and all() forgets would be dropped by every reader. */
$raw = get_option( SFAF_Announcements::OPTION );
rt( 'S: all() does not read back every key save() writes: ' . json_encode( array_diff( array_keys( $raw[ $pride ] ), array_keys( $p ) ) ),
    array() === array_diff( array_keys( $raw[ $pride ] ), array_keys( $p ) ) );

/* ==== I: the icon rule ================================================= */
$ok = function ( $s ) { return '' === SFAF_Announcements::svg_icon_error( $s ); };
rt( 'I: a square SVG is refused', $ok( $SVG ) );
rt( 'I: a square SVG by width and height is refused', $ok( '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"><rect width="32" height="32" fill="none"/></svg>' ) );
rt( 'I: an SVG with an internal reference is refused', $ok( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><path id="p" d="M1 1h2"/></defs><use href="#p"/></svg>' ) );
foreach ( array(
    'a wide SVG'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 24"></svg>',
    'a script'            => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>alert(1)</script></svg>',
    'an event attribute'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)"></svg>',
    'foreignObject'       => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><foreignObject/></svg>',
    'an outside href'     => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><use href="https://x.test/a.svg#p"/></svg>',
    'an xlink href'       => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><use xlink:href="//x.test/a.svg#p"/></svg>',
    'an entity'           => '<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"></svg>',
    'a url() fetch'       => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect fill="url(https://x.test/a)"/></svg>',
    'no size at all'      => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
    'not an SVG'          => 'hello',
    'over 10KB'           => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><!--' . str_repeat( 'a', 10300 ) . '--></svg>',
) as $what => $svg ) {
    rt( "I: an SVG with $what is let through", ! $ok( $svg ) );
}
rt( 'I: a refused SVG is stored by save()', is_wp_error( SFAF_Announcements::save( '', array( 'title' => 'x', 'start' => $today, 'icon_svg' => '<svg viewBox="0 0 2 1"></svg>' ) ) ) );
$dir = sys_get_temp_dir() . '/sfaf-31420-' . getmypid();
@mkdir( $dir );
$png = function ( $name, $w, $h, $type = 6, $pad = 0 ) use ( $dir ) { return '' === SFAF_Announcements::png_icon_error( rt_png( "$dir/$name.png", $w, $h, $type, $pad ) ); };
rt( 'I: a 64px RGBA PNG is refused', $png( 'ok', 64, 64 ) );
rt( 'I: a 512px RGBA PNG is refused', $png( 'max', 512, 512 ) );
rt( 'I: a PNG with no alpha channel is let through', ! $png( 'rgb', 64, 64, 2 ) );
rt( 'I: a PNG that is not square is let through', ! $png( 'wide', 64, 32 ) );
rt( 'I: a PNG over 512px is let through', ! $png( 'big', 600, 600 ) );
rt( 'I: a PNG over 100KB is let through', ! $png( 'heavy', 64, 64, 6, 110000 ) );
file_put_contents( "$dir/fake.png", 'not a png' );
rt( 'I: a file that is not a PNG is let through', '' !== SFAF_Announcements::png_icon_error( "$dir/fake.png" ) );
array_map( 'unlink', glob( "$dir/*" ) ); @rmdir( $dir );

/* ==== events for the views ============================================== */
foreach ( array( 1 => 'Coffee social', 3 => 'Book club', 4 => 'Walk' ) as $d => $t ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => $t, 'post_content' => '<p>Words.</p>' ) );
    foreach ( array( '_uc_event_date' => $day( $d ), '_uc_start_time' => '18:00', '_uc_end_time' => '19:30' ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
}
$sc = new SFAF_Shortcodes();

/* ==== G: the month grid ================================================= */
$grid = rt_cap( function () use ( $sc, $today ) { echo $sc->render_month_grid( substr( $today, 0, 7 ), array() ); } );
rt( 'G: the month grid threw: ' . $grid, 0 !== strpos( $grid, 'THREW' ) );
$x = rt_x( $grid );
foreach ( array( $today, $day( 1 ) ) as $d ) {
    if ( substr( $d, 0, 7 ) !== substr( $today, 0, 7 ) && ! $x->query( "//td[@data-day='$d']" )->length ) { continue; }
    $cell = $x->query( "//td[@data-day='$d']" )->item( 0 );
    rt( "G: $d is not marked as carrying an announcement", $cell && false !== strpos( $cell->getAttribute( 'class' ), 'uc-day-ann' ) );
    rt( "G: $d is marked closed", $cell && false === strpos( $cell->getAttribute( 'class' ), 'uc-day-closed' ) );
    $bar = $x->query( "//td[@data-day='$d']//div[@class='uc-ann-bars']/button[@data-uc-ann]" );
    rt( "G: $d has no Pride week bar", 1 === $bar->length && 'Pride week' === trim( $x->query( ".//span[@class='uc-ann-title']", $bar->item( 0 ) )->item( 0 )->textContent ) );
    $first = $x->query( "//td[@data-day='$d']/*" );
    rt( "G: on $d the bar is not right under the day's number", $first->length >= 2 && 'uc-day-num' === $first->item( 0 )->getAttribute( 'class' ) && 'uc-ann-bars' === $first->item( 1 )->getAttribute( 'class' ) );
    $kids = $bar->length ? $bar->item( 0 )->childNodes : null;
    rt( "G: on $d the icon is not first, before the title", $kids && 'img' === $kids->item( 0 )->nodeName && 'uc-ann-icon' === $kids->item( 0 )->getAttribute( 'class' ) && '' === $kids->item( 0 )->getAttribute( 'alt' ) );
    rt( "G: $d's spoken label does not end naming the announcement, after the date", $cell && '. Announcement: Pride week' === substr( $cell->getAttribute( 'aria-label' ), -26 ) && 0 !== strpos( $cell->getAttribute( 'aria-label' ), 'Announcement' ) );
    rt( "G: $d has no phone strip", 1 === $x->query( "//td[@data-day='$d']/span[@class='uc-ann-strip'][@aria-hidden='true']" )->length );
}
$b = $x->query( "//td[@data-day='$today']//button[@data-uc-ann]" )->item( 0 );
rt( 'G: the bar does not carry the card: ' . ( $b ? $b->ownerDocument->saveHTML( $b ) : '' ),
    $b && 'Pride week' === $b->getAttribute( 'data-uc-ann-title' ) && 'Come by the booth.' === $b->getAttribute( 'data-uc-ann-desc' )
    && 'https://resources.sfaf.org/wp-content/uploads/pic.png' === $b->getAttribute( 'data-uc-ann-img' ) && 'https://sfaf.org/pride' === $b->getAttribute( 'data-uc-ann-link' )
    && 'false' === $b->getAttribute( 'aria-expanded' ) && 'button' === $b->getAttribute( 'type' ) );
if ( $x->query( "//td[@data-day='" . $day( 3 ) . "']" )->length ) {
    $only = $x->query( "//td[@data-day='" . $day( 3 ) . "']//div[@class='uc-ann-bars']/*" );
    rt( 'G: a title-only announcement is a button with nothing to open', 1 === $only->length && 'span' === $only->item( 0 )->nodeName && ! $only->item( 0 )->hasAttribute( 'data-uc-ann' ) );
    rt( 'G: a title-only bar draws an icon it does not have', 0 === $x->query( "//td[@data-day='" . $day( 3 ) . "']//img" )->length );
}
rt( 'G: a day with no announcement carries a bar', 0 === $x->query( "//td[@data-day='" . $day( 2 ) . "']//*[contains(@class,'uc-ann')]" )->length || ! $x->query( "//td[@data-day='" . $day( 2 ) . "']" )->length );
rt( 'G: the cell bar shows its dates', 0 === $x->query( "//td//span[@class='uc-ann-when']" )->length );

/* ==== L: the list ====================================================== */
$list = $sc->render_events( 10, 1, array(), 'card' );
$h    = (string) $list['html'];
$at   = function ( $needle ) use ( $h ) { $p = strpos( $h, $needle ); return false === $p ? -1 : $p; };
rt( 'L: no Pride week banner in the list', $at( 'uc-ann-banner" data-uc-ann data-uc-ann-id="' . $pride . '"' ) >= 0 );
rt( 'L: the Pride week banner is not above the first event', $at( 'data-uc-ann-id="' . $pride . '"' ) >= 0 && $at( 'data-uc-ann-id="' . $pride . '"' ) < $at( 'Coffee social' ) );
rt( 'L: the Office move banner is not between the events before and after its day',
    $at( 'data-uc-ann-id="' . $move . '"' ) > $at( 'Coffee social' ) && $at( 'data-uc-ann-id="' . $move . '"' ) < $at( 'Book club' ) );
rt( 'L: an announcement outside the list\'s dates is in it', -1 === $at( 'data-uc-ann-id="' . $far . '"' ) );
$lx = rt_x( $h );
rt( 'L: the banner does not carry its dates', 1 === $lx->query( "//*[@data-uc-ann-id='$move']/span[@class='uc-ann-when']" )->length );
$compact = $sc->render_events( 10, 1, array(), 'compact' );
rt( 'L: the compact list carries banners', false === strpos( (string) $compact['html'], 'uc-ann-' ) );

/* ==== D: the sidebar ==================================================== */
$side = $sc->render_sidebar( array(), 5 );
$sx   = rt_x( $side );
rt( 'D: the sidebar does not show today\'s announcement above its list',
    1 === $sx->query( "//div[@class='uc-ann-side']/button[@data-uc-ann-id='$pride'][contains(@class,'uc-ann-sidebar')]" )->length
    && strpos( $side, 'uc-ann-side' ) < strpos( $side, 'uc-sidebar-list' ) );
rt( 'D: the sidebar shows an announcement that is not on today', false === strpos( $side, $move ) && false === strpos( $side, $far ) );
SFAF_Announcements::delete( $pride );
$side2 = $sc->render_sidebar( array(), 5 );
rt( 'D: with nothing on today the sidebar still draws the strip of announcements', false === strpos( $side2, 'uc-ann-' ) );
$pride = SFAF_Announcements::save( '', array( 'title' => 'Pride week', 'start' => $today, 'end' => $day( 1 ), 'desc' => 'Come by the booth.',
    'link' => 'https://sfaf.org/pride', 'icon_svg' => $SVG, 'image_id' => 77 ) );

/* ==== J: the script ===================================================== */
$cal = str_replace( "\r\n", "\n", file_get_contents( "$root/public/js/calendar.js" ) );
$emb = str_replace( "\r\n", "\n", file_get_contents( "$root/public/js/embed.js" ) );
$slice = function ( $s ) { $a = strpos( $s, '/* SFAF-ANNOUNCE-START' ); $b = strpos( $s, '/* SFAF-ANNOUNCE-END */' ); return ( false === $a || false === $b ) ? '' : substr( $s, $a, $b - $a ); };
rt( 'J: the card\'s block is missing from a script', '' !== $slice( $cal ) && '' !== $slice( $emb ) );
rt( 'J: the card\'s block differs between calendar.js and embed.js', $slice( $cal ) === $slice( $emb ) );
rt( 'J: calendar.js does not start the card', false !== strpos( $cal, "run('announcements', function () { initAnnouncements(null); });" ) );
rt( 'J: embed.js does not start the card in the preview\'s mount', false !== strpos( $emb, 'initAnnouncements(SHARED_MOUNT);' ) );
rt( 'J: calendar.js\'s day panel does not carry the bars', false !== strpos( $cal, "var \$ann = \$cell.find('.uc-ann-bars');" ) );
rt( 'J: embed.js\'s day panel does not carry the bars', false !== strpos( $emb, "var ann = cell.querySelector('.uc-ann-bars');" ) );
rt( 'J: calendar.js lets a key on a bar select the day', false !== strpos( $cal, "if (\$(e.target).closest('.uc-ann-bar').length) {" ) );

/* ==== A: the screen ===================================================== */
$adm = file_get_contents( "$root/admin/class-sfaf-admin.php" );
rt( 'A: the menu is not "Closures and announcements"', false !== strpos( $adm, "'Closures and announcements',\n            'Closures and announcements',\n            'manage_options',\n            'uc-closures'," ) );
$admin = ( new ReflectionClass( 'SFAF_Admin' ) )->newInstanceWithoutConstructor();
$page  = rt_cap( function () use ( $admin ) { $_GET = array(); $admin->render_closures_page(); } );
rt( 'A: the screen threw: ' . substr( $page, 0, 300 ), 0 !== strpos( $page, 'THREW' ) );
$ax = rt_x( $page );
rt( 'A: the heading is not "Closures and announcements"', 1 === $ax->query( "//h1[normalize-space()='Closures and announcements']" )->length );
rt( 'A: the closure form is gone', 1 === $ax->query( "//input[@name='uc_closure_action'][@value='save']" )->length && 1 === $ax->query( "//input[@name='closure_label']" )->length );
$form = $ax->query( "//form[.//input[@name='uc_ann_action'][@value='save']]" )->item( 0 );
rt( 'A: the announcement form is not a file upload form', $form && 'multipart/form-data' === $form->getAttribute( 'enctype' ) );
foreach ( array( "input[@name='ann_title'][@required]" => 'Title, required', "input[@name='ann_start'][@type='date'][@required]" => 'the first day, required',
    "input[@name='ann_end'][@type='date'][not(@required)]" => 'the last day', "input[@name='ann_icon'][@type='file']" => 'the icon',
    "textarea[@name='ann_desc']" => 'the description', "input[@name='ann_image'][@type='file']" => 'the image', "input[@name='ann_link'][@type='url']" => 'the link' ) as $xp => $what ) {
    rt( "A: the form has no field for $what", $form && 1 === $ax->query( ".//$xp", $form )->length );
}
rt( 'A: the image field does not state the upload rule', false !== strpos( $page, SFAF_Uploads::RULE_MESSAGE ) );
rt( 'A: the icon field does not state the icon rule', false !== strpos( $page, SFAF_Announcements::ICON_RULE_MESSAGE ) );
rt( 'A: the list does not name the three announcements', 3 === $ax->query( "//form[.//input[@name='uc_ann_action'][@value='delete']]" )->length );
$edit = rt_cap( function () use ( $admin, $pride ) { $_GET = array( 'edit_ann' => $pride ); $admin->render_closures_page(); } );
$ex = rt_x( $edit );
rt( 'A: Edit does not load the announcement into the form', 1 === $ex->query( "//form[.//input[@name='uc_ann_action'][@value='save']]//input[@name='ann_id'][@value='$pride']" )->length
    && 1 === $ex->query( "//input[@name='ann_title'][@value='Pride week']" )->length && 1 === $ex->query( "//input[@name='ann_icon_remove']" )->length
    && 1 === $ex->query( "//input[@name='ann_image_remove']" )->length );

$GLOBALS['kit_redirect_throws'] = true;
$post = function ( $p ) use ( $admin ) {
    $_POST = $p + array( 'uc_ann_nonce' => 'n' ); $_FILES = array();
    try { $admin->handle_announcement_actions(); } catch ( KitRedirect $r ) { return $r->getMessage(); }
    return '';
};
$went = $post( array( 'uc_ann_action' => 'save', 'ann_id' => '', 'ann_title' => 'Clinic open late', 'ann_start' => $day( 7 ), 'ann_end' => $day( 8 ), 'ann_desc' => 'Until 9.', 'ann_link' => 'https://sfaf.org/clinic' ) );
$new = array_values( array_filter( SFAF_Announcements::all(), function ( $r ) { return 'Clinic open late' === $r['title']; } ) );
rt( 'A: a POST did not save the announcement: ' . $went, 1 === count( $new ) && $day( 8 ) === $new[0]['end'] && 'https://sfaf.org/clinic' === $new[0]['link'] && false !== strpos( $went, 'msg=ann_saved' ) );
$went = $post( array( 'uc_ann_action' => 'save', 'ann_id' => $pride, 'ann_title' => 'Pride week', 'ann_start' => $today, 'ann_end' => $day( 1 ), 'ann_desc' => 'Changed.', 'ann_link' => '' ) );
$pp = SFAF_Announcements::get( $pride );
rt( 'A: an edit did not keep the icon and image it was not told to remove', $pp && 'Changed.' === $pp['desc'] && $SVG === $pp['icon_svg'] && 77 === $pp['image_id'] && '' === $pp['link'] );
$post( array( 'uc_ann_action' => 'save', 'ann_id' => $pride, 'ann_title' => 'Pride week', 'ann_start' => $today, 'ann_end' => $day( 1 ), 'ann_icon_remove' => '1', 'ann_image_remove' => '1' ) );
$pp = SFAF_Announcements::get( $pride );
rt( 'A: Remove the icon and Remove the image did not', $pp && '' === $pp['icon_svg'] && 0 === $pp['icon_id'] && 0 === $pp['image_id'] );
$count = count( SFAF_Announcements::all() );
$went  = $post( array( 'uc_ann_action' => 'save', 'ann_id' => '', 'ann_title' => '', 'ann_start' => $today ) );
rt( 'A: a save with no title was not refused with its reason: ' . $went, $count === count( SFAF_Announcements::all() ) && false !== strpos( $went, 'msg=ann_failed' )
    && 'Give the announcement a title.' === ( is_array( get_transient( 'sfaf_ann_error_1' ) ) ? get_transient( 'sfaf_ann_error_1' )['message'] : get_transient( 'sfaf_ann_error_1' ) ) ); // the message and what was typed, from 3.142.1
$post( array( 'uc_ann_action' => 'delete', 'ann_id' => $move ) );
rt( 'A: Remove did not remove', null === SFAF_Announcements::get( $move ) );
$GLOBALS['kit_cannot'] = array( 'manage_options' );
$threw = '';
try { $post( array( 'uc_ann_action' => 'delete', 'ann_id' => $far ) ); } catch ( RuntimeException $r ) { $threw = $r->getMessage(); }
rt( 'A: somebody without manage_options removed an announcement', null !== SFAF_Announcements::get( $far ) && 0 === strpos( $threw, 'wp_die' ) );
$GLOBALS['kit_cannot'] = array();

/* ==== E: the cache ====================================================== */
$embed = file_get_contents( "$root/includes/class-sfaf-embed.php" );
foreach ( array( 'add', 'update', 'delete' ) as $w ) {
    rt( "E: the embed cache is not flushed on {$w}_option of the announcements", false !== strpos( $embed, "add_action( '{$w}_option_' . SFAF_Announcements::OPTION, array( \$this, 'flush_cache' ) );" ) );
}
rt( 'E: the announcements class is not loaded by the plugin', false !== strpos( file_get_contents( "$root/sfaf-calendar.php" ), "'includes/class-sfaf-announcements.php'," ) );

echo "\n3.142.0: announcements\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) {
    echo count( $fails ) . " of $n FAILED:\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "$n checks passed: the store, the icon rule, the grid, the list, the sidebar, the script, the screen and the cache.\n";
exit( 0 );
