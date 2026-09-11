<?php
/**
 * THE TWO PUBLIC FORMS, AND THE FILE HANDLER BEHIND BOTH.
 *
 *     php .claude/submissions-test.php
 *     php .claude/submissions-test.php --self-test
 *
 * WHAT THIS IS FOR. `SFAF_Submit` is the most exposed thing in the plugin: no
 * login, no email check, a URL printed on a campaign site, and a file input.
 * So the questions worth deciding here are the ones a stranger's request
 * decides: what comes back when nothing is sent, what a hostile value becomes,
 * which kind of pending row is produced, and IN WHAT ORDER the upload handler
 * refuses things.
 *
 * WHY THE UPLOAD ORDER IS ASSERTED AND THE UPLOAD IS NOT RUN. store() needs
 * $_FILES, a real temporary file that is_uploaded_file() will vouch for, a
 * writable uploads directory and wp_handle_upload(). None of those exist here,
 * and faking is_uploaded_file() would mean the one check that stops a crafted
 * request naming a local path is the one check the test disables. So the order
 * is read out of the source and pinned: each guard must appear before the work
 * it protects. That is a weaker claim than running it, and it is stated as
 * such in the report rather than dressed up.
 *
 * See the foot for what is left for a person.
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ . '/' );
define( 'SFAF_PLUGIN_URL', 'https://example.org/wp-content/plugins/sfaf-calendar/' );
define( 'SFAF_VERSION', '0.0.0-test' );
define( 'HOUR_IN_SECONDS', 3600 );
date_default_timezone_set( 'America/Los_Angeles' );

$fails = array();
$self  = in_array( '--self-test', $argv, true );

function fail( $msg ) { global $fails; $fails[] = $msg; }
function expect( $label, $got, $want ) {
    if ( $got !== $want ) {
        fail( $label . ': got ' . var_export( $got, true ) . ', expected ' . var_export( $want, true ) );
    }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 *
 * wp_kses() RECORDS ITS ARGUMENTS AND STRIPS WHAT IT WAS NOT GIVEN. It is not
 * re-implemented: what this plugin controls is the allow-list, and a stub that
 * tried to be kses would only ever prove itself. See request-form-test.php,
 * where the list itself is asserted.
 * ------------------------------------------------------------------------ */
$GLOBALS['meta']       = array();
$GLOBALS['transients'] = array();
$GLOBALS['kses_calls'] = array();

function wp_kses( $string, $allowed, $protocols = array() ) {
    $GLOBALS['kses_calls'][] = array( 'allowed' => $allowed, 'protocols' => $protocols );
    $keep = '';
    foreach ( array_keys( (array) $allowed ) as $t ) { $keep .= '<' . $t . '>'; }
    return strip_tags( (string) $string, $keep );
}
function wp_kses_post( $s ) { return (string) $s; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9\-]+/i', '-', trim( (string) $s ) ) ); }
function wp_strip_all_tags( $t, $b = false ) { return trim( strip_tags( (string) $t ) ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_url( $t ) { return esc_html( $t ); }
function esc_textarea( $t ) { return esc_html( $t ); }
function esc_url_raw( $url, $protocols = null ) {
    $url = trim( (string) $url );
    if ( '' === $url ) { return ''; }
    $scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
    if ( is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ) { return ''; }
    return $url;
}
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : date( $type ); }
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function add_query_arg( $a, $v = '', $u = '' ) {
    if ( ! is_array( $a ) ) { $a = array( $a => $v ); } else { $u = $v; }
    $out = $u;
    foreach ( $a as $k => $val ) { $out .= ( false === strpos( $out, '?' ) ? '?' : '&' ) . $k . '=' . rawurlencode( $val ); }
    return $out;
}
function home_url( $p = '', $s = null ) { return 'https://example.org' . $p; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function sfaf_ap_date( $d, $f = 'full' ) { return (string) $d; }
function sfaf_ap_time_range( $a, $b ) { return $a . ' to ' . $b; }
function class_exists_stub() {}

class WP_Error {}
function is_wp_error( $t ) { return ( $t instanceof WP_Error ); }

class SFAF_Reminders { public static function new_token() { return bin2hex( random_bytes( 16 ) ); } }
class SFAF_Series { const TAXONOMY = 'uc_series'; }
class SFAF_Venues { public static function exists( $id ) { return false; } }
/* THE REAL ONE, NOT A STUB. Section 6 asserts that the two folders do not
 * overlap, and a stubbed prefix would be asserting that the stub does not
 * overlap with the code. Both sides of that comparison have to be shipping
 * code or it proves nothing. */
require_once $root . '/includes/class-sfaf-media-folder.php';
class SFAF_Portal { public static function link( $p = '' ) { return 'https://example.org/caladmin/' . $p; } }
class SFAF_FAQ_Sets { const MAX_ROWS = 50; }

/* THE REAL to_plain(), because clean_faqs() decides emptiness with it and a
 * stub that just stripped tags would be the very reader 3.38.0 forbade. */
function sfaf_flatten_html( $html ) {
    return trim( preg_replace( '/\s+/', ' ', strip_tags( preg_replace( '#<(p|div|br|li|h[1-6])[^>]*>#i', ' ', (string) $html ) ) ) );
}
require_once $root . '/includes/class-sfaf-rich-text.php';

require_once $root . '/includes/class-sfaf-uploads.php';
require_once $root . '/includes/class-sfaf-submissions.php';
require_once $root . '/includes/class-sfaf-request.php';
require_once $root . '/includes/class-sfaf-submit.php';

/* =========================================================================
 * 1. SUBMITTING NOTHING FAILS SAFELY.
 *
 * An empty POST is the shape a bot sends and the shape a browser sends when
 * something goes wrong on the way. It must produce errors and no values, and
 * above all it must not throw: a fatal here is a 500 on a public page.
 * ====================================================================== */
$out = SFAF_Submit::validate( array() );

foreach ( array( 'submitter_name', 'submitter_email', 'title', 'description', 'date', 'start_time', 'end_time', 'street', 'contact_name', 'contact_email', 'cost' ) as $required ) {
    if ( ! isset( $out['errors'][ $required ] ) ) {
        fail( "an empty submission is accepted for $required, which is a required field" );
    }
}
foreach ( array( 'age', 'rsvp_url', 'notes' ) as $optional ) {
    if ( isset( $out['errors'][ $optional ] ) ) {
        fail( "an empty submission is refused for $optional, which is optional" );
    }
    expect( "an absent $optional is empty rather than missing", $out['clean'][ $optional ], '' );
}

/* And nothing arrives as a value. */
expect( 'no title survives an empty submission', $out['clean']['title'], '' );
expect( 'no date survives an empty submission', $out['clean']['date'], '' );

/* =========================================================================
 * 2. A GOOD SUBMISSION.
 * ====================================================================== */
function good( $over = array() ) {
    return array_merge( array(
        'submitter_name'  => 'Dana Reyes',
        'submitter_email' => 'dana@example.org',
        'title'           => 'Sunset ride',
        'description'     => '<p>A ride along the <strong>coast</strong>.</p>',
        'date'            => date( 'Y-m-d', strtotime( '+20 days' ) ),
        'start_time'      => '18:00',
        'end_time'        => '20:00',
        'street'          => '470 Castro St',
        'city'            => 'San Francisco',
        'state'           => 'CA',
        'zip'             => '94114',
        'contact_name'    => 'Ride desk',
        'contact_email'   => 'rides@example.org',
        'cost'            => 'free',
    ), $over );
}

$out = SFAF_Submit::validate( good() );
if ( ! empty( $out['errors'] ) ) {
    fail( 'a complete submission was refused: ' . implode( ', ', array_keys( $out['errors'] ) ) );
}
expect( 'the title survives', $out['clean']['title'], 'Sunset ride' );
if ( false === strpos( $out['clean']['description'], '<strong>' ) ) {
    fail( 'the description lost the formatting the editor produced' );
}

/* =========================================================================
 * 3. WHAT A HOSTILE VALUE BECOMES.
 * ====================================================================== */
$out = SFAF_Submit::validate( good( array(
    'title'       => 'Ride <script>alert(1)</script> Night',
    'description' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)>',
    'street'       => "  two    spaces  ",
    'contact_name' => '<b>Ride desk</b>',
    'rsvp_url'    => 'javascript:alert(1)',
) ) );

if ( false !== strpos( $out['clean']['title'], '<' ) ) {
    fail( 'markup survives in the title' );
}
if ( false !== stripos( $out['clean']['description'], '<script' ) ) {
    fail( 'a script tag survives in the description' );
}
if ( false !== stripos( $out['clean']['description'], '<img' ) ) {
    fail( 'an img tag survives in the description, which is an off-site request on every view' );
}
expect( 'the street collapses its whitespace', $out['clean']['street'], 'two spaces' );
if ( false !== strpos( $out['clean']['contact_name'], '<' ) ) {
    fail( 'markup survives in the public contact, which is printed on the event page' );
}
expect( 'a javascript: registration link is dropped', $out['clean']['rsvp_url'], '' );
if ( ! isset( $out['errors']['rsvp_url'] ) ) {
    fail( 'a refused registration link is dropped silently, so the submitter never learns it was not kept' );
}

/* =========================================================================
 * 3b. COST IS AN ANSWER, AND "OTHER" WITH AN EMPTY BOX IS NOT ONE.
 *
 * The dropdown used to carry a "Not saying" entry, which read as an option and
 * was really a way to answer without answering. There are three real answers
 * now and the field is required, so both shapes of non-answer are refused: no
 * choice at all, and the escape hatch chosen with nothing typed behind it.
 * ====================================================================== */
$out = SFAF_Submit::validate( good( array( 'cost' => 'other', 'cost_other' => '' ) ) );
if ( ! isset( $out['errors']['cost_other'] ) ) {
    fail( 'cost "Something else" with an empty box is accepted, which is no answer wearing the shape of one' );
}

$out = SFAF_Submit::validate( good( array( 'cost' => 'other', 'cost_other' => '   ' ) ) );
if ( ! isset( $out['errors']['cost_other'] ) ) {
    fail( 'cost "Something else" with only whitespace is accepted' );
}

$out = SFAF_Submit::validate( good( array( 'cost' => 'other', 'cost_other' => '$15 at the door' ) ) );
if ( isset( $out['errors']['cost_other'] ) || isset( $out['errors']['cost'] ) ) {
    fail( 'cost "Something else" with a real answer is refused' );
}
expect( 'the typed cost is kept', $out['clean']['cost_other'], '$15 at the door' );

/* A value the control never offered is no answer, which is also what a forged
 * one gets. "Not saying" is the one that used to exist and must not come back. */
foreach ( array( '', 'not_saying', 'nonsense' ) as $bad ) {
    $out = SFAF_Submit::validate( good( array( 'cost' => $bad ) ) );
    if ( ! isset( $out['errors']['cost'] ) ) {
        fail( 'cost accepted the value "' . $bad . '", which the control does not offer' );
    }
}
if ( array_key_exists( '', SFAF_Submit::cost_options() ) ) {
    fail( 'the cost list still carries an empty option, which is a way to answer without answering' );
}
expect( 'three cost answers, and no more', count( SFAF_Submit::cost_options() ), 3 );

/* Free has to be sayable, because it is the question people ask. */
$out = SFAF_Submit::validate( good( array( 'cost' => 'free' ) ) );
if ( ! empty( $out['errors'] ) ) {
    fail( 'a submission that says Free is refused: ' . implode( ', ', array_keys( $out['errors'] ) ) );
}
expect( 'Free is stored as words, not as a key', SFAF_Submit::choice_phrase( 'free', '', 'cost' ), 'Free' );

/* =========================================================================
 * 3c. FAQs AND CAPACITY.
 *
 * Empty rows go silently, because the repeater starts with one and most
 * submitters will send it untouched. A HALF-filled row is kept: somebody meant
 * it, and dropping it quietly is worse than showing a gap at review.
 * ====================================================================== */
expect( 'an untouched repeater stores nothing', SFAF_Submit::clean_faqs( array( array( 'question' => '', 'answer' => '' ) ) ), array() );
expect( 'a non-array is not FAQs', SFAF_Submit::clean_faqs( 'x' ), array() );

$faqs = SFAF_Submit::clean_faqs( array(
    array( 'question' => 'Is there parking?', 'answer' => '<p>Yes, <strong>free</strong>.</p><script>alert(1)</script>' ),
    array( 'question' => '',                  'answer' => '' ),
    array( 'question' => 'Question only',     'answer' => '' ),
) );
expect( 'empty rows are dropped and the rest kept', count( $faqs ), 2 );
if ( false !== stripos( $faqs[0]['answer'], '<script' ) ) {
    fail( 'a script tag survives in a submitted FAQ answer' );
}
if ( false === strpos( $faqs[0]['answer'], '<strong>' ) ) {
    fail( 'a submitted FAQ answer lost the formatting it is allowed to keep' );
}
expect( 'a half-filled row is kept', $faqs[1]['question'], 'Question only' );

/*
 * AND AN ANCHOR SURVIVES, WHICH IS WHAT 3.51.0'S TOOLBAR RESTS ON.
 *
 * The FAQ answer became the shared rich text control with the shared toolbar,
 * and that toolbar carries link and unlink. A link button over a rule that
 * strips anchors is a control that appears to work and silently discards what
 * somebody typed, so the rule is asserted here rather than assumed: prose()
 * already permitted `a href`, and NOTHING WAS WIDENED to add the toolbar.
 *
 * THE PROTOCOL HALF IS ASSERTED AS THE LIST, NOT AS AN OUTCOME, and that is
 * forced by the harness rather than chosen. wp_kses() here is strip_tags(),
 * which cannot filter a protocol at all, so feeding it `javascript:` and
 * checking what came back would be a fact about this file and not about the
 * plugin. The stub records its arguments for exactly this reason. The first
 * draft of this assertion got that wrong and reported a fault that was the
 * stub's.
 */
$GLOBALS['kses_calls'] = array();
$linked = SFAF_Submit::clean_faqs( array(
    array(
        'question' => 'Where do I book?',
        'answer'   => '<p>Book <a href="https://example.org/book" title="Booking">here</a>.</p>',
    ),
) );

if ( empty( $linked ) ) {
    fail( 'a FAQ answer containing a link was dropped entirely' );
} elseif ( false === strpos( $linked[0]['answer'], '<a' ) ) {
    fail( 'the link the FAQ toolbar can produce does not survive the sanitiser' );
}

$call = end( $GLOBALS['kses_calls'] );
if ( ! $call ) {
    fail( 'a submitted FAQ answer never reached wp_kses() at all' );
} else {
    if ( ! isset( $call['allowed']['a']['href'] ) ) {
        fail( 'the FAQ answer allow-list has no anchor href, so the toolbar offers a link the rule discards' );
    }
    foreach ( array( 'http', 'https', 'mailto' ) as $scheme ) {
        if ( ! in_array( $scheme, (array) $call['protocols'], true ) ) {
            fail( "the FAQ answer allow-list does not permit the $scheme protocol" );
        }
    }
    if ( in_array( 'javascript', (array) $call['protocols'], true ) ) {
        fail( 'the FAQ answer allow-list permits the javascript protocol' );
    }
}

$out = SFAF_Submit::validate( good( array( 'capacity' => '40' ) ) );
expect( 'a capacity is kept', $out['clean']['capacity'], 40 );
$out = SFAF_Submit::validate( good( array( 'capacity' => '' ) ) );
expect( 'a blank capacity means unlimited', $out['clean']['capacity'], 0 );
$out = SFAF_Submit::validate( good( array( 'capacity' => '-3' ) ) );
if ( ! isset( $out['errors']['capacity'] ) ) {
    fail( 'a negative capacity is accepted' );
}

/* An array where a string belongs is the shape that reaches a type error. */
$out = SFAF_Submit::validate( good( array( 'title' => array( 'a', 'b' ), 'description' => array( 'x' ) ) ) );
expect( 'an array title becomes empty', $out['clean']['title'], '' );
expect( 'an array description becomes empty', $out['clean']['description'], '' );

/* A date in the past is refused, because a submission form is about what is
 * coming and a past date is far more often a typo than a deliberate answer. */
$out = SFAF_Submit::validate( good( array( 'date' => date( 'Y-m-d', strtotime( '-2 days' ) ) ) ) );
if ( ! isset( $out['errors']['date'] ) ) {
    fail( 'a date in the past is accepted' );
}
$out = SFAF_Submit::validate( good( array( 'start_time' => '20:00', 'end_time' => '18:00' ) ) );
if ( ! isset( $out['errors']['end_time'] ) ) {
    fail( 'an end time before the start time is accepted' );
}

/* =========================================================================
 * 3b. UP TO FIVE ADDRESSES ON THE ABOUT YOU FIELD (3.67.0).
 *
 * The submitter's own is the FIRST of the five rather than additional to them,
 * so nothing about who submitted a thing changes; what changes is how many
 * people the approval tick can put on the event's notification list. That list
 * is sent registrant names and email addresses, which is why every one of the
 * assertions below is about REFUSING rather than about accepting.
 * ====================================================================== */
$out = SFAF_Submit::validate( good( array(
    'submitter_email' => array( 'dana@example.org', 'lee@example.org', 'sam@example.org' ),
) ) );
expect( 'three addresses are kept, in the order they were given',
    $out['clean']['submitter_emails'],
    array( 'dana@example.org', 'lee@example.org', 'sam@example.org' ) );
expect( 'and the first of them is the submitter',
    $out['clean']['submitter_email'], 'dana@example.org' );
if ( ! empty( $out['errors'] ) ) {
    fail( 'a submission with three addresses was refused: ' . implode( ', ', array_keys( $out['errors'] ) ) );
}

/* ONE ADDRESS STILL POSTS AS A STRING, which is what a browser with no script
 * sends and what every existing caller passes. */
$out = SFAF_Submit::validate( good() );
expect( 'a single address still arrives as a list of one',
    $out['clean']['submitter_emails'], array( 'dana@example.org' ) );

/* THE CAP IS THE SERVER'S, not the button's. A sixth is a hand-built body. */
$out = SFAF_Submit::validate( good( array( 'submitter_email' => array(
    'a@example.org', 'b@example.org', 'c@example.org',
    'd@example.org', 'e@example.org', 'f@example.org',
) ) ) );
expect( 'the sixth address is dropped', count( $out['clean']['submitter_emails'] ), SFAF_Submit::MAX_EMAILS );
if ( in_array( 'f@example.org', $out['clean']['submitter_emails'], true ) ) {
    fail( 'an address past the cap was stored' );
}

/* A BAD ONE IS AN ERROR, NOT A SILENT DROP. Dropping it would promote the next
 * address to first, and the first is who the submission is recorded as being
 * from and who gets the confirmation. */
$out = SFAF_Submit::validate( good( array(
    'submitter_email' => array( 'not-an-address', 'lee@example.org' ),
) ) );
if ( ! isset( $out['errors']['submitter_email'] ) ) {
    fail( 'an address that is not one was dropped silently, promoting the next to submitter' );
}

expect( 'the same address twice is stored once',
    SFAF_Submit::validate( good( array( 'submitter_email' => array(
        'dana@example.org', 'DANA@example.org',
    ) ) ) )['clean']['submitter_emails'],
    array( 'dana@example.org' ) );

expect( 'an empty box between two filled ones is skipped rather than stored',
    SFAF_Submit::validate( good( array( 'submitter_email' => array(
        'dana@example.org', '', 'lee@example.org',
    ) ) ) )['clean']['submitter_emails'],
    array( 'dana@example.org', 'lee@example.org' ) );

/* ---------------------------------------------------------------------------
 * "USE MY DETAILS AS THE PUBLIC CONTACT" (3.74.0)
 *
 * The direction is the whole of what is worth testing here. The submitter's
 * details are internal; the contact prints on the event page. Copying the
 * wrong way, or copying when nobody asked, would put somebody's address on a
 * public page because a control was left alone.
 * ------------------------------------------------------------------------ */
$out = SFAF_Submit::validate( good( array( 'contact_same' => '1' ) ) );
expect( 'ticked, the public name is the submitter\'s', $out['clean']['contact_name'], 'Dana Reyes' );
expect( 'ticked, the public email is the submitter\'s', $out['clean']['contact_email'], 'dana@example.org' );
expect( 'ticked, no phone is invented', $out['clean']['contact_phone'], '' );
expect( 'ticked, the submitter is unchanged', $out['clean']['submitter_email'], 'dana@example.org' );
if ( ! empty( $out['errors'] ) ) {
    fail( 'a ticked form with no contact fields was refused: ' . implode( ', ', array_keys( $out['errors'] ) ) );
}

/* WITH NO SCRIPT the three fields are visible and whatever is in them is
 * overwritten, because the tick is what was answered. */
$out = SFAF_Submit::validate( good( array(
    'contact_same'  => '1',
    'contact_name'  => 'Somebody else',
    'contact_email' => 'someone@example.org',
    'contact_phone' => '555-0000',
) ) );
expect( 'ticked beats what is in the boxes', $out['clean']['contact_name'], 'Dana Reyes' );
expect( 'and the phone goes with them', $out['clean']['contact_phone'], '' );

/* UNTICKED IS THE DEFAULT AND CHANGES NOTHING. */
$out = SFAF_Submit::validate( good() );
expect( 'unticked, the public contact is what was typed', $out['clean']['contact_name'], 'Ride desk' );
expect( 'unticked, nothing of the submitter leaks into it', $out['clean']['contact_email'], 'rides@example.org' );

/* AND NOTHING EVER COPIES THE OTHER WAY. */
$out = SFAF_Submit::validate( good( array(
    'contact_name'  => 'Ride desk',
    'contact_email' => 'rides@example.org',
) ) );
expect( 'the submitter is never overwritten by the public contact',
    $out['clean']['submitter_name'], 'Dana Reyes' );

/* A NESTED ARRAY NAMES NO ADDRESS. Casting one to a string is a PHP warning
 * and an empty key, which is the shape a hand-built post body arrives in. */
$out = SFAF_Submit::validate( good( array(
    'submitter_email' => array( 'dana@example.org', array( 'x' => 'y' ) ),
) ) );
expect( 'a nested array in the field is ignored',
    $out['clean']['submitter_emails'], array( 'dana@example.org' ) );

/*
 * WHAT THE APPROVAL SCREEN READS BACK, and it re-checks rather than trusting
 * what is stored: a stored value can predate the rule that would have refused
 * it, and these go onto a list carrying registrant data.
 */
$GLOBALS['meta'] = array(
    20 => array(
        SFAF_Submissions::META_KIND    => SFAF_Submissions::KIND_COMMUNITY,
        SFAF_Request::META_EMAIL       => 'dana@example.org',
        SFAF_Submit::META_NOTIFY_EMAILS => array( 'dana@example.org', 'lee@example.org', 'sam@example.org' ),
    ),
    21 => array(
        SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_STAFF,
        SFAF_Request::META_EMAIL    => 'colleague@sfaf.org',
    ),
    22 => array(
        SFAF_Submissions::META_KIND    => SFAF_Submissions::KIND_COMMUNITY,
        SFAF_Request::META_EMAIL       => 'dana@example.org',
        SFAF_Submit::META_NOTIFY_EMAILS => array( 'dana@example.org', 'nonsense', 'lee@example.org' ),
    ),
);
expect( 'every address comes back, the submitter first',
    SFAF_Submissions::notify_addresses( 20 ),
    array( 'dana@example.org', 'lee@example.org', 'sam@example.org' ) );
expect( 'a staff request answers with the one address it has',
    SFAF_Submissions::notify_addresses( 21 ), array( 'colleague@sfaf.org' ) );
expect( 'a stored value that is not an address is refused on the way out',
    SFAF_Submissions::notify_addresses( 22 ),
    array( 'dana@example.org', 'lee@example.org' ) );
expect( 'an event nobody submitted has nobody to tell',
    SFAF_Submissions::notify_addresses( 13 ), array() );

/*
 * FIVE ADDRESSES ARE NOT FIVE SUBMISSIONS. The limiter counts POSTS, keyed on
 * the client and on the campaign, and neither key has ever been an address.
 * This is the assertion that says so rather than the reasoning saying so.
 */
$submit_rate_src = file_get_contents( $root . '/includes/class-sfaf-submit.php' );
if ( preg_match( '/SFAF_Submissions::allow\(\s*[\'"]([a-z_]+)[\'"]\s*,\s*([^,]+),/', $submit_rate_src, $m ) ) {
    if ( false !== strpos( $m[2], 'email' ) ) {
        fail( 'a rate limiter is keyed on an address, so five addresses would be five allowances' );
    }
}
if ( preg_match_all( '/SFAF_Submissions::allow\(/', $submit_rate_src ) !== 3 ) {
    fail( 'the submission form no longer makes exactly three rate-limited calls; check none is per address' );
}

/* =========================================================================
 * 4. WHICH KIND OF PENDING ROW IT IS.
 *
 * BOTH FORMS WRITE AN ADDRESS TO THE SAME KEY, which is exactly why the old
 * "has an email" test stopped being an answer. Every case below is a row the
 * queue has to badge correctly, including the one with no marker at all, which
 * is every request made before this release.
 * ====================================================================== */
$GLOBALS['meta'] = array(
    10 => array( SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_COMMUNITY, SFAF_Request::META_EMAIL => 'a@b.org' ),
    11 => array( SFAF_Submissions::META_KIND => SFAF_Submissions::KIND_STAFF, SFAF_Request::META_EMAIL => 'c@sfaf.org' ),
    12 => array( SFAF_Request::META_EMAIL => 'legacy@sfaf.org' ),
    13 => array(),
);
expect( 'a community submission is community', SFAF_Submissions::kind( 10 ), 'community' );
expect( 'a staff request is staff',            SFAF_Submissions::kind( 11 ), 'staff' );
expect( 'a request from before the marker is still staff', SFAF_Submissions::kind( 12 ), 'staff' );
expect( 'an event nobody submitted is local',  SFAF_Submissions::kind( 13 ), 'local' );
expect( 'nothing is not a kind',               SFAF_Submissions::kind( 0 ), 'local' );

expect( 'a community row is badged',  SFAF_Submissions::kind_label( 'community' ), 'Community submission' );
expect( 'a staff row is badged',      SFAF_Submissions::kind_label( 'staff' ), 'Staff request' );
expect( 'a local row is not badged',  SFAF_Submissions::kind_label( 'local' ), '' );
expect( 'an import is not badged here', SFAF_Submissions::kind_label( 'import' ), '' );

/* =========================================================================
 * 5. THE RATE LIMITER COUNTS, AND STOPS.
 * ====================================================================== */
$GLOBALS['transients'] = array();
$allowed = 0;
for ( $i = 0; $i < 8; $i++ ) {
    if ( SFAF_Submissions::allow( 'probe', '203.0.113.9', 5, 3600 ) ) { $allowed++; }
}
expect( 'the limiter allows exactly its limit', $allowed, 5 );

/* Two subjects are counted apart, or one busy person locks out everybody. */
$GLOBALS['transients'] = array();
SFAF_Submissions::allow( 'probe', 'one', 1, 3600 );
expect( 'a different subject has its own count', SFAF_Submissions::allow( 'probe', 'two', 1, 3600 ), true );

/* And two buckets are counted apart, or an upload spends a submission. */
$GLOBALS['transients'] = array();
SFAF_Submissions::allow( 'bucket_a', 'same', 1, 3600 );
expect( 'a different bucket has its own count', SFAF_Submissions::allow( 'bucket_b', 'same', 1, 3600 ), true );

/* THE SUBJECT IS NOT STORED IN THE CLEAR. A transient key holding an address
 * is an address in the options table for anybody with database access. */
$GLOBALS['transients'] = array();
SFAF_Submissions::allow( 'probe', 'dana@example.org', 5, 3600 );
foreach ( array_keys( $GLOBALS['transients'] ) as $key ) {
    if ( false !== strpos( $key, 'dana@example.org' ) ) {
        fail( 'the rate limiter writes the subject into the transient key in the clear' );
    }
}

/* =========================================================================
 * 6. THE UPLOAD HANDLER'S ORDER.
 *
 * READ OUT OF THE SOURCE, and the report says so. Each guard must come before
 * the work it protects; the point of the order is that nothing expensive or
 * credulous happens to a file that was never going to be accepted.
 * ====================================================================== */
$src = code_without_comments( $root . '/includes/class-sfaf-uploads.php' );
/*
 * THE HANDLER IS TWO METHODS SINCE 3.74.0 AND THE ORDER IS STILL ONE ORDER.
 * inspect() holds everything decided before a file moves and store() holds the
 * move; inspect() is written first, so reading from there covers both in the
 * order they run. Reading from store() alone would have silently skipped every
 * guard and reported the remaining pairs as fine.
 */
$body_at = strpos( $src, 'public static function inspect(' );
if ( false === $body_at ) {
    $body_at = strpos( $src, 'public static function store(' );
}
if ( false === $body_at ) {
    fail( 'neither inspect() nor store() is in SFAF_Uploads, so the whole of section 6 checks nothing' );
} else {
    $body = substr( $src, $body_at );

    /* Ordered pairs: the first must appear before the second. */
    $order = array(
        array( 'UPLOAD_ERR_NO_FILE', 'call_user_func',      'a file is checked for existing before the rate limiter is spent on it' ),
        array( 'call_user_func',     'is_uploaded_file',    'the rate limit is counted before the file is touched' ),
        array( 'is_uploaded_file',   'filesize',            'the upload is proved genuine before its size is read' ),
        array( 'filesize',           'getimagesize',        'the size ceiling is applied before the image is parsed' ),
        array( 'getimagesize',       'self::sniff',         'the header is read before the second reader is asked to agree' ),
        array( 'self::sniff',        'image_type_to_extension', 'both readers agree before an extension is derived' ),
        array( 'image_type_to_extension', 'wp_handle_upload',   'the name is generated before anything is moved' ),
        array( 'wp_handle_upload',   'chmod',               'the file is moved before its mode is set' ),
        array( 'chmod',              'realpath',            'the mode is set before the destination is confirmed' ),
        array( 'realpath',           'wp_insert_attachment', 'the destination is confirmed before it becomes an attachment' ),
    );
    foreach ( $order as $pair ) {
        $a = strpos( $body, $pair[0] );
        $b = strpos( $body, $pair[1] );
        if ( false === $a || false === $b ) {
            fail( "the upload handler no longer mentions {$pair[0]} or {$pair[1]}, so its order cannot be read" );
        } elseif ( $a > $b ) {
            fail( 'the upload handler is out of order: ' . $pair[2] );
        }
    }
}

/* WHAT MAY BE SENT. The list is the whole of the file policy. */
$types = SFAF_Uploads::allowed_types();
expect( 'four image types are accepted', count( $types ), 4 );
foreach ( $types as $const => $mime ) {
    if ( 0 !== strpos( $mime, 'image/' ) ) {
        fail( "the upload handler accepts $mime, which is not an image type" );
    }
}
foreach ( array( 'image/svg+xml', 'application/pdf', 'text/html' ) as $never ) {
    if ( in_array( $never, $types, true ) ) {
        fail( "the upload handler accepts $never" );
    }
}
expect( 'the ceiling is 10MB', SFAF_Uploads::MAX_BYTES, 10485760 );

/* THE TWO FOLDERS DO NOT OVERLAP, which is what keeps raw uploads out of the
 * caladmin picker. An anchored prefix is the whole mechanism, so both
 * directions are checked. */
if ( SFAF_Uploads::path_is_inside( 'calendar/photo.jpg' ) ) {
    fail( 'an approved calendar image reads as a submission' );
}
if ( SFAF_Media_Folder::path_is_inside( 'calendar-submissions/photo.jpg' ) ) {
    fail( 'a raw submission reads as an approved calendar image, so the picker would offer it' );
}
if ( ! SFAF_Uploads::path_is_inside( 'calendar-submissions/photo.jpg' ) ) {
    fail( 'a submission is not recognised as being in its own folder' );
}
if ( SFAF_Uploads::path_is_inside( 'photos/calendar-submissions/x.jpg' ) ) {
    fail( 'the submissions prefix is not anchored at the front' );
}

/* =========================================================================
 * 7. TURNSTILE FAILS OPEN ON AN OUTAGE, AND CLOSED ON A MISSING TOKEN.
 * ====================================================================== */
$turn = code_without_comments( $root . '/includes/class-sfaf-turnstile.php' );
if ( false === strpos( $turn, 'is_wp_error' ) ) {
    fail( 'Turnstile does not handle a transport error, so an outage at Cloudflare is an unhandled result' );
}
if ( ! preg_match( '/if\s*\(\s*\'\'\s*===\s*\$token\s*\)\s*\{\s*return false;/', $turn ) ) {
    fail( 'Turnstile does not refuse a request that carries no token at all' );
}
if ( false === strpos( $turn, 'self::ready()' ) ) {
    fail( 'Turnstile does not check that both keys are configured' );
}

/* =========================================================================
 * 8. THE FORM EXPOSES NOTHING IT DOES NOT NEED.
 *
 * It is the most open page in the plugin, so what it can be made to PRINT
 * matters as much as what it accepts. An unknown slug must not answer with a
 * list of what exists.
 * ====================================================================== */
/**
 * Source with the comments taken out.
 *
 * THIS FILE IS MOSTLY COMMENTS, AND THE FIRST VERSION OF THIS SECTION READ
 * THEM. It searched for set_post_thumbnail and found the sentence saying
 * set_post_thumbnail is deliberately NOT called, then reported the opposite
 * of the truth. token_get_all() rather than a regex, because a regex over
 * PHP is the trap this project has already been caught by twice.
 *
 * @param string $path
 * @return string
 */
function code_without_comments( $path ) {
    $out = '';
    foreach ( token_get_all( file_get_contents( $path ) ) as $t ) {
        if ( is_array( $t ) ) {
            if ( T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

$submit_src = code_without_comments( $root . '/includes/class-sfaf-submit.php' );
if ( preg_match( '/render_unknown\(\).*?SFAF_Series::all\(\)/s', $submit_src ) ) {
    fail( 'the unknown-link page lists the series, which turns this into a directory of campaigns' );
}
if ( false !== strpos( $submit_src, 'register_rest_route' ) ) {
    fail( 'the submission form registers a REST route; is_embed_request() is an exact match and a new route breaks CORS' );
}
if ( false === strpos( $submit_src, "'post_status'  => 'pending'" ) ) {
    fail( 'the submission form does not name pending as the status, so a form value could decide it' );
}
/*
 * THE RULE IS ABOUT THE UPLOADED FILE, NOT ABOUT THUMBNAILS (3.74.0).
 *
 * It was written as "no set_post_thumbnail in this file at all", which was a
 * true statement of the code and a wider statement than the reason. The reason
 * is that a SUBMITTED file is a working copy in a folder meant to be emptied,
 * so nothing may point at it permanently. A picture chosen from the calendar
 * folder is the opposite case in every respect: approved, right shape, already
 * on the public calendar, and checked by clean_image_choice() before it is
 * stored. The check now asks the question the reason asks.
 */
if ( preg_match( '/set_post_thumbnail\(\s*\$event_id,\s*\$image_id/', $submit_src ) ) {
    fail( 'the submitted file is set as the event image; it is a working copy and the folder is meant to be emptied' );
}
if ( false === strpos( $submit_src, 'SFAF_Submissions::trapped' ) ) {
    fail( 'the submission form has no honeypot' );
}

/*
 * THE EMAIL FIELD'S MARKUP, READ RATHER THAN RENDERED.
 *
 * Rendering this form needs a term, a rich text editor, Turnstile and the
 * venue list, so what is read here is the shape and what proves the behaviour
 * is elsewhere: validate() above runs for real, and
 * .claude/repeater-max-test.js runs the real initRepeaters() and presses the
 * button until the cap. These three are one assertion in three files.
 */
if ( false === strpos( $submit_src, 'name="submitter_email[]"' ) ) {
    fail( 'the About you email field does not post as an array, so only one address can ever arrive' );
}
if ( false === strpos( $submit_src, 'data-repeater-max="<?php echo (int) self::MAX_EMAILS; ?>"' ) ) {
    fail( 'the email repeater does not carry the cap the validator enforces, so the two can disagree' );
}
if ( false === strpos( $submit_src, 'if ( count( $emails ) < self::MAX_EMAILS ) :' ) ) {
    fail( 'the Add email button is rendered unconditionally; at the cap it must not be drawn at all' );
}
if ( false !== strpos( $submit_src, 'name="submitter_email"' ) ) {
    fail( 'a single-value submitter_email control is still rendered somewhere on this form' );
}

/* THE SUBMITTER'S OWN ADDRESS IS NOT A TEMPLATE FIELD. Their contact line is
 * public because they answered it knowing that; their own address is not. */
$tpl = code_without_comments( $root . '/templates/single-uc_event.php' );
foreach ( array( 'META_EMAIL', '_uc_request_email', 'submitter_email' ) as $private ) {
    if ( false !== strpos( $tpl, $private ) ) {
        fail( "the event template reads $private, which is the submitter's own address and is internal" );
    }
}

/* ---------------------------------------------------------------------------
 * SELF TEST. Every case is a shape this file has NOT already seen pass.
 * ------------------------------------------------------------------------ */
if ( $self ) {
    echo "SELF TEST\n" . str_repeat( '=', 72 ) . "\n";
    $bad = 0;

    /* validate() must actually reject, or sections 1 and 3 are vacuous. */
    $empty = SFAF_Submit::validate( array() );
    if ( count( $empty['errors'] ) >= 9 ) {
        echo "ok       validate() rejects an empty submission on every required field\n";
    } else {
        echo 'BROKEN:  an empty submission produced ' . count( $empty['errors'] ) . " errors; the reader is wrong\n";
        $bad++;
    }

    /* And it must ACCEPT, or "a good submission" proves nothing either. */
    $ok = SFAF_Submit::validate( good() );
    if ( empty( $ok['errors'] ) ) {
        echo "ok       validate() accepts a complete submission\n";
    } else {
        echo "BROKEN:  a complete submission is refused, so every rejection above may be for the wrong reason\n";
        $bad++;
    }

    /* The kses stub must strip, or section 3 passes on anything. */
    if ( false === strpos( wp_kses( '<script>x</script>keep', array( 'p' => array() ), array() ), '<script' ) ) {
        echo "ok       the kses stub removes a tag it was not given\n";
    } else {
        echo "BROKEN:  the kses stub keeps everything, so no sanitising assertion means anything\n";
        $bad++;
    }

    /* The meta store must actually store, or section 4 reads '' every time and
     * every row would answer 'local'. */
    $GLOBALS['meta'] = array( 99 => array( SFAF_Submissions::META_KIND => 'community' ) );
    if ( 'community' === SFAF_Submissions::kind( 99 ) && 'local' === SFAF_Submissions::kind( 98 ) ) {
        echo "ok       the meta stub distinguishes a marked row from an unmarked one\n";
    } else {
        echo "BROKEN:  the meta stub answers the same whatever it is asked\n";
        $bad++;
    }

    /* The order reader must be able to SEE a violation. */
    $probe = 'function store() { wp_insert_attachment(); realpath(); }';
    if ( strpos( $probe, 'realpath' ) > strpos( $probe, 'wp_insert_attachment' ) ) {
        echo "ok       the order reader can see a pair in the wrong order\n";
    } else {
        echo "BROKEN:  the order comparison does not detect a reversed pair\n";
        $bad++;
    }

    echo "\n" . ( $bad ? "$bad case(s) wrong; this checker cannot be trusted.\n" : "the harness reads what it claims to read.\n" );
    exit( $bad ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Report.
 * ------------------------------------------------------------------------ */
echo "The two public forms, and the file handler behind both\n";
echo str_repeat( '=', 72 ) . "\n";
echo "checked by running:   validate() on an empty, a good and a hostile submission,\n";
echo "                      kind() on all four kinds of row, the rate limiter, and\n";
echo "                      the two folders' prefixes\n";
echo "checked by reading:   the ORDER of the upload handler's guards, which needs\n";
echo "                      \$_FILES and a real temporary file to run for real\n";
echo "not proven here:      that a crafted image is refused by getimagesize() and\n";
echo "                      finfo, that the .htaccess is honoured, that Turnstile\n";
echo "                      answers, or that TinyMCE starts on a page with no\n";
echo "                      logged-in user. Every one of those needs the live site.\n\n";

if ( empty( $fails ) ) {
    echo "a stranger can send only what the form offers, and only as a pending row.\n";
    exit( 0 );
}

echo count( $fails ) . " problem(s):\n";
foreach ( $fails as $f ) {
    echo '  - ' . $f . "\n";
}
exit( 1 );
