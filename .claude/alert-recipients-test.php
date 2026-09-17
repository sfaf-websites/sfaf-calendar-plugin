<?php
/**
 * WHO GETS A CALADMIN LINK, IN THE TWO EMAILS THAT CARRY ONE.
 *
 * Both go to the event's notification list, which is gated on NOTHING: it holds
 * contributors, people reached through a team, and free-text addresses that are
 * not accounts at all. Both link to a caladmin screen that is gated. So both are
 * built per recipient, and this proves the routing for each.
 *
 *   registration alert   /caladmin/rsvps?event_id=N   gated on can_view_all
 *   pre-event summary    /caladmin/events/edit/N      gated on can_edit_event
 *
 * THE TWO GATES ARE NOT THE SAME QUESTION, which is why each message names the
 * capability its own link needs rather than sharing one flag. can_edit_event is
 * can_view_all OR being the event's author, so a contributor who created the
 * event keeps the summary's link and a contributor merely added to its list does
 * not. Testing the summary against can_view_all would have passed and been
 * wrong.
 *
 * The rule enforced is one-directional, because only one direction can hurt:
 *
 *     NOBODY WITHOUT THE GATE'S CAPABILITY MAY RECEIVE A caladmin LINK.
 *
 * Sending one is sending somebody a link to a page that will refuse them, which
 * also tells them a screen exists that they are not allowed to see.
 *
 *     php .claude/alert-recipients-test.php
 *
 * WHAT IT STUBS. WordPress, and the classes around the two under test. The real
 * files here are class-sfaf-email.php and class-sfaf-notifications.php: the
 * recipient loops, the capability questions and the builders are the real ones,
 * and wp_mail() is the seam, so what is inspected is the message that would
 * actually have been handed to the mailer.
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
/*
 * THE TEXT PART ARRIVES THROUGH phpmailer_init, NOT THROUGH wp_mail().
 *
 * SFAF_Email::send() hands wp_mail() the HTML and attaches the plain-text
 * alternative as PHPMailer's AltBody from a hook. A stub that swallowed the
 * hook would leave every message here with no text part, and the check that a
 * text/plain reader is no less protected than an HTML one would have been
 * checking nothing. So these stubs do what WordPress does: hold the callback,
 * and fire it against a mailer object at send time.
 */
$GLOBALS['sfaf_mailer_hooks'] = array();
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
    if ( 'phpmailer_init' === $hook ) { $GLOBALS['sfaf_mailer_hooks'][] = $cb; }
    return true;
}
function remove_action( $hook, $cb, $priority = 10 ) {
    if ( 'phpmailer_init' === $hook ) { $GLOBALS['sfaf_mailer_hooks'] = array(); }
    return true;
}

/* The summary's send-once claim. add_post_meta() with $unique = true returns
   false when the row already exists, which is the whole guarantee. */
function add_post_meta( $post_id, $key, $value, $unique = false ) {
    if ( $unique && isset( $GLOBALS['sfaf_claims'][ $key ] ) ) { return false; }
    $GLOBALS['sfaf_claims'][ $key ] = $value;
    return 1;
}
/* THE EVENT'S AUTHOR. user 21 is a contributor who created it, which is the
   case that separates can_edit_event from can_view_all. */
function get_post( $id = null ) {
    return (object) array( 'ID' => 42, 'post_type' => 'uc_event', 'post_author' => 21 );
}

/* wp_mail() IS THE SEAM. Every message the sender hands off lands here, with
   its text alternative pulled through the real AltBody path above. */
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $mailer = new stdClass();
    $mailer->AltBody = '';
    foreach ( (array) $GLOBALS['sfaf_mailer_hooks'] as $cb ) {
        call_user_func( $cb, $mailer );
    }
    $GLOBALS['sfaf_sent'][] = array(
        'to'      => $to,
        'subject' => $subject,
        'html'    => $message,
        'text'    => (string) $mailer->AltBody,
    );
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
 * partner@example.org and editor@sfaf.org.uk are the case a lookup on the
 * address would get wrong: the second HAPPENS to resemble an editor's. Both are
 * strings somebody typed in a box rather than people on this list, so both carry
 * user_id 0 and neither may be offered a caladmin link. That is why the
 * capability is asked of the resolution's user id and never of the address.
 *
 * author@sfaf.org IS THE CASE THAT SEPARATES THE TWO GATES. User 21 is a
 * contributor and created this event, so can_view_all says no and
 * can_edit_event says yes: no RSVP link in the alert, and the summary's link to
 * the event kept. A test that only knew about can_view_all would call the
 * summary correct while it took the link away from the one person most likely
 * to want it.
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
            'author@sfaf.org'      => array( 'label' => 'Wren Diaz', 'user_id' => 21 ),
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
    public static function get_rsvps( $event_id, $status = 'confirmed' ) {
        // The summary refuses to exist with an empty list, so it needs one.
        return array(
            (object) array( 'name' => 'Ana Ruiz', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@example.org', 'created_at' => '2026-08-11 09:00:00' ),
        );
    }
    public static function display_name( $row ) {
        $row = (object) $row;
        $both = trim( ( isset( $row->first_name ) ? $row->first_name : '' ) . ' ' . ( isset( $row->last_name ) ? $row->last_name : '' ) );
        return '' !== $both ? $both : ( isset( $row->name ) ? (string) $row->name : '' );
    }
}
class SFAF_Portal {
    public static function link( $path = '' ) { return 'https://resources.example.org/caladmin/' . ltrim( $path, '/' ); }
    public static function user_can_view_all( $user_id ) {
        return in_array( (int) $user_id, $GLOBALS['sfaf_editors'], true );
    }
    /* The real rule, copied: can_view_all OR the event's author. */
    public static function user_can_edit_event( $user_id, $post ) {
        if ( self::user_can_view_all( $user_id ) ) { return true; }
        $post = is_object( $post ) ? $post : get_post( (int) $post );
        return $post && (int) $post->post_author === (int) $user_id;
    }
}
$GLOBALS['sfaf_editors'] = $EDITORS;
$GLOBALS['sfaf_claims']  = array();

require $root . '/includes/class-sfaf-email.php';

/* CAPACITY, WHICH MOVED INTO THE MAIN FILE IN 3.96.0 (sfaf_event_capacity).
 * Stubbed rather than loaded, because the main file is the whole plugin. The
 * value is the same key this harness has always written, so nothing about the
 * world it builds changes: a non-hybrid event has one capacity and it is
 * `_uc_capacity`. */
function sfaf_event_capacity( $event_id, $format = '' ) {
    return max( 0, (int) get_post_meta( (int) $event_id, '_uc_capacity', true ) );
}
function sfaf_get_rsvp_count_by_format( $event_id, $format ) {
    return 0;
}

/* SFAF_Online, because build_alert() asks whether the event is hybrid
 * before it decides which count to report (3.96.0). The real class, not a
 * stub: a stub answering "not hybrid" would make this harness unable to
 * ever see the branch it is now covering. */
require_once $root . '/includes/class-sfaf-online.php';
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

$fails = array();

/**
 * ONE ROUTINE, BOTH MESSAGES. The rule being enforced is the same rule, so it
 * is written once: a second copy per message is a second thing to remember to
 * update, which is how the summary came to have this fault in the first place
 * while the alert did not.
 *
 * @param string   $what     For the failure text.
 * @param array    $sent     What wp_mail() was handed.
 * @param string[] $may_open Addresses that CAN open the caladmin screen.
 * @param string   $expect   The caladmin path they should be sent to.
 */
function check_routing( $what, $sent, $may_open, $expect, &$fails ) {
    $everyone = SFAF_Reminders::notify_entries( 42 );
    if ( count( $everyone ) !== count( $sent ) ) {
        $fails[] = sprintf( '%s: %d messages for a list of %d people', $what, count( $sent ), count( $everyone ) );
    }

    foreach ( $sent as $msg ) {
        $to = $msg['to'];
        // Both parts. A text/plain reader is not less protected than an HTML
        // one, and the two parts are built separately.
        $both      = $msg['html'] . "\n" . $msg['text'];
        $has_admin = ( false !== strpos( $both, '/caladmin' ) );
        $should    = in_array( $to, $may_open, true );

        // THE RULE. A caladmin link in front of somebody who cannot open it.
        if ( $has_admin && ! $should ) {
            $fails[] = "$what: $to cannot open that screen and was sent a link to it";
        }
        // The other direction: somebody who CAN was sent the public page
        // instead, which is the feature failing rather than leaking.
        if ( ! $has_admin && $should ) {
            $fails[] = "$what: $to can open that screen and was not offered it";
        }
        if ( $should && false === strpos( $msg['html'], $expect ) ) {
            $fails[] = "$what: $to was linked somewhere in caladmin other than $expect";
        }
        if ( $should && false === strpos( $msg['text'], $expect ) ) {
            $fails[] = "$what: $to has the link in the HTML part and not in the text part";
        }
        if ( ! $should && false === strpos( $msg['html'], 'https://resources.example.org/events/' ) ) {
            $fails[] = "$what: $to was sent no link at all; the public event page is what they get";
        }
    }

    // Nobody was mailed twice: the list is keyed on the address and stays that
    // way through a per-recipient build.
    $addresses = array();
    foreach ( $sent as $msg ) { $addresses[] = $msg['to']; }
    if ( count( $addresses ) !== count( array_unique( $addresses ) ) ) {
        $fails[] = "$what: an address was mailed more than once";
    }
}

/* =========================================================================
 * (1) THE REGISTRATION ALERT. /caladmin/rsvps, gated on can_view_all.
 *
 * The two editors, and nobody else. The contributor who AUTHORED the event is
 * not on this list: authorship does not open the RSVP screen.
 * ====================================================================== */
$GLOBALS['sfaf_sent'] = array();
$sent_count = SFAF_Notifications::send_alert( 42, $person );

check_routing(
    'alert',
    $GLOBALS['sfaf_sent'],
    array( 'editor@sfaf.org', 'teameditor@sfaf.org' ),
    '/caladmin/rsvps?event_id=42',
    $fails
);

if ( 7 !== $sent_count ) {
    $fails[] = "alert: send_alert() reported $sent_count sends and the list has 7 people on it";
}
foreach ( $GLOBALS['sfaf_sent'] as $msg ) {
    // Every recipient is still told the same facts about the registration.
    if ( false === strpos( $msg['html'], 'Ana Ruiz' ) ) {
        $fails[] = 'alert: ' . $msg['to'] . ' was not told who registered';
    }
    if ( false === strpos( $msg['subject'], 'New registration' ) ) {
        $fails[] = 'alert: ' . $msg['to'] . ' got the wrong subject';
    }
    /* THE COUNT IN THE MESSAGE. A real registration is one that has been
       written, so the alert built after the insert reads one higher than the
       empty room. The stub returns 1, and this is what the "0 of 12" test
       message was failing to be. */
    if ( false === strpos( $msg['html'], '1 of 12 places taken' ) ) {
        $fails[] = 'alert: ' . $msg['to'] . ' was not told the registration being announced counts';
    }
}

/* =========================================================================
 * (2) THE PRE-EVENT SUMMARY. /caladmin/events/edit/N, gated on can_edit_event.
 *
 * The two editors AND the contributor who created the event. That third
 * address is the whole reason this is a separate capability: checking
 * can_view_all here would pass every assertion above and still have taken the
 * link away from them.
 * ====================================================================== */
$GLOBALS['sfaf_sent']   = array();
$GLOBALS['sfaf_claims'] = array();
$summary = SFAF_Notifications::send_summary_for_event( 42 );

check_routing(
    'summary',
    $GLOBALS['sfaf_sent'],
    array( 'editor@sfaf.org', 'teameditor@sfaf.org', 'author@sfaf.org' ),
    '/caladmin/events/edit/42',
    $fails
);

if ( 7 !== $summary['sent'] || 7 !== $summary['recipients'] ) {
    $fails[] = sprintf( 'summary: reported %d sent to %d recipients, and the list has 7 people on it',
        $summary['sent'], $summary['recipients'] );
}
foreach ( $GLOBALS['sfaf_sent'] as $msg ) {
    // Everybody gets the same list of who is coming. The link is the only
    // thing that differs; the content is not rationed by capability.
    if ( false === strpos( $msg['html'], 'Ana Ruiz' ) ) {
        $fails[] = 'summary: ' . $msg['to'] . ' was not told who is coming';
    }
    if ( false === strpos( $msg['subject'], 'Starting soon' ) ) {
        $fails[] = 'summary: ' . $msg['to'] . ' got the wrong subject';
    }
}

/* THE SEND-ONCE CLAIM SURVIVED THE PER-RECIPIENT REWRITE. The claim is taken
   once for the event, not once per variant, so a second run sends nothing. */
$GLOBALS['sfaf_sent'] = array();
$again = SFAF_Notifications::send_summary_for_event( 42 );
if ( 'already sent' !== $again['skipped'] || $GLOBALS['sfaf_sent'] ) {
    $fails[] = 'summary: a second run sent it again; the claim no longer holds';
}

/* THE ALERT'S PER-EVENT SWITCH STILL SWITCHES IT OFF, for everybody, before any
   recipient is resolved. (The summary's switch is applied a level up, by
   summary_due_events(), which decides whether the event is due at all.) */
$GLOBALS['sfaf_meta']['_uc_notify_off'] = array( 'alert' );
$GLOBALS['sfaf_sent'] = array();
if ( 0 !== SFAF_Notifications::send_alert( 42, $person ) || $GLOBALS['sfaf_sent'] ) {
    $fails[] = 'alert: sent for an event that has alerts switched off';
}

echo "Caladmin links in email: who gets one\n";
echo "recipients: 7 (two editors, two contributors, the contributor who created the event,\n";
echo "            and two typed addresses, one of which resembles an account)\n";
echo "messages:   the registration alert (/caladmin/rsvps, can_view_all) and the pre-event\n";
echo "            summary (/caladmin/events/edit/N, can_edit_event)\n";
echo "checked:    no caladmin link in either part reaches anybody without that screen's\n";
echo "            capability, everybody with it gets the right screen in both parts, everybody\n";
echo "            else gets the public page, nobody is mailed twice, the alert counts the\n";
echo "            registration it announces, the summary's send-once claim still holds, and\n";
echo "            the per-event off switch still works\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "every recipient got the link they can actually open, in both messages.\n";
exit( 0 );
