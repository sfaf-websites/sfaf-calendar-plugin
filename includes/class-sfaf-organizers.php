<?php
/**
 * ORGANIZERS: WHO IS PUTTING AN EVENT ON.
 *
 * A thin wrapper over the `uc_organizer` taxonomy, which is unchanged. Nothing
 * here registers it, renames it, or touches its slug or its public archive:
 * embed blocks scoped by organizer carry the SLUG, they live on sites this
 * plugin does not control, and a slug that stops resolving empties somebody
 * else's calendar with nothing to say why. See save() on what that costs a
 * rename.
 *
 * WHAT AN ORGANIZER CARRIES
 * ---------------------------------------------------------------------------
 * Name, slug, description, and nothing else. There is no term meta on this
 * taxonomy at all, which makes it the simplest of the four: categories carry a
 * colour and an icon, venues carry four address parts, series carry an image
 * and a default FAQ set. An organizer is a name.
 *
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * Until 3.37.0 there was no way to add one from /caladmin. The event editor
 * offered a picker of existing organizers and nothing else, so a manager
 * setting up an event for a new programme had to leave the portal for
 * edit-tags.php, which 3.27.0 unlisted from the menu, and find their way back.
 * Same reasoning that moved venues in 3.13.0: this is event management, not
 * site configuration, and it belongs where the work happens.
 *
 * DELETION IS ALLOWED, WHICH IS THE CATEGORY RULE AND NOT THE VENUE RULE.
 * ---------------------------------------------------------------------------
 * The two precedents differ and the difference is real. A venue REFUSES
 * deletion while events are held there, because an event keeps no address of
 * its own: the term is the only record of where it happens, and deleting it
 * leaves an event with nowhere to be. A category ALLOWS it, because a deleted
 * category leaves every event exactly where and when it was, merely
 * uncategorised.
 *
 * An organizer is the second kind. Every fact about the event survives; what is
 * lost is a byline. So deletion is allowed, and the confirmation names the
 * count either way.
 *
 * WITH ONE THING THE CATEGORY CASE DOES NOT HAVE, said out loud rather than
 * discovered later: an embed block on another site can be scoped to an
 * organizer by slug, and this plugin cannot enumerate those blocks because they
 * are HTML on somebody else's pages. Deleting an organizer that a block filters
 * by empties that block silently. That is not a reason to refuse, since the
 * same is true of categories and refusing would make the screen useless for its
 * main purpose, but it IS a reason for the confirmation to say so.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Organizers {

    /** Unchanged, and not registered here. See class-sfaf-post-types.php. */
    const TAXONOMY = 'uc_organizer';

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /**
     * Every organizer, name-ordered, including ones holding no events.
     *
     * hide_empty is false on purpose: an organizer created for an event that
     * has not been written yet must appear in the picker, or the create-from
     * -the-editor path would produce a term nobody can select.
     *
     * @return WP_Term[]
     */
    public static function all() {
        $terms = get_terms( array(
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        return is_wp_error( $terms ) ? array() : $terms;
    }

    /** One organizer, or null. */
    public static function get( $term_id ) {
        $term = get_term( (int) $term_id, self::TAXONOMY );
        return ( $term && ! is_wp_error( $term ) ) ? $term : null;
    }

    /** Does this organizer exist? */
    public static function exists( $term_id ) {
        return null !== self::get( $term_id );
    }

    /**
     * How many events name this organizer.
     *
     * The term's own `count` is not used, and that is deliberate: WordPress
     * counts only published posts in it, so a manager who has three drafts
     * against an organizer would be told "0 events" and then be surprised by
     * what the deletion confirmation said. This counts everything a manager can
     * see, which is what the number on their screen has to mean.
     *
     * @param int $term_id
     * @return int
     */
    public static function event_count( $term_id ) {
        return count( self::events_using( $term_id ) );
    }

    /**
     * Event ids naming this organizer, in every status a manager works with.
     *
     * @param int $term_id
     * @param int $limit
     * @return int[]
     */
    public static function events_using( $term_id, $limit = 500 ) {
        $term_id = (int) $term_id;
        if ( ! $term_id ) {
            return array();
        }

        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'posts_per_page'         => (int) $limit,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'tax_query'              => array(
                array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => $term_id ),
            ),
        ) );

        return array_map( 'intval', $q->posts );
    }

    /** The organizer on an event, or null. One per event, as the editor allows. */
    public static function for_event( $post_id ) {
        $ids = wp_get_post_terms( (int) $post_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
        if ( is_wp_error( $ids ) || empty( $ids ) ) {
            return null;
        }
        return self::get( $ids[0] );
    }

    /* ---------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------- */

    /**
     * Create or update an organizer.
     *
     * THE SLUG IS NEVER CHANGED BY A RENAME, and this is the one rule on this
     * screen that is not obvious. WordPress will happily leave a slug alone
     * when a name changes, and that is what we want, because an embed block on
     * another site can be scoped `organizer="the-stonewall-project"` and that
     * string is the whole of the link between the two. Renaming the display
     * name is safe and is what somebody usually means; changing the slug is a
     * different act with a consequence this plugin cannot see, so it is offered
     * as its own field with its own warning rather than being derived from the
     * name on every save.
     *
     * @param int    $term_id 0 to create.
     * @param string $name
     * @param string $description
     * @param string $slug        '' leaves it alone (or lets WP derive it on create).
     * @return int|WP_Error The term id.
     */
    public static function save( $term_id, $name, $description = '', $slug = '' ) {
        $term_id     = (int) $term_id;
        $name        = trim( wp_strip_all_tags( (string) $name ) );
        $description = trim( (string) $description );
        $slug        = trim( (string) $slug );

        if ( '' === $name ) {
            return new WP_Error( 'sfaf_organizer_no_name', 'Give the organizer a name.' );
        }

        $args = array( 'description' => $description );
        if ( '' !== $slug ) {
            $args['slug'] = sanitize_title( $slug );
        }

        if ( $term_id ) {
            if ( ! self::exists( $term_id ) ) {
                return new WP_Error( 'sfaf_organizer_missing', 'That organizer no longer exists.' );
            }
            $args['name'] = $name;
            $done = wp_update_term( $term_id, self::TAXONOMY, $args );
        } else {
            /*
             * A NAME THAT ALREADY EXISTS RETURNS THE EXISTING TERM RATHER THAN
             * AN ERROR, and that matters most on the create-from-the-editor
             * path: somebody typing "The Stonewall Project" into the new-
             * organizer box on an event form, when that organizer already
             * exists, means "use that one". Failing their save over it would be
             * a poor trade, and creating a second term with the same name and a
             * -2 slug would be worse.
             */
            $existing = get_term_by( 'name', $name, self::TAXONOMY );
            if ( $existing && ! is_wp_error( $existing ) ) {
                return (int) $existing->term_id;
            }
            $done = wp_insert_term( $name, self::TAXONOMY, $args );
        }

        if ( is_wp_error( $done ) ) {
            return $done;
        }
        return (int) $done['term_id'];
    }

    /**
     * Delete an organizer. Allowed, with the count reported by the caller.
     *
     * See the note at the top of this file for why this allows what
     * SFAF_Venues::delete() refuses. In short: an event survives losing its
     * organizer intact, and does not survive losing its venue.
     *
     * @param int $term_id
     * @return int|WP_Error How many events lost their organizer.
     */
    public static function delete( $term_id ) {
        $term_id = (int) $term_id;
        if ( ! self::exists( $term_id ) ) {
            return new WP_Error( 'sfaf_organizer_missing', 'That organizer no longer exists.' );
        }

        $affected = count( self::events_using( $term_id ) );

        /*
         * wp_delete_term() removes the relationships as well as the term, so
         * every event that named it is simply left without one. Nothing else
         * has to be tidied: no event stores an organizer name of its own, and
         * the card, the event page and the schema all read the taxonomy at
         * render time and print nothing when it is empty.
         */
        $done = wp_delete_term( $term_id, self::TAXONOMY );
        if ( is_wp_error( $done ) ) {
            return $done;
        }

        return $affected;
    }
}
