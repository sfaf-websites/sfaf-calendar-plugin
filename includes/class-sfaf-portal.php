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

    public static function get_role( $user_id ) {
        // Memoize: every capability check (can_view_all/can_create/...) resolves
        // the role, so a single page render asks for it many times.
        static $cache = array();
        $user_id = (int) $user_id;
        if ( isset( $cache[ $user_id ] ) ) {
            return $cache[ $user_id ];
        }
        $role = get_user_meta( $user_id, '_uc_calendar_role', true );
        if ( ! $role ) {
            // WordPress administrators get implicit calendar-admin access.
            $role = user_can( $user_id, 'manage_options' ) ? 'admin' : '';
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
                // /series/remove and /series/orphans are gone. Both existed
                // only to manage a series being an event; neither problem can
                // occur now. Old links land on the list rather than 404ing.
                if ( isset( $segments[1] ) && $segments[1] === 'edit' ) {
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

            case 'remove_series':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $term_id = intval( $_POST['series_id'] );
                $freed   = SFAF_Series::delete( $term_id );
                $this->redirect( 'series', array( 'msg' => 'series_removed', 'freed' => $freed ) );
                break;

            case 'save_series':
                $id = $this->save_series_from_post( $user );
                if ( ! $id ) {
                    $this->redirect( 'series', array( 'msg' => 'series_failed' ) );
                }
                $this->redirect( 'series/edit/' . $id, array( 'msg' => 'series_saved' ) );
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
                $uid  = intval( $_POST['user_id'] );
                $role = $this->valid_role( $_POST['role'] ?? 'contributor' );
                if ( $uid && get_userdata( $uid ) ) {
                    update_user_meta( $uid, '_uc_calendar_role', $role );
                }
                $this->redirect( 'users', array( 'msg' => 'user_added' ) );
                break;

            case 'remove_user':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $uid = intval( $_POST['user_id'] );
                delete_user_meta( $uid, '_uc_calendar_role' );
                delete_user_meta( $uid, '_uc_calendar_approval' );
                delete_user_meta( $uid, '_uc_calendar_categories' );
                $this->redirect( 'users', array( 'msg' => 'user_removed' ) );
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

    private function valid_role( $role ) {
        $role = sanitize_key( $role );
        return in_array( $role, array( 'admin', 'editor', 'contributor' ), true ) ? $role : 'contributor';
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
        $text = array(
            'date'       => '_uc_event_date',
            'start_time' => '_uc_start_time',
            'end_time'   => '_uc_end_time',
            'location'   => '_uc_location',
            'capacity'   => '_uc_capacity',
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
        // The Donate URL is the campaign link on an imported campaign, which a
        // fetch writes, so it locks under source_url.
        if ( isset( $_POST['gofundme_url'] ) && ! $is_locked( 'source_url' ) ) {
            update_post_meta( $event_id, '_uc_gofundme_url', esc_url_raw( wp_unslash( $_POST['gofundme_url'] ) ) );
        }
        if ( isset( $_POST['organizer_email'] ) ) {
            update_post_meta( $event_id, '_uc_organizer_email', sanitize_email( wp_unslash( $_POST['organizer_email'] ) ) );
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

        $toggles = array(
            'rsvp_enabled'    => '_uc_rsvp_enabled',
            'notify_organizer'=> '_uc_notify_organizer',
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
        ->save_manager_fields_from_post( , ,  );

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
         * THE PER-EVENT NOTIFICATION LIST.
         *
         * Guarded by the marker field, not by isset() on the controls: an
         * unticked checkbox and an empty checkbox group both submit nothing, so
         * without the marker "the creator opted out" and "this form did not
         * carry the block at all" would be the same POST. The block is not
         * rendered on imported events, and this must leave their meta alone
         * rather than clearing it.
         */
        if ( isset( $_POST['notify_list_present'] ) && ! $is_imported ) {
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

            // Free-text addresses. Validated, not trusted: anything that is not
            // an address is dropped AND named back to the person who typed it,
            // because a typo that disappears in silence looks like a save.
            $valid    = array();
            $rejected = array();
            $raw      = isset( $_POST['notify_emails'] ) ? (string) wp_unslash( $_POST['notify_emails'] ) : '';
            foreach ( preg_split( '/[\r\n,;]+/', $raw ) as $line ) {
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

        /*
         * GENERATE, ONCE.
         *
         * A new event that asked to repeat produces its other dates here, as
         * separate complete events sharing a recurrence group. generate()
         * refuses a seed that already carries a group, so a back-button
         * resubmit cannot double them, and nothing ever runs this again.
         */
        $generated = 0;
        $pattern   = isset( $_POST['repeat'] ) ? SFAF_Recurrence::clean_pattern( wp_unslash( $_POST['repeat'] ) ) : '';
        $until     = isset( $_POST['repeat_until'] ) ? sanitize_text_field( wp_unslash( $_POST['repeat_until'] ) ) : '';
        if ( ! $is_imported && '' !== $pattern && '' !== $until ) {
            $made      = SFAF_Recurrence::generate( $event_id, $pattern, $until );
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
     * Users page actions
     * ================================================================== */

    private function save_user_role() {
        $uid = intval( $_POST['user_id'] );
        if ( ! $uid || ! get_userdata( $uid ) ) {
            return;
        }
        update_user_meta( $uid, '_uc_calendar_role', $this->valid_role( $_POST['role'] ?? 'contributor' ) );
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
        $rsvps    = SFAF_RSVP::get_all_rsvps( array( 'event_id' => $event_id, 'search' => $search ) );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="rsvps-' . current_time( 'Y-m-d' ) . '.csv"' );

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
    <link rel="stylesheet" href="<?php echo esc_url( SFAF_PLUGIN_URL . 'public/css/portal.css?ver=' . SFAF_VERSION ); ?>" />
    <style>:root{--uc-primary:<?php echo esc_html( $primary ); ?>;--uc-accent:<?php echo esc_html( $accent ); ?>;}</style>
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
            'series'    => array( 'Series', 'series', 'repeat' ),
            'faq-sets'  => array( 'FAQ Sets', 'faq-sets', 'help' ),
        );
        if ( $this->can_view_all( $user ) ) {
            $nav['rsvps']  = array( 'RSVPs', 'rsvps', 'check' );
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
                <nav class="uc-portal-nav">
                    <?php foreach ( $nav as $key => $item ) : ?>
                        <a href="<?php echo esc_url( $this->url( $item[1] ) ); ?>" class="uc-nav-item<?php echo $active === $key ? ' active' : ''; ?>">
                            <span class="uc-nav-icon"><?php echo sfaf_icon( $item[2], array( 'size' => '20px' ) ); ?></span>
                            <span><?php echo esc_html( $item[0] ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="uc-portal-rolebadge"><?php echo esc_html( ucfirst( $role ) ); ?></div>
            </aside>

            <div class="uc-portal-main">
                <header class="uc-portal-topbar">
                    <button class="uc-portal-menu-btn" id="uc-menu-btn" aria-label="Menu"><?php echo sfaf_icon( 'menu', array( 'size' => '22px' ) ); ?></button>
                    <div class="uc-portal-user">
                        <span class="uc-portal-username"><?php echo esc_html( $user->display_name ); ?></span>
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
            'approved'       => 'Event approved and published.',
            'rejected'       => 'Event rejected.',
            'user_saved'     => 'User permissions updated.',
            'user_added'     => 'User added to the calendar system.',
            'user_removed'   => 'User removed from the calendar system.',
            'series_saved'   => 'Series saved. Nothing about the events in it changed — a series groups them, it does not overwrite them.',
            'series_failed'  => 'That series could not be saved. Give it a name and try again.',
            'series_removed' => 'Series removed. Every event that was in it is still on the calendar, on the same date and at the same address — only the grouping has gone.',
            'fetched'        => 'Fetch complete. See the results below.',
            'import_dismissed' => 'Event dismissed. It stays in the Dismissed list and will not be fetched again.',
            'import_restored'  => 'Event restored to Pending.',
            'import_review'    => 'Assign a category, organizer and series, then press Publish to put this event on the calendar.',
            'refreshed'        => 'Refreshed from the source. See below for what changed.',
            'faq_set_applied'  => 'FAQ set applied.',
            'faq_set_saved'    => 'FAQ set saved.',
            'faq_set_deleted'  => 'FAQ set deleted. Events that already used it keep their questions, because the rows were copied.',
            'manager_saved'    => 'Saved. Those are the same fields the event editor shows, so the event now reads the same in both places.',
        );
        $key = sanitize_key( $_GET['msg'] );

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

        $own        = ! $this->can_view_all( $user );
        $total      = $this->count_events( 'publish', $own ? $user->ID : 0 );
        $upcoming   = $this->count_events( 'publish', $own ? $user->ID : 0, true );
        $rsvp_total = $this->count_rsvps();
        $pending    = $this->count_events( 'pending', 0 );
        $is_admin   = $this->is_admin_role( $user );
        $imports    = $is_admin ? SFAF_Sources::queue_count( SFAF_Sources::STATUS_PENDING ) : 0;
        ?>
        <div class="uc-page-head">
            <h1>Welcome, <?php echo esc_html( $user->first_name ?: $user->display_name ); ?></h1>
        </div>

        <div class="uc-stats">
            <div class="uc-stat"><span class="uc-stat-num"><?php echo (int) $total; ?></span><span class="uc-stat-label"><?php echo $own ? 'My Events' : 'Total Events'; ?></span></div>
            <div class="uc-stat"><span class="uc-stat-num"><?php echo (int) $upcoming; ?></span><span class="uc-stat-label">Upcoming</span></div>
            <div class="uc-stat"><span class="uc-stat-num"><?php echo (int) $rsvp_total; ?></span><span class="uc-stat-label">Total RSVPs</span></div>
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
                <a href="<?php echo esc_url( $this->url( 'events' ) ); ?>">Manage events &rarr;</a>
            </div>
            <?php $this->upcoming_overview( $this->query_events( $user, array( 'upcoming' => true, 'per_page' => 8 ) ) ); ?>
        </div>

        <div class="uc-card">
            <div class="uc-card-head"><h2>Recent activity</h2></div>
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
    private function upcoming_overview( $ids ) {
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
                    <td><?php echo (int) sfaf_get_rsvp_count( $id ); ?></td>
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

        // Registrations.
        $table = $wpdb->prefix . 'uc_rsvps';
        $rsvps = $wpdb->get_results(
            "SELECT event_id, name, status, created_at FROM $table ORDER BY id DESC LIMIT 5"
        );
        foreach ( (array) $rsvps as $r ) {
            $title = get_the_title( $r->event_id );
            $rows[] = array(
                'when' => strtotime( $r->created_at ),
                'text' => sprintf(
                    '%s %s for %s',
                    $r->name ? $r->name : 'Someone',
                    'subscribed' === $r->status ? 'asked for reminders' : ( 'cancelled' === $r->status ? 'cancelled their place' : 'registered' ),
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
        $filters = array(
            's'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'cat'      => isset( $_GET['cat'] ) ? intval( $_GET['cat'] ) : 0,
            'status'   => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
            'from'     => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
            'to'       => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
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
        }
        $sort  = array( 'orderby' => $orderby, 'order' => $order );
        $paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

        $cats = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );

        $ids = $this->query_events( $user, array_merge( $filters, array(
            'orderby'  => $orderby,
            'order'    => $order,
            'paged'    => $paged,
            'per_page' => 25,
        ) ) );
        $total = $this->last_query_total;
        $pages = $this->last_query_pages;
        ?>
        <div class="uc-page-head">
            <h1><?php echo $this->can_view_all( $user ) ? 'All Events' : 'My Events'; ?></h1>
            <a href="<?php echo esc_url( $this->url( 'events/new' ) ); ?>" class="uc-btn uc-btn-primary">+ New Event</a>
        </div>

        <form method="get" action="<?php echo esc_url( $this->url( 'events' ) ); ?>" class="uc-filters-bar">
            <input type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="Search events…" />
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
            <?php $this->events_table( $ids, $user, $sort, $filters ); ?>
            <?php $this->events_pagination( $paged, $pages, $total, $sort, $filters ); ?>
        </div>
        <?php
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
                    <td><?php echo (int) sfaf_get_rsvp_count( $id ); ?></td>
                    <td><span class="uc-pill uc-pill-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( sfaf_status_label( $st ) ); ?></span></td>
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
                        $src = get_post_meta( $id, '_uc_source_site', true );
                        echo $src ? esc_html( wp_parse_url( $src, PHP_URL_HOST ) ?: $src ) : '<span class="uc-muted">Local</span>';
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
                        <label class="uc-field uc-image-url-field">Or enter image URL
                            <input type="url" name="image_url" id="uc-image-url-<?php echo esc_attr( $uid ); ?>" data-uc-image-url value="<?php echo esc_attr( $own_url ); ?>" placeholder="https://…/image.jpg" />
                        </label>
                        <p class="uc-hint">Set an image to override the series image for this occurrence. The URL is a fallback.</p>
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
                $current = $event_id ? ( wp_get_post_terms( $event_id, 'uc_event_category', array( 'fields' => 'ids' ) ) ?: array( 0 ) )[0] : 0;
                ?>
                <label class="uc-field<?php echo esc_attr( $this->field_class( $state ) ); ?>"<?php echo $this->field_watch_attr( 'category', $state ); ?>>
                    <span class="uc-field-label">Category <?php echo $this->field_badge( $state, $label ); ?></span>
                    <select name="category">
                        <option value="0">None</option>
                        <?php if ( ! is_wp_error( $ctx['cats'] ) ) : foreach ( $ctx['cats'] as $c ) :
                            if ( 'contributor' === $ctx['role'] && ! empty( $ctx['allowed'] ) && ! in_array( $c->term_id, $ctx['allowed'], true ) ) { continue; } ?>
                            <option value="<?php echo (int) $c->term_id; ?>" <?php selected( $current, $c->term_id ); ?>><?php echo esc_html( $c->name ); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </label>
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

        // Taxonomies (respect contributor category restrictions).
        if ( isset( $_POST['category'] ) ) {
            $cat     = intval( $_POST['category'] );
            $allowed = $this->allowed_categories( $user );
            if ( 'contributor' === $role && ! empty( $allowed ) && $cat && ! in_array( $cat, $allowed, true ) ) {
                $cat = 0; // not permitted
            }
            wp_set_object_terms( $event_id, $cat ? array( $cat ) : array(), 'uc_event_category' );
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
            <h1>Series</h1>
            <?php if ( $this->can_view_all( $user ) ) : ?>
                <a href="<?php echo esc_url( $this->url( 'series/new' ) ); ?>" class="uc-btn uc-btn-primary">+ New Series</a>
            <?php endif; ?>
        </div>

        <p class="uc-help">
            A series is an umbrella for grouping and filtering &mdash; closer to a category than to an event. It has no
            date, never appears on the calendar, and may hold different kinds of event: the same group might run an
            educational session one week and a social the next. A series with no dates yet is perfectly normal.
        </p>

        <div class="uc-card">
            <?php if ( empty( $series ) ) : ?>
                <p class="uc-empty">No series yet. Create one, then choose it on any event.</p>
            <?php else : ?>
                <table class="uc-table">
                    <thead><tr><th></th><th>Series</th><th>Upcoming events</th><th>Next date</th><th class="uc-col-actions">Actions</th></tr></thead>
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
                                    <a class="uc-action-link" href="<?php echo esc_url( $edit ); ?>">Edit</a>
                                    <a class="uc-action-link" href="<?php echo esc_url( SFAF_Series::url( $term->term_id ) ); ?>" target="_blank" rel="noopener">View</a>
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
            <h1><?php echo $term ? 'Edit Series' : 'New Series'; ?></h1>
            <a href="<?php echo esc_url( $this->url( 'series' ) ); ?>" class="uc-btn">&larr; Back</a>
        </div>

        <form method="post" action="<?php echo esc_url( $this->url( $term_id ? 'series/edit/' . $term_id : 'series/new' ) ); ?>" class="uc-form">
            <input type="hidden" name="uc_action" value="save_series" />
            <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
            <?php wp_nonce_field( 'uc_portal_save_series', 'uc_nonce' ); ?>

            <div class="uc-card">
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
            $total    = SFAF_Series::total_count( $term_id );
            $upcoming = SFAF_Series::upcoming_count( $term_id );
            ?>
            <div class="uc-card">
                <h3>Events in this series</h3>
                <?php if ( ! $total ) : ?>
                    <p class="uc-hint">None yet. Choose this series on any event, or create an event into it.</p>
                <?php else : ?>
                    <p class="uc-hint">
                        <strong><?php echo (int) $total; ?></strong> <?php echo esc_html( _n( 'event', 'events', $total ) ); ?>,
                        <strong><?php echo (int) $upcoming; ?></strong> upcoming.
                    </p>
                    <ul class="uc-series-list">
                        <?php foreach ( SFAF_Series::events( $term_id, array( 'upcoming' => true, 'status' => SFAF_Series::editable_statuses(), 'limit' => 25 ) ) as $eid ) :
                            $d = get_post_meta( $eid, '_uc_event_date', true ); ?>
                            <li>
                                <a href="<?php echo esc_url( $this->url( 'events/edit/' . $eid ) ); ?>">
                                    <span class="uc-series-date"><?php echo $d ? esc_html( date_i18n( 'M j, Y', strtotime( $d ) ) ) : ''; ?></span>
                                    <span class="uc-series-title"><?php echo esc_html( get_the_title( $eid ) ); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="uc-card uc-card-danger">
                <h3>Remove this series</h3>
                <p class="uc-hint">
                    This removes the grouping and nothing else. Every event in it stays on the calendar, on the same
                    date, at the same address, and simply stops saying it is part of a series.
                </p>
                <form method="post" action="<?php echo esc_url( $this->url( 'series' ) ); ?>">
                    <input type="hidden" name="uc_action" value="remove_series" />
                    <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
                    <?php wp_nonce_field( 'uc_portal_remove_series', 'uc_nonce' ); ?>
                    <button type="submit" class="uc-btn uc-link-danger"
                            data-uc-confirm="Remove the series &quot;<?php echo esc_attr( $term->name ); ?>&quot;? The <?php echo (int) $total; ?> events in it stay on the calendar, unchanged.">Remove series</button>
                </form>
            </div>
        <?php endif; ?>
        <?php
        $this->chrome_close();
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

            <div class="uc-form-grid">
                <div class="uc-form-main">
                    <?php $s_title = $st( 'title' ); ?>
                    <label class="uc-field<?php echo esc_attr( $this->field_class( $s_title ) ); ?>"<?php echo $this->field_watch_attr( 'title', $s_title ); ?>>
                        <span class="uc-field-label">Title <?php echo $this->field_badge( $s_title, $prov['label'] ); ?></span>
                        <input type="text" name="title" value="<?php echo esc_attr( $post ? $post->post_title : '' ); ?>"<?php echo $this->field_disabled( $s_title ); ?> <?php echo ( 'locked' === $s_title ) ? '' : 'required'; ?> />
                    </label>

                    <?php
                    /*
                     * THE MANAGER-OWNED FIELDS, RENDERED FROM THE ONE PLACE
                     * THEY ARE WRITTEN.
                     *
                     * Featured image, description, category, organizer and
                     * the fundraising toggle used to be four separate blocks
                     * of markup scattered down this form, with the pending
                     * approval queue showing none of them and only an amber
                     * pencil saying something was missing. Two screens, two
                     * templates, and a new field had to be remembered twice.
                     *
                     * They now come out of render_manager_panel(), which the
                     * queue calls as well. Neither screen holds a list of the
                     * fields, so neither screen can be missing one.
                     */
                    $this->render_manager_panel( $this->manager_panel_context( $user, $event_id, 'editor' ) );
                    ?>

                    <?php
                    $s_date  = $st( 'date' );
                    $s_start = $st( 'start_time' );
                    $s_end   = $st( 'end_time' );
                    ?>
                    <div class="uc-field-row">
                        <label class="uc-field<?php echo esc_attr( $this->field_class( $s_date ) ); ?>"<?php echo $this->field_watch_attr( 'date', $s_date ); ?>>
                            <span class="uc-field-label">Date <?php echo $this->field_badge( $s_date, $prov['label'] ); ?></span>
                            <input type="date" name="date" value="<?php echo esc_attr( $g( '_uc_event_date' ) ); ?>"<?php echo $this->field_disabled( $s_date ); ?> />
                        </label>
                        <label class="uc-field<?php echo esc_attr( $this->field_class( $s_start ) ); ?>">
                            <span class="uc-field-label">Start <?php echo $this->field_badge( $s_start, $prov['label'] ); ?></span>
                            <input type="time" name="start_time" value="<?php echo esc_attr( $g( '_uc_start_time' ) ); ?>"<?php echo $this->field_disabled( $s_start ); ?> />
                        </label>
                        <label class="uc-field<?php echo esc_attr( $this->field_class( $s_end ) ); ?>">
                            <span class="uc-field-label">End <?php echo $this->field_badge( $s_end, $prov['label'] ); ?></span>
                            <input type="time" name="end_time" value="<?php echo esc_attr( $g( '_uc_end_time' ) ); ?>"<?php echo $this->field_disabled( $s_end ); ?> />
                        </label>
                    </div>

                    <?php $s_loc = $st( 'location' ); ?>
                    <label class="uc-field<?php echo esc_attr( $this->field_class( $s_loc ) ); ?>"<?php echo $this->field_watch_attr( 'location', $s_loc ); ?>>
                        <span class="uc-field-label">Location <?php echo $this->field_badge( $s_loc, $prov['label'] ); ?></span>
                        <input type="text" name="location" value="<?php echo esc_attr( $g( '_uc_location' ) ); ?>" placeholder="e.g., Strut - 470 Castro St"<?php echo $this->field_disabled( $s_loc ); ?> />
                    </label>

                    <?php
                    /*
                     * SERIES: the umbrella this event belongs to.
                     *
                     * A plain term assignment. Nothing is inherited from it,
                     * nothing about this event is rewritten because of it, and
                     * changing it never touches another event. The only two
                     * effects are the badge a visitor sees and the image this
                     * event falls back to when it has none of its own.
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
                        <span class="uc-hint">
                            An umbrella for grouping and filtering. A series may hold different kinds of event, so it is
                            not the same thing as repeating. <a href="<?php echo esc_url( $this->url( 'series' ) ); ?>">Manage series</a>.
                        </span>
                    </label>

                    <?php
                    /*
                     * REPEAT IS A GENERATOR, AND IT RUNS ONCE.
                     *
                     * On save it creates one separate event per date, all
                     * stamped with a shared recurrence group, and then forgets
                     * the pattern. Nothing regenerates. Editing or deleting any
                     * of them afterwards is an ordinary edit or delete.
                     *
                     * SHOWN ONLY WHERE IT CAN DO SOMETHING: on an event that is
                     * not already part of a group (generating again would double
                     * every date it made the first time) and that has no source
                     * (a platform decides whether its own event repeats, and a
                     * fetch brings that shape back regardless).
                     */
                    $rec_locked = ( $event_id && '' !== $prov['source'] );
                    $has_group  = $event_id && '' !== SFAF_Recurrence::group_of( $event_id );
                    ?>
                    <fieldset class="uc-fieldset<?php echo $rec_locked ? ' uc-fieldset-locked' : ''; ?>">
                        <legend>Repeat</legend>
                        <?php if ( $rec_locked ) : ?>
                            <p class="uc-hint">
                                <?php echo $this->icon_lock(); ?>
                                Whether this event repeats is decided at <?php echo esc_html( $prov['label'] ? $prov['label'] : 'the source' ); ?>,
                                and every fetch brings that shape across.
                            </p>
                        <?php elseif ( $has_group ) : ?>
                            <?php $group_count = count( $bulk_targets ); ?>
                            <p class="uc-hint">
                                <strong>Part of a recurrence group.</strong>
                                <?php echo esc_html( SFAF_Recurrence::pattern_label( SFAF_Recurrence::pattern_of( $event_id ), $g( '_uc_event_date' ) ) ); ?>
                                &middot; <?php echo (int) $group_count; ?> upcoming
                                <?php echo esc_html( _n( 'occurrence', 'occurrences', $group_count ) ); ?>.
                                This event is its own record. Nothing regenerates it, and deleting it removes one date and nothing else.
                            </p>
                        <?php else : ?>
                            <div class="uc-field-row">
                                <label class="uc-field">
                                    <span class="uc-field-label">Repeats</span>
                                    <select name="repeat">
                                        <option value="">Does not repeat</option>
                                        <?php foreach ( SFAF_Recurrence::patterns() as $k => $lbl ) : ?>
                                            <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $lbl ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="uc-field">
                                    <span class="uc-field-label">Repeat until</span>
                                    <input type="date" name="repeat_until" value="" />
                                </label>
                            </div>
                            <p class="uc-hint">
                                Creates one separate event per date on save, all grouped so they can be edited together later.
                                It happens once &mdash; nothing regenerates afterwards.
                            </p>
                        <?php endif; ?>
                    </fieldset>
                </div>

                <div class="uc-form-side">
                    <div class="uc-side-box">
                        <h3>RSVP</h3>
                        <label class="uc-check"><input type="checkbox" name="rsvp_enabled" value="1" <?php checked( $g( '_uc_rsvp_enabled' ), '1' ); ?> /> Enable RSVP</label>
                        <label class="uc-field">Capacity (0 = unlimited)<input type="number" name="capacity" min="0" value="<?php echo esc_attr( $g( '_uc_capacity' ) ); ?>" /></label>
                    </div>

                    <?php
                    // The Donate box's URL is the campaign link for an imported
                    // campaign, and a fetch writes it. So it locks with
                    // source_url rather than looking editable and being
                    // silently replaced on the next run.
                    $s_url = $st( 'source_url' );
                    ?>
                    <div class="uc-side-box">
                        <h3>Donate</h3>
                        <label class="uc-field<?php echo esc_attr( $this->field_class( $s_url ) ); ?>">
                            <span class="uc-field-label">GoFundMe URL <?php echo $this->field_badge( $s_url, $prov['label'] ); ?></span>
                            <input type="url" name="gofundme_url" value="<?php echo esc_attr( $g( '_uc_gofundme_url' ) ); ?>" placeholder="https://gofund.me/…"<?php echo $this->field_disabled( $s_url ); ?> />
                        </label>
                    </div>

                    <div class="uc-side-box">
                        <h3>Organizer</h3>
                        <label class="uc-field">Email<input type="email" name="organizer_email" value="<?php echo esc_attr( $g( '_uc_organizer_email' ) ); ?>" /></label>
                        <label class="uc-check"><input type="checkbox" name="notify_organizer" value="1" <?php checked( $g( '_uc_notify_organizer' ), '1' ); ?> /> Email on new RSVP</label>
                    </div>

                    <?php
                    /*
                     * WHERE REPLIES GO. Native events only, matching reminders:
                     * an imported event's emails are sent by its platform, so
                     * a reply-to here would set nothing.
                     *
                     * Pre-filled with the creator's address rather than left
                     * blank. An event set up and then forgotten still has
                     * replies arriving somewhere a person reads, which is the
                     * whole point of the field.
                     *
                     * This is NOT the notification list. That is who receives
                     * the reminder; this is who fields the answers. A person
                     * can be on one and not the other.
                     */
                    if ( $event_id && '' === $prov['source'] ) :
                        $reply_current  = (string) $g( '_uc_email_replyto' );
                        $reply_resolved = SFAF_Reminders::reply_to_for( $event_id );
                        $reply_source   = SFAF_Reminders::reply_to_source( $event_id );
                        $reply_rejected = get_transient( 'sfaf_replyto_rejected_' . $user->ID . '_' . $event_id );
                        if ( $reply_rejected ) {
                            delete_transient( 'sfaf_replyto_rejected_' . $user->ID . '_' . $event_id );
                        }
                        ?>
                        <div class="uc-side-box">
                            <h3>Replies to</h3>
                            <label class="uc-field">
                                <span class="uc-field-label">Reply-To address</span>
                                <?php
                                // A server-side rejection renders in exactly the
                                // shape the client-side validator uses, so the
                                // two are indistinguishable to the person
                                // reading them and to a screen reader.
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
                            </label>
                            <p class="uc-hint">
                                Where a reply to this event's reminder email lands. One address. A shared or group
                                mailbox is more reliable than several people, and it does not have to be an
                                sfaf.org address.
                                <?php if ( '' === $reply_current && 'author' === $reply_source ) : ?>
                                    Pre-filled with the address of whoever created this event; change it if somebody
                                    else should field the replies.
                                <?php elseif ( '' === $reply_current && 'setting' === $reply_source ) : ?>
                                    Nothing is set here, so replies currently go to the site-wide default in Settings.
                                <?php elseif ( '' === $reply_current ) : ?>
                                    Nothing is set here and there is no site-wide default, so a reply would go to
                                    whatever address the mail is sent from.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="uc-side-box">
                        <h3>Display</h3>
                        <?php
                        $feat = array( 'show_rsvp' => 'RSVP', 'show_donate' => 'Donate', 'show_social' => 'Social share', 'show_calendar' => 'Add to calendar', 'show_reminders' => 'Reminders' );
                        foreach ( $feat as $f => $lbl ) :
                            $on = $event_id ? sfaf_show_feature( $event_id, str_replace( 'show_', '', $f ) ) : true; ?>
                            <label class="uc-check"><input type="checkbox" name="<?php echo esc_attr( $f ); ?>" value="1" <?php checked( $on ); ?> /> <?php echo esc_html( $lbl ); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php
            // The platform that owns this event's FAQ rows, if any. Read from
            // the adapter, exactly like every other locked field.
            $faq_source = array(
                'locked' => in_array( 'faqs', $owned, true ),
                'label'  => $prov['label'],
                'url'    => $prov['source_url'],
            );
            ?>
            <div class="uc-card">
                <h3>FAQ</h3>
                <p class="uc-hint">
                    Frequently asked questions for this event. These are its own &mdash; there is no series block above
                    them and nothing overrides them.
                </p>
                <?php $this->faq_repeater( 'uc_faqs', $event_id ? sfaf_get_faqs( $event_id ) : array(), $faq_source ); ?>
            </div>

            <?php
            /*
             * WHO ELSE GETS THIS EVENT'S REMINDER.
             *
             * Native events only. An imported event's reminders are the
             * platform's job — this plugin does not send for them — so showing
             * a notification list on one would offer a setting that does
             * nothing. Same rule as SFAF_Reminders, read from the same
             * provenance, so the two cannot disagree.
             */
            if ( $event_id && '' === $prov['source'] ) {
                $this->render_notify_box( $user, $event_id );
            }
            // How far this save travels is chosen at the TOP of the form now,
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
    private function render_notify_box( $user, $event_id ) {
        $post = get_post( $event_id );
        if ( ! $post ) {
            return;
        }

        $author        = get_userdata( $post->post_author );
        $author_on     = ! SFAF_Reminders::author_opted_out( $event_id );
        $chosen_users  = array_map( 'intval', (array) get_post_meta( $event_id, SFAF_Reminders::NOTIFY_USERS_META, true ) );
        $extra_emails  = (array) get_post_meta( $event_id, SFAF_Reminders::NOTIFY_EMAILS_META, true );
        $resolved      = SFAF_Reminders::notify_list( $event_id );
        $reminders_on  = SFAF_Reminders::enabled();

        // Anyone with a calendar-portal role is pickable. WordPress users with
        // no role here are deliberately not offered: this is a list of the
        // people who work on the calendar, not of every account on the site.
        $portal_users = get_users( array(
            'meta_key'     => '_uc_calendar_role',
            'meta_compare' => 'EXISTS',
            'orderby'      => 'display_name',
            'order'        => 'ASC',
            'number'       => 200,
        ) );

        // Addresses the last save could not use. Reported rather than dropped
        // in silence, because a typo that vanishes without comment reads as
        // "saved" to the person who typed it.
        $rejected_key = 'sfaf_notify_rejected_' . $user->ID . '_' . $event_id;
        $rejected     = get_transient( $rejected_key );
        if ( $rejected ) {
            delete_transient( $rejected_key );
        }
        ?>
        <div class="uc-card">
            <h3>Reminder notifications</h3>
            <p class="uc-hint">
                Everyone who has registered for this event gets the morning-of reminder automatically.
                This is who <em>else</em> receives a copy, so staff can see what participants are sent.
                It changes nothing about who can edit this event.
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
                <p class="uc-hint">Untick to take yourself, or whoever created this, off the list. The event is unaffected.</p>
            <?php else : ?>
                <p class="uc-muted">This event's creator has no usable email address on file.</p>
            <?php endif; ?>

            <p class="uc-hint" style="margin-top:14px;"><strong>Other calendar users</strong></p>
            <?php if ( empty( $portal_users ) ) : ?>
                <p class="uc-muted">No other calendar users yet.</p>
            <?php else : ?>
                <div class="uc-notify-users">
                    <?php foreach ( $portal_users as $pu ) :
                        if ( $author && (int) $pu->ID === (int) $author->ID ) { continue; } ?>
                        <label class="uc-check">
                            <input type="checkbox" name="notify_users[]" value="<?php echo (int) $pu->ID; ?>" <?php checked( in_array( (int) $pu->ID, $chosen_users, true ) ); ?> />
                            <?php echo esc_html( $pu->display_name ); ?> <span class="uc-muted">(<?php echo esc_html( $pu->user_email ); ?>)</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            /*
             * data-uc-email-list hands this to the shared validator, which
             * checks it line by line and names the offending lines rather than
             * saying the box as a whole is wrong. Same red border, same message
             * position and same aria wiring as a single email input.
             */
            $notify_invalid = ( is_array( $rejected ) && ! empty( $rejected ) );
            ?>
            <label class="uc-field" style="margin-top:14px;">
                <span class="uc-field-label">Anyone else</span>
                <textarea name="notify_emails" id="uc-notify-emails" rows="3"
                          data-uc-email-list="1"
                          <?php echo $notify_invalid ? ' class="uc-invalid" aria-invalid="true" aria-describedby="uc-notify-emails-error"' : ''; ?>
                          placeholder="supervisor@example.org&#10;co-host@example.org"><?php echo esc_textarea( implode( "\n", array_map( 'strval', $extra_emails ) ) ); ?></textarea>
            </label>
            <?php if ( $notify_invalid ) : ?>
                <p class="uc-field-error" id="uc-notify-emails-error" data-uc-for="uc-notify-emails" role="alert">
                    <?php echo esc_html( 1 === count( $rejected ) ? 'This is not an email address, so it was not saved: ' : 'These are not email addresses, so they were not saved: ' ); ?>
                    <?php echo esc_html( implode( ', ', $rejected ) ); ?>
                </p>
            <?php endif; ?>
            <p class="uc-hint">One address per line, for people outside the calendar system: a supervisor, a co-host. Anything that is not an address is rejected on save and named here.</p>

            <p class="uc-hint" style="margin-top:14px;"><strong>Currently on the list</strong></p>
            <?php if ( empty( $resolved ) ) : ?>
                <p class="uc-muted">Nobody. Only people who register will get the reminder.</p>
            <?php else : ?>
                <ul class="uc-notify-list">
                    <?php foreach ( $resolved as $email => $label ) : ?>
                        <li><?php echo esc_html( $label ); ?>: <?php echo esc_html( $email ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="uc-hint">Saved as of the last save. Change the boxes above and save to update it.</p>
            <?php endif; ?>

            <?php
            // What actually went out, if anything has. The ledger is the record
            // of record for "did they get it?", so it is shown where the
            // question gets asked.
            $sent = SFAF_Reminders::log_for_event( $event_id );
            if ( ! empty( $sent ) ) : ?>
                <p class="uc-hint" style="margin-top:14px;"><strong>Reminder log for this event</strong></p>
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
            <?php endif; ?>
        </div>
        <?php
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
        <div class="uc-scope" data-uc-scope-choice data-uc-scope-count="<?php echo (int) $count; ?>">
            <h2 class="uc-scope-title">How should this save apply?</h2>
            <p class="uc-scope-lead">
                This event is one of <strong><?php echo (int) $count; ?></strong> upcoming occurrences generated from
                the same pattern. Choose before you edit &mdash; the fields below are locked until you do.
            </p>
            <div class="uc-scope-buttons">
                <button type="button" class="uc-btn uc-scope-btn" data-uc-scope="this">
                    Edit this event
                </button>
                <button type="button" class="uc-btn uc-scope-btn" data-uc-scope="all_upcoming">
                    Edit all <?php echo (int) $count; ?> upcoming occurrences
                </button>
            </div>
            <p class="uc-hint">
                Past occurrences are never changed by either choice. The events in this group are not necessarily the
                whole series &mdash; a series can hold different kinds of event, which is why an edit travels through
                the group rather than through the series.
            </p>
            <noscript>
                <p class="uc-scope-noscript">
                    Choosing a scope needs JavaScript. With it switched off this form saves this event only.
                </p>
            </noscript>
        </div>

        <?php // Stays visible while a long form scrolls, because the whole
              // point is that the manager can see what this save will do at the
              // moment they press the button, not only at the moment they
              // chose. ?>
        <div class="uc-scope-banner" data-uc-scope-banner hidden role="status">
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
            <p class="uc-hint">
                People who ticked &ldquo;Receive monthly email updates from SFAF&rdquo; on an RSVP form.
                Each row is one act of consent, with the moment it was given and the form it came from,
                so whoever wires this to a mailing platform later can evidence both.
                <strong>Nothing is sent from here and nothing is pushed anywhere.</strong> This is a record.
            </p>
            <form method="get" class="uc-inline-form">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name or email" />
                <button type="submit" class="uc-btn uc-btn-sm">Search</button>
            </form>
        </div>

        <div class="uc-card">
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

    private function render_rsvps( $user ) {
        $this->chrome_open( $user, 'rsvps' );
        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $rsvps    = SFAF_RSVP::get_all_rsvps( array( 'event_id' => $event_id, 'search' => $search ) );

        $export = add_query_arg(
            array_filter( array( 'event_id' => $event_id, 's' => $search, '_wpnonce' => wp_create_nonce( 'uc_portal_export' ) ) ),
            $this->url( 'rsvps/export' )
        );
        ?>
        <div class="uc-page-head">
            <h1>RSVPs</h1>
            <a href="<?php echo esc_url( $export ); ?>" class="uc-btn">Export CSV</a>
        </div>

        <form method="get" action="<?php echo esc_url( $this->url( 'rsvps' ) ); ?>" class="uc-filters-bar">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name or email…" />
            <?php if ( $event_id ) : ?><input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" /><?php endif; ?>
            <button class="uc-btn" type="submit">Search</button>
        </form>

        <div class="uc-card">
            <div class="uc-card-head"><h2><?php echo count( $rsvps ); ?> registrations</h2></div>
            <?php if ( empty( $rsvps ) ) : ?>
                <p class="uc-empty">No RSVPs found.</p>
            <?php else : ?>
                <table class="uc-table">
                    <thead><tr><th>Event</th><th>Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Registered</th></tr></thead>
                    <tbody>
                    <?php foreach ( $rsvps as $r ) : ?>
                        <tr>
                            <td><?php echo esc_html( SFAF_RSVP::event_label( $r ) ); ?></td>
                            <td><strong><?php echo esc_html( $r->name ); ?></strong></td>
                            <td><?php echo esc_html( $r->email ); ?></td>
                            <td><?php echo esc_html( $r->phone ); ?></td>
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
                    <?php // Enabled whenever any source is built, even if none is connected.
                          // Pressing it then reports what each one is waiting for. ?>
                    <button type="submit" class="uc-btn"<?php echo empty( SFAF_Sources::adapters() ) ? ' disabled' : ''; ?>>Fetch updates</button>
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

        <h2 class="uc-section-title">Submitted for review</h2>
        <div class="uc-card">
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
            <form method="post" action="<?php echo esc_url( $this->url( 'events/edit/' . (int) $event_id ) ); ?>" class="uc-inline-form">
                <input type="hidden" name="uc_action" value="refresh_source_event" />
                <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                <?php wp_nonce_field( 'uc_portal_refresh_source_event', 'uc_nonce' ); ?>
                <button type="submit" class="uc-btn uc-btn-sm">Refresh from source</button>
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
                    <form method="post" action="<?php echo esc_url( $this->url( 'events/edit/' . (int) $event_id ) ); ?>" class="uc-inline-form">
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

                <?php if ( $event_id ) : ?>
                    <form method="post" action="<?php echo esc_url( $this->url( 'events/edit/' . (int) $event_id ) ); ?>" class="uc-inline-form">
                        <input type="hidden" name="uc_action" value="faq_set_create" />
                        <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                        <?php wp_nonce_field( 'uc_portal_faq_set_create', 'uc_nonce' ); ?>
                        <label class="uc-field uc-field-inline">
                            <span class="uc-field-label">Save these FAQs as a set</span>
                            <input type="text" name="faq_set_name" placeholder="e.g. Cycle to Zero standard questions" required />
                        </label>
                        <button type="submit" class="uc-btn uc-btn-sm">Save as a set</button>
                        <p class="uc-hint">Saves the questions currently stored on this event. Save the event first if you have just edited them.</p>
                    </form>
                <?php endif; ?>
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
            <p class="uc-help">
                A set is a reusable group of questions and answers. Applying one <strong>copies</strong> its rows onto an event,
                so editing a set here never changes an event that already used it, and deleting a set never removes questions from anything.
                Create a set from the FAQ panel on any event.
            </p>
        </div>

        <?php if ( empty( $sets ) ) : ?>
            <div class="uc-card">
                <p class="uc-empty">No sets yet. Open an event with FAQs you would reuse, and press &ldquo;Save these FAQs as a set&rdquo;.</p>
            </div>
        <?php else : ?>
            <?php foreach ( $sets as $set ) : ?>
                <div class="uc-card">
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
        <h2 class="uc-section-title">
            Imported, pending review
            <?php if ( $pending ) : ?><span class="uc-count-badge"><?php echo count( $pending ); ?></span><?php endif; ?>
        </h2>
        <div class="uc-card">
            <?php if ( empty( $pending ) ) : ?>
                <p class="uc-empty">Nothing new from connected sources. Use &ldquo;Fetch updates&rdquo; on the dashboard to check again.</p>
            <?php else : ?>
                <?php $this->import_queue_table( $pending, 'pending' ); ?>
            <?php endif; ?>
        </div>

        <?php if ( ! empty( $dismissed ) ) : ?>
            <h2 class="uc-section-title">
                Dismissed
                <span class="uc-count-badge"><?php echo count( $dismissed ); ?></span>
            </h2>
            <div class="uc-card">
                <p class="uc-help">Dismissed events are kept so they are never fetched again. Restore one to put it back in the pending list.</p>
                <?php $this->import_queue_table( $dismissed, 'dismissed' ); ?>
            </div>
        <?php endif;
    }

    /**
     * One table of imported events.
     *
     * @param int[]  $ids
     * @param string $section 'pending' or 'dismissed' — decides the actions.
     */
    private function import_queue_table( $ids, $section ) {
        ?>
        <table class="uc-table">
            <thead><tr><th>Source</th><th>Event</th><th>Date &amp; time</th><th>Location</th><th class="uc-col-actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ( $ids as $id ) :
                $prov     = SFAF_Sources::provenance( $id );
                $date     = get_post_meta( $id, '_uc_event_date', true );
                $start    = get_post_meta( $id, '_uc_start_time', true );
                $end      = get_post_meta( $id, '_uc_end_time', true );
                $location = get_post_meta( $id, '_uc_location', true );

                // A campaign with no date is normal, not broken — say so
                // rather than showing a bare dash the manager has to decode.
                $when = $date ? date_i18n( 'M j, Y', strtotime( $date ) ) : 'No date. Set it when publishing';
                if ( $date && $start ) {
                    $when .= ' · ' . $start . ( $end ? '–' . $end : '' );
                }
                if ( $date && $prov['timezone'] ) {
                    $when .= ' (' . $prov['timezone'] . ')';
                }
                ?>
                <?php
                // Fields this platform will never supply and a person has not
                // filled in yet. Amber and a pencil, never red and never "!":
                // a GoFundMe Pro campaign arrives needing these EVERY time by
                // design, so it is a step in the job, not a fault. The icon
                // disappears once they are all filled, which makes a queue with
                // no icons mean "all of these are ready to publish".
                $needs   = SFAF_Sources::missing_manager_fields( $id );
                $needs_t = ! empty( $needs ) ? 'Needs ' . SFAF_Sources::field_phrase( $needs ) : '';
                ?>
                <tr<?php echo $needs_t ? ' class="uc-row-needs"' : ''; ?>>
                    <td><span class="uc-source-badge"><?php echo esc_html( $prov['label'] ? $prov['label'] : 'Imported' ); ?></span></td>
                    <td>
                        <a class="uc-tlink" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></a>
                        <?php if ( $needs_t ) : ?>
                            <?php // Reachable by hover AND by keyboard focus, so it works
                                  // on a phone and for anyone not using a mouse. The
                                  // aria-label names the fields rather than saying that
                                  // something is missing, so a screen reader user gets the
                                  // same information a sighted one does. ?>
                            <span class="uc-needs-flag" tabindex="0" role="img"
                                  aria-label="<?php echo esc_attr( $needs_t . ' before publishing' ); ?>"
                                  title="<?php echo esc_attr( $needs_t . ' before publishing' ); ?>">
                                <?php echo $this->icon_needs(); ?>
                                <span class="uc-needs-tip"><?php echo esc_html( $needs_t ); ?></span>
                            </span>
                        <?php endif; ?>
                        <?php if ( $prov['source_url'] ) : ?>
                            <br /><a class="uc-source-link<?php echo $needs_t ? ' uc-source-link-strong' : ''; ?>" href="<?php echo esc_url( $prov['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php
                                echo $needs_t ? 'Open campaign page to copy them &nearr;' : 'View on ' . esc_html( $prov['label'] ? $prov['label'] : 'source' ) . ' &nearr;';
                            ?></a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $when ); ?></td>
                    <td><?php echo esc_html( $location ? $location : 'Not set' ); ?></td>
                    <td class="uc-row-actions uc-row-actions-top">
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
                    </td>
                </tr>
                <?php
                /*
                 * THE SAME CONTROLS AS THE EVENT EDITOR, ON THE SAME EVENT.
                 *
                 * Pending is a view of an event, not a different form, so it
                 * gets the fields rather than only a pencil telling somebody
                 * that fields exist elsewhere. The markup comes from
                 * render_manager_panel(), which the editor also calls: there is
                 * no second list here that could fall behind.
                 *
                 * Collapsed by default. A queue is for scanning, and a dozen
                 * open panels would stop it being one; opening it is the moment
                 * a manager has chosen this event.
                 *
                 * Its own <form>, because forms cannot nest and this one posts
                 * and redirects on its own. It carries only these fields, so
                 * saving here cannot disturb anything else about the event.
                 */
                $ctx = $this->manager_panel_context( wp_get_current_user(), $id, 'queue' );
                ?>
                <tr class="uc-queue-panel-row">
                    <td colspan="5">
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
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
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

        <div class="uc-card">
            <div class="uc-card-head"><h2>Add a user to the calendar</h2></div>
            <form method="post" action="<?php echo esc_url( $this->url( 'users' ) ); ?>" class="uc-inline-form">
                <input type="hidden" name="uc_action" value="add_user" />
                <?php wp_nonce_field( 'uc_portal_add_user', 'uc_nonce' ); ?>
                <select name="user_id" required>
                    <option value="">Select a WordPress user</option>
                    <?php foreach ( $non_members as $u ) : ?>
                        <option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="role">
                    <option value="contributor">Contributor</option>
                    <option value="editor">Editor</option>
                    <option value="admin">Admin</option>
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
                        <label>Role
                            <select name="role">
                                <?php foreach ( array( 'contributor' => 'Contributor', 'editor' => 'Editor', 'admin' => 'Admin' ) as $rk => $rl ) : ?>
                                    <option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $role, $rk ); ?>><?php echo esc_html( $rl ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
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
                        <form method="post" action="<?php echo esc_url( $this->url( 'users' ) ); ?>" class="uc-user-remove" onsubmit="return confirm('Remove calendar access for this user?');">
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
        <?php
        $this->chrome_close();
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
            'order'                  => isset( $args['upcoming'] ) ? 'ASC' : 'DESC',
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

        // Contributors only see their own.
        if ( ! $this->can_view_all( $user ) ) {
            $q['author'] = $user->ID;
        }

        if ( ! empty( $args['s'] ) ) {
            $q['s'] = $args['s'];
        }
        if ( ! empty( $args['cat'] ) ) {
            $q['tax_query'] = array( array( 'taxonomy' => 'uc_event_category', 'field' => 'term_id', 'terms' => (int) $args['cat'] ) );
        }
        if ( ! empty( $args['upcoming'] ) ) {
            $q['meta_query'][] = array( 'key' => '_uc_event_date', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' );
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

    private function count_rsvps() {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'confirmed'" );
    }
}
