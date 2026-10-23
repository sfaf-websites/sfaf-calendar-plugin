<?php
/**
 * 3.114.0: THE WORDPRESS EDITOR STOPS DAMAGING EVENTS, ONLY ADMINISTRATORS
 * USE IT, AND EVENTBRITE'S FULL DESCRIPTION.
 *
 *     php .claude/release-31140-test.php
 *
 * The real plugin through wp-kit.php.
 *
 *   A  the WordPress editor's Location box is read-only and its save writes
 *      no address; the schema 16 pass takes the repeated place name off the
 *      front of an address, and nothing else
 *   B  a WordPress editor save keeps an FAQ answer's links and formatting
 *   C  anybody but an administrator is sent to caladmin from the event list,
 *      Add New and the editor, and refused on the REST route and Quick Edit
 *   D  Eventbrite: the full description from the structured content, with
 *      its formatting; the summary leads only when the body does not open
 *      with it; the event's own HTML, then text, when there is none
 *   E  a fetch rewrites a description it wrote, and never one a person
 *      changed, before or after 3.114.0
 *
 * The embed is embed-identity-test.php's. PLANTs in plant-31140.php.
 */

require __DIR__ . '/wp-kit.php';
if ( ! function_exists( 'wpautop' ) ) { function wpautop( $s ) { return '<p>' . str_replace( "\n\n", "</p>\n<p>", trim( (string) $s ) ) . '</p>'; } }
if ( ! function_exists( 'get_post_type_object' ) ) { function get_post_type_object( $t ) { return (object) array( 'rest_base' => false ); } }
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private $m; private $r;
        public function __construct( $m, $r ) { $this->m = $m; $this->r = $r; }
        public function get_method() { return $this->m; }
        public function get_route() { return $this->r; }
    }
}

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function rt_cap( $fn ) { ob_start(); try { $fn(); } catch ( KitRedirect $e ) { } return (string) ob_get_clean(); }
$root = $GLOBALS['kit_root'];
$pt   = new SFAF_Post_Types();

/* ======================================================================== A */
$ev = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Coffee' ) );
update_post_meta( $ev, '_uc_location_name', 'Strut' );
update_post_meta( $ev, '_uc_location', '470 Castro St' );
$box = rt_cap( function () use ( $pt, $ev ) { $pt->render_meta_box( get_post( $ev ) ); } );
rt( 'A: the Location box shows the place', (bool) preg_match( '#<input type="text" id="uc_location" value="Strut, 470 Castro St" readonly />#', $box ), true );
rt( 'A: and posts nothing', false === strpos( $box, 'name="uc_location"' ), true );
$_POST = array( 'uc_event_nonce' => 'n', 'uc_location' => 'Strut, 470 Castro St', 'uc_event_date' => '2026-12-01' );
rt_cap( function () use ( $pt, $ev ) { $pt->save_meta( $ev ); } );
rt( 'A: a save leaves the address alone', get_post_meta( $ev, '_uc_location', true ), '470 Castro St' );
rt( 'A: and saves the rest', get_post_meta( $ev, '_uc_event_date', true ), '2026-12-01' );

foreach ( array(
    array( 'Strut, Strut, 470 Castro St', 'Strut', '470 Castro St' ),
    array( 'strut, STRUT, 470 Castro St', 'Strut', '470 Castro St' ),
    array( 'Strut', 'Strut', '' ),
    array( 'Strut, Strut', 'Strut', '' ),
    array( '470 Castro St', 'Strut', '470 Castro St' ),
    array( 'Strutton Ave, 1 Main', 'Strut', 'Strutton Ave, 1 Main' ),
    array( 'Strut Hall, 1 Main', 'Strut', 'Strut Hall, 1 Main' ),
    array( '1 Main St, Strut', 'Strut', '1 Main St, Strut' ),
    array( 'Anything', '', 'Anything' ),
) as $c ) {
    rt( 'A: "' . $c[0] . '" under "' . $c[1] . '"', sfaf_strip_repeated_place_name( $c[0], $c[1] ), $c[2] );
}
$GLOBALS['kit_query'] = function ( $a ) {
    $out = array();
    foreach ( $GLOBALS['kit_posts'] as $id => $p ) {
        if ( 'uc_event' === $p->post_type && '' !== (string) get_post_meta( $id, '_uc_location_name', true ) ) { $out[] = (int) $id; }
    }
    return $out;
};
$d1 = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Damaged' ) );
update_post_meta( $d1, '_uc_location_name', 'Strut' );
update_post_meta( $d1, '_uc_location', 'Strut, Strut, 470 Castro St' );
$d2 = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'draft', 'post_title' => 'Only the name' ) );
update_post_meta( $d2, '_uc_location_name', 'The Center' );
update_post_meta( $d2, '_uc_location', 'The Center' );
delete_option( 'sfaf_location_name_migration' );
rt( 'A: the pass touches the two damaged events', sfaf_migrate_location_name_repeat(), 2 );
rt( 'A: and repairs the address', get_post_meta( $d1, '_uc_location', true ), '470 Castro St' );
rt( 'A: an address that was only the name is empty', get_post_meta( $d2, '_uc_location', true ), '' );
rt( 'A: an undamaged one is left', get_post_meta( $ev, '_uc_location', true ), '470 Castro St' );
$rec = get_option( 'sfaf_location_name_migration' );
rt( 'A: and it lists them', isset( $rec['ids'] ) ? $rec['ids'] : null, array( $d1, $d2 ) );
update_post_meta( $d1, '_uc_location', 'Strut, 1 Other St' );
rt( 'A: it runs once', sfaf_migrate_location_name_repeat(), 0 );
rt( 'A: schema 16', SFAF_DB_VERSION, '16' );
$main = file_get_contents( $root . '/sfaf-calendar.php' );
rt( 'A: the installer runs the pass', false !== strpos( $main, "    sfaf_migrate_location_name_repeat();\n\n    update_option( 'sfaf_db_version', SFAF_DB_VERSION );" ), true );

/* ======================================================================== B */
$rows = kit_call( 'SFAF_Post_Types', 'sanitize_faq_post', $pt, array( array(
    array( 'question' => 'Parking?', 'answer' => 'See <a href="https://sfaf.org/parking">the <strong>map</strong></a>, or <em>ask</em>.' . "\n" . 'Second line.' ),
) ) );
rt( 'B: the answer keeps its link and formatting', $rows[0]['answer'], 'See <a href="https://sfaf.org/parking">the <strong>map</strong></a>, or <em>ask</em>.' . "\n" . 'Second line.' );
$pts = file_get_contents( $root . '/includes/class-sfaf-post-types.php' );
rt( 'B: through the sanitizer caladmin uses', false !== strpos( $pts, "\$a = isset( \$row['answer'] ) ? trim( SFAF_Rich_Text::sanitize( (string) \$row['answer'] ) ) : '';" ), true );

/* ======================================================================== C */
$GLOBALS['kit_redirect_throws'] = true;
global $pagenow;
foreach ( array(
    array( 'post.php', array( 'post' => $ev, 'action' => 'edit' ), '/caladmin/events/edit/' . $ev ),
    array( 'post-new.php', array( 'post_type' => 'uc_event' ), '/caladmin/events/new' ),
    array( 'edit.php', array( 'post_type' => 'uc_event' ), '/caladmin/events' ),
) as $c ) {
    $GLOBALS['kit_cannot'] = array( 'manage_options' );
    $pagenow = $c[0]; $_GET = $c[1]; $_REQUEST = $c[1]; $GLOBALS['kit_redirect'] = '';
    rt_cap( function () use ( $pt ) { $pt->send_to_caladmin(); } );
    rt( 'C: ' . $c[0] . ' sends an editor to caladmin', (string) $GLOBALS['kit_redirect'], home_url( $c[2] ) );
    $GLOBALS['kit_cannot'] = array(); $GLOBALS['kit_redirect'] = '';
    rt_cap( function () use ( $pt ) { $pt->send_to_caladmin(); } );
    rt( 'C: ' . $c[0] . ' keeps an administrator', (string) $GLOBALS['kit_redirect'], '' );
}
$GLOBALS['kit_cannot'] = array( 'manage_options' );
$pagenow = 'edit.php'; $_GET = array(); $_REQUEST = array(); $GLOBALS['kit_redirect'] = '';
rt_cap( function () use ( $pt ) { $pt->send_to_caladmin(); } );
rt( 'C: the Posts list is not ours', (string) $GLOBALS['kit_redirect'], '' );
$GLOBALS['kit_redirect_throws'] = false;

$w = $pt->refuse_rest_write( null, null, new WP_REST_Request( 'POST', '/wp/v2/uc_event/' . $ev ) );
rt( 'C: a REST write is refused for an editor', is_wp_error( $w ) ? $w->get_error_code() : $w, 'sfaf_events_in_caladmin' );
rt( 'C: a REST read is not', $pt->refuse_rest_write( null, null, new WP_REST_Request( 'GET', '/wp/v2/uc_event' ) ), null );
rt( 'C: nor another route', $pt->refuse_rest_write( null, null, new WP_REST_Request( 'POST', '/wp/v2/posts/3' ) ), null );
$GLOBALS['kit_cannot'] = array();
rt( 'C: an administrator may write', $pt->refuse_rest_write( null, null, new WP_REST_Request( 'DELETE', '/wp/v2/uc_event/' . $ev ) ), null );
rt( 'C: the three ways in are hooked', false !== strpos( $pts, "foreach ( array( 'load-post.php', 'load-post-new.php', 'load-edit.php' ) as \$screen ) {" )
    && false !== strpos( $pts, "add_filter( 'rest_pre_dispatch', array( \$this, 'refuse_rest_write' ), 10, 3 );" )
    && false !== strpos( $pts, "add_action( 'wp_ajax_inline-save', array( \$this, 'refuse_quick_edit' ), 0 );" ), true );
rt( 'C: an administrator is manage_options', (bool) preg_match( '#function wp_editor_allowed\(\) \{\s*return current_user_can\( \'manage_options\' \);#', $pts ), true );

/* ======================================================================== D */
$sc = array( 'modules' => array(
    array( 'type' => 'text', 'data' => array( 'body' => array( 'text' => '<p>What\'s next in HIV, and how we get there.</p><p><strong>Overview</strong></p><p>Join <em>our</em> panel. <a href="https://x.org">More</a><br>Line two.</p>' ) ) ),
    array( 'type' => 'image', 'data' => array( 'image' => array( 'url' => 'https://img/1.jpg' ) ) ),
    array( 'type' => 'text', 'data' => array( 'body' => array( 'text' => '<p>Moderator: A. Speakers: B, C.</p>' ) ) ),
) );
$full = SFAF_Eventbrite::structured_html( $sc );
rt( 'D: the text modules, in order, images left out', $full, '<p>What\'s next in HIV, and how we get there.</p><p><strong>Overview</strong></p><p>Join <em>our</em> panel. <a href="https://x.org">More</a><br>Line two.</p>' . "\n" . '<p>Moderator: A. Speakers: B, C.</p>' );
rt( 'D: nothing to read is empty', SFAF_Eventbrite::structured_html( array( 'modules' => array() ) ), '' );
$eb = new SFAF_Source_Eventbrite();
function rt_row( $raw, $structured ) {
    $raw = array_merge( array( 'id' => '9001', 'name' => array( 'text' => 'The roadmap to zero: Virtual event' ), 'start' => array( 'local' => '2026-11-12T12:00:00' ), 'end' => array( 'local' => '2026-11-12T13:00:00' ) ), $raw );
    $row = SFAF_Eventbrite::normalize_event( $raw );
    $row['structured_html'] = $structured;
    return $row;
}
$sum = "What's next in HIV, and how we get there";
$e = $eb->normalize( rt_row( array( 'summary' => $sum, 'description' => array( 'text' => $sum, 'html' => '<p>' . $sum . '</p>' ) ), $full ) );
rt( 'D: the full description, and the summary dropped when the body opens with it', $e['description'], $full );
$e = $eb->normalize( rt_row( array( 'summary' => 'A short line', 'description' => array( 'text' => 'A short line' ) ), '<p>Overview</p><p>Body.</p>' ) );
rt( 'D: a summary the body does not repeat leads it', $e['description'], "<p>A short line</p>\n<p>Overview</p><p>Body.</p>" );
$e = $eb->normalize( rt_row( array( 'summary' => 'A different line', 'description' => array( 'text' => 'Old event text', 'html' => '<p>Old event <b>text</b></p>' ) ), '' ) );
rt( 'D: with no structured content, the event\'s own HTML', $e['description'], "<p>A different line</p>\n<p>Old event <b>text</b></p>" );
$e = $eb->normalize( rt_row( array( 'summary' => '', 'description' => array( 'text' => "Para one\n\nPara two" ) ), '' ) );
rt( 'D: then its text, as paragraphs', $e['description'], "<p>Para one</p>\n<p>Para two</p>" );
$e = $eb->normalize( rt_row( array( 'summary' => 'Only this' ), '' ) );
rt( 'D: then the summary alone', $e['description'], '<p>Only this</p>' );
$e = $eb->normalize( rt_row( array( 'summary' => $sum, 'description' => array( 'text' => $sum ) ), $full ) );
rt( 'D: what 3.113.8 stored is known', $e['description_was'], array( $sum ) );
rt( 'D: the opening line is compared as text', SFAF_Source_Eventbrite::opens_with( '<p><strong>Hello</strong> world.</p>', 'hello WORLD' ), true );
rt( 'D: and not as a prefix of a word', SFAF_Source_Eventbrite::opens_with( '<p>Overview</p>', 'Over' ), false );
$src = file_get_contents( $root . '/includes/class-sfaf-source-eventbrite.php' );
rt( 'D: every fetched event and a single refetch get the structured content', 2 === substr_count( $src, 'self::with_structured_html( $token, ' ), true );

/* ======================================================================== E */
$e = $eb->normalize( rt_row( array( 'summary' => $sum, 'description' => array( 'text' => $sum ) ), $full ) );
$e['external_source'] = 'eventbrite';
$old = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'The roadmap to zero: Virtual event', 'post_content' => $sum ) );
SFAF_Sources::update_event( $old, $e );
rt( 'E: what 3.113.8 imported is replaced with the full description', get_post_field( 'post_content', $old ), $full );
rt( 'E: and remembered', get_post_meta( $old, SFAF_Sources::META_DESC_HASH, true ), md5( $full ) );
kit_write_post( array( 'ID' => $old, 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_content' => $full . '<p>Added by hand.</p>' ) );
SFAF_Sources::update_event( $old, $e );
rt( 'E: an edit after that is kept', get_post_field( 'post_content', $old ), $full . '<p>Added by hand.</p>' );
$hand = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Edited before', 'post_content' => '<p>Written by a person.</p>' ) );
SFAF_Sources::update_event( $hand, $e );
rt( 'E: an edit from before 3.114.0 is kept', get_post_field( 'post_content', $hand ), '<p>Written by a person.</p>' );
rt( 'E: and no fingerprint is taken for it', get_post_meta( $hand, SFAF_Sources::META_DESC_HASH, true ), '' );
$empty = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Empty', 'post_content' => '' ) );
SFAF_Sources::update_event( $empty, $e );
rt( 'E: an empty description is filled', get_post_field( 'post_content', $empty ), $full );
$e2 = $e; $e2['description'] = $full . '<p>Speakers changed.</p>';
SFAF_Sources::update_event( $empty, $e2 );
rt( 'E: and follows the source while nobody edits it', get_post_field( 'post_content', $empty ), $full . '<p>Speakers changed.</p>' );
$ss = file_get_contents( $root . '/includes/class-sfaf-sources.php' );
rt( 'E: a new import is remembered too', 2 === substr_count( $ss, 'self::remember_description( ' ), true );

echo "\n3.114.0: the WordPress editor, administrators only, Eventbrite's full description\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. The WordPress editor no longer writes the address and keeps FAQ formatting, the repeated\nplace name is taken off once, everyone but an administrator is sent to caladmin or refused, and an Eventbrite event\ngets its full description with formatting, never over a person's edit.\n";
exit( 0 );
