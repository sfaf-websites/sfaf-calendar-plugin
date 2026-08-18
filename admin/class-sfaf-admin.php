<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Admin {

    public function register() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        /*
         * PRIORITY 11: remove_submenu_page() can only remove what is already
         * there, and the post type's own "Add New" submenu is added by
         * WordPress at the default 10. See hide_duplicate_submenus().
         */
        add_action( 'admin_menu', array( $this, 'hide_duplicate_submenus' ), 11 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        /*
         * NO SERIES HANDLERS. handle_series_save() and handle_series_action()
         * were the POST targets of the WordPress Series screen, which went in
         * 3.27.0 along with the two other screens /caladmin owns. They are
         * removed with it rather than left listening: a handler with no form
         * pointing at it is an endpoint nobody remembers is there, and the
         * series editor in /caladmin has its own.
         */
        add_action( 'admin_init', array( $this, 'handle_cron_action' ) );
        add_action( 'admin_init', array( $this, 'handle_users_action' ) );
    }

    /**
     * Take the manager-facing entries out of the Events menu.
     *
     * TWO OF THESE ARE NOT OURS TO NOT-REGISTER. "Add New" is added by
     * WordPress for any post type with show_ui, and the taxonomy entries are
     * added from register_taxonomy(). The taxonomies are handled at the
     * registration end with show_in_menu (see SFAF_Post_Types), because that is
     * the flag that means "no menu entry" without also meaning "no metabox".
     * "Add New" has no such flag, so it is removed here.
     *
     * WHAT THIS DOES NOT DO is remove any capability. post-new.php still
     * answers, the Add New button at the top of the Events list still works,
     * and an editor who has bookmarked either still gets there. The menu is a
     * statement about where the work is meant to happen, not a lock.
     */
    public function hide_duplicate_submenus() {
        remove_submenu_page( 'edit.php?post_type=uc_event', 'post-new.php?post_type=uc_event' );

        /*
         * The Shortcode Generator, unlisted rather than unregistered. It is
         * still routed, still capability-checked and still enqueues its assets;
         * it just is not in the menu, because the audience for it is the two
         * people who build pages on this site and they do not need a permanent
         * entry for a screen they use twice a year. The URL is
         * /wp-admin/edit.php?post_type=uc_event&page=uc-shortcode-generator and
         * the readme carries it.
         */
        remove_submenu_page( 'edit.php?post_type=uc_event', 'uc-shortcode-generator' );
    }

    /**
     * THE WORDPRESS MENU IS ADMINISTRATOR CONCERNS ONLY.
     *
     * Everything an event manager does is in /caladmin. What was here as well
     * was a second set of forms over the same data, and two forms over one
     * record drift: they had already diverged on which fields they offered.
     * Removed in 3.27.0, in three different ways depending on what would break:
     *
     *   RSVPs, Series          GONE. /caladmin owns both, the screens there are
     *                          better (a series screen that holds the schedule;
     *                          an RSVP list gated on can_view_all rather than
     *                          on edit_posts), and every link that pointed here
     *                          now points there.
     *   Series migration       GONE. Never run, and after the test data is
     *                          cleared there is nothing left for it to convert.
     *                          A one-way destructive button does not sit in a
     *                          menu with no remaining purpose.
     *   Shortcode Generator    STILL REGISTERED, JUST UNLISTED. It is the only
     *                          thing here /caladmin has no equivalent for, so
     *                          unregistering it would remove a capability
     *                          rather than a duplicate. Registered normally and
     *                          then unlisted in hide_duplicate_submenus(), NOT
     *                          registered with a null parent: add_submenu_page()
     *                          runs plugin_basename() on the parent slug, and
     *                          passing null to a string parameter is deprecated
     *                          on PHP 8.1 and up. Same outcome, no notice.
     *   Add New Event          Menu entry only, via remove_submenu_page()
     *                          below. post-new.php still works, and so does the
     *                          Add New button on the Events list.
     *   Categories, Organizers Menu entry only, via show_in_menu on the
     *                          taxonomies. Their edit-tags screens still answer
     *                          and the metaboxes on the event editor are
     *                          untouched, which matters because show_ui would
     *                          have taken those with it.
     *
     * WHAT STAYS, AND WHY EACH ONE IS AN ADMINISTRATOR'S JOB:
     *
     *   Embed Code       Site admins set these up on other sites.
     *   Calendar Users   The FALLBACK, and the reason it exists. If /caladmin
     *                    will not let somebody in, this is where that gets
     *                    fixed, and it must not need /caladmin to work. That is
     *                    the trap 3.7.0 walked into.
     *   Settings         Credentials and the Maps key. Configuration.
     *   Automation       manage_options already, and it holds the test send.
     */
    public function add_menu_pages() {
        // Shortcode Generator (produces [sfaf_calendar] blocks for pages on
        // THIS site). Registered here, unlisted in hide_duplicate_submenus().
        add_submenu_page(
            'edit.php?post_type=uc_event',
            'Shortcode Generator',
            'Shortcode Generator',
            'edit_posts',
            'uc-shortcode-generator',
            array( $this, 'render_shortcode_generator' )
        );

        // Embed Code (produces an HTML block for OTHER sites)
        add_submenu_page(
            'edit.php?post_type=uc_event',
            'Embed Code',
            'Embed Code',
            'edit_posts',
            'uc-embed',
            array( $this, 'render_embed_page' )
        );

        // Settings / Integrations
        /*
         * AUTOMATION IS AN ADMINISTRATOR SCREEN, NOT AN EVENT MANAGER ONE.
         *
         * It lived in /caladmin until 2.13.0, gated on the plugin's own
         * "calendar admin" role. That was the wrong gate for the wrong
         * audience: the run log, the cron URL and "Run now" are facts about
         * how the server is configured, and the people who manage events can
         * neither act on them nor fix them. manage_options is the capability
         * that actually corresponds to "may change how this site runs".
         */
        add_submenu_page(
            'edit.php?post_type=uc_event',
            'Automation',
            'Automation',
            'manage_options',
            'uc-automation',
            array( $this, 'render_automation_page' )
        );

        /*
         * CALENDAR USERS.
         *
         * Every WordPress user, their WordPress role, their calendar access
         * level and their teams, on one screen, gated on manage_options. It
         * exists because the portal's own Users screen lists only people who
         * already have a calendar record, which made "give this person access"
         * and "put this person in a team" the same action and hid everybody
         * else. It is also the screen an administrator can reach when the
         * portal will not let them in.
         */
        add_submenu_page(
            'edit.php?post_type=uc_event',
            'Calendar Users',
            'Calendar Users',
            'manage_options',
            'uc-users',
            array( $this, 'render_users_page' )
        );

        add_submenu_page(
            'edit.php?post_type=uc_event',
            'Settings & Integrations',
            'Settings',
            'manage_options',
            'uc-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /* =====================================================================
     * Calendar Users
     * ================================================================== */

    /** Where this screen lives. */
    public static function users_url( $args = array() ) {
        return add_query_arg(
            array_merge( array( 'post_type' => 'uc_event', 'page' => 'uc-users' ), $args ),
            admin_url( 'edit.php' )
        );
    }

    /**
     * Save one row of the Calendar Users screen.
     *
     * TWO ACTIONS, NOT ONE FORM WITH TWO HALVES.
     *
     * Access level and team membership are separate concerns stored in separate
     * places, so they are separate submissions. Saving somebody's teams sends
     * no access level at all, and saving their access level sends no teams, so
     * neither can carry a stale value from the other and there is no shared
     * handler in which one could be written as a side effect of the other. That
     * is exactly how the 3.5.0 defect happened in the portal, where adding
     * somebody so they could be put in a team wrote an access level.
     *
     * NEITHER ACTION TOUCHES WORDPRESS ROLES OR CAPABILITIES. Nothing here
     * calls set_role(), wp_update_user(), add_cap() or remove_cap(), and
     * nothing writes wp_capabilities. The WordPress role on this screen is a
     * read-only display with a link to the built-in Users screen, which is the
     * one control whose purpose is changing it.
     */
    public function handle_users_action() {
        if ( empty( $_POST['uc_users_action'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_POST['uc_users_action'] ) );
        $uid    = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : 0;
        $nonce  = isset( $_POST['uc_users_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['uc_users_nonce'] ) ) : '';

        if ( ! $uid || ! wp_verify_nonce( $nonce, 'uc_users_' . $action . '_' . $uid ) ) {
            return;
        }
        if ( ! get_userdata( $uid ) ) {
            return;
        }

        $back = array(
            'paged'  => isset( $_POST['paged'] ) ? max( 1, intval( $_POST['paged'] ) ) : 1,
            's'      => isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '',
            'saved'  => $action,
            'who'    => $uid,
        );
        if ( '' === $back['s'] ) {
            unset( $back['s'] );
        }

        if ( 'set_access' === $action ) {
            /*
             * An administrator's access cannot be reduced here, and the screen
             * does not offer a control that would try. This re-checks it anyway
             * rather than trusting the markup: a POST is a POST.
             */
            if ( SFAF_Portal::is_site_admin( $uid ) ) {
                update_user_meta( $uid, '_uc_calendar_role', 'admin' );
            } else {
                $level = isset( $_POST['access'] ) ? sanitize_key( wp_unslash( $_POST['access'] ) ) : '';
                if ( 'none' === $level || '' === $level ) {
                    // No calendar record. Their teams are left exactly as they
                    // were: this control edits access and only access.
                    delete_user_meta( $uid, '_uc_calendar_role' );
                } else {
                    update_user_meta( $uid, '_uc_calendar_role', SFAF_Portal::storable_role( $uid, $level ) );
                }
            }
        } elseif ( 'set_teams' === $action ) {
            $ids = isset( $_POST['teams'] ) ? (array) wp_unslash( $_POST['teams'] ) : array();
            SFAF_Teams::set_for_user( $uid, array_map( 'sanitize_key', $ids ) );
        } else {
            return;
        }

        wp_safe_redirect( self::users_url( $back ) );
        exit;
    }

    /**
     * Everybody, and what the calendar thinks of them.
     *
     * THE WORDPRESS ROLE IS READ-ONLY HERE, WITH A LINK.
     *
     * Editing it was the alternative, and it would have had to go through
     * wp_update_user() to be correct. That is not the hard part: the hard part
     * is everything the built-in Users screen does around it — editable_roles,
     * refusing to let the last administrator demote themselves, multisite
     * super-admins, network role restrictions — all of which would have to be
     * reproduced here and kept in step with core. A second, subtly different
     * way to change a WordPress role is precisely the kind of thing that
     * produced the defect this screen exists to fix, so the role links to the
     * screen that already owns it and this screen owns the calendar's own two
     * fields.
     */
    public function render_users_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to view this page.' );
        }

        $paged  = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $per    = 25;

        $q = new WP_User_Query( array(
            'number'  => $per,
            'paged'   => $paged,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'search'  => $search ? '*' . $search . '*' : '',
            'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
        ) );

        $users  = $q->get_results();
        $total  = (int) $q->get_total();
        $pages  = $per > 0 ? (int) ceil( $total / $per ) : 1;
        $teams  = SFAF_Teams::all();
        $levels = SFAF_Portal::roles();

        // The site's role display names, fetched once. WP_Roles is what the
        // built-in Users screen reads for the same column.
        $role_names = array();
        if ( function_exists( 'wp_roles' ) ) {
            $role_names = (array) wp_roles()->role_names;
        }
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div>
                    <h1>Calendar Users</h1>
                    <p class="uc-subtitle">Who can use the calendar, and who gets told about events. Two separate things, edited separately.</p>
                </div>
                <div class="uc-admin-actions">
                    <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" class="button">WordPress Users</a>
                </div>
            </div>

            <?php if ( ! empty( $_GET['saved'] ) ) :
                $who   = isset( $_GET['who'] ) ? get_userdata( intval( $_GET['who'] ) ) : null;
                $whom  = $who ? $who->display_name : 'That user';
                $what  = ( 'set_teams' === $_GET['saved'] ) ? 'Team membership saved for' : 'Calendar access saved for';
                ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html( $what . ' ' . $whom . '.' ); ?>
                    <?php echo ( 'set_teams' === $_GET['saved'] )
                        ? 'Their access level is unchanged.'
                        : 'Their teams are unchanged.'; ?>
                </p></div>
            <?php endif; ?>

            <div class="uc-admin-card">
                <p class="uc-users-explainer">
                    <strong>Access level</strong> is what somebody may do in <code>/caladmin</code>.
                    <strong>Teams</strong> are who gets notified about an event. Changing one never changes the other,
                    and neither one changes anybody's WordPress role.
                    A WordPress administrator always has full calendar access, whatever is listed here, so their
                    access level is shown as fixed rather than offered as a control that would do nothing.
                </p>

                <form method="get" class="uc-users-search">
                    <input type="hidden" name="post_type" value="uc_event" />
                    <input type="hidden" name="page" value="uc-users" />
                    <label class="screen-reader-text" for="uc-users-s">Search users</label>
                    <input type="search" id="uc-users-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name or email" />
                    <button type="submit" class="button">Search</button>
                    <?php if ( '' !== $search ) : ?>
                        <a class="button-link" href="<?php echo esc_url( self::users_url() ); ?>">Clear</a>
                    <?php endif; ?>
                    <span class="uc-users-count"><?php echo (int) $total; ?> <?php echo esc_html( 1 === $total ? 'user' : 'users' ); ?></span>
                </form>

                <?php if ( empty( $users ) ) : ?>
                    <p class="uc-no-data">No users matched.</p>
                <?php else : ?>
                <table class="uc-admin-table uc-users-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>WordPress role</th>
                            <th>Calendar access</th>
                            <th>Teams</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $users as $u ) :
                        $is_wpadm  = SFAF_Portal::is_site_admin( $u->ID );
                        $stored    = get_user_meta( $u->ID, '_uc_calendar_role', true );
                        $effective = SFAF_Portal::get_role( $u->ID );
                        $in_teams  = wp_list_pluck( SFAF_Teams::for_user( $u->ID ), 'id' );
                        $wp_roles  = array();
                        foreach ( (array) $u->roles as $r ) {
                            $wp_roles[] = isset( $role_names[ $r ] ) ? translate_user_role( $role_names[ $r ] ) : $r;
                        }
                        ?>
                        <tr>
                            <td class="uc-users-who">
                                <strong><?php echo esc_html( $u->display_name ); ?></strong>
                                <span class="uc-muted"><?php echo esc_html( $u->user_email ); ?></span>
                            </td>

                            <td class="uc-users-wprole">
                                <?php echo $wp_roles ? esc_html( implode( ', ', $wp_roles ) ) : '<span class="uc-muted">None</span>'; ?>
                                <a class="uc-users-editrole" href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>">Edit in WordPress</a>
                            </td>

                            <td class="uc-users-access">
                                <?php if ( $is_wpadm ) : ?>
                                    <strong>Full (WP Admin)</strong>
                                    <span class="uc-muted">A WordPress administrator has full calendar access, including RSVPs, whether or not they are listed as a calendar user. Take away their administrator role on the WordPress Users screen to change that.</span>
                                <?php else : ?>
                                    <form method="post" class="uc-users-form">
                                        <input type="hidden" name="uc_users_action" value="set_access" />
                                        <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>" />
                                        <input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" />
                                        <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
                                        <?php wp_nonce_field( 'uc_users_set_access_' . $u->ID, 'uc_users_nonce' ); ?>
                                        <label class="screen-reader-text" for="uc-access-<?php echo (int) $u->ID; ?>">Calendar access for <?php echo esc_attr( $u->display_name ); ?></label>
                                        <select name="access" id="uc-access-<?php echo (int) $u->ID; ?>">
                                            <option value="none" <?php selected( '', $effective ); ?>>No calendar access</option>
                                            <?php foreach ( $levels as $lk => $ll ) : ?>
                                                <option value="<?php echo esc_attr( $lk ); ?>" <?php selected( $effective, $lk ); ?>><?php echo esc_html( $ll ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="button button-small">Save access</button>
                                        <?php if ( $stored && $stored !== $effective ) : ?>
                                            <span class="uc-muted">Stored as <?php echo esc_html( $stored ); ?>.</span>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </td>

                            <td class="uc-users-teams">
                                <?php if ( empty( $teams ) ) : ?>
                                    <span class="uc-muted">No teams yet. Make one in <a href="<?php echo esc_url( home_url( '/caladmin/users' ) ); ?>">the portal</a>.</span>
                                <?php else : ?>
                                    <form method="post" class="uc-users-form">
                                        <input type="hidden" name="uc_users_action" value="set_teams" />
                                        <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>" />
                                        <input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" />
                                        <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
                                        <?php wp_nonce_field( 'uc_users_set_teams_' . $u->ID, 'uc_users_nonce' ); ?>
                                        <div class="uc-users-teamlist">
                                            <?php foreach ( $teams as $team ) : ?>
                                                <label>
                                                    <input type="checkbox" name="teams[]" value="<?php echo esc_attr( $team['id'] ); ?>"
                                                           <?php checked( in_array( $team['id'], $in_teams, true ) ); ?> />
                                                    <?php echo esc_html( $team['name'] ); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="submit" class="button button-small">Save teams</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $pages > 1 ) : ?>
                    <div class="uc-users-pager">
                        <?php
                        $args = array();
                        if ( '' !== $search ) {
                            $args['s'] = $search;
                        }
                        for ( $p = 1; $p <= $pages; $p++ ) {
                            $url = self::users_url( array_merge( $args, array( 'paged' => $p ) ) );
                            if ( $p === $paged ) {
                                echo '<span class="uc-users-page current">' . (int) $p . '</span>';
                            } else {
                                echo '<a class="uc-users-page" href="' . esc_url( $url ) . '">' . (int) $p . '</a>';
                            }
                        }
                        ?>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Run now / Clear log, handled before any output so they can redirect.
     *
     * "Run now" goes through exactly the same SFAF_Cron::run() that real cron
     * calls, lock and log included. Testing a different code path from the one
     * that runs at 6am would test nothing.
     */
    public function handle_cron_action() {
        if ( empty( $_POST['uc_cron_action'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['uc_cron_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['uc_cron_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'uc_cron_action' ) ) {
            return;
        }

        $action = sanitize_key( $_POST['uc_cron_action'] );
        $base   = SFAF_Cron::admin_url();

        if ( 'run_now' === $action ) {
            SFAF_Cron::run( 'manual' );
            wp_safe_redirect( add_query_arg( 'ran', '1', $base ) );
            exit;
        }
        if ( 'clear_log' === $action ) {
            SFAF_Cron::clear_log();
            wp_safe_redirect( add_query_arg( 'cleared', '1', $base ) );
            exit;
        }
        if ( 'send_test' === $action ) {
            $to    = isset( $_POST['uc_test_to'] ) ? sanitize_email( wp_unslash( $_POST['uc_test_to'] ) ) : '';
            $type  = isset( $_POST['uc_test_type'] ) ? sanitize_key( wp_unslash( $_POST['uc_test_type'] ) ) : '';
            $event = isset( $_POST['uc_test_event'] ) ? absint( $_POST['uc_test_event'] ) : 0;

            $result = SFAF_Email::send_test( $to, $type, $event );

            // The outcome is a sentence, not a flag: "it went" and "it went and
            // here is what to look at in it" are different messages, and the
            // second is the useful one.
            set_transient( 'sfaf_test_email_result_' . get_current_user_id(), $result, 120 );
            wp_safe_redirect( add_query_arg( 'tested', '1', $base ) );
            exit;
        }
    }

    /**
     * The scheduled runner: whether it is running, what it runs, and the log.
     *
     * Moved wholesale from SFAF_Portal in 2.13.0. The chrome is WordPress's and
     * the gate is manage_options.
     *
     * IT OPENS BY ANSWERING "IS THIS WORKING?" IN A SENTENCE. Until 3.34.0 the
     * first thing on the screen was a Status table whose top row was a health
     * message, and everything under it was configuration. Somebody who has just
     * pointed an external scheduler at this site, and somebody coming back in
     * six months because reminders stopped, are asking the same one question,
     * and neither of them knows or should need to know what cron is. So the
     * question is answered first, in words, in a banner that is coloured by the
     * answer, and the configuration keeps its table below.
     */
    public function render_automation_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to view this page.' );
        }

        $health   = SFAF_Cron::health();
        $status   = SFAF_Cron::status();
        $tasks    = SFAF_Cron::task_report();
        $log      = SFAF_Cron::log();
        $next     = SFAF_Cron::next_due();
        $last_run = SFAF_Cron::last_run();
        $last_ok  = SFAF_Cron::last_success();
        $locked   = SFAF_Cron::lock_held_since();
        $wp_off   = SFAF_Cron::wp_cron_disabled();
        $alert_to = SFAF_Cron::alert_recipient();
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div>
                    <h1>Automation</h1>
                    <p class="uc-subtitle">Work this site does on its own: reminder emails, the who-is-coming list, and source fetching</p>
                </div>
                <form method="post" class="uc-cron-run-form">
                    <?php wp_nonce_field( 'uc_cron_action', 'uc_cron_nonce' ); ?>
                    <input type="hidden" name="uc_cron_action" value="run_now" />
                    <?php // Slow: it does the real work synchronously, so the
                          // button says it is working rather than sitting there
                          // looking unpressed. ?>
                    <button type="submit" class="button button-primary" data-uc-busy="Running&hellip;">Run now</button>
                </form>
            </div>

            <?php if ( ! empty( $_GET['ran'] ) ) : ?>
                <div class="notice notice-success"><p>Run complete. The newest entry in the log below is what it did.</p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['cleared'] ) ) : ?>
                <div class="notice notice-success"><p>Run log cleared.</p></div>
            <?php endif; ?>

            <?php
            /*
             * THE ANSWER, BEFORE ANY OF THE MACHINERY.
             *
             * Four states, four colours, and the state word is in the text as
             * well as in the colour: a banner that says "working" only by being
             * green is a banner that says nothing to somebody who cannot tell
             * the greens apart. See DESIGN.md on colour never carrying meaning
             * on its own.
             */
            ?>
            <div class="uc-cron-banner uc-cron-banner-<?php echo esc_attr( $status['state'] ); ?>">
                <h2><?php echo esc_html( $status['headline'] ); ?></h2>
                <p><?php echo esc_html( $status['detail'] ); ?></p>
                <?php if ( 'stopped' === $status['state'] || 'behind' === $status['state'] ) : ?>
                    <p class="uc-cron-banner-do">
                        Press <strong>Run now</strong> above. If that works, the jobs themselves are fine and what
                        has stopped is whatever is meant to be starting them.
                    </p>
                <?php endif; ?>
            </div>

            <div class="uc-admin-card">
                <h2>Scheduled tasks</h2>
                <p class="description">
                    Each of these runs on its own, without anybody pressing anything. A run happens every
                    15 minutes and does whichever of them are due.
                </p>
                <table class="uc-admin-table uc-cron-tasks">
                    <thead>
                        <tr><th>Task</th><th>Last ran</th><th>Next due</th><th>What happened</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $tasks as $t ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $t['label'] ); ?></strong>
                                    <span class="uc-muted"><?php echo esc_html( $t['plain'] ); ?></span>
                                </td>
                                <td>
                                    <?php if ( $t['last'] ) : ?>
                                        <?php echo esc_html( SFAF_Cron::ago( $t['last'] ) ); ?>
                                        <span class="uc-muted"><?php echo esc_html( SFAF_Cron::local_time( $t['last'] ) ); ?></span>
                                    <?php else : ?>
                                        <span class="uc-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( ! $t['on'] ) : ?>
                                        <span class="uc-muted">Switched off</span>
                                    <?php elseif ( $t['next'] ) : ?>
                                        <?php echo esc_html( SFAF_Cron::ago( $t['next'] ) ); ?>
                                        <span class="uc-muted"><?php echo esc_html( SFAF_Cron::local_time( $t['next'] ) ); ?></span>
                                    <?php else : ?>
                                        <span class="uc-cron-failed">Not scheduled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( ! $t['on'] ) : ?>
                                        <?php echo esc_html( $t['off'] ); ?>
                                    <?php elseif ( $t['summary'] ) : ?>
                                        <span class="uc-cron-<?php echo esc_attr( $t['status'] ); ?>"><?php echo esc_html( $t['summary'] ); ?></span>
                                    <?php else : ?>
                                        <span class="uc-muted">It has not run yet.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">
                    &ldquo;Last ran&rdquo; is the last time the task actually did something, not the last time it was
                    passed over. A task that is switched off has no next due date because it has none.
                </p>
            </div>

            <div class="uc-admin-card">
                <h2>Status</h2>
                <table class="uc-admin-table uc-cron-status">
                    <tbody>
                        <tr>
                            <th>Last run</th>
                            <td>
                                <?php if ( $last_run ) : ?>
                                    Started <?php echo esc_html( SFAF_Cron::ago( $last_run ) ); ?>,
                                    <?php echo esc_html( SFAF_Cron::local_time( $last_run ) ); ?>.
                                    <?php if ( $last_ok && $last_ok !== $last_run ) : ?>
                                        The last one that finished cleanly was <?php echo esc_html( SFAF_Cron::ago( $last_ok ) ); ?>.
                                    <?php endif; ?>
                                <?php else : ?>
                                    No run has ever started.
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Health</th>
                            <td class="uc-cron-<?php echo esc_attr( $health['state'] ); ?>"><?php echo esc_html( $health['message'] ); ?></td>
                        </tr>
                        <tr>
                            <th>Schedule</th>
                            <td>Every <?php echo (int) ( SFAF_Cron::SCHEDULE_EVERY / 60 ); ?> minutes. <?php echo $next ? 'Next due ' . esc_html( SFAF_Cron::ago( $next ) ) . ', ' . esc_html( SFAF_Cron::local_time( $next ) ) . '.' : 'Not currently scheduled.'; ?></td>
                        </tr>
                        <tr>
                            <th>Trigger</th>
                            <td>
                                <?php if ( $wp_off ) : ?>
                                    <code>DISABLE_WP_CRON</code> is set, so runs come only from something outside this site requesting the URL below. This is the intended setup, and it means the whole schedule now depends on that external service still being there.
                                <?php else : ?>
                                    <code>DISABLE_WP_CRON</code> is <strong>not</strong> set, so WordPress is still firing scheduled tasks off visitor traffic. That means a 6am reminder does not go out until somebody visits the site. <strong>Set up the external ping first and confirm above that tasks are running, then add the constant.</strong> Doing it the other way round can leave nothing running at all. The readme has the steps in order.
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Cron URL</th>
                            <td>
                                <code><?php echo esc_html( SFAF_Cron::cron_url() ); ?></code>
                                <br />This is the address an external scheduler should request, every
                                <?php echo (int) ( SFAF_Cron::SCHEDULE_EVERY / 60 ); ?> minutes. It needs no parameter,
                                no header and no key, and it keeps working once <code>DISABLE_WP_CRON</code> is set.
                                Do not point the scheduler at the page-view nudge below instead: that one runs this
                                plugin's jobs only, and would leave the rest of WordPress's schedule stopped.
                            </td>
                        </tr>
                        <tr>
                            <th>Page-view nudge</th>
                            <td>
                                <?php $last_ping = SFAF_Cron::last_ping(); ?>
                                <?php if ( $last_ping ) : ?>
                                    A page carrying a calendar last started a run <?php echo esc_html( SFAF_Cron::local_time( $last_ping ) ); ?>.
                                <?php else : ?>
                                    No page has started a run yet.
                                <?php endif; ?>
                                Every sfaf.org page with a calendar on it asks this site to run its jobs, at most
                                once every <?php echo (int) ( SFAF_Cron::PING_EVERY / 60 ); ?> minutes. Nothing is set up
                                for this and nothing needs to be. Leave it on after the external scheduler is
                                working: it is a request from a site that has visitors, which is what lets this
                                site notice the scheduler has stopped and send the alert email below.
                                <br /><code><?php echo esc_html( SFAF_Cron::ping_url() ); ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th>Lock</th>
                            <td><?php echo $locked
                                ? 'Held since ' . esc_html( SFAF_Cron::local_time( $locked ) ) . '. A run is in progress, or one was interrupted and the lock will be broken automatically.'
                                : 'Free.'; ?></td>
                        </tr>
                        <tr>
                            <th>Alert email</th>
                            <td>
                                <?php if ( $alert_to ) : ?>
                                    Failures and recoveries are emailed to <code><?php echo esc_html( $alert_to ); ?></code>.
                                <?php else : ?>
                                    No alert address is set, so nothing is emailed when the runner stops.
                                <?php endif; ?>
                                <a href="<?php echo esc_url( add_query_arg( array( 'post_type' => 'uc_event', 'page' => 'uc-settings' ), admin_url( 'edit.php' ) ) ); ?>">Change it under Settings.</a>
                            </td>
                        </tr>
                        <tr>
                            <?php /* Whether each task is on, when it last ran and when it
                                     is next due is the Scheduled tasks card above, and is
                                     deliberately not repeated here. What is left in these
                                     two rows is what that card does not say. */ ?>
                            <th>Who a reminder goes to</th>
                            <td>Registrations and the event&rsquo;s notification list. Native events only: imported events are never sent for.</td>
                        </tr>
                        <tr>
                            <th>Fetching by hand</th>
                            <td>&ldquo;Fetch updates&rdquo; on the calendar portal&rsquo;s Pending screen runs a fetch whether or not the automated one is switched on.</td>
                        </tr>
                    </tbody>
                </table>
                <p class="description">
                    An external scheduler may report a timeout even when the run finished: <code>wp-cron.php</code> keeps
                    working after the connection drops. The run log below is the source of truth, not the status code
                    the scheduler recorded.
                </p>
            </div>

            <?php
            /*
             * THE TEST SEND.
             *
             * Every email this plugin sends was written, reviewed and shipped
             * without one of them ever having been delivered to a mailbox. This
             * card is how that stops being true: it builds a real message
             * through the real builders and hands it to wp_mail(), so what
             * arrives is what a registrant would get.
             *
             * FOUR THINGS CAN ONLY BE CHECKED IN A DELIVERED MESSAGE, and they
             * are listed on the card rather than left as folklore: whether the
             * Postmark plugin forwards Reply-To, whether a text/plain part
             * survives it, whether the banner loads from this site, and whether
             * Outlook renders the buttons as rectangles.
             */
            $test_result = get_transient( 'sfaf_test_email_result_' . get_current_user_id() );
            if ( $test_result ) {
                delete_transient( 'sfaf_test_email_result_' . get_current_user_id() );
            }
            $test_events = get_posts( array(
                'post_type'      => 'uc_event',
                'post_status'    => 'publish',
                'posts_per_page' => 25,
                'meta_key'       => '_uc_event_date',
                'orderby'        => 'meta_value',
                'order'          => 'DESC',
            ) );
            ?>
            <div class="uc-admin-card">
                <h2>Send a test email</h2>

                <?php if ( $test_result ) : ?>
                    <div class="notice <?php echo ! empty( $test_result['sent'] ) ? 'notice-success' : 'notice-error'; ?>">
                        <p><?php echo esc_html( $test_result['message'] ); ?></p>
                    </div>
                <?php endif; ?>

                <p class="description">
                    Builds the real message against a real event and sends it to you. Nobody is registered and no place
                    is held. The subject is prefixed with [Test].
                </p>

                <?php if ( empty( $test_events ) ) : ?>
                    <p class="uc-no-data">Publish an event first: a test message is built from a real one.</p>
                <?php else : ?>
                    <form method="post" class="uc-test-email-form">
                        <?php wp_nonce_field( 'uc_cron_action', 'uc_cron_nonce' ); ?>
                        <input type="hidden" name="uc_cron_action" value="send_test" />
                        <p>
                            <label for="uc_test_type">Which email</label><br />
                            <select name="uc_test_type" id="uc_test_type">
                                <option value="confirmation">Confirmation, to the person who registers</option>
                                <option value="alert">Alert, when somebody registers</option>
                                <option value="reminder">Morning-of reminder</option>
                                <option value="summary">Who is coming, two hours before</option>
                            </select>
                        </p>
                        <p>
                            <label for="uc_test_event">Built from</label><br />
                            <select name="uc_test_event" id="uc_test_event">
                                <?php foreach ( $test_events as $ev ) : ?>
                                    <option value="<?php echo (int) $ev->ID; ?>">
                                        <?php echo esc_html( get_the_title( $ev ) ); ?>
                                        <?php $ev_date = get_post_meta( $ev->ID, '_uc_event_date', true ); ?>
                                        <?php echo $ev_date ? esc_html( ' (' . sfaf_ap_date( $ev_date, 'short_year' ) . ')' ) : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="uc_test_to">Send it to</label><br />
                            <input type="email" name="uc_test_to" id="uc_test_to" class="regular-text"
                                   value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required />
                        </p>
                        <p><button type="submit" class="button button-primary" data-uc-busy="Sending&hellip;">Send it</button></p>
                    </form>

                    <p class="description">
                        <strong>When it arrives, check four things.</strong> The Reply-To address, by pressing reply and
                        reading who it is addressed to. Whether the message has a plain-text part, by viewing its source
                        and looking for <code>text/plain</code>. That the banner loads. And, in Outlook on Windows, that
                        the buttons are rectangles rather than bare links.
                    </p>
                    <p class="description">
                        The summary email needs somebody registered for the event you pick, and the confirmation and
                        reminder carry a cancel link. The link in a test message points at a token that does not exist,
                        so it opens the "not valid" page. That is the correct answer, and it is worth seeing once.
                    </p>
                <?php endif; ?>
            </div>

            <div class="uc-admin-card">
                <h2>Run log</h2>
                <?php if ( empty( $log ) ) : ?>
                    <p class="uc-no-data">Nothing has run yet. Press &ldquo;Run now&rdquo; to try it.</p>
                <?php else : ?>
                    <table class="uc-admin-table">
                        <thead><tr><th>Started</th><th>Trigger</th><th>Status</th><th>Took</th><th>What ran</th></tr></thead>
                        <tbody>
                            <?php foreach ( $log as $entry ) : ?>
                                <tr>
                                    <td><?php echo esc_html( SFAF_Cron::local_time( isset( $entry['started_ts'] ) ? $entry['started_ts'] : 0 ) ); ?></td>
                                    <?php
                                    // The raw value is a machine name and one of
                                    // them, "ping", means nothing to a reader.
                                    $trigger_labels = array(
                                        'cron'   => 'Scheduled',
                                        'manual' => 'Run now',
                                        'ping'   => 'A page view',
                                    );
                                    $trigger_raw = isset( $entry['trigger'] ) ? (string) $entry['trigger'] : '';
                                    ?>
                                    <td><?php echo esc_html( isset( $trigger_labels[ $trigger_raw ] ) ? $trigger_labels[ $trigger_raw ] : $trigger_raw ); ?></td>
                                    <td class="uc-cron-<?php echo esc_attr( isset( $entry['status'] ) ? $entry['status'] : '' ); ?>"><?php echo esc_html( isset( $entry['status'] ) ? $entry['status'] : '' ); ?></td>
                                    <td><?php echo esc_html( isset( $entry['duration'] ) ? $entry['duration'] . 's' : '' ); ?></td>
                                    <td>
                                        <?php foreach ( (array) ( isset( $entry['tasks'] ) ? $entry['tasks'] : array() ) as $task ) : ?>
                                            <div class="uc-cron-task">
                                                <strong><?php echo esc_html( isset( $task['label'] ) ? $task['label'] : $task['task'] ); ?>:</strong>
                                                <?php echo esc_html( isset( $task['summary'] ) ? $task['summary'] : '' ); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description">The newest <?php echo (int) SFAF_Cron::LOG_MAX; ?> runs are kept; older entries are pruned automatically.</p>
                    <form method="post" style="margin-top:12px;">
                        <?php wp_nonce_field( 'uc_cron_action', 'uc_cron_nonce' ); ?>
                        <input type="hidden" name="uc_cron_action" value="clear_log" />
                        <button type="submit" class="button">Clear the log</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting( 'uc_settings', 'uc_settings', array( $this, 'sanitize_settings' ) );
    }

    public function sanitize_settings( $input ) {
        $out = array();

        // Credentials are taken out of the submission here and written to
        // their own dedicated option. They are deliberately NOT added to $out:
        // this array is what replaces uc_settings wholesale, and a credential
        // that never enters it cannot be dropped by a later save that forgets
        // about it. See class-sfaf-credentials.php.
        SFAF_Credentials::absorb( $input );

        // Plain text fields. No credentials in this list — see above.
        $text_fields = array(
            'pardot_business_unit', 'google_calendar_id',
            'galaxy_portal_url', 'galaxy_agency_id',
            'galaxy_needs_category', 'galaxy_sync_interval',
            'webhook_url',
            'pardot_default_campaign',
            'route_sheet_url',
            'brand_logo',
            'email_rsvp_subject', 'email_reminder_subject',
            // Morning-of reminder + the provider-neutral sender identity.
            // These are settings rather than constants precisely so an email
            // provider can be chosen later without editing any code.
            'email_dayof_subject', 'email_from_name',
        );
        foreach ( $text_fields as $field ) {
            $out[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
        }

        // Email addresses.
        //
        // route_organizer_email is gone: its control was removed in 3.25.0 when
        // registrations moved onto the event's notification list, and since
        // uc_settings is rebuilt wholesale on every save, dropping the key from
        // this list is what actually retires it.
        foreach ( array( 'email_rsvp_replyto', 'email_from_address', 'email_reply_to', 'cron_alert_email' ) as $field ) {
            $out[ $field ] = isset( $input[ $field ] ) ? sanitize_email( $input[ $field ] ) : '';
        }

        // Textareas.
        foreach ( array( 'email_rsvp_body', 'email_reminder_body', 'email_dayof_body' ) as $field ) {
            $out[ $field ] = isset( $input[ $field ] ) ? sanitize_textarea_field( $input[ $field ] ) : '';
        }

        /*
         * THE CALENDAR HOME URL.
         *
         * The page on the main site that carries the calendar. It exists because
         * event pages are served from this site and the calendar is not on it:
         * without this, "All Events" and every category chip on an event page
         * had nowhere honest to point and fell back to this site's own archive,
         * which is not a public surface. See sfaf_calendar_return_url().
         *
         * Stored as a URL, not a page ID, precisely because it is normally on
         * ANOTHER site. Only http and https are kept, so nothing else can be
         * stored and later rendered into an href.
         */
        $home_url = isset( $input['calendar_home_url'] ) ? esc_url_raw( trim( (string) $input['calendar_home_url'] ), array( 'http', 'https' ) ) : '';
        $out['calendar_home_url'] = $home_url;

        // Colors.
        foreach ( array( 'brand_primary_color', 'brand_accent_color' ) as $field ) {
            $color = isset( $input[ $field ] ) ? sanitize_hex_color( $input[ $field ] ) : '';
            $out[ $field ] = $color ? $color : '';
        }

        // Display: events per page (0/-1 = all) + pagination style.
        $out['display_per_page'] = ( isset( $input['display_per_page'] ) && $input['display_per_page'] !== '' ) ? intval( $input['display_per_page'] ) : 12;
        $pg_styles = array( 'load_more', 'pages', 'infinite' );
        $out['display_pagination'] = ( isset( $input['display_pagination'] ) && in_array( $input['display_pagination'], $pg_styles, true ) )
            ? $input['display_pagination'] : 'load_more';

        // Card style (whitelist).
        $card_styles = array( 'bordered', 'shadow', 'minimal' );
        $out['brand_card_style'] = ( isset( $input['brand_card_style'] ) && in_array( $input['brand_card_style'], $card_styles, true ) )
            ? $input['brand_card_style'] : 'bordered';

        // Endpoint overrides and secrets used to be handled here, each guarded
        // by a read-back of the previous uc_settings value. Both now go to the
        // dedicated credential option via the SFAF_Credentials::absorb() call
        // at the top of this method, which is what makes them survive a save
        // that does not mention them.

        // Toggles. gofundme_connected is gone: connection state is now derived
        // from whether a real token is on file (SFAF_GFMP::status()), so it can
        // no longer be set by hand.
        $toggles = array(
            'gofundme_show_progress', 'gofundme_auto_import',
            'pardot_auto_prospect', 'pardot_event_emails',
            'google_auto_publish', 'galaxy_auto_sync',
            'galaxy_import_events', 'galaxy_active_only',
            'webhook_event_created', 'webhook_event_updated',
            'webhook_event_deleted', 'webhook_event_rsvp',
            'route_pardot',
            'route_google_sheet', 'route_confirmation_email',
            // Scheduled tasks. auto_fetch_enabled is absent-means-off, which is
            // what makes unattended fetching off by default and off after any
            // save that does not deliberately tick it.
            'reminders_enabled', 'auto_fetch_enabled',
        );
        foreach ( $toggles as $toggle ) {
            $out[ $toggle ] = isset( $input[ $toggle ] ) ? '1' : '0';
        }

        // GoFundMe campaign manager (repeater: name, url, goal).
        $out['gofundme_campaigns'] = array();
        if ( isset( $input['gofundme_campaigns'] ) && is_array( $input['gofundme_campaigns'] ) ) {
            foreach ( $input['gofundme_campaigns'] as $row ) {
                if ( empty( $row['name'] ) && empty( $row['url'] ) ) {
                    continue;
                }
                $out['gofundme_campaigns'][] = array(
                    'name' => sanitize_text_field( isset( $row['name'] ) ? $row['name'] : '' ),
                    'url'  => esc_url_raw( isset( $row['url'] ) ? $row['url'] : '' ),
                    'goal' => preg_replace( '/[^0-9.]/', '', isset( $row['goal'] ) ? $row['goal'] : '' ),
                );
            }
        }

        // Pardot campaign manager (repeater: name, id).
        $out['pardot_campaigns'] = array();
        if ( isset( $input['pardot_campaigns'] ) && is_array( $input['pardot_campaigns'] ) ) {
            foreach ( $input['pardot_campaigns'] as $row ) {
                if ( empty( $row['name'] ) && empty( $row['id'] ) ) {
                    continue;
                }
                $out['pardot_campaigns'][] = array(
                    'name' => sanitize_text_field( isset( $row['name'] ) ? $row['name'] : '' ),
                    'id'   => sanitize_text_field( isset( $row['id'] ) ? $row['id'] : '' ),
                );
            }
        }

        // Pardot category -> campaigns map.
        $out['pardot_category_map'] = array();
        if ( isset( $input['pardot_category_map'] ) && is_array( $input['pardot_category_map'] ) ) {
            foreach ( $input['pardot_category_map'] as $term_id => $campaign_ids ) {
                $term_id = intval( $term_id );
                if ( ! $term_id || ! is_array( $campaign_ids ) ) {
                    continue;
                }
                $out['pardot_category_map'][ $term_id ] = array_values( array_map( 'sanitize_text_field', $campaign_ids ) );
            }
        }

        /*
         * NO satellite_sites KEY. It was sanitized here and nowhere else: no
         * field ever rendered it and nothing ever read it, so the branch had
         * been sanitizing a value that could not arrive since before 2.0.
         * Removed in 3.28.0.
         *
         * This is NOT part of retiring the satellite feed, which is dormant and
         * deliberately kept. See the header note on SFAF_Sync. multisite_api_key
         * is the key that feature actually uses, and it is still saved above.
         */

        return $out;
    }

    /*
     * RSVPs, SERIES AND THE 3.0.0 MIGRATION USED TO BE THREE SCREENS HERE.
     *
     * All three were removed in 3.27.0. The first two are managed in /caladmin
     * and having them in both places meant two forms that could drift; the
     * third had never been run and no longer had anything to convert. See the
     * note above add_menu_pages() for what was checked before each went, and
     * the 3.27.0 changelog for the reasoning.
     *
     * Recoverable from git if any of it is ever wanted back: they were last
     * present at 3.26.1.
     */
    /**
     * Render Shortcode Generator page
     */
    public function render_shortcode_generator() {
        $categories = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );
        $organizers = get_terms( array( 'taxonomy' => 'uc_organizer', 'hide_empty' => false ) );
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div>
                    <h1>Shortcode Generator</h1>
                    <p class="uc-subtitle">Build a calendar shortcode, then copy it into any page or post.</p>
                </div>
            </div>

            <div class="uc-admin-card uc-generator">
                <div class="uc-generator-grid">
                    <div class="uc-gen-field">
                        <label for="uc-gen-type">Shortcode Type</label>
                        <select id="uc-gen-type" class="uc-gen-input">
                            <option value="sfaf_calendar">[sfaf_calendar]: Full calendar</option>
                            <option value="upcoming_events">[upcoming_events]: Compact widget</option>
                        </select>
                    </div>

                    <div class="uc-gen-field">
                        <label for="uc-gen-category">Category Filter</label>
                        <select id="uc-gen-category" class="uc-gen-input">
                            <option value="">All categories</option>
                            <?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>

                    <div class="uc-gen-field">
                        <label for="uc-gen-organizer">Organizer Filter</label>
                        <select id="uc-gen-organizer" class="uc-gen-input">
                            <option value="">All organizers</option>
                            <?php if ( ! is_wp_error( $organizers ) ) : foreach ( $organizers as $org ) : ?>
                                <option value="<?php echo esc_attr( $org->slug ); ?>"><?php echo esc_html( $org->name ); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>

                    <div class="uc-gen-field">
                        <label for="uc-gen-count">Number of Events</label>
                        <input type="number" id="uc-gen-count" class="uc-gen-input" value="12" min="1" max="100" />
                    </div>

                    <div class="uc-gen-field uc-gen-calendar-only">
                        <label for="uc-gen-layout">Layout Style</label>
                        <select id="uc-gen-layout" class="uc-gen-input">
                            <option value="cards">Cards</option>
                            <option value="compact">Compact list</option>
                        </select>
                    </div>

                    <div class="uc-gen-field uc-gen-calendar-only">
                        <label class="uc-gen-checkbox">
                            <input type="checkbox" id="uc-gen-filters" checked /> Show filters &amp; search bar
                        </label>
                    </div>
                </div>

                <div class="uc-gen-output-wrap">
                    <label>Generated Shortcode</label>
                    <div class="uc-gen-output-row">
                        <input type="text" id="uc-gen-output" class="uc-gen-output" readonly value="[sfaf_calendar]" onfocus="this.select();" />
                        <button type="button" class="button button-primary" id="uc-gen-copy">Copy</button>
                    </div>
                    <span class="uc-gen-copied" id="uc-gen-copied" style="display:none;">Copied!</span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Embed Code generator.
     *
     * Picks the filters, writes the block to paste into another site, and shows
     * that block actually running underneath — the preview is a real embed
     * loading from the real endpoint, not a mock-up of one.
     *
     * Distinct from the Shortcode Generator: that produces [sfaf_calendar]
     * shortcodes for pages on THIS WordPress site; this produces a portable HTML
     * block for OTHER sites that can't run the plugin.
     */
    public function render_embed_page() {
        $categories = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );
        $organizers = get_terms( array( 'taxonomy' => 'uc_organizer', 'hide_empty' => false ) );
        $categories = is_wp_error( $categories ) ? array() : $categories;
        $organizers = is_wp_error( $organizers ) ? array() : $organizers;

        /*
         * Series, as term ID => name.
         *
         * NEW SNIPPETS CARRY THE TERM ID; existing ones out on sfaf.org carry
         * the old parent post ID, and both keep resolving — SFAF_Series::
         * resolve() tries the legacy ID first. So the snippet CONTRACT is
         * unchanged (one integer in data-series / series="…"), and nothing
         * already published has to be regenerated.
         */
        $series = array();
        foreach ( SFAF_Series::all() as $term ) {
            $series[ (int) $term->term_id ] = $term->name;
        }
        natcasesort( $series );

        $settings = get_option( 'uc_settings', array() );
        $per_page = ( isset( $settings['display_per_page'] ) && $settings['display_per_page'] !== '' )
            ? (int) $settings['display_per_page'] : 12;
        ?>
        <div class="wrap uc-admin-wrap uc-embed-gen"
             data-script-url="<?php echo esc_attr( SFAF_Embed::script_url() ); ?>">

            <div class="uc-admin-header">
                <div>
                    <h1>Embed Code</h1>
                    <p class="uc-subtitle">Build a calendar block, then paste it into any page on any site.</p>
                </div>
            </div>

            <div class="uc-admin-card uc-embed-note">
                <p>
                    <strong>This block works on any site or page</strong>: another WordPress site, or a
                    platform where you can only add an HTML block. Nothing is installed on the other
                    site and no events are copied to it. The block asks this calendar for its events
                    each time someone opens the page, so what visitors see is always current, and every
                    RSVP still happens here.
                </p>
            </div>

            <div class="uc-embed-layout">
                <div class="uc-admin-card uc-embed-options">
                    <h2>What should this calendar show?</h2>

                    <?php
                    /*
                     * DISPLAY MODE AND FILTER ARE INDEPENDENT CONTROLS.
                     *
                     * Not a list of preset block types: every combination is
                     * valid and useful, so offering "Programa Latino sidebar"
                     * and "Programa Latino calendar" as separate presets would
                     * mean nine presets today and more with every new filter.
                     * Two controls produce all of them, and a Programa Latino
                     * sidebar and a TransLife sidebar are just two snippets
                     * from the same screen.
                     */
                    ?>
                    <div class="uc-embed-field">
                        <span class="uc-embed-label">Display mode</span>
                        <div class="uc-radio-stack">
                            <?php
                            $modes = array(
                                'list'     => 'List: one card per event, with images and details',
                                'calendar' => 'Calendar: a month grid',
                                'combined' => 'Combined: the month grid and the upcoming dates sidebar side by side, stacking below 888px',
                                'sidebar'  => 'Sidebar: a narrow column of upcoming dates',
                            );
                            foreach ( $modes as $val => $label ) :
                                ?>
                                <label class="uc-radio-opt">
                                    <input type="radio" name="uc_embed_view" class="uc-embed-view"
                                           value="<?php echo esc_attr( $val ); ?>" <?php checked( 'list', $val ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php
                    /*
                     * NO TOGGLE FIELD FOR THE COMBINED MODE, which is why
                     * data-when-view does not name it. The toggle switches
                     * between the grid and the list; combined shows both, so
                     * there is nothing for it to switch. The renderer forces it
                     * off as well, so a hand-written shortcode asking for both
                     * gets the same answer as the generator.
                     */
                    ?>
                    <div class="uc-embed-field" data-when-view="list calendar">
                        <span class="uc-embed-label">Visitor view toggle</span>
                        <label class="uc-check">
                            <input type="checkbox" id="uc-embed-toggle" checked />
                            Let visitors switch between list and calendar
                        </label>
                        <p class="description">The mode chosen above is what the block opens on. A visitor who switches keeps their choice for this block only.</p>
                    </div>

                    <?php
                    /*
                     * OPEN EVENTS TO SOURCE LISTING.
                     *
                     * Every mode, so no data-when-view: it changes where a card
                     * points and every mode renders cards.
                     *
                     * THE HELPER TEXT SAYS WHAT HAPPENS AND WHAT IS LEFT ALONE,
                     * in that order. The second half is the part somebody
                     * actually needs: a block mixing native and imported events
                     * behaves two ways, and a manager who does not know that
                     * will read it as the setting half working.
                     */
                    ?>
                    <div class="uc-embed-field">
                        <span class="uc-embed-label">Where events open</span>
                        <label class="uc-check">
                            <input type="checkbox" id="uc-embed-source-links"
                                   <?php checked( sfaf_source_links_default() ); ?> />
                            Open events at their source listing
                        </label>
                        <p class="description">
                            Ticked, an imported event links straight to its page on the platform it came from, so a
                            GoFundMe Pro ride sends visitors to donate.sfaf.org rather than through an event page here.
                            Events created on this calendar have no other page and always open here, in the same block.
                        </p>
                        <p class="description">
                            Leave it unticked and every event opens on this site, where the map, the series dates and
                            add to calendar are, and registration hands off to the source. That is the right choice
                            for most blocks and is what this calendar has always done.
                        </p>
                    </div>

                    <div class="uc-embed-field" data-when-view="sidebar">
                        <label class="uc-embed-label" for="uc-embed-count">How many to show</label>
                        <input type="number" id="uc-embed-count" class="uc-input uc-input-narrow"
                               value="10" min="1" max="50" />
                        <p class="description">The next N dates. Occurrences, so a weekly group appears once per date. Fewer are shown if fewer exist.</p>
                    </div>

                    <div class="uc-embed-field" data-when-view="sidebar">
                        <label class="uc-embed-label" for="uc-embed-heading">Heading above the list</label>
                        <input type="text" id="uc-embed-heading" class="uc-input"
                               value="Upcoming event dates" maxlength="80" />
                        <p class="description">Clear this field for no heading at all. The block is embedded inside a page that has its own headings, so it renders as an H3 and never claims a level above the section it sits in.</p>
                    </div>

                    <?php
                    /*
                     * THE DEFAULT IS EVERYTHING, AND NOTHING IS PRESELECTED.
                     *
                     * This defaulted to "One organizer" with whichever
                     * organizer sorted first already chosen, so a block
                     * generated without touching the control silently showed
                     * one team's events. A narrowing that nobody asked for is
                     * the worst kind: the page looks like it is working and is
                     * quietly missing most of the calendar. Narrowing is a
                     * decision, so it has to be made on purpose.
                     *
                     * Organizer is still listed first among the narrowings,
                     * because on a programme page it is the one that matches
                     * how the work is organised: Programa Latino runs five
                     * distinct series, so filtering by series would show a
                     * fifth of their programming, and category cuts across
                     * organizers entirely.
                     */
                    ?>
                    <div class="uc-embed-field">
                        <label class="uc-embed-label" for="uc-embed-filter-type">Limit to</label>
                        <select id="uc-embed-filter-type" class="uc-input">
                            <option value="" selected>All events</option>
                            <option value="organizer">One organizer</option>
                            <option value="series">One series</option>
                            <option value="category">One category</option>
                        </select>
                        <p class="description">All events is the default, so a block shows the whole calendar unless it is deliberately narrowed. Organizer is usually the right narrowing for a program page: a team&rsquo;s work is often several series and several categories.</p>
                    </div>

                    <div class="uc-embed-field" data-when-filter="organizer" hidden>
                        <label class="uc-embed-label" for="uc-embed-organizer">Which organizer</label>
                        <select id="uc-embed-organizer" class="uc-input uc-embed-which">
                            <?php if ( empty( $organizers ) ) : ?>
                                <option value="">No organizers yet</option>
                            <?php else : ?>
                                <?php foreach ( $organizers as $org ) : ?>
                                    <option value="<?php echo esc_attr( $org->slug ); ?>"
                                            data-name="<?php echo esc_attr( $org->name ); ?>"><?php echo esc_html( $org->name ); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="uc-embed-field" data-when-filter="series" hidden>
                        <label class="uc-embed-label" for="uc-embed-series">Which series</label>
                        <select id="uc-embed-series" class="uc-input uc-embed-which">
                            <?php if ( empty( $series ) ) : ?>
                                <option value="">No series yet</option>
                            <?php else : ?>
                                <?php foreach ( $series as $term_id => $title ) : ?>
                                    <option value="<?php echo (int) $term_id; ?>"
                                            data-name="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $title ); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="uc-embed-field" data-when-filter="category" hidden>
                        <label class="uc-embed-label" for="uc-embed-category">Which category</label>
                        <select id="uc-embed-category" class="uc-input uc-embed-which">
                            <?php if ( empty( $categories ) ) : ?>
                                <option value="">No categories yet</option>
                            <?php else : ?>
                                <?php foreach ( $categories as $cat ) : ?>
                                    <option value="<?php echo esc_attr( $cat->slug ); ?>"
                                            data-name="<?php echo esc_attr( $cat->name ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="uc-embed-field" data-when-view="list calendar">
                        <label class="uc-embed-label" for="uc-embed-per-page">Events per page</label>
                        <input type="number" id="uc-embed-per-page" class="uc-input uc-input-narrow"
                               value="<?php echo (int) $per_page; ?>" min="1" max="100" />
                        <p class="description">Visitors load the next batch with a button. Applies to the list; the calendar shows a whole month.</p>
                    </div>

                    <div class="uc-embed-field uc-embed-field-inline" data-when-view="list calendar">
                        <label class="uc-embed-label" for="uc-embed-filters">Let visitors search and filter</label>
                        <label class="uc-toggle">
                            <input type="checkbox" id="uc-embed-filters" checked />
                            <span class="uc-toggle-slider"></span>
                        </label>
                        <p class="description">Shows a search box and category buttons above the events.</p>
                    </div>
                </div>

                <div class="uc-admin-card uc-embed-output">
                    <h2>Your block</h2>
                    <textarea id="uc-embed-code" class="uc-embed-code" readonly rows="9"
                              onfocus="this.select();"></textarea>
                    <div class="uc-embed-actions">
                        <button type="button" class="button button-primary" id="uc-embed-copy">Copy block</button>
                        <span class="uc-embed-copied" id="uc-embed-copied" aria-live="polite"></span>
                    </div>
                    <p class="description">
                        Paste it into an HTML or Custom HTML block. To filter by venue as well, add
                        <code>data-venue="venue-slug"</code> to the block by hand.
                    </p>
                </div>
            </div>

            <div class="uc-admin-card uc-embed-preview-card">
                <h2>Preview</h2>
                <p class="description">This is the block above, running for real against the embed endpoint.</p>
                <div class="uc-embed-preview" id="uc-embed-preview"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Settings / Integrations page
     */
    public function render_settings_page() {
        $settings = get_option( 'uc_settings', array() );
        $s = function( $key, $default = '' ) use ( $settings ) {
            return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
        };

        // Credentials come from their own option, never from uc_settings. The
        // two write-only ones (Eventbrite private token, GoFundMe Pro client
        // secret) are never passed through this — their fields stay empty.
        $c = function ( $key ) {
            return SFAF_Credentials::is_secret( $key ) ? '' : SFAF_Credentials::get( $key );
        };

        $gf_campaigns     = $s( 'gofundme_campaigns', array() );
        $pardot_campaigns = $s( 'pardot_campaigns', array() );
        $category_map     = $s( 'pardot_category_map', array() );
        $event_categories = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) );
        $pardot_connected = $s( 'pardot_business_unit' ) !== '';
        ?>
        <div class="wrap uc-admin-wrap">
            <div class="uc-admin-header">
                <div>
                    <h1>Settings &amp; Integrations</h1>
                    <p class="uc-subtitle">Connect your calendar to external services and sync events across your digital ecosystem</p>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'uc_settings' ); ?>

                <!-- DISPLAY -->
                <div class="uc-integration-panel uc-panel-open">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'calendar', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Display</h2>
                            <p>How many events to show and how visitors page through them</p>
                        </div>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Events per page</label>
                            <input type="number" name="uc_settings[display_per_page]" value="<?php echo esc_attr( $s( 'display_per_page', '12' ) ); ?>" class="uc-input" min="-1" step="1" style="max-width:120px;" />
                        </div>
                        <p class="description">Default 12. Use 0 or -1 to show all events with no pagination. Override per shortcode with <code>per_page="20"</code>.</p>
                        <div class="uc-field-row">
                            <label>Calendar home URL</label>
                            <input type="url" name="uc_settings[calendar_home_url]" value="<?php echo esc_attr( $s( 'calendar_home_url' ) ); ?>" class="uc-input" placeholder="https://www.sfaf.org/events/" />
                        </div>
                        <p class="description">
                            The page that carries the calendar. Event pages are served from this site and the calendar
                            usually is not, so this is where <strong>All Events</strong> and the category chips on an
                            event page send somebody who arrived without a referrer: a shared link, a search result, a
                            bookmark. When they did click through from a calendar, they go back to that exact page
                            instead, whichever one it was.
                            <br />
                            If there are several calendar pages, this one is the fallback for all of them. The plugin
                            cannot tell which page holds a shortcode and does not try to guess. Leaving this empty falls
                            back to this site's own event archive, which is almost certainly not what a visitor should
                            be shown.
                        </p>
                        <div class="uc-field-row uc-field-row-top">
                            <label>Pagination style</label>
                            <div class="uc-radio-stack">
                                <?php
                                $pg_style = $s( 'display_pagination', 'load_more' );
                                $pg_opts  = array(
                                    'load_more' => 'Load More button: fetches the next batch and appends it',
                                    'pages'     => 'Next / Previous pages: numbered page links below the list',
                                    'infinite'  => 'Infinite scroll: loads more automatically near the bottom',
                                );
                                foreach ( $pg_opts as $val => $label ) :
                                ?>
                                    <label class="uc-radio-opt">
                                        <input type="radio" name="uc_settings[display_pagination]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $pg_style, $val ); ?> />
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BRANDING -->
                <div class="uc-integration-panel uc-panel-open">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'palette', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Branding</h2>
                            <p>Logo, colors, and card style for the public calendar</p>
                        </div>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Logo</label>
                            <div class="uc-logo-control">
                                <input type="text" name="uc_settings[brand_logo]" id="uc_brand_logo" value="<?php echo esc_attr( $s( 'brand_logo' ) ); ?>" class="uc-input uc-monospace" placeholder="No logo selected" />
                                <button type="button" class="button uc-upload-logo">Upload Logo</button>
                                <button type="button" class="button uc-remove-logo">Remove</button>
                                <div class="uc-logo-preview">
                                    <?php if ( $s( 'brand_logo' ) ) : ?>
                                        <img src="<?php echo esc_url( $s( 'brand_logo' ) ); ?>" alt="" />
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="uc-field-row">
                            <label>Primary Color</label>
                            <input type="text" name="uc_settings[brand_primary_color]" value="<?php echo esc_attr( $s( 'brand_primary_color', '#FFD900' ) ); ?>" class="uc-color-field" data-default-color="#FFD900" />
                        </div>
                        <div class="uc-field-row">
                            <label>Accent Color</label>
                            <input type="text" name="uc_settings[brand_accent_color]" value="<?php echo esc_attr( $s( 'brand_accent_color', '#16BECF' ) ); ?>" class="uc-color-field" data-default-color="#16BECF" />
                        </div>
                        <div class="uc-field-row">
                            <label>SFAF Palette</label>
                            <div class="uc-palette">
                                <span class="uc-swatch" style="background:#FFD900" title="SFAF Yellow #FFD900"></span>
                                <span class="uc-swatch" style="background:#000000" title="SFAF Black #000000"></span>
                                <span class="uc-swatch" style="background:#373433" title="SFAF Dark Gray #373433"></span>
                                <span class="uc-swatch" style="background:#16BECF" title="Teal #16BECF"></span>
                                <span class="uc-palette-note">Yellow #FFD900 · Black #000000 · Dark Gray #373433</span>
                            </div>
                        </div>
                        <div class="uc-field-row">
                            <label>Card Style</label>
                            <select name="uc_settings[brand_card_style]" class="uc-input">
                                <?php
                                $styles = array( 'bordered' => 'Bordered', 'shadow' => 'Shadow', 'minimal' => 'Minimal' );
                                $current_style = $s( 'brand_card_style', 'bordered' );
                                foreach ( $styles as $val => $label ) :
                                ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_style, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- RSVP DATA ROUTING -->
                <div class="uc-integration-panel uc-panel-open">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'link', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>RSVP Data Routing</h2>
                            <p>What happens when someone RSVPs</p>
                        </div>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-routing-row">
                            <span class="uc-status-dot uc-dot-green"></span>
                            <div class="uc-routing-info"><strong>Save to database</strong><span>Always on. Every RSVP is stored.</span></div>
                            <label class="uc-toggle uc-toggle-disabled"><input type="checkbox" checked disabled /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <?php
                        /*
                         * THE SITE-WIDE "EMAIL ORGANIZER" ADDRESS AND TOGGLE ARE
                         * GONE, and nothing replaced them here on purpose.
                         *
                         * They were a fallback address used when an event named
                         * nobody. Since 3.25.0 every event has a notification
                         * list that already starts with a real person, whoever
                         * created it, so the case the fallback existed for
                         * cannot arise: an event with an empty list is an event
                         * somebody emptied.
                         *
                         * A site-wide address would also have been the one
                         * recipient nobody could see from the event they were
                         * being emailed about, which is the failure the merge
                         * was for.
                         */
                        ?>
                        <div class="uc-routing-row">
                            <span class="uc-status-dot uc-dot-green"></span>
                            <div class="uc-routing-info">
                                <strong>Tell the event's notification list</strong>
                                <span>Always on, per event. Each event carries its own list of people, teams, and addresses, and it starts with whoever created the event. Edit it on the event.</span>
                            </div>
                            <label class="uc-toggle uc-toggle-disabled"><input type="checkbox" checked disabled /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-routing-row">
                            <span class="uc-status-dot <?php echo $pardot_connected ? 'uc-dot-green' : 'uc-dot-gray'; ?>"></span>
                            <div class="uc-routing-info">
                                <strong>Create Pardot prospect</strong>
                                <span class="<?php echo $pardot_connected ? 'uc-conn-ok' : 'uc-conn-no'; ?>"><?php echo $pardot_connected ? 'Connected' : 'Not configured'; ?></span>
                            </div>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[route_pardot]" value="1" <?php checked( $s( 'route_pardot' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-routing-row">
                            <span class="uc-status-dot <?php echo $s( 'route_google_sheet' ) === '1' ? 'uc-dot-green' : 'uc-dot-gray'; ?>"></span>
                            <div class="uc-routing-info">
                                <strong>Add to Google Sheet</strong>
                                <input type="url" name="uc_settings[route_sheet_url]" value="<?php echo esc_attr( $s( 'route_sheet_url' ) ); ?>" class="uc-input uc-monospace" placeholder="https://docs.google.com/spreadsheets/..." />
                            </div>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[route_google_sheet]" value="1" <?php checked( $s( 'route_google_sheet' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <?php
                        /*
                         * ON WHEN NOTHING HAS BEEN SAVED, AND THE CONTROL HAS TO
                         * SAY SO. This row read the raw setting, so on an
                         * install where nobody had ever pressed Save it drew a
                         * grey dot and an empty switch while the code, which
                         * treats absent as on, was sending confirmations. A
                         * control that disagrees with the behaviour it controls
                         * is worse than no control. Both the dot and the switch
                         * ask SFAF_RSVP::confirmations_enabled(), which is the
                         * same function the sending path asks.
                         */
                        $confirm_on = SFAF_RSVP::confirmations_enabled();
                        ?>
                        <div class="uc-routing-row">
                            <span class="uc-status-dot <?php echo $confirm_on ? 'uc-dot-green' : 'uc-dot-gray'; ?>"></span>
                            <div class="uc-routing-info"><strong>Send confirmation email</strong><span>Emails the person who registered. On unless you switch it off here, and switchable per event.</span></div>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[route_confirmation_email]" value="1" <?php checked( $confirm_on ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                    </div>
                </div>

                <!-- SCHEDULED TASKS -->
                <div class="uc-integration-panel uc-panel-open">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'bolt', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Scheduled Tasks</h2>
                            <p>The hourly runner: morning-of reminders, and automated fetching</p>
                        </div>
                        <span class="uc-panel-status <?php echo SFAF_Cron::wp_cron_disabled() ? 'uc-status-active' : ''; ?>"><?php echo SFAF_Cron::wp_cron_disabled() ? 'System cron' : 'WP pseudo-cron'; ?></span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <?php $health = SFAF_Cron::health(); ?>
                        <p class="description"><strong>Status:</strong> <?php echo esc_html( $health['message'] ); ?>
                            The full run log and a &ldquo;Run now&rdquo; button are on the
                            <a href="<?php echo esc_url( SFAF_Cron::admin_url() ); ?>">Automation screen</a>.</p>

                        <?php if ( ! SFAF_Cron::wp_cron_disabled() ) : ?>
                            <p class="description" style="color:#92400e;">
                                <strong>WordPress is still firing scheduled tasks off visitor traffic.</strong>
                                That means a 6:00am reminder does not go out until somebody happens to visit the site.
                                Add a system cron job hitting <code><?php echo esc_html( SFAF_Cron::cron_url() ); ?></code> hourly,
                                and set <code>define( 'DISABLE_WP_CRON', true );</code> in <code>wp-config.php</code>.
                                The readme has the exact steps.
                            </p>
                        <?php endif; ?>

                        <div class="uc-routing-row">
                            <span class="uc-status-dot <?php echo SFAF_Reminders::enabled() ? 'uc-dot-green' : 'uc-dot-gray'; ?>"></span>
                            <div class="uc-routing-info">
                                <strong>Send morning-of reminders</strong>
                                <span>6:00am site time on the day of the event, to everyone registered plus the event's notification list. Native events only: imported events are never sent for, because their platform sends its own.</span>
                            </div>
                            <?php // Absent-means-off would switch reminders off for any site that
                                  // saves settings before this panel exists, so the default when
                                  // the key has never been written is ON. ?>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[reminders_enabled]" value="1" <?php checked( SFAF_Reminders::enabled() ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-routing-row">
                            <span class="uc-status-dot <?php echo SFAF_Cron::auto_fetch_enabled() ? 'uc-dot-green' : 'uc-dot-gray'; ?>"></span>
                            <div class="uc-routing-info">
                                <strong>Fetch from third-party sources automatically</strong>
                                <span>
                                    <strong>Leave this off for now.</strong> A fetch can take an event off the calendar when
                                    it stops being returned by its source, and that behavior has never been watched through
                                    a real removal. Running it unattended before then is how live events disappear overnight.
                                    Switch it on by hand once one removal has been seen go through correctly.
                                    &ldquo;Fetch updates&rdquo; on the dashboard runs it manually in the meantime.
                                </span>
                            </div>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[auto_fetch_enabled]" value="1" <?php checked( $s( 'auto_fetch_enabled' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <h3>Failure alerts</h3>
                        <p class="description">
                            An admin notice only works on somebody who is logged in and looking, which is exactly what
                            nobody is doing at 3am. These two conditions are also emailed: three failed runs in a row,
                            and no completed run for three hours. You get one message when it breaks, at most one a day
                            while it stays broken, and one when it starts working again.
                            <strong>Separate from the event email settings on purpose:</strong> this is about the
                            server, not about the events.
                        </p>
                        <div class="uc-field-row">
                            <label for="uc_cron_alert_email">Alert address</label>
                            <input type="email" id="uc_cron_alert_email" name="uc_settings[cron_alert_email]"
                                   value="<?php echo esc_attr( $s( 'cron_alert_email' ) ); ?>"
                                   class="uc-input"
                                   placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
                        </div>
                        <p class="description">
                            Leave blank to use this site's administration email
                            (<code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code>).
                            Alerts currently go to <code><?php echo esc_html( SFAF_Cron::alert_recipient() ?: 'nobody' ); ?></code>.
                        </p>
                    </div>
                </div>

                <!-- EMAIL TEMPLATES -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'mail', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Email Templates</h2>
                            <p>Global defaults for confirmation and reminder emails</p>
                        </div>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <p class="uc-token-ref">Tokens: <code>{event_name}</code> <code>{attendee_name}</code> <code>{first_name}</code> <code>{last_name}</code> <code>{event_date}</code> <code>{event_time}</code> <code>{event_end_time}</code> <code>{event_time_range}</code> <code>{event_location}</code> <code>{event_url}</code> <code>{organizer_name}</code> <code>{cancel_link}</code> <code>{cancel_url}</code></p>

                        <h3>Sender</h3>
                        <p class="description">
                            Mail goes out through <code>wp_mail()</code>, so an SMTP plugin or a transactional
                            service can take delivery over without any code change. These three are settings and
                            not constants for exactly that reason. Nothing in the plugin hardcodes a provider.
                            <strong>WordPress mail through Bluehost has poor deliverability;</strong> configure a
                            transactional service before launch or reminders will land in spam.
                        </p>
                        <div class="uc-field-row">
                            <label>From name</label>
                            <input type="text" name="uc_settings[email_from_name]" value="<?php echo esc_attr( $s( 'email_from_name' ) ); ?>" class="uc-input" placeholder="San Francisco AIDS Foundation" />
                        </div>
                        <div class="uc-field-row">
                            <label>From address</label>
                            <input type="email" name="uc_settings[email_from_address]" value="<?php echo esc_attr( $s( 'email_from_address' ) ); ?>" class="uc-input" placeholder="events@sfaf.org" />
                        </div>
                        <div class="uc-field-row">
                            <label>Reply-To</label>
                            <input type="email" name="uc_settings[email_reply_to]" value="<?php echo esc_attr( $s( 'email_reply_to' ) ); ?>" class="uc-input" placeholder="events@sfaf.org" />
                        </div>

                        <h3>Morning-of Reminder</h3>
                        <p class="description">
                            Sent once per event, at 6:00am site time on the day. Leave either field blank to use
                            the shipped default. <code>{cancel_link}</code> renders a whole sentence offering to
                            release the recipient's place, and collapses to nothing for staff on a notification
                            list, who have no registration to cancel.
                        </p>
                        <div class="uc-field-row">
                            <label>Subject</label>
                            <input type="text" name="uc_settings[email_dayof_subject]" value="<?php echo esc_attr( $s( 'email_dayof_subject' ) ); ?>" class="uc-input" placeholder="Today: {event_name}" />
                        </div>
                        <div class="uc-field-row uc-field-row-top">
                            <label>Body</label>
                            <textarea name="uc_settings[email_dayof_body]" rows="8" class="uc-input uc-textarea" placeholder="<?php echo esc_attr( SFAF_Reminders::default_body() ); ?>"><?php echo esc_textarea( $s( 'email_dayof_body' ) ); ?></textarea>
                        </div>

                        <h3>RSVP Confirmation</h3>
                        <div class="uc-field-row">
                            <label>Subject</label>
                            <input type="text" name="uc_settings[email_rsvp_subject]" value="<?php echo esc_attr( $s( 'email_rsvp_subject' ) ); ?>" class="uc-input" placeholder="You're registered for {event_name}" />
                        </div>
                        <div class="uc-field-row uc-field-row-top">
                            <label>Body</label>
                            <textarea name="uc_settings[email_rsvp_body]" rows="4" class="uc-input uc-textarea" placeholder="Hi {attendee_name}, you're registered for {event_name} on {event_date}."><?php echo esc_textarea( $s( 'email_rsvp_body' ) ); ?></textarea>
                        </div>
                        <div class="uc-field-row">
                            <label>Reply-To</label>
                            <input type="email" name="uc_settings[email_rsvp_replyto]" value="<?php echo esc_attr( $s( 'email_rsvp_replyto' ) ); ?>" class="uc-input" placeholder="events@sfaf.org" />
                        </div>

                        <h3>Reminder Signup Confirmation</h3>
                        <div class="uc-field-row">
                            <label>Subject</label>
                            <input type="text" name="uc_settings[email_reminder_subject]" value="<?php echo esc_attr( $s( 'email_reminder_subject' ) ); ?>" class="uc-input" placeholder="You'll get a reminder for {event_name}" />
                        </div>
                        <div class="uc-field-row uc-field-row-top">
                            <label>Body</label>
                            <textarea name="uc_settings[email_reminder_body]" rows="4" class="uc-input uc-textarea" placeholder="Thanks! We'll remind you before {event_name}."><?php echo esc_textarea( $s( 'email_reminder_body' ) ); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- MULTI-SITE API (server) -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'link', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Multi-Site API</h2>
                            <p>This site is the API server. Satellite sites pull events from it using the SFAF Calendar Satellite plugin.</p>
                        </div>
                        <span class="uc-panel-status uc-status-active">Server</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Public Endpoint</label>
                            <input type="text" value="<?php echo esc_url( rest_url( 'sfaf-calendar/v1/events' ) ); ?>" readonly class="uc-input uc-monospace" />
                        </div>
                        <div class="uc-field-row">
                            <label>API Key</label>
                            <input type="text" id="uc_multisite_api_key" name="uc_settings[multisite_api_key]" value="<?php echo esc_attr( $c( 'multisite_api_key' ) ); ?>" class="uc-input uc-monospace" placeholder="Generate a key, then paste it into each satellite" />
                            <button type="button" class="button uc-generate-key">Generate Key</button>
                        </div>
                        <p class="description">When a key is set, the events feed requires the <code>X-SFAF-API-Key</code> header (satellites send it automatically). Leave blank to keep the feed public. This site never pulls from or pushes to other sites.</p>
                    </div>
                </div>

                <!-- GOOGLE MAPS -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'pin', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Google Maps</h2>
                            <p>An optional map on the event page.</p>
                        </div>
                        <span class="uc-panel-status <?php echo SFAF_Credentials::has( 'google_maps_embed_key' ) ? 'uc-status-connected' : 'uc-status-pending'; ?>"><?php echo SFAF_Credentials::has( 'google_maps_embed_key' ) ? 'Connected' : 'Not configured'; ?></span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Maps Embed API key</label>
                            <input type="text" name="uc_settings[google_maps_embed_key]" value="<?php echo esc_attr( $c( 'google_maps_embed_key' ) ); ?>" class="uc-input uc-monospace" placeholder="Leave blank for no map" />
                        </div>
                        <p class="description">
                            <strong>Paste a key and every event page with an address shows a map, loaded from Google when the visitor reaches it.</strong> Google is told the page was viewed, and event pages cover HIV services, substance use, and trans health programming. Leave this blank and the page shows the address as an ordinary Google Maps link alone, with no map and no error.
                        </p>
                        <p class="description">
                            <strong>Restrict the key before you paste it in.</strong> In the Google Cloud console set <em>Application restrictions</em> to HTTP referrers and list both <code>resources.sfaf.org/*</code> and <code>sfaf.org/*</code>, then set <em>API restrictions</em> to the <strong>Maps Embed API</strong> and nothing else. The Embed API has no usage cap, so an unrestricted key is a billing exposure rather than a map risk: anyone who copies it can run it up on their own site against this account.
                        </p>
                    </div>
                </div>

                <!-- GOFUNDME PRO -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'heart', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>GoFundMe Pro</h2>
                            <p>Manage campaigns and display live progress on event cards</p>
                        </div>
                        <span class="uc-panel-status uc-status-active">Active</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <p class="description">GoFundMe Pro uses OAuth2 (client credentials). Tokens and data come from <strong>different hosts</strong>. GoFundMe Pro support has confirmed the token endpoint below is correct, so <strong>leave it as it is</strong>; pro.gofundme.com does not issue tokens. Every request also carries the <code>x-integration-id</code> header they issued, which is what stops their edge security treating this server as a bot.</p>
                        <div class="uc-field-row">
                            <label>Token endpoint URL</label>
                            <input type="url" name="uc_settings[gofundme_token_url]" value="<?php echo esc_attr( $c( 'gofundme_token_url' ) ); ?>"
                                   placeholder="<?php echo esc_attr( SFAF_GFMP::DEFAULT_TOKEN_URL ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>API base URL (data)</label>
                            <input type="url" name="uc_settings[gofundme_api_base]" value="<?php echo esc_attr( $c( 'gofundme_api_base' ) ); ?>"
                                   placeholder="<?php echo esc_attr( SFAF_GFMP::DEFAULT_API_BASE ); ?>" class="uc-input" />
                        </div>
                        <p class="description">Leave blank to use the defaults shown. In use now. Token: <code><?php echo esc_html( SFAF_GFMP::token_endpoint() ); ?></code> &middot; data: <code><?php echo esc_html( SFAF_GFMP::api_base() ); ?></code> &middot; integration ID: <code><?php echo esc_html( SFAF_GFMP::integration_id() ); ?></code></p>
                        <div class="uc-field-row">
                            <label>Client ID</label>
                            <input type="text" name="uc_settings[gofundme_client_id]" value="<?php echo esc_attr( $c( 'gofundme_client_id' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Client Secret</label>
                            <?php // Never rendered back to the browser — only whether one is stored. ?>
                            <input type="password" name="uc_settings[gofundme_client_secret]" value="" autocomplete="new-password"
                                   placeholder="<?php echo SFAF_GFMP::has_secret() ? 'Saved. Leave blank to keep it' : 'Paste the client secret'; ?>"
                                   class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Organization ID</label>
                            <input type="text" name="uc_settings[gofundme_org_id]" value="<?php echo esc_attr( $c( 'gofundme_org_id' ) ); ?>" class="uc-input" />
                        </div>
                        <p class="description">The Organization ID is not used to obtain a token. It identifies which organization's data to read, in calls such as <code>GET /organizations/{org_id}/campaigns</code>. Set it before the campaign step.</p>
                        <div class="uc-field-row">
                            <label>Connection</label>
                            <div class="uc-conn-controls">
                                <?php $gf_status = SFAF_GFMP::status(); ?>
                                <button type="button" class="button uc-gofundme-connect">Test connection</button>
                                <span class="uc-conn-pill <?php echo $gf_status['connected'] ? 'is-connected' : ''; ?>"><?php
                                    echo $gf_status['connected']
                                        ? 'Connected. Token valid for ' . esc_html( $gf_status['expires_human'] )
                                        : 'Not connected';
                                ?></span>
                            </div>
                        </div>
                        <p class="description uc-gofundme-conn-msg" style="display:none;"></p>

                        <?php // [PROBE] Temporary diagnostic. Remove this block, the
                              // [PROBE] block in class-sfaf-gfmp.php and initGfmpProbe()
                              // in admin.js together once the payload is understood. ?>
                        <div class="uc-probe-box">
                            <h3>Campaign probe <span class="uc-probe-tag">diagnostic</span></h3>
                            <p class="description">Calls four campaign endpoints and prints exactly what comes back: status and body, nothing decoded or filtered. <strong>Read-only:</strong> it imports nothing and changes nothing. Here to replace guesswork about where the campaign's real copy and images live.</p>
                            <div class="uc-field-row">
                                <label>Campaign ID</label>
                                <div class="uc-conn-controls">
                                    <input type="text" class="uc-input uc-probe-id" placeholder="e.g. 227362" />
                                    <button type="button" class="button uc-gfmp-probe">Probe campaign</button>
                                    <button type="button" class="button uc-probe-copy" style="display:none;">Copy all output</button>
                                </div>
                            </div>
                            <p class="description uc-probe-msg" style="display:none;"></p>
                            <div class="uc-probe-out" style="display:none;"></div>
                        </div>
                        <div class="uc-field-row">
                            <label>Show progress bar on event cards</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[gofundme_show_progress]" value="1" <?php checked( $s( 'gofundme_show_progress' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Import Campaigns as Events</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[gofundme_auto_import]" value="1" <?php checked( $s( 'gofundme_auto_import' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-campaign-manager">
                            <h3>Campaign Manager</h3>
                            <p class="description">These campaigns populate the dropdown on each event's Integrations box.</p>
                            <div class="uc-repeater" data-repeater="gofundme_campaigns">
                                <div class="uc-repeater-rows">
                                    <?php if ( is_array( $gf_campaigns ) ) : foreach ( $gf_campaigns as $i => $c ) : ?>
                                        <div class="uc-repeater-row">
                                            <input type="text" name="uc_settings[gofundme_campaigns][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="Campaign name" />
                                            <input type="url" name="uc_settings[gofundme_campaigns][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $c['url'] ); ?>" placeholder="https://gofund.me/..." />
                                            <input type="text" name="uc_settings[gofundme_campaigns][<?php echo (int) $i; ?>][goal]" value="<?php echo esc_attr( $c['goal'] ); ?>" placeholder="Goal $" class="uc-repeater-narrow" />
                                            <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                                <button type="button" class="button uc-repeater-add">+ Add Campaign</button>
                                <script type="text/html" class="uc-repeater-template">
                                    <div class="uc-repeater-row">
                                        <input type="text" name="uc_settings[gofundme_campaigns][__INDEX__][name]" placeholder="Campaign name" />
                                        <input type="url" name="uc_settings[gofundme_campaigns][__INDEX__][url]" placeholder="https://gofund.me/..." />
                                        <input type="text" name="uc_settings[gofundme_campaigns][__INDEX__][goal]" placeholder="Goal $" class="uc-repeater-narrow" />
                                        <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                                    </div>
                                </script>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- EVENTBRITE -->
                <?php $eb_status = SFAF_Eventbrite::status(); ?>
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'calendar', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Eventbrite</h2>
                            <p>Connect the Eventbrite account whose events this calendar will read</p>
                        </div>
                        <?php // The badge reflects a real verified call, never a stored flag. ?>
                        <span class="uc-panel-status uc-eventbrite-panel-status <?php echo $eb_status['connected'] ? 'uc-status-connected' : 'uc-status-pending'; ?>"><?php
                            echo $eb_status['connected'] ? 'Connected' : ( $eb_status['has_token'] ? 'Not verified' : 'Not configured' );
                        ?></span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <p class="description">Eventbrite uses a single long-lived <strong>private token</strong> from your account's API keys page. There is no OAuth round trip. It is sent as a bearer token on every request.</p>
                        <div class="uc-field-row">
                            <label>API base URL</label>
                            <input type="url" name="uc_settings[eventbrite_api_base]" value="<?php echo esc_attr( $c( 'eventbrite_api_base' ) ); ?>"
                                   placeholder="<?php echo esc_attr( SFAF_Eventbrite::DEFAULT_API_BASE ); ?>" class="uc-input" />
                        </div>
                        <p class="description">Leave blank to use the default shown. In use now: <code><?php echo esc_html( SFAF_Eventbrite::api_base() ); ?></code> &middot; the test calls <code><?php echo esc_html( SFAF_Eventbrite::me_endpoint() ); ?></code></p>
                        <div class="uc-field-row">
                            <label>Private token</label>
                            <?php // Never rendered back to the browser — only whether one is stored. ?>
                            <input type="password" name="uc_settings[eventbrite_private_token]" value="" autocomplete="new-password"
                                   placeholder="<?php echo SFAF_Eventbrite::has_token() ? 'Saved. Leave blank to keep it' : 'Paste the private token'; ?>"
                                   class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Connection</label>
                            <div class="uc-conn-controls">
                                <button type="button" class="button uc-eventbrite-connect">Test connection</button>
                                <span class="uc-conn-pill <?php echo $eb_status['connected'] ? 'is-connected' : ''; ?>"><?php
                                    if ( $eb_status['connected'] ) {
                                        $eb_who = ( '' !== $eb_status['name'] ) ? $eb_status['name'] : 'Eventbrite';
                                        echo 'Connected as ' . esc_html( $eb_who );
                                        if ( '' !== $eb_status['verified_human'] ) {
                                            echo ', verified ' . esc_html( $eb_status['verified_human'] ) . ' ago';
                                        }
                                    } else {
                                        echo 'Not connected';
                                    }
                                ?></span>
                            </div>
                        </div>
                        <?php if ( $eb_status['connected'] && '' !== $eb_status['email'] ) : ?>
                            <p class="description">Account email: <code><?php echo esc_html( $eb_status['email'] ); ?></code></p>
                        <?php endif; ?>
                        <p class="description uc-eventbrite-conn-msg" style="display:none;"></p>

                        <div class="uc-field-row">
                            <label>Events</label>
                            <div class="uc-conn-controls">
                                <button type="button" class="button uc-eventbrite-preview">Fetch events (preview)</button>
                                <select class="uc-input uc-eventbrite-status uc-repeater-narrow">
                                    <option value="live">Published (live)</option>
                                    <option value="draft">Draft</option>
                                    <option value="started">Started</option>
                                    <option value="ended">Ended</option>
                                    <option value="completed">Completed</option>
                                    <option value="canceled">Canceled</option>
                                    <option value="all">All statuses</option>
                                </select>
                            </div>
                        </div>
                        <p class="description">Reads the account's organizations, then every event under each one, and shows what came back. <strong>Nothing is imported</strong>. This is a look at the data before anything is mapped to events on this site.</p>
                        <p class="description uc-eventbrite-fetch-msg" style="display:none;"></p>
                        <div class="uc-eventbrite-preview-out" style="display:none;"></div>

                        <p class="description">Step 2 of the integration: authenticate, then read. Importing into the Pending queue comes next, using this same fetch.</p>
                    </div>
                </div>

                <!-- PARDOT / SALESFORCE -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'cloud', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Pardot / Salesforce</h2>
                            <p>Manage campaigns and route RSVPs to prospect lists</p>
                        </div>
                        <span class="uc-panel-status <?php echo $pardot_connected ? 'uc-status-connected' : 'uc-status-pending'; ?>"><?php echo $pardot_connected ? 'Connected' : 'Not configured'; ?></span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Salesforce SSO</label>
                            <button type="button" class="button">Connect Salesforce Account</button>
                        </div>
                        <div class="uc-field-row">
                            <label>Business Unit ID</label>
                            <input type="text" name="uc_settings[pardot_business_unit]" value="<?php echo esc_attr( $s( 'pardot_business_unit' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Create Prospect on RSVP</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[pardot_auto_prospect]" value="1" <?php checked( $s( 'pardot_auto_prospect' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Event Notification Emails</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[pardot_event_emails]" value="1" <?php checked( $s( 'pardot_event_emails' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>

                        <div class="uc-campaign-manager">
                            <h3>Campaign Manager</h3>
                            <p class="description">These campaigns populate the multi-select on each event and the mapping table below.</p>
                            <div class="uc-repeater" data-repeater="pardot_campaigns">
                                <div class="uc-repeater-rows">
                                    <?php if ( is_array( $pardot_campaigns ) ) : foreach ( $pardot_campaigns as $i => $c ) : ?>
                                        <div class="uc-repeater-row">
                                            <input type="text" name="uc_settings[pardot_campaigns][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="Campaign name" />
                                            <input type="text" name="uc_settings[pardot_campaigns][<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $c['id'] ); ?>" placeholder="Campaign ID" />
                                            <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                                <button type="button" class="button uc-repeater-add">+ Add Campaign</button>
                                <script type="text/html" class="uc-repeater-template">
                                    <div class="uc-repeater-row">
                                        <input type="text" name="uc_settings[pardot_campaigns][__INDEX__][name]" placeholder="Campaign name" />
                                        <input type="text" name="uc_settings[pardot_campaigns][__INDEX__][id]" placeholder="Campaign ID" />
                                        <button type="button" class="button uc-repeater-remove" aria-label="Remove">&times;</button>
                                    </div>
                                </script>
                            </div>
                        </div>

                        <div class="uc-field-row uc-field-row-top">
                            <label>Global Default Campaign</label>
                            <div style="flex:1;">
                                <input type="text" name="uc_settings[pardot_default_campaign]" value="<?php echo esc_attr( $s( 'pardot_default_campaign' ) ); ?>" class="uc-input" placeholder="Campaign ID, all RSVPs go here regardless" />
                                <p class="description">Every RSVP is assigned to this campaign, no matter the category or event.</p>
                            </div>
                        </div>

                        <div class="uc-campaign-map">
                            <h3>Category &rarr; Campaign Mapping</h3>
                            <?php if ( empty( $pardot_campaigns ) ) : ?>
                                <p class="description">Add campaigns above to enable category mapping.</p>
                            <?php elseif ( is_wp_error( $event_categories ) || empty( $event_categories ) ) : ?>
                                <p class="description">Create event categories to enable mapping.</p>
                            <?php else : ?>
                                <?php foreach ( $event_categories as $cat ) :
                                    $mapped = isset( $category_map[ $cat->term_id ] ) ? (array) $category_map[ $cat->term_id ] : array(); ?>
                                    <div class="uc-map-row uc-map-row-multi">
                                        <span class="uc-map-cat"><?php echo esc_html( $cat->name ); ?></span>
                                        <div class="uc-checkbox-list uc-checkbox-list-inline">
                                            <?php foreach ( $pardot_campaigns as $pc ) : ?>
                                                <label>
                                                    <input type="checkbox" name="uc_settings[pardot_category_map][<?php echo (int) $cat->term_id; ?>][]" value="<?php echo esc_attr( $pc['id'] ); ?>" <?php checked( in_array( $pc['id'], $mapped, true ) ); ?> />
                                                    <?php echo esc_html( $pc['name'] ); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="uc-tier-explainer">
                            <strong>How RSVPs are assigned to campaigns:</strong>
                            <span>Global default <em>+</em> Category mapping <em>+</em> Event-specific campaigns</span>
                        </div>
                    </div>
                </div>

                <!-- GOOGLE CALENDAR -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'calendar', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Google Calendar</h2>
                            <p>Two-way sync between WordPress events and Google Workspace calendars</p>
                        </div>
                        <span class="uc-panel-status uc-status-connected">Connected</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Google Calendar ID</label>
                            <input type="text" name="uc_settings[google_calendar_id]" value="<?php echo esc_attr( $s( 'google_calendar_id' ) ); ?>" class="uc-input" placeholder="events@sfaf.org" />
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Publish to Public Calendar</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[google_auto_publish]" value="1" <?php checked( $s( 'google_auto_publish' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                    </div>
                </div>

                <!-- GALAXY DIGITAL -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'handshake', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Galaxy Digital</h2>
                            <p>Import volunteer opportunities from volunteers.sfaf.org</p>
                        </div>
                        <span class="uc-panel-status uc-status-connected">Connected</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <p class="description">Get Connected (v1.9.2) uses Bearer-token auth. Base URL: <code>https://api.galaxydigital.com/api/</code></p>
                        <div class="uc-field-row">
                            <label>API Key (Bearer Token)</label>
                            <input type="password" name="uc_settings[galaxy_api_key]" value="<?php echo esc_attr( $c( 'galaxy_api_key' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Portal URL</label>
                            <input type="text" name="uc_settings[galaxy_portal_url]" value="<?php echo esc_attr( $s( 'galaxy_portal_url' ) ); ?>" class="uc-input" placeholder="https://volunteers.sfaf.org" />
                        </div>
                        <div class="uc-field-row">
                            <label>Agency / Organization ID</label>
                            <input type="text" name="uc_settings[galaxy_agency_id]" value="<?php echo esc_attr( $s( 'galaxy_agency_id' ) ); ?>" class="uc-input" />
                        </div>
                        <div class="uc-field-row">
                            <label>Import Needs as Category</label>
                            <select name="uc_settings[galaxy_needs_category]" class="uc-input">
                                <option value="">Select a category</option>
                                <option value="__create__" <?php selected( $s( 'galaxy_needs_category' ), '__create__' ); ?>>Auto-create "Volunteer Opportunities"</option>
                                <?php if ( ! is_wp_error( $event_categories ) ) : foreach ( $event_categories as $cat ) : ?>
                                    <option value="<?php echo (int) $cat->term_id; ?>" <?php selected( $s( 'galaxy_needs_category' ), (string) $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <div class="uc-field-row">
                            <label>Import Galaxy Digital Events</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[galaxy_import_events]" value="1" <?php checked( $s( 'galaxy_import_events' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Active Needs Only</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[galaxy_active_only]" value="1" <?php checked( $s( 'galaxy_active_only', '1' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Auto-Sync</label>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[galaxy_auto_sync]" value="1" <?php checked( $s( 'galaxy_auto_sync' ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <div class="uc-field-row">
                            <label>Sync Interval</label>
                            <select name="uc_settings[galaxy_sync_interval]" class="uc-input">
                                <option value="5" <?php selected( $s( 'galaxy_sync_interval' ), '5' ); ?>>5 minutes</option>
                                <option value="15" <?php selected( $s( 'galaxy_sync_interval', '15' ), '15' ); ?>>15 minutes</option>
                                <option value="30" <?php selected( $s( 'galaxy_sync_interval' ), '30' ); ?>>30 minutes</option>
                                <option value="60" <?php selected( $s( 'galaxy_sync_interval' ), '60' ); ?>>1 hour</option>
                            </select>
                        </div>
                        <div class="uc-field-row">
                            <label>Manual Sync</label>
                            <div class="uc-conn-controls">
                                <button type="button" class="button uc-galaxy-sync">Sync Now</button>
                                <span class="uc-conn-pill <?php echo $c( 'galaxy_api_key' ) ? 'is-connected' : ''; ?>"><?php echo $c( 'galaxy_api_key' ) ? 'Connected' : 'Not configured'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WEBHOOKS -->
                <div class="uc-integration-panel">
                    <div class="uc-panel-header" onclick="this.parentElement.classList.toggle('uc-panel-open')">
                        <span class="uc-panel-icon"><?php echo sfaf_icon( 'bolt', array( 'size' => '20px' ) ); ?></span>
                        <div class="uc-panel-info">
                            <h2>Webhooks (n8n / Zapier)</h2>
                            <p>Fire outbound webhooks on event actions for custom automation</p>
                        </div>
                        <span class="uc-panel-status uc-status-active">Active</span>
                        <span class="uc-panel-toggle">&#9660;</span>
                    </div>
                    <div class="uc-panel-body">
                        <div class="uc-field-row">
                            <label>Webhook URL</label>
                            <input type="url" name="uc_settings[webhook_url]" value="<?php echo esc_attr( $s( 'webhook_url' ) ); ?>" class="uc-input uc-monospace" />
                        </div>
                        <div class="uc-field-row">
                            <label>Secret Key (HMAC)</label>
                            <input type="password" name="uc_settings[webhook_secret]" value="<?php echo esc_attr( $c( 'webhook_secret' ) ); ?>" class="uc-input" />
                        </div>
                        <h3>Event Triggers</h3>
                        <?php
                        $triggers = array(
                            'webhook_event_created' => 'event.created',
                            'webhook_event_updated' => 'event.updated',
                            'webhook_event_deleted' => 'event.deleted',
                            'webhook_event_rsvp'    => 'event.rsvp',
                        );
                        foreach ( $triggers as $key => $label ) :
                        ?>
                        <div class="uc-field-row uc-trigger-row">
                            <code><?php echo esc_html( $label ); ?></code>
                            <label class="uc-toggle"><input type="checkbox" name="uc_settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $s( $key ), '1' ); ?> /><span class="uc-toggle-slider"></span></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="uc-save-bar">
                    <?php submit_button( 'Save All Settings', 'primary', 'submit', false ); ?>
                    <button type="button" class="button">Test All Connections</button>
                </div>
            </form>
        </div>
        <?php
    }
}
