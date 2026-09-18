<?php
/**
 * DOES THE MEETING LINK REACH THE PERSON WHO REGISTERED ONLINE?
 *
 *     php .claude/hybrid-link-delivery-test.php
 *     php .claude/hybrid-link-delivery-test.php --self-test
 *
 * THE GATE WAS NEVER THE PROBLEM. `SFAF_Online::joining_html()` refuses the
 * link unless the recipient's format is `online`, and that is correct. What was
 * wrong is that NOBODY EVER TOLD IT THE FORMAT: the person object assembled by
 * `SFAF_RSVP::route_submission()` carried five fields and `format` was not one
 * of them, so `person_format()` read nothing and every recipient of every
 * hybrid event looked like "the event never asked".
 *
 * THAT IS WHY READING THE GATE FINDS NOTHING. Each end is right on its own.
 * The fault is the JOIN between them, and a join is only visible to a test that
 * runs one end into the other.
 *
 * SO THIS DRIVES THE REAL `route_submission()`, with `SFAF_Notifications`
 * replaced by a recorder, and asks what the person object actually contained.
 * Then it feeds that SAME shape to the REAL builders and reads the message. A
 * test that built its own person would have asserted the gate and shipped the
 * bug, which is exactly what the existing hybrid suite did.
 *
 * FOUR CASES, THREE SURFACES. The email, the calendar file it offers, and the
 * morning-of reminder, each for: hybrid registered online, hybrid registered in
 * person, a plain online event, and an in-person event.
 */

$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
define( 'SFAF_PLUGIN_URL', 'https://resources.example.org/wp-content/plugins/sfaf-calendar/' );
date_default_timezone_set( 'America/Los_Angeles' );

$self_test = in_array( '--self-test', $argv, true );
$fails     = array();
function check( $ok, $why ) {
    global $fails;
    if ( ! $ok ) { $fails[] = $why; }
}

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Signatures match the real ones, optional arguments
 * included: a stub taking fewer arguments is read by the callable audit as the
 * definition, and it then reports every correct call site as an arity error.
 * ------------------------------------------------------------------------ */
$GLOBALS['sfaf_meta']    = array();
$GLOBALS['sfaf_options'] = array();

function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $u ) { return (string) $u; }
function wp_strip_all_tags( $t, $remove_breaks = false ) { return strip_tags( (string) $t ); }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function wp_timezone() { return new DateTimeZone( date_default_timezone_get() ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : date( $type ); }
function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ) {
    return date( $format, false === $timestamp_with_offset ? time() : $timestamp_with_offset );
}
function home_url( $path = '', $scheme = null ) { return 'https://resources.example.org' . $path; }
function add_query_arg( $key, $value = '', $url = '' ) {
    return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' )
         . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
}
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['sfaf_options'] ) ? $GLOBALS['sfaf_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) { $GLOBALS['sfaf_options'][ $name ] = $value; return true; }
function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['sfaf_meta'][ $key ] ) ? $GLOBALS['sfaf_meta'][ $key ] : '';
}
function update_post_meta( $id, $key, $v ) { $GLOBALS['sfaf_meta'][ $key ] = $v; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['sfaf_meta'][ $key ] ); return true; }
function get_the_title( $id = 0 ) { return 'Programa Latino: Grupo de Apoyo'; }
function get_permalink( $id = 0, $leavename = false ) { return 'https://resources.example.org/events/grupo/'; }
function get_post( $id = null, $output = OBJECT, $filter = 'raw' ) { return null; }
function sanitize_title( $t, $fallback = '', $context = 'save' ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $t ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function sfaf_flatten_html( $h ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $h ) ) ); }
function wp_salt( $scheme = 'auth' ) { return 'a-fixed-salt-for-the-harness'; }
function do_action( $tag, ...$args ) {}
function apply_filters( $tag, $value, ...$args ) { return $value; }

if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

/* The event's own facts, which the builders read through these. */
/*
 * MIRRORS THE REAL ONE'S FIRST BRANCH, which is the only part of it this test
 * depends on: a purely online event has no place and returns the label, and a
 * HYBRID one keeps its address, which is the whole reason hybrid is not a tick
 * on top of online. A stub that returned the address unconditionally reported
 * a plain online event as leaking a street address, which is the harness being
 * wrong rather than the code.
 */
function sfaf_event_location( $id ) {
    if ( SFAF_Online::is_online( (int) $id ) ) {
        return SFAF_Online::LABEL;
    }
    return '470 Castro Street, San Francisco, CA 94114';
}
function sfaf_event_datetimes( $id ) {
    $tz = wp_timezone();
    return array( new DateTime( '2026-10-14 18:00:00', $tz ), new DateTime( '2026-10-14 19:30:00', $tz ) );
}
function sfaf_ap_date( $d, $style = 'full' ) { return 'Wednesday, Oct. 14, 2026'; }
function sfaf_ap_time_range( $s, $e ) { return '6 to 7:30 p.m.'; }
function sfaf_ics_url( $id ) { return 'https://resources.example.org/?uc_ics=' . (int) $id; }
function sfaf_google_calendar_url( $id ) { return 'https://calendar.google.com/calendar/render?x=1'; }
function sfaf_event_capacity( $id, $format = '' ) { return 0; }
function sfaf_get_rsvp_count( $id ) { return 3; }
function sfaf_get_rsvp_count_by_format( $id, $format ) { return 1; }
function sfaf_event_formats( $id ) {
    $mode = SFAF_Online::mode( (int) $id );
    if ( SFAF_Online::MODE_HYBRID === $mode ) {
        return array( SFAF_Online::MODE_IN_PERSON, SFAF_Online::MODE_ONLINE );
    }
    return array( $mode );
}
function sfaf_event_format_line( $id ) { return SFAF_Online::is_hybrid( (int) $id ) ? 'In person and online' : ''; }
function sfaf_event_cancelled( $id ) { return false; }
function sfaf_show_feature( $id, $what ) { return true; }

class SFAF_Venues { public static function set_for_event( $post_id, $term_id ) {} }
function sfaf_location_part_keys() { return array( '_uc_location_street' ); }

class SFAF_Portal { public static function link( $what = '' ) { return 'https://resources.example.org/caladmin/' . $what; } }
class SFAF_Privacy { public static function is_private( $id ) { return false; } }

/*
 * THE CANCEL LINK AND THE REPLY-TO, AND NOTHING THIS TEST IS ABOUT. Stubbed
 * because SFAF_Reminders drags in $wpdb; it touches neither the format nor the
 * joining block, so it cannot uphold the rule under test on the real code's
 * behalf. The reminder's OWN two faults are asserted against the real file as
 * TEXT further down, which is why this stand-in does not hide them.
 */
class SFAF_Reminders {
    public static function cancel_url( $token ) { return 'https://resources.example.org/?uc_cancel=' . rawurlencode( (string) $token ); }
    public static function notify_list( $event_id ) { return array(); }
    public static function notify_entries( $event_id ) { return array(); }
    public static function reply_to_for( $event_id ) { return 'websites@sfaf.org'; }
}

require_once $root . '/includes/class-sfaf-email.php';
require_once $root . '/includes/class-sfaf-online.php';
require_once $root . '/includes/class-sfaf-notifications.php';

$EVENT = 42;
$LINK  = 'https://zoom.us/j/98765432100';

/* ---------------------------------------------------------------------------
 * THE RECORDER. It stands in for SFAF_Notifications only where route_submission
 * CALLS it, so the person object that the real method assembles is captured
 * exactly as the real builders would have received it.
 * ------------------------------------------------------------------------ */
class Recorder {
    public static $confirmation = null;
    public static $alert        = null;
    public static function reset() { self::$confirmation = null; self::$alert = null; }
}

/* ---------------------------------------------------------------------------
 * 1. THE JOIN: what does route_submission() actually hand over?
 *
 * THE REAL METHOD, read off disk and rebound onto a stand-in class, so this
 * asserts the SHIPPED code rather than a copy of it. Slicing the method text
 * out of the file is what makes the assertion real: rewriting it here would
 * pass forever.
 * ------------------------------------------------------------------------ */
$rsvp_src = file_get_contents( $root . '/includes/class-sfaf-rsvp.php' );

$from = strpos( $rsvp_src, 'public function route_submission( $rsvp_id, $data ) {' );
check( false !== $from, 'route_submission() could not be found, so the join was never tested' );

if ( false !== $from ) {
    /* Brace-match to the end of the method, so the slice is the whole of it. */
    $depth = 0; $end = $from;
    for ( $i = $from; $i < strlen( $rsvp_src ); $i++ ) {
        if ( '{' === $rsvp_src[ $i ] ) { $depth++; }
        if ( '}' === $rsvp_src[ $i ] ) { $depth--; if ( 0 === $depth ) { $end = $i; break; } }
    }
    $method = substr( $rsvp_src, $from, $end - $from + 1 );

    /* The two sends become recorder calls; nothing else about the method
     * changes, so the person object is assembled by the shipped lines. */
    $method = str_replace(
        'SFAF_Notifications::send_confirmation( $event_id, $person );',
        'Recorder::$confirmation = $person;',
        $method
    );
    $method = str_replace(
        'SFAF_Notifications::send_alert( $event_id, $person );',
        'Recorder::$alert = $person;',
        $method
    );
    $method = str_replace(
        'self::confirmations_enabled()',
        'true',
        $method
    );
    $method = str_replace(
        "SFAF_Notifications::on( \$event_id, 'confirmation' )",
        'true',
        $method
    );

    eval( 'class RouteProbe { ' . $method . ' }' );
}

/* HYBRID, REGISTERED ONLINE. The one person the link exists for. */
$GLOBALS['sfaf_meta'] = array();
SFAF_Online::set( $EVENT, SFAF_Online::MODE_HYBRID, $LINK, array( 'confirmation', 'reminder' ) );

Recorder::reset();
$probe = new RouteProbe();
$probe->route_submission( 7, array(
    'event_id'   => $EVENT,
    'name'       => 'Lee Ramirez',
    'first_name' => 'Lee',
    'last_name'  => 'Ramirez',
    'email'      => 'lee@example.org',
    'phone'      => '',
    'token'      => 'tok-abc',
    'format'     => SFAF_Online::MODE_ONLINE,
) );

$p = Recorder::$confirmation;
check( is_object( $p ), 'route_submission() sent no confirmation at all' );
check( is_object( $p ) && isset( $p->format ),
    'THE PERSON OBJECT CARRIES NO FORMAT. Every hybrid registrant reaches the builders looking like somebody whose event never asked, so the gate refuses the link to the one person it is for' );
check( is_object( $p ) && isset( $p->format ) && SFAF_Online::MODE_ONLINE === (string) $p->format,
    'the format on the person object is not the one that was registered' );

/* AND THE ALERT GETS THE SAME OBJECT, which is what lets it name the format and
 * count that format's places rather than the whole event's. */
$a = Recorder::$alert;
check( is_object( $a ) && isset( $a->format ) && SFAF_Online::MODE_ONLINE === (string) $a->format,
    "the registration alert is not told the registrant's format, so it reports the room's capacity at somebody joining by link" );

/* ---------------------------------------------------------------------------
 * 2. THE FOUR CASES, ON THREE SURFACES.
 *
 * Each case sets the event up, builds the message through the REAL builders,
 * and reads the result. The person object is the one shape route_submission()
 * produces, so surface and join are tested together.
 * ------------------------------------------------------------------------ */
function person( $format ) {
    return (object) array(
        'name'       => 'Lee Ramirez',
        'first_name' => 'Lee',
        'last_name'  => 'Ramirez',
        'email'      => 'lee@example.org',
        'token'      => 'tok-abc',
        'format'     => (string) $format,
    );
}

function setup( $mode, $link ) {
    global $EVENT;
    $GLOBALS['sfaf_meta'] = array();
    SFAF_Online::set( $EVENT, $mode, $link, array( 'confirmation', 'reminder' ) );
}

$ADDRESS = '470 Castro Street';

$cases = array(
    /* label, mode, registered format, wants link, wants address */
    array( 'hybrid registered ONLINE',    SFAF_Online::MODE_HYBRID,    SFAF_Online::MODE_ONLINE,    true,  false ),
    array( 'hybrid registered IN PERSON', SFAF_Online::MODE_HYBRID,    SFAF_Online::MODE_IN_PERSON, false, true  ),
    array( 'a plain ONLINE event',        SFAF_Online::MODE_ONLINE,    '',                          true,  false ),
    array( 'an IN PERSON event',          SFAF_Online::MODE_IN_PERSON, '',                          false, true  ),
);

foreach ( $cases as $case ) {
    list( $label, $mode, $format, $wants_link, $wants_address ) = $case;

    /* An in-person event has no link to withhold, so it is set up without one
     * and the assertion is that nothing invents a joining block. */
    setup( $mode, SFAF_Online::MODE_IN_PERSON === $mode ? '' : $LINK );

    foreach ( array( 'confirmation', 'reminder' ) as $kind ) {
        $built = SFAF_Notifications::build( $kind, $EVENT, person( $format ) );
        check( is_array( $built ) && isset( $built['html'], $built['text'] ),
            $label . ': the ' . $kind . ' did not build at all' );
        if ( ! is_array( $built ) ) { continue; }

        $has_link_html = ( false !== strpos( $built['html'], $LINK ) );
        $has_link_text = ( false !== strpos( $built['text'], $LINK ) );
        $has_addr_html = ( false !== strpos( $built['html'], $ADDRESS ) );

        /* BOTH PARTS, ALWAYS. A link present in the HTML and absent from the
         * text is the same failure for anybody reading the plain part, and a
         * link present in the TEXT of a message that withheld it from the HTML
         * is the credential leaking through the half nobody looks at. */
        check( $wants_link === $has_link_html,
            $label . ': the ' . $kind . ' HTML ' . ( $wants_link ? 'does NOT carry' : 'CARRIES' ) . ' the meeting link' );
        check( $wants_link === $has_link_text,
            $label . ': the ' . $kind . ' TEXT ' . ( $wants_link ? 'does NOT carry' : 'CARRIES' ) . ' the meeting link' );
        check( $wants_address === $has_addr_html,
            $label . ': the ' . $kind . ' ' . ( $wants_address ? 'does NOT carry' : 'CARRIES' ) . ' the street address' );
    }

    /* THE CALENDAR FILE THE CONFIRMATION OFFERS. The link rides on a token, so
     * what is asserted is whether one was minted: no token means the endpoint
     * hands back the ordinary public file. */
    $ics    = SFAF_Online::ics_url_with_link( $EVENT, $format );
    $tokened = ( false !== strpos( $ics, SFAF_Online::ICS_JOIN_ARG . '=' ) );
    check( $wants_link === $tokened,
        $label . ': the calendar file ' . ( $wants_link ? 'is NOT given' : 'IS given' ) . ' the join token' );
}

/* ---------------------------------------------------------------------------
 * 3. THE REMINDER'S OWN RECIPIENT LIST.
 *
 * The reminder builds its person in SFAF_Reminders::send_one(), off a row from
 * recipients(), and BOTH had to learn the format: a query that does not select
 * it cannot pass it on, and 3.96.0 changed neither. Asserted on the source,
 * because there is no database here.
 * ------------------------------------------------------------------------ */
$rem = file_get_contents( $root . '/includes/class-sfaf-reminders.php' );

check( (bool) preg_match( '/SELECT email, format FROM/', $rem ),
    'the reminder list does not select the format column, so it has nothing to pass on however the person object is built' );

/*
 * INSIDE send_one(), NOT ANYWHERE IN THE FILE.
 *
 * THIS ASSERTION WAS WRONG THE FIRST TIME AND A PLANT CAUGHT IT. It matched
 * `'format' =>` across the whole file, and recipients() carries one too, so
 * taking the field OFF THE PERSON OBJECT left the pattern matching and the
 * plant walked through. Presence somewhere is not the claim; the claim is that
 * the object handed to the builder has the field, which is a relationship
 * between a key and the function it sits in.
 */
$so_at = strpos( $rem, 'private static function send_one(' );
check( false !== $so_at, 'send_one() could not be found, so the reminder person was never tested' );
if ( false !== $so_at ) {
    $depth = 0; $so_end = $so_at;
    for ( $i = $so_at; $i < strlen( $rem ); $i++ ) {
        if ( '{' === $rem[ $i ] ) { $depth++; }
        if ( '}' === $rem[ $i ] ) { $depth--; if ( 0 === $depth ) { $so_end = $i; break; } }
    }
    $so_body = substr( $rem, $so_at, $so_end - $so_at + 1 );
    check( (bool) preg_match( "/'format'\s*=>\s*\(string\) \\\$format,/", $so_body ),
        'the reminder builds a person with no format, so a hybrid event never sends its link on the morning of' );
    check( (bool) preg_match( '/function send_one\( \$event_id, \$email, \$type, \$token, \$format/', $so_body ),
        'send_one() is no longer given the format, so it has nothing to put on the person whatever the query selected' );
}

/* AND THE LOOP HAS TO PASS IT. A parameter with a default is silently accepted
 * by every caller that forgets it, which is the shape that makes this fail
 * quietly rather than loudly. */
check( (bool) preg_match( "/self::send_one\( \\\$event_id, \\\$email, \\\$type, \\\$claim\['token'\], \\\$format \)/", $rem ),
    'the reminder loop calls send_one() without the format, so the default empty string wins and no link is ever sent' );

/* ---------------------------------------------------------------------------
 * 4. THE GATE ITSELF IS UNCHANGED.
 *
 * Widening WHO is told the format must not widen WHO is handed the link. Both
 * gates still ask for `online` explicitly and still treat an empty format as
 * "the event never asked" rather than as an in-person answer.
 * ------------------------------------------------------------------------ */
$on = file_get_contents( $root . '/includes/class-sfaf-online.php' );
check( 3 === preg_match_all( '/if \( self::is_hybrid\( \$event_id \) && self::MODE_ONLINE !== \(string\) \$format \) \{/', $on ),
    'one of the three link gates changed shape; there should be exactly three, in joining_html, joining_text and ics_url_with_link' );

/* ---------------------------------------------------------------------------
 * THE SELF-TEST. A reader that cannot see the fault reports none.
 * ------------------------------------------------------------------------ */
if ( $self_test ) {
    $probe_fails = array();
    /* A person with no format is the bug, and every link assertion above must
     * fail for the hybrid-online case when it is handed one. */
    setup( SFAF_Online::MODE_HYBRID, $LINK );
    $blind = (object) array( 'name' => 'Lee', 'first_name' => 'Lee', 'email' => 'lee@example.org', 'token' => 't' );
    $built = SFAF_Notifications::build( 'confirmation', $EVENT, $blind );
    if ( false !== strpos( $built['html'], $LINK ) ) {
        $probe_fails[] = 'a person object with no format still received the link, so the gate is not gating';
    }
    $ics = SFAF_Online::ics_url_with_link( $EVENT, '' );
    if ( false !== strpos( $ics, SFAF_Online::ICS_JOIN_ARG . '=' ) ) {
        $probe_fails[] = 'a person with no format still got a tokened calendar file';
    }
    /* And the opposite: told the format, it must be handed over. */
    $built = SFAF_Notifications::build( 'confirmation', $EVENT, person( SFAF_Online::MODE_ONLINE ) );
    if ( false === strpos( $built['html'], $LINK ) ) {
        $probe_fails[] = 'an online registrant was refused the link, so the reader cannot tell the two apart';
    }
    if ( $probe_fails ) {
        echo "SELF-TEST FAILED\n";
        foreach ( $probe_fails as $f ) { echo '  . ' . $f . "\n"; }
        exit( 1 );
    }
    echo "self-test passed: the reader tells a person with a format from one without.\n";
}

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "the meeting link reaches the online registrant and nobody else, on the email,\n";
echo "the calendar file and the morning-of reminder.\n";
exit( 0 );
