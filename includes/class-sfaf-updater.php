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

    public function register() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
        add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
        add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 2 );
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
     */
    public function forget( $upgrader, $options ) {
        if ( isset( $options['action'], $options['type'] )
            && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
            delete_site_transient( self::CACHE_KEY );
        }
    }
}
