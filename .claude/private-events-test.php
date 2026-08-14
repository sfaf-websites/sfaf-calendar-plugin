<?php
/**
 * WHERE A PRIVATE EVENT MAY APPEAR, AS A WHITELIST.
 *
 * A checklist of routes to hide from can only ever be as complete as the person
 * writing it, and the failure mode for this feature is a route nobody thought
 * of. So this is inverted: it enumerates every query this plugin builds against
 * uc_event, and asserts that each one either EXCLUDES private events or is on
 * the short list of places section 3 says a private event belongs. A query
 * added later is caught by default, because it will be neither.
 *
 *     php .claude/private-events-test.php
 *
 * WHAT IT DOES. Two independent passes, because they fail differently:
 *
 *   1. BEHAVIOUR. SFAF_Privacy's own logic run for real against stubbed
 *      WordPress: the meta clause, how exclude() folds into an existing
 *      meta_query of each shape, the robots tag, the sitemap filters and the
 *      slug. These are assertions about what the code does.
 *
 *   2. COVERAGE. A static sweep of the source for every WP_Query args array
 *      naming post_type uc_event, checked against the whitelist below. This is
 *      the pass that catches a new query nobody excluded.
 *
 * WHAT IT CANNOT PROVE, and the readme says so too: that Yoast honours either
 * mechanism, that a real crawler obeys the robots tag, or that WordPress routes
 * a token slug the way it routes any other. Those need a live site with Yoast
 * on it. Everything decided in this plugin's own code is decided here.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ );

/* --- WordPress, in miniature. ------------------------------------------- */
$GLOBALS['meta']    = array();
$GLOBALS['posts']   = array();
$GLOBALS['updated'] = array();

function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value, $prev = '' ) {
    $GLOBALS['meta'][ $id ][ $key ] = $value;
    return true;
}
function delete_post_meta( $id, $key, $value = '' ) {
    unset( $GLOBALS['meta'][ $id ][ $key ] );
    return true;
}
function get_post( $id = null ) {
    return isset( $GLOBALS['posts'][ $id ] ) ? (object) $GLOBALS['posts'][ $id ] : null;
}
function wp_update_post( $args, $wp_error = false ) {
    $id = (int) $args['ID'];
    foreach ( $args as $k => $v ) {
        if ( 'ID' !== $k ) { $GLOBALS['posts'][ $id ][ $k ] = $v; }
    }
    $GLOBALS['updated'][] = $args;
    return $id;
}
function wp_generate_password( $len = 12, $special = true, $extra = false ) {
    return str_repeat( 'A1b2', (int) ceil( $len / 4 ) );
}
function is_admin() { return false; }
function is_singular( $t = '' ) { return ! empty( $GLOBALS['is_singular'] ); }
function get_queried_object_id() { return isset( $GLOBALS['queried'] ) ? $GLOBALS['queried'] : 0; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {}

class WP_Query {
    public $posts = array();
    public function __construct( $args = array() ) {
        $GLOBALS['last_query'] = $args;
        $this->posts = isset( $GLOBALS['query_result'] ) ? $GLOBALS['query_result'] : array();
    }
}
class SFAF_Series { const TAXONOMY = 'uc_series'; }

require $root . '/includes/class-sfaf-privacy.php';

$fails = array();
function check( $ok, $msg ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $msg; }
}

/* =========================================================================
 * PASS 1: BEHAVIOUR
 * ====================================================================== */

/* --- The clause has to survive an event that predates the feature. ------ */
$clause = SFAF_Privacy::meta_clause();
check( 'OR' === $clause['relation'], 'the clause is not an OR, so it cannot match both shapes' );
$compares = array( $clause[0]['compare'], $clause[1]['compare'] );
check(
    in_array( 'NOT EXISTS', $compares, true ),
    'the clause has no NOT EXISTS branch, so every event with no privacy row at all would be excluded'
);

/* --- exclude(), in each of the three shapes it can meet. ---------------- */

// (a) No meta_query at all.
$args = array( 'post_type' => 'uc_event' );
SFAF_Privacy::exclude( $args );
check( 1 === count( $args['meta_query'] ), 'exclude() on a bare args array did not produce exactly one clause' );

/*
 * (b) AN IMPLICIT AND WITH A NAMED CLAUSE. This is the month grid and
 * SFAF_Series::events(), both of which sort by the clause name. The name must
 * still be a TOP LEVEL key afterwards: WP_Meta_Query does collect names
 * recursively, but the file those queries live in carries a long note about
 * getting this array's shape wrong causing a double join, and "probably still
 * works" is not the standard for the query behind the month grid.
 */
$args = array( 'meta_query' => array(
    'event_date' => array( 'key' => '_uc_event_date', 'compare' => 'EXISTS' ),
) );
SFAF_Privacy::exclude( $args );
check(
    isset( $args['meta_query']['event_date'] ),
    'exclude() moved the named event_date clause out of the top level, which is what the month grid sorts by'
);
check(
    3 !== count( $args['meta_query'] ) || ! isset( $args['meta_query']['relation'] ),
    'exclude() wrapped an implicit AND it did not need to wrap'
);
$found_privacy = false;
foreach ( $args['meta_query'] as $k => $v ) {
    if ( is_array( $v ) && isset( $v['relation'] ) && 'OR' === $v['relation'] ) { $found_privacy = true; }
}
check( $found_privacy, 'exclude() did not add the privacy group beside the named clause' );

// (c) An explicit OR, which MUST be wrapped or the date window goes optional.
$args = array( 'meta_query' => array(
    'relation' => 'OR',
    array( 'key' => 'a', 'value' => '1' ),
    array( 'key' => 'b', 'value' => '2' ),
) );
SFAF_Privacy::exclude( $args );
check(
    isset( $args['meta_query']['relation'] ) && 'AND' === $args['meta_query']['relation'],
    'exclude() appended to an explicit OR, which makes the original conditions optional'
);

/* --- is_private reads only an exact '1'. ------------------------------- */
$GLOBALS['meta'] = array( 7 => array( SFAF_Privacy::META => '1' ), 8 => array(), 9 => array( SFAF_Privacy::META => '0' ) );
check( SFAF_Privacy::is_private( 7 ), 'a private event does not read as private' );
check( ! SFAF_Privacy::is_private( 8 ), 'an event with no privacy row reads as private' );
check( ! SFAF_Privacy::is_private( 9 ), 'an event with an explicit 0 reads as private' );

/* --- set() moves the meta AND the slug, and gives the slug back. -------- */
$GLOBALS['meta']  = array();
$GLOBALS['posts'] = array( 42 => array( 'ID' => 42, 'post_type' => 'uc_event', 'post_name' => 'donor-reception' ) );

SFAF_Privacy::set( 42, true );
check( SFAF_Privacy::is_private( 42 ), 'set(true) did not mark the event private' );
$slug = $GLOBALS['posts'][42]['post_name'];
check( 'donor-reception' !== $slug, 'set(true) left the readable slug in place, so the URL is still guessable' );
check( 32 === strlen( $slug ), "set(true) produced a $slug, which is not 32 characters" );
check( (bool) preg_match( '/^[0-9a-f]{32}$/', $slug ), 'the token is not 32 hex characters' );
check(
    'donor-reception' === get_post_meta( 42, SFAF_Privacy::PREV_SLUG_META, true ),
    'set(true) did not remember the previous slug, so making it public again cannot restore the address'
);
check(
    '1' === get_post_meta( 42, SFAF_Privacy::YOAST_NOINDEX_META, true ),
    "set(true) did not stamp Yoast's noindex meta, which is what keeps it out of Yoast's sitemap"
);

// A second call must not overwrite the remembered slug with the token.
SFAF_Privacy::set( 42, true );
check(
    'donor-reception' === get_post_meta( 42, SFAF_Privacy::PREV_SLUG_META, true ),
    'a second set(true) recorded the token as though it were the original slug'
);

SFAF_Privacy::set( 42, false );
check( ! SFAF_Privacy::is_private( 42 ), 'set(false) did not make the event public' );
check( 'donor-reception' === $GLOBALS['posts'][42]['post_name'], 'set(false) did not restore the original address' );
check( '' === get_post_meta( 42, SFAF_Privacy::YOAST_NOINDEX_META, true ), "set(false) left Yoast's noindex meta behind" );

/* --- Two events never share a token. ------------------------------------ */
$tokens = array();
for ( $i = 0; $i < 25; $i++ ) { $tokens[] = SFAF_Privacy::new_slug(); }
check( count( $tokens ) === count( array_unique( $tokens ) ), 'new_slug() repeated itself, so occurrences could share an address' );

/* --- robots: noindex AND nofollow, and only on a private event. --------- */
$GLOBALS['meta']        = array( 7 => array( SFAF_Privacy::META => '1' ) );
$GLOBALS['is_singular'] = true;

$GLOBALS['queried'] = 7;
$r = SFAF_Privacy::robots( array( 'index' => true, 'follow' => true, 'max-snippet' => -1 ) );
check( ! empty( $r['noindex'] ), 'a private event page is not noindex' );
check( ! empty( $r['nofollow'] ), 'a private event page is not nofollow' );
check( ! isset( $r['index'] ), 'a private event page still carries index, which contradicts noindex' );
check( ! isset( $r['max-snippet'] ), 'a private event page still offers a snippet directive' );

$GLOBALS['queried'] = 8;
$r = SFAF_Privacy::robots( array( 'index' => true, 'follow' => true ) );
check( empty( $r['noindex'] ), 'a PUBLIC event page was marked noindex, which would deindex the whole calendar' );

$GLOBALS['is_singular'] = false;
$r = SFAF_Privacy::robots( array( 'index' => true ) );
check( empty( $r['noindex'] ), 'a non event page was marked noindex' );

/* --- The two sitemaps and core REST. ------------------------------------ */
$args = SFAF_Privacy::hide_from_core_sitemap( array( 'post_type' => 'uc_event' ), 'uc_event' );
check( isset( $args['meta_query'] ), "the core sitemap query was not filtered" );
$untouched = SFAF_Privacy::hide_from_core_sitemap( array( 'post_type' => 'post' ), 'post' );
check( ! isset( $untouched['meta_query'] ), 'the core sitemap filter touched a post type that is not ours' );

$args = SFAF_Privacy::hide_from_rest( array(), null );
check( isset( $args['meta_query'] ), 'core REST collection for uc_event was not filtered' );

$GLOBALS['query_result'] = array( 7, 11 );
$ex = SFAF_Privacy::yoast_excluded_ids( array( 99 ) );
check( in_array( 7, $ex, true ) && in_array( 11, $ex, true ), "Yoast's exclusion list is missing private ids" );
check( in_array( 99, $ex, true ), "Yoast's exclusion list dropped ids somebody else had added" );

/* =========================================================================
 * PASS 2: COVERAGE
 *
 * Every args array in the source that names post_type uc_event, and what it
 * does about privacy. A query is acceptable if it excludes, or if it is on the
 * whitelist with a reason.
 * ====================================================================== */

/*
 * THE WHITELIST. file => why a private event belongs in this query.
 *
 * Everything here is either a manager-facing screen (private events must be
 * findable by the people running them), an internal mechanism that acts on an
 * event regardless of who can see it (reminders, the summary, recurrence,
 * imports), or a lookup that never renders a list to a visitor.
 */
$WHITELIST = array(
    'class-sfaf-portal.php'          => 'the /caladmin screens: managers must see private events',
    'class-sfaf-recurrence.php'      => 'generating and editing the dates of a group, private or not',
    'class-sfaf-reminders.php'       => 'the morning-of reminder, which section 3 says still sends',
    'class-sfaf-notifications.php'   => 'the pre-event summary, which section 3 says still sends',
    'class-sfaf-sources.php'         => 'the import queue and its matching, before anybody sees anything',
    'class-sfaf-source-gfmp.php'     => 'matching an incoming campaign to an event already here',
    'class-sfaf-teams.php'           => 'which events name a team, for the deletion refusal',
    'class-sfaf-venues.php'          => 'which events use a venue, for the deletion refusal',
    'class-sfaf-categories.php'      => 'which events use a category, for the deletion refusal',
    'class-sfaf-privacy.php'         => 'the list of private events, which is the point of it',
    'sfaf-sample-data.php'           => 'first-run seeding, before any event exists',
    'class-sfaf-admin.php'           => 'the WordPress admin, an administrator screen',

    /*
     * PER FUNCTION, because sfaf-calendar.php holds one query that must exclude
     * and one that must not. sfaf_rest_get_events() is the satellite feed and
     * excludes; this one finds events carrying legacy notification meta so it
     * can be folded into the new list, and it has to see every event or a
     * private event's notification list would not be migrated.
     */
    'sfaf-calendar.php::sfaf_migrate_notification_lists' => 'the 3.25.0 notification list upgrade, which must reach every event',
);

$files = array_merge(
    glob( $root . '/includes/*.php' ),
    glob( $root . '/admin/*.php' ),
    array( $root . '/sfaf-calendar.php' )
);

$queries   = 0;
$excluded  = 0;
$listed    = 0;
$not_query = 0;

foreach ( $files as $file ) {
    $name = basename( $file );
    $src  = file_get_contents( $file );

    // Strip comments so a post_type named in prose is not counted as a query.
    $code = preg_replace( '#/\*.*?\*/#s', '', $src );
    $code = preg_replace( '#^\s*//.*$#m', '', $code );

    $offset = 0;
    while ( false !== ( $pos = strpos( $code, "'post_type'", $offset ) ) ) {
        $offset  = $pos + 10;
        $segment = substr( $code, $pos, 220 );
        if ( false === strpos( $segment, 'uc_event' ) ) {
            continue;
        }

        /*
         * AN ARGS KEY, NOT A STRING ARGUMENT. 'post_type' => is a query being
         * built; 'post_type' ) is $query->get( 'post_type' ) being compared,
         * which is what SFAF_List_Columns does inside an is_admin() guarded
         * sort filter and is not a query at all.
         */
        if ( ! preg_match( "/^'post_type'\s*=>/", $segment ) ) {
            $not_query++;
            continue;
        }

        /*
         * IS THIS ACTUALLY A QUERY? A two-sided test, because "the words
         * post_type and uc_event near each other" also matches
         * add_query_arg( array( 'post_type' => 'uc_event', 'page' => ... ) )
         * building an admin URL, and $query->get( 'post_type' ) !== 'uc_event'
         * inside a sort filter. Both of those are in this source and neither
         * can return an event to anybody.
         *
         * So it counts as a query if the array carries another WP_Query
         * argument, or if the 200 characters before it open one. The rejects
         * are counted and printed rather than dropped silently: a detector that
         * quietly stopped matching would report full coverage of nothing.
         */
        $before   = substr( $code, max( 0, $pos - 200 ), min( 200, $pos ) );
        $is_query = ( preg_match( "/'(posts_per_page|post_status|meta_query|tax_query|fields|meta_key|orderby|no_found_rows)'/", $segment )
            || preg_match( '/(new\s+WP_Query|get_posts|wp_count_posts)\s*\(\s*(array\s*\(|\[)?\s*$/', $before ) );

        if ( ! $is_query ) {
            $not_query++;
            continue;
        }

        $queries++;

        /*
         * PER FUNCTION, NOT PER FILE, AND THAT DISTINCTION IS NOT ACADEMIC.
         *
         * This test first asked "does this file exclude anywhere", and it
         * passed with the exclusion deleted from SFAF_Shortcodes::build_query_args(),
         * because month_grid_data() in the same file still had one. That is the
         * exact defect the test exists to catch: the list would have carried
         * private events while the month grid beside it did not.
         *
         * So the window is the enclosing function: from the previous function
         * declaration to the next one. A query is covered only if the function
         * that builds it excludes.
         */
        $fn_start = 0;
        if ( preg_match_all( '/\n\s*(?:public |private |protected |static )*function\s+\w+/', substr( $code, 0, $pos ), $m, PREG_OFFSET_CAPTURE ) ) {
            $last     = end( $m[0] );
            $fn_start = $last[1];
        }
        $fn_end = $pos;
        if ( preg_match( '/\n\s*(?:public |private |protected |static )*function\s+\w+/', $code, $m2, PREG_OFFSET_CAPTURE, $pos ) ) {
            $fn_end = $m2[0][1];
        } else {
            $fn_end = strlen( $code );
        }
        $body = substr( $code, $fn_start, $fn_end - $fn_start );

        if ( false !== strpos( $body, 'SFAF_Privacy::exclude' ) ) {
            $excluded++;
            continue;
        }

        // A file may hold one query that must exclude and one that need not, so
        // the whitelist takes file::function as well as a whole file. See
        // sfaf-calendar.php, which has both.
        $fn = '';
        if ( preg_match( '/function\s+(\w+)/', $body, $fm ) ) {
            $fn = $fm[1];
        }
        if ( isset( $WHITELIST[ $name . '::' . $fn ] ) || isset( $WHITELIST[ $name ] ) ) {
            $listed++;
            continue;
        }
        $fails[] = "$name builds a uc_event query in a function that does not exclude private events, and is not on the whitelist";
    }
}

check( $queries > 15, "only $queries uc_event queries were found, so the sweep is not reaching the source" );

echo "Private events\n";
echo "behaviour:  the meta clause, exclude() against all three meta_query shapes, is_private,\n";
echo "            set() moving both the meta and the slug and giving the slug back, token\n";
echo "            uniqueness, the robots tag on private / public / non-event pages, both\n";
echo "            sitemap filters and the core REST filter\n";
printf( "coverage:   %d uc_event queries found. %d exclude private events, %d are whitelisted with a\n", $queries, $excluded, $listed );
printf( "            stated reason, 0 unaccounted for. %d further post_type mentions were rejected\n", $not_query );
echo "            as not queries (admin URLs, a sort filter's comparison).\n";
echo "not proven here: that Yoast honours the noindex meta or the exclusion filter, that a\n";
echo "            crawler obeys the robots tag, or that WordPress routes a token slug. Live site.\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "a private event is excluded from every query that is not a manager screen or a mechanism.\n";
exit( 0 );
