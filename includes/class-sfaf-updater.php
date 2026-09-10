<?php
/**
 * UPDATES FROM INSIDE WORDPRESS, FROM GITHUB RELEASES.
 *
 * The plugin ships as a zip that somebody uploads. This makes it behave like
 * any other plugin instead: WordPress checks for a newer version, the Plugins
 * screen shows the update notice, and Update now does the rest.
 *
 * WHAT IT ASKS FOR, AND WHY THAT ONE THING. The GitHub releases API for a
 * PUBLIC repository, unauthenticated. A private repository would mean storing a
 * token on the site to serve its release assets, and a token on the site is the
 * thing this arrangement exists to avoid. That is the whole reason the public
 * repository exists.
 *
 * ONE ZIP, NOT TWO. The update downloads the release ASSET, which is the very
 * file `.claude/build-zip.sh` produces and which is already what gets uploaded
 * by hand today. It is NOT GitHub's auto-generated source zip: that one is
 * named after the tag, contains the whole repository including the checkers and
 * the documents, and unpacks to a folder WordPress would treat as a different
 * plugin. asset_url() below refuses anything but the built asset for exactly
 * that reason, so a release published without one reports no update rather than
 * installing the wrong thing.
 *
 * ONE VERSION, NOT TWO. The installed version is SFAF_VERSION, and the
 * available version is the release tag with any leading "v" removed. Nothing
 * here holds a version of its own, and build-zip.sh refuses to build unless the
 * three places version discipline already bumps agree with each other, so the
 * tag is derived from them rather than being a fourth place to keep in step.
 *
 * WHAT IT DOES NOT DO. It never downgrades, never installs across a major
 * version by itself, and never fires on a site that has no releases to see. The
 * result is cached for twelve hours and on every failure, so a rate limit or an
 * outage costs one slow admin page rather than one per page load.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Updater {

    /** The public repository releases are served from. */
    const REPO = 'sfaf-websites/sfaf-calendar-plugin';

    /** Where the answer is cached, and for how long. */
    const CACHE_KEY = 'sfaf_updater_release';
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /** Cached for a shorter time after a failure, so an outage self-heals. */
    const CACHE_TTL_FAIL = 1 * HOUR_IN_SECONDS;

    /** The forced check: its admin-post action, and the nonce that guards it. */
    const CHECK_ACTION = 'sfaf_check_updates';

    public function register() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
        add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
        add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 2 );

        /*
         * THE FORCED CHECK (3.73.0).
         *
         * Both halves are admin-only and neither is on the front end, so they
         * are added here rather than in register()'s always-on set: the link
         * renders on one screen and the handler answers one POST.
         */
        add_filter( 'plugin_action_links_' . self::basename(), array( $this, 'action_link' ) );
        add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_check' ) );
        add_action( 'admin_notices', array( $this, 'check_notice' ) );
    }

    /** `sfaf-calendar/sfaf-calendar.php`, however the folder is actually named. */
    public static function basename() {
        return plugin_basename( SFAF_PLUGIN_DIR . 'sfaf-calendar.php' );
    }

    /**
     * The latest release, or null.
     *
     * @return array{version:string,zip:string,url:string,notes:string,date:string}|null
     */
    public static function latest( $force = false ) {
        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                return empty( $cached['version'] ) ? null : $cached;
            }
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    // GitHub refuses a request with no user agent.
                    'User-Agent' => 'SFAF-Calendar/' . SFAF_VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            set_site_transient( self::CACHE_KEY, array( 'version' => '' ), self::CACHE_TTL_FAIL );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['tag_name'] ) || ! empty( $body['draft'] ) ) {
            set_site_transient( self::CACHE_KEY, array( 'version' => '' ), self::CACHE_TTL_FAIL );
            return null;
        }

        $version = ltrim( (string) $body['tag_name'], 'vV' );
        $zip     = self::asset_url( $body );

        // A release with no built asset is not an update. See the file header.
        if ( '' === $zip || ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
            set_site_transient( self::CACHE_KEY, array( 'version' => '' ), self::CACHE_TTL_FAIL );
            return null;
        }

        $release = array(
            'version' => $version,
            'zip'     => $zip,
            'url'     => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
            'notes'   => isset( $body['body'] ) ? (string) $body['body'] : '',
            'date'    => isset( $body['published_at'] ) ? substr( (string) $body['published_at'], 0, 10 ) : '',
        );
        set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
        return $release;
    }

    /**
     * The built zip attached to a release, and nothing else.
     *
     * `sfaf-calendar-3.70.0.zip`, matching what build-zip.sh names. GitHub's
     * own `zipball_url` is deliberately not accepted: it is the repository, not
     * the plugin.
     */
    private static function asset_url( $body ) {
        if ( empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
            return '';
        }
        foreach ( $body['assets'] as $asset ) {
            $name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
            if ( preg_match( '/^sfaf-calendar-\d+\.\d+\.\d+\.zip$/', $name )
                && ! empty( $asset['browser_download_url'] ) ) {
                return (string) $asset['browser_download_url'];
            }
        }
        return '';
    }

    /**
     * Tell WordPress an update is available.
     *
     * THE GUARD IS version_compare AND NOTHING ELSE, so a release older than
     * what is installed is silently not an update rather than a downgrade
     * offered to somebody who would accept it.
     */
    public function inject( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        $release = self::latest();
        if ( ! $release ) {
            return $transient;
        }

        $file = self::basename();
        if ( version_compare( $release['version'], SFAF_VERSION, '<=' ) ) {
            // Up to date. Recorded so the Plugins screen does not keep asking.
            if ( isset( $transient->response[ $file ] ) ) {
                unset( $transient->response[ $file ] );
            }
            $transient->no_update[ $file ] = self::payload( $release );
            return $transient;
        }

        $transient->response[ $file ] = self::payload( $release );
        return $transient;
    }

    /** The object both response and no_update want. */
    private static function payload( $release ) {
        $file = self::basename();
        return (object) array(
            'id'          => self::REPO,
            'slug'        => dirname( $file ),
            'plugin'      => $file,
            'new_version' => $release['version'],
            'url'         => $release['url'],
            'package'     => $release['zip'],
            'tested'      => get_bloginfo( 'version' ),
            'requires_php' => '7.4',
            'icons'       => array(),
        );
    }

    /**
     * The "View details" panel.
     *
     * Without this WordPress offers a link to wordpress.org, where this plugin
     * does not exist and never will.
     */
    public function details( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || dirname( self::basename() ) !== $args->slug ) {
            return $result;
        }
        $release = self::latest();
        if ( ! $release ) {
            return $result;
        }
        return (object) array(
            'name'          => 'SFAF Calendar',
            'slug'          => $args->slug,
            'version'       => $release['version'],
            'author'        => 'San Francisco AIDS Foundation',
            'homepage'      => 'https://github.com/' . self::REPO,
            'download_link' => $release['zip'],
            'last_updated'  => $release['date'],
            'requires_php'  => '7.4',
            'sections'      => array(
                'description' => 'The San Francisco AIDS Foundation event calendar.',
                'changelog'   => '' !== $release['notes']
                    ? wpautop( wp_kses_post( $release['notes'] ) )
                    : '<p>See the release on GitHub.</p>',
            ),
        );
    }

    /**
     * Forget the cached answer after an update, so the Plugins screen does not
     * keep offering a version that has just been installed.
     *
     * INSTALLING COUNTS, NOT ONLY UPDATING (3.73.0). This asked for
     * `'update' === $options['action']` and nothing else, and **uploading a zip
     * on the Plugins screen is `install`, not `update`**. That is how every
     * release before 3.70.0 reached this site and it is how 3.72.0 reached it
     * in the end, so the one route that was used most often was the one route
     * that did not clear the cache.
     *
     * IT DOES NOT ASK WHETHER THE UPGRADE SUCCEEDED, deliberately. A half
     * finished install is exactly when the cached answer is least trustworthy:
     * the files on disk may be the new version, the old one, or a mixture, and
     * this hook cannot tell which. Clearing costs one HTTP request on the next
     * check and re-derives the answer from the files that are actually there.
     *
     * IT DOES NOT ASK WHETHER IT WAS THIS PLUGIN either, and that is the same
     * trade. `$options['plugins']` is present on a bulk run and absent on a
     * single one, so telling them apart is two shapes to get right in order to
     * save a request nobody notices.
     *
     * WHAT IT STILL CANNOT COVER. `upgrader_process_complete` does not fire at
     * all when WP_Upgrader bails early, on a failed download or a failed
     * unpack. The remedy for that is not another hook, it is the manual check
     * below: there is now a control a person can press, which is what this
     * arrangement lacked entirely.
     */
    public function forget( $upgrader, $options ) {
        if ( ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
            return;
        }
        $action = isset( $options['action'] ) ? (string) $options['action'] : '';
        if ( 'update' !== $action && 'install' !== $action ) {
            return;
        }
        delete_site_transient( self::CACHE_KEY );
    }

    /* =====================================================================
     * The forced check
     *
     * WHY THIS HAD TO EXIST, AND IT IS NOT A CONVENIENCE.
     *
     * `latest()` has taken a `$force` argument since 3.70.0 and nothing has
     * ever passed `true`. Both call sites, inject() and details(), ask without
     * it, so every answer this plugin has ever given about whether an update
     * exists came out of a twelve-hour cache that only an install could clear.
     *
     * The consequence was reported as "the site is not offering 3.72.0".
     * WordPress's own **Check again** on Dashboard > Updates calls
     * wp_clean_update_cache(), which deletes `update_core`, `update_plugins`
     * and `update_themes`, and does not touch `sfaf_updater_release`. So the
     * forced check fires our filter, our filter reads our own cache, and
     * WordPress is answered from memory. Pressing it could never work, and
     * both build scripts told the operator it would.
     *
     * ON THE PLUGINS SCREEN, WHICH IS WHERE SOMEBODY IS STANDING when they want
     * it. Not in caladmin: updating the plugin is an administrator concern and
     * caladmin is for calendar managers, which is the 3.27.0 rule.
     * ================================================================== */

    /**
     * "Check for updates", beside Deactivate on the Plugins screen.
     *
     * @param string[] $links
     * @return string[]
     */
    public function action_link( $links ) {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return $links;
        }
        $url = wp_nonce_url(
            add_query_arg( 'action', self::CHECK_ACTION, admin_url( 'admin-post.php' ) ),
            self::CHECK_ACTION
        );
        // Prepended: it is the one of these links somebody came looking for.
        array_unshift(
            $links,
            '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'sfaf-calendar' ) . '</a>'
        );
        return $links;
    }

    /**
     * Ask GitHub now, whatever the cache says.
     *
     * THE ORDER OF THE FOUR STEPS IS THE WHOLE OF IT, and getting it wrong
     * leaves the screen saying the same thing it said before:
     *
     *   1. drop our cache, so nothing can answer from memory
     *   2. latest( true ), which fetches once and writes the fresh answer back
     *   3. drop WordPress's `update_plugins`, so its check cannot short-circuit
     *   4. wp_update_plugins(), which re-runs the check and fires inject()
     *
     * Step 4 reads the cache step 2 just wrote, so this is ONE request to
     * GitHub rather than two. Step 3 is what makes step 4 actually run:
     * wp_update_plugins() returns early while `last_checked` is recent, and
     * deleting the transient is what makes it not recent.
     */
    public function handle_check() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to check for updates.', 'sfaf-calendar' ) );
        }
        check_admin_referer( self::CHECK_ACTION );

        $release = self::run_check();

        /*
         * THE OUTCOME IS CARRIED IN THE URL, and it is the version rather than
         * a yes or no. "No update" and "the check failed" are different facts
         * and a person who has just pressed this needs to be able to tell them
         * apart: one means wait, the other means look at the network.
         */
        $args = array( 'sfaf_checked' => '1' );
        if ( is_array( $release ) && ! empty( $release['version'] ) ) {
            $args['sfaf_found'] = $release['version'];
        }

        wp_safe_redirect( add_query_arg( $args, self_admin_url( 'plugins.php' ) ) );
        exit;
    }

    /**
     * The four steps, with no capability check, no nonce and no redirect.
     *
     * SEPARATE FROM handle_check() SO IT CAN BE RUN, which is the only reason
     * it is its own method. `exit` is a language construct and cannot be
     * stubbed, so a checker calling handle_check() would take the process with
     * it; this is the part with the behaviour in it and
     * `.claude/updater-test.php` executes it and reads the transients
     * afterwards.
     *
     * THE GATES ARE NOT IN HERE ON PURPOSE. This is called from exactly one
     * place, which asks both of them first, and a second capability check
     * inside a method that cannot be reached without passing one would read as
     * though it could.
     *
     * @return array|null Whatever latest() found.
     */
    public static function run_check() {
        /*
         * 1. THE FORCE IS THE MECHANISM, AND THERE IS NO delete() IN FRONT OF
         *    IT. There was, and it had to come out: clearing our cache first
         *    makes an unforced latest() fetch anyway, so the `true` became
         *    decoration and a regression that dropped it changed nothing
         *    observable. .claude/updater-test.php plants exactly that, and it
         *    went uncaught until this line carried the whole job.
         *
         *    Nothing is lost. latest( true ) skips the read, and EVERY path
         *    through it writes the cache back: the release on success, the
         *    empty marker on a failure. So the cache is replaced either way.
         */
        $release = self::latest( true );

        // 2. WordPress's own, so its check cannot short-circuit on last_checked.
        delete_site_transient( 'update_plugins' );

        // 3. Which re-runs the check and fires inject(), reading step 1's cache.
        wp_update_plugins();

        return $release;
    }

    /**
     * Say what the forced check found, on the screen it returns to.
     *
     * IT NAMES BOTH VERSIONS. "An update is available" is what WordPress's own
     * row already says; what this adds is the answer to "did it actually go and
     * look", which is the question somebody pressing the link is asking.
     */
    public function check_notice() {
        if ( empty( $_GET['sfaf_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        $found = isset( $_GET['sfaf_found'] ) ? sanitize_text_field( wp_unslash( $_GET['sfaf_found'] ) ) : '';
        // Re-checked on the way out: this becomes screen text, and a stored or
        // submitted value can predate the rule that would have refused it.
        if ( '' !== $found && ! preg_match( '/^\d+\.\d+\.\d+$/', $found ) ) {
            $found = '';
        }

        if ( '' === $found ) {
            $class = 'notice-warning';
            $text  = sprintf(
                /* translators: %s: the installed version. */
                __( 'SFAF Calendar: the update check could not reach GitHub. You are running %s. Nothing has changed; try again in a few minutes.', 'sfaf-calendar' ),
                SFAF_VERSION
            );
        } elseif ( version_compare( $found, SFAF_VERSION, '>' ) ) {
            $class = 'notice-success';
            $text  = sprintf(
                /* translators: 1: the released version, 2: the installed version. */
                __( 'SFAF Calendar: %1$s is available. You are running %2$s. Update now is in the row below.', 'sfaf-calendar' ),
                $found,
                SFAF_VERSION
            );
        } else {
            $class = 'notice-info';
            $text  = sprintf(
                /* translators: %s: the installed version. */
                __( 'SFAF Calendar: checked just now, and %s is the latest release. Nothing to install.', 'sfaf-calendar' ),
                SFAF_VERSION
            );
        }

        printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $text ) );
    }
}
