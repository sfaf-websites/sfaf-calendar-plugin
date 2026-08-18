<?php
/**
 * WHO MAY EDIT AN EVENT, AND THE WHITELIST THAT STOPS A ROUTE SLIPPING PAST IT.
 *
 * Two halves, and they answer different questions.
 *
 * PART ONE is behavioural: it exercises the real
 * SFAF_Portal::user_can_edit_event() against every combination of role,
 * organizer and team membership that exists, including the ones that only
 * happen when somebody's access is withdrawn while their team membership stays.
 * The five permission defects in PROJECT.md §5 were all "the check was in the
 * wrong place or absent", and the answer to that is one function everything
 * asks. This proves the function is right.
 *
 * PART TWO is structural, and it is a WHITELIST, in the shape private-events
 * -test.php uses: every route that reads or writes an event or its RSVPs is
 * named here with the gate it is allowed to use, and any route in the source
 * that is not on the list, or that uses a gate the list does not allow, fails.
 * A route added tomorrow is caught by DEFAULT rather than by somebody
 * remembering. Being right today is not the property being protected here.
 *
 *     php .claude/event-access-test.php
 *
 * WHAT IT STUBS. WordPress, and only what the two classes under test call.
 * SFAF_Portal is 10,000 lines of rendering, so part one loads the real
 * SFAF_Teams and re-implements NOTHING: user_can_edit_event() is extracted from
 * the real file and eval'd into a stand-in class, and the test FAILS if that
 * extraction stops matching, so it cannot silently drift into testing a copy.
 */

$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

$fails = array();

/* ===========================================================================
 * PART ONE: the gate itself.
 * ======================================================================== */

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
$GLOBALS['users']  = array();   // id => array('caps'=>bool manage_options)
$GLOBALS['meta']   = array();   // "user:id:key" => value
$GLOBALS['posts']  = array();   // id => object
$GLOBALS['pmeta']  = array();   // "post:id:key" => value
$GLOBALS['opt']    = array();

function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) { $GLOBALS['opt'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['opt'][ $name ] ); return true; }
function user_can( $user, $cap, ...$args ) {
    $id = is_object( $user ) ? (int) $user->ID : (int) $user;
    return ! empty( $GLOBALS['users'][ $id ]['manage_options'] );
}
function get_user_meta( $id, $key, $single = false ) {
    $k = 'user:' . (int) $id . ':' . $key;
    return array_key_exists( $k, $GLOBALS['meta'] ) ? $GLOBALS['meta'][ $k ] : '';
}
function update_user_meta( $id, $key, $value, $prev = '' ) { $GLOBALS['meta'][ 'user:' . (int) $id . ':' . $key ] = $value; return true; }
function delete_user_meta( $id, $key, $value = '' ) { unset( $GLOBALS['meta'][ 'user:' . (int) $id . ':' . $key ] ); return true; }
function get_userdata( $id ) { return isset( $GLOBALS['users'][ (int) $id ] ) ? (object) array( 'ID' => (int) $id ) : false; }
function get_post( $id = 0 ) {
    $id = is_object( $id ) ? $id->ID : (int) $id;
    return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ] : null;
}
function get_post_meta( $id, $key, $single = false ) {
    $k = 'post:' . (int) $id . ':' . $key;
    return array_key_exists( $k, $GLOBALS['pmeta'] ) ? $GLOBALS['pmeta'][ $k ] : '';
}
function update_post_meta( $id, $key, $value, $prev = '' ) { $GLOBALS['pmeta'][ 'post:' . (int) $id . ':' . $key ] = $value; return true; }
function delete_post_meta( $id, $key, $value = '' ) { unset( $GLOBALS['pmeta'][ 'post:' . (int) $id . ':' . $key ] ); return true; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function __return_true() { return true; }
function current_time( $type, $gmt = 0 ) { return date( 'Y-m-d H:i:s' ); }
function wp_list_pluck( $list, $field, $index_key = null ) {
    $out = array();
    foreach ( $list as $row ) { $out[] = is_object( $row ) ? $row->$field : $row[ $field ]; }
    return $out;
}

/** Not exercised in part one; present so the real SFAF_Teams file loads. */
class WP_Query {
    public $posts = array();
    public function __construct( $args = array() ) { $this->posts = isset( $GLOBALS['query_posts'] ) ? $GLOBALS['query_posts'] : array(); }
}
function get_the_title( $id = 0 ) { return 'Event ' . (int) $id; }
function get_post_status( $id = 0 ) { return 'publish'; }

require_once $root . '/includes/class-sfaf-teams.php';

/* ---------------------------------------------------------------------------
 * THE GATE, LIFTED OUT OF THE REAL FILE RATHER THAN COPIED INTO THIS ONE.
 *
 * SFAF_Portal cannot be loaded here: it is ten thousand lines and pulls in most
 * of the plugin. But a hand-written copy of the rule would be a test that keeps
 * passing after the real rule changes, which is the exact failure mode this
 * project keeps writing down. So the three methods that make up the gate are
 * extracted from the source by name and eval'd, and the extraction is asserted:
 * if any of them stops being found, or stops looking like itself, the test fails
 * rather than quietly testing something else.
 * ------------------------------------------------------------------------ */
$portal_src = file_get_contents( $root . '/includes/class-sfaf-portal.php' );

function extract_method( $src, $name ) {
    if ( ! preg_match( '#\n    (?:public |private |protected )?static function ' . preg_quote( $name, '#' ) . '\s*\([^)]*\)\s*\{#', $src, $m, PREG_OFFSET_CAPTURE ) ) {
        return '';
    }
    $start = $m[0][1] + 1;
    $open  = strpos( $src, '{', $start );
    $depth = 0;
    for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) { return substr( $src, $start, $i - $start + 1 ); }
        }
    }
    return '';
}

$wanted = array( 'get_role', 'user_can_view_all', 'user_can_edit_event', 'is_site_admin', 'roles' );
$bodies = array();
foreach ( $wanted as $w ) {
    $bodies[ $w ] = extract_method( $portal_src, $w );
    if ( '' === $bodies[ $w ] ) {
        $fails[] = "could not extract SFAF_Portal::$w() from the source. This test is now blind.";
    }
}

// The one assertion that proves the extraction is the real rule and not a
// leftover: the gate must actually consult teams.
if ( false === strpos( $bodies['user_can_edit_event'], 'SFAF_Teams::user_owns_event' ) ) {
    $fails[] = 'user_can_edit_event() does not consult SFAF_Teams. Either the model was reverted or the extraction is wrong.';
}

if ( $fails ) {
    echo "FAIL: " . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}

eval( 'class SFAF_Portal { ' . implode( "\n", $bodies ) . ' }' );

/* ---------------------------------------------------------------------------
 * The world.
 * ------------------------------------------------------------------------ */
function make_user( $id, $role, $wp_admin = false ) {
    $GLOBALS['users'][ $id ] = array( 'manage_options' => $wp_admin );
    if ( '' !== $role ) {
        update_user_meta( $id, '_uc_calendar_role', $role );
    } else {
        delete_user_meta( $id, '_uc_calendar_role' );
    }
}
function make_event( $id, $author ) {
    $GLOBALS['posts'][ $id ] = (object) array( 'ID' => $id, 'post_author' => $author, 'post_type' => 'uc_event' );
}
function make_team( $id, $name, $users ) {
    $t = get_option( 'sfaf_teams', array() );
    $t[ $id ] = array( 'id' => $id, 'name' => $name, 'users' => $users, 'created' => 1, 'updated' => 1 );
    update_option( 'sfaf_teams', $t );
}

const WP_ADMIN    = 1;
const CAL_ADMIN   = 2;
const EDITOR      = 3;
const ORGANIZER   = 4;   // contributor who created event 100
const TEAMMATE    = 5;   // contributor, on team alpha
const OUTSIDER    = 6;   // contributor, on no team
const OTHER_TEAM  = 7;   // contributor, on team beta
const NO_ACCESS   = 8;   // in team alpha, but no calendar record at all

make_user( WP_ADMIN,   '',            true );
make_user( CAL_ADMIN,  'admin' );
make_user( EDITOR,     'editor' );
make_user( ORGANIZER,  'contributor' );
make_user( TEAMMATE,   'contributor' );
make_user( OUTSIDER,   'contributor' );
make_user( OTHER_TEAM, 'contributor' );
make_user( NO_ACCESS,  '' );

make_team( 'alpha', 'Programa Latino', array( TEAMMATE, NO_ACCESS ) );
make_team( 'beta',  'Syringe Access',  array( OTHER_TEAM ) );

make_event( 100, ORGANIZER );   // team alpha owns it, set below
make_event( 200, ORGANIZER );   // no team at all

SFAF_Teams::set_access_for_event( 100, array( 'alpha' ) );

function may( $uid, $event ) { return SFAF_Portal::user_can_edit_event( $uid, $event ); }

function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, $got ? 'true' : 'false', $want ? 'true' : 'false' );
    }
}

echo "The gate\n";

/* --- An event WITH a team. ------------------------------------------------ */
expect( 'WP admin edits a team event',            may( WP_ADMIN, 100 ),    true );
expect( 'calendar admin edits a team event',      may( CAL_ADMIN, 100 ),   true );
expect( 'editor edits a team event',              may( EDITOR, 100 ),      true );
expect( 'organizer edits their own',              may( ORGANIZER, 100 ),   true );
expect( 'team member edits the team event',       may( TEAMMATE, 100 ),    true );
expect( 'ANOTHER team cannot',                    may( OTHER_TEAM, 100 ),  false );
expect( 'a contributor on no team cannot',        may( OUTSIDER, 100 ),    false );

/*
 * THE ONE THAT IS EASY TO GET WRONG. A team may hold somebody with no calendar
 * record at all: the $offered guarantee keeps them, and access may have been
 * withdrawn while membership stayed. Answering true for them would send the
 * pre-event summary a caladmin link that opens a Denied page, which is defect
 * five in PROJECT.md §5 rebuilt out of new parts.
 */
expect( 'a team member with NO calendar access cannot', may( NO_ACCESS, 100 ), false );

/* --- An event with NO team. ----------------------------------------------- */
expect( 'organizer edits their teamless event',   may( ORGANIZER, 200 ),   true );
expect( 'calendar admin edits a teamless event',  may( CAL_ADMIN, 200 ),   true );
expect( 'team member cannot reach a teamless event', may( TEAMMATE, 200 ), false );
expect( 'outsider cannot reach a teamless event', may( OUTSIDER, 200 ),    false );

/* --- Membership is LIVE, in both directions. ------------------------------ */
echo "Membership is live\n";

make_team( 'alpha', 'Programa Latino', array( TEAMMATE, NO_ACCESS, OUTSIDER ) );
expect( 'joining a team grants access to events it already owned', may( OUTSIDER, 100 ), true );

make_team( 'alpha', 'Programa Latino', array( NO_ACCESS ) );
expect( 'leaving a team removes it again', may( TEAMMATE, 100 ), false );
expect( 'and the organizer still has it',  may( ORGANIZER, 100 ), true );

/* --- Removing the team from the event. ------------------------------------ */
make_team( 'alpha', 'Programa Latino', array( TEAMMATE ) );
expect( 'team member has it back',         may( TEAMMATE, 100 ), true );
SFAF_Teams::set_access_for_event( 100, array() );
expect( 'removing the team leaves only the organizer and admins', may( TEAMMATE, 100 ), false );
expect( 'the organizer is unaffected',     may( ORGANIZER, 100 ), true );
expect( 'the calendar admin is unaffected', may( CAL_ADMIN, 100 ), true );

/* --- Two teams. ----------------------------------------------------------- */
echo "Two teams\n";
SFAF_Teams::set_access_for_event( 100, array( 'alpha', 'beta' ) );
expect( 'first team edits',  may( TEAMMATE, 100 ),   true );
expect( 'second team edits', may( OTHER_TEAM, 100 ), true );

SFAF_Teams::set_access_for_event( 100, array( 'alpha', 'beta', 'alpha' ) );
if ( count( SFAF_Teams::access_for_event( 100 ) ) > SFAF_Teams::MAX_PER_EVENT ) {
    $fails[] = 'more than MAX_PER_EVENT teams were stored on one event';
}

/* --- An unknown team grants nothing. --------------------------------------
 *
 * ASSERTED AT TWO LEVELS ON PURPOSE, because it is guarded at two levels.
 * access_for_event() drops an id no team answers to, and user_owns_event()
 * checks again before reading a team's members. Testing only the outcome hides
 * which of the two is doing the work, and a plant that removes one of them then
 * passes: that is exactly what happened when these checks were first written.
 */
update_post_meta( 100, SFAF_Teams::ACCESS_META, array( 'ghost' ) );
if ( array() !== SFAF_Teams::access_for_event( 100 ) ) {
    $fails[] = 'access_for_event() returned a team id that no team answers to';
}
expect( 'a team id that no longer exists grants nobody anything', may( TEAMMATE, 100 ), false );

update_post_meta( 100, SFAF_Teams::ACCESS_META, array( 'ghost', 'alpha' ) );
expect( 'a real team beside a ghost still works', may( TEAMMATE, 100 ), true );
expect( 'and the ghost still grants nobody',      may( OUTSIDER, 100 ), false );

SFAF_Teams::set_access_for_event( 100, array( 'alpha' ) );

/* --- Clearing the teams really clears the stored value. -------------------
 *
 * The behavioural assertion above ("removing the team leaves only the
 * organizer") passes whether the meta is deleted or merely ignored, so this
 * asks the storage directly. A set_access_for_event( [] ) that leaves the old
 * array behind is a removal that appears to work and comes back on the next
 * read path that does not filter as carefully.
 */
SFAF_Teams::set_access_for_event( 100, array() );
if ( '' !== get_post_meta( 100, SFAF_Teams::ACCESS_META, true ) ) {
    $fails[] = 'clearing an event\'s teams left the old value in the database';
}
SFAF_Teams::set_access_for_event( 100, array( 'alpha' ) );

/* --- Nonsense in, nothing out. -------------------------------------------- */
echo "Edge cases\n";
expect( 'a missing event',           may( TEAMMATE, 9999 ), false );
expect( 'user id zero',              may( 0, 100 ),         false );
$GLOBALS['posts'][300] = (object) array( 'ID' => 300, 'post_author' => ORGANIZER, 'post_type' => 'page' );
SFAF_Teams::set_access_for_event( 300, array( 'alpha' ) );
expect( 'a post that is not an event', may( TEAMMATE, 300 ), false );

/* --- The 3.7.0 rule, which nothing here may weaken. ----------------------- */
echo "A calendar record can never reduce a WordPress administrator\n";
update_user_meta( WP_ADMIN, '_uc_calendar_role', 'contributor' );
expect( 'a contributor record on a WP admin',    may( WP_ADMIN, 200 ), true );
update_user_meta( WP_ADMIN, '_uc_calendar_role', 'nonsense' );
expect( 'a junk record on a WP admin',           may( WP_ADMIN, 200 ), true );
delete_user_meta( WP_ADMIN, '_uc_calendar_role' );

/* ===========================================================================
 * PART TWO: THE WHITELIST.
 *
 * Every route that reads or writes an event, or reads its registrations, with
 * the gate it is ALLOWED to use. A route in the source that is not named here
 * fails. A route whose gate is not in its allowed set fails. Both directions,
 * because a whitelist that only checks one is a list that goes stale.
 * ======================================================================== */
echo "The route whitelist\n";

/**
 * gate keys:
 *   event      the single gate, can_edit_event / user_can_edit_event
 *   viewall    can_view_all: a question about the whole calendar, not an event
 *   caladmin   is_admin_role
 *   create     can_create
 *   none       deliberately ungated, with the reason stated
 */
$ALLOWED = array(
    // --- Events: read or write one. Every one of these is the event gate. ---
    'POST:save_event'             => array( 'event', 'create' ),
    'POST:trash_event'            => array( 'event' ),
    // Cancelling is an edit to one event: the same gate, deliberately, so a
    // team member who may edit an event may also cancel it. It is not an
    // admin-only action, because the person running an event is the one who
    // knows it is not happening.
    'POST:cancel_event'           => array( 'event' ),
    'POST:duplicate_event'        => array( 'event' ),
    'POST:save_rsvp_settings'     => array( 'event' ),
    'POST:save_manager_fields'    => array( 'event', 'viewall' ),
    'POST:refresh_source_event'   => array( 'event' ),
    'POST:faq_set_apply'          => array( 'event' ),
    'POST:faq_set_create'         => array( 'event' ),
    'GET:events/edit'             => array( 'event' ),
    'GET:rsvps'                   => array( 'event', 'viewall' ),
    'GET:rsvps/export'            => array( 'event', 'viewall' ),

    // --- Calendar-wide, and correctly not per event. ------------------------
    'POST:save_series'            => array( 'viewall' ),
    'POST:remove_series'          => array( 'viewall' ),
    'POST:schedule_pattern'       => array( 'viewall' ),
    'POST:schedule_extend'        => array( 'viewall' ),
    'POST:schedule_add_date'      => array( 'viewall' ),
    'POST:schedule_remove_date'   => array( 'viewall' ),
    'POST:save_category'          => array( 'viewall' ),
    'POST:delete_category'        => array( 'viewall' ),
    'POST:save_venue'             => array( 'viewall' ),
    'POST:delete_venue'           => array( 'viewall' ),
    'POST:save_organizer'         => array( 'viewall' ),
    'POST:delete_organizer'       => array( 'viewall' ),
    'POST:faq_set_save'           => array( 'viewall' ),
    'POST:faq_set_delete'         => array( 'viewall' ),
    'GET:faq-sets'                => array( 'viewall' ),
    'GET:optins'                  => array( 'viewall' ),
    'GET:venues'                  => array( 'viewall' ),
    'GET:organizers'              => array( 'viewall' ),
    'GET:series'                  => array( 'viewall' ),

    // --- Administrator only. ------------------------------------------------
    'POST:approve_event'          => array( 'caladmin' ),
    'POST:reject_event'           => array( 'caladmin' ),
    'POST:fetch_sources'          => array( 'caladmin' ),
    'POST:import_publish'         => array( 'caladmin' ),
    'POST:import_dismiss'         => array( 'caladmin' ),
    'POST:import_restore'         => array( 'caladmin' ),
    'POST:add_user'               => array( 'caladmin' ),
    'POST:remove_user'            => array( 'caladmin' ),
    'POST:set_user_role'          => array( 'caladmin' ),
    'POST:save_team'              => array( 'caladmin' ),
    'POST:delete_team'            => array( 'caladmin' ),
    'GET:pending'                 => array( 'caladmin' ),
    'GET:users'                   => array( 'caladmin' ),

    /*
     * --- Deliberately ungated, each with its reason. -----------------------
     *
     * These are the rows a reader has to agree with; a script cannot.
     */
    // Shows only what the viewer may act on, by narrowing the QUERY rather than
    // by refusing. query_events() gives a non-view-all user their own events
    // plus their teams', and "All events" hands the ids to
    // public_events_table(), which has no code path that can emit a
    // registration count, a link or a form.
    'GET:events'                  => array( 'none' ),
    'GET:dashboard'               => array( 'none' ),
    // The scheduled runner's own screen. Read-only, no event data.
    'GET:automation'              => array( 'none' ),
);

$portal = $portal_src;

function switch_body_at( $src, $from ) {
    $open = strpos( $src, '{', $from );
    if ( false === $open ) { return ''; }
    $depth = 0;
    for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        if ( '}' === $src[ $i ] ) {
            $depth--;
            if ( 0 === $depth ) { return substr( $src, $open, $i - $open + 1 ); }
        }
    }
    return '';
}

$post_sw = strpos( $portal, 'switch ( $action ) {', strpos( $portal, 'private function dispatch_post(' ) );
$POST_SRC = switch_body_at( $portal, $post_sw );

if ( '' === $POST_SRC ) {
    $fails[] = 'the POST dispatcher could not be sliced; the whitelist is blind';
}

preg_match_all( "#\n            case '([a-z_]+)':#", $POST_SRC, $m );
$found_actions = array_values( array_unique( $m[1] ) );

if ( count( $found_actions ) < 20 ) {
    $fails[] = 'only ' . count( $found_actions ) . ' POST actions found; the dispatcher moved and this is blind';
}

/** Which gate vocabulary appears in a slice. */
function gate_keys( $code ) {
    $out = array();
    if ( preg_match( '#\b(can_edit_event|user_can_edit_event)\s*\(#', $code ) )   { $out[] = 'event'; }
    if ( preg_match( '#\b(can_view_all|user_can_view_all)\s*\(#', $code ) )       { $out[] = 'viewall'; }
    if ( preg_match( '#\bis_admin_role\s*\(#', $code ) )                          { $out[] = 'caladmin'; }
    if ( preg_match( '#\bcan_create\s*\(#', $code ) )                             { $out[] = 'create'; }
    return $out;
}

function case_slice( $src, $action ) {
    $needle = "case '" . $action . "':";
    $at = strpos( $src, $needle );
    if ( false === $at ) { return ''; }
    $next = strpos( $src, "\n            case '", $at + strlen( $needle ) );
    return substr( $src, $at, ( false === $next ? 4000 : $next - $at ) );
}

foreach ( $found_actions as $action ) {
    $key = 'POST:' . $action;

    if ( ! isset( $ALLOWED[ $key ] ) ) {
        $fails[] = "$key is a route and is NOT on the whitelist. Add it deliberately, with the gate it should use.";
        continue;
    }

    $slice = case_slice( $POST_SRC, $action );
    $gates = gate_keys( $slice );

    // Follow one level into a save_*_from_post() helper, which is where some
    // actions keep their gate.
    if ( ! $gates && preg_match( '#\$this->([a-z_]+)\s*\(#', $slice, $call ) ) {
        if ( preg_match( '#function ' . $call[1] . '\s*\([^)]*\)\s*\{#', $portal, $fm, PREG_OFFSET_CAPTURE ) ) {
            $gates = gate_keys( substr( $portal, $fm[0][1], 3000 ) );
        }
    }

    $allowed = $ALLOWED[ $key ];

    if ( in_array( 'none', $allowed, true ) ) {
        continue;
    }

    if ( ! $gates ) {
        $fails[] = "$key has NO gate at all. Every route that reads or writes an event must ask one.";
        continue;
    }

    foreach ( $gates as $g ) {
        if ( ! in_array( $g, $allowed, true ) ) {
            $fails[] = "$key uses the '$g' gate, which the whitelist does not allow for it (allowed: " . implode( ', ', $allowed ) . ").";
        }
    }
}

// The other direction: a whitelist entry for a POST route that no longer
// exists is a line nobody has read in a while.
foreach ( array_keys( $ALLOWED ) as $key ) {
    if ( 0 !== strpos( $key, 'POST:' ) ) {
        continue;
    }
    $action = substr( $key, 5 );
    if ( ! in_array( $action, $found_actions, true ) ) {
        $fails[] = "$key is on the whitelist and no longer exists in the dispatcher. Remove it.";
    }
}

/*
 * THE TWO ROUTES THAT ARE NOT `case` ARMS, checked by reading the source for
 * the gate they must contain. Both were widened in 3.35.0 and both hand out
 * registration data, which is the category every one of the five defects came
 * from.
 */
foreach ( array(
    'render_rsvps'      => 'GET:rsvps',
    'export_rsvps_csv'  => 'GET:rsvps/export',
) as $method => $label ) {
    if ( ! preg_match( '#function ' . $method . '\s*\([^)]*\)\s*\{#', $portal, $fm, PREG_OFFSET_CAPTURE ) ) {
        $fails[] = "$label: $method() not found";
        continue;
    }
    $slice = substr( $portal, $fm[0][1], 3000 );
    $gates = gate_keys( $slice );
    if ( ! in_array( 'event', $gates, true ) ) {
        $fails[] = "$label does not ask the event gate, so a team member cannot reach their own event's registrations (or worse, anyone can).";
    }
    if ( ! in_array( 'viewall', $gates, true ) ) {
        $fails[] = "$label does not keep can_view_all for the unscoped list, so one event's gate would let somebody read the whole calendar's registrations.";
    }
}

/*
 * NO SECOND ANSWER TO THE EDIT QUESTION.
 *
 * The whole point of §5 is one function. This looks for the shape of a rule
 * being re-derived somewhere else: a direct post_author comparison outside the
 * gate itself is how a second, quietly different answer gets written.
 */
$outside = 0;
foreach ( preg_split( '#\n#', $portal ) as $i => $line ) {
    if ( preg_match( '#post_author\s*===?\s*.*user#i', $line ) || preg_match( '#user.*===?\s*.*post_author#i', $line ) ) {
        // The gate itself is allowed exactly one.
        if ( false !== strpos( $bodies['user_can_edit_event'], trim( $line ) ) ) {
            continue;
        }
        $outside++;
        $fails[] = sprintf(
            'class-sfaf-portal.php:%d compares post_author to a user id outside user_can_edit_event(). That is a second answer to the one question this release exists to have one answer for.',
            $i + 1
        );
    }
}

/* ------------------------------------------------------------------------ */
echo "\nEvent access test\n";
echo "part one:  the real gate, extracted from the source and asserted to consult teams, against\n";
echo "           every combination of role, organizer and membership, plus a team member with no\n";
echo "           calendar record, live join and leave, team removal, two teams and the 3.7.0 rule\n";
echo "part two:  " . count( $ALLOWED ) . " routes on the whitelist, checked in both directions, plus the two\n";
echo "           registration routes read for their gates, and a sweep for a second post_author rule\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "one function answers who may edit an event, and every route asks it.\n";
