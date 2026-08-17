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
 * WORDPRESS KEEPS OLD SLUGS ALIVE, AND THAT CUTS BOTH WAYS.
 * ---------------------------------------------------------------------------
 * Core hooks wp_check_for_changed_slugs() to post_updated: when a published,
 * non-hierarchical post's slug changes, the previous slug is stored as
 * _wp_old_slug meta, and wp_old_slug_redirect() then 301s any 404 matching one
 * of those to the post's current address. uc_event qualifies on every count.
 *
 * The SAME mechanism gives the right answer in one direction and a disclosure
 * in the other, which is why neither can be left to it:
 *
 *   PRIVATE TO PUBLIC. The token is retained and redirects to the restored
 *   readable address, so every link already sent to a donor keeps working.
 *   That is exactly what is wanted and it is left alone.
 *
 *   PUBLIC TO PRIVATE. The readable address is retained and redirects to the
 *   TOKEN. /events/donor-reception did not die: it kept resolving and it handed
 *   the secret address to anybody who tried it, in a Location header, as a 301
 *   that browsers and proxies cache. Confirmed on the live site before this was
 *   written, in both directions.
 *
 * So the retained slugs are deleted in randomize_slug(), not in set(). Every
 * caller of randomize_slug() is by definition making an address unguessable, so
 * keeping the previous one contradicts that everywhere, and putting the delete
 * there also covers the occurrence path, which set() never touches. Clearing it
 * in set() would run on the way back to public too and destroy the working
 * links, which is the half that behaves correctly.
 *
 * block_old_slug_redirect() is the second, independent mechanism, for a row
 * added by a path the delete does not reach. Same belt-to-the-braces reasoning
 * as the two Yoast sitemap mechanisms below.
 *
 * EACH OCCURRENCE OF A PRIVATE SERIES GETS ITS OWN TOKEN, and that is not
 * decoration. Occurrence slugs are normally {seed-slug}-{date}, so one token
 * shared across a series would mean that being sent one date hands you every
 * other date by editing the URL. Independent tokens make one forwarded link
 * exactly one forwarded link.
 *
 * THAT GUARANTEE WAS BEING DEFEATED BY THE SAME CORE MECHANISM. An occurrence
 * used to be INSERTED at {seed-slug}-{date} and randomized immediately after,
 * so for a private seed the predictable address was retained as an old slug and
 * anybody holding one date's link could walk to every other date. The slug is
 * now decided BEFORE the insert, by occurrence_slug(), so no predictable
 * address is ever the post's name and there is nothing for core to retain.
 *
 * PRIVACY IS A SCOPED FIELD, LIKE EVERY OTHER FIELD ON THE EDITOR. The scope
 * modal already asks "this event" or "all upcoming occurrences" and every other
 * field respects the answer; privacy used to touch only the row it was ticked
 * on while its own label promised it hid the event everywhere. It travels with
 * the group now. See SFAF_Portal::apply_to_group().
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

        /*
         * Core's old-slug redirect, refused for a private event. This is the
         * mechanism that made a private event reachable at its old public
         * address; see the file header. randomize_slug() deletes the rows, and
         * this refuses the redirect whatever rows exist.
         */
        add_filter( 'old_slug_redirect_post_id', array( __CLASS__, 'block_old_slug_redirect' ) );
    }

    /** WordPress's own record of a slug a post used to answer on. */
    const OLD_SLUG_META = '_wp_old_slug';

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

        /*
         * THE TOKEN IS DELIBERATELY LEFT FOR CORE TO RETAIN. Restoring the
         * readable slug here changes the slug on a published post, so
         * wp_check_for_changed_slugs() records the token as an old slug and
         * every link already sent to a donor keeps working, redirecting to the
         * readable address. Nothing is cleared on this branch.
         */
        $prev = (string) get_post_meta( $post_id, self::PREV_SLUG_META, true );
        if ( '' === $prev ) {
            // No remembered address: an occurrence generated before this
            // release, or an event made private by something other than set().
            // A public event should not be left at a token, so one is derived.
            $prev = self::derived_public_slug( $post );
        }
        if ( '' !== $prev ) {
            wp_update_post( array( 'ID' => $post_id, 'post_name' => $prev ) );
            delete_post_meta( $post_id, self::PREV_SLUG_META );
        }
        return true;
    }

    /**
     * The slug this event would carry if it were public.
     *
     * For a public event that is simply its slug. For a private one it is the
     * address remembered when it went private, because the current slug is a
     * token and carries no words. Falls back to the title.
     *
     * USED WHEN GENERATING OCCURRENCES, which is why it must never return the
     * token: an occurrence named from a seed's token is an occurrence somebody
     * holding the seed's link can guess.
     *
     * @param int|WP_Post $post
     * @return string
     */
    public static function readable_base( $post ) {
        $post = is_object( $post ) ? $post : get_post( (int) $post );
        if ( ! $post ) {
            return '';
        }

        if ( self::is_private( $post->ID ) ) {
            $prev = (string) get_post_meta( $post->ID, self::PREV_SLUG_META, true );
            return ( '' !== $prev ) ? $prev : sanitize_title( $post->post_title );
        }

        return ( '' !== (string) $post->post_name )
            ? (string) $post->post_name
            : sanitize_title( $post->post_title );
    }

    /**
     * An address for an event going public that never recorded one.
     *
     * The date is appended only for a member of a recurrence group, because
     * that is the one case where the title alone collides: twelve occurrences
     * share a title and would otherwise become donor-reception-2, -3, -4, which
     * is a worse address than the date it actually is. A standalone event keeps
     * its title and lets wp_unique_post_slug() settle any clash.
     *
     * @param WP_Post $post
     * @return string
     */
    private static function derived_public_slug( $post ) {
        $base = sanitize_title( $post->post_title );
        if ( '' === $base ) {
            return '';
        }

        $in_group = class_exists( 'SFAF_Recurrence' )
            && '' !== (string) SFAF_Recurrence::group_of( $post->ID );
        if ( ! $in_group ) {
            return $base;
        }

        $date = (string) get_post_meta( $post->ID, '_uc_event_date', true );
        return ( '' !== $date ) ? $base . '-' . $date : $base;
    }

    /**
     * What slug a new occurrence should be CREATED with, and what to remember.
     *
     * THE DECISION HAPPENS BEFORE THE INSERT, AND THAT IS THE WHOLE POINT. An
     * occurrence of a private seed used to be inserted at {seed-slug}-{date}
     * and randomized a moment later, which left the predictable address behind
     * as an old slug that core would redirect. Deciding here means the post is
     * never named anything a person could guess, so there is no old slug for
     * core to retain and nothing to clean up afterwards.
     *
     * prev_slug is the readable address this date WOULD have had, so making one
     * occurrence public again lands on words rather than staying a token
     * forever.
     *
     * @param int    $seed_id
     * @param string $readable_base The public-facing base, from readable_base().
     * @param string $date          Y-m-d.
     * @return array{post_name:string,prev_slug:string,private:bool}
     */
    public static function occurrence_slug( $seed_id, $readable_base, $date ) {
        $base = trim( (string) $readable_base );
        if ( '' === $base ) {
            $base = 'event';
        }
        $dated = $base . '-' . $date;

        if ( ! self::is_private( (int) $seed_id ) ) {
            return array( 'post_name' => $dated, 'prev_slug' => '', 'private' => false );
        }

        return array( 'post_name' => self::new_slug(), 'prev_slug' => $dated, 'private' => true );
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
        $post_id = (int) $post_id;

        wp_update_post( array(
            'ID'        => $post_id,
            'post_name' => self::new_slug(),
        ) );

        /*
         * THE OLD ADDRESS DIES HERE, AND THIS IS THE LINE THE FEATURE TURNED
         * OUT TO DEPEND ON.
         *
         * The wp_update_post() above just changed the slug on a published post,
         * so core has this moment recorded the PREVIOUS one as _wp_old_slug and
         * would 301 anybody requesting it straight to the token. Every earlier
         * one is deleted with it: a token from a previous private spell would
         * redirect to the new token just as readily.
         *
         * AFTER the update, not before, because the update is what creates the
         * row. Deleting first would leave the readable address alive.
         */
        delete_post_meta( $post_id, self::OLD_SLUG_META );
    }

    /**
     * Refuse core's old-slug redirect when it would reveal a private event.
     *
     * THE SECOND MECHANISM, AND IT DOES NOT DEPEND ON A WRITE. randomize_slug()
     * deletes the rows, and this holds even if one exists anyway: an event made
     * private by something other than set(), a slug edited by hand in the
     * WordPress editor, a row restored from a backup. Returning 0 leaves the
     * request as the 404 it already is.
     *
     * SCOPED TO uc_event ON PURPOSE. This filter is global, and every page and
     * post on the site relies on that redirect working.
     *
     * @param int $post_id The post core resolved the old slug to.
     * @return int The same id, or 0 to refuse.
     */
    public static function block_old_slug_redirect( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id || 'uc_event' !== get_post_type( $post_id ) ) {
            return $post_id;
        }
        return self::is_private( $post_id ) ? 0 : $post_id;
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
