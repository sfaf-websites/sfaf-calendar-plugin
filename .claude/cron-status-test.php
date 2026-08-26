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
/*
 * THE STUB MODELS THE REAL API, AND DID NOT.
 *
 * It carried fetch_all(), which SFAF_Sources has not had for a long time, and
 * no run_all(), which is what SFAF_Cron::run_fetch() actually calls. Nothing
 * caught it because the fetch task is switched off in every case this file had,
 * so run_fetch() was never reached. A stub that answers a method the real class
 * does not have is the "a stub removes the hooks it replaced" fault in another
 * costume: the test passes and proves nothing about the code that ships.
 *
 * $results is what the next run_all() returns, in the shape run_fetch() reads.
 */
class SFAF_Sources {
    public static $results = array();
    public static function run_all() { return self::$results; }
    public static function adapters() { return self::$results; }

    /* The queue sweep is a task in its own right, so the runner calls it on
       every pass. Without it here run_task() caught the "undefined method"
       and recorded the task as failed, which is a passing test reporting a
       broken job — the same stale-stub trap this file was bitten by once. */
    public static $swept = 0;
    public static function sweep_queues() {
        self::$swept++;
        return array( 'status' => 'ok', 'summary' => 'Queues: nothing to clear.', 'counts' => array() );
    }

    /* The real summarize()'s three shapes, short. run_fetch() stores whatever
       comes back verbatim, so the exact words do not matter here; which of the
       three branches produced them does. */
    public static function summarize( $r ) {
        if ( ! empty( $r['skipped'] ) ) { return $r['label'] . ': not connected. ' . $r['reason']; }
        if ( '' !== $r['error'] ) { return $r['label'] . ': failed. ' . $r['error']; }
        if ( 0 === (int) $r['fetched'] ) { return $r['label'] . ': connected, but the source returned nothing at all.'; }
        return $r['label'] . ': nothing new. ' . (int) $r['fetched'] . ' checked.';
    }
}

/** One result row in the shape run_fetch() reads. */
function src( $label, $state = 'ok', $fetched = 4 ) {
    return array(
        'label'   => $label,
        'skipped' => ( 'skipped' === $state ),
        'reason'  => ( 'skipped' === $state ) ? 'no API key' : '',
        'error'   => ( 'failed' === $state ) ? 'the platform returned 503' : '',
        'fetched' => ( 'ok' === $state ) ? $fetched : 0,
        'new'     => 0, 'updated' => 0, 'unchanged' => 0, 'unpublished' => 0,
    );
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

/*
 * THE QUEUE SWEEP RUNS, AND RUNS WHATEVER ELSE IS SWITCHED OFF. A row expires
 * because a day passed, not because a source said anything, so it must not be
 * conditional on fetching being on — and fetching is off in this case.
 */
is( 'the queue sweep ran', $report['queues']['status'], 'ok' );
is( 'the queue sweep is not gated on anything', $report['queues']['on'], true );
if ( SFAF_Sources::$swept < 1 ) {
    $fails[] = 'the queue sweep was reported ok without the callback being reached';
}
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
 * 3b. WHAT THE FETCH RECORDS, WHICH THE PENDING SCREEN READS.
 *
 * The caladmin Pending queue shows the last automatic fetch, and it has to name
 * the source that failed and say when anything last WORKED. Both answers come
 * out of task_report(), so both are proved here rather than on the screen: the
 * screen is a renderer, and a renderer cannot be more right than what it reads.
 * ------------------------------------------------------------------------ */
echo "What the fetch records\n";

reset_site();
$GLOBALS['opt']['uc_settings'] = array( 'auto_fetch_enabled' => '1' );
SFAF_Sources::$results = array( src( 'Eventbrite' ), src( 'GoFundMe Pro', 'failed' ), src( 'Meetup', 'skipped' ) );
SFAF_Cron::run( 'manual' );

$fetch = SFAF_Cron::task_last( 'fetch' );
is( 'task_last() finds the fetch', is_array( $fetch ), true );
is( 'the fetch is on', $fetch['on'], true );
is( 'every source is recorded separately', count( $fetch['sources'] ), 3 );
is( 'the working source is ok', $fetch['sources'][0]['state'], 'ok' );
is( 'the erroring source is failed', $fetch['sources'][1]['state'], 'failed' );
is( 'the unconnected source is skipped', $fetch['sources'][2]['state'], 'skipped' );
is( 'the failed source is named', $fetch['sources'][1]['label'], 'GoFundMe Pro' );

/*
 * ONE SOURCE DOWN IS NOT A FAILED FETCH, and the screen must not report it as
 * one: the other platforms ran and their events are in the queue. This is why
 * the Pending box asks each source's state rather than the task's.
 */
is( 'one failed source does not fail the whole task', $fetch['status'], 'ok' );
if ( ! $fetch['last_ok'] ) {
    $fails[] = 'a run that mostly worked did not set last_ok, so the screen would call it stale';
}

/*
 * THE LINE IS KEPT WHOLE. run_fetch() also joins these with ' | ' for the
 * Automation screen, and a reader could be tempted to split that back apart.
 * A platform's own error text is inside it, so this proves the separator can
 * appear in the data and that the stored breakdown does not care.
 */
reset_site();
$GLOBALS['opt']['uc_settings'] = array( 'auto_fetch_enabled' => '1' );
$piped = src( 'Eventbrite', 'failed' );
$piped['error'] = 'upstream said: 503 | retry later';
SFAF_Sources::$results = array( $piped, src( 'GoFundMe Pro' ) );
SFAF_Cron::run( 'manual' );

$fetch = SFAF_Cron::task_last( 'fetch' );
is( 'a pipe in an error message does not invent a source', count( $fetch['sources'] ), 2 );
contains( 'the error survives whole', $fetch['sources'][0]['line'], '503 | retry later' );
if ( count( explode( ' | ', $fetch['summary'] ) ) === count( $fetch['sources'] ) ) {
    $fails[] = 'the joined summary happens to split correctly here, so this case proves nothing';
}

/*
 * 'last' AND 'last_ok' PART COMPANY WHEN A FETCH BREAKS, and that is the whole
 * reason for the second field. Every source fails, so the task fails; the run
 * still happened, so 'last' moves; nothing worked, so 'last_ok' must not.
 */
$worked_at = $fetch['last_ok'];
if ( ! $worked_at ) {
    $fails[] = 'the mostly-working run did not set last_ok, so the next assertion proves nothing';
}

/*
 * Back-dated for the same reason the reminders case is: two runs in one second
 * cannot tell a moving timestamp from a stuck one.
 *
 * PAST THE HOUR, NOT ONTO IT. Exactly $HOUR put the last working run on the
 * boundary the box tests, where "more than an hour ago" is false by a second
 * and the staleness assertion fails on arithmetic rather than on behaviour.
 * Five minutes clear of it is the same test without the coin toss.
 */
$BACK = $HOUR + 5 * $MIN;
$log  = get_option( 'sfaf_cron_log', array() );
foreach ( $log as $i => $entry ) {
    $log[ $i ]['started_ts'] = $entry['started_ts'] - $BACK;
}
update_option( 'sfaf_cron_log', $log );
$worked_at -= $BACK;

SFAF_Sources::$results = array( src( 'Eventbrite', 'failed' ), src( 'GoFundMe Pro', 'failed' ) );
SFAF_Cron::run( 'manual' );

$broken = SFAF_Cron::task_last( 'fetch' );
is( 'every source failing fails the task', $broken['status'], 'failed' );
is( 'a failed run still moves "last ran"', ( $broken['last'] > $worked_at ), true );
is( 'a failed run does not move "last worked"', $broken['last_ok'], $worked_at );

/*
 * AND THAT IS THE STALENESS CASE. The Pending box calls the fetch stale when
 * nothing has worked for an hour. Read off 'last' this fetch looks fresh, which
 * is exactly the reading that would show a manager a healthy box over a queue
 * that has not taken an event in a day.
 */
if ( ( time() - $broken['last_ok'] ) <= $HOUR ) {
    $fails[] = 'an hour of nothing but failures does not read as stale, so the box would not fire';
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
echo "         15-minute recurrence and the migration off hourly, and the run lock;\n";
echo "         and, for the Pending screen's fetch box, that each source is recorded with its own\n";
echo "         state, that one source failing does not fail the task, that a pipe inside a\n";
echo "         platform's error message cannot invent a source, and that a failed run moves\n";
echo "         'last ran' without moving 'last worked', which is what makes staleness detectable\n\n";

if ( $fails ) {
    echo 'FAIL: ' . count( $fails ) . "\n";
    foreach ( $fails as $f ) { echo "  . $f\n"; }
    exit( 1 );
}
echo "the Automation screen tells the truth in every state it can be in.\n";
