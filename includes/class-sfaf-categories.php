<?php
/**
 * CATEGORIES ARE EVENT ORGANISATION, SO THEY ARE MANAGED IN /caladmin.
 *
 * Until 3.15.0 the only place to add or rename one was the WordPress admin,
 * under Events > Categories, which the people who actually run this calendar do
 * not open. That is the same argument that moved venues in 3.13.0 and series
 * before them: deciding what kinds of event exist is programming work, not site
 * configuration.
 *
 * WHAT WAS ACTUALLY BROKEN, AND IT WAS MORE THAN A MISSING SCREEN.
 *
 *   COLOUR was term meta with no form field anywhere. `_uc_category_color` has
 *   been read since 3.1.0 by the chips, the card accents and the placeholder,
 *   and the only thing that ever WROTE it was the sample-data seeder. So every
 *   category anybody created by hand was the default teal, permanently, with no
 *   way to change it short of the database.
 *
 *   ICON was not stored at all. sfaf_category_icon_key() mapped five exact
 *   category NAMES to icons and returned 'calendar' for everything else, so a
 *   sixth category could not have an icon and renaming one silently took its
 *   icon away.
 *
 *   THE PLACEHOLDER had a SECOND hard-coded name map, with its own colours that
 *   ignored the stored one entirely, and it named two icons ('people', 'hands')
 *   that are not in sfaf_icon_paths() and therefore never drew.
 *
 * All three now read from here. A name map that has to be edited in PHP every
 * time somebody invents a category is not a feature, it is a list of the
 * categories that existed when it was written.
 *
 * THE PALETTE IS CLOSED. save() puts every colour through
 * sfaf_sanitize_brand_color(), so an off-brand hex cannot be stored from this
 * screen and a category with no colour gets the documented default rather than
 * an empty string that renders as nothing.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Categories {

    /** The taxonomy, registered in SFAF_Post_Types alongside the others. */
    const TAXONOMY = 'uc_event_category';

    /** Term meta: the approved brand colour. Read since 3.1.0, written since 3.15.0. */
    const META_COLOR = '_uc_category_color';

    /** Term meta: which icon the placeholder and the tile draw. */
    const META_ICON = '_uc_category_icon';

    /* =====================================================================
     * Reading
     * ================================================================== */

    /**
     * Every category, name-ordered.
     *
     * hide_empty is FALSE: a category added today has no events yet and still
     * has to be pickable, or it could never get one.
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
     * One category, or null.
     *
     * @param int $term_id
     * @return WP_Term|null
     */
    public static function get( $term_id ) {
        $term = get_term( (int) $term_id, self::TAXONOMY );
        return ( $term && ! is_wp_error( $term ) ) ? $term : null;
    }

    /** Whether a term id is a real category. */
    public static function exists( $term_id ) {
        return null !== self::get( $term_id );
    }

    /**
     * The icons a category may use.
     *
     * A CURATED SUBSET of sfaf_icon_paths(), not all of it: the interface icons
     * (menu, arrow, pencil, x) and the social ones are not things an event
     * category is, and offering them would be offering a way to make the
     * calendar look broken. Every key here is checked against sfaf_icon_paths()
     * at the point of use, so a rename there cannot leave this pointing at
     * nothing.
     *
     * @return array<string,string> icon key => human label.
     */
    public static function icons() {
        /*
         * THE LIST GREW IN 3.75.0, and the reason was a collision
         * rather than a shortage. Espanol and Program Groups both drew
         * 'community', so two kinds of event were indistinguishable on every
         * placeholder they produced, and more categories are coming.
         *
         * NINE ARE NEW GLYPHS and four were already in sfaf_icon_paths() and
         * simply not offered here: a video camera, a ticket, a picture and a
         * house are all things an event can be about, and leaving them out was
         * an oversight rather than a decision.
         *
         * STILL A CURATED SUBSET. The interface icons (menu, arrow, pencil, x,
         * chevron, search, refresh, check, lock) and the social marks are not
         * things an event category IS, and offering them would be offering a
         * way to make the calendar look broken.
         *
         * Grouped by what somebody is looking for rather than alphabetically,
         * because a person picking an icon is thinking "what is this event",
         * not "what letter does it start with".
         */
        return array(
            // General
            'calendar'  => 'Calendar',
            'star'      => 'Star',
            'bell'      => 'Bell',
            'clock'     => 'Clock',
            'repeat'    => 'Repeating',
            // People and talking
            'users'     => 'People',
            'community' => 'Community',
            'speech'    => 'Conversation',
            'globe'     => 'Globe',
            'handshake' => 'Handshake',
            'hand'      => 'Hand',
            'heart'     => 'Heart',
            // Health and support
            'cross'     => 'Health',
            'shield'    => 'Prevention',
            'help'      => 'Question',
            // Things an event does
            'book'      => 'Learning',
            'meal'      => 'Food',
            'music'     => 'Music',
            'bike'      => 'Cycling',
            'bolt'      => 'Bolt',
            'palette'   => 'Arts',
            'flag'      => 'Campaign',
            'ticket'    => 'Ticketed',
            'video'     => 'Online',
            'image'     => 'Picture',
            // Where and how to reach it
            'venue'     => 'Building',
            'home'      => 'House',
            'pin'       => 'Location pin',
            'mail'      => 'Mail',
            'link'      => 'Link',
        );
    }

    /** The default icon when a category has none and its name is not a legacy one. */
    public static function default_icon() {
        return 'calendar';
    }

    /**
     * Which icon a category draws.
     *
     * THE STORED VALUE WINS, then the legacy name map, then the default. The
     * name map is kept only so the five categories that predate this screen
     * keep the icons they have always had; once one is saved here it carries
     * its own icon and the map stops mattering to it.
     *
     * @param int    $term_id
     * @param string $name Falls back to the term's own name when omitted.
     * @return string An icon key that exists in sfaf_icon_paths().
     */
    public static function icon( $term_id, $name = '' ) {
        $term_id = (int) $term_id;
        $stored  = $term_id ? (string) get_term_meta( $term_id, self::META_ICON, true ) : '';

        if ( '' === $stored ) {
            if ( '' === $name && $term_id ) {
                $term = self::get( $term_id );
                $name = $term ? $term->name : '';
            }
            $stored = sfaf_category_icon_key( $name );
        }

        // Never hand back a key the icon set does not have: that draws nothing
        // at all, which is how 'people' and 'hands' went unnoticed.
        $paths = sfaf_icon_paths();
        return isset( $paths[ $stored ] ) ? $stored : self::default_icon();
    }

    /**
     * Events currently in a category.
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

    /* =====================================================================
     * Writing
     * ================================================================== */

    /**
     * Create or rename a category and set its colour and icon.
     *
     * @param int    $term_id 0 to create.
     * @param string $name
     * @param string $color   An approved brand hex; anything else becomes the default.
     * @param string $icon    A key from icons(); anything else becomes the default.
     * @return int|WP_Error Term id.
     */
    public static function save( $term_id, $name, $color, $icon ) {
        $term_id = (int) $term_id;
        $name    = trim( sanitize_text_field( $name ) );

        if ( '' === $name ) {
            return new WP_Error( 'sfaf_category_no_name', 'Give the category a name so it can be found later.' );
        }

        if ( $term_id ) {
            if ( ! self::exists( $term_id ) ) {
                return new WP_Error( 'sfaf_category_missing', 'That category no longer exists.' );
            }
            $updated = wp_update_term( $term_id, self::TAXONOMY, array( 'name' => $name ) );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
        } else {
            // An existing category of the same name is reused rather than
            // duplicated. Two "Fundraising" terms with the events split between
            // them is exactly the mess this screen exists to prevent.
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

        /*
         * ALWAYS WRITTEN, NEVER LEFT EMPTY. sfaf_sanitize_brand_color() returns
         * the default for anything off-palette, so a category saved from this
         * screen always has a real colour and the readers never have to guess.
         * That is the difference between "no colour set" and "renders as
         * nothing", which is the state every hand-made category was in.
         */
        update_term_meta( $term_id, self::META_COLOR, sfaf_sanitize_brand_color( $color ) );

        $icons = self::icons();
        $icon  = (string) $icon;
        update_term_meta( $term_id, self::META_ICON, isset( $icons[ $icon ] ) ? $icon : self::default_icon() );

        return $term_id;
    }

    /**
     * Delete a category. Its events keep everything except the classification.
     *
     * NOT REFUSED WHILE IN USE, and that is a deliberate difference from
     * venues and teams rather than an oversight. Deleting a venue would leave
     * its events with nowhere to be, because they keep no address of their own;
     * deleting a team would silently change who gets notified. Deleting a
     * category leaves every event exactly where and when it was, simply
     * uncategorised, drawn in the default colour. That is a visible, reversible
     * state, not data loss, so it is a decision a manager is allowed to make
     * with the count in front of them.
     *
     * @param int $term_id
     * @return int|WP_Error How many events lost the category.
     */
    public static function delete( $term_id ) {
        $term_id = (int) $term_id;
        if ( ! self::exists( $term_id ) ) {
            return new WP_Error( 'sfaf_category_missing', 'That category no longer exists.' );
        }

        $count = count( self::events_using( $term_id, -1 ) );

        // wp_delete_term removes the relationships and the term meta for us.
        $done = wp_delete_term( $term_id, self::TAXONOMY );
        if ( is_wp_error( $done ) ) {
            return $done;
        }
        return $count;
    }
}
