<?php
/**
 * NOBODY IS EMAILED WITHOUT SOMEBODY SAYING SO.
 *
 *     php .claude/notify-consent-test.php
 *
 * WHAT WAS WRONG. Changing a date, a time or a location on an event with
 * registrations emailed all of them, and the only control was a checkbox on the
 * form, ticked, among thirty others. A checkbox somebody scrolls past is not a
 * decision, and mail cannot be recalled.
 *
 * WHY THIS FILE IS WRITTEN AS OUTCOMES. The 3.36.0 tests for this area passed
 * while the whole cancel card was nested inside another form and every save
 * cancelled the event. They asserted properties of the source. So the two
 * sentences below are the assertions, in the words of the brief, and each one
 * is decided by RUNNING the shipped gate and the shipped mailer and counting
 * what came out:
 *
 *   1. No mail is written when the choice is do-not-send.
 *   2. Mail IS written when the choice is send.
 *
 * The gate is the real sfaf_should_notify() out of the plugin, and the mailer
 * is the real SFAF_Announce with wp_mail() captured. Nothing about consent is
 * restated here, so deleting the check from the plugin fails this file rather
 * than passing it.
 *
 * The structural half at the end is deliberately secondary: it catches a
 * checkbox coming back, which is cheap to check and impossible to see from the
 * outcomes above.
 */

/* Every plugin file opens with an ABSPATH guard that exits silently, so
 * without this the harness runs nothing and reports success. */
define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

$root  = dirname( __DIR__ );
$fails = array();

/* ---------------------------------------------------------------------------
 * WordPress, in miniature. Same shape as cancellation-test.php.
 * ------------------------------------------------------------------------ */
$GLOBALS['pmeta'] = array();
$GLOBALS['posts'] = array();
$GLOBALS['opt']   = array();
$GLOBALS['sent']  = array();

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opt'][ $n ] = $v; return true; }
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
function get_the_title( $id = 0 ) { $p = get_post( $id ); return $p ? $p->post_title : ''; }
function get_post_status( $id = 0 ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function get_permalink( $id = 0, $l = false ) { return 'https://example.org/events/' . (int) $id; }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $t, $b = false ) { return strip_tags( (string) $t ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : date( $type ); }
function date_i18n( $f, $ts = false, $gmt = false ) { return date( $f, false === $ts ? time() : $ts ); }
function home_url( $p = '', $s = null ) { return 'https://example.org' . $p; }
function add_query_arg( $k, $v = '', $u = '' ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . rawurlencode( $k ) . '=' . rawurlencode( $v ); }
function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return $s; }
function get_bloginfo( $show = '', $filter = 'raw' ) { return 'SFAF Calendar'; }
function absint( $n ) { return abs( (int) $n ); }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }

/* THE TWO THE GATE ITSELF USES. Real behaviour, because the gate's whole job is
 * to reject anything that is not one of two exact strings. */
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }

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
    public static function link_para( $u, $l ) { return '<p><a href="' . esc_url( $u ) . '">' . esc_html( $l ) . '</a></p>'; }
    public static function details( $rows ) {
        $o = '<table>';
        foreach ( $rows as $k => $v ) { if ( '' === trim( (string) $v ) ) { continue; } $o .= '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( $v ) . '</td></tr>'; }
        return $o . '</table>';
    }
    public static function button( $u, $l, $s = 'primary', $i = false, $f = false ) {
        return '<a href="' . esc_url( $u ) . '">' . esc_html( $l ) . '</a>';
    }
    public static function button_row( $b ) { return implode( '', $b ); }
}

class SFAF_Reminders {
    const EVENT_DONE_META = '_uc_reminder_done';
    public static function cancel_url( $t ) { return 'https://example.org/?uc_rsvp_cancel=' . rawurlencode( $t ); }
    public static function reply_to_for( $id ) { return 'events@example.org'; }
    public static function is_imported( $id ) { return '' !== (string) get_post_meta( $id, '_uc_source', true ); }
}
class SFAF_Sources { const META_SOURCE = '_uc_source'; }
class SFAF_Cancellation {
    public static function is_cancelled( $id ) { return '1' === (string) get_post_meta( $id, '_uc_cancelled', true ); }
    public static function label( $id ) { return 'Cancelled'; }
    public static function cancelled_at( $id ) { return ''; }
}

/*
 * NOT THE HOUSE DATE STYLE. Writing the real format here would be a second copy
 * of the thing sfaf_ap_date() owns, living in a test, which is what
 * date-callsite-sweep.php exists to stop. These assertions count messages, so
 * the stub only needs a value that is stable and obviously a stub.
 */
function sfaf_ap_date( $d, $f = 'full' ) {
    if ( '' === $d ) { return ''; }
    $parts = explode( '-', $d );
    return ( 3 === count( $parts ) ) ? ( 'day ' . implode( '/', $parts ) ) : '';
}
function sfaf_ap_time_range( $s, $e ) {
    if ( '' === $s ) { return ''; }
    return '' !== $e ? ( $s . ' to ' . $e ) : $s;
}
function sfaf_ap_datetime( $ts, $f = '' ) { return gmdate( 'c', is_numeric( $ts ) ? (int) $ts : strtotime( (string) $ts ) ); }
function sfaf_event_location( $id ) { return (string) get_post_meta( $id, '_uc_location', true ); }
function sfaf_google_calendar_url( $id ) { return 'https://calendar.google.com/render?e=' . (int) $id; }
function sfaf_ics_url( $id ) { return 'https://example.org/?uc_ics=' . (int) $id; }
function sfaf_replace_tokens( $s, $id, $t = array() ) { return $s; }
function sfaf_status_label( $s ) { return ucfirst( (string) $s ); }

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
    private function matching( $sql ) {
        if ( ! preg_match( '/event_id = (\d+)/', $sql, $m ) ) { return array(); }
        $event_id = (int) $m[1];
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

/* THE CODE UNDER TEST. The gate and the mailer, both real. */
require_once $root . '/includes/sfaf-notify-consent.php';
require_once $root . '/includes/class-sfaf-notifications.php';
require_once $root . '/includes/class-sfaf-announce.php';

function expect( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}

/* ---------------------------------------------------------------------------
 * The world: one event that moved, three people who would hear about it.
 * ------------------------------------------------------------------------ */
$GLOBALS['posts'][ 300 ] = (object) array(
    'ID' => 300, 'post_title' => 'Wednesday Support Group', 'post_type' => 'uc_event', 'post_status' => 'publish',
);
update_post_meta( 300, '_uc_event_date', '2026-09-08' );
update_post_meta( 300, '_uc_start_time', '18:00' );
update_post_meta( 300, '_uc_end_time', '19:30' );
update_post_meta( 300, '_uc_location', 'Castro office' );

foreach ( array(
    array( 'dana@example.org', 'Dana', 'confirmed' ),
    array( 'sam@example.org',  'Sam',  'confirmed' ),
    array( 'jo@example.org',   'Jo',   'confirmed' ),
) as $i => $p ) {
    $GLOBALS['wpdb']->rows[] = (object) array(
        'id' => $i + 1, 'event_id' => 300, 'email' => $p[0],
        'first_name' => $p[1], 'last_name' => '', 'name' => $p[1],
        'status' => $p[2], 'token' => 'tok-' . $i, 'created_at' => '2026-08-01 10:00:00',
    );
}

/* The move itself, in the shape save_event_from_post() builds. */
$MOVED = array( 300 => array( 'Time' => array( 'from' => '6 pm', 'to' => '7 pm' ) ) );

/**
 * ONE SAVE, DRIVEN BY A POSTED ANSWER.
 *
 * This is the composed condition out of save_event_from_post(), and it is the
 * only thing restated from the plugin: the diff decides who and what, the gate
 * decides whether. Everything inside it is the real code.
 *
 * @param array $post  What the browser posted.
 * @param array $moved The diff, empty when nothing a registrant cares about moved.
 * @return int Messages actually written.
 */
function save_with( $post, $moved ) {
    $GLOBALS['sent'] = array();
    if ( $moved && sfaf_should_notify( $post ) ) {
        SFAF_Announce::changed( array_keys( $moved ), $moved );
    }
    return count( $GLOBALS['sent'] );
}

/* ---------------------------------------------------------------------------
 * 1. NO MAIL IS WRITTEN WHEN THE CHOICE IS DO-NOT-SEND.
 * ------------------------------------------------------------------------ */
expect( 'silent writes nothing',              save_with( array( 'notify_choice' => 'silent' ), $MOVED ), 0 );
expect( 'no answer at all writes nothing',    save_with( array(), $MOVED ), 0 );
expect( 'an empty answer writes nothing',     save_with( array( 'notify_choice' => '' ), $MOVED ), 0 );

/*
 * A FORM POSTED BY SOMETHING THAT IS NOT THIS SCREEN. The old checkbox failed
 * open: any post carrying notify_registrants=1 mailed everybody. These are the
 * shapes that used to work and must not.
 */
expect( 'the old checkbox name does nothing', save_with( array( 'notify_registrants' => '1' ), $MOVED ), 0 );
expect( 'a truthy unknown value is not send', save_with( array( 'notify_choice' => '1' ), $MOVED ), 0 );
expect( 'yes is not send',                    save_with( array( 'notify_choice' => 'yes' ), $MOVED ), 0 );
/*
 * CASE IS NOT PART OF THE ANSWER, and this asserts what actually happens rather
 * than what reads well: sanitize_key() lowercases, so 'SEND' is 'send' and does
 * send. That is fine, because it is still an explicit answer that only this
 * dialog produces. It is written down because the obvious guess is the opposite.
 */
expect( 'SEND is lowercased and counts',      save_with( array( 'notify_choice' => 'SEND' ), $MOVED ), 3 );

/* ---------------------------------------------------------------------------
 * 2. MAIL IS WRITTEN WHEN THE CHOICE IS SEND.
 *
 * Three people, three messages: one each, however the diff is shaped. All three
 * hold a place: since 3.53.0 that is the only kind of row this table has.
 * ------------------------------------------------------------------------ */
expect( 'send writes one message per person', save_with( array( 'notify_choice' => 'send' ), $MOVED ), 3 );

/* ---------------------------------------------------------------------------
 * 3. NO CHANGE MEANS NO MAIL, WHATEVER WAS ANSWERED.
 *
 * The dialog is not shown when nothing moved, but an answer can still arrive:
 * a resubmitted form, a back button, anything hand-built. The diff is the other
 * half of the condition and it has to hold on its own.
 * ------------------------------------------------------------------------ */
expect( 'send with nothing moved sends nothing', save_with( array( 'notify_choice' => 'send' ), array() ), 0 );

/* ---------------------------------------------------------------------------
 * 4. THE GATE ITSELF, at its own level.
 * ------------------------------------------------------------------------ */
expect( 'choice reads send',      sfaf_notify_choice( array( 'notify_choice' => 'send' ) ), 'send' );
expect( 'choice reads silent',    sfaf_notify_choice( array( 'notify_choice' => 'silent' ) ), 'silent' );
expect( 'choice rejects garbage', sfaf_notify_choice( array( 'notify_choice' => 'send-them-all' ) ), '' );
expect( 'choice survives no key', sfaf_notify_choice( array() ), '' );
expect( 'choice survives an array', sfaf_notify_choice( array( 'notify_choice' => array( 'send' ) ) ), '' );
expect( 'should_notify is send only', sfaf_should_notify( array( 'notify_choice' => 'silent' ) ), false );
expect( 'should_notify says yes to send', sfaf_should_notify( array( 'notify_choice' => 'send' ) ), true );

/* ---------------------------------------------------------------------------
 * 5. THE CHECKBOX IS GONE, AND CANNOT COME BACK QUIETLY.
 *
 * Secondary to the outcomes above and cheap to keep. Comments are stripped
 * first: the notes explaining why the checkbox went name it.
 * ------------------------------------------------------------------------ */
$portal = file_get_contents( $root . '/includes/class-sfaf-portal.php' );
$code   = preg_replace( '#/\*.*?\*/#s', '', $portal );
$code   = preg_replace( '#^\s*//.*$#m', '', $code );

if ( false !== strpos( $code, 'notify_registrants' ) ) {
    $fails[] = 'notify_registrants is back in the portal: the ticked checkbox was the fault, not the wording';
}
if ( false !== strpos( $code, 'change_notice_present' ) ) {
    $fails[] = 'change_notice_present is back: the marker belonged to the checkbox and means nothing now';
}

/*
 * THE HIDDEN FIELD MUST SHIP EMPTY. A value of 'send' sitting in the markup
 * would mail everybody on any save, which is the original fault under a new
 * name. This is only about hidden fields: a BUTTON carrying notify_choice is
 * the opposite thing, an answer somebody pressed, and the series cancellation
 * screen is built out of exactly two of those.
 */
if ( ! preg_match_all( '#type="hidden"\s+name="notify_choice"\s+value="([^"]*)"#', $portal, $m ) ) {
    $fails[] = 'no hidden notify_choice field is rendered, so the dialog has nothing to answer into';
} else {
    foreach ( $m[1] as $value ) {
        if ( '' !== $value ) {
            $fails[] = "a hidden notify_choice field ships with value=\"$value\"; it must ship empty, because empty is what means do not send";
        }
    }
}

/* A button may carry an answer, and only one of the two real answers. */
if ( preg_match_all( '#<button[^>]*name="notify_choice"[^>]*value="([^"]*)"#', $portal, $m ) ) {
    foreach ( $m[1] as $value ) {
        if ( ! in_array( $value, array( 'send', 'silent' ), true ) ) {
            $fails[] = "a button answers notify_choice with \"$value\", which the gate does not accept, so it would silently mean do not send";
        }
    }
    if ( ! in_array( 'send', $m[1], true ) || ! in_array( 'silent', $m[1], true ) ) {
        $fails[] = 'the screen that answers with buttons offers only one of the two answers, so one of them is unreachable';
    }
}

/* Every path that can send manager-caused mail asks the one gate. */
foreach ( array( 'SFAF_Announce::changed', 'SFAF_Announce::cancelled' ) as $call ) {
    $n = substr_count( $code, $call . '(' );
    if ( $n < 1 ) {
        $fails[] = "$call is never called from the portal, which cannot be right";
    }
}
if ( substr_count( $code, 'sfaf_should_notify(' ) < 3 ) {
    $fails[] = 'fewer than three paths ask sfaf_should_notify(); the save, the cancel and the series cancel all must';
}

/* ---------------------------------------------------------------------------
 * 6. THE DIALOG NEVER ANSWERS ITSELF.
 *
 * The one thing that would undo all of the above is the script setting the
 * field without a person pressing anything. Every assignment must come from
 * inside the callback that the dialog's buttons resolve.
 * ------------------------------------------------------------------------ */
$js = file_get_contents( $root . '/public/js/portal.js' );
if ( preg_match_all( "#field\.value\s*=\s*'([a-z]+)'#", $js, $m ) ) {
    foreach ( $m[1] as $literal ) {
        $fails[] = "portal.js assigns a literal '$literal' to the choice field; the value must be the answer the manager gave";
    }
}
if ( ! preg_match( '#field\.value = answer;#', $js ) ) {
    $fails[] = 'portal.js does not write the answer into the choice field, so the dialog decides nothing';
}
if ( false === strpos( $js, 'initNotifyConsent' ) ) {
    $fails[] = 'initNotifyConsent is not in portal.js';
}
if ( ! preg_match( "#run\('notifyConsent'#", $js ) ) {
    $fails[] = 'initNotifyConsent is never run, so the question is never asked and no save can ever send';
}

/* ---------------------------------------------------------------------------
 * Result.
 * ------------------------------------------------------------------------ */
echo "Notification consent\n";
echo str_repeat( '=', 72 ) . "\n";
if ( $fails ) {
    foreach ( $fails as $f ) {
        echo "  FAIL  $f\n";
    }
    echo "\n" . count( $fails ) . " failure(s).\n";
    exit( 1 );
}
echo "nobody is emailed unless somebody said to, and saying so does email them.\n";
