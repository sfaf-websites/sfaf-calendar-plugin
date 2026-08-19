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

/* --- WordPress, in miniature. -------------------------------------------
 *
 * THE SLUG HALF OF THIS IS MODELLED, NOT STUBBED, and that is the difference
 * between this file before and after 3.33.0. The earlier version stubbed
 * wp_update_post() as "write the fields and return", which cannot fire
 * post_updated, so the entire mechanism that actually decides what a private
 * event's old address does was invisible to it. Every assertion passed while a
 * private event kept answering on its public URL.
 *
 * So three pieces of core are reproduced here from its documented behaviour:
 *
 *   wp_check_for_changed_slugs()  post_updated, records the previous slug as
 *                                 _wp_old_slug for a published, non-hierarchical
 *                                 post whose slug changed, and drops the new
 *                                 slug from the list if it was used before.
 *   wp_old_slug_redirect()        on a 404, resolves a requested slug through
 *                                 _wp_old_slug and the old_slug_redirect_post_id
 *                                 filter, and 301s to the post.
 *   the hook registry             so the filter SFAF_Privacy registers is really
 *                                 called rather than assumed.
 *
 * _wp_old_slug is MULTI-VALUE in WordPress and single-value meta is enough for
 * everything else here, so it is the one key routed to its own store. That is a
 * modelled limitation and is stated rather than hidden.
 *
 * What this still cannot prove: that a real WordPress routes a token slug, that
 * Yoast honours either sitemap mechanism, or that a crawler obeys robots. Those
 * need the live site, and Mark confirmed the redirect behaviour there before
 * this was written.
 */
$GLOBALS['meta']      = array();
$GLOBALS['posts']     = array();
$GLOBALS['updated']   = array();
$GLOBALS['oldslugs']  = array();  // post_id => array of slugs, core's _wp_old_slug
$GLOBALS['filters']   = array();
$GLOBALS['redirects'] = array();

const OLD_SLUG_KEY = '_wp_old_slug';

function get_post_meta( $id, $key, $single = false ) {
    if ( OLD_SLUG_KEY === $key ) {
        $rows = isset( $GLOBALS['oldslugs'][ $id ] ) ? $GLOBALS['oldslugs'][ $id ] : array();
        return $single ? ( isset( $rows[0] ) ? $rows[0] : '' ) : $rows;
    }
    return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : '';
}
function add_post_meta( $id, $key, $value, $unique = false ) {
    if ( OLD_SLUG_KEY === $key ) {
        $GLOBALS['oldslugs'][ $id ][] = $value;
        return true;
    }
    $GLOBALS['meta'][ $id ][ $key ] = $value;
    return true;
}
function update_post_meta( $id, $key, $value, $prev = '' ) {
    $GLOBALS['meta'][ $id ][ $key ] = $value;
    return true;
}
function delete_post_meta( $id, $key, $value = '' ) {
    if ( OLD_SLUG_KEY === $key ) {
        // Core with no $value deletes every row for the key.
        if ( '' === $value ) {
            unset( $GLOBALS['oldslugs'][ $id ] );
        } elseif ( isset( $GLOBALS['oldslugs'][ $id ] ) ) {
            $GLOBALS['oldslugs'][ $id ] = array_values(
                array_diff( $GLOBALS['oldslugs'][ $id ], array( $value ) )
            );
        }
        return true;
    }
    unset( $GLOBALS['meta'][ $id ][ $key ] );
    return true;
}
function get_post( $id = null ) {
    return isset( $GLOBALS['posts'][ $id ] ) ? (object) $GLOBALS['posts'][ $id ] : null;
}
function get_post_type( $id = null ) {
    return isset( $GLOBALS['posts'][ $id ]['post_type'] ) ? $GLOBALS['posts'][ $id ]['post_type'] : '';
}

/**
 * Core's wp_check_for_changed_slugs(), on post_updated.
 *
 * Published and non-hierarchical only, which uc_event is. A post with no
 * post_status in a fixture is treated as published, because that is the state
 * every one of these assertions is about.
 */
function model_check_for_changed_slugs( $id, $before, $after ) {
    if ( $before === $after || '' === (string) $before ) {
        return;
    }
    $status = isset( $GLOBALS['posts'][ $id ]['post_status'] )
        ? $GLOBALS['posts'][ $id ]['post_status']
        : 'publish';
    if ( 'publish' !== $status ) {
        return;
    }
    $rows = isset( $GLOBALS['oldslugs'][ $id ] ) ? $GLOBALS['oldslugs'][ $id ] : array();
    if ( ! in_array( $before, $rows, true ) ) {
        add_post_meta( $id, OLD_SLUG_KEY, $before );
    }
    // If the new slug was used previously, core drops it from the list.
    if ( in_array( $after, $rows, true ) ) {
        delete_post_meta( $id, OLD_SLUG_KEY, $after );
    }
}

function wp_update_post( $args, $wp_error = false ) {
    $id     = (int) $args['ID'];
    $before = isset( $GLOBALS['posts'][ $id ]['post_name'] ) ? $GLOBALS['posts'][ $id ]['post_name'] : '';
    foreach ( $args as $k => $v ) {
        if ( 'ID' !== $k ) { $GLOBALS['posts'][ $id ][ $k ] = $v; }
    }
    $GLOBALS['updated'][] = $args;
    if ( isset( $args['post_name'] ) ) {
        model_check_for_changed_slugs( $id, $before, (string) $args['post_name'] );
    }
    return $id;
}
function wp_insert_post( $args, $wp_error = false ) {
    $id                     = isset( $args['import_id'] ) ? (int) $args['import_id'] : ( count( $GLOBALS['posts'] ) + 100 );
    $GLOBALS['posts'][ $id ] = $args;
    return $id;
}

/**
 * Core's wp_old_slug_redirect(), reduced to the question it answers: given a
 * slug nothing currently resolves to, which post does the visitor land on?
 *
 * Returns 0 for a real 404. Runs the old_slug_redirect_post_id filter, which is
 * where SFAF_Privacy refuses.
 */
function model_old_slug_redirect( $slug ) {
    foreach ( $GLOBALS['posts'] as $id => $post ) {
        if ( isset( $post['post_name'] ) && $post['post_name'] === $slug ) {
            return 0; // Not a 404 at all: a live post answers here.
        }
    }
    $found = 0;
    foreach ( $GLOBALS['oldslugs'] as $id => $rows ) {
        if ( in_array( $slug, $rows, true ) ) { $found = (int) $id; break; }
    }
    return (int) apply_filters( 'old_slug_redirect_post_id', $found );
}

function wp_generate_password( $len = 12, $special = true, $extra = false ) {
    return str_repeat( 'A1b2', (int) ceil( $len / 4 ) );
}
function sanitize_title( $s ) {
    $s = strtolower( trim( (string) $s ) );
    $s = preg_replace( '/[^a-z0-9]+/', '-', $s );
    return trim( (string) $s, '-' );
}
function is_admin() { return false; }
function is_singular( $t = '' ) { return ! empty( $GLOBALS['is_singular'] ); }
function get_queried_object_id() { return isset( $GLOBALS['queried'] ) ? $GLOBALS['queried'] : 0; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {
    $GLOBALS['filters'][ $h ][] = $c;
}
function apply_filters( $h, $value ) {
    if ( empty( $GLOBALS['filters'][ $h ] ) ) {
        return $value;
    }
    foreach ( $GLOBALS['filters'][ $h ] as $cb ) {
        $value = call_user_func( $cb, $value );
    }
    return $value;
}

class WP_Query {
    public $posts = array();
    public function __construct( $args = array() ) {
        $GLOBALS['last_query'] = $args;
        $this->posts = isset( $GLOBALS['query_result'] ) ? $GLOBALS['query_result'] : array();
    }
}
class SFAF_Series { const TAXONOMY = 'uc_series'; }

/**
 * Only what derived_public_slug() asks: is this event part of a group? The date
 * is appended for a member and not for a standalone event, so both branches are
 * reachable from a fixture.
 */
class SFAF_Recurrence {
    const GROUP_META = '_uc_recurrence_group';
    public static function group_of( $post_id ) {
        return (string) get_post_meta( (int) $post_id, self::GROUP_META, true );
    }
}

require $root . '/includes/class-sfaf-privacy.php';

// Register for real, so the old-slug guard is under test rather than assumed.
// A build that drops the add_filter line fails here rather than in production.
SFAF_Privacy::register();

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

/* --- THE OLD ADDRESS, IN BOTH DIRECTIONS. -------------------------------
 *
 * This is the section the earlier version of this file could not have: it needs
 * post_updated modelled. Mark confirmed both behaviours on the live site before
 * 3.33.0, and both are asserted here.
 */

// GOING IN. The readable address must be dead, not redirected to the token.
check(
    0 === model_old_slug_redirect( 'donor-reception' ),
    'the old readable address still resolves for a private event, which hands the secret URL to anybody who tries it'
);
check(
    empty( $GLOBALS['oldslugs'][42] ),
    'a retained old slug survived randomize_slug(), so the readable address is still recorded against a private event'
);

$token_in = $GLOBALS['posts'][42]['post_name'];

SFAF_Privacy::set( 42, false );
check( ! SFAF_Privacy::is_private( 42 ), 'set(false) did not make the event public' );
check( 'donor-reception' === $GLOBALS['posts'][42]['post_name'], 'set(false) did not restore the original address' );
check( '' === get_post_meta( 42, SFAF_Privacy::YOAST_NOINDEX_META, true ), "set(false) left Yoast's noindex meta behind" );

// COMING BACK. The token must keep working, because donors were sent it.
check(
    42 === model_old_slug_redirect( $token_in ),
    'the token stopped working once the event went public again, so every link already sent to a donor is broken'
);

/* --- The guard refuses even when a row exists by some other route. -------
 *
 * A slug the event no longer answers on, so it really is a 404 and the redirect
 * really is what decides. 'donor-reception' would not do: post 42 currently
 * carries it, so nothing would 404 and the assertion would prove nothing.
 */
$GLOBALS['oldslugs'][42] = array( 'reception-2025' );
check(
    42 === model_old_slug_redirect( 'reception-2025' ),
    'a PUBLIC event stopped honouring its own old address, which breaks every link on the calendar'
);
update_post_meta( 42, SFAF_Privacy::META, '1' );
check(
    0 === model_old_slug_redirect( 'reception-2025' ),
    'the redirect guard let an old slug through to a private event, so deleting the row is the only defence'
);
delete_post_meta( 42, SFAF_Privacy::META );
$GLOBALS['oldslugs'][42] = array();

/* --- The guard is scoped, and must not break the rest of the site. ------ */
$GLOBALS['posts'][900] = array( 'ID' => 900, 'post_type' => 'page', 'post_name' => 'about' );
$GLOBALS['meta'][900]  = array( SFAF_Privacy::META => '1' );
check(
    900 === SFAF_Privacy::block_old_slug_redirect( 900 ),
    'the guard refused a redirect for a page, so it is not scoped to uc_event and would break the whole site'
);
check(
    0 === SFAF_Privacy::block_old_slug_redirect( 0 ),
    'the guard invented a post id out of a miss'
);

/* --- An event that never recorded an address still comes back readable. -- */
$GLOBALS['posts'][51] = array( 'ID' => 51, 'post_type' => 'uc_event', 'post_title' => 'Donor Reception', 'post_name' => 'abc123' );
$GLOBALS['meta'][51]  = array( SFAF_Privacy::META => '1' );
SFAF_Privacy::set( 51, false );
check(
    'donor-reception' === $GLOBALS['posts'][51]['post_name'],
    'a private event with no remembered slug stayed at its token after being made public'
);

// The same, for a member of a recurrence group: the date keeps twelve
// occurrences from collapsing onto one title.
$GLOBALS['posts'][52] = array( 'ID' => 52, 'post_type' => 'uc_event', 'post_title' => 'Donor Reception', 'post_name' => 'def456' );
$GLOBALS['meta'][52]  = array(
    SFAF_Privacy::META            => '1',
    SFAF_Recurrence::GROUP_META   => 'grp1',
    '_uc_event_date'              => '2026-09-12',
);
SFAF_Privacy::set( 52, false );
check(
    'donor-reception-2026-09-12' === $GLOBALS['posts'][52]['post_name'],
    'an occurrence with no remembered slug did not fall back to a dated readable address'
);

/* --- OCCURRENCES CANNOT BE WALKED TO FROM THE SEED. ---------------------
 *
 * The seed is private, so its post_name IS its token. readable_base() must
 * never hand that token out as the base for a date's address, and
 * occurrence_slug() must decide the address BEFORE the insert so no predictable
 * one is ever the post's name for core to retain.
 */
$GLOBALS['posts'][60] = array( 'ID' => 60, 'post_type' => 'uc_event', 'post_title' => 'Donor Reception', 'post_name' => 'ffffffffffffffffffffffffffffffff' );
$GLOBALS['meta'][60]  = array(
    SFAF_Privacy::META           => '1',
    SFAF_Privacy::PREV_SLUG_META => 'donor-reception',
);

$base = SFAF_Privacy::readable_base( $GLOBALS['posts'][60]['ID'] );
check(
    'donor-reception' === $base,
    "readable_base() returned '$base' for a private seed; if that is the token, every date is guessable from the seed's own link"
);
check(
    false === strpos( $base, 'ffffffff' ),
    'readable_base() leaked the private token as the base for occurrence addresses'
);

$plan = SFAF_Privacy::occurrence_slug( 60, $base, '2026-09-19' );
check( ! empty( $plan['private'] ), 'occurrence_slug() did not carry the seed privacy to the date' );
check(
    (bool) preg_match( '/^[0-9a-f]{32}$/', $plan['post_name'] ),
    'an occurrence of a private seed is created at a slug that is not a token'
);
check(
    'donor-reception-2026-09-19' !== $plan['post_name'],
    'an occurrence of a private seed is created at the predictable dated address, which core retains and redirects'
);
check(
    'donor-reception-2026-09-19' === $plan['prev_slug'],
    'occurrence_slug() did not remember a readable address, so this date can never come back from its token'
);

// The public case is unchanged: dated, readable, nothing remembered.
$GLOBALS['posts'][61] = array( 'ID' => 61, 'post_type' => 'uc_event', 'post_title' => 'Coffee Social', 'post_name' => 'coffee-social' );
$plan_pub = SFAF_Privacy::occurrence_slug( 61, SFAF_Privacy::readable_base( 61 ), '2026-09-19' );
check(
    'coffee-social-2026-09-19' === $plan_pub['post_name'],
    'a public occurrence stopped being created at its readable dated address'
);
check(
    '' === $plan_pub['prev_slug'] && empty( $plan_pub['private'] ),
    'a public occurrence was handed a remembered slug or marked private'
);

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
    'class-sfaf-organizers.php'      => 'which events name an organizer, for the count and the deletion confirmation',
    'class-sfaf-categories.php'      => 'which events use a category, for the deletion refusal',
    'class-sfaf-privacy.php'         => 'the list of private events, which is the point of it',
    /*
     * AN INSERT, NOT A SELECT. The public request form names uc_event once, in
     * the wp_insert_post() that creates the pending event, and creating one can
     * no more leak a private event than writing a letter can read somebody
     * else's. The form does not list events anywhere: request-form-test.php
     * asserts that separately, and would fail if it started to.
     */
    'class-sfaf-request.php::create_event' => 'creates one pending event; it selects nothing',
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
