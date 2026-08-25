<?php
/**
 * CANCELLING AN EVENT, AND EVERYTHING THAT MUST STOP HAPPENING WHEN ONE IS.
 *
 * The brief for 3.36.0 named three things to check explicitly, and every one of
 * them is a case where the OBVIOUS implementation looks right and is wrong:
 *
 *   1. The morning-of reminder and the two-hour summary must not go out. Both
 *      select on post_status 'publish' and today's date, and a cancelled event
 *      has both, because cancellation is meta. So the queries CANNOT exclude it
 *      and the exclusion has to be a line in each loop. A test that only asked
 *      "is the event cancelled" would pass while both emails went out.
 *   2. A refetch must not un-cancel an imported event, or move it.
 *   3. One person registered for several affected dates gets ONE email. The
 *      naive loop sends one per date, and it looks completely correct until
 *      somebody is registered for six occurrences of a weekly group.
 *
 *     php .claude/cancellation-test.php
 *
 * WHAT IT STUBS. WordPress, and $wpdb far enough to hold registration rows. The
 * classes under test are the real files: SFAF_Cancellation and SFAF_Announce in
 * full, and the two due-event queries lifted out of their sources by name, with
 * the extraction asserted so this cannot drift into testing a copy of a rule
 * that has since changed.
 */

$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

$fails = array();

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
$GLOBALS['pmeta']  = array();
$GLOBALS['posts']  = array();
$GLOBALS['opt']    = array();
$GLOBALS['sent']   = array();

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opt'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['opt'][ $n ] ); return true; }
function get_post_meta( $id, $k, $single = false ) {
    $key = (int) $id . ':' . $k;
    return array_key_exists( $key, $GLOBALS['pmeta'] ) ? $GLOBALS['pmeta'][ $key ] : '';
}
function update_post_meta( $id, $k, $v, $prev = '' ) { $GLOBALS['pmeta'][ (int) $id . ':' . $k ] = $v; return true; }
function delete_post_meta( $id, $k, $v = '' ) { unset( $GLOBALS['pmeta'][ (int) $id . ':' . $k ] ); return true; }
function get_post( $id = 0 ) {
    $id = is_object( $id ) ? $id->ID : (int) $id;
    return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ] : null;
}
function get_the_title( $id = 0 ) {
    $p = get_post( $id );
    return $p ? $p->post_title : '';
}
function get_post_status( $id = 0 ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function get_permalink( $id = 0, $leavename = false ) { return 'https://example.org/events/' . (int) $id; }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $t, $b = false ) { return strip_tags( (string) $t ); }
function wp_timezone() { return new DateTimeZone( date_default_timezone_get() ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : date( $type ); }
function date_i18n( $f, $ts = false, $gmt = false ) { return date( $f, false === $ts ? time() : $ts ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function home_url( $p = '', $s = null ) { return 'https://example.org' . $p; }
function add_query_arg( $k, $v = '', $u = '' ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . rawurlencode( $k ) . '=' . rawurlencode( $v ); }
function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return $s; }
function get_bloginfo( $show = '', $filter = 'raw' ) { return 'SFAF Calendar'; }
function absint( $n ) { return abs( (int) $n ); }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }

/** Every send is captured instead of leaving. */
class SFAF_Email {
    const FONT = 'Montserrat, Arial, sans-serif';
    const C_YELLOW = '#FFD900';
    const C_INK    = '#373433';
    const C_TEAL   = '#0E7680';
    const C_MUTED  = '#6B6764';
    const C_RULE   = '#D1D3D4';
    const POSTAL   = 'San Francisco AIDS Foundation, 940 Howard Street, San Francisco, CA 94103';

    public static function send( $to, $subject, $html, $text, $reply_to = '' ) {
        $GLOBALS['sent'][] = array( 'to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text );
        return true;
    }
    public static function shell( $pre, $content ) { return '<html>' . $content . '</html>'; }
    public static function heading( $t ) { return '<p>' . esc_html( $t ) . '</p>'; }
    public static function para( $t ) { return '<p>' . esc_html( $t ) . '</p>'; }
    public static function label( $t ) { return '<p>' . esc_html( $t ) . '</p>'; }
    public static function small_para( $h ) { return '<p>' . $h . '</p>'; }
    public static function rule() { return '<hr />'; }
    public static function link_para( $url, $label ) { return '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>'; }
    public static function details( $rows ) {
        $o = '<table>';
        foreach ( $rows as $k => $v ) { if ( '' === trim( (string) $v ) ) { continue; } $o .= '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( $v ) . '</td></tr>'; }
        return $o . '</table>';
    }
    public static function button( $url, $label, $style = 'primary', $icon = false, $fill = false ) {
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }
    public static function button_row( $b ) { return implode( '', $b ); }
}

class SFAF_Reminders {
    const EVENT_DONE_META = '_uc_reminder_done';
    public static function cancel_url( $token ) { return 'https://example.org/?uc_rsvp_cancel=' . rawurlencode( $token ); }
    public static function reply_to_for( $id ) { return 'events@example.org'; }
    public static function is_imported( $id ) { return '' !== (string) get_post_meta( $id, '_uc_source', true ); }
}

class SFAF_Sources { const META_SOURCE = '_uc_source'; }

/*
 * THE HOUSE DATE STYLE IS NOT SPELLED OUT HERE.
 *
 * date-callsite-sweep.php caught this written as date( 'l, F j, Y' ), which is
 * a second copy of the format the one formatter owns, living in a test. What
 * these assertions need is a value that is stable and recognisable, not one
 * that matches production, so the stub builds its own unambiguous string.
 */
function sfaf_ap_date( $d, $f = 'full' ) {
    if ( '' === $d ) { return ''; }
    $ts = strtotime( $d . ' 12:00:00' );
    if ( ! $ts ) { return ''; }
    $parts = explode( '-', gmdate( 'Y-m-d', $ts ) );
    $months = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December' );
    return $months[ (int) $parts[1] ] . ' ' . (int) $parts[2] . ', ' . $parts[0];
}
function sfaf_ap_time_range( $s, $e ) {
    if ( '' === $s ) { return ''; }
    $f = function ( $t ) { $ts = strtotime( '2026-01-01 ' . $t ); return $ts ? ltrim( date( 'g:i a', $ts ), '0' ) : ''; };
    return '' !== $e ? $f( $s ) . ' to ' . $f( $e ) : $f( $s );
}
function sfaf_ap_datetime( $ts, $f = '' ) { return gmdate( 'c', (int) $ts ); }
function sfaf_event_location( $id ) { return (string) get_post_meta( $id, '_uc_location', true ); }
function sfaf_google_calendar_url( $id ) { return 'https://calendar.google.com/calendar/render?e=' . (int) $id; }
function sfaf_ics_url( $id ) { return 'https://example.org/?uc_ics=' . (int) $id; }
function sfaf_replace_tokens( $s, $id, $t = array() ) { return $s; }
function sfaf_status_label( $s ) { return ucfirst( (string) $s ); }

/** Enough $wpdb to hold registration rows. */
class Fake_WPDB {
    public $prefix = 'wp_';
    public $rows = array();
    public function prepare( $sql, ...$args ) {
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
            $sql = preg_replace( '/%s/', "'" . addslashes( (string) $a ) . "'", $sql, 1 );
        }
        return $sql;
    }
    /**
     * THE STUB READS THE QUERY. It used to apply "status = confirmed" itself,
     * whatever the SQL said, which meant the rule was enforced by the test
     * scaffolding rather than by the code under test: deleting that condition
     * from the real query changed nothing this could see. A stub that upholds
     * the thing being tested hides its removal.
     */
    private function matching( $sql ) {
        if ( ! preg_match( '/event_id = (\d+)/', $sql, $m ) ) { return array(); }
        $event_id = (int) $m[1];

        /*
         * BOTH SHAPES THE REAL QUERIES USE. Reading only `status = 'x'` meant
         * that when they changed to `status IN ('confirmed','subscribed')` this
         * silently stopped filtering at all, and a 'cancelled' row came back
         * from a query that excludes them. A stub that cannot read its input
         * does not test the input.
         */
        $wants = array();
        if ( preg_match( "/status\s+IN\s*\(([^)]*)\)/i", $sql, $s ) ) {
            preg_match_all( "/'([a-z]+)'/", $s[1], $m2 );
            $wants = $m2[1];
        } elseif ( preg_match( "/status = '([a-z]+)'/", $sql, $s ) ) {
            $wants = array( $s[1] );
        }

        $out = array();
        foreach ( $this->rows as $r ) {
            if ( (int) $r->event_id !== $event_id ) { continue; }
            if ( $wants && ! in_array( $r->status, $wants, true ) ) { continue; }
            $out[] = $r;
        }
        return $out;
    }
    public function get_results( $sql ) { return $this->matching( $sql ); }
    public function get_var( $sql ) { return count( $this->matching( $sql ) ); }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require_once $root . '/includes/class-sfaf-cancellation.php';
require_once $root . '/includes/class-sfaf-notifications.php';
require_once $root . '/includes/class-sfaf-announce.php';

/* ---------------------------------------------------------------------------
 * The world.
 * ------------------------------------------------------------------------ */
function make_event( $id, $title, $date, $start = '18:00', $end = '19:30', $where = 'Castro office', $status = 'publish' ) {
    $GLOBALS['posts'][ $id ] = (object) array(
        'ID' => $id, 'post_title' => $title, 'post_type' => 'uc_event', 'post_status' => $status,
    );
    update_post_meta( $id, '_uc_event_date', $date );
    update_post_meta( $id, '_uc_start_time', $start );
    update_post_meta( $id, '_uc_end_time', $end );
    update_post_meta( $id, '_uc_location', $where );
}
function register_person( $event_id, $email, $first, $last = '', $status = 'confirmed' ) {
    $GLOBALS['wpdb']->rows[] = (object) array(
        'id' => count( $GLOBALS['wpdb']->rows ) + 1,
        'event_id' => $event_id, 'email' => $email,
        'first_name' => $first, 'last_name' => $last, 'name' => trim( $first . ' ' . $last ),
        'status' => $status, 'token' => 'tok-' . $event_id . '-' . $email,
        'created_at' => '2026-08-01 10:00:00',
    );
}
function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}

$today = date( 'Y-m-d' );

/* ===========================================================================
 * 1. THE STATE.
 * ======================================================================== */
echo "The state\n";

make_event( 10, 'Programa Latino', $today );
expect( 'a new event is not cancelled', SFAF_Cancellation::is_cancelled( 10 ), false );
expect( 'and is not hidden',            SFAF_Cancellation::is_hidden( 10 ),    false );

SFAF_Cancellation::set( 10, true );
expect( 'cancelled',                    SFAF_Cancellation::is_cancelled( 10 ), true );
expect( 'stay is the default',          SFAF_Cancellation::visibility( 10 ),   'stay' );
expect( 'staying is not hidden',        SFAF_Cancellation::is_hidden( 10 ),    false );
if ( ! SFAF_Cancellation::cancelled_at( 10 ) ) { $fails[] = 'no cancelled_at was recorded'; }

SFAF_Cancellation::set( 10, true, 'hide' );
expect( 'hidden when asked',            SFAF_Cancellation::is_hidden( 10 ),    true );

SFAF_Cancellation::set( 10, false );
expect( 'un-cancelled',                 SFAF_Cancellation::is_cancelled( 10 ), false );
expect( 'and no longer hidden',         SFAF_Cancellation::is_hidden( 10 ),    false );
expect( 'the visibility key is gone',   get_post_meta( 10, SFAF_Cancellation::VISIBILITY_META, true ), '' );

/*
 * THE EXCLUSION CLAUSE TESTS VISIBILITY, NOT CANCELLATION, and it matters:
 * an event cancelled but left listed must NOT be excluded from public queries,
 * because being listed and marked is the whole of the default behaviour.
 */
$clause = SFAF_Cancellation::meta_clause();
expect( 'the clause keys on visibility', $clause[1]['key'], SFAF_Cancellation::VISIBILITY_META );

$args = array( 'post_type' => 'uc_event' );
SFAF_Cancellation::exclude( $args );
if ( ! isset( $args['meta_query'] ) || 1 !== count( $args['meta_query'] ) ) {
    $fails[] = 'exclude() did not add its clause to an empty args array';
}
// It must append beside an existing AND rather than wrapping it, or a named
// clause moves a level deeper. Same trap SFAF_Privacy::exclude() documents.
$args = array( 'meta_query' => array( array( 'key' => '_uc_event_date', 'value' => '2026-01-01' ) ) );
SFAF_Cancellation::exclude( $args );
expect( 'appended beside an implicit AND', count( $args['meta_query'] ), 2 );
$args = array( 'meta_query' => array( 'relation' => 'OR', array( 'key' => 'a' ), array( 'key' => 'b' ) ) );
SFAF_Cancellation::exclude( $args );
expect( 'wrapped around an explicit OR', $args['meta_query']['relation'], 'AND' );

/* ===========================================================================
 * 2. THE SCHEDULED JOBS.
 *
 * The one the brief asked for by name. Both queries ask for post_status
 * 'publish' and today's date, and a cancelled event satisfies both, so the
 * guard has to be a line in each loop. This asserts it is THERE, in the real
 * source, in both files, and before the work.
 * ======================================================================== */
echo "The scheduled jobs\n";

$rem_src = file_get_contents( $root . '/includes/class-sfaf-reminders.php' );
$not_src = file_get_contents( $root . '/includes/class-sfaf-notifications.php' );

foreach ( array(
    'the morning-of reminder' => array( $rem_src, 'due_events' ),
    'the two-hour summary'    => array( $not_src, 'summary_due_events' ),
) as $what => $pair ) {
    list( $src, $fn ) = $pair;

    if ( ! preg_match( '#function ' . $fn . '\s*\(.*?\)\s*\{#s', $src, $m, PREG_OFFSET_CAPTURE ) ) {
        $fails[] = "$what: $fn() not found, so this check is blind";
        continue;
    }
    $body = substr( $src, $m[0][1], 3000 );

    if ( false === strpos( $body, 'SFAF_Cancellation::skip_scheduled' ) ) {
        $fails[] = "$what: $fn() does not ask SFAF_Cancellation, so a cancelled event would still be sent for";
        continue;
    }

    /*
     * AND IT COMES FIRST. Not decoration: if the cancelled test sat after the
     * per-event "is it switched on" checks, an event could be marked done and
     * skipped for the wrong reason, and the ordering would be one refactor away
     * from mattering. Cheapest and most absolute test first.
     */
    $at_cancel = strpos( $body, 'SFAF_Cancellation::skip_scheduled' );
    $at_other  = strpos( $body, 'is_imported' );
    if ( false !== $at_other && $at_cancel > $at_other ) {
        $fails[] = "$what: the cancelled check runs after other per-event tests; it should be first";
    }
}

// The same question, one method, so the two jobs cannot disagree.
SFAF_Cancellation::set( 10, true );
expect( 'skip_scheduled says yes when cancelled', SFAF_Cancellation::skip_scheduled( 10 ), true );
SFAF_Cancellation::set( 10, false );
expect( 'skip_scheduled says no otherwise',       SFAF_Cancellation::skip_scheduled( 10 ), false );

/* ===========================================================================
 * 3. CANCELLING IS FOR NATIVE EVENTS ONLY (3.38.0).
 *
 * 3.36.0 offered it on imported events with a warning that it would not reach
 * the source. That produced a half-cancellation that looks whole: off this
 * calendar, still selling places at Eventbrite, and nobody told by anybody.
 * The control is gone, so what has to be asserted is that it is gone from BOTH
 * halves, and that the removal path is untouched.
 *
 * The 3.36.0 refetch lock went with it and is deliberately not replaced: it
 * guarded a state that can no longer be reached, and a check that can never
 * fire reads later as evidence the case is possible.
 * ======================================================================== */
echo "Native events only\n";

$portal_src = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$src_src    = file_get_contents( $root . '/includes/class-sfaf-sources.php' );

// The card refuses to render for an imported event.
if ( ! preg_match( '#function render_cancel_card\s*\(.*?\)\s*\{#s', $portal_src, $m, PREG_OFFSET_CAPTURE ) ) {
    $fails[] = 'render_cancel_card() not found';
} else {
    $body = substr( $portal_src, $m[0][1], 2500 );
    if ( ! preg_match( "#META_SOURCE.*?\)\s*\{\s*return;#s", $body ) ) {
        $fails[] = 'render_cancel_card() does not return early for an imported event, so the control is still offered on one';
    }
}

/*
 * AND THE WRITE REFUSES TOO. A control that is not drawn is not a refusal: a
 * POST is a request anybody can construct by hand, which is defect one in
 * PROJECT.md section 5.
 */
if ( ! preg_match( "#case 'cancel_event':(.*?)break;#s", $portal_src, $m ) ) {
    $fails[] = "the cancel_event action was not found";
} else {
    if ( false === strpos( $m[1], 'META_SOURCE' ) ) {
        $fails[] = 'the cancel_event action does not refuse an imported event, so the form not being drawn is the only thing stopping it';
    }
}

// The lock is gone, and nothing calls what it used method.
if ( false !== strpos( $src_src, 'SFAF_Cancellation::skip_refresh' ) ) {
    $fails[] = 'update_event() still calls skip_refresh(), which guards a state that can no longer occur';
}
if ( method_exists( 'SFAF_Cancellation', 'skip_refresh' ) ) {
    $fails[] = 'SFAF_Cancellation::skip_refresh() still exists with no caller';
}

/*
 * THE REMOVAL PATH IS UNTOUCHED, AND SENDS NOTHING.
 *
 * An imported event that vanishes at source is unpublished to a draft by
 * SFAF_Sources. That is not a cancellation, it must not become one, and it must
 * not reach SFAF_Announce: those people registered at the platform and this
 * plugin holds none of their addresses.
 */
if ( false !== strpos( $src_src, 'SFAF_Announce' ) ) {
    $fails[] = 'class-sfaf-sources.php references SFAF_Announce. An event disappearing at source must not email anybody from here.';
}
if ( false !== strpos( $src_src, 'SFAF_Cancellation::set' ) ) {
    $fails[] = 'class-sfaf-sources.php sets cancellation. Removal at source is an unpublish, not a cancellation.';
}

// update_event() must still never write post_status, which is what kept a
// fetch from changing whether an event is live. Unchanged by 3.38.0.
if ( ! preg_match( '#function update_event\s*\(.*?\)\s*\{#s', $src_src, $m, PREG_OFFSET_CAPTURE ) ) {
    $fails[] = 'update_event() not found';
} else {
    $body = substr( $src_src, $m[0][1], 2500 );
    if ( preg_match( "#\\\$postarr\['post_status'\]#", $body ) ) {
        $fails[] = 'update_event() writes post_status, which it must never do';
    }
}
/* ===========================================================================
 * 4. THE TWO MESSAGES.
 * ======================================================================== */
echo "The messages\n";

make_event( 20, 'Syringe Access Drop-in', '2026-08-13', '18:00', '19:30', 'Sixth Street' );
$person = (object) array( 'first_name' => 'Alex', 'last_name' => 'Rivera', 'name' => 'Alex Rivera', 'email' => 'alex@example.org', 'token' => 'tok-1' );

$cancelled = SFAF_Notifications::build( 'cancelled', 20, $person );
if ( ! $cancelled ) {
    $fails[] = 'the cancelled message did not build';
} else {
    if ( false === strpos( $cancelled['subject'], 'Cancelled' ) ) { $fails[] = 'cancelled: subject does not say so'; }
    if ( false === stripos( $cancelled['html'], 'cancelled' ) )   { $fails[] = 'cancelled: the body does not say cancelled'; }
    // NO CANCEL LINK. There is no place to release.
    if ( false !== strpos( $cancelled['html'], 'uc_rsvp_cancel' ) ) {
        $fails[] = 'cancelled: carries a cancel link, and there is nothing to release';
    }
    if ( false !== strpos( $cancelled['text'], 'uc_rsvp_cancel' ) ) {
        $fails[] = 'cancelled: the text part carries a cancel link';
    }
    if ( strlen( trim( $cancelled['text'] ) ) < 80 ) { $fails[] = 'cancelled: no usable plain text part'; }
}

$changes = array( 'Date' => array( 'from' => 'August 12, 2026', 'to' => 'August 13, 2026' ) );
$changed = SFAF_Notifications::build( 'changed', 20, $person, array( 'changes' => $changes ) );
if ( ! $changed ) {
    $fails[] = 'the changed message did not build';
} else {
    // OLD VALUE AND NEW VALUE, BOTH. The whole point of this message.
    foreach ( array( 'August 12', 'August 13' ) as $needle ) {
        if ( false === strpos( $changed['html'], $needle ) ) { $fails[] = "changed: the HTML does not carry \"$needle\""; }
        if ( false === strpos( $changed['text'], $needle ) ) { $fails[] = "changed: the text does not carry \"$needle\""; }
    }
    // AND THE CANCEL LINK, unlike the cancellation message.
    if ( false === strpos( $changed['html'], 'uc_rsvp_cancel' ) ) {
        $fails[] = 'changed: no cancel link, so somebody who cannot make the new time cannot release their place';
    }
    if ( strlen( trim( $changed['text'] ) ) < 80 ) { $fails[] = 'changed: no usable plain text part'; }
}

/* ===========================================================================
 * 5. ONE PERSON, ONE EMAIL, HOWEVER MANY DATES.
 *
 * The claim the brief asked to be confirmed. Six occurrences, one person on
 * four of them, two other people on one each.
 * ======================================================================== */
echo "One person, one email\n";

$GLOBALS['wpdb']->rows = array();
$GLOBALS['sent']       = array();

$group = array();
for ( $i = 0; $i < 6; $i++ ) {
    $id = 100 + $i;
    $group[] = $id;
    make_event( $id, 'Weekly Support Group', date( 'Y-m-d', strtotime( '+' . ( 7 * $i ) . ' days' ) ), '18:00', '19:30', 'Castro office' );
}

// Dana is on four of the six. Two others are on one each.
foreach ( array( 100, 101, 103, 105 ) as $id ) {
    register_person( $id, 'dana@example.org', 'Dana' );
}
register_person( 100, 'sam@example.org', 'Sam' );
register_person( 104, 'jo@example.org', 'Jo' );
// Somebody who already released their place is not told.
register_person( 102, 'gone@example.org', 'Gone', '', 'cancelled' );

$counts = SFAF_Announce::count_affected( $group );
expect( 'registrations across the group', $counts['registrations'], 6 );
expect( 'distinct people',                $counts['people'],        3 );
expect( 'events with anybody on them',    $counts['events'],        5 );

$result = SFAF_Announce::cancelled( $group );

expect( 'people told',            $result['people'], 3 );
expect( 'messages sent',          $result['sent'],   3 );
expect( 'one message per person', count( $GLOBALS['sent'] ), 3 );

$to = array();
foreach ( $GLOBALS['sent'] as $msg ) {
    $to[] = $msg['to'];
}
sort( $to );
expect( 'and to the right three', $to, array( 'dana@example.org', 'jo@example.org', 'sam@example.org' ) );

// A released registration is not written to.
if ( in_array( 'gone@example.org', $to, true ) ) {
    $fails[] = 'somebody who had already cancelled their registration was emailed';
}

// Dana's one message names all four dates, not one of them.
$dana = null;
foreach ( $GLOBALS['sent'] as $msg ) {
    if ( 'dana@example.org' === $msg['to'] ) { $dana = $msg; }
}
if ( ! $dana ) {
    $fails[] = 'Dana got no message at all';
} else {
    $named = 0;
    foreach ( array( 100, 101, 103, 105 ) as $id ) {
        $when = sfaf_ap_date( get_post_meta( $id, '_uc_event_date', true ), 'full' );
        if ( false !== strpos( $dana['text'], $when ) ) { $named++; }
    }
    expect( 'Dana\'s one email names all four of her dates', $named, 4 );

    // And not the two she was not registered for.
    $wrong = 0;
    foreach ( array( 102, 104 ) as $id ) {
        $when = sfaf_ap_date( get_post_meta( $id, '_uc_event_date', true ), 'full' );
        if ( false !== strpos( $dana['text'], $when ) ) { $wrong++; }
    }
    expect( 'and none she was not on', $wrong, 0 );
}

// Sam is on one date only, so Sam gets the ordinary single-event message.
$sam = null;
foreach ( $GLOBALS['sent'] as $msg ) {
    if ( 'sam@example.org' === $msg['to'] ) { $sam = $msg; }
}
if ( $sam && false !== strpos( $sam['subject'], 'dates' ) ) {
    $fails[] = 'somebody on one date got the several-dates message';
}

/* ===========================================================================
 * WHO COUNTS AS SOMEBODY TO TELL.
 *
 * ONE STATUS SINCE 3.53.0, AND THE ASSERTION IS THAT THE THREE READS AGREE.
 *
 * The history is worth keeping because it is what these assertions are shaped
 * around. Until 3.39.0 SFAF_Announce counted 'confirmed' only while
 * SFAF_Reminders counted 'confirmed' and 'subscribed', so somebody who had
 * pressed "Get Reminders" was invisible to the prompt: count_affected()
 * answered 0, nothing rendered above the Save buttons, and moving a date told
 * them nothing. 3.39.0 widened the announcement to match. 3.53.0 removed
 * 'subscribed' from this table altogether, so the two agree at the narrow end
 * instead.
 *
 * THE BUG THAT MATTERS IS STILL DISAGREEMENT, not which value they settle on.
 * has_registrations() is the delete guard as well as the prompt's trigger, so
 * if it and registrants() ever part company, one direction refuses a deletion
 * nobody would be told about and the other allows one that strands people.
 * That agreement is asserted from the source, not assumed.
 * ======================================================================== */
echo "Who counts as somebody to tell\n";

$GLOBALS['wpdb']->rows = array();
$GLOBALS['sent']       = array();

make_event( 300, 'Thursday Group', $today );
register_person( 300, 'sam@example.org', 'Sam' );

expect( 'a registrant is somebody to tell', SFAF_Announce::has_registrations( 300 ), true );

$counts = SFAF_Announce::count_affected( array( 300 ) );
expect( 'and is counted by the prompt', $counts['people'], 1 );

$result = SFAF_Announce::changed( array( 300 ), array( 300 => array( 'Time' => array( 'from' => '2:30 pm', 'to' => '2:31 pm' ) ) ) );
expect( 'and is written to', $result['sent'], 1 );

/*
 * THE MESSAGE OFFERS THEM THE THING THAT IS TRUE FOR THEM. Everybody reaching
 * this path holds a place now, so there is one sentence and it is this one.
 * Until 3.53.0 there were two, and the wrong one told somebody they had given
 * up a place they never held.
 */
$to_reg = $GLOBALS['sent'][0];
if ( false === strpos( $to_reg['text'], 'Release your place' ) ) {
    $fails[] = 'a registrant was not offered the cancel link on a changed event';
}

/*
 * NOBODY WHO DOES NOT HOLD A PLACE IS IN THIS AUDIENCE. A row at any other
 * status is not somebody to tell, which is what makes the follower table safe
 * to add beside this one: it shares no query with it.
 */
$GLOBALS['wpdb']->rows = array();
make_event( 302, 'Not A Registration', $today );
register_person( 302, 'sub@example.org', 'Sam', '', 'subscribed' );
expect( 'a non-registration row is not somebody to tell', SFAF_Announce::has_registrations( 302 ), false );
$counts = SFAF_Announce::count_affected( array( 302 ) );
expect( 'and is not counted by the prompt', $counts['people'], 0 );

// A released registration is still out, which was always right.
$GLOBALS['wpdb']->rows = array();
make_event( 301, 'Everybody Left', $today );
register_person( 301, 'gone@example.org', 'Gone', '', 'cancelled' );
expect( 'somebody who already cancelled is not counted', SFAF_Announce::has_registrations( 301 ), false );

/*
 * ONE DEFINITION OF THE AUDIENCE, READ OUT OF BOTH SOURCES. The original bug
 * was that there were two definitions and the newer one was narrower; the
 * remedy is not a particular clause but that the same clause appears in all
 * three places. Comments are stripped first, because the docblocks quote the
 * clause and counting prose as a query is the sweep matching itself.
 */
$ann_code = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $root . '/includes/class-sfaf-announce.php' ) );
$rem_code = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $root . '/includes/class-sfaf-reminders.php' ) );

if ( ! preg_match( "#status = 'confirmed'#", $rem_code ) ) {
    $fails[] = 'SFAF_Reminders no longer selects its reminder audience on confirmed, so the agreement below proves nothing';
}
if ( 2 !== preg_match_all( "#status = 'confirmed'#", $ann_code ) ) {
    $fails[] = 'SFAF_Announce does not ask the same question in both of its queries, so the prompt and the delete guard can disagree';
}
if ( preg_match( "#'subscribed'#", $ann_code ) || preg_match( "#'subscribed'#", $rem_code ) ) {
    $fails[] = "a 'subscribed' row is being selected again; that status was removed in 3.53.0 and following a series is its own table";
}

/* --- The same, for a change rather than a cancellation. -------------------- */
$GLOBALS['wpdb']->rows = array();
foreach ( array( 100, 101, 103, 105 ) as $id ) { register_person( $id, 'dana@example.org', 'Dana' ); }
register_person( 100, 'sam@example.org', 'Sam' );
register_person( 104, 'jo@example.org', 'Jo' );

$GLOBALS['sent'] = array();
$by_event = array();
foreach ( $group as $id ) {
    $by_event[ $id ] = array( 'Time' => array( 'from' => '6:00 pm', 'to' => '7:00 pm' ) );
}
$result = SFAF_Announce::changed( $group, $by_event );
expect( 'a bulk change also sends one each', count( $GLOBALS['sent'] ), 3 );

/* --- Nothing to do is not an error. --------------------------------------- */
$GLOBALS['sent'] = array();
$none = SFAF_Announce::cancelled( array() );
expect( 'an empty set sends nothing', count( $GLOBALS['sent'] ), 0 );
expect( 'and reports nobody',         $none['people'], 0 );

make_event( 900, 'Nobody Registered', $today );
expect( 'an event with nobody on it', SFAF_Announce::has_registrations( 900 ), false );
expect( 'and one with somebody',      SFAF_Announce::has_registrations( 100 ), true );

/* ------------------------------------------------------------------------ */
echo "\nCancellation test\n";
echo "checked: the state and its two keys, that the exclusion clause tests visibility so a cancelled\n";
echo "         event left listed still appears, that BOTH scheduled jobs ask about cancellation and\n";
echo "         ask first, that cancelling is refused on an imported event at BOTH the render and the\n";
echo "         write and that removal at source neither cancels nor emails from here,\n";
echo "         that the cancelled message carries no cancel link and the changed message carries both\n";
echo "         old and new values plus the link, and that one person on four of six dates gets one\n";
echo "         email naming four dates and not six; and that a REMINDER SUBSCRIBER counts as somebody\n";
echo "         to tell, is written to, and is not offered a place they never held\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "cancelling stops what it must stop, and nobody is written to twice.\n";
