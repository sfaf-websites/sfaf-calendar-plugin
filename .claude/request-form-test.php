<?php
/**
 * THE REQUEST FORM IS A PUBLIC SURFACE, SO EVERYTHING IT ACCEPTS IS CHECKED.
 *
 *     php .claude/request-form-test.php
 *
 * This form takes input from a browser with no calendar account, which makes it
 * the only thing in this plugin where the person submitting is not somebody
 * SFAF has given access to. Every assertion here is written as what a submitter
 * MUST NOT be able to do, because that is the list that matters:
 *
 *   . get a link to an address that is not ours
 *   . open the form without a live token, or with somebody else's
 *   . attach a file that is not an image in the calendar folder
 *   . set a category, series or venue that does not exist
 *   . store markup, or an unbounded string
 *   . submit a date that is not a date, or a time that is not a time
 *   . choose the post status, the author, or anything not on the form
 *   . send mail to arbitrary addresses by asking for links in a loop
 *
 * WHAT IT DOES NOT COVER, said plainly rather than left to be assumed: the
 * screens are not rendered here and the mail is not sent, so this proves what
 * validate() and the access helpers decide, not what the page looks like. The
 * one-pass live check is in HANDOVER.md.
 */

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

$root  = dirname( __DIR__ );
$fails = array();

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Only what validate() and the helpers touch.
 * ------------------------------------------------------------------------ */
$GLOBALS['terms']       = array();
$GLOBALS['posts']       = array();
$GLOBALS['pmeta']       = array();
$GLOBALS['transients']  = array();

function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : date( $type ); }
function get_post_type( $id ) { return isset( $GLOBALS['posts'][ (int) $id ] ) ? $GLOBALS['posts'][ (int) $id ]['type'] : false; }
function get_post_mime_type( $id ) { return isset( $GLOBALS['posts'][ (int) $id ] ) ? $GLOBALS['posts'][ (int) $id ]['mime'] : false; }
function get_post_meta( $id, $k, $single = false ) {
    $key = (int) $id . ':' . $k;
    return array_key_exists( $key, $GLOBALS['pmeta'] ) ? $GLOBALS['pmeta'][ $key ] : '';
}
function get_term( $id, $tax = '' ) {
    $id = (int) $id;
    if ( isset( $GLOBALS['terms'][ $tax ] ) && in_array( $id, $GLOBALS['terms'][ $tax ], true ) ) {
        return (object) array( 'term_id' => $id, 'name' => 'Term ' . $id );
    }
    return null;
}
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { return true; }
function add_query_arg( $args, $value = '', $url = '' ) {
    if ( ! is_array( $args ) ) { $args = array( $args => $value ); } else { $url = $value; }
    $out = $url;
    foreach ( $args as $k => $v ) {
        $out .= ( false === strpos( $out, '?' ) ? '?' : '&' ) . rawurlencode( $k ) . '=' . rawurlencode( $v );
    }
    return $out;
}
function home_url( $p = '', $s = null ) { return 'https://example.org' . $p; }
function is_wp_error( $t ) { return ( $t instanceof WP_Error ); }
class WP_Error {}
function sfaf_ap_date( $d, $f = 'full' ) { return '' === $d ? '' : ( 'day ' . str_replace( '-', '/', $d ) ); }

class SFAF_Reminders {
    public static function new_token() { return bin2hex( random_bytes( 16 ) ); }
}
class SFAF_Series {
    const TAXONOMY = 'uc_series';
    public static function set_for_event( $a, $b ) {}
    public static function all() { return array(); }
}
class SFAF_Venues {
    public static function set_for_event( $a, $b ) {}
    public static function exists( $id ) { return in_array( (int) $id, isset( $GLOBALS['terms']['uc_venue'] ) ? $GLOBALS['terms']['uc_venue'] : array(), true ); }
}
class SFAF_Media_Folder {
    const FOLDER = 'calendar';
    public static function prefix() { return 'calendar/'; }
    public static function holds( $id ) {
        $file = get_post_meta( $id, '_wp_attached_file', true );
        return 0 === strpos( (string) $file, 'calendar/' );
    }
}
class SFAF_Portal {
    public static function link( $p = '' ) { return 'https://example.org/caladmin/' . $p; }
}
/*
 * TEAMS, WITH THE REAL CAP AND THE REAL WRITER'S SHAPE (3.66.0).
 *
 * The staff form offers a team picker, so validate() reads the team list. The
 * cap is NOT restated here: MAX_PER_EVENT is taken from the real class so the
 * form and the portal cannot end up enforcing two different numbers, which is
 * the whole reason the form asks SFAF_Teams rather than carrying a literal.
 *
 * set_access_for_event() records what it was handed, so the assertions below
 * can ask what actually reached the one writer rather than reading the meta and
 * hoping the right path wrote it.
 */
$GLOBALS['team_writes'] = array();
class SFAF_Teams {
    const MAX_PER_EVENT = 2;
    public static function all() {
        return array(
            'marcom'  => array( 'id' => 'marcom',  'name' => 'MarCom',        'users' => array( 1 ) ),
            'clinic'  => array( 'id' => 'clinic',  'name' => 'Clinic',        'users' => array( 2 ) ),
            'harmred' => array( 'id' => 'harmred', 'name' => 'Harm Reduction', 'users' => array( 3 ) ),
        );
    }
    public static function set_access_for_event( $event_id, $ids ) {
        $GLOBALS['team_writes'][] = array( 'event' => (int) $event_id, 'ids' => array_values( (array) $ids ) );
    }
}

/*
 * wp_kses() IS NOT RE-IMPLEMENTED HERE, AND THAT IS DELIBERATE.
 *
 * A hand-written stripper in a test file would be a second, worse kses, and
 * every assertion about it would be an assertion about the stub. What this
 * plugin actually controls is the ALLOW-LIST it hands to kses, so the stub
 * records the arguments and the assertions are made against those. What kses
 * does with a correct list is WordPress's business and is tested there.
 *
 * It still strips the obvious cases, so a caller that passes nothing sensible
 * does not silently look correct: anything not in the list given is removed.
 */
$GLOBALS['kses_calls'] = array();
function wp_kses( $string, $allowed, $protocols = array() ) {
    $GLOBALS['kses_calls'][] = array( 'allowed' => $allowed, 'protocols' => $protocols );
    $keep = implode( '', array_map( function ( $t ) { return '<' . $t . '>'; }, array_keys( (array) $allowed ) ) );
    return strip_tags( (string) $string, $keep );
}
function wp_kses_post( $string ) { return (string) $string; }
function esc_url_raw( $url, $protocols = null ) {
    $url = trim( (string) $url );
    if ( '' === $url ) { return ''; }
    $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
    if ( is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ) { return ''; }
    return $url;
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_strip_all_tags( $t, $break = false ) { return trim( strip_tags( (string) $t ) ); }

class SFAF_Uploads {
    const MAX_BYTES = 10485760;
}
/*
 * THE REAL SFAF_Submit AND SFAF_FAQ_Sets, not stubs.
 *
 * SFAF_Request::validate() sends the requester's own rows through
 * SFAF_Submit::clean_faqs(), and faqs_for() reads a set through
 * SFAF_FAQ_Sets. Both are exactly what the FAQ assertions below are about, so
 * a stub of either would be a test of this file rather than of the plugin.
 */
class SFAF_Rich_Text {
    public static function sanitize( $v ) { return (string) $v; }
    public static function to_plain( $v ) { return trim( strip_tags( (string) $v ) ); }
    public static function enqueue() {}
    public static function render( $id, $name, $content, $args = array() ) {}
    public static function deferred( $name, $content, $args = array() ) {}
}
class SFAF_Turnstile {
    public static function field() {}
    public static function verify() { return true; }
}
/* The set store is an option. Real reads and writes, so "copy, not
 * reference" is decided by the code rather than by a stub that hands back a
 * fresh array every time. */
$GLOBALS['options'] = array();
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $n, $d = false ) { return isset( $GLOBALS['options'][ $n ] ) ? $GLOBALS['options'][ $n ] : $d; }
    function update_option( $n, $v, $a = null ) { $GLOBALS['options'][ $n ] = $v; return true; }
    function delete_option( $n ) { unset( $GLOBALS['options'][ $n ] ); return true; }
}

require_once $root . '/includes/class-sfaf-faq-sets.php';
require_once $root . '/includes/class-sfaf-submissions.php';
require_once $root . '/includes/class-sfaf-submit.php';
require_once $root . '/includes/class-sfaf-request.php';

function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}
function expect_error( $label, $errors, $field ) {
    global $fails;
    if ( ! isset( $errors[ $field ] ) ) {
        $fails[] = "$label: expected an error on '$field' and there was none";
    }
}
function expect_no_error( $label, $errors, $field ) {
    global $fails;
    if ( isset( $errors[ $field ] ) ) {
        $fails[] = "$label: unexpected error on '$field': " . $errors[ $field ];
    }
}

/* The world: two categories, one series, one venue, three attachments. */
$GLOBALS['terms'] = array(
    'uc_event_category' => array( 11, 12 ),
    'uc_series'         => array( 21 ),
    'uc_venue'          => array( 31 ),
);
$GLOBALS['posts'] = array(
    41 => array( 'type' => 'attachment', 'mime' => 'image/jpeg' ),  // in the folder
    42 => array( 'type' => 'attachment', 'mime' => 'image/jpeg' ),  // outside it
    43 => array( 'type' => 'attachment', 'mime' => 'application/pdf' ), // in the folder, not an image
    44 => array( 'type' => 'uc_event',   'mime' => '' ),            // not an attachment at all
);
$GLOBALS['pmeta']['41:_wp_attached_file'] = 'calendar/latino.jpg';
$GLOBALS['pmeta']['42:_wp_attached_file'] = '2026/08/board-photo.jpg';
$GLOBALS['pmeta']['43:_wp_attached_file'] = 'calendar/programme.pdf';

$tomorrow = date( 'Y-m-d', strtotime( '+1 day' ) );
$next_week = date( 'Y-m-d', strtotime( '+8 days' ) );

function good_post( $over = array() ) {
    global $tomorrow;
    return array_merge( array(
        'requester_name' => 'Tester Reed',
        'title'          => 'Board Social',
        'description'    => 'An evening for the board and volunteers.',
        'date'           => $tomorrow,
        'start_time'     => '18:00',
        'end_time'       => '19:30',
        'repeat'         => 'none',
    ), $over );
}

/* ---------------------------------------------------------------------------
 * 1. WHO MAY ASK FOR A LINK.
 * ------------------------------------------------------------------------ */
foreach ( array(
    'tester@sfaf.org'            => true,
    'TESTER@SFAF.ORG'            => true,
    'tester@mail.sfaf.org'       => true,
    'tester@notsfaf.org'         => false,
    'tester@sfaf.org.example.com'=> false,
    'tester@sfaf.com'            => false,
    'tester@example.org'         => false,
    'not-an-address'           => false,
    ''                         => false,
    'tester@sfaf.org '           => true,
) as $address => $want ) {
    expect( "is_staff_address(" . var_export( $address, true ) . ")", SFAF_Request::is_staff_address( $address ), $want );
}

/* ---------------------------------------------------------------------------
 * 2. TOKENS. Shape, resolution, and what an unknown one does.
 * ------------------------------------------------------------------------ */
$ref = new ReflectionMethod( 'SFAF_Request', 'issue_token' );
$ref->setAccessible( true );
$token = $ref->invoke( null, 'tester@sfaf.org' );

expect( 'a token is 32 hex characters', (bool) preg_match( '/^[a-f0-9]{32}$/', $token ), true );
expect( 'a live token resolves to its address', SFAF_Request::resolve_token( $token ), 'tester@sfaf.org' );
expect( 'an unknown token resolves to nothing', SFAF_Request::resolve_token( str_repeat( 'a', 32 ) ), '' );
expect( 'a short token is refused',             SFAF_Request::resolve_token( 'abc' ), '' );
expect( 'a non-hex token is refused',           SFAF_Request::resolve_token( str_repeat( 'z', 32 ) ), '' );
expect( 'an empty token is refused',            SFAF_Request::resolve_token( '' ), '' );

/* THE TOKEN IS NOT THE KEY. Whatever is written to the store, the usable
 * credential must not be findable in it. */
foreach ( array_keys( $GLOBALS['transients'] ) as $key ) {
    if ( false !== strpos( $key, $token ) ) {
        $fails[] = 'the token itself is part of a stored key, so the store holds a working credential';
    }
}
/* And the stored value is the address, which is what the form needs. */
expect( 'the store holds the address', in_array( 'tester@sfaf.org', array_values( $GLOBALS['transients'] ), true ), true );

/* A token whose stored address stops being a staff one is dead. Somebody's
 * address is the only thing making a token usable. */
$key = 'sfaf_evreq_tok_' . hash( 'sha256', $token );
$GLOBALS['transients'][ $key ] = 'someone@example.org';
expect( 'a token holding a non-staff address is dead', SFAF_Request::resolve_token( $token ), '' );
$GLOBALS['transients'][ $key ] = 'tester@sfaf.org';

/* ---------------------------------------------------------------------------
 * 3. THE FIELDS. What a good submission keeps.
 * ------------------------------------------------------------------------ */
$out = SFAF_Request::validate( good_post( array(
    'categories'  => array( 11, 12 ),
    'series'      => 21,
    'venue'       => 31,
    'image_id'    => 41,
    'rsvp'        => '1',
    'capacity'    => '40',
    'notes'       => 'Parking is tight.',
) ) );
expect( 'a good request has no errors', $out['errors'], array() );
expect( 'both categories kept',   $out['clean']['categories'], array( 11, 12 ) );
expect( 'the series kept',        $out['clean']['series'], 21 );
expect( 'the venue kept',         $out['clean']['venue'], 31 );
expect( 'the folder image kept',  $out['clean']['image'], 41 );
expect( 'registration kept',      $out['clean']['rsvp'], true );
expect( 'the capacity kept',      $out['clean']['capacity'], 40 );

/* ---------------------------------------------------------------------------
 * 4. WHAT IS REFUSED. One assertion per thing a submitter could send.
 * ------------------------------------------------------------------------ */

/* Required fields. */
foreach ( array( 'requester_name', 'title', 'description' ) as $field ) {
    $out = SFAF_Request::validate( good_post( array( $field => '' ) ) );
    expect_error( "an empty $field is refused", $out['errors'], $field );
}
$out = SFAF_Request::validate( good_post( array( 'requester_name' => '   ' ) ) );
expect_error( 'whitespace is not a name', $out['errors'], 'requester_name' );

/* Ids that do not resolve. Dropped rather than stored, and no error: a stale
 * option is not the submitter's fault and must not block the request. */
$out = SFAF_Request::validate( good_post( array( 'categories' => array( 11, 999, 'abc' ) ) ) );
expect( 'an unknown category is dropped', $out['clean']['categories'], array( 11 ) );

$out = SFAF_Request::validate( good_post( array( 'series' => 999 ) ) );
expect( 'an unknown series is dropped', $out['clean']['series'], 0 );

$out = SFAF_Request::validate( good_post( array( 'venue' => 999 ) ) );
expect( 'an unknown venue is dropped', $out['clean']['venue'], 0 );

/* THE IMAGE. Three separate ways to be refused. */
$out = SFAF_Request::validate( good_post( array( 'image_id' => 42 ) ) );
expect( 'an image outside the calendar folder is refused', $out['clean']['image'], 0 );
$out = SFAF_Request::validate( good_post( array( 'image_id' => 43 ) ) );
expect( 'a PDF in the folder is refused',                  $out['clean']['image'], 0 );
$out = SFAF_Request::validate( good_post( array( 'image_id' => 44 ) ) );
expect( 'a post that is not an attachment is refused',     $out['clean']['image'], 0 );
$out = SFAF_Request::validate( good_post( array( 'image_id' => 99999 ) ) );
expect( 'an id that is nothing at all is refused',         $out['clean']['image'], 0 );

/* ---------------------------------------------------------------------------
 * THE TEAM PICKER (3.66.0).
 *
 * A requester names the team that should be able to work on the event. It is
 * still the public surface this whole file is about, so the questions are the
 * same ones: what can somebody who edits the form get out of it that they were
 * not offered?
 * ------------------------------------------------------------------------ */

$out = SFAF_Request::validate( good_post( array( 'request_teams' => array( 'clinic' ) ) ) );
expect( 'a real team is kept',       $out['clean']['teams'], array( 'clinic' ) );
expect_no_error( 'and raises nothing', $out['errors'], 'request_teams' );

$out = SFAF_Request::validate( good_post( array( 'request_teams' => array( 'clinic', 'marcom' ) ) ) );
expect( 'two teams are kept, which is the cap', $out['clean']['teams'], array( 'clinic', 'marcom' ) );

/* Over the cap is an ERROR, not a silent trim. With scripting off nothing stops
   a third box being ticked, and dropping one quietly would leave the requester
   believing they had said something they had not. */
$out = SFAF_Request::validate( good_post( array( 'request_teams' => array( 'clinic', 'marcom', 'harmred' ) ) ) );
expect_error( 'three teams is refused out loud', $out['errors'], 'request_teams' );

$out = SFAF_Request::validate( good_post( array( 'request_teams' => array( 'clinic', 'clinic' ) ) ) );
expect( 'the same team twice is one team', $out['clean']['teams'], array( 'clinic' ) );

/*
 * WHAT THE GUARANTEE ACTUALLY IS, and writing it the other way round found the
 * mistake. The first draft of this asserted that malformed ids are REFUSED, and
 * three of them were not: sanitize_key() lowercases and strips, so 'MARCOM' and
 * '../marcom' both arrive as 'marcom'. That is not a hole and refusing them
 * would not close one, because a normalized id still has to name a team that
 * EXISTS before it is kept. Whatever a hand-edited form sends, the result can
 * only ever be real team ids.
 *
 * So the assertion is that property, over hostile input, rather than a guess
 * about which strings survive normalization.
 */
$known_team_ids = array_keys( SFAF_Teams::all() );
$hostile = array(
    array( 'no-such-team' ),
    array( '' ),
    array( '0' ),
    array( 'MARCOM' ),
    array( '../marcom' ),
    array( 'clinic;drop' ),
    array( 'nope', 'harmred' ),
    array( 'clinic', 'clinic', 'clinic', 'marcom', 'harmred' ),
    'clinic',                       // not an array at all
    array( array( 'clinic' ) ),     // nested, which sanitize_key sees as ''
    123,
    null,
);
foreach ( $hostile as $i => $sent ) {
    $res = SFAF_Request::validate( good_post( array( 'request_teams' => $sent ) ) );
    $got = $res['clean']['teams'];

    expect( "case $i names only teams that exist",
        array_values( array_diff( $got, $known_team_ids ) ), array() );
    expect( "case $i has no duplicates", count( $got ), count( array_unique( $got ) ) );

    /*
     * OVER THE CAP IS ALLOWED IN $clean AND ONLY THERE. The ticks are kept so
     * the form comes back showing what the person actually chose, with an error
     * telling them to drop one; store() is never reached while there is an
     * error, and set_access_for_event() caps again on the way in. So the
     * contract is "within the cap, OR refused by name", and asserting only the
     * first was what this test got wrong first time.
     */
    expect( "case $i is within the cap or refused by name",
        count( $got ) <= SFAF_Teams::MAX_PER_EVENT || isset( $res['errors']['request_teams'] ),
        true );
}

/* An invented id alongside a real one takes the real one and nothing else,
   rather than failing the whole field. */
$out = SFAF_Request::validate( good_post( array( 'request_teams' => array( 'nope', 'harmred' ) ) ) );
expect( 'a real team survives an invented one beside it', $out['clean']['teams'], array( 'harmred' ) );

/* An id naming no team is dropped rather than kept as a string. */
$out = SFAF_Request::validate( good_post( array( 'request_teams' => array( 'no-such-team' ) ) ) );
expect( 'an id naming no team is dropped', $out['clean']['teams'], array() );

/*
 * ONE WRITER, AND THIS IS AN ASSERTION ABOUT ABSENCE.
 *
 * store() is not called here for the reason given in section 10 below, and the
 * claim being made is not about a value anyway: it is that this file writes the
 * access list through SFAF_Teams and NOWHERE ELSE. A second path writing
 * _uc_event_teams directly would be a second definition of what a valid access
 * list is, free to drift from the cap and the existence check the portal
 * enforces. Only a search for what is not there can answer that.
 */
$request_src = file_get_contents( $root . '/includes/class-sfaf-request.php' );
/* COMMENTS STRIPPED FIRST. The note beside the call names the meta key in order
   to say it is not written here, and a search over the raw file finds that
   sentence and reports the opposite of the truth. */
$request_code = preg_replace( '#/\*.*?\*/#s', '', $request_src );
expect( 'the access list is written through SFAF_Teams',
    (bool) strpos( $request_code, 'SFAF_Teams::set_access_for_event( $event_id, $c[\'teams\'] )' ), true );
expect( 'and the meta key is never touched directly',
    false !== strpos( $request_code, '_uc_event_teams' ), false );
expect( 'the cap is asked of SFAF_Teams rather than written out',
    substr_count( $request_src, 'SFAF_Teams::MAX_PER_EVENT' ) >= 2, true );

$out = SFAF_Request::validate( good_post() );
expect( 'naming no team is allowed and is the default', $out['clean']['teams'], array() );
expect_no_error( 'and is not an error', $out['errors'], 'request_teams' );

/* Dates. */
foreach ( array( '', 'tomorrow', '2026-13-01', '2026-02-30', '26-01-01', '2026-1-1', '2026-01-01T10:00' ) as $bad ) {
    $out = SFAF_Request::validate( good_post( array( 'date' => $bad ) ) );
    expect_error( 'a date of ' . var_export( $bad, true ) . ' is refused', $out['errors'], 'date' );
}
$out = SFAF_Request::validate( good_post( array( 'date' => '2020-01-01' ) ) );
expect_error( 'a date in the past is refused', $out['errors'], 'date' );

/* Times. */
foreach ( array( '', '25:00', '10:70', 'noon', '6pm' ) as $bad ) {
    $out = SFAF_Request::validate( good_post( array( 'start_time' => $bad ) ) );
    expect_error( 'a start time of ' . var_export( $bad, true ) . ' is refused', $out['errors'], 'start_time' );
}
$out = SFAF_Request::validate( good_post( array( 'start_time' => '18:00:00' ) ) );
expect_no_error( 'seconds on a time are accepted', $out['errors'], 'start_time' );
expect( 'and trimmed to H:i', $out['clean']['start'], '18:00' );

$out = SFAF_Request::validate( good_post( array( 'start_time' => '19:00', 'end_time' => '18:00' ) ) );
expect_error( 'an end before the start is refused', $out['errors'], 'end_time' );
$out = SFAF_Request::validate( good_post( array( 'start_time' => '18:00', 'end_time' => '18:00' ) ) );
expect_error( 'an end equal to the start is refused', $out['errors'], 'end_time' );

/* Repeating. */
$out = SFAF_Request::validate( good_post( array( 'repeat' => 'every-tuesday-ish' ) ) );
expect( 'an unknown repeat falls back to none', $out['clean']['repeat'], 'none' );
$out = SFAF_Request::validate( good_post( array( 'repeat' => 'weekly', 'repeat_until' => '2020-01-01' ) ) );
expect_error( 'an end date before the first date is refused', $out['errors'], 'repeat_until' );
$out = SFAF_Request::validate( good_post( array( 'repeat' => 'none', 'repeat_until' => $next_week ) ) );
expect( 'an until date on a one-off is dropped', $out['clean']['repeat_until'], '' );

/* Capacity. */
foreach ( array( '-1', '999999' ) as $bad ) {
    $out = SFAF_Request::validate( good_post( array( 'rsvp' => '1', 'capacity' => $bad ) ) );
    expect_error( "a capacity of $bad is refused", $out['errors'], 'capacity' );
}
$out = SFAF_Request::validate( good_post( array( 'rsvp' => '1', 'capacity' => '' ) ) );
expect_no_error( 'a blank capacity means no limit', $out['errors'], 'capacity' );
expect( 'and stores zero', $out['clean']['capacity'], 0 );
$out = SFAF_Request::validate( good_post( array( 'capacity' => '40' ) ) );
expect( 'a capacity with no registration is ignored', $out['clean']['capacity'], 0 );

/* Markup and length.
 *
 * THE DESCRIPTION IS PROSE FROM 3.46.0 AND THE TITLE IS STILL A LINE. Mark
 * reversed the 3.43.0 decision for the description alone, so the assertion
 * changed shape rather than being dropped: the title must still carry no
 * markup at all, and the description must carry ONLY what the toolbar can
 * produce. What that list is, and that it excludes img and script, is proved
 * in section 9 against the arguments actually handed to wp_kses().
 */
$out = SFAF_Request::validate( good_post( array(
    'title'       => 'Board <script>alert(1)</script> Social',
    'description' => '<p>Come <strong>along</strong></p><script>alert(1)</script>',
) ) );
if ( false !== strpos( $out['clean']['title'], '<' ) ) {
    $fails[] = 'markup survives in the title, which is a line and never prose';
}
if ( false !== stripos( $out['clean']['title'], 'script' ) && false !== strpos( $out['clean']['title'], '<' ) ) {
    $fails[] = 'a script tag survives in the title';
}
if ( false !== stripos( $out['clean']['description'], '<script' ) ) {
    $fails[] = 'a script tag survives in the description';
}
if ( false === strpos( $out['clean']['description'], '<strong>' ) ) {
    $fails[] = 'the description lost its formatting, so the rich text control stores nothing it produces';
}

$out = SFAF_Request::validate( good_post( array(
    'title'       => str_repeat( 'a', 5000 ),
    'description' => str_repeat( 'b', 20000 ),
    'notes'       => str_repeat( 'c', 20000 ),
) ) );
expect( "the title is capped",       strlen( $out["clean"]["title"] ), 200 );
expect( 'the description is capped', strlen( $out['clean']['description'] ), 8000 );
expect( 'the notes are capped',      strlen( $out['clean']['notes'] ), 2000 );

/* Arrays where a string belongs, which is the shape that reaches a type error. */
$out = SFAF_Request::validate( good_post( array( 'title' => array( 'a', 'b' ) ) ) );
expect_error( 'an array in a text field is refused', $out['errors'], 'title' );

/* Nothing at all. */
$out = SFAF_Request::validate( array() );
foreach ( array( 'requester_name', 'title', 'description', 'date', 'start_time', 'end_time' ) as $field ) {
    expect_error( "an empty submission is refused on $field", $out['errors'], $field );
}

/* ---------------------------------------------------------------------------
 * 5. NOTHING NOT ON THE FORM IS READ.
 *
 * The status, the author and the visibility are decisions for whoever approves
 * this, so a submitter naming one must change nothing.
 * ------------------------------------------------------------------------ */
$out = SFAF_Request::validate( good_post( array(
    'post_status'  => 'publish',
    'post_author'  => 1,
    'uc_private'   => '1',
    'notify_choice'=> 'send',
    'featured_image_id' => 42,
) ) );
foreach ( array( 'post_status', 'post_author', 'uc_private', 'notify_choice', 'featured_image_id' ) as $key ) {
    if ( array_key_exists( $key, $out['clean'] ) ) {
        $fails[] = "validate() carries '$key' through, and nothing off the form should survive it";
    }
}

/* And the source itself must name the status rather than take one. */
/*
 * COMMENTS OUT WITH THE TOKENIZER, NOT WITH A REGEX.
 *
 * Every check below is "this file does not contain X", and this file's comments
 * explain at length why it does not use wp.media, does not add a REST route and
 * does not touch registrations. A sweep matching its own explanatory prose has
 * happened five times on this project. A regex for block comments also misses
 * the // ones, and token_get_all() knows the difference between a comment and a
 * string that looks like one.
 */
$src  = file_get_contents( $root . '/includes/class-sfaf-request.php' );
$code = '';
foreach ( token_get_all( $src ) as $t ) {
    if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
        continue;
    }
    $code .= is_array( $t ) ? $t[1] : $t;
}

/* The tokenizer must actually be removing something, or every "is absent"
 * assertion below passes for the wrong reason. */
if ( strlen( $code ) >= strlen( $src ) ) {
    $fails[] = 'stripping comments removed nothing, so the checks below prove nothing';
}
foreach ( array( 'wp.media', 'register_rest_route', 'add_rewrite_rule' ) as $mentioned ) {
    if ( false === stripos( $src, $mentioned ) ) {
        continue;
    }
    if ( false !== stripos( $code, $mentioned ) ) {
        $fails[] = "$mentioned survives outside the comments, so the form does the thing it says it does not";
    }
}
if ( ! preg_match( "#'post_status'\s*=>\s*'pending'#", $code ) ) {
    $fails[] = 'the insert does not name pending as the status, so the queue may never see a request';
}
foreach ( array( "_POST['post_status']", '$_POST["post_status"]', "_REQUEST['post_status']" ) as $bad ) {
    if ( false !== strpos( $code, $bad ) ) {
        $fails[] = "the status is read from the request ($bad)";
    }
}

/* ---------------------------------------------------------------------------
 * 6. RATE LIMITING EXISTS ON ALL FOUR THINGS.
 *
 * Behaviour first: the counter must refuse once the limit is reached.
 * ------------------------------------------------------------------------ */
$allow = new ReflectionMethod( 'SFAF_Request', 'allow' );
$allow->setAccessible( true );
$ok = 0;
for ( $i = 0; $i < 10; $i++ ) {
    if ( $allow->invoke( null, 'test', 'someone', 3, 60 ) ) { $ok++; }
}
expect( 'a limit of three allows exactly three', $ok, 3 );

/* Two subjects do not share a counter. */
expect( 'a different subject is unaffected', $allow->invoke( null, 'test', 'somebody-else', 3, 60 ), true );
/* Two buckets do not share a counter either. */
expect( 'a different bucket is unaffected', $allow->invoke( null, 'other', 'someone', 3, 60 ), true );

foreach ( array( 'link_addr', 'link_ip', 'submit_tok', 'submit_ip' ) as $bucket ) {
    if ( false === strpos( $code, "'" . $bucket . "'" ) ) {
        $fails[] = "the $bucket rate limit is not applied anywhere";
    }
}

/* ---------------------------------------------------------------------------
 * 7. IT ADDS NO REST ROUTE, and says nothing about the calendar it need not.
 * ------------------------------------------------------------------------ */
if ( false !== strpos( $code, 'register_rest_route' ) ) {
    $fails[] = 'the request form registers a REST route; is_embed_request() matches exactly and this would fail CORS';
}
/*
 * IT MUST NOT READ THE REGISTRATION TABLE. Writing _uc_rsvp_enabled onto the
 * event it just created is the form doing its job; touching $wpdb, or the
 * registrations, would be it reading who has signed up for anything.
 */
if ( false !== strpos( $code, 'wpdb' ) ) {
    $fails[] = 'the request form touches $wpdb directly, which is more of the calendar than it needs';
}
if ( preg_match( '#SFAF_RSVP|uc_rsvps|registrations#i', $code ) ) {
    $fails[] = 'the request form reaches into registrations, which a submitter must never see';
}

/*
 * AND IT MUST NOT LIST EVENTS. The only get_posts() here is the picture grid,
 * which asks for attachments. Anything selecting uc_event would be a public
 * page enumerating the calendar, including drafts and private events.
 */
if ( preg_match( '#query_events\s*\(#', $code ) ) {
    $fails[] = 'the request form calls query_events(), which would expose the calendar to a submitter';
}
if ( preg_match_all( "#'post_type'\s*=>\s*'([a-z_]+)'#", $code, $m ) ) {
    foreach ( $m[1] as $i => $type ) {
        /* uc_event appears once, in the INSERT. Anywhere it is being selected
         * rather than created is a listing. */
        if ( 'uc_event' === $type && false === strpos( $code, "'post_status'  => 'pending'" ) ) {
            $fails[] = 'uc_event is named outside the insert, so something here may be listing events';
        }
        if ( ! in_array( $type, array( 'uc_event', 'attachment' ), true ) ) {
            $fails[] = "the request form queries post type '$type', which it has no reason to read";
        }
    }
}

/* ---------------------------------------------------------------------------
 * 8. THE QUEUE CAN TELL A REQUEST FROM AN IMPORT.
 * ------------------------------------------------------------------------ */
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
foreach ( array( 'SFAF_Request::META_EMAIL', 'SFAF_Submissions::kind', 'render_request_panel' ) as $needle ) {
    if ( false === strpos( $portal, $needle ) ) {
        $fails[] = "the portal does not use $needle, so a staff request is indistinguishable in the queue";
    }
}

/*
 * AND THE PANEL SHOWS THE CONTACT THAT WAS ACTUALLY SUBMITTED (3.67.0).
 *
 * IT READ A KEY NOTHING HAS WRITTEN SINCE 3.47.0. SFAF_Submit::META_CONTACT is
 * the single open box that release replaced with three fields, so "Contact for
 * the listing" rendered empty on every community submission for nineteen
 * releases and the name, email and phone the submitter typed were invisible to
 * whoever approved it. Nothing was lost; nothing was shown.
 *
 * THE FIX IS TO ASK THE READER THE EVENT PAGE ASKS, sfaf_event_public_contact(),
 * which prefers the three fields and falls back to the old box. Asserted as
 * "this method calls that function and names no meta key of its own", because
 * a panel assembling the three keys again would be a second answer free to
 * disagree with the page.
 *
 * THE SLICE IS BOUNDED BY THE NEXT METHOD, not by counting braces: this method
 * is mixed PHP and HTML, and a brace counter over that is a brace counter over
 * whatever is in the markup.
 */
/* COMMENTS OUT FIRST, with the tokenizer, for the reason given at section 6:
 * every assertion below is "this method does not contain X", and the method's
 * own comment explains at length which key it used to read. */
$portal_code = '';
foreach ( token_get_all( $portal ) as $t ) {
    if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
        continue;
    }
    $portal_code .= is_array( $t ) ? $t[1] : $t;
}
if ( strlen( $portal_code ) >= strlen( $portal ) ) {
    $fails[] = 'stripping comments from the portal removed nothing, so the panel checks prove nothing';
}

$panel_at = strpos( $portal_code, 'function render_request_panel(' );
if ( false === $panel_at ) {
    $fails[] = 'render_request_panel() is gone; the pending row shows nothing about who submitted an event';
} else {
    $panel_end = strpos( $portal_code, ' function ', $panel_at + 30 );
    $panel     = substr( $portal_code, $panel_at, ( false === $panel_end ? strlen( $portal_code ) : $panel_end ) - $panel_at );

    if ( false === strpos( $panel, 'sfaf_event_public_contact(' ) ) {
        $fails[] = 'the pending panel does not ask sfaf_event_public_contact(), so it can disagree with the event page about what is public';
    }
    if ( false !== strpos( $panel, 'SFAF_Submit::META_CONTACT' ) ) {
        $fails[] = 'the pending panel still reads SFAF_Submit::META_CONTACT, which no public form has written since 3.47.0';
    }
    foreach ( array( 'META_CONTACT_NAME', 'META_CONTACT_EMAIL', 'META_CONTACT_PHONE' ) as $part ) {
        if ( false !== strpos( $panel, $part ) ) {
            $fails[] = "the pending panel assembles $part itself rather than asking the one reader";
        }
    }
}

/*
 * AND IT CAN TELL THE TWO KINDS OF SUBMISSION APART.
 *
 * Both forms write an address to the same meta key, so "has an email" stopped
 * being an answer in 3.46.0. The row asks kind() and badges what it says, and
 * the two labels must differ or the marker is only in the class attribute.
 */
if ( false === strpos( $portal, 'SFAF_Submissions::kind_label' ) ) {
    $fails[] = 'the queue does not ask kind_label(), so both kinds of submission carry the same words';
}
if ( SFAF_Submissions::kind_label( SFAF_Submissions::KIND_STAFF )
    === SFAF_Submissions::kind_label( SFAF_Submissions::KIND_COMMUNITY ) ) {
    $fails[] = 'a staff request and a community submission are badged with the same words';
}
if ( '' !== SFAF_Submissions::kind_label( 'import' ) ) {
    $fails[] = 'an import is badged by this list as well as by the import queue, so it would carry two';
}

/* ---------------------------------------------------------------------------
 * 9. WHAT SUBMITTED PROSE IS ACTUALLY ALLOWED TO CARRY.
 *
 * ASSERTED AGAINST THE ARGUMENTS HANDED TO wp_kses(), not against a stripper
 * written in this file. The plugin controls the allow-list; kses's behaviour
 * given a correct list is WordPress's own and is tested there. A stub that
 * re-implemented it would only ever prove the stub.
 *
 * THE LIST IS NOT wp_kses_post(). That is the rule for a logged-in author, and
 * SFAF_Rich_Text::sanitize() keeps using it for the event editor. Anonymous
 * prose is narrower, and this is where that stays true.
 * ------------------------------------------------------------------------ */
$GLOBALS['kses_calls'] = array();
SFAF_Submissions::prose( '<p>hi</p>' );
if ( empty( $GLOBALS['kses_calls'] ) ) {
    $fails[] = 'prose() does not go through wp_kses() at all, so nothing is filtered';
} else {
    $call    = $GLOBALS['kses_calls'][0];
    $allowed = array_keys( (array) $call['allowed'] );

    /* Everything the toolbar can produce has to survive, or the control makes
     * formatting the visitor then loses on save. */
    foreach ( array( 'p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'h3', 'a' ) as $tag ) {
        if ( ! in_array( $tag, $allowed, true ) ) {
            $fails[] = "submitted prose refuses <$tag>, which the toolbar can produce";
        }
    }

    /* And nothing that reaches outside the box the prose is drawn in. */
    foreach ( array( 'script', 'iframe', 'img', 'style', 'object', 'embed', 'form', 'input', 'video', 'audio', 'svg', 'link', 'meta' ) as $tag ) {
        if ( in_array( $tag, $allowed, true ) ) {
            $fails[] = "submitted prose allows <$tag>, which an anonymous form must not accept";
        }
    }

    /* No attribute beyond the link's own two. class, id and style are how
     * prose escapes its container; on* is script by another name. */
    foreach ( (array) $call['allowed'] as $tag => $attrs ) {
        foreach ( array_keys( (array) $attrs ) as $attr ) {
            if ( 'a' === $tag && in_array( $attr, array( 'href', 'title' ), true ) ) {
                continue;
            }
            $fails[] = "submitted prose allows the $attr attribute on <$tag>";
        }
    }

    $protocols = (array) $call['protocols'];
    if ( empty( $protocols ) ) {
        $fails[] = 'submitted prose passes no protocol list, so kses falls back to every scheme it knows';
    }
    foreach ( $protocols as $scheme ) {
        if ( ! in_array( $scheme, array( 'http', 'https', 'mailto' ), true ) ) {
            $fails[] = "submitted prose permits the $scheme: scheme in a link";
        }
    }
}

/* The parts that ARE ours, checked by running them. */
expect( 'a run of empty paragraphs is collapsed', SFAF_Submissions::prose( '<p></p><p>  </p><p>real</p>' ), '<p>real</p>' );
expect( 'a wall of breaks is cut back',           SFAF_Submissions::prose( 'a<br /><br /><br /><br />b' ), 'a<br /><br />b' );
expect( 'a non-string is not prose',              SFAF_Submissions::prose( array( 'x' ) ), '' );

expect( 'a line collapses its whitespace', SFAF_Submissions::line( "two    spaces\n\nand a break", 200 ), 'two spaces and a break' );
expect( 'a line is capped',                strlen( SFAF_Submissions::line( str_repeat( 'z', 500 ), 120 ) ), 120 );
expect( 'a line carries no markup',        SFAF_Submissions::line( '<b>x</b>', 100 ), 'x' );

expect( 'an https link survives',   SFAF_Submissions::url( 'https://example.org/x' ), 'https://example.org/x' );
expect( 'an http link survives',    SFAF_Submissions::url( 'http://example.org/x' ),  'http://example.org/x' );
expect( 'a javascript: link does not', SFAF_Submissions::url( 'javascript:alert(1)' ), '' );
expect( 'a data: link does not',       SFAF_Submissions::url( 'data:text/html,<script>' ), '' );
expect( 'a mailto: is not a web link', SFAF_Submissions::url( 'mailto:a@b.org' ), '' );
expect( 'an empty link is empty',      SFAF_Submissions::url( '' ), '' );

/* ---------------------------------------------------------------------------
 * 10. THE UPLOAD REFUSES BEFORE IT READS.
 *
 * store() is not called here, because it needs $_FILES, a writable uploads
 * directory and wp_handle_upload(). What IS checked is the part that decides
 * WHAT MAY BE SENT, because that list is the whole of the file policy and an
 * SVG appearing in it is the difference between an image upload and stored
 * XSS on our own domain.
 * ------------------------------------------------------------------------ */
$uploads_src = file_get_contents( $root . '/includes/class-sfaf-uploads.php' );
foreach ( array( 'is_uploaded_file', 'getimagesize', 'finfo_file', 'image_type_to_extension', 'realpath', 'chmod' ) as $needed ) {
    if ( false === strpos( $uploads_src, $needed ) ) {
        $fails[] = "the upload handler never calls $needed(), so one of the numbered checks is missing";
    }
}
foreach ( array( 'svg', 'image/svg', 'application/pdf' ) as $never ) {
    if ( false !== stripos( $uploads_src, "=> '" . $never ) ) {
        $fails[] = "the upload handler lists $never as an accepted type";
    }
}
/* =========================================================================
 * 11. ONE SECTION MECHANISM, AND THE SERIES ASKED FIRST (3.68.0).
 *
 * THE FAULT THIS PINS DOWN WAS A CASCADE ONE, not a type one. 3.66.0 fixed the
 * type. What was left is that a form of six sections drew four dividers,
 * because two of them were fieldsets carrying BOTH .uc-field and
 * .uc-form-section-group, and `.uc-request-card fieldset.uc-field` at (0,2,1)
 * sets `border: 0; padding: 0` over the section rule's (0,1,0). One class loses
 * to one class plus one type, which is the recurring fault on this project.
 *
 * So the assertions are about the CLASSES ON THE ELEMENTS, which is where that
 * fault lives, and about there being ONE spelling of a section rather than the
 * three there were: an <h2 class="uc-form-section">, a
 * <fieldset class="uc-field-group"> and a
 * <fieldset class="uc-form-section-group">.
 *
 * READ OUT OF THE SOURCE OF BOTH FORMS. Rendering the staff form needs a live
 * token, a rich text editor and the venue list; what is being checked is the
 * shape of the markup, and the picture section is rendered for real in
 * .claude/request-picture-picker-test.php.
 * ====================================================================== */
$forms = array(
    'the staff form'     => $src,
    'the community form' => file_get_contents( $root . '/includes/class-sfaf-submit.php' ),
);
foreach ( $forms as $which => $form_src ) {
    if ( preg_match( '/<h2\s+class="uc-form-section"/', $form_src ) ) {
        $fails[] = "$which still heads a section with an <h2>, so it has two kinds of section again";
    }
    if ( preg_match( '/class="uc-field-group"/', $form_src ) ) {
        $fails[] = "$which still uses .uc-field-group, which is the third spelling of a section";
    }
    /* THE ONE THAT ACTUALLY BROKE. A section fieldset must not carry
     * .uc-field, or the reset in .uc-request-card strips its boundary. */
    if ( preg_match_all( '/<fieldset class="([^"]*uc-form-section-group[^"]*)"/', $form_src, $sections ) ) {
        foreach ( $sections[1] as $classes ) {
            if ( false !== strpos( ' ' . $classes . ' ', ' uc-field ' ) ) {
                $fails[] = "a section on $which carries .uc-field, which is (0,2,1) and strips the boundary the section is drawn with";
            }
        }
    } else {
        $fails[] = "$which has no sections at all";
    }
    /* And every section names itself at the one step a group heading uses. */
    if ( preg_match_all( '/<legend class="([^"]*)"/', $form_src, $legends ) ) {
        foreach ( $legends[1] as $classes ) {
            if ( 'uc-field-group-title' !== $classes && 'uc-field-label' !== $classes ) {
                $fails[] = "a legend on $which carries '$classes', which is neither the group step nor a field label";
            }
        }
    }
}

/* THE SERIES IS THE FIRST QUESTION. Choosing one is what gives the event its
 * picture, so asking it after the picture chooser is asking in the wrong
 * order. New Event asks first for the same reason. */
$form_at   = strpos( $src, 'uc_request_action' );
$series_at = strpos( $src, 'data-uc-request-series' );
$name_at   = strpos( $src, 'name="requester_name"' );
$image_at  = strpos( $src, 'render_image_choice(' );
if ( false === $series_at || false === $name_at ) {
    $fails[] = 'the staff form has no series control or no name field, so the order cannot be checked';
} else {
    if ( $series_at > $name_at ) {
        $fails[] = 'the series is still asked after the requester name, so it is not the first question';
    }
    if ( false !== $image_at && $series_at > $image_at ) {
        $fails[] = 'the series is asked after the picture chooser, which is the control it answers';
    }
    if ( false !== $form_at && $series_at < $form_at ) {
        $fails[] = 'the series control is outside the form, so it would post nothing';
    }
}

/* AND IT CARRIES THE SERIES PICTURE FOR THE CONTROL BELOW IT. Shown, never
 * written: create_event() copies no image, because an event in a series with
 * no picture of its own already resolves to the series photo at display time. */
if ( false === strpos( $src, 'data-uc-series-thumb' ) ) {
    $fails[] = 'the series options carry no picture, so nothing can say what choosing one does to the image';
}
if ( false === strpos( $src, 'data-uc-image-default' ) ) {
    $fails[] = 'the "nothing chosen" image row is unmarked, so the series picture has nowhere to be shown';
}
if ( preg_match( '/set_post_thumbnail\(\s*\$event_id,\s*\$c\[.series.\]/', $src ) ) {
    $fails[] = 'the series image is copied onto the event, which is a value that goes stale when the series photo changes';
}

/* THE COPY MARK'S TWO LABELS (3.68.0). */
if ( false === strpos( $src, '<span class="uc-field-label">Location</span>' ) ) {
    $fails[] = 'the Location field is not labelled Location';
}
if ( false !== strpos( $src, '<span class="uc-field-label">Where</span>' ) ) {
    $fails[] = 'the Location field is still labelled Where';
}
if ( false === strpos( $src, '>Event Image</legend>' ) ) {
    $fails[] = 'the picture section is not headed Event Image';
}

/* =========================================================================
 * FAQs: A SET, THE REQUESTER'S OWN, OR BOTH (3.52.0).
 *
 * The three things this has to get right are all decisions rather than
 * mechanics, so each is asserted rather than assumed:
 *
 *   1. THE SET IS COPIED, NOT REFERENCED. Editing it afterwards must not touch
 *      an event already submitted, and nothing may record which set was used.
 *   2. THE SET'S QUESTIONS COME FIRST, the requester's after. No interleaving.
 *   3. THE CLEANUP RULE IS THE NARROW ONE, unchanged, for rows from either
 *      source.
 * ====================================================================== */
$GLOBALS['options']['sfaf_faq_sets'] = array(
    'parking' => array(
        'name'    => 'Parking and access',
        'rows'    => array(
            array( 'question' => 'Is there parking?',      'answer' => '<p>Yes, <strong>free</strong>.</p>' ),
            array( 'question' => 'Is it accessible?',      'answer' => '<p>Step-free throughout.</p><script>alert(1)</script>' ),
        ),
        'created' => 1,
        'updated' => 1,
    ),
);

/* --- Ordering, with both used. ---------------------------------------- */
$both = SFAF_Request::faqs_for( 'parking', SFAF_Submit::clean_faqs( array(
    array( 'question' => 'What should I bring?', 'answer' => '<p>Water.</p>' ),
) ) );
expect( 'a set and a question of their own make three rows', count( $both ), 3 );
if ( 3 === count( $both ) ) {
    expect( "the set's first question leads",  $both[0]['question'], 'Is there parking?' );
    expect( "the set's second follows",        $both[1]['question'], 'Is it accessible?' );
    expect( "the requester's own comes last",  $both[2]['question'], 'What should I bring?' );
}

/* --- Either alone. ----------------------------------------------------- */
expect( 'a set alone gives the set', count( SFAF_Request::faqs_for( 'parking', array() ) ), 2 );
expect( 'own questions alone need no set', count( SFAF_Request::faqs_for( '', SFAF_Submit::clean_faqs( array(
    array( 'question' => 'Only mine', 'answer' => '<p>Yes.</p>' ),
) ) ) ), 1 );
expect( 'neither gives nothing', SFAF_Request::faqs_for( '', array() ), array() );
expect( 'a set id naming nothing is dropped', SFAF_Request::faqs_for( 'no-such-set', array() ), array() );

/* --- A duplicate question is sent once, by the same rule apply() uses. -- */
$dupe = SFAF_Request::faqs_for( 'parking', SFAF_Submit::clean_faqs( array(
    array( 'question' => '  is there PARKING? ', 'answer' => '<p>Mine.</p>' ),
) ) );
expect( 'a question already in the set is not asked twice', count( $dupe ), 2 );

/* --- COPY, NOT REFERENCE, ASKED THE ONLY WAY IT CAN BE. ----------------
 *
 * NOT by editing the set afterwards and re-reading what came back. PHP arrays
 * are values, so rows already returned could never change however this were
 * written, and that check passed whatever the code did. The first draft of this
 * file did exactly that and a planted reference walked straight through it.
 *
 * The decidable question is whether anything about the SET reaches storage. A
 * copy stores text and forgets where it came from; a reference has to keep an
 * id to resolve later. So: every stored row is a question and an answer and
 * nothing else, and the event carries no set id anywhere in its meta.
 */
$out = SFAF_Request::validate( good_post( array(
    'faq_set' => 'parking',
    'faq'     => array( array( 'question' => 'Mine', 'answer' => '<p>Yes.</p>' ) ),
) ) );
foreach ( $out['clean']['faqs'] as $row ) {
    if ( array_keys( $row ) !== array( 'question', 'answer' ) ) {
        $fails[] = 'a stored FAQ row carries more than a question and an answer: ' . implode( ',', array_keys( $row ) );
        break;
    }
}
/* And nothing carries a set id onward into storage. faq_set is on $clean so
 * the form can show the chosen set again when it re-renders after an error
 * elsewhere; it must never become part of what is written to the event. */
if ( isset( $out['clean']['faqs']['set'] ) ) {
    $fails[] = 'the stored FAQ list names the set it came from, which is a reference rather than a copy';
}

/* The set's own markup is cleaned by the narrow rule too, not carried across
 * on the strength of having been written by staff in caladmin. */
$from_set = SFAF_Request::faqs_for( 'parking', array() );
if ( false !== stripos( $from_set[1]['answer'], '<script' ) ) {
    $fails[] = "a set's rows reach the event without the anonymous cleanup rule";
}

/* --- The cleanup rule is the narrow one, for rows from EITHER source. --- */
$nasty = SFAF_Request::faqs_for( 'parking', SFAF_Submit::clean_faqs( array(
    array( 'question' => 'Dangerous', 'answer' => '<p>ok</p><script>alert(1)</script><img src=x>' ),
) ) );
$last = end( $nasty );
if ( false !== stripos( $last['answer'], '<script' ) || false !== stripos( $last['answer'], '<img' ) ) {
    $fails[] = 'the staff form stores markup the anonymous rule forbids in a FAQ answer';
}
if ( false === strpos( $both[0]['answer'], '<strong>' ) ) {
    $fails[] = "a set's formatting was stripped on the way through the request form";
}

/* --- The cap is FAQ Sets' cap, not a number invented here. ------------- */
$many = array();
for ( $i = 0; $i < SFAF_FAQ_Sets::MAX_ROWS + 20; $i++ ) {
    $many[] = array( 'question' => 'Q' . $i, 'answer' => '<p>A</p>' );
}
expect(
    'the combined list is capped where every other FAQ list is',
    count( SFAF_Request::faqs_for( 'parking', SFAF_Submit::clean_faqs( $many ) ) ),
    SFAF_FAQ_Sets::MAX_ROWS
);

/* --- And the community form still has no set picker. ------------------- */
$submit_src = file_get_contents( $root . '/includes/class-sfaf-submit.php' );
if ( false !== strpos( $submit_src, "name=\"faq_set\"" ) ) {
    $fails[] = 'the community form has grown a FAQ set picker, which would list internal programming to a stranger';
}

/* ---------------------------------------------------------------------------
 * Result.
 * ------------------------------------------------------------------------ */
echo "Public event request form\n";
echo str_repeat( '=', 72 ) . "\n";
if ( $fails ) {
    foreach ( $fails as $f ) {
        echo "  FAIL  $f\n";
    }
    echo "\n" . count( $fails ) . " failure(s).\n";
    exit( 1 );
}
echo "a submitter can send only what the form offers, and only with a live link.\n";
