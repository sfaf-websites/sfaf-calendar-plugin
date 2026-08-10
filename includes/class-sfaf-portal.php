<?php
/**
 * /caladmin — a standalone front-end portal for managing the calendar.
 *
 * Authenticates against WordPress users but renders its own chrome (no WP
 * admin bar, sidebar, or dashboard). Routing is handled internally off a
 * single rewrite rule.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Portal {

    /** Error string shown on the login screen. */
    private $login_error = '';

    /** Whether to load the WP media library (event form image picker). */
    private $load_media = false;

    /* =====================================================================
     * Bootstrap
     * ================================================================== */

    public function register() {
        // register() runs on the `init` hook, so register the rules directly.
        self::add_rewrite_rules();
        add_filter( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );

        // "Fetch updates" over AJAX, so the button can report finishing and
        // report failing rather than the page simply sitting there. The plain
        // POST route is still in dispatch_post() and still works without
        // JavaScript.
        add_action( 'wp_ajax_sfaf_portal_fetch', array( $this, 'ajax_fetch_sources' ) );
    }

    /**
     * Run a source fetch and say what happened.
     *
     * A fetch is several seconds of remote HTTP with nothing on screen, and
     * the old form POST gave no sign it had started, no sign it had finished,
     * and no sign when it failed: the page simply reloaded, or did not.
     *
     * The report still goes into the same per-user transient the redirect
     * target reads, so there is exactly one piece of report-rendering code and
     * the AJAX and non-AJAX routes cannot drift apart.
     */
    public function ajax_fetch_sources() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You are signed out. Sign in again and retry.' ), 403 );
        }
        // $die = false, so an expired nonce comes back as a readable sentence
        // rather than a bare "-1" the browser cannot parse as JSON. A page left
        // open overnight is the ordinary way to reach this.
        if ( ! check_ajax_referer( 'sfaf_portal_fetch', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'This page has been open a while and its security token expired. Reload and try again.' ), 403 );
        }

        $user = wp_get_current_user();
        if ( ! $this->is_admin_role( $user ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to fetch from sources.' ), 403 );
        }

        try {
            $results = SFAF_Sources::run_all();
        } catch ( \Throwable $e ) {
            // Contained and reported. run_all() already contains a single
            // adapter's failure, so reaching here means the framework broke.
            wp_send_json_error( array( 'message' => 'The fetch stopped unexpectedly: ' . $e->getMessage() ) );
        }

        set_transient( 'sfaf_fetch_report_' . $user->ID, $results, 10 * MINUTE_IN_SECONDS );
        wp_send_json_success( array(
            'redirect' => add_query_arg( 'msg', 'fetched', $this->url( 'pending' ) ),
        ) );
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule( '^caladmin/?$', 'index.php?uc_caladmin=1', 'top' );
        add_rewrite_rule( '^caladmin/(.+?)/?$', 'index.php?uc_caladmin=1&uc_caladmin_route=$matches[1]', 'top' );
    }

    public function query_vars( $vars ) {
        $vars[] = 'uc_caladmin';
        $vars[] = 'uc_caladmin_route';
        return $vars;
    }

    public function maybe_render() {
        if ( ! get_query_var( 'uc_caladmin' ) ) {
            return;
        }
        $this->handle( (string) get_query_var( 'uc_caladmin_route' ) );
        exit;
    }

    /* =====================================================================
     * Roles & capabilities
     * ================================================================== */

    /** The calendar access levels, worst to best, and what to call them. */
    public static function roles() {
        return array(
            'contributor' => 'Contributor',
            'editor'      => 'Editor',
            'admin'       => 'Admin',
        );
    }

    /**
     * Whether this user is a WordPress administrator.
     *
     * The single question everything below asks. manage_options is the
     * capability that means "may change how this site runs", so it is also what
     * "may do anything in the calendar" means. Kept as one function so there is
     * one answer and one place to read it.
     *
     * @param int $user_id
     * @return bool
     */
    public static function is_site_admin( $user_id ) {
        return user_can( (int) $user_id, 'manage_options' );
    }

    /**
     * What to call an access level on screen.
     *
     * One list, roles(), and one place that turns a key into words. The
     * sidebar footer says "Admin" because that is what the Users screen calls
     * the same thing, and a level that is somehow not one of the three is named
     * "No access" rather than printed raw.
     *
     * @param string $role
     * @return string
     */
    public static function role_label( $role ) {
        $roles = self::roles();
        return isset( $roles[ $role ] ) ? $roles[ $role ] : 'No access';
    }

    /**
     * Somebody's calendar access level.
     *
     * ADMINISTRATORS ARE CHECKED FIRST, AND THE STORED RECORD CANNOT OVERRIDE
     * THEM.
     *
     * This used to read the meta first and only fall back to manage_options
     * when there was none, which made the calendar user record able to REDUCE a
     * WordPress administrator. It did, in 3.5.0: an administrator who added
     * himself on the Users screen so that he could be put in a team got the
     * form's default level, 'contributor', written against his account, and the
     * entrance gate then held him to it. His WordPress role was never touched
     * and never could have been; the calendar simply stopped believing it.
     *
     * So the order is now: are you an administrator? Then you have full access,
     * whatever any record says. The record still exists and still matters, for
     * teams and for the notification picker, but it can only ever describe
     * somebody who is not already an administrator.
     *
     * @param int $user_id
     * @return string 'admin'|'editor'|'contributor'|'' (no access)
     */
    public static function get_role( $user_id ) {
        // Memoize: every capability check (can_view_all/can_create/...) resolves
        // the role, so a single page render asks for it many times.
        static $cache = array();
        $user_id = (int) $user_id;
        if ( isset( $cache[ $user_id ] ) ) {
            return $cache[ $user_id ];
        }

        if ( self::is_site_admin( $user_id ) ) {
            $cache[ $user_id ] = 'admin';
            return 'admin';
        }

        $role  = get_user_meta( $user_id, '_uc_calendar_role', true );
        $known = self::roles();
        // Only the three known levels grant anything. A meta value that is not
        // one of them is not access by accident.
        if ( ! is_string( $role ) || ! isset( $known[ $role ] ) ) {
            $role = '';
        }

        $cache[ $user_id ] = $role;
        return $role;
    }

    private function is_admin_role( $user ) {
        return self::get_role( $user->ID ) === 'admin';
    }

    private function can_view_all( $user ) {
        return in_array( self::get_role( $user->ID ), array( 'admin', 'editor' ), true );
    }

    private function can_create( $user ) {
        return in_array( self::get_role( $user->ID ), array( 'admin', 'editor', 'contributor' ), true );
    }

    private function can_edit_event( $user, $post ) {
        if ( $this->can_view_all( $user ) ) {
            return true;
        }
        // Contributor: only their own events.
        return (int) $post->post_author === (int) $user->ID;
    }

    /** Status a contributor's published event lands in (auto vs review). */
    private function contributor_status( $user ) {
        $mode = get_user_meta( $user->ID, '_uc_calendar_approval', true );
        return $mode === 'auto' ? 'publish' : 'pending';
    }

    private function allowed_categories( $user ) {
        $cats = get_user_meta( $user->ID, '_uc_calendar_categories', true );
        return is_array( $cats ) ? $cats : array(); // empty array = all
    }

    /* =====================================================================
     * URLs & helpers
     * ================================================================== */

    public function url( $path = '' ) {
        $base = home_url( '/caladmin' );
        return $path ? $base . '/' . ltrim( $path, '/' ) : $base;
    }

    private function redirect( $path = '', $args = array() ) {
        $url = $this->url( $path );
        if ( $args ) {
            $url = add_query_arg( $args, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }

    /* =====================================================================
     * Request handling
     * ================================================================== */

    public function handle( $route ) {
        nocache_headers();
        $segments = array_values( array_filter( explode( '/', trim( $route, '/' ) ) ) );

        // POST dispatch (may redirect + exit).
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['uc_action'] ) ) {
            $this->dispatch_post( sanitize_key( $_POST['uc_action'] ) );
        }

        // CSV exports stream and exit.
        if ( isset( $segments[0], $segments[1] ) && $segments[0] === 'rsvps' && $segments[1] === 'export' ) {
            $this->export_rsvps_csv();
        }
        if ( isset( $segments[0], $segments[1] ) && $segments[0] === 'optins' && $segments[1] === 'export' ) {
            $this->export_optins_csv();
        }

        if ( ! is_user_logged_in() ) {
            $this->render_login();
            return;
        }

        $user = wp_get_current_user();
        if ( ! self::get_role( $user->ID ) ) {
            $this->render_denied( $user );
            return;
        }

        status_header( 200 );
        $page = isset( $segments[0] ) ? $segments[0] : 'dashboard';

        switch ( $page ) {
            case 'events':
                if ( isset( $segments[1] ) && $segments[1] === 'new' ) {
                    $this->render_event_form( $user, 0 );
                } elseif ( isset( $segments[1] ) && $segments[1] === 'edit' ) {
                    $this->render_event_form( $user, isset( $segments[2] ) ? intval( $segments[2] ) : 0 );
                } else {
                    $this->render_events( $user );
                }
                break;
            case 'series':
                // /series/orphans is gone: it existed only to manage a series
                // being an event, which cannot happen now. /series/remove is
                // back, and is a different screen from the one that used to be
                // there: it asks what should happen to the events before
                // anything is removed. See render_series_remove().
                if ( isset( $segments[1] ) && $segments[1] === 'remove' ) {
                    $this->render_series_remove( $user, isset( $segments[2] ) ? intval( $segments[2] ) : 0 );
                } elseif ( isset( $segments[1] ) && $segments[1] === 'edit' ) {
                    $this->render_series_edit( $user, isset( $segments[2] ) ? intval( $segments[2] ) : 0 );
                } elseif ( isset( $segments[1] ) && $segments[1] === 'new' ) {
                    $this->render_series_edit( $user, 0 );
                } else {
                    $this->render_series_list( $user );
                }
                break;
            case 'rsvps':      $this->render_rsvps( $user ); break;
            case 'optins':     $this->render_optins( $user ); break;
            case 'pending':    $this->render_pending( $user ); break;
            case 'automation':
                // Moved to the WordPress admin in 2.13.0. Kept as a redirect
                // rather than deleted, because this URL has been linked from
                // notices, the readme and anybody's bookmarks.
                wp_safe_redirect( SFAF_Cron::admin_url(), 301 );
                exit;
            case 'users':      $this->render_users( $user ); break;
            case 'venues':     $this->render_venues( $user ); break;
            case 'faq-sets':   $this->render_faq_sets( $user ); break;
            default:           $this->render_dashboard( $user );
        }
    }

    private function dispatch_post( $action ) {
        if ( ! is_user_logged_in() ) {
            if ( $action === 'login' ) {
                $this->process_login();
            }
            return;
        }

        $user  = wp_get_current_user();
        $nonce = isset( $_POST['uc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['uc_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'uc_portal_' . $action ) ) {
            wp_die( 'Security check failed.' );
        }

        switch ( $action ) {
            case 'save_event':
                $result = $this->save_event_from_post( $user );
                $this->redirect( 'events/edit/' . $result['id'], array( 'msg' => $result['msg'] ) );
                break;

            /* ---- Removing things. -------------------------------------------
             *
             * TWO ACTIONS NOW, AND ONE OF THEM IS ORDINARY.
             *
             *   trash_event   an event goes away. Any event. That is all it does.
             *   remove_series the grouping goes; every event in it stays.
             *
             * There used to be a third — cancel_occurrence — plus a screen
             * asking whether removing a series meant removing everything or
             * promoting the next occurrence. Both existed because deleting an
             * event could damage other events: a parent orphaned its children,
             * and a deleted occurrence came back on the next save unless the
             * date was recorded as cancelled. Nothing regenerates any more, so
             * deleting an event deletes an event and nothing brings it back. */
            case 'trash_event':
                $event_id = intval( $_POST['event_id'] );
                $post     = get_post( $event_id );
                if ( ! $post || $post->post_type !== 'uc_event' || ! $this->can_edit_event( $user, $post ) ) {
                    $this->redirect( 'events', array( 'msg' => 'trashed' ) );
                }
                wp_trash_post( $event_id );
                $this->redirect( 'events', array( 'msg' => 'trashed' ) );
                break;

            /*
             * DUPLICATE. Inserts one new draft and changes nothing else.
             *
             * Lands the manager in the editor on the copy, because the copy has
             * no date and cannot be published until it does. See
             * duplicate_event() for what travels and what does not.
             */
            case 'duplicate_event':
                $new_id = $this->duplicate_event( $user, intval( $_POST['event_id'] ) );
                if ( is_wp_error( $new_id ) ) {
                    $this->redirect( 'events', array( 'msg' => 'duplicate_failed' ) );
                }
                $this->redirect( 'events/edit/' . (int) $new_id, array( 'msg' => 'duplicated' ) );
                break;

            /*
             * REMOVING A SERIES IS A CHOICE, AND IT IS MADE ON A SCREEN.
             *
             * This used to delete on the click behind a browser confirm() that
             * promised the events were safe. See render_series_remove() for
             * why that was the wrong shape. Everything below is re-derived
             * from the stored data: the mode is one of two known strings, the
             * target series is checked for existence and against being the one
             * being removed, and the counts come out of the queries rather
             * than out of the form.
             */
            case 'remove_series':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $term_id = intval( $_POST['series_id'] );
                $mode    = isset( $_POST['remove_mode'] ) ? sanitize_key( wp_unslash( $_POST['remove_mode'] ) ) : 'keep_events';

                // The sub-choice only means anything under "keep", and only
                // when it says to move rather than to leave unassigned.
                $move_to = 0;
                if ( 'delete_events' !== $mode
                    && isset( $_POST['keep_target'] ) && 'move' === sanitize_key( wp_unslash( $_POST['keep_target'] ) ) ) {
                    $move_to = isset( $_POST['keep_target_series'] ) ? intval( $_POST['keep_target_series'] ) : 0;
                }

                $done = SFAF_Series::remove( $term_id, $mode, $move_to );
                if ( is_wp_error( $done ) ) {
                    $this->redirect( 'series', array( 'msg' => 'series_failed' ) );
                }
                $this->redirect( 'series', array(
                    'msg'      => 'series_removed',
                    'trashed'  => (int) $done['trashed'],
                    'detached' => (int) $done['detached'],
                    'moved'    => (int) $done['moved'],
                ) );
                break;

            case 'save_series':
                $id = $this->save_series_from_post( $user );
                if ( ! $id ) {
                    $this->redirect( 'series', array( 'msg' => 'series_failed' ) );
                }
                $this->redirect( 'series/edit/' . $id, array( 'msg' => 'series_saved' ) );
                break;

            /* ---- The schedule. Three writes, all bounded by "upcoming". ----
             *
             * Gated exactly as series editing is, because that is what this is:
             * the schedule screen IS the series screen. Each one re-derives its
             * targets from the stored data rather than from the form, so a POST
             * naming an event in another series, or a date that has passed,
             * achieves nothing.
             */
            case 'schedule_pattern':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $this->schedule_pattern_from_post();
                break;

            case 'schedule_add_date':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $this->schedule_add_date_from_post();
                break;

            case 'schedule_remove_date':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $this->schedule_remove_date_from_post();
                break;

            /* ---- Categories. What kind of event this is. ------------------ */
            case 'save_category':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $saved = SFAF_Categories::save(
                    isset( $_POST['category_id'] ) ? intval( $_POST['category_id'] ) : 0,
                    isset( $_POST['category_name'] ) ? wp_unslash( $_POST['category_name'] ) : '',
                    isset( $_POST['category_color'] ) ? wp_unslash( $_POST['category_color'] ) : '',
                    isset( $_POST['category_icon'] ) ? sanitize_key( wp_unslash( $_POST['category_icon'] ) ) : ''
                );
                if ( is_wp_error( $saved ) ) {
                    set_transient( 'sfaf_category_error_' . $user->ID, $saved->get_error_message(), 60 );
                    $this->redirect( 'series', array( 'msg' => 'category_failed' ) );
                }
                $this->redirect( 'series', array( 'msg' => 'category_saved' ) );
                break;

            case 'delete_category':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $gone = SFAF_Categories::delete( isset( $_POST['category_id'] ) ? intval( $_POST['category_id'] ) : 0 );
                if ( is_wp_error( $gone ) ) {
                    set_transient( 'sfaf_category_error_' . $user->ID, $gone->get_error_message(), 60 );
                    $this->redirect( 'series', array( 'msg' => 'category_failed' ) );
                }
                $this->redirect( 'series', array( 'msg' => 'category_deleted', 'freed' => (int) $gone ) );
                break;

            /* ---- Venues. A name and an address, and events point at them. -- */
            case 'save_venue':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $saved = SFAF_Venues::save(
                    isset( $_POST['venue_id'] ) ? intval( $_POST['venue_id'] ) : 0,
                    isset( $_POST['venue_name'] ) ? wp_unslash( $_POST['venue_name'] ) : '',
                    array(
                        'street' => isset( $_POST['venue_street'] ) ? wp_unslash( $_POST['venue_street'] ) : '',
                        'city'   => isset( $_POST['venue_city'] ) ? wp_unslash( $_POST['venue_city'] ) : '',
                        'state'  => isset( $_POST['venue_state'] ) ? wp_unslash( $_POST['venue_state'] ) : '',
                        'zip'    => isset( $_POST['venue_zip'] ) ? wp_unslash( $_POST['venue_zip'] ) : '',
                    )
                );
                if ( is_wp_error( $saved ) ) {
                    set_transient( 'sfaf_venue_error_' . $user->ID, $saved->get_error_message(), 60 );
                    $this->redirect( 'venues', array( 'msg' => 'venue_failed' ) );
                }
                $this->redirect( 'venues', array( 'msg' => 'venue_saved' ) );
                break;

            case 'delete_venue':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                /*
                 * REFUSED WHILE IN USE, and the events are named. They keep no
                 * address of their own, so deleting the venue would leave them
                 * with nowhere to be rather than with stale text. Same shape of
                 * refusal as deleting a team that events still notify.
                 */
                $done = SFAF_Venues::delete( isset( $_POST['venue_id'] ) ? intval( $_POST['venue_id'] ) : 0 );
                if ( is_wp_error( $done ) ) {
                    $data = $done->get_error_data();
                    set_transient( 'sfaf_venue_error_' . $user->ID, $done->get_error_message(), 120 );
                    set_transient( 'sfaf_venue_blocked_' . $user->ID, isset( $data['events'] ) ? $data['events'] : array(), 120 );
                    $this->redirect( 'venues', array( 'msg' => 'venue_in_use' ) );
                }
                $this->redirect( 'venues', array( 'msg' => 'venue_deleted' ) );
                break;

            case 'approve_event':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $event_id = intval( $_POST['event_id'] );
                wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ) );
                $this->redirect( 'pending', array( 'msg' => 'approved' ) );
                break;

            case 'reject_event':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                wp_trash_post( intval( $_POST['event_id'] ) );
                $this->redirect( 'pending', array( 'msg' => 'rejected' ) );
                break;

            /*
             * NO set_language ACTION. There never should have been one.
             *
             * 3.16.0 read "move the English/Espanol control into the sidebar"
             * as licence to build one when the plugin had none: nothing in this
             * codebase rendered those two words, and caladmin emits its own
             * document with no wp_head or wp_footer, so nothing could have
             * injected one either. The control the instruction described
             * belongs to Weglot and is not ours to move. It is hidden on these
             * screens in portal.css and left alone everywhere else.
             *
             * The whole feature is gone in 3.17.0: this case, the toggle, the
             * languages list, the locale write and the switch_to_locale() call.
             * If a language control is ever wanted here it is a new decision,
             * not a tidy-up.
             */
            case 'fetch_sources':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                // The report has to survive the redirect that stops a refresh
                // re-running the fetch, so it goes in a short-lived transient
                // keyed to this user.
                $results = SFAF_Sources::run_all();
                set_transient( 'sfaf_fetch_report_' . $user->ID, $results, 10 * MINUTE_IN_SECONDS );
                // Lands on Pending, where the report and the queue it fills
                // both live. The old /caladmin/?msg=fetched still resolves to
                // the Dashboard, so an old bookmark is not a dead link.
                $this->redirect( 'pending', array( 'msg' => 'fetched' ) );
                break;

            /* The scheduled runner's controls (Run now, Clear log) moved to
             * the WordPress admin in 2.13.0 along with the rest of Automation.
             * See SFAF_Admin::handle_cron_action(). */

            case 'import_dismiss':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                SFAF_Sources::move( intval( $_POST['event_id'] ), SFAF_Sources::STATUS_DISMISSED );
                $this->redirect( 'pending', array( 'msg' => 'import_dismissed' ) );
                break;

            case 'import_restore':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                SFAF_Sources::move( intval( $_POST['event_id'] ), SFAF_Sources::STATUS_PENDING );
                $this->redirect( 'pending', array( 'msg' => 'import_restored' ) );
                break;

            case 'refresh_source_event':
                $event_id = intval( $_POST['event_id'] );
                $post     = get_post( $event_id );
                // Same capability rule as every other edit in this portal.
                if ( ! $post || $post->post_type !== 'uc_event' || ! $this->can_edit_event( $user, $post ) ) {
                    wp_die( 'Denied' );
                }
                $refresh = SFAF_Sources::refresh_event( $event_id );
                set_transient(
                    'sfaf_refresh_result_' . $user->ID . '_' . $event_id,
                    is_wp_error( $refresh )
                        ? array( 'error' => $refresh->get_error_message() )
                        : $refresh,
                    5 * MINUTE_IN_SECONDS
                );
                $this->redirect( 'events/edit/' . $event_id, array( 'msg' => 'refreshed' ) );
                break;

            /*
             * The manager-owned panel, saved from the pending queue.
             *
             * The same controls the editor renders, on the same event, posting
             * only themselves: nothing else about the event is read or written
             * here. It exists so approving an import does not have to mean
             * opening a second screen to set two fields.
             */
            case 'save_manager_fields':
                $event_id = $this->save_manager_panel_from_post( $user );
                $this->redirect( 'pending', array( 'msg' => 'manager_saved', 'event' => $event_id ) );
                break;

            case 'import_publish':
                // Publishing is not a one-click move: the platform supplies the
                // title, times and location, but the category, organizer and
                // series are local decisions. So this opens the event for those
                // to be assigned, and the form's own Publish button is what
                // puts it on the calendar.
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $event_id = intval( $_POST['event_id'] );
                if ( ! SFAF_Sources::is_queued( $event_id ) ) {
                    $this->redirect( 'pending' );
                }
                $this->redirect( 'events/edit/' . $event_id, array( 'msg' => 'import_review' ) );
                break;

            /* ---- FAQ sets. ------------------------------------------------
             *
             * These live outside the event form rather than inside it. HTML
             * forms cannot nest, and applying a set is its own post-and-
             * redirect that must not carry the whole editor's fields with it,
             * so the controls sit in their own panel above the form. Same
             * reasoning as the "Refresh from source" panel. */
            case 'faq_set_apply':
                $event_id = intval( $_POST['event_id'] );
                $post     = get_post( $event_id );
                if ( ! $post || $post->post_type !== 'uc_event' || ! $this->can_edit_event( $user, $post ) ) {
                    wp_die( 'Denied' );
                }
                $result = SFAF_FAQ_Sets::apply(
                    $event_id,
                    isset( $_POST['faq_set_id'] ) ? sanitize_text_field( wp_unslash( $_POST['faq_set_id'] ) ) : '',
                    ( isset( $_POST['faq_set_mode'] ) && 'replace' === $_POST['faq_set_mode'] ) ? 'replace' : 'append'
                );
                set_transient(
                    'sfaf_faq_set_result_' . $user->ID . '_' . $event_id,
                    is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result,
                    5 * MINUTE_IN_SECONDS
                );
                $this->redirect( 'events/edit/' . $event_id, array( 'msg' => 'faq_set_applied' ) );
                break;

            case 'faq_set_create':
                $event_id = intval( $_POST['event_id'] );
                $post     = get_post( $event_id );
                if ( ! $post || $post->post_type !== 'uc_event' || ! $this->can_edit_event( $user, $post ) ) {
                    wp_die( 'Denied' );
                }
                $created = SFAF_FAQ_Sets::create_from_event(
                    $event_id,
                    isset( $_POST['faq_set_name'] ) ? wp_unslash( $_POST['faq_set_name'] ) : ''
                );
                set_transient(
                    'sfaf_faq_set_result_' . $user->ID . '_' . $event_id,
                    is_wp_error( $created )
                        ? array( 'error' => $created->get_error_message() )
                        : array( 'created' => (string) $created ),
                    5 * MINUTE_IN_SECONDS
                );
                $this->redirect( 'events/edit/' . $event_id, array( 'msg' => 'faq_set_saved' ) );
                break;

            case 'faq_set_save':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                SFAF_FAQ_Sets::save(
                    isset( $_POST['faq_set_id'] ) ? sanitize_text_field( wp_unslash( $_POST['faq_set_id'] ) ) : '',
                    isset( $_POST['faq_set_name'] ) ? wp_unslash( $_POST['faq_set_name'] ) : '',
                    isset( $_POST['faq_set_rows'] ) ? wp_unslash( $_POST['faq_set_rows'] ) : array()
                );
                $this->redirect( 'faq-sets', array( 'msg' => 'faq_set_saved' ) );
                break;

            case 'faq_set_delete':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                SFAF_FAQ_Sets::delete( isset( $_POST['faq_set_id'] ) ? sanitize_text_field( wp_unslash( $_POST['faq_set_id'] ) ) : '' );
                $this->redirect( 'faq-sets', array( 'msg' => 'faq_set_deleted' ) );
                break;

            case 'set_user_role':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $this->save_user_role();
                $this->redirect( 'users', array( 'msg' => 'user_saved' ) );
                break;

            case 'add_user':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $uid = intval( $_POST['user_id'] );
                if ( $uid && get_userdata( $uid ) ) {
                    /*
                     * THE LEVEL AN ADMINISTRATOR IS ADDED AT IS 'admin',
                     * whatever the form said. Adding yourself here is how
                     * somebody gets into the team picker, and in 3.5.0 doing it
                     * wrote the form's default against your own account and cost
                     * you the portal. See SFAF_Portal::get_role().
                     */
                    update_user_meta( $uid, '_uc_calendar_role', self::storable_role( $uid, $_POST['role'] ?? 'contributor' ) );
                }
                $this->redirect( 'users', array( 'msg' => 'user_added' ) );
                break;

            case 'remove_user':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $uid = intval( $_POST['user_id'] );
                delete_user_meta( $uid, '_uc_calendar_role' );
                delete_user_meta( $uid, '_uc_calendar_approval' );
                delete_user_meta( $uid, '_uc_calendar_categories' );
                // Out of the calendar means out of its teams. Nothing else has
                // to happen: no event stored them, so no event has to be
                // corrected. See SFAF_Teams.
                SFAF_Teams::forget_user( $uid );
                $this->redirect( 'users', array( 'msg' => 'user_removed' ) );
                break;

            /*
             * THE RSVP SETTINGS, SAVED FROM THE REGISTRATIONS SCREEN.
             *
             * Ends at the same method the event editor ends at, so a rule
             * about how one of these is stored cannot be true on one screen
             * and false on the other. This action writes nothing else about
             * the event: the title, the date and everything else are the
             * editor's business and are not on this form.
             */
            case 'save_rsvp_settings':
                $event_id = intval( $_POST['event_id'] );
                $post     = get_post( $event_id );
                if ( ! $post || 'uc_event' !== $post->post_type || ! $this->can_edit_event( $user, $post ) ) {
                    wp_die( 'Denied' );
                }
                $src_slug  = (string) get_post_meta( $event_id, SFAF_Sources::META_SOURCE, true );
                $src_owned = ( '' !== $src_slug ) ? SFAF_Sources::owned_fields_for( $src_slug ) : array();
                $this->save_rsvp_settings_from_post(
                    $user,
                    $event_id,
                    function ( $field ) use ( $src_owned ) {
                        return in_array( $field, $src_owned, true );
                    }
                );
                $this->redirect( 'rsvps', array( 'event_id' => $event_id, 'msg' => 'rsvp_settings_saved' ) );
                break;

            /* ---- Teams. A name and a set of users, and nothing else. ------ */
            case 'save_team':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                /*
                 * WHICH IDS THE FORM ACTUALLY OFFERED.
                 *
                 * Three different forms post this action: rename, which shows
                 * no members at all; create, likewise; and the member picker,
                 * which shows the calendar's users and nobody else. Without
                 * team_offered, the first two would read "no boxes ticked" as
                 * "empty this team", and the third would silently drop any
                 * member who has no calendar role. The list of ids on offer
                 * travels with the ticks, and SFAF_Teams::save() keeps
                 * everything stored outside it.
                 */
                $offered = isset( $_POST['team_offered'] )
                    ? array_map( 'intval', (array) wp_unslash( $_POST['team_offered'] ) )
                    : array();
                $saved = SFAF_Teams::save(
                    isset( $_POST['team_id'] ) ? sanitize_key( wp_unslash( $_POST['team_id'] ) ) : '',
                    isset( $_POST['team_name'] ) ? wp_unslash( $_POST['team_name'] ) : '',
                    isset( $_POST['team_users'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['team_users'] ) ) : array(),
                    $offered
                );
                if ( is_wp_error( $saved ) ) {
                    set_transient( 'sfaf_team_error_' . $user->ID, $saved->get_error_message(), 60 );
                    $this->redirect( 'users', array( 'msg' => 'team_failed' ) );
                }
                $this->redirect( 'users', array( 'msg' => 'team_saved' ) );
                break;

            case 'delete_team':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $done = SFAF_Teams::delete( isset( $_POST['team_id'] ) ? sanitize_key( wp_unslash( $_POST['team_id'] ) ) : '' );
                if ( is_wp_error( $done ) ) {
                    /*
                     * REFUSED, AND SAID SO WITH THE EVENTS NAMED.
                     *
                     * The events are carried in a transient rather than in the
                     * URL because there can be a lot of them and they need
                     * titles and links, not ids. See SFAF_Teams::delete() for
                     * why this refuses rather than warning and deleting.
                     */
                    $data = $done->get_error_data();
                    set_transient( 'sfaf_team_error_' . $user->ID, $done->get_error_message(), 120 );
                    set_transient( 'sfaf_team_blocked_' . $user->ID, isset( $data['events'] ) ? $data['events'] : array(), 120 );
                    $this->redirect( 'users', array( 'msg' => 'team_in_use' ) );
                }
                $this->redirect( 'users', array( 'msg' => 'team_deleted' ) );
                break;
        }
    }

    private function process_login() {
        $nonce = isset( $_POST['uc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['uc_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'uc_portal_login' ) ) {
            $this->login_error = 'Security check failed. Please try again.';
            return;
        }
        $creds = array(
            'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
            'user_password' => (string) ( $_POST['pwd'] ?? '' ),
            'remember'      => ! empty( $_POST['rememberme'] ),
        );
        $user = wp_signon( $creds, is_ssl() );
        if ( is_wp_error( $user ) ) {
            $this->login_error = 'Invalid username or password.';
            return;
        }
        wp_set_current_user( $user->ID );
        $this->redirect();
    }

    /**
     * What to actually store as somebody's calendar access level.
     *
     * An administrator's record is stored as 'admin' and nothing else. Not
     * because the stored value decides anything for them any more (get_role()
     * settles that before it reads the meta), but so that the row in the
     * database says the same thing the screens say. A record reading
     * "contributor" against an administrator is a lie waiting to become true
     * the day somebody takes their WordPress role away.
     *
     * @param int    $user_id
     * @param string $requested
     * @return string
     */
    public static function storable_role( $user_id, $requested ) {
        if ( self::is_site_admin( $user_id ) ) {
            return 'admin';
        }
        $role  = sanitize_key( $requested );
        $known = self::roles();
        return isset( $known[ $role ] ) ? $role : 'contributor';
    }

    /* =====================================================================
     * Event create / update
     * ================================================================== */

    private function save_event_from_post( $user ) {
        $event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
        $is_new   = ! $event_id;

        if ( $is_new ) {
            if ( ! $this->can_create( $user ) ) { wp_die( 'Denied' ); }
        } else {
            $post = get_post( $event_id );
            if ( ! $post || $post->post_type !== 'uc_event' || ! $this->can_edit_event( $user, $post ) ) {
                wp_die( 'Denied' );
            }
        }

        $role      = self::get_role( $user->ID );
        $save_mode = isset( $_POST['save_mode'] ) ? sanitize_key( $_POST['save_mode'] ) : 'draft';

        if ( $save_mode === 'publish' ) {
            $status = in_array( $role, array( 'admin', 'editor' ), true ) ? 'publish' : $this->contributor_status( $user );
        } elseif ( $save_mode === 'review' ) {
            $status = 'pending';
        } else {
            $status = 'draft';
        }

        // WHICH FIELDS THIS SAVE IS ALLOWED TO WRITE.
        //
        // A locked field renders `disabled`, and a disabled control submits
        // NOTHING — so without this, saving an imported event would read '' for
        // its title and description and write both back, replacing a real title
        // with "(untitled event)" and blanking the description. Every meta
        // field below is already guarded by isset(), which handles the absent
        // case correctly; these two are not, because they have fallbacks.
        //
        // The list comes from the adapter, so it is the same list the editor
        // disabled and the same list a fetch overwrites.
        $src_slug   = $event_id ? (string) get_post_meta( $event_id, SFAF_Sources::META_SOURCE, true ) : '';
        $src_owned  = ( '' !== $src_slug ) ? SFAF_Sources::owned_fields_for( $src_slug ) : array();
        $is_locked  = function ( $field ) use ( $src_owned ) {
            return in_array( $field, $src_owned, true );
        };

        $postarr = array(
            'post_type'   => 'uc_event',
            'post_status' => $status,
        );
        // Locked means locked in both directions: a submitted value for a
        // locked field is ignored rather than trusted, so tampering with the
        // form achieves nothing that the next fetch would not undo anyway.
        if ( ! $is_locked( 'title' ) ) {
            $postarr['post_title'] = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ) ?: '(untitled event)';
        }
        if ( ! $is_locked( 'description' ) ) {
            $postarr['post_content'] = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
        }

        if ( $is_new ) {
            $postarr['post_author'] = $user->ID;
            $event_id = wp_insert_post( $postarr, true );
        } else {
            $postarr['ID'] = $event_id;
            wp_update_post( $postarr );
        }
        if ( is_wp_error( $event_id ) ) {
            wp_die( 'Could not save event.' );
        }

        // Meta.
        //
        // 'recurrence' and 'end_date' are gone from this list. The cadence is
        // an instruction to the generator now, handled once at the bottom of
        // this method, and _uc_end_date means only what a source says an
        // event's end date is — which this editor has no business writing.
        //
        // 'capacity' is gone from this list too, and 'rsvp_enabled' from the
        // toggles below. Both are RSVP settings, and the registrations page
        // offers the same controls, so they are written in one place that both
        // forms reach: save_rsvp_settings_from_post(), called further down.
        $text = array(
            'date'       => '_uc_event_date',
            'start_time' => '_uc_start_time',
            'end_time'   => '_uc_end_time',
        );
        $is_imported = ( '' !== $src_slug );

        foreach ( $text as $field => $key ) {
            if ( $is_locked( $field ) ) {
                continue; // the platform's, and a fetch would put it back anyway
            }
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $event_id, $key, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }

        /*
         * WHERE IT HAPPENS: A VENUE, OR TEXT, NEVER BOTH.
         *
         * The editor posts both controls, because both are in the form and a
         * radio decides which one meant it. That decision is made HERE, once,
         * and the other one is cleared, so an event never carries a venue and a
         * contradicting line of text for anything downstream to choose between.
         * sfaf_event_location() is then a lookup rather than a judgement.
         *
         * An imported event is left entirely alone: the platform owns this
         * field and writes it on every fetch, so the picker is not rendered
         * there and 'location_mode' does not arrive.
         */
        if ( ! $is_locked( 'location' ) && isset( $_POST['location_mode'] ) ) {
            $mode  = ( 'venue' === sanitize_key( wp_unslash( $_POST['location_mode'] ) ) ) ? 'venue' : 'custom';
            $venue = isset( $_POST['venue'] ) ? intval( $_POST['venue'] ) : 0;

            if ( 'venue' === $mode && $venue && SFAF_Venues::exists( $venue ) ) {
                SFAF_Venues::set_for_event( $event_id, $venue );
                delete_post_meta( $event_id, '_uc_location' );
                foreach ( sfaf_location_part_keys() as $key ) {
                    delete_post_meta( $event_id, $key );
                }
            } else {
                SFAF_Venues::set_for_event( $event_id, 0 );

                /*
                 * FOUR PARTS IN, ONE LINE OUT. The parts are the record and
                 * `_uc_location` is composed from them here, on every save, so
                 * the thirteen readers of sfaf_event_location() keep reading a
                 * string and the two can never disagree. Same arrangement as a
                 * venue's address, using the same composer.
                 *
                 * A form that carried the old single field instead posts
                 * `location` and no parts; that is handled in the branch below.
                 */
                $posted = array();
                foreach ( sfaf_location_part_keys() as $part => $key ) {
                    $field           = 'location_' . $part;
                    $posted[ $part ] = isset( $_POST[ $field ] )
                        ? trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) )
                        : '';
                    if ( '' !== $posted[ $part ] ) {
                        update_post_meta( $event_id, $key, $posted[ $part ] );
                    } else {
                        delete_post_meta( $event_id, $key );
                    }
                }

                $line = SFAF_Venues::compose( $posted );
                if ( '' === $line && isset( $_POST['location'] ) ) {
                    // An older form, or one with the parts empty: fall back to
                    // whatever single field it did carry rather than blanking a
                    // location that is already there.
                    $line = sanitize_text_field( wp_unslash( $_POST['location'] ) );
                }
                update_post_meta( $event_id, '_uc_location', $line );
            }
        } elseif ( ! $is_locked( 'location' ) && isset( $_POST['location'] ) ) {
            // A form that carried the plain field and no mode: the pending
            // queue, and any older bookmarked form. Text only, venue untouched.
            update_post_meta( $event_id, '_uc_location', sanitize_text_field( wp_unslash( $_POST['location'] ) ) );
        }
        // The Donate URL is the campaign link on an imported campaign, which a
        // fetch writes, so it locks under source_url.
        if ( isset( $_POST['gofundme_url'] ) && ! $is_locked( 'source_url' ) ) {
            update_post_meta( $event_id, '_uc_gofundme_url', esc_url_raw( wp_unslash( $_POST['gofundme_url'] ) ) );
        }
        /*
         * WHO GETS TOLD ON A NEW REGISTRATION. Both halves of it, guarded by
         * one marker, because they moved into the Notifications card together
         * and a form either carries that block or it does not. Without the
         * marker an unticked box would be indistinguishable from a form that
         * never asked, which is the same trap the notify list is guarded
         * against further down.
         */
        if ( isset( $_POST['uc_org_notify_present'] ) ) {
            update_post_meta( $event_id, '_uc_notify_organizer', isset( $_POST['notify_organizer'] ) ? '1' : '0' );
            if ( isset( $_POST['organizer_email'] ) ) {
                update_post_meta( $event_id, '_uc_organizer_email', sanitize_email( wp_unslash( $_POST['organizer_email'] ) ) );
            }
        } elseif ( isset( $_POST['organizer_email'] ) ) {
            // An older form that carried the address on its own.
            update_post_meta( $event_id, '_uc_organizer_email', sanitize_email( wp_unslash( $_POST['organizer_email'] ) ) );
        }

        // The RSVP settings: capacity, whether to accept them, who is notified
        // and where replies go. Shared with the registrations page, written
        // once. See save_rsvp_settings_from_post().
        $this->save_rsvp_settings_from_post( $user, $event_id, $is_locked );

        $toggles = array(
            // 'notify_organizer' is written above, with the address it belongs
            // to, under its own marker. It is not an unconditional toggle any
            // more because the block it lives in is not on every form.
            'show_rsvp'       => '_uc_show_rsvp',
            'show_donate'     => '_uc_show_donate',
            'show_social'     => '_uc_show_social',
            'show_calendar'   => '_uc_show_calendar',
            'show_reminders'  => '_uc_show_reminders',
        );
        foreach ( $toggles as $field => $key ) {
            update_post_meta( $event_id, $key, isset( $_POST[ $field ] ) ? '1' : '0' );
        }

        /*
         * THE MANAGER-OWNED FIELDS, SAVED FROM THE ONE PLACE THEY ARE READ.
         *
         * Featured image, category, organizer and the fundraising toggle were
         * written out longhand here and nowhere else, which was fine while the
         * event editor was the only form that could submit them. The pending
         * approval queue now posts the same controls, from the same render, so
         * the reading of them moved somewhere both callers can reach.
         */
        $this->save_manager_fields_from_post( $user, $event_id, $is_locked );

        /*
         * SERIES. A term assignment and nothing else.
         *
         * Moving an event INTO a series applies that series' default FAQ set,
         * and only when the event has no questions of its own. That is the one
         * moment inheritance-like behaviour helps, and the rows it puts there
         * are copies the manager can edit or clear — nothing stays linked.
         */
        if ( isset( $_POST['series'] ) ) {
            $series_was = SFAF_Series::id_for_event( $event_id );
            $series_now = intval( $_POST['series'] );
            SFAF_Series::set_for_event( $event_id, $series_now );
            if ( $series_now && $series_now !== $series_was ) {
                SFAF_FAQ_Sets::apply_series_default( $event_id, $series_now );
            }
        }

        // FAQ. One block, one key, on the event.
        $clean_faq = function ( $raw ) {
            $faqs = array();
            foreach ( (array) wp_unslash( $raw ) as $row ) {
                $q = isset( $row['question'] ) ? sanitize_text_field( $row['question'] ) : '';
                $a = isset( $row['answer'] ) ? sanitize_textarea_field( $row['answer'] ) : '';
                if ( $q === '' && $a === '' ) { continue; }
                $faqs[] = array( 'question' => $q, 'answer' => $a );
            }
            return $faqs;
        };
        /*
         * IMPORTED FAQ ROWS ARE NEVER READ FROM THE BROWSER.
         *
         * When a platform owns the FAQ block, its rows render disabled and
         * without a `name`, so nothing about them is submitted. They are read
         * back from the database here and put in front of whatever the form
         * did submit, which is by definition only the manual rows.
         *
         * That ordering is the same one sync_faqs() writes — imported first,
         * manual after — so a save does not reshuffle the block, and a POST
         * that has been tampered with cannot rewrite, reorder or delete a row
         * that belongs to the source. $clean_faq keeps only question and
         * answer, so it cannot smuggle in a source_faq_id either.
         */
        $faq_locked = $is_locked( 'faqs' );
        $merge_faq  = function ( $meta_key, $posted ) use ( $event_id, $faq_locked, $clean_faq ) {
            $rows = $clean_faq( $posted );
            if ( ! $faq_locked ) {
                return $rows;
            }
            $keep = array();
            foreach ( sfaf_normalize_faqs( get_post_meta( $event_id, $meta_key, true ) ) as $row ) {
                if ( sfaf_faq_is_imported( $row ) ) {
                    $keep[] = $row;
                }
            }
            return array_merge( $keep, $rows );
        };

        // A repeater with no rows submits nothing at all, which is
        // indistinguishable from "the block was not on this form" — so the
        // locked FAQ block ships a marker field. With it present, an absent
        // row array means "the manual rows were all deleted" rather than
        // "leave the meta alone".
        $posted_faq = function ( $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                return $_POST[ $field ];
            }
            return isset( $_POST['uc_faq_has_manual'] ) ? array() : null;
        };

        $rows = $posted_faq( 'uc_faqs' );
        if ( null !== $rows ) {
            update_post_meta( $event_id, sfaf_faq_meta_key(), $merge_faq( sfaf_faq_meta_key(), $rows ) );
        }

        /*
         * GENERATE, ONCE.
         *
         * A new event that asked to repeat produces its other dates here, as
         * separate complete events sharing a recurrence group. generate()
         * refuses a seed that already carries a group, so a back-button
         * resubmit cannot double them, and nothing ever runs this again.
         */
        $generated = 0;
        list( $pattern, $until, $limit ) = $this->recurrence_from_post();
        if ( ! $is_imported && '' !== $pattern && ( '' !== $until || $limit > 0 ) ) {
            /*
             * THE SERIES IS MADE HERE, FROM THE EVENT, BEFORE THE DATES ARE.
             *
             * An event with several dates is a series by definition, and in this
             * organisation's programming it is the ONLY thing in that series and
             * carries the same name. Making the manager go to another screen and
             * type that name a second time was asking one question twice.
             *
             * Ordering matters and is not incidental: generate() copies the
             * uc_series term from the seed onto every occurrence it makes, so
             * the series has to exist and be assigned first. Done afterwards it
             * would land on the first date only.
             *
             * An event already in a series keeps it. Somebody who deliberately
             * put this into an existing programme is not overruled.
             */
            if ( ! SFAF_Series::id_for_event( $event_id ) ) {
                SFAF_Series::create_for_event( $event_id );
            }

            $made      = SFAF_Recurrence::generate( $event_id, $pattern, $until, $limit );
            $generated = count( $made['created'] );
        }

        /*
         * HOW FAR THIS SAVE TRAVELS.
         *
         * THE TARGET IS THE RECURRENCE GROUP, NOT THE SERIES, and only its
         * upcoming members. Re-derived here from the group rather than taken
         * from the form: the two buttons are an affordance, and a POST claiming
         * "all upcoming" for an event with no group, or reaching for a past
         * date, gets exactly the same treatment as one that never asked.
         */
        $scope   = ( isset( $_POST['edit_scope'] ) && 'all_upcoming' === sanitize_key( $_POST['edit_scope'] ) )
            ? 'all_upcoming'
            : 'this';
        $written = 1;

        if ( 'all_upcoming' === $scope && SFAF_Recurrence::has_bulk_scope( $event_id ) ) {
            $targets = SFAF_Recurrence::bulk_targets( $event_id );
            $written = $this->apply_to_group( $event_id, $targets );
        } else {
            $scope = 'this';
        }

        if ( $generated ) {
            $msg = 'generated_' . $generated;
        } elseif ( 'all_upcoming' === $scope ) {
            $msg = 'bulk_' . $written;
        } else {
            $msg = 'saved';
        }

        return array( 'id' => $event_id, 'msg' => $msg, 'written' => $written );
    }

    /**
     * Copy one event's details onto the other upcoming events in its
     * recurrence group.
     *
     * READ BACK FROM THE SAVED EVENT, NOT FROM $_POST. The event has already
     * been saved by the time this runs, so this copies what was actually
     * stored — which means every sanitizer, every locked-field refusal and
     * every imported-row merge above has already been applied, and there is no
     * second, subtly different parse of the same form to drift out of step.
     *
     * WHAT IS NEVER COPIED:
     *
     *   THE DATE. It is the only thing that makes the occurrences distinct, and
     *   one date written across twelve of them would collapse the group onto a
     *   single day.
     *
     *   CAPACITY, WHERE RSVPS EXIST. Places are held against one date, so a
     *   single capacity written across the set can land under the confirmed
     *   count on a date nobody is looking at. bulk_locked_fields() decides, and
     *   the editor hides the pencil on exactly what it names.
     *
     *   ANYTHING A TARGET'S OWN SOURCE OWNS. A generated event has no source,
     *   so this should never trigger — but if an imported event ever ends up in
     *   a group, a bulk edit must not write a field the next fetch will
     *   overwrite anyway.
     *
     *   THE SLUG. Permalinks are live URLs and are not a detail of an edit.
     *
     * @param int   $source_id
     * @param int[] $targets   Including $source_id.
     * @return int Number of events written, the source included.
     */
    private function apply_to_group( $source_id, $targets ) {
        $source_id = (int) $source_id;
        $source    = get_post( $source_id );
        if ( ! $source ) {
            return 1;
        }

        $locked = SFAF_Recurrence::bulk_locked_fields( $targets );

        $meta_keys = array(
            '_uc_start_time', '_uc_end_time', '_uc_location',
            '_uc_rsvp_enabled', '_uc_gofundme_url', '_uc_gofundme_goal',
            '_uc_pardot_campaigns', '_uc_organizer_email', '_uc_notify_organizer',
            '_uc_email_subject', '_uc_email_body', '_uc_email_replyto',
            '_uc_show_rsvp', '_uc_show_donate', '_uc_show_social', '_uc_show_calendar', '_uc_show_reminders',
            '_uc_image_url', '_uc_image_override',
            // Whether the fundraising figures are published. It travels with
            // the donate URL and goal it governs: a group that shares a
            // campaign should not show its progress on one date and not the
            // next, which is exactly what leaving this out would produce.
            sfaf_fundraising_progress_meta_key(),
            sfaf_faq_meta_key(),
        );
        if ( ! isset( $locked['capacity'] ) ) {
            $meta_keys[] = '_uc_capacity';
        }

        $taxonomies = array( 'uc_event_category', 'uc_organizer', 'uc_venue', SFAF_Series::TAXONOMY );
        $thumb      = get_post_thumbnail_id( $source_id );
        $written    = 1;

        foreach ( $targets as $target_id ) {
            $target_id = (int) $target_id;
            if ( $target_id === $source_id ) {
                continue;
            }
            $target = get_post( $target_id );
            if ( ! $target || 'uc_event' !== $target->post_type ) {
                continue;
            }

            $target_source = (string) get_post_meta( $target_id, SFAF_Sources::META_SOURCE, true );
            $target_owned  = ( '' !== $target_source ) ? SFAF_Sources::owned_fields_for( $target_source ) : array();

            $postarr = array( 'ID' => $target_id );
            if ( ! in_array( 'title', $target_owned, true ) ) {
                $postarr['post_title'] = $source->post_title;
            }
            if ( ! in_array( 'description', $target_owned, true ) ) {
                $postarr['post_content'] = $source->post_content;
                $postarr['post_excerpt'] = $source->post_excerpt;
            }
            $postarr['post_status'] = $source->post_status;
            wp_update_post( $postarr );

            foreach ( $meta_keys as $key ) {
                if ( in_array( 'faqs', $target_owned, true ) && sfaf_faq_meta_key() === $key ) {
                    continue;
                }
                $value = get_post_meta( $source_id, $key, true );
                if ( '' === $value || array() === $value ) {
                    delete_post_meta( $target_id, $key );
                } else {
                    update_post_meta( $target_id, $key, $value );
                }
            }

            foreach ( $taxonomies as $tax ) {
                $terms = wp_get_object_terms( $source_id, $tax, array( 'fields' => 'ids' ) );
                if ( ! is_wp_error( $terms ) ) {
                    wp_set_object_terms( $target_id, $terms, $tax );
                }
            }

            if ( $thumb ) {
                set_post_thumbnail( $target_id, $thumb );
            } else {
                delete_post_thumbnail( $target_id );
            }

            $written++;
        }

        return $written;
    }

    /* =====================================================================
     * Duplicate as template
     * ================================================================== */

    /**
     * Create a new draft event from an existing one.
     *
     * THE COPY IS AN ALLOW-LIST, AND THAT IS THE WHOLE SAFETY ARGUMENT.
     *
     * The obvious way to write this is to copy every meta row and then delete
     * the ones that must not travel. That works exactly until somebody adds a
     * new source-owned key, at which point every duplicate made afterwards
     * quietly carries it and looks imported to everything that reads the meta.
     * A deny-list has to be remembered; an allow-list cannot be forgotten.
     * So nothing is copied unless it is named below, and the source identity
     * is not absent because it was removed, it is absent because it was never
     * asked for.
     *
     * WHAT COMES ACROSS. Title, description and excerpt; the featured image
     * and the image URL; location; start and end times; capacity and the RSVP
     * switch; the fundraising URL, goal and figures toggle; the Pardot
     * campaigns; the organizer address and the new-RSVP notification; the
     * confirmation email overrides and the reply-to; all five display
     * toggles; category, organizer, venue and series; the FAQ rows; and the
     * per-event notification list.
     *
     * WHAT DOES NOT, AND WHY EACH ONE:
     *
     *   THE DATE. Deliberately empty. Setting a date is the reason a manager
     *   is here, and a duplicate that arrived carrying last year's date would
     *   be a past event pretending to be a new one.
     *
     *   REGISTRATIONS AND SEND HISTORY. Rows in uc_rsvps and uc_reminder_log
     *   belong to the event people actually registered for. They are keyed by
     *   event_id and this function never writes to either table, so the new
     *   event starts with no attendees and no record of having mailed anyone.
     *   _uc_reminder_sent_at is left off for the same reason: carried over, it
     *   would tell the reminder run that this event had already been done and
     *   the morning-of mail would never go out.
     *
     *   THE RECURRENCE GROUP AND PATTERN. A duplicate is a new independent
     *   event. Carrying the group would put it inside the original's bulk-edit
     *   scope, so an "edit all upcoming occurrences" on any member would reach
     *   into a copy nobody meant to include.
     *
     *   EVERY TRACE OF AN IMPORT. A duplicate of a GoFundMe Pro or Eventbrite
     *   event is a NATIVE event. No external source, id, URL, image, timezone,
     *   import timestamp, update timestamp or removal record, which is what
     *   makes SFAF_Sources::provenance() return nothing for it, which in turn
     *   is what makes owned_fields_for() lock nothing and the editor render
     *   every field as editable. Nothing has to be told to unlock: there is no
     *   adapter to ask.
     *
     *   THE source_faq_id ON EVERY COPIED FAQ ROW. Stripped explicitly, at
     *   copy time. sfaf_normalize_faqs() preserves the id on read, so the rows
     *   arrive here still carrying the original's platform record ids, and
     *   they would keep them forever if this did not take them off. They are
     *   meaningless on an event with no source, and worse than meaningless:
     *   sync_faqs() splits rows on exactly that key, so a later import that
     *   ever touched this event would treat hand-written rows as the
     *   platform's to rewrite and remove.
     *
     * NOTHING IS DELETED BY ANY OF THIS. The original is not read-modified,
     * not restatused and not touched. This function only ever inserts.
     *
     * @param WP_User $user
     * @param int     $source_id
     * @return int|WP_Error New draft's ID.
     */
    private function duplicate_event( $user, $source_id ) {
        $source_id = (int) $source_id;
        $source    = get_post( $source_id );

        if ( ! $source || 'uc_event' !== $source->post_type ) {
            return new WP_Error( 'sfaf_dup_not_event', 'That is not an event.' );
        }
        if ( ! $this->can_edit_event( $user, $source ) ) {
            return new WP_Error( 'sfaf_dup_denied', 'You do not have permission to copy that event.' );
        }

        /*
         * A DRAFT, AND SAYING SO IN THE TITLE.
         *
         * Both halves matter. Draft keeps it off the calendar until somebody
         * has set a date and looked at it, and the title keeps it from
         * shadowing the original in a list where two identical names would be
         * a coin toss. Authored by whoever pressed the button rather than by
         * the original's author, because it is their event now and a
         * contributor has to be able to see and edit what they just made.
         */
        $new_id = wp_insert_post( array(
            'post_type'    => 'uc_event',
            'post_status'  => 'draft',
            'post_title'   => $source->post_title . ' (copy)',
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_author'  => $user->ID,
        ), true );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }
        $new_id = (int) $new_id;

        // Everything that travels. Nothing outside this list is read.
        $copy_keys = array(
            '_uc_start_time', '_uc_end_time', '_uc_location',
            '_uc_capacity', '_uc_rsvp_enabled',
            '_uc_gofundme_url', '_uc_gofundme_goal', sfaf_fundraising_progress_meta_key(),
            '_uc_pardot_campaigns',
            '_uc_organizer_email', '_uc_notify_organizer',
            '_uc_email_subject', '_uc_email_body', '_uc_email_replyto',
            '_uc_show_rsvp', '_uc_show_donate', '_uc_show_social', '_uc_show_calendar', '_uc_show_reminders',
            '_uc_image_url', '_uc_image_override',
            SFAF_Reminders::NOTIFY_USERS_META,
            SFAF_Reminders::NOTIFY_EMAILS_META,
            SFAF_Reminders::NOTIFY_AUTHOR_OPTOUT_META,
            // The team REFERENCES, which is all a team ever is on an event.
            // Copying them copies "tell Philanthropy about this", not the six
            // people who happen to be in Philanthropy today.
            SFAF_Teams::EVENT_META,
        );

        foreach ( $copy_keys as $key ) {
            $value = get_post_meta( $source_id, $key, true );
            if ( '' === $value || array() === $value || null === $value ) {
                continue;
            }
            update_post_meta( $new_id, $key, $value );
        }

        /*
         * THE FAQ ROWS, WITH THE PLATFORM IDS TAKEN OFF.
         *
         * Rebuilt field by field rather than copied and unset, so a row can
         * only ever contain a question and an answer whatever it arrived with.
         * That is the same shape SFAF_FAQ_Sets::clean_rows() produces and the
         * same shape the editor's repeater posts, so a duplicated row is
         * indistinguishable from a hand-typed one, which is exactly what it
         * now is.
         */
        $faqs = array();
        foreach ( sfaf_get_faqs( $source_id ) as $row ) {
            $q = isset( $row['question'] ) ? (string) $row['question'] : '';
            $a = isset( $row['answer'] ) ? (string) $row['answer'] : '';
            if ( '' === $q && '' === $a ) {
                continue;
            }
            $faqs[] = array( 'question' => $q, 'answer' => $a );
        }
        if ( ! empty( $faqs ) ) {
            update_post_meta( $new_id, sfaf_faq_meta_key(), $faqs );
        }

        // Category, organizer, venue and series. A series is a term, so it
        // copies like any other and does not make the duplicate a member of
        // anything that regenerates.
        foreach ( array( 'uc_event_category', 'uc_organizer', 'uc_venue', SFAF_Series::TAXONOMY ) as $tax ) {
            $terms = wp_get_object_terms( $source_id, $tax, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $new_id, $terms, $tax );
            }
        }

        // The featured image. The attachment is shared, not duplicated: two
        // events pointing at one library item is what the library is for, and
        // copying the file would leave a second copy to maintain.
        $thumb = get_post_thumbnail_id( $source_id );
        if ( $thumb ) {
            set_post_thumbnail( $new_id, $thumb );
        }

        return $new_id;
    }

    /* =====================================================================
     * Users page actions
     * ================================================================== */

    private function save_user_role() {
        $uid = intval( $_POST['user_id'] );
        if ( ! $uid || ! get_userdata( $uid ) ) {
            return;
        }
        update_user_meta( $uid, '_uc_calendar_role', self::storable_role( $uid, $_POST['role'] ?? 'contributor' ) );
        $approval = ( isset( $_POST['approval'] ) && $_POST['approval'] === 'auto' ) ? 'auto' : 'review';
        update_user_meta( $uid, '_uc_calendar_approval', $approval );

        if ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) {
            $cats = array_map( 'intval', wp_unslash( $_POST['categories'] ) );
            update_user_meta( $uid, '_uc_calendar_categories', array_values( array_filter( $cats ) ) );
        } else {
            update_user_meta( $uid, '_uc_calendar_categories', array() );
        }
    }

    /* =====================================================================
     * CSV export
     * ================================================================== */

    private function export_rsvps_csv() {
        if ( ! is_user_logged_in() || ! $this->can_view_all( wp_get_current_user() ) ) {
            wp_die( 'Denied' );
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'uc_portal_export' ) ) {
            wp_die( 'Security check failed.' );
        }

        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $orphans  = ! empty( $_GET['orphans'] );
        $rsvps    = SFAF_RSVP::get_all_rsvps( array(
            'event_id' => $event_id,
            'search'   => $search,
            'orphans'  => $orphans,
        ) );

        /*
         * THE FILENAME SAYS WHAT IS IN IT.
         *
         * A folder of files all called rsvps-2026-08-04.csv is how the wrong
         * one gets attached to an email. The event's slug goes in the name so
         * the file is identifiable without opening it, which also means
         * nobody has to open it to check.
         */
        $slug = 'all';
        if ( $event_id ) {
            $post = get_post( $event_id );
            $slug = ( $post && $post->post_name ) ? $post->post_name : 'event-' . $event_id;
        } elseif ( $orphans ) {
            $slug = 'deleted-events';
        }

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="rsvps-' . sanitize_file_name( $slug ) . '-' . current_time( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Event', 'Name', 'Email', 'Phone', 'Status', 'Date Registered' ) );
        foreach ( $rsvps as $r ) {
            $title = SFAF_RSVP::event_label( $r );
            fputcsv( $out, array(
                $this->csv( $title ), $this->csv( $r->name ), $this->csv( $r->email ),
                $this->csv( $r->phone ), $this->csv( $r->status ), $this->csv( $r->created_at ),
            ) );
        }
        fclose( $out );
        exit;
    }

    private function export_optins_csv() {
        if ( ! is_user_logged_in() || ! $this->can_view_all( wp_get_current_user() ) ) {
            wp_die( 'Denied' );
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'uc_portal_export' ) ) {
            wp_die( 'Security check failed.' );
        }

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $rows   = SFAF_Optins::all( array( 'search' => $search, 'limit' => 10000 ) );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="email-optins-' . current_time( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        // The two evidence columns are deliberately next to the address: an
        // export that loses "when" and "from which form" is not evidence of
        // consent, it is just a mailing list.
        fputcsv( $out, array( 'Email', 'Name', 'Consented At', 'Consented At (GMT)', 'Source Form', 'Event' ) );
        foreach ( $rows as $r ) {
            fputcsv( $out, array(
                $this->csv( $r->email ),
                $this->csv( $r->name ),
                $this->csv( $r->consented_at ),
                $this->csv( $r->created_gmt ),
                $this->csv( $r->source_form ),
                $this->csv( $r->event_title ),
            ) );
        }
        fclose( $out );
        exit;
    }

    private function csv( $value ) {
        $value = (string) $value;
        if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            $value = "'" . $value;
        }
        return $value;
    }

    /* =====================================================================
     * Rendering — chrome
     * ================================================================== */

    private function brand( $key, $default = '' ) {
        $settings = get_option( 'uc_settings', array() );
        return isset( $settings[ $key ] ) && $settings[ $key ] !== '' ? $settings[ $key ] : $default;
    }

    private function head( $title ) {
        $primary = sanitize_hex_color( $this->brand( 'brand_primary_color', '#FFD900' ) ) ?: '#FFD900';
        $accent  = sanitize_hex_color( $this->brand( 'brand_accent_color', '#16BECF' ) ) ?: '#16BECF';
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex,nofollow" />
    <title><?php echo esc_html( $title ); ?> - SFAF Calendar</title>
    <?php
    /*
     * MONTSERRAT, THE BRAND'S WEB HEADLINE FACE (guide v3.0, p.10).
     *
     * preconnect first, because the font file is on a second host and the
     * connection cost is paid before the CSS that asks for it has even been
     * parsed. display=swap so text is readable in the fallback immediately
     * rather than invisible while the file arrives: a portal that flashes
     * blank headings on every page load is worse than one in Segoe UI for
     * 200ms.
     *
     * The fallback stack in --uc-font-heading is a real stack, not a token
     * ending in sans-serif, so a machine with no network still gets the
     * platform's own UI face at the same weights.
     */
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&amp;display=swap" />
    <link rel="stylesheet" href="<?php echo esc_url( SFAF_PLUGIN_URL . 'public/css/portal.css?ver=' . SFAF_VERSION ); ?>" />
    <style>:root{--uc-primary:<?php echo esc_html( $primary ); ?>;--uc-accent:<?php echo esc_html( $accent ); ?>;}</style>
    <?php
    /*
     * THE ENTRANCE SWITCH, IN THE HEAD, BEFORE THE FIRST PAINT.
     *
     * WHY IT IS NOT IN portal.js. It was, in 3.16.0, at the bottom of a chain
     * of nineteen initialisers running on DOMContentLoaded, and that is two
     * separate faults. One: an exception in any earlier initialiser silently
     * takes out every one after it, and the entrance was last. Two, and worse:
     * DOMContentLoaded fires AFTER first paint, so the page drew itself
     * complete and only then had the elements set back to opacity 0 to fade in
     * from. What that produces is not an entrance, it is a flicker, and on a
     * fast machine it is over before it registers as anything at all.
     *
     * Here, in the head, this runs before <body> exists. The class lands on
     * <html> before anything is painted, and the animation is then pure CSS
     * with no further script involved: nothing in portal.js can break it.
     *
     * data-uc-motion IS DELIBERATE AND IS FOR READING. "The animations are not
     * appearing" and "this machine asks for reduced motion" look identical from
     * outside, and guessing between them is what cost this a release. The
     * attribute says which branch shipped: inspect <html> and it reads either
     * data-uc-motion="on" or data-uc-motion="reduced".
     *
     * WITH SCRIPTING OFF neither class is set, nothing is hidden, and the page
     * is a normal finished page. That guarantee is why the hidden state lives
     * in the animation rather than in a base rule.
     */
    ?>
    <script>
    (function (d) {
        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        d.setAttribute('data-uc-motion', reduced ? 'reduced' : 'on');
        if (reduced) { return; }
        d.className += ' uc-anim';
        // The nav stagger is once per session, not once per navigation: every
        // screen here is a full page load. A storage failure means it simply
        // runs again, which is the harmless outcome.
        try {
            if (window.sessionStorage.getItem('ucNavIntro') !== '1') {
                window.sessionStorage.setItem('ucNavIntro', '1');
                d.className += ' uc-anim-nav';
            }
        } catch (e) {
            d.className += ' uc-anim-nav';
        }
    })(document.documentElement);
    </script>
    <?php
    if ( $this->load_media ) {
        wp_print_styles();
        wp_print_head_scripts();
    }
    ?>
</head>
<body class="uc-portal"><?php
    }

    private function foot() {
        if ( $this->load_media ) {
            // The portal builds its own document, so print the enqueued media
            // scripts + Backbone templates manually to power wp.media here.
            wp_print_footer_scripts();
            wp_print_media_templates();
        }
        ?><script src="<?php echo esc_url( SFAF_PLUGIN_URL . 'public/js/sfaf-email.js?ver=' . SFAF_VERSION ); ?>"></script>
<script src="<?php echo esc_url( SFAF_PLUGIN_URL . 'public/js/portal.js?ver=' . SFAF_VERSION ); ?>"></script>
</body></html><?php
    }

    private function chrome_open( $user, $active ) {
        $this->head( ucfirst( $active ) );
        $role     = self::get_role( $user->ID );
        $logo     = $this->brand( 'brand_logo' );
        $is_admin = $this->is_admin_role( $user );

        // Third value is an SFAF icon name (see sfaf_icon()).
        $nav = array(
            'dashboard' => array( 'Dashboard', '', 'home' ),
            'events'    => array( 'Events', 'events', 'calendar' ),
            'series'    => array( 'Series & Categories', 'series', 'repeat' ),
            'faq-sets'  => array( 'FAQ Sets', 'faq-sets', 'help' ),
        );
        if ( $this->can_view_all( $user ) ) {
            // Venues moved here from the WordPress admin in 3.9.0. Deciding
            // where events are held is event management, and the address they
            // carry is what the Location field on every event now points at.
            $nav['venues'] = array( 'Venues', 'venues', 'venue' );

            /*
             * NO RSVPs ENTRY. Retired in 3.5.0.
             *
             * It led to every registration ever taken, across every event, in
             * one flat table. That is not a question anybody has: the question
             * is who is coming to a particular event, and that is now the RSVP
             * count on the Events list, which links straight to it.
             *
             * The screen itself is still there and still reachable at /rsvps,
             * because registrations outlive their events on purpose and rows
             * whose event has been deleted have nothing to be reached through.
             * The Events list links to those whenever any exist. See
             * render_rsvps().
             */
            $nav['optins'] = array( 'Email Opt-ins', 'optins', 'mail' );
        }
        if ( $is_admin ) {
            // Automation moved out of this portal in 2.13.0. The run log, cron
            // health and "Run now" are server administration, not event
            // management, and they are gated on manage_options rather than on
            // a calendar role. They live under Events > Automation in the
            // WordPress admin now.
            $nav['pending'] = array( 'Pending', 'pending', 'clock' );
            $nav['users']   = array( 'Users', 'users', 'users' );
        }

        /*
         * THE ONE COUNT THAT EARNS A BADGE.
         *
         * Yellow appears once on this sidebar, on Pending, for the same reason
         * it appears once on a form: it means "this is the thing to act on".
         * Everything else in the nav is a place to go. Both queues are counted,
         * because a manager reading "4" has to be able to trust that it is
         * everything waiting, not just the locally submitted half.
         */
        $pending_count = 0;
        if ( isset( $nav['pending'] ) ) {
            $pending_count  = count( $this->query_events( $user, array( 'status' => 'pending', 'per_page' => 100 ) ) );
            $pending_count += count( SFAF_Sources::queue_ids( SFAF_Sources::STATUS_PENDING ) );
        }
        ?>
        <div class="uc-portal-layout">
            <aside class="uc-portal-sidebar" id="uc-sidebar">
                <div class="uc-portal-brand">
                    <?php if ( $logo ) : ?>
                        <img src="<?php echo esc_url( $logo ); ?>" alt="" class="uc-portal-logo" />
                    <?php else : ?>
                        <span class="uc-portal-brandmark">SFAF</span>
                    <?php endif; ?>
                    <span class="uc-portal-brandtext">Calendar Admin</span>
                </div>
                <nav class="uc-portal-nav" aria-label="Calendar admin">
                    <?php $i = 0; foreach ( $nav as $key => $item ) : ?>
                        <?php
                        // The stagger's step, set per item rather than by an
                        // :nth-child chain, so adding a nav entry needs no CSS.
                        // portal.js is what decides whether it ever runs.
                        $i++;
                        ?>
                        <a href="<?php echo esc_url( $this->url( $item[1] ) ); ?>"
                           class="uc-nav-item<?php echo $active === $key ? ' active' : ''; ?>"
                           style="--uc-nav-i: <?php echo (int) $i; ?>"
                           <?php echo $active === $key ? ' aria-current="page"' : ''; ?>>
                            <span class="uc-nav-icon"><?php echo sfaf_icon( $item[2], array( 'size' => '20px' ) ); ?></span>
                            <span class="uc-nav-label"><?php echo esc_html( $item[0] ); ?></span>
                            <?php if ( 'pending' === $key && $pending_count > 0 ) : ?>
                                <span class="uc-nav-badge"><?php echo (int) $pending_count; ?><span class="uc-visually-hidden"> waiting for review</span></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <?php
                /*
                 * THE FOOT OF THE SIDEBAR: WHO IS SIGNED IN, AND IN WHICH
                 * LANGUAGE.
                 *
                 * The name and the access level were split across two places
                 * that answered the same question, a bare role chip down here
                 * and a name in the top bar, so neither said "you are Ana, and
                 * you are a Manager". They are one block now.
                 *
                 * NO LANGUAGE CONTROL HERE. 3.16.0 put one in and 3.17.0 took
                 * it out; see the note at the removed set_language case in
                 * dispatch_post(). The English/Espanol control that prompted it
                 * is Weglot's, and portal.css hides it on these screens.
                 */
                ?>
                <div class="uc-portal-foot">
                    <div class="uc-portal-me">
                        <span class="uc-portal-me-name"><?php echo esc_html( $user->display_name ); ?></span>
                        <span class="uc-portal-me-role"><?php echo esc_html( self::role_label( $role ) ); ?></span>
                        <a class="uc-portal-signout" href="<?php echo esc_url( wp_logout_url( $this->url() ) ); ?>">Sign out</a>
                    </div>
                </div>
            </aside>

            <div class="uc-portal-main">
                <header class="uc-portal-topbar">
                    <button class="uc-portal-menu-btn" id="uc-menu-btn" aria-label="Menu"><?php echo sfaf_icon( 'menu', array( 'size' => '22px' ) ); ?></button>
                    <?php
                    // The name and the access level moved to the sidebar foot,
                    // where they are one block instead of two halves of an
                    // answer. Log out stays here as well as there, because
                    // below 720px the sidebar is an off-canvas drawer and the
                    // one in it would be behind a menu button.
                    ?>
                    <div class="uc-portal-user">
                        <a class="uc-portal-logout" href="<?php echo esc_url( wp_logout_url( $this->url() ) ); ?>">Log out</a>
                    </div>
                </header>
                <main class="uc-portal-content">
        <?php
        $this->flash();
    }

    private function chrome_close() {
        ?>
                </main>
            </div>
        </div>
        <?php
        $this->foot();
    }

    private function flash() {
        if ( empty( $_GET['msg'] ) ) {
            return;
        }
        $map = array(
            'saved'          => 'Event saved.',
            'trashed'        => 'Event removed.',
            'duplicated'     => 'Copied. This is a new draft with no date and no registrations, and the event it came from is unchanged. Set the date, check the details, then publish. If the original was imported, this copy is not: nothing here is tied to the platform and every field is yours to edit.',
            'duplicate_failed' => 'That event could not be copied.',
            'approved'       => 'Event approved and published.',
            'rejected'       => 'Event rejected.',
            'user_saved'     => 'User permissions updated.',
            'user_added'     => 'User added to the calendar system.',
            'user_removed'   => 'User removed from the calendar system, and taken out of any teams they were in. No event needed changing, because no event stored them.',
            'team_saved'     => 'Team saved. Events that name it will notify whoever is in it at the moment the reminder goes out.',
            'team_deleted'   => 'Team deleted. No event named it, so no notification changed.',
            'series_saved'   => 'Series saved. Nothing about the events in it changed: a series groups them, it does not overwrite them.',
            'category_saved' => 'Category saved. Its color and icon are what a card and its placeholder are drawn from, so events in it change appearance straight away.',
            'category_failed'=> 'That category could not be saved. Give it a name and try again.',
            'series_failed'  => 'That series could not be saved. Give it a name and try again.',
            // 'series_removed' is built from real counts further down, because
            // what it did depends on which option was chosen.
            'fetched'        => 'Fetch complete. See the results below.',
            'import_dismissed' => 'Event dismissed. It stays in the Dismissed list and will not be fetched again.',
            'import_restored'  => 'Event restored to Pending.',
            'import_review'    => 'Assign a category, organizer and series, then press Publish to put this event on the calendar.',
            'refreshed'        => 'Refreshed from the source. See below for what changed.',
            'faq_set_applied'  => 'FAQ set applied.',
            'faq_set_saved'    => 'FAQ set saved.',
            'faq_set_deleted'  => 'FAQ set deleted. Events that already used it keep their questions, because the rows were copied.',
            'manager_saved'    => 'Saved. Those are the same fields the event editor shows, so the event now reads the same in both places.',
            'rsvp_settings_saved' => 'Registration settings saved. These are the same controls the event editor shows, on the same event, so it now reads the same in both places.',

            // The schedule.
            'schedule_added'    => 'Date added. It is an ordinary event, identical to the others, with nobody registered yet.',
            'schedule_removed'  => 'Date removed. Nothing regenerates it, so it stays removed. Any registrations against it are kept.',
            'schedule_unchanged'=> 'Nothing to change: the schedule already says that.',
            'schedule_no_group' => 'These dates are not on a repeating pattern, so there is no pattern to change. Each date can still be edited on its own.',
            'schedule_nothing_upcoming' => 'There are no upcoming dates to change. Dates that have already been are the record of what happened and are never rewritten.',
            'schedule_imported' => 'This event comes from another platform, which decides when it happens. Changing the schedule here would be undone by the next fetch.',
            'schedule_no_seed'  => 'There is no existing date to copy, so there is nothing to base a new one on. Create the first event and choose this series on it.',
            'schedule_add_failed'    => 'That date could not be added.',
            'schedule_remove_failed' => 'That date is not part of this series, so nothing was removed.',
            'schedule_past'     => 'That date has already been. Past occurrences are the record of what happened and are never changed from here.',

            // Venues.
            'venue_saved'   => 'Venue saved. Every event held there now shows this address, including ones already published: they point at the venue rather than keeping a copy.',
            'venue_deleted' => 'Venue deleted. No event was held there, so nothing lost its location.',
        );
        $key = sanitize_key( $_GET['msg'] );

        /*
         * The pattern edit reports its count for the same reason a bulk save
         * does: the number in the confirmation and the number in the answer have
         * to be the same number, or nobody knows how much moved.
         */
        /*
         * REMOVING A SERIES ANSWERS WITH WHAT IT DID, not with a sentence
         * about what it usually does. The three numbers are what
         * SFAF_Series::remove() actually counted, so what the screen asked and
         * what the screen reports are the same arithmetic.
         */
        /* Deleting a category reports what it actually did to the events. */
        if ( 'category_deleted' === $key ) {
            $n = isset( $_GET['freed'] ) ? max( 0, intval( $_GET['freed'] ) ) : 0;
            echo '<div class="uc-flash">' . esc_html( sprintf(
                'Category deleted. %d %s on the calendar, uncategorized, and %s drawn in the default color until given another category.',
                $n,
                _n( 'event stays', 'events stay', $n ),
                _n( 'is', 'are', $n )
            ) ) . '</div>';
            return;
        }

        if ( 'series_removed' === $key ) {
            $trashed  = isset( $_GET['trashed'] ) ? max( 0, intval( $_GET['trashed'] ) ) : 0;
            $detached = isset( $_GET['detached'] ) ? max( 0, intval( $_GET['detached'] ) ) : 0;
            $moved    = isset( $_GET['moved'] ) ? max( 0, intval( $_GET['moved'] ) ) : 0;

            $parts = array( 'Series removed.' );
            if ( $trashed ) {
                $parts[] = sprintf( '%d upcoming %s deleted.', $trashed, _n( 'event was', 'events were', $trashed ) );
            }
            if ( $detached ) {
                $parts[] = sprintf(
                    '%d %s on the calendar, on the same date and at the same address, no longer grouped.',
                    $detached,
                    _n( 'event stays', 'events stay', $detached )
                );
            }
            if ( $moved ) {
                $parts[] = sprintf( '%d %s moved to another series.', $moved, _n( 'event was', 'events were', $moved ) );
            }
            $parts[] = 'Registration records were kept.';
            echo '<div class="uc-flash">' . esc_html( implode( ' ', $parts ) ) . '</div>';
            return;
        }

        if ( 'schedule_updated' === $key ) {
            $n = isset( $_GET['written'] ) ? max( 0, intval( $_GET['written'] ) ) : 0;
            echo '<div class="uc-flash">' . esc_html( sprintf(
                'Schedule updated. %d upcoming %s changed. Past dates were not touched.',
                $n,
                _n( 'occurrence was', 'occurrences were', $n )
            ) ) . '</div>';
            return;
        }

        $sched_error = get_transient( 'sfaf_schedule_error_' . get_current_user_id() );
        if ( $sched_error && in_array( $key, array( 'schedule_add_failed', 'schedule_remove_failed' ), true ) ) {
            delete_transient( 'sfaf_schedule_error_' . get_current_user_id() );
            echo '<div class="uc-flash uc-flash-error">' . esc_html( $sched_error ) . '</div>';
            return;
        }

        /*
         * Two messages carry a count, so they are built rather than looked up.
         *
         *   bulk_N       "Saved. 12 upcoming occurrences updated."
         *   generated_N  "Created, plus 11 more dates."
         *
         * The count is the point of both: a manager who has just changed a
         * term's worth of programming should be told how much of it moved, in
         * the same number the confirmation dialog asked about.
         */
        if ( 0 === strpos( $key, 'bulk_' ) ) {
            $n = (int) substr( $key, 5 );
            echo '<div class="uc-flash">' . esc_html( sprintf(
                'Saved. %d upcoming %s updated. Past occurrences were not touched.',
                $n,
                _n( 'occurrence was', 'occurrences were', $n )
            ) ) . '</div>';
            return;
        }
        if ( 0 === strpos( $key, 'generated_' ) ) {
            $n = (int) substr( $key, 10 );
            echo '<div class="uc-flash">' . esc_html( sprintf(
                'Saved, and %d further %s created. Each one is a separate event you can edit or delete on its own — nothing regenerates them.',
                $n,
                _n( 'date was', 'dates were', $n )
            ) ) . '</div>';
            return;
        }

        if ( isset( $map[ $key ] ) ) {
            echo '<div class="uc-flash">' . esc_html( $map[ $key ] ) . '</div>';
        }
    }

    /* =====================================================================
     * Rendering — login & denied
     * ================================================================== */

    private function render_login() {
        status_header( 200 );
        $logo = $this->brand( 'brand_logo' );
        $this->head( 'Sign In' );
        ?>
        <div class="uc-login-wrap">
            <div class="uc-login-card">
                <div class="uc-login-brand">
                    <?php if ( $logo ) : ?>
                        <img src="<?php echo esc_url( $logo ); ?>" alt="" class="uc-login-logo" />
                    <?php else : ?>
                        <span class="uc-portal-brandmark">SFAF</span>
                    <?php endif; ?>
                </div>
                <h1>SFAF Calendar Admin</h1>
                <p class="uc-login-sub">Sign in to manage events</p>

                <?php if ( $this->login_error ) : ?>
                    <div class="uc-login-error"><?php echo esc_html( $this->login_error ); ?></div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url( $this->url() ); ?>" class="uc-login-form">
                    <input type="hidden" name="uc_action" value="login" />
                    <?php wp_nonce_field( 'uc_portal_login', 'uc_nonce' ); ?>
                    <label>Username or Email
                        <input type="text" name="log" autocomplete="username" required autofocus />
                    </label>
                    <label>Password
                        <input type="password" name="pwd" autocomplete="current-password" required />
                    </label>
                    <label class="uc-login-remember">
                        <input type="checkbox" name="rememberme" value="1" /> Remember me
                    </label>
                    <button type="submit" class="uc-btn uc-btn-primary uc-btn-block">Sign In</button>
                </form>
            </div>
        </div>
        <?php
        $this->foot();
    }

    private function render_denied( $user ) {
        status_header( 403 );
        $this->head( 'Access Denied' );
        ?>
        <div class="uc-login-wrap">
            <div class="uc-login-card">
                <h1>Access Denied</h1>
                <p class="uc-login-sub">Your account doesn't have access to the calendar portal. Contact an administrator to request a role.</p>
                <a class="uc-btn" href="<?php echo esc_url( wp_logout_url( $this->url() ) ); ?>">Log out</a>
            </div>
        </div>
        <?php
        $this->foot();
    }

    /* =====================================================================
     * Rendering — dashboard
     * ================================================================== */

    /**
     * THE DASHBOARD IS AN OVERVIEW AND NOTHING ELSE.
     *
     * It used to carry a "+ New Event" button, a "Fetch updates" button, the
     * fetch report, and a table of upcoming events with Edit and Remove on
     * every row. That put the single most destructive control in the plugin
     * (Remove, on a row that might be a series parent) on the first screen
     * anybody sees after logging in, next to a welcome message.
     *
     * Everything that changes something now lives on the screen that owns it:
     * single events and occurrences on Events, whole series on Series, imports
     * on Pending, the scheduled runner on Automation. What is left here is
     * what is going on, and a way through to the place that can act on it.
     */
    private function render_dashboard( $user ) {
        $this->chrome_open( $user, 'dashboard' );

        /*
         * EVERY NUMBER ON THIS SCREEN IS WHAT THIS PERSON CAN ACT ON.
         *
         * Two of them were not. The events counts have been scoped by author
         * since they were written; Total RSVPs counted the whole table for
         * everyone, and Recent activity read the whole table too. See
         * count_rsvps() and recent_activity() for what that meant and why it
         * matters on this calendar in particular.
         *
         * Pending Review and Needs attention are inside the is_admin gate
         * below and stay unscoped on purpose: reviewing other people's
         * submissions IS an administrator's job, so those totals are exactly
         * what they can act on.
         */
        /*
         * SCOPE CHANGES WHICH EVENTS ARE COUNTED. IT DOES NOT CHANGE WHO MAY
         * SEE REGISTRATION DATA, AND THAT DISTINCTION IS THE POINT.
         *
         * "All events" answers "what is on the calendar". It is not a way to
         * acquire access: the RSVP figure below stays scoped to this person's
         * own events under both scopes for anyone who is not an admin or an
         * editor, because a registration count is not one of the things the
         * public calendar shows. The toggle moves the events; it never moves
         * the gate.
         */
        $scope      = $this->scope_choice();
        $public     = $this->is_public_scope( $user, $scope );
        $own        = ( 'mine' === $scope );
        $author     = $own ? $user->ID : 0;
        $total      = $this->count_events( 'publish', $author );
        $upcoming   = $this->count_events( 'publish', $author, true );
        $rsvp_total = $this->count_rsvps( $user );
        $pending    = $this->count_events( 'pending', 0 );
        $is_admin   = $this->is_admin_role( $user );
        $imports    = $is_admin ? SFAF_Sources::queue_count( SFAF_Sources::STATUS_PENDING ) : 0;

        // Whether the RSVP figure covers everything or only this person's own,
        // which is not the same question as the scope above.
        $rsvps_are_own = ! $this->can_view_all( $user );
        ?>
        <div class="uc-page-head">
            <h1>Welcome, <?php echo esc_html( $user->first_name ?: $user->display_name ); ?></h1>
            <?php $this->render_scope_toggle( $scope, '' ); ?>
        </div>

        <div class="uc-stats">
            <?php
            /*
             * THE LABELS SAY WHOSE NUMBER IT IS. "Upcoming" and "Total RSVPs"
             * were the same words whether the figure covered the whole calendar
             * or one person's events, so a contributor reading 3 had no way to
             * know whether that was three of theirs or three altogether. A
             * scoped number under an unscoped label is still misleading, even
             * once the number itself is right.
             */
            ?>
            <div class="uc-stat"><span class="uc-stat-num"><?php echo (int) $total; ?></span><span class="uc-stat-label"><?php echo $own ? 'My events' : 'Total events'; ?></span></div>
            <div class="uc-stat"><span class="uc-stat-num"><?php echo (int) $upcoming; ?></span><span class="uc-stat-label"><?php echo $own ? 'My upcoming' : 'Upcoming'; ?></span></div>
            <div class="uc-stat"><span class="uc-stat-num"><?php echo (int) $rsvp_total; ?></span><span class="uc-stat-label"><?php echo $rsvps_are_own ? 'RSVPs to my events' : 'Total RSVPs'; ?></span></div>
            <?php if ( $is_admin ) : ?>
                <div class="uc-stat uc-stat-accent"><span class="uc-stat-num"><?php echo (int) $pending; ?></span><span class="uc-stat-label">Pending Review</span></div>
            <?php endif; ?>
        </div>

        <?php if ( $is_admin ) : ?>
            <?php
            // Things that want a person, said plainly and linked to the screen
            // that can deal with them. Silence here means nothing is waiting.
            /*
             * NOTHING ABOUT ORPHANS APPEARS HERE ANY MORE, because nothing can
             * be orphaned. An occurrence used to be able to outlive the series
             * post it pointed at; a series is a term now, and a term
             * relationship goes with the term.
             */
            /*
             * NOTHING ABOUT THE SCHEDULED RUNNER APPEARS HERE ANY MORE.
             *
             * Cron health, the run log and "Run now" moved to the WordPress
             * admin in 2.13.0. Leaving a health line here would have been worse
             * than useless: it is not something an event manager can act on,
             * there is no longer a screen in this portal to send them to, and a
             * warning with no available action just teaches people to ignore
             * warnings. An administrator sees it on the admin notice, on the
             * Automation screen, and now by email.
             */
            $needs = ( $imports || $pending );
            ?>
            <div class="uc-card">
                <div class="uc-card-head"><h2>Needs attention</h2></div>
                <?php if ( ! $needs ) : ?>
                    <p class="uc-empty">Nothing is waiting. Imports are clear.</p>
                <?php else : ?>
                    <ul class="uc-attention-list">
                        <?php if ( $imports ) : ?>
                            <li>
                                <strong><?php echo (int) $imports; ?></strong>
                                imported <?php echo esc_html( _n( 'event is', 'events are', $imports ) ); ?> waiting to be reviewed and published.
                                <a href="<?php echo esc_url( $this->url( 'pending' ) ); ?>">Review imports &rarr;</a>
                            </li>
                        <?php endif; ?>
                        <?php if ( $pending ) : ?>
                            <li>
                                <strong><?php echo (int) $pending; ?></strong>
                                <?php echo esc_html( _n( 'event was', 'events were', $pending ) ); ?> submitted for review.
                                <a href="<?php echo esc_url( $this->url( 'pending' ) ); ?>">Review submissions &rarr;</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="uc-card">
            <div class="uc-card-head">
                <h2><?php echo $own ? 'My next events' : 'Next events'; ?></h2>
                <a href="<?php echo esc_url( add_query_arg( 'scope', $scope, $this->url( 'events' ) ) ); ?>">Manage events &rarr;</a>
            </div>
            <?php
            $next = $this->query_events( $user, array( 'upcoming' => true, 'per_page' => 8, 'scope' => $scope ) );
            /*
             * The same branch as the Events list, on the same question, into
             * the same two renderers. upcoming_overview() carries an RSVP
             * column, so it is only ever reached when every row is one this
             * person may see registrations for.
             */
            if ( $public ) {
                $this->public_events_table( $next );
            } else {
                $this->upcoming_overview( $next, $user );
            }
            ?>
        </div>

        <div class="uc-card">
            <?php
            /*
             * RECENT ACTIVITY DOES NOT FOLLOW THE SCOPE, and that is deliberate.
             * It names registrants, so it stays on the 3.18.0 rule: everything
             * for an admin or an editor, own events only for anybody else,
             * under both scopes. Switching to All events is not a way to read
             * who registered for somebody else's group.
             */
            ?>
            <div class="uc-card-head"><h2><?php echo $rsvps_are_own ? 'Recent activity on my events' : 'Recent activity'; ?></h2></div>
            <?php $this->recent_activity( $user ); ?>
        </div>
        <?php
        $this->chrome_close();
    }

    /**
     * Read-only list of what is coming up. No Edit, no Remove.
     *
     * A separate renderer from events_table() on purpose. Sharing one would
     * mean a flag deciding whether the destructive controls appear, and a flag
     * like that is one refactor away from defaulting the wrong way.
     */
    private function upcoming_overview( $ids, $user = null ) {
        if ( empty( $ids ) ) {
            echo '<p class="uc-empty">Nothing coming up.</p>';
            return;
        }
        _prime_post_caches( $ids, true, true );
        sfaf_prime_rsvp_counts( $ids );
        ?>
        <table class="uc-table">
            <thead><tr><th>Event</th><th>Date</th><th>RSVPs</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ( $ids as $id ) :
                $date = get_post_meta( $id, '_uc_event_date', true );
                $st   = get_post_status( $id ); ?>
                <tr>
                    <td><a class="uc-tlink" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></a></td>
                    <td><?php echo $date ? esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ) : '<span class="uc-muted">None</span>'; ?></td>
                    <td><?php
                        /*
                         * THE SAME LINK THE EVENTS LIST HAS. It was added there
                         * in 3.5.0 and not here, so the dashboard showed a
                         * number that answered "how many" and refused to answer
                         * "who" while the identical number two screens away did
                         * both. LINKED EVEN AT ZERO, exactly as in
                         * events_table(): see the note there.
                         */
                        $rsvp_n = (int) sfaf_get_rsvp_count( $id );
                        if ( $user && $this->can_view_all( $user ) ) {
                            echo '<a class="uc-tlink" href="'
                                . esc_url( add_query_arg( 'event_id', $id, $this->url( 'rsvps' ) ) ) . '">'
                                . (int) $rsvp_n . '</a>';
                        } else {
                            echo (int) $rsvp_n;
                        }
                    ?></td>
                    <td><span class="uc-pill uc-pill-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( sfaf_status_label( $st ) ); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * What has happened lately, from the three things that actually move:
     * registrations, edits, and the scheduled runner.
     *
     * Read-only, and deliberately short. This answers "has anything happened
     * while I was away", not "show me everything".
     */
    private function recent_activity( $user ) {
        global $wpdb;
        $rows = array();

        /*
         * REGISTRATIONS, SCOPED TO WHAT THIS PERSON CAN ACT ON.
         *
         * This was the whole table, for everyone, and it is the worst of the
         * three dashboard leaks because it NAMES PEOPLE: a contributor read
         * "Ana Ruiz registered for Trans Health Drop-in" about an event they
         * cannot open, edit or see the registrations for. A count is a fact
         * about volume; this was a fact about an individual, and it went to
         * somebody with no part in that event.
         *
         * The edits half below was already scoped, because it goes through
         * query_events(), which applies the author filter for anyone who is not
         * can_view_all(). This half went straight to SQL and bypassed it. That
         * is the shape of the fault: one list built through the helper that
         * knows the rule, one built around it.
         */
        $table = $wpdb->prefix . 'uc_rsvps';
        if ( $this->can_view_all( $user ) ) {
            $rsvps = $wpdb->get_results(
                "SELECT event_id, name, status, created_at FROM $table ORDER BY id DESC LIMIT 5"
            );
        } else {
            $rsvps = $wpdb->get_results( $wpdb->prepare(
                "SELECT r.event_id, r.name, r.status, r.created_at FROM $table r
                 INNER JOIN {$wpdb->posts} p ON p.ID = r.event_id
                 WHERE p.post_author = %d
                 ORDER BY r.id DESC LIMIT 5",
                (int) $user->ID
            ) );
        }
        foreach ( (array) $rsvps as $r ) {
            $title = get_the_title( $r->event_id );
            $rows[] = array(
                'when' => strtotime( $r->created_at ),
                'text' => sprintf(
                    '%s %s for %s',
                    $r->name ? $r->name : 'Someone',
                    'subscribed' === $r->status ? 'asked for reminders' : ( 'cancelled' === $r->status ? 'canceled their place' : 'registered' ),
                    $title ? $title : 'a deleted event'
                ),
            );
        }

        // Edits.
        $edited = $this->query_events( $user, array( 'per_page' => 5, 'orderby_modified' => true ) );
        foreach ( $edited as $id ) {
            $rows[] = array(
                'when' => (int) get_post_modified_time( 'U', true, $id ),
                'text' => sprintf( '%s was edited', get_the_title( $id ) ?: '(untitled)' ),
            );
        }

        // Scheduled runs are deliberately NOT listed here. They are server
        // administration and moved out of this portal in 2.13.0; the run log
        // lives under Events > Automation in the WordPress admin.

        usort( $rows, function ( $a, $b ) {
            return $b['when'] - $a['when'];
        } );
        $rows = array_slice( $rows, 0, 10 );

        if ( empty( $rows ) ) {
            echo '<p class="uc-empty">Nothing has happened yet.</p>';
            return;
        }
        ?>
        <ul class="uc-activity-list">
            <?php foreach ( $rows as $row ) : ?>
                <li>
                    <span class="uc-activity-when"><?php echo esc_html( $row['when'] ? human_time_diff( $row['when'], time() ) . ' ago' : '' ); ?></span>
                    <span class="uc-activity-what"><?php echo esc_html( $row['text'] ); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * The result of the last "Fetch updates" run.
     *
     * Read once and cleared, so it belongs to the run that just happened
     * rather than lingering on every later visit. Each source reports
     * separately — one failing says so beside the ones that worked.
     *
     * @param WP_User               $user
     * @param SFAF_Source_Adapter[] $active_sources
     */
    private function render_fetch_report( $user, $active_sources ) {
        $key     = 'sfaf_fetch_report_' . $user->ID;
        $results = get_transient( $key );

        if ( false === $results ) {
            // Nothing just ran. When no source is connected, name each one and
            // say what it is waiting for — "no sources connected" on its own
            // gives nobody anything to act on.
            if ( empty( $active_sources ) ) {
                $all = SFAF_Sources::adapters();
                ?>
                <div class="uc-card uc-card-muted">
                    <div class="uc-card-head"><h2>Sources</h2></div>
                    <p class="uc-empty">No third-party sources are connected yet. Connect one under
                    <strong>Settings &rsaquo; Integrations</strong> in the WordPress admin, then
                    &ldquo;Fetch updates&rdquo; will pull its events into the Pending queue.</p>
                    <?php if ( ! empty( $all ) ) : ?>
                        <ul class="uc-fetch-report">
                            <?php foreach ( $all as $adapter ) : ?>
                                <li class="uc-fetch-skip"><?php
                                    echo esc_html( $adapter->label() . ': ' . $adapter->inactive_reason() );
                                ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <?php
            }
            return;
        }

        delete_transient( $key );

        if ( ! is_array( $results ) ) {
            $results = array();
        }

        // A run with nothing registered at all still has to say something —
        // previously this returned silently and the flash message pointed at
        // results that were never rendered.
        if ( empty( $results ) ) {
            ?>
            <div class="uc-card">
                <div class="uc-card-head"><h2>Fetch results</h2></div>
                <p class="uc-empty">No third-party sources are registered in this build, so there was nothing to fetch.</p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="uc-card">
            <div class="uc-card-head"><h2>Fetch results</h2>
                <a href="<?php echo esc_url( $this->url( 'pending' ) ); ?>">Review pending &rarr;</a></div>
            <ul class="uc-fetch-report">
                <?php foreach ( $results as $result ) :
                    if ( ! empty( $result['skipped'] ) ) {
                        $row_class = 'uc-fetch-skip';
                    } elseif ( ! empty( $result['error'] ) ) {
                        $row_class = 'uc-fetch-fail';
                    } else {
                        $row_class = 'uc-fetch-ok';
                    }
                    ?>
                    <li class="<?php echo esc_attr( $row_class ); ?>">
                        <?php echo esc_html( SFAF_Sources::summarize( $result ) ); ?>

                        <?php // Whether removal handling was allowed to run at all is
                              // the thing worth being loudest about — it is the step
                              // that takes live events off the calendar.
                        if ( ! empty( $result['removal_skip'] ) ) : ?>
                            <div class="uc-fetch-guard">Removal check skipped. <?php echo esc_html( $result['removal_skip'] ); ?>. No event was unpublished by this source.</div>
                        <?php elseif ( ! empty( $result['removal_ran'] ) ) : ?>
                            <div class="uc-fetch-guard uc-fetch-guard-ok">Removal check ran on a complete result set<?php
                                if ( (int) $result['unpublished'] > 0 ) {
                                    echo ': ' . (int) $result['ended'] . ' closed at source, ' . (int) $result['vanished'] . ' gone entirely.';
                                } else {
                                    echo ', nothing had gone.';
                                }
                            ?></div>
                        <?php endif; ?>

                        <?php // FAQ movement, counted per source. Its own line rather
                              // than buried in the summary because it is content
                              // changing on live pages.
                        if ( ! empty( $result['faq_added'] ) || ! empty( $result['faq_updated'] ) || ! empty( $result['faq_removed'] ) ) : ?>
                            <div class="uc-fetch-guard uc-fetch-guard-ok">FAQs from this source:
                                <?php echo (int) $result['faq_added']; ?> added,
                                <?php echo (int) $result['faq_updated']; ?> updated,
                                <?php echo (int) $result['faq_removed']; ?> removed.
                                Questions typed by hand were not touched.</div>
                        <?php endif; ?>

                        <?php if ( ! empty( $result['notes'] ) ) : ?>
                            <ul class="uc-fetch-notes">
                                <?php foreach ( $result['notes'] as $note ) : ?>
                                    <li><?php echo esc_html( $note ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php // Which image field each item resolved to. Shipped
                              // because the GoFundMe Pro mapping is a best guess and
                              // we need to see what actually came back.
                        if ( ! empty( $result['images'] ) ) : ?>
                            <details class="uc-fetch-images">
                                <summary>Image field used (<?php echo (int) count( $result['images'] ); ?>)</summary>
                                <table class="uc-table">
                                    <thead><tr><th>Event</th><th>Field</th><th>URL</th></tr></thead>
                                    <tbody>
                                    <?php foreach ( $result['images'] as $img ) : ?>
                                        <tr>
                                            <td><?php echo esc_html( $img['title'] ); ?></td>
                                            <td><code><?php echo esc_html( $img['field'] ); ?></code></td>
                                            <td class="uc-break"><?php echo $img['url'] ? esc_html( $img['url'] ) : 'None'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </details>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /* =====================================================================
     * Rendering — events list
     * ================================================================== */

    /**
     * Columns the Events list can sort on, with the direction a first click
     * should produce.
     *
     * NOT EVERY COLUMN, AND THE OMISSIONS ARE DELIBERATE. Category is a
     * taxonomy an event can hold more than one of, so "sorted by category" has
     * no single answer; Series is derived from another post's title; Source is
     * "Local" on nearly every row. Sorting any of those would need a join whose
     * result nobody could predict, which is worse than a column that plainly
     * does not sort.
     *
     * @return array<string,string> column key => first-click direction
     */
    /**
     * The Events list's views, and the query each one adds.
     *
     * ONE DEFINITION, READ BY THE TAB STRIP, THE VALIDATOR, THE QUERY AND
     * EVERY LINK THAT CARRIES THE VIEW ALONG. A view that existed in the tabs
     * but not in the validator would 404 into the default; one that existed in
     * the validator but not the query would silently show everything. Both are
     * the sort of drift that only shows up in use.
     *
     * UPCOMING IS THE DEFAULT because it is the work. Past events have not
     * gone anywhere and are one click away, which is the whole of what
     * "archived" means here: a view, not a lifecycle. Nothing is deleted,
     * nothing expires, and no event is ever moved or marked by any of this.
     *
     * @return array[] key => array{label:string, args:array, hint:string}
     */
    private function event_views() {
        return array(
            'upcoming' => array(
                'label' => 'Upcoming',
                'args'  => array( 'upcoming' => true ),
                'hint'  => 'Events dated today or later.',
            ),
            'archived' => array(
                'label' => 'Archived',
                'args'  => array( 'archived' => true ),
                'hint'  => 'Events that have already happened. Nothing here has been deleted or altered: these are the same records, kept permanently, including events imported from GoFundMe Pro and Eventbrite.',
            ),
            'removed'  => array(
                'label' => 'Removed at source',
                'args'  => array( 'removed' => true ),
                'hint'  => 'Imported events the platform stopped listing. They were taken off the calendar and kept as drafts rather than deleted, at any date, so they are gathered here instead of being split across the other views.',
            ),
            'all'      => array(
                'label' => 'All',
                'args'  => array(),
                'hint'  => '',
            ),
        );
    }

    /**
     * The view tabs, each carrying the current filters and sort with it.
     *
     * Links rather than another dropdown in the filter bar: which events are
     * being looked at is the first question the screen answers, and burying it
     * in a select beside four refinements makes it read as a fifth refinement.
     */
    private function view_tabs( $view, $filters, $sort ) {
        $base = array_filter( array(
            's'       => $filters['s'],
            'cat'     => $filters['cat'] ? $filters['cat'] : '',
            'status'  => $filters['status'],
            'from'    => $filters['from'],
            'to'      => $filters['to'],
            'orderby' => $sort['orderby'],
            // Switching view must not silently put somebody back in My events.
            'scope'   => isset( $filters['scope'] ) ? $filters['scope'] : '',
        ), function ( $v ) { return '' !== $v && null !== $v; } );
        ?>
        <div class="uc-view-tabs" role="navigation" aria-label="Which events to show">
            <?php foreach ( $this->event_views() as $key => $def ) :
                $active = ( $key === $view );
                // The order is deliberately NOT carried: each view has its own
                // sensible default direction and forcing the previous view's
                // onto it lands somebody on the oldest event of all.
                $url = add_query_arg( array_merge( $base, array( 'view' => $key ) ), $this->url( 'events' ) );
                ?>
                <a class="uc-view-tab<?php echo $active ? ' uc-view-tab-active' : ''; ?>"
                   href="<?php echo esc_url( $url ); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                    <?php echo esc_html( $def['label'] ); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
        $hint = $this->event_views()[ $view ]['hint'];
        if ( '' !== $hint ) {
            echo '<p class="uc-hint uc-view-hint">' . esc_html( $hint ) . '</p>';
        }
    }

    private function sortable_columns() {
        return array(
            'title'  => 'asc',   // alphabetical is what a first click should mean
            'date'   => 'desc',  // newest first, matching the default view
            'rsvps'  => 'desc',  // "which are filling up" is the useful question
            'status' => 'asc',
        );
    }

    /**
     * One sortable column heading.
     *
     * The indicator is a character AND an aria-sort attribute, not colour or
     * weight: a column header that only looks different is no indicator at all
     * to a screen reader, and the whole point is being able to tell at a glance
     * which of eight columns the table is ordered by.
     */
    private function sort_header( $column, $label, $sort, $filters ) {
        $sortable = $this->sortable_columns();
        if ( ! isset( $sortable[ $column ] ) ) {
            return '<th>' . esc_html( $label ) . '</th>';
        }

        $active = ( $sort['orderby'] === $column );
        // Clicking the active column flips it; clicking a new one starts at
        // that column's natural direction rather than always at ascending.
        $next   = $active ? ( 'asc' === $sort['order'] ? 'desc' : 'asc' ) : $sortable[ $column ];

        // Every filter travels with the sort, and the page resets to 1: a sort
        // that silently kept you on page 4 of a different ordering would show
        // rows nobody asked for.
        $args = array_filter( array(
            's'       => $filters['s'],
            'cat'     => $filters['cat'] ? $filters['cat'] : '',
            'status'  => $filters['status'],
            'from'    => $filters['from'],
            'to'      => $filters['to'],
            // Sorting inside Archived must stay inside Archived, and sorting
            // inside All events must stay inside All events.
            'view'    => isset( $filters['view'] ) ? $filters['view'] : '',
            'scope'   => isset( $filters['scope'] ) ? $filters['scope'] : '',
            'orderby' => $column,
            'order'   => $next,
        ), function ( $v ) { return '' !== $v && null !== $v; } );

        $url  = add_query_arg( $args, $this->url( 'events' ) );
        $mark = $active ? ( 'asc' === $sort['order'] ? ' ▲' : ' ▼' ) : '';
        $aria = $active ? ( 'asc' === $sort['order'] ? 'ascending' : 'descending' ) : 'none';

        return '<th class="uc-sortable' . ( $active ? ' uc-sorted' : '' ) . '" aria-sort="' . esc_attr( $aria ) . '">'
            . '<a href="' . esc_url( $url ) . '">' . esc_html( $label )
            . '<span class="uc-sort-mark" aria-hidden="true">' . $mark . '</span>'
            . '<span class="screen-reader-text">'
            . esc_html( $active
                ? ( 'asc' === $sort['order'] ? ', sorted ascending. Activate to sort descending.' : ', sorted descending. Activate to sort ascending.' )
                : ', not sorted. Activate to sort.' )
            . '</span></a></th>';
    }

    private function render_events( $user ) {
        $this->chrome_open( $user, 'events' );

        /*
         * THE VIEW IS A FILTER, NOT A SCREEN.
         *
         * Archived events are the same posts in the same table with the same
         * columns, the same sorting and the same actions; the only difference
         * is which side of today the date falls on. So this is one more value
         * in the query string beside the search, category, status and date
         * range, and everything already built keeps working across it. A
         * second screen would have meant a second table to keep in step.
         */
        $view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'upcoming';
        if ( ! isset( $this->event_views()[ $view ] ) ) {
            $view = 'upcoming';
        }

        $filters = array(
            's'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'cat'      => isset( $_GET['cat'] ) ? intval( $_GET['cat'] ) : 0,
            'status'   => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
            'from'     => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
            'to'       => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
            'view'     => $view,
            // Carried so paging, sorting and the view tabs keep the chosen
            // scope. Without it, page two of All events would be page two of
            // My events and the count above it would stop matching the table.
            'scope'    => $this->scope_choice(),
        );

        // SORT LIVES IN THE URL so a view can be linked, bookmarked and shared,
        // and so it survives paging and filtering without a session or a cookie
        // holding state the address bar does not admit to.
        $sortable = $this->sortable_columns();
        $orderby  = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'date';
        if ( ! isset( $sortable[ $orderby ] ) ) {
            $orderby = 'date';
        }
        $order = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( $_GET['order'] ) ) ) ? 'asc' : 'desc';
        if ( ! isset( $_GET['order'] ) ) {
            $order = $sortable[ $orderby ];
            /*
             * The useful end of the list is a different end in each view. On
             * upcoming events it is the soonest, which needs doing first; on
             * archived events it is the most recent, which is the one anybody
             * is looking for. Only the unstated default moves: a sort chosen
             * in the URL still wins, in either view.
             */
            if ( 'date' === $orderby && 'upcoming' === $view ) {
                $order = 'asc';
            }
        }
        $sort  = array( 'orderby' => $orderby, 'order' => $order );
        $paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

        $cats = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );

        /*
         * SCOPE, AND WHAT IT CHANGES BESIDES THE QUERY.
         *
         * For an admin or an editor this narrows or widens a list they may see
         * either way. For a contributor, "All events" is a genuinely different
         * screen: a read-only view of what is on the public calendar, drawn by
         * public_events_table(), which cannot emit a registration count, a link
         * or an action. is_public_scope() is the one question that decides it,
         * asked once here and answered the same way everywhere below.
         */
        $scope  = $this->scope_choice();
        $public = $this->is_public_scope( $user, $scope );

        $ids = $this->query_events( $user, array_merge( $filters, $this->event_views()[ $view ]['args'], array(
            'orderby'  => $orderby,
            'order'    => $order,
            'paged'    => $paged,
            'per_page' => 25,
            'scope'    => $scope,
        ) ) );
        $total = $this->last_query_total;
        $pages = $this->last_query_pages;
        ?>
        <div class="uc-page-head">
            <h1><?php echo 'all' === $scope ? 'All Events' : 'My Events'; ?></h1>
            <a href="<?php echo esc_url( $this->url( 'events/new' ) ); ?>" class="uc-btn uc-btn-primary">+ New Event</a>
        </div>

        <?php
        // The scope travels with the view, the search and the sort, so
        // switching it does not silently drop any of them.
        $this->render_scope_toggle( $scope, 'events', array(
            'view'    => $view,
            's'       => $filters['s'],
            'cat'     => $filters['cat'] ? $filters['cat'] : '',
            'status'  => $filters['status'],
            'orderby' => $orderby,
            'order'   => $order,
        ) );
        ?>

        <?php if ( $public ) : ?>
            <p class="uc-view-hint">
                Everything on the calendar, read-only. Events that are not yours show what the public calendar shows.
                Switch to My events to edit, or to see registrations.
            </p>
        <?php endif; ?>

        <?php $this->view_tabs( $view, $filters, $sort ); ?>

        <?php
        /*
         * SEARCH RUNS AS YOU TYPE, AND IT IS STILL AN ORDINARY GET FORM.
         *
         * The search is a real server query (3.6.0), not a pass over the rows
         * already on screen, so it cannot be done on keyup without hitting the
         * server on every keystroke. portal.js debounces it and then submits
         * THIS form, which means the answer arrives at a real URL: the address
         * bar says what is being searched, the back button works, the result is
         * shareable, and the sort and filters ride along in the fields they
         * were already in.
         *
         * With scripting off the Filter button is still there and still does
         * exactly what it always did. Nothing here depends on the script
         * running; it only removes the keystroke.
         */
        ?>
        <form method="get" action="<?php echo esc_url( $this->url( 'events' ) ); ?>" class="uc-filters-bar" data-uc-live-search>
            <?php // Filtering must not silently drop you back into Upcoming. ?>
            <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
            <?php // Nor may filtering drop somebody back into My events. ?>
            <input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>" />
            <input type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="Search events…"
                   data-uc-live-search-input autocomplete="off"
                   <?php // Typing continues where it left off after the reload
                         // the search causes. Without this every debounce would
                         // take the caret out of the box mid-word. ?>
                   <?php echo ( '' !== $filters['s'] ) ? ' data-uc-refocus' : ''; ?> />
            <select name="cat">
                <option value="0">All categories</option>
                <?php if ( ! is_wp_error( $cats ) ) : foreach ( $cats as $c ) : ?>
                    <option value="<?php echo (int) $c->term_id; ?>" <?php selected( $filters['cat'], $c->term_id ); ?>><?php echo esc_html( $c->name ); ?></option>
                <?php endforeach; endif; ?>
            </select>
            <select name="status">
                <option value="">Any status</option>
                <?php foreach ( array( 'publish', 'pending', 'draft', 'future' ) as $k ) : ?>
                    <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['status'], $k ); ?>><?php echo esc_html( sfaf_status_label( $k ) ); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" title="From date" />
            <input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" title="To date" />
            <?php
            // The sort rides along as hidden fields, so filtering does not
            // silently throw away the ordering somebody just chose.
            ?>
            <input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
            <input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
            <button class="uc-btn" type="submit">Filter</button>
        </form>

        <div class="uc-card">
            <div class="uc-card-head">
                <h2><?php echo (int) $total; ?> <?php echo esc_html( 1 === (int) $total ? "event" : "events" ); ?></h2>
            </div>
            <?php
            /*
             * TWO RENDERERS, ONE QUESTION, NO FLAG INSIDE EITHER. The branch is
             * here and nowhere else: whichever table runs, it has no way to
             * behave like the other one.
             */
            if ( $public ) {
                $this->public_events_table( $ids );
            } else {
                $this->events_table( $ids, $user, $sort, $filters );
            }
            ?>
            <?php $this->events_pagination( $paged, $pages, $total, $sort, $filters ); ?>
        </div>

        <?php
        /*
         * THE ROUTE TO REGISTRATIONS WITH NO EVENT LEFT TO CLICK.
         *
         * RSVP rows are kept when an event is deleted, with the title
         * snapshotted at that moment so they still read as something. Every
         * other way into this data is now through an event, and those rows
         * have no event, so this is their way in. It sits at the foot of the
         * Events list, appears only when there is something behind it, and
         * says how many, so it is a signpost rather than furniture.
         */
        if ( $this->can_view_all( $user ) ) {
            $orphan_total = SFAF_RSVP::orphan_count();
            if ( $orphan_total ) : ?>
                <p class="uc-hint uc-orphan-note">
                    <a href="<?php echo esc_url( add_query_arg( 'orphans', 1, $this->url( 'rsvps' ) ) ); ?>">
                        <?php echo (int) $orphan_total; ?>
                        <?php echo esc_html( 1 === $orphan_total ? 'registration belongs' : 'registrations belong' ); ?>
                        to events that have been deleted</a>.
                    Those records are kept on purpose and are not reachable through an event, so they are gathered here.
                </p>
            <?php endif;
        }
        $this->chrome_close();
    }

    /**
     * Page links that carry the whole view with them.
     *
     * The list used to be capped at 50 with no paging at all, which silently
     * dropped every event past the fiftieth: not a truncation anybody was told
     * about, and indistinguishable from not having those events.
     */
    private function events_pagination( $paged, $pages, $total, $sort, $filters ) {
        if ( $pages < 2 ) {
            if ( $total ) {
                echo '<p class="uc-hint uc-list-total">' . (int) $total . ' ' . esc_html( _n( 'event', 'events', $total ) ) . '.</p>';
            }
            return;
        }

        $base = array_filter( array(
            's'       => $filters['s'],
            'cat'     => $filters['cat'] ? $filters['cat'] : '',
            'status'  => $filters['status'],
            'from'    => $filters['from'],
            'to'      => $filters['to'],
            // Page 2 of Archived is page 2 of Archived.
            'view'    => isset( $filters['view'] ) ? $filters['view'] : '',
            // And page 2 of All events is page 2 of All events.
            'scope'   => isset( $filters['scope'] ) ? $filters['scope'] : '',
            'orderby' => $sort['orderby'],
            'order'   => $sort['order'],
        ), function ( $v ) { return '' !== $v && null !== $v; } );

        $link = function ( $page ) use ( $base ) {
            return add_query_arg( array_merge( $base, array( 'paged' => (int) $page ) ), $this->url( 'events' ) );
        };
        ?>
        <div class="uc-list-pagination">
            <p class="uc-hint uc-list-total">
                <?php echo (int) $total; ?> <?php echo esc_html( _n( 'event', 'events', $total ) ); ?>.
                Page <?php echo (int) $paged; ?> of <?php echo (int) $pages; ?>.
            </p>
            <div class="uc-list-pages">
                <?php if ( $paged > 1 ) : ?>
                    <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( $link( $paged - 1 ) ); ?>" rel="prev">&larr; Previous</a>
                <?php endif; ?>
                <?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
                    <?php if ( $p === $paged ) : ?>
                        <span class="uc-page-num uc-page-current" aria-current="page"><?php echo (int) $p; ?></span>
                    <?php else : ?>
                        <a class="uc-page-num" href="<?php echo esc_url( $link( $p ) ); ?>"><?php echo (int) $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ( $paged < $pages ) : ?>
                    <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( $link( $paged + 1 ) ); ?>" rel="next">Next &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* =====================================================================
     * Scope: whose events am I looking at
     * ================================================================== */

    /**
     * The chosen scope, from the URL. 'mine' unless 'all' was asked for.
     *
     * DEFAULTS TO MINE FOR EVERYONE. A manager arriving at their own screen is
     * there to do their own work, and for a contributor the wider list is a
     * read-only view of the public calendar rather than a place to work at all.
     *
     * @return string 'mine'|'all'
     */
    private function scope_choice() {
        return ( isset( $_GET['scope'] ) && 'all' === $_GET['scope'] ) ? 'all' : 'mine';
    }

    /**
     * The All events / My events control.
     *
     * TWO REAL LINKS, NOT A CONTROL WITH STATE. Each is a URL that can be
     * bookmarked and shared, which is the same reasoning as the view tabs on
     * the Events list, and it means the scope survives paging, sorting and
     * filtering without anything holding state the address bar does not admit
     * to.
     *
     * @param string $scope Current scope.
     * @param string $path  Portal path this control lives on.
     * @param array  $carry Query args to preserve across the switch.
     */
    private function render_scope_toggle( $scope, $path, $carry = array() ) {
        $carry = array_filter( (array) $carry, function ( $v ) { return '' !== $v && null !== $v; } );
        $options = array(
            'mine' => 'My events',
            'all'  => 'All events',
        );
        ?>
        <div class="uc-scope-switch" role="group" aria-label="Which events to show">
            <?php foreach ( $options as $key => $label ) :
                $url = add_query_arg( array_merge( $carry, array( 'scope' => $key ) ), $this->url( $path ) );
                ?>
                <a class="uc-scope-opt<?php echo $scope === $key ? ' is-on' : ''; ?>"
                   href="<?php echo esc_url( $url ); ?>"
                   <?php echo $scope === $key ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Whether this person is looking at other people's events read-only.
     *
     * @param WP_User $user
     * @param string  $scope
     * @return bool
     */
    private function is_public_scope( $user, $scope ) {
        return ( 'all' === $scope && ! $this->can_view_all( $user ) );
    }

    /**
     * Other people's events, showing ONLY what is already on the public
     * calendar.
     *
     * A SEPARATE RENDERER, AND THAT IS THE WHOLE SAFETY ARGUMENT. events_table()
     * could have taken a flag deciding whether to draw the registration column
     * and the actions, and a flag like that is one refactor away from
     * defaulting the wrong way. This method instead has NO CODE PATH that can
     * emit any of it: there is no call to sfaf_get_rsvp_count() in it, no link
     * to the editor, no link to the registrations screen, and no form. It
     * cannot leak participant data by being changed carelessly, because it
     * would have to be given the ability first. Same reasoning, and the same
     * decision, as upcoming_overview() against events_table().
     *
     * THE RSVP COLUMN IS ABSENT, NOT EMPTY. Not the number, not a dash, not a
     * blank cell: a table has one column set for all its rows, so the column
     * does not exist in this table at all. A contributor who wants their own
     * counts switches to My events, where every row is theirs.
     *
     * NOTHING IS CLICKABLE. A link would mean another surface that has to
     * exclude participant data correctly, and the last three permission
     * defects in this plugin were all of that shape. The public calendar is
     * where somebody goes for detail about an event that is not theirs.
     *
     * FIELDS, AND WHY EACH ONE IS SAFE: title, date, time, location, category,
     * organizer and published status are exactly what an anonymous visitor
     * reads off the public calendar for the same event.
     *
     * @param int[] $ids
     */
    private function public_events_table( $ids ) {
        if ( empty( $ids ) ) {
            echo '<p class="uc-empty">No events found.</p>';
            return;
        }
        _prime_post_caches( $ids, true, true );
        ?>
        <table class="uc-table uc-table-public">
            <thead><tr>
                <th>Event</th><th>Date</th><th>Time</th><th>Location</th>
                <th>Category</th><th>Organizer</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $ids as $id ) :
                $date  = (string) get_post_meta( $id, '_uc_event_date', true );
                $cats  = wp_get_post_terms( $id, 'uc_event_category', array( 'fields' => 'names' ) );
                $orgs  = wp_get_post_terms( $id, 'uc_organizer', array( 'fields' => 'names' ) );
                $st    = get_post_status( $id );
                $clock = sfaf_ap_time_range(
                    (string) get_post_meta( $id, '_uc_start_time', true ),
                    (string) get_post_meta( $id, '_uc_end_time', true )
                );
                ?>
                <tr>
                    <?php // Plain text. Deliberately not a link: see the note above. ?>
                    <td><strong><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></strong></td>
                    <td><?php echo $date ? esc_html( sfaf_ap_date( $date, 'short' ) . ', ' . date_i18n( 'Y', strtotime( $date . ' 12:00:00' ) ) ) : '<span class="uc-muted">None</span>'; ?></td>
                    <td><?php echo '' !== $clock ? esc_html( $clock ) : '<span class="uc-muted">None</span>'; ?></td>
                    <td><?php
                        $where = sfaf_event_location_short( $id );
                        echo '' !== $where ? esc_html( $where ) : '<span class="uc-muted">None</span>';
                    ?></td>
                    <td><?php echo ( $cats && ! is_wp_error( $cats ) ) ? esc_html( implode( ', ', $cats ) ) : '<span class="uc-muted">None</span>'; ?></td>
                    <td><?php echo ( $orgs && ! is_wp_error( $orgs ) ) ? esc_html( implode( ', ', $orgs ) ) : '<span class="uc-muted">None</span>'; ?></td>
                    <td><span class="uc-pill uc-pill-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( sfaf_status_label( $st ) ); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function events_table( $ids, $user, $sort = null, $filters = null ) {
        if ( empty( $ids ) ) {
            echo '<p class="uc-empty">No events found.</p>';
            return;
        }
        // Bulk-load everything the rows need: post/meta/term caches in a couple of
        // queries and all RSVP counts in one, so the loop below is cache-only.
        _prime_post_caches( $ids, true, true );
        sfaf_prime_rsvp_counts( $ids );

        // Called without a sort from screens that are not the Events list.
        $plain = ( null === $sort );
        ?>
        <table class="uc-table">
            <thead><tr>
                <?php if ( $plain ) : ?>
                    <th>Event</th><th>Date</th><th>Category</th><th>RSVPs</th><th>Status</th>
                <?php else :
                    echo $this->sort_header( 'title', 'Event', $sort, $filters );
                    echo $this->sort_header( 'date', 'Date', $sort, $filters );
                    echo '<th>Category</th>';
                    echo $this->sort_header( 'rsvps', 'RSVPs', $sort, $filters );
                    echo $this->sort_header( 'status', 'Status', $sort, $filters );
                endif; ?>
                <th>Series</th><th>Source</th><th class="uc-col-actions">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $ids as $id ) :
                $date = get_post_meta( $id, '_uc_event_date', true );
                $cats = wp_get_post_terms( $id, 'uc_event_category', array( 'fields' => 'names' ) );
                $st   = get_post_status( $id );
                ?>
                <tr>
                    <td><a class="uc-tlink" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></a></td>
                    <td><?php echo $date ? esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ) : '<span class="uc-muted">None</span>'; ?></td>
                    <td><?php echo $cats && ! is_wp_error( $cats ) ? esc_html( implode( ', ', $cats ) ) : '<span class="uc-muted">None</span>'; ?></td>
                    <td><?php
                        /*
                         * THE COUNT IS THE WAY IN. A number in a cell that
                         * answers "how many" and refuses to answer "who" is
                         * half an answer, and the other half used to be a
                         * sidebar tab leading to every registration on the
                         * calendar at once.
                         *
                         * LINKED EVEN AT ZERO. It used to link only when the
                         * count was non-zero, on the reasoning that a link to
                         * an empty table is a disappointment. That was wrong
                         * twice over. A number that is sometimes a link and
                         * sometimes not is a control that changes shape under
                         * the reader, and 0 is exactly when somebody wants to
                         * go and look, because the page behind it now says
                         * whether registrations are even switched on and lets
                         * them switch them on from there. A dead number that
                         * goes nowhere is worse than an empty page that
                         * explains itself.
                         */
                        $rsvp_n = (int) sfaf_get_rsvp_count( $id );
                        if ( $this->can_view_all( $user ) ) {
                            echo '<a class="uc-tlink" href="'
                                . esc_url( add_query_arg( 'event_id', $id, $this->url( 'rsvps' ) ) ) . '">'
                                . (int) $rsvp_n . '</a>';
                        } else {
                            echo (int) $rsvp_n;
                        }
                    ?></td>
                    <td>
                        <span class="uc-pill uc-pill-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( sfaf_status_label( $st ) ); ?></span>
                        <?php
                        /*
                         * "REMOVED AT SOURCE" IS NOT "PAST", AND MUST NOT READ
                         * AS IT.
                         *
                         * An event the platform stopped listing was unpublished
                         * to a draft by this plugin, not by a person, and can be
                         * at any date. Left as a bare "Draft" pill it is
                         * indistinguishable from something a manager parked
                         * deliberately, and in the Archived view it would be
                         * indistinguishable from an event that simply happened.
                         * So it says so, with when and why, and the editor's
                         * fuller banner is unchanged and still one click away.
                         */
                        $removed_at = (int) get_post_meta( $id, SFAF_Sources::META_REMOVED_AT, true );
                        if ( $removed_at ) :
                            $why = (string) get_post_meta( $id, SFAF_Sources::META_REMOVED_WHY, true );
                            ?>
                            <span class="uc-pill uc-pill-removed"
                                  title="<?php echo esc_attr(
                                      ( 'ended' === $why ? 'The campaign is no longer active at the source' : 'It stopped being returned by the source' )
                                      . ', ' . human_time_diff( $removed_at, time() ) . ' ago. It was taken off the calendar and kept as a draft.'
                                  ); ?>">Removed at source</span>
                        <?php endif; ?>
                    </td>
                    <td><?php
                        // NO SERIES EVER APPEARS IN THIS TABLE. It is a list of
                        // uc_event posts and a series is a term, so that is now
                        // structural rather than something this loop has to
                        // check for. What a row can say is which series it
                        // belongs to — and, separately, that it came from a
                        // recurrence group.
                        $row_series = SFAF_Series::for_event( $id );
                        if ( $row_series ) {
                            echo '<a class="uc-tlink" href="' . esc_url( $this->url( 'series/edit/' . $row_series->term_id ) ) . '">'
                                . esc_html( $row_series->name ) . '</a>';
                        } else {
                            echo '<span class="uc-muted">None</span>';
                        }
                        if ( '' !== SFAF_Recurrence::group_of( $id ) ) {
                            echo '<br><span class="uc-muted">Repeating</span>';
                        }
                    ?></td>
                    <td><?php
                        /*
                         * WHERE THIS EVENT CAME FROM, ALL THREE ANSWERS.
                         *
                         * This column used to read only _uc_source_site, the
                         * multi-site sync marker, so an event imported from
                         * GoFundMe Pro or Eventbrite said "Local", which is the
                         * one thing it is not. That mattered least while the list
                         * showed only upcoming events and most in the archive,
                         * which is where somebody is looking at a record of
                         * something that happened and needs to know whose
                         * record it is.
                         *
                         * The import badge wins when both are set, because it
                         * is the one that says anything about how the event is
                         * maintained.
                         */
                        $row_prov = SFAF_Sources::provenance( $id );
                        $src      = get_post_meta( $id, '_uc_source_site', true );
                        if ( '' !== $row_prov['source'] ) {
                            $badge = '<span class="uc-source-badge">' . esc_html( $row_prov['label'] ) . '</span>';
                            echo $row_prov['source_url']
                                ? '<a href="' . esc_url( $row_prov['source_url'] ) . '" target="_blank" rel="noopener noreferrer">' . $badge . '</a>'
                                : $badge;
                        } elseif ( $src ) {
                            echo esc_html( wp_parse_url( $src, PHP_URL_HOST ) ?: $src );
                        } else {
                            echo '<span class="uc-muted">Local</span>';
                        }
                    ?></td>
                    <?php
                    /*
                     * ONE REMOVAL, AND IT MEANS WHAT IT SAYS.
                     *
                     * There used to be three, because removing an event could
                     * damage other events: removing a series parent orphaned
                     * every occurrence, and removing an occurrence had to be
                     * recorded as a cancellation or the next series save put it
                     * straight back. Nothing regenerates now and no event is a
                     * series, so this removes one event and nothing else.
                     */
                    ?>
                    <td class="uc-row-actions">
                        <div class="uc-actions">
                            <a class="uc-action-link" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>">Edit</a>
                            <?php
                            /*
                             * DUPLICATE LIVES HERE AND NOT IN THE EDITOR.
                             *
                             * It posts and redirects to the new draft, which on
                             * the editor would mean walking away from whatever
                             * the manager had typed and not saved. From the
                             * list there is nothing to lose. It is also where
                             * the thought occurs: somebody scanning last
                             * year's events for the one to run again is
                             * already looking at this row.
                             *
                             * Offered on every event, archived or not, and on
                             * imported ones, where it is the way to turn a
                             * campaign the platform owns into an event of our
                             * own.
                             */
                            ?>
                            <form method="post" action="<?php echo esc_url( $this->url( 'events' ) ); ?>">
                                <input type="hidden" name="uc_action" value="duplicate_event" />
                                <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                                <?php wp_nonce_field( 'uc_portal_duplicate_event', 'uc_nonce' ); ?>
                                <button type="submit" class="uc-action-link uc-action-btn"
                                        title="Create a new draft from this event. No date, no registrations, and this event is not changed.">Duplicate</button>
                            </form>
                            <form method="post" action="<?php echo esc_url( $this->url( 'events' ) ); ?>"
                                  onsubmit="return confirm('Remove this event? Nothing else changes and nothing brings it back.');">
                                <input type="hidden" name="uc_action" value="trash_event" />
                                <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                                <?php wp_nonce_field( 'uc_portal_trash_event', 'uc_nonce' ); ?>
                                <button type="submit" class="uc-link-danger">Remove</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* =====================================================================
     * Series Manager
     * ================================================================== */

    /**
     * Apply a saved FAQ set, from inside the FAQ block itself.
     *
     * WHY THIS EXISTS WHEN THE PANEL ABOVE THE FORM ALREADY DID IT. The panel
     * applies by posting and redirecting, which is correct on the server and
     * useless in practice: pressing it throws away every unsaved edit in the
     * form below it, and it sits at the top of a long page nowhere near the
     * questions it changes. So the set was appliable and nobody applied one.
     *
     * This control does the copying in the browser instead. It writes the
     * set's rows into the repeater as ordinary new rows and stops there. They
     * are saved when the event is saved, alongside everything else the manager
     * has typed, and can be edited or removed first. Nothing is posted, so
     * nothing is lost.
     *
     * WHAT IT CANNOT DO, BY CONSTRUCTION rather than by care:
     *
     *   It cannot overwrite. It only ever appends rows to the end of the
     *   repeater. There is no replace mode here, because a replace that
     *   silently discards typing is exactly what this must not be.
     *
     *   It cannot touch an imported row. Platform-owned rows are rendered
     *   outside this repeater with no `name` attributes at all (see
     *   faq_repeater()), so there is no field here that could carry one. They
     *   are read back from the database on save and re-attached in front.
     *
     *   It cannot smuggle in a source_faq_id. A repeater row is a question
     *   input and an answer textarea, and that is the whole of what posts.
     *   An applied row is therefore a manual row in the only sense that
     *   matters to SFAF_Sources::sync_faqs(), which splits rows on whether
     *   they carry an ID and leaves the ones that do not alone forever.
     *
     * ON A NEW EVENT TOO. The old panel needed a saved event to post to. This
     * needs nothing, so the questions can be applied while the event is still
     * being written and saved with it.
     *
     * The server-side apply is left in place and hidden by script, so a
     * manager without JavaScript keeps the post-and-redirect they had.
     */
    private function faq_set_picker() {
        $sets = SFAF_FAQ_Sets::all();
        if ( empty( $sets ) ) {
            return;
        }

        // Name and rows only. The IDs are the option's keys and the timestamps
        // are for the management screen; neither is any use to the browser.
        $payload = array();
        foreach ( $sets as $id => $set ) {
            $payload[ $id ] = array(
                'name' => $set['name'],
                'rows' => $set['rows'],
            );
        }
        ?>
        <div class="uc-faq-picker" data-uc-faq-picker hidden>
            <?php
            /*
             * NOT class="uc-field". That class is one of the wrappers the edit
             * scope script hangs a pencil on, so labelling this control with
             * it would put a second pencil inside the FAQ block: one for the
             * dropdown and one for the questions, unlocking half the block
             * each. Without it the whole block resolves to the one .uc-card
             * wrapper and gets a single pencil that opens the set picker and
             * the rows together, which is how a manager thinks of them.
             */
            ?>
            <div class="uc-faq-picker-row">
                <label class="uc-faq-picker-label">
                    <span class="uc-field-label">Apply a saved FAQ set</span>
                    <select data-uc-faq-set>
                        <?php foreach ( $sets as $set ) : ?>
                            <option value="<?php echo esc_attr( $set['id'] ); ?>"><?php
                                echo esc_html( $set['name'] . ' (' . count( $set['rows'] ) . ')' );
                            ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="button" class="uc-btn uc-btn-sm" data-uc-faq-apply>Add these questions</button>
            </div>
            <p class="uc-flash uc-faq-picker-said" data-uc-faq-said role="status" hidden></p>
            <p class="uc-hint">
                The questions are copied in and added underneath the ones already here. Nothing already written is
                changed or removed, and questions that are already on this event are skipped rather than duplicated.
                Edit or remove any of them before you save. Editing the set afterwards does not change this event.
            </p>
            <script type="application/json" data-uc-faq-sets><?php
                echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
            ?></script>
        </div>
        <?php
    }

    /**
     * Portal FAQ repeater markup (vanilla repeater handled by portal.js).
     *
     * WHY IMPORTED ROWS CARRY NO FORM FIELDS AT ALL. When a platform owns the
     * FAQ block, its rows are rendered as disabled controls with no `name`, so
     * the browser submits nothing for them. The save then reads them back from
     * the database and re-attaches them ahead of whatever was submitted.
     *
     * That is deliberately not the usual trick of mirroring disabled fields
     * into hidden inputs: a disabled input submits nothing, so mirroring is
     * the only way to keep the rows — and it also hands the browser a way to
     * rewrite or delete rows it is not allowed to touch. Reading them from the
     * database instead makes tampering with the POST simply have no effect,
     * and means the ID on each imported row never has to survive a round trip
     * through a form.
     *
     * @param string  $name   POST field name for the editable rows.
     * @param array[] $faqs   All rows, imported and manual.
     * @param array   $source array{locked:bool,label:string,url:string} — the
     *                        platform that owns the imported rows, if any.
     */
    private function faq_repeater( $name, $faqs, $source = array() ) {
        $locked = ! empty( $source['locked'] );
        $label  = isset( $source['label'] ) ? (string) $source['label'] : 'the source';

        // Only split when a platform actually owns them. On an ordinary event
        // every row is editable, exactly as before.
        $imported = array();
        $manual   = $faqs;
        if ( $locked ) {
            $imported = array();
            $manual   = array();
            foreach ( $faqs as $f ) {
                if ( sfaf_faq_is_imported( $f ) ) {
                    $imported[] = $f;
                } else {
                    $manual[] = $f;
                }
            }
        }

        if ( $locked ) : ?>
            <div class="uc-locked-faqs">
                <p class="uc-field-note uc-field-note-locked">
                    <?php echo $this->icon_lock(); ?>
                    <span><strong><?php echo (int) count( $imported ); ?> question<?php echo 1 === count( $imported ) ? '' : 's'; ?> from <?php echo esc_html( $label ); ?>.</strong>
                    These are kept in step with the campaign on every fetch, so they cannot be edited here. An edit would be overwritten the next time the campaign is read.
                    <?php if ( ! empty( $source['url'] ) ) : ?>
                        <a href="<?php echo esc_url( $source['url'] ); ?>" target="_blank" rel="noopener noreferrer">Edit on <?php echo esc_html( $label ); ?> &nearr;</a>
                    <?php endif; ?>
                    </span>
                </p>
                <?php if ( empty( $imported ) ) : ?>
                    <p class="uc-muted"><?php echo esc_html( $label ); ?> has no FAQs on this campaign yet.</p>
                <?php else : ?>
                    <?php foreach ( $imported as $f ) : ?>
                        <div class="uc-repeater-row uc-faq-row uc-faq-row-locked">
                            <input type="text" value="<?php echo esc_attr( $f['question'] ); ?>" disabled aria-label="Question, from <?php echo esc_attr( $label ); ?>, not editable here" />
                            <textarea rows="2" disabled aria-label="Answer, from <?php echo esc_attr( $label ); ?>, not editable here"><?php echo esc_textarea( $f['answer'] ); ?></textarea>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p class="uc-hint" style="margin-top:14px;"><strong>Your own questions.</strong> Added here, kept forever, and never reordered or removed by a fetch. They appear after the ones above.</p>
        <?php endif; ?>

        <div class="uc-repeater" data-repeater>
            <div class="uc-repeater-rows">
                <?php foreach ( $manual as $i => $f ) : ?>
                    <div class="uc-repeater-row uc-faq-row">
                        <input type="text" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][question]" value="<?php echo esc_attr( $f['question'] ); ?>" placeholder="Question" />
                        <textarea name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][answer]" rows="2" placeholder="Answer"><?php echo esc_textarea( $f['answer'] ); ?></textarea>
                        <button type="button" class="uc-link-danger uc-repeater-remove">&times;</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="uc-btn uc-btn-sm uc-repeater-add">+ Add FAQ</button>
            <template class="uc-repeater-tpl">
                <div class="uc-repeater-row uc-faq-row">
                    <input type="text" name="<?php echo esc_attr( $name ); ?>[__I__][question]" placeholder="Question" />
                    <textarea name="<?php echo esc_attr( $name ); ?>[__I__][answer]" rows="2" placeholder="Answer"></textarea>
                    <button type="button" class="uc-link-danger uc-repeater-remove">&times;</button>
                </div>
            </template>
            <?php if ( $locked ) : ?>
                <input type="hidden" name="uc_faq_has_manual" value="1" />
            <?php endif; ?>
        </div>
        <?php
    }

    /* =====================================================================
     * Field ownership — rendering
     *
     * Every one of these reads the ADAPTER's declaration. Nothing here knows
     * that GoFundMe Pro has no image or that Eventbrite has a description;
     * changing which fields a platform owns is a change to that adapter and
     * nothing else. See SFAF_Source_Adapter::owned_fields().
     * ================================================================== */

    /** The lock mark on a platform-owned field. */
    private function icon_lock() {
        return '<svg class="uc-state-icon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">'
            . '<path fill="currentColor" d="M17 9V7a5 5 0 0 0-10 0v2H5v12h14V9zm-8-2a3 3 0 0 1 6 0v2H9zm4 9.7V19h-2v-2.3a2 2 0 1 1 2 0z"/></svg>';
    }

    /**
     * The mark on a manager-owned field that is still empty.
     *
     * A pencil, not an exclamation mark: a GoFundMe Pro campaign arrives
     * needing these EVERY time, by design, so this is a step in the workflow
     * and not a fault. "!" and the word "error" would tell a manager something
     * had gone wrong on every single import.
     */
    private function icon_needs() {
        return '<svg class="uc-state-icon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">'
            . '<path fill="currentColor" d="M4 17.2V20h2.8L17 9.8 14.2 7zm14.8-9.6a.75.75 0 0 0 0-1.06l-1.74-1.74a.75.75 0 0 0-1.06 0L14.6 6.2 17.4 9z"/></svg>';
    }

    /**
     * State of one editor field for an imported event.
     *
     * FOUR STATES, NOT THREE. 'filled' is a manager-owned field that has been
     * filled in — the same rendering as 'normal', but it remembers that this
     * field is one of the ones the completeness check watches. Without that
     * distinction the markup could not carry a hidden "Needs you" badge for
     * the live check to reveal when somebody clears the control again, and the
     * highlight would go back to being frozen at page load.
     *
     * @param string $field   Editor field name.
     * @param array  $owned   Adapter's owned_fields().
     * @param array  $manager Adapter's manager_fields().
     * @param int    $event_id
     * @return string 'locked' | 'attention' | 'filled' | 'normal'
     */
    private function field_state( $field, $owned, $manager, $event_id ) {
        if ( in_array( $field, $owned, true ) ) {
            return 'locked';
        }
        if ( in_array( $field, $manager, true ) ) {
            return SFAF_Sources::field_is_filled( $event_id, $field ) ? 'filled' : 'attention';
        }
        return 'normal';
    }

    /** The extra class a field wrapper carries for its state. */
    private function field_class( $state ) {
        if ( 'locked' === $state ) {
            return ' uc-field-locked';
        }
        if ( 'attention' === $state ) {
            return ' uc-field-attention';
        }
        return '';
    }

    /**
     * The attribute that hands a field wrapper to the live completeness check.
     *
     * Only on the fields that check watches, so the JavaScript has nothing to
     * guess at and an ordinary field is untouched.
     *
     * @param string $field
     * @param string $state
     * @return string
     */
    private function field_watch_attr( $field, $state ) {
        if ( 'attention' !== $state && 'filled' !== $state ) {
            return '';
        }
        return ' data-uc-field="' . esc_attr( $field ) . '"';
    }

    /**
     * The badge beside a field's label.
     *
     * NEVER COLOUR ALONE. Each state pairs its colour with an icon and with
     * words, so it survives colour blindness, greyscale printing and a
     * high-contrast theme. The absence of a badge is what "this is finished"
     * looks like — a green tick on every completed field would be noise.
     *
     * A manager-owned field that is already filled still renders its badge,
     * hidden. Nothing about that is visible until the live check un-hides it,
     * and it is what lets the check work in both directions: filling a field
     * clears the badge, emptying it again brings the badge back.
     *
     * @param string $state
     * @param string $label Platform name, for the locked wording.
     * @return string
     */
    private function field_badge( $state, $label ) {
        if ( 'locked' === $state ) {
            return '<span class="uc-field-flag uc-flag-locked">' . $this->icon_lock()
                . '<span>From ' . esc_html( $label ) . ' &middot; not editable</span></span>';
        }
        if ( 'attention' === $state || 'filled' === $state ) {
            return '<span class="uc-field-flag uc-flag-attention" data-uc-attention-badge'
                . ( 'filled' === $state ? ' hidden' : '' ) . '>' . $this->icon_needs()
                . '<span>Needs you</span></span>';
        }
        return '';
    }

    /** `disabled` for a locked control, and nothing otherwise. */
    private function field_disabled( $state ) {
        return ( 'locked' === $state ) ? ' disabled' : '';
    }

    /* =====================================================================
     * MANAGER-OWNED FIELDS: ONE RENDER, TWO SCREENS
     *
     * THE PROBLEM THIS SOLVES, STATED PLAINLY. The event editor and the
     * pending approval queue are two screens showing the same event. Until
     * 3.2.0 they were also two unrelated templates: the editor drew the
     * manager-owned controls inline, one at a time, scattered down a long
     * form, and the queue drew a read-only table with an amber pencil that
     * said something was missing without offering anywhere to put it. Adding
     * a field meant remembering both places. Nobody remembers both places.
     *
     * So there is now exactly one function that knows what a manager-owned
     * control looks like (render_manager_control()) and exactly one that
     * knows which controls an event has: manager_panel_fields(). Both screens
     * call render_manager_panel(), which is a loop over the second calling the
     * first. A field cannot exist on one screen and not the other, because
     * neither screen contains a list.
     *
     * WHAT MAKES A FIELD "MANAGER-OWNED". For an imported event, the adapter
     * says so: SFAF_Sources::manager_fields_for(). That declaration is already
     * what stops a fetch writing the field, so the editor asks for exactly
     * what the import refuses to supply, by construction. For a native event
     * there is no adapter and no fetch, so the same controls render without
     * badges: they are simply the event's own fields.
     *
     * THE SAVE HALF IS SHARED TOO. save_manager_fields_from_post() is called
     * by the full event save and by the queue's own small form, so the two
     * cannot disagree about what a submitted value means either.
     * ================================================================== */

    /**
     * The canonical order of manager-owned controls.
     *
     * Order lives here rather than in either screen, so both render them the
     * same way round.
     *
     * @return string[]
     */
    private function manager_field_order() {
        return array( 'image', 'description', 'category', 'organizer', 'fundraising_progress' );
    }

    /**
     * Everything the controls need, gathered once per event.
     *
     * @param WP_User $user
     * @param int     $event_id
     * @param string  $screen 'editor' or 'queue'.
     * @return array
     */
    private function manager_panel_context( $user, $event_id, $screen = 'editor' ) {
        $event_id = (int) $event_id;
        $prov     = $event_id
            ? SFAF_Sources::provenance( $event_id )
            : array( 'source' => '', 'label' => '', 'source_url' => '' );

        $owned   = ( $event_id && '' !== $prov['source'] ) ? SFAF_Sources::owned_fields_for( $prov['source'] ) : array();
        $manager = ( $event_id && '' !== $prov['source'] ) ? SFAF_Sources::manager_fields_for( $prov['source'] ) : array();
        $adapter = ( '' !== $prov['source'] ) ? SFAF_Sources::adapter( $prov['source'] ) : null;

        return array(
            'event_id' => $event_id,
            'post'     => $event_id ? get_post( $event_id ) : null,
            'user'     => $user,
            'role'     => self::get_role( $user->ID ),
            'screen'   => ( 'queue' === $screen ) ? 'queue' : 'editor',
            'prov'     => $prov,
            'owned'    => $owned,
            'manager'  => $manager,
            'note'     => $adapter ? (string) $adapter->manager_fields_note() : '',
            'cats'     => get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) ),
            'orgs'     => get_terms( array( 'taxonomy' => 'uc_organizer', 'hide_empty' => false ) ),
            'allowed'  => $this->allowed_categories( $user ),
            // Unique per event so several panels can sit on the queue screen
            // without two elements sharing an id.
            'uid'      => 'e' . $event_id,
        );
    }

    /**
     * Which manager-owned controls this event has.
     *
     * @param array $ctx From manager_panel_context().
     * @return string[]
     */
    private function manager_panel_fields( $ctx ) {
        $imported = ( '' !== $ctx['prov']['source'] );

        // An imported event asks its adapter. A native event has the same four
        // controls, because they are its ordinary fields; nothing is refusing
        // to write them, so nothing badges them either.
        $fields = $imported
            ? $ctx['manager']
            : array( 'image', 'description', 'category', 'organizer' );

        // The fundraising toggle governs a donate link, so it appears wherever
        // there is one and nowhere else, including a native event with a
        // campaign URL typed by hand. A control over nothing is worse than no
        // control: it invites a manager to set something with no effect.
        $has_donate = $ctx['event_id'] && '' !== (string) get_post_meta( $ctx['event_id'], '_uc_gofundme_url', true );
        $fields     = array_diff( $fields, array( 'fundraising_progress' ) );
        if ( $has_donate ) {
            $fields[] = 'fundraising_progress';
        }

        // Canonical order, and nothing this method does not recognise.
        $ordered = array();
        foreach ( $this->manager_field_order() as $field ) {
            if ( in_array( $field, $fields, true ) ) {
                $ordered[] = $field;
            }
        }
        return $ordered;
    }

    /**
     * Render the manager-owned controls this event has, from a chosen subset.
     *
     * THIS IS HOW THE BENTO LAYOUT AND THE QUEUE STAY THE SAME THING.
     *
     * The editor lays the controls out across several cards: the description
     * belongs with the title, the categories with the organizer, the image on
     * its own. The pending queue shows them as one stack. Those are two layouts,
     * and a layout is not something two screens can share.
     *
     * What they DO share, and what the 3.2.0 rule was actually about, is the
     * list of controls and the markup of each one. Neither screen holds a list:
     * both ask manager_panel_fields(), and both draw through
     * render_manager_control(). This method is the only addition, and all it
     * does is intersect that list with the controls one card has room for, in
     * the canonical order. A field added to the shared list still cannot appear
     * on one screen and not the other, because of the $rest call the editor
     * makes at the end of the form: anything no card claimed is rendered
     * anyway rather than silently dropped.
     *
     * @param array         $ctx   From manager_panel_context().
     * @param string[]|null $only  Field names this call may render; null for all.
     * @param string[]      $skip  Field names already rendered elsewhere.
     * @return string[] What this call actually rendered.
     */
    private function render_manager_fields( $ctx, $only = null, $skip = array() ) {
        $fields = $this->manager_fields_for_card( $this->manager_panel_fields( $ctx ), $only, $skip );
        foreach ( $fields as $field ) {
            $this->render_manager_control( $field, $ctx );
        }
        return $fields;
    }

    /**
     * Which of an event's manager-owned fields one card takes.
     *
     * Split out from the rendering so the placement rule is a pure function of
     * three lists and can be checked on its own. The rule it has to satisfy is
     * that across a whole form, with $skip accumulating, every field appears
     * exactly once: a card claiming nothing is fine, a field claimed twice or
     * dropped is not.
     *
     * @param string[]      $all   The event's fields, in canonical order.
     * @param string[]|null $only  What this card may take; null takes the rest.
     * @param string[]      $skip  Already taken by an earlier card.
     * @return string[]
     */
    private function manager_fields_for_card( $all, $only = null, $skip = array() ) {
        $out = array();
        foreach ( $all as $field ) {
            if ( in_array( $field, $skip, true ) ) {
                continue;
            }
            if ( null !== $only && ! in_array( $field, $only, true ) ) {
                continue;
            }
            $out[] = $field;
        }
        return $out;
    }

    /**
     * THE shared render. Both screens call this and neither has a list.
     *
     * @param array $ctx From manager_panel_context().
     */
    private function render_manager_panel( $ctx ) {
        $fields = $this->manager_panel_fields( $ctx );
        if ( empty( $fields ) ) {
            return;
        }

        $imported = ( '' !== $ctx['prov']['source'] );
        ?>
        <div class="uc-manager-panel" data-uc-manager-panel>
            <?php if ( 'queue' === $ctx['screen'] ) : ?>
                <p class="uc-help">
                    These are the fields
                    <?php echo $imported ? esc_html( $ctx['prov']['label'] ) . ' does not supply' : 'a person sets'; ?>.
                    They are the same controls as the event editor, on the same event: whatever is set here is set there.
                </p>
            <?php endif; ?>

            <?php foreach ( $fields as $field ) {
                $this->render_manager_control( $field, $ctx );
            } ?>
        </div>
        <?php
    }

    /**
     * One manager-owned control.
     *
     * Every branch renders the same input names the save routine reads, so a
     * control behaves identically wherever the panel is placed.
     *
     * @param string $field
     * @param array  $ctx
     */
    private function render_manager_control( $field, $ctx ) {
        $event_id = (int) $ctx['event_id'];
        $uid      = $ctx['uid'];
        $state    = $this->field_state( $field, $ctx['owned'], $ctx['manager'], $event_id );
        $note     = $ctx['note'];
        $label    = $ctx['prov']['label'];

        switch ( $field ) {

            case 'image':
                $thumb_id   = ( $event_id && has_post_thumbnail( $event_id ) ) ? get_post_thumbnail_id( $event_id ) : 0;
                $own_url    = $event_id ? get_post_meta( $event_id, '_uc_image_url', true ) : '';
                $img_source = $event_id ? sfaf_event_image_source( $event_id ) : 'none';
                $preview    = $event_id ? sfaf_event_image_url( $event_id ) : '';
                $src_labels = array( 'event' => 'Event-specific', 'source' => 'From source', 'series' => 'From series', 'remote' => 'Synced', 'none' => 'Placeholder' );
                $in_series  = $event_id && SFAF_Series::id_for_event( $event_id ) > 0;
                ?>
                <div class="uc-field uc-image-field<?php echo esc_attr( $this->field_class( $state ) ); ?>"<?php echo $this->field_watch_attr( 'image', $state ); ?>>
                    <span class="uc-field-label">Featured Image
                        <span class="uc-img-source-tag"><?php echo esc_html( isset( $src_labels[ $img_source ] ) ? $src_labels[ $img_source ] : $img_source ); ?></span>
                        <?php echo $this->field_badge( $state, $label ); ?>
                    </span>
                    <?php if ( ( 'attention' === $state || 'filled' === $state ) && '' !== $note ) : ?>
                        <p class="uc-field-note uc-field-note-attention" data-uc-attention-note<?php echo ( 'filled' === $state ) ? ' hidden' : ''; ?>><?php echo $this->icon_needs(); ?><span><?php echo esc_html( $note ); ?></span></p>
                    <?php endif; ?>
                    <input type="hidden" name="featured_image_id" id="uc-featured-image-id-<?php echo esc_attr( $uid ); ?>" data-uc-image-id value="<?php echo (int) $thumb_id; ?>" />
                    <div class="uc-image-preview" id="uc-image-preview-<?php echo esc_attr( $uid ); ?>" data-uc-image-preview<?php echo $preview ? '' : ' style="display:none;"'; ?>>
                        <img src="<?php echo esc_url( $preview ); ?>" alt="" data-uc-image-preview-img />
                    </div>
                    <?php if ( 'locked' === $state ) : ?>
                        <p class="uc-hint"><?php echo esc_html( $label ); ?> supplies this image and refreshes it on every fetch. Change it there and it follows through on the next fetch.</p>
                    <?php else : ?>
                        <div class="uc-image-buttons">
                            <button type="button" class="uc-btn uc-btn-sm uc-choose-image">Choose Image</button>
                            <button type="button" class="uc-btn uc-btn-sm uc-link-danger uc-remove-image"<?php echo ( 'event' === $img_source ) ? '' : ' style="display:none;"'; ?>>Remove</button>
                        </div>
                        <?php if ( 'event' === $img_source && $in_series ) : ?>
                            <label class="uc-check"><input type="checkbox" name="reset_series_image" value="1" /> Reset to series image</label>
                        <?php endif; ?>
                        <label class="uc-field uc-image-url-field">
                            <span class="uc-field-label">Or an image URL
                                <?php echo sfaf_help(
                                    'uc-help-imgurl-' . $uid,
                                    'A picture set here overrides the series image for this one date. The URL is the fallback: it is used only when no image has been chosen from the library, so pasting one never fights with a chosen file.',
                                    'the image URL'
                                ); ?>
                            </span>
                            <input type="url" name="image_url" id="uc-image-url-<?php echo esc_attr( $uid ); ?>" data-uc-image-url value="<?php echo esc_attr( $own_url ); ?>" placeholder="https://…/image.jpg" />
                        </label>
                        <?php
                        /*
                         * THE SPEC STAYS INLINE, AND IT IS THE ONE THING HERE
                         * THAT DOES.
                         *
                         * Card images are cropped to 16:9 and filled, so a
                         * portrait photograph loses its top and bottom and a
                         * group shot can lose the faces. This is the difference
                         * between cropping before uploading and finding out
                         * afterwards, it is one line, and it is read every time
                         * somebody picks a picture rather than once. Behind a
                         * "?" it would be read never.
                         */
                        ?>
                        <p class="uc-hint uc-hint-spec"><strong>1200 x 675 pixels, 16:9 landscape.</strong> Cards crop to this shape and fill it.</p>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'description':
                ?>
                <label class="uc-field<?php echo esc_attr( $this->field_class( $state ) ); ?>"<?php echo $this->field_watch_attr( 'description', $state ); ?>>
                    <span class="uc-field-label">Description <?php echo $this->field_badge( $state, $label ); ?></span>
                    <?php if ( ( 'attention' === $state || 'filled' === $state ) && '' !== $note ) : ?>
                        <span class="uc-field-note uc-field-note-attention" data-uc-attention-note<?php echo ( 'filled' === $state ) ? ' hidden' : ''; ?>><?php echo $this->icon_needs(); ?><span><?php echo esc_html( $note ); ?></span></span>
                    <?php endif; ?>
                    <textarea name="description" rows="8"<?php echo $this->field_disabled( $state ); ?>><?php echo esc_textarea( $ctx['post'] ? $ctx['post']->post_content : '' ); ?></textarea>
                </label>
                <?php
                break;

            case 'category':
                /*
                 * SEVERAL CATEGORIES, BECAUSE AN EVENT IS OFTEN SEVERAL THINGS.
                 *
                 * This was a single <select>, and the taxonomy never was: an
                 * event tagged both Support Groups and Workshops in the
                 * WordPress editor lost the second one the moment anybody
                 * pressed Save here, because the save wrote an array of one.
                 * The data model did not change for this; the control did.
                 *
                 * THE MARKER FIELD IS NOT DECORATION. A checkbox group with
                 * nothing ticked submits nothing at all, which is
                 * indistinguishable from "this screen did not carry the
                 * control", and the pending queue renders a different subset
                 * of controls from the editor. Without the marker, clearing
                 * every category would silently leave the old ones in place.
                 */
                $current = $event_id
                    ? array_map( 'intval', (array) wp_get_post_terms( $event_id, 'uc_event_category', array( 'fields' => 'ids' ) ) )
                    : array();
                ?>
                <?php
                /*
                 * CHIPS, BECAUSE THAT IS WHAT A CATEGORY LOOKS LIKE EVERYWHERE
                 * ELSE. On a card, on the event page and in the filter bar a
                 * category is a coloured pill. A two-column grid of checkboxes
                 * in the editor was the only place it was not, and with eight
                 * categories the labels wrapped mid-word.
                 *
                 * THE CHECKBOXES ARE STILL THE FORM. They are what posts, they
                 * are what a browser with no JavaScript shows, and the chips are
                 * a view of them: ticking a box adds a chip, a chip's × unticks
                 * the box. Nothing about the submitted data changed, so the save
                 * did not have to.
                 */
                ?>
                <div class="uc-field uc-cat-field<?php echo esc_attr( $this->field_class( $state ) ); ?>"<?php echo $this->field_watch_attr( 'category', $state ); ?>
                     data-uc-chips>
                    <span class="uc-field-label">Categories <?php echo $this->field_badge( $state, $label ); ?>
                        <?php echo sfaf_help(
                            'uc-help-cats-' . $uid,
                            'An event can be in several, and it appears under each of them in the filter bar. The first one alphabetically supplies the card color and the placeholder picture, so the order you see the chips in is the order that decides it.',
                            'categories'
                        ); ?>
                    </span>
                    <input type="hidden" name="uc_category_present" value="1" />

                    <?php // Where the script writes the chips. Empty and hidden
                          // until it does, so no-script sees only the list. ?>
                    <div class="uc-chips" data-uc-chips-list hidden></div>
                    <p class="uc-chips-empty" data-uc-chips-empty hidden>No categories chosen yet.</p>

                    <div class="uc-chips-add" data-uc-chips-add hidden>
                        <button type="button" class="uc-btn uc-btn-sm" data-uc-chips-toggle
                                aria-expanded="false">+ Add category</button>
                    </div>

                    <div class="uc-check-grid uc-cat-grid" data-uc-chips-source>
                        <?php if ( ! is_wp_error( $ctx['cats'] ) ) : foreach ( $ctx['cats'] as $c ) :
                            if ( 'contributor' === $ctx['role'] && ! empty( $ctx['allowed'] ) && ! in_array( $c->term_id, $ctx['allowed'], true ) ) { continue; } ?>
                            <label class="uc-check" data-uc-chip-label="<?php echo esc_attr( $c->name ); ?>">
                                <input type="checkbox" name="category[]" value="<?php echo (int) $c->term_id; ?>"
                                       data-uc-chip-color="<?php echo esc_attr( sfaf_category_color( $c->term_id ) ); ?>"
                                       <?php checked( in_array( (int) $c->term_id, $current, true ) ); ?> />
                                <?php echo esc_html( $c->name ); ?>
                            </label>
                        <?php endforeach; endif; ?>
                    </div>

                </div>
                <?php
                break;

            case 'organizer':
                $current = $event_id ? ( wp_get_post_terms( $event_id, 'uc_organizer', array( 'fields' => 'ids' ) ) ?: array( 0 ) )[0] : 0;
                ?>
                <label class="uc-field<?php echo esc_attr( $this->field_class( $state ) ); ?>"<?php echo $this->field_watch_attr( 'organizer', $state ); ?>>
                    <span class="uc-field-label">Organizer <?php echo $this->field_badge( $state, $label ); ?></span>
                    <select name="organizer">
                        <option value="0">None</option>
                        <?php if ( ! is_wp_error( $ctx['orgs'] ) ) : foreach ( $ctx['orgs'] as $o ) : ?>
                            <option value="<?php echo (int) $o->term_id; ?>" <?php selected( $current, $o->term_id ); ?>><?php echo esc_html( $o->name ); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </label>
                <?php
                break;

            case 'fundraising_progress':
                $on     = ( '1' === (string) get_post_meta( $event_id, sfaf_fundraising_progress_meta_key(), true ) );
                $goal   = (float) get_post_meta( $event_id, '_uc_gofundme_goal', true );
                $raised = get_post_meta( $event_id, '_uc_gofundme_raised', true );
                $has_r  = ( '' !== $raised && is_numeric( $raised ) );
                ?>
                <div class="uc-field uc-fund-field">
                    <span class="uc-field-label">Fundraising progress</span>
                    <?php // The hidden 0 is what makes an unticked box mean off
                          // rather than "not submitted". Both screens post this
                          // control on its own, so absent-means-unchanged would
                          // make the toggle impossible to switch back off. ?>
                    <input type="hidden" name="show_fund_progress" value="0" />
                    <label class="uc-check">
                        <input type="checkbox" name="show_fund_progress" value="1" <?php checked( $on ); ?> />
                        Show the raised and goal figures on this event
                    </label>
                    <p class="uc-hint">
                        Off by default. Nothing about the money appears anywhere until this is ticked, on this event.
                        <?php if ( $goal > 0 && $has_r ) : ?>
                            Currently $<?php echo esc_html( number_format( (float) $raised ) ); ?> raised of $<?php echo esc_html( number_format( $goal ) ); ?>.
                        <?php elseif ( $goal > 0 ) : ?>
                            There is a $<?php echo esc_html( number_format( $goal ) ); ?> goal but no raised figure yet, so ticking this shows nothing until one arrives. A goal on its own reads as zero raised.
                        <?php else : ?>
                            No goal has come from the campaign yet, so there is nothing to show.
                        <?php endif; ?>
                    </p>
                    <?php if ( '' !== $ctx['prov']['source'] ) : ?>
                        <p class="uc-hint">This choice is yours permanently. A fetch never changes it.</p>
                    <?php endif; ?>
                </div>
                <?php
                break;
        }
    }

    /**
     * Save the manager-owned fields for one event.
     *
     * Called by the full event save and by the queue's panel, so a submitted
     * value means the same thing on both screens.
     *
     * @param WP_User  $user
     * @param int      $event_id
     * @param callable $is_locked Takes a field name, returns whether the
     *                            platform owns it on this event.
     */
    private function save_manager_fields_from_post( $user, $event_id, $is_locked ) {
        $event_id = (int) $event_id;
        $role     = self::get_role( $user->ID );

        // Featured image: uploaded attachment wins, pasted URL is the fallback.
        // "Reset to series image" clears the event's own image so it inherits.
        //
        // Skipped entirely when the platform owns the image: those controls are
        // not rendered at all, so featured_image_id is absent and this would
        // read 0 and strip the thumbnail.
        if ( $is_locked( 'image' ) ) {
            // Nothing to do: the source's image lives in its own meta key.
        } elseif ( isset( $_POST['reset_series_image'] ) ) {
            delete_post_thumbnail( $event_id );
            delete_post_meta( $event_id, '_uc_image_url' );
            delete_post_meta( $event_id, '_uc_image_override' );
        } elseif ( isset( $_POST['featured_image_id'] ) || isset( $_POST['image_url'] ) ) {
            $thumb_id = isset( $_POST['featured_image_id'] ) ? intval( $_POST['featured_image_id'] ) : 0;
            if ( $thumb_id ) {
                set_post_thumbnail( $event_id, $thumb_id );
            } else {
                delete_post_thumbnail( $event_id );
            }
            if ( isset( $_POST['image_url'] ) ) {
                $img_url = esc_url_raw( wp_unslash( $_POST['image_url'] ) );
                if ( $img_url ) {
                    update_post_meta( $event_id, '_uc_image_url', $img_url );
                } else {
                    delete_post_meta( $event_id, '_uc_image_url' );
                }
            }
            // Flag a per-event image override so series image changes skip it.
            if ( has_post_thumbnail( $event_id ) || get_post_meta( $event_id, '_uc_image_url', true ) ) {
                update_post_meta( $event_id, '_uc_image_override', '1' );
            } else {
                delete_post_meta( $event_id, '_uc_image_override' );
            }
        }

        /*
         * CATEGORIES. Several, and only when this form actually carried the
         * control. See the marker note in render_manager_control().
         *
         * A contributor restricted to certain categories can neither add one
         * they are not allowed nor, just as importantly, REMOVE one they cannot
         * see: the categories already on the event that are outside their
         * allow-list are read back and kept. Otherwise a restricted contributor
         * editing an event would quietly strip every category they had not been
         * given, simply by saving.
         */
        if ( isset( $_POST['uc_category_present'] ) ) {
            $posted  = isset( $_POST['category'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['category'] ) ) : array();
            $allowed = $this->allowed_categories( $user );
            $limited = ( 'contributor' === $role && ! empty( $allowed ) );

            if ( $limited ) {
                $posted = array_values( array_intersect( $posted, array_map( 'intval', $allowed ) ) );
                foreach ( (array) wp_get_post_terms( $event_id, 'uc_event_category', array( 'fields' => 'ids' ) ) as $existing ) {
                    if ( ! in_array( (int) $existing, array_map( 'intval', $allowed ), true ) ) {
                        $posted[] = (int) $existing;
                    }
                }
            }

            $posted = array_values( array_unique( array_filter( $posted ) ) );
            wp_set_object_terms( $event_id, $posted, 'uc_event_category' );
        }
        if ( isset( $_POST['organizer'] ) ) {
            $org = intval( $_POST['organizer'] );
            wp_set_object_terms( $event_id, $org ? array( $org ) : array(), 'uc_organizer' );
        }

        /*
         * The fundraising toggle. Written only when the control was on the
         * form, so a screen that does not offer it cannot silently switch it
         * off. And because the control always posts a hidden 0 beside the
         * checkbox, a screen that DOES offer it always says which way.
         */
        if ( isset( $_POST['show_fund_progress'] ) ) {
            update_post_meta(
                $event_id,
                sfaf_fundraising_progress_meta_key(),
                ( '1' === (string) wp_unslash( $_POST['show_fund_progress'] ) ) ? '1' : '0'
            );
        }
    }

    /**
     * Save the manager-owned panel from the pending queue.
     *
     * The queue's panel posts only these fields, so this touches only these
     * fields: an event's title, date and everything else is left exactly as it
     * was. Same nonce discipline and same permission check as the editor.
     *
     * @param WP_User $user
     * @return int Event ID, or 0.
     */
    private function save_manager_panel_from_post( $user ) {
        $event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
        $post     = $event_id ? get_post( $event_id ) : null;

        if ( ! $post || 'uc_event' !== $post->post_type || ! $this->can_edit_event( $user, $post ) ) {
            wp_die( 'Denied' );
        }

        $src_slug  = (string) get_post_meta( $event_id, SFAF_Sources::META_SOURCE, true );
        $src_owned = ( '' !== $src_slug ) ? SFAF_Sources::owned_fields_for( $src_slug ) : array();
        $is_locked = function ( $field ) use ( $src_owned ) {
            return in_array( $field, $src_owned, true );
        };

        $this->save_manager_fields_from_post( $user, $event_id, $is_locked );
        return $event_id;
    }

    /**
     * Create or update a series.
     *
     * @param WP_User $user
     * @return int Term ID, or 0 on failure.
     */
    private function save_series_from_post( $user ) {
        if ( ! $this->can_view_all( $user ) ) {
            wp_die( 'Denied' );
        }
        $term_id = isset( $_POST['series_id'] ) ? intval( $_POST['series_id'] ) : 0;

        $args = array(
            'description' => wp_unslash( $_POST['series_desc'] ?? '' ),
            'image_id'    => intval( $_POST['series_image_id'] ?? 0 ),
            'image_url'   => wp_unslash( $_POST['series_image_url'] ?? '' ),
            'faq_set'     => sanitize_text_field( wp_unslash( $_POST['series_faq_set'] ?? '' ) ),
        );
        $name = wp_unslash( $_POST['series_name'] ?? '' );

        if ( $term_id ) {
            $result = SFAF_Series::update( $term_id, $name, $args );
            return is_wp_error( $result ) ? 0 : $term_id;
        }

        $created = SFAF_Series::create( $name, $args );
        return is_wp_error( $created ) ? 0 : (int) $created;
    }

    /**
     * The Series screen.
     *
     * ONE ROW PER SERIES, showing the name, how many upcoming events it holds
     * and its next date. No series ever appears on the Events screen and no
     * event ever appears here, and that is now structural rather than
     * something every query has to remember.
     */
    private function render_series_list( $user ) {
        $this->chrome_open( $user, 'series' );
        $series = SFAF_Series::all();
        ?>
        <div class="uc-page-head">
            <h1>Series &amp; Categories</h1>
        </div>

        <?php
        /*
         * TWO SECTIONS ON ONE PAGE, the same shape as Users and Permissions.
         *
         * Both answer "how is the programming organised": a series is one
         * event and all of its dates, a category is what KIND of event it is.
         * Neither is site configuration, which is the same reasoning that
         * moved venues out of the WordPress admin in 3.13.0 and series before
         * them. Managers work here; they do not open wp-admin.
         */
        ?>
        <section class="uc-section" id="uc-series">
            <div class="uc-section-head">
                <h2>Series</h2>
                <p class="uc-section-sub">An event and all of its dates, with the pattern it runs on.</p>
            </div>

        <p class="uc-help">
            <strong>A series is an event's schedule: the event, and all of its dates.</strong> Open one to see the
            pattern it runs on, change the day or the time for everything still to come, add a date, or take one off.
            <br />
            You do not normally create a series here: set a repeat on a new event and its series is made in the same
            step. Create one by hand when a program needs describing before any of its dates are known.
        </p>

        <div class="uc-card">
            <div class="uc-card-head">
                <?php // "series" is the same word either way, so no plural test. ?>
                <h2><?php echo count( $series ); ?> series</h2>
            </div>
            <?php if ( empty( $series ) ) : ?>
                <p class="uc-empty">No series yet. Set a repeat on a new event and one is made for it.</p>
            <?php else : ?>
                <table class="uc-table">
                    <thead><tr><th></th><th>Series</th><th>Schedule</th><th>Upcoming</th><th>Next date</th><th class="uc-col-actions">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ( $series as $term ) :
                        $edit  = $this->url( 'series/edit/' . $term->term_id );
                        $count = SFAF_Series::upcoming_count( $term->term_id );
                        $next  = SFAF_Series::next_date( $term->term_id );
                        $img   = SFAF_Series::image_url( $term->term_id, 'medium' );
                        ?>
                        <tr>
                            <td class="uc-series-thumb"><a href="<?php echo esc_url( $edit ); ?>"><?php
                                if ( $img ) {
                                    echo '<img src="' . esc_url( $img ) . '" alt="" />';
                                } else {
                                    echo '<span class="uc-series-thumb-none" aria-hidden="true">' . sfaf_icon( 'calendar', array( 'size' => '18px' ) ) . '</span>';
                                }
                            ?></a></td>
                            <td><a class="uc-tlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $term->name ); ?></strong></a></td>
                            <td><?php
                                /*
                                 * THE CADENCE, IN THE LIST. It has been stored
                                 * since 3.0.0 and shown nowhere, so a manager
                                 * scanning this screen could not tell a weekly
                                 * group from a one-off without opening it.
                                 */
                                $row_ids = SFAF_Series::events( $term->term_id, array( 'upcoming' => true, 'status' => SFAF_Series::editable_statuses(), 'limit' => 1 ) );
                                if ( empty( $row_ids ) ) {
                                    $row_ids = SFAF_Series::events( $term->term_id, array( 'status' => SFAF_Series::editable_statuses(), 'limit' => 1 ) );
                                }
                                if ( empty( $row_ids ) ) {
                                    echo '<span class="uc-muted">No dates yet</span>';
                                } else {
                                    $rid  = (int) $row_ids[0];
                                    $line = SFAF_Recurrence::schedule_sentence(
                                        SFAF_Recurrence::pattern_of( $rid ),
                                        (string) get_post_meta( $rid, '_uc_event_date', true ),
                                        (string) get_post_meta( $rid, '_uc_start_time', true ),
                                        (string) get_post_meta( $rid, '_uc_end_time', true )
                                    );
                                    echo esc_html( $line );
                                }
                            ?></td>
                            <td><?php echo (int) $count; ?></td>
                            <td><?php
                                // "No dates yet" rather than a dash. An empty
                                // series is a normal state, not a fault, and a
                                // dash makes a manager go looking for the fault.
                                echo $next
                                    ? esc_html( date_i18n( 'M j, Y', strtotime( $next ) ) )
                                    : '<span class="uc-muted">No dates yet</span>';
                            ?></td>
                            <td class="uc-row-actions">
                                <div class="uc-actions">
                                    <a class="uc-action-link" href="<?php echo esc_url( $edit ); ?>">Schedule</a>
                                    <a class="uc-action-link" href="<?php echo esc_url( SFAF_Series::url( $term->term_id ) ); ?>" target="_blank" rel="noopener">View</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php
            /*
             * "NEW SERIES" SITS AT THE BOTTOM, LIKE "CREATE A CATEGORY".
             *
             * It was in the page head, above both sections, which put the
             * rarest control on this screen in the most prominent place and
             * made the two halves of the page work differently: one section
             * offered its "add" at the top, the other at the foot of its list.
             * Both are now "here is what exists, and here is how to add one".
             */
            ?>
            <?php if ( $this->can_view_all( $user ) ) : ?>
                <div class="uc-cat-new">
                    <a href="<?php echo esc_url( $this->url( 'series/new' ) ); ?>" class="uc-btn uc-btn-sm">+ New series</a>
                </div>
            <?php endif; ?>
        </div>
        </section>

        <?php $this->render_categories( $user ); ?>
        <?php
        $this->chrome_close();
    }

    /**
     * Categories: what KIND of event this is, with the colour and icon that
     * follow from it.
     *
     * WHERE THIS COULD BE DONE BEFORE: only the WordPress admin, under Events >
     * Categories, and only the name could be set there. Colour was term meta
     * with no form field in the entire plugin, so the only thing that ever
     * wrote it was the sample-data seeder and every hand-made category was
     * permanently the default teal. Icon was not stored at all. See the header
     * of class-sfaf-categories.php for the third fault, in the placeholder.
     *
     * THE COLOUR PICKER IS THE PALETTE, not a colour input. Ten radios, each an
     * approved brand colour, because a free hex field is a way to put something
     * off-brand on the public calendar and the guide is explicit that the
     * palette is the palette. sfaf_sanitize_brand_color() enforces the same
     * thing on the way in, so a hand-written POST cannot get past it either.
     *
     * EDITING IS A MODE IN THE URL, exactly as the team rows are: no script
     * needed, and a rename in progress survives a reload.
     *
     * @param WP_User $user
     */
    private function render_categories( $user ) {
        $can_edit = $this->can_view_all( $user );
        $cats     = SFAF_Categories::all();
        $palette  = sfaf_brand_palette();
        $icons    = SFAF_Categories::icons();

        $editing  = isset( $_GET['cat_edit'] ) ? (int) $_GET['cat_edit'] : 0;
        $creating = ! empty( $_GET['cat_new'] );
        $base     = $this->url( 'series' );

        $err = get_transient( 'sfaf_category_error_' . $user->ID );
        if ( false !== $err ) {
            delete_transient( 'sfaf_category_error_' . $user->ID );
        }
        ?>
        <section class="uc-section" id="uc-categories">
            <div class="uc-section-head">
                <h2>Categories</h2>
                <p class="uc-section-sub">What kind of event this is. The color and icon are what a card and its placeholder are drawn from.</p>
            </div>

            <div class="uc-card">
                <div class="uc-card-head">
                    <h2><?php echo count( $cats ); ?> <?php echo esc_html( 1 === count( $cats ) ? 'category' : 'categories' ); ?></h2>
                </div>

                <?php if ( $err ) : ?>
                    <div class="uc-flash uc-flash-error"><?php echo esc_html( $err ); ?></div>
                <?php endif; ?>

                <p class="uc-hint">
                    An event can be in several. The first one alphabetically supplies the color of its card and the
                    picture shown when it has no image of its own, which is why every category has both.
                </p>

                <?php if ( empty( $cats ) ) : ?>
                    <p class="uc-empty">No categories yet. Create one below.</p>
                <?php else : ?>
                    <ul class="uc-cat-list">
                        <?php foreach ( $cats as $cat ) :
                            $cid   = (int) $cat->term_id;
                            $color = sfaf_category_color( $cid );
                            $icon  = SFAF_Categories::icon( $cid, $cat->name );
                            $used  = count( SFAF_Categories::events_using( $cid, -1 ) );
                            $open  = ( $editing === $cid );
                            ?>
                            <li class="uc-cat-row<?php echo $open ? ' uc-cat-row-open' : ''; ?>">
                                <?php if ( ! $open ) : ?>
                                    <span class="uc-cat-swatch" style="--cat: <?php echo esc_attr( $color ); ?>; --cat-ink: <?php echo esc_attr( sfaf_on_color( $color ) ); ?>;">
                                        <?php echo sfaf_icon( $icon, array( 'size' => '17px' ) ); ?>
                                    </span>
                                    <span class="uc-cat-id">
                                        <strong><?php echo esc_html( $cat->name ); ?></strong>
                                        <span class="uc-muted">
                                            <?php echo esc_html( $palette[ $color ] ); ?>,
                                            <?php echo esc_html( isset( $icons[ $icon ] ) ? strtolower( $icons[ $icon ] ) : $icon ); ?> icon,
                                            <?php echo (int) $used; ?> <?php echo esc_html( 1 === $used ? 'event' : 'events' ); ?>
                                        </span>
                                    </span>
                                    <?php if ( $can_edit ) : ?>
                                        <span class="uc-cat-actions">
                                            <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( add_query_arg( 'cat_edit', $cid, $base ) . '#uc-categories' ); ?>">Edit</a>
                                            <form method="post" action="<?php echo esc_url( $base ); ?>" class="uc-cat-delete"
                                                  onsubmit="return confirm('<?php echo esc_attr( sprintf(
                                                      'Delete the category "%s"? %d %s stay on the calendar and simply lose this category.',
                                                      $cat->name, $used, ( 1 === $used ? 'event will' : 'events will' )
                                                  ) ); ?>');">
                                                <input type="hidden" name="uc_action" value="delete_category" />
                                                <input type="hidden" name="category_id" value="<?php echo $cid; ?>" />
                                                <?php wp_nonce_field( 'uc_portal_delete_category', 'uc_nonce' ); ?>
                                                <button type="submit" class="uc-link-danger uc-btn-sm">Delete</button>
                                            </form>
                                        </span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <?php $this->render_category_form( $cat, $base ); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ( $can_edit ) : ?>
                    <div class="uc-cat-new">
                        <?php if ( ! $creating ) : ?>
                            <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( add_query_arg( 'cat_new', 1, $base ) . '#uc-categories' ); ?>">Create a category</a>
                        <?php else : ?>
                            <?php $this->render_category_form( null, $base ); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * One category's editor: its name, its colour and its icon.
     *
     * The same markup creates and edits, so a new category and an existing one
     * cannot offer different fields. $cat === null is the create case.
     *
     * @param WP_Term|null $cat
     * @param string       $base Return URL for this screen.
     */
    private function render_category_form( $cat, $base ) {
        $cid     = $cat ? (int) $cat->term_id : 0;
        $name    = $cat ? $cat->name : '';
        $color   = $cid ? sfaf_category_color( $cid ) : sfaf_default_category_color();
        $icon    = $cid ? SFAF_Categories::icon( $cid, $name ) : SFAF_Categories::default_icon();
        $palette = sfaf_brand_palette();
        $icons   = SFAF_Categories::icons();
        ?>
        <form method="post" action="<?php echo esc_url( $base ); ?>" class="uc-cat-form">
            <input type="hidden" name="uc_action" value="save_category" />
            <input type="hidden" name="category_id" value="<?php echo $cid; ?>" />
            <?php wp_nonce_field( 'uc_portal_save_category', 'uc_nonce' ); ?>

            <label class="uc-field">
                <span class="uc-field-label">Name</span>
                <input type="text" name="category_name" value="<?php echo esc_attr( $name ); ?>"
                       placeholder="e.g. Support Groups" required <?php echo $cat ? 'autofocus' : ''; ?> />
            </label>

            <div class="uc-field">
                <span class="uc-field-label">Color</span>
                <div class="uc-swatches" role="radiogroup" aria-label="Category colour">
                    <?php foreach ( $palette as $hex => $label ) : ?>
                        <label class="uc-swatch" style="--cat: <?php echo esc_attr( $hex ); ?>; --cat-ink: <?php echo esc_attr( sfaf_on_color( $hex ) ); ?>;">
                            <input type="radio" name="category_color" value="<?php echo esc_attr( $hex ); ?>"
                                   <?php checked( strtoupper( $color ), $hex ); ?> />
                            <span class="uc-swatch-dot" aria-hidden="true"></span>
                            <span class="uc-visually-hidden"><?php echo esc_html( $label ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <span class="uc-hint">The ten approved brand colors. Nothing outside them can be saved here.</span>
            </div>

            <label class="uc-field uc-cat-icon">
                <span class="uc-field-label">Icon</span>
                <select name="category_icon">
                    <?php foreach ( $icons as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $icon, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="uc-hint">Drawn on an event that has no picture of its own, which is most imported ones.</span>
            </label>

            <div class="uc-cat-form-actions">
                <button type="submit" class="uc-btn uc-btn-sm uc-btn-primary"><?php echo $cat ? 'Save category' : 'Create category'; ?></button>
                <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( $base . '#uc-categories' ); ?>">Cancel</a>
            </div>
        </form>
        <?php
    }

    /**
     * Create or edit one series.
     *
     * WHAT A SERIES CARRIES: a name, a description, an image and a default FAQ
     * set. It deliberately no longer holds a default time, location, category
     * or organizer. Those were the template every occurrence was generated
     * from, and there is no generation from a series any more — a series may
     * hold different kinds of event, so a "default category" would have been
     * actively wrong rather than merely unused.
     *
     * @param WP_User $user
     * @param int     $term_id 0 to create.
     */
    private function render_series_edit( $user, $term_id ) {
        if ( ! $this->can_view_all( $user ) ) {
            $this->chrome_open( $user, 'series' );
            echo '<div class="uc-card"><p class="uc-empty">You don\'t have permission to manage series.</p></div>';
            $this->chrome_close();
            return;
        }
        $term = $term_id ? SFAF_Series::get( $term_id ) : null;
        if ( $term_id && ! $term ) {
            $this->chrome_open( $user, 'series' );
            echo '<div class="uc-card"><p class="uc-empty">Series not found.</p></div>';
            $this->chrome_close();
            return;
        }

        $img_id  = $term_id ? (int) get_term_meta( $term_id, SFAF_Series::META_IMAGE_ID, true ) : 0;
        $img_url = $term_id ? (string) get_term_meta( $term_id, SFAF_Series::META_IMAGE_URL, true ) : '';
        $preview = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : $img_url;
        $set     = $term_id ? SFAF_Series::default_faq_set( $term_id ) : '';
        $sets    = SFAF_FAQ_Sets::all();

        $this->load_media = true;
        wp_enqueue_media();

        $this->chrome_open( $user, 'series' );
        ?>
        <div class="uc-page-head">
            <h1><?php echo $term ? esc_html( $term->name ) : 'New Series'; ?></h1>
            <a href="<?php echo esc_url( $this->url( 'series' ) ); ?>" class="uc-btn">&larr; Back</a>
        </div>
        <?php if ( $term ) : ?>
            <p class="uc-help">This event and all of its dates. The schedule is below the series details.</p>
        <?php else : ?>
            <p class="uc-help">
                A series made by hand, for a program that needs describing before its dates are known. If you already
                know the dates, create the event instead and set a repeat on it: the series is made from the event's own
                name in the same step.
            </p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( $this->url( $term_id ? 'series/edit/' . $term_id : 'series/new' ) ); ?>" class="uc-form">
            <input type="hidden" name="uc_action" value="save_series" />
            <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
            <?php wp_nonce_field( 'uc_portal_save_series', 'uc_nonce' ); ?>

            <div class="uc-card">
                <div class="uc-card-head"><h2>Series details</h2></div>
                <label class="uc-field">
                    <span class="uc-field-label">Series name</span>
                    <input type="text" name="series_name" value="<?php echo esc_attr( $term ? $term->name : '' ); ?>" required />
                </label>

                <div class="uc-field uc-image-field">
                    <span class="uc-field-label">Image</span>
                    <input type="hidden" name="series_image_id" id="uc-featured-image-id" value="<?php echo (int) $img_id; ?>" />
                    <div class="uc-image-preview" id="uc-image-preview"<?php echo $preview ? '' : ' style="display:none;"'; ?>>
                        <img src="<?php echo esc_url( $preview ); ?>" alt="" id="uc-image-preview-img" />
                    </div>
                    <div class="uc-image-buttons">
                        <button type="button" class="uc-btn uc-btn-sm uc-choose-image">Choose Image</button>
                        <button type="button" class="uc-btn uc-btn-sm uc-link-danger uc-remove-image"<?php echo $preview ? '' : ' style="display:none;"'; ?>>Remove</button>
                    </div>
                    <label class="uc-field uc-image-url-field">Or enter image URL
                        <input type="url" name="series_image_url" id="uc-image-url" value="<?php echo esc_attr( $img_url ); ?>" placeholder="https://…/image.jpg" />
                    </label>
                    <p class="uc-hint">Shown on the series page, and used by any event in the series with no image of its own.</p>
                    <p class="uc-hint"><strong>Best size: 1200 x 675 pixels (16:9 landscape).</strong> Event cards crop
                        to this shape and fill it, so anything taller loses its top and bottom.</p>
                </div>

                <label class="uc-field">
                    <span class="uc-field-label">Description</span>
                    <textarea name="series_desc" rows="6"><?php echo esc_textarea( $term ? $term->description : '' ); ?></textarea>
                    <span class="uc-hint">What this series is. Shown on the series page, including while it has no dates scheduled.</span>
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">Default FAQ set</span>
                    <select name="series_faq_set">
                        <option value="">None</option>
                        <?php foreach ( $sets as $s ) : ?>
                            <option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $set, $s['id'] ); ?>>
                                <?php echo esc_html( $s['name'] ); ?> (<?php echo (int) count( $s['rows'] ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="uc-hint">
                        Copied onto each event created into this series, so nobody has to remember to pick it. The rows
                        become that event's own and can be edited or cleared. Existing events are not changed.
                    </span>
                </label>
            </div>

            <div class="uc-form-actions">
                <button type="submit" class="uc-btn uc-btn-primary"><?php echo $term ? 'Save Series' : 'Create Series'; ?></button>
            </div>
        </form>

        <?php if ( $term ) : ?>
            <?php
            // Outside the form: this posts on its own, and HTML forms cannot
            // nest. Same reasoning as the FAQ set and refresh panels.
            $this->render_schedule( $term_id );
            ?>

            <?php
            /*
             * A BUTTON, AND NOTHING ELSE. 3.18.0.
             *
             * There was a card here, with a heading and a paragraph explaining
             * that removal asks before it acts. The paragraph described the
             * next screen instead of letting the reader get to it: everything
             * it said, the two options and the real counts, is on that screen,
             * stated better, with the numbers filled in. Explaining a
             * confirmation in front of the control that opens it is a longer
             * road to the same place.
             *
             * A LINK, NOT A SUBMIT. This used to post straight through to
             * deletion behind a browser confirm(), which is one stray Return
             * key away from acting. It opens render_series_remove(), which is
             * where the choice is actually made.
             */
            ?>
            <div class="uc-series-remove-row">
                <a class="uc-btn uc-btn-danger" href="<?php echo esc_url( $this->url( 'series/remove/' . (int) $term_id ) ); ?>">Remove series</a>
            </div>
        <?php endif; ?>
        <?php
        $this->chrome_close();
    }

    /**
     * "Remove this series": the question, with the real numbers in it.
     *
     * WHY THIS IS A SCREEN AND NOT A confirm(). Removing a series used to act
     * on the click, behind a browser dialog that said the events were safe.
     * They are not necessarily safe, because there is a genuine second thing
     * somebody might mean: the group is finished and its remaining dates
     * should go too. Those are different actions with different consequences
     * and no dialog can offer a sub-choice, name two counts and explain what
     * happens to a term's description and image in a line of text.
     *
     * THE NUMBERS ARE READ HERE AND POSTED NOWHERE. The form carries the
     * series and the choice; the counts are computed again inside
     * SFAF_Series::remove(), off the same queries, so a page left open for an
     * hour cannot delete a number of events that was true when it loaded.
     *
     * @param WP_User $user
     * @param int     $term_id
     */
    private function render_series_remove( $user, $term_id ) {
        if ( ! $this->can_view_all( $user ) ) {
            $this->render_dashboard( $user );
            return;
        }

        $term_id = (int) $term_id;
        $term    = SFAF_Series::get( $term_id );
        $this->chrome_open( $user, 'series' );

        if ( ! $term ) {
            echo '<div class="uc-card"><p class="uc-empty">That series no longer exists.</p></div>';
            $this->chrome_close();
            return;
        }

        $upcoming = SFAF_Series::upcoming_count( $term_id );
        $past     = SFAF_Series::past_count( $term_id );
        $others   = array();
        foreach ( SFAF_Series::all() as $s ) {
            if ( (int) $s->term_id !== $term_id ) {
                $others[] = $s;
            }
        }

        // What lives on the term itself and therefore goes with it. Named
        // specifically when it is actually set, so the warning is about this
        // series rather than about series in general.
        $has_desc  = ( '' !== trim( (string) $term->description ) );
        $has_image = ( '' !== (string) SFAF_Series::image_url( $term_id ) );
        $faq_set   = SFAF_Series::default_faq_set( $term_id );
        $has_faq   = ! empty( $faq_set );
        ?>
        <div class="uc-page-head">
            <h1>Remove <?php echo esc_html( $term->name ); ?></h1>
            <a href="<?php echo esc_url( $this->url( 'series/edit/' . $term_id ) ); ?>" class="uc-btn">&larr; Back to the series</a>
        </div>

        <div class="uc-card uc-card-danger">
            <div class="uc-card-head"><h2>What happens to the events</h2></div>
            <p class="uc-remove-lead">
                This series holds
                <strong><?php echo (int) $upcoming; ?> upcoming <?php echo esc_html( 1 === $upcoming ? 'event' : 'events' ); ?></strong>
                and
                <strong><?php echo (int) $past; ?> past <?php echo esc_html( 1 === $past ? 'event' : 'events' ); ?></strong>.
                Neither choice can be undone.
            </p>

            <form method="post" action="<?php echo esc_url( $this->url( 'series' ) ); ?>" class="uc-remove-form">
                <input type="hidden" name="uc_action" value="remove_series" />
                <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
                <?php wp_nonce_field( 'uc_portal_remove_series', 'uc_nonce' ); ?>

                <div class="uc-remove-options">

                    <label class="uc-remove-option">
                        <input type="radio" name="remove_mode" value="keep_events" checked />
                        <span class="uc-remove-body">
                            <strong>Remove the series, keep its events</strong>
                            <span class="uc-hint">
                                All <?php echo (int) ( $upcoming + $past ); ?>
                                <?php echo esc_html( 1 === ( $upcoming + $past ) ? 'event stays' : 'events stay' ); ?>
                                on the calendar. Only the grouping goes.
                            </span>

                            <span class="uc-remove-sub">
                                <span class="uc-remove-sub-label">And then:</span>
                                <label class="uc-radio-row">
                                    <input type="radio" name="keep_target" value="0" checked />
                                    <span>Leave them unassigned</span>
                                </label>
                                <?php if ( ! empty( $others ) ) : ?>
                                    <label class="uc-radio-row">
                                        <input type="radio" name="keep_target" value="move" />
                                        <span>Move them to another series</span>
                                    </label>
                                    <?php // The series being removed is not in
                                          // this list, and the server checks
                                          // that again before acting. ?>
                                    <label class="uc-field uc-remove-picker">
                                        <span class="uc-visually-hidden">Which series to move them to</span>
                                        <select name="keep_target_series">
                                            <?php foreach ( $others as $s ) : ?>
                                                <option value="<?php echo (int) $s->term_id; ?>"><?php echo esc_html( $s->name ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php else : ?>
                                    <span class="uc-hint">There is no other series to move them to.</span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </label>

                    <label class="uc-remove-option uc-remove-option-danger">
                        <input type="radio" name="remove_mode" value="delete_events" />
                        <span class="uc-remove-body">
                            <strong>Remove the series and its events</strong>
                            <span class="uc-hint">
                                <strong><?php echo (int) $upcoming; ?> upcoming
                                <?php echo esc_html( 1 === $upcoming ? 'event is deleted' : 'events are deleted' ); ?></strong>
                                and moved to the trash.
                                <strong><?php echo (int) $past; ?> past
                                <?php echo esc_html( 1 === $past ? 'event remains' : 'events remain' ); ?></strong>
                                on the calendar as standalone past events.
                            </span>
                        </span>
                    </label>

                </div>

                <?php
                /*
                 * ONE LINE, AND ONLY WHEN THERE IS SOMETHING TO LOSE.
                 *
                 * This was a yellow callout explaining that the description and
                 * image live on the series rather than on the events, and a
                 * paragraph on why registration records outlive their events.
                 * Both were true and neither told somebody standing at this
                 * decision what to do or what would happen to them: one
                 * justified where the data lives, the other justified a design
                 * choice made two releases ago. The fact that survives is that
                 * these things go, so it is stated and nothing else.
                 *
                 * Registrations are not mentioned at all now. Nothing on this
                 * screen touches them, and a reassurance about something that
                 * is not at risk is one more thing to read before deciding.
                 */
                $goes = array();
                if ( $has_desc )  { $goes[] = 'description'; }
                if ( $has_image ) { $goes[] = 'image'; }
                if ( $has_faq )   { $goes[] = 'default FAQ set'; }
                ?>
                <?php if ( ! empty( $goes ) ) : ?>
                    <p class="uc-hint uc-remove-goes">
                        The series' own
                        <?php echo esc_html(
                            ( 1 === count( $goes ) )
                                ? $goes[0]
                                : implode( ', ', array_slice( $goes, 0, -1 ) ) . ' and ' . $goes[ count( $goes ) - 1 ]
                        ); ?>
                        <?php echo esc_html( 1 === count( $goes ) ? 'goes with it.' : 'go with it.' ); ?>
                    </p>
                <?php endif; ?>

                <div class="uc-form-actions">
                    <a class="uc-btn" href="<?php echo esc_url( $this->url( 'series/edit/' . $term_id ) ); ?>">Cancel</a>
                    <button type="submit" class="uc-btn uc-btn-danger">Remove <?php echo esc_html( $term->name ); ?></button>
                </div>
            </form>
        </div>
        <?php
        $this->chrome_close();
    }
    /* =====================================================================
     * Venues
     *
     * WHERE EVENTS HAPPEN, MANAGED WHERE EVENTS ARE MANAGED.
     *
     * The venue taxonomy has existed since the beginning with a WordPress admin
     * screen and no way at all to pick one while creating an event, so the
     * Location field was free text every single time and the same building got
     * typed six different ways. The screen is here now and the WordPress one is
     * gone: scheduling is not site configuration, and the WordPress screen could
     * not hold an address anyway, which is the thing a venue is for.
     *
     * THE ADDRESS LIVES HERE AND ONLY HERE. An event refers to a venue; it does
     * not copy the address. Correcting a suite number on this screen corrects
     * every event held there at once. See class-sfaf-venues.php.
     * ================================================================== */

    private function render_venues( $user ) {
        if ( ! $this->can_view_all( $user ) ) {
            $this->render_dashboard( $user );
            return;
        }
        $this->chrome_open( $user, 'venues' );

        $venues = SFAF_Venues::all();
        $err    = get_transient( 'sfaf_venue_error_' . $user->ID );
        $blocked= get_transient( 'sfaf_venue_blocked_' . $user->ID );
        if ( false !== $err ) {
            delete_transient( 'sfaf_venue_error_' . $user->ID );
        }
        if ( false !== $blocked ) {
            delete_transient( 'sfaf_venue_blocked_' . $user->ID );
        }
        ?>
        <div class="uc-page-head"><h1>Venues</h1></div>

        <p class="uc-help">
            The places events are held, with their addresses. Correcting an address here corrects every event held
            there, including ones already published. An event somewhere one-off takes a location typed onto it instead.
        </p>

        <?php if ( $err ) : ?>
            <div class="uc-flash uc-flash-error">
                <?php echo esc_html( $err ); ?>
                <?php if ( is_array( $blocked ) && ! empty( $blocked ) ) : ?>
                    <ul class="uc-team-blocked">
                        <?php foreach ( $blocked as $ev ) : ?>
                            <li>
                                <a href="<?php echo esc_url( $this->url( 'events/edit/' . (int) $ev['id'] ) ); ?>"><?php echo esc_html( $ev['title'] ); ?></a>
                                <span class="uc-muted">(<?php echo esc_html( sfaf_status_label( $ev['status'] ) ); ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="uc-card">
            <div class="uc-card-head"><h2>Venues (<?php echo count( $venues ); ?>)</h2></div>
            <?php if ( empty( $venues ) ) : ?>
                <p class="uc-empty">No venues yet. Add the first one below, then pick it on any event.</p>
            <?php else : ?>
                <?php foreach ( $venues as $venue ) : $this->render_venue_form( $venue ); ?><?php endforeach; ?>
            <?php endif; ?>

            <details class="uc-venue-new">
                <summary>Add a venue</summary>
                <?php $this->render_venue_form( null ); ?>
            </details>
        </div>
        <?php
        $this->chrome_close();
    }

    /**
     * One venue's editor. The same markup creates and edits, so a new venue and
     * an existing one cannot offer different fields.
     *
     * @param WP_Term|null $venue
     */
    private function render_venue_form( $venue ) {
        $id      = $venue ? (int) $venue->term_id : 0;
        $name    = $venue ? $venue->name : '';
        $address = $id ? SFAF_Venues::address( $id ) : '';
        $parts   = $id ? SFAF_Venues::parts( $id ) : array( 'street' => '', 'city' => '', 'state' => '', 'zip' => '' );
        $count   = $id ? count( SFAF_Venues::events_using( $id ) ) : 0;
        ?>
        <div class="uc-venue">
            <form method="post" action="<?php echo esc_url( $this->url( 'venues' ) ); ?>" class="uc-venue-form">
                <input type="hidden" name="uc_action" value="save_venue" />
                <input type="hidden" name="venue_id" value="<?php echo (int) $id; ?>" />
                <?php wp_nonce_field( 'uc_portal_save_venue', 'uc_nonce' ); ?>

                <?php
                /*
                 * FOUR FIELDS, NOT ONE LINE.
                 *
                 * One free-text box made "470 Castro St, San Francisco, CA
                 * 94114" and "470 Castro" the same field, so nothing could
                 * tell a complete address from half of one and correcting a
                 * city meant retyping the string. What everything else reads
                 * is still one line: SFAF_Venues::save() composes these four
                 * into it on every save, which is why no reader had to change.
                 */
                ?>
                <div class="uc-venue-grid">
                    <label class="uc-field uc-venue-name">
                        <span class="uc-field-label">Name</span>
                        <input type="text" name="venue_name" value="<?php echo esc_attr( $name ); ?>" placeholder="e.g. Strut" required />
                    </label>
                    <label class="uc-field uc-venue-street">
                        <span class="uc-field-label">Street</span>
                        <input type="text" name="venue_street" value="<?php echo esc_attr( $parts['street'] ); ?>" placeholder="470 Castro St" />
                    </label>
                    <label class="uc-field uc-venue-city">
                        <span class="uc-field-label">City</span>
                        <input type="text" name="venue_city" value="<?php echo esc_attr( $parts['city'] ); ?>" placeholder="San Francisco" />
                    </label>
                    <label class="uc-field uc-venue-state">
                        <span class="uc-field-label">State</span>
                        <input type="text" name="venue_state" value="<?php echo esc_attr( $parts['state'] ); ?>" placeholder="CA" maxlength="20" />
                    </label>
                    <label class="uc-field uc-venue-zip">
                        <span class="uc-field-label">ZIP</span>
                        <input type="text" name="venue_zip" value="<?php echo esc_attr( $parts['zip'] ); ?>" placeholder="94114" maxlength="10" />
                    </label>
                </div>
                <?php if ( $id && '' !== $address ) : ?>
                    <p class="uc-hint uc-venue-composed">
                        Events here show: <strong><?php echo esc_html( $address ); ?></strong>
                    </p>
                <?php endif; ?>

                <div class="uc-venue-actions">
                    <button class="uc-btn uc-btn-sm uc-btn-primary" type="submit"><?php echo $venue ? 'Save venue' : 'Add venue'; ?></button>
                    <?php if ( $venue ) : ?>
                        <span class="uc-muted">
                            <?php echo (int) $count; ?> <?php echo esc_html( _n( 'event', 'events', $count ) ); ?> here
                        </span>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ( $venue ) : ?>
                <form method="post" action="<?php echo esc_url( $this->url( 'venues' ) ); ?>" class="uc-venue-delete">
                    <input type="hidden" name="uc_action" value="delete_venue" />
                    <input type="hidden" name="venue_id" value="<?php echo (int) $id; ?>" />
                    <?php wp_nonce_field( 'uc_portal_delete_venue', 'uc_nonce' ); ?>
                    <button type="submit" class="uc-link-danger uc-btn-sm"
                            data-uc-confirm="Delete the venue &quot;<?php echo esc_attr( $name ); ?>&quot;? This is refused while any event is held there.">Delete</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =====================================================================
     * Schedule writes
     * ================================================================== */

    /**
     * Change the day, the time, or both, across a group's upcoming dates.
     *
     * THE GROUP IS RE-DERIVED FROM THE SERIES, never taken from the form. The
     * form carries a series id and nothing else that decides what is written, so
     * a tampered POST can only ever aim this at a series the person is already
     * allowed to edit, at the same set of upcoming events the screen showed
     * them.
     *
     * THE PATTERN META IS LEFT ALONE. A weekly group moved from Wednesdays to
     * Tuesdays is still weekly; what changed is which day, and that is recorded
     * where it has always been recorded, in the dates themselves.
     */
    private function schedule_pattern_from_post() {
        $term_id = isset( $_POST['series_id'] ) ? intval( $_POST['series_id'] ) : 0;
        if ( ! $term_id || ! SFAF_Series::exists( $term_id ) ) {
            $this->redirect( 'series', array( 'msg' => 'series_failed' ) );
        }

        $group = SFAF_Series::recurrence_group( $term_id );
        if ( '' === $group ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_no_group' ) );
        }

        $ids = SFAF_Recurrence::upcoming_in_group( $group );
        if ( empty( $ids ) ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_nothing_upcoming' ) );
        }

        // Imported events are not ours to reschedule. Checked here as well as in
        // the markup: the control is not rendered for them, and this is what
        // makes that a rule rather than an appearance.
        $prov = SFAF_Sources::provenance( $ids[0] );
        if ( '' !== $prov['source'] ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_imported' ) );
        }

        $moved = 0;
        if ( isset( $_POST['weekday'] ) && '' !== $_POST['weekday'] ) {
            $result = SFAF_Recurrence::reday_group( $group, intval( $_POST['weekday'] ) );
            $moved  = (int) $result['moved'];
        }

        $start   = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
        $end     = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
        $retimed = ( '' !== $start ) ? SFAF_Recurrence::retime_group( $group, $start, $end ) : 0;

        $written = max( $moved, $retimed );
        $this->redirect( 'series/edit/' . $term_id, array(
            'msg'     => $written ? 'schedule_updated' : 'schedule_unchanged',
            'written' => $written,
        ) );
    }

    /**
     * Add one date to a series.
     */
    private function schedule_add_date_from_post() {
        $term_id = isset( $_POST['series_id'] ) ? intval( $_POST['series_id'] ) : 0;
        if ( ! $term_id || ! SFAF_Series::exists( $term_id ) ) {
            $this->redirect( 'series', array( 'msg' => 'series_failed' ) );
        }

        /*
         * THE SEED IS THE NEXT OCCURRENCE, or the most recent one when there is
         * nothing upcoming. The next one is what the event looks like NOW:
         * current location, current times, current capacity, rather than what
         * it looked like when the group was first set up.
         */
        $upcoming = SFAF_Series::events( $term_id, array( 'upcoming' => true, 'status' => SFAF_Series::editable_statuses(), 'limit' => 1 ) );
        $seed_id  = ! empty( $upcoming ) ? (int) $upcoming[0] : 0;
        if ( ! $seed_id ) {
            $all     = SFAF_Series::events( $term_id, array( 'status' => SFAF_Series::editable_statuses(), 'limit' => -1 ) );
            $seed_id = ! empty( $all ) ? (int) end( $all ) : 0;
        }
        if ( ! $seed_id ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_no_seed' ) );
        }

        $prov = SFAF_Sources::provenance( $seed_id );
        if ( '' !== $prov['source'] ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_imported' ) );
        }

        $new_id = SFAF_Recurrence::add_occurrence(
            $seed_id,
            isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '',
            isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '',
            isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '',
            empty( $_POST['independent'] )
        );

        if ( is_wp_error( $new_id ) ) {
            set_transient( 'sfaf_schedule_error_' . get_current_user_id(), $new_id->get_error_message(), 60 );
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_add_failed' ) );
        }

        $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_added', 'event' => (int) $new_id ) );
    }

    /**
     * Take one date off the schedule.
     *
     * TRASHED, NOT ERASED, which is the same thing "Delete" does everywhere else
     * in this portal. The date comes off the calendar immediately and nothing
     * regenerates it: recurrence has not been a template since 3.0.0, so there
     * is no pattern re-run on save that could notice a gap and fill it back in.
     * That is what makes removing a holiday stick, and it is why the old
     * cancelled-dates list no longer exists.
     *
     * REGISTRATIONS ARE NOT TOUCHED. RSVP rows live in their own table keyed by
     * event id and nothing here writes to it. They stay reachable, and if the
     * event is ever purged for good SFAF_RSVP::snapshot_event_title() has
     * already recorded which event they were for.
     *
     * A PAST DATE CANNOT BE REMOVED FROM HERE. The row carries no button, and
     * this refuses one anyway.
     */
    private function schedule_remove_date_from_post() {
        $term_id  = isset( $_POST['series_id'] ) ? intval( $_POST['series_id'] ) : 0;
        $event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;

        if ( ! $term_id || ! SFAF_Series::exists( $term_id ) ) {
            $this->redirect( 'series', array( 'msg' => 'series_failed' ) );
        }

        $post = $event_id ? get_post( $event_id ) : null;
        $in   = $event_id ? in_array(
            $event_id,
            SFAF_Series::events( $term_id, array( 'status' => SFAF_Series::editable_statuses(), 'limit' => -1 ) ),
            true
        ) : false;

        if ( ! $post || 'uc_event' !== $post->post_type || ! $in ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_remove_failed' ) );
        }

        $date = (string) get_post_meta( $event_id, '_uc_event_date', true );
        if ( '' === $date || $date < current_time( 'Y-m-d' ) ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_past' ) );
        }

        wp_trash_post( $event_id );
        $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_removed' ) );
    }

    /* =====================================================================
     * The schedule
     *
     * THIS SCREEN IS "THIS EVENT, ALL ITS DATES", NOT "A SEPARATE OBJECT".
     *
     * Everything a manager needs to do to a repeating event's dates happens
     * here, because until now none of it happened anywhere. The pattern has been
     * stored since 3.0.0 and shown nowhere after creation, so a group's cadence
     * was invisible; there was no way at all to add a date to an existing group,
     * which meant extending a term's programme by three weeks was three
     * hand-built events that no bulk edit could reach.
     *
     * THE PAST IS SHOWN AND IS NOT EDITABLE. It is the record of sessions that
     * happened, in front of people who attended them, and every write below is
     * bounded by SFAF_Recurrence::upcoming_in_group(), which is "today or later"
     * at the query. Past rows carry no controls, and there is no route from this
     * screen that reaches one even if the markup were tampered with.
     *
     * THE PATTERN EDIT TARGETS THE RECURRENCE GROUP, which is what "edit all
     * upcoming occurrences" in the event editor has always targeted. Same
     * marker, same "today or later" bound, same idea of what a set of
     * occurrences is. There is no second grouping here to disagree with it.
     * ================================================================== */

    /**
     * A series' dates: the pattern, what is coming, what has been, and the four
     * things a manager can do about it.
     *
     * @param int $term_id
     */
    private function render_schedule( $term_id ) {
        $term_id  = (int) $term_id;
        $group    = SFAF_Series::recurrence_group( $term_id );
        $all_ids  = SFAF_Series::events( $term_id, array( 'status' => SFAF_Series::editable_statuses(), 'limit' => -1 ) );
        $today    = current_time( 'Y-m-d' );

        $upcoming = array();
        $past     = array();
        foreach ( $all_ids as $eid ) {
            $d = (string) get_post_meta( $eid, '_uc_event_date', true );
            if ( '' !== $d && $d >= $today ) {
                $upcoming[] = $eid;
            } else {
                $past[] = $eid;
            }
        }
        // Most recent first: the last session held is the one anybody scrolling
        // to the past section is actually looking for.
        $past = array_reverse( $past );

        /*
         * THE SEED IS WHAT A NEW DATE IS COPIED FROM, and it is the next
         * occurrence rather than the first. The next one is the current shape of
         * the event: its location, its times and its capacity as they are now,
         * not as they were when the group was set up in March.
         */
        $seed_id = ! empty( $upcoming ) ? $upcoming[0] : ( ! empty( $past ) ? $past[0] : 0 );

        /*
         * IMPORTED EVENTS HAVE NO SCHEDULE OF OURS TO EDIT. Recurrence has been
         * refused on them since 2.9.0 because the platform decides whether its
         * own event repeats and every fetch brings that shape back. Offering an
         * editable pattern here would be offering to make a change the next
         * fetch quietly undoes.
         */
        $prov     = $seed_id ? SFAF_Sources::provenance( $seed_id ) : array( 'source' => '', 'label' => '' );
        $imported = ( '' !== $prov['source'] );

        $pattern  = $seed_id ? SFAF_Recurrence::pattern_of( $seed_id ) : '';
        $start    = $seed_id ? (string) get_post_meta( $seed_id, '_uc_start_time', true ) : '';
        $end      = $seed_id ? (string) get_post_meta( $seed_id, '_uc_end_time', true ) : '';
        $anchor   = $seed_id ? (string) get_post_meta( $seed_id, '_uc_event_date', true ) : '';
        $sentence = SFAF_Recurrence::schedule_sentence( $pattern, $anchor, $start, $end );

        $in_group = ( '' !== $group ) ? SFAF_Recurrence::upcoming_in_group( $group ) : array();
        $movable  = SFAF_Recurrence::weekday_is_movable( $pattern );
        $days     = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
        $cur_dow  = $anchor ? (int) date( 'w', strtotime( $anchor . ' 12:00:00' ) ) : -1;
        $back     = 'series/edit/' . $term_id;
        ?>
        <div class="uc-card uc-schedule">
            <div class="uc-card-head">
                <h3>Schedule</h3>
                <?php if ( ! empty( $all_ids ) ) : ?>
                    <span class="uc-muted">
                        <?php echo (int) count( $upcoming ); ?> upcoming,
                        <?php echo (int) count( $past ); ?> past
                    </span>
                <?php endif; ?>
            </div>

            <?php if ( empty( $all_ids ) ) : ?>
                <p class="uc-hint">
                    No dates yet. Create an event and choose this series on it, or set a repeat on a new event, which
                    makes its dates and its series in one step.
                </p>
            <?php else : ?>

                <p class="uc-schedule-sentence"><?php echo esc_html( $sentence ); ?></p>

                <?php if ( $imported ) : ?>
                    <p class="uc-hint">
                        <?php echo $this->icon_lock(); ?>
                        These dates come from <?php echo esc_html( $prov['label'] ? $prov['label'] : 'another platform' ); ?>,
                        which decides when this event happens. Changing the pattern here would be undone by the next
                        fetch, so it is not offered.
                    </p>
                <?php elseif ( '' === $group ) : ?>
                    <p class="uc-hint">
                        These dates are not on a repeating pattern, so there is no pattern to change. Each one can still
                        be edited on its own, and a date can be added below.
                    </p>
                <?php else : ?>

                    <details class="uc-schedule-edit">
                        <summary>Change the pattern</summary>
                        <p class="uc-hint">
                            Applies to the <strong><?php echo (int) count( $in_group ); ?></strong>
                            upcoming <?php echo esc_html( _n( 'occurrence', 'occurrences', count( $in_group ) ) ); ?>
                            in this group. Dates that have already been are the record of what happened and are never
                            rewritten.
                        </p>
                        <form method="post" action="<?php echo esc_url( $this->url( $back ) ); ?>" class="uc-form">
                            <input type="hidden" name="uc_action" value="schedule_pattern" />
                            <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
                            <?php wp_nonce_field( 'uc_portal_schedule_pattern', 'uc_nonce' ); ?>

                            <div class="uc-field-row">
                                <?php if ( $movable ) : ?>
                                    <label class="uc-field">
                                        <span class="uc-field-label">Day</span>
                                        <select name="weekday">
                                            <?php foreach ( $days as $i => $day ) : ?>
                                                <option value="<?php echo (int) $i; ?>" <?php selected( $cur_dow, $i ); ?>><?php echo esc_html( $day ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php endif; ?>
                                <label class="uc-field">
                                    <span class="uc-field-label">Start time</span>
                                    <input type="time" name="start_time" value="<?php echo esc_attr( $start ); ?>" />
                                </label>
                                <label class="uc-field">
                                    <span class="uc-field-label">End time</span>
                                    <input type="time" name="end_time" value="<?php echo esc_attr( $end ); ?>" />
                                </label>
                            </div>

                            <?php if ( ! $movable ) : ?>
                                <p class="uc-hint">
                                    <?php if ( 'daily' === $pattern ) : ?>
                                        This happens every day, so there is no weekday to move it to. The time can still
                                        be changed here, and a single date can be moved from its own event.
                                    <?php else : ?>
                                        This repeats on a day of the month rather than a day of the week, so moving it to
                                        a weekday would turn it into a different pattern from the one it was set up with.
                                        The time can still be changed here, and a single date can be moved from its own
                                        event.
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>

                            <div class="uc-form-actions">
                                <button type="submit" class="uc-btn uc-btn-primary"
                                        data-uc-confirm="Update <?php echo (int) count( $in_group ); ?> upcoming <?php echo esc_attr( _n( 'occurrence', 'occurrences', count( $in_group ) ) ); ?>? Dates that have already been are not touched.">
                                    Update <?php echo (int) count( $in_group ); ?> upcoming
                                    <?php echo esc_html( _n( 'occurrence', 'occurrences', count( $in_group ) ) ); ?>
                                </button>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>

                <h4 class="uc-schedule-head">Upcoming</h4>
                <?php if ( empty( $upcoming ) ) : ?>
                    <p class="uc-empty">Nothing upcoming. Add a date below.</p>
                <?php else : ?>
                    <ul class="uc-schedule-list">
                        <?php foreach ( $upcoming as $eid ) : $this->render_schedule_row( $eid, $term_id, true, $group ); ?><?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ( ! empty( $past ) ) : ?>
                    <?php
                    /*
                     * THE PAST IS FOLDED AWAY, AND IT STARTS FOLDED.
                     *
                     * A weekly group two years old has a hundred past dates and
                     * four upcoming ones, and this screen exists to answer "what
                     * is coming up". Listing the whole history above that answer
                     * buries it, and the list only ever grows.
                     *
                     * FOLDED, NOT DROPPED. Nothing here can be edited from this
                     * screen anyway, so what is behind the summary is a record
                     * rather than a control; and the Events list already has an
                     * Archived view that holds all of it, which the summary
                     * points at. A <details> keeps it one click away with no
                     * script involved.
                     */
                    ?>
                    <details class="uc-schedule-pastfold">
                        <summary>
                            <?php echo (int) count( $past ); ?> past
                            <?php echo esc_html( _n( 'date', 'dates', count( $past ) ) ); ?>
                        </summary>
                        <p class="uc-hint">
                            What already happened. Nothing here can be changed from this screen.
                            <a href="<?php echo esc_url( add_query_arg( 'view', 'archived', $this->url( 'events' ) ) ); ?>">See them in the Archived view</a>.
                        </p>
                        <ul class="uc-schedule-list uc-schedule-past">
                            <?php foreach ( array_slice( $past, 0, 50 ) as $eid ) : $this->render_schedule_row( $eid, $term_id, false, $group ); ?><?php endforeach; ?>
                        </ul>
                        <?php if ( count( $past ) > 50 ) : ?>
                            <p class="uc-hint">Showing the 50 most recent of <?php echo (int) count( $past ); ?>.</p>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( ! $imported && $seed_id ) : ?>
                <details class="uc-schedule-add">
                    <summary>Add a date</summary>
                    <p class="uc-hint">
                        Creates one more event exactly like the others: same title, description, location, times,
                        capacity, categories and series. It starts with nobody registered, because registrations belong
                        to the date they were made for.
                    </p>
                    <form method="post" action="<?php echo esc_url( $this->url( $back ) ); ?>" class="uc-form">
                        <input type="hidden" name="uc_action" value="schedule_add_date" />
                        <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
                        <?php wp_nonce_field( 'uc_portal_schedule_add_date', 'uc_nonce' ); ?>

                        <div class="uc-field-row">
                            <label class="uc-field">
                                <span class="uc-field-label">Date</span>
                                <input type="date" name="date" required />
                            </label>
                            <label class="uc-field">
                                <span class="uc-field-label">Start time</span>
                                <input type="time" name="start_time" value="<?php echo esc_attr( $start ); ?>" />
                            </label>
                            <label class="uc-field">
                                <span class="uc-field-label">End time</span>
                                <input type="time" name="end_time" value="<?php echo esc_attr( $end ); ?>" />
                            </label>
                        </div>

                        <?php if ( '' !== $group ) : ?>
                            <label class="uc-check">
                                <input type="checkbox" name="independent" value="1" />
                                Keep this date out of the group
                            </label>
                            <p class="uc-hint">
                                Left unticked, the new date joins the group, so "update all upcoming occurrences"
                                reaches it like any other. Tick it for a genuine one-off, a special session or a
                                different venue for one week, that should not be rewritten by a bulk edit.
                            </p>
                        <?php endif; ?>

                        <div class="uc-form-actions">
                            <button type="submit" class="uc-btn uc-btn-primary">Add this date</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * One row of the schedule.
     *
     * A PAST ROW CARRIES NO CONTROLS AT ALL, rather than disabled ones. A
     * greyed-out pencil invites somebody to work out how to enable it; nothing
     * there says the matter is closed.
     *
     * @param int    $eid
     * @param int    $term_id
     * @param bool   $editable
     * @param string $group
     */
    private function render_schedule_row( $eid, $term_id, $editable, $group ) {
        $date  = (string) get_post_meta( $eid, '_uc_event_date', true );
        $start = (string) get_post_meta( $eid, '_uc_start_time', true );
        $end   = (string) get_post_meta( $eid, '_uc_end_time', true );
        $ts    = $date ? strtotime( $date . ' 12:00:00' ) : 0;
        $rsvps = sfaf_get_rsvp_count( $eid );
        $solo  = ( '' !== $group && SFAF_Recurrence::group_of( $eid ) !== $group );
        ?>
        <li class="uc-schedule-row">
            <span class="uc-schedule-date"><?php echo $ts ? esc_html( date_i18n( 'D, M j, Y', $ts ) ) : '<span class="uc-muted">No date</span>'; ?></span>
            <span class="uc-schedule-time"><?php echo esc_html( SFAF_Recurrence::time_phrase( $start, $end ) ); ?></span>
            <span class="uc-schedule-meta">
                <?php echo esc_html( sfaf_status_label( get_post_status( $eid ) ) ); ?>
                <?php if ( $rsvps > 0 ) : ?>
                    &middot; <?php echo (int) $rsvps; ?> <?php echo esc_html( _n( 'RSVP', 'RSVPs', $rsvps ) ); ?>
                <?php endif; ?>
                <?php if ( $solo ) : ?>
                    <?php // Said out loud, because it is why a bulk edit will skip it. ?>
                    &middot; <span class="uc-muted">one-off</span>
                <?php endif; ?>
            </span>
            <?php if ( $editable ) : ?>
                <span class="uc-schedule-actions">
                    <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( $this->url( 'events/edit/' . $eid ) ); ?>"
                       title="Edit this date" aria-label="Edit <?php echo esc_attr( $date ); ?>"><?php echo sfaf_icon( 'pencil', array( 'size' => '15px' ) ); ?></a>
                    <form method="post" action="<?php echo esc_url( $this->url( 'series/edit/' . $term_id ) ); ?>" class="uc-schedule-remove">
                        <input type="hidden" name="uc_action" value="schedule_remove_date" />
                        <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
                        <input type="hidden" name="event_id" value="<?php echo (int) $eid; ?>" />
                        <?php wp_nonce_field( 'uc_portal_schedule_remove_date', 'uc_nonce' ); ?>
                        <button type="submit" class="uc-link-danger uc-btn-sm"
                                data-uc-confirm="Remove <?php echo esc_attr( $ts ? date_i18n( 'D, M j, Y', $ts ) : 'this date' ); ?> from the schedule?<?php echo $rsvps > 0 ? ' ' . (int) $rsvps . ' people have registered; their registrations are kept and stay in the RSVP list.' : ''; ?> Nothing puts it back.">Remove</button>
                    </form>
                </span>
            <?php endif; ?>
        </li>
        <?php
    }

    /* =====================================================================
     * Rendering — event form
     * ================================================================== */

    private function render_event_form( $user, $event_id ) {
        $post = $event_id ? get_post( $event_id ) : null;
        if ( $event_id && ( ! $post || $post->post_type !== 'uc_event' || ! $this->can_edit_event( $user, $post ) ) ) {
            $this->chrome_open( $user, 'events' );
            echo '<div class="uc-card"><p class="uc-empty">Event not found or you don\'t have permission to edit it.</p></div>';
            $this->chrome_close();
            return;
        }

        $g = function( $key, $default = '' ) use ( $event_id ) {
            return $event_id ? get_post_meta( $event_id, $key, true ) : $default;
        };
        $role = self::get_role( $user->ID );
        // The category and organizer lists, the current selections and the
        // contributor restriction are gathered by manager_panel_context() now,
        // because the controls that need them live in the shared panel.

        // Load the WP media library for the image picker on this page.
        $this->load_media = true;
        wp_enqueue_media();

        $this->chrome_open( $user, 'events' );
        ?>
        <div class="uc-page-head">
            <h1><?php echo $event_id ? 'Edit Event' : 'New Event'; ?></h1>
            <a href="<?php echo esc_url( $this->url( 'events' ) ); ?>" class="uc-btn">&larr; Back</a>
        </div>

        <?php
        /*
         * WHAT THIS EVENT IS PART OF — two different facts, said separately.
         *
         * A SERIES is an umbrella and has no bearing on how this save behaves:
         * it may hold an educational session one week and a social the next, so
         * a series-wide edit would be wrong and is not offered.
         *
         * A RECURRENCE GROUP is the set of events generated together from one
         * pattern, identical by default, and IS what a bulk edit targets. See
         * the header of class-sfaf-recurrence.php.
         */
        $series_term  = $event_id ? SFAF_Series::for_event( $event_id ) : null;
        $bulk_targets = $event_id ? SFAF_Recurrence::bulk_targets( $event_id ) : array();
        $has_bulk     = $event_id && count( $bulk_targets ) > 1;
        $bulk_locked  = $has_bulk ? SFAF_Recurrence::bulk_locked_fields( $bulk_targets ) : array();
        ?>
        <?php if ( $series_term ) : ?>
            <div class="uc-flash uc-flash-info">
                Part of the series <strong><?php echo esc_html( $series_term->name ); ?></strong>.
                A series groups events for browsing and filtering; it does not change what saving this event does.
                <a href="<?php echo esc_url( $this->url( 'series/edit/' . $series_term->term_id ) ); ?>">Manage the series &rarr;</a>
            </div>
        <?php endif; ?>

        <?php
        // Imported events: say where this came from, link back to it, and be
        // explicit about which fields belong to the platform and which are
        // ours to set.
        $prov = $event_id ? SFAF_Sources::provenance( $event_id ) : array( 'source' => '', 'label' => '', 'source_url' => '' );

        // The adapter's own declaration drives everything below: which fields
        // are locked, which are waiting for a person, and the sentence
        // explaining why. Nothing here names a platform or a field.
        $owned      = ( $event_id && '' !== $prov['source'] ) ? SFAF_Sources::owned_fields_for( $prov['source'] ) : array();
        $mgr_fields = ( $event_id && '' !== $prov['source'] ) ? SFAF_Sources::manager_fields_for( $prov['source'] ) : array();
        $adapter    = ( '' !== $prov['source'] ) ? SFAF_Sources::adapter( $prov['source'] ) : null;
        $mgr_note   = $adapter ? (string) $adapter->manager_fields_note() : '';
        $missing    = $event_id ? SFAF_Sources::missing_manager_fields( $event_id ) : array();
        $st         = function ( $field ) use ( $owned, $mgr_fields, $event_id ) {
            return $this->field_state( $field, $owned, $mgr_fields, $event_id );
        };

        if ( $event_id && '' !== $prov['source'] ) : ?>
            <div class="uc-flash uc-flash-info uc-import-banner">
                <span class="uc-source-badge"><?php echo esc_html( $prov['label'] ); ?></span>
                Imported from <?php echo esc_html( $prov['label'] ); ?>. Fields marked
                <?php echo $this->icon_lock(); ?> <strong>not editable</strong> are kept in step with <?php echo esc_html( $prov['label'] ); ?> and are overwritten on every fetch. Change those at the source.
                Set the <strong>category, organizer and series</strong> below, then press Publish to put it on the calendar.
                <?php if ( $prov['source_url'] ) : ?>
                    <a href="<?php echo esc_url( $prov['source_url'] ); ?>" target="_blank" rel="noopener noreferrer">Edit on <?php echo esc_html( $prov['label'] ); ?> &nearr;</a>
                <?php endif; ?>
            </div>

            <?php // What this platform cannot supply, said once, up front, with
                  // the source page one click away so filling it in is copy and
                  // paste rather than a hunt.
                  //
                  // ALWAYS RENDERED, hidden when nothing is missing, so the
                  // live check has something to write into. It used to be
                  // rendered only when something was missing, which meant the
                  // banner could not appear or disappear while the manager
                  // worked — the whole reason filling the image and the
                  // description left the warning standing. ?>
            <div class="uc-flash uc-flash-attention" data-uc-missing-banner<?php echo empty( $missing ) ? ' hidden' : ''; ?>>
                <?php echo $this->icon_needs(); ?>
                <strong>This event still needs <span data-uc-missing-text><?php echo esc_html( SFAF_Sources::field_phrase( $missing ) ); ?></span>.</strong>
                <?php if ( '' !== $mgr_note ) : ?>
                    <?php echo esc_html( $mgr_note ); ?>
                <?php endif; ?>
                <?php if ( $prov['source_url'] ) : ?>
                    <a class="uc-btn uc-btn-sm uc-btn-source" href="<?php echo esc_url( $prov['source_url'] ); ?>" target="_blank" rel="noopener noreferrer">Open the campaign page &nearr;</a>
                <?php endif; ?>
            </div>

            <?php
            // "Removed at source" is a draft this plugin made, not a human one.
            $removed_at = (int) get_post_meta( $event_id, '_uc_source_removed_at', true );
            if ( $removed_at ) :
                $removed_why = (string) get_post_meta( $event_id, '_uc_source_removed_reason', true );
                ?>
                <div class="uc-flash uc-flash-warn">
                    This event is no longer at <?php echo esc_html( $prov['label'] ); ?>
                    (<?php echo esc_html( 'ended' === $removed_why ? 'the campaign is no longer active there' : 'it stopped being returned by the source' ); ?>,
                    <?php echo esc_html( human_time_diff( $removed_at, time() ) ); ?> ago),
                    so it was taken off the calendar and kept as a draft. Publish it again if it should be live.
                </div>
            <?php endif; ?>

            <?php $this->render_refresh_panel( $user, $event_id, $prov ); ?>
        <?php endif; ?>

        <?php
        // FAQ sets. Above the form, not inside it: forms cannot nest and these
        // post and redirect on their own. Only on a saved event, since there
        // are no stored FAQs to save or apply to before that.
        if ( $event_id ) {
            $this->render_faq_set_panel( $user, $event_id );
            // The form the FAQ card's "Save these as a set" control belongs to.
            // Out here because forms cannot nest; referenced from in there by
            // id. See render_faq_save_as_set().
            $this->render_faq_set_create_form( $event_id );
        }
        ?>

        <form method="post" action="<?php echo esc_url( $this->url( $event_id ? 'events/edit/' . $event_id : 'events/new' ) ); ?>" class="uc-form">
            <input type="hidden" name="uc_action" value="save_event" />
            <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
            <?php wp_nonce_field( 'uc_portal_save_event', 'uc_nonce' ); ?>

            <?php $this->render_scope_header( $event_id, $bulk_targets, $bulk_locked ); ?>

            <?php
            /*
             * THE LOCK.
             *
             * Everything editable lives inside this fieldset, and on an event
             * with a scope choice it is rendered `disabled` — which is the
             * point: nothing can be typed, and nothing can be saved, before the
             * manager has decided how the save will apply. Choosing a scope
             * enables it. The Save and Publish buttons are inside it too, so
             * "locked" means locked rather than "editable but unsaveable".
             *
             * Rendered disabled in the HTML rather than switched off by script,
             * so the lock is real from the moment the page arrives rather than
             * from whenever the JavaScript happens to run.
             */
            ?>
            <fieldset class="uc-scope-fields"<?php echo $has_bulk ? ' data-uc-scope-fields disabled' : ''; ?>>

            <?php
            /*
             * THE EDITOR: A MAIN COLUMN AND A SIDE COLUMN.
             *
             * Every group of fields is a card that says what it is. Before the
             * cards existed the whole editor was one column of identically
             * weighted labels: the title, the fundraising toggle and the
             * reply-to address all looked equally important, and finding the
             * date meant reading everything above it.
             *
             * TWO COLUMNS, TWO THIRDS AND ONE THIRD. Six equal columns were
             * tried and read as a scatter, because a card's width said nothing
             * about what the card was for; CSS multi-column was tried before
             * that and filled the first column top to bottom, leaving the page
             * beside it empty.
             *
             * The split is by WHAT A CARD IS, not by how tall it happens to be.
             * The main column is the event itself: what it is, how it is found,
             * when, where, who hears about it, and what people ask. Every card
             * in it is full width and no card there ever sits beside another,
             * so the eye goes straight down. The side column is secondary
             * settings that are read rarely and changed rarely: capacity, the
             * donate link, the organizer's address, which features show.
             *
             * THE SIDE COLUMN IS RENDERED FIRST AND HELD IN A BUFFER, because
             * it comes second in the document and one of its cards claims a
             * manager-owned field. The "Other details" catch-all has to be the
             * LAST render_manager_fields() call of the form or it would sweep
             * up a field a later card was going to draw. Rendering the side
             * column into a string first makes the PHP order right while
             * leaving the document order right. See render_manager_fields().
             *
             * WHAT IS SHARED WITH THE PENDING QUEUE, AND WHAT IS NOT. The cards
             * are not: they are a layout, and the queue's layout is a stack under
             * one heading. The CONTROLS are, and always were: both screens draw
             * every manager-owned field through render_manager_control(), and
             * neither holds a list of which fields exist. See
             * render_manager_fields() for how the same list is split across
             * cards without either screen learning the list.
             *
             * LOCKED FIELDS HAD TO SURVIVE THIS. An imported event disables its
             * platform-owned controls and marks empty manager-owned ones amber,
             * and a card of three disabled inputs has to read as deliberate. So
             * the state classes are unchanged and applied per field exactly as
             * before, a card whose every control is locked says so in its
             * header, and no card is built out of fields that can only be locked
             * together.
             */
            $mgr_ctx = $this->manager_panel_context( $user, $event_id, 'editor' );
            $placed  = array();

            // ---- THE SIDE COLUMN, built now and printed further down. ------
            ob_start();
            ?>
                <?php // ---- Capacity: how many, and whether to take names. -- ?>
                <section class="uc-bento-card">
                    <h2 class="uc-bento-title">Capacity</h2>
                    <?php
                    /*
                     * DRAWN FROM THE SHARED RSVP SETTINGS, not written out
                     * here. The registrations screen offers the same four
                     * controls so nobody has to come back to the editor to
                     * change a capacity, and neither screen holds a list of
                     * what they are. Same rule as the manager-owned fields.
                     */
                    $rsvp_ctx    = $this->rsvp_settings_context( $user, $event_id );
                    $rsvp_placed = $this->render_rsvp_settings( $rsvp_ctx, array( 'rsvp_enabled', 'capacity' ) );
                    ?>
                </section>

                <?php
                // ---- Donate ------------------------------------------------
                // The Donate URL is the campaign link on an imported campaign
                // and a fetch writes it, so it locks with source_url rather than
                // looking editable and being replaced on the next run.
                $s_url = $st( 'source_url' );
                ?>
                <section class="uc-bento-card">
                    <h2 class="uc-bento-title">Donate</h2>
                    <label class="uc-field<?php echo esc_attr( $this->field_class( $s_url ) ); ?>">
                        <span class="uc-field-label">GoFundMe URL <?php echo $this->field_badge( $s_url, $prov['label'] ); ?></span>
                        <input type="url" name="gofundme_url" value="<?php echo esc_attr( $g( '_uc_gofundme_url' ) ); ?>" placeholder="https://gofund.me/…"<?php echo $this->field_disabled( $s_url ); ?> />
                    </label>
                    <?php $placed = array_merge( $placed, $this->render_manager_fields( $mgr_ctx, array( 'fundraising_progress' ), $placed ) ); ?>
                </section>

                <?php
                /*
                 * ORGANIZER CONTACT IS GONE, AND NOTHING WAS LOST WITH IT.
                 *
                 * It held two controls, an address and "Email on new RSVP",
                 * which together are one setting: who gets told when somebody
                 * registers. Split across a card of their own, three cards away
                 * from Notifications, they read as a card with no purpose. Both
                 * are now the first thing in the Notifications card, which is
                 * where that question is asked. See render_notify_box().
                 */
                ?>
                <section class="uc-bento-card">
                    <h2 class="uc-bento-title">Display</h2>
                    <?php
                    $feat = array( 'show_rsvp' => 'RSVP', 'show_donate' => 'Donate', 'show_social' => 'Social share', 'show_calendar' => 'Add to calendar', 'show_reminders' => 'Reminders' );
                    foreach ( $feat as $f => $lbl ) :
                        $on = $event_id ? sfaf_show_feature( $event_id, str_replace( 'show_', '', $f ) ) : true; ?>
                        <label class="uc-check"><input type="checkbox" name="<?php echo esc_attr( $f ); ?>" value="1" <?php checked( $on ); ?> /> <?php echo esc_html( $lbl ); ?></label>
                    <?php endforeach; ?>
                </section>
            <?php
            $side_html = ob_get_clean();
            ?>
            <div class="uc-bento">
            <div class="uc-bento-main">

                <?php
                /*
                 * ---- EVENT DETAILS: what this event IS. Full width, first. ----
                 *
                 * Title, description and picture are the three things a person
                 * writes about the event itself, so they are one card and it is
                 * the first one. "Basics" said nothing; this names its contents.
                 *
                 * THE IMAGE LIVES HERE NOW rather than in a card of its own two
                 * thirds of the way down the page, where it was found last and
                 * chosen last. Two calls, because the canonical field order is
                 * image before description and the writing order is the other
                 * way round; $placed carries across them exactly as before, so
                 * neither field can be claimed twice.
                 */
                ?>
                <section class="uc-bento-card">
                    <h2 class="uc-bento-title">Event details</h2>
                    <?php $s_title = $st( 'title' ); ?>
                    <label class="uc-field<?php echo esc_attr( $this->field_class( $s_title ) ); ?>"<?php echo $this->field_watch_attr( 'title', $s_title ); ?>>
                        <span class="uc-field-label">Title <?php echo $this->field_badge( $s_title, $prov['label'] ); ?></span>
                        <input type="text" name="title" value="<?php echo esc_attr( $post ? $post->post_title : '' ); ?>"<?php echo $this->field_disabled( $s_title ); ?> <?php echo ( 'locked' === $s_title ) ? '' : 'required'; ?> />
                    </label>
                    <?php $placed = array_merge( $placed, $this->render_manager_fields( $mgr_ctx, array( 'description' ), $placed ) ); ?>
                    <?php $placed = array_merge( $placed, $this->render_manager_fields( $mgr_ctx, array( 'image' ), $placed ) ); ?>
                </section>

                <?php
                // ---- FAQs, full width: the rows and BOTH set controls. ----
                //
                // The platform that owns this event's FAQ rows, if any. Read
                // from the adapter, exactly like every other locked field.
                $faq_source = array(
                    'locked' => in_array( 'faqs', $owned, true ),
                    'label'  => $prov['label'],
                    'url'    => $prov['source_url'],
                );
                ?>
                <section class="uc-bento-card uc-faq-card">
                    <div class="uc-bento-head">
                        <h2 class="uc-bento-title">FAQs</h2>
                        <?php
                        /*
                         * BOTH SET CONTROLS, HERE, WHERE THE QUESTIONS ARE.
                         *
                         * 3.3.0 moved "apply a set" into this block and left
                         * "save these as a set" on a panel above the form, so
                         * the manager writing FAQs had no way to save them from
                         * where they were working. A control that exists
                         * somewhere else is missing.
                         */
                        $this->render_faq_save_as_set( $event_id );
                        ?>
                    </div>
                    <p class="uc-hint">
                        Frequently asked questions for this event. These are its own: there is no series block above
                        them and nothing overrides them.
                    </p>
                    <?php $this->faq_set_picker(); ?>
                    <?php $this->faq_repeater( 'uc_faqs', $event_id ? sfaf_get_faqs( $event_id ) : array(), $faq_source ); ?>
                </section>

                <?php // ---- Classification: how it is found. --------------- ?>
                <section class="uc-bento-card">
                    <h2 class="uc-bento-title">Classification</h2>
                    <?php $placed = array_merge( $placed, $this->render_manager_fields( $mgr_ctx, array( 'category', 'organizer' ), $placed ) ); ?>
                </section>

                <?php // ---- Schedule: when, and where its other dates live. - ?>
                <section class="uc-bento-card">
                    <h2 class="uc-bento-title">Schedule</h2>
                    <?php
                    $s_date  = $st( 'date' );
                    $s_start = $st( 'start_time' );
                    $s_end   = $st( 'end_time' );
                    ?>
                    <label class="uc-field<?php echo esc_attr( $this->field_class( $s_date ) ); ?>"<?php echo $this->field_watch_attr( 'date', $s_date ); ?>>
                        <span class="uc-field-label">Date <?php echo $this->field_badge( $s_date, $prov['label'] ); ?></span>
                        <input type="date" name="date" value="<?php echo esc_attr( $g( '_uc_event_date' ) ); ?>"<?php echo $this->field_disabled( $s_date ); ?> />
                    </label>
                    <div class="uc-field-row">
                        <label class="uc-field<?php echo esc_attr( $this->field_class( $s_start ) ); ?>">
                            <span class="uc-field-label">Start <?php echo $this->field_badge( $s_start, $prov['label'] ); ?></span>
                            <input type="time" name="start_time" value="<?php echo esc_attr( $g( '_uc_start_time' ) ); ?>"<?php echo $this->field_disabled( $s_start ); ?> />
                        </label>
                        <label class="uc-field<?php echo esc_attr( $this->field_class( $s_end ) ); ?>">
                            <span class="uc-field-label">End <?php echo $this->field_badge( $s_end, $prov['label'] ); ?></span>
                            <input type="time" name="end_time" value="<?php echo esc_attr( $g( '_uc_end_time' ) ); ?>"<?php echo $this->field_disabled( $s_end ); ?> />
                        </label>
                    </div>

                    <?php
                    /*
                     * SERIES: the umbrella this event belongs to, and where its
                     * other dates are edited. A plain term assignment; nothing
                     * is inherited from it and changing it never touches another
                     * event.
                     */
                    $all_series = SFAF_Series::all();
                    $cur_series = $event_id ? SFAF_Series::id_for_event( $event_id ) : 0;
                    ?>
                    <label class="uc-field">
                        <span class="uc-field-label">Series</span>
                        <select name="series">
                            <option value="0">Not part of a series</option>
                            <?php foreach ( $all_series as $term ) : ?>
                                <option value="<?php echo (int) $term->term_id; ?>" <?php selected( $cur_series, $term->term_id ); ?>>
                                    <?php echo esc_html( $term->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php if ( $cur_series ) : ?>
                        <?php // ONE SHORT LINK, not a sentence with a link inside
                              // it. The old wording wrapped mid-phrase and left
                              // the link broken across two lines. ?>
                        <p class="uc-bento-link">
                            <a href="<?php echo esc_url( $this->url( 'series/edit/' . $cur_series ) ); ?>">
                                <?php echo sfaf_icon( 'repeat', array( 'size' => '15px' ) ); ?>
                                <span>Edit the schedule</span>
                            </a>
                        </p>
                    <?php endif; ?>

                    <?php
                    /*
                     * REPEAT IS A GENERATOR, AND IT RUNS ONCE. On save it
                     * creates one separate event per date, all stamped with a
                     * shared recurrence group, and then forgets the pattern.
                     *
                     * SHOWN ONLY WHERE IT CAN DO SOMETHING: on an event not
                     * already in a group, and with no source.
                     */
                    $rec_locked = ( $event_id && '' !== $prov['source'] );
                    $has_group  = $event_id && '' !== SFAF_Recurrence::group_of( $event_id );
                    ?>
                    <?php
                    /*
                     * ONE LINE EACH, AND NO CARD INSIDE THE CARD.
                     *
                     * This was a bordered fieldset carrying its own paragraph,
                     * inside a card that already had a heading. The paragraph is
                     * the same three sentences every time and is read once ever,
                     * so it is behind the "?" and what is left is the control
                     * and its state.
                     */
                    ?>
                    <?php if ( $rec_locked ) : ?>
                        <p class="uc-hint uc-repeat-line">
                            <?php echo $this->icon_lock(); ?>
                            Repeating is decided at <?php echo esc_html( $prov['label'] ? $prov['label'] : 'the source' ); ?>.
                        </p>
                    <?php elseif ( $has_group ) : ?>
                        <?php $group_count = count( $bulk_targets ); ?>
                        <p class="uc-repeat-line">
                            <strong><?php echo esc_html( SFAF_Recurrence::pattern_label( SFAF_Recurrence::pattern_of( $event_id ), $g( '_uc_event_date' ) ) ); ?></strong>,
                            <?php echo (int) $group_count; ?> upcoming
                            <?php echo esc_html( _n( 'occurrence', 'occurrences', $group_count ) ); ?>
                            <?php echo sfaf_help(
                                'uc-help-group-' . (int) $event_id,
                                'These dates were generated together from one pattern, which is what "edit all upcoming occurrences" targets. Each one is a separate, complete event: nothing regenerates it, and deleting it removes that single date and nothing else.',
                                'the recurrence group'
                            ); ?>
                        </p>
                    <?php else : ?>
                        <?php $this->render_recurrence_control( $event_id, (string) $g( '_uc_event_date' ) ); ?>
                    <?php endif; ?>
                </section>

                <?php // ---- Location: a venue, or somewhere one-off. -------
                      // There is no Image card any more: the picture moved up
                      // into Event details, with the title it belongs to. ?>
                <?php $s_loc = $st( 'location' ); ?>
                <section class="uc-bento-card">
                    <h2 class="uc-bento-title">Location</h2>
                    <?php $this->render_location_field( $event_id, $s_loc, $prov ); ?>
                </section>

                <?php
                /*
                 * ANYTHING THE CARDS DID NOT CLAIM.
                 *
                 * This is what keeps the 3.2.0 guarantee true under a layout
                 * that places controls by name. Add a field to the shared list
                 * and forget to give it a card, and it appears here rather than
                 * vanishing from the editor while still showing in the queue.
                 * Silence is the failure mode this exists to prevent.
                 */
                ob_start();
                $rest = $this->render_manager_fields( $mgr_ctx, null, $placed );
                $rest_html = ob_get_clean();
                ?>

                <?php
                /*
                 * NOTIFICATIONS: who else gets the reminder, and where replies
                 * land. Two questions about the same email, so one card.
                 *
                 * AND THE CATCH-ALL FOR RSVP SETTINGS. The Capacity card in the
                 * side column took two of them by name; this call takes
                 * everything left, so a setting added to
                 * rsvp_setting_fields() appears here rather than showing on
                 * the registrations screen and nowhere else. Same guarantee as
                 * "Other details" below, for the other shared list.
                 *
                 * Native events only. An imported event's reminders are the
                 * platform's job, so a list here would offer a setting that
                 * does nothing. Same rule as SFAF_Reminders, read from the same
                 * provenance, so the two cannot disagree.
                 */
                if ( $event_id && '' === $prov['source'] ) :
                    ?>
                    <section class="uc-bento-card">
                        <h2 class="uc-bento-title">Notifications</h2>
                        <?php $this->render_rsvp_settings( $rsvp_ctx, null, $rsvp_placed ); ?>
                    </section>
                <?php endif; ?>

                <?php if ( ! empty( $rest ) ) : ?>
                    <section class="uc-bento-card">
                        <h2 class="uc-bento-title">Other details</h2>
                        <?php echo $rest_html; ?>
                    </section>
                <?php endif; ?>

            </div><?php // .uc-bento-main ?>

            <?php // The side column, built at the top of this block. ?>
            <div class="uc-bento-side"><?php echo $side_html; ?></div>
            </div><?php // .uc-bento ?>

            <?php
            // How far this save travels is chosen at the TOP of the form,
            // before anything is edited, rather than in a box at the bottom
            // after it. See render_scope_header().
            ?>

            <div class="uc-form-actions">
                <?php
                /*
                 * CONFIRM ON SAVE, NAMING THE COUNT.
                 *
                 * This is the click that can rewrite a term's worth of
                 * programming, so in "all upcoming" mode both buttons ask
                 * "Update 12 events?" first. The sentence is built by the same
                 * script that owns the scope, off the count in the banner, so
                 * the number in the dialog and the number on screen are the
                 * same number.
                 */
                ?>
                <button type="submit" name="save_mode" value="draft" class="uc-btn" data-uc-scope-confirm>Save Draft</button>
                <?php
                // WARN, DO NOT BLOCK. There are legitimate reasons to publish a
                // campaign before its image and description are written — a
                // date announcement that has to go out today, for one. So this
                // names what is missing and then does exactly what was asked.
                //
                // The sentence is a TEMPLATE, and the live check fills in the
                // %s and sets or removes data-uc-confirm as the manager works.
                // Baking the finished sentence in here is what made the button
                // warn about an image that had just been chosen: it was written
                // when the page was rendered and nothing rewrote it.
                $confirm_tpl = sprintf(
                    'This event still needs %%s. %s cannot supply that, so it stays empty on the live page until somebody writes it here. Publish anyway?',
                    $prov['label'] ? $prov['label'] : 'The source'
                );
                $confirm = ! empty( $missing )
                    ? str_replace( '%s', SFAF_Sources::field_phrase( $missing ), $confirm_tpl )
                    : '';
                $watched = $event_id ? SFAF_Sources::completeness_payload( $event_id ) : array();
                ?>
                <?php if ( $role === 'contributor' && $this->contributor_status( $user ) === 'pending' ) : ?>
                    <button type="submit" name="save_mode" value="review" class="uc-btn uc-btn-primary" data-uc-scope-confirm>Submit for Review</button>
                <?php else : ?>
                    <button type="submit" name="save_mode" value="publish" class="uc-btn uc-btn-primary" data-uc-scope-confirm
                            <?php echo ! empty( $watched ) ? ' data-uc-confirm-template="' . esc_attr( $confirm_tpl ) . '"' : ''; ?>
                            <?php echo $confirm ? ' data-uc-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>Publish</button>
                <?php endif; ?>
                <?php
                // The list the live check watches, straight off
                // SFAF_Sources::completeness_fields(). Emitted inside the form
                // so it cannot outlive it, and only when there is something to
                // watch.
                if ( ! empty( $watched ) ) : ?>
                    <script type="application/json" id="uc-completeness-data"><?php
                        // JSON_HEX_TAG so a "<" can never close this block early,
                        // whatever a future field phrase turns out to contain.
                        echo wp_json_encode( $watched, JSON_HEX_TAG | JSON_HEX_AMP );
                    ?></script>
                <?php endif; ?>
            </div>
            </fieldset>
        </form>
        <?php
        $this->chrome_close();
    }

    /**
     * "Does this repeat?", asked so that the answer is readable.
     *
     * WHAT WAS WRONG WITH THE DROPDOWN. Six options, each of which had to be
     * reverse-engineered: "every Thursday" was spelled "Every week" and only
     * meant Thursday if the date above happened to be one, "the first Monday of
     * the month" was spelled "Every month, on the same weekday", and a group
     * meeting Tuesdays AND Thursdays could not be expressed at all. The list
     * described the ARITHMETIC. This describes the schedule.
     *
     * FOUR CONTROLS, EACH ANSWERING ONE QUESTION. How often (segmented), on
     * which days (circles), when it stops (ends), and what that comes to (the
     * summary). The summary is the important one: generation is a creation-time
     * action that makes N independent posts, so the number is stated before the
     * button is pressed rather than discovered afterwards.
     *
     * NO JAVASCRIPT: every section is visible and every control is a real
     * input. The server reads repeat_mode and uses only the fields belonging to
     * it, exactly as the location picker reads location_mode. What is lost
     * without script is the folding away of the sections that do not apply and
     * the live summary; nothing becomes unreachable and nothing is built by
     * script.
     *
     * KEYBOARD: the day circles are checkboxes with visible labels, styled
     * round; the mode switch is a radio group. Both are focusable, both answer
     * to Space, and both are announced as what they are.
     *
     * @param int    $event_id
     * @param string $date The event's own date, which anchors every pattern.
     */
    private function render_recurrence_control( $event_id, $date ) {
        $uid  = 'uc-rep-' . (int) $event_id;
        $dow  = $date ? (int) SFAF_Recurrence::dow_of( $date ) : (int) current_time( 'w' );
        $days = SFAF_Recurrence::weekday_names();
        $abbr = SFAF_Recurrence::weekday_names( true );

        $day_num = $date ? date_i18n( 'jS', strtotime( $date ) ) : '';
        $nth     = $date ? SFAF_Recurrence::nth_weekday_of_month( $date ) : null;
        ?>
        <div class="uc-repeat" data-uc-repeat data-uc-repeat-date="<?php echo esc_attr( $date ); ?>">

            <span class="uc-field-label">Repeats
                <?php echo sfaf_help(
                    'uc-help-repeat-' . (int) $event_id,
                    'On save this creates one separate event per date, all grouped so they can be edited together afterwards. It happens once: nothing regenerates, and the schedule is edited on the series from then on.',
                    'repeating'
                ); ?>
            </span>

            <?php // ---- How often. A radio group that looks like a switch. -- ?>
            <div class="uc-seg" role="radiogroup" aria-label="How often this repeats">
                <?php foreach ( array(
                    ''        => 'Never',
                    'daily'   => 'Daily',
                    'weekly'  => 'Weekly',
                    'monthly' => 'Monthly',
                ) as $val => $label ) : ?>
                    <label class="uc-seg-opt">
                        <input type="radio" name="repeat_mode" value="<?php echo esc_attr( $val ); ?>"
                               <?php checked( '' === $val ); ?> data-uc-repeat-mode />
                        <span><?php echo esc_html( $label ); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php // ---- Weekly ------------------------------------------- ?>
            <div class="uc-repeat-panel" data-uc-repeat-panel="weekly">
                <div class="uc-repeat-every">
                    <span>Every</span>
                    <input type="number" name="repeat_weekly_interval" value="1" min="1" max="52"
                           class="uc-repeat-num" data-uc-not-a-field aria-label="Weeks between occurrences" />
                    <span>week(s) on</span>
                </div>
                <div class="uc-days" role="group" aria-label="Which days of the week">
                    <?php foreach ( $abbr as $i => $letter ) : ?>
                        <label class="uc-day">
                            <input type="checkbox" name="repeat_days[]" value="<?php echo (int) $i; ?>"
                                   <?php checked( $i === $dow ); ?> data-uc-repeat-day />
                            <span aria-hidden="true"><?php echo esc_html( $letter ); ?></span>
                            <span class="uc-visually-hidden"><?php echo esc_html( $days[ $i ] ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="uc-hint">The event's own day is ticked to start with. Tick more than one for a group that meets twice a week.</p>
            </div>

            <?php // ---- Monthly ------------------------------------------ ?>
            <div class="uc-repeat-panel" data-uc-repeat-panel="monthly">
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_monthly_mode" value="date" checked data-uc-repeat-monthly />
                    <span>On the <strong><?php echo esc_html( $day_num ? $day_num : 'same date' ); ?></strong> of each month</span>
                </label>
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_monthly_mode" value="nth" data-uc-repeat-monthly />
                    <span>On the</span>
                </label>
                <div class="uc-repeat-nth">
                    <select name="repeat_nth" aria-label="Which occurrence in the month" data-uc-not-a-field>
                        <?php foreach ( array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', -1 => 'last' ) as $n => $word ) : ?>
                            <option value="<?php echo (int) $n; ?>" <?php selected( $nth && (int) $nth['nth'] === (int) $n ); ?>><?php echo esc_html( $word ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="repeat_nth_dow" aria-label="Which weekday" data-uc-not-a-field>
                        <?php foreach ( $days as $i => $name ) : ?>
                            <option value="<?php echo (int) $i; ?>" <?php selected( $i === $dow ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span>of each month</span>
                </div>
                <?php // "last" is not "fifth": a month with four Fridays has a
                      // last Friday and no fifth one, and the engine skips the
                      // months a fifth would fall outside. ?>
                <p class="uc-hint">Choose <em>last</em> rather than <em>fourth</em> if you mean the final one, since some months have five.</p>
            </div>

            <?php // ---- Ends --------------------------------------------- ?>
            <div class="uc-repeat-panel" data-uc-repeat-panel="ends">
                <span class="uc-field-label">Ends</span>
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_ends" value="never" checked data-uc-repeat-ends />
                    <span>No end date</span>
                </label>
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_ends" value="on" data-uc-repeat-ends />
                    <span>On</span>
                    <input type="date" name="repeat_until" value="" class="uc-repeat-date"
                           aria-label="Repeat until this date" data-uc-not-a-field />
                </label>
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_ends" value="after" data-uc-repeat-ends />
                    <span>After</span>
                    <input type="number" name="repeat_count" value="12" min="2" max="366" class="uc-repeat-num"
                           aria-label="How many occurrences in total" data-uc-not-a-field />
                    <span>occurrences</span>
                </label>
                <?php
                /*
                 * "NO END DATE" CANNOT MEAN FOREVER, AND SAYS SO.
                 *
                 * Generation makes real posts, once. There is no pattern left
                 * afterwards for anything to extend, so an unbounded choice
                 * would have to mean "as many as we are willing to create",
                 * and pretending otherwise would be the one place on this
                 * screen that lies about what the software does.
                 */
                ?>
                <p class="uc-hint">
                    No end date creates a year of dates. Generation happens once, so there is no pattern left running
                    afterwards; add more dates later from the series screen.
                </p>
            </div>

            <?php
            /*
             * THE SUMMARY, AND THE COUNT.
             *
             * Rendered by the server for the page load and recomputed by
             * portal.js on every change, from the same rules. The number is
             * the whole point: this creates N independent events and nobody
             * should meet that number for the first time afterwards.
             */
            ?>
            <p class="uc-repeat-summary" data-uc-repeat-summary aria-live="polite">
                Does not repeat.
            </p>
        </div>
        <?php
    }
    /**
     * Read the recurrence control back into a pattern, an end date and a count.
     *
     * ALL FOUR PANELS POST, ALWAYS, because without script they are all on
     * screen and even with it they are only hidden. So this reads repeat_mode
     * first and then looks at nothing else: the weekly interval on a form
     * saved as Monthly is a field somebody never saw, and honouring it would
     * be honouring a value nobody chose. Same rule as the location picker,
     * which reads location_mode and then ignores whichever branch lost.
     *
     * THE DEFAULT IS ALWAYS "NO", in every direction. An unrecognised mode, a
     * missing end, a count of zero: each returns something that generates
     * nothing, because this function's mistakes create posts.
     *
     * @return array{0:string,1:string,2:int} pattern, end date, occurrence limit.
     */
    private function recurrence_from_post() {
        $mode = isset( $_POST['repeat_mode'] ) ? sanitize_key( wp_unslash( $_POST['repeat_mode'] ) ) : '';

        // The pre-3.14.0 form posted a single `repeat` select. Still honoured,
        // because a browser can hold a form open across a plugin update.
        if ( '' === $mode && isset( $_POST['repeat'] ) ) {
            $legacy = SFAF_Recurrence::clean_pattern( wp_unslash( $_POST['repeat'] ) );
            $until  = isset( $_POST['repeat_until'] ) ? sanitize_text_field( wp_unslash( $_POST['repeat_until'] ) ) : '';
            return array( $legacy, $until, 0 );
        }

        $spec = null;
        if ( 'daily' === $mode ) {
            $spec = array( 'type' => 'daily', 'interval' => 1 );
        } elseif ( 'weekly' === $mode ) {
            $days = array();
            if ( isset( $_POST['repeat_days'] ) && is_array( $_POST['repeat_days'] ) ) {
                foreach ( wp_unslash( $_POST['repeat_days'] ) as $d ) {
                    $d = (int) $d;
                    if ( $d >= 0 && $d <= 6 ) {
                        $days[] = $d;
                    }
                }
            }
            $spec = array(
                'type'     => 'weekly',
                'interval' => isset( $_POST['repeat_weekly_interval'] ) ? (int) $_POST['repeat_weekly_interval'] : 1,
                'days'     => $days,
            );
        } elseif ( 'monthly' === $mode ) {
            $monthly = isset( $_POST['repeat_monthly_mode'] ) ? sanitize_key( wp_unslash( $_POST['repeat_monthly_mode'] ) ) : 'date';
            if ( 'nth' === $monthly ) {
                $spec = array(
                    'type' => 'monthly_nth',
                    'nth'  => isset( $_POST['repeat_nth'] ) ? (int) $_POST['repeat_nth'] : 1,
                    'dow'  => isset( $_POST['repeat_nth_dow'] ) ? (int) $_POST['repeat_nth_dow'] : 0,
                );
            } else {
                $spec = array( 'type' => 'monthly', 'interval' => 1 );
            }
        }

        if ( ! $spec ) {
            return array( '', '', 0 );
        }
        $pattern = SFAF_Recurrence::pattern_string( $spec );
        if ( '' === $pattern ) {
            return array( '', '', 0 );
        }

        $ends  = isset( $_POST['repeat_ends'] ) ? sanitize_key( wp_unslash( $_POST['repeat_ends'] ) ) : 'never';
        $until = '';
        $limit = 0;

        if ( 'on' === $ends ) {
            $until = isset( $_POST['repeat_until'] ) ? sanitize_text_field( wp_unslash( $_POST['repeat_until'] ) ) : '';
            if ( '' === $until ) {
                return array( '', '', 0 ); // "until" with no date is not an instruction
            }
        } elseif ( 'after' === $ends ) {
            // The control counts the event itself as the first occurrence,
            // because that is what somebody means by "after 12". The engine
            // counts dates it CREATES, which is one fewer.
            $total = isset( $_POST['repeat_count'] ) ? (int) $_POST['repeat_count'] : 0;
            $limit = max( 0, $total - 1 );
            if ( $limit <= 0 ) {
                return array( '', '', 0 );
            }
        } else {
            // No end date. Bounded at a year, and the control says so.
            $limit = self::REPEAT_OPEN_ENDED_LIMIT;
        }

        return array( $pattern, $until, $limit );
    }

    /**
     * How many dates "no end date" creates.
     *
     * Generation is one-off and makes real posts, so unbounded is not a thing
     * this can offer. A year is the honest reading of "keep going", it is what
     * the control tells the manager it will do, and the summary states the
     * resulting number before anything is created.
     */
    const REPEAT_OPEN_ENDED_LIMIT = 52;

    /**
     * Where the event happens: a venue, or somewhere one-off.
     *
     * THE PICKER IS THE DEFAULT AND FREE TEXT IS STILL THERE.
     *
     * Choosing a venue stores the term and clears the text; choosing "a
     * different location" stores the text and clears the term. An event never
     * holds both, so nothing downstream has to decide which one wins. See
     * sfaf_event_location(), which is the single reader.
     *
     * AN IMPORTED EVENT KEEPS THE PLAIN FIELD. The platform owns the location
     * and writes it on every fetch, so a venue chosen here would be replaced
     * within the hour. It renders exactly as it did before, locked.
     *
     * WITHOUT JAVASCRIPT BOTH CONTROLS ARE VISIBLE and both submit; the save
     * prefers whichever the radio says. So this degrades to two fields and a
     * choice rather than to nothing.
     *
     * @param int    $event_id
     * @param string $state Field state from field_state().
     * @param array  $prov
     */
    private function render_location_field( $event_id, $state, $prov ) {
        $imported = ( '' !== $prov['source'] );
        $text     = $event_id ? (string) get_post_meta( $event_id, '_uc_location', true ) : '';

        if ( $imported ) {
            ?>
            <label class="uc-field<?php echo esc_attr( $this->field_class( $state ) ); ?>"<?php echo $this->field_watch_attr( 'location', $state ); ?>>
                <span class="uc-field-label">Location <?php echo $this->field_badge( $state, $prov['label'] ); ?></span>
                <input type="text" name="location" value="<?php echo esc_attr( $text ); ?>" placeholder="e.g. Strut, 470 Castro St"<?php echo $this->field_disabled( $state ); ?> />
                <span class="uc-hint">
                    Imported events keep the location the platform sends. Venues are for events set up here.
                </span>
            </label>
            <?php
            return;
        }

        $venues   = SFAF_Venues::all();
        $venue_id = $event_id ? SFAF_Venues::id_for_event( $event_id ) : 0;
        $mode     = $venue_id ? 'venue' : 'custom';
        ?>
        <div class="uc-field uc-location-field" data-uc-location>
            <span class="uc-field-label">Location
                <?php echo sfaf_help(
                    'uc-help-venue-' . (int) $event_id,
                    'An event points at its venue rather than keeping a copy of the address, so correcting an address on the Venues screen corrects every event held there at once, including ones already published. Use a different location for a one-off place that is not worth adding as a venue.',
                    'venues'
                ); ?>
            </span>

            <?php if ( empty( $venues ) ) : ?>
                <p class="uc-hint">
                    No venues yet. <a href="<?php echo esc_url( $this->url( 'venues' ) ); ?>">Add one</a> to keep its address in one place.
                </p>
            <?php else : ?>
                <?php // Ordinary radio rows: control first, label beside it, both
                      // left aligned. They were centred with the labels adrift,
                      // which made two short phrases wrap. ?>
                <label class="uc-radio-row">
                    <input type="radio" name="location_mode" value="venue" data-uc-location-mode="venue" <?php checked( 'venue', $mode ); ?> />
                    <span>A venue</span>
                </label>
                <div class="uc-location-venue" data-uc-location-panel="venue">
                    <select name="venue">
                        <option value="0">Choose a venue</option>
                        <?php foreach ( $venues as $v ) :
                            $addr = SFAF_Venues::address( $v->term_id ); ?>
                            <option value="<?php echo (int) $v->term_id; ?>" <?php selected( $venue_id, $v->term_id ); ?>>
                                <?php echo esc_html( '' !== $addr ? $v->name . ' - ' . $addr : $v->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="uc-hint"><a href="<?php echo esc_url( $this->url( 'venues' ) ); ?>">Manage venues</a></p>
                </div>
            <?php endif; ?>

            <label class="uc-radio-row">
                <input type="radio" name="location_mode" value="custom" data-uc-location-mode="custom" <?php checked( 'custom', $mode ); ?> />
                <span>A different location</span>
            </label>
            <div class="uc-location-custom" data-uc-location-panel="custom">
                <?php
                /*
                 * THE SAME FOUR FIELDS A VENUE HAS.
                 *
                 * 3.13.0 gave venues street, city, state and ZIP and left this
                 * branch as one free-text line, so the same address was
                 * structured in one place and a sentence in the other. They are
                 * the same thing and are now entered the same way.
                 *
                 * Existing one-line locations are parsed by the venues parser,
                 * at read time rather than in a migration pass, so nothing is
                 * rewritten until somebody saves. See
                 * sfaf_event_location_parts().
                 */
                $loc_parts = sfaf_event_location_parts( $event_id );
                ?>
                <div class="uc-venue-grid uc-location-grid">
                    <label class="uc-field uc-venue-street">
                        <span class="uc-field-label">Street</span>
                        <input type="text" name="location_street" value="<?php echo esc_attr( $loc_parts['street'] ); ?>" placeholder="Dolores Park, near the tennis courts" />
                    </label>
                    <label class="uc-field uc-venue-city">
                        <span class="uc-field-label">City</span>
                        <input type="text" name="location_city" value="<?php echo esc_attr( $loc_parts['city'] ); ?>" placeholder="San Francisco" />
                    </label>
                    <label class="uc-field uc-venue-state">
                        <span class="uc-field-label">State</span>
                        <input type="text" name="location_state" value="<?php echo esc_attr( $loc_parts['state'] ); ?>" placeholder="CA" maxlength="20" />
                    </label>
                    <label class="uc-field uc-venue-zip">
                        <span class="uc-field-label">ZIP</span>
                        <input type="text" name="location_zip" value="<?php echo esc_attr( $loc_parts['zip'] ); ?>" placeholder="94114" maxlength="10" />
                    </label>
                </div>
                <?php // The line every reader still sees, so what is stored and
                      // what is shown cannot drift apart unnoticed. ?>
                <?php if ( '' !== $text ) : ?>
                    <p class="uc-hint">Shows as: <strong><?php echo esc_html( $text ); ?></strong></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * "Save these as a set", in the FAQ card, posting to its own form.
     *
     * HTML FORMS CANNOT NEST, and this control has to sit inside the event
     * editor's form, visually, next to the questions it saves. The `form`
     * attribute is exactly the tool for that: the input and the button live
     * here in the markup and belong to a form declared outside the editor's
     * one. No JavaScript is involved in that association, so this works with
     * scripting switched off, which the old panel above the form also did.
     *
     * WHAT GETS SAVED. Without script, the questions as they are STORED on the
     * event, which is what the old control did and what the hint says. With
     * script, portal.js copies the rows currently on screen into the hidden
     * form first, so a set can be saved from questions just typed. Both routes
     * end at the same server action.
     *
     * @param int $event_id
     */
    private function render_faq_save_as_set( $event_id ) {
        if ( ! $event_id ) {
            // Nothing to save yet: the event has no stored rows and no id to
            // post against. Saying so beats a control that cannot work.
            echo '<span class="uc-muted uc-faq-saveset-note">Save the event to reuse these questions as a set.</span>';
            return;
        }
        ?>
        <div class="uc-faq-saveset" data-uc-faq-saveset>
            <label class="uc-visually-hidden" for="uc-faq-set-name-<?php echo (int) $event_id; ?>">Name for the saved set</label>
            <input type="text" id="uc-faq-set-name-<?php echo (int) $event_id; ?>"
                   form="uc-faq-set-create" name="faq_set_name"
                   placeholder="Name a set, e.g. Cycle to Zero questions" />
            <button type="submit" form="uc-faq-set-create" class="uc-btn uc-btn-sm">Save these as a set</button>
        </div>
        <?php
    }

    /**
     * The form the FAQ card's save-as-set control belongs to.
     *
     * Rendered OUTSIDE the editor's form, because forms cannot nest, and
     * referenced from inside it by id. See render_faq_save_as_set().
     *
     * @param int $event_id
     */
    private function render_faq_set_create_form( $event_id ) {
        if ( ! $event_id ) {
            return;
        }
        ?>
        <form method="post" id="uc-faq-set-create" class="uc-offscreen-form"
              action="<?php echo esc_url( $this->url( 'events/edit/' . (int) $event_id ) ); ?>">
            <input type="hidden" name="uc_action" value="faq_set_create" />
            <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
            <?php wp_nonce_field( 'uc_portal_faq_set_create', 'uc_nonce' ); ?>
            <?php // portal.js writes the on-screen rows in here before submit. ?>
            <div data-uc-faq-set-rows></div>
        </form>
        <?php
    }

    /* =====================================================================
     * Rendering — the per-event notification list
     * ================================================================== */

    /**
     * Who else receives this event's morning-of reminder.
     *
     * A NOTIFICATION LIST AND NOTHING MORE. Being on it does not grant the
     * right to edit the event, is not ownership, and changes no permission
     * anywhere. It exists so staff can see what participants are being sent.
     *
     * The creator comes from post_author, which WordPress already stores, so
     * there is no second copy of "who made this" to drift out of step with the
     * first. What IS stored is the opposite: a single flag recording that the
     * creator took themselves off, because "not opted out" is the default and
     * an absent flag should mean exactly that.
     */
    /* =====================================================================
     * THE RSVP SETTINGS, SHARED BETWEEN THE EDITOR AND THE REGISTRATIONS PAGE
     *
     * Somebody looking at a list of registrations wants to change the capacity,
     * stop taking names, or add a colleague to the reminder, and had to go and
     * find the event in the editor to do any of it. Those controls are now on
     * both screens.
     *
     * WHICH MEANS THEY CANNOT BE WRITTEN OUT TWICE. This is the same rule the
     * manager-owned fields have followed since 3.2.0, applied to a second list:
     * one method says WHICH settings exist, one method draws ONE of them, and
     * one method saves them. Neither screen holds a list. The editor splits
     * them across two cards by name and ends with a call that takes everything
     * left, so a setting added here appears in the editor rather than showing
     * on the registrations page and nowhere else.
     * ================================================================== */

    /**
     * Everything the RSVP settings need, gathered once.
     *
     * @param WP_User $user
     * @param int     $event_id
     * @return array
     */
    private function rsvp_settings_context( $user, $event_id ) {
        $event_id = (int) $event_id;
        $prov     = $event_id
            ? SFAF_Sources::provenance( $event_id )
            : array( 'source' => '', 'label' => '', 'source_url' => '' );

        return array(
            'event_id' => $event_id,
            'user'     => $user,
            'prov'     => $prov,
            'owned'    => ( $event_id && '' !== $prov['source'] ) ? SFAF_Sources::owned_fields_for( $prov['source'] ) : array(),
            'imported' => ( '' !== $prov['source'] ),
        );
    }

    /**
     * Which RSVP settings this event has.
     *
     * The recipient list and the reply-to are NOT offered on an imported event:
     * its reminders are the platform's job, so both would be controls over
     * nothing. Same rule as SFAF_Reminders, read from the same provenance, so
     * the two cannot disagree.
     *
     * @param array $ctx From rsvp_settings_context().
     * @return string[]
     */
    private function rsvp_setting_fields( $ctx ) {
        $fields = array( 'rsvp_enabled', 'capacity' );
        if ( $ctx['event_id'] && ! $ctx['imported'] ) {
            $fields[] = 'notify';
            $fields[] = 'replyto';
        }
        return $fields;
    }

    /**
     * Draw a chosen subset of the RSVP settings.
     *
     * $only/$skip work exactly as they do for the manager-owned fields, and the
     * placement rule is literally the same pure function, so the guarantee it
     * carries is the same one: with a final $only === null call, every setting
     * is rendered exactly once.
     *
     * @param array         $ctx
     * @param string[]|null $only
     * @param string[]      $skip
     * @return string[] What this call rendered.
     */
    private function render_rsvp_settings( $ctx, $only = null, $skip = array() ) {
        $fields = $this->manager_fields_for_card( $this->rsvp_setting_fields( $ctx ), $only, $skip );
        foreach ( $fields as $field ) {
            $this->render_rsvp_setting( $field, $ctx );
        }
        return $fields;
    }

    /**
     * One RSVP setting's markup. THE shared render; both screens call this.
     *
     * EVERY CONTROL CARRIES ITS OWN "I WAS ON THE FORM" SIGNAL, because the two
     * screens draw different subsets and an unticked checkbox submits nothing
     * at all. Without that, "nobody is on the list" and "this form did not ask"
     * are the same POST, and saving from the registrations page would quietly
     * clear settings it never showed.
     *
     * @param string $field
     * @param array  $ctx
     */
    private function render_rsvp_setting( $field, $ctx ) {
        $event_id = (int) $ctx['event_id'];
        $user     = $ctx['user'];
        $g        = function ( $key, $default = '' ) use ( $event_id ) {
            return $event_id ? get_post_meta( $event_id, $key, true ) : $default;
        };

        switch ( $field ) {

            case 'rsvp_enabled':
                ?>
                <?php // The marker travels WITH the checkbox, so it is present
                      // exactly when the control is. ?>
                <input type="hidden" name="uc_rsvp_toggle_present" value="1" />
                <label class="uc-check">
                    <input type="checkbox" name="rsvp_enabled" value="1" <?php checked( $g( '_uc_rsvp_enabled' ), '1' ); ?> />
                    Accept RSVPs
                </label>
                <?php
                break;

            case 'capacity':
                $s_cap = $this->field_state( 'capacity', $ctx['owned'], array(), $event_id );
                ?>
                <label class="uc-field<?php echo esc_attr( $this->field_class( $s_cap ) ); ?>">
                    <span class="uc-field-label">Capacity <?php echo $this->field_badge( $s_cap, $ctx['prov']['label'] ); ?></span>
                    <input type="number" name="capacity" min="0" value="<?php echo esc_attr( $g( '_uc_capacity' ) ); ?>"<?php echo $this->field_disabled( $s_cap ); ?> />
                    <?php // Stays inline: it is four words, and it stops
                          // somebody typing 0 meaning "nobody". ?>
                    <span class="uc-hint uc-hint-spec">0 means unlimited.</span>
                </label>
                <?php
                break;

            case 'notify':
                // Carries its own notify_list_present marker.
                $this->render_notify_box( $user, $event_id );
                break;

            case 'replyto':
                $reply_current  = (string) $g( '_uc_email_replyto' );
                $reply_resolved = SFAF_Reminders::reply_to_for( $event_id );
                $reply_source   = SFAF_Reminders::reply_to_source( $event_id );
                $reply_rejected = get_transient( 'sfaf_replyto_rejected_' . $user->ID . '_' . $event_id );
                if ( $reply_rejected ) {
                    delete_transient( 'sfaf_replyto_rejected_' . $user->ID . '_' . $event_id );
                }
                /*
                 * ITS OWN HEADING TRAVELS WITH IT. Replies is a separate
                 * question from who receives the mail: it is who fields the
                 * answers, very often a shared mailbox rather than whichever
                 * staff member is on the list. Carrying the heading here means
                 * the editor and the registrations screen both show it under
                 * the same words, and it cannot be drawn by anything else.
                 */
                ?>
                <div class="uc-notify-section">
                <h4 class="uc-notify-subhead">Replies</h4>
                <label class="uc-field">
                    <span class="uc-field-label">Replies go to</span>
                    <?php
                    // A server-side rejection renders in exactly the shape the
                    // client-side validator uses, so the two are
                    // indistinguishable to the person reading them and to a
                    // screen reader.
                    ?>
                    <input type="email" name="event_replyto" id="uc-event-replyto"
                           value="<?php echo esc_attr( '' !== $reply_current ? $reply_current : $reply_resolved ); ?>"
                           <?php echo $reply_rejected ? ' class="uc-invalid" aria-invalid="true" aria-describedby="uc-event-replyto-error"' : ''; ?>
                           placeholder="events@sfaf.org" />
                    <?php if ( $reply_rejected ) : ?>
                        <p class="uc-field-error" id="uc-event-replyto-error" data-uc-for="uc-event-replyto" role="alert">
                            <?php echo esc_html( 'Not an email address, so it was not saved: ' . $reply_rejected ); ?>
                        </p>
                    <?php endif; ?>
                    <span class="uc-hint">
                        One address, for replies to this event's reminder. A shared mailbox is more reliable
                        than a person, and it does not have to be an sfaf.org address.
                        <?php if ( '' === $reply_current && 'author' === $reply_source ) : ?>
                            Pre-filled with whoever created this event.
                        <?php elseif ( '' === $reply_current && 'setting' === $reply_source ) : ?>
                            Nothing is set here, so replies go to the site-wide default in Settings.
                        <?php elseif ( '' === $reply_current ) : ?>
                            Nothing is set here and there is no site-wide default, so a reply goes to whatever
                            address the mail is sent from.
                        <?php endif; ?>
                    </span>
                </label>
                </div>
                <?php
                break;
        }
    }

    /**
     * Save the RSVP settings from whichever form posted them.
     *
     * The event editor and the registrations page both end here, so a rule
     * about how one of these is stored cannot be true on one screen and not
     * the other. Each block is guarded by the signal its own control carries,
     * so a form that did not draw a setting leaves it alone rather than
     * clearing it.
     *
     * @param WP_User  $user
     * @param int      $event_id
     * @param callable $is_locked Field name => bool, from the source adapter.
     */
    private function save_rsvp_settings_from_post( $user, $event_id, $is_locked ) {
        $event_id = (int) $event_id;
        $imported = ( '' !== (string) get_post_meta( $event_id, SFAF_Sources::META_SOURCE, true ) );

        if ( ! $is_locked( 'capacity' ) && isset( $_POST['capacity'] ) ) {
            update_post_meta( $event_id, '_uc_capacity', sanitize_text_field( wp_unslash( $_POST['capacity'] ) ) );
        }

        if ( isset( $_POST['uc_rsvp_toggle_present'] ) ) {
            update_post_meta( $event_id, '_uc_rsvp_enabled', isset( $_POST['rsvp_enabled'] ) ? '1' : '0' );
        }

        /*
         * PER-EVENT REPLY-TO. One address, free text, so it can be a person or
         * a group mailbox and does not have to be on the sending domain.
         *
         * Reuses _uc_email_replyto, which has existed since the confirmation
         * email override and is already copied down to occurrences by
         * SFAF_Recurrence. A second field would have meant two answers to
         * "where do replies go" and no way to tell which one won.
         *
         * An address that is not valid is REJECTED AND REPORTED, never stored.
         * A reply-to nobody can receive is worse than no reply-to, because the
         * fallback would at least have reached somebody.
         */
        if ( isset( $_POST['event_replyto'] ) ) {
            $raw = trim( (string) wp_unslash( $_POST['event_replyto'] ) );
            if ( '' === $raw ) {
                delete_post_meta( $event_id, '_uc_email_replyto' );
            } else {
                $clean = sanitize_email( $raw );
                if ( $clean && is_email( $clean ) ) {
                    update_post_meta( $event_id, '_uc_email_replyto', $clean );
                } else {
                    set_transient( 'sfaf_replyto_rejected_' . $user->ID . '_' . $event_id, $raw, 5 * MINUTE_IN_SECONDS );
                }
            }
        }

        /*
         * THE PER-EVENT NOTIFICATION LIST.
         *
         * Guarded by the marker field, not by isset() on the controls: an
         * unticked checkbox and an empty checkbox group both submit nothing, so
         * without the marker "the creator opted out" and "this form did not
         * carry the block at all" would be the same POST. The block is not
         * rendered on imported events, and this must leave their meta alone
         * rather than clearing it.
         */
        if ( isset( $_POST['notify_list_present'] ) && ! $imported ) {
            // Stored as an opt-OUT so that the absence of any setting means the
            // creator is on the list, which is the documented default.
            if ( isset( $_POST['notify_author'] ) ) {
                delete_post_meta( $event_id, SFAF_Reminders::NOTIFY_AUTHOR_OPTOUT_META );
            } else {
                update_post_meta( $event_id, SFAF_Reminders::NOTIFY_AUTHOR_OPTOUT_META, '1' );
            }

            $uids = isset( $_POST['notify_users'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['notify_users'] ) ) : array();
            $uids = array_values( array_unique( array_filter( $uids ) ) );
            update_post_meta( $event_id, SFAF_Reminders::NOTIFY_USERS_META, $uids );

            /*
             * TEAMS. The reference, and only the reference.
             *
             * What is stored is a list of team ids. Nobody's address and
             * nobody's user id is written here, which is the whole reason a
             * change of membership reaches events that were saved months ago.
             * Guarded by its own marker for the same reason the block above is:
             * every box unticked submits nothing at all, and that has to mean
             * "none" rather than "this form did not ask".
             */
            if ( isset( $_POST['notify_teams_present'] ) ) {
                SFAF_Teams::set_for_event(
                    $event_id,
                    isset( $_POST['notify_teams'] ) ? (array) wp_unslash( $_POST['notify_teams'] ) : array()
                );
            }

            /*
             * ADDRESSES FOR PEOPLE OUTSIDE THE SYSTEM.
             *
             * These arrive as PILLS now: one ticked checkbox per address, so
             * unticking one takes it off and the whole set posts as an array.
             * A textarea of one-per-line is still accepted, because an older
             * cached form posts that and losing somebody's recipients to a
             * stale tab would be a poor trade for a tidier parser.
             *
             * notify_email_new is the "add one" field. With script it never
             * arrives, because the button turns it into a pill on the spot and
             * clears it. Without script it is how an address gets added at all,
             * and it is validated here exactly as every other one is.
             *
             * Validated, not trusted: anything that is not an address is
             * dropped AND named back to the person who typed it, because a typo
             * that disappears in silence looks like a save.
             */
            $valid    = array();
            $rejected = array();

            $candidates = array();
            $posted     = isset( $_POST['notify_emails'] ) ? wp_unslash( $_POST['notify_emails'] ) : array();
            if ( is_array( $posted ) ) {
                foreach ( $posted as $one ) {
                    $candidates[] = (string) $one;
                }
            } else {
                foreach ( preg_split( '/[\r\n,;]+/', (string) $posted ) as $line ) {
                    $candidates[] = $line;
                }
            }
            if ( isset( $_POST['notify_email_new'] ) ) {
                $candidates[] = (string) wp_unslash( $_POST['notify_email_new'] );
            }

            foreach ( $candidates as $line ) {
                $line = trim( $line );
                if ( '' === $line ) {
                    continue;
                }
                $clean = sanitize_email( $line );
                if ( $clean && is_email( $clean ) ) {
                    $valid[ strtolower( $clean ) ] = $clean;
                } else {
                    $rejected[] = $line;
                }
            }
            update_post_meta( $event_id, SFAF_Reminders::NOTIFY_EMAILS_META, array_values( $valid ) );

            if ( ! empty( $rejected ) ) {
                set_transient(
                    'sfaf_notify_rejected_' . $user->ID . '_' . $event_id,
                    array_slice( $rejected, 0, 10 ),
                    5 * MINUTE_IN_SECONDS
                );
            }
        }
    }

    /**
     * WHO GETS TOLD, AND WHEN. Two different emails, in the order they happen.
     *
     * THE FRAMING WAS BACKWARDS. This card used to open by explaining that
     * registrants get the morning-of reminder automatically and that everything
     * below was about who else gets a copy of it. That is the second thing this
     * card does. The first thing, and the reason a manager opens it, is "who
     * finds out when somebody signs up". That question was answered by a lone
     * checkbox in a card called Organizer contact, three cards away, which is
     * why that card looked like it had no purpose. It had one, it was just
     * filed under the wrong heading.
     *
     * So: registrations first, the reminder second, replies last. The two are
     * genuinely separate mechanisms and are not merged. A new registration
     * emails one address (SFAF_RSVP::handle_routing). The morning-of reminder
     * goes to a resolved list (SFAF_Reminders::notify_list). Presenting them as
     * one list would be a lie about what the software does.
     *
     * WHAT IS STORED, AND WHY THE CREATOR IS NOT A NAME IN A LIST. The list is
     * user ids and team ids, never addresses copied out of an account, so
     * changing somebody's address changes where their mail goes and nothing has
     * to be corrected here. The creator is not stored at all: they are derived
     * from post_author every time, so there is no second copy of "who made
     * this" to drift out of step with the first. What IS stored is the
     * opposite: a single flag recording that the creator took themselves off,
     * because "not opted out" is the default and an absent flag should mean
     * exactly that.
     *
     * @param WP_User $user
     * @param int     $event_id
     */
    private function render_notify_box( $user, $event_id ) {
        $post = get_post( $event_id );
        if ( ! $post ) {
            return;
        }

        $author        = get_userdata( $post->post_author );
        $author_on     = ! SFAF_Reminders::author_opted_out( $event_id );
        $chosen_users  = array_map( 'intval', (array) get_post_meta( $event_id, SFAF_Reminders::NOTIFY_USERS_META, true ) );
        $extra_emails  = (array) get_post_meta( $event_id, SFAF_Reminders::NOTIFY_EMAILS_META, true );
        $reminders_on  = SFAF_Reminders::enabled();

        $org_email  = (string) get_post_meta( $event_id, '_uc_organizer_email', true );
        $org_notify = ( '1' === (string) get_post_meta( $event_id, '_uc_notify_organizer', true ) );

        $portal_users = $this->calendar_people();

        // Addresses the last save could not use. Reported rather than dropped
        // in silence, because a typo that vanishes without comment reads as
        // "saved" to the person who typed it.
        $rejected_key = 'sfaf_notify_rejected_' . $user->ID . '_' . $event_id;
        $rejected     = get_transient( $rejected_key );
        if ( $rejected ) {
            delete_transient( $rejected_key );
        }
        ?>
        <?php // No card of its own since 3.9.0: this sits inside the editor's
              // Notifications card, which supplies the heading. ?>
        <div class="uc-notify-block">

            <?php // ---- 1. WHEN SOMEBODY REGISTERS. --------------------- ?>
            <div class="uc-notify-section">
                <h4 class="uc-notify-subhead">When somebody registers</h4>
                <p class="uc-hint">
                    One email, as it happens, to one address. Use a shared mailbox rather than a person if more than
                    one of you needs to see them.
                </p>
                <input type="hidden" name="uc_org_notify_present" value="1" />
                <label class="uc-check">
                    <input type="checkbox" name="notify_organizer" value="1" <?php checked( $org_notify ); ?> />
                    Email somebody each time an RSVP comes in
                </label>
                <label class="uc-field">
                    <span class="uc-field-label">Send those to</span>
                    <input type="email" name="organizer_email" value="<?php echo esc_attr( $org_email ); ?>"
                           placeholder="events@sfaf.org" />
                    <span class="uc-hint">
                        <?php if ( '' === $org_email ) : ?>
                            Nothing is set here, so registrations go to the site-wide address in Settings if there is one.
                        <?php endif; ?>
                    </span>
                </label>
            </div>

            <?php // ---- 2. THE MORNING-OF REMINDER. --------------------- ?>
            <div class="uc-notify-section">
                <h4 class="uc-notify-subhead">The morning-of reminder</h4>
                <p class="uc-hint">
                    Everyone who has registered gets this automatically. Below is who <em>else</em> receives a copy,
                    so staff can see what participants are sent. It changes nothing about who can edit this event.
                </p>

                <?php if ( ! $reminders_on ) : ?>
                    <p class="uc-field-note uc-field-note-attention"><?php echo $this->icon_needs(); ?><span>Reminder emails are currently switched off in Settings, so nothing on this list will be sent until they are switched back on.</span></p>
                <?php endif; ?>

                <input type="hidden" name="notify_list_present" value="1" />

                <?php if ( $author && is_email( $author->user_email ) ) : ?>
                    <label class="uc-check">
                        <input type="checkbox" name="notify_author" value="1" <?php checked( $author_on ); ?> />
                        Send to <strong><?php echo esc_html( $author->display_name ); ?></strong> (created this event, <?php echo esc_html( $author->user_email ); ?>)
                    </label>
                <?php else : ?>
                    <p class="uc-muted">This event's creator has no usable email address on file.</p>
                <?php endif; ?>

                <?php $this->render_notify_picker( $event_id, $author, $portal_users, $chosen_users ); ?>

                <?php
                /*
                 * ANYONE ELSE: PILLS, AND ONE ADDRESS AT A TIME.
                 *
                 * This was a textarea of one address per line, which meant a
                 * typo was not found until Save, was reported after the page
                 * had reloaded, and left the manager rereading their own
                 * block of text to find which line was wrong.
                 *
                 * EACH PILL IS A TICKED CHECKBOX, not a script-built widget.
                 * Untick and the address comes off the list; that works with
                 * scripting off, it posts correctly, and a screen reader gets
                 * a labelled checkbox rather than a div with an x in it. The
                 * script hides an unticked pill so the effect is immediate,
                 * but it is the checkbox doing the work either way.
                 *
                 * ADDING is one field and one button. With script the button
                 * validates and makes a pill on the spot, so a bad address is
                 * refused while the person is still looking at it. Without
                 * script the field simply posts and the server appends it,
                 * validating exactly as it always did.
                 */
                $notify_invalid = ( is_array( $rejected ) && ! empty( $rejected ) );
                ?>
                <div class="uc-field uc-emails-field" data-uc-emails>
                    <span class="uc-field-label">Anyone else</span>
                    <div class="uc-email-pills" data-uc-email-pills>
                        <?php foreach ( $extra_emails as $addr ) :
                            $addr = (string) $addr;
                            if ( '' === $addr ) { continue; } ?>
                            <label class="uc-email-pill">
                                <input type="checkbox" name="notify_emails[]" value="<?php echo esc_attr( $addr ); ?>"
                                       checked data-uc-email-pill />
                                <span class="uc-email-pill-text"><?php echo esc_html( $addr ); ?></span>
                                <span class="uc-email-pill-x" aria-hidden="true">&times;</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="uc-muted uc-emails-empty" data-uc-emails-empty<?php echo empty( $extra_emails ) ? '' : ' hidden'; ?>>
                        Nobody outside the calendar yet.
                    </p>

                    <div class="uc-email-add">
                        <input type="email" name="notify_email_new" id="uc-notify-email-new"
                               data-uc-email-input data-uc-not-a-field
                               placeholder="supervisor@example.org" autocomplete="off"
                               <?php echo $notify_invalid ? ' class="uc-invalid" aria-invalid="true" aria-describedby="uc-notify-emails-error"' : ''; ?> />
                        <button type="button" class="uc-btn uc-btn-sm uc-btn-primary" data-uc-email-add>Add</button>
                    </div>
                    <p class="uc-field-error" data-uc-email-error role="alert" hidden></p>
                    <?php if ( $notify_invalid ) : ?>
                        <p class="uc-field-error" id="uc-notify-emails-error" data-uc-for="uc-notify-email-new" role="alert">
                            <?php echo esc_html( 1 === count( $rejected ) ? 'This is not an email address, so it was not saved: ' : 'These are not email addresses, so they were not saved: ' ); ?>
                            <?php echo esc_html( implode( ', ', $rejected ) ); ?>
                        </p>
                    <?php endif; ?>
                    <span class="uc-hint">
                        For people outside the calendar system: a supervisor, a co-host. Untick an address to take it
                        off the list.
                    </span>
                </div>
            </div>

            <?php
            /*
             * ---- 3. REPLIES IS NOT DRAWN HERE. ---------------------------
             *
             * It used to be, and that is what put the field on screen twice.
             * This method IS the 'notify' setting; 'replyto' is a setting of
             * its own in rsvp_setting_fields(), so the catch-all call that
             * renders this one also renders that one. A field drawn inside
             * another field's renderer is invisible to the placement
             * bookkeeping, which is the whole mechanism that stops anything
             * appearing twice. So it draws itself, in order, right after this
             * block, carrying its own heading. See render_rsvp_setting().
             */
            ?>

            <?php
            // What actually went out, if anything has. The ledger is the record
            // of record for "did they get it?", so it is shown where the
            // question gets asked. The "currently on the list" block that used
            // to sit here is gone: it restated what the picker already shows,
            // one save behind, so the two disagreed for as long as it took to
            // press Save and nobody could tell which was true.
            $sent = SFAF_Reminders::log_for_event( $event_id );
            if ( ! empty( $sent ) ) : ?>
                <div class="uc-notify-section">
                    <h4 class="uc-notify-subhead">Reminder log for this event</h4>
                    <table class="uc-table">
                        <thead><tr><th>Recipient</th><th>Type</th><th>Result</th><th>When</th></tr></thead>
                        <tbody>
                        <?php foreach ( $sent as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row->email ); ?></td>
                                <td><?php echo esc_html( $row->recipient_type ); ?></td>
                                <td><?php echo esc_html( $row->result ); ?></td>
                                <td><?php echo esc_html( $row->sent_at ? $row->sent_at : $row->claimed_at ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Who appears in a picker. NOT the same question as who has access.
     *
     * TWO CONCEPTS, AND KEEPING THEM APART IS THE POINT.
     *
     *   ACCESS is decided by manage_options, first, in get_role(). A WordPress
     *   administrator has full calendar access and can never be locked out of
     *   it. That is the 3.7.0 fix and nothing here touches it.
     *
     *   VISIBILITY is decided by having a calendar user record, which is what
     *   Users and Permissions creates. Only people with a record appear in the
     *   notification picker and the team picker.
     *
     * So an administrator who has not added themselves has full access and does
     * NOT appear in pickers, and that is correct: a picker is a list of the
     * people who work on this calendar, not a list of everybody who could. The
     * way to appear in one is to be added, which takes one action on a screen
     * that exists for it.
     *
     * 3.14.0 GOT THIS WRONG AND IS CORRECTED HERE. Administrators were missing
     * from the pickers and the fix applied was to widen the list to include
     * them, which quietly made "can access" and "is a colleague on this
     * calendar" the same list. The real fault was that nothing said which
     * question the picker was asking. It asks the second one.
     *
     * @return WP_User[] Display-name order.
     */
    private function calendar_people() {
        $people = get_users( array(
            'meta_key'     => '_uc_calendar_role',
            'meta_compare' => 'EXISTS',
            'orderby'      => 'display_name',
            'order'        => 'ASC',
            'number'       => 200,
        ) );
        return is_array( $people ) ? $people : array();
    }

    /**
     * The notification picker: one control, two tabs, individuals and teams.
     *
     * WHY A DISCLOSURE AND NOT A LIST. The list it replaces rendered every
     * calendar user as a checkbox, always open, in the middle of the event
     * form. At a dozen users that is a paragraph; at a few hundred it is a
     * screenful of names between the manager and the Save button, and there
     * was no way to find anybody in it except to read. This is shut until it
     * is wanted, says what is currently chosen when shut, and has a filter
     * field at the top when open.
     *
     * WHY THE CHECKBOXES ARE ALL STILL IN THE FORM. Filtering hides rows; it
     * never removes them. A checkbox that is display:none still posts, so
     * typing a name to find one person cannot silently deselect the four
     * chosen earlier. The filter is a view over the list, not an edit of it.
     *
     * WITHOUT JAVASCRIPT it is a <details> element containing every checkbox,
     * both tab panels visible one after the other, and the server-rendered
     * summary. Everything can still be chosen and saved; what is lost is the
     * filtering and the live count. Nothing here is built by script.
     *
     * THE COUNT IS RESOLVED, NOT COUNTED. Selecting two individuals and a team
     * of six does not necessarily mean eight people, because a team member may
     * be one of the individuals, or the creator. The summary deduplicates by
     * email address, which is exactly what SFAF_Reminders::notify_list() does
     * when the mail goes out, so the number shown is the number of messages.
     *
     * @param int       $event_id
     * @param WP_User   $author
     * @param WP_User[] $portal_users
     * @param int[]     $chosen_users
     */
    private function render_notify_picker( $event_id, $author, $portal_users, $chosen_users ) {
        $teams        = SFAF_Teams::all();
        $chosen_teams = SFAF_Teams::for_event( $event_id );

        /*
         * What the browser needs to recompute the count as boxes are ticked:
         * one address per user, lowercased, and each team's membership as user
         * ids. Addresses rather than ids because deduplication happens on the
         * address, the same key the server uses. Every one of these addresses
         * is already on this screen as a checkbox label.
         */
        $payload = array(
            'users'  => array(),
            'teams'  => array(),
            'author' => '',
        );
        foreach ( $portal_users as $pu ) {
            if ( is_email( $pu->user_email ) ) {
                $payload['users'][ (string) $pu->ID ] = strtolower( trim( $pu->user_email ) );
            }
        }
        foreach ( $teams as $team ) {
            $emails = array();
            foreach ( $team['users'] as $uid ) {
                $member = get_userdata( $uid );
                if ( $member && is_email( $member->user_email ) ) {
                    $emails[] = strtolower( trim( $member->user_email ) );
                }
            }
            $payload['teams'][ $team['id'] ] = array(
                'name'   => $team['name'],
                'emails' => array_values( array_unique( $emails ) ),
            );
        }
        if ( $author && is_email( $author->user_email ) ) {
            $payload['author'] = strtolower( trim( $author->user_email ) );
        }

        $uid_attr = 'uc-notify-' . (int) $event_id;
        ?>
        <div class="uc-notify-picker" data-uc-notify-picker>
            <details class="uc-picker" data-uc-picker>
                <summary class="uc-picker-toggle" data-uc-picker-toggle>
                    <span class="uc-picker-label">Choose who else gets it</span>
                    <span class="uc-picker-count" data-uc-picker-count><?php
                        echo esc_html( $this->notify_summary_text( $event_id, $chosen_users, $chosen_teams, $teams ) );
                    ?></span>
                </summary>

                <div class="uc-picker-panel">
                    <?php
                    /*
                     * Real tabs: a tablist of buttons controlling two panels,
                     * with arrow keys between them. Without script both panels
                     * are shown, each under its own heading, because a tab that
                     * cannot be switched is just a hidden panel.
                     */
                    ?>
                    <div class="uc-picker-tabs" role="tablist" aria-label="Who to notify" data-uc-picker-tabs>
                        <button type="button" role="tab" class="uc-picker-tab"
                                id="<?php echo esc_attr( $uid_attr ); ?>-tab-people"
                                aria-controls="<?php echo esc_attr( $uid_attr ); ?>-panel-people"
                                aria-selected="true" data-uc-picker-tab="people">Individuals</button>
                        <button type="button" role="tab" class="uc-picker-tab"
                                id="<?php echo esc_attr( $uid_attr ); ?>-tab-teams"
                                aria-controls="<?php echo esc_attr( $uid_attr ); ?>-panel-teams"
                                aria-selected="false" tabindex="-1" data-uc-picker-tab="teams">Teams</button>
                    </div>

                    <div role="tabpanel" class="uc-picker-panel-body"
                         id="<?php echo esc_attr( $uid_attr ); ?>-panel-people"
                         aria-labelledby="<?php echo esc_attr( $uid_attr ); ?>-tab-people"
                         data-uc-picker-panel="people">
                        <h4 class="uc-picker-heading">Individuals</h4>
                        <?php
                        /*
                         * THE LIST IS TESTED, NOT THE SOURCE OF THE LIST.
                         *
                         * This branch used to ask whether $portal_users was
                         * empty, then render a filter box over a list built by
                         * excluding the creator from it. On a calendar where
                         * every listed person IS the creator, that produced a
                         * search field above nothing, and typing anything at
                         * all reported "Nobody matches that" because there was
                         * genuinely nobody to match. Count what will actually
                         * be drawn.
                         */
                        $pickable = array();
                        foreach ( $portal_users as $pu ) {
                            if ( $author && (int) $pu->ID === (int) $author->ID ) {
                                continue;
                            }
                            $pickable[] = $pu;
                        }
                        ?>
                        <?php if ( empty( $pickable ) ) : ?>
                            <p class="uc-muted">
                                <?php echo empty( $portal_users )
                                    ? 'No calendar users yet. An admin can add people under Users.'
                                    : 'Nobody else has calendar access yet, so there is no one to add here.'; ?>
                            </p>
                        <?php else : ?>
                            <label class="uc-picker-filter">
                                <span class="uc-visually-hidden">Filter people by name or address</span>
                                <?php
                                /*
                                 * data-uc-not-a-field IS THE FIX, AND IT IS A
                                 * CATEGORY CORRECTION RATHER THAN A PATCH.
                                 *
                                 * The edit-scope lock walks every input in the
                                 * form and makes it readonly until its field's
                                 * pencil is pressed. This box is not a field on
                                 * the event: it changes nothing, saves nothing
                                 * and posts nothing, it only narrows the list
                                 * below it. Locking it made the picker
                                 * unusable at any real size on exactly the
                                 * events that need it most, since a recurring
                                 * event is the one that has a scope choice.
                                 * See initEditScope() in portal.js.
                                 */
                                ?>
                                <input type="search" placeholder="Type to filter people…"
                                       data-uc-picker-filter="people" data-uc-not-a-field autocomplete="off" />
                            </label>
                            <div class="uc-picker-options" data-uc-picker-options="people">
                                <?php foreach ( $pickable as $pu ) : ?>
                                    <label class="uc-check uc-picker-option"
                                           data-uc-picker-search="<?php echo esc_attr( strtolower( $pu->display_name . ' ' . $pu->user_email ) ); ?>">
                                        <input type="checkbox" name="notify_users[]" value="<?php echo (int) $pu->ID; ?>"
                                               data-uc-picker-user="<?php echo (int) $pu->ID; ?>"
                                               <?php checked( in_array( (int) $pu->ID, $chosen_users, true ) ); ?> />
                                        <span><?php echo esc_html( $pu->display_name ); ?>
                                            <span class="uc-muted"><?php echo esc_html( $pu->user_email ); ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="uc-muted uc-picker-empty" data-uc-picker-empty="people" hidden>Nobody matches that.</p>
                        <?php endif; ?>
                    </div>

                    <div role="tabpanel" class="uc-picker-panel-body"
                         id="<?php echo esc_attr( $uid_attr ); ?>-panel-teams"
                         aria-labelledby="<?php echo esc_attr( $uid_attr ); ?>-tab-teams"
                         data-uc-picker-panel="teams">
                        <h4 class="uc-picker-heading">Teams</h4>
                        <?php if ( empty( $teams ) ) : ?>
                            <p class="uc-muted">No teams yet. An admin can make one under Users.</p>
                        <?php else : ?>
                            <div class="uc-picker-options" data-uc-picker-options="teams">
                                <?php foreach ( $teams as $team ) : $tsize = SFAF_Teams::size( $team['id'] ); ?>
                                    <label class="uc-check uc-picker-option">
                                        <input type="checkbox" name="notify_teams[]" value="<?php echo esc_attr( $team['id'] ); ?>"
                                               data-uc-picker-team="<?php echo esc_attr( $team['id'] ); ?>"
                                               <?php checked( in_array( $team['id'], $chosen_teams, true ) ); ?> />
                                        <span><?php echo esc_html( $team['name'] ); ?>
                                            <span class="uc-muted"><?php echo (int) $tsize; ?> <?php echo esc_html( 1 === $tsize ? 'person' : 'people' ); ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="uc-hint">A team is resolved when the reminder is sent, so it always reaches whoever is in it then, not whoever was in it today.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </details>

            <?php
            /*
             * WHO IS CHOSEN, AS CHIPS, BELOW THE PICKER.
             *
             * THE LIST ABOVE IS FOR FINDING PEOPLE, NOT FOR HOLDING THE
             * ANSWER. A scrolling column of forty checkboxes with six ticked
             * somewhere in it makes "who is on this list" a question you
             * answer by scrolling, and the answer changes as you filter. The
             * chips are the answer, they sit still, and they read the same way
             * as the address pills further down the card, so the whole card
             * says one thing in one language.
             *
             * BUILT BY SCRIPT, AND HIDDEN UNTIL IT IS. Same arrangement as the
             * category chips: the checkboxes ARE the form and post on their
             * own, so with the script gone this container never appears and
             * the plain list is exactly the control it has always been.
             * Nothing here is the only way to do anything.
             */
            ?>
            <div class="uc-rchips" data-uc-notify-chips hidden>
                <span class="uc-rchips-label">On the list</span>
                <div class="uc-rchips-list" data-uc-notify-chips-list></div>
                <p class="uc-muted uc-rchips-empty" data-uc-notify-chips-empty hidden>
                    Nobody else yet. Open the picker above to add somebody.
                </p>
            </div>

            <?php // The marker that tells the save "this form carried the teams
                  // block", so clearing every box means clear rather than
                  // "leave alone". Same reasoning as notify_list_present. ?>
            <input type="hidden" name="notify_teams_present" value="1" />

            <script type="application/json" data-uc-notify-data><?php
                echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
            ?></script>
        </div>
        <?php
    }

    /**
     * The one-line summary of what is currently selected.
     *
     * Rendered by PHP for the page load and recomputed by portal.js on every
     * tick, from the same rule: count the distinct addresses. Both say
     * "3 individuals, Philanthropy team (6 people), 7 people in total" so a
     * manager can see both what they chose and what it comes to.
     *
     * @param int      $event_id
     * @param int[]    $chosen_users
     * @param string[] $chosen_teams
     * @param array[]  $teams
     * @return string
     */
    private function notify_summary_text( $event_id, $chosen_users, $chosen_teams, $teams ) {
        $parts = array();
        $n     = count( $chosen_users );
        if ( $n ) {
            $parts[] = $n . ' ' . ( 1 === $n ? 'individual' : 'individuals' );
        }
        foreach ( $chosen_teams as $tid ) {
            if ( ! isset( $teams[ $tid ] ) ) {
                continue;
            }
            $size    = SFAF_Teams::size( $tid );
            $parts[] = $teams[ $tid ]['name'] . ' team (' . $size . ' ' . ( 1 === $size ? 'person' : 'people' ) . ')';
        }

        if ( empty( $parts ) ) {
            return 'Nobody chosen yet';
        }

        // The total is the resolved list, which already deduplicates and
        // already includes the creator and any typed addresses. It is the
        // honest answer to "how many emails is that".
        $total = count( SFAF_Reminders::notify_list( $event_id ) );
        return implode( ', ', $parts ) . '. ' . $total . ' ' . ( 1 === $total ? 'person' : 'people' ) . ' in total.';
    }

    /* =====================================================================
     * Rendering — edit scope
     * ================================================================== */

    /**
     * The scope choice, at the TOP of the form, made BEFORE anything is edited.
     *
     * TWO BUTTONS, NOT THREE RADIOS AT THE BOTTOM. The old control offered
     * "only this event", "this and later occurrences" and "all occurrences,
     * including past ones", sat below the form, and defaulted to one of them —
     * so a manager could type a new time into a field, scroll past a control
     * they had already stopped reading, and save. Worse, one of the three
     * choices could rewrite events that had already happened.
     *
     * What replaces it:
     *
     *   CHOSEN FIRST. Every field is locked until a scope is picked, so nothing
     *   can be edited before the manager has decided how it saves. There is no
     *   default, because a default is a decision made by whoever wrote the
     *   form rather than by the person about to change twelve events.
     *
     *   THE TARGET IS THE RECURRENCE GROUP, NOT THE SERIES. A series may hold
     *   an educational session one week and a social the next, so a series-wide
     *   edit would be wrong. A recurrence group is a set of events generated
     *   together from one pattern, identical by default. See
     *   SFAF_Recurrence's header note on why these are two different things.
     *
     *   PAST OCCURRENCES ARE NOT REACHABLE. "All upcoming" means exactly that:
     *   events whose date has passed are the historical record and no scope
     *   offers to rewrite them.
     *
     *   ONLY WHERE THERE IS A CHOICE. Shown when the event is in a recurrence
     *   group with more than one upcoming occurrence. A one-off event just
     *   opens for editing — a scope question with one possible answer is not a
     *   question.
     *
     *   ASKED AS A MODAL, NOT AS A PANEL. Two buttons in a tinted box at the
     *   top of the page are easy to scroll past, and a manager who scrolled
     *   past them met a form where nothing could be typed and no explanation
     *   of why. The question now arrives as a dialog over a frozen editor:
     *   it is the only thing on screen that can be operated, so it cannot be
     *   missed and cannot be postponed. Declining it leaves the editor rather
     *   than sitting in it undecided.
     *
     * THE MARKUP IS STILL A PLAIN BLOCK IN THE PAGE. portal.js lifts it into a
     * real <dialog> and opens it modally; the browser's own top layer then
     * supplies the backdrop, the focus trap and the Escape key rather than
     * this reimplementing three things it would get wrong. Without JavaScript,
     * or in a browser with no <dialog>, it stays exactly what it is here: a
     * panel at the top of the form, which is what the previous version was.
     * So the enhancement can fail and leave a usable screen behind.
     *
     * @param int   $event_id
     * @param int[] $targets     Upcoming events in this event's recurrence group.
     * @param array $locked      field => reason, from bulk_locked_fields().
     */
    private function render_scope_header( $event_id, $targets, $locked ) {
        $count = count( $targets );
        if ( ! $event_id || $count < 2 ) {
            return;
        }
        ?>
        <div class="uc-scope" data-uc-scope-choice data-uc-scope-count="<?php echo (int) $count; ?>"
             data-uc-scope-back="<?php echo esc_url( $this->url( 'events' ) ); ?>">
            <h2 class="uc-scope-title" id="uc-scope-title">How should this save apply?</h2>
            <p class="uc-scope-lead" id="uc-scope-lead">
                This event is one of <strong><?php echo (int) $count; ?></strong> upcoming occurrences generated from
                the same pattern. Choose before you edit. Until you do, nothing on this event can be changed.
            </p>
            <div class="uc-scope-buttons">
                <button type="button" class="uc-btn uc-scope-btn" data-uc-scope="this">
                    Edit this event
                    <span class="uc-scope-btn-sub">The other <?php echo (int) ( $count - 1 ); ?> upcoming
                        occurrence<?php echo ( 2 === $count ) ? '' : 's'; ?> stay as
                        <?php echo ( 2 === $count ) ? 'it is' : 'they are'; ?>.</span>
                </button>
                <button type="button" class="uc-btn uc-scope-btn uc-scope-btn-all" data-uc-scope="all_upcoming">
                    Edit all <?php echo (int) $count; ?> upcoming occurrences
                    <span class="uc-scope-btn-sub">One save changes every one of them.</span>
                </button>
            </div>
            <p class="uc-hint">
                Past occurrences are never changed by either choice. The events in this group are not necessarily the
                whole series: a series can hold different kinds of event, which is why an edit travels through
                the group rather than through the series.
            </p>
            <?php
            /*
             * DECLINING IS A REAL ANSWER AND HAS A REAL BUTTON.
             *
             * A dialog that can only be answered one of two ways traps anybody
             * who opened the wrong event. This goes back to where they came
             * from, which is the honest outcome: no scope was chosen, so no
             * editing starts.
             */
            ?>
            <div class="uc-scope-dismiss" data-uc-scope-dismiss-row hidden>
                <button type="button" class="uc-btn uc-btn-sm" data-uc-scope-cancel>Cancel and go back</button>
            </div>
            <noscript>
                <p class="uc-scope-noscript">
                    Choosing a scope needs JavaScript. With it switched off this form saves this event only.
                </p>
            </noscript>
        </div>

        <?php // Stays visible while a long form scrolls, because the whole
              // point is that the manager can see what this save will do at the
              // moment they press the button, not only at the moment they
              // chose. It also takes focus when the dialog closes, so the
              // answer is the first thing announced after the question. ?>
        <div class="uc-scope-banner" data-uc-scope-banner hidden role="status" tabindex="-1">
            <span data-uc-scope-banner-text></span>
            <button type="button" class="uc-btn uc-btn-sm" data-uc-scope-change>Change</button>
        </div>

        <?php
        /*
         * The chosen scope, and the fields that carry no pencil when it is
         * "all upcoming". Both are read straight back by
         * save_event_from_post(), which re-derives the locked list server-side
         * rather than trusting this — the markup is the affordance, not the
         * rule.
         */
        ?>
        <input type="hidden" name="edit_scope" value="this" data-uc-scope-input />
        <script type="application/json" id="uc-scope-locked"><?php
            echo wp_json_encode( $locked, JSON_HEX_TAG | JSON_HEX_AMP );
        ?></script>
        <?php
    }

    /* =====================================================================
     * Rendering — email opt-ins
     * ================================================================== */

    private function render_optins( $user ) {
        if ( ! $this->can_view_all( $user ) ) {
            $this->chrome_open( $user, 'optins' );
            echo '<div class="uc-card"><p class="uc-empty">You don\'t have permission to view email opt-ins.</p></div>';
            $this->chrome_close();
            return;
        }

        $this->chrome_open( $user, 'optins' );
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $rows   = SFAF_Optins::all( array( 'search' => $search ) );
        $export = wp_nonce_url( $this->url( 'optins/export' ) . ( $search ? '?s=' . rawurlencode( $search ) : '' ), 'uc_portal_export' );
        ?>
        <div class="uc-page-head">
            <h1>Email Opt-ins</h1>
            <div class="uc-head-actions">
                <a class="uc-btn" href="<?php echo esc_url( $export ); ?>">Export CSV</a>
            </div>
        </div>

        <div class="uc-card">
            <div class="uc-card-head"><h2>What this record is</h2></div>
            <p class="uc-hint">
                People who ticked &ldquo;Receive monthly email updates from SFAF&rdquo; on an RSVP form, with the
                moment consent was given and the form it came from.
                <strong>Nothing is sent from here and nothing is pushed anywhere.</strong>
            </p>
            <?php
            /*
             * .uc-filters-bar, WHICH IS WHAT EVERY OTHER SEARCH ON THIS PORTAL
             * WEARS. It was .uc-inline-form, and that class styles a `select`
             * and nothing else, so the search box here was the only text field
             * in caladmin still being drawn by the browser. 3.16.0.
             */
            ?>
            <form method="get" class="uc-filters-bar">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name or email" />
                <button type="submit" class="uc-btn uc-btn-sm">Search</button>
            </form>
        </div>

        <div class="uc-card">
            <div class="uc-card-head">
                <h2><?php echo count( $rows ); ?> <?php echo esc_html( 1 === count( $rows ) ? "opt-in" : "opt-ins" ); ?></h2>
            </div>
            <?php if ( empty( $rows ) ) : ?>
                <p class="uc-empty">No opt-ins recorded<?php echo $search ? ' for that search' : ' yet'; ?>.</p>
            <?php else : ?>
                <table class="uc-table">
                    <thead><tr><th>Email</th><th>Name</th><th>Consented</th><th>Form</th><th>Event</th></tr></thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row->email ); ?></td>
                                <td><?php echo esc_html( $row->name ); ?></td>
                                <td><?php echo esc_html( $row->consented_at ); ?></td>
                                <td><?php echo esc_html( $row->source_form ); ?></td>
                                <td><?php echo $row->event_title ? esc_html( $row->event_title ) : '--'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        $this->chrome_close();
    }

    /* =====================================================================
     * Rendering — RSVPs
     * ================================================================== */

    /**
     * Registrations: for one event, or the orphans, or everything.
     *
     * THE WAY IN IS THE EVENT NOW. The RSVP count on the Events list is a link
     * to this screen filtered to that event, because "who is coming to Santa
     * Skivvies" is the question people actually have. A flat list of every
     * registration ever taken, across every event, answers nothing on its own,
     * which is why it is no longer in the sidebar.
     *
     * IT IS STILL REACHABLE, AND HAS TO BE. Registrations outlive their events
     * on purpose: the rows are kept when an event is deleted and carry a title
     * snapshot so they read "Santa Skivvies (deleted)". Those rows have no
     * event to be reached through. So this screen keeps its URL, gains an
     * "orphans only" mode, and the Events list links to it whenever there is
     * anything in it. Retiring the tab must not make that history unreachable,
     * and does not.
     *
     * THE GATE. can_view_all, matching the CSV export beside it and the nav
     * entry that used to lead here. This check is new: the screen relied on
     * not being linked, which is not a permission. A contributor who typed the
     * URL saw every registration on the calendar. See the note in the readme.
     *
     * @param WP_User $user
     */
    private function render_rsvps( $user ) {
        if ( ! $this->can_view_all( $user ) ) {
            $this->render_dashboard( $user );
            return;
        }

        $this->chrome_open( $user, 'events' );
        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $orphans  = ! empty( $_GET['orphans'] );
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $rsvps    = SFAF_RSVP::get_all_rsvps( array(
            'event_id' => $event_id,
            'search'   => $search,
            'orphans'  => $orphans,
        ) );

        $event = $event_id ? get_post( $event_id ) : null;
        $title = 'All registrations';
        if ( $event ) {
            $title = get_the_title( $event_id ) ? get_the_title( $event_id ) : '(untitled event)';
        } elseif ( $orphans ) {
            $title = 'Registrations for deleted events';
        }

        $export = add_query_arg(
            array_filter( array(
                'event_id' => $event_id,
                's'        => $search,
                'orphans'  => $orphans ? 1 : '',
                '_wpnonce' => wp_create_nonce( 'uc_portal_export' ),
            ) ),
            $this->url( 'rsvps/export' )
        );
        $orphan_total = SFAF_RSVP::orphan_count();
        ?>
        <div class="uc-page-head">
            <h1><?php echo esc_html( $title ); ?></h1>
            <div class="uc-page-head-actions">
                <?php if ( $event ) : ?>
                    <a href="<?php echo esc_url( $this->url( 'events/edit/' . (int) $event_id ) ); ?>" class="uc-btn">Edit event</a>
                <?php endif; ?>
                <a href="<?php echo esc_url( $this->url( 'events' ) ); ?>" class="uc-btn">&larr; Events</a>
                <a href="<?php echo esc_url( $export ); ?>" class="uc-btn uc-btn-primary">Export CSV</a>
            </div>
        </div>

        <?php
        /*
         * NO SENSITIVITY BANNER HERE, AND NONE ON THE EXPORT. Removed in
         * 3.16.0 by instruction. It stood between the page head and the list
         * from 3.5.0 to 3.15.0.
         *
         * WHAT STILL GUARDS THIS SCREEN IS UNCHANGED, and it is the part that
         * was ever load-bearing: render_rsvps() and export_rsvps_csv() both
         * begin with can_view_all(), and the export also verifies the
         * uc_portal_export nonce. Removing a paragraph removes a paragraph.
         */
        ?>
        <?php if ( $event ) : ?>
            <p class="uc-hint">
                Registrations for this event only.
                <a href="<?php echo esc_url( $this->url( 'rsvps' ) ); ?>">All registrations across every event</a>.
            </p>

            <?php
            /*
             * THE EVENT'S REGISTRATION SETTINGS, HERE, WHERE THE QUESTION GETS
             * ASKED.
             *
             * "Why has nobody registered" and "who else is being told about
             * this" are asked while looking at this list, and the answers were
             * only in the event editor: capacity, whether registrations are
             * even switched on, who is notified, where replies go. Somebody had
             * to leave the list, find the event, scroll a form and come back.
             *
             * NOT A SECOND COPY OF THOSE CONTROLS. These are drawn by
             * render_rsvp_settings() and saved by
             * save_rsvp_settings_from_post(), which are the same two methods
             * the editor uses. Neither screen holds a list of what the settings
             * are, so one of them cannot grow a field the other lacks. Same
             * rule that has kept the editor and the pending queue in step since
             * 3.2.0.
             */
            $rsvp_ctx = $this->rsvp_settings_context( $user, $event_id );
            ?>
            <?php
            /*
             * CLOSED WHEN THE PAGE OPENS. ALWAYS.
             *
             * It used to open itself whenever the list was empty. Somebody
             * arriving here came for the registrations; the settings are a
             * convenience, and a panel that decides for itself when to be open
             * moves the list down the page for a reason the reader cannot see.
             *
             * THE CHEVRON IS THE AFFORDANCE, and it is on a <summary>, which is
             * a real interactive element: click, tap, Enter and Space all work
             * with no script, and the disclosure still opens if portal.js never
             * runs. aria-expanded is written here for the closed state and kept
             * in step by initDisclosures() in portal.js, because the attribute
             * is what a screen reader reads and the browser's own details state
             * is not exposed consistently.
             */
            $cap_now = (int) get_post_meta( $event_id, '_uc_capacity', true );
            $on_now  = ( '1' === (string) get_post_meta( $event_id, '_uc_rsvp_enabled', true ) );
            ?>
            <details class="uc-card uc-rsvp-settings" data-uc-disclosure>
                <summary class="uc-rsvp-settings-toggle" aria-expanded="false">
                    <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '18px' ) ); ?></span>
                    <span class="uc-rsvp-settings-id">
                        <span class="uc-rsvp-settings-name"><strong>Registration settings</strong> for this event</span>
                        <span class="uc-muted">
                            <?php
                            echo esc_html( $on_now ? 'Accepting registrations' : 'Not accepting registrations' );
                            echo esc_html( $cap_now > 0 ? ', capacity ' . $cap_now : ', no capacity limit' );
                            ?>
                        </span>
                    </span>
                </summary>
                <form method="post" action="<?php echo esc_url( $this->url( 'rsvps' ) ); ?>" class="uc-rsvp-settings-form">
                    <input type="hidden" name="uc_action" value="save_rsvp_settings" />
                    <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                    <?php wp_nonce_field( 'uc_portal_save_rsvp_settings', 'uc_nonce' ); ?>
                    <?php
                    /*
                     * FOUR SUBSECTIONS, NOT ONE LONG BLOCK.
                     *
                     * The editor splits these across two cards, so its headings
                     * come from the cards. Here all four settings are in one
                     * card and the block ran top to bottom with nothing saying
                     * where one subject ended and the next began.
                     *
                     * Registrations is claimed by name and given its own
                     * section; the catch-all takes the remainder, which is the
                     * notify block (two sections of its own) and Replies. THE
                     * CATCH-ALL IS STILL LAST AND STILL $only === null, so the
                     * guarantee that every setting renders exactly once is
                     * untouched: a setting added to rsvp_setting_fields()
                     * appears here without this screen being told about it, and
                     * $skip is what stops these two being drawn twice. Same
                     * rule as the manager-owned fields since 3.2.0.
                     */
                    ?>
                    <div class="uc-notify-section">
                        <h4 class="uc-notify-subhead">Registrations</h4>
                        <p class="uc-hint">Whether the form is on the event page, and how many places there are.</p>
                        <?php $rsvp_placed = $this->render_rsvp_settings( $rsvp_ctx, array( 'rsvp_enabled', 'capacity' ) ); ?>
                    </div>
                    <?php $this->render_rsvp_settings( $rsvp_ctx, null, $rsvp_placed ); ?>
                    <div class="uc-form-actions">
                        <a class="uc-btn" href="<?php echo esc_url( $this->url( 'events/edit/' . (int) $event_id ) ); ?>">Edit the whole event</a>
                        <button type="submit" class="uc-btn uc-btn-primary">Save settings</button>
                    </div>
                </form>
            </details>
        <?php elseif ( $orphans ) : ?>
            <p class="uc-hint">
                Registrations whose event has been deleted. The name shown is the title the event had when it was
                deleted.
                <a href="<?php echo esc_url( $this->url( 'rsvps' ) ); ?>">All registrations</a>.
            </p>
        <?php elseif ( $orphan_total ) : ?>
            <p class="uc-hint">
                <a href="<?php echo esc_url( add_query_arg( 'orphans', 1, $this->url( 'rsvps' ) ) ); ?>">
                    <?php echo (int) $orphan_total; ?> <?php echo esc_html( 1 === $orphan_total ? 'registration belongs' : 'registrations belong' ); ?> to events that have been deleted</a>.
            </p>
        <?php endif; ?>

        <form method="get" action="<?php echo esc_url( $this->url( 'rsvps' ) ); ?>" class="uc-filters-bar">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name or email…" />
            <?php if ( $event_id ) : ?><input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" /><?php endif; ?>
            <?php if ( $orphans ) : ?><input type="hidden" name="orphans" value="1" /><?php endif; ?>
            <button class="uc-btn" type="submit">Search</button>
        </form>

        <?php
        /*
         * WHO ALSO SAID YES TO THE MAILING LIST.
         *
         * Consent lives in its own table and is deliberately not a column on
         * the registration: they are two different permissions and an RSVP row
         * must never be the evidence for a mailing list. This marks the rows
         * without merging the two, in one query for the whole page. See
         * SFAF_Optins::consent_index().
         */
        $consented = SFAF_Optins::consent_index( wp_list_pluck( $rsvps, 'email' ) );
        ?>

        <div class="uc-card">
            <div class="uc-card-head"><h2><?php echo count( $rsvps ); ?> registrations</h2></div>
            <?php if ( empty( $rsvps ) ) : ?>
                <?php
                /*
                 * AN EMPTY STATE THAT SAYS WHY IT IS EMPTY.
                 *
                 * The count on the Events list links here at zero now, so
                 * arriving at an empty page is a normal thing to do rather
                 * than a wrong turn. "No RSVPs found" answered none of the
                 * three questions somebody actually has: is anybody
                 * registered, are registrations even switched on, and did my
                 * search just not match.
                 */
                ?>
                <?php if ( '' !== $search ) : ?>
                    <p class="uc-empty">
                        Nothing matches &ldquo;<?php echo esc_html( $search ); ?>&rdquo;.
                        <a href="<?php echo esc_url( $event_id ? add_query_arg( 'event_id', $event_id, $this->url( 'rsvps' ) ) : $this->url( 'rsvps' ) ); ?>">Clear the search</a>.
                    </p>
                <?php elseif ( $event ) : ?>
                    <p class="uc-empty">
                        Nobody has registered for this event yet.
                        <?php if ( '1' !== (string) get_post_meta( $event_id, '_uc_rsvp_enabled', true ) ) : ?>
                            Registrations are switched off for it, so the form is not on the event page. Turn them on in
                            the registration settings above.
                        <?php else : ?>
                            Registrations are switched on, so the form is on the event page and anybody who registers
                            will appear here.
                        <?php endif; ?>
                    </p>
                <?php elseif ( $orphans ) : ?>
                    <p class="uc-empty">No registrations belong to a deleted event.</p>
                <?php else : ?>
                    <p class="uc-empty">Nobody has registered for anything yet.</p>
                <?php endif; ?>
            <?php else : ?>
                <table class="uc-table">
                    <thead><tr><?php if ( ! $event ) : ?><th>Event</th><?php endif; ?><th>Name</th><th>Email</th><th>Phone</th><th>Updates</th><th>Status</th><th>Registered</th></tr></thead>
                    <tbody>
                    <?php foreach ( $rsvps as $r ) : ?>
                        <tr>
                            <?php if ( ! $event ) : ?>
                                <td><?php
                                    // Linked when the event still exists, plain
                                    // text when it does not. event_label() is
                                    // what appends "(deleted)".
                                    $label = SFAF_RSVP::event_label( $r );
                                    $live  = ( ! empty( $r->post_title ) || get_post( (int) $r->event_id ) );
                                    if ( $live ) {
                                        echo '<a class="uc-tlink" href="' . esc_url( add_query_arg( 'event_id', (int) $r->event_id, $this->url( 'rsvps' ) ) ) . '">' . esc_html( $label ) . '</a>';
                                    } else {
                                        echo esc_html( $label );
                                    }
                                ?></td>
                            <?php endif; ?>
                            <td><strong><?php echo esc_html( $r->name ); ?></strong></td>
                            <td><?php echo esc_html( $r->email ); ?></td>
                            <td><?php echo esc_html( $r->phone ); ?></td>
                            <td><?php
                                $opted = isset( $consented[ strtolower( trim( (string) $r->email ) ) . '|' . (int) $r->event_id ] );
                                if ( $opted ) {
                                    // Said in words as well as marked, because a
                                    // tick on its own does not say what it is a
                                    // tick for, and this one is a consent.
                                    echo '<span class="uc-optin-yes" title="Ticked &quot;SFAF news and updates&quot; on this registration">'
                                        . '<span aria-hidden="true">&#10003;</span> <span>Updates</span></span>';
                                } else {
                                    echo '<span class="uc-muted">&ndash;</span>';
                                }
                            ?></td>
                            <td><span class="uc-pill uc-pill-<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( sfaf_rsvp_status_label( $r->status ) ); ?></span></td>
                            <td><?php echo esc_html( date_i18n( 'M j, Y g:i A', strtotime( $r->created_at ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        $this->chrome_close();
    }

    /* =====================================================================
     * Rendering — pending queue
     * ================================================================== */

    private function render_pending( $user ) {
        if ( ! $this->is_admin_role( $user ) ) {
            $this->render_dashboard( $user );
            return;
        }

        // The queue's manager panels carry the same featured-image picker the
        // editor does, so this screen needs the media library too. Same render,
        // same dependencies: a panel that worked in one place and not the other
        // would be the drift this whole arrangement exists to prevent.
        $this->load_media = true;
        wp_enqueue_media();

        $this->chrome_open( $user, 'pending' );
        $ids = $this->query_events( $user, array( 'status' => 'pending', 'per_page' => 100 ) );

        // "Fetch updates" and its report moved here from the Dashboard in
        // 2.12.0. It is an import action, its result is an import queue, and
        // the queue is on this screen: the button now sits beside the thing it
        // fills. The Dashboard links here instead.
        $active_sources = SFAF_Sources::active_adapters();
        ?>
        <div class="uc-page-head">
            <h1>Pending Events</h1>
            <div class="uc-head-actions">
                <?php
                /*
                 * PROGRESSIVE, NOT AJAX-ONLY. The form still posts to the same
                 * dispatch_post() action it always did, so with JavaScript off
                 * it behaves exactly as before. portal.js takes it over when it
                 * can, which is what buys the spinner and, more importantly, a
                 * visible failure instead of a page that just sits there.
                 */
                ?>
                <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>" class="uc-inline-form"
                      data-uc-async="fetch"
                      data-uc-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
                      data-uc-action="sfaf_portal_fetch"
                      data-uc-ajax-nonce="<?php echo esc_attr( wp_create_nonce( 'sfaf_portal_fetch' ) ); ?>"
                      data-uc-busy="Fetching&hellip;">
                    <input type="hidden" name="uc_action" value="fetch_sources" />
                    <?php wp_nonce_field( 'uc_portal_fetch_sources', 'uc_nonce' ); ?>
                    <?php
                    /*
                     * A UTILITY COLOUR, NOT THE PRIMARY ONE. Yellow means "this
                     * is the action" and on this screen the actions are Publish
                     * and Dismiss, on the rows. Fetching is how the rows get
                     * here: solid darkened teal, white label, measured at
                     * 5.35:1, and its own edge at 4.95:1 against the page.
                     *
                     * Enabled whenever any source is built, even if none is
                     * connected. Pressing it then reports what each one is
                     * waiting for.
                     */
                    ?>
                    <button type="submit" class="uc-btn uc-btn-utility"<?php echo empty( SFAF_Sources::adapters() ) ? ' disabled' : ''; ?>>
                        <?php echo sfaf_icon( 'refresh', array( 'size' => '16px' ) ); ?>
                        <span>Fetch updates</span>
                    </button>
                </form>
            </div>
        </div>

        <?php $this->render_fetch_report( $user, $active_sources ); ?>

        <?php
        // Imported third-party events, in their own two sub-sections above the
        // locally submitted queue. They are separate things: one is a
        // colleague asking for review, the other is a platform's event
        // awaiting a decision.
        $this->render_import_queue( $user );
        ?>

        <div class="uc-card">
            <div class="uc-card-head">
                <h2>Submitted for review</h2>
                <?php if ( ! empty( $ids ) ) : ?><span class="uc-count-badge"><?php echo count( $ids ); ?></span><?php endif; ?>
            </div>
            <?php if ( empty( $ids ) ) : ?>
                <p class="uc-empty">Nothing waiting for review.</p>
            <?php else : ?>
                <table class="uc-table">
                    <thead><tr><th>Event</th><th>Date</th><th>Submitted by</th><th>When</th><th class="uc-col-actions">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ( $ids as $id ) :
                        $author = get_userdata( get_post_field( 'post_author', $id ) );
                        $date   = get_post_meta( $id, '_uc_event_date', true ); ?>
                        <tr>
                            <td><a class="uc-tlink" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></a></td>
                            <td><?php echo $date ? esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ) : 'Not set'; ?></td>
                            <td><?php echo esc_html( $author ? $author->display_name : 'Unknown' ); ?></td>
                            <td><?php echo esc_html( get_the_date( 'M j, Y', $id ) ); ?></td>
                            <td class="uc-row-actions">
                                <div class="uc-actions">
                                    <a class="uc-action-link" href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank" rel="noopener">Preview</a>
                                    <a class="uc-action-link" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>">Edit</a>
                                    <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>">
                                        <input type="hidden" name="uc_action" value="approve_event" />
                                        <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                                        <?php wp_nonce_field( 'uc_portal_approve_event', 'uc_nonce' ); ?>
                                        <button class="uc-link-ok" type="submit">Approve</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>" onsubmit="return confirm('Reject and remove this event?');">
                                        <input type="hidden" name="uc_action" value="reject_event" />
                                        <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                                        <?php wp_nonce_field( 'uc_portal_reject_event', 'uc_nonce' ); ?>
                                        <button class="uc-link-danger" type="submit">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        $this->chrome_close();
    }

    /**
     * "Refresh from source" for a third-party event, plus the result of the
     * last refresh.
     *
     * Its own form rather than a button inside the event form: this posts and
     * redirects on its own, and must not carry the edit form's fields with it.
     *
     * @param WP_User $user
     * @param int     $event_id
     * @param array   $prov     SFAF_Sources::provenance() for this event.
     */
    private function render_refresh_panel( $user, $event_id, $prov ) {
        $key    = 'sfaf_refresh_result_' . $user->ID . '_' . (int) $event_id;
        $result = get_transient( $key );
        if ( false !== $result ) {
            delete_transient( $key );
        }
        ?>
        <div class="uc-card uc-refresh-panel">
            <div class="uc-card-head"><h2>Imported event</h2></div>
            <form method="post" action="<?php echo esc_url( $this->url( 'events/edit/' . (int) $event_id ) ); ?>" class="uc-inline-form">
                <input type="hidden" name="uc_action" value="refresh_source_event" />
                <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                <?php wp_nonce_field( 'uc_portal_refresh_source_event', 'uc_nonce' ); ?>
                <?php // Same family as Fetch updates on Pending: same job, same
                      // colour, same mark. ?>
                <button type="submit" class="uc-btn uc-btn-sm uc-btn-utility">
                    <?php echo sfaf_icon( 'refresh', array( 'size' => '15px' ) ); ?>
                    <span>Refresh from source</span>
                </button>
            </form>
            <span class="uc-hint">Pulls this event's platform fields again. Your category, organizer, series and any image you chose are left alone.</span>

            <?php if ( is_array( $result ) ) : ?>
                <?php if ( ! empty( $result['error'] ) ) : ?>
                    <div class="uc-flash uc-flash-error">Refresh failed: <?php echo esc_html( $result['error'] ); ?></div>
                <?php elseif ( empty( $result['changed'] ) ) : ?>
                    <div class="uc-flash">Nothing had changed at the source. This event is already up to date.</div>
                <?php else : ?>
                    <div class="uc-flash">Updated <?php echo (int) count( $result['changed'] ); ?> field(s) from <?php echo esc_html( isset( $result['label'] ) ? $result['label'] : 'the source' ); ?>:</div>
                    <table class="uc-table uc-refresh-diff">
                        <thead><tr><th>Field</th><th>Was</th><th>Now</th></tr></thead>
                        <tbody>
                        <?php foreach ( $result['changed'] as $field => $change ) : ?>
                            <tr>
                                <td><?php echo esc_html( $field ); ?></td>
                                <td class="uc-diff-old"><?php echo esc_html( '' === $change['from'] ? '(empty)' : $change['from'] ); ?></td>
                                <td class="uc-diff-new"><?php echo esc_html( $change['to'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =====================================================================
     * FAQ sets
     * ================================================================== */

    /**
     * Apply a saved FAQ set to this event, or save its FAQs as a new one.
     *
     * ITS OWN FORMS, ABOVE THE EDITOR. HTML forms cannot nest, and the FAQ
     * block sits inside the main event form, so the controls cannot live
     * beside it. They also must not carry the whole editor's fields on a
     * post-and-redirect. Same arrangement as the "Refresh from source" panel.
     *
     * @param WP_User $user
     * @param int     $event_id
     */
    private function render_faq_set_panel( $user, $event_id ) {
        $sets = SFAF_FAQ_Sets::all();

        $key    = 'sfaf_faq_set_result_' . $user->ID . '_' . (int) $event_id;
        $result = get_transient( $key );
        if ( false !== $result ) {
            delete_transient( $key );
        }

        /*
         * NOTHING TO SHOW MEANS NOTHING IS SHOWN. Since "save these as a set"
         * moved into the FAQ card, all this panel does is apply a set without
         * JavaScript and report what the last one did. With no sets and nothing
         * to report there is no panel: an empty card above the form telling
         * somebody they have no sets is a row of chrome answering a question
         * nobody asked.
         */
        if ( empty( $sets ) && ! is_array( $result ) ) {
            return;
        }

        // There is nothing left to explain about where the rows land: an event
        // has one FAQ block and a set is copied into it. The two paragraphs
        // that used to be here described the three-storage-case model, which
        // was the model rather than a quirk of the panel.
        ?>
        <div class="uc-card uc-faq-set-panel">
            <div class="uc-card-head"><h2><?php echo sfaf_icon( 'help' ); ?> FAQ sets</h2>
                <a href="<?php echo esc_url( $this->url( 'faq-sets' ) ); ?>">Manage sets</a></div>

            <?php if ( is_array( $result ) ) : ?>
                <?php if ( ! empty( $result['error'] ) ) : ?>
                    <div class="uc-flash uc-flash-error"><?php echo esc_html( $result['error'] ); ?></div>
                <?php elseif ( ! empty( $result['created'] ) ) : ?>
                    <div class="uc-flash">Saved as a set. It is now available on every event.</div>
                <?php else : ?>
                    <div class="uc-flash">
                        Applied &ldquo;<?php echo esc_html( $result['name'] ); ?>&rdquo;:
                        <?php echo (int) $result['added']; ?> question(s) added<?php
                        if ( ! empty( $result['replaced'] ) ) { echo ', ' . (int) $result['replaced'] . ' of your own replaced'; }
                        if ( ! empty( $result['skipped'] ) ) { echo ', ' . (int) $result['skipped'] . ' skipped as already present'; }
                        ?>.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="uc-faq-set-actions">
                <?php if ( empty( $sets ) ) : ?>
                    <p class="uc-muted">No saved sets yet. Write this event&rsquo;s FAQs below, save the event, then use &ldquo;Save these as a set&rdquo; to reuse them on the next one.</p>
                <?php else : ?>
                    <?php
                    /*
                     * THE FALLBACK, NOT THE CONTROL.
                     *
                     * Applying happens in the FAQ block itself now, where the
                     * questions are, without a page load and without throwing
                     * away unsaved edits. See faq_set_picker().
                     *
                     * This form still works and is still the only thing that
                     * does anything without JavaScript, so it is rendered and
                     * then hidden by the script that takes over, rather than
                     * deleted. The server action behind it is unchanged.
                     */
                    ?>
                    <form method="post" action="<?php echo esc_url( $this->url( 'events/edit/' . (int) $event_id ) ); ?>" class="uc-inline-form" data-uc-faq-apply-fallback>
                        <input type="hidden" name="uc_action" value="faq_set_apply" />
                        <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                        <?php wp_nonce_field( 'uc_portal_faq_set_apply', 'uc_nonce' ); ?>
                        <label class="uc-field uc-field-inline">
                            <span class="uc-field-label">Apply a saved set</span>
                            <select name="faq_set_id">
                                <?php foreach ( $sets as $set ) : ?>
                                    <option value="<?php echo esc_attr( $set['id'] ); ?>"><?php
                                        echo esc_html( $set['name'] . ' (' . count( $set['rows'] ) . ')' );
                                    ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="uc-check"><input type="radio" name="faq_set_mode" value="append" checked /> Add to the questions already here</label>
                        <label class="uc-check"><input type="radio" name="faq_set_mode" value="replace" /> Replace my own questions with the set</label>
                        <button type="submit" class="uc-btn uc-btn-sm uc-btn-primary">Apply</button>
                        <p class="uc-hint">Rows are copied. Editing the set later does not change this event, and deleting the set never touches it. Questions imported from a platform are left exactly where they are either way.</p>
                    </form>
                <?php endif; ?>

                <?php
                /*
                 * "SAVE THESE AS A SET" IS NOT HERE ANY MORE. It moved into the
                 * FAQ card's header, next to the questions it saves, which is
                 * where somebody writing FAQs actually is. 3.3.0 moved applying
                 * a set down there and left saving one up here, so the block a
                 * manager was working in offered no way to keep what they had
                 * just written. See render_faq_save_as_set().
                 */
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Manage saved sets: rename, edit rows, delete.
     *
     * There is no "new set" form here on purpose. Sets are born from a real
     * event that already has the questions on it, which is both less typing
     * and less of a blank page to face.
     *
     * @param WP_User $user
     */
    private function render_faq_sets( $user ) {
        if ( ! $this->can_view_all( $user ) ) {
            $this->render_dashboard( $user );
            return;
        }
        $this->chrome_open( $user, 'faq-sets' );
        $sets = SFAF_FAQ_Sets::all();
        ?>
        <div class="uc-page-head"><h1>FAQ Sets</h1></div>

        <div class="uc-card">
            <div class="uc-card-head"><h2>What a set is</h2></div>
            <p class="uc-help">
                A set is a reusable group of questions and answers. Applying one <strong>copies</strong> its rows onto an event,
                so editing a set here never changes an event that already used it, and deleting a set never removes questions from anything.
                Create a set from the FAQ panel on any event.
            </p>
        </div>

        <?php if ( empty( $sets ) ) : ?>
            <div class="uc-card">
                <div class="uc-card-head"><h2>0 sets</h2></div>
                <p class="uc-empty">No sets yet. Open an event with FAQs you would reuse, and press &ldquo;Save these FAQs as a set&rdquo;.</p>
            </div>
        <?php else : ?>
            <?php foreach ( $sets as $set ) : ?>
                <div class="uc-card">
                    <div class="uc-card-head">
                        <h2><?php echo esc_html( $set['name'] ); ?></h2>
                        <span class="uc-muted"><?php echo (int) count( $set['rows'] ); ?> <?php echo esc_html( 1 === count( $set['rows'] ) ? 'question' : 'questions' ); ?></span>
                    </div>
                    <form method="post" action="<?php echo esc_url( $this->url( 'faq-sets' ) ); ?>" class="uc-form">
                        <input type="hidden" name="uc_action" value="faq_set_save" />
                        <input type="hidden" name="faq_set_id" value="<?php echo esc_attr( $set['id'] ); ?>" />
                        <?php wp_nonce_field( 'uc_portal_faq_set_save', 'uc_nonce' ); ?>
                        <label class="uc-field">
                            <span class="uc-field-label">Set name</span>
                            <input type="text" name="faq_set_name" value="<?php echo esc_attr( $set['name'] ); ?>" required />
                        </label>
                        <?php $this->faq_repeater( 'faq_set_rows', $set['rows'] ); ?>
                        <div class="uc-form-actions">
                            <button type="submit" class="uc-btn uc-btn-primary">Save set</button>
                        </div>
                    </form>
                    <form method="post" action="<?php echo esc_url( $this->url( 'faq-sets' ) ); ?>"
                          onsubmit="return confirm('Delete this set? Events that already used it keep their questions, because the rows were copied when it was applied.');">
                        <input type="hidden" name="uc_action" value="faq_set_delete" />
                        <input type="hidden" name="faq_set_id" value="<?php echo esc_attr( $set['id'] ); ?>" />
                        <?php wp_nonce_field( 'uc_portal_faq_set_delete', 'uc_nonce' ); ?>
                        <button type="submit" class="uc-link-danger">Delete this set</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
        $this->chrome_close();
    }

    /**
     * The imported-event queue: Pending and Dismissed, as two sub-sections.
     *
     * @param WP_User $user
     */
    private function render_import_queue( $user ) {
        $pending   = SFAF_Sources::queue_ids( SFAF_Sources::STATUS_PENDING );
        $dismissed = SFAF_Sources::queue_ids( SFAF_Sources::STATUS_DISMISSED );
        ?>
        <?php
        /*
         * THE HEADING IS IN THE CARD, NOT FLOATING ABOVE IT. These were <h2
         * class="uc-section-title"> siblings of the card they named, which is a
         * second way of heading a card and the reason the treatment looked
         * partly applied: on this one screen there were cards with a band,
         * cards with a heading outside them and cards with neither. One
         * pattern now, everywhere.
         */
        ?>
        <div class="uc-card">
            <div class="uc-card-head">
                <h2>Imported, pending review</h2>
                <?php if ( $pending ) : ?><span class="uc-count-badge"><?php echo count( $pending ); ?></span><?php endif; ?>
            </div>
            <?php if ( empty( $pending ) ) : ?>
                <p class="uc-empty">Nothing new from connected sources. Use &ldquo;Fetch updates&rdquo; on the dashboard to check again.</p>
            <?php else : ?>
                <?php $this->import_queue_list( $pending, 'pending' ); ?>
            <?php endif; ?>
        </div>

        <?php if ( ! empty( $dismissed ) ) : ?>
            <div class="uc-card">
                <div class="uc-card-head">
                    <h2>Dismissed</h2>
                    <span class="uc-count-badge"><?php echo count( $dismissed ); ?></span>
                </div>
                <p class="uc-help">Dismissed events are kept so they are never fetched again. Restore one to put it back in the pending list.</p>
                <?php $this->import_queue_list( $dismissed, 'dismissed' ); ?>
            </div>
        <?php endif;
    }

    /**
     * The imported queue, as a list of events rather than a table of columns.
     *
     * WHY THIS IS NO LONGER A TABLE. It was five columns wide: Source, Event,
     * Date and time, Location, Actions. A table gives every column the same
     * weight, so the title, the platform badge, a timezone string, an address
     * and two verbs all arrived at once and nothing said which was the thing.
     * On a queue that is exactly wrong: a manager is scanning for WHICH EVENT
     * this is and then deciding about it, which is one heading and one line of
     * supporting detail, not five equal cells.
     *
     * So each row leads with the event's title as a real heading, with source,
     * when and where beneath it in the quiet line, and the actions to the
     * right. The disclosure that carries the fields the platform cannot supply
     * is still there and is now plainly subordinate to the row it belongs to
     * rather than a full-width cell of its own.
     *
     * THE PANEL ITSELF IS UNCHANGED, and deliberately: its markup comes from
     * render_manager_panel(), which the editor also calls, so there is no
     * second list of fields here that could fall behind. Its own form, because
     * forms cannot nest and this one posts and redirects on its own, carrying
     * only these fields so saving here cannot disturb anything else.
     *
     * @param int[]  $ids
     * @param string $section 'pending' or 'dismissed', which decides the actions.
     */
    private function import_queue_list( $ids, $section ) {
        ?>
        <ul class="uc-queue-list">
            <?php foreach ( $ids as $id ) :
                $prov     = SFAF_Sources::provenance( $id );
                $date     = get_post_meta( $id, '_uc_event_date', true );
                $start    = get_post_meta( $id, '_uc_start_time', true );
                $end      = get_post_meta( $id, '_uc_end_time', true );
                /*
                 * THE VENUE'S NAME, NOT THE POSTAL ADDRESS. Most of a queue is
                 * the same handful of venues, so a full address on every row
                 * was the longest thing on the row carrying the least new
                 * information. See sfaf_event_location_short().
                 */
                $location = sfaf_event_location_short( $id );

                /*
                 * WHEN, AS ONE PHRASE. The times used to be printed as the raw
                 * meta, so a 6pm event read "18:00-19:30" in a portal where
                 * every other time is AP style. Through the one formatter now.
                 * A campaign with no date is normal rather than broken, so it
                 * says so instead of showing a dash somebody has to decode.
                 */
                $when = $date ? sfaf_ap_date( $date, 'short' ) . ', ' . date_i18n( 'Y', strtotime( $date . ' 12:00:00' ) ) : '';
                $clock = sfaf_ap_time_range( $start, $end );
                if ( $when && $clock ) {
                    $when .= ', ' . $clock;
                }

                /*
                 * THE TIMEZONE ONLY WHEN IT IS NOT THE SITE'S.
                 *
                 * "(America/Los_Angeles)" appeared on every row of a queue run
                 * by people in America/Los_Angeles, which is a string that can
                 * never change anybody's decision and was competing with the
                 * title for attention. It is worth saying exactly when it is
                 * surprising: an imported event in another zone is a real trap,
                 * because the time shown is then not the time a local reader
                 * assumes. So the comparison decides, not the presence.
                 */
                if ( $when && $date && $prov['timezone'] && $prov['timezone'] !== wp_timezone_string() ) {
                    $when .= ' (' . $prov['timezone'] . ')';
                }
                if ( '' === $when ) {
                    $when = 'No date. Set it when publishing';
                }

                /*
                 * Fields this platform will never supply and a person has not
                 * filled in yet. Amber and a mark, never red and never "!": a
                 * GoFundMe Pro campaign arrives needing these EVERY time by
                 * design, so it is a step in the job, not a fault. The mark
                 * disappears once they are all filled, which makes a queue with
                 * no marks mean "all of these are ready to publish".
                 */
                $needs   = SFAF_Sources::missing_manager_fields( $id );
                $needs_t = ! empty( $needs ) ? 'Needs ' . SFAF_Sources::field_phrase( $needs ) : '';
                $ctx     = $this->manager_panel_context( wp_get_current_user(), $id, 'queue' );
                ?>
                <li class="uc-queue-item<?php echo $needs_t ? ' uc-queue-item-needs' : ''; ?>">
                    <div class="uc-queue-row">
                        <div class="uc-queue-id">
                            <h3 class="uc-queue-title">
                                <a href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></a>
                                <?php if ( $needs_t ) : ?>
                                    <?php // Reachable by hover AND by keyboard focus, so it works on
                                          // a phone and for anyone not using a mouse. The aria-label
                                          // names the fields rather than saying something is missing,
                                          // so a screen reader user gets what a sighted one does. ?>
                                    <span class="uc-needs-flag" tabindex="0" role="img"
                                          aria-label="<?php echo esc_attr( $needs_t . ' before publishing' ); ?>"
                                          title="<?php echo esc_attr( $needs_t . ' before publishing' ); ?>">
                                        <?php echo $this->icon_needs(); ?>
                                        <span class="uc-needs-tip"><?php echo esc_html( $needs_t ); ?></span>
                                    </span>
                                <?php endif; ?>
                            </h3>

                            <?php // ONE quiet line, in the order somebody reads it: who it
                                  // came from, when it is, where it is. ?>
                            <p class="uc-queue-meta">
                                <span class="uc-source-badge"><?php echo esc_html( $prov['label'] ? $prov['label'] : 'Imported' ); ?></span>
                                <span><?php echo esc_html( $when ); ?></span>
                                <?php // Omitted entirely when there is none, rather than
                                      // printing "Location not set" on every row of a
                                      // platform that never supplies one. The disclosure
                                      // below is where a missing field is reported. ?>
                                <?php if ( '' !== $location ) : ?>
                                    <span><?php echo esc_html( $location ); ?></span>
                                <?php endif; ?>
                            </p>

                            <?php if ( $prov['source_url'] ) : ?>
                                <p class="uc-queue-links">
                                    <a class="uc-source-link<?php echo $needs_t ? ' uc-source-link-strong' : ''; ?>" href="<?php echo esc_url( $prov['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php
                                        echo $needs_t ? 'Open campaign page to copy them &nearr;' : 'View on ' . esc_html( $prov['label'] ? $prov['label'] : 'source' ) . ' &nearr;';
                                    ?></a>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="uc-queue-actions">
                            <div class="uc-actions">
                                <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>">
                                    <input type="hidden" name="uc_action" value="import_publish" />
                                    <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                                    <?php wp_nonce_field( 'uc_portal_import_publish', 'uc_nonce' ); ?>
                                    <button class="uc-link-ok" type="submit">Publish</button>
                                </form>
                                <?php if ( 'dismissed' === $section ) : ?>
                                    <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>">
                                        <input type="hidden" name="uc_action" value="import_restore" />
                                        <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                                        <?php wp_nonce_field( 'uc_portal_import_restore', 'uc_nonce' ); ?>
                                        <button class="uc-action-link" type="submit">Restore</button>
                                    </form>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>">
                                        <input type="hidden" name="uc_action" value="import_dismiss" />
                                        <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                                        <?php wp_nonce_field( 'uc_portal_import_dismiss', 'uc_nonce' ); ?>
                                        <button class="uc-action-link" type="submit">Dismiss</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php // Collapsed by default. A queue is for scanning, and a dozen
                          // open panels would stop it being one; opening it is the
                          // moment a manager has chosen this event. ?>
                    <details class="uc-queue-panel">
                        <summary>
                            <?php echo $needs_t ? esc_html( $needs_t ) : 'Set the fields this platform does not supply'; ?>
                        </summary>
                        <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>" class="uc-form uc-queue-form">
                            <input type="hidden" name="uc_action" value="save_manager_fields" />
                            <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                            <?php wp_nonce_field( 'uc_portal_save_manager_fields', 'uc_nonce' ); ?>
                            <?php $this->render_manager_panel( $ctx ); ?>
                            <div class="uc-form-actions">
                                <button type="submit" class="uc-btn uc-btn-primary">Save these fields</button>
                                <a class="uc-action-link" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>">Open the full editor</a>
                            </div>
                        </form>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /* =====================================================================
     * Rendering — users & permissions
     * ================================================================== */

    private function render_users( $user ) {
        if ( ! $this->is_admin_role( $user ) ) {
            $this->render_dashboard( $user );
            return;
        }
        $this->chrome_open( $user, 'users' );

        $members = get_users( array( 'meta_key' => '_uc_calendar_role', 'orderby' => 'display_name' ) );
        $member_ids = wp_list_pluck( $members, 'ID' );
        $non_members = get_users( array( 'exclude' => $member_ids, 'number' => 200, 'orderby' => 'display_name' ) );
        $cats = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );
        ?>
        <div class="uc-page-head"><h1>Users &amp; Permissions</h1></div>

        <?php
        /*
         * TWO SECTIONS, AND THEY LOOK LIKE TWO SECTIONS.
         *
         * This was four cards in a row down one page, so "add a user", "the
         * users", "the teams" and "add a team" all read as one continuous
         * flow and nothing said where one subject ended. They are two
         * different jobs: who can use the calendar, and which named groups
         * exist to notify. Each gets a heading, a sentence saying what it is
         * for, and a rule under it.
         */
        ?>
        <section class="uc-section" id="uc-users">
            <div class="uc-section-head">
                <h2>Calendar users</h2>
                <p class="uc-section-sub">Who can sign in to the calendar, what they may do, and whether their events need approving.</p>
            </div>

        <div class="uc-card">
            <div class="uc-card-head"><h2>Add a user to the calendar</h2></div>
            <p class="uc-hint">
                Adding somebody here gives them a calendar record, which is what puts them in the team picker and
                the notification picker. It is not how anybody gets their WordPress role, and it cannot take access
                away: a WordPress administrator has full calendar access whether or not they are listed here, and
                adding one lists them at Admin.
            </p>
            <form method="post" action="<?php echo esc_url( $this->url( 'users' ) ); ?>" class="uc-inline-form">
                <input type="hidden" name="uc_action" value="add_user" />
                <?php wp_nonce_field( 'uc_portal_add_user', 'uc_nonce' ); ?>
                <select name="user_id" required>
                    <option value="">Select a WordPress user</option>
                    <?php foreach ( $non_members as $u ) : ?>
                        <option value="<?php echo (int) $u->ID; ?>"><?php
                            echo esc_html( $u->display_name . ' (' . $u->user_email . ')' );
                            echo self::is_site_admin( $u->ID ) ? ' — administrator' : '';
                        ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="role">
                    <?php foreach ( self::roles() as $rk => $rl ) : ?>
                        <option value="<?php echo esc_attr( $rk ); ?>"><?php echo esc_html( $rl ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="uc-btn uc-btn-primary" type="submit">Add</button>
            </form>
        </div>

        <div class="uc-card">
            <div class="uc-card-head"><h2>Calendar Users (<?php echo count( $members ); ?>)</h2></div>
            <?php if ( empty( $members ) ) : ?>
                <p class="uc-empty">No calendar users yet.</p>
            <?php else : foreach ( $members as $m ) :
                $role     = self::get_role( $m->ID );
                $approval = get_user_meta( $m->ID, '_uc_calendar_approval', true ) ?: 'review';
                $ucats    = (array) get_user_meta( $m->ID, '_uc_calendar_categories', true );
                $is_self  = (int) $m->ID === (int) $user->ID;
                $is_wpadm = self::is_site_admin( $m->ID );
                ?>
                <form method="post" action="<?php echo esc_url( $this->url( 'users' ) ); ?>" class="uc-user-row">
                    <input type="hidden" name="uc_action" value="set_user_role" />
                    <input type="hidden" name="user_id" value="<?php echo (int) $m->ID; ?>" />
                    <?php wp_nonce_field( 'uc_portal_set_user_role', 'uc_nonce' ); ?>
                    <div class="uc-user-id">
                        <strong><?php echo esc_html( $m->display_name ); ?></strong>
                        <span class="uc-muted"><?php echo esc_html( $m->user_email ); ?></span>
                    </div>
                    <div class="uc-user-controls">
                        <?php if ( $is_wpadm ) : ?>
                            <?php /*
                              * FIXED, AND SAID SO, RATHER THAN A CONTROL THAT DOES NOTHING.
                              * A WordPress administrator's access is decided by
                              * manage_options, so a dropdown here would accept a
                              * change and then have no effect. See get_role().
                              */ ?>
                            <div class="uc-user-fixed">
                                <span class="uc-field-label">Access</span>
                                <strong>Admin (fixed)</strong>
                                <span class="uc-muted">WordPress administrator, so full calendar access. Change it on the WordPress Users screen.</span>
                            </div>
                        <?php else : ?>
                            <label>Role
                                <select name="role">
                                    <?php foreach ( self::roles() as $rk => $rl ) : ?>
                                        <option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $role, $rk ); ?>><?php echo esc_html( $rl ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label>Approval
                            <select name="approval">
                                <option value="review" <?php selected( $approval, 'review' ); ?>>Requires approval</option>
                                <option value="auto" <?php selected( $approval, 'auto' ); ?>>Auto-publish</option>
                            </select>
                        </label>
                    </div>
                    <details class="uc-user-cats">
                        <summary>Contributor categories</summary>
                        <p class="uc-hint">Leave all unchecked to allow all categories.</p>
                        <div class="uc-check-grid">
                            <?php if ( ! is_wp_error( $cats ) ) : foreach ( $cats as $c ) : ?>
                                <label class="uc-check"><input type="checkbox" name="categories[]" value="<?php echo (int) $c->term_id; ?>" <?php checked( in_array( $c->term_id, $ucats, true ) ); ?> /> <?php echo esc_html( $c->name ); ?></label>
                            <?php endforeach; endif; ?>
                        </div>
                    </details>
                    <div class="uc-user-actions">
                        <button class="uc-btn uc-btn-sm uc-btn-primary" type="submit">Save</button>
                    </div>
                    <?php if ( ! $is_self ) : ?>
                        </form>
                        <form method="post" action="<?php echo esc_url( $this->url( 'users' ) ); ?>" class="uc-user-remove"
                              onsubmit="return confirm('<?php echo $is_wpadm
                                  ? 'Take this administrator off the calendar list? They keep full access, because they are a WordPress administrator. They come off every team.'
                                  : 'Remove calendar access for this user?'; ?>');">
                            <input type="hidden" name="uc_action" value="remove_user" />
                            <input type="hidden" name="user_id" value="<?php echo (int) $m->ID; ?>" />
                            <?php wp_nonce_field( 'uc_portal_remove_user', 'uc_nonce' ); ?>
                            <button class="uc-link-danger uc-btn-sm" type="submit">Remove</button>
                        </form>
                    <?php else : ?>
                        <div class="uc-user-remove"><span class="uc-muted">(you)</span></div>
                        </form>
                    <?php endif; ?>
            <?php endforeach; endif; ?>
        </div>
        </section>

        <?php
        /*
         * BOTH PICKERS ASK ONE METHOD who is on this calendar, so neither
         * holds the rule and the two cannot drift apart. It resolves to the
         * same set $members is built from, deliberately: a picker lists the
         * people with a calendar record, and nobody else. See
         * calendar_people() for why that is not the same question as who has
         * access.
         */
        $this->render_teams( $user, $this->calendar_people() );
        ?>
        <?php
        $this->chrome_close();
    }

    /**
     * Teams: a list of what exists, with actions on each row.
     *
     * UNDER USERS, BECAUSE A TEAM IS MADE OF USERS AND NOTHING ELSE. It is not
     * a settings screen and not an event screen; it is the second thing you do
     * after adding people, and it belongs on the page where the people are.
     *
     * A LIST, NOT A STACK OF FORMS. Every team used to render as an open form
     * with its name in a permanently editable text box and every calendar user
     * as a checkbox underneath. Two things were wrong with that. It read as
     * unfinished, because a page of half-filled inputs looks like work in
     * progress rather than a record of what exists. And it was actively
     * misleading about membership: the checkbox list showed EVERY calendar
     * user, member or not, with nothing but a tick to tell them apart, so a
     * team with nobody in it looked like a team with eight people in it. That
     * is what produced "0 people right now" above a list of names. The count
     * was right and the list was lying.
     *
     * So a team is now a name, a count and three actions. The members are
     * behind "Manage members", where the list is a picker and is plainly a
     * picker, and the row itself says how many there are.
     *
     * WHY THE ACTIONS ARE LINKS AND NOT SCRIPT. Rename and Manage members put
     * the row into a mode, and the mode is in the URL. Nothing here needs
     * JavaScript to work, which is the same choice every other disclosure in
     * this portal makes, and a rename in progress survives a reload.
     *
     * WHAT THIS SCREEN CANNOT DO. It cannot enter an email address. Membership
     * is chosen from the calendar's own users, because a team has to resolve to
     * accounts for removal-by-one-action to mean anything: an address typed in
     * here would be a copy that removing somebody could not reach. Addresses
     * for people outside the system still work, on the event, where they are
     * plainly one-off.
     *
     * @param WP_User   $user
     * @param WP_User[] $members The calendar's users, already loaded above.
     */
    private function render_teams( $user, $members ) {
        $teams = SFAF_Teams::all();

        $err     = get_transient( 'sfaf_team_error_' . $user->ID );
        $blocked = get_transient( 'sfaf_team_blocked_' . $user->ID );
        if ( false !== $err ) {
            delete_transient( 'sfaf_team_error_' . $user->ID );
        }
        if ( false !== $blocked ) {
            delete_transient( 'sfaf_team_blocked_' . $user->ID );
        }

        /*
         * Rename is still a query arg, because it REPLACES the row and a
         * replaced row has to survive the redirect that a failed save sends
         * somebody back through. Membership is no longer one: it is a
         * <details> now, opened in place with no page load, so there is nothing
         * to carry in the address. See render_team_row().
         */
        $renaming = isset( $_GET['team_rename'] ) ? sanitize_key( wp_unslash( $_GET['team_rename'] ) ) : '';
        $creating = ! empty( $_GET['team_new'] );
        ?>
        <section class="uc-section" id="uc-teams">
            <div class="uc-section-head">
                <h2>Teams</h2>
                <p class="uc-section-sub">Named groups of calendar users, so an event can notify "Philanthropy" instead of five addresses.</p>
            </div>

            <div class="uc-card">
                <div class="uc-card-head">
                    <h2><?php echo count( $teams ); ?> <?php echo esc_html( 1 === count( $teams ) ? 'team' : 'teams' ); ?></h2>
                </div>
                <p class="uc-hint">
                    A team is a name and a set of people, resolved when the reminder is sent. So taking somebody out
                    of a team stops their notifications for every event naming it, and adding somebody puts them on
                    events that were set up before they joined.
                </p>

                <?php if ( $err ) : ?>
                    <div class="uc-flash uc-flash-error">
                        <?php echo esc_html( $err ); ?>
                        <?php if ( is_array( $blocked ) && ! empty( $blocked ) ) : ?>
                            <ul class="uc-team-blocked">
                                <?php foreach ( $blocked as $ev ) : ?>
                                    <li>
                                        <a href="<?php echo esc_url( $this->url( 'events/edit/' . (int) $ev['id'] ) ); ?>"><?php echo esc_html( $ev['title'] ); ?></a>
                                        <span class="uc-muted">(<?php echo esc_html( sfaf_status_label( $ev['status'] ) ); ?>)</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( empty( $teams ) ) : ?>
                    <p class="uc-empty">No teams yet. Create one below.</p>
                <?php else : ?>
                    <ul class="uc-team-list">
                        <?php foreach ( $teams as $team ) : ?>
                            <?php $this->render_team_row( $team, $members, $renaming ); ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php
                /*
                 * CREATE IS A SEPARATE, COLLAPSED CONTROL, and it asks for one
                 * thing. Membership is a second decision made on the row that
                 * now exists, so creating a team is one field and a button
                 * rather than a form with a checkbox list attached to it.
                 */
                ?>
                <div class="uc-team-new">
                    <?php if ( ! $creating ) : ?>
                        <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( add_query_arg( 'team_new', 1, $this->url( 'users' ) ) . '#uc-teams' ); ?>">Create a team</a>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url( $this->url( 'users' ) ); ?>" class="uc-team-create">
                            <input type="hidden" name="uc_action" value="save_team" />
                            <input type="hidden" name="team_id" value="" />
                            <?php wp_nonce_field( 'uc_portal_save_team', 'uc_nonce' ); ?>
                            <label class="uc-field">
                                <span class="uc-field-label">Team name</span>
                                <input type="text" name="team_name" value="" placeholder="e.g. Philanthropy" required autofocus />
                            </label>
                            <div class="uc-team-create-actions">
                                <button class="uc-btn uc-btn-sm uc-btn-primary" type="submit">Create team</button>
                                <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( $this->url( 'users' ) . '#uc-teams' ); ?>">Cancel</a>
                            </div>
                            <?php // The old copy said "on the next screen", which
                                  // stopped being true when membership became a
                                  // disclosure on the row itself. ?>
                            <p class="uc-hint">Open the team once it exists to add people to it.</p>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * One team, as a row that opens into its own membership editor.
     *
     * WHAT THIS REPLACED, AND WHY IT WAS THE WRONG SHAPE. The old screen put
     * EVERY calendar user under EVERY team as a ticked or unticked checkbox.
     * That is a list of the calendar with some ticks in it, not a team: a team
     * of four on a calendar of forty read as forty rows, an empty team looked
     * identical to a full one at a glance, and "who is in Philanthropy" could
     * only be answered by scanning for ticks. It also does not scale, since it
     * grows with the calendar rather than with the team.
     *
     * SO THE TEAM SHOWS ITS MEMBERS. The list is SFAF_Teams::members(), the
     * people actually stored on it, each with a remove control. Everyone else
     * is behind "Add member", which offers only calendar users who are not
     * already in, with a type-to-filter box over them. That is the same source
     * rule as the notification picker: a person who has not been given calendar
     * access under Users and Permissions is not somebody a team can name.
     *
     * NOTHING IS SAVED UNTIL SAVE IS PRESSED. Removals and additions are
     * staged: a removed member stays on screen struck through and a chosen
     * candidate stays ticked, so what is about to happen is readable before it
     * happens, and Cancel is a plain reload that discards the lot. No control
     * here writes on change, and there is no request between opening the panel
     * and pressing the button.
     *
     * IT IS A <details>, NOT A QUERY ARG. The old "Manage members" / "Close"
     * pair was two page loads to look at four names, and "Close" describes
     * neither what it does nor what will happen; the chevron does both, and it
     * is the same disclosure the RSVP settings screen uses, marked with
     * data-uc-disclosure so initDisclosures() keeps aria-expanded honest.
     *
     * THE COUNT IS MEMBERSHIP, NOT REACH, and where those differ it says so.
     * SFAF_Teams::size() counts people who can actually be emailed, which is
     * the right number beside a notification picker and the wrong one beside a
     * membership list: a member whose account has no address is still a member
     * and printing 0 for them is simply false. member_count() is what this
     * uses, and the gap between the two is stated in words rather than left as
     * a number nobody can account for.
     *
     * @param array     $team
     * @param WP_User[] $members  The calendar's users, for the Add member picker.
     * @param string    $renaming Team id currently being renamed, or ''.
     */
    private function render_team_row( $team, $members, $renaming = '' ) {
        $id        = (string) $team['id'];
        $name      = (string) $team['name'];
        $in_team   = $team['users'];
        $people    = SFAF_Teams::member_count( $id );
        $reach     = SFAF_Teams::size( $id );
        $base      = $this->url( 'users' );
        $rename_h  = add_query_arg( 'team_rename', $id, $base ) . '#uc-teams';
        $is_rename = ( $renaming === $id );
        $panel_id  = 'uc-team-panel-' . sanitize_html_class( $id );

        /*
         * THE MEMBERS ARE THE TEAM'S, NOT THE CALENDAR'S, and that is what
         * makes the $offered guarantee hold by construction rather than by
         * remembering. A stored member who has since lost calendar access, or
         * never had it, appears HERE, because this list asks the team who is in
         * it. It therefore gets a checkbox, so it is offered, so it can be
         * removed deliberately and cannot be dropped by accident.
         *
         * The one person who still has no checkbox is a member whose WordPress
         * account has been deleted: members() cannot resolve them and skips
         * them, exactly as all() refuses to filter, so they are NOT offered and
         * SFAF_Teams::save() keeps them. A lookup failure must never become a
         * silent deletion. See the $offered argument there, and the assertion
         * in the build that checks every checkbox in this method is paired.
         */
        $team_members = SFAF_Teams::members( $id );

        // Calendar users who are not already in. The candidates list, and the
        // only thing "Add member" may ever contain.
        $candidates = array();
        foreach ( $members as $m ) {
            if ( ! in_array( (int) $m->ID, $in_team, true ) ) {
                $candidates[] = $m;
            }
        }
        ?>
        <li class="uc-team-row<?php echo $is_rename ? ' uc-team-row-open' : ''; ?>">

            <?php if ( $is_rename ) : ?>
                <?php // Rename turns THIS name into an input and nothing else. ?>
                <form method="post" action="<?php echo esc_url( $base ); ?>" class="uc-team-rename">
                    <input type="hidden" name="uc_action" value="save_team" />
                    <input type="hidden" name="team_id" value="<?php echo esc_attr( $id ); ?>" />
                    <?php wp_nonce_field( 'uc_portal_save_team', 'uc_nonce' ); ?>
                    <label class="uc-field uc-team-name">
                        <span class="uc-visually-hidden">Team name</span>
                        <input type="text" name="team_name" value="<?php echo esc_attr( $name ); ?>" required autofocus />
                    </label>
                    <div class="uc-team-actions">
                        <button class="uc-btn uc-btn-sm uc-btn-primary" type="submit">Save name</button>
                        <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( $base . '#uc-teams' ); ?>">Cancel</a>
                    </div>
                </form>
                <?php
                /*
                 * NO MEMBERS POSTED, AND THAT IS THE CASE $offered EXISTS FOR.
                 * This form carries no team_users[] and no team_offered[], so
                 * dispatch_post() passes an empty offered set and save() keeps
                 * the stored membership whole. Renaming a team has never been
                 * allowed to empty it, and this is the mechanism.
                 */
                ?>
            <?php else : ?>
                <details class="uc-team-manage" data-uc-disclosure>
                    <summary class="uc-team-summary" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>">
                        <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '18px' ) ); ?></span>
                        <span class="uc-team-id">
                            <strong class="uc-team-name-text"><?php echo esc_html( $name ); ?></strong>
                            <span class="uc-team-count">
                                <?php echo (int) $people; ?> <?php echo esc_html( 1 === $people ? 'person' : 'people' ); ?>
                                <?php if ( $people > $reach ) : ?>
                                    <?php // Said in words. A second bare number here is
                                          // what made the old screen unreadable. ?>
                                    <span class="uc-muted">(<?php echo (int) ( $people - $reach ); ?> with no email address, so
                                    <?php echo esc_html( 1 === ( $people - $reach ) ? 'that one is' : 'those are' ); ?> skipped when a reminder goes out)</span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </summary>

                    <div class="uc-team-panel" id="<?php echo esc_attr( $panel_id ); ?>">
                        <form method="post" action="<?php echo esc_url( $base ); ?>" class="uc-team-members-form">
                            <input type="hidden" name="uc_action" value="save_team" />
                            <input type="hidden" name="team_id" value="<?php echo esc_attr( $id ); ?>" />
                            <?php // The name travels unchanged: this form is about
                                  // membership, and save() needs a name to keep. ?>
                            <input type="hidden" name="team_name" value="<?php echo esc_attr( $name ); ?>" />
                            <?php wp_nonce_field( 'uc_portal_save_team', 'uc_nonce' ); ?>

                            <h4 class="uc-picker-heading">In this team</h4>
                            <?php if ( empty( $team_members ) ) : ?>
                                <p class="uc-muted uc-team-none">Nobody is in this team yet. Add somebody below.</p>
                            <?php else : ?>
                                <ul class="uc-member-list">
                                    <?php foreach ( $team_members as $m ) : ?>
                                        <li class="uc-member-row">
                                            <?php
                                            /*
                                             * THE REMOVE CONTROL IS THE CHECKBOX, and it
                                             * is ticked. Unticking it stages the removal
                                             * and the row goes struck through where it
                                             * stands, so with scripting off nothing
                                             * vanishes and what is about to happen is
                                             * still on screen to be read. Same pattern,
                                             * and the same reasoning, as the "anyone
                                             * else" address pills.
                                             */
                                            ?>
                                            <label class="uc-member-chip">
                                                <input type="checkbox" name="team_users[]" value="<?php echo (int) $m->ID; ?>" checked />
                                                <span class="uc-member-body">
                                                    <span class="uc-member-name"><?php echo esc_html( $m->display_name ); ?></span>
                                                    <span class="uc-member-email uc-muted"><?php echo esc_html( $m->user_email ); ?></span>
                                                </span>
                                                <span class="uc-member-x" aria-hidden="true"><?php echo sfaf_icon( 'x', array( 'size' => '14px' ) ); ?></span>
                                                <span class="uc-visually-hidden">In the team. Untick to take <?php echo esc_html( $m->display_name ); ?> out when this is saved.</span>
                                            </label>
                                            <input type="hidden" name="team_offered[]" value="<?php echo (int) $m->ID; ?>" />
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php
                            /*
                             * ADD MEMBER: CALENDAR USERS ONLY, AND ONLY THE ONES
                             * NOT ALREADY IN. Three states, and each says which
                             * of them it is, because "nobody to add" for want of
                             * calendar users and "nobody to add" because they are
                             * all in already are different situations with
                             * different next steps.
                             */
                            ?>
                            <?php if ( empty( $members ) ) : ?>
                                <p class="uc-hint">Add people to the calendar above first. Only calendar users can be put in a team.</p>
                            <?php elseif ( empty( $candidates ) ) : ?>
                                <p class="uc-hint">Every calendar user is already in this team.</p>
                            <?php else : ?>
                                <details class="uc-team-add" data-uc-disclosure>
                                    <summary class="uc-team-add-toggle" aria-expanded="false">
                                        <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '16px' ) ); ?></span>
                                        <span>Add member</span>
                                    </summary>
                                    <?php // The filter, its list and its empty note are
                                          // found within this element; see
                                          // initFilterLists() in portal.js. ?>
                                    <div class="uc-team-add-body" data-uc-filter-scope>
                                        <label class="uc-picker-filter">
                                            <span class="uc-visually-hidden">Filter people by name or address</span>
                                            <?php
                                            /*
                                             * data-uc-not-a-field, for the same reason as
                                             * the notification picker's filter: this box
                                             * changes nothing and posts nothing, so the
                                             * edit-scope lock must not make it readonly.
                                             */
                                            ?>
                                            <input type="search" placeholder="Type to filter people&hellip;"
                                                   data-uc-filter data-uc-not-a-field autocomplete="off" />
                                        </label>
                                        <div class="uc-picker-options" data-uc-filter-list>
                                            <?php foreach ( $candidates as $c ) : ?>
                                                <label class="uc-check uc-picker-option"
                                                       data-uc-filter-text="<?php echo esc_attr( strtolower( $c->display_name . ' ' . $c->user_email ) ); ?>">
                                                    <input type="checkbox" name="team_users[]" value="<?php echo (int) $c->ID; ?>" />
                                                    <span><?php echo esc_html( $c->display_name ); ?>
                                                        <span class="uc-muted"><?php echo esc_html( $c->user_email ); ?></span></span>
                                                </label>
                                                <input type="hidden" name="team_offered[]" value="<?php echo (int) $c->ID; ?>" />
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="uc-muted uc-picker-empty" data-uc-filter-empty hidden>Nobody matches that.</p>
                                    </div>
                                </details>
                            <?php endif; ?>

                            <div class="uc-team-actions">
                                <button class="uc-btn uc-btn-sm uc-btn-primary" type="submit">Save members</button>
                                <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( $base . '#uc-teams' ); ?>">Cancel</a>
                            </div>
                        </form>

                        <?php
                        /*
                         * RENAME AND DELETE LIVE IN THE PANEL, not on the closed
                         * row. Everything that can be done to a team is inside
                         * the team, and the closed list is a list of teams and
                         * their sizes rather than a grid of verbs.
                         *
                         * DELETE IS STILL REFUSED WHILE ANY EVENT NAMES THE
                         * TEAM, and the refusal names the events. Unchanged; see
                         * SFAF_Teams::delete().
                         */
                        ?>
                        <div class="uc-team-admin-actions">
                            <a class="uc-btn uc-btn-sm" href="<?php echo esc_url( $rename_h ); ?>">Rename</a>
                            <form method="post" action="<?php echo esc_url( $base ); ?>" class="uc-team-delete"
                                  onsubmit="return confirm('Delete the team &quot;<?php echo esc_attr( $name ); ?>&quot;? This is refused if any event still names it.');">
                                <input type="hidden" name="uc_action" value="delete_team" />
                                <input type="hidden" name="team_id" value="<?php echo esc_attr( $id ); ?>" />
                                <?php wp_nonce_field( 'uc_portal_delete_team', 'uc_nonce' ); ?>
                                <button class="uc-link-danger uc-btn-sm" type="submit">Delete</button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </li>
        <?php
    }

    /* =====================================================================
     * Data helpers
     * ================================================================== */

    /**
     * Totals from the last query_events() call, for the pager.
     *
     * Kept as properties rather than returned, because query_events() has four
     * call sites and only one of them pages. Set on every call so a stale value
     * from an earlier query can never be read as this one's.
     */
    private $last_query_total = 0;
    private $last_query_pages = 1;

    private function query_events( $user, $args = array() ) {
        // NB: we deliberately do NOT use fields=>ids here. A normal query primes
        // the post, postmeta and term caches for the whole result set in a couple
        // of queries, so the per-row get_post_meta()/get_the_title()/
        // wp_get_post_terms() calls in events_table() are cache hits rather than
        // one DB round-trip each.
        $q = array(
            'post_type'              => 'uc_event',
            'posts_per_page'         => isset( $args['per_page'] ) ? (int) $args['per_page'] : 50,
            'meta_key'               => '_uc_event_date',
            'orderby'                => 'meta_value',
            // ! empty, not isset: passing 'upcoming' => false used to flip the
            // order to ASC just by naming the key, which is a trap for any
            // caller that computes the flag rather than hard-coding it.
            'order'                  => ! empty( $args['upcoming'] ) ? 'ASC' : 'DESC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
            'meta_query'             => array(),
        );

        // "What changed lately" is a different sort from "what is coming up",
        // and it must not carry the _uc_event_date meta_key, which would drop
        // every event that has no date set.
        if ( ! empty( $args['orderby_modified'] ) ) {
            unset( $q['meta_key'] );
            $q['orderby'] = 'modified';
            $q['order']   = 'DESC';
        }

        /*
         * COLUMN SORTING, AND THE TWO THAT NEED SQL.
         *
         * title and date are ordinary WP_Query orderby values. status and rsvps
         * are not: WP_Query has no orderby for post_status, and the RSVP count
         * lives in this plugin's own table. Both are done with a posts_clauses
         * filter added around this one query and removed straight afterwards,
         * so nothing global is left behind for the next query to trip over.
         *
         * The count is joined as a grouped subquery rather than a correlated
         * one, so it is a single scan of the RSVP table however many events are
         * on the page, and it is a LEFT JOIN with COALESCE so an event with no
         * registrations sorts as zero instead of dropping out of the list.
         */
        $dir     = ( isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'ASC' : 'DESC';
        $orderby = isset( $args['orderby'] ) ? (string) $args['orderby'] : '';
        $clause_filter = null;

        if ( 'title' === $orderby ) {
            unset( $q['meta_key'] );
            $q['orderby'] = 'title';
            $q['order']   = $dir;
        } elseif ( 'date' === $orderby ) {
            $q['orderby'] = 'meta_value';
            $q['order']   = $dir;
        } elseif ( 'status' === $orderby || 'rsvps' === $orderby ) {
            unset( $q['meta_key'] );
            $q['orderby'] = 'none';
            global $wpdb;
            $rsvp_table = $wpdb->prefix . 'uc_rsvps';
            $clause_filter = function ( $clauses ) use ( $orderby, $dir, $wpdb, $rsvp_table ) {
                if ( 'rsvps' === $orderby ) {
                    $clauses['join']   .= " LEFT JOIN ( SELECT event_id, COUNT(*) AS uc_c FROM {$rsvp_table}"
                        . " WHERE status = 'confirmed' GROUP BY event_id ) uc_rc"
                        . " ON uc_rc.event_id = {$wpdb->posts}.ID ";
                    // A stable tiebreak, so two events on the same count keep a
                    // fixed order between pages instead of shuffling.
                    $clauses['orderby'] = "COALESCE(uc_rc.uc_c, 0) {$dir}, {$wpdb->posts}.ID DESC";
                } else {
                    $clauses['orderby'] = "{$wpdb->posts}.post_status {$dir}, {$wpdb->posts}.post_title ASC";
                }
                return $clauses;
            };
            add_filter( 'posts_clauses', $clause_filter );
        }

        // Paging. found_posts is only computed when asked for, so the screens
        // that do not page keep their cheaper query.
        if ( ! empty( $args['paged'] ) ) {
            $q['paged']         = max( 1, (int) $args['paged'] );
            $q['no_found_rows'] = false;
        }

        // Status.
        if ( ! empty( $args['status'] ) ) {
            $q['post_status'] = $args['status'];
        } else {
            $q['post_status'] = array( 'publish', 'pending', 'draft', 'future' );
        }

        /*
         * WHOSE EVENTS. THREE STATES, AND SAYING NOTHING IS THE SAFE ONE.
         *
         *   (unset)  the access rule, unchanged since this method was written:
         *            a contributor sees their own, anyone who may view all
         *            sees everything. Every caller that predates 3.19.0 is in
         *            this state and behaves exactly as it did.
         *   'mine'   this person's own events, whoever they are. For an admin
         *            or an editor that is a filter, not a gate.
         *   'all'    no author filter at all.
         *
         * WIDENING TAKES AN EXPLICIT ARGUMENT. Only the two screens that offer
         * the toggle pass 'all', and each of them is then responsible for the
         * renderer it hands the ids to. What protects other people's events is
         * not this query: it is public_events_table(), which has no code path
         * that can emit a registration count, a link or an action. A default
         * that showed everything and relied on each caller to narrow it would
         * be one forgotten argument away from a disclosure.
         */
        $scope = isset( $args['scope'] ) ? (string) $args['scope'] : '';
        if ( 'all' === $scope ) {
            // Nothing added: the caller has said so in as many words.
        } elseif ( 'mine' === $scope ) {
            $q['author'] = $user->ID;
        } elseif ( ! $this->can_view_all( $user ) ) {
            $q['author'] = $user->ID;
        }

        /*
         * SEARCH. Not $q['s'].
         *
         * WordPress's built-in search reaches post_title, post_content and
         * post_excerpt and stops, which on this post type is the title and the
         * description and nothing else: not the location, not the venue,
         * organizer, series or category, not the FAQ, not which platform an
         * imported event came from. Most of an event is in meta and terms, so
         * most of an event was unsearchable.
         *
         * SFAF_Search covers the post columns itself as well, which is why the
         * built-in is switched off rather than combined with it: both active
         * would AND together and an event would have to match the narrow one
         * too. See the note on SFAF_Search::QUERY_VAR.
         */
        if ( ! empty( $args['s'] ) ) {
            SFAF_Search::apply( $q, $args['s'] );
        }
        if ( ! empty( $args['cat'] ) ) {
            $q['tax_query'] = array( array( 'taxonomy' => 'uc_event_category', 'field' => 'term_id', 'terms' => (int) $args['cat'] ) );
        }
        if ( ! empty( $args['upcoming'] ) ) {
            $q['meta_query'][] = array( 'key' => '_uc_event_date', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' );
        }

        /*
         * ARCHIVED: THE EVENT DATE IS IN THE PAST. THAT IS THE WHOLE RULE.
         *
         * Nothing is deleted, nothing expires and no retention period exists.
         * A past event is an ordinary uc_event post that has stopped being
         * upcoming, so "archived" is a question asked of the date at read
         * time and never a state written to the post. That is why there is no
         * archive flag to keep in step with anything, and why an event moves
         * between the two views by the calendar changing rather than by
         * something running.
         *
         * Today is upcoming, not archived, in both directions: the two views
         * use the same boundary with opposite comparisons, so no event can
         * fall into both and none can fall between them.
         */
        if ( ! empty( $args['archived'] ) ) {
            $q['meta_query'][] = array( 'key' => '_uc_event_date', 'value' => current_time( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' );
        }

        /*
         * REMOVED AT SOURCE. A view of its own, because it is the one case
         * where a manager has to find events by something other than when they
         * happen. An event the platform stopped listing was unpublished to a
         * draft and can be at any date, so it is neither reliably upcoming nor
         * reliably archived and would be findable in neither.
         */
        if ( ! empty( $args['removed'] ) ) {
            $q['meta_query'][] = array( 'key' => SFAF_Sources::META_REMOVED_AT, 'compare' => 'EXISTS' );
        }
        if ( ! empty( $args['from'] ) ) {
            $q['meta_query'][] = array( 'key' => '_uc_event_date', 'value' => $args['from'], 'compare' => '>=', 'type' => 'DATE' );
        }
        if ( ! empty( $args['to'] ) ) {
            $q['meta_query'][] = array( 'key' => '_uc_event_date', 'value' => $args['to'], 'compare' => '<=', 'type' => 'DATE' );
        }

        $query = new WP_Query( $q );

        // Removed immediately: a posts_clauses filter left attached would
        // rewrite the ORDER BY of every later query on the page.
        if ( $clause_filter ) {
            remove_filter( 'posts_clauses', $clause_filter );
        }

        $this->last_query_total = (int) $query->found_posts;
        $this->last_query_pages = max( 1, (int) $query->max_num_pages );

        return wp_list_pluck( $query->posts, 'ID' );
    }

    private function count_events( $status, $author = 0, $upcoming = false ) {
        // Ask only for the total (found_posts) instead of pulling every matching
        // ID into memory just to count() them.
        $q = array(
            'post_type'              => 'uc_event',
            'post_status'            => $status,
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        if ( $author ) {
            $q['author'] = $author;
        }
        if ( $upcoming ) {
            $q['meta_query'] = array( array( 'key' => '_uc_event_date', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ) );
        }
        $query = new WP_Query( $q );
        return (int) $query->found_posts;
    }

    /**
     * Confirmed registrations, counted over what this person can act on.
     *
     * IT USED TO COUNT THE WHOLE TABLE, FOR EVERYONE. A contributor's editing
     * rights stop at their own events, and the dashboard told them how many
     * people had registered for every event on the calendar. That is the same
     * family as the ungated RSVP screen found in 3.5.0: not a leak of names,
     * but a statement about activity they have no part in, on a calendar
     * carrying HIV, substance use and trans health programming where the size
     * of a group is itself worth not saying.
     *
     * ORPHANS ARE INCLUDED FOR THOSE WHO CAN SEE EVERYTHING and excluded for a
     * contributor, and that follows from the join rather than from a decision:
     * a registration whose event has been deleted has no author to compare
     * against. The orphan rows are reachable from the Events list, which is
     * gated on can_view_all() already.
     *
     * @param WP_User|null $user null keeps the unscoped count, for callers that
     *                           have already established the viewer may see all.
     * @return int
     */
    private function count_rsvps( $user = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';

        if ( $user && ! $this->can_view_all( $user ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table r
                 INNER JOIN {$wpdb->posts} p ON p.ID = r.event_id
                 WHERE r.status = 'confirmed' AND p.post_author = %d",
                (int) $user->ID
            ) );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'confirmed'" );
    }
}
