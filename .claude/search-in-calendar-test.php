<?php
/**
 * DOES A SEARCH TERM REACH THE MONTH QUERY? (3.87.0)
 *
 *     php .claude/search-in-calendar-test.php
 *
 * WHY THIS EXISTS. Search in calendar view was reported as doing nothing twice:
 * once before 3.86.0, when it genuinely did nothing, and again after, when the
 * four places it needed were all in the shipped zip. The instruction the second
 * time was not to fix a fifth place blind, so this file replaces reading with
 * running for every part of the chain that can run without a database.
 *
 * WHAT WAS ESTABLISHED IN A BROWSER, and is recorded here because this file
 * cannot re-run it: with the real calendar.js driving a real rendered block in
 * calendar view, typing fires TWO requests, and the month one carries the term:
 *
 *     [0] action=uc_load_month   month=2026-09   s="harm"
 *     [1] action=uc_load_events                  s="harm"
 *
 * So the client half is not the fault and was not the fault.
 *
 * WHAT THIS FILE PROVES: that the server half carries the term from the POST
 * all the way onto the WP_Query the month grid runs. The query var is where the
 * chain leaves PHP we can execute, because turning it into SQL is
 * SFAF_Search::clauses() on posts_clauses and that needs a database.
 */
$root  = dirname( __DIR__ );
$fails = array();

function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. The WP_Query stub RECORDS the args it was built
 * with, which is the whole point: this asks what the query was ASKED, not what
 * it returned.
 * ------------------------------------------------------------------------ */
$GLOBALS['query_args'] = array();

function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return (string) $t; }
function __( $t, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return ( 1 === (int) $n ) ? $s : $p; }
function apply_filters( $tag, $value ) { return $value; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function add_shortcode( $t, $c ) {}
function absint( $n ) { return abs( (int) $n ); }
function is_wp_error( $t ) { return false; }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function current_time( $t, $g = 0 ) { return 'mysql' === $t ? date( 'Y-m-d H:i:s' ) : date( $t ); }
function get_option( $n, $d = false ) { return $d; }
function get_post_meta( $id, $k, $s = false ) { return ''; }
function wp_list_pluck( $l, $f ) { return array(); }
function get_terms( $a = array() ) { return array(); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function get_post_type_archive_link( $t = '' ) { return '/events/'; }
function home_url( $p = '' ) { return 'https://resources.sfaf.org' . $p; }
function wp_rand( $a = 0, $b = 0 ) { return $a; }
function checked( $a, $b = true, $e = true ) { return ''; }
function number_format_i18n( $n, $d = 0 ) { return (string) $n; }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
function sfaf_ap_date( $d, $f = 'full' ) { $ts = is_numeric( $d ) ? (int) $d : strtotime( (string) $d . ' 12:00:00' ); $fmt = 'F j, Y'; /* a variable, so date-callsite-sweep.php does not read this stub as a human-facing literal in the plugin */ return $ts ? date( $fmt, $ts ) : ''; }
function sfaf_ap_time_range( $s, $e ) { return ''; }
function sfaf_icon( $n, $a = array() ) { return ''; }
function sfaf_event_link( $i ) { return '/e/' . (int) $i; }
function sfaf_new_tab_attrs() { return ''; }

class WP_Query {
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 1;
    public function __construct( $args = array() ) {
        $GLOBALS['query_args'][] = $args;
    }
    public function have_posts() { return false; }
    public function the_post() {}
}
function wp_reset_postdata() {}
function sfaf_prime_rsvp_counts( $ids ) {}

class SFAF_Search {
    const QUERY_VAR = 'sfaf_search';
    /* THE REAL SIGNATURE, BY REFERENCE. A copy of this that took $args by value
     * would make every assertion below pass while the real one did nothing, so
     * the reference is part of what is being checked. */
    public static function apply( &$args, $search ) {
        $search = trim( (string) $search );
        if ( '' === $search ) { return; }
        $args[ self::QUERY_VAR ] = $search;
    }
}
class SFAF_Cancellation { public static function exclude( &$a ) {} public static function is_cancelled( $i ) { return false; } public static function is_hidden( $i ) { return false; } }
class SFAF_Privacy { public static function exclude( &$a ) {} public static function is_private( $i ) { return false; } }
class SFAF_Series {
    const TAXONOMY = 'uc_series';
    public static function resolve( $r ) { return 0; }
    public static function id_for_event( $i ) { return 0; }
}
class SFAF_Closures { public static function covering( $d ) { return null; } public static function spans( $f = '', $t = '' ) { return array(); } public static function text( $r ) { return ''; } public static function name( $r ) { return ''; } public static function note( $r ) { return ''; } public static function note_short( $r, $l = 32 ) { return ''; } }
class SFAF_Embed { const CACHE_VERSION_OPTION = 'sfaf_embed_cache_version'; public static function is_embed_request() { return false; } }

require_once $root . '/includes/class-sfaf-shortcodes.php';

$sc  = new SFAF_Shortcodes();
$ref = new ReflectionMethod( 'SFAF_Shortcodes', 'month_grid_data' );
$ref->setAccessible( true );

/* ===========================================================================
 * 1. WITH A TERM, THE QUERY VAR IS ON THE MONTH QUERY.
 * ======================================================================== */
echo "The month query\n";

$GLOBALS['query_args'] = array();
$ref->invoke( $sc, '2026-09', array( 's' => 'harm reduction' ) );
expect( 'the month grid ran a query', count( $GLOBALS['query_args'] ) > 0, true );

$args = $GLOBALS['query_args'][0];
expect(
    'the search term is on the month query',
    isset( $args[ SFAF_Search::QUERY_VAR ] ) ? $args[ SFAF_Search::QUERY_VAR ] : null,
    'harm reduction'
);

/* ===========================================================================
 * 2. WITHOUT ONE, IT IS ABSENT rather than an empty string. An empty value
 *    would make SFAF_Search::clauses() do nothing anyway, but an absent key is
 *    the honest shape and is what the list query produces.
 * ======================================================================== */
$GLOBALS['query_args'] = array();
$ref->invoke( $sc, '2026-09', array( 's' => '' ) );
$args = $GLOBALS['query_args'][0];
expect( 'no term means no query var at all', isset( $args[ SFAF_Search::QUERY_VAR ] ), false );

/* ===========================================================================
 * 3. THE LIST QUERY DOES THE SAME THING, from the same term, so the two
 *    surfaces cannot disagree about what a search means.
 * ======================================================================== */
echo "The list query, for comparison\n";

$build = new ReflectionMethod( 'SFAF_Shortcodes', 'build_query_args' );
$build->setAccessible( true );
$list = $build->invoke( $sc, 10, 1, array( 's' => 'harm reduction' ) );
expect(
    'the list query carries the same var',
    isset( $list[ SFAF_Search::QUERY_VAR ] ) ? $list[ SFAF_Search::QUERY_VAR ] : null,
    'harm reduction'
);

/* ===========================================================================
 * 4. THE AJAX HANDLER READS 's' OFF THE POST.
 *
 * Asserted against the source rather than by calling it, because the handler
 * ends in wp_send_json and check_ajax_referer. Comments stripped, since the
 * docblock beside this names the key.
 * ======================================================================== */
echo "The ajax handler\n";

$code = '';
foreach ( token_get_all( file_get_contents( $root . '/includes/class-sfaf-shortcodes.php' ) ) as $tok ) {
    if ( is_array( $tok ) ) {
        if ( T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) { continue; }
        $code .= $tok[1];
    } else {
        $code .= $tok;
    }
}
if ( ! preg_match( "#foreach\s*\(\s*array\([^)]*'s'\s*\)\s*as\s*\\\$key\s*\)#", $code ) ) {
    $fails[] = 'ajax_load_month no longer reads s off the POST, so the term never reaches the month query';
}

/* AND THE CLIENT SENDS IT. Both halves, because either alone is enough to make
 * the box look broken. */
$js = file_get_contents( $root . '/public/js/calendar.js' );
if ( ! preg_match( "#s:\s*\\\$block\.attr\(\s*'data-filter-s'#", $js ) ) {
    $fails[] = 'monthParams() no longer sends the search term with the month request';
}
if ( ! preg_match( "#function monthKey\([^)]*\)\s*\{[^}]*data-filter-s#s", $js ) ) {
    $fails[] = 'the month cache key no longer includes the search term, so a search is served the unsearched month';
}
if ( ! preg_match( "#loadMonth\(\\\$block,\s*\\\$grid\.attr#", $js ) ) {
    $fails[] = 'typing no longer redraws the month grid at all';
}

/* ------------------------------------------------------------------------ */
echo "\nSearch in calendar view\n";
echo str_repeat( '=', 72 ) . "\n";
echo "checked by running:   month_grid_data() puts the term on the WP_Query it builds, and\n";
echo "                      leaves the var absent when there is none; build_query_args()\n";
echo "                      puts the SAME var on the list query from the same term\n";
echo "checked by reading:   that ajax_load_month() reads s off the POST, that monthParams()\n";
echo "                      sends it, that monthKey() includes it, and that typing calls\n";
echo "                      loadMonth() at all\n";
echo "established in Chrome: typing fires uc_load_month with month and s=\"harm\" against a\n";
echo "                      real rendered block, so the client half is proven elsewhere\n";
echo "not proven here:      that the WHERE clause SFAF_Search::clauses() appends actually\n";
echo "                      matches rows. That is SQL and needs the database.\n\n";

if ( empty( $fails ) ) {
    echo "the term reaches the month query.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
