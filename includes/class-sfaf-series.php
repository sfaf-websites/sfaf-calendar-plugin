<?php
/**
 * A SERIES IS A CONTAINER, NOT AN EVENT.
 *
 * Until 3.0.0 the series was a uc_event post that was simultaneously the
 * template every occurrence was generated from AND its own first occurrence.
 * That single fact was the root of an entire family of problems, and none of
 * them were bugs in the ordinary sense — they were the data model behaving
 * exactly as built:
 *
 *   - series appeared in the Events list, because a series WAS an event;
 *   - deleting the series parent orphaned every other occurrence, because they
 *     pointed at a post that no longer existed;
 *   - promote_to_parent() existed to move the template forward without
 *     rewriting history;
 *   - cancelling one week had to be recorded on the parent as a DATE, because
 *     a flag on the occurrence died with the post;
 *   - FAQs lived in three meta keys with an override flag deciding which won.
 *
 * A TERM CANNOT BE AN EVENT. That is the whole argument for this file. Moving
 * the series to a taxonomy removes the class of problem structurally rather
 * than by remembering to exclude series from every query that touches events —
 * which is what the old code did, in a dozen places, and got wrong in some of
 * them. A term has no date, is never returned by a uc_event query, and cannot
 * be searched for as an event, because it is not one.
 *
 * A SERIES IS NOT A RECURRENCE PATTERN. The same group may run an educational
 * session one week and a social the next, so a series holds DIFFERENT KINDS OF
 * EVENT and is never a target for bulk edits. That job belongs to the
 * recurrence group — see SFAF_Recurrence::GROUP_META, and the note there about
 * why the two groupings are deliberately separate things.
 *
 * A SERIES WITH NO EVENTS IS VALID. People can read what the series is about
 * and see that dates may be added. Nothing here treats an empty series as
 * broken or cleans it up.
 *
 * WHAT A SERIES CARRIES: name (the term name), description (the term's own
 * description field), an image, and a default FAQ set applied to events created
 * into it. All four are native term data or term meta; nothing about a series
 * needs a post.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Series {

    /** The taxonomy. Registered alongside category/organizer/venue. */
    const TAXONOMY = 'uc_series';

    /** Term meta: the series image, as an attachment or as a bare URL. */
    const META_IMAGE_ID  = '_sfaf_series_image_id';
    const META_IMAGE_URL = '_sfaf_series_image_url';

    /** Term meta: the FAQ set copied onto each event created into this series. */
    const META_FAQ_SET = '_sfaf_series_faq_set';

    /**
     * Term meta: the post ID of the uc_event that used to BE this series.
     *
     * THIS IS WHAT KEEPS EXISTING EMBED CODE WORKING. Every [sfaf_calendar]
     * shortcode and every embed snippet ever generated filters by series using
     * that post ID, and there is embed code on sfaf.org this release must not
     * require anybody to regenerate. resolve() below reads this first, so an
     * old snippet keeps resolving to the same series for as long as the term
     * exists. Only migrated series carry it; a series created from now on has
     * none and is addressed by its term ID.
     */
    const META_LEGACY_ID = '_sfaf_legacy_parent_id';

    /* =====================================================================
     * Registration
     * ================================================================== */

    /**
     * Register the taxonomy.
     *
     * Called directly rather than hooked to `init`, for the same reason
     * SFAF_Post_Types does it: sfaf_init() is already running on init, so a
     * further add_action( 'init', … ) would never fire.
     *
     * PUBLIC AND REWRITTEN, because the "Part of series" badge on a public
     * event page now links to the term archive. See sfaf_series_link().
     */
    public static function register_taxonomy() {
        register_taxonomy( self::TAXONOMY, 'uc_event', array(
            'labels' => array(
                'name'          => 'Series',
                'singular_name' => 'Series',
                'add_new_item'  => 'Add New Series',
                'search_items'  => 'Search Series',
                'not_found'     => 'No series found',
                'menu_name'     => 'Series',
            ),
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => false, // managed on the plugin's own Series screen
            'show_in_menu'      => false,
            'show_admin_column' => false,
            'rewrite'           => array( 'slug' => 'event-series' ),
            'show_in_rest'      => true,
        ) );
    }

    /* =====================================================================
     * Reading
     * ================================================================== */

    /**
     * Every series, name-ordered.
     *
     * hide_empty is FALSE on purpose: a series with no events yet is a valid,
     * useful thing — somebody has written what it is about and dates are
     * coming — and hiding it from the screen that manages series would make it
     * impossible to add those dates.
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
     * One series by term ID.
     *
     * @param int $term_id
     * @return WP_Term|null
     */
    public static function get( $term_id ) {
        $term = get_term( (int) $term_id, self::TAXONOMY );
        return ( $term && ! is_wp_error( $term ) ) ? $term : null;
    }

    /** Whether a term ID is a real series. */
    public static function exists( $term_id ) {
        return null !== self::get( $term_id );
    }

    /**
     * The series an event belongs to, or null.
     *
     * ONE SERIES PER EVENT. The taxonomy is flat and the editors assign a
     * single term, because "which programme is this part of" has one answer and
     * a multi-select would make the Series screen's counts ambiguous.
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
     * The series term ID for an event, or 0.
     *
     * @param int $post_id
     * @return int
     */
    public static function id_for_event( $post_id ) {
        $term = self::for_event( $post_id );
        return $term ? (int) $term->term_id : 0;
    }

    /**
     * The series name for an event, or ''.
     *
     * @param int $post_id
     * @return string
     */
    public static function name_for_event( $post_id ) {
        $term = self::for_event( $post_id );
        return $term ? $term->name : '';
    }

    /**
     * Resolve whatever a shortcode, an embed snippet or a URL asked for into a
     * series term ID.
     *
     * TWO NUMBERING SCHEMES, ONE ANSWER. Everything published before 3.0.0
     * addresses a series by the post ID of its old parent event; everything
     * from now on uses the term ID. Both arrive in the same `series="123"`
     * attribute and there is no way to tell them apart by looking, so the
     * legacy ID is tried FIRST: an existing snippet on sfaf.org has to keep
     * meaning what it has always meant, and a term ID that happens to collide
     * with somebody's old parent post ID is a far cheaper thing to be wrong
     * about than a live embed silently changing what it shows.
     *
     * @param int $id
     * @return int Series term ID, or 0 when nothing matches.
     */
    public static function resolve( $id ) {
        $id = (int) $id;
        if ( $id <= 0 ) {
            return 0;
        }

        // Memoized for the request. The list view, the month grid and the
        // filter bar all resolve the same number on one page load, and this is
        // a term-meta query rather than a cached lookup.
        static $cache = array();
        if ( isset( $cache[ $id ] ) ) {
            return $cache[ $id ];
        }

        $legacy = get_terms( array(
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'number'     => 1,
            'fields'     => 'ids',
            'meta_query' => array(
                array( 'key' => self::META_LEGACY_ID, 'value' => (string) $id ),
            ),
        ) );
        if ( ! is_wp_error( $legacy ) && ! empty( $legacy ) ) {
            $cache[ $id ] = (int) $legacy[0];
            return $cache[ $id ];
        }

        $cache[ $id ] = self::exists( $id ) ? $id : 0;
        return $cache[ $id ];
    }

    /**
     * The series image URL: a chosen attachment first, then a pasted URL.
     *
     * @param int    $term_id
     * @param string $size
     * @return string
     */
    public static function image_url( $term_id, $size = 'large' ) {
        $term_id = (int) $term_id;
        if ( ! $term_id ) {
            return '';
        }
        $att = (int) get_term_meta( $term_id, self::META_IMAGE_ID, true );
        if ( $att ) {
            $src = wp_get_attachment_image_url( $att, $size );
            if ( $src ) {
                return $src;
            }
        }
        return (string) get_term_meta( $term_id, self::META_IMAGE_URL, true );
    }

    /**
     * The FAQ set this series applies to events created into it, or ''.
     *
     * @param int $term_id
     * @return string
     */
    public static function default_faq_set( $term_id ) {
        return (string) get_term_meta( (int) $term_id, self::META_FAQ_SET, true );
    }

    /**
     * The public address of a series.
     *
     * @param int $term_id
     * @return string
     */
    public static function url( $term_id ) {
        $link = get_term_link( (int) $term_id, self::TAXONOMY );
        return is_wp_error( $link ) ? '' : $link;
    }

    /* =====================================================================
     * Events in a series
     * ================================================================== */

    /**
     * The statuses a management screen counts. Published only is not enough:
     * a series being built up is full of drafts and the count would read 0.
     *
     * @return string[]
     */
    public static function editable_statuses() {
        return array( 'publish', 'pending', 'draft', 'future', 'private' );
    }

    /**
     * Event IDs in a series, earliest first.
     *
     * @param int   $term_id
     * @param array $args {
     *     @type bool     $upcoming Limit to today and later.
     *     @type string[] $status   Post statuses. Defaults to published only.
     *     @type int      $limit    Max rows. -1 for all.
     * }
     * @return int[]
     */
    public static function events( $term_id, $args = array() ) {
        $term_id = (int) $term_id;
        if ( ! $term_id ) {
            return array();
        }
        $args = wp_parse_args( $args, array(
            'upcoming' => false,
            'past'     => false,
            'status'   => array( 'publish' ),
            'limit'    => 200,
        ) );

        $query = array(
            'post_type'              => 'uc_event',
            'post_status'            => (array) $args['status'],
            'posts_per_page'         => (int) $args['limit'],
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            /*
             * A NAMED clause and orderby, never 'meta_key' alongside one. Same
             * reasoning as SFAF_Shortcodes::month_grid_data(): setting meta_key
             * and a meta_query clause on the same key makes WP_Query join
             * wp_postmeta twice, and the sort then refers to whichever join it
             * happens to pick.
             */
            'orderby'                => array( 'event_date' => 'ASC', 'ID' => 'ASC' ),
            'meta_query'             => array(
                'event_date' => array( 'key' => '_uc_event_date', 'compare' => 'EXISTS' ),
            ),
            'tax_query'              => array(
                array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => $term_id ),
            ),
        );

        if ( $args['upcoming'] ) {
            $query['meta_query']['upcoming'] = array(
                'key'     => '_uc_event_date',
                'value'   => current_time( 'Y-m-d' ),
                'compare' => '>=',
                'type'    => 'DATE',
            );
        }

        // The complement of 'upcoming', on the same boundary and the same
        // clock, so an event can never be in neither set or in both. Today
        // counts as upcoming: an event happening this afternoon is not
        // history.
        if ( ! empty( $args['past'] ) ) {
            $query['meta_query']['past'] = array(
                'key'     => '_uc_event_date',
                'value'   => current_time( 'Y-m-d' ),
                'compare' => '<',
                'type'    => 'DATE',
            );
        }

        $q = new WP_Query( $query );
        return array_map( 'intval', $q->posts );
    }

    /**
     * How many upcoming events a series holds, across every status a manager
     * can see. This is the number the Series screen shows.
     *
     * @param int $term_id
     * @return int
     */
    public static function upcoming_count( $term_id ) {
        return count( self::events( $term_id, array(
            'upcoming' => true,
            'status'   => self::editable_statuses(),
            'limit'    => -1,
        ) ) );
    }

    /**
     * The next date in a series, or '' when it holds nothing upcoming.
     *
     * @param int $term_id
     * @return string Y-m-d
     */
    public static function next_date( $term_id ) {
        $ids = self::events( $term_id, array(
            'upcoming' => true,
            'status'   => self::editable_statuses(),
            'limit'    => 1,
        ) );
        if ( empty( $ids ) ) {
            return '';
        }
        return (string) get_post_meta( $ids[0], '_uc_event_date', true );
    }

    /**
     * Total events in a series, any status, upcoming or past.
     *
     * @param int $term_id
     * @return int
     */
    public static function total_count( $term_id ) {
        return count( self::events( $term_id, array(
            'status' => self::editable_statuses(),
            'limit'  => -1,
        ) ) );
    }

    /* =====================================================================
     * Writing
     * ================================================================== */

    /**
     * Create a series.
     *
     * @param string $name
     * @param array  $args description, image_id, image_url, faq_set, legacy_id
     * @return int|WP_Error Term ID.
     */
    public static function create( $name, $args = array() ) {
        $name = trim( sanitize_text_field( $name ) );
        if ( '' === $name ) {
            return new WP_Error( 'sfaf_series_no_name', 'Give the series a name so it can be found later.' );
        }

        $result = wp_insert_term( $name, self::TAXONOMY, array(
            'description' => isset( $args['description'] ) ? wp_kses_post( $args['description'] ) : '',
        ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $term_id = (int) $result['term_id'];
        self::save_meta( $term_id, $args );
        return $term_id;
    }

    /**
     * Update a series' name, description and settings.
     *
     * @param int    $term_id
     * @param string $name
     * @param array  $args
     * @return true|WP_Error
     */
    public static function update( $term_id, $name, $args = array() ) {
        $term_id = (int) $term_id;
        if ( ! self::exists( $term_id ) ) {
            return new WP_Error( 'sfaf_series_missing', 'That series no longer exists.' );
        }
        $name = trim( sanitize_text_field( $name ) );
        if ( '' === $name ) {
            return new WP_Error( 'sfaf_series_no_name', 'Give the series a name so it can be found later.' );
        }

        $update = array( 'name' => $name );
        if ( isset( $args['description'] ) ) {
            $update['description'] = wp_kses_post( $args['description'] );
        }
        $result = wp_update_term( $term_id, self::TAXONOMY, $update );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        self::save_meta( $term_id, $args );
        return true;
    }

    /**
     * Store the settings a series carries.
     *
     * Absent keys are left alone; an empty value clears. That distinction
     * matters because create() and update() are called from two editors that
     * do not submit identical field sets.
     *
     * @param int   $term_id
     * @param array $args
     */
    private static function save_meta( $term_id, $args ) {
        $term_id = (int) $term_id;

        if ( array_key_exists( 'image_id', $args ) ) {
            $id = (int) $args['image_id'];
            if ( $id ) {
                update_term_meta( $term_id, self::META_IMAGE_ID, $id );
            } else {
                delete_term_meta( $term_id, self::META_IMAGE_ID );
            }
        }
        if ( array_key_exists( 'image_url', $args ) ) {
            $url = esc_url_raw( (string) $args['image_url'] );
            if ( '' !== $url ) {
                update_term_meta( $term_id, self::META_IMAGE_URL, $url );
            } else {
                delete_term_meta( $term_id, self::META_IMAGE_URL );
            }
        }
        if ( array_key_exists( 'faq_set', $args ) ) {
            $set = trim( (string) $args['faq_set'] );
            // Only a set that still exists, so deleting one cannot leave a
            // series pointing at nothing and silently applying nothing.
            if ( '' !== $set && SFAF_FAQ_Sets::get( $set ) ) {
                update_term_meta( $term_id, self::META_FAQ_SET, $set );
            } else {
                delete_term_meta( $term_id, self::META_FAQ_SET );
            }
        }
        if ( ! empty( $args['legacy_id'] ) ) {
            update_term_meta( $term_id, self::META_LEGACY_ID, (int) $args['legacy_id'] );
        }
    }

    /**
     * Choose (or clear) the FAQ set a series applies to new events.
     *
     * @param int    $term_id
     * @param string $set_id '' to clear.
     */
    public static function update_faq_set( $term_id, $set_id ) {
        self::save_meta( (int) $term_id, array( 'faq_set' => (string) $set_id ) );
    }

    /**
     * Put an event in a series, or take it out of every series when $term_id
     * is 0.
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
     * Give an event a series named after itself, and return the term.
     *
     * ONE EVENT, ONE SERIES, AND THE MANAGER NAMES IT ONCE.
     *
     * In SFAF's actual programming a repeating event has exactly one series and
     * it is called the same thing as the event. Asking for the event's title and
     * then asking again for a series name was asking the same question twice and
     * inviting the two answers to differ, which is how you end up with the
     * "Wednesday Support Group" series holding the "Support Group (Weds)" event.
     * So a repeat creates its series implicitly, from the title already typed.
     *
     * THE GENERAL CASE IS UNTOUCHED. A series can still hold different kinds of
     * event and can still be made by hand before any date exists; a programme
     * often is described before it is scheduled. That path is still there, it is
     * simply no longer the one a manager is pushed down to schedule a repeat.
     *
     * AN EXISTING SERIES OF THE SAME NAME IS REUSED rather than a second one
     * created beside it. Scheduling the autumn dates of a group that already ran
     * in the spring should land in the series that already exists.
     *
     * @param int    $post_id
     * @param string $name Defaults to the event's title.
     * @return int Term ID, or 0 when nothing could be made.
     */
    public static function create_for_event( $post_id, $name = '' ) {
        $post_id = (int) $post_id;
        if ( ! $post_id ) {
            return 0;
        }

        $name = trim( sanitize_text_field( '' !== $name ? $name : (string) get_the_title( $post_id ) ) );
        if ( '' === $name ) {
            return 0;
        }

        $existing = get_term_by( 'name', $name, self::TAXONOMY );
        if ( $existing && ! is_wp_error( $existing ) ) {
            $term_id = (int) $existing->term_id;
        } else {
            $created = self::create( $name );
            if ( is_wp_error( $created ) ) {
                return 0;
            }
            $term_id = (int) $created;
        }

        self::set_for_event( $post_id, $term_id );
        return $term_id;
    }

    /**
     * The recurrence group this series' events belong to, or ''.
     *
     * A SERIES IS THE EVENT'S SCHEDULE, so in practice every event in it shares
     * one group. This asks the events rather than storing a second copy of the
     * answer on the term: a group is a marker on the posts and nothing else, and
     * a stored copy could disagree with them.
     *
     * Upcoming events are asked first, because the schedule screen is about what
     * is still to come; a series whose only group is in the past falls back to
     * the whole set so the pattern can still be read.
     *
     * @param int $term_id
     * @return string
     */
    public static function recurrence_group( $term_id ) {
        foreach ( array( true, false ) as $upcoming_only ) {
            $ids = self::events( $term_id, array(
                'upcoming' => $upcoming_only,
                'status'   => self::editable_statuses(),
                'limit'    => -1,
            ) );
            foreach ( $ids as $id ) {
                $group = SFAF_Recurrence::group_of( $id );
                if ( '' !== $group ) {
                    return $group;
                }
            }
        }
        return '';
    }

    /**
     * Delete a series.
     *
     * THE EVENTS SURVIVE. Removing a series removes the grouping and nothing
     * else: every event that was in it stays exactly where it is, on the same
     * date, at the same URL, and simply stops saying it is part of anything.
     *
     * That is the whole point of the container model. Under the old one this
     * operation could not exist — the series was also an event, so deleting it
     * either deleted a real date off the calendar or orphaned everything that
     * pointed at it, which is why there was a screen asking a manager to choose
     * between those two bad outcomes. There is nothing left to ask.
     *
     * @param int $term_id
     * @return int Number of events released.
     */
    public static function delete( $term_id ) {
        $term_id = (int) $term_id;
        if ( ! self::exists( $term_id ) ) {
            return 0;
        }
        $count = count( self::events( $term_id, array(
            'status' => self::editable_statuses(),
            'limit'  => -1,
        ) ) );

        // wp_delete_term removes the relationships for us.
        wp_delete_term( $term_id, self::TAXONOMY );
        return $count;
    }

    /**
     * How many of a series' events have already happened.
     *
     * @param int $term_id
     * @return int
     */
    public static function past_count( $term_id ) {
        return count( self::events( $term_id, array(
            'past'   => true,
            'status' => self::editable_statuses(),
            'limit'  => -1,
        ) ) );
    }

    /**
     * Remove a series, having been told what to do with its events.
     *
     * THE PAST IS NEVER DELETED. That is the rule this method exists to hold.
     * "Remove the series and its events" removes the events that have not
     * happened yet and DETACHES the ones that have: they stay on the calendar
     * as ordinary standalone past events, on the same date, at the same
     * address. Past events are the record of what this organisation actually
     * did, and no single click may destroy that. Somebody who genuinely wants
     * a past event gone can delete that one event.
     *
     * UPCOMING EVENTS GO TO THE TRASH, not out of the database, because that
     * is exactly what deleting an event has always meant everywhere else in
     * this portal (see the trash_event action). Making this one button harder
     * to recover from than the ordinary delete button would be a surprise in
     * the wrong direction.
     *
     * REMOVING THE TERM REMOVES WHAT LIVES ON IT: the series description, its
     * image and its default FAQ set are term meta, so they go with it under
     * every mode. Nothing copies them anywhere first, and events that already
     * took a copy of the FAQ rows keep theirs, because applying a set copies
     * rather than links.
     *
     * RSVP ROWS ARE UNAFFECTED under every mode. They live in their own table
     * and outlive their events by design; see SFAF_RSVP::snapshot_event_title.
     *
     * @param int    $term_id
     * @param string $mode    'delete_events' or 'keep_events'.
     * @param int    $move_to Series to move the events to under 'keep_events';
     *                        0 leaves them unassigned. Ignored otherwise.
     * @return array|WP_Error {trashed:int, detached:int, moved:int, name:string}
     */
    public static function remove( $term_id, $mode, $move_to = 0 ) {
        $term_id = (int) $term_id;
        $term    = self::get( $term_id );
        if ( ! $term ) {
            return new WP_Error( 'sfaf_series_missing', 'That series no longer exists.' );
        }

        $mode    = ( 'delete_events' === $mode ) ? 'delete_events' : 'keep_events';
        $move_to = (int) $move_to;

        // A series cannot be moved into itself, and cannot be moved into one
        // that has gone. Re-derived here rather than trusted from the form.
        if ( $move_to === $term_id || ( $move_to && ! self::exists( $move_to ) ) ) {
            $move_to = 0;
        }

        $result = array(
            'name'     => $term->name,
            'trashed'  => 0,
            'detached' => 0,
            'moved'    => 0,
        );

        $all      = self::events( $term_id, array( 'status' => self::editable_statuses(), 'limit' => -1 ) );
        $upcoming = self::events( $term_id, array( 'upcoming' => true, 'status' => self::editable_statuses(), 'limit' => -1 ) );

        if ( 'delete_events' === $mode ) {
            foreach ( $upcoming as $event_id ) {
                wp_trash_post( $event_id );
                $result['trashed']++;
            }
            // Everything that was not upcoming is past, and is simply let go
            // of when the term is deleted below.
            $result['detached'] = count( $all ) - $result['trashed'];
        } elseif ( $move_to ) {
            foreach ( $all as $event_id ) {
                self::set_for_event( $event_id, $move_to );
                $result['moved']++;
            }
        } else {
            $result['detached'] = count( $all );
        }

        // wp_delete_term takes the relationships and the term meta with it.
        wp_delete_term( $term_id, self::TAXONOMY );

        return $result;
    }

    /**
     * Forget a deleted FAQ set on any series still naming it as its default.
     *
     * @param string $set_id
     * @return int Number of series cleared.
     */
    public static function clear_faq_set( $set_id ) {
        $terms = get_terms( array(
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'fields'     => 'ids',
            'meta_query' => array(
                array( 'key' => self::META_FAQ_SET, 'value' => (string) $set_id ),
            ),
        ) );
        if ( is_wp_error( $terms ) ) {
            return 0;
        }
        foreach ( $terms as $tid ) {
            delete_term_meta( (int) $tid, self::META_FAQ_SET );
        }
        return count( $terms );
    }
}
