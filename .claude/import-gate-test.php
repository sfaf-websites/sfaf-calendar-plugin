<?php
/**
 * WHAT IS ALLOWED TO BECOME AN EVENT, AND WHAT LEAVES THE QUEUES.
 *
 *     php .claude/import-gate-test.php
 *     php .claude/import-gate-test.php --self-test
 *
 * WHY THIS FILE EXISTS. The Pending queue filled with rows like "SFAF Website
 * Donations, Feb 23 2026 11:29 pm" — GoFundMe Pro campaigns that are not events
 * and never were. The adapter maps `started_at` into the event date, which on a
 * ticketed campaign is the event and on a donation page is the moment somebody
 * created the campaign. So a date test alone would have cleared the rows that
 * prompted the complaint and none of the ones arriving next week.
 *
 * THE DANGEROUS HALF IS NOT THE REFUSAL, IT IS WHERE IT HAPPENS. The obvious
 * place to refuse an item is normalize(), and it is the wrong one: run_adapter()
 * adds an item's id to $seen_ids only AFTER normalize() succeeds, and
 * handle_removals() treats anything not in $seen_ids as gone from the source and
 * UNPUBLISHES it. Refusing in normalize() would therefore have taken live,
 * published events off the calendar every time a campaign was refused — the one
 * outcome the brief forbids outright. Section 3 is that assertion and it is the
 * reason this file renders a whole fetch instead of unit-testing a predicate.
 *
 * THE SECOND RULE IS THE QUEUE SWEEP, agreed 2026-07-29: an expired row leaves
 * the decision queues by itself. Its boundary is the same shape of hazard — the
 * rule must not reach a PUBLISHED event, which simply becomes a past event.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ . '/' );
define( 'SFAF_VERSION', '0.0.0-test' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
date_default_timezone_set( 'America/Los_Angeles' );

$fails = array();
$self  = in_array( '--self-test', $argv, true );
function fail( $m ) { global $fails; $fails[] = $m; }
function is_( $label, $got, $want ) {
    if ( $got !== $want ) {
        fail( sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) ) );
    }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Posts are an array; WP_Query filters on post_status
 * and on a single meta_query clause, which is all SFAF_Sources asks of it.
 * ------------------------------------------------------------------------ */
$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();
$GLOBALS['next']  = 500;

function wp_timezone() { return new DateTimeZone( 'America/Los_Angeles' ); }
function apply_filters( $t, $v ) { return $v; }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function do_action( $h ) {}
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $t ) { return (string) $t; }
function __( $t, $d = '' ) { return $t; }
function absint( $n ) { return abs( (int) $n ); }
function wp_strip_all_tags( $t, $b = false ) { return trim( strip_tags( (string) $t ) ); }
function wp_kses_post( $t ) { return (string) $t; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $k ) ); }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( (string) $s ) ) ); }
function wp_list_pluck( $list, $field ) {
    $out = array();
    foreach ( (array) $list as $i ) { $out[] = is_object( $i ) ? $i->$field : $i[ $field ]; }
    return $out;
}
function get_option( $n, $d = false ) { return isset( $GLOBALS['opt'][ $n ] ) ? $GLOBALS['opt'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opt'][ $n ] = $v; return true; }
function is_wp_error( $t ) { return ( $t instanceof WP_Error ); }
class WP_Error {
    private $msg;
    public function __construct( $c = '', $m = '' ) { $this->msg = $m; }
    public function get_error_message() { return $this->msg; }
}

function get_post_meta( $id, $k, $single = false ) {
    return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : '';
}
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); return true; }
function get_post_status( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]['post_status'] : false; }
function get_the_title( $id = 0 ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]['post_title'] : ''; }
function get_post( $id = 0 ) {
    return isset( $GLOBALS['posts'][ $id ] ) ? (object) array_merge( array( 'ID' => (int) $id ), $GLOBALS['posts'][ $id ] ) : null;
}
function wp_insert_post( $args, $wp_error = false ) {
    $id = $GLOBALS['next']++;
    $GLOBALS['posts'][ $id ] = array(
        'post_title'  => isset( $args['post_title'] ) ? $args['post_title'] : '',
        'post_status' => isset( $args['post_status'] ) ? $args['post_status'] : 'draft',
        'post_type'   => 'uc_event',
        'post_date'   => date( 'Y-m-d H:i:s' ),
    );
    return $id;
}
function wp_update_post( $args, $wp_error = false ) {
    $id = (int) $args['ID'];
    if ( isset( $GLOBALS['posts'][ $id ] ) && isset( $args['post_status'] ) ) {
        $GLOBALS['posts'][ $id ]['post_status'] = $args['post_status'];
    }
    return $id;
}
function wp_set_object_terms( $id, $terms, $tax, $append = false ) { return array(); }
function get_current_user_id() { return 0; }
function current_time( $t, $gmt = 0 ) { return 'mysql' === $t ? date( 'Y-m-d H:i:s' ) : time(); }
function sfaf_get_faqs( $id ) { return array(); }
function sfaf_faq_meta_key() { return '_uc_faqs'; }

/**
 * Enough WP_Query for find_existing(), handle_removals(), queue_ids() and the
 * sweep: post_status as string or array, and one meta_query clause.
 */
class WP_Query {
    public $posts = array();
    public $found_posts = 0;
    public function __construct( $args ) {
        $want = isset( $args['post_status'] ) ? (array) $args['post_status'] : array( 'publish' );
        $out  = array();
        foreach ( $GLOBALS['posts'] as $id => $p ) {
            if ( ! in_array( $p['post_status'], $want, true ) ) {
                continue;
            }
            if ( ! empty( $args['meta_query'] ) ) {
                $ok = true;
                foreach ( $args['meta_query'] as $k => $clause ) {
                    if ( 'relation' === $k || ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
                        continue;
                    }
                    $have = isset( $GLOBALS['meta'][ $id ][ $clause['key'] ] ) ? $GLOBALS['meta'][ $id ][ $clause['key'] ] : null;
                    if ( isset( $clause['compare'] ) && 'NOT EXISTS' === $clause['compare'] ) {
                        if ( null !== $have ) { $ok = false; }
                        continue;
                    }
                    if ( ! isset( $clause['value'] ) || (string) $have !== (string) $clause['value'] ) {
                        $ok = false;
                    }
                }
                if ( ! $ok ) { continue; }
            }
            $out[] = $id;
        }
        $this->found_posts = count( $out );
        $this->posts = ( isset( $args['fields'] ) && 'ids' === $args['fields'] )
            ? array_map( 'intval', $out )
            : array_map( function ( $id ) { return get_post( $id ); }, $out );
    }
}

require_once $root . '/includes/class-sfaf-sources.php';

/** A source that returns exactly what a case hands it. */
class Fake_Adapter extends SFAF_Source_Adapter {
    public static $items = array();
    public function slug() { return 'gofundme_pro'; }
    public function label() { return 'GoFundMe Pro'; }
    public function is_active() { return true; }
    public function inactive_reason() { return ''; }
    public function fetch() { return array( 'items' => self::$items, 'complete' => true, 'notes' => array() ); }
    public function normalize( $item ) { return $item; }
}

/** A normalized event, defaulted to a perfectly good upcoming one. */
function ev( $over = array() ) {
    $tz = new DateTimeZone( 'America/Los_Angeles' );
    return array_merge( array(
        'external_source' => 'gofundme_pro',
        'external_id'     => 'c1',
        'title'           => 'A real event',
        'description'     => '',
        'start_date'      => ( new DateTime( '+10 days', $tz ) )->format( 'Y-m-d' ),
        'start_time'      => '18:00',
        'end_date'        => '',
        'end_time'        => '',
        'location'        => '',
        'source_url'      => '',
    ), $over );
}
function day( $offset ) {
    return ( new DateTime( $offset . ' days', new DateTimeZone( 'America/Los_Angeles' ) ) )->format( 'Y-m-d' );
}
function reset_world() {
    $GLOBALS['posts'] = array();
    $GLOBALS['meta']  = array();
    $GLOBALS['opt']   = array();
    $GLOBALS['next']  = 500;
}

/* ===========================================================================
 * 1. WHICH DAY AN EVENT STOPS COUNTING.
 * ======================================================================== */
echo "The last day\n";

is_( 'a one-day event ends on its date', SFAF_Sources::last_day( '2026-03-04', '', 'ticketed' ), '2026-03-04' );
is_( 'a multi-day event ends on its end date', SFAF_Sources::last_day( '2026-03-04', '2026-03-08', 'ticketed' ), '2026-03-08' );
is_( 'an end before the start is ignored', SFAF_Sources::last_day( '2026-03-04', '2026-03-01', 'ticketed' ), '2026-03-04' );
is_( 'no date has no last day', SFAF_Sources::last_day( '' ), '' );

/*
 * THE FIX IN 3.59.0, AND THE WHOLE POINT OF THE TYPE.
 *
 * `_uc_end_date` is written only by a source, and on a GoFundMe Pro campaign it
 * holds `ended_at` — the close of the FUNDRAISING WINDOW. A donation page
 * collecting until December reported a December last day and never cleared,
 * while the screen showed a start date months past. Four rows sat in the queues
 * on exactly that. The end date is believed only where the type says it means
 * an event's end.
 */
is_( 'a donation page ignores its fundraising window', SFAF_Sources::last_day( day( -120 ), day( 120 ), 'donation' ), day( -120 ) );
is_( 'AND AN UNKNOWN TYPE IGNORES IT TOO', SFAF_Sources::last_day( day( -120 ), day( 120 ), '' ), day( -120 ) );
is_( 'a ticketed event still believes its end date', SFAF_Sources::last_day( day( -2 ), day( 2 ), 'ticketed' ), day( 2 ) );
is_( 'an Eventbrite event believes its end date', SFAF_Sources::last_day( day( -2 ), day( 2 ), 'event' ), day( 2 ) );

/* An unrecognised type must NOT default into being treated as an event. */
is_( 'a type the platform adds later is not event-shaped', SFAF_Sources::type_is_event_shaped( 'some_new_thing' ), false );
is_( 'a blank type is not event-shaped', SFAF_Sources::type_is_event_shaped( '' ), false );
foreach ( array( 'event', 'ticketed', 'registration', 'reg_w_fund', 'fund_for_entry' ) as $t ) {
    is_( "$t is event-shaped", SFAF_Sources::type_is_event_shaped( $t ), true );
}
foreach ( array( 'donation', 'crowdfunding', 'peer_to_peer', 'dynamic', 'gfm_npo', 'gfm_p2p' ) as $t ) {
    is_( "$t is not event-shaped", SFAF_Sources::type_is_event_shaped( $t ), false );
}

is_( 'yesterday has passed', SFAF_Sources::date_has_passed( day( -1 ) ), true );
is_( 'TODAY HAS NOT PASSED', SFAF_Sources::date_has_passed( day( 0 ) ), false );
is_( 'tomorrow has not passed', SFAF_Sources::date_has_passed( day( 1 ) ), false );
// A conference that started Thursday and runs to Sunday is not over on Friday.
is_( 'a multi-day event running through today has not passed', SFAF_Sources::date_has_passed( day( -2 ), day( 2 ), 'ticketed' ), false );
is_( 'a multi-day event that finished has passed', SFAF_Sources::date_has_passed( day( -5 ), day( -2 ), 'ticketed' ), true );
// Dateless is refused for its own reason and must not be called "past".
is_( 'a dateless event has not "passed"', SFAF_Sources::date_has_passed( '' ), false );
// The window does not keep a donation page alive.
is_( 'a past donation page HAS passed despite an open window', SFAF_Sources::date_has_passed( day( -120 ), day( 120 ), 'donation' ), true );

/* ===========================================================================
 * 2. WHAT MAY BECOME AN EVENT.
 * ======================================================================== */
echo "The import gate\n";

is_( 'an upcoming event is imported', SFAF_Sources::import_refusal( ev() ), '' );
is_( "today's event is imported", SFAF_Sources::import_refusal( ev( array( 'start_date' => day( 0 ) ) ) ), '' );

$no_date = SFAF_Sources::import_refusal( ev( array( 'start_date' => '' ) ) );
if ( false === strpos( $no_date, 'no event date' ) ) {
    fail( 'a dateless item is not refused for having no date: ' . var_export( $no_date, true ) );
}

$past = SFAF_Sources::import_refusal( ev( array( 'start_date' => day( -3 ) ) ) );
if ( false === strpos( $past, 'passed' ) ) {
    fail( 'a past item is not refused for being past: ' . var_export( $past, true ) );
}

/*
 * THE ADAPTER'S VERDICT OUTRANKS THE DATE, and this is the case the whole
 * investigation turned on: a donation page carrying a perfectly good future
 * date is still not an event. Refusing it for its date would be wrong AND
 * would stop working the moment the date was in the future.
 */
$not_event = SFAF_Sources::import_refusal( ev( array(
    'start_date'   => day( 30 ),
    'not_an_event' => 'it is a donation campaign at GoFundMe Pro, not an event',
) ) );
if ( false === strpos( $not_event, 'not an event' ) ) {
    fail( 'a future-dated donation page is not refused: ' . var_export( $not_event, true ) );
}

/* ===========================================================================
 * 3. THE REMOVAL SAFEGUARD.
 *
 * THE ASSERTION THIS FILE WAS WRITTEN FOR. A refused item must still count as
 * present at the source, or handle_removals() reads its absence as a deletion
 * and unpublishes the live event that came from it.
 * ======================================================================== */
echo "A refusal does not look like a deletion\n";

reset_world();

// A published event imported from campaign c1 in a previous run.
$GLOBALS['posts'][601] = array( 'post_title' => 'Gala', 'post_status' => 'publish', 'post_type' => 'uc_event' );
$GLOBALS['meta'][601]  = array(
    SFAF_Sources::META_SOURCE      => 'gofundme_pro',
    SFAF_Sources::META_EXTERNAL_ID => 'c1',
    '_uc_event_date'               => day( -3 ),   // it happened last week
);

// This run returns c1 again, but its date has now passed, so the gate would
// refuse it if it were arriving for the first time.
Fake_Adapter::$items = array( ev( array( 'external_id' => 'c1', 'start_date' => day( -3 ) ) ) );
$res = SFAF_Sources::run_adapter( new Fake_Adapter() );

is_( 'the published event is still published', get_post_status( 601 ), 'publish' );
is_( 'nothing was unpublished', (int) $res['unpublished'], 0 );
is_( 'nothing vanished', (int) $res['vanished'], 0 );
if ( get_post_meta( 601, SFAF_Sources::META_REMOVED_AT, true ) ) {
    fail( 'a published event was marked removed at source because its own campaign was past' );
}

/*
 * AND A GENUINELY REFUSED NEW ITEM STILL PROTECTS ITS OWN CAMPAIGN. c2 is a
 * donation page: refused, no row created — and the published event that came
 * from c2 in some earlier era must not be unpublished for it.
 */
reset_world();
$GLOBALS['posts'][602] = array( 'post_title' => 'Old import', 'post_status' => 'publish', 'post_type' => 'uc_event' );
$GLOBALS['meta'][602]  = array(
    SFAF_Sources::META_SOURCE      => 'gofundme_pro',
    SFAF_Sources::META_EXTERNAL_ID => 'c2',
    '_uc_event_date'               => day( 20 ),
);
// The stored event is matched by external id, so it is UPDATED, not refused.
// c3 is the new donation page and is the one that must be refused.
Fake_Adapter::$items = array(
    ev( array( 'external_id' => 'c2', 'start_date' => day( 20 ) ) ),
    ev( array( 'external_id' => 'c3', 'title' => 'SFAF Website Donations', 'start_date' => day( 5 ), 'not_an_event' => 'it is a donation campaign at GoFundMe Pro, not an event' ) ),
);
$before = count( $GLOBALS['posts'] );
$res    = SFAF_Sources::run_adapter( new Fake_Adapter() );

is_( 'the donation page was refused', (int) $res['refused'], 1 );
is_( 'no row was created for it', count( $GLOBALS['posts'] ), $before );
is_( 'the other published event is untouched', get_post_status( 602 ), 'publish' );
is_( 'nothing was unpublished', (int) $res['unpublished'], 0 );

// The refusal is reported by name, or nobody can tell why an event never came.
$said = false;
foreach ( (array) $res['notes'] as $n ) {
    if ( false !== strpos( $n, 'SFAF Website Donations' ) && false !== strpos( $n, 'not an event' ) ) { $said = true; }
}
if ( ! $said ) {
    fail( 'the refusal is not reported with the campaign name and the reason' );
}

/*
 * THE CASE THE PLANT EXPOSED, AND THE SHARPEST ONE IN THIS FILE.
 *
 * A PUBLISHED event whose campaign would now be refused. Both earlier cases
 * survived a deliberately misplaced refusal by accident: one left $seen_ids
 * empty and was saved by the belt-and-braces guard, and in the other no
 * published event belonged to the refused campaign. Neither is the hazard.
 *
 * Here c5 IS a live published event and its campaign is a donation page, with
 * c2 alongside so the run is not empty and handle_removals() really engages.
 * Refuse before find_existing() and c5 drops out of $seen_ids, the removal
 * check reads that as "deleted at the source", and a live event comes off the
 * calendar. That is the whole reason the gate sits where it sits.
 */
reset_world();
$GLOBALS['posts'][604] = array( 'post_title' => 'Live gala', 'post_status' => 'publish', 'post_type' => 'uc_event' );
$GLOBALS['meta'][604]  = array(
    SFAF_Sources::META_SOURCE      => 'gofundme_pro',
    SFAF_Sources::META_EXTERNAL_ID => 'c5',
    '_uc_event_date'               => day( 40 ),
);
$GLOBALS['posts'][605] = array( 'post_title' => 'Another', 'post_status' => 'publish', 'post_type' => 'uc_event' );
$GLOBALS['meta'][605]  = array(
    SFAF_Sources::META_SOURCE      => 'gofundme_pro',
    SFAF_Sources::META_EXTERNAL_ID => 'c2',
    '_uc_event_date'               => day( 20 ),
);
Fake_Adapter::$items = array(
    ev( array( 'external_id' => 'c2', 'start_date' => day( 20 ) ) ),
    ev( array( 'external_id' => 'c5', 'title' => 'Live gala', 'start_date' => day( 40 ), 'not_an_event' => 'it is a donation campaign at GoFundMe Pro, not an event' ) ),
);
$res = SFAF_Sources::run_adapter( new Fake_Adapter() );

is_( 'the removal check really ran', (bool) $res['removal_ran'], true );
is_( 'A LIVE EVENT WHOSE CAMPAIGN IS REFUSED STAYS PUBLISHED', get_post_status( 604 ), 'publish' );
is_( 'nothing was unpublished by a refusal', (int) $res['unpublished'], 0 );
is_( 'nothing looked like it had vanished', (int) $res['vanished'], 0 );
if ( get_post_meta( 604, SFAF_Sources::META_REMOVED_AT, true ) ) {
    fail( 'a refusal marked a live published event as removed at the source' );
}

/*
 * AN ALREADY-IMPORTED EVENT IS NEVER RE-JUDGED. A queue row whose date has
 * since passed keeps being refreshed rather than being refused: the gate is
 * about creating rows, and the sweep in section 4 is what clears that one.
 */
reset_world();
$GLOBALS['posts'][603] = array( 'post_title' => 'Queued', 'post_status' => SFAF_Sources::STATUS_PENDING, 'post_type' => 'uc_event' );
$GLOBALS['meta'][603]  = array(
    SFAF_Sources::META_SOURCE      => 'gofundme_pro',
    SFAF_Sources::META_EXTERNAL_ID => 'c4',
    '_uc_event_date'               => day( -2 ),
);
Fake_Adapter::$items = array( ev( array( 'external_id' => 'c4', 'title' => 'Queued', 'start_date' => day( -2 ) ) ) );
$res = SFAF_Sources::run_adapter( new Fake_Adapter() );
is_( 'an existing row is not refused', (int) $res['refused'], 0 );
is_( 'it is still in the queue after the fetch', get_post_status( 603 ), SFAF_Sources::STATUS_PENDING );

/* ===========================================================================
 * 4. THE QUEUES CLEAR THEMSELVES, AND STOP THERE.
 * ======================================================================== */
echo "The queue sweep\n";

reset_world();
// One of everything the sweep can see, plus the two it must not touch.
$GLOBALS['posts'][701] = array( 'post_title' => 'Past pending',      'post_status' => SFAF_Sources::STATUS_PENDING,   'post_type' => 'uc_event' );
$GLOBALS['posts'][702] = array( 'post_title' => 'Future pending',    'post_status' => SFAF_Sources::STATUS_PENDING,   'post_type' => 'uc_event' );
$GLOBALS['posts'][703] = array( 'post_title' => 'Dateless pending',  'post_status' => SFAF_Sources::STATUS_PENDING,   'post_type' => 'uc_event' );
$GLOBALS['posts'][704] = array( 'post_title' => 'Past dismissed',    'post_status' => SFAF_Sources::STATUS_DISMISSED, 'post_type' => 'uc_event' );
$GLOBALS['posts'][705] = array( 'post_title' => 'PAST PUBLISHED',    'post_status' => 'publish',                      'post_type' => 'uc_event' );
$GLOBALS['posts'][706] = array( 'post_title' => 'Past draft',        'post_status' => 'draft',                        'post_type' => 'uc_event' );
$GLOBALS['meta'][701]  = array( '_uc_event_date' => day( -1 ) );
$GLOBALS['meta'][702]  = array( '_uc_event_date' => day( 9 ) );
$GLOBALS['meta'][704]  = array( '_uc_event_date' => day( -6 ) );
$GLOBALS['meta'][705]  = array( '_uc_event_date' => day( -6 ) );
$GLOBALS['meta'][706]  = array( '_uc_event_date' => day( -6 ) );

$out = SFAF_Sources::sweep_queues();

is_( 'the expired pending row is dismissed', get_post_status( 701 ), SFAF_Sources::STATUS_DISMISSED );
is_( 'the upcoming pending row is left alone', get_post_status( 702 ), SFAF_Sources::STATUS_PENDING );
/*
 * A DATELESS ROW STAYS IN THE QUEUE. It has no date to have passed, and a
 * manager fills that in at approval — PROJECT.md is explicit that dateless
 * imports are accommodated. Sweeping it would delete somebody's work item for
 * the crime of being the thing they were about to do.
 */
is_( 'the dateless pending row stays', get_post_status( 703 ), SFAF_Sources::STATUS_PENDING );
is_( 'an already-dismissed row is not churned', get_post_status( 704 ), SFAF_Sources::STATUS_DISMISSED );

/* THE BOUNDARY. A published event that expires is a past event and stays one. */
is_( 'A PAST PUBLISHED EVENT IS UNTOUCHED', get_post_status( 705 ), 'publish' );
is_( 'a past draft is untouched', get_post_status( 706 ), 'draft' );
is_( 'the sweep counted the one it moved', (int) $out['counts']['dismissed'], 1 );

/* ===========================================================================
 * 5. WHAT THE QUEUES SHOW, AND WHAT THE BADGE SAYS.
 * ======================================================================== */
echo "The lists and their counts\n";

$pending = SFAF_Sources::queue_ids( SFAF_Sources::STATUS_PENDING );
sort( $pending );
is_( 'the pending list is the two live rows', $pending, array( 702, 703 ) );

// 701 was swept into dismissed and 704 was already there; both are expired, so
// the Dismissed list shows neither. That is the half a status move cannot do.
is_( 'no expired row is shown under Dismissed', SFAF_Sources::queue_ids( SFAF_Sources::STATUS_DISMISSED ), array() );

/* A count beside a list must be a count OF that list. */
is_( 'the pending badge matches the pending list', SFAF_Sources::queue_count( SFAF_Sources::STATUS_PENDING ), count( $pending ) );
is_( 'the dismissed badge matches the dismissed list', SFAF_Sources::queue_count( SFAF_Sources::STATUS_DISMISSED ), 0 );

/*
 * AND THE LIST HIDES AN EXPIRED ROW BEFORE THE SWEEP HAS RUN. The sweep is on
 * the 15-minute runner; somebody opening the queue in between must not see a
 * row that expired an hour ago.
 */
reset_world();
$GLOBALS['posts'][801] = array( 'post_title' => 'Expired, unswept', 'post_status' => SFAF_Sources::STATUS_PENDING, 'post_type' => 'uc_event' );
$GLOBALS['meta'][801]  = array( '_uc_event_date' => day( -1 ) );
is_( 'an expired row is hidden before the sweep runs', SFAF_Sources::queue_ids( SFAF_Sources::STATUS_PENDING ), array() );
is_( 'and it is still stored, not deleted', get_post_status( 801 ), SFAF_Sources::STATUS_PENDING );

/* ===========================================================================
 * 6. THE FOUR ROWS THAT SURVIVED 3.58.0.
 *
 * Rebuilt from what the screen showed on Aug 26 2026: past start dates, and a
 * fundraising window still open behind them. Every one of these is a row the
 * previous sweep looked at on every pass and judged not spent.
 * ======================================================================== */
echo "The rows 3.58.0 could not clear\n";

reset_world();

/* Pending. Types unknown, because nothing stored a type before 3.59.0. */
$GLOBALS['posts'][901] = array( 'post_title' => 'The Agenda Event 2026', 'post_status' => SFAF_Sources::STATUS_PENDING, 'post_type' => 'uc_event' );
$GLOBALS['meta'][901]  = array( '_uc_event_date' => '2026-07-29', '_uc_end_date' => '2026-12-31' );

$GLOBALS['posts'][902] = array( 'post_title' => 'SFAF Giving Appeal - June 2026 Multi-Channel', 'post_status' => SFAF_Sources::STATUS_PENDING, 'post_type' => 'uc_event' );
$GLOBALS['meta'][902]  = array( '_uc_event_date' => '2026-05-26', '_uc_end_date' => '2026-12-31' );

$GLOBALS['posts'][903] = array( 'post_title' => 'SFAF Giving Appeal - June 2026', 'post_status' => SFAF_Sources::STATUS_PENDING, 'post_type' => 'uc_event' );
$GLOBALS['meta'][903]  = array( '_uc_event_date' => '2026-04-21', '_uc_end_date' => '2026-12-31' );

/* Dismissed, and visible, which is what proved the predicate was the fault. */
$GLOBALS['posts'][904] = array( 'post_title' => 'SFAF Board Impact', 'post_status' => SFAF_Sources::STATUS_DISMISSED, 'post_type' => 'uc_event' );
$GLOBALS['meta'][904]  = array( '_uc_event_date' => '2026-02-20', '_uc_end_date' => '2026-12-31' );

/* The two that must NOT move, alongside them. */
$GLOBALS['posts'][905] = array( 'post_title' => 'A real conference, running now', 'post_status' => SFAF_Sources::STATUS_PENDING, 'post_type' => 'uc_event' );
$GLOBALS['meta'][905]  = array( '_uc_event_date' => day( -1 ), '_uc_end_date' => day( 2 ), SFAF_Sources::META_SOURCE_TYPE => 'ticketed' );
$GLOBALS['posts'][906] = array( 'post_title' => 'A past published campaign', 'post_status' => 'publish', 'post_type' => 'uc_event' );
$GLOBALS['meta'][906]  = array( '_uc_event_date' => '2026-02-20', '_uc_end_date' => '2026-12-31' );

foreach ( array( 901, 902, 903, 904 ) as $id ) {
    is_( "row $id is spent", SFAF_Sources::queue_row_is_spent( $id ), true );
}
is_( 'the conference in progress is NOT spent', SFAF_Sources::queue_row_is_spent( 905 ), false );

$out = SFAF_Sources::sweep_queues();

is_( 'the three pending rows are dismissed', array(
    get_post_status( 901 ), get_post_status( 902 ), get_post_status( 903 )
), array( SFAF_Sources::STATUS_DISMISSED, SFAF_Sources::STATUS_DISMISSED, SFAF_Sources::STATUS_DISMISSED ) );
is_( 'the dismissed row stays dismissed', get_post_status( 904 ), SFAF_Sources::STATUS_DISMISSED );
is_( 'the conference stays in Pending', get_post_status( 905 ), SFAF_Sources::STATUS_PENDING );
is_( 'THE PUBLISHED ROW IS UNTOUCHED', get_post_status( 906 ), 'publish' );

/* And what a person then sees: one row waiting, nothing under Dismissed. */
is_( 'only the conference is left in Pending', SFAF_Sources::queue_ids( SFAF_Sources::STATUS_PENDING ), array( 905 ) );
is_( 'the Dismissed card is empty', SFAF_Sources::queue_ids( SFAF_Sources::STATUS_DISMISSED ), array() );

/* ===========================================================================
 * 7. THE TYPE IS STORED, AND FILLS IN ON A ROW THAT NEVER HAD ONE.
 * ======================================================================== */
echo "The stored type\n";

reset_world();
Fake_Adapter::$items = array( ev( array( 'external_id' => 'c9', 'source_type' => 'ticketed' ) ) );
SFAF_Sources::run_adapter( new Fake_Adapter() );
$new_id = array_key_last( $GLOBALS['posts'] );
is_( 'a new import records its type', (string) get_post_meta( $new_id, SFAF_Sources::META_SOURCE_TYPE, true ), 'ticketed' );

/*
 * A ROW FROM BEFORE 3.59.0 LEARNS ITS TYPE on the next fetch that still returns
 * its campaign. This is what makes a genuine multi-day event stop being judged
 * on its start date. A campaign the source no longer returns never gets here,
 * and is judged on its start forever, which is accepted rather than solved.
 */
reset_world();
/*
 * EVERY OTHER FIELD ALREADY MATCHES WHAT THE FETCH RETURNS. That is the point:
 * the only thing this run can change is the type, so "was anything reported as
 * changed?" is a question about the type alone. Left mismatched, the title
 * moved and the assertion passed or failed on that instead.
 */
$GLOBALS['posts'][910] = array( 'post_title' => 'A real event', 'post_status' => SFAF_Sources::STATUS_PENDING, 'post_type' => 'uc_event' );
$GLOBALS['meta'][910]  = array(
    SFAF_Sources::META_SOURCE      => 'gofundme_pro',
    SFAF_Sources::META_EXTERNAL_ID => 'c10',
    '_uc_event_date'               => day( 5 ),
    '_uc_start_time'               => '18:00',
);
is_( 'it starts with no type', (string) get_post_meta( 910, SFAF_Sources::META_SOURCE_TYPE, true ), '' );

Fake_Adapter::$items = array( ev( array( 'external_id' => 'c10', 'start_date' => day( 5 ), 'source_type' => 'ticketed' ) ) );
$res = SFAF_Sources::run_adapter( new Fake_Adapter() );
is_( 'the refresh filled the type in', (string) get_post_meta( 910, SFAF_Sources::META_SOURCE_TYPE, true ), 'ticketed' );

/*
 * AND IT IS NOT REPORTED AS A CHANGE. The first run after 3.59.0 would
 * otherwise tell a manager every still-returned event had changed, which is
 * this build catching up rather than anything the source did.
 */
is_( 'filling the type in is not reported as an event change', (int) $res['updated'], 0 );

/* A row removed at source is spent too, whatever its date says. */
reset_world();
$GLOBALS['posts'][802] = array( 'post_title' => 'Gone from source', 'post_status' => SFAF_Sources::STATUS_DISMISSED, 'post_type' => 'uc_event' );
$GLOBALS['meta'][802]  = array( '_uc_event_date' => day( 30 ), SFAF_Sources::META_REMOVED_AT => time() );
is_( 'a source-removed row is spent', SFAF_Sources::queue_row_is_spent( 802 ), true );

/* ---------------------------------------------------------------------------
 * SELF TEST. The harness has to be able to fail, or none of the above means
 * anything. Each case below is a fault this file must catch.
 * ------------------------------------------------------------------------ */
if ( $self ) {
    echo "\nSELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    $bad = 0;

    // The WP_Query stub really filters on post_status.
    reset_world();
    $GLOBALS['posts'][1] = array( 'post_title' => 'a', 'post_status' => 'publish', 'post_type' => 'uc_event' );
    $GLOBALS['posts'][2] = array( 'post_title' => 'b', 'post_status' => SFAF_Sources::STATUS_PENDING, 'post_type' => 'uc_event' );
    $q = new WP_Query( array( 'post_status' => SFAF_Sources::STATUS_PENDING, 'fields' => 'ids' ) );
    if ( array( 2 ) === $q->posts ) {
        echo "ok       the query stub filters on post_status\n";
    } else {
        echo "BROKEN:  the query stub returns everything, so section 4's boundary proves nothing\n"; $bad++;
    }

    // It really filters on meta, which is what find_existing() depends on.
    $GLOBALS['meta'][2] = array( SFAF_Sources::META_SOURCE => 'gofundme_pro' );
    $q = new WP_Query( array(
        'post_status' => array( SFAF_Sources::STATUS_PENDING ),
        'fields'      => 'ids',
        'meta_query'  => array( array( 'key' => SFAF_Sources::META_SOURCE, 'value' => 'nope' ) ),
    ) );
    if ( array() === $q->posts ) {
        echo "ok       the query stub filters on meta\n";
    } else {
        echo "BROKEN:  the query stub ignores meta_query, so find_existing() matches anything\n"; $bad++;
    }

    // wp_update_post really moves a status, or section 4 cannot fail.
    reset_world();
    $GLOBALS['posts'][3] = array( 'post_title' => 'c', 'post_status' => SFAF_Sources::STATUS_PENDING, 'post_type' => 'uc_event' );
    wp_update_post( array( 'ID' => 3, 'post_status' => SFAF_Sources::STATUS_DISMISSED ) );
    if ( SFAF_Sources::STATUS_DISMISSED === get_post_status( 3 ) ) {
        echo "ok       the stub really moves a post status\n";
    } else {
        echo "BROKEN:  wp_update_post does nothing, so the sweep assertions are vacuous\n"; $bad++;
    }

    // And run_adapter really creates rows when nothing refuses them, or
    // "no row was created" is true for the wrong reason in section 3.
    reset_world();
    Fake_Adapter::$items = array( ev( array( 'external_id' => 'ok1' ) ) );
    SFAF_Sources::run_adapter( new Fake_Adapter() );
    if ( 1 === count( $GLOBALS['posts'] ) ) {
        echo "ok       an acceptable event really is imported\n";
    } else {
        echo "BROKEN:  nothing is ever imported, so every refusal assertion passes trivially\n"; $bad++;
    }

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the harness can tell a pass from a fail.\n" );
    exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "\nImport gate and queue sweep\n";
echo str_repeat( '=', 72 ) . "\n";
echo "checked: which day an event stops counting, including today and a multi-day event\n";
echo "         running through it; that a dateless and a past item are refused for their own\n";
echo "         reasons and a future-dated donation page is refused for neither; that a\n";
echo "         refusal never reads as a deletion, so no published event is unpublished by\n";
echo "         one; that an already-imported row is never re-judged; and that the sweep\n";
echo "         clears the queues without reaching a published, draft or dateless event\n\n";

if ( empty( $fails ) ) {
    echo "only events are imported, and only the queues are swept.\n";
    exit( 0 );
}
echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) { echo '  - ' . $f . "\n"; }
exit( 1 );
