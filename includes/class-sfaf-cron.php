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
 * simply does not happen. So the intended setup is a real system cron (cPanel
 * on Bluehost, or an external pinger) hitting wp-cron.php on the hour, with
 * DISABLE_WP_CRON in wp-config.php to stop the visitor-triggered path from
 * duplicating the work. The readme has the exact steps and URL.
 *
 * The runner does not care which of those triggered it. Real cron, WP
 * pseudo-cron and the portal's "Run now" button all land in run(), and the run
 * lock is what makes that safe.
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

    /** The scheduled action. */
    const HOOK = 'sfaf_cron_hourly';

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
    const STALE_AFTER = 10800; // 3 hours — two missed hourly runs.

    /** Where the health alert stands: the last state we told anybody about. */
    const ALERT_STATE_OPTION = 'sfaf_cron_alert_state';

    /** When the health check last ran, so it can be throttled cheaply. */
    const HEALTH_CHECKED_OPTION = 'sfaf_cron_health_checked';

    /** How often the health check is allowed to actually look. */
    const HEALTH_CHECK_EVERY = 900; // 15 minutes

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
     * Make sure the hourly event exists. Idempotent, and safe to call on
     * every request: wp_next_scheduled() is served from the cached cron array.
     */
    public static function ensure_scheduled() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, 'hourly', self::HOOK );
        }
    }

    /** Remove the schedule (deactivation). */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
            $timestamp = wp_next_scheduled( self::HOOK );
        }
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
     * The run
     * ------------------------------------------------------------------- */

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
            $tasks[] = self::run_task( 'reminders', 'Reminder emails', array( 'SFAF_Reminders', 'run' ) );

            if ( self::auto_fetch_enabled() ) {
                $tasks[] = self::run_task( 'fetch', 'Fetch from sources', array( __CLASS__, 'run_fetch' ) );
            } else {
                $tasks[] = array(
                    'task'    => 'fetch',
                    'label'   => 'Fetch from sources',
                    'status'  => 'skipped',
                    'summary' => 'Automated fetching is switched off. Use "Fetch updates" on the dashboard to run it by hand.',
                    'counts'  => array(),
                );
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
     */
    public static function run_fetch() {
        $results = SFAF_Sources::run_all();
        $lines   = array();
        $active  = 0;
        $errored = 0;
        $counts  = array( 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'unpublished' => 0 );

        foreach ( (array) $results as $r ) {
            $lines[] = SFAF_Sources::summarize( $r );
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
                    'The scheduled run has not completed since %s. Reminders are not going out. Check that the system cron job is still hitting %s.',
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
        $body .= "Check the run log and the cron configuration here:\n" . self::admin_url() . "\n\n";
        $body .= 'The cron job should be requesting: ' . self::cron_url() . "\n";

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

    /** Format a timestamp in the site's timezone. */
    public static function local_time( $timestamp ) {
        if ( ! $timestamp ) {
            return 'never';
        }
        return wp_date( 'M j, Y \a\t g:i a', (int) $timestamp );
    }
}
