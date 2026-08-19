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
}
class SFAF_Venues {
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
        'requester_name' => 'Dana Reed',
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
    'dana@sfaf.org'            => true,
    'DANA@SFAF.ORG'            => true,
    'dana@mail.sfaf.org'       => true,
    'dana@notsfaf.org'         => false,
    'dana@sfaf.org.example.com'=> false,
    'dana@sfaf.com'            => false,
    'dana@example.org'         => false,
    'not-an-address'           => false,
    ''                         => false,
    'dana@sfaf.org '           => true,
) as $address => $want ) {
    expect( "is_staff_address(" . var_export( $address, true ) . ")", SFAF_Request::is_staff_address( $address ), $want );
}

/* ---------------------------------------------------------------------------
 * 2. TOKENS. Shape, resolution, and what an unknown one does.
 * ------------------------------------------------------------------------ */
$ref = new ReflectionMethod( 'SFAF_Request', 'issue_token' );
$ref->setAccessible( true );
$token = $ref->invoke( null, 'dana@sfaf.org' );

expect( 'a token is 32 hex characters', (bool) preg_match( '/^[a-f0-9]{32}$/', $token ), true );
expect( 'a live token resolves to its address', SFAF_Request::resolve_token( $token ), 'dana@sfaf.org' );
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
expect( 'the store holds the address', in_array( 'dana@sfaf.org', array_values( $GLOBALS['transients'] ), true ), true );

/* A token whose stored address stops being a staff one is dead. Somebody's
 * address is the only thing making a token usable. */
$key = 'sfaf_evreq_tok_' . hash( 'sha256', $token );
$GLOBALS['transients'][ $key ] = 'someone@example.org';
expect( 'a token holding a non-staff address is dead', SFAF_Request::resolve_token( $token ), '' );
$GLOBALS['transients'][ $key ] = 'dana@sfaf.org';

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

/* Markup and length. */
$out = SFAF_Request::validate( good_post( array(
    'title'       => 'Board <script>alert(1)</script> Social',
    'description' => '<a href="https://evil.example">click</a> here',
) ) );
if ( false !== strpos( $out['clean']['title'], '<' ) || false !== strpos( $out['clean']['description'], '<' ) ) {
    $fails[] = 'markup survives sanitisation, so a request can store HTML';
}
if ( false !== stripos( $out['clean']['title'], 'script' ) && false !== strpos( $out['clean']['title'], '<' ) ) {
    $fails[] = 'a script tag survives in the title';
}

$out = SFAF_Request::validate( good_post( array(
    'title'       => str_repeat( 'a', 5000 ),
    'description' => str_repeat( 'b', 20000 ),
    'notes'       => str_repeat( 'c', 20000 ),
) ) );
expect( "the title is capped",       strlen( $out["clean"]["title"] ), 200 );
expect( 'the description is capped', strlen( $out['clean']['description'] ), 5000 );
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
foreach ( array( 'SFAF_Request::META_EMAIL', 'uc-badge-request', 'render_request_panel' ) as $needle ) {
    if ( false === strpos( $portal, $needle ) ) {
        $fails[] = "the portal does not use $needle, so a staff request is indistinguishable in the queue";
    }
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
