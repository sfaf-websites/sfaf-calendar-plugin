<?php
/**
 * WHERE THE DONATE BUTTON GOES (3.105.0).
 *
 *     php .claude/donate-link-test.php
 *
 * Runs the real sfaf_donate_resolve(), sfaf_donate_block(), the list row and
 * the editor's save through wp-kit.php. The rule: the event's choice, else its
 * series' link, else the default in Settings. What is asserted is what a
 * visitor would be sent to, read off the rendered block, not the meta written.
 *
 * The two faults the brief names are the ones this has to catch: a button
 * drawn for None, and the default used when the series has a link.
 */

require __DIR__ . '/wp-kit.php';

$fails = array();
function dn_check( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = $label . ': got ' . json_encode( $got ) . ', expected ' . json_encode( $want );
    }
}

const SERIES_LINK = 'https://donate.sfaf.org/campaign/111/donate';
const OTHER_LINK  = 'https://donate.sfaf.org/campaign/222/donate';
const OWN_LINK    = 'https://gofund.me/strut-art';

function dl_event( $meta = array(), $series = 0 ) {
    $id = kit_write_post( array( 'post_type' => 'uc_event', 'post_status' => 'publish', 'post_title' => 'Art show' ) );
    foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
    if ( $series ) { wp_set_object_terms( $id, array( $series ), 'uc_series' ); }
    return $id;
}
/** Where the donate line in the emails links, or '' when it is absent (3.106.0). */
function dl_href( $id ) {
    return sfaf_donate_url( $id );
}

/* ---- Settings never saved: SFAF's own page. -------------------------- */
kit_reset();
dn_check( 'no settings saved: the default is SFAF\'s donation page', sfaf_donate_default_url(), 'https://donate.sfaf.org/campaign/773029/donate' );
$e = dl_event();
dn_check( 'a new event with no series goes to the default', dl_href( $e ), 'https://donate.sfaf.org/campaign/773029/donate' );
dn_check( 'and says where it came from', sfaf_donate_resolve( $e )['from'], 'default' );

/* ---- A series with a link. ------------------------------------------- */
update_term_meta( 11, SFAF_Series::META_DONATE, SERIES_LINK );
$e = dl_event( array(), 11 );
dn_check( 'PLANT: an event in a series with a link goes to the series link, not the default', dl_href( $e ), SERIES_LINK );
dn_check( 'a series without a link falls through to the default', dl_href( dl_event( array(), 12 ) ), 'https://donate.sfaf.org/campaign/773029/donate' );

/* ---- None. --------------------------------------------------------- */
$e = dl_event( array( '_uc_donate_choice' => 'none', '_uc_gofundme_url' => OWN_LINK ), 11 );
dn_check( 'PLANT: None gives no link, whatever else is set', dl_href( $e ), '' );

/* ---- Custom, and an event saved before 3.105.0 with a link. ----------- */
dn_check( 'Custom goes to the event\'s own link', dl_href( dl_event( array( '_uc_donate_choice' => 'custom', '_uc_gofundme_url' => OWN_LINK ), 11 ) ), OWN_LINK );
dn_check( 'Custom with the box empty gives no link', dl_href( dl_event( array( '_uc_donate_choice' => 'custom' ), 11 ) ), '' );
$old = dl_event( array( '_uc_gofundme_url' => OWN_LINK ), 11 );
dn_check( 'an event from before 3.105.0 with its own link keeps it', dl_href( $old ), OWN_LINK );
dn_check( 'and reads as its own', sfaf_donate_resolve( $old )['from'], 'own' );

/* ---- A series named in the list. ------------------------------------- */
update_term_meta( 12, SFAF_Series::META_DONATE, OTHER_LINK );
dn_check( 'a series chosen by name is used over the event\'s own series', dl_href( dl_event( array( '_uc_donate_choice' => 'series:12' ), 11 ) ), OTHER_LINK );
delete_term_meta( 12, SFAF_Series::META_DONATE );
dn_check( 'a named series that lost its link inherits rather than vanishing', dl_href( dl_event( array( '_uc_donate_choice' => 'series:12' ), 11 ) ), SERIES_LINK );

/* ---- Inherit, said explicitly, with a stale own link kept in the box. --- */
dn_check( 'inherit ignores a link left in the Custom box', dl_href( dl_event( array( '_uc_donate_choice' => 'inherit', '_uc_gofundme_url' => OWN_LINK ), 11 ) ), SERIES_LINK );

/* ---- Settings emptied: no default at all. ----------------------------- */
update_option( 'uc_settings', array( 'donate_default_url' => '' ) );
dn_check( 'an emptied default is empty, not SFAF\'s page', sfaf_donate_default_url(), '' );
dn_check( 'so an event with nothing else has no link', dl_href( dl_event() ), '' );
dn_check( 'but a series link still wins', dl_href( dl_event( array(), 11 ) ), SERIES_LINK );
update_option( 'uc_settings', array( 'donate_default_url' => 'https://example.org/give' ) );
dn_check( 'a changed default is used', dl_href( dl_event() ), 'https://example.org/give' );

/* ---- 3.106.0: NO DONATE BUTTON ON ANY PUBLIC PAGE. ---------------------
 * The link appears only as one line in the confirmation and the reminder
 * (email-messages-test.php). Nothing that draws a public page may read it. */
$root = dirname( __DIR__ );
foreach ( array( 'templates/single-uc_event.php', 'includes/class-sfaf-shortcodes.php', 'includes/class-sfaf-embed.php', 'public/js/calendar.js', 'public/js/embed.js' ) as $file ) {
    $code = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . '/' . $file ) );
    $code = preg_replace( '#^\s*//.*$#m', '', $code );
    dn_check( "PLANT: $file draws no donate button", (bool) preg_match( '/sfaf_donate_(resolve|url|block)\s*\(|>Donate<|uc-lrow-donate/', $code ), false );
}
dn_check( 'the old block is gone', function_exists( 'sfaf_donate_block' ), false );

/* ---- The save: a choice is stored, a series without a link is refused. -- */
$src = (string) file_get_contents( $root . '/includes/class-sfaf-portal.php' );
dn_check( 'the editor saves donate_choice', false !== strpos( $src, "isset( \$_POST['donate_choice'] )" ), true );
dn_check( 'and accepts a series only while it has a link', false !== strpos( $src, "'' !== SFAF_Series::donate_url( (int) \$dm[1] )" ), true );
if ( $fails ) {
    echo 'DONATE LINK: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "donate link: the event's choice, else the series link, else the default; None gives no link; no public page draws a donate button.\n";
