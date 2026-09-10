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
     *
     * THE TERM SCREEN IS A FALLBACK, AND ONLY THAT. 3.27.0 left it with
     * show_ui => false, which meant there was no route to a series outside
     * /caladmin at all: not a screen, not a URL, nothing. Every other thing an
     * administrator might need to reach with the portal down has a way in,
     * which is the whole argument for keeping Calendar Users in the WordPress
     * admin, and series was the one gap. 3.27.1 opens it.
     *
     * THREE FLAGS, AND EACH IS DOING SOMETHING DIFFERENT:
     *
     *   show_ui => true          the term screen exists again.
     *   show_in_menu => false    it is not in the menu. Same treatment as
     *                            Categories and Organizers: reachable at a URL
     *                            by somebody who needs it, not an invitation to
     *                            work there. The URL is in the readme.
     *   meta_box_cb => false     NO SECOND PICKER ON THE EVENT EDITOR. show_ui
     *                            would otherwise add WordPress's own series box
     *                            beside the one SFAF_Post_Types already draws,
     *                            and two controls over one relationship on one
     *                            screen is exactly the duplication 3.27.0 spent
     *                            a release removing. The existing select stays;
     *                            this suppresses the automatic one.
     *
     * WHAT THIS SCREEN CANNOT DO, and it is most of what a series is: the
     * image, the default FAQ set, the schedule and its pattern, and the events
     * in the series. A term screen edits a name, a slug and a description.
     * render_fallback_notice() says so at the top of it rather than leaving
     * somebody to conclude that a series is those three fields.
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
            'show_ui'           => true,
            'show_in_menu'      => false,
            'meta_box_cb'       => false,
            'show_admin_column' => false,
            'rewrite'           => array( 'slug' => 'event-series' ),
            'show_in_rest'      => true,
        ) );

        add_action( 'uc_series_pre_add_form', array( __CLASS__, 'render_fallback_notice' ) );
        add_action( 'uc_series_pre_edit_form', array( __CLASS__, 'render_fallback_notice' ) );
    }

    /**
     * Say what this screen is, at the top of it.
     *
     * A BARE TERM SCREEN IS MISLEADING BY OMISSION HERE, which is the reason
     * this exists. It shows a name, a slug and a description and nothing else,
     * so it reads as though that is what a series is. A series also carries an
     * image, a default FAQ set applied to events created into it, a schedule
     * with a repeat pattern, and the events themselves. None of those appear
     * here, and none of them are lost by editing here either: the fields this
     * screen does not draw are simply not touched by it.
     *
     * The one thing it can do that matters in an emergency is rename a series,
     * fix a slug, and delete one. That is what it is for.
     *
     * NOT A WARNING, AND NOT AN APOLOGY. It says what the screen does and where
     * the rest is, which is what a person arriving here needs.
     */
    public static function render_fallback_notice() {
        printf(
            '<div class="notice notice-info inline"><p><strong>This is the fallback screen.</strong> '
            . 'It edits a series name, slug and description. The image, the default FAQ set, the schedule and '
            . 'the events in the series are on <a href="%s">Series &amp; Categories</a> in the calendar portal, '
            . 'which is where series are normally managed.</p></div>',
            esc_url( SFAF_Portal::link( 'series' ) )
        );
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
     * THE LEGACY BRANCH BELOW FINDS NOTHING TODAY, AND IS KEPT ON PURPOSE. DO
     * NOT DELETE IT, OR META_LEGACY_ID, IN A CLEANUP.
     *
     * Only the 3.0.0 migration ever wrote that term meta, and it was never run
     * before being removed in 3.27.0, so no term on this install carries one and
     * the get_terms() call below always comes back empty. That is the correct
     * behaviour rather than a fault: resolve() then falls through to the term ID
     * and answers correctly.
     *
     * It stays because it is a CONTRACT with markup this plugin does not
     * control. Every [sfaf_calendar] shortcode and embed snippet published
     * before 3.0.0 addresses a series by its old parent post ID, that code is on
     * pages nobody is going to regenerate, and this branch is the only thing
     * that would make those snippets resolve if such a database ever arrives.
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
        /*
         * public_only DEFAULTS TO FALSE, AND THAT IS THE SAFE DIRECTION HERE.
         *
         * Most callers of this are /caladmin screens and the recurrence tools,
         * which must see every event in a series including the private ones:
         * hiding a private event from the people running it is the failure this
         * feature must not have. So the default is "everything", and the public
         * surfaces opt in.
         *
         * There are exactly two of those and both pass it: sfaf_get_series_events(),
         * which the event page's "Upcoming in this series" list and the series
         * term archive both go through. Anything else public that starts
         * listing a series has to say so, which is a visible line in a diff
         * rather than a silent inheritance.
         */
        $args = wp_parse_args( $args, array(
            'upcoming'    => false,
            'past'        => false,
            'status'      => array( 'publish' ),
            'limit'       => 200,
            'public_only' => false,
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

        // A private event stays in its series for authoring, and comes out of
        // every public listing of that series. One rule on the event, applied
        // wherever the series is shown to a visitor.
        if ( ! empty( $args['public_only'] ) ) {
            SFAF_Privacy::exclude( $query );
            // And cancelled events the organizer chose to hide. A different
            // question about the same query, applied at the same place so the
            // two cannot be excluded from one builder and not the other.
            SFAF_Cancellation::exclude( $query );
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

    /* total_count() removed in 3.28.0: never called. Every screen that counts a
       series counts what it is already listing, or asks upcoming_count(). */

    /* =====================================================================
     * Writing
     * ================================================================== */

    /**
     * The event "use this event's details on another date" copies FROM.
     *
     * ONE SEED, COMPUTED ONCE (3.73.0), AND IT WAS TWO.
     *
     * The schedule screen worked it out one way and the handler behind the
     * button worked it out another. The screen preferred the next event IN
     * THE RECURRENCE GROUP, because a series can hold several groups and a
     * pattern read off the wrong one describes a schedule that does not
     * exist. The handler asked only for the next event in the SERIES.
     *
     * ON A SERIES HOLDING ONE GROUP THEY AGREE AND ALWAYS DID. On one
     * holding several they can disagree, and then the placeholder shows
     * event A's title while the button copies event B. Four series hold
     * several distinct events, so this is not hypothetical: PROP holds
     * four, Coffee Social two, Mobile Health Sites two, and the Strut
     * community events three.
     *
     * THE GROUP-AWARE ANSWER IS THE RIGHT ONE and is the one kept. The
     * fallback, a series with no recurrence group at all, is a plain
     * container of hand-made dates whose soonest event is exactly what a
     * new date should be copied from.
     *
     * IT TAKES THE LISTS RATHER THAN RE-QUERYING, because the screen has
     * them already and a second query is a second chance to disagree about
     * which events are even in the series. The handler has no lists, so it
     * asks for them by the same arguments the screen used: see
     * seed_for_series().
     *
     * @param string $group    The recurrence group, or empty.
     * @param int[]  $upcoming Upcoming event ids, soonest first.
     * @param int[]  $past     Past event ids, most recent first.
     * @return int 0 when the series holds nothing.
     */
    public static function seed_from_lists( $group, $upcoming, $past ) {
        $group    = (string) $group;
        $upcoming = array_values( array_map( 'intval', (array) $upcoming ) );
        $past     = array_values( array_map( 'intval', (array) $past ) );

        if ( '' !== $group ) {
            // Upcoming first, then the most recent past one, which is the
            // order the two lists are already in.
            foreach ( array( $upcoming, $past ) as $list ) {
                foreach ( $list as $eid ) {
                    if ( SFAF_Recurrence::group_of( $eid ) === $group ) {
                        return (int) $eid;
                    }
                }
            }
        }

        if ( ! empty( $upcoming ) ) {
            return (int) $upcoming[0];
        }
        return ! empty( $past ) ? (int) $past[0] : 0;
    }

    /**
     * The same seed, for a caller with no lists in hand.
     *
     * THE HANDLER USES THIS AND THE SCREEN DOES NOT, which is the whole
     * shape of the fix: the rule lives in seed_from_lists() and is asked
     * once, and this is only the two queries that put the same lists in
     * front of it. A handler that recomputed the RULE is what went wrong.
     *
     * @param int $term_id
     * @return int
     */
    public static function seed_for_series( $term_id ) {
        $term_id = (int) $term_id;
        $upcoming = self::events( $term_id, array(
            'upcoming' => true,
            'status'   => self::editable_statuses(),
            'limit'    => -1,
        ) );
        $all = self::events( $term_id, array(
            'status' => self::editable_statuses(),
            'limit'  => -1,
        ) );

        /* The past ones, most recent first, which is the order the screen
         * hands over. events() returns soonest first, so what is not
         * upcoming is reversed. */
        $past = array_values( array_reverse( array_diff( $all, $upcoming ) ) );

        return self::seed_from_lists(
            self::recurrence_group( $term_id ),
            $upcoming,
            $past
        );
    }

    /**
     * The drafts in this series that a bulk publish may touch, and what it
     * would leave behind.
     *
     * WHY THIS EXISTS. The import creates a series' worth of drafts at once, and
     * approving 270 of them one at a time is not a thing anybody is going to do.
     * WordPress's own Events list in wp-admin can already bulk-publish, but it
     * knows nothing about a series, nothing about upcoming versus past, and
     * nothing about which rows are somebody else's decision to make. This is the
     * same action with those three things known.
     *
     * UPCOMING ONLY, AND AN EVENT WITH NO DATE IS NOT UPCOMING. Publishing a
     * past date puts a session that has already happened on the public calendar,
     * which is the one outcome nobody wants from a button labelled "publish".
     * The import also leaves events with no date at all, for series whose
     * schedule Mark fills in later; those are not upcoming either, and
     * publishing an event with no date would put it on no calendar while
     * removing the draft badge that says it still needs one.
     *
     * FOUR KINDS OF ROW ARE NEVER TOUCHED, and three of them are somebody else's
     * decision rather than a state:
     *
     *   - anything that is not a draft. A published event is already published,
     *     and a `pending` row is a submission awaiting review, which is the
     *     reviewer's call and not a bulk one.
     *   - anything carrying source provenance. A fetch owns those.
     *   - an import parked as a draft because it vanished at its source.
     *     Publishing it would put back an event the source has dropped.
     *   - a submission. It reaches the calendar by being approved.
     *
     * The queue statuses need no rule here and get none: `uc_imported` and
     * `uc_dismissed` are not in editable_statuses(), so this screen has never
     * been able to see them.
     *
     * THE IMPORT'S OWN EVENTS ARE ORDINARY DRAFTS AND ARE MEANT TO QUALIFY.
     * They were created by a script rather than through the event editor, so it
     * is worth saying what they carry: a `draft` status, a `_uc_event_date`, and
     * this series' term. That is the whole of what this asks for. They carry no
     * source provenance and no submission marker, so nothing here excludes
     * them, and the rule is written in terms of what an event IS rather than
     * how it was made so that stays true.
     *
     * `blocked` CARRIES THE ROWS, `skipped` CARRIES THE COUNTS, AND BOTH ARE
     * WANTED (3.72.0). The counts are what the line above the button says, and
     * they were the whole of what this returned while the button was all or
     * nothing. Now that the screen lists every row with a tick, it has to be
     * able to draw the ones with no tick and say why each one has none, and a
     * count cannot do that. Neither is derived from the other at the call site,
     * because deriving the counts by grouping `blocked` in the renderer would
     * be a second place that decides what a skip reason is.
     *
     * @param int $term_id
     * @return array{ready:int[],skipped:array<string,int>,blocked:array<int,string>}
     */
    public static function publishable( $term_id ) {
        $term_id = (int) $term_id;
        $out     = array( 'ready' => array(), 'skipped' => array(), 'blocked' => array() );

        $ids   = self::events( $term_id, array(
            'status' => self::editable_statuses(),
            'limit'  => -1,
        ) );
        $today = current_time( 'Y-m-d' );

        foreach ( $ids as $id ) {
            $why = self::publish_skip_reason( (int) $id, $today );
            if ( '' === $why ) {
                $out['ready'][] = (int) $id;
            } else {
                $out['skipped'][ $why ] = ( isset( $out['skipped'][ $why ] ) ? $out['skipped'][ $why ] : 0 ) + 1;
                $out['blocked'][ (int) $id ] = $why;
            }
        }

        return $out;
    }

    /**
     * Why one event is not this bulk publish's to touch, or '' when it is.
     *
     * ITS OWN METHOD BECAUSE IT IS THE PART THAT MATTERS. publishable() decides
     * which events to ask about, which is a WP_Query and is the same query the
     * screen already runs. This decides what happens to each one, and it is the
     * only thing standing between a button labelled "publish" and somebody
     * else's row. A rule that can be exercised without a database is a rule
     * that gets exercised; see .claude/series-publish-test.php.
     *
     * The order is deliberate: cheapest and most common first, so an ordinary
     * published event costs one get_post_status() and nothing else.
     *
     * @param int    $id
     * @param string $today Y-m-d, passed in so one run judges every event
     *                      against one date rather than against the clock.
     * @return string
     */
    public static function publish_skip_reason( $id, $today ) {
        $id = (int) $id;

        if ( 'draft' !== get_post_status( $id ) ) {
            return 'not a draft';
        }
        $date = (string) get_post_meta( $id, '_uc_event_date', true );
        if ( '' === $date ) {
            return 'no date yet';
        }
        if ( $date < (string) $today ) {
            return 'already happened';
        }
        $prov = SFAF_Sources::provenance( $id );
        if ( '' !== $prov['source'] || '' !== $prov['external_id'] ) {
            return 'imported from a source';
        }
        if ( '' !== (string) get_post_meta( $id, SFAF_Sources::META_REMOVED_AT, true ) ) {
            return 'gone at its source';
        }
        if ( '' !== (string) get_post_meta( $id, SFAF_Submissions::META_KIND, true ) ) {
            return 'a submission';
        }
        return '';
    }

    /**
     * Publish those drafts.
     *
     * THE SET IS RE-DECIDED HERE RATHER THAN TAKEN FROM THE FORM. The button
     * said a number, and between the page rendering and the press a fetch could
     * have run, a date could have passed midnight or somebody could have
     * published one by hand. Asking publishable() again is what makes the button
     * do what its label meant rather than what a hidden field remembered.
     *
     * wp_update_post(), not a direct status write, so save_post fires and every
     * listener that cares about an event becoming public gets its turn.
     *
     * THE CHOSEN SET NARROWS, IT NEVER WIDENS (3.72.0). The screen now carries
     * a tick per row, so a press can mean "these four" rather than "all of
     * them", and $only is how that arrives. It is INTERSECTED with what
     * publishable() says is ready, in that order: an id that is not in `ready`
     * is dropped whatever the form said, so individual selection cannot become
     * a route to publishing a past date, an event with no date, a submission or
     * somebody else's imported row. The four skip rules are unchanged and are
     * still the only thing that decides eligibility.
     *
     * NULL MEANS ALL OF THEM, which is the no-script path: with no ticks posted
     * the form falls back to what the button has always done. An EMPTY ARRAY is
     * not the same thing and does not mean all: it means somebody unticked
     * everything, and the honest answer to that is to publish nothing.
     *
     * @param int        $term_id
     * @param int[]|null $only Ids the manager ticked, or null for every ready one.
     * @return array{published:int,skipped:array<string,int>,failed:int}
     */
    public static function publish_drafts( $term_id, $only = null ) {
        $plan   = self::publishable( $term_id );
        $done   = 0;
        $failed = 0;

        if ( null !== $only ) {
            $wanted        = array_map( 'intval', (array) $only );
            $plan['ready'] = array_values( array_intersect( $plan['ready'], $wanted ) );
        }

        foreach ( $plan['ready'] as $id ) {
            $res = wp_update_post( array( 'ID' => (int) $id, 'post_status' => 'publish' ), true );
            if ( is_wp_error( $res ) || ! $res ) {
                $failed++;
            } else {
                $done++;
            }
        }

        return array( 'published' => $done, 'skipped' => $plan['skipped'], 'failed' => $failed );
    }

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
    /**
     * What this series can lend a new event, for the creation prefill.
     *
     * WHERE EACH VALUE COMES FROM, because it is two different places and the
     * difference matters. The series term itself carries a description, an
     * image and a default FAQ set. It does NOT carry a location, times, a
     * category or an organizer: those are properties of the EVENTS in it, so
     * they are read from the most recent one.
     *
     * "Most recent" rather than "next upcoming": somebody adding a date to a
     * running series wants another one like the last one, and the last one is
     * the one whose details were most recently thought about. An upcoming
     * occurrence may itself be a half-finished draft.
     *
     * THE DATE IS NEVER HERE. Setting the date is the reason somebody is
     * creating an event, and a prefilled one is a past date pretending to be a
     * new event. Same rule as duplicate-as-template.
     *
     * @param int $term_id
     * @return array<string,mixed>
     */
    public static function prefill_data( $term_id ) {
        $term_id = (int) $term_id;
        $out = array(
            'location_mode' => '',
            'venue'         => 0,
            'venue_name'    => '',
            'location'      => '',
            'start_time'    => '',
            'end_time'      => '',
            'description'   => '',
            'image_url'     => '',
            'image_id'      => 0,
            'image_preview' => '',
            'categories'    => array(),
            'category_names'=> array(),
            'organizers'    => array(),
            'organizer_name'=> '',
            'faq_set'       => '',
            'faq_set_name'  => '',
            'from_event'    => 0,
        );

        $term = self::get( $term_id );
        if ( ! $term ) {
            return $out;
        }

        /* ---- From the series term itself. --------------------------- */
        $out['description'] = (string) $term->description;
        $out['image_url']   = (string) self::image_url( $term_id );
        $out['faq_set']     = (string) self::default_faq_set( $term_id );

        /*
         * WHAT TO SHOW IS NOT ALWAYS WHAT TO WRITE, which is the whole reason
         * this is a second key rather than the same one read twice.
         *
         * 'image_url' is the value COPIED into the event's URL field, and it is
         * legitimately empty when the picture is a chosen attachment: the id is
         * then what carries it. A card and a preview still have to show
         * something, so 'image_preview' is a URL for the screen and nothing
         * else. Never write it into a field.
         */
        $out['image_preview'] = $out['image_url'];
        if ( '' !== $out['faq_set'] ) {
            $set = SFAF_FAQ_Sets::get( $out['faq_set'] );
            $out['faq_set_name'] = $set ? $set['name'] : '';
        }

        /* ---- From its most recent event. ----------------------------
         *
         * NOT limit => 1. events() takes no order argument and always returns
         * ascending by event date, so asking for one row hands back the series'
         * OLDEST event while looking like it asked for the newest. The whole
         * list comes back cheaply as ids and the last of it is the one wanted.
         */
        $events = self::events( $term_id, array( 'status' => array( 'publish', 'draft', 'pending', 'future' ) ) );
        if ( empty( $events ) ) {
            return $out;
        }
        $id = (int) end( $events );
        $out['from_event'] = $id;

        $out['start_time'] = (string) get_post_meta( $id, '_uc_start_time', true );
        $out['end_time']   = (string) get_post_meta( $id, '_uc_end_time', true );

        $venue = SFAF_Venues::id_for_event( $id );
        if ( $venue ) {
            $out['location_mode'] = 'venue';
            $out['venue']         = (int) $venue;
            $v = get_term( $venue, 'uc_venue' );
            $out['venue_name'] = ( $v && ! is_wp_error( $v ) ) ? $v->name : '';
        } else {
            $text = (string) get_post_meta( $id, '_uc_location', true );
            if ( '' !== $text ) {
                $out['location_mode'] = 'custom';
                $out['location']      = $text;
            }
        }

        // The series' own description wins when it has one: it describes the
        // series, which is what a new date in it is. An event's description is
        // the fallback and is usually the same text anyway.
        if ( '' === trim( $out['description'] ) ) {
            $post = get_post( $id );
            $out['description'] = $post ? (string) $post->post_content : '';
        }
        if ( '' === $out['image_url'] ) {
            $out['image_url'] = (string) get_post_meta( $id, '_uc_image_url', true );
            $out['image_id']  = (int) get_post_thumbnail_id( $id );

            $out['image_preview'] = $out['image_url'];
            if ( $out['image_id'] ) {
                $src = wp_get_attachment_image_url( $out['image_id'], 'medium' );
                if ( $src ) {
                    $out['image_preview'] = (string) $src;
                }
            }
        }

        foreach ( (array) wp_get_post_terms( $id, 'uc_event_category' ) as $c ) {
            if ( is_object( $c ) ) {
                $out['categories'][]     = (int) $c->term_id;
                $out['category_names'][] = $c->name;
            }
        }

        // Every organizer, so a co-hosted series lends both. The label is the
        // same phrase the event page uses, so the checkbox in the prefill panel
        // reads the way the result will.
        foreach ( SFAF_Organizers::for_event( $id ) as $term ) {
            $out['organizers'][] = (int) $term->term_id;
        }
        $out['organizer_name'] = SFAF_Organizers::phrase( $id );

        return $out;
    }

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
