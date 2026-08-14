<?php
/**
 * PRIVATE EVENTS: not findable, fully working for anybody holding the link.
 *
 * WHAT THIS IS AND IS NOT. It is an unlisted-link scheme, which is what SFAF
 * asked for: a reception for donors above a giving level, where the guest list
 * is managed by who receives the invitation rather than by the software. It is
 * NOT access control. A link can be forwarded, and everybody involved knows it.
 *
 * The bar it does have to clear is that the event cannot be FOUND by somebody
 * who was not sent the link. That is a claim about every route into an event,
 * not about the ones anybody happened to think of, which is why the routes are
 * enumerated in the readme and asserted in .claude/private-events-test.php as a
 * WHITELIST: a private event may appear in the places section 3 names and
 * nowhere else, so a query added later cannot quietly surface one.
 *
 * PRIVACY LIVES ON THE EVENT AND NEVER ON THE SERIES.
 * ---------------------------------------------------------------------------
 * One checkbox, on the event, default off. A private event is hidden whatever
 * series it belongs to, and a private event in a public series does not appear
 * on that series page. A wholly private series is every event in it marked
 * private.
 *
 * There is deliberately NO series-level setting. Two settings that can
 * contradict each other are worse than one rule: the moment a series says
 * "private" and an event in it says "public", something has to decide which
 * wins, and whatever it decides will be wrong for somebody. A series still
 * carries the authoring details, so creating an event into one starts from the
 * series rather than from scratch, and privacy is simply not one of the things
 * it carries.
 *
 * THE URL IS THE CREDENTIAL, SO IT HAS TO BE UNGUESSABLE.
 * ---------------------------------------------------------------------------
 * Making an event private replaces its slug with 32 hex characters from
 * random_bytes(), which is 128 bits. A readable slug is not a credential:
 * /events/donor-reception is a thing somebody types. The previous slug is kept
 * so making the event public again restores the address it used to have.
 *
 * EACH OCCURRENCE OF A PRIVATE SERIES GETS ITS OWN TOKEN, and that is not
 * decoration. Occurrence slugs are normally {seed-slug}-{date}, so one token
 * shared across a series would mean that being sent one date hands you every
 * other date by editing the URL. Independent tokens make one forwarded link
 * exactly one forwarded link.
 *
 * WHAT DOES NOT CHANGE. Everything, for anybody holding the link: the page
 * renders, registration works, all four emails send, add to calendar, the map,
 * capacity and cancellation behave normally. A private event is a normal event
 * that cannot be found.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Privacy {

    /** '1' when the event is private. Absent means public, so a default costs no writes. */
    const META = '_uc_private';

    /** The slug the event had before it was made private, so it can be given back. */
    const PREV_SLUG_META = '_uc_private_prev_slug';

    /**
     * Yoast's own noindex meta.
     *
     * WRITTEN DIRECTLY, ON PURPOSE. Yoast excludes a post it considers noindex
     * from its sitemap by itself, so one write covers both the robots tag Yoast
     * emits and the sitemap it builds. A filter would cover only whichever of
     * the two it was attached to, and Yoast has moved which filter is the right
     * one more than once. The wpseo_exclude_from_sitemap_by_post_ids filter is
     * registered as well, for the case where this meta is missing because an
     * event was made private before this release.
     */
    const YOAST_NOINDEX_META = '_yoast_wpseo_meta-robots-noindex';

    public static function register() {
        /*
         * ONE HOOK PER ROUTE, EACH NARROW, AND DELIBERATELY NOT ONE BROAD
         * pre_get_posts THAT HIDES PRIVATE EVENTS FROM EVERYTHING.
         *
         * pre_get_posts fires for every WP_Query in the process, including the
         * reminder runner's, the summary runner's, the recurrence generator's
         * and the whole of /caladmin. A blanket "hide unless is_admin()" would
         * have stopped a private event's morning-of reminder from ever being
         * sent, on a front-end cron request, silently. That is the opposite of
         * what section 3 promises.
         *
         * So the front-end hook below tests is_main_query() and names the four
         * kinds of page it applies to. An internal query is never the main
         * query, so it cannot be caught by accident.
         */
        add_action( 'pre_get_posts', array( __CLASS__, 'hide_from_main_query' ) );

        // Core's REST collection for the post type. Not the main query, so it
        // needs its own hook, and it has a precise one.
        add_filter( 'rest_uc_event_query', array( __CLASS__, 'hide_from_rest' ), 10, 2 );

        // Core's XML sitemap.
        add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'hide_from_core_sitemap' ), 10, 2 );

        // Yoast's XML sitemap, belt to the noindex meta's braces.
        add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'yoast_excluded_ids' ) );

        // noindex, nofollow on the page itself.
        add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
    }

    /* =====================================================================
     * Reading
     * ================================================================== */

    /** Whether this event is private. */
    public static function is_private( $post_id ) {
        return '1' === (string) get_post_meta( (int) $post_id, self::META, true );
    }

    /**
     * The meta_query clause that removes private events from a result set.
     *
     * NOT EXISTS OR != '1', because absent is the normal state. Every event
     * that predates this feature has no row at all, and a bare != would drop
     * all of them: MySQL cannot compare a value that is not there.
     *
     * @return array
     */
    public static function meta_clause() {
        return array(
            'relation' => 'OR',
            array( 'key' => self::META, 'compare' => 'NOT EXISTS' ),
            array( 'key' => self::META, 'value' => '1', 'compare' => '!=' ),
        );
    }

    /**
     * Add the clause to a WP_Query args array, in place.
     *
     * APPENDED AS A SIBLING WHERE THAT IS SAFE, WRAPPED ONLY WHERE IT IS NOT,
     * and the difference matters because of a trap this codebase already
     * documents.
     *
     * SFAF_Shortcodes::month_grid_data() uses a NAMED meta_query clause and
     * sorts by that name, with a comment explaining that getting the shape of
     * this array wrong makes WP_Query join wp_postmeta twice and multiply rows.
     * Wrapping its clause inside another array moves the name a level deeper.
     * WP_Meta_Query does collect named clauses recursively, so it would very
     * probably still sort correctly, and "very probably" is not the standard
     * for the query behind the month grid.
     *
     * So: an implicit or explicit AND gets the exclusion appended beside what
     * is already there, which cannot change the meaning of anything and cannot
     * move a name. Only an explicit OR gets wrapped, because appending to an OR
     * would make the date window optional, and there is no such caller today.
     *
     * @param array $args WP_Query args, modified in place.
     */
    public static function exclude( &$args ) {
        $existing = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

        if ( empty( $existing ) ) {
            $args['meta_query'] = array( self::meta_clause() );
            return;
        }

        $relation = isset( $existing['relation'] ) ? strtoupper( (string) $existing['relation'] ) : 'AND';

        if ( 'AND' === $relation ) {
            $existing[]         = self::meta_clause();
            $args['meta_query'] = $existing;
            return;
        }

        $args['meta_query'] = array(
            'relation' => 'AND',
            $existing,
            self::meta_clause(),
        );
    }

    /**
     * Every private event id.
     *
     * Used by the Yoast sitemap filter, which wants a list rather than a query.
     * There are not many of these by definition: a private event is a
     * deliberate act on one event.
     *
     * @return int[]
     */
    public static function private_ids() {
        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => 'any',
            'posts_per_page'         => 500,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array( 'key' => self::META, 'value' => '1' ),
            ),
        ) );
        return array_map( 'intval', (array) $q->posts );
    }

    /* =====================================================================
     * Writing
     * ================================================================== */

    /**
     * Make an event private, or public again.
     *
     * THE SLUG IS PART OF THE STATE, not a side effect. An event is private
     * when the meta says so AND its address is unguessable; either alone is a
     * half-done job, so both move here together and nowhere else.
     *
     * @param int  $post_id
     * @param bool $private
     * @return bool Whether anything changed.
     */
    public static function set( $post_id, $private ) {
        $post_id = (int) $post_id;
        $post    = get_post( $post_id );
        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return false;
        }

        $private = (bool) $private;
        if ( $private === self::is_private( $post_id ) ) {
            return false;
        }

        if ( $private ) {
            update_post_meta( $post_id, self::META, '1' );

            // Remember the readable address so it can be given back, but only
            // the first time: a second pass must not record the token as though
            // it were the original.
            if ( '' === (string) get_post_meta( $post_id, self::PREV_SLUG_META, true ) ) {
                update_post_meta( $post_id, self::PREV_SLUG_META, $post->post_name );
            }

            self::randomize_slug( $post_id );

            // Yoast, if it is here. Harmless if it is not: an unused meta row.
            update_post_meta( $post_id, self::YOAST_NOINDEX_META, '1' );
            return true;
        }

        delete_post_meta( $post_id, self::META );
        delete_post_meta( $post_id, self::YOAST_NOINDEX_META );

        $prev = (string) get_post_meta( $post_id, self::PREV_SLUG_META, true );
        if ( '' !== $prev ) {
            wp_update_post( array( 'ID' => $post_id, 'post_name' => $prev ) );
            delete_post_meta( $post_id, self::PREV_SLUG_META );
        }
        return true;
    }

    /**
     * Give this event a fresh unguessable slug.
     *
     * 32 hex characters, 128 bits, from random_bytes(). Not sanitize_title() of
     * anything: the point is that the address carries no information about what
     * the event is.
     *
     * @param int $post_id
     */
    public static function randomize_slug( $post_id ) {
        wp_update_post( array(
            'ID'        => (int) $post_id,
            'post_name' => self::new_slug(),
        ) );
    }

    /** A slug nobody is going to type. */
    public static function new_slug() {
        try {
            return bin2hex( random_bytes( 16 ) );
        } catch ( Exception $e ) {
            // random_bytes() throws rather than returning something weak. There
            // is no acceptable fallback here, because a guessable slug is the
            // one failure this feature cannot have, so this uses WordPress's
            // own CSPRNG wrapper rather than inventing a weaker one.
            return strtolower( wp_generate_password( 32, false, false ) );
        }
    }

    /* =====================================================================
     * The routes
     * ================================================================== */

    /**
     * Public WordPress pages: search, the events archive, the term archives
     * and feeds.
     *
     * MAIN QUERY ONLY, AND NAMED PAGE KINDS ONLY. See register() for why this
     * is not a blanket rule. A singular request is deliberately absent: that IS
     * the direct link, and it must render.
     *
     * @param WP_Query $q
     */
    public static function hide_from_main_query( $q ) {
        if ( is_admin() || ! $q->is_main_query() ) {
            return;
        }
        if ( $q->is_singular() ) {
            return; // the direct link
        }

        $applies = $q->is_search()
            || $q->is_feed()
            || $q->is_post_type_archive( 'uc_event' )
            || $q->is_tax( array( SFAF_Series::TAXONOMY, 'uc_event_category', 'uc_organizer', 'uc_venue' ) );

        if ( ! $applies ) {
            return;
        }

        $meta = $q->get( 'meta_query' );
        $args = array( 'meta_query' => is_array( $meta ) ? $meta : array() );
        self::exclude( $args );
        $q->set( 'meta_query', $args['meta_query'] );
    }

    /**
     * Core's REST collection, /wp-json/wp/v2/uc_event.
     *
     * REGISTERED BECAUSE THE POST TYPE IS show_in_rest, which is what makes the
     * block editor work and also publishes a list of every event to anybody who
     * asks. It is not the main query and pre_get_posts above cannot see it.
     *
     * @param array           $args
     * @param WP_REST_Request $request
     * @return array
     */
    public static function hide_from_rest( $args, $request ) {
        self::exclude( $args );
        return $args;
    }

    /**
     * Core's XML sitemap.
     *
     * @param array  $args
     * @param string $post_type
     * @return array
     */
    public static function hide_from_core_sitemap( $args, $post_type ) {
        if ( 'uc_event' !== $post_type ) {
            return $args;
        }
        self::exclude( $args );
        return $args;
    }

    /**
     * Yoast's XML sitemap.
     *
     * THE SECOND OF TWO MECHANISMS, and it exists because the first one depends
     * on a write. set() stamps Yoast's own noindex meta, and Yoast leaves a
     * noindexed post out of its sitemap without being asked. This filter covers
     * the case that write did not happen: an event made private by something
     * other than set(), or a Yoast version that changes its mind about the
     * meta. Neither mechanism can be verified from a development machine with
     * no Yoast installed, which is why there are two.
     *
     * @param int[] $excluded
     * @return int[]
     */
    public static function yoast_excluded_ids( $excluded ) {
        $excluded = is_array( $excluded ) ? $excluded : array();
        return array_values( array_unique( array_merge( $excluded, self::private_ids() ) ) );
    }

    /**
     * noindex, nofollow on a private event page.
     *
     * BOTH, NOT JUST noindex. nofollow stops a crawler that reached the page
     * some other way, a forwarded link in a public message board, from walking
     * out of it into anything else and recording the relationship.
     *
     * @param array $robots
     * @return array
     */
    public static function robots( $robots ) {
        if ( ! is_singular( 'uc_event' ) ) {
            return $robots;
        }
        if ( ! self::is_private( get_queried_object_id() ) ) {
            return $robots;
        }
        $robots['noindex']  = true;
        $robots['nofollow'] = true;
        unset( $robots['index'], $robots['follow'], $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
        return $robots;
    }
}
