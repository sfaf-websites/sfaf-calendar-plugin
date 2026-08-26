<?php
/**
 * The scheduled runner.
 *
 * ONE runner, hourly, for every unattended job this plugin has. At the time of
 * writing that is two: the morning-of reminder pass and (when switched on) the
 * third-party fetch. Anything added later registers itself here rather than
 * scheduling its own cron event, so there is one lock, one log and one place to
 * look when something happened overnight.
 *
 * HOW IT IS MEANT TO BE DRIVEN
 * ---------------------------------------------------------------------------
 * WordPress's own "cron" is not a scheduler: it is a check that runs when
 * somebody visits the site. On a calendar nobody visits at 6am, a 6am job
 * simply does not happen. So the intended setup is an external scheduler
 * (cron-jobs.org or similar) requesting wp-cron.php every fifteen minutes, with
 * DISABLE_WP_CRON in wp-config.php to stop the visitor-triggered path from
 * duplicating the work. The readme has the exact steps and URL, and the order
 * they must be done in.
 *
 * WHY wp-cron.php AND NOT THE PING ENDPOINT BELOW. The ping endpoint calls
 * run() directly, so it runs THIS PLUGIN'S jobs and nothing else. wp-cron.php
 * dispatches WordPress's whole schedule: core's own maintenance, every other
 * plugin's jobs, and ours among them. Under DISABLE_WP_CRON nothing else is
 * dispatching that queue, so pointing the scheduler at the ping endpoint would
 * keep this plugin working and quietly stop everything else on the site. The
 * ping endpoint stays what it was built to be: a heartbeat that needs nothing
 * set up, not the scheduler.
 *
 * The runner does not care which of those triggered it. Real cron, WP
 * pseudo-cron, the page-view nudge and the portal's "Run now" button all land
 * in run(), and the run lock is what makes that safe.
 *
 * WHY A LOCK
 * ---------------------------------------------------------------------------
 * A run that takes longer than an hour would otherwise be overlapped by the
 * next one, and two passes over the same due events at the same moment is
 * exactly how a person gets two copies of the same reminder. add_option() is
 * the closest thing WordPress offers to an atomic claim — option_name is
 * UNIQUE, and add_option() returns false when the row already exists — so the
 * second run finds the lock held, records that it stood down, and stops.
 *
 * The lock is NOT the only thing preventing a double send. It is the first of
 * two layers; the second is a unique key per (event, recipient) in the reminder
 * ledger, which holds even if the lock is broken, bypassed or lost to a fatal.
 * See SFAF_Reminders.
 *
 * WHY A LOG
 * ---------------------------------------------------------------------------
 * This work happens when nobody is watching. When an attendee says they never
 * got a reminder, the log is the only thing that can answer whether the run
 * happened, what it found, and what it did. It keeps a bounded history and
 * prunes as it goes, so it cannot grow into the options table forever.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Cron {

    /** The scheduled action. The name is historical; the interval is not. */
    const HOOK = 'sfaf_cron_hourly';

    /**
     * The recurrence the runner is scheduled on, and how long it is.
     *
     * FIFTEEN MINUTES, NOT AN HOUR, AND THE PRE-EVENT SUMMARY IS WHY. It is due
     * two hours before an event starts, so an hourly runner is up to an hour
     * late for it. The page-view nudge already bought that accuracy for one of
     * the two ways a run can start (see PING_EVERY, which is the same number for
     * the same reason); this makes a real scheduler buy it for the other. An
     * external pinger hitting wp-cron.php every fifteen minutes only produces a
     * run every fifteen minutes if the event is DUE that often, and until
     * 3.34.0 it was not: it was hourly, and three of every four pings would have
     * found nothing to dispatch.
     *
     * Every job in a run is idempotent and most passes find nothing due, so the
     * cost of a run with no work is one WP_Query.
     */
    const SCHEDULE       = 'sfaf_quarter_hour';
    const SCHEDULE_EVERY = 900;

    /** Options. */
    const LOCK_OPTION    = 'sfaf_cron_lock';
    const LOG_OPTION     = 'sfaf_cron_log';
    const FAIL_OPTION    = 'sfaf_cron_failures';
    const LAST_OK_OPTION = 'sfaf_cron_last_success';

    /**
     * How long a lock may be held before it is treated as abandoned.
     *
     * A fatal mid-run leaves the lock behind with nobody to release it, which
     * without this would stop the runner permanently and silently. Fifteen
     * minutes is comfortably longer than a real run and comfortably shorter
     * than the hour before the next one.
     */
    const LOCK_TTL = 900;

    /** Entries kept in the run log. Older ones are pruned on every write. */
    const LOG_MAX = 60;

    /** Consecutive failures before the admin notice appears. */
    const FAIL_NOTICE_AT = 3;

    /** How long without a completed run before the "it stopped" notice shows. */
    const STALE_AFTER = 10800; // 3 hours, and an email goes out with it.

    /**
     * How long without a completed run before the SCREEN says so.
     *
     * Deliberately shorter than STALE_AFTER, and the two are not the same
     * judgement. STALE_AFTER decides when to wake somebody up by email, so it
     * has to be long enough that a single hiccup at the pinger does not send
     * one. This decides what the screen says to somebody who is already looking
     * at it, where being told early costs nothing. Forty-five minutes is three
     * missed runs at the fifteen-minute cadence: past coincidence, short of an
     * emergency.
     */
    const BEHIND_AFTER = 2700;

    /** Where the health alert stands: the last state we told anybody about. */
    const ALERT_STATE_OPTION = 'sfaf_cron_alert_state';

    /** When the health check last ran, so it can be throttled cheaply. */
    const HEALTH_CHECKED_OPTION = 'sfaf_cron_health_checked';

    /** How often the health check is allowed to actually look. */
    const HEALTH_CHECK_EVERY = 900; // 15 minutes

    /** The admin-ajax action a page carrying a calendar pings. */
    const PING_ACTION = 'sfaf_cron_ping';

    /** When a ping last got through to a run. */
    const PING_AT_OPTION = 'sfaf_cron_pinged_at';

    /**
     * The shortest gap between two ping-driven runs.
     *
     * Fifteen minutes, not an hour, and the pre-event summary is why: it is due
     * two hours before an event starts, and a run that only happens hourly can
     * be up to an hour late for it. Four cheap runs an hour is what buys that
     * accuracy. Every job in the run is idempotent and most passes find nothing
     * due, so the cost of a run that has no work is one WP_Query.
     */
    const PING_EVERY = 900;

    /**
     * How long a bad state must persist before it is repeated by email.
     *
     * Written as a number rather than as DAY_IN_SECONDS so this class constant
     * has no dependency on WordPress having defined its own constants first.
     */
    const ALERT_REPEAT_AFTER = 86400;

    public function register() {
        add_action( self::HOOK, array( $this, 'run_scheduled' ) );
        add_action( 'admin_notices', array( $this, 'admin_notice' ) );

        /*
         * THE VISITOR-POWERED HEARTBEAT.
         *
         * Registered on admin-ajax rather than as a REST route on purpose. Every
         * CORS mechanism in SFAF_Embed is gated on is_embed_request(), which
         * compares the route string EXACTLY, so a second REST route would match
         * none of preflight, the response headers or the rest_pre_serve_request
         * fallback, and would be blocked by the browser the moment sfaf.org
         * asked for it. admin-ajax is outside that machinery entirely, and the
         * request is sent no-cors anyway, so there is nothing to preflight.
         *
         * Both the logged-in and logged-out hooks: the visitor firing this is
         * almost always anonymous, but a signed-in editor reading a programme
         * page should nudge it too.
         */
        add_action( 'wp_ajax_nopriv_' . self::PING_ACTION, array( $this, 'handle_ping' ) );
        add_action( 'wp_ajax_' . self::PING_ACTION, array( $this, 'handle_ping' ) );

        /*
         * THE HEALTH CHECK IS NOT INSIDE THE THING IT MONITORS.
         *
         * This is the whole difficulty with alerting on a dead cron: anything
         * the runner would have sent does not send either, because the runner
         * is what is broken. Hanging the alert off SFAF_Cron::run() would
         * produce a monitor that works perfectly right up to the moment it is
         * needed.
         *
         * So it hangs off `wp_loaded`, which fires on EVERY request this site
         * serves — an admin page load, a visitor hitting the calendar, a REST
         * call, WordPress's own pseudo-cron — none of which depend on the
         * scheduled runner having run. If a person can reach the site at all,
         * the check happens.
         *
         * It costs one option read per request, throttled to doing real work
         * at most every fifteen minutes. See maybe_check_health().
         *
         * WHAT THIS STILL CANNOT CATCH, once cron is externally driven.
         *
         * wp_loaded fires on a wp-cron.php request too, so an external pinger
         * drives the monitor as well as the work, and a run that fails or a
         * task that breaks is reported exactly as before. The hole is the case
         * where the trigger itself dies AND nobody visits: no request means no
         * wp_loaded, which means no check, which means no email. A monitor
         * inside the site cannot report that the site is not being visited.
         *
         * The page-view nudge below is what keeps that hole closed in practice.
         * It is a request from sfaf.org, which has traffic this site does not,
         * and it is a request whether or not the external scheduler is alive.
         * That is a second reason to keep it after the scheduler is set up, on
         * top of the one it was built for: it is the heartbeat that lets the
         * monitor notice the scheduler has stopped.
         */
        add_action( 'wp_loaded', array( $this, 'maybe_check_health' ), 99 );

        self::ensure_scheduled();
    }

    /** The WordPress admin screen that owns all of this. */
    public static function admin_url() {
        return add_query_arg(
            array( 'post_type' => 'uc_event', 'page' => 'uc-automation' ),
            admin_url( 'edit.php' )
        );
    }

    /* ---------------------------------------------------------------------
     * Scheduling
     * ------------------------------------------------------------------- */

    /**
     * Our own recurrence, on WordPress's list of them.
     *
     * Registered from file scope in sfaf-calendar.php rather than from
     * register(), because wp_cron() runs before this plugin's init callback
     * and an unrecognised recurrence makes it unschedule the event. See the
     * note at that add_filter().
     */
    public static function add_schedule( $schedules ) {
        if ( ! is_array( $schedules ) ) {
            $schedules = array();
        }
        $schedules[ self::SCHEDULE ] = array(
            'interval' => self::SCHEDULE_EVERY,
            'display'  => 'Every 15 minutes (SFAF Calendar)',
        );
        return $schedules;
    }

    /**
     * Make sure the event exists, on the right recurrence. Idempotent, and safe
     * to call on every request: wp_next_scheduled() is served from the cached
     * cron array.
     *
     * IT ALSO MIGRATES. A site that installed any version before 3.34.0 has this
     * hook stored against 'hourly', and simply checking "is it scheduled?" would
     * leave it there forever: the event exists, so nothing would ever move it.
     * wp_get_schedule() answers what it is actually on, and anything that is not
     * ours is cleared and re-laid. Nothing else in the plugin owns this hook, so
     * there is no other schedule this could be trampling.
     */
    public static function ensure_scheduled() {
        // Activation calls this too, and it can run before the file-scope
        // add_filter() in a freshly installed copy. Adding it here as well is
        // idempotent and removes the dependency on which happens first.
        if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ) ) {
            add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
        }

        $next = wp_next_scheduled( self::HOOK );

        if ( $next && wp_get_schedule( self::HOOK ) !== self::SCHEDULE ) {
            // Clear the schedule and nothing else. unschedule() also drops the
            // run lock, which is correct when the plugin is being switched off
            // and wrong here: this can happen while a run is in progress, and
            // breaking that run's lock is how a person gets two reminders.
            self::clear_schedule();
            $next = false;
        }

        if ( ! $next ) {
            wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
        }
    }

    /** Drop every future occurrence of the hook, and touch nothing else. */
    private static function clear_schedule() {
        $timestamp = wp_next_scheduled( self::HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
            $timestamp = wp_next_scheduled( self::HOOK );
        }
    }

    /** Remove the schedule (deactivation). */
    public static function unschedule() {
        self::clear_schedule();
        // Never leave a lock behind for a plugin that is switched off.
        delete_option( self::LOCK_OPTION );
    }

    /** The URL a system cron or external pinger should request. */
    public static function cron_url() {
        return site_url( 'wp-cron.php?doing_wp_cron' );
    }

    /** Whether wp-config.php has switched off the visitor-triggered path. */
    public static function wp_cron_disabled() {
        return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    }

    /** Entry point for the scheduled action. */
    public function run_scheduled() {
        self::run( 'cron' );
    }

    /* ---------------------------------------------------------------------
     * The ping
     * ------------------------------------------------------------------- */

    /**
     * Spawn a run, if one is due. Called by any page carrying a calendar.
     *
     * IT DOES NOTHING ELSE, AND THAT IS THE WHOLE SECURITY ARGUMENT. It reads
     * no parameters, writes nothing a caller controls and returns no data, so
     * being reachable by anybody from any origin costs nothing: the only thing
     * a stranger can make it do is what it does for a visitor, which is run the
     * jobs this site was going to run anyway.
     *
     * THE INTERVAL IS THE THROTTLE AND IT IS CHECKED FIRST. A ping inside the
     * window costs one option read and a 204. The embed script also keeps its
     * own timestamp so most visitors never send anything, but that is a
     * courtesy to the network: this check is what makes the endpoint safe to
     * hammer, because it is on the server where the work would happen.
     *
     * THE MARKER IS WRITTEN BEFORE THE RUN, NOT AFTER. Two simultaneous pings
     * would otherwise both pass the check and both start work. The second one
     * now finds the marker moved and stands down; if they are simultaneous
     * enough that both get through, the run lock catches it, which is the layer
     * that was there before this existed.
     */
    public function handle_ping() {
        nocache_headers();
        // Any origin, because the calendar is embedded on sites we do not own
        // and this must work from all of them. There is nothing in the response
        // to protect: it has no body.
        header( 'Access-Control-Allow-Origin: *' );

        $now  = time();
        $last = (int) get_option( self::PING_AT_OPTION, 0 );

        if ( $last && ( $now - $last ) < self::PING_EVERY ) {
            status_header( 204 );
            wp_die( '', '', array( 'response' => 204 ) );
        }

        update_option( self::PING_AT_OPTION, $now, false );

        status_header( 202 );

        /*
         * Let the browser go before doing the work. The visitor's page has
         * nothing to wait for, and holding the connection open for the length
         * of a mail run is the one way this could become a cost to them.
         * fastcgi_finish_request() is the supported way and is present on most
         * PHP-FPM hosts; where it is not, the run simply happens with the
         * connection still open, which is slower for nobody in particular.
         */
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        }

        self::run( 'ping' );
        exit;
    }

    /** The URL the embed script pings. Shown in the admin so it can be tested. */
    public static function ping_url() {
        return admin_url( 'admin-ajax.php?action=' . self::PING_ACTION );
    }

    /** When a ping last started a run, or 0. */
    public static function last_ping() {
        return (int) get_option( self::PING_AT_OPTION, 0 );
    }

    /* ---------------------------------------------------------------------
     * The run
     * ------------------------------------------------------------------- */

    /**
     * EVERY UNATTENDED JOB, IN ONE LIST.
     *
     * One list, one run, one render, in the shape §7 of CLAUDE.md asks for.
     * run() iterates it and the Automation screen iterates it, so a job cannot
     * be run without appearing on the screen and cannot appear on the screen
     * without being run. Before 3.34.0 the three jobs were three hand-written
     * lines inside run() and the screen described two of them in prose, which
     * is two places to keep in step and one of them was already out of date.
     *
     * 'plain' is the sentence a person who does not know what cron is reads on
     * the screen. It says what the job does, never why it exists.
     *
     * @return array<string,array{label:string,plain:string,callback:callable,on:callable,off:string}>
     */
    public static function tasks() {
        return array(
            'reminders' => array(
                'label'    => 'Reminder emails',
                'plain'    => 'Emails everybody registered for an event on the morning it happens, at 6:00am.',
                'callback' => array( 'SFAF_Reminders', 'run' ),
                'on'       => array( 'SFAF_Reminders', 'enabled' ),
                'off'      => 'Reminder emails are switched off in Settings.',
            ),
            'summaries' => array(
                'label'    => 'Who is coming',
                'plain'    => 'Emails the people running an event a list of who has registered, two hours before it starts.',
                'callback' => array( 'SFAF_Notifications', 'run_summaries' ),
                'on'       => '__return_true',
                'off'      => '',
            ),
            'fetch'     => array(
                'label'    => 'Fetch from sources',
                'plain'    => 'Brings in new and changed events from Eventbrite and GoFundMe Pro.',
                'callback' => array( __CLASS__, 'run_fetch' ),
                'on'       => array( __CLASS__, 'auto_fetch_enabled' ),
                'off'      => 'Automated fetching is switched off. Use "Fetch updates" on the calendar portal\'s Pending screen to run it by hand.',
            ),
            'orphans'   => array(
                'label'    => 'Events with no organizer',
                'plain'    => 'Checks once a day for events whose organizer no longer has calendar access, and emails the calendar admins when the list changes.',
                'callback' => array( 'SFAF_Orphans', 'run' ),
                'on'       => '__return_true',
                'off'      => '',
            ),
        );
    }

    /**
     * Run every registered task once.
     *
     * @param string $trigger 'cron' | 'manual'.
     * @return array The log entry that was recorded.
     */
    public static function run( $trigger = 'cron' ) {
        $started = microtime( true );

        if ( ! self::acquire_lock() ) {
            // Not an error. A run that stands down because the previous one is
            // still going is the lock doing its job — but it is recorded,
            // because a log full of these means runs are taking over an hour.
            return self::log_entry( array(
                'trigger'  => $trigger,
                'status'   => 'skipped',
                'duration' => 0,
                'tasks'    => array( array(
                    'task'    => 'lock',
                    'status'  => 'skipped',
                    'summary' => 'Stood down: the previous run was still in progress.',
                    'counts'  => array(),
                ) ),
            ) );
        }

        $tasks = array();
        try {
            foreach ( self::tasks() as $key => $spec ) {
                if ( ! call_user_func( $spec['on'] ) ) {
                    $tasks[] = array(
                        'task'    => $key,
                        'label'   => $spec['label'],
                        'status'  => 'skipped',
                        'summary' => $spec['off'],
                        'counts'  => array(),
                    );
                    continue;
                }
                $tasks[] = self::run_task( $key, $spec['label'], $spec['callback'] );
            }
        } catch ( \Throwable $e ) {
            // Belt and braces: run_task() already contains each task's own
            // failures, so reaching here means the runner itself broke.
            $tasks[] = array(
                'task'    => 'runner',
                'label'   => 'Runner',
                'status'  => 'failed',
                'summary' => 'The run stopped unexpectedly: ' . $e->getMessage(),
                'counts'  => array(),
            );
        }

        self::release_lock();

        $failed = false;
        $ran    = false;
        foreach ( $tasks as $t ) {
            if ( 'failed' === $t['status'] ) { $failed = true; }
            if ( 'skipped' !== $t['status'] ) { $ran = true; }
        }
        $status = $failed ? ( $ran ? 'partial' : 'failed' ) : 'ok';

        $entry = self::log_entry( array(
            'trigger'  => $trigger,
            'status'   => $status,
            'duration' => round( microtime( true ) - $started, 2 ),
            'tasks'    => $tasks,
        ) );

        // Failure visibility. A run that got through anything at all resets the
        // streak; only a wholly failed run counts against it.
        if ( 'failed' === $status ) {
            update_option( self::FAIL_OPTION, (int) get_option( self::FAIL_OPTION, 0 ) + 1, false );
        } else {
            update_option( self::FAIL_OPTION, 0, false );
            update_option( self::LAST_OK_OPTION, time(), false );
        }

        return $entry;
    }

    /**
     * Run one task, containing anything it throws.
     *
     * @param string   $key      Machine name.
     * @param string   $label    Human name for the log.
     * @param callable $callback Returns array( status, summary, counts ).
     * @return array
     */
    private static function run_task( $key, $label, $callback ) {
        try {
            $result = call_user_func( $callback );
            if ( ! is_array( $result ) ) {
                $result = array();
            }
            return array(
                'task'    => $key,
                'label'   => $label,
                'status'  => isset( $result['status'] ) ? $result['status'] : 'ok',
                'summary' => isset( $result['summary'] ) ? (string) $result['summary'] : '',
                'counts'  => isset( $result['counts'] ) && is_array( $result['counts'] ) ? $result['counts'] : array(),
                /*
                 * OPTIONAL, AND ONLY THE FETCH SUPPLIES IT. A task that returns
                 * no per-item breakdown stores an empty array rather than
                 * nothing, so every entry has the same shape and no reader has
                 * to test whether the key is there. Entries written before
                 * 3.57.0 do not have it, which is why the readers still default.
                 */
                'sources' => isset( $result['sources'] ) && is_array( $result['sources'] ) ? $result['sources'] : array(),
            );
        } catch ( \Throwable $e ) {
            return array(
                'task'    => $key,
                'label'   => $label,
                'status'  => 'failed',
                'summary' => 'Failed: ' . $e->getMessage(),
                'counts'  => array(),
            );
        }
    }

    /**
     * The fetch task. Wraps SFAF_Sources::run_all() into the shape the log
     * wants, and calls the whole task failed only when every source that was
     * asked to run errored — one platform being down is reported, not fatal.
     *
     * IT ALSO RECORDS EACH SOURCE SEPARATELY, ADDED 3.57.0, because the Pending
     * screen in caladmin now shows the last run and has to say which source
     * failed rather than that something did.
     *
     * WHY NOT SPLIT THE SUMMARY BACK APART. 'summary' is these same lines
     * joined with ' | ', so the per-source breakdown looks recoverable by
     * splitting on that. It is not: a failed source puts $result['error']
     * into its line verbatim, and that string comes from a remote platform or
     * an exception message, either of which may contain a pipe. Recovering
     * structure from prose that a third party can write is the same mistake as
     * auditing PHP with grep. The lines are kept whole instead.
     *
     * THE THREE STATES ARE THE MANUAL REPORT'S THREE STATES, deliberately:
     * SFAF_Portal::render_fetch_report() classifies a result skipped / failed /
     * ok on exactly these two tests, and the same words mean the same thing in
     * both places. Two screens describing one fetch must not have two
     * vocabularies for it.
     */
    public static function run_fetch() {
        $results = SFAF_Sources::run_all();
        $lines   = array();
        $sources = array();
        $active  = 0;
        $errored = 0;
        $counts  = array( 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'unpublished' => 0 );

        foreach ( (array) $results as $r ) {
            $line    = SFAF_Sources::summarize( $r );
            $lines[] = $line;

            if ( ! empty( $r['skipped'] ) ) {
                $state = 'skipped';
            } elseif ( '' !== $r['error'] ) {
                $state = 'failed';
            } else {
                $state = 'ok';
            }

            $sources[] = array(
                'label' => isset( $r['label'] ) ? (string) $r['label'] : '',
                'state' => $state,
                'line'  => $line,
            );

            if ( ! empty( $r['skipped'] ) ) {
                continue;
            }
            $active++;
            if ( '' !== $r['error'] ) {
                $errored++;
                continue;
            }
            foreach ( array_keys( $counts ) as $k ) {
                $counts[ $k ] += isset( $r[ $k ] ) ? (int) $r[ $k ] : 0;
            }
        }

        return array(
            'status'  => ( $active > 0 && $errored === $active ) ? 'failed' : 'ok',
            'summary' => $lines ? implode( ' | ', $lines ) : 'No sources are registered.',
            'counts'  => $counts,
            'sources' => $sources,
        );
    }

    /** Whether the unattended fetch is switched on. Off unless explicitly set. */
    public static function auto_fetch_enabled() {
        $settings = get_option( 'uc_settings', array() );
        return isset( $settings['auto_fetch_enabled'] ) && '1' === $settings['auto_fetch_enabled'];
    }

    /* ---------------------------------------------------------------------
     * Lock
     * ------------------------------------------------------------------- */

    private static function acquire_lock() {
        $held = get_option( self::LOCK_OPTION );
        if ( $held && ( time() - (int) $held ) > self::LOCK_TTL ) {
            // Abandoned by a fatal or a killed process. Break it, or the
            // runner would be stopped for good with nothing saying why.
            delete_option( self::LOCK_OPTION );
        }
        // add_option() returns false when the option already exists, which is
        // what makes this a claim rather than a write.
        return (bool) add_option( self::LOCK_OPTION, time(), '', 'no' );
    }

    private static function release_lock() {
        delete_option( self::LOCK_OPTION );
    }

    /** Unix timestamp the current lock was taken, or 0 when not held. */
    public static function lock_held_since() {
        return (int) get_option( self::LOCK_OPTION, 0 );
    }

    /* ---------------------------------------------------------------------
     * Log
     * ------------------------------------------------------------------- */

    /**
     * Prepend an entry and prune. Times are stored in GMT and rendered in the
     * site's timezone, so a timezone change never rewrites history.
     */
    private static function log_entry( $entry ) {
        $entry = array_merge( array(
            'started'     => gmdate( 'Y-m-d H:i:s' ),
            'started_ts'  => time(),
            'trigger'     => 'cron',
            'status'      => 'ok',
            'duration'    => 0,
            'tasks'       => array(),
            'wp_cron_off' => self::wp_cron_disabled(),
        ), $entry );

        $log = get_option( self::LOG_OPTION, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        array_unshift( $log, $entry );
        if ( count( $log ) > self::LOG_MAX ) {
            $log = array_slice( $log, 0, self::LOG_MAX );
        }
        update_option( self::LOG_OPTION, $log, false );

        return $entry;
    }

    /** The run log, newest first. */
    public static function log() {
        $log = get_option( self::LOG_OPTION, array() );
        return is_array( $log ) ? $log : array();
    }

    /** Empty the run log. */
    public static function clear_log() {
        delete_option( self::LOG_OPTION );
    }

    /* ---------------------------------------------------------------------
     * What a person needs to see
     *
     * BEFORE 3.34.0 THERE WAS NO WAY TO ANSWER "IS THIS RUNNING?". The screen
     * carried the health sentence, which says only whether the runner has
     * failed or gone quiet for three hours, and a "next due" for the runner as
     * a whole. It could not say when a job last actually ran, which is the
     * question somebody has who has just pointed an external scheduler at this
     * site and wants to know whether it worked, and the question somebody has
     * six months later when reminders have stopped.
     * ------------------------------------------------------------------- */

    /** When the runner is next due to fire, or 0 when it is not scheduled. */
    public static function next_due() {
        return (int) wp_next_scheduled( self::HOOK );
    }

    /** When a run last STARTED, whatever came of it. 0 when none ever has. */
    public static function last_run() {
        foreach ( self::log() as $entry ) {
            if ( ! empty( $entry['started_ts'] ) ) {
                return (int) $entry['started_ts'];
            }
        }
        return 0;
    }

    /** When a run last COMPLETED without failing outright. */
    public static function last_success() {
        return (int) get_option( self::LAST_OK_OPTION, 0 );
    }

    /**
     * A timestamp said the way a person says it: "12 minutes ago", "in 3
     * minutes", "never".
     *
     * human_time_diff() is WordPress's own and is already translated. Under a
     * minute it answers "1 min", which reads as a stale value rather than a
     * fresh one, so that case gets its own words.
     */
    public static function ago( $timestamp ) {
        $timestamp = (int) $timestamp;
        if ( ! $timestamp ) {
            return 'never';
        }
        $now = time();
        if ( $timestamp > $now + 30 ) {
            return 'in ' . human_time_diff( $now, $timestamp );
        }
        if ( abs( $now - $timestamp ) < 60 ) {
            return 'just now';
        }
        return human_time_diff( $timestamp, $now ) . ' ago';
    }

    /**
     * How the runner is doing, in three states a person can act on.
     *
     * 'working'  A run completed recently enough that nothing is wrong.
     * 'behind'   Nothing has completed for BEHIND_AFTER. Might be a hiccup.
     * 'stopped'  Nothing has completed for STALE_AFTER, or runs are failing.
     *            This is the state that also sends an email.
     * 'never'    Nothing has ever completed. A fresh install, not a fault.
     *
     * It reads the same two options health() reads, so the screen and the
     * alert can never disagree about whether something is wrong; it only
     * divides the healthy side of that line into two.
     *
     * @return array{state:string,headline:string,detail:string}
     */
    public static function status() {
        $fails   = (int) get_option( self::FAIL_OPTION, 0 );
        $last_ok = self::last_success();
        $silent  = $last_ok ? ( time() - $last_ok ) : 0;

        if ( $fails >= self::FAIL_NOTICE_AT ) {
            return array(
                'state'    => 'stopped',
                'headline' => 'Scheduled tasks are failing',
                'detail'   => sprintf(
                    'The last %d runs all failed. Reminder emails are not going out. The run log below says what went wrong.',
                    $fails
                ),
            );
        }

        if ( ! $last_ok ) {
            return array(
                'state'    => 'never',
                'headline' => 'Scheduled tasks have never run',
                'detail'   => 'Press "Run now" to try one. If that works, the next thing to set up is something that presses it automatically. The readme has the steps.',
            );
        }

        if ( $silent > self::STALE_AFTER ) {
            return array(
                'state'    => 'stopped',
                'headline' => 'Scheduled tasks have stopped',
                'detail'   => sprintf(
                    'Nothing has run since %s, which is %s. Reminder emails are not going out. Whatever is meant to be visiting this site every 15 minutes is not doing it.',
                    self::local_time( $last_ok ),
                    self::ago( $last_ok )
                ),
            );
        }

        if ( $silent > self::BEHIND_AFTER ) {
            return array(
                'state'    => 'behind',
                'headline' => 'Scheduled tasks are running late',
                'detail'   => sprintf(
                    'The last run was %s, and one is expected every 15 minutes. Nothing has been missed yet. If this is still true in an hour, something has stopped visiting this site.',
                    self::ago( $last_ok )
                ),
            );
        }

        return array(
            'state'    => 'working',
            'headline' => 'Scheduled tasks are running',
            'detail'   => sprintf(
                'The last run finished %s, and one is expected every 15 minutes.',
                self::ago( $last_ok )
            ),
        );
    }

    /**
     * Every job, when it last actually ran, and when it is next due.
     *
     * "Last ran" means the newest log entry in which this job DID something, so
     * a job that has been standing down because it is switched off reports the
     * last time it really ran rather than the last time it was passed over. A
     * job that is switched off has no next due date, because it has none.
     *
     * 'last' AND 'last_ok' ARE DIFFERENT QUESTIONS AND THE SCREENS ASK
     * DIFFERENT ONES. 'last' is the newest run that did something, whatever
     * came of it, which is what the Automation table shows beside its own
     * status word. 'last_ok' is the newest run that did something and did not
     * fail, which is the only one that answers "is this still working?" — a job
     * failing every quarter of an hour has a very recent 'last' and a stale
     * 'last_ok', and reading the first as the second would report a broken
     * fetch as a healthy one. Added 3.57.0 for the Pending screen's staleness
     * line. Both are 0 when there is no such run.
     *
     * 'sources' is the per-source breakdown for jobs that record one, and an
     * empty array for those that do not and for entries written before 3.57.0.
     *
     * @return array<int,array>
     */
    public static function task_report() {
        $log  = self::log();
        $next = self::next_due();
        $out  = array();

        foreach ( self::tasks() as $key => $spec ) {
            $on  = (bool) call_user_func( $spec['on'] );
            $row = array(
                'key'     => $key,
                'label'   => $spec['label'],
                'plain'   => $spec['plain'],
                'on'      => $on,
                'off'     => $spec['off'],
                'last'    => 0,
                'last_ok' => 0,
                'status'  => '',
                'summary' => '',
                'sources' => array(),
                'next'    => $on ? $next : 0,
            );

            /*
             * ONE PASS, TWO ANSWERS. The newest non-skipped run fills 'last'
             * and everything that describes it; the newest non-skipped run that
             * did not fail fills 'last_ok'. They are usually the same entry, so
             * the scan stops as soon as both are settled rather than reading
             * all sixty every time.
             */
            $have_last = false;
            foreach ( $log as $entry ) {
                foreach ( (array) ( isset( $entry['tasks'] ) ? $entry['tasks'] : array() ) as $t ) {
                    if ( ! isset( $t['task'] ) || $key !== $t['task'] ) {
                        continue;
                    }
                    $status = isset( $t['status'] ) ? (string) $t['status'] : '';
                    if ( 'skipped' === $status ) {
                        continue;
                    }
                    $when = isset( $entry['started_ts'] ) ? (int) $entry['started_ts'] : 0;

                    if ( ! $have_last ) {
                        $row['last']    = $when;
                        $row['status']  = $status;
                        $row['summary'] = isset( $t['summary'] ) ? (string) $t['summary'] : '';
                        $row['sources'] = ( isset( $t['sources'] ) && is_array( $t['sources'] ) ) ? $t['sources'] : array();
                        $have_last      = true;
                    }
                    if ( ! $row['last_ok'] && 'failed' !== $status ) {
                        $row['last_ok'] = $when;
                    }
                    break;
                }
                if ( $have_last && $row['last_ok'] ) {
                    break;
                }
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * One job's row from task_report(), or null when there is no such job.
     *
     * A caller wanting a single job should not have to know that the report is
     * a list keyed by position. Added 3.57.0 with the Pending screen's fetch
     * box, which wants exactly one of the five.
     *
     * @param string $key
     * @return array|null
     */
    public static function task_last( $key ) {
        foreach ( self::task_report() as $row ) {
            if ( isset( $row['key'] ) && $key === $row['key'] ) {
                return $row;
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------------
     * Health & notices
     * ------------------------------------------------------------------- */

    /**
     * A plain reading of whether the runner is working.
     *
     * @return array{state:string,message:string}
     *   state: 'ok' | 'never' | 'failing' | 'stale'
     */
    public static function health() {
        $fails   = (int) get_option( self::FAIL_OPTION, 0 );
        $last_ok = (int) get_option( self::LAST_OK_OPTION, 0 );

        if ( $fails >= self::FAIL_NOTICE_AT ) {
            return array(
                'state'   => 'failing',
                'message' => sprintf(
                    'The scheduled run has failed %d times in a row. Check the run log for the reason.',
                    $fails
                ),
            );
        }

        if ( ! $last_ok ) {
            return array(
                'state'   => 'never',
                'message' => 'The scheduled run has never completed yet. If you have just installed this, wait for the top of the hour or press "Run now".',
            );
        }

        if ( ( time() - $last_ok ) > self::STALE_AFTER ) {
            return array(
                'state'   => 'stale',
                'message' => sprintf(
                    'The scheduled run has not completed since %s. Reminders are not going out. Check that the external scheduler is still requesting %s.',
                    self::local_time( $last_ok ),
                    self::cron_url()
                ),
            );
        }

        return array(
            'state'   => 'ok',
            'message' => sprintf( 'Last completed %s.', self::local_time( $last_ok ) ),
        );
    }

    /**
     * The notice that stops a stopped cron being silent.
     *
     * A runner that fails loudly is recoverable; one that simply never runs
     * again is not noticed until somebody asks why nobody got an email. Both
     * cases are covered: repeated failures, and no completed run at all.
     */
    public function admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $health = self::health();
        if ( 'failing' !== $health['state'] && 'stale' !== $health['state'] ) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>SFAF Calendar scheduled tasks:</strong> '
            . esc_html( $health['message'] ) . '</p></div>';
    }

    /* ---------------------------------------------------------------------
     * Health alert email
     * ------------------------------------------------------------------- */

    /**
     * Where alerts go. The site administrator unless somebody says otherwise.
     *
     * Deliberately separate from the reminder email settings: those are about
     * the events people attend, this is about whether the server is working,
     * and the person who fields one is very often not the person who fields
     * the other.
     */
    public static function alert_recipient() {
        $settings = get_option( 'uc_settings', array() );
        $set      = isset( $settings['cron_alert_email'] ) ? trim( (string) $settings['cron_alert_email'] ) : '';
        if ( $set && is_email( $set ) ) {
            return $set;
        }
        $admin = get_option( 'admin_email' );
        return ( $admin && is_email( $admin ) ) ? $admin : '';
    }

    /**
     * Look at the runner's health and email if that has changed for the worse
     * or the better. Throttled, and safe to call on every request.
     *
     * WHY AN OPTION AND NOT A TRANSIENT for the throttle: a transient without a
     * persistent object cache is a non-autoloaded row, so reading it costs a
     * query on every single page view. An ordinary autoloaded option arrives
     * with the alloptions cache that WordPress loads anyway, so the common case
     * (checked recently, nothing to do) costs nothing at all.
     */
    public function maybe_check_health() {
        $last = (int) get_option( self::HEALTH_CHECKED_OPTION, 0 );
        if ( $last && ( time() - $last ) < self::HEALTH_CHECK_EVERY ) {
            return;
        }
        update_option( self::HEALTH_CHECKED_OPTION, time() );
        self::check_health_and_alert();
    }

    /**
     * The alert state machine.
     *
     * ALERT ON TRANSITION, THEN AT MOST ONCE A DAY. A runner dead for a week
     * is one problem, not a hundred and sixty of them, and an inbox that fills
     * with identical warnings is an inbox where the next real one is missed.
     *
     * Only the two conditions the admin notice already covers are emailed:
     * three consecutive failed runs, and no completed run for three hours.
     * "Never run at all" is deliberately NOT emailed — on a fresh install that
     * is simply the truth before anything has been set up, and an alert about
     * it would arrive before the administrator had finished installing.
     *
     * @return string What it did: '', 'alert' or 'recovery'.
     */
    public static function check_health_and_alert() {
        $health  = self::health();
        $state   = $health['state'];
        $alertable = in_array( $state, array( 'failing', 'stale' ), true );

        $stored = get_option( self::ALERT_STATE_OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        $known    = isset( $stored['state'] ) ? (string) $stored['state'] : 'ok';
        $notified = isset( $stored['notified_at'] ) ? (int) $stored['notified_at'] : 0;
        $since    = isset( $stored['since'] ) ? (int) $stored['since'] : 0;

        if ( $alertable ) {
            $changed = ( $known !== $state );
            $stale   = ( $notified && ( time() - $notified ) >= self::ALERT_REPEAT_AFTER );
            if ( ! $changed && ! $stale ) {
                return ''; // already told them, and not yet a day ago
            }
            $since = $changed ? time() : ( $since ? $since : time() );
            $sent  = self::send_alert( $health, $since, $changed );
            update_option( self::ALERT_STATE_OPTION, array(
                'state'       => $state,
                'since'       => $since,
                // Only advance the clock when something actually went out, so a
                // failed send is retried at the next check rather than being
                // silently swallowed for a day.
                'notified_at' => $sent ? time() : $notified,
            ), false );
            return 'alert';
        }

        // Recovered. Say so, so nobody has to go and look.
        if ( in_array( $known, array( 'failing', 'stale' ), true ) ) {
            self::send_recovery( $health, $since );
            update_option( self::ALERT_STATE_OPTION, array(
                'state'       => 'ok',
                'since'       => 0,
                'notified_at' => 0,
            ), false );
            return 'recovery';
        }

        // Keep the stored state honest even when nothing is sent, so a move
        // from 'never' to 'ok' does not later look like a recovery.
        if ( $known !== $state ) {
            update_option( self::ALERT_STATE_OPTION, array(
                'state'       => $state,
                'since'       => 0,
                'notified_at' => 0,
            ), false );
        }
        return '';
    }

    /** The "it has stopped" email. */
    private static function send_alert( $health, $since, $is_new ) {
        $to = self::alert_recipient();
        if ( ! $to ) {
            return false;
        }

        $last_ok = (int) get_option( self::LAST_OK_OPTION, 0 );
        $site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        $subject = sprintf(
            '[%s] Calendar scheduled tasks have %s',
            $site,
            'failing' === $health['state'] ? 'been failing' : 'stopped running'
        );

        $body  = "The SFAF Calendar scheduled runner is not working.\n\n";
        $body .= 'What was detected: ' . $health['message'] . "\n\n";
        $body .= 'Last completed run: ' . self::local_time( $last_ok ) . "\n";
        $body .= 'First noticed: ' . self::local_time( $since ) . "\n";
        if ( ! $is_new ) {
            $body .= "\nThis is a reminder: the problem is still going, and this message repeats at most once a day.\n";
        }
        $body .= "\nWhile this is broken, reminder emails are not going out.\n\n";
        $body .= "Check the run log and the trigger here:\n" . self::admin_url() . "\n\n";
        $body .= 'Something external should be requesting this URL every 15 minutes: ' . self::cron_url() . "\n";
        $body .= "If that is set up at cron-jobs.org or a similar service, start by checking that the job is\n";
        $body .= "still enabled there and what it last reported.\n";

        return (bool) wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
    }

    /** The "it is working again" email. */
    private static function send_recovery( $health, $since ) {
        $to = self::alert_recipient();
        if ( ! $to ) {
            return false;
        }
        $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        $subject = sprintf( '[%s] Calendar scheduled tasks are working again', $site );

        $body  = "The SFAF Calendar scheduled runner has completed a run and is healthy again.\n\n";
        $body .= $health['message'] . "\n";
        if ( $since ) {
            $body .= 'The problem started around ' . self::local_time( $since ) . ".\n";
        }
        $body .= "\nAnything that was due while it was down did not go out at its usual time. Reminders for events\n";
        $body .= "that have already passed are not sent retrospectively.\n\n";
        $body .= "Run log:\n" . self::admin_url() . "\n";

        return (bool) wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
    }

    /**
     * A timestamp in the site's timezone, for the run log and the alert email.
     *
     * ONE FORMATTER. This held its own copy of "M j, Y \a\t g:i a", which is
     * sfaf_ap_datetime()'s output spelled out by hand, so the reminder email and
     * the RSVP tables could drift apart on the house date style. 'never' stays
     * here: it is this method's answer for "no run yet", not a date.
     */
    public static function local_time( $timestamp ) {
        if ( ! $timestamp ) {
            return 'never';
        }
        return sfaf_ap_datetime( (int) $timestamp );
    }
}
