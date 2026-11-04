<?php
/**
 * 3.136.0: THE EVENTS LIST'S RSVP COLUMN, ONE PILL.
 *
 *     php .claude/release-31360-test.php
 *
 * The real events_table() on wp-kit.php. Every event that takes RSVPs here
 * shows a pill with its number, "0 RSVPs", "1 RSVP", "12 RSVPs", never "View
 * RSVPs", a person before it; a link to the event's RSVP list for whoever may
 * open it and the same pill as text for anyone else; a dash on an event that
 * takes none here (switched off, or Register on another site). The browser
 * half is release-31360-live.php; PLANTs in plant-31360.php.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
$ran   = 0;
function rt( $label, $ok ) {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) { $fails[] = $label; }
}
function rt_cap( $fn ) {
    $d = ob_get_level();
    ob_start();
    try { $fn(); } catch ( Throwable $t ) { while ( ob_get_level() > $d ) { ob_end_clean(); } return 'THREW ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine(); }
    $h = '';
    while ( ob_get_level() > $d ) { $h = ob_get_clean() . $h; }
    return $h;
}
function rt_x( $html ) {
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    libxml_clear_errors();
    return new DOMXPath( $doc );
}

kit_reset();
$GLOBALS['kit_db'] = function ( $method, $sql ) { return 'get_results' === $method ? array() : ( 'get_var' === $method ? 0 : null ); };
function rt_event( $id, $title, $rsvps ) {
    kit_write_post( array( 'ID' => $id, 'post_title' => $title, 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_author' => 1 ) );
    update_post_meta( $id, '_uc_event_date', '2026-12-0' . ( $id % 9 ) );
    if ( $rsvps ) { update_post_meta( $id, '_uc_rsvp_enabled', '1' ); }
}
rt_event( 901, 'None yet', true );
rt_event( 902, 'Twelve', true );
rt_event( 903, 'Just one', true );
rt_event( 904, 'Switched off', false );
rt_event( 905, 'Elsewhere', true );
update_post_meta( 905, SFAF_Register_Elsewhere::META_MODE, 'elsewhere' );
update_post_meta( 905, SFAF_Register_Elsewhere::META_URL, 'https://example.org/register' );
$store =& sfaf_rsvp_count_store();
$store[901] = 0; $store[902] = 12; $store[903] = 1; $store[904] = 3; $store[905] = 4;
$ids    = array( 901, 902, 903, 904, 905 );
$portal = ( new ReflectionClass( 'SFAF_Portal' ) )->newInstanceWithoutConstructor();
function rt_cells( $portal, $ids, $user ) {
    $html = rt_cap( function () use ( $portal, $ids, $user ) { kit_call( 'SFAF_Portal', 'events_table', $portal, array( $ids, $user ) ); } );
    $x    = rt_x( $html );
    $out  = array( '_html' => $html );
    foreach ( $x->query( '//tr[td]' ) as $tr ) {
        $title = '';
        foreach ( $x->query( './/td/a[contains(@class,"uc-tlink")]', $tr ) as $a ) { $title = trim( $a->textContent ); break; }
        // The RSVP cell is the one before the status pill's.
        $pill = $x->query( './/*[@data-uc-rsvp-pill]', $tr );
        $none = $x->query( './/*[@data-uc-rsvp-none]', $tr );
        if ( $pill->length ) {
            $p = $pill->item( 0 );
            $out[ $title ] = array( 'tag' => $p->nodeName, 'text' => trim( preg_replace( '/\s+/', ' ', $p->textContent ) ), 'href' => $p->getAttribute( 'href' ),
                'class' => $p->getAttribute( 'class' ), 'icon' => $x->query( './/svg[contains(@class,"sfaf-icon")][@aria-hidden="true"]', $p )->length );
        } elseif ( $none->length ) {
            $out[ $title ] = array( 'tag' => 'dash', 'text' => trim( $none->item( 0 )->textContent ) );
        } else {
            $out[ $title ] = array( 'tag' => 'nothing' );
        }
    }
    return $out;
}

/* ---- Who may open the lists: an administrator. ---- */
$admin = wp_get_current_user();
$c = rt_cells( $portal, $ids, $admin );
rt( 'the Events list renders', 0 !== strpos( $c['_html'], 'THREW' ) );
rt( 'none yet: "0 RSVPs", never "View RSVPs"', '0 RSVPs' === ( $c['None yet']['text'] ?? '' ) && false === strpos( $c['_html'], 'View RSVPs' ) );
rt( '"12 RSVPs"', '12 RSVPs' === ( $c['Twelve']['text'] ?? '' ) );
rt( '"1 RSVP"', '1 RSVP' === ( $c['Just one']['text'] ?? '' ) );
foreach ( array( 'None yet' => 901, 'Twelve' => 902, 'Just one' => 903 ) as $t => $id ) {
    rt( "$t: a link to its own RSVP list", 'a' === ( $c[ $t ]['tag'] ?? '' ) && false !== strpos( $c[ $t ]['href'], 'event_id=' . $id ) && false !== strpos( $c[ $t ]['href'], 'rsvps' ) );
    rt( "$t: the pill, a person before the number", 'uc-rsvp-pill' === ( $c[ $t ]['class'] ?? '' ) && 1 === ( $c[ $t ]['icon'] ?? 0 ) );
}
rt( 'switched off: a dash', array( 'tag' => 'dash', 'text' => "\u{2013}" ) === ( $c['Switched off'] ?? null ) );
rt( 'registering on another site: a dash', SFAF_Register_Elsewhere::is_on( 905 ) && 'dash' === ( $c['Elsewhere']['tag'] ?? '' ) );
rt( 'no button any more', false === strpos( $c['_html'], 'uc-rsvp-count-btn' ) && false === strpos( $c['_html'], 'data-uc-rsvp-count-btn' ) );

/* ---- Somebody who may not open them: the same pill, as text. ---- */
$GLOBALS['kit_refused'] = array( 7 );
$GLOBALS['kit_cannot']  = array( 'manage_options' );
$other = new WP_User();
$other->ID = 7;
$c = rt_cells( $portal, $ids, $other );
rt( 'not theirs: the list still renders', 0 !== strpos( $c['_html'], 'THREW' ) );
rt( 'not theirs: the same pill and number, not a link', 'span' === ( $c['Twelve']['tag'] ?? '' ) && '12 RSVPs' === $c['Twelve']['text'] && '' === $c['Twelve']['href'] && 'uc-rsvp-pill' === $c['Twelve']['class'] && 1 === $c['Twelve']['icon'] );
rt( 'not theirs: "0 RSVPs" too', 'span' === ( $c['None yet']['tag'] ?? '' ) && '0 RSVPs' === $c['None yet']['text'] );
rt( 'not theirs: still a dash where none are taken', 'dash' === ( $c['Switched off']['tag'] ?? '' ) );
rt( 'not theirs: no link to any RSVP list in the column', 0 === preg_match( '/<a class="uc-rsvp-pill"/', $c['_html'] ) );
$GLOBALS['kit_refused'] = array();
$GLOBALS['kit_cannot']  = array();

/* ---- The style and the icon. ---- */
$root = $GLOBALS['kit_root'];
$css  = (string) file_get_contents( $root . '/public/css/portal.css' );
rt( 'the pill is the band teal with teal ink', 1 === preg_match( '/\.uc-rsvp-pill \{[^}]*border-radius: 99px;[^}]*background: var\(--uc-band\); color: var\(--uc-accent-text\);/s', $css ) );
rt( 'darker on hover, the link only', false !== strpos( $css, '.uc-portal a.uc-rsvp-pill:hover { background: #D9F2F4; color: #0C666F; text-decoration: none; }' ) );
rt( 'the old button rule is gone', false === strpos( $css, '.uc-rsvp-count-btn' ) );
$paths = sfaf_icon_paths();
rt( 'the person icon exists, and is not a category icon', isset( $paths['person'] ) && count( SFAF_Categories::icons() ) > 10 && ! isset( SFAF_Categories::icons()['person'] ) );

echo "\n3.136.0: the Events list's RSVP column, one pill\n" . str_repeat( '=', 72 ) . "\n";
echo "release-31360: $ran checks\n";
if ( $fails ) { foreach ( $fails as $f ) { echo "FAIL  $f\n"; } exit( 1 ); }
echo "every check passed.\n";
exit( 0 );
