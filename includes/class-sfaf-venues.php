<?php
/**
 * Venues: the places events happen, managed where events are managed.
 *
 * WHY THIS FILE EXISTS. uc_venue has been a taxonomy since the beginning, with
 * a WordPress admin screen and nothing else. That screen is in the wrong place
 * for two reasons: it is behind the WordPress admin, which the people who
 * actually schedule events do not use, and /caladmin offered no way to pick a
 * venue at all, so the Location field was free text every single time. Strut,
 * 470 Castro St got typed by hand for every event held there, differently each
 * time, and the map link was only as good as that typing.
 *
 * THE ADDRESS IS HELD ONCE, ON THE VENUE, AND EVENTS REFER TO IT.
 *
 * This is the whole design decision and it is deliberate. An event that names a
 * venue stores the TERM and nothing else: no address, no copy, no snapshot. The
 * address is term meta on the venue, read at display time by
 * sfaf_event_location(). So when a venue moves, or somebody corrects a typo in
 * a suite number, every event held there is correct immediately, including the
 * ones that already happened and the ones already published.
 *
 * The alternative was copying the address onto each event at save time. That is
 * easier to write and wrong in the way that matters: a corrected address would
 * fix the venue and none of the forty events pointing at it, and there would be
 * no way to tell an event whose address is deliberately different from one whose
 * address is simply out of date.
 *
 * FREE TEXT STILL WORKS, AND IS THE OTHER HALF OF THE SAME CHOICE. A one-off in
 * somebody's back garden is not a venue and should not become one. An event
 * therefore has EITHER a venue reference OR its own location text, never both:
 * choosing a venue clears the text, choosing "a different location" clears the
 * term. One source of truth per event, so nothing has to decide which wins.
 *
 * AN IMPORTED EVENT KEEPS ITS TEXT. The platform owns that field and writes it
 * on every fetch, so the picker is not offered there and _uc_location stays what
 * the source said it was.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Venues {

    /** The taxonomy, registered in SFAF_Post_Types alongside the others. */
    const TAXONOMY = 'uc_venue';

    /** Term meta: the street address, as one line, for display and for maps. */
    const META_ADDRESS = '_sfaf_venue_address';

    /* =====================================================================
     * Reading
     * ================================================================== */

    /**
     * Every venue, name-ordered.
     *
     * hide_empty is FALSE: a venue added today has no events yet and still has
     * to be pickable, or it could never get one.
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

    /**
     * One venue.
     *
     * @param int $term_id
     * @return WP_Term|null
     */
    public static function get( $term_id ) {
        $term = get_term( (int) $term_id, self::TAXONOMY );
        return ( $term && ! is_wp_error( $term ) ) ? $term : null;
    }

    /** Whether a term id is a real venue. */
    public static function exists( $term_id ) {
        return null !== self::get( $term_id );
    }

    /**
     * A venue's address, or ''.
     *
     * @param int $term_id
     * @return string
     */
    public static function address( $term_id ) {
        return (string) get_term_meta( (int) $term_id, self::META_ADDRESS, true );
    }

    /**
     * The venue an event is held at, or null.
     *
     * ONE VENUE PER EVENT. An event happens in one place; a multi-select here
     * would make "where is this" a question with several answers and the map
     * link impossible to build.
     *
     * @param int $post_id
     * @return WP_Term|null
     */
    public static function for_event( $post_id ) {
        $terms = get_the_terms( (int) $post_id, self::TAXONOMY );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return null;
        }
        return reset( $terms );
    }

    /**
     * The venue term id for an event, or 0.
     *
     * @param int $post_id
     * @return int
     */
    public static function id_for_event( $post_id ) {
        $term = self::for_event( $post_id );
        return $term ? (int) $term->term_id : 0;
    }

    /**
     * What to print as an event's location when it names a venue.
     *
     * The venue's name and its address, so a card says "Strut, 470 Castro St"
     * rather than making somebody guess which Strut. A venue with no address
     * yet is still worth naming.
     *
     * @param int $term_id
     * @return string
     */
    public static function display( $term_id ) {
        $term = self::get( $term_id );
        if ( ! $term ) {
            return '';
        }
        $address = self::address( $term_id );
        return ( '' !== $address ) ? $term->name . ', ' . $address : $term->name;
    }

    /* =====================================================================
     * Writing
     * ================================================================== */

    /**
     * Create or rename a venue and set its address.
     *
     * @param int    $term_id 0 to create.
     * @param string $name
     * @param string $address
     * @return int|WP_Error Term id.
     */
    public static function save( $term_id, $name, $address ) {
        $term_id = (int) $term_id;
        $name    = trim( sanitize_text_field( $name ) );
        $address = trim( sanitize_text_field( $address ) );

        if ( '' === $name ) {
            return new WP_Error( 'sfaf_venue_no_name', 'Give the venue a name so it can be found later.' );
        }

        if ( $term_id ) {
            if ( ! self::exists( $term_id ) ) {
                return new WP_Error( 'sfaf_venue_missing', 'That venue no longer exists.' );
            }
            $updated = wp_update_term( $term_id, self::TAXONOMY, array( 'name' => $name ) );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
        } else {
            // An existing venue of the same name is reused rather than
            // duplicated. Two "Strut" entries with one address between them is
            // exactly the mess this screen exists to prevent.
            $existing = get_term_by( 'name', $name, self::TAXONOMY );
            if ( $existing && ! is_wp_error( $existing ) ) {
                $term_id = (int) $existing->term_id;
            } else {
                $created = wp_insert_term( $name, self::TAXONOMY );
                if ( is_wp_error( $created ) ) {
                    return $created;
                }
                $term_id = (int) $created['term_id'];
            }
        }

        if ( '' !== $address ) {
            update_term_meta( $term_id, self::META_ADDRESS, $address );
        } else {
            delete_term_meta( $term_id, self::META_ADDRESS );
        }

        return $term_id;
    }

    /**
     * Put an event at a venue, or take it out of every venue when 0.
     *
     * @param int $post_id
     * @param int $term_id
     */
    public static function set_for_event( $post_id, $term_id ) {
        $term_id = (int) $term_id;
        wp_set_object_terms(
            (int) $post_id,
            ( $term_id && self::exists( $term_id ) ) ? array( $term_id ) : array(),
            self::TAXONOMY,
            false
        );
    }

    /**
     * Events held at a venue.
     *
     * @param int $term_id
     * @param int $limit
     * @return int[]
     */
    public static function events_using( $term_id, $limit = 200 ) {
        $term_id = (int) $term_id;
        if ( ! $term_id ) {
            return array();
        }

        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => SFAF_Series::editable_statuses(),
            'posts_per_page'         => (int) $limit,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => array(
                array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => $term_id ),
            ),
        ) );

        return array_map( 'intval', $q->posts );
    }

    /**
     * Delete a venue, refusing while events are held there.
     *
     * REFUSED, NOT WARNED. Deleting the term would leave those events with no
     * location at all: they hold a reference and no text of their own, by
     * design, so the address does not survive the venue. An event whose
     * location silently becomes blank looks exactly like one nobody filled in.
     * Same reasoning as SFAF_Teams::delete(), and the same shape of answer:
     * the events are named so the manager knows what to change.
     *
     * @param int $term_id
     * @return true|WP_Error
     */
    public static function delete( $term_id ) {
        $term_id = (int) $term_id;
        if ( ! self::exists( $term_id ) ) {
            return new WP_Error( 'sfaf_venue_missing', 'That venue no longer exists.' );
        }

        $in_use = self::events_using( $term_id );
        if ( ! empty( $in_use ) ) {
            $rows = array();
            foreach ( $in_use as $id ) {
                $rows[] = array(
                    'id'     => (int) $id,
                    'title'  => get_the_title( $id ) ? get_the_title( $id ) : '(untitled)',
                    'status' => get_post_status( $id ),
                );
            }
            return new WP_Error(
                'sfaf_venue_in_use',
                sprintf(
                    'This venue is where %d %s held, and those events keep no address of their own, so deleting it would leave them with no location. Move them somewhere else first.',
                    count( $in_use ),
                    ( 1 === count( $in_use ) ) ? 'event is' : 'events are'
                ),
                array( 'events' => $rows )
            );
        }

        wp_delete_term( $term_id, self::TAXONOMY );
        return true;
    }
}
