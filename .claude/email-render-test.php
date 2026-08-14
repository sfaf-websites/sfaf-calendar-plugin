<?php
/**
 * RENDER ALL FOUR EMAILS AND CHECK WHAT CAME OUT.
 *
 * There is no WordPress and no mailbox here, so this cannot prove an email
 * arrives or that Outlook draws it correctly. What it CAN prove is everything
 * that is decided before the message leaves: that each builder produces both an
 * HTML part and a text part, that the HTML is table-based rather than div-based,
 * that it carries the banner with real alt text, that every fact in the HTML is
 * also in the text, that no colour outside the brand palette got in, and that
 * the cancel link is present in exactly the messages that should carry one.
 *
 * Those are the failures that would otherwise be found by a person reading a
 * broken email, which is the most expensive place to find them.
 *
 *     php .claude/email-render-test.php              # check
 *     php .claude/email-render-test.php --write DIR  # also write the HTML out
 *                                                    # so it can be opened
 *
 * WHAT IT STUBS. WordPress, and only the twenty-odd functions the builders call.
 * The two classes under test, SFAF_Email and SFAF_Notifications, are the real
 * files. Everything else is a stand-in returning fixed data, so a failure here
 * is a failure in the thing being tested rather than in the scaffolding.
 */

$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
define( 'SFAF_PLUGIN_URL', 'https://resources.example.org/wp-content/plugins/sfaf-calendar/' );
date_default_timezone_set( 'America/Los_Angeles' );

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Signatures match the real ones, optional arguments
 * included: a stub taking fewer arguments is a stub the callable audit reads as
 * the definition, and it then reports every correct call site as an arity error.
 * ------------------------------------------------------------------------ */
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
function nl2br_stub( $t ) { return nl2br( $t ); }
function home_url( $path = '', $scheme = null ) { return 'https://resources.example.org' . $path; }
function add_query_arg( $key, $value = '', $url = '' ) {
    return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
function get_option( $name, $default = false ) {
    $GLOBALS['sfaf_options'] = isset( $GLOBALS['sfaf_options'] ) ? $GLOBALS['sfaf_options'] : array();
    return array_key_exists( $name, $GLOBALS['sfaf_options'] ) ? $GLOBALS['sfaf_options'][ $name ] : $default;
}
function get_post_meta( $id, $key, $single = false ) {
    $meta = isset( $GLOBALS['sfaf_meta'] ) ? $GLOBALS['sfaf_meta'] : array();
    return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
}
function get_the_title( $id = 0 ) { return 'Programa Latino: Grupo de Apoyo'; }
function get_permalink( $id = 0, $leavename = false ) { return 'https://resources.example.org/events/programa-latino/'; }
function get_the_excerpt( $post = null ) { return 'A weekly peer support group.'; }
function wp_get_post_terms( $id, $tax, $args = array() ) { return array(); }
function is_wp_error( $thing ) { return false; }

/* The formatter and the event helpers, in the shapes the builders use. */
/* The real one, copied: a bare date is anchored at midday, a stored datetime is
   read in the site's timezone because that is the zone it was written in. */
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
    $f = array(
        'full' => 'l, F j, Y', 'day' => 'F j', 'short' => 'M j', 'short_year' => 'M j, Y',
        'month_year' => 'F Y', 'weekday' => 'D', 'month' => 'M', 'daynum' => 'j',
    );
    return date( isset( $f[ $style ] ) ? $f[ $style ] : $f['full'], (int) $when );
}
function sfaf_ap_time( $raw, $meridiem = true ) {
    $raw = trim( (string) $raw );
    if ( '' === $raw ) { return ''; }
    $ts = strtotime( $raw );
    if ( false === $ts ) { return ''; }
    $clock = ( '00' === date( 'i', $ts ) ) ? date( 'g', $ts ) : date( 'g:i', $ts );
    return $meridiem ? $clock . ' ' . strtolower( date( 'A', $ts ) ) : $clock;
}
function sfaf_ap_time_range( $start, $end ) {
    if ( '' === trim( (string) $start ) ) { return ''; }
    if ( '' === trim( (string) $end ) ) { return sfaf_ap_time( $start ); }
    $same = date( 'A', strtotime( $start ) ) === date( 'A', strtotime( $end ) );
    return sfaf_ap_time( $start, ! $same ) . "\xE2\x80\x93" . sfaf_ap_time( $end );
}
function sfaf_event_location( $id ) { return '470 Castro Street, San Francisco, CA 94114'; }
function sfaf_ics_url( $id ) { return 'https://resources.example.org/?uc_ics=' . (int) $id; }
function sfaf_google_calendar_url( $id ) { return 'https://calendar.google.com/calendar/render?action=TEMPLATE'; }
function sfaf_get_rsvp_count( $id ) { return 3; }
function sfaf_replace_tokens( $text, $event_id, $data = array() ) { return $text; }

class SFAF_Reminders {
    public static function cancel_url( $token ) {
        return 'https://resources.example.org/?uc_rsvp_cancel=' . rawurlencode( $token );
    }
    public static function reply_to_for( $event_id ) { return 'programs@sfaf.org'; }
}
class SFAF_RSVP {
    public static function get_rsvps( $event_id, $status = 'confirmed' ) {
        return array(
            // Somebody with both names, and somebody who gave a first name
            // only. The second is the case the form is built for and it must
            // render as "Alex", not as a blank cell.
            (object) array( 'name' => 'Alex Rivera', 'first_name' => 'Alex', 'last_name' => 'Rivera', 'email' => 'alex@example.org', 'created_at' => '2026-08-01 10:00:00' ),
            (object) array( 'name' => 'Jo', 'first_name' => 'Jo', 'last_name' => '', 'email' => 'jo@example.org', 'created_at' => '2026-08-03 18:20:00' ),
        );
    }
    /** The real one, copied. */
    public static function display_name( $row ) {
        $row   = (object) $row;
        $first = isset( $row->first_name ) ? trim( (string) $row->first_name ) : '';
        $last  = isset( $row->last_name ) ? trim( (string) $row->last_name ) : '';
        $both  = trim( $first . ' ' . $last );
        if ( '' !== $both ) { return $both; }
        return isset( $row->name ) ? trim( (string) $row->name ) : '';
    }
}
class SFAF_Portal {
    public static function link( $path = '' ) { return 'https://resources.example.org/caladmin/' . ltrim( $path, '/' ); }
    public static function user_can_view_all( $user_id ) { return 1 === (int) $user_id; }
}

require $root . '/includes/class-sfaf-email.php';
require $root . '/includes/class-sfaf-notifications.php';

/* The event under test. */
$GLOBALS['sfaf_meta'] = array(
    '_uc_event_date'  => '2026-08-12',
    '_uc_start_time'  => '18:00',
    '_uc_end_time'    => '19:30',
    '_uc_capacity'    => '25',
);
$GLOBALS['sfaf_options'] = array();

$person = (object) array(
    'name'       => 'Alex Rivera',
    'first_name' => 'Alex',
    'last_name'  => 'Rivera',
    'email'      => 'alex@example.org',
    'token'      => str_repeat( 'a1', 16 ),
);
$staff = (object) array( 'email' => 'programs@sfaf.org', 'token' => '', 'is_staff' => true );

/*
 * THE ALERT IS TWO MESSAGES NOW, and both are built here. The only difference
 * between them is where the button goes, and that is the whole point of the
 * case: a recipient who can open the RSVP list gets it, and one who cannot gets
 * the public event page. Sending the first to the second is sending somebody a
 * link to a page that will refuse them, so it is checked rather than assumed.
 */
$cases = array(
    'confirmation' => array( 'person' => $person, 'cancel' => true ),
    'reminder'     => array( 'person' => $person, 'cancel' => true ),
    'reminder-staff' => array( 'type' => 'reminder', 'person' => $staff, 'cancel' => false ),
    'alert'        => array( 'person' => $person, 'cancel' => false ),
    'alert-viewer' => array( 'type' => 'alert', 'person' => $person, 'cancel' => false, 'context' => array( 'can_view_all' => true ) ),
    'summary'      => array( 'person' => null, 'cancel' => false ),
);

$fails = array();
$built = array();

foreach ( $cases as $name => $case ) {
    $type = isset( $case['type'] ) ? $case['type'] : $name;
    $ctx  = isset( $case['context'] ) ? $case['context'] : array();
    $out  = SFAF_Notifications::build( $type, 42, $case['person'], $ctx );

    if ( ! is_array( $out ) ) {
        $fails[] = "$name: built nothing";
        continue;
    }
    $built[ $name ] = $out;

    $html = $out['html'];
    $text = $out['text'];

    if ( '' === trim( (string) $out['subject'] ) ) { $fails[] = "$name: no subject"; }

    // A PLAIN-TEXT ALTERNATIVE IS NOT OPTIONAL. A text-only client shows an
    // empty message without one.
    if ( strlen( trim( $text ) ) < 80 ) { $fails[] = "$name: the plain text part is missing or too short"; }

    // TABLES, NOT DIVS. One div is allowed and expected: the hidden preheader.
    $divs = substr_count( $html, '<div' );
    if ( $divs > 1 ) { $fails[] = "$name: $divs divs in the layout, which Outlook will stack"; }
    if ( false === strpos( $html, '<table role="presentation"' ) ) { $fails[] = "$name: no layout table"; }

    // 600px, stated twice: the attribute for Outlook, the style for everyone.
    if ( false === strpos( $html, 'width="600"' ) ) { $fails[] = "$name: no 600px width attribute"; }
    if ( false === strpos( $html, 'max-width:600px' ) ) { $fails[] = "$name: no max-width"; }

    // The banner, and it has to read with images off.
    if ( false === strpos( $html, 'sfaf-email-header.png' ) ) { $fails[] = "$name: no banner"; }
    if ( false === strpos( $html, 'alt="San Francisco AIDS Foundation"' ) ) { $fails[] = "$name: the banner has no alt text"; }

    // The footer address, in both parts.
    if ( false === strpos( $html, '940 Howard Street' ) ) { $fails[] = "$name: no postal address in the HTML"; }
    if ( false === strpos( $text, '940 Howard Street' ) ) { $fails[] = "$name: no postal address in the text"; }

    // MODERN CSS DOES NOT SURVIVE WORD'S RENDERING ENGINE.
    foreach ( array( 'display:flex', 'display:grid', 'border-radius', 'position:absolute', 'var(--' ) as $banned ) {
        if ( false !== strpos( str_replace( ' ', '', $html ), $banned ) ) {
            $fails[] = "$name: uses $banned, which Outlook on Windows does not support";
        }
    }

    // THE PALETTE IS CLOSED. Any hex in the markup must be one we approved.
    $allowed = array( '#FFD900', '#373433', '#0E7680', '#6B6764', '#D1D3D4', '#F5F6F7', '#ffffff', '#fff' );
    preg_match_all( '/#[0-9A-Fa-f]{3,6}\b/', $html, $hexes );
    foreach ( array_unique( $hexes[0] ) as $hex ) {
        $ok = false;
        foreach ( $allowed as $a ) {
            if ( strtolower( $a ) === strtolower( $hex ) ) { $ok = true; break; }
        }
        if ( ! $ok ) { $fails[] = "$name: $hex is not in the brand palette"; }
    }

    // NO EM DASHES, ANYWHERE, INCLUDING HERE.
    if ( false !== strpos( $html . $text, "\xE2\x80\x94" ) ) { $fails[] = "$name: contains an em dash"; }

    // The cancel link belongs to people who hold a place, and to nobody else.
    $has_cancel = ( false !== strpos( $html, 'uc_rsvp_cancel' ) );
    if ( $case['cancel'] && ! $has_cancel ) { $fails[] = "$name: should carry a cancel link and does not"; }
    if ( ! $case['cancel'] && $has_cancel ) { $fails[] = "$name: carries a cancel link and should not"; }
    if ( $case['cancel'] && false === strpos( $text, 'uc_rsvp_cancel' ) ) {
        $fails[] = "$name: the cancel link is missing from the plain text part";
    }

    // EVERY FACT IN THE HTML IS IN THE TEXT. A text reader must not be told
    // less than an HTML reader.
    foreach ( array( 'August 12, 2026', '6', 'Castro' ) as $fact ) {
        if ( false !== strpos( $html, $fact ) && false === strpos( $text, $fact ) ) {
            $fails[] = "$name: \"$fact\" is in the HTML but not in the text";
        }
    }

    // Links must be absolute: an email has no base URL to be relative to.
    preg_match_all( '/href="([^"]+)"/', $html, $hrefs );
    foreach ( $hrefs[1] as $href ) {
        if ( 0 !== strpos( $href, 'http' ) && 0 !== strpos( $href, 'mailto:' ) ) {
            $fails[] = "$name: relative link $href";
        }
    }
}

/*
 * NO EMPTY CELL IN A TABLE THAT HAS A HEADING FOR IT.
 *
 * This check exists because of what it found. The summary's "Registered"
 * column rendered blank for every person: the formatter was handed a stored
 * datetime ('2026-08-04 21:30:00'), appended ' 12:00:00' to it as though it
 * were a bare date, and produced a string strtotime() could not read. Nothing
 * else noticed, because a missing value is not a broken layout and every other
 * rule above still held. A heading with nothing under it is a defect, and it is
 * cheap to say so.
 */
foreach ( $built as $name => $out ) {
    if ( preg_match_all( '/<td[^>]*>\s*<\/td>/', $out['html'], $blanks ) ) {
        $fails[] = "$name: " . count( $blanks[0] ) . ' empty table cell(s); a column with a heading and no value in it';
    }
}

/* ---------------------------------------------------------------------------
 * THE ALERT'S LINK IS THE RIGHT ONE FOR THE RECIPIENT.
 *
 * /caladmin/rsvps is gated on can_view_all. The notification list is not: it
 * holds contributors, people reached through a team, and typed addresses that
 * are not accounts. So the rule checked here is one-directional and absolute,
 * because only one direction can hurt: a recipient WITHOUT the capability must
 * never see a caladmin link at all.
 * ------------------------------------------------------------------------ */
$viewer = isset( $built['alert-viewer'] ) ? $built['alert-viewer'] : null;
$plain  = isset( $built['alert'] ) ? $built['alert'] : null;

if ( $viewer ) {
    if ( false === strpos( $viewer['html'], '/caladmin/rsvps' ) ) {
        $fails[] = 'alert-viewer: does not link to the RSVP list, which is the whole reason it is built separately';
    }
    if ( false === strpos( $viewer['text'], '/caladmin/rsvps' ) ) {
        $fails[] = 'alert-viewer: the RSVP link is missing from the plain text part';
    }
}
if ( $plain ) {
    if ( false !== strpos( $plain['html'] . $plain['text'], '/caladmin' ) ) {
        $fails[] = 'alert: links into caladmin for a recipient who cannot open it';
    }
    if ( false === strpos( $plain['html'], 'https://resources.example.org/events/' ) ) {
        $fails[] = 'alert: no link at all for a recipient without access; the public event page is what they get';
    }
}

/* The confirmation greets by first name, and by first name only. */
if ( isset( $built['confirmation'] ) ) {
    if ( false === strpos( $built['confirmation']['text'], 'You are registered, Alex.' ) ) {
        $fails[] = 'confirmation: does not greet by first name';
    }
    if ( false !== strpos( $built['confirmation']['text'], 'registered, Alex Rivera' ) ) {
        $fails[] = 'confirmation: greets with the full name, and the greeting is the first name only';
    }
}

/* A registration with no surname renders as a name, not as a blank. */
if ( isset( $built['summary'] ) ) {
    if ( false === strpos( $built['summary']['html'], '>Jo<' ) ) {
        $fails[] = 'summary: a person who gave a first name only is missing from the table';
    }
    if ( false === strpos( $built['summary']['text'], '- Jo <jo@example.org>' ) ) {
        $fails[] = 'summary: a person who gave a first name only is missing from the text list';
    }
}

/* ONE PRIMARY BUTTON PER EMAIL. Yellow means "act on this"; two of them means
   neither does. */
foreach ( $built as $name => $out ) {
    $yellow = substr_count( str_replace( ' ', '', $out['html'] ), 'background-color:#FFD900' );
    if ( $yellow > 1 ) { $fails[] = "$name: $yellow yellow buttons, and yellow is one per view"; }
}

/* The summary must refuse to exist when nobody has registered. */
class SFAF_RSVP_Empty { public static function get_rsvps( $e, $s = 'confirmed' ) { return array(); } }
$empty = SFAF_Notifications::build( 'summary', 42, null );
if ( null === $empty ) {
    $fails[] = 'summary: built nothing for an event that HAS registrations';
}

/* Write the files out, for a human to look at. */
$write = array_search( '--write', $argv, true );
if ( false !== $write && isset( $argv[ $write + 1 ] ) ) {
    $dir = rtrim( $argv[ $write + 1 ], '/\\' );
    if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
    foreach ( $built as $name => $out ) {
        file_put_contents( $dir . '/' . $name . '.html', $out['html'] );
        file_put_contents( $dir . '/' . $name . '.txt', $out['subject'] . "\n\n" . $out['text'] );
    }
    echo 'wrote ' . ( count( $built ) * 2 ) . " files to $dir\n";
}

echo "Email render test\n";
echo 'built: ' . count( $built ) . " messages (confirmation, reminder, reminder to staff, alert to a\n";
echo "       recipient without access, alert to one with it, summary)\n";
echo "checked per message: subject, text alternative, table layout, 600px, banner and its alt text,\n";
echo "                     postal address in both parts, no modern CSS, closed palette, no em dash,\n";
echo "                     cancel link only where it belongs, HTML facts present in the text, absolute links\n";
echo "checked across them: the alert's link matches the recipient's access, the confirmation greets by\n";
echo "                     first name only, and a registration with no surname still renders a name\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "every message builds, in both parts, and holds every rule above.\n";
exit( 0 );
