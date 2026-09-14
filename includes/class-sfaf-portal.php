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

    /**
     * Where a bulk save parks one occurrence's date, time and location before
     * moving it, so the "this event has moved" email can say what it was.
     *
     * Written by apply_to_group(), read and deleted by movable_diff() in the
     * same call, so it never persists past the request that wrote it.
     */
    const MOVED_FROM_META = '_uc_moved_from';

    /** Error string shown on the login screen. */
    private $login_error = '';

    /** Whether to load the WP media library (event form image picker). */
    /**
     * TWO ASSETS, TWO FLAGS, AND CONFLATING THEM RETURNED A 500 (3.44.1).
     *
     * caladmin builds its own document, so anything WordPress would normally
     * print into a page has to be asked for. There was one flag for that, named
     * for the media library, and it did three jobs: print the enqueued styles
     * and scripts, print the media Backbone templates, and print the editor
     * settings.
     *
     * 3.44.0 gave the FAQ Sets screen a rich text control and set that flag to
     * get the scripts. It had no reason to call wp_enqueue_media() and did not,
     * so foot() reached wp_print_media_templates() on a request where the media
     * stack had never been loaded, and the screen fataled halfway through its
     * own footer. The document went out truncated, which is why the chrome
     * looked squeezed rather than absent.
     *
     * So: load_media means "this screen opens the media library" and is what
     * pairs with wp_enqueue_media(). load_editor means "this screen has a rich
     * text control". Either one means the enqueued styles and scripts get
     * printed; only load_media prints media templates.
     */
    private $load_media = false;
    private $load_editor = false;

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

    /* ---------------------------------------------------------------------
     * Organizers, and events that have lost theirs
     *
     * An event's organizer is its post_author and nothing else: there is no
     * second field to keep in step, no snapshot, and no way for the two to
     * disagree. It never changes implicitly. The only things that move it are
     * the reassignment on the Users screen and an administrator doing it by
     * hand in WordPress.
     * ------------------------------------------------------------------- */

    /**
     * Events this person organizes, as id and title.
     *
     * Every status a manager can act on, trash excluded: a trashed event is on
     * its way out and holding up somebody's removal for one is noise.
     *
     * @param int $user_id
     * @return array[] array{id:int,title:string,status:string}
     */
    public static function events_organized_by( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return array();
        }

        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'author'                 => $user_id,
            'posts_per_page'         => 500,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ) );

        $out = array();
        foreach ( $q->posts as $id ) {
            $out[] = array(
                'id'     => (int) $id,
                'title'  => get_the_title( $id ) ? get_the_title( $id ) : '(untitled)',
                'status' => get_post_status( $id ),
            );
        }
        return $out;
    }

    /**
     * Has this event lost its organizer?
     *
     * TWO WAYS, AND THE SECOND IS THE ONE THAT ACTUALLY HAPPENS. The account
     * may be gone outright, which is what deleting a user in WordPress does.
     * Or it may still exist with its calendar record removed, which is what
     * happens when somebody leaves and their WordPress account is kept. Both
     * leave an event nobody but an admin can edit, so both count.
     *
     * @param int $event_id
     * @return bool
     */
    public static function event_is_orphaned( $event_id ) {
        $post = get_post( (int) $event_id );
        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return false;
        }

        /*
         * "NOBODY HAS BEEN GIVEN IT YET" IS NOT "WHOEVER HAD IT HAS GONE"
         * (3.47.0).
         *
         * This alert exists for one thing: an event somebody used to own and
         * nobody owns now, which is discovered months later when it needs
         * editing. A public submission is the opposite case. It has no author
         * BY CONSTRUCTION, because nobody was logged in to make it, and it is
         * sitting in a queue precisely so that a person can look at it and
         * give it one. That is the system working.
         *
         * Left as it was, EVERY community submission would have produced an
         * orphan email the next morning, and the one alert that matters would
         * be buried under a daily list of things that are fine. An alert that
         * cries wolf is worse than no alert, because it trains the person
         * reading it to skim.
         *
         * SO THE TEST IS "AWAITING REVIEW", NOT "IS A SUBMISSION". A submission
         * that has been APPROVED and published still has no author, and at that
         * point it genuinely is an event nobody owns: somebody looked at it and
         * put it on the calendar without giving it an organizer, which is the
         * thing worth being told about. Approving is what moves it from pending
         * to publish, so the status is the signal and nothing extra has to be
         * written or cleaned up.
         */
        if ( 'pending' === $post->post_status && (int) $post->post_author <= 0
            && 'local' !== SFAF_Submissions::kind( (int) $event_id ) ) {
            return false;
        }

        $author = (int) $post->post_author;
        if ( $author <= 0 || ! get_userdata( $author ) ) {
            return true;
        }
        return '' === self::get_role( $author );
    }

    /**
     * Every event with no valid organizer, newest first.
     *
     * @return array[] array{id:int,title:string,status:string,author:int,who:string}
     */
    public static function orphaned_events() {
        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'posts_per_page'         => 500,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ) );

        $out = array();
        foreach ( $q->posts as $id ) {
            if ( ! self::event_is_orphaned( $id ) ) {
                continue;
            }
            $post   = get_post( $id );
            $author = $post ? (int) $post->post_author : 0;
            $ud     = $author ? get_userdata( $author ) : null;
            $out[]  = array(
                'id'     => (int) $id,
                'title'  => get_the_title( $id ) ? get_the_title( $id ) : '(untitled)',
                'status' => get_post_status( $id ),
                'author' => $author,
                // Which of the two, because the fix differs: a deleted account
                // needs a new organizer, a withdrawn record may just need the
                // record back.
                'who'    => $ud ? $ud->display_name . ' (no calendar access)' : 'a deleted account',
            );
        }
        return $out;
    }

    /** Everybody who should be told about an orphan. Calendar admins. */
    public static function admin_recipients() {
        $out = array();
        foreach ( get_users( array( 'fields' => array( 'ID', 'user_email', 'display_name' ) ) ) as $u ) {
            if ( 'admin' !== self::get_role( $u->ID ) ) {
                continue;
            }
            if ( ! is_email( $u->user_email ) ) {
                continue;
            }
            $out[ strtolower( $u->user_email ) ] = $u->display_name;
        }
        return $out;
    }

    private function can_view_all( $user ) {
        return self::user_can_view_all( $user->ID );
    }

    /**
     * The same test, by user id, reachable without an instance.
     *
     * THE EMAILS HAVE TO ASK THIS. The registration alert links an organizer to
     * the RSVP list for the event, and that screen is gated on can_view_all, so
     * the message has to know per recipient whether the link would open or
     * refuse. A second copy of the rule in class-sfaf-notifications.php is a
     * second thing to change when the rule changes, which is how a link to a
     * "Denied" page gets sent. One definition; the private method above is now
     * a wrapper on it.
     *
     * @param int $user_id
     * @return bool
     */
    public static function user_can_view_all( $user_id ) {
        return in_array( self::get_role( (int) $user_id ), array( 'admin', 'editor' ), true );
    }

    private function can_create( $user ) {
        return in_array( self::get_role( $user->ID ), array( 'admin', 'editor', 'contributor' ), true );
    }

    private function can_edit_event( $user, $post ) {
        return self::user_can_edit_event( $user->ID, $post );
    }

    /**
     * The same test, by user id, reachable without an instance.
     *
     * THE PRE-EVENT SUMMARY HAS TO ASK THIS, and it is a DIFFERENT question
     * from user_can_view_all(). The summary's button opens
     * /caladmin/events/edit/N, and render_event_form() gates that screen on
     * can_edit_event, which is can_view_all OR being the event's author. A
     * contributor who created the event can open it; a contributor who was
     * merely added to its notification list cannot.
     *
     * So the email asks the gate that the page it links to actually applies,
     * rather than a stricter one that would take the link away from the person
     * most likely to want it: the contributor whose own event is starting in
     * two hours. Using can_view_all here would have been safe and wrong.
     *
     * @param int          $user_id
     * @param int|\WP_Post $post Event id or post object.
     * @return bool
     */
    public static function user_can_edit_event( $user_id, $post ) {
        $user_id = (int) $user_id;

        // Calendar admins and editors, from manage_options or the stored
        // record, in that order. Nothing below can reduce this. See get_role().
        if ( self::user_can_view_all( $user_id ) ) {
            return true;
        }

        $post = is_object( $post ) ? $post : get_post( (int) $post );
        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return false;
        }

        // The organizer. Never changes implicitly, and never loses access.
        if ( (int) $post->post_author === $user_id ) {
            return true;
        }

        /*
         * A TEAM THAT OWNS THIS EVENT (3.35.0).
         *
         * THE CALENDAR ROLE IS REQUIRED FIRST, AND THAT ORDER IS THE POINT.
         * A team is a name and a set of user ids, and the $offered guarantee
         * means it may legitimately hold somebody who has no calendar record at
         * all: a person added so they could be mailed, or one whose access was
         * withdrawn while their membership stayed. Answering true for them would
         * be worse than useless. They cannot pass the portal's entrance gate, so
         * every link this answer produces would open a "Denied" page, and the
         * pre-event summary asks exactly this question to decide whether to send
         * such a link. That is defect five in PROJECT.md §5, rebuilt.
         *
         * So team access widens what a calendar user may reach. It never grants
         * calendar access to somebody who has none.
         */
        if ( ! self::get_role( $user_id ) ) {
            return false;
        }

        return SFAF_Teams::user_owns_event( $user_id, $post->ID );
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
        return self::link( $path );
    }

    /**
     * The same URL, reachable without an instance.
     *
     * The emails link into the portal and are built from static context, and a
     * second copy of "/caladmin" in another file is a second thing to change if
     * the base ever moves. One definition, two callers.
     */
    public static function link( $path = '' ) {
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
            case 'media':      $this->render_media( $user ); break;
            case 'venues':     $this->render_venues( $user ); break;
            case 'organizers': $this->render_organizers( $user ); break;
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
                /*
                 * THE SCOPE ANSWER RIDES THE REDIRECT (3.41.0).
                 *
                 * A save lands back on the editor, and the editor asks the
                 * scope question on every load, so it was asked again after
                 * every save as though it had never been answered. 3.39.0
                 * removed the confirmation from the save BUTTON, which is a
                 * different second ask and was correctly removed; this is the
                 * one on the page somebody lands on afterwards.
                 *
                 * Carried in the URL rather than a transient because the
                 * address bar is where every other piece of this screen's state
                 * lives: the view, the sort and the filters all ride it, and a
                 * reload has to mean the same thing as arriving.
                 */
                /*
                 * SAY WHICH OF THE TWO HAPPENED (3.42.0). The question is
                 * asked in a dialog now, and a dialog is a thing somebody can
                 * mis-click, so the screen they land on states the outcome:
                 * how many were emailed, or that nobody was. Only when
                 * something actually moved, because otherwise there was never
                 * a question to answer.
                 */
                $this->redirect( 'events/edit/' . $result['id'], array(
                    'msg'        => $result['msg'],
                    'edit_scope' => $result['scope'],
                    'moved'      => (int) $result['moved'],
                    'told'       => (int) $result['told'],
                ) );
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
            /*
             * THE EVENTS LIST'S BULK ACTIONS (3.73.0, 3.79.0).
             *
             * ONE CASE, ONE NONCE, TWO VERBS. The ticks are shared, so the
             * form is shared, so the posted action is shared and `uc_do`
             * chooses between filing and publishing. Splitting this into two
             * cases would mean two nonce fields with one name in one form,
             * which is one field.
             *
             * ADDS, NEVER REPLACES. wp_set_post_terms() with $append = true,
             * which is the whole of the promise the control makes twice on
             * screen. Categories have been multi-select since 3.8.0, so this
             * is not fighting the data model.
             *
             * PERMISSION IS ASKED PER ID, AT THE WRITE. The events list draws
             * only what the viewer can see, and that is a render rather than a
             * refusal: a POST is a request anybody can construct, and this one
             * takes a list of ids. can_edit_event() is the same gate every
             * other route asks, and an id it refuses is dropped rather than
             * failing the whole run, because the other forty are legitimate.
             *
             * NO STATUS RULE, AND THAT IS DELIBERATE. See
             * bulk_plan() for why the bulk publish exclusions
             * were asked about rather than copied: publishing reaches the
             * public and filing does not.
             *
             * THE MARKER TELLS "NONE TICKED" FROM "NO PICKER". Without it an
             * empty bulk_ids[] is byte-identical to a form that never carried
             * the control, and the honest answer to unticking everything is to
             * do nothing.
             */
            case 'bulk_events':
                if ( ! isset( $_POST['uc_bulk_present'] ) ) {
                    $this->redirect( 'events', array( 'msg' => 'bulk_cat_none' ) );
                }

                $wanted = isset( $_POST['bulk_ids'] )
                    ? array_map( 'intval', (array) wp_unslash( $_POST['bulk_ids'] ) )
                    : array();

                /*
                 * WHICH VERB (3.79.0). One form carries one set of ticks and
                 * two buttons, so the BUTTON says what to do and the form says
                 * only who to do it to. Anything that is not 'publish' files
                 * under a category, which is the route that changes no status
                 * and publishes nothing: pressing Enter in the category select
                 * sends no button value at all, and this is what that means.
                 */
                if ( isset( $_POST['uc_do'] ) && 'publish' === $_POST['uc_do'] ) {
                    $this->bulk_publish_from_post( $user, $wanted );
                    break;
                }

                $term_id = isset( $_POST['bulk_category'] ) ? intval( $_POST['bulk_category'] ) : 0;
                if ( ! $term_id || ! SFAF_Categories::exists( $term_id ) ) {
                    $this->redirect( 'events', array( 'msg' => 'bulk_cat_failed' ) );
                }

                if ( empty( $wanted ) ) {
                    $this->redirect( 'events', array( 'msg' => 'bulk_cat_none' ) );
                }

                $did     = 0;
                $refused = 0;
                foreach ( array_unique( $wanted ) as $bulk_id ) {
                    $bulk_post = get_post( $bulk_id );
                    if ( ! $bulk_post || 'uc_event' !== $bulk_post->post_type ) {
                        continue;
                    }
                    if ( ! $this->can_edit_event( $user, $bulk_post ) ) {
                        $refused++;
                        continue;
                    }
                    /* $append = true. The third argument is the whole rule. */
                    $set = wp_set_post_terms( $bulk_id, array( $term_id ), SFAF_Categories::TAXONOMY, true );
                    if ( ! is_wp_error( $set ) ) {
                        $did++;
                    }
                }

                $bulk_term = SFAF_Categories::get( $term_id );
                $this->redirect( 'events', array(
                    'msg'     => 'bulk_cat_done',
                    'did'     => $did,
                    'refused' => $refused,
                    'cat'     => $bulk_term ? $bulk_term->name : '',
                ) );
                break;

            /* ---- The image library. ---------------------------------------
             *
             * THREE ROUTES AND THREE DIFFERENT GATES, asked here and not only
             * at the render. A control that is not drawn is not a refusal: a
             * POST is a request anybody can construct, and defect one in
             * PROJECT.md 5 was a screen that relied on not being linked to.
             *
             *   media_tag     add one series to one or many images   admin, editor
             *   media_untag   take one series off one image          admin, editor
             *   media_upload  put a new file in the folder           admin
             *
             * NONE OF THEM TOUCHES A FILE except the upload, which creates one.
             * Tagging writes a term relationship and nothing else, which is
             * what makes it safe to give an editor.
             */
            case 'media_tag':
                if ( ! SFAF_Media::can_tag( $user ) ) { wp_die( 'Denied' ); }

                /* The marker tells "none ticked" from "no picker", the same
                 * way the bulk category control does. Without it an empty
                 * ids[] is byte-identical to a form that never carried one. */
                if ( ! isset( $_POST['uc_media_tick_present'] ) ) {
                    $this->redirect( 'media', array( 'msg' => 'tag_none' ) );
                }

                $term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
                $ids     = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
                if ( empty( $ids ) ) {
                    $this->redirect( 'media', array( 'msg' => 'tag_none' ) );
                }

                $done = SFAF_Media::add_tag( $ids, $term_id );
                if ( ! $done['did'] ) {
                    $this->redirect( 'media', array( 'msg' => 'tag_failed' ) );
                }
                $this->redirect( 'media', array( 'msg' => 'tagged', 'n' => (int) $done['did'] ) );
                break;

            case 'media_save':
                if ( ! SFAF_Media::can_tag( $user ) ) { wp_die( 'Denied' ); }
                /*
                 * ONE PRESS, TWO WRITES, AND NEITHER IS DESTRUCTIVE (3.78.0).
                 * The card had a name form and a tag form with a submit each,
                 * which is two controls for what is one act at one moment:
                 * naming a picture and filing it.
                 *
                 * THE NAME IS REPLACED AND THE SERIES IS ADDED, and the two
                 * verbs are different on purpose. A name is one value and the
                 * box holds all of it; a series is one of several an image may
                 * carry, and appending is what the bulk control promises too.
                 * Taking one off is the chip's own x.
                 *
                 * rename() WRITES post_title AND NOTHING ELSE. The file does
                 * not move, the id does not change, and every event pointing at
                 * this picture goes on pointing at it: a title is what a picker
                 * SHOWS, not what anything resolves by.
                 *
                 * EMPTYING THE BOX IS A REAL ANSWER. It puts the row back to
                 * its file name, which is what looks_like_a_filename() would
                 * have done anyway, so there is nothing to undo and no
                 * confirmation to ask for.
                 */
                $media_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
                SFAF_Media::rename(
                    $media_id,
                    isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : ''
                );
                $media_term = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
                if ( $media_term ) {
                    SFAF_Media::add_tag( array( $media_id ), $media_term );
                }
                $this->redirect( 'media', array( 'msg' => $media_term ? 'saved_tagged' : 'renamed' ) );
                break;

            case 'media_untag':
                if ( ! SFAF_Media::can_tag( $user ) ) { wp_die( 'Denied' ); }
                SFAF_Media::remove_tag(
                    isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
                    isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0
                );
                $this->redirect( 'media', array( 'msg' => 'untagged' ) );
                break;

            case 'media_upload':
                if ( ! SFAF_Media::can_upload( $user ) ) { wp_die( 'Denied' ); }
                $this->handle_media_upload( $user );
                break;

            case 'trash_event':
                $event_id = intval( $_POST['event_id'] );
                $post     = get_post( $event_id );
                if ( ! $post || $post->post_type !== 'uc_event' || ! $this->can_edit_event( $user, $post ) ) {
                    $this->redirect( 'events', array( 'msg' => 'trashed' ) );
                }

                /*
                 * DELETING IS NOT A WAY AROUND CANCELLING.
                 *
                 * An event with registrations may not simply be deleted, because
                 * deleting it is how somebody who signed up finds out nothing at
                 * all: the page 404s, no message is sent, and the only record
                 * that they were coming is a row pointing at a post that no
                 * longer exists.
                 *
                 * Cancelling is the operation that exists for this, and it is
                 * offered rather than merely demanded: the refusal links
                 * straight to it. Once an event is cancelled and its
                 * registrants have been told, deleting it is allowed, which is
                 * why the exemption is on being cancelled rather than on having
                 * sent anything. Somebody who cancels and chooses not to notify
                 * has made that decision deliberately at the prompt, and this
                 * is not the place to second-guess it.
                 */
                if ( ! SFAF_Cancellation::is_cancelled( $event_id ) && SFAF_Announce::has_registrations( $event_id ) ) {
                    $this->redirect( 'events/edit/' . $event_id, array( 'msg' => 'delete_needs_cancel' ) );
                }

                wp_trash_post( $event_id );
                $this->redirect( 'events', array( 'msg' => 'trashed' ) );
                break;

            /*
             * CANCEL, AND UN-CANCEL.
             *
             * Its own action rather than a field on the event form, because it
             * is a decision with consequences that the form's Save is not: it
             * closes registration, stops the reminder and the summary, and asks
             * whether to email everybody who signed up. A checkbox among thirty
             * other fields would be pressed by accident.
             */
            case 'cancel_event':
                $event_id = intval( $_POST['event_id'] );
                $post     = get_post( $event_id );
                if ( ! $post || $post->post_type !== 'uc_event' || ! $this->can_edit_event( $user, $post ) ) {
                    wp_die( 'Denied' );
                }

                /*
                 * NATIVE EVENTS ONLY, CHECKED AT THE WRITE AS WELL AS THE
                 * RENDER. A control that is not drawn is not a refusal: a POST
                 * is a request anybody can construct, and defect one in
                 * PROJECT.md 5 was a screen that relied on not being linked to.
                 */
                if ( '' !== (string) get_post_meta( $event_id, SFAF_Sources::META_SOURCE, true ) ) {
                    wp_die( 'Imported events are cancelled at their source.' );
                }

                $undo = isset( $_POST['uncancel'] );

                if ( $undo ) {
                    /*
                     * TELLING THEM IT IS BACK ON (3.73.0).
                     *
                     * WHAT WAS HERE. Nothing. Everybody registered had been
                     * written to and told the event was off, and putting it
                     * back told them nothing at all, so the only way to find
                     * out was to go and look at a page they had no reason to
                     * open again.
                     *
                     * SUBJECT TO THE CONSENT RULE LIKE EVERY OTHER
                     * MANAGER-CAUSED MESSAGE. sfaf_should_notify() reads the
                     * confirmation's answer and an absent answer means no, so
                     * an organizer reinstating quietly mails nobody. It fails
                     * closed, which is the whole of PROJECT.md 4 on this.
                     *
                     * IT GOES OUT WHETHER OR NOT THE DATE MOVED, because
                     * somebody told an event was cancelled needs telling it is
                     * back even on the original day. Where it HAS moved, the
                     * message says what it is now, in the same message rather
                     * than a second one.
                     *
                     * AND THIS SETTLES THE ORDERING THAT WAS TWO PATHS.
                     * Reinstate-then-change-date offered the ordinary save's
                     * change notice; change-date-then-reinstate offered
                     * nothing, because reinstating had no prompt. Now
                     * reinstating always asks, so the second order is covered,
                     * and it asks about the RIGHT thing: "this is back on, and
                     * here is when" rather than "the date moved", which is
                     * meaningless to somebody who thinks it is not happening.
                     *
                     * THE FIRST ORDER IS STILL TWO MESSAGES AND THAT IS
                     * CORRECT, not a leftover: reinstate first and the person
                     * is told it is back on the old date, which was true when
                     * it was sent; change the date afterwards and the change
                     * notice tells them it moved, which is also true. Two
                     * things happened and they were told about both. What is
                     * gone is the case where NEITHER was sent.
                     *
                     * THE DATE IS READ BEFORE THE STATE CHANGES, because a
                     * cancellation carries no record of what the date was when
                     * it was cancelled: nothing stores it, and reading it after
                     * an ordinary save in the same request would read the new
                     * one. On this path nothing moves the date, so this is the
                     * date the event has had all along and $moved is false;
                     * the argument exists for the caller that reinstates and
                     * moves in one action, which the schedule screen may grow.
                     */
                    $was_on = (string) get_post_meta( $event_id, '_uc_event_date', true );

                    SFAF_Cancellation::set( $event_id, false );
                    /*
                     * THE TWO MESSAGES GO WITH IT (3.72.0). Both describe a
                     * cancellation that is no longer in force, and leaving
                     * either behind means the next cancellation inherits a
                     * sentence somebody wrote about a different one. The public
                     * reason was already outliving its event before this: set()
                     * clears the three state keys and has never touched these.
                     */
                    delete_post_meta( $event_id, '_uc_cancelled_reason' );
                    delete_post_meta( $event_id, SFAF_Cancellation::MESSAGE_META );

                    $told_back = array( 'sent' => 0 );
                    if ( sfaf_should_notify( $_POST ) ) {
                        $told_back = SFAF_Announce::reinstated(
                            array( $event_id ),
                            array( $event_id => $was_on )
                        );
                    }

                    $this->redirect( 'events/edit/' . $event_id, array(
                        'msg'  => 'uncancelled',
                        'told' => (int) $told_back['sent'],
                    ) );
                }

                $visibility = ( isset( $_POST['cancel_visibility'] ) && 'hide' === $_POST['cancel_visibility'] )
                    ? 'hide' : 'stay';

                /*
                 * ALREADY CANCELLED, AND ONLY THE LISTING IS MOVING (3.72.0).
                 *
                 * This is "Remove from the calendar" on the cancelled card, and
                 * it is deliberately not a second trip through the whole cancel
                 * path. It writes one meta key. It does not touch the public
                 * reason or the registrants' message, which describe why the
                 * event is off and are still true; it does not restamp the
                 * cancelled-at time, which is when it was cancelled rather than
                 * when it was tidied away; and it never asks about mail, so
                 * nothing can be sent from here whatever arrives in the POST.
                 *
                 * REFUSED WHEN THE EVENT IS NOT CANCELLED, because then this is
                 * not a visibility change, it is a cancellation with no
                 * confirmation in front of it.
                 */
                if ( isset( $_POST['cancel_visibility_only'] ) ) {
                    if ( ! SFAF_Cancellation::is_cancelled( $event_id ) ) {
                        $this->redirect( 'events/edit/' . $event_id, array( 'msg' => 'cancel_failed' ) );
                    }
                    SFAF_Cancellation::set_visibility( $event_id, $visibility );
                    $this->redirect( 'events/edit/' . $event_id, array(
                        'msg' => ( 'hide' === $visibility ) ? 'cancel_hidden' : 'cancel_listed',
                    ) );
                }
                $reason = isset( $_POST['cancel_reason'] )
                    ? sanitize_textarea_field( wp_unslash( $_POST['cancel_reason'] ) ) : '';

                SFAF_Cancellation::set( $event_id, true, $visibility );
                if ( '' !== $reason ) {
                    update_post_meta( $event_id, '_uc_cancelled_reason', $reason );
                } else {
                    delete_post_meta( $event_id, '_uc_cancelled_reason' );
                }

                // The answer comes off the cancel confirmation, which states how
                // many people would be told (3.42.0). No answer means no mail:
                // see sfaf_should_notify(). No registrations means the dialog
                // never asks, so nothing is sent either.
                $tell = sfaf_should_notify( $_POST );

                /*
                 * THE REGISTRANTS' MESSAGE IS WRITTEN ONLY WHEN IT IS BEING
                 * SENT (3.72.0).
                 *
                 * The confirmation cannot reach the box except through the
                 * answer that sends it, so an arriving message already implies
                 * the answer. Asking the answer again here is what makes that a
                 * rule rather than a property of the script: a POST is a request
                 * anybody can construct, and a message stored on an event nobody
                 * was emailed about would sit there until the next cancellation
                 * picked it up and sent it.
                 *
                 * It is stored rather than passed because SFAF_Notifications
                 * builds the message from the event, so every path that renders
                 * a cancellation reads the same two keys.
                 */
                $extra = ( $tell && isset( $_POST['cancel_message'] ) )
                    ? SFAF_Rich_Text::to_plain( sanitize_textarea_field( wp_unslash( $_POST['cancel_message'] ) ) )
                    : '';
                if ( '' !== $extra ) {
                    update_post_meta( $event_id, SFAF_Cancellation::MESSAGE_META, $extra );
                } else {
                    delete_post_meta( $event_id, SFAF_Cancellation::MESSAGE_META );
                }

                $told = array( 'people' => 0, 'sent' => 0 );
                if ( $tell ) {
                    $told = SFAF_Announce::cancelled( array( $event_id ) );
                }

                $this->redirect( 'events/edit/' . $event_id, array(
                    'msg'  => 'cancelled',
                    'told' => (int) $told['sent'],
                ) );
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

                /*
                 * DELETING A SERIES WITH REGISTRATIONS OFFERS TO CANCEL INSTEAD.
                 *
                 * Only under 'delete_events'. The other modes detach or move
                 * events and leave every one of them standing, so nobody is
                 * stranded and there is nothing to ask about.
                 *
                 * It OFFERS rather than refuses, which is the difference between
                 * this and deleting a single event. A single event has one
                 * alternative and the refusal can just name it. Here the
                 * alternative has two further questions of its own, what happens
                 * on the public calendar and whether to write to people, so the
                 * offer is a screen rather than a sentence.
                 */
                if ( 'delete_events' === $mode ) {
                    $in_series = SFAF_Series::events( $term_id );
                    $counts    = SFAF_Announce::count_affected( $in_series );

                    $confirmed = isset( $_POST['cancel_instead_confirmed'] );

                    if ( $counts['people'] > 0 && ! $confirmed ) {
                        set_transient( 'sfaf_series_delete_blocked_' . $user->ID, array(
                            'series_id' => $term_id,
                            'counts'    => $counts,
                            'events'    => $in_series,
                        ), 300 );
                        $this->redirect( 'series/remove/' . $term_id, array( 'msg' => 'series_needs_cancel' ) );
                    }

                    if ( $counts['people'] > 0 && $confirmed && 'cancel' === ( isset( $_POST['instead'] ) ? sanitize_key( wp_unslash( $_POST['instead'] ) ) : '' ) ) {
                        $visibility = ( isset( $_POST['cancel_visibility'] ) && 'hide' === $_POST['cancel_visibility'] ) ? 'hide' : 'stay';
                        foreach ( $in_series as $eid ) {
                            SFAF_Cancellation::set( $eid, true, $visibility );
                        }
                        $told = array( 'sent' => 0 );
                        if ( sfaf_should_notify( $_POST ) ) {
                            // One email per person across the whole series, not
                            // one per date. See SFAF_Announce. The answer is one
                            // of two buttons on the confirmation screen since
                            // 3.42.0; no answer means no mail.
                            $told = SFAF_Announce::cancelled( $in_series );
                        }
                        $this->redirect( 'series/edit/' . $term_id, array(
                            'msg'  => 'series_cancelled',
                            'n'    => count( $in_series ),
                            'told' => (int) $told['sent'],
                        ) );
                    }
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

            /* ---- The schedule. Four writes. ------------------------------
             *
             * Gated exactly as series editing is, because that is what this is:
             * the schedule screen IS the series screen. Each one re-derives its
             * targets from the stored data rather than from the form, so a POST
             * naming an event in another series, or a date that has passed,
             * achieves nothing.
             *
             * THREE OF THE FOUR ARE BOUNDED BY "UPCOMING" and cannot reach a
             * session that has already happened. Extend is the fourth and is
             * bounded the other way: it only ever creates dates after the last
             * one the series already holds, and never before today.
             */
            case 'schedule_pattern':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $this->schedule_pattern_from_post();
                break;

            case 'schedule_extend':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $this->schedule_extend_from_post();
                break;

            case 'schedule_add_date':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $this->schedule_add_date_from_post();
                break;

            case 'schedule_remove_date':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $this->schedule_remove_date_from_post();
                break;

            case 'schedule_publish':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $this->schedule_publish_from_post();
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

            case 'save_organizer':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $saved = SFAF_Organizers::save(
                    isset( $_POST['organizer_id'] ) ? intval( $_POST['organizer_id'] ) : 0,
                    isset( $_POST['organizer_name'] ) ? wp_unslash( $_POST['organizer_name'] ) : '',
                    isset( $_POST['organizer_description'] ) ? wp_unslash( $_POST['organizer_description'] ) : '',
                    isset( $_POST['organizer_slug'] ) ? wp_unslash( $_POST['organizer_slug'] ) : ''
                );
                if ( is_wp_error( $saved ) ) {
                    set_transient( 'sfaf_organizer_error_' . $user->ID, $saved->get_error_message(), 60 );
                    $this->redirect( 'organizers', array( 'msg' => 'organizer_failed' ) );
                }
                $this->redirect( 'organizers', array( 'msg' => 'organizer_saved' ) );
                break;

            case 'delete_organizer':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                /*
                 * ALLOWED, WHICH IS THE CATEGORY RULE AND NOT THE VENUE RULE
                 * ABOVE. An event keeps every fact about itself and merely
                 * loses a byline, where an event whose venue is deleted has
                 * nowhere to be. See SFAF_Organizers for the full comparison.
                 *
                 * The count comes back so the confirmation can name it, which
                 * is the part that makes an allowed deletion honest rather than
                 * merely permitted.
                 */
                $done = SFAF_Organizers::delete( isset( $_POST['organizer_id'] ) ? intval( $_POST['organizer_id'] ) : 0 );
                if ( is_wp_error( $done ) ) {
                    set_transient( 'sfaf_organizer_error_' . $user->ID, $done->get_error_message(), 60 );
                    $this->redirect( 'organizers', array( 'msg' => 'organizer_failed' ) );
                }
                $this->redirect( 'organizers', array( 'msg' => 'organizer_deleted', 'n' => (int) $done ) );
                break;

            case 'approve_event':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $event_id = intval( $_POST['event_id'] );
                wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ) );

                /*
                 * THE TWO ANSWERS FROM THE APPROVAL PROMPT (3.48.0).
                 *
                 * BOTH ARE RE-DERIVED FROM WHO ACTUALLY SUBMITTED IT, not from
                 * anything the form said about them. The POST carries two
                 * ticks and nothing else: no address, no name. An address that
                 * arrived in the request would be an address anybody who can
                 * reach this route could nominate, and this one goes on a list
                 * that is sent registrant names and email addresses.
                 *
                 * ORDER MATTERS. The list is written first so the message can
                 * truthfully say what they will now receive, and the message is
                 * only told about the list when the list actually changed.
                 */
                $who = SFAF_Submissions::submitter( $event_id );
                if ( $who['is_submission'] && $who['usable'] ) {
                    /*
                     * THE TICK COVERS EVERY ADDRESS THE SUBMISSION NAMED, and
                     * the community form takes up to five. They are read from
                     * the event by SFAF_Submissions::notify_addresses(), for
                     * the reason above: an address arriving in this POST would
                     * be an address anybody who can reach this route could
                     * nominate onto a list that is sent registrant names.
                     *
                     * `$listed` STILL MEANS THE SUBMITTER, and only the
                     * submitter, because it is what the published notice reads
                     * to decide whether to say "you will start getting mail".
                     * That message goes to the first address and speaks for it
                     * alone; the others were named by somebody else and get no
                     * message from here at all.
                     */
                    $listed = false;
                    if ( ! empty( $_POST['notify_submitter'] ) ) {
                        foreach ( SFAF_Submissions::notify_addresses( $event_id ) as $n => $addr ) {
                            $added = SFAF_Submissions::add_to_notify_list( $event_id, $addr );
                            if ( 0 === $n ) {
                                $listed = $added;
                            }
                        }
                    }

                    if ( ! empty( $_POST['tell_submitter'] ) ) {
                        SFAF_Submissions::send_published_notice( $event_id, $listed );
                    }
                }

                $this->redirect( 'pending', array( 'msg' => 'approved' ) );
                break;

            /*
             * MAKE THE SUBMITTED FILE THE EVENT'S PICTURE (3.72.0).
             *
             * ITS OWN ACTION RATHER THAN A FIELD ON THE MANAGER PANEL. The
             * panel's image control offers the calendar folder, which is
             * curated and stays curated; this attachment is in
             * `calendar-submissions/` and is deliberately not in that list. So
             * this is not "choose a picture", it is "the one that arrived is
             * the one", and it is one press on the row where the picture is
             * already on screen.
             *
             * IT SETS THE THUMBNAIL AND NOTHING ELSE. It does not move the file,
             * copy it into the calendar folder or change the status: the
             * submissions folder is where raw uploads live and this event now
             * points at one. Mark moves images between the folders by hand when
             * one is worth keeping, and an event pointing at a submissions file
             * goes on working either way, because a thumbnail is an attachment
             * id and WordPress does not care which folder the file sits in.
             */
            case 'use_submitted_image':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $event_id = intval( $_POST['event_id'] );
                $post     = get_post( $event_id );
                if ( ! $post || 'uc_event' !== $post->post_type ) {
                    $this->redirect( 'pending', array( 'msg' => 'image_failed' ) );
                }

                /*
                 * THE ID COMES FROM THE EVENT, NEVER FROM THE FORM, which is
                 * what stops this being a way to set any attachment on the site
                 * as any event's picture. The row's own meta is the only
                 * source, and it is checked to be a real image attachment
                 * before it becomes one.
                 */
                $shot_id = (int) get_post_meta( $event_id, SFAF_Submit::META_IMAGE, true );
                if ( $shot_id < 1
                    || 'attachment' !== get_post_type( $shot_id )
                    || 0 !== strpos( (string) get_post_mime_type( $shot_id ), 'image/' ) ) {
                    $this->redirect( 'pending', array( 'msg' => 'image_failed' ) );
                }

                set_post_thumbnail( $event_id, $shot_id );
                /*
                 * AND THE URL FIELD IS CLEARED. sfaf_event_image_url() prefers a
                 * chosen attachment over a typed URL, so a stale URL underneath
                 * would be invisible now and would come back the day somebody
                 * removed the thumbnail. One answer to "what is this event's
                 * picture" is the point of pressing this.
                 */
                delete_post_meta( $event_id, '_uc_image_url' );

                $this->redirect( 'pending', array( 'msg' => 'image_used' ) );
                break;

            case 'reject_event':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $event_id = intval( $_POST['event_id'] );

                /*
                 * TOLD BEFORE IT IS TRASHED, AND THAT ORDER IS THE POINT
                 * (3.72.0).
                 *
                 * The message names the event and its date, and it reads them
                 * off the post. wp_trash_post() does not delete a post, so the
                 * meta would still be there, but the title of a trashed post is
                 * not what get_the_title() is for and one status filter added
                 * later would make this message silently blank. Sending first
                 * means the message is built from a post that is still what the
                 * submitter sent.
                 *
                 * ONLY UNDER THE TICK. The panel is unticked by default, so
                 * nothing goes out unless somebody said so, and the note is
                 * read only in the same branch: a note with no tick is somebody
                 * who typed and then decided not to send, and it must not be
                 * stored on a row that is about to be trashed.
                 */
                if ( ! empty( $_POST['tell_rejected'] ) ) {
                    /* Plain text, not prose(). This is typed into a bare
                     * textarea and read in an email; there is no editor behind
                     * it and no reason for a tag to survive into a message
                     * telling somebody no. */
                    $note = isset( $_POST['reject_note'] )
                        ? sanitize_textarea_field( wp_unslash( $_POST['reject_note'] ) )
                        : '';
                    SFAF_Submissions::send_rejected_notice( $event_id, $note );
                }

                wp_trash_post( $event_id );
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

            /*
             * 'faq_set_create' IS GONE, AND IT WAS AN EVENT-SIDE CONTROL.
             *
             * An event is where a set is APPLIED and where questions specific
             * to that event are written. Creating, editing, duplicating and
             * deleting sets all happen on the FAQ Sets screen. Two of the three
             * reported defects on the old control were disposed of by removing
             * it rather than repaired: it collected the on-screen rows in the
             * browser and then built the set from the last SAVED state, and it
             * redirected a refusal with the success message key.
             *
             * The gate it carried was the third thing wrong with it. Creating a
             * set was can_edit_event, so a contributor could add to a list every
             * event picks from, while editing and deleting one were can_view_all.
             * Every operation on the shared list is the editor gate now,
             * duplication included.
             */

            case 'faq_set_save':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $saved = SFAF_FAQ_Sets::save(
                    isset( $_POST['faq_set_id'] ) ? sanitize_text_field( wp_unslash( $_POST['faq_set_id'] ) ) : '',
                    isset( $_POST['faq_set_name'] ) ? wp_unslash( $_POST['faq_set_name'] ) : '',
                    isset( $_POST['faq_set_rows'] ) ? wp_unslash( $_POST['faq_set_rows'] ) : array()
                );
                /*
                 * THE ANSWER IS REPORTED. save() refuses a set with no name and
                 * a set with no usable rows, and this discarded both, which was
                 * nearly unreachable while the only caller was an edit form that
                 * already had rows in it. The create control added in 3.38.0
                 * makes "submitted with every row blank" an ordinary mistake,
                 * and a success message for a set that was not made is worse
                 * than a refusal.
                 */
                if ( is_wp_error( $saved ) ) {
                    set_transient( 'sfaf_faq_set_error_' . $user->ID, $saved->get_error_message(), 60 );
                    $this->redirect( 'faq-sets', array( 'msg' => 'faq_set_failed' ) );
                }
                $this->redirect( 'faq-sets', array( 'msg' => 'faq_set_saved' ) );
                break;

            /*
             * DUPLICATE, ON THE SAME GATE AS ITS TWO NEIGHBOURS.
             *
             * can_view_all, which is what editing and deleting a set already
             * take. Duplication adds a row to a list every event picks from, so
             * it is an operation on the shared list and not on any one event.
             *
             * THE REDIRECT CARRIES THE NEW ID so the copy opens expanded with
             * its name field focused. Renaming is then the first thing that
             * happens rather than a step somebody skips, which is what stops
             * the list filling with sets called "copy". See render_faq_sets().
             */
            case 'faq_set_duplicate':
                if ( ! $this->can_view_all( $user ) ) { wp_die( 'Denied' ); }
                $copy = SFAF_FAQ_Sets::duplicate(
                    isset( $_POST['faq_set_id'] ) ? sanitize_text_field( wp_unslash( $_POST['faq_set_id'] ) ) : ''
                );
                if ( is_wp_error( $copy ) ) {
                    set_transient( 'sfaf_faq_set_error_' . $user->ID, $copy->get_error_message(), 60 );
                    $this->redirect( 'faq-sets', array( 'msg' => 'faq_set_failed' ) );
                }
                $this->redirect( 'faq-sets', array( 'msg' => 'faq_set_duplicated', 'edit' => $copy ) );
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

            /*
             * REMOVING A CALENDAR USER, WHICH IS NOW TWO STEPS WHEN IT HAS TO BE.
             *
             * Somebody's events do not leave with them. Until 3.35.0 this
             * removed the record and redirected, and any event they organized
             * was left with an organizer who could no longer open the calendar:
             * nobody could edit it except an admin, and nothing anywhere said
             * so. This is the deliberate moment, with an administrator present
             * and looking at the screen, so it is the right moment to ask.
             *
             * IT REFUSES RATHER THAN REASSIGNING BY ITSELF. Picking a new
             * organizer is a judgement about who is responsible for a piece of
             * work, and a default would be wrong often enough to matter. The
             * refusal names the count and the screen then offers the choice.
             * Same shape as refusing to delete a team that is in use.
             */
            case 'remove_user':
                if ( ! $this->is_admin_role( $user ) ) { wp_die( 'Denied' ); }
                $uid = intval( $_POST['user_id'] );

                $organized = SFAF_Portal::events_organized_by( $uid );
                $reassign  = isset( $_POST['reassign_to'] ) ? intval( $_POST['reassign_to'] ) : 0;

                if ( $organized && ! $reassign ) {
                    set_transient( 'sfaf_remove_user_blocked_' . $user->ID, array(
                        'user_id' => $uid,
                        'events'  => $organized,
                    ), 300 );
                    $this->redirect( 'users', array( 'msg' => 'reassign_first', 'uid' => $uid ) );
                }

                if ( $organized && $reassign ) {
                    // The new organizer must be somebody who can actually take
                    // it on. A reassignment to an account with no calendar
                    // access would produce the orphan this exists to prevent.
                    if ( ! self::get_role( $reassign ) || $reassign === $uid ) {
                        $this->redirect( 'users', array( 'msg' => 'reassign_invalid', 'uid' => $uid ) );
                    }
                    foreach ( $organized as $ev ) {
                        wp_update_post( array( 'ID' => (int) $ev['id'], 'post_author' => $reassign ) );
                    }
                }

                delete_user_meta( $uid, '_uc_calendar_role' );
                delete_user_meta( $uid, '_uc_calendar_approval' );
                delete_user_meta( $uid, '_uc_calendar_categories' );
                // Out of the calendar means out of its teams. Nothing else has
                // to happen: no event stored them, so no event has to be
                // corrected. See SFAF_Teams.
                SFAF_Teams::forget_user( $uid );
                $this->redirect( 'users', array(
                    'msg'      => $organized ? 'user_removed_reassigned' : 'user_removed',
                    'moved'    => $organized ? count( $organized ) : 0,
                ) );
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

        /*
         * WHAT THE EVENT WAS, BEFORE THIS SAVE TOUCHES IT.
         *
         * Taken here and compared at the end, because the "this event has
         * moved" email has to say the OLD value as well as the new one, and
         * once the meta is written the old value is gone. Three fields only:
         * date, time and location. Those are the three that change whether
         * somebody turns up. A description, a category or a capacity does not,
         * and mailing everybody who registered because a typo was fixed is how
         * a notification becomes something people filter.
         */
        $was = $event_id ? self::movable_facts( $event_id ) : array();

        $role      = self::get_role( $user->ID );
        $save_mode = isset( $_POST['save_mode'] ) ? sanitize_key( $_POST['save_mode'] ) : 'keep';

        /*
         * 'keep' IS THE ONLY MODE THAT DOES NOT DECIDE A STATUS.
         *
         * Every other one sets it, and the fallback used to be 'draft', so a
         * save that arrived without a save_mode took a published event off the
         * public calendar. That is not hypothetical: "Save Draft" was the first
         * submit button in the form, which is the one a browser activates when
         * somebody presses Enter in a text field.
         *
         * Leaving a status alone is the only safe thing to do with a status
         * nobody chose, so it is both a real mode and the fallback. A new event
         * has no status to keep, and a draft is what an unsaved event is.
         */
        if ( $save_mode === 'publish' ) {
            $status = in_array( $role, array( 'admin', 'editor' ), true ) ? 'publish' : $this->contributor_status( $user );
        } elseif ( $save_mode === 'review' ) {
            $status = 'pending';
        } elseif ( $save_mode === 'draft' ) {
            $status = 'draft';
        } else {
            $existing = $event_id ? get_post_status( $event_id ) : '';
            $status   = $existing ? $existing : 'draft';
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
        /*
         * ONLINE IS A THIRD ANSWER TO "WHERE", AND IT IS DECIDED FIRST.
         *
         * The venue/text pair is EITHER/OR so that nothing downstream has to
         * choose between two places. Online is the same rule with a third case:
         * it wins outright, and SFAF_Online::set() clears the term, the line
         * and the four parts in the one call, so no combination of them
         * survives for a renderer added later to find and believe.
         *
         * $handled is what stops the branches below writing a location back
         * onto an event that has just said it has none. Without it the form
         * posts location_mode as well (both controls are in the form, exactly
         * as the radio pair both submit) and the very next block would restore
         * what set() had deleted, in the same request.
         *
         * GATED ON THE SAME LOCK AS THE FIELD. An imported event is never
         * offered the control, and the marker never arrives from it, so this is
         * belt to those braces: a POST that has been tampered with cannot mark
         * a GoFundMe Pro campaign online.
         */
        $handled = false;
        if ( ! $is_locked( 'location' ) && isset( $_POST['uc_online_present'] ) ) {
            $want_online = ( '1' === (string) wp_unslash( $_POST['uc_online'] ) );
            SFAF_Online::set(
                $event_id,
                $want_online,
                isset( $_POST['meeting_url'] ) ? wp_unslash( $_POST['meeting_url'] ) : '',
                isset( $_POST['meeting_send'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['meeting_send'] ) ) : array()
            );
            $handled = $want_online;
        }

        if ( ! $handled && ! $is_locked( 'location' ) && isset( $_POST['location_mode'] ) ) {
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
        } elseif ( ! $handled && ! $is_locked( 'location' ) && isset( $_POST['location'] ) ) {
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
         * WHO GETS TOLD ON A NEW REGISTRATION.
         *
         * NO LONGER A FIELD OF ITS OWN. Until 3.25.0 this was a checkbox and a
         * single address, separate from the notification list that received the
         * reminder copy; both are now the one list, and whether the alert goes
         * at all is one of the four switches below. The old address was folded
         * into the list by sfaf_migrate_notification_lists() on upgrade.
         *
         * NOTHING WRITES THOSE TWO KEYS ANY MORE, here or in the WordPress
         * admin. The controls are gone from both editors, and a write with no
         * control behind it is a stored answer to a question nobody was asked.
         * The meta itself is left alone: it is the evidence for what the
         * migration folded into the list, and it is worth being able to read in
         * six months when somebody asks why an address is on it.
         */

        /*
         * WHICH OF THE FOUR EMAILS THIS EVENT SENDS.
         *
         * GUARDED BY A MARKER, for the reason every list on this form is: an
         * absent checkbox and a form that never asked are the same bytes, and
         * only the marker tells them apart. Without it, saving from the pending
         * queue would switch off every email on the event.
         *
         * STORED AS THE EXCEPTION. The ticked boxes are the ones that are ON,
         * so what is written is the complement: the keys the form offered and
         * did not get back. An event with all four on stores nothing at all,
         * which is what makes the default free.
         */
        if ( isset( $_POST['uc_notify_kinds_present'] ) ) {
            $offered = array_keys( SFAF_Notifications::kinds() );
            $on      = isset( $_POST['notify_kinds'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['notify_kinds'] ) ) : array();
            SFAF_Notifications::set_off( $event_id, array_diff( $offered, $on ) );
        }

        // The RSVP settings: capacity, whether to accept them, who is notified
        // and where replies go. Shared with the registrations page, written
        // once. See save_rsvp_settings_from_post().
        $this->save_rsvp_settings_from_post( $user, $event_id, $is_locked );

        $toggles = array(
            // 'notify_organizer' is not here, and is not written anywhere any
            // more: whether a registration alert goes out is one of the four
            // per-event email switches, saved above under its own marker.
            'show_rsvp'       => '_uc_show_rsvp',
            'show_donate'     => '_uc_show_donate',
            'show_social'     => '_uc_show_social',
            'show_calendar'   => '_uc_show_calendar',
            'show_reminders'  => '_uc_show_reminders',
        );
        /*
         * SHOW_CALENDAR IS NOT READ WHILE THE EVENT TAKES REGISTRATIONS.
         *
         * The editor greys that tick, because the button is off the event page
         * in that case and the calendar file goes out with the confirmation
         * instead. A disabled input posts nothing, so this loop would write '0'
         * and quietly forget the manager's own setting, and the next person to
         * switch registration off would find Add to calendar unticked without
         * having unticked it.
         *
         * IT IS NOT READ EITHER, WHICH IS THE OTHER HALF. A disabled control is
         * one that posts nothing today and posts something the day somebody
         * removes the attribute in the browser. The form is not the guarantee;
         * this test is.
         *
         * ORDER MATTERS AND IS SAFE. save_rsvp_settings_from_post() has already
         * written _uc_rsvp_enabled above, and 'show_rsvp' is written before
         * 'show_calendar' in the list below, so both halves of the predicate
         * are this save's values rather than the previous save's.
         */
        foreach ( $toggles as $field => $key ) {
            if ( 'show_calendar' === $field && sfaf_event_takes_rsvps( $event_id ) ) {
                continue;
            }
            /*
             * AND show_rsvp IS SKIPPED WHILE THE EVENT ACCEPTS NOTHING
             * (3.73.0), for the identical reason one line up. The editor
             * greys that tick, a disabled input posts nothing, and this
             * loop would write '0' and quietly forget the manager's own
             * setting. The next person to switch Accept RSVPs back on
             * would find the button unticked without having unticked it.
             *
             * ORDER MATTERS AND IS SAFE. save_rsvp_settings_from_post()
             * has already written _uc_rsvp_enabled, so this reads THIS
             * save's answer rather than the previous one, and show_rsvp
             * is written before show_calendar in $toggles so that test
             * reads this save too.
             */
            if ( 'show_rsvp' === $field
                && '1' !== (string) get_post_meta( $event_id, '_uc_rsvp_enabled', true ) ) {
                continue;
            }
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

        // Team access. Its own gate, checked again here. See the method.
        $this->save_access_from_post( $user, $event_id );

        // FAQ. One block, one key, on the event.
        $clean_faq = function ( $raw ) {
            $faqs = array();
            foreach ( (array) wp_unslash( $raw ) as $row ) {
                $q = isset( $row['question'] ) ? sanitize_text_field( $row['question'] ) : '';
                $a = isset( $row['answer'] ) ? SFAF_Rich_Text::sanitize( $row['answer'] ) : '';
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
        list( $pattern, $until, $limit, $extra_dates ) = $this->recurrence_from_post();
        /*
         * TWO WAYS TO HAVE SOMETHING TO GENERATE, AND EITHER IS ENOUGH.
         *
         * A bounded pattern is the original condition. A list of explicit dates
         * is the second: under Custom it is the only instruction there is, and
         * beside a pattern whose "Ends" answer was unusable it is still a set of
         * dates somebody typed. Both make events, both make a group, and the
         * series is created for both, because an event on several dates is a
         * series however those dates were arrived at.
         */
        $has_pattern = ( '' !== $pattern && ( '' !== $until || $limit > 0 ) );
        if ( ! $is_imported && ( $has_pattern || ! empty( $extra_dates ) ) ) {
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

            // 'custom' with dates behind it is a real pattern to store; a
            // pattern whose "Ends" answer was unusable is not, and generate()
            // treats an empty one as "produce nothing from a cadence", which
            // leaves the explicit dates as the whole set.
            $made      = SFAF_Recurrence::generate( $event_id, $pattern, $until, $limit, $extra_dates );
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

        /*
         * TELL THE REGISTRANTS, IF ANYTHING THEY CARE ABOUT MOVED.
         *
         * After apply_to_group(), deliberately, so a bulk save that moved
         * twelve occurrences reports on all twelve rather than on the one that
         * was open. SFAF_Announce groups by address, so somebody registered for
         * six of those twelve gets ONE email listing six dates.
         *
         * CONSENT IS AN ANSWER, NOT A LEFTOVER TICK (3.42.0).
         *
         * This used to read a checkbox that was ticked by default, so the
         * common case was mail going out because nobody noticed a box among
         * thirty other controls. It is now a dialog raised at the moment of
         * saving, and only an explicit 'send' sends. Anything else, including
         * a form posted with no answer at all, is silence: see
         * sfaf_should_notify().
         *
         * The DIFF still decides WHO and WHAT, exactly as before, and it is
         * still computed after the write so that a bulk save reports on every
         * occurrence it moved. Consent decides only whether that goes out.
         */
        $moved = array();
        if ( ! $is_new && $was ) {
            $ids = ( 'all_upcoming' === $scope && ! empty( $targets ) ) ? $targets : array( $event_id );
            foreach ( $ids as $id ) {
                $diff = self::movable_diff(
                    ( (int) $id === (int) $event_id ) ? $was : null,
                    $id
                );
                if ( $diff ) {
                    $moved[ (int) $id ] = $diff;
                }
            }
        }

        $told = 0;
        if ( $moved && sfaf_should_notify( $_POST ) ) {
            $result = SFAF_Announce::changed( array_keys( $moved ), $moved );
            $told   = (int) $result['sent'];
        }

        if ( $generated ) {
            $msg = 'generated_' . $generated;
        } elseif ( 'all_upcoming' === $scope ) {
            $msg = 'bulk_' . $written;
        } else {
            $msg = 'saved';
        }

        /*
         * 'moved' AND 'told' TRAVEL BACK SEPARATELY, because "nobody was
         * emailed" is only reassuring if the manager knows something moved.
         * A save that changed a description reports neither.
         */
        return array(
            'id'      => $event_id,
            'msg'     => $msg,
            'written' => $written,
            'scope'   => $scope,
            'moved'   => $moved ? 1 : 0,
            'told'    => $told,
        );
    }

    /* ---------------------------------------------------------------------
     * What counts as a change worth telling somebody about
     *
     * THREE FIELDS. Date, time and location, because those three decide whether
     * a person turns up in the right place at the right moment and nothing else
     * on the form does. A description, a category, a series, a capacity or an
     * image can all change without any registrant needing to know, and a
     * notification that goes out for those is one that gets filtered, taking
     * the date change with it.
     * ------------------------------------------------------------------- */

    /**
     * The three facts, as a person would read them.
     *
     * FORMATTED, NOT RAW. What is compared is what the email will print, so a
     * change that is invisible to a reader cannot produce an email. Storing
     * '18:00' as '6:00 pm' is not a change anybody should be told about, and
     * comparing raw values would send one.
     *
     * @param int $event_id
     * @return array<string,string> label => value
     */
    public static function movable_facts( $event_id ) {
        $event_id = (int) $event_id;
        $date     = (string) get_post_meta( $event_id, '_uc_event_date', true );
        $start    = (string) get_post_meta( $event_id, '_uc_start_time', true );
        $end      = (string) get_post_meta( $event_id, '_uc_end_time', true );

        return array(
            'Date'     => '' !== $date ? sfaf_ap_date( $date, 'full' ) : '',
            'Time'     => sfaf_ap_time_range( $start, $end ),
            'Location' => (string) sfaf_event_location( $event_id ),
        );
    }

    /**
     * What moved on this event, comparing a snapshot against the event now.
     *
     * @param array|null $before A movable_facts() snapshot, or null to read the
     *                           group member's own stored "before" (see below).
     * @param int        $event_id
     * @return array<string,array{from:string,to:string}> Empty when nothing moved.
     */
    public static function movable_diff( $before, $event_id ) {
        $event_id = (int) $event_id;

        /*
         * A GROUP MEMBER'S "BEFORE" IS NOT THE OPEN EVENT'S.
         *
         * apply_to_group() writes each occurrence its own previous values as it
         * goes, because occurrence three's old date is not occurrence one's and
         * an email saying otherwise would be wrong on eleven of twelve dates.
         * When that record is absent the event did not move, whatever else the
         * save did.
         */
        if ( null === $before ) {
            $stored = get_post_meta( $event_id, self::MOVED_FROM_META, true );
            delete_post_meta( $event_id, self::MOVED_FROM_META );
            if ( ! is_array( $stored ) || empty( $stored ) ) {
                return array();
            }
            $before = $stored;
        }

        $now  = self::movable_facts( $event_id );
        $diff = array();

        foreach ( $now as $label => $value ) {
            $old = isset( $before[ $label ] ) ? (string) $before[ $label ] : '';
            if ( $old === (string) $value ) {
                continue;
            }
            // A field that was empty and still is, or that has only ever been
            // empty, is not a move. Going from nothing to something IS one:
            // "the location is now the Castro office" is worth sending.
            if ( '' === $old && '' === (string) $value ) {
                continue;
            }
            $diff[ $label ] = array(
                'from' => '' !== $old ? $old : 'not set',
                'to'   => '' !== (string) $value ? (string) $value : 'not set',
            );
        }

        return $diff;
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
     *   the editor renders exactly what it names as disabled, with the reason
     *   under the control.
     *
     *   ANYTHING A TARGET'S OWN SOURCE OWNS. A generated event has no source,
     *   so this should never trigger — but if an imported event ever ends up in
     *   a group, a bulk edit must not write a field the next fetch will
     *   overwrite anyway.
     *
     *   THE SLUG. Permalinks are live URLs and are not a detail of an edit.
     *   PRIVACY IS THE ONE EXCEPTION, AND IT IS NOT REALLY ONE: there the slug
     *   is not a detail of the edit, it IS the edit. See below.
     *
     * PRIVACY TRAVELS, AND IT IS NOT A META COPY. It used to touch only the row
     * it was ticked on, while its own label promised it hid the event
     * everywhere, so a weekly reception made private left every other date
     * public. Every other field on this form respects the scope answer and
     * privacy was ignoring it. It cannot go in $meta_keys either, because
     * copying _uc_private without replacing the target's slug produces an event
     * that claims to be private at a guessable address, which is the one state
     * the feature cannot have. So it goes through SFAF_Privacy::set(), which
     * moves the meta and the address together, per target, each keeping its own
     * remembered readable slug. Past occurrences are never in $targets, so they
     * are never reached, exactly as with every other bulk edit.
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

        // Read back from the saved source, like everything else here: the
        // per-event save has already run SFAF_Privacy::set() on it.
        $source_private = SFAF_Privacy::is_private( $source_id );

        $meta_keys = array(
            '_uc_start_time', '_uc_end_time', '_uc_location',
            /*
             * ONLINE TRAVELS WITH THE GROUP, AND IT IS AN ORDINARY META COPY.
             *
             * Unlike privacy, which cannot go in this list because the slug is
             * the other half of its state, these three are just values: the
             * tick, the link and which messages carry it. A weekly group that
             * moves online moves online on every upcoming date, which is what
             * the scope answer promised. The list is SFAF_Online::meta_keys()
             * rather than three literals so a key added there travels without
             * anybody remembering to come back here.
             *
             * The loop below DELETES a key whose source value is empty, which
             * is exactly right for the tick coming off: the target loses the
             * link and the delivery ticks along with it, the same way
             * SFAF_Online::set() clears them on the source.
             */
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
        $meta_keys = array_merge( $meta_keys, SFAF_Online::meta_keys() );
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

            /*
             * EACH OCCURRENCE RECORDS ITS OWN "BEFORE", HERE, BEFORE IT MOVES.
             *
             * Occurrence three's old time is not occurrence one's, so a single
             * snapshot taken from the event that happens to be open in the
             * editor would put the wrong old value in eleven of twelve emails.
             * Written as meta rather than returned because this method's answer
             * is a count and has three callers; movable_diff() reads it back and
             * deletes it in the same breath, so nothing accumulates and a stale
             * record cannot be mistaken for a fresh one on a later save.
             *
             * The date is not in the group meta list below, so an occurrence
             * keeps its own date and only the time and the location can move
             * here. It is recorded anyway: what is compared is what the email
             * prints, and this method is not the only thing that may ever
             * change these.
             */
            update_post_meta( $target_id, self::MOVED_FROM_META, self::movable_facts( $target_id ) );

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

            /*
             * LAST, AND AFTER wp_update_post(). set() changes the slug, and the
             * postarr above sets post_status; running privacy first would have
             * the status write land on a post whose address had just moved, for
             * no reason. It is a no-op when the target already agrees, so a
             * bulk edit that did not touch privacy churns no addresses.
             */
            SFAF_Privacy::set( $target_id, $source_private );

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
        if ( ! is_user_logged_in() ) {
            wp_die( 'Denied' );
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'uc_portal_export' ) ) {
            wp_die( 'Security check failed.' );
        }

        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $orphans  = ! empty( $_GET['orphans'] );

        /*
         * THE EXPORT ASKS THE SAME GATE AS THE SCREEN, PARAMETER FOR PARAMETER.
         *
         * This is a download of exactly what render_rsvps() would show for the
         * same query string, so it has to make the same decision from the same
         * inputs. A gate that is merely "as strict" is not enough: uc_export_rsvps
         * (defect three in PROJECT.md §5) was a download link whose gate had
         * drifted from its screen's, and any Author on the site could fetch the
         * whole registration list by calling it directly. Scoped to one event,
         * the event gate. Unscoped, and for the orphan view, can_view_all.
         */
        $user = wp_get_current_user();
        if ( $event_id ) {
            $event = get_post( $event_id );
            if ( ! $event || 'uc_event' !== $event->post_type || ! $this->can_edit_event( $user, $event ) ) {
                wp_die( 'Denied' );
            }
        } elseif ( ! $this->can_view_all( $user ) ) {
            wp_die( 'Denied' );
        }
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

        // Two name columns, matching SFAF_RSVP::export_csv() exactly. Two
        // exports of the same table with different headings is how the wrong
        // one gets pasted into Salesforce.
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Event', 'First Name', 'Last Name', 'Email', 'Phone', 'Status', 'Date Registered' ) );
        foreach ( $rsvps as $r ) {
            $title = SFAF_RSVP::event_label( $r );
            fputcsv( $out, array(
                $this->csv( $title ), $this->csv( $r->first_name ), $this->csv( $r->last_name ),
                $this->csv( $r->email ),
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
     * THE FAVICON (3.49.0), FROM THE ONE PLACE THAT DECLARES IT (3.66.0).
     *
     * The comment that stood here said these three lines reached caladmin and
     * nothing else, and that the public forms did not need them because they
     * went through wp_head(). THEY DO NOT: both forms and the notice page write
     * their own documents and call wp_head() nowhere, which is why they had no
     * icon at all. sfaf_favicon_links() is now the single declaration and all
     * four self-built documents call it.
     */
    sfaf_favicon_links();
    ?>
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
    // Either control needs whatever it enqueued printed into this head.
    if ( $this->load_media || $this->load_editor ) {
        wp_print_styles();
        wp_print_head_scripts();
    }
    ?>
</head>
<body class="uc-portal"><?php
    }

    private function foot() {
        // The portal builds its own document, so whatever either control
        // enqueued has to be printed by hand.
        if ( $this->load_media || $this->load_editor ) {
            wp_print_footer_scripts();
        }

        /*
         * MEDIA TEMPLATES ONLY WHERE THE MEDIA LIBRARY WAS ENQUEUED.
         *
         * This is the 500. It used to hang off the one flag, so a screen that
         * set the flag for the editor alone reached a media-stack function on a
         * request that had never loaded the media stack.
         */
        if ( $this->load_media ) {
            wp_print_media_templates();
        }

        if ( $this->load_editor ) {
            /*
             * THE TOOLBAR, ONCE, FOR THE ROWS THE BROWSER BUILDS.
             *
             * A FAQ row cloned after load has no editor rendered for it, so
             * portal.js starts one with wp.editor.initialize(). It reads the
             * settings from here rather than carrying its own copy, because
             * two definitions of what somebody may type is the drift this
             * whole arrangement exists to prevent. See SFAF_Rich_Text.
             */
            ?><script type="application/json" id="uc-rich-settings"><?php
                echo SFAF_Rich_Text::settings_json();
            ?></script><?php
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
            /*
             * IMAGES, FOR EVERYBODY WITH CALADMIN ACCESS (3.74.0).
             *
             * Not gated, because looking is what most people come here to do:
             * an organizer wanting to know what pictures exist for their
             * programme had nowhere at all to find out, since contributors and
             * editors never see wp-admin and the WordPress media library is
             * therefore not available to them. What IS gated is doing
             * something: tagging is admins and editors, uploading is admins,
             * and the screen draws neither control for anybody else.
             */
            'media'     => array( 'Images', 'media', 'image' ),
        );
        if ( $this->can_view_all( $user ) ) {
            // Venues moved here from the WordPress admin in 3.9.0. Deciding
            // where events are held is event management, and the address they
            // carry is what the Location field on every event now points at.
            $nav['venues'] = array( 'Venues', 'venues', 'venue' );

            /*
             * ORGANIZERS, STANDALONE (3.37.0).
             *
             * Its own entry rather than folded into "Series & Categories", for
             * the reason that pairing exists at all: those two are grouped
             * because a category is a property OF a series' events and the two
             * are edited together. An organizer is not a property of either. It
             * is who is putting the event on, it is the one taxonomy with no
             * caladmin screen until now, and burying it inside a heading naming
             * two other things is how it stays unfindable.
             */
            $nav['organizers'] = array( 'Organizers', 'organizers', 'users' );

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
            $nav['users']   = array( 'Users & Teams', 'users', 'users' );
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
            /* The same arguments the screen itself uses, or the badge counts a
             * different set from the list it links to. */
            $pending_count  = count( $this->query_events( $user, $this->pending_query_args() ) );
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
            'bulk_cat_none'  => 'Nothing was changed. Tick the events you want the category added to, then press the button.',
            'bulk_cat_failed' => 'That category could not be applied. Choose one from the list and try again.',
            'bulk_pub_none'  => 'Nothing was published. Tick the drafts you want on the public calendar, then press Publish. A draft that says why it cannot be published is not one this button can touch.',
            'delete_needs_cancel' => 'This event has people registered, so it cannot be deleted. Cancel it instead: that keeps the registrations, closes new ones, stops the reminders, and offers to tell everybody who signed up. Once it is cancelled you can delete it.',
            'series_needs_cancel' => 'Some events in this series have people registered, so deleting them is refused. Cancel them instead, below. Once they are cancelled and the people who signed up have been told, the series can be deleted.',
            'cancelled'      => 'Event cancelled. It takes no new registrations, and neither the morning-of reminder nor the two-hour summary will go out for it.',
            'uncancelled'    => 'Event is on again. Registrations are open and its reminders will go out as usual.',
            'cancel_hidden'  => 'Off the public calendar. It is still cancelled, its page still opens and still says so, and the registrations are kept. Nobody has been told.',
            'cancel_listed'  => 'Back on the public calendar, marked cancelled. Nobody has been told.',
            'cancel_failed'  => 'That could not be changed. The event is not cancelled.',
            'duplicated'     => 'Copied. This is a new draft with no date and no registrations, and the event it came from is unchanged. Set the date, check the details, then publish. If the original was imported, this copy is not: nothing here is tied to the platform and every field is yours to edit.',
            'duplicate_failed' => 'That event could not be copied.',
            'approved'       => 'Event approved and published.',
            'rejected'       => 'Event rejected.',
            /*
             * THE SHAPE WARNING IS HERE AND NOT ON THE BUTTON, because it is a
             * thing that will have happened rather than a thing to decide. A
             * submitted photo is whatever shape the person had; the calendar
             * folder's pictures are 16:9 because somebody made them that way,
             * and the card crops to 16:9 either way. So a portrait photo will
             * lose its top and bottom on a card, and this says so at the moment
             * it becomes possible rather than leaving it to be found on a
             * published event.
             */
            'image_used'     => 'That is the event\'s picture now. Cards crop to 16:9, so check how a tall or square photo looks before publishing.',
            'image_failed'   => 'That picture could not be used. It is no longer on the event, or it is not an image.',
            'user_saved'     => 'User permissions updated.',
            'user_added'     => 'User added to the calendar system.',
            'user_removed'   => 'User removed from the calendar system, and taken out of any teams they were in. They organized no events, so nothing needed reassigning.',
            'user_removed_reassigned' => 'User removed from the calendar system, and taken out of any teams they were in. Their events were handed to the person you chose.',
            'reassign_first' => 'That person organizes events. Choose who should take them on before removing them, so nothing is left with nobody responsible for it.',
            'reassign_invalid' => 'That is not somebody who can take those events on. Pick a person who has calendar access.',
            'team_saved'     => 'Team saved. Everybody in it can edit the events this team has access to, from now, including events set up before they joined.',
            'team_deleted'   => 'Team deleted. No event named it, so nobody lost access and no notification changed.',
            'series_saved'   => 'Series saved. Nothing about the events in it changed: a series groups them, it does not overwrite them.',
            'category_saved' => 'Category saved. Its color and icon are what a card and its placeholder are drawn from, so events in it change appearance straight away.',
            'category_failed'=> 'That category could not be saved. Give it a name and try again.',
            'series_failed'  => 'That series could not be saved. Give it a name and try again.',
            'faq_set_failed' => 'That set could not be saved.',
            // 'series_removed' is built from real counts further down, because
            // what it did depends on which option was chosen.
            'fetched'        => 'Fetch complete. See the results below.',
            'import_dismissed' => 'Event dismissed. It stays in the Dismissed list and will not be fetched again.',
            'import_restored'  => 'Event restored to Pending.',
            'import_review'    => 'Assign a category, organizer, and series, then press Publish to put this event on the calendar.',
            'refreshed'        => 'Refreshed from the source. See below for what changed.',
            'faq_set_applied'  => 'FAQ set applied.',
            'faq_set_saved'    => 'FAQ set saved.',
            'faq_set_duplicated' => 'Copy made. Give it a name. Nothing about the original changed, and the two are separate from here on.',
            'faq_set_deleted'  => 'FAQ set deleted. Events that already used it keep their questions, because the rows were copied.',
            'manager_saved'    => 'Saved. Those are the same fields the event editor shows, so the event now reads the same in both places.',
            'rsvp_settings_saved' => 'Registration settings saved. These are the same controls the event editor shows, on the same event, so it now reads the same in both places.',

            // The schedule.
            'schedule_added'    => 'Date added. It is an ordinary event, identical to the others, with nobody registered yet. A time change to the group reaches it; a change to the pattern leaves it where it is.',
            'schedule_removed'  => 'Date removed. Nothing regenerates it, including extending the series later, so it stays removed. Any registrations against it are kept.',
            'schedule_unchanged'=> 'Nothing to change: the schedule already says that.',
            'schedule_pattern_refused' => 'That pattern could not be laid out across every upcoming date, so nothing was moved. Choose a different pattern, or remove some dates first.',
            'schedule_extend_bad_date' => 'Give the date to run through as a real date.',
            'schedule_extend_no_cadence' => 'These dates were chosen one at a time rather than produced by a pattern, so there is no cadence to carry forward. Add each new date below.',
            'schedule_extend_nothing'  => 'Nothing to add: every date the pattern produces up to then is already on the schedule, or was removed on purpose.',
            'schedule_extend_failed'   => 'The series could not be extended.',
            'schedule_publish_none'    => 'Nothing was published. Either no date was ticked, or the drafts left are ones this action does not touch.',
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

        /*
         * PUTTING IT BACK ON SAYS WHETHER ANYBODY WAS TOLD (3.73.0), for the
         * reason cancelling has said so since 3.42.0: the question is asked in
         * a dialog, a dialog is a thing somebody can mis-click, and the screen
         * they land on has to state which of the two answers actually happened.
         */
        if ( 'uncancelled' === $key ) {
            $told = isset( $_GET['told'] ) ? max( 0, intval( $_GET['told'] ) ) : 0;
            echo '<div class="uc-flash uc-flash-ok">'
                . esc_html(
                    'Event is on again. Registrations are open and its reminders will go out as usual. '
                    . ( $told
                        ? sprintf( '%d %s told it is back on.', $told, _n( 'person was', 'people were', $told ) )
                        : 'Nobody was emailed.' )
                )
                . '</div>';
            return;
        }

        if ( 'cancelled' === $key ) {
            $told = isset( $_GET['told'] ) ? max( 0, intval( $_GET['told'] ) ) : 0;
            echo '<div class="uc-flash uc-flash-ok">'
                . esc_html(
                    'Event cancelled. It takes no new registrations, and neither the morning-of reminder nor the two-hour summary will go out for it. '
                    . ( $told
                        ? sprintf( '%d %s told.', $told, _n( 'person was', 'people were', $told ) )
                        : 'Nobody was emailed.' )
                )
                . '</div>';
            return;
        }

        if ( 'series_cancelled' === $key ) {
            $n    = isset( $_GET['n'] ) ? max( 0, intval( $_GET['n'] ) ) : 0;
            $told = isset( $_GET['told'] ) ? max( 0, intval( $_GET['told'] ) ) : 0;
            echo '<div class="uc-flash uc-flash-ok">'
                . esc_html( sprintf(
                    '%d %s cancelled, and every registration kept. %s The series still exists and can be deleted now.',
                    $n,
                    _n( 'event was', 'events were', $n ),
                    $told
                        ? sprintf( '%d %s told, one email each however many dates they were registered for.', $told, _n( 'person was', 'people were', $told ) )
                        : 'Nobody was emailed.'
                ) )
                . '</div>';
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
            $n    = isset( $_GET['written'] ) ? max( 0, intval( $_GET['written'] ) ) : 0;
            $left = isset( $_GET['left'] ) ? max( 0, intval( $_GET['left'] ) ) : 0;
            $said = sprintf(
                'Schedule updated. %d upcoming %s changed.',
                $n,
                _n( 'occurrence was', 'occurrences were', $n )
            );
            /*
             * WHERE THE GROUP NOW RUNS FROM AND TO, IN DATES.
             *
             * A cadence change can move a term's programming a long way: twelve
             * daily sessions become twelve weekly ones and the last of them is
             * eleven weeks further out than it was. "12 occurrences were
             * changed" is true and says nothing about that, and the schedule
             * list below is the only other place to find out. Naming the first
             * and last date is one sentence and settles it.
             */
            $span = $this->schedule_span_phrase();
            if ( '' !== $span ) {
                $said .= ' ' . $span;
            }
            // Named, not implied. The screen warned that a cadence change leaves
            // extra dates alone; this is where it says that it did.
            if ( $left > 0 ) {
                $said .= ' ' . sprintf(
                    '%d extra %s not moved, because %s never on the pattern.',
                    $left,
                    _n( 'date was', 'dates were', $left ),
                    _n( 'it was', 'they were', $left )
                );
            }
            $said .= ' Past dates were not touched.';
            echo '<div class="uc-flash">' . esc_html( $said ) . '</div>';
            return;
        }

        /*
         * THE BULK CATEGORY OUTCOME NAMES THE CATEGORY AND THE COUNT
         * (3.73.0), because "Done" over a list of 259 rows is not an
         * answer: the whole risk of a bulk action is doing it to the wrong
         * set, and the only thing that settles it is being told what
         * happened.
         *
         * REFUSALS ARE SAID SEPARATELY AND ONLY WHEN THERE WERE ANY. A
         * contributor who ticks Select all on a page holding somebody
         * else's events gets the ones they may edit and a count of the
         * ones they may not, rather than a silent partial success.
         */
        /*
         * THE PUBLISH OUTCOME NAMES EVERY BUCKET (3.79.0), and it has four
         * where the category outcome has two. Publishing is the one bulk
         * action on this screen that reaches the public calendar, so "12
         * published" over a tick of 40 is not an answer: the other 28 went
         * somewhere, and a person who cannot see where will press it again.
         *
         * SKIPPED AND REFUSED ARE DIFFERENT AND ARE SAID DIFFERENTLY. Refused
         * is "not yours"; skipped is "not this button's". Rolling them into
         * one number would tell somebody their permissions are wrong when what
         * is actually true is that they ticked a past date.
         */
        if ( 'bulk_pub_done' === $key ) {
            $did     = isset( $_GET['did'] ) ? (int) $_GET['did'] : 0;
            $failed  = isset( $_GET['failed'] ) ? (int) $_GET['failed'] : 0;
            $refused = isset( $_GET['refused'] ) ? (int) $_GET['refused'] : 0;
            $skipped = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;

            $said = sprintf(
                /* translators: 1: how many events, 2: event or events. */
                _n( '%1$d %2$s published.', '%1$d %2$s published.', $did ),
                $did,
                _n( 'event', 'events', $did )
            );
            $said .= ' They are on the public calendar now.';
            if ( $skipped > 0 ) {
                $said .= sprintf(
                    /* translators: %d: how many were not publishable. */
                    _n(
                        ' %d could not be published and was left as it was.',
                        ' %d could not be published and were left as they were.',
                        $skipped
                    ),
                    $skipped
                );
            }
            if ( $refused > 0 ) {
                $said .= sprintf(
                    /* translators: %d: how many events were not the viewer's. */
                    _n( ' %d was not yours to change.', ' %d were not yours to change.', $refused ),
                    $refused
                );
            }
            if ( $failed > 0 ) {
                $said .= sprintf(
                    /* translators: %d: how many writes failed. */
                    _n( ' %d could not be saved. Open it and publish it on its own.',
                        ' %d could not be saved. Open them and publish them one at a time.',
                        $failed ),
                    $failed
                );
            }
            return $said;
        }

        if ( 'bulk_cat_done' === $key ) {
            $did     = isset( $_GET['did'] ) ? (int) $_GET['did'] : 0;
            $refused = isset( $_GET['refused'] ) ? (int) $_GET['refused'] : 0;
            $cat     = isset( $_GET['cat'] ) ? sanitize_text_field( wp_unslash( $_GET['cat'] ) ) : '';

            $said = sprintf(
                /* translators: 1: how many events, 2: event or events, 3: the category name. */
                _n( 'Added to %1\$d %2\$s.', 'Added to %1\$d %2\$s.', $did ),
                $did,
                _n( 'event', 'events', $did )
            );
            if ( '' !== $cat ) {
                $said = sprintf( '%s added to %d %s.', $cat, $did, _n( 'event', 'events', $did ) );
            }
            $said .= ' They kept the categories they already had.';
            if ( $refused > 0 ) {
                $said .= sprintf(
                    /* translators: %d: how many events were skipped. */
                    _n( ' %d was not yours to change and was skipped.', ' %d were not yours to change and were skipped.', $refused ),
                    $refused
                );
            }
            return $said;
        }

        if ( 'schedule_published' === $key ) {
            $made   = isset( $_GET['made'] ) ? max( 0, intval( $_GET['made'] ) ) : 0;
            $failed = isset( $_GET['failed'] ) ? max( 0, intval( $_GET['failed'] ) ) : 0;

            $said = sprintf( '%d %s now on the public calendar.', $made, _n( 'draft is', 'drafts are', $made ) );
            $said .= ' Past dates, events with no date yet, imported events and submissions were left as they were.';
            if ( $failed > 0 ) {
                $said .= ' ' . sprintf( '%d could not be published and %s still %s.', $failed, _n( 'is', 'are', $failed ), _n( 'a draft', 'drafts', $failed ) );
            }
            echo '<div class="uc-flash">' . esc_html( $said ) . '</div>';
            return;
        }

        if ( 'schedule_extended' === $key ) {
            $made    = isset( $_GET['made'] ) ? max( 0, intval( $_GET['made'] ) ) : 0;
            $from    = $this->schedule_date_arg( 'from' );
            $through = $this->schedule_date_arg( 'through' );

            $said = sprintf( '%d %s added on the existing pattern.', $made, _n( 'date was', 'dates were', $made ) );
            if ( '' !== $from ) {
                $said .= ' ' . sprintf( 'The series ran to %s and now runs to %s.', $from, $through );
            }
            $said .= ' Nothing already on the schedule was changed, and dates you had removed stayed removed.';
            echo '<div class="uc-flash">' . esc_html( $said ) . '</div>';
            return;
        }

        if ( 'schedule_extend_not_further' === $key ) {
            $from = $this->schedule_date_arg( 'from' );
            echo '<div class="uc-flash">' . esc_html(
                '' !== $from
                    ? sprintf( 'That date is not past the end of the series, which already runs to %s. Choose a later one.', $from )
                    : 'That date is not past the end of the series. Choose a later one.'
            ) . '</div>';
            return;
        }

        if ( 'schedule_extend_nothing' === $key ) {
            $from = $this->schedule_date_arg( 'from' );
            echo '<div class="uc-flash">' . esc_html(
                'Nothing to add: every date the pattern produces up to then is already on the schedule, or was removed on purpose.'
                . ( '' !== $from ? sprintf( ' The series still runs to %s.', $from ) : '' )
            ) . '</div>';
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
            ) . $this->notify_outcome() ) . '</div>';
            return;
        }
        if ( 0 === strpos( $key, 'generated_' ) ) {
            $n = (int) substr( $key, 10 );
            echo '<div class="uc-flash">' . esc_html( sprintf(
                'Saved, and %d further %s created. Each one is a separate event you can edit or delete on its own, and nothing regenerates them.',
                $n,
                _n( 'date was', 'dates were', $n )
            ) ) . '</div>';
            return;
        }

        if ( isset( $map[ $key ] ) ) {
            $extra = ( 'saved' === $key ) ? $this->notify_outcome() : '';
            echo '<div class="uc-flash">' . esc_html( $map[ $key ] . $extra ) . '</div>';
        }
    }

    /**
     * What the save did about telling the people who are registered.
     *
     * SAID EVERY TIME SOMETHING MOVED, INCLUDING WHEN NOTHING WAS SENT.
     *
     * The question is a dialog now, and a dialog can be mis-clicked or
     * dismissed by a browser nobody tested. Leaving the manager to assume is
     * how somebody believes twelve people were told that an event moved when
     * they were not. So the screen the save lands on states which of the two
     * happened, in the same number the dialog asked about.
     *
     * NOTHING IS SAID WHEN NOTHING MOVED, because then there was never a
     * question: a save that changed the description is not a save anybody
     * needed to be told about, and a line saying "nobody was emailed" on one is
     * noise that would train people to stop reading the flash.
     *
     * @return string Empty, or a sentence to append.
     */
    private function notify_outcome() {
        if ( empty( $_GET['moved'] ) ) {
            return '';
        }
        $told = isset( $_GET['told'] ) ? absint( $_GET['told'] ) : 0;
        if ( $told > 0 ) {
            return sprintf(
                ' %d %s emailed about the change.',
                $told,
                _n( 'person was', 'people were', $told )
            );
        }
        return ' The date, time or location changed and nobody was emailed about it.';
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

        <?php
        /*
         * THE FORM LINKS, ABOVE THE NUMBERS AND CLOSED.
         *
         * Here because this is the screen everybody with caladmin access lands
         * on, and it is offered to all of them rather than to admins only: a
         * contributor has as much reason to send somebody the community form.
         * Closed, and one line tall, because it is opened when a campaign
         * launches and not on the daily visit.
         */
        $this->render_form_links( $user );
        ?>

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
     * The two public form links, on the screen everybody lands on.
     *
     * NOTHING IN CALADMIN LINKED TO EITHER FORM. Both are reached by a plain
     * URL, neither is advertised anywhere in this portal on purpose, and the
     * effect was that sending somebody a link meant remembering its shape. The
     * staff form's is one query var; the community form's names a series, so it
     * is a different link per series and exactly the thing nobody should be
     * assembling by hand.
     *
     * ON THE DASHBOARD, NOT ON PENDING, AND VISIBLE TO EVERYONE WHO GETS HERE.
     * Pending is admin-only, and a contributor has as much reason to send
     * somebody the community form as Mark does. There is no capability check in
     * this method for that reason: reaching caladmin at all is the gate, and
     * neither link is a secret. The forms have their own protection, and it is
     * not the obscurity of their addresses: the staff form emails a token to an
     * sfaf.org address before it shows anything, and the community form is rate
     * limited and produces a PENDING row that somebody has to approve.
     *
     * A DISCLOSURE, NOT A CARD. This is opened when a campaign launches or when
     * somebody asks, which is not daily, so it must not take space from the
     * things the dashboard is actually for. portal.js lifts the panel into a
     * <dialog>; with no JavaScript the <details> opens in place and every link
     * is already written out, which is the same fallback shape the approval
     * prompt uses.
     *
     * @param WP_User $user
     */
    private function render_form_links( $user ) {
        $series = SFAF_Series::all();
        ?>
        <details class="uc-form-links" id="uc-form-links" data-uc-form-links>
            <summary class="uc-form-links-open">
                <?php echo sfaf_icon( 'link', array( 'size' => '15px' ) ); ?>
                <span>Get a form link</span>
            <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '15px' ) ); ?></span>
            </summary>

            <div class="uc-form-links-body">
                <h2 class="uc-form-links-title">Links to the event forms</h2>

                <div class="uc-form-link">
                    <h3>Staff form</h3>
                    <p class="uc-hint">For anyone with an sfaf.org address. They enter it, and the form arrives by email.</p>
                    <div class="uc-form-link-row">
                        <input type="text" class="uc-form-link-url" readonly onfocus="this.select();"
                               aria-label="Staff form link"
                               value="<?php echo esc_attr( SFAF_Request::start_url() ); ?>" />
                        <button type="button" class="uc-btn uc-btn-sm uc-copy-btn" data-uc-copy>Copy</button>
                    </div>
                </div>

                <div class="uc-form-link">
                    <h3>Community form</h3>
                    <?php
                    /*
                     * THREE NAMES FOR ONE TAXONOMY, ON PURPOSE (3.66.0). Do not
                     * "fix" this by making them agree; each one is chosen for
                     * who is reading it.
                     *
                     *   Groups        the public calendar's filter row. A
                     *                 visitor should not have to know this
                     *                 calendar has a taxonomy, let alone what
                     *                 it is called.
                     *   Series        everywhere in caladmin. The people there
                     *                 manage them and it is the word the
                     *                 screens, the URL and the code all use.
                     *   Event Series  here, and only here. Somebody in this
                     *                 dialog is choosing WHICH KIND OF THING a
                     *                 link points at, with a staff form link
                     *                 sitting directly above that points at no
                     *                 series at all. "Campaign" was a fourth
                     *                 name and named nothing in the product.
                     */
                    ?>
                    <?php if ( empty( $series ) ) : ?>
                        <?php
                        /*
                         * NO SERIES MEANS NO LINK, AND IT SAYS SO. The community
                         * form takes a series slug and refuses an unknown one, so
                         * offering an empty picker here would produce an address
                         * that goes to the "that link is not right" page.
                         */
                        ?>
                        <p class="uc-hint">This form opens an event series by name, and there are no series yet.
                            <a href="<?php echo esc_url( $this->url( 'series' ) ); ?>">Create one</a> and its link appears here.</p>
                    <?php else : ?>
                        <p class="uc-hint">Choose the event series. Each one has its own link, and a submission arrives against that series.</p>
                        <div class="uc-form-link-pick">
                            <label class="uc-field">
                                <span class="uc-field-label">Event Series</span>
                                <select class="uc-form-link-series" data-uc-form-link-series>
                                    <?php foreach ( $series as $term ) : ?>
                                        <option value="<?php echo esc_attr( SFAF_Submit::url( $term->slug ) ); ?>">
                                            <?php echo esc_html( $term->name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <div class="uc-form-link-row">
                            <input type="text" class="uc-form-link-url" readonly onfocus="this.select();"
                                   aria-label="Community form link"
                                   data-uc-form-link-out
                                   value="<?php echo esc_attr( SFAF_Submit::url( $series[0]->slug ) ); ?>" />
                            <button type="button" class="uc-btn uc-btn-sm uc-copy-btn" data-uc-copy>Copy</button>
                        </div>
                        <?php
                        /*
                         * WITHOUT JAVASCRIPT THE PICKER CANNOT REWRITE THE BOX,
                         * so the box would keep showing the first series' link
                         * whatever was chosen: a wrong answer presented as a right
                         * one. Every link is written out instead, and the picker
                         * above it is the thing that is missing rather than the
                         * links.
                         */
                        ?>
                        <noscript>
                            <ul class="uc-form-link-all">
                                <?php foreach ( $series as $term ) : ?>
                                    <li>
                                        <strong><?php echo esc_html( $term->name ); ?></strong>
                                        <code><?php echo esc_html( SFAF_Submit::url( $term->slug ) ); ?></code>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </noscript>
                    <?php endif; ?>
                </div>
            </div>
        </details>
        <?php
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
                    <?php
                    /*
                     * NO TICK COLUMN HERE, AND THERE NEVER SHOULD HAVE BEEN
                     * ONE (3.79.0).
                     *
                     * 3.73.0 inserted the bulk tick cell into THIS loop rather
                     * than events_table()'s. The two loops open identically,
                     * `$date`, then `$st = get_post_status( $id )`, then `<tr>`,
                     * which is how it went unnoticed, and it produced both
                     * halves of one fault at once: the events list got a tick
                     * COLUMN HEADER with no cell under it in any row, and this
                     * read-only dashboard table got a cell with no header over
                     * it, gated on a `$plain` that does not exist in this
                     * method and posting to a form that is not on this screen.
                     *
                     * THIS TABLE IS READ-ONLY BY DESIGN. It is a separate
                     * renderer from events_table() precisely so it has no
                     * destructive controls to leak, and a bulk selector is one.
                     */
                    ?>
                    <td><a class="uc-tlink" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></a></td>
                    <td><?php echo $date ? esc_html( sfaf_ap_date( $date, 'short_year' ) ) : '<span class="uc-muted">None</span>'; ?></td>
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
                "SELECT event_id, name, first_name, last_name, status, created_at FROM $table ORDER BY id DESC LIMIT 5"
            );
        } else {
            $rsvps = $wpdb->get_results( $wpdb->prepare(
                "SELECT r.event_id, r.name, r.first_name, r.last_name, r.status, r.created_at FROM $table r
                 INNER JOIN {$wpdb->posts} p ON p.ID = r.event_id
                 WHERE p.post_author = %d
                 ORDER BY r.id DESC LIMIT 5",
                (int) $user->ID
            ) );
        }
        foreach ( (array) $rsvps as $r ) {
            $title = get_the_title( $r->event_id );
            $who   = SFAF_RSVP::display_name( $r );
            $rows[] = array(
                'when' => strtotime( $r->created_at ),
                'text' => sprintf(
                    '%s %s for %s',
                    '' !== $who ? $who : 'Someone',
                    'cancelled' === $r->status ? 'canceled their place' : 'registered',
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
                        <span class="uc-fetch-line"><?php echo esc_html( SFAF_Sources::summarize( $result ) ); ?></span>

                        <?php
                        /*
                         * WHICH EVENTS CHANGED, BY NAME.
                         *
                         * The gap this closes: the panel said four were updated
                         * and never said which four. A source overwriting a
                         * field a manager cares about is the thing they are
                         * reading this to catch, and a count cannot tell them.
                         *
                         * Each row links to the editor, because the next thing
                         * after "it changed the description" is looking at it.
                         */
                        if ( ! empty( $result['changed'] ) ) : ?>
                            <ul class="uc-fetch-changed">
                                <?php foreach ( $result['changed'] as $ch ) :
                                    $phrase = ( 'new' === $ch['kind'] )
                                        ? 'added'
                                        : SFAF_Sources::field_change_phrase( $ch['fields'] );
                                    ?>
                                    <li>
                                        <a class="uc-tlink" href="<?php echo esc_url( $this->url( 'events/edit/' . (int) $ch['id'] ) ); ?>"><?php
                                            echo esc_html( '' !== trim( (string) $ch['title'] ) ? $ch['title'] : '(untitled)' ); ?></a>
                                        <?php if ( '' !== $phrase ) : ?>
                                            <span class="uc-fetch-what"><?php echo esc_html( $phrase ); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php // Whether removal handling was allowed to run at all is
                              // the thing worth being loudest about — it is the step
                              // that takes live events off the calendar.
                              //
                              // ONLY THE SKIP IS REPORTED NOW. The success branch said
                              // "Removal check ran on a complete result set, nothing
                              // had gone", which is an internal guard answering a
                              // question nobody asked. A guard that did its job is not
                              // news; a guard that could not run is.
                        if ( ! empty( $result['removal_skip'] ) ) : ?>
                            <div class="uc-fetch-guard">Removal check skipped. <?php echo esc_html( $result['removal_skip'] ); ?>. No event was unpublished by this source.</div>
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
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * What the UNATTENDED fetch did last time, on the screen it fills.
     *
     * THE GAP THIS CLOSES. Automated fetching runs every 15 minutes and the
     * only account of it is the Automation screen in wp-admin, which is a
     * different admin area from the one somebody reviewing imports is standing
     * in. A person working the Pending queue could not tell whether the queue
     * was empty because nothing had come in or because nothing had run for a
     * day.
     *
     * IT IS THE SCHEDULED RUN, NOT THE BUTTON. "Fetch updates" at the top of
     * this screen calls SFAF_Sources::run_all() straight and reports through
     * its own transient in render_fetch_report(); it never touches the run log.
     * So this box does not move when that button is pressed, and it says
     * "automatic" in as many words so that reads as correct rather than
     * broken.
     *
     * NO SECOND STORE. Every figure here comes from SFAF_Cron::task_last(),
     * which reads the same run log the Automation screen reads. Nothing about a
     * fetch is written down twice, and the two screens cannot come to disagree
     * about a run because there is only one record of it.
     *
     * ONE RUN, NOT A HISTORY. At 96 runs a day a list is a thing nobody reads
     * to the bottom of, and the bottom is where the old ones are. The question
     * this box answers is "is this working and what did it just do", which is
     * one entry, plus the one thing a single entry cannot say: how long it has
     * been since anything worked.
     */
    private function render_fetch_last_run() {
        // Nothing is built into this install to fetch FROM, so there is no such
        // thing as a fetch that should have happened. render_fetch_report()
        // covers the "built but not connected" case with what each one wants.
        if ( empty( SFAF_Sources::adapters() ) ) {
            return;
        }

        $fetch = SFAF_Cron::task_last( 'fetch' );
        if ( ! $fetch ) {
            return;
        }

        /*
         * STALE AFTER AN HOUR. The runner fires every 15 minutes, so four
         * chances have been missed by then and a hiccup has become a fault.
         * It is measured from the last run that did not FAIL, not the last that
         * ran: a fetch erroring every quarter hour is the case this line exists
         * to catch, and that one has a fresh 'last' and a stale 'last_ok'.
         */
        $stale_after = HOUR_IN_SECONDS;
        $stale       = $fetch['on'] && ( ! $fetch['last_ok'] || ( time() - (int) $fetch['last_ok'] ) > $stale_after );

        // A source that errored, by name. The whole task is only 'failed' when
        // EVERY active source errored, so asking the task alone would report a
        // fetch in which Eventbrite died and GoFundMe worked as a clean run.
        $failed = array();
        foreach ( (array) $fetch['sources'] as $s ) {
            if ( isset( $s['state'] ) && 'failed' === $s['state'] ) {
                $failed[] = isset( $s['label'] ) && '' !== $s['label'] ? (string) $s['label'] : 'A source';
            }
        }
        if ( empty( $failed ) && 'failed' === $fetch['status'] ) {
            $failed[] = 'The fetch';
        }
        ?>
        <div class="uc-card uc-card-lastrun">
            <div class="uc-card-head">
                <h2>Automatic fetching</h2>
                <span class="uc-lastrun-when"><?php
                    echo esc_html( $fetch['last'] ? 'Last run ' . SFAF_Cron::ago( $fetch['last'] ) : 'Not run yet' );
                ?></span>
            </div>

            <?php if ( ! $fetch['on'] ) : ?>
                <?php // Switched off is not a fault and must not be dressed as
                      // one. It is also the only state on this screen with
                      // something to do about it, so that is what it says. ?>
                <p class="uc-empty">Automatic fetching is switched off. Use &ldquo;Fetch updates&rdquo; above to run it by
                hand. It is switched on under <strong>Settings &rsaquo; Scheduled Tasks</strong> in the WordPress admin.</p>

            <?php else : ?>

                <?php if ( ! empty( $failed ) ) : ?>
                    <?php /* LOUDEST, AND FIRST. A failure listed fourth among
                             four sources is a failure nobody sees. It is named,
                             it is above the per-source lines, and it says the
                             word "failed" as well as being red, because colour
                             is never the only thing carrying a fact here. */ ?>
                    <p class="uc-lastrun-alarm"><strong><?php
                        echo esc_html( sprintf(
                            '%s failed on the last run.',
                            implode( ' and ', array_map( 'strval', $failed ) )
                        ) );
                    ?></strong> The line below says what came back. Events from a source that
                    failed were not touched, and nothing it would have sent is in the queue.</p>
                <?php endif; ?>

                <?php if ( $stale ) : ?>
                    <p class="uc-lastrun-alarm"><strong><?php
                        echo esc_html( $fetch['last_ok']
                            ? sprintf( 'Nothing has fetched successfully since %s.', SFAF_Cron::local_time( $fetch['last_ok'] ) )
                            : 'No fetch has ever completed.' );
                    ?></strong> A run is due every 15 minutes, so this queue may be missing events that
                    are already live at the source. The Automation screen in the WordPress admin says why.</p>
                <?php endif; ?>

                <?php if ( ! $fetch['last'] ) : ?>
                    <p class="uc-empty">Automatic fetching is switched on and has not run yet. The first run is due
                    within 15 minutes.</p>

                <?php elseif ( ! empty( $fetch['sources'] ) ) : ?>
                    <?php /* The source's own sentence, whole. summarize() is
                             already the words a manager would use and it draws
                             the distinction that matters most here: "nothing
                             new" and "returned nothing at all" are different
                             facts and neither reads as a failure. */ ?>
                    <ul class="uc-fetch-report">
                        <?php foreach ( $fetch['sources'] as $s ) :
                            $state = isset( $s['state'] ) ? (string) $s['state'] : 'ok';
                            $class = ( 'failed' === $state ) ? 'uc-fetch-fail' : ( ( 'skipped' === $state ) ? 'uc-fetch-skip' : 'uc-fetch-ok' );
                            ?>
                            <li class="<?php echo esc_attr( $class ); ?>">
                                <span class="uc-fetch-line"><?php echo esc_html( isset( $s['line'] ) ? $s['line'] : '' ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                <?php else : ?>
                    <?php /* A run recorded before 3.57.0, which stored the
                             joined sentence and no breakdown. It is shown as it
                             was stored rather than split back apart: an error
                             string may contain the separator, so splitting it
                             would invent sources that never ran. One run
                             replaces this. */ ?>
                    <p class="uc-lastrun-summary"><?php echo esc_html( $fetch['summary'] ); ?></p>
                <?php endif; ?>

                <p class="uc-lastrun-foot">Runs every 15 minutes on its own.
                    <?php if ( $fetch['last'] ) : ?>
                        Last run <?php echo esc_html( SFAF_Cron::local_time( $fetch['last'] ) ); ?>.
                    <?php endif; ?>
                    The full run log is on the Automation screen in the WordPress admin.</p>
            <?php endif; ?>
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

        <?php
        /*
         * THE ONE THING A SEARCH CHANGES (3.74.0).
         *
         * Everything a search can alter is inside this card: the count, the
         * bulk picker, the rows and the pagination. portal.js fetches the same
         * URL this form would have navigated to, lifts this element out of the
         * answer and swaps what is inside it, which is what stops the search
         * box being torn down and rebuilt under somebody's hands mid-word.
         *
         * SO THE MARKER IS ON THE CARD AND NOT ON THE TABLE. A search that
         * changes the count and leaves the heading saying the old number is a
         * worse answer than no search at all, and the pagination has to move
         * with the rows it pages.
         */
        ?>
        <div class="uc-card" data-uc-live-search-results>
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
                /*
                 * ASKED ONCE, HANDED TO BOTH (3.79.0). The panel's counts and
                 * the table's boxes have to be the same answer, so they read
                 * the same array rather than each running the loop.
                 */
                $bulk = $this->bulk_plan( $user, $ids );
                $this->render_bulk_actions( $bulk );
                $this->events_table( $ids, $user, $sort, $filters, $bulk );
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
                // Ordered, so this column and the event page name a co-hosted
                // event's organizers in the same order. A comma is right here:
                // this is a table column, not prose.
                $orgs  = SFAF_Organizers::names_for_event( $id );
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

    /**
     * THE TWO BULK ACTIONS ON THE EVENTS LIST, and why their reach differs.
     *
     * Add one category to every ticked event, and publish the ticked drafts.
     * One set of ticks feeds both, and the two do NOT reach the same rows. The
     * whole of that difference is set out here, because it is the thing a
     * future edit is most likely to flatten into one rule.
     *
     * WHY THE CATEGORY CONTROL EXISTS. WordPress's own bulk edit does this and
     * contributors and
     * editors never see wp-admin, so for most of the people who maintain this
     * calendar it is not available at all. Categorising a term's worth of
     * imported drafts one event at a time is not a thing anybody is going to
     * do.
     *
     * IT ADDS. It does not replace, and the control says so twice: on the
     * button and in the confirmation. wp_set_post_terms() with $append = true
     * is the whole of that guarantee, and categories have been multi-select
     * since 3.8.0 so there is nothing here fighting the data model.
     *
     * WHICH ROWS IT MAY REACH: EVERY ROW THE VIEWER CAN ALREADY EDIT, and
     * that is deliberately NOT the bulk publish's list of exclusions.
     *
     * Publishing is refused on a past date, an event with no date, an import,
     * an event that vanished at its source and a submission awaiting review.
     * Every one of those rules exists because publishing puts an event on the
     * PUBLIC CALENDAR, which is irreversible in the way that matters: somebody
     * may see it. Adding a category changes how an event is filed. It changes
     * no status, publishes nothing, and on an event that is not public it
     * reaches nobody at all.
     *
     * So each exclusion was asked about rather than copied:
     *
     *   . A PAST EVENT. Filing last March's workshop under Workshops is
     *     useful and harmless. Allowed.
     *   . A SUBMISSION AWAITING REVIEW. The reviewer wants it categorised
     *     BEFORE approving, which is the moment it goes public. Refusing here
     *     would mean doing it one at a time on the queue instead. Allowed.
     *   . AN IMPORTED EVENT. Category is a manager-owned field on every
     *     adapter: no source declares it, the manager panel offers it, and a
     *     fetch never overwrites it. This is the same write that panel makes,
     *     in bulk. Allowed, and the $offered guarantee is untouched.
     *   . THE QUEUES. `uc_imported` and `uc_dismissed` are not in
     *     editable_statuses(), so they have never been in $ids and need no
     *     rule. Not excluded, because they cannot arrive.
     *
     * WHAT IS ENFORCED IS PERMISSION, and it is enforced at the write rather
     * than by drawing fewer boxes: can_edit_event() for every id, which is the
     * same gate every other route asks. A contributor sees only their own
     * events here, and a posted id naming somebody else's is dropped.
     *
     * NOTHING RENDERS WITH NO CATEGORIES TO CHOOSE, because a picker over an
     * empty list is a control that cannot do anything.
     *
     * ---------------------------------------------------------------------
     * AND THE PUBLISH HALF (3.79.0), WHOSE RULES ARE THE OPPOSITE.
     *
     * WHY IT IS HERE AND NOT ONLY ON THE SCHEDULE SCREEN. 3.71.0 put bulk
     * publish on a series' schedule, which works one series at a time. There
     * are 287 drafts across 32 series, and 32 visits to 32 screens is the
     * thing the button was built to stop happening.
     *
     * THE FIVE SKIP RULES ARE UNCHANGED AND ARE NOT RE-STATED HERE. They are
     * SFAF_Series::publish_skip_reason(), called by bulk_plan() above and by
     * the schedule screen's publishable(), and the method is written per event
     * with the date passed in, so it was already general. A past date, an event
     * with no date, a submission awaiting review, anything with source
     * provenance and an import parked because it vanished at its source are
     * each refused, and this screen weakens none of them.
     *
     * THE TICK STAYS ON EVERY EDITABLE ROW, INCLUDING ROWS THAT CANNOT BE
     * PUBLISHED, and that is the one place this departs from the schedule
     * screen's "an ineligible row gets no tick". It has to. The tick is shared:
     * the category control reaches every row the viewer can edit, past events
     * and imports and submissions included, and taking the box off a past
     * import to protect the publish button would take the category control's
     * reach away with it. One selection mechanism was the requirement, and two
     * actions with different reach is what that costs.
     *
     * SO THE ROW SAYS WHAT IT CANNOT DO, rather than the box being absent. A
     * draft that cannot be published carries the reason beside its tick, which
     * is the same information the schedule screen puts where the box would
     * have been. Published rows say nothing: "not a draft" on a page of
     * published events is the page restating itself. See bulk_plan().
     *
     * AND THE COUNTS ARE PER BUTTON. The category button counts every tick;
     * the publish button counts only the ticks it could act on. A button whose
     * number includes rows it is about to skip is the failure mode this whole
     * arrangement exists to avoid, and it is the reason portal.js had to learn
     * about a second submit rather than a second form.
     *
     * NOTHING IS TRUSTED FROM THE FORM EITHER WAY. The handler re-asks
     * can_edit_event() per id, and re-asks publish_skip_reason() per id against
     * its own $today, so a hand-built POST naming a past date publishes
     * nothing. The ticks narrow; they never widen.
     */
    /**
     * Which rows on this page a tick may reach, and what each tick may then do.
     *
     * ONE PASS, ASKED ONCE, READ BY THE PANEL AND BY THE TABLE (3.79.0). The
     * panel has to say how many rows can be published before anybody ticks
     * anything, and the table has to draw a tick on the same rows the panel
     * counted. Two loops asking the same two questions is two answers waiting
     * to drift apart, and the drift would show up as a button whose number does
     * not match the boxes under it.
     *
     * `tick` IS can_edit_event() AND NOTHING ELSE. That is the bulk category
     * control's reach as 3.73.0 defined it and it does not narrow here: filing
     * a past event, an import or a submission under a category is allowed and
     * harmless, and the reasons are set out above render_bulk_actions().
     *
     * `publish_why` IS SFAF_Series::publish_skip_reason(), CALLED, NOT COPIED.
     * That method is the schedule screen's rule and it is written per event
     * with the date passed in: it asks nothing about a series and takes no
     * term id, so it is already the general rule and this is simply its second
     * caller. A second copy here is how the two screens would come to disagree
     * about what may go on the public calendar, and that is the one thing on
     * these screens that must never be decided twice.
     *
     * ONE `$today` FOR THE WHOLE PAGE, for the same reason publishable() takes
     * one: a run that straddles midnight must not judge row 1 against
     * yesterday and row 40 against today.
     *
     * @param WP_User $user
     * @param int[]   $ids Rows on this page.
     * @return array{tick:int[],publish:int[],blocked:array<int,string>,skipped:array<string,int>}
     */
    private function bulk_plan( $user, $ids ) {
        $out = array( 'tick' => array(), 'publish' => array(), 'blocked' => array(), 'skipped' => array() );
        if ( empty( $ids ) ) {
            return $out;
        }

        /*
         * PRIMED HERE BECAUSE THIS NOW RUNS FIRST. events_table() has always
         * primed these caches and it is called after this, so without this line
         * the loop below is 25 get_post() queries and a meta query per event
         * before the table gets to do it properly. publish_skip_reason() reads
         * a status, three meta keys and provenance for every row.
         *
         * The second call in events_table() then costs nothing, which is why it
         * stays: that method is borrowed by screens that never come through
         * here and has to keep priming for itself.
         */
        _prime_post_caches( $ids, true, true );

        $today = current_time( 'Y-m-d' );

        foreach ( $ids as $id ) {
            $id   = (int) $id;
            $post = get_post( $id );
            if ( ! $post || 'uc_event' !== $post->post_type || ! $this->can_edit_event( $user, $post ) ) {
                continue;
            }
            $out['tick'][] = $id;

            $why = SFAF_Series::publish_skip_reason( $id, $today );
            if ( '' === $why ) {
                $out['publish'][] = $id;
                continue;
            }
            $out['blocked'][ $id ] = $why;

            /*
             * "NOT A DRAFT" IS NOT COUNTED AS SOMETHING LEFT OUT, and this is
             * the one place this screen's arithmetic differs from the schedule
             * screen's. That screen lists one series' dates, where a published
             * row genuinely is a date the button is passing over. This list is
             * every event on the calendar, filtered and paged, and on the
             * default view most rows are published. "Not included: 22 not a
             * draft" over a page of published events is not information; it is
             * the page restating itself, and it would bury the four counts
             * that are information.
             */
            if ( 'not a draft' === $why ) {
                continue;
            }
            $out['skipped'][ $why ] = ( isset( $out['skipped'][ $why ] ) ? $out['skipped'][ $why ] : 0 ) + 1;
        }

        return $out;
    }

    /**
     * The bulk panel above the events table. See the block above bulk_plan()
     * for what each button may reach and why the two differ.
     *
     * IT TAKES NO $user. Every permission question was asked in bulk_plan(),
     * and a renderer that could ask one again is a renderer that could answer
     * it differently from the table beside it.
     *
     * @param array $plan From bulk_plan().
     */
    private function render_bulk_actions( $plan ) {
        if ( empty( $plan['tick'] ) ) {
            return;
        }

        $cats  = SFAF_Categories::all();
        $ready = count( $plan['publish'] );

        /*
         * NOTHING TO CHOOSE AND NOTHING TO PUBLISH IS AN EMPTY PANEL, so there
         * is no panel. A form holding two hidden fields and a nonce is a
         * hairline rule above a table and nothing else.
         */
        if ( empty( $cats ) && $ready < 1 ) {
            return;
        }

        /*
         * WHAT IS BEING LEFT OUT, NAMED ON THE SCREEN AND AGAIN IN THE
         * CONFIRMATION, in the schedule screen's own words because they come
         * from the same method. "Publish 4" over a page of 25 invites the
         * question this line answers.
         */
        $left = array();
        foreach ( $plan['skipped'] as $why => $n ) {
            $left[] = $n . ' ' . $why;
        }
        $left_said = $left ? ' Nothing else is touched: ' . implode( ', ', $left ) . '.' : '';
        ?>
        <form method="post" action="<?php echo esc_url( $this->url( 'events' ) ); ?>"
              class="uc-bulk-cat" id="uc-bulk-cat" data-uc-tick-picker>
            <?php
            /*
             * ONE FORM, ONE SET OF TICKS, TWO VERBS (3.79.0).
             *
             * A checkbox associates with exactly ONE form, so a second bulk
             * action cannot have a second form without a second column of
             * boxes, and two columns of boxes on one table is the worst answer
             * available: it doubles the width of the thing somebody's eye runs
             * down and it makes "select all" ambiguous.
             *
             * So `uc_action` names the FORM and `uc_do` names the BUTTON. The
             * dispatcher verifies uc_portal_bulk_events once, before the
             * switch, and the case below reads uc_do to decide which of the two
             * routes runs. An absent or unrecognised uc_do categorises, which
             * is what pressing Enter in the category select means and is the
             * route that publishes nothing.
             */
            ?>
            <input type="hidden" name="uc_action" value="bulk_events" />
            <?php
            /*
             * THE MARKER, so an empty list of ticks means "none of them"
             * rather than "this form did not ask". Same discipline as every
             * other shared control on these screens.
             */
            ?>
            <input type="hidden" name="uc_bulk_present" value="1" />
            <?php wp_nonce_field( 'uc_portal_bulk_events', 'uc_nonce' ); ?>

            <?php if ( ! empty( $cats ) ) : ?>
                <label class="uc-field uc-bulk-cat-pick">
                    <span class="uc-field-label">Add a category to the ticked events</span>
                    <select name="bulk_category" required>
                        <option value="">Choose a category</option>
                        <?php foreach ( $cats as $term ) : ?>
                            <option value="<?php echo (int) $term->term_id; ?>"><?php echo esc_html( $term->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="uc-bulk-cat-go">
                    <?php
                    /*
                     * THE COUNT STARTS AT 0 AND THE BUTTON IS THE COUNT.
                     * Nothing is ticked on arrival, so a button reading "Add to
                     * 25 events" is wrong the moment it is drawn, and it is
                     * wrong in the direction that matters: it names a set
                     * larger than the one that would be acted on. The Images
                     * screen starts at 0 for the same reason.
                     *
                     * NOT DISABLED BY THE SERVER. With no script the boxes
                     * still tick and the form still posts, and a button the
                     * server disabled would never come back. portal.js disables
                     * it at 0 and releases it on the first tick; with no script
                     * an empty press is caught by the marker and answered with
                     * a sentence.
                     */
                    ?>
                    <button type="submit" class="uc-btn uc-btn-sm uc-btn-primary"
                            name="uc_do" value="categorize"
                            data-uc-tick-submit
                            data-uc-confirm="Add that category to the ticked events? They keep the categories they already have."
                            data-uc-tick-confirm="Add that category to {n} {noun}? They keep the categories they already have."
                            data-uc-tick-word="events"
                            data-uc-tick-word-one="event">
                        Add to <span data-uc-tick-count>0</span>
                        <span data-uc-tick-noun>events</span>
                    </button>
                    <?php
                    /*
                     * SAID ON THE SCREEN AND NOT ONLY IN THE CONFIRMATION. The
                     * one thing somebody needs to know before ticking 40 boxes is
                     * that this is not a replace, and a sentence they meet only
                     * after pressing is a sentence they meet too late.
                     */
                    ?>
                    <span class="uc-hint">Adds it. Nothing already on an event is removed or changed.</span>
                </div>
            <?php endif; ?>

            <?php
            /*
             * PUBLISH RENDERS ONLY WHEN THERE IS SOMETHING IT COULD PUBLISH.
             * A page of published events, or a page of imports, gets no publish
             * button at all: the absence is the answer, exactly as on the
             * schedule screen, and a greyed button that can never come alive on
             * this page would be a control that cannot do anything.
             */
            ?>
            <?php if ( $ready > 0 ) : ?>
                <div class="uc-bulk-cat-go uc-bulk-publish">
                    <?php
                    /*
                     * formnovalidate, AND IT IS LOAD-BEARING. The category
                     * select is `required`, which is right for the button
                     * beside it and would otherwise block this one: publishing
                     * has nothing to do with a category, and a browser refusing
                     * to submit until one is chosen would be the form enforcing
                     * a rule nobody wrote.
                     */
                    ?>
                    <button type="submit" class="uc-btn uc-btn-sm uc-btn-primary"
                            name="uc_do" value="publish" formnovalidate
                            data-uc-tick-submit data-uc-tick-eligible
                            data-uc-confirm="<?php echo esc_attr(
                                'Publish the ticked events? They go on the public calendar straight away.' . $left_said
                            ); ?>"
                            data-uc-tick-confirm="<?php echo esc_attr(
                                'Publish {n} {noun}? They go on the public calendar straight away.' . $left_said
                            ); ?>"
                            data-uc-tick-word="drafts"
                            data-uc-tick-word-one="draft">
                        Publish <span data-uc-tick-count>0</span>
                        <span data-uc-tick-noun>drafts</span>
                    </button>
                    <span class="uc-hint">
                        <?php echo (int) $ready; ?>
                        <?php echo esc_html( _n( 'draft on this page can be published', 'drafts on this page can be published', $ready ) ); ?>.
                        <?php if ( $left ) : ?>
                            Not included: <?php echo esc_html( implode( ', ', $left ) ); ?>.
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </form>
        <?php
    }

    private function events_table( $ids, $user, $sort = null, $filters = null, $bulk = null ) {
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

        /*
         * THE TICK COLUMN NEEDS A PLAN, NOT JUST A SORT (3.79.0).
         *
         * $plain answered "is this the Events list", which is the question the
         * HEADER asked while no row ever drew a cell. Now that both ends are
         * here they have to ask ONE question, and it is a stronger one: is
         * there a bulk form on this page, and does it have at least one row it
         * may reach. A column of boxes over rows the viewer cannot edit is a
         * control that cannot do anything, and a header cell with no body cell
         * under it is what this release is fixing.
         */
        $ticks = ( ! $plain && is_array( $bulk ) && ! empty( $bulk['tick'] ) )
            ? array_flip( $bulk['tick'] )
            : null;
        $tick_why = ( null !== $ticks && isset( $bulk['blocked'] ) ) ? $bulk['blocked'] : array();
        ?>
        <table class="uc-table">
            <thead><tr>
                <?php
                /*
                 * THE TICK COLUMN IS ON THE EVENTS LIST ONLY (3.73.0).
                 *
                 * Every other screen that borrows this table passes no plan,
                 * and none of them carries the bulk form the boxes would post
                 * to. A checkbox associated with a form that is not on the page
                 * is a control that cannot do anything.
                 *
                 * SELECT-ALL IS HIDDEN UNTIL THE SCRIPT REVEALS IT, because a
                 * box that cannot select anything is a control that lies. Same
                 * reason the image picker keeps its search box hidden.
                 */
                ?>
                <?php if ( null !== $ticks ) : ?>
                    <th class="uc-col-tick">
                        <label class="uc-tick-all" hidden data-uc-tick-all-row>
                            <input type="checkbox" data-uc-tick-all form="uc-bulk-cat" />
                            <span class="uc-visually-hidden">Select every event on this page</span>
                        </label>
                    </th>
                <?php endif; ?>
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
                    <?php if ( null !== $ticks ) : ?>
                        <td class="uc-col-tick">
                            <?php
                            /*
                             * ASSOCIATED BY THE form ATTRIBUTE, NOT BY BEING
                             * INSIDE ONE. These rows already contain their own
                             * forms for Duplicate and Remove, and forms cannot
                             * nest. form.elements is what portal.js reads,
                             * which is exactly what the association is for.
                             *
                             * THIS CELL IS DRAWN IN EVERY ROW, TICK OR NO TICK,
                             * and that is not decoration. A row the viewer
                             * cannot edit gets an empty cell rather than no
                             * cell, because a table whose rows have different
                             * numbers of cells is the fault this release
                             * exists to fix: 3.73.0 shipped a header cell with
                             * nothing under it for six releases.
                             */
                            $row_tick  = isset( $ticks[ $id ] );
                            $row_why   = isset( $tick_why[ $id ] ) ? (string) $tick_why[ $id ] : '';
                            /*
                             * THE REASON IS SHOWN ON DRAFTS ONLY. On every
                             * other row "not a draft" is the status pill two
                             * columns over, said again in smaller type.
                             */
                            $row_say   = ( 'draft' === $st && '' !== $row_why ) ? $row_why : '';
                            ?>
                            <?php if ( $row_tick ) : ?>
                                <input type="checkbox" name="bulk_ids[]" value="<?php echo (int) $id; ?>"
                                       form="uc-bulk-cat" data-uc-tick-one
                                       <?php if ( '' !== $row_why ) : ?>
                                           data-uc-tick-block="<?php echo esc_attr( $row_why ); ?>"
                                       <?php endif; ?>
                                       aria-label="<?php echo esc_attr(
                                           'Select ' . ( get_the_title( $id ) ?: 'this event' )
                                           . ( '' !== $row_say ? '. Cannot be published: ' . $row_say : '' )
                                       ); ?>" />
                                <?php if ( '' !== $row_say ) : ?>
                                    <span class="uc-tick-block" aria-hidden="true"
                                          title="<?php echo esc_attr( 'This draft is not one the bulk publish may touch: ' . $row_say . '.' ); ?>"><?php
                                        echo esc_html( $row_say );
                                    ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><a class="uc-tlink" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></a></td>
                    <td><?php echo $date ? esc_html( sfaf_ap_date( $date, 'short_year' ) ) : '<span class="uc-muted">None</span>'; ?></td>
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
                        <?php
                        /*
                         * PRIVATE EVENTS ARE IN THIS LIST LIKE ANY OTHER, AND
                         * SAY SO. Hiding them from managers would hide them
                         * from the people running them, which is the one group
                         * that has to be able to find them.
                         *
                         * THE WORDS ARE THE MARKER. Not a padlock on its own and
                         * not "Unlisted": somebody who did not build this has to
                         * read the row and know what it means, and "Private" is
                         * the word the editor's own control uses. The title
                         * attribute carries the rest for anybody who hovers.
                         */
                        if ( SFAF_Privacy::is_private( $id ) ) : ?>
                            <span class="uc-pill uc-pill-private"
                                  title="Hidden from the calendar, search, its series page and the sitemap. Reachable only by its direct link.">Private</span>
                        <?php endif; ?>

                        <?php
                        /*
                         * CANCELLED IS ITS OWN PILL, BESIDE THE STATUS AND NOT
                         * INSTEAD OF IT. A cancelled event is still published or
                         * still a draft, and the row has to say both: "Published,
                         * Cancelled" is the truth, and replacing the status would
                         * hide whether it is on the public calendar, which is the
                         * next thing somebody wants to know.
                         */
                        if ( SFAF_Cancellation::is_cancelled( $id ) ) : ?>
                            <span class="uc-pill uc-pill-cancelled"
                                  title="<?php echo esc_attr( SFAF_Cancellation::label( $id ) . '. Takes no new registrations, and no reminders go out for it.' ); ?>">Cancelled</span>
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
                        <?php
                        /*
                         * ICONS ON ONE LINE, FROM 3.73.0.
                         *
                         * WHAT WAS HERE. Edit, Duplicate and Remove as three
                         * text links, in two similar colours, at one weight,
                         * across 259 rows. The common action did not stand out,
                         * the destructive one did not either, and the column was
                         * wide enough that some rows wrapped onto two lines and
                         * some did not, so the edge of the table looked broken.
                         *
                         * THE ICONS COME FROM sfaf_icon(), which is the one
                         * source. 'duplicate' was added for this and follows the
                         * same spec as the rest of the set.
                         *
                         * EVERY ONE CARRIES BOTH A title AND A CLIPPED LABEL,
                         * and that pairing is the whole accessibility answer:
                         *
                         *   title            the hover text, for a mouse.
                         *   .uc-visually-hidden  the accessible name, for a
                         *                    screen reader and for the keyboard
                         *                    focus ring's announcement.
                         *
                         * SHAPE IS NEVER THE ONLY CARRIER. A pencil, two panels
                         * and a cross are three shapes AND three names, and the
                         * red on Remove is a third signal rather than the first.
                         *
                         * ON A TOUCH DEVICE THERE IS NO HOVER, and that is not
                         * left to chance: the clipped label is what a screen
                         * reader reads out on a phone, and the icons are drawn
                         * at a 44px target with visible spacing between them, so
                         * the failure mode of a mis-tap is a miss rather than
                         * the wrong action. Anybody who cannot tell them apart
                         * has Edit one tap away, which is the same screen every
                         * one of these rows already links to from its title.
                         */
                        ?>
                        <div class="uc-actions uc-actions-icons">
                            <a class="uc-icon-action" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"
                               title="Edit this event">
                                <?php echo sfaf_icon( 'pencil', array( 'size' => '17px' ) ); ?>
                                <span class="uc-visually-hidden">Edit this event</span>
                            </a>
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
                                <button type="submit" class="uc-icon-action"
                                        title="Duplicate. Makes a new draft with no date and no registrations. This event is not changed.">
                                    <?php echo sfaf_icon( 'duplicate', array( 'size' => '17px' ) ); ?>
                                    <span class="uc-visually-hidden">Duplicate this event</span>
                                </button>
                            </form>
                            <?php
                            /*
                             * REMOVE, AND WHAT IT ACTUALLY DOES (3.72.0).
                             *
                             * THE OLD SENTENCE WAS WRONG IN BOTH HALVES. It
                             * read "Remove this event? Nothing else changes and
                             * nothing brings it back." The action is
                             * wp_trash_post(), which is what every remove
                             * control in this portal does and is one click to
                             * undo from the WordPress trash, so "nothing brings
                             * it back" promised a permanence the code has never
                             * had. And "nothing else changes" is only true of
                             * the removals that go through: trash_event refuses
                             * outright on a live event with registrations.
                             *
                             * THE REFUSAL WAS ALREADY THERE AND THE ROW DID NOT
                             * KNOW. The handler has redirected such an event to
                             * the editor with delete_needs_cancel since 3.36.0,
                             * and that message names cancelling and links to it.
                             * What was missing is that this row offered the
                             * button anyway, so the flow was: confirm a deletion,
                             * arrive somewhere else, read that it did not happen.
                             *
                             * THE TEST COSTS NOTHING. sfaf_get_rsvp_count() and
                             * SFAF_Announce::has_registrations() count the same
                             * predicate, status = 'confirmed', and the count is
                             * already read a few cells to the left of here and
                             * memoized for the request. The server still asks
                             * its own question at the write, because a POST is a
                             * request anybody can construct.
                             */
                            $blocked = ( $rsvp_n > 0 && ! SFAF_Cancellation::is_cancelled( $id ) );

                            /*
                             * AND WHETHER CANCELLING IS EVEN OFFERED HERE.
                             *
                             * render_cancel_card() returns early on an imported
                             * event, because a platform owns it and cancelling
                             * is done at the source: cancel_event refuses one
                             * outright. So a Cancel link on such a row would
                             * land on a page with no cancel card on it, which
                             * is the fault this release is fixing, rebuilt one
                             * row along.
                             *
                             * THE THIRD CASE IS A STATE AND NOT A CONTROL. An
                             * imported event with registrations can be neither
                             * removed nor cancelled from here, so what the row
                             * shows is a padlock saying so, rather than a
                             * control that would be refused.
                             */
                            $row_imported = ( '' !== (string) get_post_meta( $id, SFAF_Sources::META_SOURCE, true ) );
                            ?>
                            <?php if ( $blocked && $row_imported ) : ?>
                                <span class="uc-icon-action uc-icon-action-locked"
                                      title="<?php echo esc_attr(
                                          $rsvp_n . ( 1 === $rsvp_n ? ' person is' : ' people are' )
                                          . ' registered, and this event came from ' . $row_prov['label']
                                          . '. It is cancelled or removed there, not here.'
                                      ); ?>">
                                    <?php echo sfaf_icon( 'lock', array( 'size' => '17px' ) ); ?>
                                    <span class="uc-visually-hidden">Registered people, and owned by <?php echo esc_html( $row_prov['label'] ); ?>. Removed at its source.</span>
                                </span>
                            <?php elseif ( $blocked ) : ?>
                                <?php
                                /*
                                 * CANCEL, AND IT LANDS ON THE CANCEL CARD
                                 * (3.73.0).
                                 *
                                 * IT USED TO SAY "Cancel instead" AND GO TO THE
                                 * EDITOR. Both halves were wrong. "Instead"
                                 * pointed at a Remove link the reader never saw,
                                 * because on this row it had been replaced by
                                 * this one; and the editor asked which
                                 * occurrences an EDIT should touch and then
                                 * opened a form, so somebody who pressed
                                 * something about cancelling got a question
                                 * about editing and landed on the wrong screen.
                                 *
                                 * `cancel=1` OPENS THE CANCEL CARD, which is
                                 * rendered outside the event form and is its own
                                 * control. See render_cancel_card().
                                 *
                                 * AND `edit_scope=this` COMES WITH IT, which is
                                 * how the scope question is skipped. It is not
                                 * suppressed, it is ANSWERED, and answered
                                 * truthfully: cancel_event takes one event id
                                 * and acts on one event, so "this event" is what
                                 * a cancellation from here is. A modal asking
                                 * which occurrences to edit, in front of
                                 * somebody who came to cancel one date, is a
                                 * question about a different operation.
                                 *
                                 * A DIFFERENT ACTION, NOT A VARIANT OF REMOVE.
                                 * It takes the 'bell' glyph with a slash rather
                                 * than a second cross: two crosses side by side
                                 * on the same row would read as two ways of
                                 * doing the same thing, and cancelling is the
                                 * opposite of removing. It keeps its own colour,
                                 * which is the ordinary action ink rather than
                                 * the destructive red, because cancelling is
                                 * reversible and removing is the one that is not
                                 * offered here at all.
                                 */
                                ?>
                                <a class="uc-icon-action uc-icon-action-cancel"
                                   href="<?php echo esc_url( add_query_arg(
                                       array( 'cancel' => 1, 'edit_scope' => 'this' ),
                                       $this->url( 'events/edit/' . $id )
                                   ) . '#uc-cancel-this' ); ?>"
                                   title="<?php echo esc_attr(
                                       'Cancel this event. '
                                       . $rsvp_n . ( 1 === $rsvp_n ? ' person is' : ' people are' )
                                       . ' registered, so it cannot be removed: cancelling keeps the registrations'
                                       . ' and offers to tell everybody who signed up.'
                                   ); ?>">
                                    <?php echo sfaf_icon( 'bell-off', array( 'size' => '17px' ) ); ?>
                                    <span class="uc-visually-hidden">Cancel this event</span>
                                </a>
                            <?php else : ?>
                                <form method="post" action="<?php echo esc_url( $this->url( 'events' ) ); ?>">
                                    <input type="hidden" name="uc_action" value="trash_event" />
                                    <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                                    <?php wp_nonce_field( 'uc_portal_trash_event', 'uc_nonce' ); ?>
                                    <?php // The styled dialog every other destructive control here
                                          // uses, rather than a browser box that cannot say which
                                          // row it belongs to. See ucConfirm(). ?>
                                    <button type="submit" class="uc-icon-action uc-icon-action-danger"
                                            title="Remove this event. It goes to the WordPress trash and can be restored."
                                            data-uc-confirm="Remove &ldquo;<?php echo esc_attr( get_the_title( $id ) ?: 'this event' ); ?>&rdquo;? It goes to the WordPress trash, where it can be restored until the trash is emptied. Nothing else changes.">
                                        <?php echo sfaf_icon( 'x', array( 'size' => '17px' ) ); ?>
                                        <span class="uc-visually-hidden">Remove this event</span>
                                    </button>
                                </form>
                            <?php endif; ?>
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
    /**
     * The description, as rich text (3.38.0).
     *
     * A MINIMAL TOOLBAR, AND THE OMISSIONS ARE THE POINT. Bold, italic, a link,
     * two kinds of list and one heading. No font colours, no sizes, no
     * alignment: the brand guide governs colour and type, and a full toolbar is
     * how a calendar ends up with events in purple Comic Sans that nobody can
     * unpick afterwards because the styling is inline on every paragraph.
     *
     * ONE HEADING, AND IT IS h3. The event page's own title is the h1 and the
     * page's sections sit at h2, so a heading somebody types into a description
     * has to start below both or it breaks the reading order for anybody
     * navigating by headings. block_formats offers exactly Paragraph and that
     * one level, so there is no way to choose a level that would compete.
     *
     * IT DEGRADES TO A TEXTAREA. caladmin builds its own document rather than
     * running through wp_head, so TinyMCE is being asked to start somewhere it
     * usually does not. If its scripts do not run, wp_editor() leaves a plain
     * textarea holding the same content: somebody sees tags instead of
     * formatting, which is worse than the editor working and much better than
     * losing anything. wp_kses_post() on save means the stored value survives
     * either way. quicktags is off so the fallback is one control rather than
     * two disagreeing about the same field.
     *
     * EXISTING PLAIN TEXT CARRIES OVER AS PARAGRAPHS. wp_editor() runs the
     * stored value through wpautop() for display, and the event page's
     * the_content() does the same, so a description written before this release
     * reads as the paragraphs it always looked like rather than collapsing into
     * one block. Nothing is migrated and nothing needs to be.
     *
     * @param array  $ctx
     * @param string $state
     */
    /**
     * The series picker, at the top of a new event, with its prefill offer.
     *
     * NOTHING POSTS. The whole control is a select, a panel of checkboxes and a
     * button that writes values into the fields already on this page. It is the
     * FAQ set picker's shape, and for the FAQ set picker's reason: 3.3.0 found
     * that a control which applies by posting and redirecting discards every
     * unsaved edit on the form, so people learn not to press it. A creation
     * form is usually empty, which is exactly when that failure is invisible in
     * testing and expensive in use.
     *
     * IT ASKS BEFORE OVERWRITING. Putting the picker first mostly means there
     * is nothing to overwrite, but "mostly" is not a guarantee: somebody may
     * type a title and a location and then think of the series. The script
     * checks every field a ticked box would write and names the ones that
     * already have something in them before touching any of them.
     *
     * VALUES ARE COPIED, NOT LINKED. Editing the series afterwards does not
     * reach the event, which is how the default FAQ set has always behaved.
     *
     * THE DATE IS NOT OFFERED, and is not in the payload at all, so there is no
     * checkbox to tick and nothing for a later change to expose. Setting the
     * date is the reason somebody is here.
     *
     * THE SELECT IS AT THE TOP ON BOTH, THE OFFER IS ONLY ON A NEW EVENT
     * (3.72.0).
     *
     * The card was rendered on creation only, and an edit carried a second
     * copy of the same select two thirds of the way down, inside "When and
     * where". Two screens asking one question in two places is how a manager
     * learns where a field is on one screen and cannot find it on the other,
     * and "which series is this one of" is the question that decides what the
     * event looks like, so it belongs where the eye starts on both.
     *
     * WHAT DOES NOT MOVE IS THE PREFILL. Filling an event in from its series is
     * a convenience for a blank form; arriving on an event with real content
     * and being offered a panel that writes over it is not a convenience, it is
     * a hazard, and the 3.3.0 reasoning about a control that discards unsaved
     * work applies twice as hard to one that discards saved work. So an edit
     * gets the select and no panel.
     *
     * THE PICTURE IS NOT COPIED ON AN EDIT AND DOES NOT NEED TO BE. An event
     * with no picture of its own already shows its series' one, through
     * sfaf_event_image_url(), and the image control says so in as many words:
     * sfaf_event_image_source() returns 'series' and the field is tagged "From
     * series". That is the never-overwrite rule holding by construction rather
     * than by care, and it is better than copying because nothing goes stale
     * when the series photo changes.
     *
     * @param WP_Term[] $all_series
     * @param int       $cur_series
     * @param int       $event_id 0 on a new event, which is what decides
     *                            whether the prefill offer is drawn at all.
     */
    private function render_series_prefill( $all_series, $cur_series, $event_id = 0 ) {
        if ( empty( $all_series ) ) {
            // No series, no choice to make, and no card saying so.
            return;
        }
        $offer = ! (int) $event_id;

        /* Built only where it is offered. The payload is every series' whole
         * prefill set, which is not small, and an edit has nothing to do with
         * it. */
        $payload = array();
        if ( $offer ) {
            foreach ( $all_series as $term ) {
                $payload[ (string) $term->term_id ] = SFAF_Series::prefill_data( $term->term_id );
            }
        }
        ?>
        <section class="uc-bento-card uc-series-first"<?php echo $offer ? ' data-uc-series-prefill' : ''; ?>>
            <h2 class="uc-bento-title">Is this part of a series?
                <?php
                /* WHAT BEING IN ONE DOES, rather than what a series is. Both
                 * are things that will happen to this event and neither is
                 * visible from this card. */
                echo sfaf_help(
                    'uc-help-series-' . (int) $event_id,
                    'An event with no picture of its own shows its series picture. Repeating dates are added and removed on the series schedule screen, not here.',
                    'series'
                );
                ?>
            </h2>

            <label class="uc-field">
                <span class="uc-field-label">Series</span>
                <select name="series" data-uc-series-select>
                    <option value="0">Not part of a series</option>
                    <?php foreach ( $all_series as $term ) : ?>
                        <option value="<?php echo (int) $term->term_id; ?>" <?php selected( $cur_series, $term->term_id ); ?>>
                            <?php echo esc_html( $term->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php if ( ! $offer ) : ?>
                <?php
                /*
                 * ON AN EDIT, WHAT THE SELECT ACTUALLY DOES, SAID ONCE.
                 * Moving an event between series is a real change with a
                 * consequence somebody would otherwise find on the event page,
                 * and it is the consequence the picture rule turns on.
                 */
                ?>
                <p class="uc-hint">
                    Moving it changes which series it is listed under. Nothing else about the event changes.
                    An event with no picture of its own shows its series' picture.
                </p>
            <?php else : ?>
            <?php
            /*
             * Rendered hidden and revealed by the script that can actually
             * apply it. Without the script the select still works and still
             * saves the series, which is the behaviour this screen had before.
             */
            ?>
            <div class="uc-prefill" data-uc-prefill-panel hidden>
                <p class="uc-prefill-head">
                    Fill this event in from <strong data-uc-prefill-name></strong>?
                </p>
                <div class="uc-prefill-opts" data-uc-prefill-opts></div>
                <div class="uc-prefill-actions">
                    <button type="button" class="uc-btn uc-btn-sm uc-btn-primary" data-uc-prefill-apply>Fill these in</button>
                    <button type="button" class="uc-btn uc-btn-sm" data-uc-prefill-none>Start from scratch</button>
                </div>
                <p class="uc-flash uc-prefill-said" data-uc-prefill-said role="status" hidden></p>
                <p class="uc-hint">
                    The date is never filled in.
                </p>
            </div>

            <script type="application/json" data-uc-prefill-data><?php
                echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
            ?></script>
            <?php endif; ?>
        </section>
        <?php
    }

    private function description_editor( $ctx, $state ) {
        // The toolbar, the fallback and the locked case all live in
        // SFAF_Rich_Text now. This screen chooses the field and nothing else.
        SFAF_Rich_Text::render(
            'uc-description',
            'description',
            $ctx['post'] ? $ctx['post']->post_content : '',
            array( 'rows' => 10, 'locked' => ( 'locked' === $state ) )
        );
    }

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
             * NOT class="uc-field", and the reason changed in 3.23.0 without
             * the answer changing. It used to be that .uc-field was one of the
             * wrappers the edit-scope script hung a pencil on, so labelling
             * this control with it would have put two pencils inside the FAQ
             * block, one for the dropdown and one for the questions, each
             * unlocking half of it. The pencils are gone. What remains is the
             * plain reason: .uc-field is the label-and-control layout for a
             * field on the event, and this is a picker that writes rows into
             * the block below it rather than a value onto the event.
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
                <?php
                /*
                 * "MANAGE SETS" LIVES HERE FROM 3.72.0.
                 *
                 * It was in the head of the card above the form, which is where
                 * it was when that card was the control. The control is this
                 * one, and the moment somebody wants the management screen is
                 * the moment they have opened this list and not found the set
                 * they wanted, so the link belongs at the end of this row.
                 *
                 * A LINK, NOT A BUTTON, because it navigates. Leaving the
                 * editor from here loses unsaved work like any other link on
                 * this page, and nothing in caladmin warns about that: see
                 * PROJECT.md 7. It is not made more prominent for that reason.
                 */
                ?>
                <a class="uc-action-link uc-faq-manage" href="<?php echo esc_url( $this->url( 'faq-sets' ) ); ?>">Manage sets</a>
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
                    <?php
                    /*
                     * THE LOCKED ANSWER GOES THROUGH THE LOCKED RENDERER NOW
                     * (3.73.0).
                     *
                     * WHAT WAS ON SCREEN. Raw markup, as literal text: an
                     * imported answer is stored as HTML, because
                     * SFAF_Sources::sync_faqs() sanitises it with
                     * SFAF_Rich_Text::sanitize() and that is wp_kses_post(),
                     * which keeps the tags. This block then hand-wrote its own
                     * <textarea> and put that HTML through esc_textarea(),
                     * which escapes it so the browser shows the tags.
                     *
                     * EVERY OTHER LOCKED RICH TEXT FIELD IN THE PLUGIN ALREADY
                     * DID THE RIGHT THING, and this was the one that did not.
                     * SFAF_Rich_Text::render() with locked => true runs the
                     * value through to_plain(), which is sfaf_flatten_html():
                     * a space where a block tag was, then the tags removed. So
                     * the reader gets the prose.
                     *
                     * IT WAS THREE RELEASES OF LOOKING IN THE WRONG PLACE. The
                     * symptom reads as "the FAQ editors do not start", and
                     * these rows are not editors and are not meant to be: they
                     * are locked because a fetch owns them and an edit here
                     * would be overwritten within the hour. What was wrong was
                     * only ever how they were DISPLAYED.
                     *
                     * NOT A CONTROL, SO NOT A CONTROL'S MARKUP. It stays a
                     * disabled textarea rather than becoming a paragraph,
                     * because it sits in a row beside a disabled question input
                     * and the pair has to read as one row of the same list.
                     */
                    ?>
                    <?php foreach ( $imported as $f ) : ?>
                        <div class="uc-repeater-row uc-faq-row uc-faq-row-locked">
                            <input type="text" value="<?php echo esc_attr( $f['question'] ); ?>" disabled aria-label="Question, from <?php echo esc_attr( $label ); ?>, not editable here" />
                            <?php SFAF_Rich_Text::render(
                                '',
                                '',
                                (string) $f['answer'],
                                array(
                                    'rows'       => 2,
                                    'locked'     => true,
                                    'aria_label' => 'Answer, from ' . $label . ', not editable here',
                                )
                            ); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p class="uc-hint" style="margin-top:14px;"><strong>Your own questions.</strong> Added here, kept forever, and never reordered or removed by a fetch. They appear after the ones above.</p>
        <?php endif; ?>

        <div class="uc-repeater" data-repeater>
            <div class="uc-repeater-rows">
                <?php foreach ( $manual as $i => $f ) : ?>
                    <?php sfaf_faq_row( array(
                        'name'     => $name,
                        'index'    => (int) $i,
                        'question' => $f['question'],
                        'answer'   => $f['answer'],
                    ) ); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="uc-btn uc-btn-sm uc-repeater-add">+ Add FAQ</button>
            <template class="uc-repeater-tpl">
                <?php sfaf_faq_row( array( 'name' => $name ) ); ?>
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
        return array( 'image', 'description', 'category', 'organizer', 'listing_detail', 'fundraising_progress', 'private' );
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

        /*
         * THE FOUR LINES A COMMUNITY SUBMISSION PUTS ON THE LISTING (3.46.0).
         *
         * OFFERED WHEREVER ONE OF THEM HAS A VALUE, rather than wherever the
         * event came from a submission. They are ordinary event fields once
         * they exist, and a public value nobody can correct is worse than no
         * value: a submitter's typo in the cost line would otherwise sit on the
         * calendar permanently, because the form that wrote it is not somewhere
         * they can go back to.
         *
         * NOT ON EVERY EVENT, because four empty boxes on every editor is four
         * more things to read past on the screen this project already has a
         * queued job to simplify.
         */
        $fields = array_diff( $fields, array( 'listing_detail' ) );
        if ( $ctx['event_id'] ) {
            /*
             * ALL SEVEN KEYS, AND THE THREE CONTACT ONES WERE THE POINT OF
             * 3.72.0 TOUCHING THIS LIST.
             *
             * The card is offered wherever one of the values it edits exists,
             * so the list has to be every key it edits. Splitting the contact
             * into a name, an address and a number and leaving this asking only
             * about `_uc_public_contact` would have hidden the card from every
             * community submission made since 3.47.0, which is all of them: the
             * only event still carrying the old single key is one submitted on
             * 3.46.0, and those are the ones this list already covered.
             */
            $detail_keys = array(
                SFAF_Submit::META_COST,
                SFAF_Submit::META_AGE,
                SFAF_Submit::META_RSVP_URL,
                SFAF_Submit::META_CONTACT_NAME,
                SFAF_Submit::META_CONTACT_EMAIL,
                SFAF_Submit::META_CONTACT_PHONE,
                SFAF_Submit::META_CONTACT,
            );
            foreach ( $detail_keys as $detail_key ) {
                if ( '' !== (string) get_post_meta( $ctx['event_id'], $detail_key, true ) ) {
                    $fields[] = 'listing_detail';
                    break;
                }
            }
        }

        /*
         * PRIVATE IS ON EVERY EVENT, NATIVE OR IMPORTED, AND ONLY ONCE SAVED.
         *
         * Native and imported both get it because it is a decision about this
         * calendar rather than about where the event came from. It is in the
         * manager-owned list for the imported case specifically: a source has
         * no concept of private, so a refetch must never be able to clear it,
         * exactly like the fundraising toggle. See SFAF_Sources::import_event()
         * and the guard on the adapter meta loop.
         *
         * NOT OFFERED BEFORE THE EVENT EXISTS. Making an event private rewrites
         * its slug, and there is no post to rewrite until the first save. A
         * manager ticking it on the new-event form would be ticking something
         * that could not take effect, so the control appears the moment there
         * is an event to apply it to.
         */
        $fields = array_diff( $fields, array( 'private' ) );
        if ( $ctx['event_id'] ) {
            $fields[] = 'private';
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
                <?php /*
                  * ONCE, HERE, FOR THE WHOLE PANEL. The adapter's note used to
                  * print beside each waiting field, which on a GoFundMe Pro
                  * campaign meant the same paragraph twice per event, every
                  * event, down the queue. Saying it at the top of the panel the
                  * fields belong to costs one sentence and covers all of them.
                  */ ?>
                <p class="uc-help">
                    These are the fields
                    <?php echo $imported ? esc_html( $ctx['prov']['label'] ) . ' does not supply' : 'a person sets'; ?>.
                    They are the same controls as the event editor, on the same event: whatever is set here is set there.
                    <?php if ( $imported && '' !== $ctx['note'] ) : ?>
                        <?php echo esc_html( $ctx['note'] ); ?>
                    <?php endif; ?>
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
    /**
     * The attributes that make a picker's wrapper a calendar-folder picker.
     *
     * On the WRAPPER rather than repeated on each control, because that is
     * where portal.js reads them from, and returned as a string so a caller
     * cannot put half of them on.
     *
     * @param bool $folder_has_any
     * @return string
     */
    private function image_picker_atts( $folder_has_any, $series_id = 0, $can_upload = null ) {
        /*
         * TWO MORE SINCE 3.74.0, AND BOTH ARE REQUESTS RATHER THAN RULES.
         *
         *   data-uc-media-series  opens the library on this event's series,
         *                         with an "All calendar images" trigger beside
         *                         Choose Image as the way out of it.
         *   data-uc-media-upload  0 hides wp.media's Upload Files tab.
         *
         * NEITHER IS WHAT ENFORCES ANYTHING, and that is worth being explicit
         * about because both look like they do. The series filter is a query
         * argument SFAF_Media_Folder reads server-side; the upload gate is a
         * capability filter on the same class, checked when the file actually
         * arrives. If this markup were removed the server would still narrow
         * and still refuse. What these do is stop a control being offered that
         * the server is going to turn down, which is a different job.
         */
        if ( null === $can_upload ) {
            $can_upload = SFAF_Media::can_upload( wp_get_current_user() );
        }
        return ' data-uc-media-folder="' . esc_attr( SFAF_Media_Folder::FOLDER ) . '"'
            . ' data-uc-media-flag="' . esc_attr( SFAF_Media_Folder::FLAG ) . '"'
            . ' data-uc-media-series="' . (int) $series_id . '"'
            . ' data-uc-media-upload="' . ( $can_upload ? '1' : '0' ) . '"'
            . ( $folder_has_any ? '' : ' data-uc-media-folder-empty="1"' );
    }

    /**
     * THE FEATURED IMAGE PICKER. ONE OF THEM, WHEREVER IT APPEARS.
     *
     * WHAT WENT WRONG WITHOUT THIS. The picker was markup written out twice,
     * once in the event editor and once on the series screen. When it was
     * rebound from `getElementById('uc-featured-image-id')` to data attributes,
     * so that the pending queue could render several on one page, the event
     * editor's copy was updated and the series screen's was not. Its Choose
     * Image button then did NOTHING AT ALL: bindImageField() looks for
     * `[data-uc-image-id]`, `[data-uc-image-preview]` and
     * `[data-uc-image-preview-img]`, found none of them, and returned before it
     * bound the click. The media library was loaded correctly on that screen
     * the whole time, which is why the enqueue was the wrong place to look.
     *
     * Then 3.42.1 filtered the picker to the calendar folder and routed its
     * uploads there, on the copy that worked, and the other copy would have
     * gone on offering the whole library if anybody had fixed only the binding.
     *
     * So there is one renderer. The four hooks the script needs, the folder
     * attributes, the buttons, the size and the folder note are emitted here
     * and nowhere else, and a new screen wanting a picker calls this rather
     * than copying markup. The parts that genuinely differ between screens are
     * arguments.
     *
     * @param array $args
     */
    private function render_image_picker( $args ) {
        $a = array_merge( array(
            'uid'         => 'x',
            'id_name'     => 'featured_image_id',
            'url_name'    => 'image_url',
            'id_value'    => 0,
            'url_value'   => '',
            'preview'     => '',
            'show_remove' => false,
            'locked_note' => '',
            'after_buttons' => '',
            'url_label'   => 'Or an image URL',
            'url_help'    => '',
            'extra_hint'  => '',
            'folder_has_any' => true,
        ), $args );
        ?>
        <input type="hidden" name="<?php echo esc_attr( $a['id_name'] ); ?>"
               id="uc-featured-image-id-<?php echo esc_attr( $a['uid'] ); ?>"
               data-uc-image-id value="<?php echo (int) $a['id_value']; ?>" />
        <div class="uc-image-preview" id="uc-image-preview-<?php echo esc_attr( $a['uid'] ); ?>"
             data-uc-image-preview<?php echo $a['preview'] ? '' : ' style="display:none;"'; ?>>
            <img src="<?php echo esc_url( $a['preview'] ); ?>" alt="" data-uc-image-preview-img />
        </div>

        <?php if ( '' !== $a['locked_note'] ) : ?>
            <p class="uc-hint"><?php echo esc_html( $a['locked_note'] ); ?></p>
            <?php return; ?>
        <?php endif; ?>

        <div class="uc-image-buttons">
            <button type="button" class="uc-btn uc-btn-sm uc-choose-image">Choose Image</button>
            <?php
            /*
             * THE WAY OUT OF THE SERIES FILTER (3.74.0). Rendered always and
             * hidden by portal.js when there is no series to be narrowed to,
             * because the whole control is JavaScript: a button that only makes
             * sense once a frame exists cannot usefully be decided by PHP, and
             * with no script neither button does anything at all.
             */
            ?>
            <button type="button" class="uc-btn uc-btn-sm uc-choose-image" data-uc-media-all hidden>All calendar images</button>
            <button type="button" class="uc-btn uc-btn-sm uc-link-danger uc-remove-image"<?php echo $a['show_remove'] ? '' : ' style="display:none;"'; ?>>Remove</button>
        </div>
        <?php echo $a['after_buttons']; // Already-built markup from the caller. ?>
        <label class="uc-field uc-image-url-field">
            <span class="uc-field-label"><?php echo esc_html( $a['url_label'] ); ?>
                <?php echo $a['url_help']; // sfaf_help() output. ?>
            </span>
            <input type="url" name="<?php echo esc_attr( $a['url_name'] ); ?>"
                   id="uc-image-url-<?php echo esc_attr( $a['uid'] ); ?>"
                   data-uc-image-url value="<?php echo esc_attr( $a['url_value'] ); ?>"
                   placeholder="https://…/image.jpg" />
        </label>
        <?php if ( '' !== $a['extra_hint'] ) : ?>
            <p class="uc-hint"><?php echo esc_html( $a['extra_hint'] ); ?></p>
        <?php endif; ?>
        <?php
        /*
         * THE SPEC STAYS INLINE, AND IT IS THE ONE THING HERE THAT DOES.
         *
         * Card images are cropped to 16:9 and filled, so a portrait photograph
         * loses its top and bottom and a group shot can lose the faces. This is
         * the difference between cropping before uploading and finding out
         * afterwards, it is one line, and it is read every time somebody picks
         * a picture rather than once. Behind a "?" it would be read never.
         */
        ?>
        <p class="uc-hint uc-hint-spec"><strong>1200 x 675 pixels, 16:9 landscape.</strong> Cards crop to this shape and fill it.</p>
        <?php
        /*
         * WHAT THE PICKER WILL SHOW, SAID BEFORE IT IS OPENED.
         *
         * A picker that opens on eleven pictures when the media library holds
         * four hundred reads as broken unless somebody was told to expect it.
         * One line, and it also says where an upload goes, which is the answer
         * to the next question. The empty case gets a different line, because
         * then the same screen means something else has gone wrong and the
         * person needs to know it is not them.
         */
        ?>
        <?php if ( $a['folder_has_any'] ) : ?>
            <p class="uc-hint">Choose Image shows the calendar folder only, so everything in it is already the right shape. Anything you upload here goes into that folder.</p>
        <?php else : ?>
            <p class="uc-field-note uc-field-note-attention"><?php echo $this->icon_needs(); ?><span>The calendar folder has no images in it yet, so Choose Image will look empty. Uploading one here puts it in the folder. If you expected pictures to be there, check that the folder is still <code>uploads/<?php echo esc_html( SFAF_Media_Folder::FOLDER ); ?></code>.</span></p>
        <?php endif; ?>
        <?php
    }

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
                <?php
                /*
                 * THE PICKER OFFERS THE CALENDAR FOLDER ONLY (3.42.1).
                 *
                 * Marked on the field rather than switched on globally in the
                 * script, because the pending queue renders this same control
                 * once per queued event and a global switch is a thing that
                 * gets flipped for one screen and forgotten on another. The
                 * attribute travels with the control that needs it.
                 *
                 * Both halves are here: the query the picker makes, and the
                 * folder an upload from it lands in. See SFAF_Media_Folder for
                 * why this filters on the file path rather than through WP
                 * Media Folder's own API.
                 */
                $folder_has_any = SFAF_Media_Folder::has_any();
                /* THE EVENT'S OWN SERIES, so the library opens on the pictures
                 * most likely to be the right one, with everything else one
                 * press away. 0 on a new event, which is the whole-folder list
                 * this has always shown. */
                $picker_series = ! empty( $ctx['event_id'] ) ? SFAF_Series::for_event( (int) $ctx['event_id'] ) : null;
                $picker_tag    = ( $picker_series && ! is_wp_error( $picker_series ) ) ? (int) $picker_series->term_id : 0;
                ?>
                <div class="uc-field uc-image-field<?php echo esc_attr( $this->field_class( $state ) ); ?>"
                     <?php echo $this->image_picker_atts( $folder_has_any, $picker_tag ); ?>
                     <?php echo $this->field_watch_attr( 'image', $state ); ?>>
                    <span class="uc-field-label">Featured Image
                        <?php
                        /*
                         * THE TAG IS WRITTEN BY THE SCRIPT TOO, so it carries
                         * the label rather than the script carrying a copy of
                         * it. Filling a new event in from a series COPIES the
                         * picture onto the event, so the tag has to stop saying
                         * "Placeholder" the moment the button runs; a second
                         * spelling of "Event-specific" in portal.js would be a
                         * second place for it to be renamed and missed.
                         */
                        ?>
                        <span class="uc-img-source-tag" data-uc-img-source-tag
                              data-uc-img-source-own="<?php echo esc_attr( $src_labels['event'] ); ?>"><?php
                            echo esc_html( isset( $src_labels[ $img_source ] ) ? $src_labels[ $img_source ] : $img_source );
                        ?></span>
                        <?php echo $this->field_badge( $state, $label ); ?>
                    </span>
                    <?php /*
                      * THE ADAPTER'S NOTE IS NOT REPEATED HERE. It used to print
                      * in full beside every field that was waiting, so a queue
                      * row opened to two identical paragraphs about GoFundMe Pro
                      * not supplying images or descriptions, once under the
                      * image and once under the description, on every event.
                      *
                      * It is said ONCE per event now: at the top of this panel
                      * on the queue, and in the missing-fields banner in the
                      * editor. The field keeps its badge, which is what marks
                      * WHICH field is waiting; the sentence explaining why is
                      * the same sentence every time and belongs in one place.
                      */ ?>
                    <?php
                    $reset_box = ( 'event' === $img_source && $in_series )
                        ? '<label class="uc-check"><input type="checkbox" name="reset_series_image" value="1" /> Reset to series image</label>'
                        : '';
                    $this->render_image_picker( array(
                        'uid'         => $uid,
                        'id_name'     => 'featured_image_id',
                        'url_name'    => 'image_url',
                        'id_value'    => $thumb_id,
                        'url_value'   => $own_url,
                        'preview'     => $preview,
                        'show_remove' => ( 'event' === $img_source ),
                        'locked_note' => ( 'locked' === $state )
                            ? $label . ' supplies this image and refreshes it on every fetch. Change it there and it follows through on the next fetch.'
                            : '',
                        'after_buttons' => $reset_box,
                        'url_help'    => sfaf_help(
                            'uc-help-imgurl-' . $uid,
                            'A picture set here overrides the series image for this one date. The URL is the fallback: it is used only when no image has been chosen from the library, so pasting one never fights with a chosen file.',
                            'the image URL'
                        ),
                        'folder_has_any' => $folder_has_any,
                    ) );
                    ?>
                </div>
                <?php
                break;

            case 'description':
                ?>
                <div class="uc-field<?php echo esc_attr( $this->field_class( $state ) ); ?>"<?php echo $this->field_watch_attr( 'description', $state ); ?>>
                    <span class="uc-field-label">Description <?php echo $this->field_badge( $state, $label ); ?></span>
                    <?php $this->description_editor( $ctx, $state ); ?>
                </div>
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
                /*
                 * EVERY ORGANIZER ON THE EVENT, NOT THE FIRST ONE (3.40.0).
                 *
                 * This read `[0]`, and the save below wrote an array of that
                 * one back with wp_set_object_terms(), whose default REPLACES.
                 * So an event holding two organizers showed one here and lost
                 * the other the moment anybody pressed Save, with nothing said.
                 * The taxonomy has always accepted several; the control was
                 * what limited it.
                 *
                 * That is the same fault categories had until 3.8.0, on a
                 * different taxonomy, and it was reachable the same way: the
                 * WordPress post editor's own Organizers box has always been
                 * there, because show_in_menu is off on this taxonomy and
                 * show_ui is not.
                 */
                $current = $event_id
                    ? wp_list_pluck( SFAF_Organizers::for_event( $event_id ), 'term_id' )
                    : array();
                $current = array_map( 'intval', $current );
                ?>
                <div class="uc-field<?php echo esc_attr( $this->field_class( $state ) ); ?>"<?php echo $this->field_watch_attr( 'organizer', $state ); ?>>
                    <span class="uc-field-label">Organizer <?php echo $this->field_badge( $state, $label ); ?></span>

                    <?php // The marker, for the reason every other multi-value
                          // control on this form has one: every box unticked
                          // submits nothing, and that has to mean "none" rather
                          // than "this form did not ask". ?>
                    <input type="hidden" name="uc_organizer_present" value="1" />

                    <div class="uc-check-grid">
                        <?php if ( ! is_wp_error( $ctx['orgs'] ) ) : foreach ( $ctx['orgs'] as $o ) : ?>
                            <label class="uc-check">
                                <input type="checkbox" name="organizer[]" value="<?php echo (int) $o->term_id; ?>"
                                       <?php checked( in_array( (int) $o->term_id, $current, true ) ); ?><?php echo $this->field_disabled( $state ); ?> />
                                <?php echo esc_html( $o->name ); ?>
                            </label>
                        <?php endforeach; endif; ?>
                    </div>

                    <?php if ( count( $current ) > 1 ) : ?>
                        <span class="uc-hint uc-hint-spec">Co-hosted. The event page names them in this order.</span>
                    <?php endif; ?>
                </div>

                <?php
                /*
                 * NO INLINE ORGANIZER CREATION HERE. REVERSED IN 3.41.0.
                 *
                 * 3.37.0 put a "Not listed? Add one" field on this card. The
                 * reasoning was that setting up an event for a new programme
                 * meant abandoning a half-typed event to go and make the
                 * organizer somewhere else, and that a field riding the
                 * ordinary Save closed that without the 3.3.0 fault of a
                 * button that posts and discards unsaved typing. That part of
                 * the reasoning was right and is why it was a field.
                 *
                 * WHAT CHANGED IS THAT THE PREMISE WENT AWAY. The same
                 * release gave organizers their own screen, so this list is
                 * one somebody curates deliberately, and a text box beside it
                 * invents entries in passing. An organizer typed mid-event
                 * gets whatever spelling was in somebody's head, which is how
                 * a list acquires "Stonewall Project", "The Stonewall
                 * Project" and "Stonewall", each owning some events. Merging
                 * them afterwards is manual and nobody does it.
                 *
                 * The friction it removed was real and is now small: the
                 * Organizers screen is two clicks away and makes the term in
                 * one field. The cost it added is permanent and lands on
                 * somebody else. So this is a picker of things that exist,
                 * and making one is a decision taken on the screen for it.
                 *
                 * The save half is gone too; see save_event_from_post().
                 */
                ?>
                <?php
                break;

            case 'listing_detail':
                $d_cost  = (string) get_post_meta( $event_id, SFAF_Submit::META_COST, true );
                $d_age   = (string) get_post_meta( $event_id, SFAF_Submit::META_AGE, true );
                $d_rsvp  = (string) get_post_meta( $event_id, SFAF_Submit::META_RSVP_URL, true );
                $d_name  = (string) get_post_meta( $event_id, SFAF_Submit::META_CONTACT_NAME, true );
                $d_email = (string) get_post_meta( $event_id, SFAF_Submit::META_CONTACT_EMAIL, true );
                $d_phone = (string) get_post_meta( $event_id, SFAF_Submit::META_CONTACT_PHONE, true );
                /* The pre-3.47.0 single box, read only where the three-part
                 * answer is empty. See below and sfaf_event_public_contact(). */
                $d_old   = (string) get_post_meta( $event_id, SFAF_Submit::META_CONTACT, true );
                ?>
                <div class="uc-field uc-listing-detail">
                    <span class="uc-field-label">Listing detail</span>
                    <p class="uc-hint">These are on the event page. Empty a box to take that line off.</p>
                    <?php
                    /*
                     * ONE MARKER FOR THE WHOLE CARD, AND IT NOW HAS TO COVER
                     * SIX FIELDS (3.72.0).
                     *
                     * The marker is what makes an empty box mean empty rather
                     * than "this screen did not ask", and the save deletes the
                     * meta on empty rather than storing ''. That contract is
                     * unchanged and it is why the marker travels with the
                     * control: a screen that does not render this card posts no
                     * marker, so its save cannot blank a public line it never
                     * showed. Splitting one box into three means three more
                     * fields depending on it, which is the same guarantee and
                     * not a new one.
                     */
                    ?>
                    <input type="hidden" name="uc_listing_detail_present" value="1" />
                    <label class="uc-field">
                        <span class="uc-field-label">Cost</span>
                        <input type="text" name="listing_cost" maxlength="120" value="<?php echo esc_attr( $d_cost ); ?>" />
                    </label>
                    <label class="uc-field">
                        <span class="uc-field-label">Age restriction</span>
                        <input type="text" name="listing_age" maxlength="120" value="<?php echo esc_attr( $d_age ); ?>" />
                    </label>

                    <?php
                    /*
                     * THREE BOXES, BECAUSE THE ONE BOX WROTE THE WRONG KEY.
                     *
                     * WHAT WAS WRONG. "Contact shown publicly" wrote
                     * `_uc_public_contact`, which is the single open box the
                     * community form had before 3.47.0. Since 3.47.0 that form
                     * has written three keys instead, and
                     * sfaf_event_public_contact() prefers them: it composes the
                     * three-part line and only falls back to the old key when
                     * that line is empty. So on any community submission from
                     * 3.47.0 onwards, which is all of them, a manager could
                     * type into this box, save, reload and see their text still
                     * sitting in the box while the event page showed something
                     * else entirely. The value was stored. It was just never
                     * read.
                     *
                     * WHY NOT MAKE THE ONE BOX WRITE THE NEW KEYS. Because
                     * there is no way to split one line into a name, an address
                     * and a number without guessing, and a guess here is
                     * printed on a public page.
                     *
                     * WHY NOT DROP THE OLD KEY. Events submitted on 3.46.0 have
                     * one and there is no migration. A stored value that
                     * predates a change is not a value to throw away, and this
                     * is the only screen that could ever show somebody what
                     * theirs says.
                     *
                     * THE OLD ONE IS READ-ONLY AND ONLY APPEARS WHEN IT IS
                     * DOING SOMETHING. An editable second control writing a key
                     * that loses to the three above it is the same fault again
                     * in a smaller box. Where the three are filled in, the old
                     * value is not being read by anything, so it is not shown:
                     * a field labelled "not in use" is an invitation to work
                     * out why. Filling in any of the three is what retires it,
                     * and the note says so.
                     */
                    $old_live = ( '' !== trim( $d_old ) && '' === trim( $d_name . $d_email . $d_phone ) );
                    ?>
                    <div class="uc-field uc-contact-group">
                        <span class="uc-field-label">Contact shown publicly</span>
                        <span class="uc-hint">On the event page, for anybody who wants to ask about it. Leave all three empty for no contact line.</span>
                        <label class="uc-field">
                            <span class="uc-field-label">Name</span>
                            <input type="text" name="contact_name" maxlength="120" value="<?php echo esc_attr( $d_name ); ?>" />
                        </label>
                        <label class="uc-field">
                            <span class="uc-field-label">Email</span>
                            <input type="email" name="contact_email" maxlength="200" value="<?php echo esc_attr( $d_email ); ?>" />
                        </label>
                        <label class="uc-field">
                            <span class="uc-field-label">Phone</span>
                            <input type="text" name="contact_phone" maxlength="60" value="<?php echo esc_attr( $d_phone ); ?>" />
                        </label>
                        <?php if ( $old_live ) : ?>
                            <div class="uc-field uc-contact-legacy">
                                <span class="uc-field-label">What the event page shows now</span>
                                <input type="text" value="<?php echo esc_attr( $d_old ); ?>" readonly />
                                <span class="uc-hint">Submitted before the three boxes above existed. Fill any of them in and this stops being used.</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <label class="uc-field">
                        <span class="uc-field-label">Registration link</span>
                        <input type="url" name="listing_rsvp_url" maxlength="500" value="<?php echo esc_attr( $d_rsvp ); ?>" />
                        <span class="uc-hint">Somewhere else people sign up. Not this calendar's own registration.</span>
                    </label>
                </div>
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

            case 'private':
                $is_private = SFAF_Privacy::is_private( $event_id );
                ?>
                <div class="uc-field uc-private-field">
                    <span class="uc-field-label">Who can find this event
                        <?php
                        /* THE HINT SAYS WHAT HAPPENS; THIS SAYS THE PART THAT
                         * IS NOT ABOUT THIS EVENT. Anybody holding the address
                         * can pass it on, and nothing here can stop them or
                         * find out, which is the thing to weigh before ticking
                         * it rather than a description of what ticking it
                         * does. */
                        echo sfaf_help(
                            'uc-help-private-' . (int) $event_id,
                            'The link is the whole of the protection: anybody who has it can open the event and can pass it on. There is no list of who has looked and no way to take the link back except making a new one.',
                            'private events'
                        );
                        ?>
                    </span>
                    <?php // Hidden 0 first, same reason as the toggle above: the
                          // pending queue posts this control on its own, so an
                          // absent checkbox has to mean off rather than
                          // "not submitted". ?>
                    <input type="hidden" name="uc_private" value="0" />
                    <label class="uc-check">
                        <input type="checkbox" name="uc_private" value="1" <?php checked( $is_private ); ?> />
                        Make this event private
                    </label>
                    <?php
                    /*
                     * WHAT HAPPENS, AND THE ONE CONSEQUENCE NOBODY EXPECTS
                     * (3.68.0).
                     *
                     * This ran to five sentences and two of them described the
                     * mechanism: which surfaces the event is left out of, and
                     * what happens to the old address if it is switched back
                     * off. Neither is a thing to do or a thing that will happen
                     * to the reader at the moment they are deciding.
                     *
                     * THE THIRD SENTENCE IS THE ONE THAT MATTERS AND IS WHY
                     * THIS IS NOT ONE SENTENCE. Ticking this box breaks a link
                     * somebody may already have sent to a room full of people,
                     * and nothing else on this screen would tell them.
                     *
                     * THE NO-INDEX HALF NEEDS NO SENTENCE. It is not a decision
                     * a manager makes or a consequence they meet: the page
                     * emits noindex, nofollow and is out of both sitemaps
                     * whatever they do. See SFAF_Privacy::robots().
                     */
                    ?>
                    <p class="uc-hint">
                        The event will not appear anywhere on the site. Only people you send the link to can
                        find it. Turning this on gives the event a new link, so any link you have already
                        shared will stop working.
                    </p>
                    <?php if ( $is_private ) : ?>
                        <p class="uc-hint uc-private-link">
                            Send this address:
                            <code><?php echo esc_html( get_permalink( $event_id ) ); ?></code>
                        </p>
                    <?php endif; ?>
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
        if ( isset( $_POST['uc_organizer_present'] ) ) {
            /*
             * EVERY TICKED BOX, NOT ONE VALUE (3.40.0).
             *
             * wp_set_object_terms() REPLACES by default, which is what silently
             * deleted a second organizer every time an event was saved here.
             * Replacing is still correct: this control shows every organizer
             * that exists and the ticked set is the complete answer. What was
             * wrong was that the complete answer could only ever be one.
             */
            $orgs = isset( $_POST['organizer'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['organizer'] ) ) : array();
            $orgs = array_values( array_unique( array_filter( $orgs ) ) );

            /*
             * organizer_new IS GONE (3.41.0). This used to create a term from
             * a text box on the event editor, in the same request as the save.
             * The field is off that card, and this half is deliberately
             * removed rather than left reading a POST key nothing sends: a
             * save that can still invent an organizer is a way back in for
             * anything that posts here. Organizers are made on the Organizers
             * screen. See the note on the control in render_manager_control().
             */
            wp_set_object_terms( $event_id, $orgs, 'uc_organizer' );
        }

        /*
         * The fundraising toggle. Written only when the control was on the
         * form, so a screen that does not offer it cannot silently switch it
         * off. And because the control always posts a hidden 0 beside the
         * checkbox, a screen that DOES offer it always says which way.
         */
        /*
         * LISTING DETAIL. Marker-gated like the two below it, so a screen that
         * did not carry the control cannot blank four public lines by saving.
         *
         * SANITISED THE SAME WAY THE FORM DID. A manager is trusted more than a
         * stranger, but these four values are printed on a public page, and the
         * rule for what may be in one belongs to the field rather than to
         * whoever last touched it. An emptied box DELETES the meta rather than
         * storing '', so the template's single test for absence stays single.
         */
        if ( isset( $_POST['uc_listing_detail_present'] ) ) {
            /*
             * THE CONTACT IS THREE KEYS NOW (3.72.0), and `_uc_public_contact`
             * IS NOT IN THIS LIST ON PURPOSE.
             *
             * The card writes the same three keys the community form writes,
             * which is what makes what a manager types the thing the event page
             * prints: sfaf_event_public_contact() composes those three and only
             * falls back to the old single key when they are all empty. See the
             * renderer for the whole of why.
             *
             * NOT LISTED HERE MEANS NEVER WRITTEN AND NEVER DELETED. The old
             * key is shown read-only where it is still the value being used, so
             * there is no control that could post it, and this save leaves it
             * exactly where it is. That matters: an event on the pre-3.47.0
             * shape whose manager saves this card without touching the contact
             * must not lose its only public contact line. Filling any of the
             * three above is what supersedes it, by the reader's own rule,
             * rather than by anything deleting anything.
             *
             * THE EMAIL IS SANITISED AS AN EMAIL AND NOT AS A LINE. It is
             * printed on a public page and is the one of the three that has a
             * shape. An address that is not one is dropped rather than
             * published, which is the same answer the community form gives.
             */
            $c_email = isset( $_POST['contact_email'] )
                ? sanitize_email( trim( (string) wp_unslash( $_POST['contact_email'] ) ) )
                : '';
            if ( '' !== $c_email && ! is_email( $c_email ) ) {
                $c_email = '';
            }

            $detail = array(
                SFAF_Submit::META_COST          => SFAF_Submissions::line( isset( $_POST['listing_cost'] ) ? wp_unslash( $_POST['listing_cost'] ) : '', 120 ),
                SFAF_Submit::META_AGE           => SFAF_Submissions::line( isset( $_POST['listing_age'] ) ? wp_unslash( $_POST['listing_age'] ) : '', 120 ),
                SFAF_Submit::META_CONTACT_NAME  => SFAF_Submissions::line( isset( $_POST['contact_name'] ) ? wp_unslash( $_POST['contact_name'] ) : '', 120 ),
                SFAF_Submit::META_CONTACT_EMAIL => $c_email,
                SFAF_Submit::META_CONTACT_PHONE => SFAF_Submissions::line( isset( $_POST['contact_phone'] ) ? wp_unslash( $_POST['contact_phone'] ) : '', 60 ),
                SFAF_Submit::META_RSVP_URL      => SFAF_Submissions::url( isset( $_POST['listing_rsvp_url'] ) ? wp_unslash( $_POST['listing_rsvp_url'] ) : '' ),
            );
            foreach ( $detail as $detail_key => $detail_value ) {
                if ( '' === $detail_value ) {
                    delete_post_meta( $event_id, $detail_key );
                } else {
                    update_post_meta( $event_id, $detail_key, $detail_value );
                }
            }
        }

        if ( isset( $_POST['show_fund_progress'] ) ) {
            update_post_meta(
                $event_id,
                sfaf_fundraising_progress_meta_key(),
                ( '1' === (string) wp_unslash( $_POST['show_fund_progress'] ) ) ? '1' : '0'
            );
        }

        /*
         * PRIVATE. Same marker discipline as the toggle above: written only
         * when the control was on the form, and the control always posts a
         * hidden 0 beside the checkbox so a screen that offers it always says
         * which way.
         *
         * THROUGH SFAF_Privacy::set() RATHER THAN update_post_meta(), because
         * the meta is only half of the state. The other half is the slug, and
         * an event whose meta says private with a readable address at
         * /events/donor-reception is not private. set() is the one place both
         * move together, and it is a no-op when nothing changed, so re-saving
         * an event does not churn the address.
         */
        if ( isset( $_POST['uc_private'] ) ) {
            SFAF_Privacy::set( $event_id, '1' === (string) wp_unslash( $_POST['uc_private'] ) );
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
            <?php
            /*
             * THE LIST FOLDS AWAY (3.76.0), AND THE DEFAULT IS A RULE RATHER
             * THAN A NUMBER SOMEBODY CHOSE.
             *
             * Twenty-five series is a long scroll to reach the form under them,
             * and seven categories is not. The obvious answer is "collapse
             * series, open categories", and it is the wrong shape: it is two
             * decisions taken against today's counts, and the seventh category
             * becomes the fortieth without anybody revisiting them.
             *
             * SO IT IS ONE THRESHOLD, ASKED OF THE LIST. Short lists open, long
             * ones fold, and both screens use the same number. Today that means
             * categories open and series folded, which is the behaviour asked
             * for, and it goes on being right when the counts move.
             *
             * A NATIVE <details>, so it works with nothing running. Same as the
             * schedule's past dates, the picture chooser and the form-link
             * disclosure. No scripted show and hide anywhere near it.
             */
            ?>
            <details class="uc-list-fold"<?php echo sfaf_fold_open( count( $series ) ) ? ' open' : ''; ?>>
                <summary class="uc-card-head uc-list-fold-head">
                    <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '16px' ) ); ?></span>
                    <?php // "series" is the same word either way, so no plural test. ?>
                    <h2><?php echo count( $series ); ?> series</h2>
                </summary>
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
                                    ? esc_html( sfaf_ap_date( $next, 'short_year' ) )
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
            </details>

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
                    <a href="<?php echo esc_url( $this->url( 'series/new' ) ); ?>" class="uc-btn uc-btn-sm uc-btn-primary">+ New series</a>
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
     * THE COLOUR PICKER IS THE PALETTE, not a colour input. One radio per
     * approved brand colour, because a free hex field is a way to put something
     * off-brand on the public calendar and the guide is explicit that the
     * palette is the palette. It was ten and is sixteen since 3.75.0: the six
     * additions are SHADES of approved colours rather than new hues, every one
     * measured by .claude/palette-audit.php for the two things that can go
     * wrong, a colour whose icon cannot be seen and a colour nobody can tell
     * from its neighbour. sfaf_sanitize_brand_color() enforces the same
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
                <?php // The same fold and the same threshold as the series list above. ?>
                <details class="uc-list-fold"<?php echo sfaf_fold_open( count( $cats ) ) ? ' open' : ''; ?>>
                <summary class="uc-card-head uc-list-fold-head">
                    <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '16px' ) ); ?></span>
                    <h2><?php echo count( $cats ); ?> <?php echo esc_html( 1 === count( $cats ) ? 'category' : 'categories' ); ?></h2>
                </summary>

                <?php if ( $err ) : ?>
                    <div class="uc-flash uc-flash-error"><?php echo esc_html( $err ); ?></div>
                <?php endif; ?>

                <p class="uc-hint">
                    An event can be in several. The first one alphabetically supplies the color of its card and the
                    picture shown when it has no image of its own.
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
                </details>

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
                <?php
                /*
                 * THE SENTENCE COUNTED AND THE COUNT MOVED (3.75.0). It read
                 * "the ten approved brand colors", which was true of the guide's
                 * palette and stopped being true the moment shades of those
                 * colours were added. A number in copy is a thing that goes
                 * stale silently, so this says what the rule IS rather than how
                 * many things it currently admits.
                 */
                ?>
                <span class="uc-hint">The approved brand colors and shades of them. Nothing outside this list can be saved here.</span>
            </div>

            <?php
            /*
             * THE ICON IS DRAWN, NOT NAMED (3.76.0).
             *
             * It was a `<select>` of thirty words, and choosing between thirty
             * glyphs by their names is guessing: "Bolt" and "Star" and "Flag"
             * tell you what the word is and nothing about what the picture
             * looks like at 20px on a card.
             *
             * SO IT IS RADIOS, WHICH IS WHAT THE COLOUR ABOVE ALREADY IS. A
             * `<select>` cannot hold an SVG, and the swatch grid on this same
             * form is the pattern for "choose one of a closed set of things you
             * have to see". Same shape, same no-script behaviour: radios post
             * whether anything ran or not.
             *
             * THE GROUPING HAD TO BECOME DATA TO SURVIVE. It was `// General`
             * and friends in the source, which reads well and cannot be
             * rendered, and a grid of thirty unlabelled glyphs would be worse
             * than the list it replaced. SFAF_Categories::icon_groups() is the
             * one list now and icons() is built from it.
             */
            ?>
            <div class="uc-field uc-cat-icon">
                <span class="uc-field-label">Icon</span>
                <div class="uc-icon-choice" role="radiogroup" aria-label="Category icon">
                    <?php foreach ( SFAF_Categories::icon_groups() as $group_label => $group ) : ?>
                        <p class="uc-icon-choice-head"><?php echo esc_html( $group_label ); ?></p>
                        <div class="uc-icon-choice-grid">
                            <?php foreach ( $group as $key => $label ) : ?>
                                <label class="uc-icon-pick" title="<?php echo esc_attr( $label ); ?>">
                                    <input type="radio" name="category_icon" value="<?php echo esc_attr( $key ); ?>"
                                           <?php checked( $icon, $key ); ?> />
                                    <span class="uc-icon-pick-face">
                                        <?php echo sfaf_icon( $key, array( 'size' => '22px' ) ); ?>
                                        <span class="uc-icon-pick-name"><?php echo esc_html( $label ); ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <span class="uc-hint">Drawn on an event that has no picture of its own, which is most imported ones.</span>
            </div>

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

        // A picker and a rich text description, so both.
        $this->load_media  = true;
        $this->load_editor = true;
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

                <?php
                /*
                 * THE SAME PICKER AS THE EVENT EDITOR, AND THAT IS THE FIX.
                 *
                 * This was a second copy of the markup, and it had missed the
                 * rebind from element ids to data attributes, so Choose Image
                 * did nothing here at all. Rebinding this copy on its own would
                 * have left it offering the whole media library while the
                 * editor's offered the calendar folder. See
                 * render_image_picker().
                 */
                ?>
                <?php // This screen IS a series, so the picker opens on its own pictures. ?>
                <div class="uc-field uc-image-field"<?php echo $this->image_picker_atts( SFAF_Media_Folder::has_any(), (int) $term_id ); ?>>
                    <span class="uc-field-label">Image</span>
                    <?php $this->render_image_picker( array(
                        'uid'         => 'series',
                        'id_name'     => 'series_image_id',
                        'url_name'    => 'series_image_url',
                        'id_value'    => $img_id,
                        'url_value'   => $img_url,
                        'preview'     => $preview,
                        'show_remove' => (bool) $preview,
                        'url_label'   => 'Or enter image URL',
                        'extra_hint'  => 'Shown on the series page, and used by any event in the series with no image of its own.',
                        'folder_has_any' => SFAF_Media_Folder::has_any(),
                    ) ); ?>
                </div>

                <?php
                /*
                 * A label, not a <label>. It wrapped the whole field, and a
                 * <label> around an editor means clicking anywhere in the
                 * toolbar focuses the textarea underneath it.
                 */
                ?>
                <div class="uc-field">
                    <span class="uc-field-label">Description</span>
                    <?php SFAF_Rich_Text::render( 'uc-series-desc', 'series_desc', $term ? $term->description : '', array( 'rows' => 8 ) ); ?>
                    <span class="uc-hint">Shown on the series page, including while it has no dates scheduled.</span>
                </div>

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

        /*
         * THE OFFER, SHOWN ONLY TO SOMEBODY WHOSE DELETE WAS JUST REFUSED.
         *
         * Read once and cleared, so it belongs to the redirect that set it
         * rather than reappearing on the next visit to this screen. Same shape
         * as the reassignment offer on the Users screen: the refusal names the
         * problem, and the answer goes where the refusal put them.
         */
        $blocked_key = 'sfaf_series_delete_blocked_' . $user->ID;
        $blocked     = get_transient( $blocked_key );
        if ( $blocked ) {
            delete_transient( $blocked_key );
        }
        if ( ! is_array( $blocked ) || (int) ( isset( $blocked['series_id'] ) ? $blocked['series_id'] : 0 ) !== $term_id ) {
            $blocked = null;
        }

        if ( $blocked ) :
            $c = $blocked['counts'];
            ?>
            <div class="uc-card uc-cancel-instead">
                <div class="uc-card-head"><h2>Cancel these instead of deleting them</h2></div>
                <p class="uc-hint">
                    <strong><?php echo (int) $c['people']; ?></strong>
                    <?php echo esc_html( 1 === (int) $c['people'] ? 'person is' : 'people are' ); ?>
                    registered across <?php echo (int) $c['events']; ?>
                    <?php echo esc_html( 1 === (int) $c['events'] ? 'event' : 'events' ); ?> in this series.
                    Deleting them would leave those people with nothing: no page to visit and no message.
                    Cancelling keeps every registration, closes new ones, and stops the reminders.
                </p>
                <form method="post" action="<?php echo esc_url( $this->url( 'series/remove/' . $term_id ) ); ?>">
                    <input type="hidden" name="uc_action" value="remove_series" />
                    <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
                    <input type="hidden" name="remove_mode" value="delete_events" />
                    <input type="hidden" name="cancel_instead_confirmed" value="1" />
                    <input type="hidden" name="instead" value="cancel" />
                    <?php wp_nonce_field( 'uc_portal_remove_series', 'uc_nonce' ); ?>

                    <fieldset class="uc-cancel-visibility">
                        <legend>What should happen to them on the public calendar?</legend>
                        <label class="uc-radio-opt">
                            <input type="radio" name="cancel_visibility" value="stay" checked />
                            <span><strong>Leave them listed, marked cancelled.</strong> Somebody who registered may come looking.</span>
                        </label>
                        <label class="uc-radio-opt">
                            <input type="radio" name="cancel_visibility" value="hide" />
                            <span><strong>Take them off the public calendar.</strong> They stay here with their registrations.</span>
                        </label>
                    </fieldset>

                    <?php
                    /*
                     * TWO BUTTONS, NOT A TICKED BOX (3.42.0).
                     *
                     * This screen is already the confirmation, so the email
                     * question belongs on its buttons rather than in a checkbox
                     * above them. Each button carries its own answer, so the
                     * decision is the same click as the commitment and there is
                     * no state to misread. Neither is a default: leaving is the
                     * third control and nothing happens without a press.
                     */
                    ?>
                    <p class="uc-hint">
                        One email each, however many of these dates they were registered for.
                    </p>

                    <div class="uc-form-actions">
                        <button type="submit" name="notify_choice" value="send" class="uc-btn uc-btn-primary">
                            Cancel these and email the <?php echo (int) $c['people']; ?>
                            <?php echo esc_html( 1 === (int) $c['people'] ? 'person' : 'people' ); ?>
                        </button>
                        <button type="submit" name="notify_choice" value="silent" class="uc-btn">
                            Cancel these without telling them
                        </button>
                        <a class="uc-btn" href="<?php echo esc_url( $this->url( 'series/edit/' . $term_id ) ); ?>">Leave everything alone</a>
                    </div>
                </form>
            </div>
            <?php
        endif;

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

    /* =====================================================================
     * ORGANIZERS (3.37.0)
     *
     * The last of the four taxonomies to get a caladmin screen, and the reason
     * it was last is that it looked like the least: an organizer is a name,
     * with no colour, no address, no image and no term meta of any kind. What
     * it actually was is the one entry a manager could not create without
     * leaving the portal for a WordPress screen that 3.27.0 had unlisted, so a
     * new programme meant abandoning a half-typed event to go and find it.
     *
     * THE LIST IS PLAIN TEXT WITH ACTIONS, not a page of permanent inputs.
     * That is the shape Teams was rebuilt into in 3.20.0 and for the same
     * reason: a screen made of live form fields invites an accidental edit on
     * every visit and gives no reading of what is there. Editing is a
     * disclosure per row, opened deliberately, and creating is a separate
     * collapsed control rather than a form permanently occupying the top of the
     * screen.
     * ================================================================== */

    private function render_organizers( $user ) {
        if ( ! $this->can_view_all( $user ) ) {
            $this->render_dashboard( $user );
            return;
        }
        $this->chrome_open( $user, 'organizers' );

        $organizers = SFAF_Organizers::all();
        $err        = get_transient( 'sfaf_organizer_error_' . $user->ID );
        if ( false !== $err ) {
            delete_transient( 'sfaf_organizer_error_' . $user->ID );
        }
        ?>
        <div class="uc-page-head"><h1>Organizers</h1></div>

        <p class="uc-help">
            Who is putting an event on. An event names one, and it appears on the event page as
            &ldquo;Hosted by&rdquo;, on the public calendar&rsquo;s organizer filter, and in the
            search engine listing. Renaming one here renames it everywhere at once.
        </p>

        <?php if ( $err ) : ?>
            <div class="uc-flash uc-flash-error"><?php echo esc_html( $err ); ?></div>
        <?php endif; ?>

        <?php
        /*
         * CREATE: A COLLAPSED CONTROL, NOT A FORM ALREADY OPEN.
         *
         * Adding an organizer is the rarer of the two jobs done here; reading
         * the list is the common one. A form standing open at the top pushes
         * the list down for something most visits do not need. Same disclosure
         * as the team-add control, so click, tap, Enter and Space all work with
         * no script and it still opens if portal.js never runs.
         */
        ?>
        <details class="uc-card uc-organizer-add" data-uc-disclosure>
            <summary class="uc-add-toggle" aria-expanded="false">
                <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '18px' ) ); ?></span>
                <span>Add an organizer</span>
            </summary>
            <div class="uc-organizer-add-body">
                <form method="post" action="<?php echo esc_url( $this->url( 'organizers' ) ); ?>" class="uc-organizer-form">
                    <input type="hidden" name="uc_action" value="save_organizer" />
                    <input type="hidden" name="organizer_id" value="0" />
                    <?php wp_nonce_field( 'uc_portal_save_organizer', 'uc_nonce' ); ?>
                    <label class="uc-field">
                        <span class="uc-field-label">Name</span>
                        <input type="text" name="organizer_name" required placeholder="The Stonewall Project" />
                    </label>
                    <label class="uc-field">
                        <span class="uc-field-label">Description (optional)</span>
                        <textarea name="organizer_description" rows="2"></textarea>
                        <span class="uc-hint uc-hint-spec">Not shown on the event page. It is here for whoever reads this list next.</span>
                    </label>
                    <div class="uc-form-actions">
                        <button type="submit" class="uc-btn uc-btn-primary">Add organizer</button>
                    </div>
                </form>
            </div>
        </details>

        <div class="uc-card">
            <div class="uc-card-head">
                <h2><?php echo count( $organizers ); ?> <?php echo esc_html( 1 === count( $organizers ) ? 'organizer' : 'organizers' ); ?></h2>
            </div>

            <?php if ( empty( $organizers ) ) : ?>
                <p class="uc-empty">No organizers yet. Add one above, or from the Organizer field on any event.</p>
            <?php else : ?>
                <?php foreach ( $organizers as $org ) :
                    $n       = SFAF_Organizers::event_count( $org->term_id );
                    $panel   = 'uc-org-' . (int) $org->term_id;
                    ?>
                    <div class="uc-organizer-row">
                        <div class="uc-organizer-id">
                            <strong><?php echo esc_html( $org->name ); ?></strong>
                            <span class="uc-muted">
                                <?php echo (int) $n; ?> <?php echo esc_html( 1 === $n ? 'event' : 'events' ); ?>
                                &middot; <code><?php echo esc_html( $org->slug ); ?></code>
                            </span>
                            <?php if ( '' !== trim( (string) $org->description ) ) : ?>
                                <span class="uc-muted"><?php echo esc_html( $org->description ); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="uc-organizer-actions">
                            <?php if ( $n ) : ?>
                                <?php
                                /*
                                 * THE SEARCH, NOT AN organizer= FILTER. The
                                 * events list has no organizer filter, so a
                                 * link carrying one would have quietly listed
                                 * everything and looked like it worked.
                                 * SFAF_Search covers the uc_organizer taxonomy
                                 * by term name, so this reaches exactly these
                                 * events through a route that already exists
                                 * and is already tested.
                                 */
                                ?>
                                <a class="uc-btn uc-btn-sm"
                                   href="<?php echo esc_url( add_query_arg( array( 's' => $org->name, 'scope' => 'all', 'view' => 'all' ), $this->url( 'events' ) ) ); ?>">See events</a>
                            <?php endif; ?>
                        </div>

                        <details class="uc-organizer-edit" data-uc-disclosure>
                            <summary class="uc-team-add-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel ); ?>">
                                <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '16px' ) ); ?></span>
                                <span>Edit</span>
                            </summary>
                            <div class="uc-organizer-edit-body" id="<?php echo esc_attr( $panel ); ?>">
                                <form method="post" action="<?php echo esc_url( $this->url( 'organizers' ) ); ?>" class="uc-organizer-form">
                                    <input type="hidden" name="uc_action" value="save_organizer" />
                                    <input type="hidden" name="organizer_id" value="<?php echo (int) $org->term_id; ?>" />
                                    <?php wp_nonce_field( 'uc_portal_save_organizer', 'uc_nonce' ); ?>
                                    <label class="uc-field">
                                        <span class="uc-field-label">Name</span>
                                        <input type="text" name="organizer_name" value="<?php echo esc_attr( $org->name ); ?>" required />
                                    </label>
                                    <label class="uc-field">
                                        <span class="uc-field-label">Description (optional)</span>
                                        <textarea name="organizer_description" rows="2"><?php echo esc_textarea( $org->description ); ?></textarea>
                                    </label>
                                    <?php
                                    /*
                                     * NO SLUG FIELD, AND NO WARNING ABOUT ONE.
                                     *
                                     * The slug is what embed blocks on other sites resolve
                                     * through and what the public archive URL is made of, and
                                     * it is not something anybody managing events needs to
                                     * read. An editable field invites a change that empties
                                     * somebody else's calendar, and a warning beside it is a
                                     * sentence explaining why a control exists rather than
                                     * telling anybody what to do.
                                     *
                                     * SFAF_Organizers::save() pins the existing slug on every
                                     * rename, so there is nothing here to warn about: the
                                     * value cannot move from this screen.
                                     */
                                    ?>
                                    <div class="uc-form-actions">
                                        <button type="submit" class="uc-btn uc-btn-primary">Save</button>
                                    </div>
                                </form>

                                <form method="post" action="<?php echo esc_url( $this->url( 'organizers' ) ); ?>" class="uc-organizer-delete">
                                    <input type="hidden" name="uc_action" value="delete_organizer" />
                                    <input type="hidden" name="organizer_id" value="<?php echo (int) $org->term_id; ?>" />
                                    <?php wp_nonce_field( 'uc_portal_delete_organizer', 'uc_nonce' ); ?>
                                    <?php
                                    /*
                                     * THE COUNT IS IN THE CONFIRMATION, which is what makes
                                     * an allowed deletion honest rather than merely
                                     * permitted. Deleting is allowed here and refused for a
                                     * venue, because an event that loses its organizer keeps
                                     * every fact about itself and an event that loses its
                                     * venue has nowhere to be.
                                     */
                                    $confirm = $n
                                        ? sprintf(
                                            'Delete %s? %d %s will keep their date, time and location and simply have no organizer. Any embed block filtered by this organizer will stop showing events.',
                                            $org->name, $n, ( 1 === $n ? 'event' : 'events' )
                                        )
                                        : sprintf( 'Delete %s? No events name it.', $org->name );
                                    ?>
                                    <button type="submit" class="uc-btn uc-btn-sm uc-btn-danger"
                                            onclick="return confirm(<?php echo esc_attr( wp_json_encode( $confirm ) ); ?>);">
                                        Delete this organizer
                                    </button>
                                    <?php if ( $n ) : ?>
                                        <span class="uc-hint">
                                            <?php echo (int) $n; ?> <?php echo esc_html( 1 === $n ? 'event names' : 'events name' ); ?>
                                            it. They stay exactly as they are, without an organizer.
                                        </span>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </details>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php
        $this->chrome_close();
    }

    /**
     * The calendar's images, tagged by series.
     *
     * WHY IT EXISTS. Contributors and editors never see wp-admin, so the
     * WordPress media library is not available to most of the people who
     * maintain this calendar. An organizer who wants to know what pictures
     * already exist for their programme had nowhere to look at all, and the
     * only route to an image was the picker inside an event, which shows the
     * whole folder and cannot say which programme anything belongs to.
     *
     * THREE THINGS, GATED SEPARATELY. Looking is everybody with caladmin
     * access. Tagging is admins and editors, because organising a library and
     * adding to it are different jobs and an editor who can tidy is worth
     * having. Uploading is admins. See SFAF_Media for the three answers; this
     * screen asks them rather than deciding anything itself.
     *
     * THE FILTER THAT MATTERS MOST IS UNTAGGED. A library gets organised by
     * somebody being shown what has not been done yet, and a filter that can
     * only show what is already sorted is a filter for a library that is
     * already sorted.
     *
     * @param WP_User $user
     */
    private function render_media( $user ) {
        $can_tag    = SFAF_Media::can_tag( $user );
        $can_upload = SFAF_Media::can_upload( $user );

        /* The controls, read from the URL and validated against the one list
         * each of them has. An unknown value is not an error page. */
        $raw      = isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '';
        $untagged = ( SFAF_Media::UNTAGGED === $raw );
        $tag      = ( ! $untagged && '' !== $raw ) ? (int) $raw : 0;
        $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

        $series = SFAF_Series::all();
        if ( $tag ) {
            $known = false;
            foreach ( $series as $term ) {
                if ( (int) $term->term_id === $tag ) {
                    $known = true;
                    break;
                }
            }
            if ( ! $known ) {
                $tag = 0;
            }
        }

        $found = SFAF_Media::pictures( array(
            'series'   => $tag,
            'untagged' => $untagged,
            'paged'    => $paged,
        ) );
        $rows = SFAF_Media::rows( $found['ids'] );

        $this->chrome_open( $user, 'media' );
        ?>
        <div class="uc-page-head">
            <h1>Images</h1>
        </div>

        <?php $this->media_notice(); ?>

        <?php
        /*
         * WHAT THIS SCREEN IS, IN ONE SENTENCE, because the answer to "why are
         * only some of the site's images here" is a rule somebody would
         * otherwise have to work out from what is missing.
         */
        ?>
        <?php
        /*
         * WHY SOME CARDS SHOW A FILE NAME (3.78.0), SAID ON THE SCREEN.
         *
         * A card reading `dsc_0043.jpg` beside one reading "Cycle To Zero"
         * looks like a fault, and was reported as one twice. It is not: a
         * picture uploaded without a title gets one from WordPress made out of
         * the file, which is not a name anybody chose, so it is refused and the
         * file name shows instead.
         *
         * THE SENTENCE POINTS AT THE REMEDY RATHER THAN EXPLAINING THE RULE.
         * Nobody needs to know about looks_like_a_filename(); they need to know
         * that the box under the picture is where a name goes. One line, in the
         * place somebody is standing when they wonder.
         */
        ?>
        <p class="uc-view-hint">
            The pictures in the calendar folder. Tag one with a series to make it easy to find later.
            <?php if ( $can_tag ) : ?>
                A picture showing its file name has no name yet; type one in the box under it.
            <?php endif; ?>
        </p>

        <form method="get" action="<?php echo esc_url( $this->url( 'media' ) ); ?>" class="uc-filters-bar">
            <label class="uc-field uc-media-filter">
                <span class="uc-visually-hidden">Show</span>
                <select name="tag">
                    <option value="">All images</option>
                    <option value="<?php echo esc_attr( SFAF_Media::UNTAGGED ); ?>" <?php selected( $untagged ); ?>>Untagged</option>
                    <?php foreach ( $series as $term ) : ?>
                        <option value="<?php echo (int) $term->term_id; ?>" <?php selected( $tag, (int) $term->term_id ); ?>>
                            <?php echo esc_html( $term->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="uc-btn" type="submit">Show</button>
        </form>

        <?php if ( $can_upload ) : ?>
            <?php
            /*
             * UPLOAD, ADMINS ONLY, AND IT LANDS IN THE FOLDER.
             *
             * SFAF_Media_Folder::upload_to_folder() reads the flag off the
             * request, so the hidden field below is what sends the file to
             * uploads/calendar/ rather than into this month's directory.
             * Without it the file would be invisible to every picker on the
             * site, which is the state this screen exists to prevent.
             */
            ?>
            <?php
            /*
             * FOLDED, AND SHUT (3.78.0). It was a full card with a padded field
             * row sitting above a grid of tight cards, which is two densities
             * arguing on one screen. Adding a picture is something an admin
             * does occasionally; looking at the library is what everybody does
             * every time, so the occasional one should not be the first thing
             * on the screen taking the most space.
             *
             * Native <details>, like the Series and Categories lists and the
             * picture chooser, so it works with nothing running.
             */
            ?>
            <div class="uc-card uc-media-upload">
                <details>
                <summary class="uc-card-head uc-list-fold-head">
                    <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '16px' ) ); ?></span>
                    <h2>Add an image</h2>
                </summary>
                <form method="post" action="<?php echo esc_url( $this->url( 'media' ) ); ?>"
                      enctype="multipart/form-data" class="uc-form">
                    <input type="hidden" name="uc_action" value="media_upload" />
                    <input type="hidden" name="<?php echo esc_attr( SFAF_Media_Folder::FLAG ); ?>" value="1" />
                    <?php wp_nonce_field( 'uc_portal_media_upload', 'uc_nonce' ); ?>
                    <div class="uc-field-row">
                        <label class="uc-field">
                            <span class="uc-field-label">File</span>
                            <input type="file" name="uc_media" accept="image/jpeg,image/png,image/gif,image/webp" required />
                            <span class="uc-hint">
                                JPEG, PNG, GIF or WebP, at least <?php echo (int) SFAF_Uploads::MIN_WIDTH; ?> pixels wide.
                                Landscape works best: an event card crops to 16:9.
                            </span>
                        </label>
                        <label class="uc-field">
                            <span class="uc-field-label">Series <span class="uc-muted">(optional)</span></span>
                            <select name="term_id">
                                <option value="0">No series</option>
                                <?php foreach ( $series as $term ) : ?>
                                    <option value="<?php echo (int) $term->term_id; ?>"><?php echo esc_html( $term->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="uc-form-actions">
                        <button type="submit" class="uc-btn uc-btn-primary">Upload</button>
                    </div>
                </form>
                </details>
            </div>
        <?php endif; ?>

        <div class="uc-card uc-media-library">
            <div class="uc-card-head">
                <h2><?php echo (int) $found['total']; ?> <?php echo esc_html( 1 === (int) $found['total'] ? 'image' : 'images' ); ?></h2>
            </div>

            <?php if ( $can_tag && ! empty( $rows ) && ! empty( $series ) ) : ?>
                <?php
                /*
                 * THE SAME TICK PATTERN THE SCHEDULE AND THE EVENTS LIST USE,
                 * down to the attribute names, because initTickPickers() in
                 * portal.js drives all three. The boxes are in the grid and the
                 * form is above it, so they associate by form= rather than by
                 * being descendants: a card carries its own form for the
                 * per-image control, and forms cannot nest.
                 */
                ?>
                <form method="post" action="<?php echo esc_url( $this->url( 'media' ) ); ?>"
                      class="uc-bulk-cat" id="uc-bulk-tag" data-uc-tick-picker>
                    <input type="hidden" name="uc_action" value="media_tag" />
                    <input type="hidden" name="uc_media_tick_present" value="1" />
                    <?php wp_nonce_field( 'uc_portal_media_tag', 'uc_nonce' ); ?>

                    <label class="uc-field uc-bulk-cat-pick">
                        <span class="uc-field-label">Add a series to the ticked images</span>
                        <select name="term_id" required>
                            <option value="">Choose a series</option>
                            <?php foreach ( $series as $term ) : ?>
                                <option value="<?php echo (int) $term->term_id; ?>"><?php echo esc_html( $term->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="uc-bulk-cat-go">
                        <label class="uc-tick-all uc-check" hidden data-uc-tick-all-row>
                            <input type="checkbox" data-uc-tick-all form="uc-bulk-tag" />
                            Select every image on this page
                        </label>
                        <button type="submit" class="uc-btn uc-btn-sm uc-btn-primary" data-uc-tick-submit
                                data-uc-tick-word="images" data-uc-tick-word-one="image">
                            Tag <span data-uc-tick-count>0</span>
                            <span data-uc-tick-noun>images</span>
                        </button>
                        <span class="uc-hint">Adds it. Any series already on an image stays.</span>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ( empty( $rows ) ) : ?>
                <p class="uc-empty"><?php
                    echo $untagged
                        ? 'Every image in the folder carries a series.'
                        : 'No images here yet.';
                ?></p>
            <?php else : ?>
                <ul class="uc-media-grid">
                    <?php foreach ( $rows as $row ) : ?>
                        <li class="uc-media-item">
                            <?php if ( $can_tag ) : ?>
                                <label class="uc-media-tick">
                                    <input type="checkbox" name="ids[]" value="<?php echo (int) $row['id']; ?>"
                                           form="uc-bulk-tag" data-uc-tick-one />
                                    <span class="uc-visually-hidden">Select <?php
                                        echo esc_attr( '' !== $row['title'] ? $row['title'] : $row['file'] );
                                    ?></span>
                                </label>
                            <?php endif; ?>

                            <img class="uc-media-thumb" src="<?php echo esc_url( $row['thumb'] ); ?>" alt="" loading="lazy" />

                            <?php if ( ! empty( $row['tags'] ) ) : ?>
                                <p class="uc-media-tags">
                                    <?php foreach ( $row['tags'] as $term ) : ?>
                                        <span class="uc-media-tag"><?php echo esc_html( $term->name ); ?><?php
                                        if ( $can_tag ) : ?>
                                            <?php
                                            /*
                                             * TAKING ONE OFF KEEPS ITS OWN x,
                                             * and it is the one control on this
                                             * card that is not part of the Save
                                             * below. It is an undo, it is per
                                             * image on purpose, and it must not
                                             * wait for a press it has nothing
                                             * to do with. There is no bulk
                                             * untag: that is a way to lose an
                                             * afternoon's work in one press.
                                             */
                                            ?>
                                            <button type="submit" class="uc-media-tag-off"
                                                    form="uc-untag-<?php echo (int) $row['id']; ?>-<?php echo (int) $term->term_id; ?>"
                                                    title="Take <?php echo esc_attr( $term->name ); ?> off this image">
                                                <span class="uc-visually-hidden">Take <?php echo esc_html( $term->name ); ?> off this image</span>
                                                <?php echo sfaf_icon( 'x', array( 'size' => '11px' ) ); ?>
                                            </button>
                                        <?php endif; ?></span>
                                    <?php endforeach; ?>
                                </p>
                            <?php endif; ?>

                            <?php
                            /*
                             * ONE FORM PER CARD, AND IT WAS TWO (3.78.0).
                             *
                             * A card held a name box with its own Save, a
                             * series dropdown with its own Add, a tick, a
                             * thumbnail and the tag chips: five controls and
                             * two submit buttons, in a column 150px wide, six
                             * across. The dropdown clipped after the word
                             * "Add", so the one thing it exists to show, a
                             * series name, was the thing it could not.
                             *
                             * NAMING A PICTURE AND FILING IT ARE THE SAME ACT
                             * AT THE SAME MOMENT, so one Save does both: the
                             * name is replaced and the series, if one is
                             * chosen, is ADDED. Two submits per card and thirty
                             * on a screen was the density, rather than the
                             * wording of any one of them.
                             *
                             * BOTH FIELDS ARE LABELLED NOW. They were
                             * placeholder-only with a clipped span for a
                             * screen reader, which is the pattern that reads as
                             * a wall of boxes: a card wide enough to carry a
                             * label is a card somebody can read without
                             * guessing.
                             */
                            ?>
                            <?php if ( $can_tag ) : ?>
                                <form method="post" action="<?php echo esc_url( $this->url( 'media' ) ); ?>" class="uc-media-edit">
                                    <input type="hidden" name="uc_action" value="media_save" />
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
                                    <?php wp_nonce_field( 'uc_portal_media_save', 'uc_nonce' ); ?>

                                    <label class="uc-field">
                                        <span class="uc-field-label">Name<?php
                                            /* ONLY WHERE IT IS TRUE, which is what keeps it from
                                             * being noise on thirty cards. A named picture says
                                             * nothing; an unnamed one says what the box is for,
                                             * beside the box. */
                                            if ( '' === $row['title'] ) :
                                            ?><span class="uc-media-unnamed">no name yet</span><?php
                                            endif;
                                        ?></span>
                                        <input type="text" name="title" maxlength="120"
                                               value="<?php echo esc_attr( $row['title'] ); ?>"
                                               placeholder="<?php echo esc_attr( $row['file'] ); ?>" />
                                    </label>

                                    <?php if ( ! empty( $series ) ) : ?>
                                        <label class="uc-field">
                                            <span class="uc-field-label">Add a series</span>
                                            <select name="term_id">
                                                <option value="">None</option>
                                                <?php foreach ( $series as $term ) : ?>
                                                    <option value="<?php echo (int) $term->term_id; ?>"><?php echo esc_html( $term->name ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    <?php endif; ?>

                                    <div class="uc-media-edit-go">
                                        <button type="submit" class="uc-btn uc-btn-sm">Save</button>
                                    </div>
                                </form>
                            <?php else : ?>
                                <p class="uc-media-name"><?php
                                    echo esc_html( '' !== $row['title'] ? $row['title'] : $row['file'] );
                                ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php
                /* The untag forms, outside the grid, because a form inside a
                 * list item that already holds one would nest. Each button
                 * above names the one it posts. */
                ?>
                <?php if ( $can_tag ) : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php foreach ( $row['tags'] as $term ) : ?>
                            <form method="post" action="<?php echo esc_url( $this->url( 'media' ) ); ?>"
                                  id="uc-untag-<?php echo (int) $row['id']; ?>-<?php echo (int) $term->term_id; ?>" hidden>
                                <input type="hidden" name="uc_action" value="media_untag" />
                                <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
                                <input type="hidden" name="term_id" value="<?php echo (int) $term->term_id; ?>" />
                                <?php wp_nonce_field( 'uc_portal_media_untag', 'uc_nonce' ); ?>
                            </form>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php $this->media_pagination( $paged, (int) $found['pages'], $raw ); ?>
            <?php endif; ?>
        </div>

        <?php
        $this->chrome_close();
    }

    /**
     * Put a file in the calendar folder, from caladmin.
     *
     * THE SAME INSPECTION THE PUBLIC FORMS GET, AND A DIFFERENT DESTINATION.
     * SFAF_Uploads::inspect() is the guard between a form and the disk: is
     * there a file, did PHP finish it, is it really an upload, is it small
     * enough, do two readers agree it is an image, and is it wide enough for a
     * card. None of that is weaker because an administrator pressed the button,
     * so none of it is skipped. What differs is where the file lands, what it
     * is called and who it belongs to.
     *
     * THE FOLDER COMES FROM THE FLAG IN THE FORM. SFAF_Media_Folder filters
     * upload_dir when that flag is on the request, which is the same route the
     * wp.media picker's own uploads take. So there is one rule for where a
     * calendar image goes and it is not repeated here.
     *
     * THE SUBMITTED NAME IS KEPT, and that is the opposite of what the public
     * handler does. A stranger's file name is untrusted and is discarded; a
     * name Mark typed is the thing the picker's search matches and the thing
     * that tells two photographs of the same event apart at 64px. sanitize_
     * file_name() is what makes keeping it safe.
     *
     * @param WP_User $user
     */
    private function handle_media_upload( $user ) {
        $seen = SFAF_Uploads::inspect( 'uc_media' );
        if ( ! $seen['ok'] ) {
            $this->redirect( 'media', array(
                'msg' => 'upload_failed',
                'why' => ( '' !== $seen['error'] ) ? $seen['error'] : 'Choose a file first.',
            ) );
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        /*
         * post_parent 0: a calendar image belongs to the folder and is used by
         * however many events want it. Attaching it to one would make it look
         * like that event's own file on every screen WordPress draws.
         */
        $id = media_handle_upload( 'uc_media', 0, array(), array( 'test_form' => false ) );

        if ( is_wp_error( $id ) ) {
            $this->redirect( 'media', array(
                'msg' => 'upload_failed',
                'why' => $id->get_error_message(),
            ) );
        }
        $id = (int) $id;

        /*
         * AND IT HAS TO BE IN THE FOLDER, CHECKED RATHER THAN ASSUMED. If the
         * upload_dir filter did not fire, the file is in this month's directory
         * and invisible to every picker on the site, which is exactly the state
         * this screen exists to prevent. Better to say so than to leave
         * somebody hunting for an image that uploaded successfully and cannot
         * be found.
         */
        if ( ! SFAF_Media_Folder::holds( $id ) ) {
            $this->redirect( 'media', array(
                'msg' => 'upload_failed',
                'why' => 'That went into the general media library rather than the calendar folder, so no picker will offer it. Tell whoever looks after the site.',
            ) );
        }

        $term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
        if ( $term_id ) {
            SFAF_Media::add_tag( array( $id ), $term_id );
        }

        $this->redirect( 'media', array( 'msg' => 'uploaded' ) );
    }

    /** What the last action did, said once, at the top of the screen. */
    private function media_notice() {
        $msg = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
        if ( '' === $msg ) {
            return;
        }
        $n    = isset( $_GET['n'] ) ? (int) $_GET['n'] : 0;
        $said = '';
        /* .uc-flash is the green one and is this portal's message band.
         * A failure takes .uc-flash-error, which is the same band in red. */
        $tone = '';
        switch ( $msg ) {
            case 'tagged':
                $said = sprintf( '%d %s tagged.', $n, _n( 'image', 'images', $n ) );
                break;
            case 'tag_none':
                $said = 'Nothing was ticked, so nothing was tagged.';
                $tone = ' uc-flash-warn';
                break;
            case 'tag_failed':
                $said = 'That series could not be found, so nothing was tagged.';
                $tone = ' uc-flash-error';
                break;
            case 'renamed':
                $said = 'Name saved. Every picker shows it now.';
                break;
            case 'saved_tagged':
                $said = 'Name saved and the series added.';
                break;
            case 'untagged':
                $said = 'Series taken off that image. The image itself is untouched.';
                break;
            case 'uploaded':
                $said = 'Uploaded, and it is in the folder every picker offers.';
                break;
            case 'upload_failed':
                $said = isset( $_GET['why'] ) ? sanitize_text_field( wp_unslash( $_GET['why'] ) ) : 'That file could not be uploaded.';
                $tone = ' uc-flash-error';
                break;
        }
        if ( '' === $said ) {
            return;
        }
        printf( '<div class="uc-flash%s">%s</div>', esc_attr( $tone ), esc_html( $said ) );
    }

    /**
     * Pages of images.
     *
     * ITS OWN PAGER RATHER THAN THE EVENTS ONE, which carries a sort and four
     * filters this screen does not have. Two links and a position is the whole
     * of what a grid needs.
     *
     * @param int    $paged
     * @param int    $pages
     * @param string $tag   The filter as it arrived, so it rides the links.
     */
    private function media_pagination( $paged, $pages, $tag ) {
        if ( $pages < 2 ) {
            return;
        }
        $base = $this->url( 'media' );
        $args = ( '' !== $tag ) ? array( 'tag' => $tag ) : array();
        ?>
        <?php // The events list's own pagination shell, so a pager reads the
              // same on both screens and neither needs a rule of its own. ?>
        <div class="uc-list-pagination">
            <p class="uc-hint uc-list-total">Page <?php echo (int) $paged; ?> of <?php echo (int) $pages; ?></p>
            <div class="uc-list-pages">
                <?php if ( $paged > 1 ) : ?>
                    <a class="uc-btn uc-btn-sm" rel="prev" href="<?php
                        echo esc_url( add_query_arg( array_merge( $args, array( 'paged' => $paged - 1 ) ), $base ) );
                    ?>">&larr; Newer</a>
                <?php endif; ?>
                <?php if ( $paged < $pages ) : ?>
                    <a class="uc-btn uc-btn-sm" rel="next" href="<?php
                        echo esc_url( add_query_arg( array_merge( $args, array( 'paged' => $paged + 1 ) ), $base ) );
                    ?>">Older &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

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

            <details class="uc-venue-new" data-uc-disclosure>
                <summary class="uc-add-toggle" aria-expanded="false">
                    <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '18px' ) ); ?></span>
                    <span>Add a venue</span>
                </summary>
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
     * A Y-m-d carried back on the redirect, rendered AP style.
     *
     * THROUGH THE ONE FORMATTER, like every other date this plugin prints. A
     * confirmation is user-facing copy and the AP rules apply to it exactly as
     * they apply to the calendar.
     *
     * SHAPE-CHECKED BEFORE IT IS FORMATTED, because this is a query string and
     * anything at all can be in it. An unusable value renders as nothing rather
     * than as today's date, so a tampered URL cannot make the screen assert a
     * date that was never written.
     *
     * @param string $arg
     * @return string '' when there is no usable date.
     */
    private function schedule_date_arg( $arg ) {
        if ( ! isset( $_GET[ $arg ] ) ) {
            return '';
        }
        $raw = sanitize_text_field( wp_unslash( $_GET[ $arg ] ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
            return '';
        }
        return sfaf_ap_date( $raw, 'full' );
    }

    /**
     * "The group now runs Aug 18 through Nov 3.", or nothing.
     *
     * Only a cadence change carries these two, so a save that only touched the
     * times says nothing extra rather than repeating dates that did not move.
     *
     * @return string
     */
    private function schedule_span_phrase() {
        $first = $this->schedule_date_arg( 'first' );
        $last  = $this->schedule_date_arg( 'last' );
        if ( '' === $first || '' === $last ) {
            return '';
        }
        if ( $first === $last ) {
            return sprintf( 'The one upcoming date is now %s.', $first );
        }
        return sprintf( 'Upcoming dates now run %s through %s.', $first, $last );
    }

    /**
     * Read the pattern control back into a stored pattern string.
     *
     * THE CHOSEN FREQUENCY DECIDES WHICH FIELDS ARE READ, and the others are
     * not looked at. Every row of the control posts, always, because there is
     * no script hiding any of them; honouring the weekly interval on a form
     * submitted as Monthly would be honouring a value nobody chose. Same rule as
     * recurrence_from_post() and as the location picker.
     *
     * AN UNANSWERABLE FORM RETURNS '', AND '' MEANS "DO NOT MOVE ANYTHING".
     * Weekly with no day ticked is the case that matters: it is a form in the
     * middle of being filled in, not an instruction, and the alternative to
     * refusing it is inventing a weekday and moving a term's programming onto
     * it. The times on the same form are a separate instruction and still apply.
     *
     * @return string A pattern in stored form, or '' when there is none to read.
     */
    private function schedule_pattern_from_fields() {
        $freq = isset( $_POST['sp_freq'] ) ? sanitize_key( wp_unslash( $_POST['sp_freq'] ) ) : '';

        $interval = function ( $key ) {
            $n = isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 1;
            return ( $n >= 1 ) ? $n : 1;
        };

        switch ( $freq ) {
            case 'daily':
                return SFAF_Recurrence::pattern_string( array(
                    'type'     => 'daily',
                    'interval' => $interval( 'sp_daily_interval' ),
                ) );

            case 'weekly':
                $days = array();
                if ( isset( $_POST['sp_days'] ) && is_array( $_POST['sp_days'] ) ) {
                    foreach ( wp_unslash( $_POST['sp_days'] ) as $d ) {
                        $d = (int) $d;
                        if ( $d >= 0 && $d <= 6 ) {
                            $days[] = $d;
                        }
                    }
                }
                if ( empty( $days ) ) {
                    return '';
                }
                return SFAF_Recurrence::pattern_string( array(
                    'type'     => 'weekly',
                    'interval' => $interval( 'sp_weekly_interval' ),
                    'days'     => $days,
                ) );

            case 'monthly':
                return SFAF_Recurrence::pattern_string( array(
                    'type'     => 'monthly',
                    'interval' => $interval( 'sp_monthly_interval' ),
                ) );

            case 'monthly_nth':
                $nth = isset( $_POST['sp_nth'] ) ? (int) $_POST['sp_nth'] : 0;
                $dow = isset( $_POST['sp_nth_dow'] ) ? (int) $_POST['sp_nth_dow'] : -1;
                if ( 0 === $nth || $dow < 0 || $dow > 6 ) {
                    return '';
                }
                return SFAF_Recurrence::pattern_string( array(
                    'type' => 'monthly_nth',
                    'nth'  => $nth,
                    'dow'  => $dow,
                ) );
        }

        return '';
    }

    /**
     * Change the cadence, the times, or both, across a group's upcoming dates.
     *
     * THE GROUP IS RE-DERIVED FROM THE SERIES, never taken from the form. The
     * form carries a series id and nothing else that decides what is written, so
     * a tampered POST can only ever aim this at a series the person is already
     * allowed to edit, at the same set of upcoming events the screen showed
     * them.
     *
     * ANY SUBSET OF THE CONTROL IS A VALID EDIT, and it needs no "which of these
     * am I changing" question to be true. Every field is prefilled with what the
     * group says today, so submitting the form having touched only the times
     * produces a pattern identical to the stored one, and identical patterns do
     * not move dates. First Monday to second Monday, 5-6pm to 6-7pm, or both at
     * once, are the same operation with different fields touched.
     *
     * THE COMPARISON IS MADE ON CANONICAL FORMS. 'weekly' and 'weekly:1:3' are
     * one schedule for a Wednesday group, and a form that prefills the day
     * circles from the anchor returns the explicit spelling. Comparing the two
     * as text would report a change on every save. See canonical_pattern().
     *
     * A CADENCE CHANGE MOVES PATTERN DATES; A TIME CHANGE MOVES EVERYTHING.
     * repattern_group() reports how many extra dates it left alone, and that
     * number is carried into the confirmation message rather than being
     * recomputed here: the screen warned about it before the button was pressed
     * and has to account for it afterwards, or the manager is left checking the
     * list to find out whether the warning happened.
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

        $current = SFAF_Recurrence::pattern_of( $ids[0] );
        $anchor  = (string) get_post_meta( $ids[0], '_uc_event_date', true );
        $moved   = 0;
        $left    = 0;
        $recast  = false;
        $first   = '';
        $last    = '';

        /*
         * A CUSTOM GROUP IS NOT OFFERED A CADENCE AND IS NOT GIVEN ONE HERE.
         * Its dates were chosen one at a time; laying them out on a weekly
         * pattern would throw away the exact thing somebody picked. The control
         * is not rendered for it and this is what makes that a rule.
         */
        if ( SFAF_Recurrence::has_cadence( $current ) ) {
            $wanted = $this->schedule_pattern_from_fields();
            if ( '' !== $wanted && $wanted !== SFAF_Recurrence::canonical_pattern( $current, $anchor ) ) {
                $result = SFAF_Recurrence::repattern_group( $group, $wanted );
                if ( $result['refused'] ) {
                    $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_pattern_refused' ) );
                }
                $recast = ( '' !== $result['pattern'] );
                $moved  = (int) $result['moved'];
                $left   = $recast ? (int) $result['left'] : 0;
                $first  = (string) $result['first'];
                $last   = (string) $result['last'];
            }
        }

        $start   = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
        $end     = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
        $retimed = ( '' !== $start ) ? SFAF_Recurrence::retime_group( $group, $start, $end ) : 0;

        /*
         * "0 OCCURRENCES CHANGED" AFTER A REAL CADENCE CHANGE IS NOT NOTHING.
         * Moving a weekly Wednesday group to "every week on Wednesday and
         * Friday" would be refused for a different reason, but moving one from
         * 'weekly' to 'weekly:1:3' on a Wednesday group lands every date on the
         * day it was already on. Nothing moved and the group is genuinely
         * recorded differently, so $recast rather than $moved decides whether
         * this reports a change.
         */
        $written = max( $moved, $retimed );
        $changed = ( $written > 0 || $recast );

        $this->redirect( 'series/edit/' . $term_id, array(
            'msg'     => $changed ? 'schedule_updated' : 'schedule_unchanged',
            'written' => $written,
            'left'    => $left,
            'first'   => $first,
            'last'    => $last,
        ) );
    }

    /**
     * Carry the existing pattern further into the future.
     *
     * NOTHING ABOUT THE PATTERN CHANGES AND NOTHING ALREADY THERE IS TOUCHED.
     * This is a pure append: the dates the cadence would have produced between
     * the last one that exists and the new end date. Every rule that makes that
     * safe lives in SFAF_Recurrence::extend_group(), including the one that
     * matters most, which is that a date somebody REMOVED is not put back.
     *
     * THE REASON CODE IS TRANSLATED HERE, NOT THERE. extend_group() is written
     * to be callable by a scheduled top-up that has nobody to talk to, so it
     * returns a reason rather than a sentence, and this is the screen that turns
     * one into the other.
     */
    /**
     * Publish this series' upcoming drafts.
     *
     * NO NONCE CHECK HERE, and that is the file's arrangement rather than an
     * omission: the dispatcher verifies `uc_portal_ . $action` for every posted
     * action before the switch, and this form emits
     * `uc_portal_schedule_publish` to match. can_view_all() is asked by the case
     * that calls this, as it is for the other four schedule actions.
     */
    /**
     * Publish the ticked drafts on the events list (3.79.0).
     *
     * THE SAME FIVE RULES AS THE SCHEDULE SCREEN, AND THE SAME METHOD ASKS
     * THEM. SFAF_Series::publish_skip_reason() is called per id here exactly as
     * publishable() calls it there. Nothing about the rule is restated, relaxed
     * or re-implemented, because a second copy is how two screens come to
     * disagree about what may go on the public calendar.
     *
     * WHY THIS IS NOT SFAF_Series::publish_drafts(). That method takes a term
     * id and re-derives its own set from one series. This list is not a series:
     * it is a filtered, paged view that can hold rows from thirty-two of them
     * and rows from none. So the shape is the same and the source of the ids is
     * different, which is why the rule lives in the per-event method that both
     * can call rather than in either caller.
     *
     * THE TICKS NARROW AND NEVER WIDEN, twice over. Permission is re-asked with
     * can_edit_event() per id, and eligibility is re-asked with
     * publish_skip_reason() per id, both AFTER the form has spoken. A POST is a
     * request anybody can construct, and this one names ids and reaches the
     * public calendar, so neither question is left to the render.
     *
     * ONE $today FOR THE RUN, so a press that straddles midnight judges every
     * id against one date.
     *
     * wp_update_post(), NOT A DIRECT STATUS WRITE, so save_post fires and every
     * listener that cares about an event becoming public gets its turn. Same as
     * publish_drafts().
     *
     * @param WP_User $user
     * @param int[]   $wanted Ids the manager ticked.
     */
    private function bulk_publish_from_post( $user, $wanted ) {
        if ( empty( $wanted ) ) {
            $this->redirect( 'events', array( 'msg' => 'bulk_pub_none' ) );
        }

        $today   = current_time( 'Y-m-d' );
        $did     = 0;
        $failed  = 0;
        $refused = 0;
        $skipped = 0;

        foreach ( array_unique( $wanted ) as $pub_id ) {
            $pub_id   = (int) $pub_id;
            $pub_post = get_post( $pub_id );
            if ( ! $pub_post || 'uc_event' !== $pub_post->post_type ) {
                continue;
            }
            if ( ! $this->can_edit_event( $user, $pub_post ) ) {
                $refused++;
                continue;
            }
            if ( '' !== SFAF_Series::publish_skip_reason( $pub_id, $today ) ) {
                $skipped++;
                continue;
            }

            $res = wp_update_post( array( 'ID' => $pub_id, 'post_status' => 'publish' ), true );
            if ( is_wp_error( $res ) || ! $res ) {
                $failed++;
            } else {
                $did++;
            }
        }

        if ( 0 === $did && 0 === $failed ) {
            $this->redirect( 'events', array( 'msg' => 'bulk_pub_none' ) );
        }

        $this->redirect( 'events', array(
            'msg'     => 'bulk_pub_done',
            'did'     => $did,
            'failed'  => $failed,
            'refused' => $refused,
            'skipped' => $skipped,
        ) );
    }

    private function schedule_publish_from_post() {
        $term_id = isset( $_POST['series_id'] ) ? intval( $_POST['series_id'] ) : 0;
        if ( ! $term_id || ! SFAF_Series::exists( $term_id ) ) {
            $this->redirect( 'series', array( 'msg' => 'series_failed' ) );
        }

        /*
         * WHICH ONES, AND THE MARKER IS WHAT MAKES "NONE" SAYABLE (3.72.0).
         *
         * publish_ids[] arrives when the picker is on the form and a box is
         * ticked. It does NOT arrive when every box is unticked, and it does
         * not arrive from a form with no picker at all, and those two mean
         * opposite things. uc_publish_picker_present tells them apart: with the
         * marker, an absent list is an empty selection and nothing is
         * published; without it, null is passed and publish_drafts() does what
         * the button has always done.
         *
         * NOTHING IS TRUSTED FROM HERE. These ids are intersected with what
         * publishable() says is ready, so this is a narrowing and never a way
         * in. See SFAF_Series::publish_drafts().
         */
        $only = null;
        if ( isset( $_POST['uc_publish_picker_present'] ) ) {
            $only = isset( $_POST['publish_ids'] )
                ? array_map( 'intval', (array) wp_unslash( $_POST['publish_ids'] ) )
                : array();
        }

        $result = SFAF_Series::publish_drafts( $term_id, $only );

        if ( 0 === $result['published'] && 0 === $result['failed'] ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_publish_none' ) );
        }

        $this->redirect( 'series/edit/' . $term_id, array(
            'msg'    => 'schedule_published',
            'made'   => $result['published'],
            'failed' => $result['failed'],
        ) );
    }

    private function schedule_extend_from_post() {
        $term_id = isset( $_POST['series_id'] ) ? intval( $_POST['series_id'] ) : 0;
        if ( ! $term_id || ! SFAF_Series::exists( $term_id ) ) {
            $this->redirect( 'series', array( 'msg' => 'series_failed' ) );
        }

        $group = SFAF_Series::recurrence_group( $term_id );
        if ( '' === $group ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_no_group' ) );
        }

        $all = SFAF_Recurrence::all_in_group( $group );
        if ( ! empty( $all ) ) {
            $prov = SFAF_Sources::provenance( $all[0] );
            if ( '' !== $prov['source'] ) {
                $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_imported' ) );
            }
        }

        $until  = isset( $_POST['extend_until'] ) ? sanitize_text_field( wp_unslash( $_POST['extend_until'] ) ) : '';
        $result = SFAF_Recurrence::extend_group( $group, $until );

        if ( empty( $result['created'] ) ) {
            $said = array(
                'bad_date'        => 'schedule_extend_bad_date',
                'not_further'     => 'schedule_extend_not_further',
                'no_cadence'      => 'schedule_extend_no_cadence',
                'nothing_missing' => 'schedule_extend_nothing',
            );
            $key = isset( $said[ $result['reason'] ] ) ? $said[ $result['reason'] ] : 'schedule_extend_failed';
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => $key, 'from' => (string) $result['from'] ) );
        }

        $this->redirect( 'series/edit/' . $term_id, array(
            'msg'     => 'schedule_extended',
            'made'    => count( $result['created'] ),
            'from'    => (string) $result['from'],
            'through' => (string) end( $result['dates'] ),
        ) );
    }

    /**
     * Add one date to a series, by copying the event onto it.
     *
     * THERE IS NO CHOICE TO MAKE HERE ANY MORE, AND THAT IS THE CHANGE.
     * This used to carry a checkbox reading "Keep this date out of the group",
     * with four sentences under it about recurrence groups and extra-date
     * marking. It was asking a manager to understand the internals in order to
     * add a date to a Tuesday class, and the two answers were not equally
     * useful: one of them was right almost every time.
     *
     * SO THE RIGHT ANSWER IS THE ONLY ANSWER. A date added here JOINS the group,
     * so a time change reaches it like any other date, AND is marked as an extra
     * date, so a cadence change leaves it alone. That is exactly what the
     * unticked box did. The genuinely different case, an event on another date
     * with another location and another description, is not a variant of this
     * one and is not offered as a tickbox on it: it is a second route on the
     * screen, which goes to the event editor with this series already chosen.
     *
     * A TITLE OVERRIDE IS THE ONE FIELD A COPY MAY DIFFER IN. "Annual picnic"
     * or "Guest speaker" on one date of a weekly group is a real and common
     * need, and it is not a reason to rebuild the event from scratch.
     */
    private function schedule_add_date_from_post() {
        $term_id = isset( $_POST['series_id'] ) ? intval( $_POST['series_id'] ) : 0;
        if ( ! $term_id || ! SFAF_Series::exists( $term_id ) ) {
            $this->redirect( 'series', array( 'msg' => 'series_failed' ) );
        }

        /*
         * THE SEED, ASKED FOR RATHER THAN WORKED OUT (3.73.0).
         *
         * This used to compute its own: the next upcoming event in the
         * SERIES, or the most recent past one. The screen that draws the
         * control computed a different one, preferring the next event in the
         * recurrence GROUP, and on a series holding several groups the two
         * disagree. The placeholder showed one event's title and this copied
         * another's details. Four series hold several distinct events.
         *
         * SFAF_Series::seed_for_series() is the one rule now, and the screen
         * asks the same one through seed_from_lists(). Neither has an answer
         * of its own left to drift.
         */
        $seed_id = SFAF_Series::seed_for_series( $term_id );
        if ( ! $seed_id ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_no_seed' ) );
        }

        $prov = SFAF_Sources::provenance( $seed_id );
        if ( '' !== $prov['source'] ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_imported' ) );
        }

        /*
         * NOT INTO THE PAST, AND THE RULE IS HERE AS WELL AS ON THE CONTROL.
         * The date field carries min="today", which is what a manager meets;
         * this is what makes it a rule. A date added behind today lands in the
         * folded-away past section, which carries no controls at all by design,
         * so it could then be neither edited nor removed from this screen.
         */
        $wanted = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
        if ( '' !== $wanted && $wanted < current_time( 'Y-m-d' ) ) {
            $this->redirect( 'series/edit/' . $term_id, array( 'msg' => 'schedule_past' ) );
        }

        $new_id = SFAF_Recurrence::add_occurrence(
            $seed_id,
            $wanted,
            isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '',
            isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '',
            // Always. See the docblock: this route has one behaviour, not two.
            true,
            isset( $_POST['title_override'] ) ? sanitize_text_field( wp_unslash( $_POST['title_override'] ) ) : ''
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
     *
     * FOUR ACTIONS, AND EACH ONE ANSWERS A QUESTION SOMEBODY ARRIVED WITH.
     *
     *   Change the pattern   "it moves to second Tuesdays and starts at 6"
     *   Extend the series    "it runs to December and we need January too"
     *   Add a date           "there is one extra session on the 14th"
     *   Remove a date        "we are closed that week"
     *
     * They are separate because they are separate questions, not because the
     * code is arranged that way. The two that create events are deliberately
     * different shapes: extending is bulk and follows the cadence, adding is one
     * date somebody chose. Offering "add a date" with a count field, or
     * "extend" with a title override, would blur two things a manager keeps
     * apart in their head.
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
         *
         * ---------------------------------------------------------------------
         * AND IT COMES FROM THE GROUP, NOT MERELY FROM THE SERIES (3.64.1).
         *
         * The lists above are TERM-scoped: every event in this series, whatever
         * made it. Everything the seed feeds is GROUP-scoped: the pattern, the
         * cadence controls, the times and the sentence describing the schedule
         * all describe the set of dates one recurrence produced. Reading them
         * off whichever event happens to be soonest silently mixes the two.
         *
         * WHAT THAT LOOKS LIKE WHEN IT GOES WRONG. Assign a one-off event to a
         * series by hand and date it before the next generated occurrence. It
         * sorts first, becomes the seed, and carries no pattern, because nothing
         * generated it. pattern_of() returns '', has_cadence() is then false, and
         * the pattern form stops offering a frequency FOR A SERIES THAT PLAINLY
         * HAS ONE. The sentence at the top of the card says the same wrong thing.
         *
         * IT WAS ALREADY REACHABLE, through the WordPress admin metabox, and
         * restoring the caladmin control in this same release makes it easy. It
         * is display only: schedule_pattern_from_post() reads the pattern and the
         * anchor off upcoming_in_group( $group ), never off this, so nothing has
         * ever been WRITTEN from the wrong event. The screen simply described the
         * schedule as something it is not.
         *
         * THE FALLBACK IS THE OLD BEHAVIOUR, and it is the right one where it
         * applies: a series with no recurrence group at all is a plain container
         * of hand-made dates, and its soonest event is exactly what a new date
         * should be copied from.
         * ---------------------------------------------------------------------
         */
        /* THE SHARED RULE (3.73.0). The reasoning above is unchanged and now
         * lives with it, in SFAF_Series::seed_from_lists(), so the handler
         * behind the button reaches the same answer. It used not to. */
        $seed_id = SFAF_Series::seed_from_lists( $group, $upcoming, $past );

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
        /*
         * THE TWO KINDS OF DATE IN ONE GROUP, counted once and used three
         * times: the hint above the form, the confirmation on the button and
         * the labels on the rows. One call, so the number in the warning and the
         * number of labelled rows in the list cannot disagree.
         */
        $split      = ( '' !== $group ) ? SFAF_Recurrence::split_group( $group ) : array( 'pattern' => array(), 'extra' => array() );
        $n_pattern  = count( $split['pattern'] );
        $n_extra    = count( $split['extra'] );
        /*
         * A CADENCE IS THE THING THE PATTERN CONTROL EDITS, and a custom group
         * has none. Its dates were chosen one at a time and may be a Monday, a
         * Tuesday and a Wednesday; laying them out on a weekly pattern would
         * throw away the exact thing somebody picked. So the frequency control
         * is not rendered for it, the times still are, and the same test gates
         * the write in schedule_pattern_from_post().
         */
        $has_cadence = SFAF_Recurrence::has_cadence( $pattern );

        /*
         * WHAT THE CONTROL IS PREFILLED WITH, AND WHY IT IS RESOLVED RATHER
         * THAN READ. A group stored as bare 'weekly' means "the anchor's own
         * weekday", and a control that showed no day ticked for it would be
         * describing the group as something it is not; a manager changing only
         * the interval would then submit a weekly pattern with no day and be
         * told to pick one. canonical_pattern() resolves both implied values
         * from the anchor and it is the same function the save compares
         * against, so what is shown and what is compared cannot drift.
         */
        $spec = SFAF_Recurrence::parse_pattern( SFAF_Recurrence::canonical_pattern( $pattern, $anchor ) );
        $f_type     = $spec ? $spec['type'] : '';
        $f_interval = $spec ? (int) $spec['interval'] : 1;
        $f_days     = ( $spec && ! empty( $spec['days'] ) ) ? $spec['days'] : array();
        $f_nth      = $spec ? (int) $spec['nth'] : 0;
        $f_ndow     = $spec ? (int) $spec['dow'] : -1;

        $days     = SFAF_Recurrence::weekday_names();
        $abbr     = SFAF_Recurrence::weekday_names( true );
        $cur_dow  = ( null !== SFAF_Recurrence::dow_of( $anchor ) ) ? (int) SFAF_Recurrence::dow_of( $anchor ) : -1;
        if ( empty( $f_days ) && $cur_dow >= 0 ) {
            $f_days = array( $cur_dow );
        }
        if ( $f_ndow < 0 && $cur_dow >= 0 ) {
            $f_ndow = $cur_dow;
        }
        if ( 0 === $f_nth ) {
            $derived = $anchor ? SFAF_Recurrence::nth_weekday_of_month( $anchor ) : null;
            $f_nth   = $derived ? (int) $derived['nth'] : 1;
        }
        $day_num = $anchor ? sfaf_ap_date( $anchor, 'daynum' ) : '';

        /*
         * HOW FAR OUT THE SERIES IS GENERATED, which is what somebody extending
         * it is extending FROM. Named as a date rather than left to be worked
         * out by scrolling to the bottom of the list: the whole question
         * "does this need extending" is answered by this one line.
         */
        $horizon = ( '' !== $group ) ? SFAF_Recurrence::horizon( $group ) : '';
        $back    = 'series/edit/' . $term_id;
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

                    <?php
                    /*
                     * ONE CONTEXT ARRAY, NOT SEVENTEEN ARGUMENTS. Everything
                     * below is derived above, once, so the count in a hint, the
                     * count in a confirmation and the count of labelled rows are
                     * the same count. Passing it as one value is what keeps that
                     * true as the form grows; a positional argument list of this
                     * length is where a pair of them gets swapped.
                     */
                    $ctx = array(
                        'term_id'  => $term_id,
                        'back'     => $back,
                        'in_group' => $in_group,
                        'cadence'  => $has_cadence,
                        'n_pat'    => $n_pattern,
                        'n_extra'  => $n_extra,
                        'start'    => $start,
                        'end'      => $end,
                        'type'     => $f_type,
                        'interval' => $f_interval,
                        'wdays'    => $f_days,
                        'nth'      => $f_nth,
                        'ndow'     => $f_ndow,
                        'names'    => $days,
                        'abbr'     => $abbr,
                        'daynum'   => $day_num,
                        'horizon'  => $horizon,
                    );
                    $this->render_schedule_pattern_form( $ctx );
                    $this->render_schedule_extend_form( $ctx );
                    ?>
                <?php endif; ?>

                <?php $this->render_schedule_publish_form( $term_id ); ?>

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
                            <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '15px' ) ); ?></span>
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
                <?php
                /*
                 * =========================================================
                 * ONE MORE DATE: TWO ROUTES, AND THE QUESTION IS ABOUT THE
                 * EVENT RATHER THAN ABOUT THE SOFTWARE.
                 *
                 * WHAT WENT. A single form with a checkbox reading "Keep this
                 * date out of the group" and four sentences under it about
                 * recurrence groups, bulk edits and extra-date marking. It was
                 * asking somebody adding one session to a Tuesday class to
                 * first understand what a recurrence group is, and the two
                 * answers were not equally likely: almost every date added
                 * here belongs to the programme.
                 *
                 * WHAT REPLACED IT. The two cases that actually differ, told
                 * apart by a fact about the event rather than by a fact about
                 * the data model:
                 *
                 *   the details are already right   -> copy this event onto
                 *                                      another date
                 *   the details genuinely differ    -> make a new event, in
                 *                                      this series
                 *
                 * Nobody has to know what a group is to pick between those.
                 * The behaviour the checkbox was offering is now the behaviour
                 * of the first route and is not a choice: the copy joins the
                 * group, so a time change reaches it, and it is marked as an
                 * extra date, so a cadence change leaves it alone. That is
                 * stated in one sentence rather than four, because it is now a
                 * consequence to know rather than a decision to make.
                 * =========================================================
                 */
                ?>
                <div class="uc-schedule-routes">
                    <h4 class="uc-schedule-head">Add a date</h4>

                    <details class="uc-schedule-add">
                        <?php
                        /*
                         * IT NAMES THE EVENT IT COPIES (3.74.0).
                         *
                         * It read "Use this event's details on another date",
                         * and on a series holding several distinct events
                         * "this event" names nothing the reader can see: four
                         * series are in that position, PROP with four, Coffee
                         * Social with two, Mobile Health Sites with two and the
                         * Strut community events with three.
                         *
                         * THE NAME COMES FROM THE SAME ONE RULE THE BUTTON
                         * USES, which is what 3.73.0 made true.
                         * SFAF_Series::seed_from_lists() decides the seed, the
                         * placeholder below reads it and so does this, so the
                         * label and the copy cannot name two different events.
                         * Before that fix they could, and did.
                         */
                        $seed_name = $seed_id ? trim( (string) get_the_title( $seed_id ) ) : '';
                        ?>
                        <summary><span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '15px' ) ); ?></span><?php
                            echo ( '' !== $seed_name )
                                ? 'Copy &ldquo;' . esc_html( $seed_name ) . '&rdquo; onto another date'
                                : 'Use this event&rsquo;s details on another date';
                        ?></summary>
                        <p class="uc-hint">
                            Copies <?php echo ( '' !== $seed_name ) ? '&ldquo;' . esc_html( $seed_name ) . '&rdquo;' : 'this event'; ?>
                            onto a date you choose: same location, description, times, category,
                            organizer and questions. It starts with nobody registered, because registrations belong to
                            the date they were made for. A time change to the group reaches it; a change to the pattern
                            leaves it where you put it.
                        </p>
                        <form method="post" action="<?php echo esc_url( $this->url( $back ) ); ?>" class="uc-form">
                            <input type="hidden" name="uc_action" value="schedule_add_date" />
                            <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
                            <?php wp_nonce_field( 'uc_portal_schedule_add_date', 'uc_nonce' ); ?>

                            <div class="uc-field-row">
                                <label class="uc-field">
                                    <span class="uc-field-label">Date</span>
                                    <?php // min is today: this route copies an event forward, and a date in the
                                          // past would create a session that never happened. ?>
                                    <input type="date" name="date" min="<?php echo esc_attr( $today ); ?>" required />
                                </label>
                                <label class="uc-field">
                                    <span class="uc-field-label">Start time</span>
                                    <input type="time" name="start_time" value="<?php echo esc_attr( $start ); ?>"<?php echo sfaf_time_step_attr( $start ); ?> />
                                </label>
                                <label class="uc-field">
                                    <span class="uc-field-label">End time</span>
                                    <input type="time" name="end_time" value="<?php echo esc_attr( $end ); ?>"<?php echo sfaf_time_step_attr( $end ); ?> />
                                </label>
                            </div>

                            <label class="uc-field">
                                <span class="uc-field-label">Title for this date <span class="uc-muted">(optional)</span></span>
                                <input type="text" name="title_override" maxlength="120"
                                       placeholder="<?php echo esc_attr( $seed_id ? get_the_title( $seed_id ) : '' ); ?>" />
                                <span class="uc-hint">Leave blank to use the same title as the other dates. Fill it in
                                    to call this one "Annual picnic" or "Guest speaker".</span>
                            </label>

                            <div class="uc-form-actions">
                                <button type="submit" class="uc-btn uc-btn-primary">Add this date</button>
                            </div>
                        </form>
                    </details>

                    <?php
                    /*
                     * A LINK, NOT A FORM, AND THAT IS THE POINT OF IT. This
                     * case needs a location, a description and possibly a
                     * picture, all of which the event editor already asks for
                     * properly. Rebuilding a third of that editor inside a
                     * <details> on this screen would be a second place to
                     * maintain the same fields and a worse place to fill them
                     * in. The series arrives already chosen, which is the only
                     * thing this screen knows that the editor does not.
                     */
                    ?>
                    <p class="uc-schedule-route">
                        <a class="uc-btn" href="<?php echo esc_url( add_query_arg( 'series', (int) $term_id, $this->url( 'events/new' ) ) ); ?>">
                            Create a new event in this series
                        </a>
                        <span class="uc-hint">For a date with different details: another location, another
                            description. It joins the series for browsing and filtering, and is not tied to this
                            schedule, so a change to the pattern or the times does not reach it.</span>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Change the pattern: the whole of it, and any part of it.
     *
     * FIVE THINGS ARE EDITABLE HERE AND THEY USED TO BE TWO. The old form
     * offered a weekday and two times, so a group that had to move from the
     * first Monday of the month to the second could not be moved at all, and
     * one that had to go from weekly to fortnightly had to be rebuilt by hand.
     * What is here now is frequency, interval, weekday, monthly ordinal and the
     * two times, and any subset of them is one edit.
     *
     * NO "WHICH OF THESE AM I CHANGING" QUESTION, because every field arrives
     * holding what the group says today. Touch the times and submit and the
     * pattern reads back identical, so nothing moves; touch the ordinal and the
     * times and both apply. See schedule_pattern_from_post() for how sameness is
     * decided, which is on canonical forms rather than on stored text.
     *
     * EACH FREQUENCY CARRIES ITS OWN PARAMETERS ON ITS OWN ROW, and the row is
     * the radio. That is what lets this work with no script at all: every row is
     * visible, every control is a real input, and the server reads sp_freq and
     * then looks at nothing belonging to the rows that lost. Same rule as the
     * location picker and the create form's repeat control.
     *
     * THE COUNT DOES NOT CHANGE, and the button says the count. Twelve upcoming
     * sessions are twelve upcoming sessions afterwards, on the new cadence.
     * Nothing here creates an event and nothing here deletes one, which is what
     * makes a single number honest on both sides of the press.
     *
     * @param array $ctx See render_schedule().
     */
    private function render_schedule_pattern_form( $ctx ) {
        $n_up    = count( $ctx['in_group'] );
        $n_extra = (int) $ctx['n_extra'];
        $n_pat   = (int) $ctx['n_pat'];
        ?>
        <details class="uc-schedule-edit">
            <summary><span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '15px' ) ); ?></span>Change the pattern</summary>
            <p class="uc-hint">
                Applies to the <strong><?php echo (int) $n_up; ?></strong>
                upcoming <?php echo esc_html( _n( 'occurrence', 'occurrences', $n_up ) ); ?>
                in this group, and creates and deletes nothing: the same dates move.
                Dates that have already been are the record of what happened and are never rewritten.
            </p>

            <?php
            /*
             * WHAT A CADENCE CHANGE WILL NOT TOUCH, SAID BEFORE IT IS PRESSED
             * RATHER THAN AFTER.
             *
             * An extra date was never on the pattern, so there is nothing about
             * it for a pattern edit to recompute and moving it with the rest
             * would land it on a day nobody chose. That is correct behaviour and
             * it is also surprising, which is exactly the combination that has
             * to be stated. The time fields are a different matter and reach
             * every date in the group, so the two halves are described apart.
             */
            ?>
            <?php if ( $n_extra > 0 && $ctx['cadence'] ) : ?>
                <p class="uc-hint uc-hint-warn">
                    <strong><?php echo (int) $n_extra; ?></strong>
                    of these <?php echo esc_html( _n( 'is an extra date', 'are extra dates', $n_extra ) ); ?>
                    added by hand rather than produced by the pattern. Changing the pattern moves the
                    <?php echo (int) $n_pat; ?> pattern
                    <?php echo esc_html( _n( 'date', 'dates', $n_pat ) ); ?>
                    and leaves <?php echo esc_html( _n( 'that one', 'those', $n_extra ) ); ?> where
                    <?php echo esc_html( _n( 'it is', 'they are', $n_extra ) ); ?>.
                    A time change applies to every date in the group.
                </p>
            <?php elseif ( $n_extra > 0 ) : ?>
                <p class="uc-hint">
                    <strong><?php echo (int) $n_extra; ?></strong>
                    of these <?php echo esc_html( _n( 'is an extra date', 'are extra dates', $n_extra ) ); ?>
                    added by hand. A time change applies to every date in the group.
                </p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( $this->url( $ctx['back'] ) ); ?>" class="uc-form">
                <input type="hidden" name="uc_action" value="schedule_pattern" />
                <input type="hidden" name="series_id" value="<?php echo (int) $ctx['term_id']; ?>" />
                <?php wp_nonce_field( 'uc_portal_schedule_pattern', 'uc_nonce' ); ?>

                <?php if ( $ctx['cadence'] ) : ?>
                    <div class="uc-sched-freq" role="radiogroup" aria-label="How often this repeats">
                        <span class="uc-field-label">How often</span>

                        <label class="uc-radio-row">
                            <input type="radio" name="sp_freq" value="daily" <?php checked( 'daily', $ctx['type'] ); ?> />
                            <span>Every
                                <input type="number" name="sp_daily_interval" class="uc-repeat-num" min="1" max="52"
                                       value="<?php echo ( 'daily' === $ctx['type'] ) ? (int) $ctx['interval'] : 1; ?>"
                                       aria-label="Days between occurrences" />
                                day(s)</span>
                        </label>

                        <label class="uc-radio-row">
                            <input type="radio" name="sp_freq" value="weekly" <?php checked( 'weekly', $ctx['type'] ); ?> />
                            <span>Every
                                <input type="number" name="sp_weekly_interval" class="uc-repeat-num" min="1" max="52"
                                       value="<?php echo ( 'weekly' === $ctx['type'] ) ? (int) $ctx['interval'] : 1; ?>"
                                       aria-label="Weeks between occurrences" />
                                week(s) on</span>
                        </label>
                        <div class="uc-days uc-sched-days" role="group" aria-label="Which days of the week">
                            <?php foreach ( $ctx['abbr'] as $i => $letter ) : ?>
                                <label class="uc-day">
                                    <input type="checkbox" name="sp_days[]" value="<?php echo (int) $i; ?>"
                                           <?php checked( in_array( (int) $i, $ctx['wdays'], true ) ); ?> />
                                    <span aria-hidden="true"><?php echo esc_html( $letter ); ?></span>
                                    <span class="uc-visually-hidden"><?php echo esc_html( $ctx['names'][ $i ] ); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <label class="uc-radio-row">
                            <input type="radio" name="sp_freq" value="monthly" <?php checked( 'monthly', $ctx['type'] ); ?> />
                            <span>Every
                                <input type="number" name="sp_monthly_interval" class="uc-repeat-num" min="1" max="52"
                                       value="<?php echo ( 'monthly' === $ctx['type'] ) ? (int) $ctx['interval'] : 1; ?>"
                                       aria-label="Months between occurrences" />
                                <?php // "on day 4", the same words SFAF_Recurrence::pattern_label()
                                      // prints for this pattern on the event page. No ordinal. ?>
                                month(s) on <strong><?php echo esc_html( $ctx['daynum'] ? 'day ' . $ctx['daynum'] : 'the same date' ); ?></strong></span>
                        </label>

                        <label class="uc-radio-row">
                            <input type="radio" name="sp_freq" value="monthly_nth" <?php checked( 'monthly_nth', $ctx['type'] ); ?> />
                            <span>On the</span>
                        </label>
                        <div class="uc-repeat-nth">
                            <select name="sp_nth" aria-label="Which occurrence in the month">
                                <?php foreach ( array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', -1 => 'last' ) as $n => $word ) : ?>
                                    <option value="<?php echo (int) $n; ?>" <?php selected( (int) $ctx['nth'], (int) $n ); ?>><?php echo esc_html( $word ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="sp_nth_dow" aria-label="Which weekday">
                                <?php foreach ( $ctx['names'] as $i => $name ) : ?>
                                    <option value="<?php echo (int) $i; ?>" <?php selected( (int) $ctx['ndow'], (int) $i ); ?>><?php echo esc_html( $name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span>of every month</span>
                        </div>
                        <?php // "last" is not "fifth": a month with four Fridays has a last
                              // Friday and no fifth one, and the engine skips the months a
                              // fifth would fall outside rather than serving a fourth. ?>
                        <p class="uc-hint">Choose <em>last</em> rather than <em>fourth</em> if you mean the final one,
                            since some months have five.</p>
                    </div>
                <?php else : ?>
                    <p class="uc-hint">
                        These dates were chosen one at a time rather than produced by a pattern, so there is no
                        cadence to change. The times can still be set for the whole group here, and a single date can
                        be moved from its own event.
                    </p>
                <?php endif; ?>

                <div class="uc-field-row">
                    <label class="uc-field">
                        <span class="uc-field-label">Start time</span>
                        <input type="time" name="start_time" value="<?php echo esc_attr( $ctx['start'] ); ?>"<?php echo sfaf_time_step_attr( $ctx['start'] ); ?> />
                    </label>
                    <label class="uc-field">
                        <span class="uc-field-label">End time</span>
                        <input type="time" name="end_time" value="<?php echo esc_attr( $ctx['end'] ); ?>"<?php echo sfaf_time_step_attr( $ctx['end'] ); ?> />
                    </label>
                </div>

                <?php
                /*
                 * THE CONFIRMATION NAMES THE EXTRA DATES TOO. It is the last
                 * thing read before a term's worth of programming moves, and
                 * "23 occurrences" without "2 of them stay put" is a true
                 * sentence that leaves somebody with the wrong picture.
                 */
                $confirm = sprintf(
                    'Update %d upcoming %s?',
                    $n_up,
                    _n( 'occurrence', 'occurrences', $n_up )
                );
                if ( $n_extra > 0 && $ctx['cadence'] ) {
                    $confirm .= sprintf(
                        ' A pattern change moves the %d pattern %s; the %d extra %s stay on the %s they are on.',
                        $n_pat,
                        _n( 'date', 'dates', $n_pat ),
                        $n_extra,
                        _n( 'date', 'dates', $n_extra ),
                        _n( 'date', 'dates', $n_extra )
                    );
                }
                $confirm .= ' Dates that have already been are not touched.';
                ?>
                <div class="uc-form-actions">
                    <button type="submit" class="uc-btn uc-btn-primary"
                            data-uc-confirm="<?php echo esc_attr( $confirm ); ?>">
                        Update <?php echo (int) $n_up; ?> upcoming
                        <?php echo esc_html( _n( 'occurrence', 'occurrences', $n_up ) ); ?>
                    </button>
                </div>
            </form>
        </details>
        <?php
    }

    /**
     * Publish this series' upcoming drafts, in one press.
     *
     * THE ONE ACTION ON THIS SCREEN THAT REACHES THE PUBLIC CALENDAR, which is
     * why it says the count twice: once on the button, so nobody has to press it
     * to find out, and once in a confirmation. Everything it will not touch is
     * named above the button rather than discovered afterwards.
     *
     * IT RENDERS NOTHING WHEN THERE IS NOTHING TO DO. A series whose dates are
     * all published gets no button, no empty state and no explanation of an
     * action that would do nothing: the absence is the answer.
     *
     * @param int $term_id
     */
    /**
     * ONE ROW PER DRAFT, ALL TICKED (3.72.0).
     *
     * WHY THE TICKS. The button published every upcoming draft in the series
     * and there was no other answer. On the import's series that is the right
     * answer most of the time, and it is the wrong one exactly when a manager
     * has read the list and found two dates that are not right yet. Their only
     * route was to publish all of them and unpublish two, which puts two
     * sessions on the public calendar for as long as it takes to notice.
     *
     * ALL TICKED BY DEFAULT, so publishing everything is still one press and
     * the common case costs nothing. This is the opposite default from the
     * rejection notice a few hundred lines up, and deliberately: that one sends
     * mail nobody asked for, this one does what the button has always done.
     *
     * AN INELIGIBLE ROW GETS NO TICK AND SAYS WHY. A disabled checkbox is still
     * a checkbox: it draws a box, it sits in the column your eye is running
     * down, and it reads as something that could be ticked if you found the
     * right way to do it. These rows get the reason where the box would have
     * been, which is the only thing anybody wants from them.
     *
     * THE TICKS DO NOT DECIDE ELIGIBILITY AND CANNOT. publish_drafts()
     * intersects whatever is posted with publishable()['ready'], so an id typed
     * into the form by hand is dropped by the same four rules that drew this
     * list. See that method.
     *
     * @param int $term_id
     */
    private function render_schedule_publish_form( $term_id ) {
        $plan  = SFAF_Series::publishable( $term_id );
        $ready = count( $plan['ready'] );
        if ( $ready < 1 ) {
            return;
        }

        $first = (string) get_post_meta( $plan['ready'][0], '_uc_event_date', true );
        $last  = (string) get_post_meta( $plan['ready'][ $ready - 1 ], '_uc_event_date', true );

        /*
         * THE SKIPPED COUNTS ARE SHOWN, NOT SUPPRESSED. "Publish 47" beside a
         * screen listing 61 dates invites the question this line answers, and a
         * manager who cannot see why fourteen are staying put will press it
         * again rather than trust it.
         */
        $left = array();
        foreach ( $plan['skipped'] as $why => $n ) {
            $left[] = $n . ' ' . $why;
        }

        $confirm = sprintf(
            'Publish %d upcoming %s in this series?',
            $ready,
            _n( 'draft', 'drafts', $ready )
        );
        $confirm .= ' They go on the public calendar straight away.';
        if ( $left ) {
            $confirm .= ' Nothing else is touched: ' . implode( ', ', $left ) . '.';
        }

        /*
         * THE SAME SENTENCE WITH THE COUNT LEFT OUT (3.73.0).
         *
         * data-uc-confirm carries the real one, which is right on arrival and is
         * what a browser with no script would use. This is the template
         * portal.js rewrites it from as ticks change, so the number on the
         * button and the number in the confirmation cannot disagree. Rewriting
         * the live attribute by regex was the first attempt and it is wrong on
         * the other screen that now shares this control: a category name can
         * hold a digit.
         */
        $confirm_tpl = sprintf( 'Publish {n} upcoming {noun} in this series?' );
        $confirm_tpl .= ' They go on the public calendar straight away.';
        if ( $left ) {
            $confirm_tpl .= ' Nothing else is touched: ' . implode( ', ', $left ) . '.';
        }
        ?>
        <div class="uc-schedule-publish">
            <h4 class="uc-schedule-head">Publish</h4>
            <p class="uc-hint">
                <?php echo (int) $ready; ?> upcoming
                <?php echo esc_html( _n( 'draft', 'drafts', $ready ) ); ?>
                <?php if ( $first === $last ) : ?>
                    on <?php echo esc_html( sfaf_ap_date( $first, 'short_year' ) ); ?>.
                <?php else : ?>
                    from <?php echo esc_html( sfaf_ap_date( $first, 'short_year' ) ); ?>
                    to <?php echo esc_html( sfaf_ap_date( $last, 'short_year' ) ); ?>.
                <?php endif; ?>
                <?php if ( $left ) : ?>
                    Not included: <?php echo esc_html( implode( ', ', $left ) ); ?>.
                <?php endif; ?>
            </p>
            <form method="post" class="uc-form uc-publish-picker" id="uc-publish-picker" data-uc-tick-picker>
                <input type="hidden" name="uc_action" value="schedule_publish" />
                <input type="hidden" name="series_id" value="<?php echo (int) $term_id; ?>" />
                <?php
                /*
                 * THE MARKER, so an empty list of ticks means "none of them"
                 * rather than "this form did not ask". Without it, unticking
                 * every row would post no publish_ids at all, which is
                 * byte-identical to the no-script form, and the handler would
                 * publish the lot. Same discipline as every other shared
                 * control on these screens.
                 */
                ?>
                <input type="hidden" name="uc_publish_picker_present" value="1" />
                <?php wp_nonce_field( 'uc_portal_schedule_publish', 'uc_nonce' ); ?>

                <?php
                /*
                 * SELECT-ALL IS AN ENHANCEMENT AND SAYS SO BY BEING HIDDEN
                 * UNTIL THE SCRIPT REVEALS IT. A box that cannot select
                 * anything is a control that lies, which is the same reason
                 * the image picker's search box starts hidden.
                 */
                ?>
                <label class="uc-check uc-publish-all" hidden data-uc-tick-all-row>
                    <input type="checkbox" checked data-uc-tick-all />
                    <span>Select all</span>
                </label>

                <ul class="uc-publish-list">
                    <?php foreach ( $plan['ready'] as $rid ) :
                        $r_date = (string) get_post_meta( $rid, '_uc_event_date', true );
                        $r_time = sfaf_ap_time_range(
                            (string) get_post_meta( $rid, '_uc_start_time', true ),
                            (string) get_post_meta( $rid, '_uc_end_time', true )
                        );
                        ?>
                        <li class="uc-publish-row">
                            <label class="uc-check">
                                <input type="checkbox" name="publish_ids[]" value="<?php echo (int) $rid; ?>"
                                       checked data-uc-tick-one />
                                <span class="uc-publish-when">
                                    <?php echo esc_html( sfaf_ap_date( $r_date, 'short_year' ) ); ?>
                                    <?php if ( '' !== $r_time ) : ?>
                                        <span class="uc-muted"><?php echo esc_html( $r_time ); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="uc-publish-title"><?php echo esc_html( get_the_title( $rid ) ?: '(untitled)' ); ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>

                    <?php
                    /*
                     * AND THE ONES THAT CANNOT BE PUBLISHED, IN THE SAME LIST.
                     * In a list of their own they read as a second thing to
                     * deal with. Here they read as what they are: rows on this
                     * schedule that this button is not for, each saying which
                     * of the four rules it met.
                     */
                    ?>
                    <?php foreach ( $plan['blocked'] as $bid => $why ) :
                        $b_date = (string) get_post_meta( $bid, '_uc_event_date', true );
                        ?>
                        <li class="uc-publish-row is-blocked">
                            <span class="uc-publish-when">
                                <?php echo '' !== $b_date
                                    ? esc_html( sfaf_ap_date( $b_date, 'short_year' ) )
                                    : '<span class="uc-muted">No date</span>'; ?>
                            </span>
                            <span class="uc-publish-title"><?php echo esc_html( get_the_title( $bid ) ?: '(untitled)' ); ?></span>
                            <span class="uc-publish-why"><?php echo esc_html( $why ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="uc-form-actions">
                    <?php
                    /*
                     * THE BUTTON STILL CARRIES THE FULL COUNT. It is the
                     * server-rendered number and it is right on arrival, when
                     * everything is ticked. portal.js keeps it in step as ticks
                     * change; with no script the ticks cannot change, so the
                     * number cannot go stale.
                     */
                    ?>
                    <button type="submit" class="uc-btn uc-btn-primary"
                            data-uc-confirm="<?php echo esc_attr( $confirm ); ?>"
                            data-uc-tick-submit
                            data-uc-tick-confirm="<?php echo esc_attr( $confirm_tpl ); ?>"
                            data-uc-tick-word="drafts"
                            data-uc-tick-word-one="draft">
                        Publish <span data-uc-tick-count><?php echo (int) $ready; ?></span> upcoming
                        <span data-uc-tick-noun><?php echo esc_html( _n( 'draft', 'drafts', $ready ) ); ?></span>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Keep going: the same pattern, further out.
     *
     * THE COMMON CASE, AND IT HAD NO CONTROL AT ALL. A series set up to run to
     * December 31 needs to continue into the new year, and until now that meant
     * adding January's dates one at a time, five clicks each, none of which the
     * pattern knew about. Nothing about the cadence changes here: this is the
     * dates the pattern would have made, made.
     *
     * IT SAYS WHERE THE SERIES CURRENTLY ENDS, WHICH IS THE QUESTION BEHIND THE
     * QUESTION. "Does this need extending" cannot be answered without it, and
     * the alternative was scrolling to the bottom of a list of forty dates. The
     * date field's own minimum is the day after, so a date that would add
     * nothing cannot be chosen by accident.
     *
     * A CUSTOM GROUP IS TOLD PLAINLY THAT THERE IS NOTHING TO CARRY FORWARD.
     * Its dates were chosen one at a time; there is no arithmetic in it to run
     * further, and inventing one would put sessions on days nobody picked.
     *
     * @param array $ctx See render_schedule().
     */
    private function render_schedule_extend_form( $ctx ) {
        $horizon = (string) $ctx['horizon'];
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $horizon ) ) {
            return;
        }
        /*
         * THE SITE'S CLOCK, NOT THE SERVER'S. This is a machine value going
         * into a min attribute rather than a sentence, and it is still a date:
         * date()/strtotime() read the server's timezone, so on a server an hour
         * ahead the day after December 31 can be computed as December 31. A day
         * either way here is the difference between offering a date that adds
         * nothing and refusing one that would have worked. Midday for the same
         * reason every date-only string in this plugin is handled at midday.
         */
        try {
            $next_day = ( new DateTimeImmutable( $horizon . ' 12:00:00', wp_timezone() ) )
                ->modify( '+1 day' )->format( 'Y-m-d' );
        } catch ( Exception $e ) {
            return;
        }
        ?>
        <details class="uc-schedule-extend">
            <summary><span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '15px' ) ); ?></span>Extend the series</summary>

            <p class="uc-hint">
                Generated through <strong><?php echo esc_html( sfaf_ap_date( $ctx['horizon'], 'full' ) ); ?></strong>.
            </p>

            <?php if ( ! $ctx['cadence'] ) : ?>
                <p class="uc-hint">
                    These dates were chosen one at a time rather than produced by a pattern, so there is no cadence to
                    carry forward. Add each new date below.
                </p>
            <?php else : ?>
                <p class="uc-hint">
                    Adds the dates this pattern would have made between then and the date you choose. Nothing already on
                    the schedule changes, and a date you removed stays removed.
                </p>
                <form method="post" action="<?php echo esc_url( $this->url( $ctx['back'] ) ); ?>" class="uc-form">
                    <input type="hidden" name="uc_action" value="schedule_extend" />
                    <input type="hidden" name="series_id" value="<?php echo (int) $ctx['term_id']; ?>" />
                    <?php wp_nonce_field( 'uc_portal_schedule_extend', 'uc_nonce' ); ?>

                    <label class="uc-field">
                        <span class="uc-field-label">Run through</span>
                        <input type="date" name="extend_until" min="<?php echo esc_attr( $next_day ); ?>" required />
                    </label>

                    <div class="uc-form-actions">
                        <button type="submit" class="uc-btn uc-btn-primary">Add the missing dates</button>
                    </div>
                </form>
            <?php endif; ?>
        </details>
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
        // Through the one formatter, like every other date this plugin prints.
        // This used to be two hand-written date_i18n( 'D, M j, Y' ) calls, one
        // in the row and one in the confirmation, which is exactly how a screen
        // ends up spelling the same date two ways.
        $shown = $date ? sfaf_ap_date( $date, 'full' ) : '';
        $rsvps = sfaf_get_rsvp_count( $eid );
        $solo  = ( '' !== $group && SFAF_Recurrence::group_of( $eid ) !== $group );
        /*
         * THREE STATES, NOT TWO, AND THE ROW SAYS WHICH.
         *
         *   (nothing)  a pattern date. The cadence made it, and a pattern edit
         *              moves it.
         *   extra      in the group, so a bulk edit and a time change reach it,
         *              but a pattern edit leaves it alone. This is the row
         *              somebody needs to be able to find when they wonder why
         *              one date did not move with the others.
         *   one-off    not in the group at all. Nothing bulk reaches it.
         *
         * "extra" is meaningless on a row that is not in the group, so the two
         * are exclusive rather than stacked. It is equally meaningless in a
         * group that has no pattern, where every date was chosen by hand and
         * the tag would be on every row saying nothing: has_cadence() is the
         * question that settles that.
         */
        $extra = ( ! $solo && '' !== $group
            && SFAF_Recurrence::is_extra_date( $eid )
            && SFAF_Recurrence::has_cadence( SFAF_Recurrence::pattern_of( $eid ) ) );
        ?>
        <li class="uc-schedule-row">
            <span class="uc-schedule-date"><?php echo '' !== $shown ? esc_html( $shown ) : '<span class="uc-muted">No date</span>'; ?></span>
            <span class="uc-schedule-time"><?php echo esc_html( SFAF_Recurrence::time_phrase( $start, $end ) ); ?></span>
            <span class="uc-schedule-meta">
                <?php echo esc_html( sfaf_status_label( get_post_status( $eid ) ) ); ?>
                <?php if ( $rsvps > 0 ) : ?>
                    &middot; <?php echo (int) $rsvps; ?> <?php echo esc_html( _n( 'RSVP', 'RSVPs', $rsvps ) ); ?>
                <?php endif; ?>
                <?php if ( $solo ) : ?>
                    <?php // Said out loud, because it is why a bulk edit will skip it. ?>
                    &middot; <span class="uc-muted">one-off</span>
                <?php elseif ( $extra ) : ?>
                    <?php // Said out loud, because it is why a pattern change
                          // will skip it while a time change will not. ?>
                    &middot; <span class="uc-tag-extra" title="Added by hand rather than produced by the pattern. A day change leaves it where it is.">extra date</span>
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
                                data-uc-confirm="Remove <?php echo esc_attr( '' !== $shown ? $shown : 'this date' ); ?> from the schedule?<?php echo $rsvps > 0 ? ' ' . (int) $rsvps . ' people have registered; their registrations are kept and stay in the RSVP list.' : ''; ?> Nothing puts it back.">Remove</button>
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
        // Rows added by the browser need the editor too. See SFAF_Rich_Text.
        $this->load_editor = true;
        SFAF_Rich_Text::enqueue();

        /*
         * THE RICH TEXT EDITOR, ENQUEUED BEFORE THE HEAD PRINTS.
         *
         * caladmin emits its own document, so the styles and scripts wp_editor()
         * needs are only there because load_media already makes head() call
         * wp_print_styles() and wp_print_head_scripts() and foot() call
         * wp_print_footer_scripts(). This adds the editor to what those print.
         * See description_editor() for what happens if it does not start.
         */
        wp_enqueue_editor();

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
                Set the <strong>category, organizer, and series</strong> below, then press Publish to put it on the calendar.
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

        <?php $this->render_request_panel( $event_id ); ?>

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
            <?php
            /*
             * LOCKED ONLY WHILE THE QUESTION IS OPEN.
             *
             * An answer carried back from a save is a server-side answer, so
             * the fieldset is not disabled on that load and the screen works
             * with scripting off. The data attribute stays either way, because
             * it is how the script finds the fieldset to re-lock what the
             * chosen scope forbids.
             */
            $scope_locked = $has_bulk && '' === $this->carried_edit_scope();
            ?>
            <fieldset class="uc-scope-fields"<?php echo $has_bulk ? ' data-uc-scope-fields' : ''; ?><?php echo $scope_locked ? ' disabled' : ''; ?>>

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
                <?php
                /*
                 * THE CANCEL CARD IS NOT HERE ANY MORE, AND MUST NEVER COME BACK.
                 *
                 * It renders its own <form>, and this is inside the event
                 * <form>. Forms cannot nest: every parser drops the inner start
                 * tag and keeps its children, so the cancel form's uc_action and
                 * uc_nonce joined the event form. PHP takes the last value of a
                 * repeated key, so EVERY SAVE POSTED uc_action=cancel_event with
                 * the matching cancel nonce, cancelled the event, emailed
                 * everybody registered, and never ran the save at all.
                 *
                 * It is rendered after </form> now. The access card below is
                 * safe here because it emits fields and no form of its own.
                 */
                ?>
                <?php $this->render_access_card( $user, $event_id ); ?>

                <section class="uc-bento-card">
                    <h2 class="uc-bento-title">Display</h2>
                    <?php
                    /*
                     * 'show_reminders' STILL WRITES _uc_show_reminders. The
                     * label is what changed in 3.53.0, because what the button
                     * does changed; renaming the key would have read every
                     * event's absent new value as "on" and switched the control
                     * back on wherever somebody had turned it off.
                     */
                    /*
                     * ADD TO CALENDAR SITS UNDER RSVP (3.64.2), because
                     * ticking RSVP is what greys it. A control whose
                     * availability is decided by another one belongs beside
                     * that one, and the two are now a pair a manager can watch
                     * work.
                     *
                     * WHAT THAT COST: the order used to follow the order these
                     * appear on the public event page. That match is gone, and
                     * it was traded on purpose. Nobody reads this card with the
                     * event page open beside it; plenty of people tick Accept
                     * RSVPs and then wonder why a tick four rows down went
                     * grey.
                     */
                    $feat = array( 'show_rsvp' => 'RSVP', 'show_calendar' => 'Add to calendar', 'show_donate' => 'Donate', 'show_social' => 'Social share', 'show_reminders' => 'Follow the series' );

                    /*
                     * ADD TO CALENDAR IS NOT A CHOICE WHILE REGISTRATIONS ARE
                     * ON (3.64.0). The button is off the event page in that
                     * case, because it sat under the RSVP button and could be
                     * pressed by somebody who thought it was how you sign up.
                     * The calendar file goes out with the confirmation instead,
                     * which it already did.
                     *
                     * SO THE CONTROL SAYS WHAT WILL HAPPEN rather than looking
                     * settable and doing nothing. It keeps the manager's own
                     * stored value, because switching registration off later
                     * should give them back the setting they chose, and the
                     * sentence under it is what carries the fact. That sentence
                     * NAMES THE CAUSE FIRST (3.64.2): it used to open on the
                     * confirmation email, leaving somebody who is not in this
                     * calendar every day to connect a greyed tick to a sentence
                     * about mail on their own.
                     *
                     * THE SAVE DOES NOT READ IT EITHER, and that is the half
                     * that matters. A disabled input is a control that posts
                     * nothing today and posts something the day somebody takes
                     * the attribute off, so save_event_from_post() skips
                     * show_calendar on its own test rather than trusting the
                     * browser to withhold it. The standing rule this brushes
                     * against is about permission-sensitive fields and this is
                     * not one; nothing is protected by the greying.
                     *
                     * portal.js keeps it in step live, so ticking Accept RSVPs
                     * in the card below greys this one without a save.
                     */
                    $takes_rsvps = $event_id ? sfaf_event_takes_rsvps( $event_id ) : false;

                    /*
                     * AND THE RSVP TICK FOLLOWS "Accept RSVPs" THE SAME WAY
                     * (3.73.0).
                     *
                     * TWO CONTROLS, TWO QUESTIONS, AND THEY ARE ANDed.
                     * `_uc_rsvp_enabled` decides whether the event takes
                     * registrations at all and SFAF_RSVP refuses a write
                     * without it; `_uc_show_rsvp` decides whether the page
                     * draws the button. sfaf_event_takes_rsvps() is both.
                     *
                     * THE STATE THIS CLOSES is an event that ACCEPTS
                     * registrations and SHOWS NO BUTTON. Nothing was broken
                     * about it and nothing said anything: the REST route
                     * would still take a registration, so the event was
                     * open and invisible at the same time.
                     *
                     * THEY STAY TWO QUESTIONS. Collapsing them into one
                     * tick was considered and rejected: an event that takes
                     * registrations through a link somewhere else is a real
                     * case, and that is exactly "accepts them, shows no
                     * button of ours".
                     *
                     * SO THE SECOND IS DEPENDENT RATHER THAN GONE, which is
                     * the treatment show_calendar has had since 3.64.2 and
                     * for the same reason: it keeps the manager's own
                     * stored value, so switching Accept RSVPs back on gives
                     * them the setting they chose rather than a default.
                     *
                     * THE SAVE SKIPS IT ON ITS OWN TEST, not on the browser
                     * withholding a disabled input. See save_event_from_post().
                     */
                    $accepts = $event_id
                        ? ( '1' === (string) get_post_meta( $event_id, '_uc_rsvp_enabled', true ) )
                        : false;

                    foreach ( $feat as $f => $lbl ) :
                        $on   = $event_id ? sfaf_show_feature( $event_id, str_replace( 'show_', '', $f ) ) : true;
                        $lock = ( 'show_calendar' === $f && $takes_rsvps )
                            || ( 'show_rsvp' === $f && $event_id && ! $accepts );
                        ?>
                        <label class="uc-check<?php echo $lock ? ' uc-check-locked' : ''; ?>"<?php
                            echo 'show_calendar' === $f ? ' data-uc-calendar-check' : ''; ?><?php
                            echo 'show_rsvp' === $f ? ' data-uc-rsvp-show-check' : ''; ?>>
                            <input type="checkbox" name="<?php echo esc_attr( $f ); ?>" value="1" <?php checked( $on ); ?><?php
                                echo $lock ? ' disabled' : ''; ?> />
                            <?php echo esc_html( $lbl ); ?>
                        </label>
                        <?php if ( 'show_rsvp' === $f ) : ?>
                            <?php
                            /*
                             * NAMES THE CAUSE FIRST, and names the control
                             * that fixes it by the words on it. Somebody
                             * who is not in this calendar every day should
                             * not have to connect a greyed tick to another
                             * card on their own.
                             */
                            ?>
                            <p class="uc-hint uc-rsvp-show-note" data-uc-rsvp-show-note<?php echo $lock ? '' : ' hidden'; ?>>
                                This event is not accepting RSVPs, so there is no button to show. Turn on <strong>Accept RSVPs</strong> under Capacity.
                            </p>
                        <?php endif; ?>
                        <?php if ( 'show_calendar' === $f ) : ?>
                            <p class="uc-hint uc-calendar-note" data-uc-calendar-note<?php echo $lock ? '' : ' hidden'; ?>>
                                Because this event takes RSVPs, the calendar link goes out with the registration confirmation instead.
                            </p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </section>
            <?php
            $side_html = ob_get_clean();
            ?>
            <div class="uc-bento">
            <div class="uc-bento-main">

                <?php
                /*
                 * SERIES: the umbrella this event belongs to, and where its
                 * other dates are edited. A plain term assignment; nothing is
                 * inherited from it and changing it never touches another event.
                 *
                 * ---------------------------------------------------------------
                 * THESE TWO LINES LIVED 102 LINES BELOW THIS POINT UNTIL 3.64.1,
                 * AND THAT IS THE WHOLE OF THE DEFECT.
                 *
                 * They sat with the edit-only <select> further down, which is
                 * where they were written and where they still made sense. Then
                 * 3.38.0 added the prefill card ABOVE them and passed them to it.
                 * Straight-line code in one function: at the call both variables
                 * were undefined, PHP passed null, and render_series_prefill()
                 * took its `empty()` early return. THE CARD HAS NEVER RENDERED.
                 *
                 * It failed in the quietest way available. Two `Undefined
                 * variable` warnings, suppressed wherever display_errors is off,
                 * and a function that returns nothing when it has nothing to
                 * offer, which is correct behaviour for the case it thought it
                 * was in. Twenty-six releases, and the only symptom was a control
                 * nobody could find.
                 *
                 * EVERY STATIC CHECK PASSED, AND WOULD PASS AGAIN. The call
                 * exists, the method exists, the arity matches, the file parses.
                 * Nothing but ORDER was wrong, and order is not a property any of
                 * those questions can see. .claude/series-control-test.php
                 * renders the form and reads what came back, which is the only
                 * shape of check that could have caught it. See PROJECT.md §7.
                 * ---------------------------------------------------------------
                 */
                $all_series = SFAF_Series::all();
                $cur_series = $event_id ? SFAF_Series::id_for_event( $event_id ) : 0;

                /*
                 * A NEW EVENT MAY ARRIVE WITH ITS SERIES ALREADY CHOSEN.
                 *
                 * The schedule screen's second route, "create a new event in this
                 * series", is for the date whose details genuinely differ:
                 * another location, another description. It sends somebody here
                 * because this is where those are asked for properly, and the one
                 * thing that screen knew and this one does not is which series
                 * they came from. Carrying it in the URL is the whole of that.
                 *
                 * IT ARRIVED NOWHERE UNTIL 3.64.1. This was read AFTER the dead
                 * call above, so on a new event it was validated into a variable
                 * and then consumed by nothing: the only other reader is the
                 * <select> below, which is gated on $event_id. The button on the
                 * schedule screen carried the term correctly and this screen
                 * dropped it. Same one ordering fault, second casualty.
                 *
                 * CHECKED, NOT TRUSTED. It is a query string, so it is an integer
                 * that must name a series that exists; anything else leaves the
                 * field on "Not part of a series" rather than preselecting
                 * something that is not there. It only preselects a control the
                 * manager can still change, so the worst a valid-but-unintended
                 * id can do is need one click.
                 */
                if ( ! $event_id && isset( $_GET['series'] ) ) {
                    $pre = intval( $_GET['series'] );
                    if ( $pre > 0 && SFAF_Series::exists( $pre ) ) {
                        $cur_series = $pre;
                    }
                }

                /*
                 * ---- WHICH SERIES, FIRST, ON A NEW EVENT ONLY ----------------
                 *
                 * The first decision somebody makes creating an event is what it
                 * is one of, because the answer fills in most of the rest. It
                 * was two thirds of the way down the form, so the useful order
                 * was the reverse of the order the form asked in.
                 *
                 * CREATION ONLY. On an existing event, changing the series
                 * changes the series and nothing else: that event has real
                 * content, and prefill is a convenience for a blank form rather
                 * than a thing that should ever arrive and overwrite work. The
                 * picker stays where it was on an edit.
                 */
                $this->render_series_prefill( $all_series, $cur_series, (int) $event_id );
                ?>

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
                    <?php
                    /*
                     * ONE SET CONTROL HERE, AND IT IS THE ONE THAT APPLIES.
                     *
                     * "Save these as a set" used to sit in this head. An event
                     * is where a set is applied and where this event's own
                     * questions are written; making, editing, duplicating and
                     * deleting sets is the FAQ Sets screen's job, and a shared
                     * list is not something to add to from inside one event.
                     * See the note beside 'faq_set_save' in handle().
                     */
                    ?>
                    <div class="uc-bento-head">
                        <h2 class="uc-bento-title">FAQs</h2>
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
                            <input type="time" name="start_time" value="<?php echo esc_attr( $g( '_uc_start_time' ) ); ?>"<?php echo sfaf_time_step_attr( $g( '_uc_start_time' ) ); ?><?php echo $this->field_disabled( $s_start ); ?> />
                        </label>
                        <label class="uc-field<?php echo esc_attr( $this->field_class( $s_end ) ); ?>">
                            <span class="uc-field-label">End <?php echo $this->field_badge( $s_end, $prov['label'] ); ?></span>
                            <input type="time" name="end_time" value="<?php echo esc_attr( $g( '_uc_end_time' ) ); ?>"<?php echo sfaf_time_step_attr( $g( '_uc_end_time' ) ); ?><?php echo $this->field_disabled( $s_end ); ?> />
                        </label>
                    </div>

                    <?php
                    /*
                     * $all_series AND $cur_series ARE RESOLVED AT THE TOP OF THE
                     * BENTO, above the prefill card that also needs them. They
                     * were assigned here, 102 lines below their first use, which
                     * is the 3.64.1 defect; see the note at that call.
                     *
                     * Nothing is recomputed here. Reading them twice from two
                     * places is how the two copies come to disagree, and the
                     * question "which series is this event in" has one answer per
                     * render.
                     */
                    /*
                     * THE SELECT IS NOT HERE ANY MORE (3.72.0).
                     *
                     * It was rendered here on an edit and at the top of the form
                     * on a new event, so the one question that decides what an
                     * event looks like was in two different places depending on
                     * which screen somebody was on. It is at the top on both
                     * now. See render_series_prefill(), which also explains why
                     * an edit gets the select and not the prefill offer.
                     *
                     * NOTHING MAY RENDER name="series" HERE AGAIN. Two selects
                     * with one name post two values and the second wins, which
                     * is not the one somebody chose. .claude/series-control-test.php
                     * renders this form and counts them.
                     */
                    ?>

                    <?php if ( $cur_series && $event_id ) : ?>
                        <?php // ONE SHORT LINK, not a sentence with a link inside
                              // it. The old wording wrapped mid-phrase and left
                              // the link broken across two lines. Not shown on a
                              // new event: there is no event yet to be one date
                              // of, and the schedule is where they just came from. ?>
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

            <?php
            /*
             * THE CHANGE NOTICE IS A QUESTION ASKED ON SAVE, NOT A TICK ON THE
             * FORM (3.42.0).
             *
             * What was here was a checkbox, ticked, sitting among thirty other
             * controls, and it decided whether everybody registered got an
             * email. The common case was that it was not noticed, so mail went
             * out by default and could not be recalled. A control somebody
             * scrolls past is not a decision.
             *
             * WHAT IS LEFT HERE IS FACTS, NOT A CONTROL. The count and the
             * event's current date, time and location are stamped where the
             * script can read them, and the question is put at the moment of
             * saving by the dialog in portal.js, which can say what actually
             * changed because it can see what was typed. Nothing here posts.
             *
             * THE HIDDEN FIELD STARTS EMPTY AND MUST. Empty means "no answer",
             * which sfaf_should_notify() reads as silence, so a form submitted
             * with scripting off, or by anything that is not this screen, sends
             * nothing rather than mailing everybody. See
             * includes/sfaf-notify-consent.php for why the default direction is
             * silence.
             *
             * The count is still rendered server-side and always, for the same
             * reason it always was: what actually moved is decided after the
             * write, by comparing the before-snapshot against the result.
             */
            $notice_counts = $event_id ? SFAF_Announce::count_affected( $this->save_scope_ids( $event_id ) ) : array( 'people' => 0, 'registrations' => 0, 'events' => 0 );
            $notice_facts  = $event_id ? self::movable_facts( $event_id ) : array();
            if ( $notice_counts['people'] > 0 ) :
                ?>
                <div class="uc-change-notice" data-uc-change-notice
                     data-uc-notify-people="<?php echo (int) $notice_counts['people']; ?>"
                     data-uc-notify-events="<?php echo (int) $notice_counts['events']; ?>"
                     data-uc-fact-date="<?php echo esc_attr( isset( $notice_facts['Date'] ) ? $notice_facts['Date'] : '' ); ?>"
                     data-uc-fact-time="<?php echo esc_attr( isset( $notice_facts['Time'] ) ? $notice_facts['Time'] : '' ); ?>"
                     data-uc-fact-location="<?php echo esc_attr( isset( $notice_facts['Location'] ) ? $notice_facts['Location'] : '' ); ?>">
                    <input type="hidden" name="notify_choice" value="" data-uc-notify-choice />
                </div>
            <?php endif; ?>

            <?php
            /*
             * WHAT THE LEFT BUTTON DOES, WORKED OUT ONCE.
             *
             * Read here rather than beside the button because the caption on
             * the row and the button's own label are the same decision, and
             * two reads of get_post_status() could answer differently if
             * anything between them ever wrote one.
             */
            $live        = $event_id ? get_post_status( $event_id ) : '';
            $keep_status = ( 'publish' === $live || 'pending' === $live || 'future' === $live );

            /*
             * WHAT THIS EVENT CAN BE TOLD TO DO, WHICH IS NOT THE SAME ON
             * EVERY EVENT (3.74.0).
             *
             * WHAT WAS WRONG. Every event, in every state, got the same two
             * buttons: Save and Publish. On something already published those
             * are two labels for one outcome, because 'keep' keeps 'publish'
             * and 'publish' sets 'publish'. Two controls that look like a
             * choice and are not teach people to stop reading the pair, and
             * the pair is what a draft genuinely needs.
             *
             * ON A PENDING SUBMISSION THEY WERE WORSE THAN REDUNDANT. Publish
             * here took the same route a draft takes and put the event on the
             * public calendar, and it is NOT the route the queue's Approve
             * takes: no address joined the notification list and no published
             * notice was sent. So somebody who reviewed a submission properly,
             * by opening it and reading it, published it in a way that told
             * the person who sent it nothing at all. Approve and Reject are on
             * this screen now, and they are the same two forms the queue posts.
             *
             * NO UNPUBLISH, DELIBERATELY. Cancelling is how an event comes off
             * the calendar, and it tells the people who registered. A second
             * quiet route to making one disappear is a way to do that by
             * accident. Mark weighed it and decided against.
             */
            $is_pending  = ( 'pending' === $live );
            $who_sent    = $is_pending
                ? SFAF_Submissions::submitter( $event_id )
                : array( 'is_submission' => false, 'usable' => false, 'name' => '', 'email' => '' );
            $is_sub      = ! empty( $who_sent['is_submission'] );

            /*
             * WHO DECIDES A SUBMISSION IS WHO DECIDES ONE ON THE QUEUE, and
             * that is an admin. The Pending screen is admin-only and both
             * routes behind it wp_die() on anybody else, so offering the
             * decision here to somebody the route would refuse is a button
             * that fails. An editor still saves and still reads everything;
             * what they do not get is the decision.
             *
             * A PENDING EVENT THAT IS NOT A SUBMISSION IS A DIFFERENT THING:
             * a contributor's own event waiting for review, with nobody
             * outside to tell. It keeps the Publish it has always had.
             */
            $can_decide  = ( $is_pending && $is_sub && $this->is_admin_role( $user ) );
            $held        = ( $is_pending && $is_sub && ! $this->is_admin_role( $user ) );
            $show_publish = ( ! $keep_status ) || ( $is_pending && ! $is_sub );
            $ask_id      = 'uc-approve-' . (int) $event_id;
            $reject_id   = 'uc-reject-' . (int) $event_id;
            ?>
            <?php
            /*
             * THE ACTIONS THIS SCREEN EXISTS FOR (3.42.1).
             *
             * uc-form-actions-primary is the event editor's row only. It is a
             * band with its own surface, clear of the card above and the
             * cancelling section below, because these two buttons were a
             * right-aligned pair under a hairline that looked like every other
             * divider on the page. See the note in portal.css for why the
             * weight comes from position and space rather than from colour.
             *
             * The caption on the left says what the two buttons differ ON,
             * which is the one thing somebody hesitating between them needs and
             * the one thing the labels cannot say.
             */
            ?>
            <div class="uc-form-actions uc-form-actions-primary">
                <p class="uc-form-actions-note"><?php
                    if ( $can_decide ) {
                        echo 'Approving puts this on the public calendar. Rejecting removes it, and can tell whoever sent it.';
                    } elseif ( $held ) {
                        echo 'A calendar admin approves or rejects this one. Saving keeps your changes and leaves it waiting.';
                    } elseif ( $keep_status ) {
                        echo 'Saving keeps this event exactly as public as it is now.';
                    } else {
                        echo 'Saving keeps this a draft. Publishing puts it on the public calendar.';
                    }
                ?></p>
                <?php
                /*
                 * NO CONFIRMATION HERE (3.39.0).
                 *
                 * Both buttons used to ask "Update 12 events?" on the way to a
                 * bulk save. The scope is answered once, at open, in the modal,
                 * and the banner above states it permanently with a Change
                 * control beside it, so asking again on Save was the question
                 * put twice. That is the redundancy the per-field pencils had
                 * before 3.23.0.
                 */
                ?>
                <?php
                /*
                 * "SAVE DRAFT" ON A PUBLISHED EVENT WAS A DESTRUCTIVE BUTTON
                 * WEARING AN ORDINARY LABEL, and it sat first in the form,
                 * which is where a browser sends an Enter keypress. On anything
                 * already published or pending it is "Save", and it keeps the
                 * status the event has. Taking an event down is a decision, and
                 * decisions get their own control, not a side effect of the
                 * button somebody reaches for to save a typo.
                 */
                ?>
                <button type="submit" name="save_mode" value="<?php echo $keep_status ? 'keep' : 'draft'; ?>" class="uc-btn"><?php echo $keep_status ? 'Save changes' : 'Save draft'; ?></button>
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
                /*
                 * WHICH FIELDS, AND THE CHOICE. Nothing else.
                 *
                 * It used to carry "GoFundMe Pro cannot supply that, so it
                 * stays empty on the live page until somebody writes it here",
                 * which explains why the software could not fill the field in.
                 * The manager is looking at the empty field, has just been
                 * told which ones are empty, and is being asked one question.
                 * Predates the copy rule in CLAUDE.md §6 and does not survive
                 * it: it tells nobody what to do or what will happen to them.
                 */
                $confirm_tpl = 'This event still needs %s. Publish anyway?';
                $confirm = ! empty( $missing )
                    ? str_replace( '%s', SFAF_Sources::field_phrase( $missing ), $confirm_tpl )
                    : '';
                $watched = $event_id ? SFAF_Sources::completeness_payload( $event_id ) : array();
                ?>
                <?php
                /*
                 * THE TWO DECISIONS THE QUEUE HAS, ON THE SCREEN WHERE THE
                 * SUBMISSION IS ACTUALLY READ (3.74.0).
                 *
                 * THE BUTTONS ARE HERE AND THE FORMS ARE NOT, because a form
                 * may not be nested inside another one and this row is inside
                 * the event form. `form=` is how HTML says which form a button
                 * submits, the two forms are emitted after this one closes,
                 * and the ticks in the ask panel already associate themselves
                 * the same way for the same reason.
                 *
                 * SO THIS IS THE QUEUE'S ROUTE, NOT A SECOND ONE. Same action,
                 * same nonce, same prompt, same two ticks. There is nothing
                 * here that could approve differently from the way the Pending
                 * screen approves, which is the whole point: Publish was a
                 * second route and it silently told nobody.
                 */
                ?>
                <?php if ( $can_decide ) : ?>
                    <button type="submit" form="<?php echo esc_attr( $ask_id ); ?>" class="uc-btn uc-btn-primary"
                            <?php echo $who_sent['is_submission'] ? ' data-uc-approve-ask="uc-approve-ask-' . (int) $event_id . '"' : ''; ?>>Approve</button>
                    <button type="submit" form="<?php echo esc_attr( $reject_id ); ?>" class="uc-btn uc-btn-danger"
                            data-uc-approve-ask="uc-reject-ask-<?php echo (int) $event_id; ?>"
                            data-uc-ask-title="Reject this event?"
                            data-uc-ask-confirm="Reject" data-uc-ask-danger>Reject</button>
                <?php elseif ( $show_publish ) : ?>
                    <?php if ( $role === 'contributor' && $this->contributor_status( $user ) === 'pending' ) : ?>
                        <button type="submit" name="save_mode" value="review" class="uc-btn uc-btn-primary">Submit for Review</button>
                    <?php else : ?>
                        <button type="submit" name="save_mode" value="publish" class="uc-btn uc-btn-primary"
                                <?php echo ! empty( $watched ) ? ' data-uc-confirm-template="' . esc_attr( $confirm_tpl ) . '"' : ''; ?>
                                <?php echo $confirm ? ' data-uc-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>Publish</button>
                    <?php endif; ?>
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
        /*
         * THE FORMS THE TWO BUTTONS ABOVE SUBMIT, AND THE PROMPTS THEY OPEN.
         *
         * Outside the event form because forms do not nest, and after it
         * because `form=` is an association by id and not by position. Same
         * markup the Pending screen renders, from the same two methods, so
         * "the queue asks this and the editor asks that" cannot happen.
         */
        if ( $can_decide ) : ?>
            <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>" id="<?php echo esc_attr( $ask_id ); ?>">
                <input type="hidden" name="uc_action" value="approve_event" />
                <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                <?php wp_nonce_field( 'uc_portal_approve_event', 'uc_nonce' ); ?>
            </form>
            <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>" id="<?php echo esc_attr( $reject_id ); ?>">
                <input type="hidden" name="uc_action" value="reject_event" />
                <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                <?php wp_nonce_field( 'uc_portal_reject_event', 'uc_nonce' ); ?>
            </form>
            <?php $this->render_approve_ask( $event_id, $who_sent ); ?>
            <?php $this->render_reject_ask( $event_id, $who_sent ); ?>
        <?php endif; ?>

        <?php
        /*
         * CANCELLING, BELOW THE FORM AND OUTSIDE IT.
         *
         * Its own form, so it must not be nested inside the event form, which
         * is what made every Save cancel the event instead of saving it. Same
         * placement rule as the FAQ set panel above the form: a control that
         * posts its own action needs its own form, and a form needs somewhere
         * that is not inside another one.
         *
         * Below rather than above because it is the destructive thing on this
         * screen and should not be the first control somebody meets.
         */
        $this->render_cancel_card( $user, $event_id );
        $this->render_delete_card( $user, $event_id );

        $this->chrome_close();
    }

    /**
     * Delete, at the bottom of the editor and after the cancel card.
     *
     * WHY IT IS NOT IN THE ROW OF ACTIONS AT THE TOP (3.74.0). Because of what
     * it is next to there. Save and Publish are pressed dozens of times a day
     * and Delete cannot be undone from this screen; putting an irreversible
     * control in the row somebody's hand already goes to is how an event gets
     * deleted by muscle memory. The events list learned the same thing in
     * 3.73.0, which is why Remove there is the third icon and carries its red
     * at rest.
     *
     * AND WHY IT IS AFTER THE CANCEL CARD RATHER THAN BEFORE IT. The two read
     * as a ladder in the order somebody should try them: cancelling keeps the
     * registrations, closes new ones, and offers to tell everybody who signed
     * up; deleting keeps nothing and tells nobody. The reversible answer to
     * "this event is not happening" is the one somebody meets first, and the
     * irreversible one is last on the page.
     *
     * IT IS THE SAME ROUTE THE LIST POSTS, so it inherits the same refusal:
     * an event with registrations that has not been cancelled cannot be
     * deleted, and says why here rather than offering a button that bounces.
     * See the trash_event case in dispatch_post().
     *
     * @param WP_User $user
     * @param int     $event_id
     */
    private function render_delete_card( $user, $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            // Nothing to delete before the first save.
            return;
        }
        $post = get_post( $event_id );
        if ( ! $post || 'uc_event' !== $post->post_type || ! $this->can_edit_event( $user, $post ) ) {
            return;
        }

        $blocked = ( ! SFAF_Cancellation::is_cancelled( $event_id )
            && SFAF_Announce::has_registrations( $event_id ) );
        ?>
        <div class="uc-card uc-delete-card">
            <div class="uc-card-head">
                <h2>Delete this event</h2>
            </div>
            <?php if ( $blocked ) : ?>
                <p class="uc-hint">
                    People are registered for this one, so it cannot be deleted. Cancel it above:
                    that keeps the registrations, closes new ones, and offers to tell everybody who signed up.
                    Once it is cancelled you can delete it.
                </p>
            <?php else : ?>
                <p class="uc-hint">
                    The event, its questions and its settings go. Nothing puts them back.
                </p>
                <form method="post" action="<?php echo esc_url( $this->url( 'events' ) ); ?>">
                    <input type="hidden" name="uc_action" value="trash_event" />
                    <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                    <?php wp_nonce_field( 'uc_portal_trash_event', 'uc_nonce' ); ?>
                    <div class="uc-form-actions">
                        <button type="submit" class="uc-btn uc-btn-danger"
                                data-uc-confirm="Delete this event? Nothing puts it back.">Delete</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * "Does this repeat?", rendered by SFAF_Recurrence (3.72.0).
     *
     * THE CONTROL MOVED, THE SCREEN DID NOT. The staff request form asks the
     * same question now, and two copies of a control that writes a recurrence
     * pattern is the drift SFAF_Rich_Text was made a class to prevent: the two
     * would differ in what somebody is ALLOWED TO SAY, and nobody would notice
     * until a request arrived expressing a schedule caladmin cannot store.
     *
     * So the markup, the field names and the summary live with the engine that
     * reads them back, and both screens call one renderer. See
     * SFAF_Recurrence::render_control().
     *
     * @param int    $event_id
     * @param string $date The event's own date, which anchors every pattern.
     */
    private function render_recurrence_control( $event_id, $date ) {
        SFAF_Recurrence::render_control(
            $event_id,
            $date,
            $this->recurrence_prefill( $event_id )
        );
    }

    /**
     * What a pending staff request asked for about repeating, for the control.
     *
     * THE OTHER HALF OF "CAPTURED, NOT ARMED" (3.72.0). The request form asks
     * the real question and stores the answer under keys of its own, and this
     * is the one thing that reads them: the approver opens the event, the
     * control is already set to "every Wednesday until December", and pressing
     * Save is what creates the dates. Nothing generates before that press.
     *
     * ONLY WHILE THE EVENT HAS NO SCHEDULE OF ITS OWN, and that is the rule
     * that makes this safe to call on every render. The moment somebody saves,
     * the event carries a real pattern or a real recurrence group, and from
     * then on this returns nothing: a prefill that kept reasserting the
     * requester's answer would silently undo an approver who had deliberately
     * changed it, on every reload, which is a far worse fault than not
     * prefilling at all.
     *
     * A DRAFT AND A PENDING ROW BOTH QUALIFY. The keys are written by the
     * request form and by nothing else, so their presence is the whole test;
     * there is no need to ask what status the row is in.
     *
     * @param int $event_id
     * @return array Empty when there is nothing to offer.
     */
    private function recurrence_prefill( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return array();
        }

        // Already scheduled: leave it alone. Either answers yes.
        if ( '' !== (string) get_post_meta( $event_id, SFAF_Recurrence::PATTERN_META, true ) ) {
            return array();
        }
        if ( '' !== (string) SFAF_Recurrence::group_of( $event_id ) ) {
            return array();
        }

        $pattern = (string) get_post_meta( $event_id, SFAF_Request::META_PATTERN, true );
        $dates   = get_post_meta( $event_id, SFAF_Request::META_PATTERN_DATES, true );
        $dates   = is_array( $dates ) ? array_values( array_map( 'strval', $dates ) ) : array();
        if ( '' === $pattern && empty( $dates ) ) {
            return array();
        }

        return array(
            'pattern' => $pattern,
            'until'   => (string) get_post_meta( $event_id, SFAF_Request::META_PATTERN_UNTIL, true ),
            'limit'   => (int) get_post_meta( $event_id, SFAF_Request::META_PATTERN_LIMIT, true ),
            'dates'   => $dates,
        );
    }
    /**
     * Read the recurrence control back, through SFAF_Recurrence (3.72.0).
     *
     * THE READER MOVED WITH THE RENDERER, and they had to move together. The
     * staff request form emits the same control, so a second parser for the
     * same field names would be free to disagree with this one about what
     * `repeat_mode=weekly` with no days ticked means, and the disagreement
     * would surface as a request that generates the wrong dates at approval.
     * One render, one save. See SFAF_Recurrence::from_post().
     *
     * @return array{0:string,1:string,2:int,3:string[]} pattern, end date,
     *         occurrence limit, explicit dates.
     */
    private function recurrence_from_post() {
        return SFAF_Recurrence::from_post( $_POST );
    }

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
            /*
             * NO ONLINE TICK HERE, AND REFUSING COSTS NOTHING.
             *
             * The platform owns this field and rewrites it every hour, so a
             * tick that emptied it would be undone by the next fetch and the
             * event would flicker between "Online Event" and whatever
             * Eventbrite says. An online event from a platform already says so
             * in the text the platform sends. See SFAF_Online.
             */
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

        $online     = $event_id ? SFAF_Online::is_online( $event_id ) : false;
        $meet_link  = $event_id ? SFAF_Online::link( $event_id ) : '';
        $sends      = $event_id ? SFAF_Online::sends( $event_id ) : array();
        ?>
        <div class="uc-field uc-location-field" data-uc-location>
            <span class="uc-field-label">Location
                <?php echo sfaf_help(
                    'uc-help-venue-' . (int) $event_id,
                    'An event points at its venue rather than keeping a copy of the address, so correcting an address on the Venues screen corrects every event held there at once, including ones already published. Use a different location for a one-off place that is not worth adding as a venue.',
                    'venues'
                ); ?>
            </span>

            <?php
            /*
             * THE ONLINE TICK, ABOVE THE PICKER IT REPLACES.
             *
             * FIRST IN THE FIELD, because it is the question that decides
             * whether the rest of the field applies at all. The hidden 0 is the
             * same discipline every other checkbox on these screens uses: the
             * save may only speak for what the form showed, and without it an
             * unticked box and a form that never asked are the same bytes.
             *
             * WITHOUT SCRIPTING the venue and address panels stay visible and
             * still submit, and the save prefers the tick, exactly as it
             * prefers the radio. So this degrades to three controls and a rule
             * rather than to nothing.
             */
            ?>
            <input type="hidden" name="uc_online_present" value="1" />
            <input type="hidden" name="uc_online" value="0" />
            <label class="uc-check uc-online-check">
                <input type="checkbox" name="uc_online" value="1" data-uc-online-toggle <?php checked( $online ); ?> />
                This is an online event
            </label>
            <p class="uc-hint">
                The venue and address will be cleared. You'll need to re-enter them if you switch back.
            </p>

            <div class="uc-online-panel" data-uc-online-panel>
                <label class="uc-field">
                    <span class="uc-field-label">Meeting link</span>
                    <input type="url" name="meeting_url" value="<?php echo esc_attr( $meet_link ); ?>"
                           placeholder="https://zoom.us/j/00000000000" />
                    <?php
                    /*
                     * ALL OF THE LINK MESSAGING IS HERE, UNDER THE LINK BOX.
                     *
                     * It used to be three places: this hint, a sentence under
                     * the tick group about the no-link case, and a paragraph
                     * about calendar files. An organizer had to assemble the
                     * picture from all three, and the fourth case was in none of
                     * them: a link entered with neither box ticked, kept on the
                     * event for the organizer's own reference and sent nowhere.
                     * Four cases, one place, next to the field they are about.
                     */
                    ?>
                    <span class="uc-hint">
                        Only people who register will get this link. It never appears on the event page.
                    </span>
                    <span class="uc-hint">
                        If no link is entered, RSVP emails will say a link will be provided before the event.
                        If you enter one, choose below where it goes out, or leave both unticked to keep it
                        here for your own reference.
                    </span>
                </label>

                <div class="uc-field">
                    <?php
                    /*
                     * "WHEN", NOT "WHO", BECAUSE THE CONTROLS ANSWER WHEN.
                     *
                     * Both of these emails go to everybody registered, so the
                     * label asked a question its own two ticks cannot answer,
                     * and an organizer reading "Who gets the link" reasonably
                     * expects to be choosing people.
                     */
                    ?>
                    <span class="uc-field-label">When the link goes out</span>
                    <?php
                    /*
                     * THE MARKER, AND WHY THIS LIST NEEDS ONE MORE THAN MOST.
                     *
                     * A checkbox group with nothing ticked submits nothing at
                     * all, which is indistinguishable from a form that never
                     * offered it. That is the shape that switched every email
                     * off on a save from the pending queue once already; see
                     * the notification kinds above. uc_online_present, posted by
                     * the hidden field at the top of this field, says the whole
                     * of this block was on screen.
                     */
                    foreach ( SFAF_Online::deliveries() as $key => $label ) : ?>
                        <label class="uc-check">
                            <input type="checkbox" name="meeting_send[]" value="<?php echo esc_attr( $key ); ?>"
                                   <?php checked( in_array( $key, $sends, true ) ); ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                    <?php
                    /*
                     * NOTHING UNDER THE TICKS, AND THE CALENDAR POINT MOVED
                     * ONTO THE TICK THAT CAUSES IT.
                     *
                     * The no-link sentence is covered under the link box above.
                     * The calendar consequence was a paragraph beneath BOTH
                     * ticks while only one of them causes it, so it is now in
                     * the confirmation tick's own label, where it is read at the
                     * moment of deciding rather than afterwards. See
                     * SFAF_Online::deliveries().
                     */
                    ?>
                </div>
            </div>

            <div class="uc-location-place" data-uc-location-place>

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
                            <?php /* data-uc-display is what sfaf_event_location() would
                                   * return for an event at this venue. The change dialog
                                   * needs the location a save WOULD produce, and composing
                                   * it a second time in JavaScript is how the two drift.
                                   * The option's own label is a different string on
                                   * purpose: it separates with a dash so the list reads. */ ?>
                            <option value="<?php echo (int) $v->term_id; ?>"
                                    data-uc-display="<?php echo esc_attr( SFAF_Venues::display( $v->term_id ) ); ?>"
                                    <?php selected( $venue_id, $v->term_id ); ?>>
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
            </div><?php // uc-location-place: everything the online tick replaces. ?>
        </div>
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
    /**
     * Which events a save of this one can reach.
     *
     * The event itself, plus every upcoming member of its recurrence group when
     * one exists. Used to count who would be told, so the prompt names the
     * total across all affected dates rather than the one that happens to be
     * open in the editor. That is the difference between "3 people are
     * registered" and the truth, which may be thirty across twelve dates.
     *
     * It is deliberately the WIDEST set the save could touch rather than the
     * set it will: which occurrences move is decided by the scope buttons after
     * this renders, and a count that shrank when somebody changed their mind
     * would be a count nobody trusted.
     *
     * @param int $event_id
     * @return int[]
     */
    private function save_scope_ids( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return array();
        }
        if ( SFAF_Recurrence::has_bulk_scope( $event_id ) ) {
            $targets = SFAF_Recurrence::bulk_targets( $event_id );
            if ( ! empty( $targets ) ) {
                return array_values( array_unique( array_map( 'absint', array_merge( array( $event_id ), $targets ) ) ) );
            }
        }
        return array( $event_id );
    }

    /**
     * CANCEL THIS EVENT, and the prompt that goes with it.
     *
     * NOT INSIDE THE EVENT FORM. Forms cannot nest, and this posts its own
     * action for a better reason than that: cancelling is not a field that gets
     * saved along with thirty others. It closes registration, stops both
     * scheduled emails and offers to write to everybody who signed up, and a
     * control with those consequences sitting among the checkboxes is a control
     * somebody presses while meaning to press Save.
     *
     * THE PROMPT APPEARS ONLY WHEN SOMEBODY IS REGISTERED. With nobody to tell,
     * the question "shall we tell them" is a click in the way, so the whole
     * notify block is absent rather than present and disabled.
     *
     * THE TICK DEFAULTS TO ON. Somebody cancelling an event is thinking about
     * the event, not about who needs telling, so the safe default is that people
     * are told and not telling them is a deliberate act. The hint says the one
     * legitimate reason to untick it, which is that they are writing to people
     * some other way.
     *
     * @param WP_User $user
     * @param int     $event_id
     */
    private function render_cancel_card( $user, $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            // Nothing to cancel before the first save.
            return;
        }
        $post = get_post( $event_id );
        if ( ! $post ) {
            return;
        }

        /*
         * CANCELLING IS FOR NATIVE EVENTS ONLY (3.38.0).
         *
         * An imported event is cancelled where it lives. If a GFMP campaign or
         * an Eventbrite listing is called off there it leaves this calendar
         * through the unpublish-on-removal path, and telling the people who
         * signed up is that platform's job: they registered there, and this
         * plugin holds none of their addresses to write to.
         *
         * Offering the control here would have produced a half-cancellation
         * that looks whole: off this calendar, still selling places at the
         * source, and nobody told by anybody. 3.36.0 shipped it with a warning
         * saying exactly that, which is a sentence explaining why a control is
         * misleading rather than a reason to have it.
         */
        if ( '' !== (string) get_post_meta( $event_id, SFAF_Sources::META_SOURCE, true ) ) {
            return;
        }

        $cancelled = SFAF_Cancellation::is_cancelled( $event_id );
        $counts    = SFAF_Announce::count_affected( array( $event_id ) );
        $has_regs  = $counts['people'] > 0;

        /*
         * A CLOSED DISCLOSURE WHEN IT IS AN ACTION, A PLAIN CARD WHEN IT IS A
         * STATE (3.42.1).
         *
         * This read as the next section of the form. Somebody scrolling past
         * Save arrived at a full card of radio buttons and a red button, in the
         * same rhythm as the cards above it, which is how a destructive control
         * ends up looking like the next thing to fill in.
         *
         * So when the event is live, cancelling is one closed line and opening
         * it is a deliberate act, which is the usual shape for something
         * destructive and the same shape the schedule's remove controls use.
         *
         * WHEN THE EVENT IS ALREADY CANCELLED IT IS NOT HIDDEN, and that is the
         * whole point of splitting the two. Then this is not an action anybody
         * is being protected from, it is the most important FACT on the screen,
         * and Reinstate is the thing somebody came here to press. Hiding a
         * status behind a disclosure is how somebody edits a cancelled event
         * for ten minutes without noticing it is cancelled.
         */
        if ( $cancelled ) :
            ?>
        <section class="uc-bento-card uc-cancel-card is-cancelled">
            <h2 class="uc-bento-title">This event is cancelled</h2>

                <p class="uc-cancel-state">
                    <?php echo esc_html( SFAF_Cancellation::label( $event_id ) ); ?>.
                    <?php $at = SFAF_Cancellation::cancelled_at( $event_id ); ?>
                    <?php if ( $at ) : ?>
                        <span class="uc-muted">Cancelled <?php echo esc_html( sfaf_ap_datetime( $at ) ); ?>.</span>
                    <?php endif; ?>
                </p>
                <p class="uc-hint">
                    It takes no new registrations, and neither the morning-of reminder nor the two-hour
                    summary will go out for it. Its <?php echo (int) $counts['registrations']; ?>
                    <?php echo esc_html( 1 === (int) $counts['registrations'] ? 'registration is' : 'registrations are' ); ?>
                    kept as the record that people signed up.
                </p>
                <div class="uc-cancel-actions">
                    <?php
                    /*
                     * PUTTING IT BACK ON ASKS ABOUT MAIL NOW (3.73.0), and
                     * it asks through the same confirmation cancelling uses.
                     *
                     * THE HINT UNDER IT USED TO BE THE WHOLE ANSWER, and it
                     * was an instruction to go and do something by hand:
                     * "Nobody is told automatically. If you emailed people
                     * that it was cancelled, tell them it is back." Everybody
                     * who needed telling is in a table this plugin owns, so
                     * that was work being handed to a person that the
                     * software was already able to do.
                     *
                     * data-uc-confirm-cancel IS THE SAME ATTRIBUTE the
                     * cancel form carries, so initCancelConsent() binds this
                     * one too and there is no second dialog. What differs is
                     * the wording, which comes off the data attributes below.
                     */
                    $back_people = (int) $counts['people'];
                    ?>
                    <form method="post" action="<?php echo esc_url( $this->url( 'events/edit/' . $event_id ) ); ?>" class="uc-cancel-form"
                          <?php echo $back_people > 0 ? 'data-uc-confirm-cancel data-uc-confirm-reinstate' : ''; ?>>
                        <input type="hidden" name="uc_action" value="cancel_event" />
                        <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                        <input type="hidden" name="uncancel" value="1" />
                        <?php wp_nonce_field( 'uc_portal_cancel_event', 'uc_nonce' ); ?>
                        <?php if ( $back_people > 0 ) : ?>
                            <input type="hidden" name="notify_choice" value="" data-uc-notify-choice />
                            <p class="uc-notice-count" data-uc-cancel-count="<?php echo (int) $back_people; ?>">
                                <strong><?php echo (int) $back_people; ?></strong>
                                <?php echo esc_html( 1 === $back_people ? 'person was' : 'people were' ); ?>
                                told this was cancelled. You will be asked whether to tell them it is back.
                            </p>
                        <?php endif; ?>
                        <button type="submit" class="uc-btn">Put it back on</button>
                        <?php if ( $back_people < 1 ) : ?>
                            <p class="uc-hint">
                                Nobody is registered, so there is nobody to tell.
                            </p>
                        <?php endif; ?>
                    </form>

                    <?php
                    /*
                     * TAKING IT OFF THE CALENDAR IS THE OTHER HALF OF THE
                     * VISIBILITY QUESTION, AND IT WAS ONLY ASKED ONCE (3.72.0).
                     *
                     * The question "leave it listed or take it off" is asked at
                     * the moment of cancelling and never again. Somebody who
                     * leaves it listed so the people who registered can find it,
                     * and then wants it gone three weeks later, had exactly one
                     * route: put it back on and cancel it a second time. That
                     * route runs through the confirmation, which offers to
                     * email everybody registered, so tidying the calendar risks
                     * a second round of mail about an event that was cancelled
                     * once.
                     *
                     * THIS SENDS NOTHING AND CANNOT. It writes the visibility
                     * meta and returns; SFAF_Cancellation::set() has never sent
                     * anything and this does not call SFAF_Announce at all. The
                     * people who needed telling were told when it was cancelled,
                     * and being told a second time that it is now also hidden is
                     * not news to anybody.
                     *
                     * IT IS NOT THE PRIVATE SETTING, and the difference is the
                     * whole reason it is a separate control. A private event is
                     * off the listings and still reachable at its address, on
                     * purpose, because the URL is the credential and somebody
                     * was given it. A cancelled event taken off the calendar is
                     * off the listings AND still answers at its address with the
                     * cancellation notice, which is what somebody arriving from
                     * an old link or an old email needs to see. Neither one is
                     * the other, and an event can be both.
                     *
                     * REVERSIBLE, and the row stays in caladmin's event list in
                     * cancelled status either way, which is where somebody goes
                     * to change their mind.
                     */
                    $hidden = SFAF_Cancellation::is_hidden( $event_id );
                    ?>
                    <form method="post" action="<?php echo esc_url( $this->url( 'events/edit/' . $event_id ) ); ?>" class="uc-cancel-form">
                        <input type="hidden" name="uc_action" value="cancel_event" />
                        <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                        <input type="hidden" name="cancel_visibility" value="<?php echo $hidden ? 'stay' : 'hide'; ?>" />
                        <?php
                        /*
                         * ITS OWN MARKER RATHER THAN AN INFERENCE. Without it
                         * the handler would have to work out from an absent
                         * `uncancel` and a present `cancel_visibility` that this
                         * is not a fresh cancellation, and it would then run the
                         * whole cancel path: deleting the public reason, because
                         * no cancel_reason was posted, and asking
                         * sfaf_should_notify() a question nobody was asked.
                         * Naming what this is makes both impossible.
                         */
                        ?>
                        <input type="hidden" name="cancel_visibility_only" value="1" />
                        <?php wp_nonce_field( 'uc_portal_cancel_event', 'uc_nonce' ); ?>
                        <button type="submit" class="uc-btn">
                            <?php echo $hidden ? 'Put it back on the calendar' : 'Remove from the calendar'; ?>
                        </button>
                        <p class="uc-hint">
                            <?php if ( $hidden ) : ?>
                                It is off the public calendar. Putting it back lists it again, marked cancelled.
                                It stays cancelled either way, and nothing is sent.
                            <?php else : ?>
                                It stops being listed on the public calendar. Its own page still opens and still says
                                it is cancelled, the registrations are kept, and nothing is sent.
                            <?php endif; ?>
                        </p>
                    </form>
                </div>
        </section>
            <?php
            return;
        endif;

        /*
         * NOT CANCELLED: the destructive control, closed.
         *
         * The two sentences that used to open this card are gone. "A cancelled
         * event keeps its registrations and takes no new ones. This is what to
         * use instead of deleting" is background: it explains the feature to
         * somebody who is not doing anything yet, and it ran to three lines
         * before the first control. What a person needs at the moment they act
         * is in the confirmation, which says it in one line while they are
         * deciding. The refusal to delete a registered event says the rest at
         * the moment it applies, which is where it means something.
         */
        ?>
        <?php
        /*
         * ARRIVED HERE TO CANCEL (3.73.0).
         *
         * The events list offers Cancel on any row whose event has
         * registrations, because Remove is refused on those, and that link
         * carries `cancel=1`. It used to go to the bare editor, so somebody who
         * pressed something about cancelling landed on a form and had to find
         * this disclosure at the bottom of it.
         *
         * OPEN, AND SCROLLED TO. The `open` attribute is the whole of the first
         * half and needs no script. The id is the anchor the browser jumps to,
         * and it is on the section rather than the details so the heading is not
         * flush against the top of the window.
         *
         * IT IS A REQUEST TO SHOW, NOT A REQUEST TO DO. Nothing is cancelled by
         * arriving; the confirmation still asks, and still asks about mail. A
         * query string that CANCELLED something would be a link anybody could
         * put in an email.
         */
        $came_to_cancel = ! empty( $_GET['cancel'] );
        ?>
        <section class="uc-danger-zone" id="uc-cancel-this" aria-label="Cancelling this event">
            <details class="uc-danger-disclosure" data-uc-disclosure<?php echo $came_to_cancel ? ' open' : ''; ?>>
                <summary class="uc-danger-toggle" aria-expanded="<?php echo $came_to_cancel ? 'true' : 'false'; ?>">
                    <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '16px' ) ); ?></span>
                    <span>Cancel this event</span>
                </summary>
                <div class="uc-danger-body">

                <form method="post" action="<?php echo esc_url( $this->url( 'events/edit/' . $event_id ) ); ?>" class="uc-cancel-form" data-uc-confirm-cancel>
                    <input type="hidden" name="uc_action" value="cancel_event" />
                    <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>" />
                    <?php wp_nonce_field( 'uc_portal_cancel_event', 'uc_nonce' ); ?>

                    <fieldset class="uc-cancel-visibility">
                        <legend>What should happen to it on the public calendar?</legend>
                        <label class="uc-radio-opt">
                            <input type="radio" name="cancel_visibility" value="stay" checked />
                            <span>
                                <strong>Leave it listed, marked cancelled.</strong>
                                Somebody who registered may come looking for it, and an event that has simply
                                vanished tells them nothing.
                            </span>
                        </label>
                        <label class="uc-radio-opt">
                            <input type="radio" name="cancel_visibility" value="hide" />
                            <span>
                                <strong>Take it off the public calendar.</strong>
                                It stays here and keeps its registrations.
                            </span>
                        </label>
                    </fieldset>

                    <?php
                    /*
                     * TWO MESSAGES, AND ONLY ONE OF THEM IS ON THIS FORM
                     * (3.72.0).
                     *
                     * This box is PUBLIC. It renders on the event page under
                     * "This event has been cancelled", for anybody who arrives
                     * at the address, and it is also carried in the email. It
                     * is written whether anybody is emailed or not, so it
                     * belongs here, on the form, where it can be typed before
                     * the decision about mail is taken.
                     *
                     * The second message, the one for the people registered
                     * and nobody else, is collected by the confirmation
                     * instead, on the far side of the answer that sends it.
                     * The hidden field below is where it lands. Putting that
                     * box here as well would have let somebody write to
                     * registrants and then choose not to write to them, with
                     * nothing on the screen saying so.
                     *
                     * THE LABEL SAYS WHICH BEFORE ANYBODY TYPES. "Why, in one
                     * line" said neither, and the placeholder said "Shown to
                     * the people you tell", which is the half that is least
                     * true: it is shown to everybody.
                     */
                    ?>
                    <label class="uc-field">
                        <span class="uc-field-label">Why, in one line (optional)</span>
                        <textarea name="cancel_reason" rows="2"
                                  placeholder="Shown on the event page, and in the email."></textarea>
                        <span class="uc-hint">This one is public. Anybody who opens the event reads it.</span>
                    </label>
                    <?php
                    /*
                     * FILLED BY THE CONFIRMATION, NEVER BY A CONTROL ON THIS
                     * PAGE. Empty means there is nothing extra to say, which is
                     * also what it means when somebody chose not to email, and
                     * the handler writes it only under sfaf_should_notify().
                     */
                    ?>
                    <input type="hidden" name="cancel_message" value="" data-uc-cancel-message />

                    <?php
                    /*
                     * THE EMAIL QUESTION RIDES THE CANCEL CONFIRMATION (3.42.0).
                     *
                     * There was a ticked checkbox here with the same fault as
                     * the one on the event form: it decided whether everybody
                     * registered was emailed, and it was easy not to notice.
                     *
                     * Cancelling is ALREADY a deliberate act behind a
                     * confirmation, so this does not get a second dialog. The
                     * existing confirmation states how many people would be
                     * told and offers the two answers, and the button that
                     * opened it now commits nothing on its own.
                     *
                     * The count is stamped for that dialog to read. The hidden
                     * field starts empty, and empty means do not send.
                     */
                    ?>
                    <input type="hidden" name="notify_choice" value="" data-uc-notify-choice />
                    <?php if ( $has_regs ) : ?>
                        <p class="uc-notice-count" data-uc-cancel-count="<?php echo (int) $counts['people']; ?>">
                            <strong><?php echo (int) $counts['people']; ?></strong>
                            <?php echo esc_html( 1 === (int) $counts['people'] ? 'person is' : 'people are' ); ?>
                            registered for this event. You will be asked whether to email them.
                        </p>
                    <?php else : ?>
                        <p class="uc-hint" data-uc-cancel-count="0">Nobody is registered, so there is nobody to tell.</p>
                    <?php endif; ?>

                    <button type="submit" class="uc-btn uc-btn-danger">Cancel this event</button>
                </form>

                </div>
            </details>
        </section>
        <?php
    }

    /**
     * Write the team access fields, if this person may set them.
     *
     * THE GATE IS HERE AS WELL AS ON THE RENDERER, AND NOT BECAUSE THE RENDERER
     * MIGHT BE WRONG. A form that is not drawn is not a permission: a POST is a
     * request anybody can construct by hand, and the whole of defect one in
     * PROJECT.md §5 was a screen that relied on not being linked to. So the
     * question "may this person give access away" is asked again at the moment
     * the write happens, against the same can_view_all() the renderer asked.
     *
     * A contributor saving their own event posts no access fields, and if one
     * arrives anyway it is ignored rather than refused: the rest of their save
     * is legitimate and failing it would teach them that saving is unreliable.
     * Nothing is written, which is the outcome that matters.
     *
     * @param WP_User $user
     * @param int     $event_id
     */
    private function save_access_from_post( $user, $event_id ) {
        if ( ! isset( $_POST['access_teams_present'] ) ) {
            return;
        }
        if ( ! $this->can_view_all( $user ) ) {
            return;
        }

        $ids = isset( $_POST['access_teams'] ) ? (array) wp_unslash( $_POST['access_teams'] ) : array();
        SFAF_Teams::set_access_for_event( $event_id, $ids );

        /*
         * THE NOTIFICATION LIST IS A SEPARATE WRITE, AND IT ONLY EVER TOUCHES
         * THE TEAMS THIS FORM OFFERED.
         *
         * The $offered guarantee, applied to a second field: a team on the
         * notification list that is NOT one of the access teams was put there
         * by the notify picker and is none of this control's business, so it is
         * kept whatever the checkbox says. Without that, ticking and unticking
         * this box would quietly delete notification choices made elsewhere on
         * the same screen.
         */
        $kept  = SFAF_Teams::access_for_event( $event_id );
        $notify_now = SFAF_Teams::for_event( $event_id );
        $others = array_values( array_diff( $notify_now, $kept ) );

        $wants = isset( $_POST['access_teams_notify'] );
        SFAF_Teams::set_for_event( $event_id, $wants ? array_merge( $others, $kept ) : $others );
    }

    /**
     * WHO CAN EDIT THIS EVENT: the organizer, and up to two teams.
     *
     * ONLY SOMEBODY WHO CAN ALREADY GIVE ACCESS AWAY MAY DRAW THIS. Assigning a
     * team hands edit rights and the registration list to a group of people, so
     * it is an admin-or-editor control, not something a contributor may do to
     * their own event. A contributor sees who has access and cannot change it,
     * which is a separate render rather than a disabled input: a disabled input
     * is a control that posts nothing today and posts something the day
     * somebody removes the attribute.
     *
     * THE NOTIFY CHECKBOX IS DELIBERATELY OFF BY DEFAULT AND DELIBERATELY
     * SEPARATE. A team generally wants to log in and look at who has registered,
     * not receive an email per registration. Assigning a team therefore grants
     * access and nothing else; the tick is what also puts it on the
     * notification list, and it writes the OTHER meta key. See
     * SFAF_Teams::ACCESS_META for why those are two keys and not one.
     *
     * @param WP_User $user
     * @param int     $event_id
     */
    private function render_access_card( $user, $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            // Nothing to own yet. The organizer is settled by the first save.
            return;
        }

        $post = get_post( $event_id );
        if ( ! $post ) {
            return;
        }

        $organizer = get_userdata( $post->post_author );
        $teams     = SFAF_Teams::all();
        $chosen    = SFAF_Teams::access_for_event( $event_id );
        $notified  = SFAF_Teams::for_event( $event_id );
        ?>
        <section class="uc-bento-card">
            <h2 class="uc-bento-title">Who can edit this
                <?php
                /* THE LIVE RESOLUTION IS THE SURPRISE. Naming a team here is
                 * not a snapshot, so somebody joining that team next month can
                 * edit this event without anybody touching it. */
                echo sfaf_help(
                    'uc-help-teams-' . (int) $event_id,
                    'A team is resolved every time somebody opens the event, so adding a person to the team gives them this event too, and removing them takes it away. Nothing here has to be changed for that to happen.',
                    'teams'
                );
                ?>
            </h2>

            <p class="uc-access-organizer">
                <strong><?php echo esc_html( $organizer ? $organizer->display_name : 'Nobody' ); ?></strong>
                <span class="uc-muted">
                    <?php echo $organizer
                        ? 'created this event and can always edit it.'
                        : 'The account that created this event no longer exists. An administrator should reassign it.'; ?>
                </span>
            </p>
            <p class="uc-hint">Calendar admins and editors can edit every event.</p>

            <?php if ( ! $this->can_view_all( $user ) ) : ?>
                <?php
                /*
                 * THE READ-ONLY RENDER. It names the teams and offers no
                 * control at all: no select, no checkbox, no marker. A form
                 * that does not ask cannot be answered, so there is nothing
                 * here for save_access_from_post() to act on even if a
                 * request arrived carrying the fields.
                 */
                ?>
                <?php if ( empty( $chosen ) ) : ?>
                    <p class="uc-muted">No team has been given access to this event.</p>
                <?php else : ?>
                    <p class="uc-muted">
                        Also editable by
                        <?php
                        $names = array();
                        foreach ( $chosen as $id ) {
                            if ( isset( $teams[ $id ] ) ) {
                                $names[] = $teams[ $id ]['name'];
                            }
                        }
                        echo esc_html( implode( ' and ', $names ) );
                        ?>.
                    </p>
                <?php endif; ?>
                <p class="uc-hint">Ask a calendar admin to change this.</p>
            <?php elseif ( empty( $teams ) ) : ?>
                <p class="uc-muted">No teams exist yet.</p>
                <p class="uc-hint">Teams are created under Users &amp; Teams.</p>
            <?php else : ?>
                <?php // The marker: every box unticked posts nothing, and that
                      // has to mean "no teams" rather than "this form did not
                      // ask". Same guarantee as notify_teams_present. ?>
                <input type="hidden" name="access_teams_present" value="1" />

                <p class="uc-hint">
                    Everybody on a team you pick can edit this event and see who has registered.
                    Pick up to <?php echo (int) SFAF_Teams::MAX_PER_EVENT; ?>.
                </p>

                <div class="uc-access-teams" data-uc-access-teams data-uc-access-max="<?php echo (int) SFAF_Teams::MAX_PER_EVENT; ?>">
                    <?php foreach ( $teams as $team ) :
                        $on = in_array( (string) $team['id'], $chosen, true ); ?>
                        <label class="uc-check">
                            <input type="checkbox" name="access_teams[]"
                                   value="<?php echo esc_attr( $team['id'] ); ?>" <?php checked( $on ); ?> />
                            <?php echo esc_html( $team['name'] ); ?>
                            <span class="uc-muted"><?php
                                $n = SFAF_Teams::member_count( $team['id'] );
                                echo esc_html( $n . ' ' . ( 1 === $n ? 'person' : 'people' ) );
                            ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <label class="uc-check uc-access-notify">
                    <input type="checkbox" name="access_teams_notify" value="1"
                        <?php checked( ! empty( $chosen ) && count( array_intersect( $chosen, $notified ) ) === count( $chosen ) ); ?> />
                    Also email these teams about registrations and reminders
                </label>
                <p class="uc-hint">
                    Leave this off if the team will log in to see registrations. Everybody on the
                    notification list gets one email per registration.
                </p>
            <?php endif; ?>
        </section>
        <?php
    }

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
        $portal_users  = $this->calendar_people();

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

            <?php // ---- 1. THE ONE LIST. --------------------------------- ?>
            <div class="uc-notify-section">
                <h4 class="uc-notify-subhead">Who hears about this event</h4>
                <p class="uc-hint">
                    Everybody here is told when somebody registers, gets a copy of the morning-of reminder, and gets the
                    list of who is coming two hours before. It changes nothing about who can edit this event.
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
                               data-uc-email-input
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
             * ---- 2. THE FOUR EMAILS, BEHIND A DISCLOSURE. -----------------
             *
             * ALL FOUR ARE ON AND NOBODY HAS TO KNOW THAT. Creating an event is
             * a title, a date, a time, a place, a category and a picture. If
             * working email costs six more decisions, it will be got wrong or
             * skipped, so the decisions are not asked: the defaults are the
             * answer and this fold is where somebody goes who wants a different
             * one.
             *
             * THE SUMMARY LINE STATES WHAT THE DEFAULT IS DOING, which is the
             * point of a disclosure rather than a hidden panel. Closed, it says
             * all four are on, or names how many are not. Either way the state
             * is visible without being a decision, and nothing is switched off
             * without the closed line saying so.
             */
            $off_count = SFAF_Notifications::off_count( $event_id );
            ?>
            <div class="uc-notify-section">
                <?php // The same disclosure the team picker uses: a real <summary>,
                      // so click, tap, Enter and Space all work with no script. ?>
                <details class="uc-notify-kinds" data-uc-disclosure <?php echo $off_count ? 'open' : ''; ?>>
                    <summary class="uc-team-add-toggle" aria-expanded="<?php echo $off_count ? 'true' : 'false'; ?>">
                        <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '16px' ) ); ?></span>
                        <span>
                            <?php if ( ! $off_count ) : ?>
                                Emails for this event: all four are on
                            <?php else : ?>
                                Emails for this event: <?php echo (int) $off_count; ?> of 4 switched off
                            <?php endif; ?>
                        </span>
                    </summary>
                    <div class="uc-team-add-body">
                        <p class="uc-hint">Untick one to stop it for this event only.</p>
                        <input type="hidden" name="uc_notify_kinds_present" value="1" />
                        <?php foreach ( SFAF_Notifications::kinds() as $key => $kind ) : ?>
                            <label class="uc-check">
                                <input type="checkbox" name="notify_kinds[]" value="<?php echo esc_attr( $key ); ?>"
                                       <?php checked( SFAF_Notifications::on( $event_id, $key ) ); ?> />
                                <?php echo esc_html( $kind['label'] ); ?>
                            </label>
                            <p class="uc-hint uc-notify-kind-note"><?php echo esc_html( $kind['note'] ); ?></p>
                        <?php endforeach; ?>
                    </div>
                </details>
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
                    <?php // The same mark every disclosure in caladmin carries.
                          // It drew its own text triangle in CSS until 3.64.0. ?>
                    <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '16px' ) ); ?></span>
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
                                 * THIS BOX IS NOT A FIELD ON THE EVENT, AND
                                 * THAT DISTINCTION USED TO BE LOAD-BEARING.
                                 *
                                 * It changes nothing, saves nothing and posts
                                 * nothing: it narrows the list below it. The
                                 * edit-scope lock used to walk every input in
                                 * the form and make it readonly until that
                                 * field's pencil was pressed, which made this
                                 * picker unusable at any real size on exactly
                                 * the events that need it most, since a
                                 * recurring event is the one with a scope
                                 * choice. It carried data-uc-not-a-field to opt
                                 * out of that walk.
                                 *
                                 * The pencils went in 3.23.0 and nothing walks
                                 * the form's inputs any more, so the attribute
                                 * went with the code that read it rather than
                                 * being left as markup nothing consults. The
                                 * category it recorded is written down here
                                 * instead: if anything ever again treats "every
                                 * input in this form" as "every field on this
                                 * event", this control is the counterexample.
                                 */
                                ?>
                                <input type="search" placeholder="Type to filter people…"
                                       data-uc-picker-filter="people" autocomplete="off" />
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
                            <p class="uc-muted">No teams yet. An admin can make one under Users &amp; Teams.</p>
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
                            <p class="uc-hint">Picking a team here emails whoever is in it when the message goes out, not whoever is in it today. It does not change who can edit this event: that is the team on the access card.</p>
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
    /**
     * The recurrence scope a save carried back here, or ''.
     *
     * ONE READER, because two would drift: the header decides whether to ask
     * the question and the form decides whether to arrive locked, and those two
     * answers have to be the same answer. Named edit_scope rather than scope
     * because scope is already this portal's mine/all filter and one name
     * meaning two things on one screen is how a value ends up in the wrong link.
     *
     * A query string is checked, not trusted: it must name one of the two
     * scopes. The worst a forged value can do is pre-answer a question the
     * manager can still change from the banner, and the save re-derives the real
     * scope from the POST regardless.
     *
     * @return string 'this', 'all_upcoming' or ''.
     */
    private function carried_edit_scope() {
        if ( ! isset( $_GET['edit_scope'] ) ) {
            return '';
        }
        $asked = sanitize_key( wp_unslash( $_GET['edit_scope'] ) );
        return in_array( $asked, array( 'this', 'all_upcoming' ), true ) ? $asked : '';
    }

    private function render_scope_header( $event_id, $targets, $locked ) {
        $count = count( $targets );
        if ( ! $event_id || $count < 2 ) {
            return;
        }
        ?>
        <?php
        /*
         * ALREADY ANSWERED, WHEN THE SAVE SAID SO.
         *
         * A save redirects here carrying the scope it used, so the question is
         * not put again: the block renders hidden, the banner renders open, and
         * the fieldset is not disabled. Somebody who wants a different scope
         * uses the Change control on the banner, which is what it is for.
         *
         * CHECKED, NOT TRUSTED. It is a query string, so it must name one of
         * the two scopes; anything else asks the question normally. The worst a
         * forged value can do is pre-answer a question the manager can still
         * change, and the save re-derives the real scope server-side anyway.
         */
        $answered = $this->carried_edit_scope();
        ?>
        <div class="uc-scope" data-uc-scope-choice data-uc-scope-count="<?php echo (int) $count; ?>"
             <?php echo $answered ? ' data-uc-scope-answered="' . esc_attr( $answered ) . '" hidden' : ''; ?>
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
                    Choosing a scope needs JavaScript. With it switched off, events in a repeating group
                    cannot be edited here.
                </p>
            </noscript>
        </div>

        <?php // Stays visible while a long form scrolls, because the whole
              // point is that the manager can see what this save will do at the
              // moment they press the button, not only at the moment they
              // chose. It also takes focus when the dialog closes, so the
              // answer is the first thing announced after the question. ?>
        <div class="uc-scope-banner<?php echo ( 'all_upcoming' === $answered ) ? ' uc-scope-banner-all' : ''; ?>"
             data-uc-scope-banner<?php echo $answered ? '' : ' hidden'; ?> role="status" tabindex="-1">
            <span data-uc-scope-banner-text><?php
                echo $answered
                    ? esc_html( ( 'all_upcoming' === $answered )
                        ? sprintf( 'Editing all %d upcoming occurrences.', (int) $count )
                        : 'Editing this event only.' )
                    : '';
            ?></span>
            <button type="button" class="uc-btn uc-btn-sm" data-uc-scope-change>Change</button>
        </div>

        <?php
        /*
         * The chosen scope, and the fields that scope FORBIDS when it is "all
         * upcoming". Both are read straight back by save_event_from_post(),
         * which re-derives the forbidden list server-side rather than trusting
         * this: the markup is the affordance, not the rule.
         */
        ?>
        <input type="hidden" name="edit_scope" value="<?php echo esc_attr( $answered ? $answered : 'this' ); ?>" data-uc-scope-input />
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
        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $orphans  = ! empty( $_GET['orphans'] );

        /*
         * TWO QUESTIONS, AND THIS SCREEN ANSWERS BOTH (3.35.0).
         *
         * Scoped to one event, it asks THE event gate, which is the single
         * function every route asks and which now includes team access. Unscoped
         * it is the whole calendar's registrations and stays on can_view_all,
         * because "every registration on this site" is not a question about any
         * event and no team owns it. The orphan view is the unscoped case by
         * definition: its rows belong to events that no longer exist.
         *
         * This is what carries out §2 of the access model: a team member may see
         * the RSVPs for events their team owns and nothing else. It also widens
         * the scoped view to a contributor reading their OWN event's
         * registrations, which it did not before. That is deliberate rather than
         * incidental: the alternative is a second rule saying team members may
         * read an event's registrations but the person responsible for it may
         * not, and a second rule is exactly what this release exists to remove.
         */
        if ( $event_id ) {
            $event = get_post( $event_id );
            if ( ! $event || 'uc_event' !== $event->post_type || ! $this->can_edit_event( $user, $event ) ) {
                $this->render_dashboard( $user );
                return;
            }
        } elseif ( ! $this->can_view_all( $user ) ) {
            $this->render_dashboard( $user );
            return;
        }

        $this->chrome_open( $user, 'events' );
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
                    <thead><tr><?php if ( ! $event ) : ?><th>Event</th><?php endif; ?><th>First name</th><th>Last name</th><th>Email</th><th>Phone</th><th>Updates</th><th>Status</th><th>Registered</th></tr></thead>
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
                            <td><strong><?php echo esc_html( $r->first_name ); ?></strong></td>
                            <?php
                            /*
                             * AN EMPTY LAST NAME IS A NORMAL ROW, NOT A GAP.
                             *
                             * The field is optional on the form for people
                             * registering for testing and for trans health
                             * groups, so a blank cell here is somebody
                             * exercising that. A dash says "nothing was given",
                             * which is the fact; leaving the cell empty reads
                             * as a column that failed to render.
                             */
                            ?>
                            <td><?php
                                $last = trim( (string) $r->last_name );
                                echo '' !== $last ? esc_html( $last ) : '<span class="uc-muted">&ndash;</span>';
                            ?></td>
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
                            <td><?php echo esc_html( sfaf_ap_datetime( $r->created_at ) ); ?></td>
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

    /**
     * What the pending queue asks for. ONE DEFINITION (3.47.0).
     *
     * The screen and the count beside Pending in the nav both read this, so a
     * badge can never count a different set from the list it links to. It is
     * also what .claude/pending-queue-test.php runs, which is the whole reason
     * it is a method rather than an array written out twice: a test holding its
     * own copy of these arguments would keep passing while the screen changed
     * underneath it.
     *
     * SCOPE 'all', SAID OUT LOUD. This queue is not "My Events" and never was.
     * It passed no scope, and an unset scope means "your own plus your teams'",
     * so the queue filtered itself by AUTHORSHIP. Both public forms set
     * post_author to 0 on purpose, because nobody was logged in to author the
     * post, so every staff request and every community submission was excluded
     * from the one screen built to review them, reachable only by the link in
     * its own notification email. The screen is admin-only either way.
     *
     * @return array
     */
    private function pending_query_args() {
        return array( 'status' => 'pending', 'per_page' => 100, 'scope' => 'all' );
    }

    /**
     * The two questions Approve asks about a submission.
     *
     * ONE INTERRUPTION, TWO TICKS. Both decisions are about the same person at
     * the same moment, so they are one dialog. Two prompts in a row is how a
     * manager learns to press the second one without reading it.
     *
     * THE MARKUP IS A PLAIN PANEL IN THE PAGE. portal.js moves it into a real
     * <dialog> and opens it modally when Approve is pressed; with no
     * JavaScript, or no <dialog>, it stays visible beside the button and the
     * ticks work exactly as they read. That is the same arrangement the
     * recurrence scope question uses, and the same reason: the enhancement can
     * fail and leave a usable screen.
     *
     * THE INPUTS CARRY `form=`, so they post with the Approve form wherever
     * they physically sit. Without that, moving the panel into a dialog
     * appended to <body> would detach them and both answers would arrive as
     * "unticked" no matter what was pressed.
     *
     * NO ADDRESS MEANS NO OFFER. An event whose submitter left no usable
     * address gets a sentence saying so rather than two ticks that would send
     * to nothing, because a tick that cannot do anything still reads as a
     * promise that it did.
     *
     * @param int   $event_id
     * @param array $who From SFAF_Submissions::submitter().
     */
    private function render_approve_ask( $event_id, $who ) {
        if ( empty( $who['is_submission'] ) ) {
            return;
        }
        $form = 'uc-approve-' . (int) $event_id;
        ?>
        <div class="uc-approve-ask" id="uc-approve-ask-<?php echo (int) $event_id; ?>"
             data-uc-ask-panel
             data-uc-approve-form="<?php echo esc_attr( $form ); ?>">
            <?php if ( empty( $who['usable'] ) ) : ?>
                <p class="uc-hint">
                    No usable email address came with this one, so nothing can be sent to whoever submitted it.
                </p>
            <?php else : ?>
                <p class="uc-approve-who">
                    Submitted by <strong><?php echo esc_html( $who['name'] ); ?></strong>
                    <span class="uc-muted"><?php echo esc_html( $who['email'] ); ?></span>
                </p>
                <?php
                /*
                 * THE OUTCOME NOTICE IS FOR COMMUNITY SUBMISSIONS ONLY
                 * (3.72.0).
                 *
                 * A staff request comes from somebody with a desk here who
                 * already got a confirmation saying the MarCom team would look
                 * at it, and who can open caladmin and see what happened. A
                 * community submission comes from somebody outside SFAF with no
                 * account and no way to find out at all: the only thing that
                 * can tell them is a message. So the offer is made where it is
                 * the only route and not where it is a second one.
                 *
                 * The tick disappearing rather than being greyed is deliberate:
                 * there is no decision to take on a staff request, so a control
                 * would be asking a question with one answer.
                 */
                if ( SFAF_Submissions::KIND_COMMUNITY === SFAF_Submissions::kind( $event_id ) ) :
                    ?>
                    <label class="uc-check">
                        <input type="checkbox" name="tell_submitter" value="1" form="<?php echo esc_attr( $form ); ?>" />
                        Email <?php echo esc_html( $who['name'] ); ?> that this event is published
                    </label>
                <?php endif; ?>
                <?php
                /*
                 * TICKED BY DEFAULT, AND THE WORDING SAYS BOTH THINGS.
                 *
                 * Somebody running an event who does not get their own
                 * registrations has a real problem, so the useful default is
                 * on. What the notification list actually carries is TWO
                 * messages, not one, and the second of them lists every
                 * registrant by name and address. A label saying only
                 * "registrations" would be describing half of what the tick
                 * does, to the person deciding whether to do it.
                 */
                ?>
                <?php
                /*
                 * AND IT NAMES THE OTHER ADDRESSES (3.67.0). The community
                 * form takes up to SFAF_Submit::MAX_EMAILS, so one tick can
                 * put five people on a list that is sent registrant names and
                 * addresses. Every one of them is printed here, because the
                 * person deciding cannot decide about a set they cannot see.
                 */
                $addresses = SFAF_Submissions::notify_addresses( $event_id );
                $others    = array_slice( $addresses, 1 );
                ?>
                <label class="uc-check">
                    <input type="checkbox" name="notify_submitter" value="1" checked form="<?php echo esc_attr( $form ); ?>" />
                    <?php if ( empty( $others ) ) : ?>
                        Send <?php echo esc_html( $who['name'] ); ?> registrations for this event
                    <?php else : ?>
                        Send registrations for this event to <?php echo esc_html( $who['name'] ); ?>
                        and <?php echo (int) count( $others ); ?> more
                    <?php endif; ?>
                </label>
                <?php if ( ! empty( $others ) ) : ?>
                    <p class="uc-hint uc-approve-others">
                        <?php echo esc_html( implode( ', ', $others ) ); ?>
                    </p>
                <?php endif; ?>
                <p class="uc-hint">
                    They get an email each time somebody registers, and a list of everybody registered
                    on the morning of the event, with names and email addresses. Untick it if that is not right.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * What Reject asks, for a community submission with a usable address.
     *
     * THE MIRROR OF render_approve_ask(), AND DELIBERATELY NOT THE SAME METHOD.
     * The two ask different questions of different shapes: approving offers two
     * ticks about a person's future mail, rejecting offers one tick and a box.
     * Folding them into one renderer with a mode flag would be the thing
     * PROJECT.md 7 warns about, a single method deciding what it is allowed to
     * show.
     *
     * COMMUNITY ONLY, for the reason the published notice is community only: a
     * staff requester has an account and a colleague to ask, and somebody
     * outside SFAF has neither.
     *
     * UNTICKED BY DEFAULT, WHICH IS THE OPPOSITE OF THE REGISTRATIONS TICK
     * ABOVE. That one has a useful default because an organizer who does not
     * get their own registrations has a real problem. This one tells somebody
     * no, and a message that goes out because nobody untangled a default is not
     * a decision anybody took. The consent rule in PROJECT.md 4 is the same
     * rule: only an explicit send sends.
     *
     * NOTHING IS STORED WHEN NOTHING IS SENT. See reject_event, which reads the
     * note only under the tick.
     *
     * @param int   $event_id
     * @param array $who From SFAF_Submissions::submitter().
     */
    private function render_reject_ask( $event_id, $who ) {
        if ( empty( $who['is_submission'] ) || empty( $who['usable'] ) ) {
            return;
        }
        if ( SFAF_Submissions::KIND_COMMUNITY !== SFAF_Submissions::kind( $event_id ) ) {
            return;
        }
        $form = 'uc-reject-' . (int) $event_id;
        ?>
        <div class="uc-approve-ask uc-reject-ask" id="uc-reject-ask-<?php echo (int) $event_id; ?>"
             data-uc-ask-panel>
            <p class="uc-approve-who">
                Submitted by <strong><?php echo esc_html( $who['name'] ); ?></strong>
                <span class="uc-muted"><?php echo esc_html( $who['email'] ); ?></span>
            </p>
            <label class="uc-check">
                <input type="checkbox" name="tell_rejected" value="1" form="<?php echo esc_attr( $form ); ?>" />
                Email <?php echo esc_html( $who['name'] ); ?> that it is not going on the calendar
            </label>
            <label class="uc-field">
                <span class="uc-field-label">Anything to tell them (optional)</span>
                <textarea name="reject_note" rows="3" maxlength="600"
                          form="<?php echo esc_attr( $form ); ?>"
                          placeholder="We only list events run by SFAF or one of our partners."></textarea>
                <span class="uc-hint">Goes in that email and nowhere else. Leave it blank to send just the decision.</span>
            </label>
            <p class="uc-hint">
                The event is removed either way. Mail cannot be recalled.
            </p>
        </div>
        <?php
    }

    /**
     * The kinds a pending row can be, and what the filter calls each one.
     *
     * ONE LIST, READ BY THE TABS, THE COUNTS, THE FILTER AND THE TEST. A second
     * copy of these keys is how this queue got three marking faults in a row:
     * every one of them was a screen deciding what something was by a rule
     * written somewhere the other screens could not see.
     *
     * 'all' IS FIRST AND IS NOT A KIND. It is the unfiltered list, and it is
     * the default, because a work list that opens already narrowed hides work.
     *
     * @return array<string,string> key => label
     */
    private function pending_kinds() {
        return array(
            'all'       => 'Everything',
            'import'    => 'Imported',
            'staff'     => 'Staff requests',
            'community' => 'Community submissions',
        );
    }

    /**
     * What the one list can be ordered by, and which way each starts.
     *
     * RECEIVED, NEWEST FIRST, IS THE DEFAULT, AND THAT IS THE DECISION.
     *
     * This is a WORK LIST, not a calendar. What arrived most recently is what
     * nobody has looked at yet, and it matters more than what happens soonest:
     * an event three months out that was submitted an hour ago is the thing
     * needing a decision, and an event next week that was reviewed yesterday is
     * not. The Events list sorts by when things HAPPEN because it answers a
     * different question.
     *
     * Event date is offered as well, soonest first, because "what is nearly
     * here and still not published" is a real second question.
     *
     * @return array<string,array{label:string,default:string}>
     */
    private function pending_sorts() {
        return array(
            'received' => array( 'label' => 'Received',   'default' => 'desc' ),
            'date'     => array( 'label' => 'Event date', 'default' => 'asc' ),
        );
    }

    /**
     * When this arrived, as a timestamp.
     *
     * THREE SOURCES, IN THE ORDER THAT KNOWS BEST. An import records the moment
     * it was fetched; a submission records its own moment, because the post
     * date is when the insert ran and those differ once anything queues; and
     * anything else falls back to the post date, which is always there.
     *
     * @param int $id
     * @return int
     */
    public function pending_received( $id ) {
        $id   = (int) $id;
        $prov = SFAF_Sources::provenance( $id );
        if ( ! empty( $prov['imported_at'] ) ) {
            return (int) $prov['imported_at'];
        }

        $at = (string) get_post_meta( $id, SFAF_Request::META_AT, true );
        if ( '' !== $at ) {
            $ts = strtotime( $at );
            if ( $ts ) {
                return (int) $ts;
            }
        }

        return (int) get_the_date( 'U', $id );
    }

    /**
     * Everything waiting for a decision, as one set.
     *
     * TWO SOURCES, ONE LIST, AND THEY CANNOT OVERLAP. Imported events sit in
     * the custom `uc_imported` status and submissions sit in WordPress's own
     * `pending`, so the two queries are disjoint by construction. The id is
     * still the array key, because "cannot overlap" is a fact about today's
     * statuses and a row printed twice is a worse failure than one missing.
     *
     * KIND AND SHAPE ARE DIFFERENT QUESTIONS AND ARE ANSWERED SEPARATELY.
     * `kind` is what it IS, and it drives the badge and the filter. `shape` is
     * which set of actions it takes, and it is decided by which queue it came
     * out of. An imported event that was published and then set back to pending
     * is kind 'import' and shape 'submission': it is still an import, and
     * Publish and Dismiss are no longer what it needs.
     *
     * A ROW WHOSE KIND MATCHES NO FILTER STILL APPEARS UNDER 'Everything'.
     * That is not a leftover. An event a contributor set to pending by hand is
     * kind 'local' and carries no badge, and the one thing that must never
     * happen again here is a pending row that is in no list at all.
     *
     * @param WP_User $user
     * @return array<int,array{kind:string,shape:string}> id => entry
     */
    private function pending_entries( $user ) {
        $entries = array();

        foreach ( SFAF_Sources::queue_ids( SFAF_Sources::STATUS_PENDING ) as $id ) {
            $entries[ (int) $id ] = array( 'kind' => 'import', 'shape' => 'import' );
        }

        foreach ( $this->query_events( $user, $this->pending_query_args() ) as $id ) {
            $id = (int) $id;
            if ( isset( $entries[ $id ] ) ) {
                continue;
            }
            $entries[ $id ] = array(
                'kind'  => SFAF_Submissions::kind( $id ),
                'shape' => 'submission',
            );
        }

        return $entries;
    }

    /**
     * The one list, narrowed and ordered as the controls above it say.
     *
     * THE FILTER NARROWS AND THE SORT ORDERS. Neither decides what is in the
     * queue: that is pending_entries(), asked once, so a row can only ever be
     * hidden by a filter somebody chose and is always found again by choosing
     * 'Everything'.
     *
     * @param array  $entries From pending_entries().
     * @param string $kind    A key of pending_kinds().
     * @param string $orderby A key of pending_sorts().
     * @param string $order   'asc' or 'desc'.
     * @return int[] Ids, in display order.
     */
    private function pending_list( $entries, $kind, $orderby, $order ) {
        $ids = array();
        foreach ( $entries as $id => $entry ) {
            if ( 'all' === $kind || $entry['kind'] === $kind ) {
                $ids[] = (int) $id;
            }
        }

        $portal = $this;
        usort( $ids, function ( $a, $b ) use ( $portal, $orderby, $order ) {
            if ( 'date' === $orderby ) {
                $da = (string) get_post_meta( $a, '_uc_event_date', true );
                $db = (string) get_post_meta( $b, '_uc_event_date', true );
                /*
                 * A DATELESS EVENT SORTS LAST IN BOTH DIRECTIONS, never first.
                 * Plenty of imports arrive with no date at all, by design, and
                 * reversing the sort must not park every one of them at the top
                 * where they push the rows with real dates off the screen.
                 */
                if ( '' === $da && '' === $db ) {
                    return $b - $a;
                }
                if ( '' === $da ) { return 1; }
                if ( '' === $db ) { return -1; }
                $cmp = strcmp( $da, $db );
            } else {
                $cmp = $portal->pending_received( $a ) - $portal->pending_received( $b );
            }

            if ( 0 === $cmp ) {
                $cmp = $a - $b; // a stable order, so a reload cannot reshuffle
            }
            return ( 'desc' === $order ) ? -$cmp : $cmp;
        } );

        return $ids;
    }

    /**
     * The filter tabs, each carrying the current sort and naming its own count.
     *
     * THE COUNT IS ON THE TAB, not only above the list, because the question a
     * manager arrives with is "is there anything from the public waiting", and
     * the tab answers it before anything is clicked.
     *
     * @param array  $entries From pending_entries().
     * @param string $current
     * @param string $orderby
     * @param string $order
     */
    private function pending_tabs( $entries, $current, $orderby, $order ) {
        $counts = array( 'all' => count( $entries ) );
        foreach ( $this->pending_kinds() as $key => $unused ) {
            if ( 'all' !== $key ) {
                $counts[ $key ] = 0;
            }
        }
        foreach ( $entries as $entry ) {
            if ( isset( $counts[ $entry['kind'] ] ) ) {
                $counts[ $entry['kind'] ]++;
            }
        }
        ?>
        <div class="uc-view-tabs" role="navigation" aria-label="Which pending events to show">
            <?php foreach ( $this->pending_kinds() as $key => $label ) :
                $active = ( $key === $current );
                $args   = array_filter( array(
                    'kind'    => ( 'all' === $key ) ? '' : $key,
                    'orderby' => $orderby,
                    'order'   => $order,
                ), function ( $v ) { return '' !== $v && null !== $v; } );
                $url = add_query_arg( $args, $this->url( 'pending' ) );
                ?>
                <a class="uc-view-tab<?php echo $active ? ' uc-view-tab-active' : ''; ?>"
                   href="<?php echo esc_url( $url ); ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                    <?php echo esc_html( $label ); ?>
                    <span class="uc-tab-count"><?php echo (int) $counts[ $key ]; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * The sort control: one link per column, the active one reversing.
     *
     * LINKS, AND THE ORDER LIVES IN THE URL, exactly as the Events list does
     * it, so a view can be linked and survives a reload without a cookie
     * holding state the address bar does not admit to.
     *
     * @param string $kind
     * @param string $orderby
     * @param string $order
     */
    private function pending_sort_control( $kind, $orderby, $order ) {
        ?>
        <div class="uc-queue-sort">
            <span class="uc-queue-sort-label">Sort by</span>
            <?php foreach ( $this->pending_sorts() as $key => $def ) :
                $active = ( $key === $orderby );
                $next   = $active ? ( 'asc' === $order ? 'desc' : 'asc' ) : $def['default'];
                $args   = array_filter( array(
                    'kind'    => ( 'all' === $kind ) ? '' : $kind,
                    'orderby' => $key,
                    'order'   => $next,
                ), function ( $v ) { return '' !== $v && null !== $v; } );
                $url  = add_query_arg( $args, $this->url( 'pending' ) );
                $mark = $active ? ( 'asc' === $order ? ' &#9650;' : ' &#9660;' ) : '';
                ?>
                <a class="uc-queue-sort-link<?php echo $active ? ' uc-queue-sort-active' : ''; ?>"
                   href="<?php echo esc_url( $url ); ?>"<?php echo $active ? ' aria-current="true"' : ''; ?>>
                    <?php echo esc_html( $def['label'] ); ?>
                    <span class="uc-sort-mark" aria-hidden="true"><?php echo $mark; ?></span>
                    <span class="screen-reader-text"><?php
                        echo esc_html( $active
                            ? ( 'asc' === $order ? ', sorted oldest first. Activate to reverse.' : ', sorted newest first. Activate to reverse.' )
                            : ', not sorted by this. Activate to sort.' );
                    ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * The pending queue: one list, with controls above it.
     *
     * WHY THIS IS ONE LIST AND WAS THREE BLOCKS.
     *
     * It used to stack "Imported, pending review", then "Dismissed", then a
     * table headed "Submitted for review". Three blocks meant a manager had to
     * know which block a thing would be in before they could look for it, and
     * the two that were pending work were sorted by different rules and drawn
     * in two different ways: one an unordered list of rich rows, one a
     * five-column table. Nothing about "this needs a decision" differs between
     * an import and a submission, so nothing about the row does either.
     *
     * DISMISSED IS STILL ITS OWN CARD, BELOW, AND THAT IS NOT AN EXCEPTION TO
     * THE RULE. Dismissed is a STATUS, not a kind: those rows have already had
     * their decision taken and are kept only so the fetch never offers them
     * again. Folding them into a work list would put items nobody has to act on
     * among items somebody does.
     *
     * @param WP_User $user
     */
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
        // Rows added by the browser need the editor too. See SFAF_Rich_Text.
        $this->load_editor = true;
        SFAF_Rich_Text::enqueue();

        $this->chrome_open( $user, 'pending' );

        /* The controls, read from the URL and validated against the one list
         * each of them has. An unknown value is not an error page: it is the
         * default, because a mistyped query string should show the queue. */
        $kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'all';
        if ( ! isset( $this->pending_kinds()[ $kind ] ) ) {
            $kind = 'all';
        }

        $sorts   = $this->pending_sorts();
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'received';
        if ( ! isset( $sorts[ $orderby ] ) ) {
            $orderby = 'received';
        }
        $order = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'asc' : 'desc';
        if ( ! isset( $_GET['order'] ) ) {
            $order = $sorts[ $orderby ]['default'];
        }

        $entries = $this->pending_entries( $user );
        $ids     = $this->pending_list( $entries, $kind, $orderby, $order );

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

        <?php
        /*
         * THE FLASH FIRST, THEN THE STANDING BOX. render_fetch_report() is the
         * answer to a button somebody just pressed and is gone on the next
         * load; render_fetch_last_run() is always there and is about the job
         * that runs on its own. Putting the reply to the action above the
         * standing state is the order the person is thinking in, and the two
         * are separately headed so a manual run and a scheduled one are never
         * read as the same event.
         */
        ?>
        <?php $this->render_fetch_report( $user, $active_sources ); ?>
        <?php $this->render_fetch_last_run(); ?>

        <?php $this->pending_tabs( $entries, $kind, $orderby, $order ); ?>

        <div class="uc-card">
            <div class="uc-card-head">
                <h2>Waiting for a decision</h2>
                <?php if ( ! empty( $ids ) ) : ?><span class="uc-count-badge"><?php echo count( $ids ); ?></span><?php endif; ?>
            </div>
            <?php $this->pending_sort_control( $kind, $orderby, $order ); ?>
            <?php if ( empty( $ids ) ) : ?>
                <p class="uc-empty"><?php
                    /* THE EMPTY MESSAGE SAYS WHICH LIST IS EMPTY. "Nothing
                     * waiting" under a filter that is hiding four rows is a
                     * screen telling somebody their work is done when it is
                     * not. */
                    echo ( 'all' === $kind )
                        ? 'Nothing is waiting for a decision.'
                        : esc_html( 'Nothing waiting under ' . $this->pending_kinds()[ $kind ] . '. Choose Everything to see the rest of the queue.' );
                ?></p>
            <?php else : ?>
                <ul class="uc-queue-list">
                    <?php foreach ( $ids as $id ) : ?>
                        <?php $this->pending_row( $id, $entries[ $id ] ); ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php $this->render_dismissed_queue(); ?>
        <?php
        $this->chrome_close();
    }

    /**
     * One row of the pending queue, whatever kind it is.
     *
     * THE HEAD OF EVERY ROW IS THE SAME AND THE FOOT IS NOT. A manager scanning
     * this list is asking "which event is this" first and "what do I do about
     * it" second, and the first question has one answer for every kind: a
     * title, a badge saying where it came from, and one quiet line of detail.
     * What differs is the decision available, so that is what differs in the
     * markup: an import takes Publish and Dismiss, a submission takes Approve
     * and Reject and carries the two questions Approve asks.
     *
     * `data-uc-id` IS THE ROW'S IDENTITY AND IS READ BY THE TEST. Three faults
     * on this screen were marking or filtering problems that source-string
     * checks could not see, so .claude/pending-queue-test.php renders this
     * screen and reads the ids back out of the HTML. It is a real attribute
     * doing real work, not a test hook bolted on: an id is what every action
     * on the row posts.
     *
     * @param int   $id
     * @param array $entry From pending_entries(): kind and shape.
     */
    private function pending_row( $id, $entry ) {
        $id    = (int) $id;
        $kind  = $entry['kind'];
        $shape = $entry['shape'];

        $date = (string) get_post_meta( $id, '_uc_event_date', true );

        /*
         * WHAT TO CALL IT. An import is badged with the platform it came from,
         * because "GoFundMe Pro" is more use than "Imported" when two platforms
         * are connected. A submission is badged by kind, which is the one
         * question the 3.46.0 fault got wrong, and it is asked of
         * SFAF_Submissions::kind() rather than inferred from a field.
         */
        $prov  = SFAF_Sources::provenance( $id );
        $badge = ( 'import' === $kind )
            ? ( $prov['label'] ? $prov['label'] : 'Imported' )
            : SFAF_Submissions::kind_label( $kind );

        /* Fields the platform will never supply and nobody has filled in yet.
         * Amber and a mark, never red: a campaign arrives needing these EVERY
         * time by design, so it is a step in the job and not a fault.
         *
         * AND THE SAME MARK ON AN EVENT THAT ARRIVED WITH NO LOCATION (3.68.0).
         * Neither public form requires one: the staff form asks for a venue OR
         * a typed address and accepts neither, and that stays deliberate,
         * because both forms land here and a manager sees them before anything
         * is published. What was missing is that this row said nothing about
         * it, so an event with nowhere to be looked exactly like one without.
         * Extended rather than a second mechanism: the same icon, the same
         * amber row, and the wording out of the same field_phrase(). An online
         * event answers "filled", because it has nowhere to be on purpose. */
        $needs   = ( 'import' === $shape )
            ? SFAF_Sources::missing_manager_fields( $id )
            : SFAF_Sources::missing_fields( $id, array( 'location' ) );
        $needs_t = ! empty( $needs ) ? 'Needs ' . SFAF_Sources::field_phrase( $needs ) : '';

        /* The submitted file, where it can be seen. A working copy rather than
         * the published image, so it is shown and never set as the thumbnail.
         * The folder is meant to be emptied, and when it has been
         * SFAF_Uploads::url() answers '' and this simply is not drawn. */
        $shot_id = (int) get_post_meta( $id, SFAF_Submit::META_IMAGE, true );
        $shot    = ( 'submission' === $shape ) ? SFAF_Uploads::url( $shot_id, 'thumbnail' ) : '';

        /* Who sent it, for the two questions Approve asks. One reader for both
         * forms; see SFAF_Submissions::submitter(). */
        $who = ( 'submission' === $shape )
            ? SFAF_Submissions::submitter( $id )
            : array( 'name' => '', 'email' => '', 'usable' => false, 'is_submission' => false );
        ?>
        <li class="uc-queue-item<?php echo $needs_t ? ' uc-queue-item-needs' : ''; ?>"
            data-uc-id="<?php echo $id; ?>" data-uc-kind="<?php echo esc_attr( $kind ); ?>">
            <div class="uc-queue-row">
                <?php if ( '' !== $shot ) : ?>
                    <div class="uc-submitted-shot">
                        <a class="uc-submitted-thumb" href="<?php echo esc_url( SFAF_Uploads::url( $shot_id, 'full' ) ); ?>" target="_blank" rel="noopener">
                            <img src="<?php echo esc_url( $shot ); ?>" alt="" loading="lazy" />
                        </a>
                        <?php
                        /*
                         * "USE THIS IMAGE" (3.72.0).
                         *
                         * WHAT IT REPLACES. A submitted file has always been
                         * shown here and has never been the event's picture: it
                         * is stored under SFAF_Submit::META_IMAGE and
                         * set_post_thumbnail() is deliberately not called on it,
                         * so an approver who wanted to use it had to download it
                         * from this link and upload it again through the media
                         * library. That is the whole of the gap this closes: one
                         * press, no download, no re-upload.
                         *
                         * IT IS ONE PRESS AND NOT A DEFAULT, and that has not
                         * changed. The reason the upload is not the thumbnail
                         * automatically is that nobody has looked at it yet: it
                         * arrived from a public form, and a picture on the
                         * public calendar is a decision somebody takes. This
                         * control is where they take it, next to the picture.
                         *
                         * ALREADY THE PICTURE MEANS NO BUTTON. Pressing it twice
                         * does nothing the first press did not, and a control
                         * that is offered when it would change nothing is one
                         * somebody presses to find out.
                         */
                        $is_thumb = ( $shot_id && (int) get_post_thumbnail_id( $id ) === (int) $shot_id );
                        ?>
                        <?php if ( $is_thumb ) : ?>
                            <p class="uc-submitted-note">This is the event's picture.</p>
                        <?php else : ?>
                            <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>" class="uc-inline-form">
                                <input type="hidden" name="uc_action" value="use_submitted_image" />
                                <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>" />
                                <?php wp_nonce_field( 'uc_portal_use_submitted_image', 'uc_nonce' ); ?>
                                <button type="submit" class="uc-btn uc-btn-sm">Use this image</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="uc-queue-id">
                    <h3 class="uc-queue-title">
                        <a href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></a>
                        <?php if ( '' !== $badge ) : ?>
                            <span class="uc-source-badge uc-badge-<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( $badge ); ?></span>
                        <?php endif; ?>
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

                    <?php
                    /*
                     * ONE QUIET LINE, in the order somebody reads it: when it
                     * is, then where, then who sent it, then when that was.
                     * Every part is omitted when there is nothing to say rather
                     * than printed as "not set", which on a queue of imports
                     * would be the same three words on every row.
                     */
                    ?>
                    <p class="uc-queue-meta">
                        <span><?php echo esc_html( $this->pending_when( $id, $date, $prov ) ); ?></span>
                        <?php $location = sfaf_event_location_short( $id ); ?>
                        <?php if ( '' !== $location ) : ?>
                            <span><?php echo esc_html( $location ); ?></span>
                        <?php endif; ?>
                        <?php if ( 'submission' === $shape ) : ?>
                            <span><?php
                                /*
                                 * post_author is 0 on both forms, because nobody
                                 * was logged in, so "who sent it" reads the
                                 * submitter's own name rather than "Unknown".
                                 */
                                if ( '' !== $who['name'] ) {
                                    echo esc_html( 'From ' . $who['name'] );
                                } else {
                                    $author = get_userdata( get_post_field( 'post_author', $id ) );
                                    echo esc_html( 'From ' . ( $author ? $author->display_name : 'somebody with no account' ) );
                                }
                            ?></span>
                        <?php endif; ?>
                        <span class="uc-muted"><?php
                            echo esc_html( 'Received ' . sfaf_ap_date( $this->pending_received( $id ), 'short_year' ) );
                        ?></span>
                    </p>

                    <?php if ( 'import' === $shape && $prov['source_url'] ) : ?>
                        <p class="uc-queue-links">
                            <a class="uc-source-link<?php echo $needs_t ? ' uc-source-link-strong' : ''; ?>" href="<?php echo esc_url( $prov['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php
                                echo $needs_t ? 'Open campaign page to copy them &nearr;' : 'View on ' . esc_html( $prov['label'] ? $prov['label'] : 'source' ) . ' &nearr;';
                            ?></a>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="uc-queue-actions">
                    <div class="uc-actions">
                        <?php if ( 'import' === $shape ) : ?>
                            <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>">
                                <input type="hidden" name="uc_action" value="import_publish" />
                                <input type="hidden" name="event_id" value="<?php echo $id; ?>" />
                                <?php wp_nonce_field( 'uc_portal_import_publish', 'uc_nonce' ); ?>
                                <button class="uc-link-ok" type="submit">Publish</button>
                            </form>
                            <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>">
                                <input type="hidden" name="uc_action" value="import_dismiss" />
                                <input type="hidden" name="event_id" value="<?php echo $id; ?>" />
                                <?php wp_nonce_field( 'uc_portal_import_dismiss', 'uc_nonce' ); ?>
                                <button class="uc-action-link" type="submit">Dismiss</button>
                            </form>
                        <?php else : ?>
                            <a class="uc-action-link" href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank" rel="noopener">Preview</a>
                            <?php
                            /*
                             * EDIT IS WHERE THE SUBMITTER'S OWN WORDS ARE READ.
                             * What they asked for that the event cannot hold,
                             * the repeat answer and the notes to whoever
                             * approves, is render_request_panel() at the top of
                             * the editor. It is one panel, in one place, rather
                             * than a second copy on this row that could fall
                             * behind it.
                             */
                            ?>
                            <a class="uc-action-link" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>">Edit</a>
                            <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>"
                                  id="uc-approve-<?php echo $id; ?>">
                                <input type="hidden" name="uc_action" value="approve_event" />
                                <input type="hidden" name="event_id" value="<?php echo $id; ?>" />
                                <?php wp_nonce_field( 'uc_portal_approve_event', 'uc_nonce' ); ?>
                                <button class="uc-link-ok" type="submit"
                                    <?php echo $who['is_submission'] ? ' data-uc-approve-ask="uc-approve-ask-' . $id . '"' : ''; ?>>Approve</button>
                            </form>
                            <?php
                            /*
                             * REJECT ASKS THE WAY APPROVE ASKS (3.72.0).
                             *
                             * It was a browser confirm() reading "Reject and
                             * remove this event?", which cannot carry a
                             * control, so there was nowhere to write the one
                             * thing somebody rejecting an event usually wants
                             * to say. It now opens the same <dialog> Approve
                             * does, holding the note and the tick that sends
                             * it. With no script the panel is visible beside
                             * the button and both still post.
                             */
                            ?>
                            <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>"
                                  id="uc-reject-<?php echo $id; ?>">
                                <input type="hidden" name="uc_action" value="reject_event" />
                                <input type="hidden" name="event_id" value="<?php echo $id; ?>" />
                                <?php wp_nonce_field( 'uc_portal_reject_event', 'uc_nonce' ); ?>
                                <button class="uc-link-danger" type="submit"
                                        data-uc-approve-ask="uc-reject-ask-<?php echo $id; ?>"
                                        data-uc-ask-title="Reject this event?"
                                        data-uc-ask-confirm="Reject" data-uc-ask-danger>Reject</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ( 'submission' === $shape ) : ?>
                        <?php $this->render_approve_ask( $id, $who ); ?>
                        <?php $this->render_reject_ask( $id, $who ); ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( 'import' === $shape ) : ?>
                <?php // Collapsed by default. A queue is for scanning, and a dozen
                      // open panels would stop it being one; opening it is the
                      // moment a manager has chosen this event. ?>
                <details class="uc-queue-panel">
                    <summary>
                        <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '15px' ) ); ?></span>
                        <?php echo $needs_t ? esc_html( $needs_t ) : 'Set the fields this platform does not supply'; ?>
                    </summary>
                    <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>" class="uc-form uc-queue-form">
                        <input type="hidden" name="uc_action" value="save_manager_fields" />
                        <input type="hidden" name="event_id" value="<?php echo $id; ?>" />
                        <?php wp_nonce_field( 'uc_portal_save_manager_fields', 'uc_nonce' ); ?>
                        <?php $this->render_manager_panel( $this->manager_panel_context( wp_get_current_user(), $id, 'queue' ) ); ?>
                        <div class="uc-form-actions">
                            <button type="submit" class="uc-btn uc-btn-primary">Save these fields</button>
                            <a class="uc-action-link" href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>">Open the full editor</a>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </li>
        <?php
    }

    /**
     * When an event is, as one phrase, or what is missing instead.
     *
     * THE TIMEZONE ONLY WHEN IT IS NOT THE SITE'S. "(America/Los_Angeles)"
     * appeared on every row of a queue run by people in America/Los_Angeles,
     * which is a string that can never change anybody's decision and was
     * competing with the title for attention. It is worth saying exactly when
     * it is surprising: an imported event in another zone is a real trap,
     * because the time shown is then not the time a local reader assumes.
     *
     * @param int    $id
     * @param string $date
     * @param array  $prov SFAF_Sources::provenance() for this event.
     * @return string
     */
    private function pending_when( $id, $date, $prov ) {
        $id = (int) $id;
        if ( '' === $date ) {
            return 'No date. Set it when publishing';
        }

        $when  = sfaf_ap_date( $date, 'short' ) . ', ' . date_i18n( 'Y', strtotime( $date . ' 12:00:00' ) );
        $clock = sfaf_ap_time_range(
            (string) get_post_meta( $id, '_uc_start_time', true ),
            (string) get_post_meta( $id, '_uc_end_time', true )
        );
        if ( $clock ) {
            $when .= ', ' . $clock;
        }
        if ( $prov['timezone'] && $prov['timezone'] !== wp_timezone_string() ) {
            $when .= ' (' . $prov['timezone'] . ')';
        }
        return $when;
    }

    /**
     * Imported events somebody has already said no to.
     *
     * ITS OWN CARD, BELOW THE WORK LIST, because a dismissed event has had its
     * decision taken. They are kept so the fetch never offers them again, which
     * is the whole reason the status exists, and nothing here is waiting on
     * anybody. Drawn only when there are some: an empty "Dismissed" heading on
     * every visit is a permanent reminder of nothing.
     */
    private function render_dismissed_queue() {
        $dismissed = SFAF_Sources::queue_ids( SFAF_Sources::STATUS_DISMISSED );
        if ( empty( $dismissed ) ) {
            return;
        }
        ?>
        <div class="uc-card">
            <div class="uc-card-head">
                <h2>Dismissed</h2>
                <span class="uc-count-badge"><?php echo count( $dismissed ); ?></span>
            </div>
            <p class="uc-help">Dismissed events are kept so they are never fetched again. Restore one to put it back in the list above.</p>
            <ul class="uc-queue-list">
                <?php foreach ( $dismissed as $id ) :
                    $id   = (int) $id;
                    $prov = SFAF_Sources::provenance( $id );
                    $date = (string) get_post_meta( $id, '_uc_event_date', true );
                    ?>
                    <li class="uc-queue-item" data-uc-id="<?php echo $id; ?>" data-uc-kind="dismissed">
                        <div class="uc-queue-row">
                            <div class="uc-queue-id">
                                <h3 class="uc-queue-title">
                                    <a href="<?php echo esc_url( $this->url( 'events/edit/' . $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: '(untitled)' ); ?></a>
                                    <span class="uc-source-badge uc-badge-import"><?php echo esc_html( $prov['label'] ? $prov['label'] : 'Imported' ); ?></span>
                                </h3>
                                <p class="uc-queue-meta">
                                    <span><?php echo esc_html( $this->pending_when( $id, $date, $prov ) ); ?></span>
                                </p>
                            </div>
                            <div class="uc-queue-actions">
                                <div class="uc-actions">
                                    <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>">
                                        <input type="hidden" name="uc_action" value="import_publish" />
                                        <input type="hidden" name="event_id" value="<?php echo $id; ?>" />
                                        <?php wp_nonce_field( 'uc_portal_import_publish', 'uc_nonce' ); ?>
                                        <button class="uc-link-ok" type="submit">Publish</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url( $this->url( 'pending' ) ); ?>">
                                        <input type="hidden" name="uc_action" value="import_restore" />
                                        <input type="hidden" name="event_id" value="<?php echo $id; ?>" />
                                        <?php wp_nonce_field( 'uc_portal_import_restore', 'uc_nonce' ); ?>
                                        <button class="uc-action-link" type="submit">Restore</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
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
    /**
     * What a staff request asked for, above the form that answers it.
     *
     * TWO THINGS THE EVENT ITSELF CANNOT HOLD. The repeat answer is in plain
     * words rather than a pattern, deliberately, because generating a schedule
     * from an unapproved request would be creating fifty-two posts on somebody
     * else's say-so; and the notes are addressed to whoever approves this, not
     * to a reader of the calendar, so they must never reach the event body.
     *
     * Read-only. Everything here is a record of what was asked, and the form
     * below is where the answer is given. Nothing on this panel posts.
     *
     * @param int $event_id
     */
    private function render_request_panel( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return;
        }
        $email = (string) get_post_meta( $event_id, SFAF_Request::META_EMAIL, true );
        if ( '' === $email ) {
            return;
        }

        $name   = (string) get_post_meta( $event_id, SFAF_Request::META_NAME, true );
        $at     = (string) get_post_meta( $event_id, SFAF_Request::META_AT, true );
        $repeat = (string) get_post_meta( $event_id, SFAF_Request::META_REPEAT, true );
        $notes  = (string) get_post_meta( $event_id, SFAF_Request::META_NOTES, true );
        $where  = (string) get_post_meta( $event_id, SFAF_Request::META_VENUE, true );

        /*
         * ONE PANEL, TWO KINDS, AND IT ASKS WHICH (3.46.0).
         *
         * A community submission carries fields a staff request never has,
         * and the person reading this needs to know which sort of stranger
         * sent it: a colleague to chase, or a member of the public whose
         * name must not end up on the listing. Same panel, because it is
         * the same question being answered, and the extra rows are drawn
         * only where they exist.
         */
        $kind      = SFAF_Submissions::kind( $event_id );
        $community = ( SFAF_Submissions::KIND_COMMUNITY === $kind );
        $shot_id   = (int) get_post_meta( $event_id, SFAF_Submit::META_IMAGE, true );
        $shot      = SFAF_Uploads::url( $shot_id, 'medium' );
        $cost      = (string) get_post_meta( $event_id, SFAF_Submit::META_COST, true );
        $age       = (string) get_post_meta( $event_id, SFAF_Submit::META_AGE, true );
        $rsvp_url  = (string) get_post_meta( $event_id, SFAF_Submit::META_RSVP_URL, true );

        /*
         * THE CONTACT, THROUGH THE ONE FORMATTER (3.67.0).
         *
         * This read SFAF_Submit::META_CONTACT, the single open box that 3.47.0
         * replaced with three fields, and the public form has not written that
         * key since. So this line rendered empty on every community submission
         * from 3.47.0 onwards and the name, email and phone the submitter
         * filled in were invisible to whoever approved it. Nothing was lost:
         * the values were stored the whole time, under the three keys the event
         * page already reads.
         *
         * sfaf_event_public_contact() IS THAT READER, and it is asked here
         * rather than the three keys being assembled again. It prefers the
         * three-part answer and falls back to the old box, so a submission from
         * 3.46.0 still shows, and this panel and the event page cannot disagree
         * about what the public contact is.
         */
        $contact   = sfaf_event_public_contact( $event_id );

        /* Everybody the submitter asked to have told about registrations. The
         * first is the submitter, already named above, so only the rest. */
        $also_tell = array_slice( SFAF_Submissions::notify_addresses( $event_id ), 1 );
        ?>
        <div class="uc-card uc-request-panel">
            <div class="uc-card-head"><h2><?php echo $community ? 'Submitted by a member of the public' : 'Requested by a colleague'; ?></h2></div>
            <?php if ( $community ) : ?>
                <p class="uc-hint">Their name and address are for reaching them about this event. Neither is shown on the listing.</p>
            <?php endif; ?>
            <p class="uc-request-who">
                <strong><?php echo esc_html( '' !== $name ? $name : $email ); ?></strong>
                <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
                <?php if ( '' !== $at ) : ?>
                    <span class="uc-muted">asked on <?php echo esc_html( sfaf_ap_date( $at, 'short_year' ) ); ?></span>
                <?php endif; ?>
            </p>
            <?php if ( '' !== $repeat ) : ?>
                <p class="uc-hint"><strong>Repeating:</strong> <?php echo esc_html( $repeat ); ?>.
                    Said in words rather than set as a schedule, because generating the dates is a decision
                    taken here. Use the Repeats control below.</p>
            <?php endif; ?>
            <?php if ( '' !== $where ) : ?>
                <p class="uc-hint"><strong>Place given as:</strong> <?php echo esc_html( $where ); ?>. Add it as a venue if it will be used again.</p>
            <?php endif; ?>
            <?php if ( ! empty( $also_tell ) ) : ?>
                <p class="uc-hint"><strong>They also asked to tell:</strong> <?php echo esc_html( implode( ', ', $also_tell ) ); ?>.
                    Approving with the registrations tick puts all of these on this event's notification list.</p>
            <?php endif; ?>
            <?php if ( '' !== $contact ) : ?>
                <p class="uc-hint"><strong>Contact for the listing:</strong> <?php echo esc_html( $contact ); ?>. This one is shown on the event page.</p>
            <?php endif; ?>
            <?php if ( '' !== $cost ) : ?>
                <p class="uc-hint"><strong>Cost:</strong> <?php echo esc_html( $cost ); ?>. Nothing is collected here.</p>
            <?php endif; ?>
            <?php if ( '' !== $age ) : ?>
                <p class="uc-hint"><strong>Ages:</strong> <?php echo esc_html( $age ); ?></p>
            <?php endif; ?>
            <?php if ( '' !== $rsvp_url ) : ?>
                <p class="uc-hint"><strong>They register at:</strong> <a href="<?php echo esc_url( $rsvp_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $rsvp_url ); ?></a></p>
            <?php endif; ?>
            <?php if ( '' !== $notes ) : ?>
                <div class="uc-request-notes">
                    <span class="uc-field-label">Anything else they told us</span>
                    <p><?php echo esc_html( $notes ); ?></p>
                </div>
            <?php endif; ?>
            <?php
            /*
             * THE FILE THEY SENT, AND WHAT TO DO WITH IT.
             *
             * It is not the event's picture and pressing Approve will not make
             * it one. The line under it says what the next step actually is,
             * because a thumbnail sitting on a screen with an image picker on
             * it otherwise reads as already set.
             */
            if ( '' !== $shot ) : ?>
                <div class="uc-request-shot">
                    <span class="uc-field-label">The picture they sent</span>
                    <a href="<?php echo esc_url( SFAF_Uploads::url( $shot_id, 'full' ) ); ?>" target="_blank" rel="noopener">
                        <img src="<?php echo esc_url( $shot ); ?>" alt="" loading="lazy" />
                    </a>
                    <p class="uc-hint">Download it, size it, and upload the finished one through the image picker. This file is not used on the event.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

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
        <?php
        /*
         * NO CARD, AND NO HEADING (3.72.0).
         *
         * WHAT WAS ON SCREEN. A card headed "FAQ sets" with a "Manage sets"
         * link, and nothing under it. The picker inside the FAQs card takes
         * over on load and portal.js hides THIS CARD'S FORM, which was the only
         * thing in it that did anything, so what a manager saw with JavaScript
         * working was two FAQ set controls, one of them an empty box with a
         * title.
         *
         * THE FORM STAYS. It is the whole of the no-script path for applying a
         * set: it posts and redirects, which is correct on the server and is
         * the only route somebody without JavaScript has. What goes is the card
         * around it, so there is nothing left to be an empty shell.
         *
         * THE WHOLE BLOCK IS MARKED FOR HIDING, not just the form, which is the
         * actual fix. A wrapper that carries the flash from a no-script apply
         * and nothing else has no reason to be on screen once the live picker
         * is running.
         *
         * "MANAGE SETS" MOVED RATHER THAN BEING DELETED. It is the only route
         * from an event to the screen where sets are made, and it now sits
         * beside the live picker, which is where somebody who has just looked
         * at the list of sets and not found the one they want is standing. See
         * faq_set_picker().
         */
        ?>
        <div class="uc-faq-set-panel" data-uc-faq-fallback-block>

            <?php if ( is_array( $result ) ) : ?>
                <?php if ( ! empty( $result['error'] ) ) : ?>
                    <div class="uc-flash uc-flash-error"><?php echo esc_html( $result['error'] ); ?></div>
                <?php // The 'created' branch that used to be here is gone with
                      // the control that set it. Only applying a set writes this
                      // transient now, so it is either an error or a result. ?>
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
                    <p class="uc-muted">No saved sets yet. Make one on <a href="<?php echo esc_url( $this->url( 'faq-sets' ) ); ?>">FAQ Sets</a> to reuse the same questions across events.</p>
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
                 * THERE IS NO "SAVE THESE AS A SET" ANYWHERE ON AN EVENT NOW.
                 *
                 * It sat here until 3.38.0, moved into the FAQ card, and is
                 * gone entirely from 3.63.0. An event applies a set and holds
                 * its own questions; the shared list is made and maintained on
                 * the FAQ Sets screen, which is the only screen gated for it.
                 */
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * The only screen that makes and maintains sets: create, edit, duplicate,
     * delete.
     *
     * THE WHOLE OF IT IS HERE FROM 3.63.0, and that is the point of the
     * release. An event applies a set and writes questions of its own; nothing
     * on an event adds to the shared list. The old note here said sets were
     * born from a real event, which stopped being true when 3.38.0 added the
     * create form above and stopped being possible when 3.63.0 removed the
     * event-side control.
     *
     * EVERY OPERATION TAKES THE SAME GATE, can_view_all. Creating used to be
     * can_edit_event from an event while editing and deleting were
     * can_view_all, so a contributor could add to a list they could not then
     * correct. Duplication is an operation on the shared list and takes the
     * same gate as its neighbours.
     *
     * @param WP_User $user
     */
    private function render_faq_sets( $user ) {
        if ( ! $this->can_view_all( $user ) ) {
            $this->render_dashboard( $user );
            return;
        }

        /*
         * ANSWERS ARE RICH TEXT, AND THE ROWS ARE BUILT BY THE BROWSER.
         *
         * Set BEFORE chrome_open(), because that writes the document head and
         * the flag is what makes it print WordPress's enqueued styles and
         * scripts into it. Setting it afterwards enqueues into a head that has
         * already gone out, which is the ordering that made a control do
         * nothing before.
         */
        $this->load_editor = true;
        SFAF_Rich_Text::enqueue();

        $this->chrome_open( $user, 'faq-sets' );
        $sets = SFAF_FAQ_Sets::all();

        $faq_err = get_transient( 'sfaf_faq_set_error_' . $user->ID );
        if ( false !== $faq_err ) {
            delete_transient( 'sfaf_faq_set_error_' . $user->ID );
        }
        ?>
        <div class="uc-page-head"><h1>FAQ Sets</h1></div>

        <?php if ( $faq_err ) : ?>
            <div class="uc-flash uc-flash-error"><?php echo esc_html( $faq_err ); ?></div>
        <?php endif; ?>

        <p class="uc-help">
            A set is a reusable group of questions and answers. Applying one <strong>copies</strong> its rows onto an
            event, so editing a set here never changes an event that already used it, and deleting a set never removes
            questions from anything.
        </p>

        <?php
        /*
         * CREATE A SET HERE (3.38.0).
         *
         * There was no way to make one from this screen. The only route was
         * "save these as a set" on an event that already had questions written
         * on it, so the first set could not exist until somebody had typed the
         * questions somewhere else first, and the event editor's set picker
         * renders nothing when there are no sets. Both halves of the reported
         * gap were this one omission.
         *
         * A PLAIN FORM IS CORRECT HERE, and that is not a contradiction of the
         * 3.3.0 rule. That rule is about a control that posts from a screen
         * carrying unsaved work: the event editor. This screen has no unsaved
         * work to discard, so posting and redirecting costs nothing and needs
         * no script.
         */
        ?>
        <details class="uc-card uc-organizer-add" data-uc-disclosure>
            <summary class="uc-add-toggle" aria-expanded="false">
                <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '18px' ) ); ?></span>
                <span>Create a set</span>
            </summary>
            <div class="uc-organizer-add-body">
                <form method="post" action="<?php echo esc_url( $this->url( 'faq-sets' ) ); ?>">
                    <input type="hidden" name="uc_action" value="faq_set_save" />
                    <input type="hidden" name="faq_set_id" value="" />
                    <?php wp_nonce_field( 'uc_portal_faq_set_save', 'uc_nonce' ); ?>

                    <label class="uc-field">
                        <span class="uc-field-label">Set name</span>
                        <input type="text" name="faq_set_name" required placeholder="e.g. Drop-in group basics" />
                    </label>

                    <?php
                    /*
                     * ONE ROW, AND A CONTROL TO ADD ANOTHER.
                     *
                     * This was three fixed pairs, which is too many for a set
                     * with one question, too few for a set with four, and gave
                     * somebody who typed into a row they did not want no way to
                     * take it out except blanking the fields.
                     *
                     * It is the repeater the event editor's FAQ block already
                     * uses, markup for markup, so "+ Add" and the row remove
                     * behave identically in both places and initRepeaters()
                     * drives this one with no new script. Empty rows are
                     * dropped by SFAF_FAQ_Sets::clean_rows() on save and are
                     * never stored as blank questions.
                     */
                    ?>
                    <div class="uc-repeater" data-repeater>
                        <div class="uc-repeater-rows">
                            <?php sfaf_faq_row( array( 'name' => 'faq_set_rows', 'index' => 0 ) ); ?>
                        </div>
                        <button type="button" class="uc-btn uc-btn-sm uc-repeater-add">+ Add FAQ</button>
                        <template class="uc-repeater-tpl">
                            <?php sfaf_faq_row( array( 'name' => 'faq_set_rows' ) ); ?>
                        </template>
                    </div>

                    <div class="uc-form-actions">
                        <button type="submit" class="uc-btn uc-btn-primary">Create set</button>
                    </div>
                    <p class="uc-hint">
                        Blank rows are ignored. Applying a set to an event copies its rows rather than linking
                        to them, so editing the set afterwards never changes an event that already used it.
                    </p>
                </form>
            </div>
        </details>

        <?php if ( empty( $sets ) ) : ?>
            <div class="uc-card">
                <div class="uc-card-head"><h2>0 sets</h2></div>
                <p class="uc-empty">No sets yet. Use &ldquo;Create a set&rdquo; above.</p>
            </div>
        <?php else : ?>
            <?php
            /*
             * COLLAPSED BY DEFAULT, AND A NATIVE DISCLOSURE RATHER THAN SCRIPT.
             *
             * Every set used to render fully expanded, so this screen was every
             * question of every set at once and finding one meant scrolling
             * past all the others.
             *
             * <details> IS THE ELEMENT, for the reason the dashboard's form-link
             * control uses it: it opens with scripting off, the browser gives it
             * keyboard and screen-reader behaviour nothing here has to
             * reproduce, and in-page find still reaches the closed content in
             * browsers that support it. Nothing about this is an initialiser
             * that can fail.
             *
             * EACH ONE IS ITS OWN <details> WITH NO name ATTRIBUTE, which is
             * what lets two be open at once. Giving them a shared name would
             * make the group exclusive and close one when another opens, and
             * somebody comparing two sets needs both.
             *
             * THE COUNT IS THE APPLY DROPDOWN'S FORMAT, "Name (3)", so the same
             * set reads the same in both places.
             */
            $open_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
            ?>
            <?php foreach ( $sets as $set ) : ?>
                <?php $is_open = ( '' !== $open_id && $open_id === $set['id'] ); ?>
                <details class="uc-card uc-faq-set" data-uc-disclosure <?php echo $is_open ? 'open' : ''; ?>>
                    <summary class="uc-faq-set-toggle">
                        <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '18px' ) ); ?></span>
                        <span class="uc-faq-set-name"><?php echo esc_html( $set['name'] ); ?></span>
                        <span class="uc-muted">(<?php echo (int) count( $set['rows'] ); ?>)</span>
                    </summary>

                    <div class="uc-faq-set-body">
                        <form method="post" action="<?php echo esc_url( $this->url( 'faq-sets' ) ); ?>" class="uc-form">
                            <input type="hidden" name="uc_action" value="faq_set_save" />
                            <input type="hidden" name="faq_set_id" value="<?php echo esc_attr( $set['id'] ); ?>" />
                            <?php wp_nonce_field( 'uc_portal_faq_set_save', 'uc_nonce' ); ?>
                            <label class="uc-field">
                                <span class="uc-field-label">Set name</span>
                                <?php
                                /*
                                 * autofocus ON THE COPY, AND ON NOTHING ELSE.
                                 *
                                 * A duplicate lands here named "<original> -
                                 * copy" with this set open, and the caret is
                                 * already in the field that needs changing. The
                                 * attribute is the browser's own, so it needs no
                                 * script; it is printed for at most one set on
                                 * the page, because two autofocus attributes is
                                 * undefined behaviour and the browser picks.
                                 */
                                ?>
                                <input type="text" name="faq_set_name" value="<?php echo esc_attr( $set['name'] ); ?>" required <?php echo $is_open ? 'autofocus' : ''; ?> />
                            </label>
                            <?php $this->faq_repeater( 'faq_set_rows', $set['rows'] ); ?>
                            <div class="uc-form-actions">
                                <button type="submit" class="uc-btn uc-btn-primary">Save set</button>
                            </div>
                        </form>

                        <div class="uc-faq-set-ops">
                            <?php
                            /*
                             * ITS OWN FORM, BESIDE DELETE AND NOT INSIDE THE
                             * EDITOR'S FORM. Forms cannot nest, and duplicating
                             * copies what is STORED rather than what is on
                             * screen, so unsaved edits in the form above are
                             * deliberately not carried into the copy. Save
                             * first, then duplicate.
                             */
                            ?>
                            <form method="post" action="<?php echo esc_url( $this->url( 'faq-sets' ) ); ?>">
                                <input type="hidden" name="uc_action" value="faq_set_duplicate" />
                                <input type="hidden" name="faq_set_id" value="<?php echo esc_attr( $set['id'] ); ?>" />
                                <?php wp_nonce_field( 'uc_portal_faq_set_duplicate', 'uc_nonce' ); ?>
                                <button type="submit" class="uc-btn uc-btn-sm">Duplicate this set</button>
                            </form>

                            <form method="post" action="<?php echo esc_url( $this->url( 'faq-sets' ) ); ?>"
                                  onsubmit="return confirm('Delete this set? Events that already used it keep their questions, because the rows were copied when it was applied.');">
                                <input type="hidden" name="uc_action" value="faq_set_delete" />
                                <input type="hidden" name="faq_set_id" value="<?php echo esc_attr( $set['id'] ); ?>" />
                                <?php wp_nonce_field( 'uc_portal_faq_set_delete', 'uc_nonce' ); ?>
                                <button type="submit" class="uc-link-danger">Delete this set</button>
                            </form>
                        </div>
                        <p class="uc-hint">
                            A copy starts from these questions as they are saved now. Editing either one afterwards leaves the other alone.
                        </p>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
        $this->chrome_close();
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

        // Set by a removal that was refused because the person organizes events.
        // Read once and cleared, so it belongs to the redirect that set it and
        // does not reappear on the next visit to this screen.
        $blocked_key = 'sfaf_remove_user_blocked_' . $user->ID;
        $blocked     = get_transient( $blocked_key );
        if ( $blocked ) {
            delete_transient( $blocked_key );
        }
        if ( ! is_array( $blocked ) || ! isset( $blocked['user_id'], $blocked['events'] ) ) {
            $blocked = null;
        }

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

            <?php
            /*
             * WHAT EACH LEVEL ACTUALLY MEANS, ONE CLICK AWAY.
             *
             * The three levels are three words in a dropdown, and the person
             * choosing between them had nowhere to find out what they grant. So it
             * sits BESIDE that dropdown rather than in a section of its own further
             * up the page: help that is anywhere other than next to the control it
             * explains is help nobody finds at the moment they need it.
             * Closed by default because it is reference rather than something to
             * read every visit, and a <details> because that is the 3.10.0
             * pattern: a real interactive element, so click, tap, Enter and
             * Space all work with no script, and it still opens if portal.js
             * never runs. NOT hover, which has no keyboard equivalent, no touch
             * equivalent and no way to read the contents at leisure.
             */
            ?>
            <details class="uc-role-help" data-uc-disclosure>
                <summary class="uc-role-help-toggle" aria-expanded="false">
                    <span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '18px' ) ); ?></span>
                    <span><strong>What each access level can do</strong></span>
                </summary>
                <div class="uc-role-help-body">
                    <dl class="uc-role-help-list">
                        <dt>Admin</dt>
                        <dd>
                            Sees and manages everything: every event, every registration, the teams, the
                            categories and venues, and who else may use the calendar. Approves events waiting
                            for review. Told when an event loses its organizer.
                        </dd>
                        <dt>Editor</dt>
                        <dd>
                            Sees and edits every event and can view every registration list. Cannot add or
                            remove calendar users and cannot approve events.
                        </dd>
                        <dt>Contributor</dt>
                        <dd>
                            Creates events and edits their own, plus any event owned by a team they are in,
                            and sees the registrations for those. Every other event is read-only, showing
                            what the public calendar already shows.
                        </dd>
                    </dl>
                    <p class="uc-hint">
                        A WordPress administrator always has Admin here, whatever this screen says, and that
                        is changed on the WordPress Users screen rather than this one.
                    </p>
                </div>
            </details>

            <form method="post" action="<?php echo esc_url( $this->url( 'users' ) ); ?>" class="uc-inline-form">
                <input type="hidden" name="uc_action" value="add_user" />
                <?php wp_nonce_field( 'uc_portal_add_user', 'uc_nonce' ); ?>
                <select name="user_id" required>
                    <option value="">Select a WordPress user</option>
                    <?php foreach ( $non_members as $u ) : ?>
                        <option value="<?php echo (int) $u->ID; ?>"><?php
                            echo esc_html( $u->display_name . ' (' . $u->user_email . ')' );
                            echo self::is_site_admin( $u->ID ) ? ', administrator' : '';
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
                              * SAID, RATHER THAN OFFERED AS A CONTROL THAT DOES NOTHING.
                              * A WordPress administrator's access is decided by
                              * manage_options, so a dropdown here would accept a
                              * change and then have no effect. See get_role().
                              *
                              * "Admin (WP Admin)" and not "Admin (fixed)". Fixed
                              * reads as though something had been corrected, and
                              * says nothing about WHY it cannot be changed here.
                              * Naming the thing that decides it answers both.
                              */ ?>
                            <div class="uc-user-fixed">
                                <span class="uc-field-label">Access</span>
                                <strong>Admin (WP Admin)</strong>
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
                    <?php
                    /*
                     * SAVE AND REMOVE SIT TOGETHER, IN THE ROW, BEFORE THE
                     * CATEGORIES DISCLOSURE.
                     *
                     * Remove used to be a second <form> that opened after this
                     * one closed, so it was a SIBLING of the row rather than
                     * part of it, and it rendered under the row's bottom border
                     * as a bare link with nothing tying it to a person. On a
                     * list where only one user can be removed it read as one
                     * "Remove" floating below the whole table.
                     *
                     * Forms cannot nest, so the button stays in this row and
                     * points at its own form by id with the `form` attribute.
                     * The form itself is rendered empty and hidden after the
                     * row. Nothing about which user is removed has changed; it
                     * was always this row's id, but now the screen says so.
                     *
                     * Ordered before the disclosure deliberately: .uc-user-cats
                     * spans the full grid, so anything after it is pushed onto
                     * a new line. The actions were landing under the name
                     * column instead of in the row's third column.
                     */
                    ?>
                    <div class="uc-user-actions">
                        <button class="uc-btn uc-btn-sm uc-btn-primary" type="submit">Save</button>
                        <?php if ( ! $is_self ) : ?>
                            <button type="submit" class="uc-link-danger uc-btn-sm"
                                    form="uc-remove-user-<?php echo (int) $m->ID; ?>"
                                    data-uc-confirm="<?php echo esc_attr( $is_wpadm
                                        ? sprintf( 'Take %s off the calendar list? They keep full calendar access, because they are a WordPress administrator. They come off every team.', $m->display_name )
                                        : sprintf( 'Remove calendar access for %s? They come off every team. Their WordPress account and role are not changed, and you can add them back at any time.', $m->display_name )
                                    ); ?>">Remove</button>
                        <?php else : ?>
                            <span class="uc-muted">(you)</span>
                        <?php endif; ?>
                    </div>
                    <details class="uc-user-cats">
                        <summary><span class="uc-disclosure-chevron" aria-hidden="true"><?php echo sfaf_icon( 'chevron', array( 'size' => '15px' ) ); ?></span>Contributor categories</summary>
                        <p class="uc-hint">Leave all unchecked to allow all categories.</p>
                        <div class="uc-check-grid">
                            <?php if ( ! is_wp_error( $cats ) ) : foreach ( $cats as $c ) : ?>
                                <label class="uc-check"><input type="checkbox" name="categories[]" value="<?php echo (int) $c->term_id; ?>" <?php checked( in_array( $c->term_id, $ucats, true ) ); ?> /> <?php echo esc_html( $c->name ); ?></label>
                            <?php endforeach; endif; ?>
                        </div>
                    </details>
                </form>
                <?php if ( ! $is_self ) : ?>
                    <?php // Carries the fields only. Its button lives in the row above
                          // and reaches it by id. ?>
                    <form method="post" action="<?php echo esc_url( $this->url( 'users' ) ); ?>"
                          id="uc-remove-user-<?php echo (int) $m->ID; ?>" hidden>
                        <input type="hidden" name="uc_action" value="remove_user" />
                        <input type="hidden" name="user_id" value="<?php echo (int) $m->ID; ?>" />
                        <?php wp_nonce_field( 'uc_portal_remove_user', 'uc_nonce' ); ?>
                    </form>

                    <?php
                    /*
                     * THE REASSIGNMENT, SHOWN ONLY FOR THE PERSON WHO WAS JUST REFUSED.
                     *
                     * Not a control on every row: the question "who should take
                     * these on" is meaningless until somebody has asked to remove
                     * this person, and a permanent dropdown offering to move
                     * another person's events is a way to do it by accident. The
                     * refusal names the count; this is where the answer goes.
                     */
                    if ( $blocked && (int) $blocked['user_id'] === (int) $m->ID ) :
                        $n = count( $blocked['events'] );
                        ?>
                        <div class="uc-reassign" role="group" aria-label="Reassign before removing">
                            <p class="uc-reassign-head">
                                <strong><?php echo esc_html( $m->display_name ); ?></strong> organizes
                                <strong><?php echo (int) $n; ?></strong> <?php echo esc_html( 1 === $n ? 'event' : 'events' ); ?>.
                                Choose who takes <?php echo esc_html( 1 === $n ? 'it' : 'them' ); ?> on.
                            </p>
                            <ul class="uc-reassign-events">
                                <?php foreach ( $blocked['events'] as $ev ) : ?>
                                    <li>
                                        <a class="uc-tlink" href="<?php echo esc_url( $this->url( 'events/edit/' . (int) $ev['id'] ) ); ?>"><?php echo esc_html( $ev['title'] ); ?></a>
                                        <span class="uc-muted"><?php echo esc_html( sfaf_status_label( $ev['status'] ) ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <form method="post" action="<?php echo esc_url( $this->url( 'users' ) ); ?>" class="uc-reassign-form">
                                <input type="hidden" name="uc_action" value="remove_user" />
                                <input type="hidden" name="user_id" value="<?php echo (int) $m->ID; ?>" />
                                <?php wp_nonce_field( 'uc_portal_remove_user', 'uc_nonce' ); ?>
                                <label>
                                    New organizer
                                    <select name="reassign_to" required>
                                        <option value="">Choose somebody</option>
                                        <?php foreach ( $this->calendar_people() as $cand ) :
                                            if ( (int) $cand->ID === (int) $m->ID ) { continue; } ?>
                                            <option value="<?php echo (int) $cand->ID; ?>"><?php echo esc_html( $cand->display_name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button type="submit" class="uc-btn uc-btn-primary">Reassign and remove</button>
                            </form>
                        </div>
                    <?php endif; ?>
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
                <p class="uc-section-sub">Named groups of calendar users. An event can give a team access, so everybody on it can edit that event and see who has registered.</p>
            </div>

            <div class="uc-card">
                <div class="uc-card-head">
                    <h2><?php echo count( $teams ); ?> <?php echo esc_html( 1 === count( $teams ) ? 'team' : 'teams' ); ?></h2>
                </div>
                <p class="uc-hint">
                    A team is a name and a set of people, read fresh every time. Adding somebody gives them access to
                    every event the team already has, including ones set up before they joined; taking somebody out
                    removes it. Events can also email a team, which is a separate tick on the event and is off unless
                    somebody sets it.
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
                        <a class="uc-btn uc-btn-sm uc-btn-primary" href="<?php echo esc_url( add_query_arg( 'team_new', 1, $this->url( 'users' ) ) . '#uc-teams' ); ?>">Create a team</a>
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
                                                <span class="uc-visually-hidden">In the team.</span>
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
                                             * A UI control, not a field, exactly like
                                             * the notification picker's filter: this box
                                             * changes nothing and posts nothing. It is
                                             * on the teams screen, which has no scope
                                             * choice at all, so it was never reached by
                                             * the lock that made that distinction
                                             * matter. See the longer note there.
                                             */
                                            ?>
                                            <input type="search" placeholder="Type to filter people&hellip;"
                                                   data-uc-filter autocomplete="off" />
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
         *   (unset)  THIS PERSON'S OWN EVENTS, PLUS THEIR TEAMS'. Whoever they
         *            are, including an admin. This line used to say "anyone who
         *            may view all sees everything", and the code below has
         *            never done that: there is no can_view_all() check on the
         *            unset path, and there never was. A comment describing a
         *            capability check that does not exist is worse than no
         *            comment, because the next caller reads it and omits the
         *            argument. That is exactly how the pending queue came to
         *            filter itself by authorship and hide every public
         *            submission, which have post_author 0 by design.
         *
         *            SO A SCREEN THAT MUST SHOW EVERYTHING SAYS 'all'. It is
         *            one word, and it is the difference between a queue and a
         *            personal list.
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
        /*
         * "MINE" MEANS MINE. "WHAT I CAN ACT ON" IS A WIDER SET (3.35.0).
         *
         * Since teams grant access, the events a contributor may work on are
         * their own PLUS every event owned by a team they are in. Those two
         * cannot be expressed as one WP_Query argument: `author` and `post__in`
         * AND together rather than OR. So the widening is a posts_where filter
         * installed for this query only and removed immediately, which is the
         * same shape as the ordering filter above it.
         *
         * 'mine' still means authored-by-me, unchanged and deliberately: it is
         * the label on a toggle a person reads, and quietly making "My events"
         * mean "my events and four other people's" would be a worse answer than
         * the narrow one.
         */
        $scope        = isset( $args['scope'] ) ? (string) $args['scope'] : '';
        $where_filter = null;

        if ( 'all' === $scope ) {
            // Nothing added: the caller has said so in as many words.
        } elseif ( 'mine' === $scope && $this->can_view_all( $user ) ) {
            // An admin or an editor asking to see only their own. A filter on a
            // list they may see either way, not a gate, so it stays literal.
            $q['author'] = $user->ID;
        } else {
            /*
             * EVERYTHING THIS PERSON MAY ACT ON: their own events plus every
             * event a team they are in owns. Both the unset default (the
             * dashboard, and every caller predating 3.19.0) and 'mine' for
             * somebody who is not view-all land here, because for them the two
             * questions are the same one and answering them differently is how
             * a team member's own work goes missing from "My events".
             *
             * `author` and `post__in` AND together in WP_Query rather than OR,
             * so the widening is a posts_where installed for this query only
             * and removed immediately. Same shape as the ordering filter above.
             * The ids are absint()ed into the string; nothing here is user
             * input, and it is cast anyway.
             */
            $team_events = SFAF_Teams::events_for_user( $user->ID );
            if ( empty( $team_events ) ) {
                $q['author'] = $user->ID;
            } else {
                global $wpdb;
                $ids  = implode( ',', array_map( 'absint', $team_events ) );
                $mine = (int) $user->ID;
                $where_filter = function ( $where ) use ( $wpdb, $ids, $mine ) {
                    return $where . " AND ( {$wpdb->posts}.post_author = {$mine} OR {$wpdb->posts}.ID IN ({$ids}) )";
                };
                add_filter( 'posts_where', $where_filter );
            }
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
        // rewrite the ORDER BY of every later query on the page, and a
        // posts_where left attached would narrow every later query to one
        // person's events, which on this screen would look like data loss.
        if ( $clause_filter ) {
            remove_filter( 'posts_clauses', $clause_filter );
        }
        if ( $where_filter ) {
            remove_filter( 'posts_where', $where_filter );
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
