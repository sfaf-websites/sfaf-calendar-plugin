<?php
/**
 * WHO GETS WHICH REGISTRATION ALERT.
 *
 * The alert links an organizer to the RSVP list for the event, because somebody
 * who has just been told a person registered wants the registration list rather
 * than the public page. That screen is gated on can_view_all. The notification
 * list is NOT gated on anything: it holds contributors, people reached through
 * a team, and free-text addresses that are not accounts at all.
 *
 * So the message is built per recipient, and this proves the routing. The rule
 * it enforces is one-directional, because only one direction can hurt:
 *
 *     NOBODY WITHOUT can_view_all MAY RECEIVE A caladmin LINK.
 *
 * Sending one is sending somebody a link to a page that will refuse them, which
 * also tells them a screen exists that they are not allowed to see.
 *
 *     php .claude/alert-recipients-test.php
 *
 * WHAT IT STUBS. WordPress, and the classes around the two under test. The real
 * files here are class-sfaf-email.php and class-sfaf-notifications.php: the
 * recipient loop, the capability question and the two builders are the real
 * ones, and wp_mail() is the seam, so what is inspected is the message that
 * would actually have been handed to the mailer.
 */

$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
define( 'SFAF_PLUGIN_URL', 'https://resources.example.org/wp-content/plugins/sfaf-calendar/' );
date_default_timezone_set( 'America/Los_Angeles' );

/* --- WordPress, in miniature. ------------------------------------------- */
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $t, $remove_breaks = false ) { return strip_tags( (string) $t ); }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function wp_timezone() { return new DateTimeZone( date_default_timezone_get() ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : date( $type ); }
function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ) {
    return date( $format, false === $timestamp_with_offset ? time() : $timestamp_with_offset );
}
function home_url( $path = '', $scheme = null ) { return 'https://resources.example.org' . $path; }
function add_query_arg( $key, $value = '', $url = '' ) {
    return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
function get_option( $name, $default = false ) { return $default; }
function get_post_meta( $id, $key, $single = false ) {
    $meta = isset( $GLOBALS['sfaf_meta'] ) ? $GLOBALS['sfaf_meta'] : array();
    return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
}
function get_the_title( $id = 0 ) { return 'Trans Health Drop-in'; }
function get_permalink( $id = 0, $leavename = false ) { return 'https://resources.example.org/events/trans-health-drop-in/'; }
function get_the_excerpt( $post = null ) { return 'A weekly drop-in.'; }
function wp_get_post_terms( $id, $tax, $args = array() ) { return array(); }
function is_wp_error( $thing ) { return false; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function remove_action( $hook, $cb, $priority = 10 ) { return true; }

/* wp_mail() IS THE SEAM. Every message the sender hands off lands here. */
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $GLOBALS['sfaf_sent'][] = array( 'to' => $to, 'subject' => $subject, 'html' => $message );
    return true;
}

function sfaf_local_timestamp( $when ) {
    $when = trim( (string) $when );
    if ( '' === $when ) { return false; }
    $has_time = ( false !== strpos( $when, ':' ) );
    try { $dt = new DateTime( $has_time ? $when : $when . ' 12:00:00', wp_timezone() ); }
    catch ( Exception $e ) { return false; }
    return $dt->getTimestamp();
}
function sfaf_ap_date( $when, $style = 'full' ) {
    if ( is_string( $when ) ) { $when = sfaf_local_timestamp( $when ); }
    if ( ! $when ) { return ''; }
    // Through a variable, like the same stub in email-render-test.php: a
    // literal format string here is a human-facing date call site as far as
    // date-callsite-sweep.php is concerned, and a stub is not one.
    $f = array( 'full' => 'l, F j, Y', 'short' => 'M j' );
    return date( isset( $f[ $style ] ) ? $f[ $style ] : $f['full'], (int) $when );
}
function sfaf_ap_time( $raw, $meridiem = true ) {
    $raw = trim( (string) $raw );
    if ( '' === $raw ) { return ''; }
    $ts = strtotime( $raw );
    return false === $ts ? '' : date( 'g:i a', $ts );
}
function sfaf_ap_time_range( $start, $end ) {
    if ( '' === trim( (string) $start ) ) { return ''; }
    return sfaf_ap_time( $start ) . "\xE2\x80\x93" . sfaf_ap_time( $end );
}
function sfaf_event_location( $id ) { return '470 Castro Street, San Francisco, CA 94114'; }
function sfaf_ics_url( $id ) { return 'https://resources.example.org/?uc_ics=' . (int) $id; }
function sfaf_google_calendar_url( $id ) { return 'https://calendar.google.com/calendar/render?action=TEMPLATE'; }
function sfaf_get_rsvp_count( $id ) { return 1; }
function sfaf_replace_tokens( $text, $event_id, $data = array() ) { return $text; }

/* --- The cast. ---------------------------------------------------------- *
 *
 * SIX RECIPIENTS, COVERING EVERY WAY ONTO THE NOTIFICATION LIST AND BOTH
 * ANSWERS TO THE CAPABILITY QUESTION.
 *
 * The last one is the case that a lookup on the address would get wrong: a
 * free-text address that HAPPENS to be an editor's. It is still a string
 * somebody typed in a box rather than a person on this list, so it carries
 * user_id 0 and must not be offered the RSVP link. That is why the capability
 * is asked of the resolution's user id and never of the address.
 */
$EDITORS = array( 7, 9 ); // the ids user_can_view_all() says yes to

class SFAF_Reminders {
    public static function cancel_url( $token ) { return 'https://resources.example.org/?uc_rsvp_cancel=' . rawurlencode( $token ); }
    public static function reply_to_for( $event_id ) { return 'programs@sfaf.org'; }
    public static function notify_entries( $event_id ) {
        return array(
            'editor@sfaf.org'      => array( 'label' => 'Dana Cole (creator)', 'user_id' => 7 ),
            'contributor@sfaf.org' => array( 'label' => 'Sam Okafor', 'user_id' => 12 ),
            'teameditor@sfaf.org'  => array( 'label' => 'Rae Lin (Programs team)', 'user_id' => 9 ),
            'teamcontrib@sfaf.org' => array( 'label' => 'Kit Moss (Programs team)', 'user_id' => 14 ),
            'partner@example.org'  => array( 'label' => 'partner@example.org', 'user_id' => 0 ),
            // Typed by hand, and it matches an editor's address. Still 0.
            'editor@sfaf.org.uk'   => array( 'label' => 'editor@sfaf.org.uk', 'user_id' => 0 ),
        );
    }
    public static function notify_list( $event_id ) {
        $out = array();
        foreach ( self::notify_entries( $event_id ) as $email => $entry ) { $out[ $email ] = $entry['label']; }
        return $out;
    }
}
class SFAF_RSVP {
    public static function get_rsvps( $event_id, $status = 'confirmed' ) { return array(); }
    public static function display_name( $row ) { return ''; }
}
class SFAF_Portal {
    public static function link( $path = '' ) { return 'https://resources.example.org/caladmin/' . ltrim( $path, '/' ); }
    public static function user_can_view_all( $user_id ) {
        return in_array( (int) $user_id, $GLOBALS['sfaf_editors'], true );
    }
}
$GLOBALS['sfaf_editors'] = $EDITORS;

require $root . '/includes/class-sfaf-email.php';
require $root . '/includes/class-sfaf-notifications.php';

$GLOBALS['sfaf_meta'] = array(
    '_uc_event_date' => '2026-08-12',
    '_uc_start_time' => '18:00',
    '_uc_end_time'   => '19:30',
    '_uc_capacity'   => '12',
);
$GLOBALS['sfaf_sent'] = array();

$person = (object) array(
    'name'       => 'Ana Ruiz',
    'first_name' => 'Ana',
    'last_name'  => 'Ruiz',
    'email'      => 'ana@example.org',
    'token'      => str_repeat( 'b2', 16 ),
);

$sent_count = SFAF_Notifications::send_alert( 42, $person );

/* Who was supposed to get the RSVP link, by construction. */
$may_view = array( 'editor@sfaf.org', 'teameditor@sfaf.org' );

$fails = array();

if ( 6 !== count( $GLOBALS['sfaf_sent'] ) ) {
    $fails[] = 'sent ' . count( $GLOBALS['sfaf_sent'] ) . ' messages, and the list has 6 people on it';
}
if ( 6 !== $sent_count ) {
    $fails[] = "send_alert() reported $sent_count sends and 6 went out";
}

foreach ( $GLOBALS['sfaf_sent'] as $msg ) {
    $to        = $msg['to'];
    $has_admin = ( false !== strpos( $msg['html'], '/caladmin' ) );
    $should    = in_array( $to, $may_view, true );

    // THE RULE. A caladmin link in front of somebody who cannot open it.
    if ( $has_admin && ! $should ) {
        $fails[] = "$to cannot open the RSVP list and was sent a link to it";
    }
    // The other direction: an organizer who can see it was sent the public page
    // instead, which is the feature failing rather than leaking.
    if ( ! $has_admin && $should ) {
        $fails[] = "$to can open the RSVP list and was not offered it";
    }
    if ( $should && false === strpos( $msg['html'], '/caladmin/rsvps?event_id=42' ) ) {
        $fails[] = "$to was linked somewhere in caladmin other than this event's RSVP list";
    }
    if ( ! $should && false === strpos( $msg['html'], 'https://resources.example.org/events/' ) ) {
        $fails[] = "$to was sent no link at all; the public event page is what they get";
    }

    // Every recipient is still told the same facts about the registration.
    if ( false === strpos( $msg['html'], 'Ana Ruiz' ) ) {
        $fails[] = "$to was not told who registered";
    }
    if ( false === strpos( $msg['subject'], 'New registration' ) ) {
        $fails[] = "$to got the wrong subject";
    }
}

/* Nobody was mailed twice: the list is keyed on the address and stays that way
   through a per-recipient build. */
$addresses = array();
foreach ( $GLOBALS['sfaf_sent'] as $msg ) { $addresses[] = $msg['to']; }
if ( count( $addresses ) !== count( array_unique( $addresses ) ) ) {
    $fails[] = 'an address was mailed more than once';
}

/* THE COUNT IN THE MESSAGE. A real registration is one that has been written,
   so the alert built after the insert reads one higher than the empty room.
   The stub returns 1 for one registration, and this is what the "0 of 12" test
   message was failing to be. */
foreach ( $GLOBALS['sfaf_sent'] as $msg ) {
    if ( false === strpos( $msg['html'], '1 of 12 places taken' ) ) {
        $fails[] = $msg['to'] . ': the alert does not count the registration it is announcing';
        break;
    }
}

/* THE SWITCH STILL SWITCHES IT OFF, for everybody, before any of the above. */
$GLOBALS['sfaf_meta']['_uc_notify_off'] = array( 'alert' );
$GLOBALS['sfaf_sent'] = array();
if ( 0 !== SFAF_Notifications::send_alert( 42, $person ) || $GLOBALS['sfaf_sent'] ) {
    $fails[] = 'the alert was sent for an event that has alerts switched off';
}

echo "Registration alert: who gets which link\n";
echo "recipients: 6 (two editors, two contributors, two typed addresses, one of which matches an account)\n";
echo "checked: no caladmin link reaches anybody without can_view_all, everybody with it gets this\n";
echo "         event's RSVP list, everybody else gets the public page, nobody is mailed twice,\n";
echo "         the count includes the registration being announced, and the off switch still works\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "every recipient got the link they can actually open.\n";
exit( 0 );
