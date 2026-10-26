<?php
/**
 * 3.122.0: VIEW EVENT PAGE, THE RSVP LIST, THE TOUR, ONE VOLUNTEER BUTTON AND
 * THE STAFF FORM'S LINE.
 *
 *     php .claude/release-31220-test.php
 *
 * The real editor, the real event page and the real staff form through
 * wp-kit.php.
 *
 *   A  Edit event's top: View event page in a new tab and a copy icon carrying
 *      the same link for a published or scheduled event; for a private event
 *      that link is the one "Who can find this event" shows; Preview and no
 *      copy icon for a draft or a pending event; nothing on Add event; each
 *      carries the saved-action mark CLAUDE.md 7 allows
 *   B  the RSVP list beside them, only for an event that takes RSVPs here and
 *      only for somebody the list's route lets in
 *   C  THE TOUR: on Add event and on Edit event in ten states, every card on
 *      the page (a .uc-card, a .uc-bento-card, anything named data-uc-card,
 *      and the action bar) is or sits in a card the tour has a caption for;
 *      every caption names the buttons its card draws and no button it does
 *      not; no em dash
 *   D  the preview: an event WordPress drops is put back only for signed-in
 *      staff who can edit it and only while it is not public; ?uc_preview=ID
 *      asks for that event; the page says Preview, and a scheduled event says
 *      when it goes live
 *   E  the event page: an event with a volunteer link shows one volunteer
 *      button and no count, a Galaxy need included; a Galaxy need with no
 *      volunteer link shows Sign Up and no count
 *   F  the staff form's line under "Who should get RSVP updates?", exactly
 *
 * PLANTs in plant-31220.php.
 */

$GLOBALS['loop_done'] = false;
function have_posts() { return ! $GLOBALS['loop_done']; }
function the_post() { $GLOBALS['loop_done'] = true; $GLOBALS['kit_current'] = $GLOBALS['rt_page_event']; }
function get_header() { echo "<!--header-->\n"; }
function get_footer() { echo "\n<!--footer-->"; }
require __DIR__ . '/wp-kit.php';
foreach ( array( 'wpautop' => 'return "<p>".$a[0]."</p>";', '_n' => 'return $a[2]==1?$a[0]:$a[1];' ) as $f => $b ) { if ( ! function_exists( $f ) ) { eval( "function $f( ...\$a ) { $b }" ); } }
if ( ! function_exists( 'the_title' ) ) { function the_title() { echo get_the_title( get_the_ID() ); } }
if ( ! function_exists( 'the_content' ) ) { function the_content() { echo get_post_field( 'post_content', get_the_ID() ); } }

$fails = array();
$ran   = 0;
function rt( $label, $got, $want ) {
    global $fails, $ran;
    $ran++;
    if ( $got !== $want ) { $fails[] = $label . ': got ' . var_export( $got, true ) . ', wanted ' . var_export( $want, true ); }
}
function rt_cap( $fn ) {
    $depth = ob_get_level();
    ob_start();
    try { $fn(); } catch ( Throwable $e ) { echo 'THREW ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine(); }
    $h = '';
    while ( ob_get_level() > $depth ) { $h = ob_get_clean() . $h; }
    return $h;
}
function rt_dom( $html ) {
    $d = new DOMDocument();
    libxml_use_internal_errors( true );
    $d->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    libxml_clear_errors();
    return new DOMXPath( $d );
}
function rt_text( $n ) { return trim( preg_replace( '/\s+/', ' ', $n->textContent ) ); }

$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
$admin  = new WP_User();
$GLOBALS['kit_query'] = function ( $a ) { return array(); };

/* The events, one per state the top of Edit event and the tour depend on. */
function rt_event( $status, $meta = array(), $series = 11 ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => $status, 'post_title' => 'Dinner ' . $status, 'post_name' => 'dinner-' . $status . '-' . $GLOBALS['kit_next'], 'post_author' => 1, 'post_date' => '2026-12-01 09:00:00' ) );
    foreach ( array_merge( array( '_uc_event_date' => '2026-12-20', '_uc_start_time' => '18:00', '_uc_end_time' => '19:30', '_uc_rsvp_enabled' => '1' ), $meta ) as $k => $v ) {
        update_post_meta( $id, $k, $v );
    }
    if ( $series ) { $GLOBALS['kit_terms'][ $id ]['uc_series'] = array( $series ); }
    return $id;
}
$ev = array(
    'published' => rt_event( 'publish' ),
    'draft'     => rt_event( 'draft' ),
    'scheduled' => rt_event( 'future' ),
    'private'   => rt_event( 'publish', array( '_uc_private' => '1' ) ),
    'no-rsvps'  => rt_event( 'publish', array( '_uc_rsvp_enabled' => '0' ) ),
    'submitted' => rt_event( 'pending', array( SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_COMMUNITY, SFAF_Request::META_EMAIL => 'pat@example.org', SFAF_Request::META_NAME => 'Pat' ) ),
    'imported'  => rt_event( 'publish', array( SFAF_Sources::META_SOURCE => 'eventbrite', SFAF_Sources::META_EXTERNAL_ID => '991', SFAF_Sources::META_SOURCE_URL => 'https://www.eventbrite.com/e/991', '_uc_rsvp_enabled' => '0' ) ),
    'cancelled' => rt_event( 'publish', array( '_uc_cancelled' => '1' ) ),
    'one-off'   => rt_event( 'draft', array(), 0 ),
    'registered' => rt_event( 'publish' ),
);
/* Three confirmed registrations on one event, so its bar has no Delete. Every
   other count the page asks for is the kit's empty answer. */
$GLOBALS['kit_db'] = function ( $method, $sql ) use ( $ev ) {
    if ( 'get_var' === $method && false !== strpos( $sql, 'uc_rsvps' ) && false !== strpos( $sql, 'event_id = ' . $ev['registered'] ) ) { return 3; }
    return 'get_results' === $method ? array() : null;
};
$pages = array( 'add' => rt_cap( function () use ( $portal, $admin ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $admin, 0 ) ); } ) );
foreach ( $ev as $name => $id ) {
    $pages[ $name ] = rt_cap( function () use ( $portal, $admin, $id ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $admin, $id ) ); } );
}
foreach ( $pages as $name => $html ) {
    rt( "setup: $name renders", false === strpos( $html, 'THREW' ) && false !== strpos( $html, 'data-uc-tour-steps' ), true );
}
rt( 'setup: the private event is private', SFAF_Privacy::is_private( $ev['private'] ), true );
rt( 'setup: the cancelled event is cancelled', SFAF_Cancellation::is_cancelled( $ev['cancelled'] ), true );

/* ======================================================================== A */
function rt_top( $html ) {
    $x    = rt_dom( $html );
    $head = $x->query( '//*[contains(concat(" ", normalize-space(@class), " "), " uc-page-head-actions ")]' )->item( 0 );
    $out  = array( 'view' => null, 'preview' => null, 'copy' => null, 'rsvps' => null, 'order' => array() );
    if ( ! $head ) { return $out; }
    foreach ( $x->query( './/a | .//button', $head ) as $n ) {
        $t = rt_text( $n );
        $out['order'][] = '' !== $t ? $t : $n->getAttribute( 'aria-label' );
        if ( 0 === strpos( $t, 'View event page' ) ) { $out['view'] = $n; }
        if ( 0 === strpos( $t, 'Preview' ) ) { $out['preview'] = $n; }
        if ( 'RSVP list' === $t ) { $out['rsvps'] = $n; }
        if ( $n->hasAttribute( 'data-uc-copy-text' ) ) { $out['copy'] = $n; }
    }
    return $out;
}
$top = array();
foreach ( $pages as $name => $html ) { $top[ $name ] = rt_top( $html ); }

rt( 'A: Add event has no View event page, Preview, copy icon or RSVP list', array( $top['add']['view'], $top['add']['preview'], $top['add']['copy'], $top['add']['rsvps'] ), array( null, null, null, null ) );
foreach ( array( 'published', 'scheduled', 'private' ) as $name ) {
    $t = $top[ $name ];
    rt( "A: $name: View event page is there", null !== $t['view'], true );
    if ( ! $t['view'] ) { continue; }
    $url = $t['view']->getAttribute( 'href' );
    rt( "A: $name: it opens in a new tab", array( $t['view']->getAttribute( 'target' ), $t['view']->getAttribute( 'rel' ) ), array( '_blank', 'noopener' ) );
    rt( "A: $name: it is the event's page, not a preview", '' !== $url && false === strpos( $url, 'uc_preview' ), true );
    rt( "A: $name: the copy icon copies the same link", $t['copy'] ? $t['copy']->getAttribute( 'data-uc-copy-text' ) : null, $url );
    rt( "A: $name: the copy icon is named for a screen reader", $t['copy'] ? $t['copy']->getAttribute( 'aria-label' ) : null, 'Copy the event page link' );
    rt( "A: $name: no Preview", null === $t['preview'], true );
    rt( "A: $name: View event page and the copy icon are saved-event actions", array( $t['view']->getAttribute( 'data-uc-saved-action' ), $t['copy'] ? $t['copy']->getAttribute( 'data-uc-saved-action' ) : '' ), array( 'view-page', 'copy-link' ) );
}
rt( 'A: published: the link is the event\'s address', $top['published']['view'] ? $top['published']['view']->getAttribute( 'href' ) : null, get_permalink( $ev['published'] ) );
$px  = rt_dom( $pages['private'] );
$box = $px->query( '//input[@data-uc-private-link]' )->item( 0 );
rt( 'A: private: the card shows its private link', null !== $box, true );
rt( 'A: private: View event page and the copy icon carry the link the card shows', $top['private']['view'] && $box ? array( $top['private']['view']->getAttribute( 'href' ), $top['private']['copy']->getAttribute( 'data-uc-copy-text' ) ) : null, $box ? array( $box->getAttribute( 'value' ), $box->getAttribute( 'value' ) ) : 'no box' );
foreach ( array( 'draft', 'submitted', 'one-off' ) as $name ) {
    $t = $top[ $name ];
    rt( "A: $name: Preview, in a new tab", $t['preview'] ? array( $t['preview']->getAttribute( 'target' ), $t['preview']->getAttribute( 'rel' ), $t['preview']->getAttribute( 'data-uc-saved-action' ) ) : null, array( '_blank', 'noopener', 'preview' ) );
    rt( "A: $name: Preview asks for this event", $t['preview'] ? $t['preview']->getAttribute( 'href' ) : null, add_query_arg( 'uc_preview', $ev[ $name ], home_url( '/' ) ) );
    rt( "A: $name: no copy icon and no View event page", array( $t['copy'], $t['view'] ), array( null, null ) );
}
// libxml's HTML parser knows no HTML5 entities, so &nearr; stays as written; a browser draws the arrow.
rt( 'A: the order is Take the tour, RSVP list, View event page, the copy icon, Back', $top['published']['order'], array( 'Take the tour', 'RSVP list', 'View event page &nearr; (opens in a new tab)', 'Copy the event page link', '← Back' ) );
rt( 'A: link() for a scheduled event is its address, as published', SFAF_Preview::link( $ev['scheduled'] ), array( 'mode' => 'view', 'url' => get_permalink( $ev['scheduled'] ) ) );
rt( 'A: link() for a trashed event is nothing', SFAF_Preview::link( kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'trash' ) ) ), array() );

/* ======================================================================== B */
rt( 'B: published, taking RSVPs: the RSVP list, to this event\'s list', $top['published']['rsvps'] ? array( $top['published']['rsvps']->getAttribute( 'href' ), $top['published']['rsvps']->getAttribute( 'data-uc-saved-action' ) ) : null,
    array( add_query_arg( 'event_id', $ev['published'], kit_call( 'SFAF_Portal', 'url', $portal, array( 'rsvps' ) ) ), 'rsvp-list' ) );
rt( 'B: an event that does not take RSVPs here has no RSVP list link', null === $top['no-rsvps']['rsvps'], true );
rt( 'B: an imported event has no RSVP list link', null === $top['imported']['rsvps'], true );
update_post_meta( $ev['no-rsvps'], SFAF_Register_Elsewhere::META_MODE, 'elsewhere' );
update_post_meta( $ev['no-rsvps'], SFAF_Register_Elsewhere::META_URL, 'https://example.org/register' );
update_post_meta( $ev['no-rsvps'], '_uc_rsvp_enabled', '1' );
$elsewhere = rt_top( rt_cap( function () use ( $portal, $admin, $ev ) { $_GET = array(); kit_call( 'SFAF_Portal', 'render_event_form', $portal, array( $admin, $ev['no-rsvps'] ) ); } ) );
rt( 'B: External RSVP: no RSVP list link', null === $elsewhere['rsvps'], true );
update_post_meta( $ev['no-rsvps'], '_uc_rsvp_enabled', '0' );
delete_post_meta( $ev['no-rsvps'], SFAF_Register_Elsewhere::META_MODE );
delete_post_meta( $ev['no-rsvps'], SFAF_Register_Elsewhere::META_URL );
/* Somebody the list's route refuses: an editor with no hold on the event. */
$GLOBALS['kit_refused'][]           = 41;
$GLOBALS['kit_umeta'][41]['_uc_calendar_role'] = 'editor';
$stranger = get_userdata( 41 );
rt( 'B: setup: the editor cannot open the event', kit_call( 'SFAF_Portal', 'can_edit_event', $portal, array( $stranger, $ev['published'] ) ), false );
$links = rt_cap( function () use ( $portal, $stranger, $ev ) { kit_call( 'SFAF_Portal', 'render_event_page_links', $portal, array( $stranger, $ev['published'] ) ); } );
rt( 'B: and is not given the RSVP list link', false === strpos( $links, 'RSVP list' ), true );

/* ======================================================================== C */
$CARD = '//*[contains(concat(" ", normalize-space(@class), " "), " uc-card ") or contains(concat(" ", normalize-space(@class), " "), " uc-bento-card ") or @data-uc-card]';
foreach ( $pages as $name => $html ) {
    $x     = rt_dom( $html );
    $start = $x->query( '//*[@data-uc-tour-start]' )->item( 0 );
    $steps = $start ? json_decode( $start->getAttribute( 'data-uc-tour-steps' ), true ) : array();
    rt( "C: $name: the tour has steps", is_array( $steps ) && count( $steps ) > 5, true );
    $by = array();
    foreach ( (array) $steps as $s ) {
        $by[ $s['card'] ] = $s;
        rt( "C: $name: {$s['card']} has a name and a caption", '' !== trim( (string) $s['name'] ) && '' !== trim( (string) $s['text'] ), true );
        rt( "C: $name: {$s['card']}'s caption has no em dash", false === strpos( $s['name'] . $s['text'], "\u{2014}" ), true );
    }
    $uncaptioned = array();
    foreach ( $x->query( $CARD ) as $card ) {
        $named = '';
        for ( $p = $card; $p && XML_ELEMENT_NODE === $p->nodeType; $p = $p->parentNode ) {
            if ( 'dialog' === strtolower( $p->nodeName ) ) { $named = '(dialog)'; break; }
            if ( $p->hasAttribute( 'data-uc-card' ) && isset( $by[ $p->getAttribute( 'data-uc-card' ) ] ) ) { $named = $p->getAttribute( 'data-uc-card' ); break; }
        }
        if ( '' === $named ) {
            $h = $x->query( './/h2 | .//h3', $card )->item( 0 );
            $uncaptioned[] = ( $card->getAttribute( 'data-uc-card' ) ?: $card->getAttribute( 'class' ) ) . ( $h ? ' "' . rt_text( $h ) . '"' : '' );
        }
    }
    rt( "C: $name: every card on the page has a caption", $uncaptioned, array() );
    $bar = $x->query( '//*[@data-uc-editor-bar]' )->item( 0 );
    rt( "C: $name: the action bar is a step", $bar && 'actions' === $bar->getAttribute( 'data-uc-card' ) && isset( $by['actions'] ), true );

    /* Each caption names what its card draws, and nothing it does not. */
    $words = array();
    if ( $bar ) {
        foreach ( $x->query( './/button | .//summary', $bar ) as $b ) { $words[ rt_text( $b ) ] = true; }
    }
    $said = isset( $by['actions'] ) ? $by['actions']['text'] : '';
    foreach ( array( 'Save changes', 'Save draft', 'Publish', 'Approve', 'Reject', 'Cancel event', 'Delete', 'Submit for Review' ) as $w ) {
        rt( "C: $name: the bar draws \"$w\" exactly when its caption names it", isset( $words[ $w ] ), false !== strpos( $said, $w ) );
    }
    $t   = rt_top( $html );
    $pls = isset( $by['page-links'] ) ? $by['page-links']['text'] : '';
    rt( "C: $name: View event page is captioned exactly when it is drawn", null !== $t['view'], false !== strpos( $pls, 'View event page' ) );
    rt( "C: $name: the copy icon is captioned exactly when it is drawn", null !== $t['copy'], false !== strpos( $pls, 'copy icon' ) );
    rt( "C: $name: Preview is captioned exactly when it is drawn", null !== $t['preview'], false !== strpos( $pls, 'Preview opens' ) );
    rt( "C: $name: RSVP list is captioned exactly when it is drawn", null !== $t['rsvps'], false !== strpos( $pls, 'RSVP list' ) );
    $sched = isset( $by['schedule'] ) ? $by['schedule']['text'] : '';
    rt( "C: $name: Repeats is captioned exactly when it is drawn", false !== strpos( $html, '<span class="uc-field-label">Repeats' ), false !== strpos( $sched, 'Repeats' ) );
    rt( "C: $name: Edit the schedule is captioned exactly when it is drawn", false !== strpos( $html, '<span>Edit the schedule</span>' ), false !== strpos( $sched, 'Edit the schedule' ) );
    $priv = isset( $by['privacy'] ) ? $by['privacy']['text'] : '';
    rt( "C: $name: the private link's Copy is captioned exactly when it is drawn", false !== strpos( $html, 'data-uc-private-link-row' ), 0 === strpos( $priv, 'This event is private' ) );
    $ser = isset( $by['series'] ) ? $by['series']['text'] : '';
    rt( "C: $name: the series offer is captioned exactly when it is drawn", false !== strpos( $html, 'data-uc-prefill-panel' ), false !== strpos( $ser, 'You are offered its details' ) );
    $reg = isset( $by['registration'] ) ? $by['registration']['text'] : '';
    rt( "C: $name: the RSVP choice is captioned exactly when it is drawn", false !== strpos( $html, 'data-uc-reg-choice' ), false !== strpos( $reg, 'RSVP Here' ) );
    $not = isset( $by['notifications'] ) ? $by['notifications']['text'] : '';
    rt( "C: $name: Choose emails is captioned exactly when it is drawn", false !== strpos( $html, '<span>Choose emails</span>' ), false !== strpos( $not, 'Choose emails' ) );
}
/* The states above drew what they were set up to draw, so the sweep had them to read. */
rt( 'C: setup: the imported event draws its panel', false !== strpos( $pages['imported'], 'data-uc-card="imported"' ), true );
rt( 'C: setup: the public submission draws its panel', false !== strpos( $pages['submitted'], 'data-uc-card="submitted"' ), true );
rt( 'C: setup: the private event draws its link row', false !== strpos( $pages['private'], 'data-uc-private-link-row' ), true );
rt( 'C: setup: the one-off draft draws Repeats', false !== strpos( $pages['one-off'], '<span class="uc-field-label">Repeats' ), true );
rt( 'C: setup: the event with registrations has no Delete', false === strpos( $pages['registered'], 'data-uc-saved-action="delete"' ) && SFAF_Announce::has_registrations( $ev['registered'] ), true );
rt( 'C: setup: the submission draws Approve', false !== strpos( $pages['submitted'], '>Approve</button>' ), true );
foreach ( array( 'Moving the event to another series' => 'published', 'Choose the series first' => 'add' ) as $want => $name ) {
    $x = rt_dom( $pages[ $name ] );
    $s = json_decode( $x->query( '//*[@data-uc-tour-start]' )->item( 0 )->getAttribute( 'data-uc-tour-steps' ), true );
    rt( "C: $name: the series step says \"$want\"", 0 === strpos( $s[ array_search( 'series', array_column( $s, 'card' ), true ) ]['text'], $want ), true );
}
$src = file_get_contents( $GLOBALS['kit_root'] . '/includes/class-sfaf-portal.php' );
rt( 'C: no caption still says the plugin creates every date', false === strpos( $src, 'the plugin creates every date for you' ), true );

/* ======================================================================== D */
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } } // a front-end request
class RT_Query { public $is_single = true; private $main; function __construct( $m = true ) { $this->main = $m; } function is_main_query() { return $this->main; } }
function rt_restore( $post, $q = null ) {
    $q = $q ? $q : new RT_Query();
    SFAF_Preview::hold( array( $post ), $q );
    return SFAF_Preview::restore( array(), $q );
}
$draft = get_post( $ev['draft'] );
$GLOBALS['kit_user_id'] = 1;
rt( 'D: a draft is put back for a calendar admin', rt_restore( $draft ), array( $draft ) );
rt( 'D: a scheduled event too', rt_restore( get_post( $ev['scheduled'] ) ), array( get_post( $ev['scheduled'] ) ) );
rt( 'D: a pending submission too', rt_restore( get_post( $ev['submitted'] ) ), array( get_post( $ev['submitted'] ) ) );
$GLOBALS['kit_logged_out'] = true;
$GLOBALS['kit_user_id']    = 0;
rt( 'D: not for a visitor', rt_restore( $draft ), array() );
unset( $GLOBALS['kit_logged_out'] );
$GLOBALS['kit_user_id'] = 41;
rt( 'D: not for an editor who cannot edit it', rt_restore( $draft ), array() );
$GLOBALS['kit_refused'][] = 42;
$GLOBALS['kit_user_id']   = 42;
rt( 'D: not for a signed-in person with no calendar role', rt_restore( $draft ), array() );
$GLOBALS['kit_refused'][] = 43;
$GLOBALS['kit_umeta'][43]['_uc_calendar_role'] = 'editor';
$own = rt_event( 'draft' );
kit_write_post( array( 'ID' => $own, 'post_author' => 43 ) );
$GLOBALS['kit_user_id'] = 43;
rt( 'D: for an editor who created it', rt_restore( get_post( $own ) ), array( get_post( $own ) ) );
$GLOBALS['kit_user_id'] = 1;
$trash = get_post( kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'trash' ) ) );
rt( 'D: never a trashed event', rt_restore( $trash ), array() );
$page = get_post( kit_write_post( array( 'post_type' => 'page', 'post_status' => 'draft' ) ) );
rt( 'D: never anything but an event', rt_restore( $page ), array() );
rt( 'D: never a query that is not the main one', rt_restore( $draft, new RT_Query( false ) ), array() );
$found = array( get_post( $ev['published'] ) );
SFAF_Preview::hold( $found, new RT_Query() );
rt( 'D: a page WordPress already found is left as found', SFAF_Preview::restore( $found, new RT_Query() ), $found );
rt( 'D: ?uc_preview=ID asks for that event', SFAF_Preview::request( array( 'uc_preview' => (string) $ev['draft'] ) ), array( 'post_type' => 'uc_event', 'p' => $ev['draft'], 'uc_preview' => $ev['draft'] ) );
rt( 'D: and nothing else is touched', SFAF_Preview::request( array( 'pagename' => 'calendar' ) ), array( 'pagename' => 'calendar' ) );
rt( 'D: the query var is public', in_array( 'uc_preview', SFAF_Preview::query_vars( array() ), true ), true );
$b = SFAF_Preview::banner( $ev['draft'] );
rt( 'D: a draft\'s page says Preview', false !== strpos( $b, '<p class="uc-preview-head">Preview</p>' ) && false !== strpos( $b, 'Visitors will see it once the event is published.' ), true );
$b = SFAF_Preview::banner( $ev['scheduled'] );
rt( 'D: a scheduled event\'s page says when it goes live', false !== strpos( $b, 'until it goes live on ' . sfaf_ap_datetime( '2026-12-01 09:00:00', 'full' ) . '.' ), true );
rt( 'D: a published event\'s page says nothing', SFAF_Preview::banner( $ev['published'] ), '' );

/* ======================================================================== E */
function rt_page( $meta ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Beach clean', 'post_name' => 'beach-' . $GLOBALS['kit_next'], 'post_content' => '<p>Bring gloves.</p>' ) );
    $GLOBALS['rt_page_event'] = $id;
    foreach ( array_merge( array( '_uc_event_date' => '2026-12-05', '_uc_start_time' => '10:00', '_uc_end_time' => '12:00' ), $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
    $GLOBALS['kit_query'] = function ( $a ) use ( $id ) { return array( $id ); };
    $GLOBALS['loop_done'] = false;
    ob_start();
    include $GLOBALS['kit_root'] . '/templates/single-uc_event.php';
    return (string) ob_get_clean();
}
update_option( 'uc_settings', array( 'galaxy_portal_url' => 'https://volunteer.example.org' ) );
$cases = array(
    'a volunteer link'                     => array( array( '_uc_volunteer_url' => 'https://example.org/help' ), 1, 'Volunteer for this event' ),
    'a Galaxy need with a volunteer link'  => array( array( '_uc_volunteer_url' => 'https://example.org/help', '_uc_galaxy_need_id' => '77' ), 1, 'Volunteer for this event' ),
    'a Galaxy need with no volunteer link' => array( array( '_uc_galaxy_need_id' => '77' ), 1, 'Sign Up to Volunteer' ),
    'neither'                              => array( array(), 0, '' ),
);
foreach ( $cases as $label => $c ) {
    $html = rt_page( $c[0] );
    $n    = substr_count( $html, '>Volunteer for this event' ) + substr_count( $html, '>Sign Up to Volunteer' );
    rt( "E: $label: volunteer buttons", $n, $c[1] );
    if ( '' !== $c[2] ) { rt( "E: $label: the one button is $c[2]", false !== strpos( $html, $c[2] ), true ); }
    rt( "E: $label: no count of spots", 0 === preg_match( '/spots? (remaining|left)/i', $html ) && false === strpos( $html, 'uc-galaxy-spots' ), true );
    rt( "E: $label: no Preview band on a published event", false === strpos( $html, 'data-uc-preview' ), true );
}
$GLOBALS['rt_page_event'] = $ev['draft'];
$GLOBALS['kit_query'] = function ( $a ) use ( $ev ) { return array( $ev['draft'] ); };
$GLOBALS['loop_done'] = false;
ob_start();
include $GLOBALS['kit_root'] . '/templates/single-uc_event.php';
$prev = (string) ob_get_clean();
rt( 'E: a draft\'s page carries the Preview band above the title', false !== strpos( $prev, 'data-uc-preview' ) && strpos( $prev, 'data-uc-preview' ) < strpos( $prev, 'uc-single-header' ), true );

/* ======================================================================== F */
$staff = rt_cap( function () { kit_call( 'SFAF_Request', 'render_form', null, array( 'tok', 'pat@sfaf.org', array(), array() ) ); } );
$field = (string) strstr( $staff, 'id="uc-rsvp-updates-label"' );
$field = substr( $field, 0, (int) strpos( $field, '</div>' ) );
rt( 'F: the line under Who should get RSVP updates?', false !== strpos( $field, '<span class="uc-hint">They&#039;ll get the RSVP count the day before and the list of who&#039;s coming two hours before.</span>' ), true );
rt( 'F: and it is the only hint there', substr_count( $field, 'class="uc-hint"' ), 1 );

echo "\n3.122.0: View event page, the RSVP list, the tour, one volunteer button, the staff form's line\n" . str_repeat( '=', 72 ) . "\n";
if ( $fails ) { echo count( $fails ) . " of $ran failed:\n  - " . implode( "\n  - ", $fails ) . "\n"; exit( 1 ); }
echo "$ran assertions passed. Edit event opens the event page or its preview and copies its link, links the RSVP list where\nthere is one, and every card on Add event and Edit event in eleven states has a caption that names what it draws; the\npreview is only for staff who can edit the event; the event page has one volunteer button and no count.\n";
exit( 0 );
