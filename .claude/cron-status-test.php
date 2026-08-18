<?php
/**
 * WHAT THE AUTOMATION SCREEN SAYS, FOR EACH STATE IT CAN BE IN.
 *
 * WHY THIS EXISTS. 3.34.0 put a banner on the Automation screen that answers
 * "are scheduled tasks running?" in a sentence, and a table saying when each job
 * last ran and when it is next due. Somebody setting up an external scheduler
 * reads it to find out whether the ping worked, and somebody six months later
 * reads it to find out why reminders stopped. A screen that answers that
 * question WRONGLY is worse than the old one that did not answer it at all,
 * because both of those people would believe it.
 *
 * None of it could be checked by looking: every state but one needs a site that
 * has been broken for hours. So the states are constructed here instead, by
 * writing the same two options the real code reads, and the answers are asserted.
 *
 *     php .claude/cron-status-test.php
 *
 * WHAT IT STUBS. WordPress, and only the dozen functions SFAF_Cron's reporting
 * half calls. SFAF_Cron itself is the real file. Stub signatures match the real
 * ones including optional arguments, because a stub taking fewer is a stub the
 * callable audit reads as the definition and then reports every correct call
 * site as an arity error.
 */

$root = dirname( __DIR__ );

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'America/Los_Angeles' );

/* ---------------------------------------------------------------------------
 * WordPress, in miniature.
 * ------------------------------------------------------------------------ */
$GLOBALS['opt']   = array();
$GLOBALS['sched'] = 0;

function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) { $GLOBALS['opt'][ $name ] = $value; return true; }
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
    if ( array_key_exists( $name, $GLOBALS['opt'] ) ) { return false; }
    $GLOBALS['opt'][ $name ] = $value; return true;
}
function delete_option( $name ) { unset( $GLOBALS['opt'][ $name ] ); return true; }
function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['sched']; }
function wp_get_schedule( $hook, $args = array() ) { return $GLOBALS['sched'] ? 'sfaf_quarter_hour' : false; }
function wp_schedule_event( $ts, $rec, $hook, $args = array(), $wp_error = false ) { $GLOBALS['sched'] = $ts; return true; }
function wp_unschedule_event( $ts, $hook, $args = array(), $wp_error = false ) { $GLOBALS['sched'] = 0; return true; }
function has_filter( $tag, $function_to_check = false ) { return false; }
function add_filter( $tag, $cb, $priority = 10, $accepted_args = 1 ) { return true; }
function add_action( $tag, $cb, $priority = 10, $accepted_args = 1 ) { return true; }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function __return_true() { return true; }
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $GLOBALS['sent'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $message );
    return true;
}
function get_bloginfo( $show = '', $filter = 'raw' ) { return 'SFAF Calendar'; }
function wp_specialchars_decode( $string, $quote_style = ENT_NOQUOTES ) { return $string; }
function current_user_can( $cap, ...$args ) { return true; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function admin_url( $path = '', $scheme = 'admin' ) { return 'https://example.org/wp-admin/' . $path; }
function site_url( $path = '', $scheme = null ) { return 'https://example.org' . $path; }
function add_query_arg( $key, $value = '', $url = '' ) { return is_array( $key ) ? $url . '?x' : $url . '?' . $key . '=' . $value; }
function nocache_headers() {}
function status_header( $code, $description = '' ) {}
function wp_die( $m = '', $t = '', $a = array() ) {}
/*
 * THE ONE FORMATTER IS NOT RE-IMPLEMENTED HERE, DELIBERATELY.
 *
 * Spelling out "M j, Y \a\t g:i a" would be a second copy of the house date
 * style living in a test, which is the exact thing date-callsite-sweep.php
 * exists to prevent, and it duly caught this line when it was written that way.
 * Nothing below asserts on how a date reads, so the stub returns a machine
 * timestamp and says so.
 */
function sfaf_ap_datetime( $ts, $fmt = '' ) { return gmdate( 'c', (int) $ts ); }

/** WordPress's own, close enough for the readings asserted below. */
function human_time_diff( $from, $to = 0 ) {
    $to   = $to ? $to : time();
    $diff = (int) abs( $to - $from );
    if ( $diff < 3600 ) { $m = max( 1, (int) round( $diff / 60 ) ); return $m . ' min' . ( 1 === $m ? '' : 's' ); }
    if ( $diff < 86400 ) { $h = max( 1, (int) round( $diff / 3600 ) ); return $h . ' hour' . ( 1 === $h ? '' : 's' ); }
    $d = max( 1, (int) round( $diff / 86400 ) ); return $d . ' day' . ( 1 === $d ? '' : 's' );
}

/* The two task owners. Only the shape SFAF_Cron asks of them matters here. */
class SFAF_Reminders {
    public static $on = true;
    public static function enabled() { return self::$on; }
    public static function run() { return array( 'status' => 'ok', 'summary' => 'Nothing due today.', 'counts' => array() ); }
}
class SFAF_Notifications {
    public static function run_summaries() { return array( 'status' => 'ok', 'summary' => 'Nothing due.', 'counts' => array() ); }
}
class SFAF_Sources {
    public static function fetch_all() { return array(); }
    public static function summarize( $r ) { return ''; }
}

require_once $root . '/includes/class-sfaf-cron.php';

/* ---------------------------------------------------------------------------
 * The harness.
 * ------------------------------------------------------------------------ */
$fails = array();
$MIN   = 60;
$HOUR  = 3600;

function reset_site() {
    $GLOBALS['opt']   = array();
    $GLOBALS['sched'] = time() + 900;
    $GLOBALS['sent']  = array();
    SFAF_Reminders::$on = true;
}

function is( $label, $got, $want ) {
    global $fails;
    if ( $got !== $want ) {
        $fails[] = sprintf( '%s: got %s, expected %s', $label, var_export( $got, true ), var_export( $want, true ) );
    }
}

function contains( $label, $haystack, $needle ) {
    global $fails;
    if ( false === strpos( (string) $haystack, $needle ) ) {
        $fails[] = sprintf( '%s: "%s" is not in %s', $label, $needle, var_export( $haystack, true ) );
    }
}

/* ---------------------------------------------------------------------------
 * 1. THE FOUR STATES.
 *
 * The thresholds are read from the class rather than written out again, so a
 * change to either one moves the test with it instead of leaving it asserting
 * a number nobody uses.
 * ------------------------------------------------------------------------ */
echo "The four states\n";

reset_site();
$s = SFAF_Cron::status();
is( 'never: state', $s['state'], 'never' );
contains( 'never: headline', $s['headline'], 'never run' );

reset_site();
update_option( 'sfaf_cron_last_success', time() - 5 * $MIN );
$s = SFAF_Cron::status();
is( 'working: state', $s['state'], 'working' );
contains( 'working: says how long ago', $s['detail'], 'ago' );

reset_site();
update_option( 'sfaf_cron_last_success', time() - ( SFAF_Cron::BEHIND_AFTER + 5 * $MIN ) );
$s = SFAF_Cron::status();
is( 'behind: state', $s['state'], 'behind' );

reset_site();
update_option( 'sfaf_cron_last_success', time() - ( SFAF_Cron::STALE_AFTER + $HOUR ) );
$s = SFAF_Cron::status();
is( 'stopped: state', $s['state'], 'stopped' );
contains( 'stopped: names the consequence', $s['detail'], 'not going out' );

reset_site();
update_option( 'sfaf_cron_last_success', time() - 5 * $MIN );
update_option( 'sfaf_cron_failures', SFAF_Cron::FAIL_NOTICE_AT );
$s = SFAF_Cron::status();
is( 'failing beats recent success', $s['state'], 'stopped' );

/*
 * THE BOUNDARY, IN BOTH DIRECTIONS. An off-by-one here means the screen goes
 * amber a run early or a run late, and neither is visible by looking at it.
 */
reset_site();
update_option( 'sfaf_cron_last_success', time() - ( SFAF_Cron::BEHIND_AFTER - 30 ) );
is( 'just inside the behind threshold is still working', SFAF_Cron::status()['state'], 'working' );

reset_site();
update_option( 'sfaf_cron_last_success', time() - ( SFAF_Cron::STALE_AFTER - 30 ) );
is( 'just inside the stale threshold is only behind', SFAF_Cron::status()['state'], 'behind' );

/*
 * THE SCREEN GOES AMBER BEFORE THE EMAIL FIRES, WHICH IS THE WHOLE POINT OF
 * HAVING TWO NUMBERS. If somebody ever sets them equal, this is what says so.
 */
if ( SFAF_Cron::BEHIND_AFTER >= SFAF_Cron::STALE_AFTER ) {
    $fails[] = 'BEHIND_AFTER is not shorter than STALE_AFTER, so the screen cannot warn before the email';
}

/* ---------------------------------------------------------------------------
 * 2. "IS THIS WORKING?" AND THE ALERT MUST NEVER DISAGREE.
 *
 * They read the same two options and divide them differently. The rule that
 * matters: every state health() calls alertable must read as stopped on the
 * screen, and nothing the screen calls working may be alertable.
 * ------------------------------------------------------------------------ */
echo "The screen and the alert agree\n";

$cases = array(
    'fresh'          => array( 'ok' => 5 * $MIN,                              'fail' => 0 ),
    'behind'         => array( 'ok' => SFAF_Cron::BEHIND_AFTER + $MIN,        'fail' => 0 ),
    'stale'          => array( 'ok' => SFAF_Cron::STALE_AFTER + $MIN,         'fail' => 0 ),
    'failing'        => array( 'ok' => $MIN,                                  'fail' => SFAF_Cron::FAIL_NOTICE_AT ),
);
foreach ( $cases as $name => $c ) {
    reset_site();
    update_option( 'sfaf_cron_last_success', time() - $c['ok'] );
    update_option( 'sfaf_cron_failures', $c['fail'] );

    $screen    = SFAF_Cron::status();
    $alertable = in_array( SFAF_Cron::health()['state'], array( 'failing', 'stale' ), true );

    if ( $alertable && 'stopped' !== $screen['state'] ) {
        $fails[] = "$name: the alert would fire but the screen says '{$screen['state']}'";
    }
    if ( 'working' === $screen['state'] && $alertable ) {
        $fails[] = "$name: the screen says working while the alert fires";
    }
}

/* ---------------------------------------------------------------------------
 * 3. THE TASK TABLE.
 * ------------------------------------------------------------------------ */
echo "The task table\n";

reset_site();
$report = SFAF_Cron::task_report();
is( 'every task in tasks() is reported', count( $report ), count( SFAF_Cron::tasks() ) );

foreach ( $report as $row ) {
    if ( '' === trim( $row['plain'] ) ) {
        $fails[] = "{$row['key']}: has no plain-language sentence, so the screen shows a bare machine name";
    }
    // §6 of CLAUDE.md: helper text says what to do or what will happen.
    if ( false !== stripos( $row['plain'], 'because' ) ) {
        $fails[] = "{$row['key']}: the plain sentence justifies a decision instead of saying what happens";
    }
    is( "{$row['key']}: never run yet", $row['last'], 0 );
}

/*
 * A RUN, THEN THE TABLE AGAIN. This is the assertion the screen exists for and
 * the one a mock cannot fake: run() really executes, really writes the log, and
 * task_report() really reads it back.
 */
reset_site();
SFAF_Cron::run( 'manual' );
$report = array_column( SFAF_Cron::task_report(), null, 'key' );

is( 'reminders ran', $report['reminders']['status'], 'ok' );
if ( ! $report['reminders']['last'] ) {
    $fails[] = 'reminders: ran but the table still says it never has';
}
is( 'reminders is on', $report['reminders']['on'], true );
is( 'fetch is off by default', $report['fetch']['on'], false );
is( 'a switched-off task has no next due date', $report['fetch']['next'], 0 );
if ( '' === $report['fetch']['off'] ) {
    $fails[] = 'fetch: is switched off and the table says nothing about why';
}

/*
 * "LAST RAN" MEANS THE LAST TIME IT DID SOMETHING, NOT THE LAST TIME IT WAS
 * PASSED OVER. Switch reminders off, run again, and the timestamp must not move
 * to the run that skipped it.
 */
/*
 * THE LOG IS BACK-DATED FIRST, AND THAT IS NOT A DETAIL.
 *
 * Without it both runs are stamped in the same second, so the assertion below
 * passes whether or not the code ignores skipped entries. Planting the fault
 * (deleting the 'skipped' guard from task_report()) proved exactly that: the
 * test reported nothing. An hour is put between the two runs by rewriting the
 * stored entry, which is instant and does not make the suite sleep.
 */
$log = get_option( 'sfaf_cron_log', array() );
foreach ( $log as $i => $entry ) {
    $log[ $i ]['started_ts'] = $entry['started_ts'] - $HOUR;
}
update_option( 'sfaf_cron_log', $log );

$ran_at = SFAF_Cron::task_report()[0]['last'];
if ( $ran_at !== ( $report['reminders']['last'] - $HOUR ) ) {
    $fails[] = 'the back-dating did not take, so the next assertion proves nothing';
}

SFAF_Reminders::$on = false;
SFAF_Cron::run( 'manual' );
$after = array_column( SFAF_Cron::task_report(), null, 'key' );

is( 'a skipped run does not move "last ran"', $after['reminders']['last'], $ran_at );
is( 'a switched-off task reports it', $after['reminders']['on'], false );

// And the run itself did happen: the log grew even though the task stood down.
if ( count( SFAF_Cron::log() ) !== count( $log ) + 1 ) {
    $fails[] = 'the second run was not logged, so "last ran" did not move for the wrong reason';
}

/* ---------------------------------------------------------------------------
 * 4. THE RELATIVE CLOCK.
 * ------------------------------------------------------------------------ */
echo "The relative clock\n";

is( 'never', SFAF_Cron::ago( 0 ), 'never' );
is( 'just now', SFAF_Cron::ago( time() - 5 ), 'just now' );
contains( 'past reads as ago', SFAF_Cron::ago( time() - 20 * $MIN ), 'ago' );
contains( 'future reads as in', SFAF_Cron::ago( time() + 20 * $MIN ), 'in ' );
if ( false !== strpos( SFAF_Cron::ago( time() + 20 * $MIN ), 'ago' ) ) {
    $fails[] = 'a future time reads as "ago", which is the wrong direction';
}

/* ---------------------------------------------------------------------------
 * 5. THE SCHEDULE, AND THE MIGRATION OFF hourly.
 * ------------------------------------------------------------------------ */
echo "The schedule\n";

$schedules = SFAF_Cron::add_schedule( array() );
if ( ! isset( $schedules[ SFAF_Cron::SCHEDULE ] ) ) {
    $fails[] = 'the recurrence is not on the cron_schedules filter, so wp_cron() would unschedule the event';
} else {
    is( 'the interval is what the constant says', $schedules[ SFAF_Cron::SCHEDULE ]['interval'], SFAF_Cron::SCHEDULE_EVERY );
}

// The interval must be short enough for the job that needs it. The pre-event
// summary is due two hours before an event; anything longer than the nudge's own
// window makes a real scheduler less accurate than a page view, which is absurd.
if ( SFAF_Cron::SCHEDULE_EVERY > SFAF_Cron::PING_EVERY ) {
    $fails[] = 'the scheduled interval is longer than the page-view nudge interval';
}

// Nothing scheduled: it lays one.
reset_site();
$GLOBALS['sched'] = 0;
SFAF_Cron::ensure_scheduled();
if ( ! $GLOBALS['sched'] ) {
    $fails[] = 'ensure_scheduled() left the runner unscheduled';
}

/*
 * THE MIGRATION. An install from before 3.34.0 has this hook on 'hourly'.
 * Checking only "is it scheduled?" finds the event present and leaves it there
 * forever, which is the whole reason wp_get_schedule() is consulted.
 */
reset_site();
$GLOBALS['sched'] = time() + 1800;
$legacy = true;
// A one-off stand-in for an install still on the old recurrence.
if ( ! function_exists( 'wp_get_schedule_legacy' ) ) {
    // wp_get_schedule() above answers 'sfaf_quarter_hour' whenever something is
    // scheduled, so the legacy case is exercised by asserting the guard's shape
    // rather than by redefining a function mid-run.
    $src = file_get_contents( $root . '/includes/class-sfaf-cron.php' );
    if ( false === strpos( $src, 'wp_get_schedule( self::HOOK ) !== self::SCHEDULE' ) ) {
        $fails[] = 'ensure_scheduled() no longer checks WHICH recurrence the hook is on, so an old install never migrates';
    }
    if ( false === strpos( $src, 'self::clear_schedule();' ) ) {
        $fails[] = 'the migration does not clear the old schedule before re-laying it';
    }
    // unschedule() drops the run lock; the migration must not, because it can
    // happen while a run is in progress and breaking that lock double-sends.
    if ( preg_match( '#!== self::SCHEDULE \) \{.*?self::unschedule\(\)#s', $src ) ) {
        $fails[] = 'the migration calls unschedule(), which drops the run lock of a run in progress';
    }
}

/* ---------------------------------------------------------------------------
 * 6. THE RUN LOCK STILL STANDS A SECOND RUN DOWN.
 *
 * Not new in 3.34.0, and checked because run() was rewritten around a list this
 * release and the lock is what stops two triggers double-sending.
 * ------------------------------------------------------------------------ */
echo "The run lock\n";

reset_site();
update_option( 'sfaf_cron_lock', time() );  // a run is in progress
$entry = SFAF_Cron::run( 'ping' );
is( 'a second run stands down', $entry['status'], 'skipped' );
contains( 'and says why', $entry['tasks'][0]['summary'], 'still in progress' );

/* ------------------------------------------------------------------------ */
echo "\nCron status test\n";
echo "checked: the four states and both threshold boundaries, that the screen and the alert email\n";
echo "         can never disagree, that every task carries a plain sentence, that a real run moves\n";
echo "         'last ran' and a skipped one does not, the relative clock in both directions, the\n";
echo "         15-minute recurrence and the migration off hourly, and the run lock\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "the Automation screen tells the truth in every state it can be in.\n";
