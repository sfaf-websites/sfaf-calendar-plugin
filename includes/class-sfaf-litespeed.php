<?php
/**
 * LITESPEED CACHE IS TOLD WHAT TO DROP (3.127.0).
 *
 * resources.sfaf.org runs LiteSpeed Cache, which keeps the embed's answers and
 * the event pages for up to a week. The embed's own cache is retired by
 * SFAF_Embed::flush_cache(), which LiteSpeed never hears about, so a change
 * could take days to show on sfaf.org.
 *
 * WHAT IS PURGED, BY TAG, AND NOTHING ELSE:
 *
 *   REST       every answer LiteSpeed cached from the REST API, which is how
 *              it labels the embed's (/wp-json/sfaf-calendar/v1/embed). Sent wherever
 *              flush_cache() runs: a save, a status, a meta or term write, a
 *              closure, the settings, an RSVP.
 *   Po.<id>    one event's own page, when it is published or is leaving
 *              published (trashed, deleted, back to draft).
 *
 * NEVER litespeed_purge_post. It adds whatever the site's purge rules name,
 * and with its "All pages" purge rule on that is '*', the whole
 * site, on every save.
 *
 * ONE PURGE PER REQUEST. Each tag is sent once per request, however many
 * hooks fire, and LiteSpeed merges every tag it is given in a request into one
 * X-LiteSpeed-Purge header (in cron or WP-CLI, one queued purge). A bulk
 * action or an import is one request, so it is one purge.
 *
 * NOTHING ABOUT THE EMBED'S REQUESTS CHANGES. No tag, header or hook is added
 * to the route; this only speaks when something is written.
 *
 * WITHOUT LITESPEED CACHE nothing listens on litespeed_purge, and this returns
 * before doing anything.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_LiteSpeed {

    /** LiteSpeed Cache's own tag for a cached REST answer. */
    const TAG_REST = 'REST';

    /** LiteSpeed Cache's prefix for a post's own page. */
    const TAG_POST = 'Po.';

    /** @var array Tags already sent in this request, as tag => true. */
    private static $sent = array();

    /** Whether LiteSpeed Cache is active and listening. */
    public static function active() {
        return function_exists( 'has_action' ) && false !== has_action( 'litespeed_purge' );
    }

    /** The embed's answers. */
    public static function purge_embed() {
        self::send( array( self::TAG_REST ) );
    }

    /**
     * One event's page, when a visitor could have it cached.
     *
     * @param int  $post_id
     * @param bool $was_public The event was published before this change.
     */
    public static function purge_event( $post_id, $was_public = false ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || ! self::active() ) {
            return;
        }
        if ( ! $was_public && 'publish' !== get_post_status( $post_id ) ) {
            return;
        }
        self::send( array( self::TAG_POST . $post_id ) );
    }

    /** @param string[] $tags */
    private static function send( $tags ) {
        if ( ! self::active() ) {
            return;
        }
        $new = array();
        foreach ( $tags as $t ) {
            if ( ! isset( self::$sent[ $t ] ) ) {
                self::$sent[ $t ] = true;
                $new[] = $t;
            }
        }
        if ( $new ) {
            do_action( 'litespeed_purge', $new );
        }
    }

    /** For the checks: start a fresh request. */
    public static function reset() {
        self::$sent = array();
    }
}
