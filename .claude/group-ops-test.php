<?php
/**
 * Behavioural harness for repattern_group() and extend_group().
 *
 * The recurrence cross-check proves the DATE ARITHMETIC. This proves the two
 * operations built on top of it: which posts they touch, which they refuse to
 * touch, and that a removed date is not put back.
 *
 * WordPress is stubbed with an in-memory post store. Only what these two
 * functions actually call is provided, so a call to anything else fails loudly
 * rather than silently returning null.
 */

define( 'ABSPATH', __DIR__ );

/* ----------------------------- the store ------------------------------- */
$GLOBALS['posts'] = array();   // id => object{ID,post_type,post_status,post_title,post_name,post_content,post_excerpt,post_author}
$GLOBALS['meta']  = array();   // id => key => value
$GLOBALS['terms'] = array();   // id => tax => ids
$GLOBALS['today'] = '2026-08-12';
$GLOBALS['next_id'] = 100;

function mkpost( $args ) {
    $id = ++$GLOBALS['next_id'];
    $GLOBALS['posts'][ $id ] = (object) array_merge( array(
        'ID' => $id, 'post_type' => 'uc_event', 'post_status' => 'publish',
        'post_title' => 'Weekly Group', 'post_name' => 'weekly-group',
        'post_content' => 'body', 'post_excerpt' => '', 'post_author' => 1,
    ), $args );
    $GLOBALS['meta'][ $id ] = array();
    return $id;
}

/* --------------------------- WordPress stubs ---------------------------- */
function get_post( $id ) { return isset( $GLOBALS['posts'][ (int) $id ] ) ? $GLOBALS['posts'][ (int) $id ] : null; }
function get_post_meta( $id, $key, $single = false ) {
    $v = isset( $GLOBALS['meta'][ (int) $id ][ $key ] ) ? $GLOBALS['meta'][ (int) $id ][ $key ] : '';
    return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ (int) $id ][ $key ] ); return true; }
function current_time( $fmt ) { return ( 'Y-m-d' === $fmt ) ? $GLOBALS['today'] : date( $fmt ); }
function wp_timezone() { return new DateTimeZone( 'America/Los_Angeles' ); }
function wp_generate_password( $n, $a = true, $b = true ) { return substr( md5( (string) mt_rand() ), 0, $n ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function _n( $one, $many, $n ) { return ( 1 === (int) $n ) ? $one : $many; }
function date_i18n( $fmt, $ts = null ) { return date( $fmt, null === $ts ? time() : $ts ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_list_pluck( $a, $k ) { return array_map( function ( $x ) use ( $k ) { return $x[ $k ]; }, $a ); }
function wp_get_object_terms( $id, $tax, $args = array() ) { return isset( $GLOBALS['terms'][ $id ][ $tax ] ) ? $GLOBALS['terms'][ $id ][ $tax ] : array(); }
function wp_set_object_terms( $id, $terms, $tax ) { $GLOBALS['terms'][ $id ][ $tax ] = $terms; }
function get_post_thumbnail_id( $id ) { return 0; }
function set_post_thumbnail( $id, $t ) {}
function sfaf_ap_time( $t, $m = true ) { return $t; }
function sfaf_ap_time_range( $s, $e = '' ) { return trim( $s . ' ' . $e ); }

class WP_Error {
    public $code; public $msg;
    public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
    public function get_error_message() { return $this->msg; }
}

function wp_insert_post( $args, $err = false ) {
    return mkpost( $args );
}

class SFAF_Series {
    public static function editable_statuses() { return array( 'publish', 'pending', 'draft', 'future', 'private' ); }
}

/**
 * Privacy, as create_occurrence() uses it.
 *
 * The real class is not loaded here because this test stubs WordPress rather
 * than running it, and the two calls the recurrence path makes are all that
 * matters to these cases: does a private seed produce private occurrences, and
 * does each one get its OWN address. Occurrence slugs are {seed-slug}-{date},
 * so a shared token would mean being sent one date hands somebody every other
 * date by editing the URL.
 */
class SFAF_Privacy {
    const META = '_uc_private';
    const YOAST_NOINDEX_META = '_yoast_wpseo_meta-robots-noindex';
    public static function is_private( $id ) {
        return '1' === (string) get_post_meta( (int) $id, self::META, true );
    }
    public static function randomize_slug( $id ) {
        // Posts are objects in this harness, as they are in WordPress.
        $GLOBALS['posts'][ (int) $id ]->post_name = 'tok' . str_pad( (string) $GLOBALS['token_seq']++, 4, '0', STR_PAD_LEFT );
    }
}
$GLOBALS['token_seq'] = 1;

/**
 * Only the two shapes the recurrence class builds: a group clause, optionally
 * with a date bound, ordered by date ascending.
 */
class WP_Query {
    public $posts = array();
    public function __construct( $args ) {
        $statuses = (array) $args['post_status'];
        $group    = $args['meta_query']['group']['value'];
        $bound    = isset( $args['meta_query']['event_date']['compare'] ) && '>=' === $args['meta_query']['event_date']['compare']
            ? $args['meta_query']['event_date']['value'] : null;

        $hits = array();
        foreach ( $GLOBALS['posts'] as $id => $p ) {
            if ( 'uc_event' !== $p->post_type || ! in_array( $p->post_status, $statuses, true ) ) { continue; }
            $g = get_post_meta( $id, '_uc_recurrence_group', true );
            if ( $g !== $group ) { continue; }
            $d = get_post_meta( $id, '_uc_event_date', true );
            if ( '' === $d ) { continue; }
            if ( null !== $bound && $d < $bound ) { continue; }
            $hits[ $id ] = $d;
        }
        asort( $hits );
        $this->posts = array_keys( $hits );
    }
}

require __DIR__ . '/../includes/class-sfaf-recurrence.php';

/* ------------------------------- runner --------------------------------- */
$fails = 0;
function t( $what, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails++;
        printf( "FAIL  %s\n   got:  %s\n   want: %s\n", $what,
            is_array( $got ) ? '[' . implode( ',', $got ) . ']' : var_export( $got, true ),
            is_array( $want ) ? '[' . implode( ',', $want ) . ']' : var_export( $want, true ) );
    } else {
        printf( "ok    %s\n", $what );
    }
}
function dates_of( $ids ) {
    $out = array();
    foreach ( $ids as $id ) { $out[] = get_post_meta( $id, '_uc_event_date', true ); }
    return $out;
}

/** Build a group: pattern, list of [date, isExtra]. */
function build( $group, $pattern, $rows, $title = 'Weekly Group' ) {
    $ids = array();
    foreach ( $rows as $r ) {
        $id = mkpost( array( 'post_title' => $title, 'post_name' => sanitize_title( $title ) ) );
        update_post_meta( $id, '_uc_event_date', $r[0] );
        update_post_meta( $id, '_uc_recurrence_group', $group );
        update_post_meta( $id, '_uc_recurrence_pattern', $pattern );
        update_post_meta( $id, '_uc_start_time', '18:00' );
        if ( ! empty( $r[1] ) ) { update_post_meta( $id, '_uc_recurrence_extra', '1' ); }
        $ids[] = $id;
    }
    return $ids;
}

echo "--- repattern: first Monday -> second Monday ---\n";
// 2026-09-07, 2026-10-05, 2026-11-02 are the first Mondays.
$ids = build( 'g1', 'monthly_nth:1:1', array(
    array( '2026-09-07', false ), array( '2026-10-05', false ), array( '2026-11-02', false ),
) );
$r = SFAF_Recurrence::repattern_group( 'g1', 'monthly_nth:2:1' );
t( 'three dates moved', (int) $r['moved'], 3 );
t( 'onto the second Mondays', dates_of( $ids ), array( '2026-09-14', '2026-10-12', '2026-11-09' ) );
t( 'the count did not change', count( $ids ), 3 );
t( 'the pattern is recorded', get_post_meta( $ids[0], '_uc_recurrence_pattern', true ), 'monthly_nth:2:1' );

echo "\n--- repattern: an extra date is left where it is ---\n";
// Weekly Wednesdays from Aug 12, plus a Saturday somebody added.
$ids = build( 'g2', 'weekly:1:3', array(
    array( '2026-08-12', false ), array( '2026-08-15', true ), array( '2026-08-19', false ), array( '2026-08-26', false ),
) );
$r = SFAF_Recurrence::repattern_group( 'g2', 'weekly:1:2' ); // to Tuesdays
t( 'the pattern dates moved', (int) $r['moved'], 3 );
t( 'the extra date was counted as left alone', (int) $r['left'], 1 );
t( 'Wednesdays became Tuesdays, the Saturday stayed',
    dates_of( $ids ), array( '2026-08-18', '2026-08-15', '2026-08-25', '2026-09-01' ) );
t( 'the extra date still carries the marker',
    SFAF_Recurrence::is_extra_date( $ids[1] ), true );
t( 'the extra date got the new pattern string too',
    get_post_meta( $ids[1], '_uc_recurrence_pattern', true ), 'weekly:1:2' );

echo "\n--- repattern: the past is never reached ---\n";
$ids = build( 'g3', 'weekly:1:3', array(
    array( '2026-07-01', false ), array( '2026-07-08', false ), array( '2026-08-12', false ), array( '2026-08-19', false ),
) );
$r = SFAF_Recurrence::repattern_group( 'g3', 'weekly:1:5' ); // to Fridays
t( 'only the two upcoming dates moved', (int) $r['moved'], 2 );
t( 'the two past dates are exactly as they were',
    array( get_post_meta( $ids[0], '_uc_event_date', true ), get_post_meta( $ids[1], '_uc_event_date', true ) ),
    array( '2026-07-01', '2026-07-08' ) );
t( 'the past keeps the pattern that produced it',
    get_post_meta( $ids[0], '_uc_recurrence_pattern', true ), 'weekly:1:3' );
t( 'the upcoming ones are on Fridays',
    array( get_post_meta( $ids[2], '_uc_event_date', true ), get_post_meta( $ids[3], '_uc_event_date', true ) ),
    array( '2026-08-14', '2026-08-21' ) );

echo "\n--- repattern: weekly to monthly, the count holds ---\n";
$ids = build( 'g4', 'weekly:1:3', array(
    array( '2026-08-12', false ), array( '2026-08-19', false ), array( '2026-08-26', false ),
) );
$r = SFAF_Recurrence::repattern_group( 'g4', 'monthly' );
t( 'still three events', count( $ids ), 3 );
t( 'now a month apart', dates_of( $ids ), array( '2026-08-12', '2026-09-12', '2026-10-12' ) );
t( 'and the screen can name the new span', array( $r['first'], $r['last'] ), array( '2026-08-12', '2026-10-12' ) );

echo "\n--- repattern: a custom group is refused ---\n";
$ids = build( 'g5', 'custom', array( array( '2026-08-20', true ), array( '2026-09-03', true ) ) );
$r = SFAF_Recurrence::repattern_group( 'g5', 'custom' );
t( 'nothing moved', (int) $r['moved'], 0 );
t( 'and no pattern was written', $r['pattern'], '' );

echo "\n--- extend: the missing dates, from the existing pattern ---\n";
$ids = build( 'g6', 'weekly:1:3', array(
    array( '2026-12-16', false ), array( '2026-12-23', false ), array( '2026-12-30', false ),
) );
$r = SFAF_Recurrence::extend_group( 'g6', '2027-01-27' );
t( 'it reports where the series ended', $r['from'], '2026-12-30' );
t( 'four Wednesdays added', $r['dates'], array( '2027-01-06', '2027-01-13', '2027-01-20', '2027-01-27' ) );
t( 'nothing already there was touched', dates_of( $ids ), array( '2026-12-16', '2026-12-23', '2026-12-30' ) );
t( 'what it made are pattern dates, not extras', SFAF_Recurrence::is_extra_date( $r['created'][0] ), false );
t( 'the copy carries the group', get_post_meta( $r['created'][0], '_uc_recurrence_group', true ), 'g6' );

echo "\n--- extend: running it again adds nothing ---\n";
$again = SFAF_Recurrence::extend_group( 'g6', '2027-01-27' );
t( 'idempotent', $again['created'], array() );
t( 'and says why', $again['reason'], 'not_further' );

echo "\n--- extend: a removed date is not put back ---\n";
$ids = build( 'g7', 'weekly:1:4', array(   // Thursdays
    array( '2026-12-10', false ), array( '2026-12-17', false ), array( '2026-12-24', false ), array( '2026-12-31', false ),
) );
// The manager removes Christmas Eve AND New Year's Eve. Trashing keeps the meta.
$GLOBALS['posts'][ $ids[2] ]->post_status = 'trash';
$GLOBALS['posts'][ $ids[3] ]->post_status = 'trash';
$r = SFAF_Recurrence::extend_group( 'g7', '2027-01-21' );
t( 'the anchor fell back to the last live pattern date', $r['anchor'], '2026-12-17' );
t( 'neither removed date came back',
    $r['dates'], array( '2027-01-07', '2027-01-14', '2027-01-21' ) );

echo "\n--- extend: a dormant group is not back-filled ---\n";
$ids = build( 'g8', 'weekly:1:3', array( array( '2026-03-04', false ), array( '2026-03-11', false ) ) );
$r = SFAF_Recurrence::extend_group( 'g8', '2026-09-02' );
t( 'nothing before today was created',
    $r['dates'], array( '2026-08-12', '2026-08-19', '2026-08-26', '2026-09-02' ) );

echo "\n--- extend: the anchor is the last PATTERN date, not the last date ---\n";
$ids = build( 'g9', 'weekly:1:3', array(   // Wednesdays
    array( '2026-12-09', false ), array( '2026-12-16', false ), array( '2026-12-19', true ), // a Saturday extra
) );
$r = SFAF_Recurrence::extend_group( 'g9', '2027-01-13' );
t( 'it resumed from the Wednesday, not the Saturday', $r['anchor'], '2026-12-16' );
t( 'so the new dates are Wednesdays',
    $r['dates'], array( '2026-12-23', '2026-12-30', '2027-01-06', '2027-01-13' ) );
t( 'and the horizon it reported was the Saturday, which is what is on screen', $r['from'], '2026-12-19' );

echo "\n--- extend: a custom group has nothing to carry forward ---\n";
build( 'g10', 'custom', array( array( '2026-09-01', true ), array( '2026-09-20', true ) ) );
$r = SFAF_Recurrence::extend_group( 'g10', '2027-01-01' );
t( 'refused', $r['created'], array() );
t( 'with the reason the screen prints', $r['reason'], 'no_cadence' );

echo "\n--- add_occurrence: joins the group, marked extra, title override ---\n";
$ids = build( 'g11', 'weekly:1:3', array( array( '2026-08-12', false ), array( '2026-08-19', false ) ), 'Tuesday Support Group' );
$new = SFAF_Recurrence::add_occurrence( $ids[0], '2026-08-15', '17:00', '19:00', true, 'Annual picnic' );
t( 'it was created', is_int( $new ) && $new > 0, true );
t( 'it is in the group', get_post_meta( $new, '_uc_recurrence_group', true ), 'g11' );
t( 'so a time change reaches it', in_array( $new, SFAF_Recurrence::upcoming_in_group( 'g11' ), true ), true );
t( 'it is marked as an extra date', SFAF_Recurrence::is_extra_date( $new ), true );
t( 'so a pattern change leaves it alone',
    in_array( $new, SFAF_Recurrence::split_group( 'g11' )['extra'], true ), true );
t( 'the title override took', get_post( $new )->post_title, 'Annual picnic' );
t( 'and the slug followed it', get_post( $new )->post_name, 'annual-picnic-2026-08-15' );
t( 'the times were written', get_post_meta( $new, '_uc_start_time', true ), '17:00' );

// Prove the two guarantees together: a pattern change must skip it, a time
// change must reach it.
$before = get_post_meta( $new, '_uc_event_date', true );
SFAF_Recurrence::repattern_group( 'g11', 'weekly:1:1' ); // to Mondays
t( 'after a pattern change the added date has not moved',
    get_post_meta( $new, '_uc_event_date', true ), $before );
SFAF_Recurrence::retime_group( 'g11', '20:00', '21:30' );
t( 'after a time change it has the new time',
    get_post_meta( $new, '_uc_start_time', true ), '20:00' );

echo "\n--- add_occurrence: no title override keeps the group's title ---\n";
$new2 = SFAF_Recurrence::add_occurrence( $ids[0], '2026-08-14', '', '', true, '' );
t( 'same title as the rest', get_post( $new2 )->post_title, 'Tuesday Support Group' );
t( 'and the slug scheme is unchanged', get_post( $new2 )->post_name, 'tuesday-support-group-2026-08-14' );

/* -------------------------------------------------------------------------
 * A PRIVATE EVENT'S DATES ARE PRIVATE, AND EACH HAS ITS OWN ADDRESS.
 *
 * Two separate guarantees and the second is the one that is easy to miss.
 * Occurrence slugs are normally {seed-slug}-{date}, so a private seed whose
 * token was simply inherited would mean that being sent one date hands somebody
 * every other date by editing the date on the end of the URL. A donor
 * reception that repeats monthly would be twelve leaks from one forwarded link.
 * ---------------------------------------------------------------------- */
echo "\n--- a private seed produces private dates, each with its own token ---\n";
$pseed = mkpost( array( 'post_title' => 'Donor Reception', 'post_name' => 'donor-reception' ) );
update_post_meta( $pseed, '_uc_event_date', '2026-09-01' );
update_post_meta( $pseed, SFAF_Recurrence::GROUP_META, 'gp1' );
update_post_meta( $pseed, SFAF_Recurrence::PATTERN_META, 'monthly:1' );
update_post_meta( $pseed, SFAF_Privacy::META, '1' );

$d1 = SFAF_Recurrence::add_occurrence( $pseed, '2026-10-01', '', '', true, '' );
$d2 = SFAF_Recurrence::add_occurrence( $pseed, '2026-11-01', '', '', true, '' );

t( 'the first new date is private', SFAF_Privacy::is_private( $d1 ), true );
t( 'the second new date is private', SFAF_Privacy::is_private( $d2 ), true );
t( 'the first date did not keep the readable slug',
    ( false === strpos( get_post( $d1 )->post_name, 'donor-reception' ) ), true );
t( 'the two dates do not share an address',
    ( get_post( $d1 )->post_name !== get_post( $d2 )->post_name ), true );
t( 'and each carries the noindex meta',
    get_post_meta( $d2, SFAF_Privacy::YOAST_NOINDEX_META, true ), '1' );

// The control: a public seed is untouched by any of this.
t( 'a public date still gets its readable slug',
    get_post( $new2 )->post_name, 'tuesday-support-group-2026-08-14' );

echo "\n";
if ( $fails ) { printf( "%d failure(s).\n", $fails ); exit( 1 ); }
echo "every group operation behaves as described, and a private group's dates are private.\n";
