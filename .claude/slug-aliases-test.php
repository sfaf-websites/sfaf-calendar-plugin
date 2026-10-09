<?php
/**
 * A CHANGED SLUG KEEPS ANSWERING (3.110.3, brief F).
 *
 *     php .claude/slug-aliases-test.php
 *
 * The Strut organizer's slug goes from magnet to strut, once; the old slug
 * redirects on the archive and the filter links and resolves as an alias for
 * a shortcode and an embed; the Organizers, Series and Categories screens
 * change a slug through the same rename, so the next change needs no release.
 *
 *   PLANT F.1  the old slug is not remembered
 *   PLANT F.2  the archive at the old slug is not redirected
 *   PLANT F.3  a filter link carrying the old slug is not redirected
 *   PLANT F.4  an embed's data-organizer with the old slug is not resolved
 */

define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );

/* ---- A term store and the WordPress it needs. ---- */
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
class WP_Term { public $term_id; public $name; public $slug; public $taxonomy; }
class SlugRedirect extends Exception {}
$GLOBALS['terms'] = array(); $GLOBALS['opts'] = array(); $GLOBALS['qv'] = array(); $GLOBALS['is404'] = false;
function st_term( $id, $tax, $name, $slug ) { $t = new WP_Term(); $t->term_id = $id; $t->taxonomy = $tax; $t->name = $name; $t->slug = $slug; $GLOBALS['terms'][ $id ] = $t; return $t; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function sanitize_title( $t ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $t ) ), '-' ); }
function get_term( $id, $tax = '' ) { $t = isset( $GLOBALS['terms'][ (int) $id ] ) ? $GLOBALS['terms'][ (int) $id ] : null; return ( $t && ( '' === $tax || $t->taxonomy === $tax ) ) ? $t : null; }
function get_term_by( $f, $v, $tax ) { foreach ( $GLOBALS['terms'] as $t ) { if ( $t->taxonomy === $tax && 'slug' === $f && $t->slug === $v ) { return $t; } } return false; }
function get_terms( $a ) { return array_values( array_filter( $GLOBALS['terms'], function ( $t ) use ( $a ) { return $t->taxonomy === $a['taxonomy']; } ) ); }
function wp_update_term( $id, $tax, $args ) { if ( isset( $args['slug'] ) ) { $GLOBALS['terms'][ (int) $id ]->slug = $args['slug']; } return array( 'term_id' => $id ); }
function get_term_link( $t, $tax ) { $base = array( 'uc_organizer' => 'event-organizer', 'uc_event_category' => 'event-category', 'uc_series' => 'event-series' ); return 'https://resources.sfaf.org/' . $base[ $tax ] . '/' . $t->slug . '/'; }
function is_admin() { return false; } function wp_doing_ajax() { return false; } function is_404() { return $GLOBALS['is404']; }
function get_query_var( $k ) { return isset( $GLOBALS['qv'][ $k ] ) ? $GLOBALS['qv'][ $k ] : ''; }
function wp_unslash( $v ) { return $v; }
function current_time( $t ) { return '2026-10-09 12:00:00'; }
function add_action() {}
function remove_query_arg( $keys ) { $q = $_GET; foreach ( (array) $keys as $k ) { unset( $q[ $k ] ); } return 'https://resources.sfaf.org/calendar/' . ( $q ? '?' . http_build_query( $q ) : '' ); }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . urldecode( http_build_query( $args ) ); }
function wp_safe_redirect( $u, $s = 302 ) { throw new SlugRedirect( $s . ' ' . $u ); }
require $root . '/includes/class-sfaf-slug-aliases.php';

$fails = array();
function sa( $ok, $why ) { global $fails; if ( ! $ok ) { $fails[] = $why; } }
function sa_redirect() { try { SFAF_Slug_Aliases::redirect(); } catch ( SlugRedirect $r ) { return $r->getMessage(); } return ''; }

/* ---- The one-time rename. ---- */
st_term( 50, 'uc_organizer', 'Strut', 'magnet' );
st_term( 51, 'uc_organizer', 'The Stonewall Project', 'the-stonewall-project' );
st_term( 52, 'uc_organizer', 'Programa Latino', 'programa-latino' );
st_term( 60, 'uc_event_category', 'Support Groups', 'support-groups' );
SFAF_Slug_Aliases::rename_magnet_once();
sa( 'strut' === get_term( 50 )->slug, 'F: the Strut organizer is still ' . get_term( 50 )->slug );
sa( 'strut' === SFAF_Slug_Aliases::resolve( 'uc_organizer', 'magnet' ), 'PLANT F.1: magnet does not resolve to strut' );
sa( 'strut,programa-latino' === SFAF_Slug_Aliases::resolve_list( 'uc_organizer', 'magnet,programa-latino,strut' ), 'F: a list with the old and the new does not come back once each' );
$said = get_option( 'sfaf_slug_magnet_done' );
sa( is_array( $said ) && 'renamed' === $said['said'], 'F: the rename did not record that it ran' );
st_term( 50, 'uc_organizer', 'Strut', 'strut' );
update_option( 'sfaf_slug_magnet_done', false );
$GLOBALS['opts']['sfaf_slug_magnet_done'] = array( 'said' => 'renamed' );
SFAF_Slug_Aliases::rename_magnet_once();
sa( 'renamed' === get_option( 'sfaf_slug_magnet_done' )['said'], 'F: the rename ran twice' );

/* A Magnet that is not Strut is left alone. */
$GLOBALS['opts'] = array(); st_term( 70, 'uc_organizer', 'Magnet Clinic', 'magnet' );
SFAF_Slug_Aliases::rename_magnet_once();
sa( 'magnet' === get_term( 70 )->slug && false !== strpos( get_option( 'sfaf_slug_magnet_done' )['said'], 'Magnet Clinic' ), 'F: an organizer named something other than Strut was renamed' );
unset( $GLOBALS['terms'][70] );

/* ---- rename(): refused, chained, and a live slug winning. ---- */
$GLOBALS['opts'] = array();
SFAF_Slug_Aliases::rename( 50, 'uc_organizer', 'magnet' ); st_term( 50, 'uc_organizer', 'Strut', 'magnet' ); $GLOBALS['opts'] = array();
SFAF_Slug_Aliases::rename( 50, 'uc_organizer', 'strut' );
sa( is_wp_error( SFAF_Slug_Aliases::rename( 50, 'uc_organizer', 'Programa Latino' ) ), 'F: a slug another organizer has was taken' );
sa( is_wp_error( SFAF_Slug_Aliases::rename( 50, 'uc_organizer', '!!!' ) ), 'F: an empty slug was taken' );
SFAF_Slug_Aliases::rename( 50, 'uc_organizer', 'Strut SF' );
sa( 'strut-sf' === get_term( 50 )->slug && 'strut-sf' === SFAF_Slug_Aliases::resolve( 'uc_organizer', 'magnet' ) && 'strut-sf' === SFAF_Slug_Aliases::resolve( 'uc_organizer', 'strut' ),
    'F: a slug changed twice does not take both old ones to the newest' );
SFAF_Slug_Aliases::rename( 50, 'uc_organizer', 'strut' );
sa( 'strut' === SFAF_Slug_Aliases::resolve( 'uc_organizer', 'strut' ) && ! isset( SFAF_Slug_Aliases::all()['uc_organizer']['strut'] ), 'F: changing back left an alias of the live slug' );
sa( 'support-groups' === SFAF_Slug_Aliases::resolve( 'uc_event_category', 'support-groups' ), 'F: a slug nobody changed is not itself' );

/* ---- The redirects. ---- */
$GLOBALS['is404'] = true; $GLOBALS['qv'] = array( 'uc_organizer' => 'magnet' ); $_GET = array();
sa( '301 https://resources.sfaf.org/event-organizer/strut/' === sa_redirect(), 'PLANT F.2: the archive at /event-organizer/magnet/ is not sent to strut with a 301: ' . sa_redirect() );
$GLOBALS['is404'] = false; $GLOBALS['qv'] = array();
$_GET = array( 'uc_org' => 'magnet', 'uc_cat' => 'support-groups' );
$to = sa_redirect();
sa( 0 === strpos( $to, '301 ' ) && false !== strpos( $to, 'uc_org=strut' ) && false !== strpos( $to, 'uc_cat=support-groups' ), 'PLANT F.3: a filter link with uc_org=magnet is not sent to uc_org=strut: ' . $to );
$_GET = array( 'uc_org' => array( 'magnet', 'programa-latino' ) );
$to = sa_redirect();
sa( false !== strpos( $to, 'uc_org[0]=strut' ) && false !== strpos( $to, 'uc_org[1]=programa-latino' ), 'F: the filter bar\'s array form is not redirected: ' . $to );
$_GET = array( 'uc_org' => 'strut' );
sa( '' === sa_redirect(), 'F: an address with the live slug was redirected' );
$_GET = array();

/* ---- Every reader resolves; every screen writes through rename(). ---- */
$sc = file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' );
foreach ( array(
    "'organizer' => SFAF_Slug_Aliases::resolve_list( 'uc_organizer'",
    "'category'  => SFAF_Slug_Aliases::resolve_list( 'uc_event_category'",
    "SFAF_Slug_Aliases::resolve_list( 'uc_event_category', \$this->slug_list( wp_unslash( \$_GET['uc_cat'] ) ) )",
    "SFAF_Slug_Aliases::resolve_list( 'uc_organizer', \$this->slug_list( wp_unslash( \$_GET['uc_org'] ) ) )",
    "SFAF_Slug_Aliases::resolve_list( 'uc_series', \$this->slug_list( wp_unslash( \$_GET['uc_group'] ) ) )",
) as $needle ) {
    sa( false !== strpos( $sc, $needle ), 'F: the shortcode does not resolve: ' . $needle );
}
$em = file_get_contents( $root . '/includes/class-sfaf-embed.php' );
sa( false !== strpos( $em, "'organizer'    => \$al( 'uc_organizer', 'organizer' )," ) && false !== strpos( $em, "'active_organizer'   => \$al( 'uc_organizer', 'active_organizer' )," ),
    'PLANT F.4: the embed does not resolve data-organizer through the alias' );
sa( false !== strpos( file_get_contents( $root . '/includes/class-sfaf-submit.php' ), 'SFAF_Slug_Aliases::resolve( SFAF_Series::TAXONOMY' ), 'F: the community form\'s series link does not resolve' );
$org = file_get_contents( $root . '/includes/class-sfaf-organizers.php' );
sa( false !== strpos( $org, 'SFAF_Slug_Aliases::rename( $term_id, self::TAXONOMY, $args[\'slug\'] )' ), 'F: the Organizers screen changes a slug without the alias' );
$po = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
sa( false !== strpos( $po, "SFAF_Slug_Aliases::rename( \$cat_id, SFAF_Categories::TAXONOMY" ) && false !== strpos( $po, "SFAF_Slug_Aliases::rename( \$term_id, SFAF_Series::TAXONOMY" ),
    'F: the Categories or Series screen changes a slug without the alias' );
foreach ( array( 'organizer_slug', 'category_slug', 'series_slug' ) as $f ) {
    sa( false !== strpos( $po, "render_slug_field( '$f'" ), "F: no slug field named $f" );
}
$ad = file_get_contents( $root . '/admin/class-sfaf-admin.php' );
sa( false !== strpos( $ad, '<option value="<?php echo esc_attr( $org->slug ); ?>"' ), 'F: the embed generator does not write the live slug' );
sa( false === stripos( file_get_contents( $root . '/includes/sfaf-sample-data.php' ), 'magnet' ), 'F: the sample data still seeds Magnet' );

/* ---- The list of slugs that are not their names. ---- */
st_term( 50, 'uc_organizer', 'Strut', 'magnet' );
st_term( 53, 'uc_organizer', 'Programa Latino', 'programa-latino-2' );
st_term( 54, 'uc_organizer', 'Stonewall Project', 'the-stonewall-project' );
$odd = array_map( function ( $t ) { return $t->name; }, SFAF_Slug_Aliases::not_their_name( 'uc_organizer' ) );
sort( $odd );
sa( array( 'Stonewall Project', 'Strut' ) === $odd, 'F: the not-their-name list is ' . json_encode( $odd ) );

if ( $fails ) {
    echo 'SLUG ALIASES: ' . count( $fails ) . " FAILURE(S)\n  - " . implode( "\n  - ", $fails ) . "\n";
    exit( 1 );
}
echo "slug aliases: magnet becomes strut once and only for Strut; an old slug redirects on the archive and the filter links, resolves for shortcodes, embeds and the community form, and every slug field changes a slug the same way.\n";
