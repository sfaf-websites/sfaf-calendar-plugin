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
/* THE REAL CLASS. The confirmation and the reminder both compose a joining
 * block from it, so stubbing it would render two of the five messages without
 * the part that was added to them. The event under test is in person, so the
 * block is empty and the five shapes below are unchanged; whether the block
 * itself is right is asserted in .claude/online-events-test.php. */
require $root . '/includes/class-sfaf-online.php';
/*
 * THE REAL SFAF_Cancellation (3.72.0), because build_cancelled() reads the
 * registrants-only message through it. A stub would be a second answer to
 * "which key is that message in", and the whole point of the key being
 * separate from the public reason is that the two must not be confused.
 */
require $root . '/includes/class-sfaf-cancellation.php';
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
    'summary-editor' => array( 'type' => 'summary', 'person' => null, 'cancel' => false, 'context' => array( 'can_edit_event' => true ) ),
    /*
     * THE CANCELLATION ALERT, added 3.56.0 with the message itself.
     *
     * A new message type that is not in this list is a message nothing renders,
     * and the suite still passes: every other case goes on building and the
     * total says 47. So the case goes in with the builder, not after somebody
     * notices. It carries no cancel link (there is nothing left to cancel) and
     * no caladmin link, so it is absent from $MAY_LINK_TO_CALADMIN below and is
     * held to that by the same check as the rest.
     */
    'cancel_alert' => array( 'person' => $person, 'cancel' => false, 'context' => array( 'count' => 11 ) ),
    /*
     * THE CANCELLATION ITSELF, WHICH WAS NEVER IN THIS LIST. Its alert was
     * added in 3.56.0 and the message telling REGISTRANTS was not, so the one
     * email that goes to the public half of the audience has been building
     * unrendered since 3.36.0.
     *
     * TWICE, because 3.72.0 gave it a second paragraph that only sometimes
     * exists: an organizer emailing the people registered may add a message
     * for them and nobody else. One case has it and one does not, so both the
     * presence and the ABSENCE are rendered rather than assumed.
     *
     * `meta` is merged over the event for the case and put back afterwards.
     */
    'cancelled' => array(
        'person' => $person, 'cancel' => false,
        'meta'   => array( '_uc_cancelled_reason' => 'The room is not available.' ),
    ),
    'cancelled-with-message' => array(
        'type' => 'cancelled', 'person' => $person, 'cancel' => false,
        'meta' => array(
            '_uc_cancelled_reason'  => 'The room is not available.',
            '_uc_cancelled_message' => 'We are looking at a new date and will write again next week.',
        ),
    ),
    /*
     * THE FIFTH THING THAT CAN HAPPEN TO A REGISTRATION (3.73.0): told it
     * was off, and now it is on.
     *
     * TWICE, because it goes out whether or not the date moved and the two
     * read differently. The one that did not move must NOT talk about a
     * move, and that absence is the half a builder reading the wrong key
     * would get wrong silently.
     */
    'reinstated' => array( 'person' => $person, 'cancel' => true ),
    'reinstated-moved' => array(
        'type' => 'reinstated', 'person' => $person, 'cancel' => true,
        'context' => array( 'was' => '2026-08-05' ),
    ),
);

/*
 * WHICH MESSAGES MAY CARRY A CALADMIN LINK AT ALL.
 *
 * Two have now been found linking staff into a gated screen without asking
 * whether the reader could open it, both by somebody going and looking. This is
 * the list that stops a third being found the same way: any message not named
 * here must contain no caladmin URL in either part, whatever context it is
 * handed. A new email that wants one has to be added deliberately, and the
 * per-recipient routing is then checked by alert-recipients-test.php.
 */
$MAY_LINK_TO_CALADMIN = array( 'alert-viewer', 'summary-editor' );

$fails = array();
$built = array();

foreach ( $cases as $name => $case ) {
    $type = isset( $case['type'] ) ? $case['type'] : $name;
    $ctx  = isset( $case['context'] ) ? $case['context'] : array();
    /* Merged over the shared event and put back, so one case cannot leak a
     * meta value into the next one and quietly make it pass. */
    $meta_was = $GLOBALS['sfaf_meta'];
    if ( ! empty( $case['meta'] ) ) {
        $GLOBALS['sfaf_meta'] = array_merge( $GLOBALS['sfaf_meta'], $case['meta'] );
    }
    $out  = SFAF_Notifications::build( $type, 42, $case['person'], $ctx );
    $GLOBALS['sfaf_meta'] = $meta_was;

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
 * THE INVENTORY: NO MESSAGE LINKS INTO CALADMIN UNLESS IT IS ONE OF THE TWO
 * THAT ROUTE PER RECIPIENT.
 *
 * This is the check that answers "is there a third" once rather than every time
 * somebody wonders. It runs over every message built above, in both parts, and
 * it is deliberately a whitelist: a message added later is caught by default
 * instead of being missed by default.
 * ------------------------------------------------------------------------ */
foreach ( $built as $name => $out ) {
    $both = $out['html'] . "\n" . $out['text'];
    $has  = ( false !== strpos( $both, '/caladmin' ) );
    $may  = in_array( $name, $MAY_LINK_TO_CALADMIN, true );

    if ( $has && ! $may ) {
        $fails[] = "$name: links into caladmin, and it is not one of the messages that route per recipient";
    }
    if ( ! $has && $may ) {
        $fails[] = "$name: is supposed to carry a caladmin link for this recipient and does not";
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

/* ---------------------------------------------------------------------------
 * ADD TO CALENDAR: A MATCHED PAIR, A GENERIC GLYPH, AND IT READS WITH IMAGES OFF.
 *
 * The pair rendered at two different heights because each button was sized by
 * its own label: "Add to Google Calendar" wrapped to three lines and "Add to
 * Apple or Outlook" to two. The labels were shortened AND the geometry was made
 * independent of them, so these checks cover both halves. A longer label
 * arriving later must not be able to bring the fault back.
 *
 * THE IMAGES-OFF CHECK IS THE ONE THAT MATTERS, and it is done by actually
 * removing the images rather than by reasoning about alt text. Many clients
 * block pictures by default; a button that says nothing without one is a button
 * a large share of recipients cannot read.
 * ------------------------------------------------------------------------ */
$confirmation = isset( $built['confirmation'] ) ? $built['confirmation']['html'] : '';

if ( '' === $confirmation ) {
    $fails[] = 'add-to-calendar: no confirmation was built, so none of this was checked';
} else {
    if ( false === strpos( $confirmation, '>Add to calendar</p>' ) ) {
        $fails[] = 'add-to-calendar: the pair has no heading above it';
    }

    // Equal width, stated as an attribute so Word honours it.
    if ( 2 !== substr_count( $confirmation, 'width="50%"' ) ) {
        $fails[] = 'add-to-calendar: the two buttons are not in equal-width cells';
    }

    // Filling the cell is what makes the width real rather than nominal.
    if ( 2 !== substr_count( $confirmation, 'display:block; padding:13px 10px' ) ) {
        $fails[] = 'add-to-calendar: a button does not fill its cell, so its size still depends on its label';
    }

    // The labels themselves, which is the root fix.
    foreach ( array( '>Google</a>', '>Apple or Outlook</a>' ) as $needle ) {
        if ( false === strpos( $confirmation, $needle ) ) {
            $fails[] = "add-to-calendar: expected a button ending $needle";
        }
    }
    if ( false !== strpos( $confirmation, 'Add to Google Calendar' ) ) {
        $fails[] = 'add-to-calendar: the long label is back, and it wraps to three lines';
    }

    // The glyph: present, decorative, and sized so a blocked one reserves 16px
    // rather than whatever the client guesses.
    if ( 2 !== preg_match_all( '#<img[^>]+icon-calendar-[a-z]+\.png[^>]*>#', $confirmation, $icons ) ) {
        $fails[] = 'add-to-calendar: expected a calendar glyph on each of the two buttons';
    } else {
        foreach ( $icons[0] as $img ) {
            if ( false === strpos( $img, 'alt=""' ) ) {
                $fails[] = 'add-to-calendar: a glyph is not marked decorative, so a screen reader will announce it';
            }
            if ( false === strpos( $img, 'width="16"' ) || false === strpos( $img, 'height="16"' ) ) {
                $fails[] = 'add-to-calendar: a glyph has no width/height attributes, so a blocked image resizes the button';
            }
        }
    }

    // IT READS WITH IMAGES OFF. Strip every picture and look again.
    $imageless = preg_replace( '#<img[^>]*>#', '', $confirmation );
    foreach ( array( '>Google</a>', '>Apple or Outlook</a>' ) as $needle ) {
        if ( false === strpos( $imageless, $needle ) ) {
            $fails[] = "add-to-calendar: with images blocked the button loses $needle";
        }
    }
    if ( false === strpos( $imageless, 'Add to calendar' ) ) {
        $fails[] = 'add-to-calendar: with images blocked the heading is gone too';
    }
}

/* ---------------------------------------------------------------------------
 * THE TWO CANCELLATION MESSAGES ARE TWO AUDIENCES (3.72.0).
 *
 * `_uc_cancelled_reason` is PUBLIC: it renders on the event page for anybody
 * who arrives at the address, and it is in the email as well.
 * `_uc_cancelled_message` is for the people who registered and NOBODY ELSE.
 *
 * That distinction is the whole of why they are two keys, and it lives in two
 * places at once, a renderer and a template, so nothing but a rendered check
 * can hold it. All four directions are asserted: each one present where it
 * belongs, in both parts, and the private one ABSENT from the message that was
 * built without it, which is the half that would go unnoticed if a builder
 * started reading the wrong key.
 * ------------------------------------------------------------------------ */
if ( isset( $built['cancelled'], $built['cancelled-with-message'] ) ) {
    $plain = $built['cancelled'];
    $extra = $built['cancelled-with-message'];
    $note  = 'We are looking at a new date and will write again next week.';
    $why   = 'The room is not available.';

    foreach ( array( 'html', 'text' ) as $part ) {
        if ( false === strpos( $plain[ $part ], $why ) ) {
            $fails[] = "cancelled: the public reason is missing from the $part part";
        }
        if ( false !== strpos( $plain[ $part ], $note ) ) {
            $fails[] = "cancelled: a message nobody wrote is in the $part part";
        }
        if ( false === strpos( $extra[ $part ], $why ) ) {
            $fails[] = "cancelled-with-message: the public reason is missing from the $part part";
        }
        if ( false === strpos( $extra[ $part ], $note ) ) {
            $fails[] = "cancelled-with-message: the registrants' message is missing from the $part part";
        }
    }

    /* The reason first, then the message. That is the order they were written
     * in and the order the questions come in: why it is off, then anything the
     * organizer wanted to add. */
    $r_at = strpos( $extra['html'], $why );
    $n_at = strpos( $extra['html'], $note );
    if ( false !== $r_at && false !== $n_at && $r_at > $n_at ) {
        $fails[] = 'cancelled-with-message: the private message is above the public reason';
    }

    /* AND IT IS IN NO OTHER MESSAGE. The key is set on the event for the whole
     * of the case that uses it, so any builder reading it would be caught here
     * rather than by somebody receiving one. */
    foreach ( $built as $name => $out ) {
        if ( 'cancelled-with-message' === $name ) { continue; }
        if ( false !== strpos( $out['html'] . $out['text'], $note ) ) {
            $fails[] = "$name: carries the registrants-only cancellation message, which is for one audience";
        }
    }
}

/* ---------------------------------------------------------------------------
 * PUTTING AN EVENT BACK ON (3.73.0).
 *
 * FOUR RULES, and each of them is a thing somebody could plausibly get
 * wrong while the message still built and still looked fine.
 * ------------------------------------------------------------------------ */
if ( isset( $built['reinstated'], $built['reinstated-moved'] ) ) {
    $back  = $built['reinstated'];
    $moved = $built['reinstated-moved'];

    /* 1. IT LEADS ON THE EVENT BEING ON, not on a change. Somebody who
     *    thinks this is not happening cannot act on "the date moved". */
    foreach ( array( $back, $moved ) as $m ) {
        if ( false === strpos( $m['html'], 'is back on' ) ) {
            $fails[] = 'reinstated: the message does not say it is back on';
        }
    }

    /* 2. THE ONE THAT DID NOT MOVE SAYS NOTHING ABOUT MOVING. */
    foreach ( array( 'html', 'text' ) as $part ) {
        if ( false !== strpos( $back[ $part ], 'has also moved' ) ) {
            $fails[] = "reinstated: an event on its original date claims it moved, in the $part part";
        }
        if ( false === strpos( $moved[ $part ], 'has also moved' ) ) {
            $fails[] = "reinstated-moved: a moved event does not say so, in the $part part";
        }
    }

    /* 3. THE CANCEL LINK IS IN BOTH. Somebody registered for a Wednesday and
     *    moved to a Thursday needs a way out, and so does somebody whose
     *    fortnight has filled up while the thing was off. */
    foreach ( array( 'reinstated', 'reinstated-moved' ) as $name ) {
        if ( false === strpos( $built[ $name ]['html'], 'uc_rsvp_cancel' ) ) {
            $fails[] = "$name: no cancel link, and this is the message that most needs one";
        }
    }

    /* 4. IT SAYS THE REGISTRATION SURVIVED. The whole reason somebody can do
     *    nothing is that their place is still theirs. */
    if ( false === strpos( $back['html'], 'still holds' ) ) {
        $fails[] = 'reinstated: does not say the registration still holds';
    }
}

/*
 * NO PLATFORM MARK IN ANY MESSAGE.
 *
 * Google, Apple and Outlook are registered trademarks with published brand
 * terms and nothing here has been cleared to reproduce them. A whitelist rather
 * than a blacklist: every image in every message must be one of the two files
 * this plugin ships, so a vendor logo added later is caught by default.
 */
$ALLOWED_IMAGES = array( 'sfaf-email-header.png', 'icon-calendar-ink.png', 'icon-calendar-teal.png' );
foreach ( $built as $name => $out ) {
    if ( ! preg_match_all( '#<img[^>]+src="([^"]+)"#', $out['html'], $srcs ) ) {
        continue;
    }
    foreach ( $srcs[1] as $src ) {
        $file = basename( parse_url( $src, PHP_URL_PATH ) );
        if ( ! in_array( $file, $ALLOWED_IMAGES, true ) ) {
            $fails[] = "$name: unknown image $file. If it is a platform logo, it is not ours to send.";
        }
        if ( 0 !== strpos( $src, 'http' ) ) {
            $fails[] = "$name: image $src is relative, and an email has no base URL";
        }
    }
}

echo "Email render test\n";
echo 'built: ' . count( $built ) . " messages (confirmation, reminder, reminder to staff, the alert and
";
echo "       the summary in both of their recipient versions, the cancellation alert, the
";
echo "       cancellation itself with and without a message for the people registered, and
";
echo "       an event put back on, with and without its date having moved)
";
echo "checked per message: subject, text alternative, table layout, 600px, banner and its alt text,
";
echo "                     postal address in both parts, no modern CSS, closed palette, no em dash,
";
echo "                     cancel link only where it belongs, HTML facts present in the text, absolute links
";
echo "checked across them: no message links into caladmin except the two that route per recipient,
";
echo "                     the alert's link matches the recipient's access, the confirmation greets by
";
echo "                     first name only, and a registration with no surname still renders a name
";
echo "reinstated:          leads on the event being on, mentions a move only where there was one,
";
echo "                     carries a cancel link in both, and says the registration still holds
";
echo "cancellation:        the public reason and the registrants-only message each present where they
";
echo "                     belong, in both parts, in that order, and the private one in no other message
";
echo "add to calendar:     a heading, two equal-width buttons that fill their cells, a generic glyph on
";
echo "                     each, no platform logo in any message, and both buttons still read with the
";
echo "                     images actually stripped out

";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( array_unique( $fails ) as $f ) { echo '  . ' . $f . "\n"; }
    exit( 1 );
}
echo "every message builds, in both parts, and holds every rule above.\n";
exit( 0 );
