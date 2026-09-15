<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Shortcodes {

    public function register() {
        add_shortcode( 'sfaf_calendar', array( $this, 'render_calendar' ) );
        add_shortcode( 'upcoming_events', array( $this, 'render_upcoming' ) );
        add_action( 'wp_ajax_uc_load_events', array( $this, 'ajax_load_events' ) );
        add_action( 'wp_ajax_nopriv_uc_load_events', array( $this, 'ajax_load_events' ) );
        add_action( 'wp_ajax_uc_load_month', array( $this, 'ajax_load_month' ) );
        add_action( 'wp_ajax_nopriv_uc_load_month', array( $this, 'ajax_load_month' ) );
        // The whole block, for a change that alters the controls as well as the
        // list: choosing a group re-derives the Groups row and the breadcrumb.
        add_action( 'wp_ajax_uc_load_block', array( $this, 'ajax_load_block' ) );
        add_action( 'wp_ajax_nopriv_uc_load_block', array( $this, 'ajax_load_block' ) );
    }

    /* ---------------------------------------------------------------------
     * Pagination helpers
     * ------------------------------------------------------------------- */

    /**
     * Resolve per-page: a shortcode attribute overrides the global setting.
     * 0 or -1 means "show all events with no pagination".
     *
     * Public so the embed endpoint resolves per-page identically rather than
     * keeping a second copy of the fallback chain.
     */
    public function resolve_per_page( $raw ) {
        if ( $raw === '' || $raw === null ) {
            $settings = get_option( 'uc_settings', array() );
            return ( isset( $settings['display_per_page'] ) && $settings['display_per_page'] !== '' )
                ? intval( $settings['display_per_page'] ) : 12;
        }
        return intval( $raw );
    }

    /** Global pagination style: load_more | pages | infinite. */
    private function pagination_style() {
        $settings = get_option( 'uc_settings', array() );
        $style    = isset( $settings['display_pagination'] ) ? $settings['display_pagination'] : 'load_more';
        return in_array( $style, array( 'load_more', 'pages', 'infinite' ), true ) ? $style : 'load_more';
    }

    /* ---------------------------------------------------------------------
     * Filters
     * ------------------------------------------------------------------- */

    /**
     * The filter set every display path shares, normalized from raw input.
     *
     * Keeping this in one place means the shortcodes, the load-more handler and
     * the public embed endpoint all accept exactly the same filters — a filter
     * added here reaches all three at once.
     *
     * @param array $raw Any array with some of: category, organizer, series, venue.
     * @return array{category:string,organizer:string,series:int,venue:string}
     */
    private function normalize_filters( $raw ) {
        return array(
            'category'  => $this->slug_list( isset( $raw['category'] ) ? $raw['category'] : '' ),
            'organizer' => $this->slug_list( isset( $raw['organizer'] ) ? $raw['organizer'] : '' ),
            'venue'     => $this->slug_list( isset( $raw['venue'] ) ? $raw['venue'] : '' ),
            'series'    => isset( $raw['series'] ) ? absint( $raw['series'] ) : 0,
            /*
             * SEARCH IS A FILTER LIKE THE REST OF THEM.
             *
             * Putting it here rather than anywhere else is what makes the
             * calendar on this site and an embed on another one return the
             * same events for the same words: both reach build_query_args()
             * through this, so there is one query and no second implementation
             * to disagree with the first.
             *
             * It used to be neither. Both surfaces filtered the cards already
             * in the page with JavaScript, which meant a search only ever
             * looked at the events on the current page and never at the rest,
             * and looked through the rendered card text rather than the event.
             */
            's'         => isset( $raw['s'] ) ? sanitize_text_field( (string) $raw['s'] ) : '',
            /*
             * THE MONTH THE QUERY IS BOUND TO, or '' for "everything upcoming".
             *
             * NOT CALLED 'month', AND THAT IS THE WHOLE OF WHY THIS KEY EXISTS
             * (3.45.2). It was, and a block carries a `month` attribute meaning
             * something completely different: which month the GRID is drawing.
             * render_calendar_block() hands its whole $args array to this
             * method, and SFAF_Embed::normalize_params() always fills that
             * attribute in, because normalize_month() answers "which month am I
             * drawing" and so never returns ''. So every embedded list was
             * silently bounded to the current month: 27 upcoming events, 5 on
             * screen. Two different things sharing one key, and the collision
             * was the bug.
             *
             * A RENDERER SETS THIS, A BLOCK NEVER DOES. render_sidebar() sets
             * it when its caller passed a month, and month_event_count() sets
             * it to count one. Nothing reads it off an attribute, an embed
             * parameter or a POST field, so no display month can reach it by
             * being spelled the same way.
             *
             * It only ever NARROWS. The builder's "today or later" clause is
             * set first and this adds a window inside it, so binding to the
             * current month cannot show anything that has already happened, and
             * binding to a past month is impossible because normalize_month()
             * will not return one.
             */
            'bound_month' => isset( $raw['bound_month'] ) ? $this->normalize_month_or_blank( $raw['bound_month'] ) : '',
            /*
             * THE GROUPS A VISITOR PICKED, as series slugs.
             *
             * Separate from 'series', which is the block's own scope and takes
             * a single id from a snippet. This is a visitor narrowing what is
             * already in front of them and may name several, so it is a slug
             * list like category, organizer and venue. See group_pills().
             */
            'groups'    => $this->slug_list( isset( $raw['groups'] ) ? $raw['groups'] : '' ),
        );
    }

    /**
     * Sanitize a comma-separated list of term slugs down to a clean CSV string.
     * Returns '' when nothing usable is left, which every caller reads as "no filter".
     */
    private function slug_list( $raw ) {
        /*
         * AN ARRAY IS A REAL SHAPE HERE FROM 3.85.0, and this used to cast one
         * straight to a string. The filter bar's controls are checkboxes now,
         * named `uc_org[]` and `uc_group[]`, so with no script the browser
         * submits a real array. `(string) array()` is "Array" plus a notice,
         * which sanitize_title() turns into the slug `array`, which matches no
         * term, which empties the calendar silently. Imploding first is the
         * whole fix and it leaves every existing CSV caller untouched.
         */
        if ( is_array( $raw ) ) {
            $raw = implode( ',', $raw );
        }
        $slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $raw ) ) );
        return implode( ',', array_unique( $slugs ) );
    }

    /**
     * The category actually queried: what the visitor chose, inside what the
     * block was scoped to.
     *
     * TWO DIFFERENT THINGS USED TO SHARE ONE ATTRIBUTE. `category="fundraising"`
     * on a shortcode is the author saying "this block is the fundraising
     * calendar", and it is not negotiable by whoever is reading the page. A
     * click on a filter chip is the visitor narrowing what is already there.
     * Keeping them apart is what lets the chips run a real query instead of
     * hiding cards, without a chip or a hand-written URL being able to widen a
     * block past what its author scoped it to.
     *
     * A chosen category outside the scope is DROPPED, not honoured and not
     * treated as an empty result: it can only arrive from a stale link or a
     * crafted request, and showing the block's own events is the honest answer
     * to both.
     *
     * @param string $scope  The block's own category attribute.
     * @param string $active What the visitor picked.
     * @return string
     */
    public function effective_category( $scope, $active ) {
        $scope  = $this->slug_list( $scope );
        $active = $this->slug_list( $active );

        if ( '' === $active ) {
            return $scope;
        }
        if ( '' === $scope ) {
            return $active;
        }
        $inside = array_intersect( explode( ',', $active ), explode( ',', $scope ) );
        return empty( $inside ) ? $scope : implode( ',', $inside );
    }

    /**
     * The category a visitor asked for in the URL.
     *
     * One parameter, uc_cat, written by every category chip on an event page so
     * that "show me the rest of the support groups" lands on a calendar already
     * filtered rather than on a full list the visitor has to filter again.
     *
     * @return string
     */
    private function requested_category() {
        return isset( $_GET['uc_cat'] ) ? $this->slug_list( wp_unslash( $_GET['uc_cat'] ) ) : '';
    }

    /**
     * The three filter rows a block can offer, in the order they render.
     *
     * ONE LIST, READ BY THE ATTRIBUTE, THE RENDER, THE GENERATOR AND THE TEST.
     * The order is the order a visitor asks the questions in: category is the
     * broadest, then who is putting it on, then which of that programme's runs.
     *
     * @return string[]
     */
    public static function filter_rows_available() {
        return array( 'category', 'organizer', 'series' );
    }

    /**
     * Which rows this block offers, as key => bool.
     *
     * WHY THIS REPLACED A SINGLE ON/OFF SWITCH. `show_filters` was all or
     * nothing, and neither answer fitted the two cases that actually come up: a
     * block scoped to one series on that programme's own page wants NO filters,
     * because the block is already the answer; a block scoped to an organizer
     * wants the SERIES row only, so a visitor can move between that organizer's
     * programmes. Many organizers run several.
     *
     * NOTHING HERE SECOND-GUESSES THE CHOICE. A block scoped to one organizer
     * that asks for the organizer row gets the organizer row. Whoever generates
     * the block decides what is useful on the page it is going on; a plugin
     * deciding a control is redundant and hiding it is a plugin overruling
     * somebody who can see the page and it cannot.
     *
     * THE OLD ATTRIBUTE STILL DECIDES WHEN THE NEW ONE IS ABSENT, which is what
     * keeps every block already pasted on sfaf.org working: `show_filters="no"`
     * still means none, and anything else still means all three.
     *
     * @param string $raw    Comma list, or '' to fall back.
     * @param string $legacy The old show_filters attribute.
     * @return array<string,bool>
     */
    public function filter_rows( $raw, $legacy = '' ) {
        $rows = array();
        foreach ( self::filter_rows_available() as $key ) {
            $rows[ $key ] = false;
        }

        $raw = strtolower( trim( (string) $raw ) );

        if ( '' === $raw ) {
            $all = $this->show_filters( $legacy );
            foreach ( $rows as $key => $unused ) {
                $rows[ $key ] = $all;
            }
            return $rows;
        }

        /* "none" said out loud, because an empty attribute means "not stated"
         * and has to keep meaning that. Without a word for it there would be no
         * way to ask for no rows except by going back to show_filters="no". */
        if ( in_array( $raw, array( 'none', 'no', 'false', '0', 'off' ), true ) ) {
            return $rows;
        }
        if ( in_array( $raw, array( 'all', 'yes', 'true', '1', 'on' ), true ) ) {
            foreach ( $rows as $key => $unused ) {
                $rows[ $key ] = true;
            }
            return $rows;
        }

        foreach ( explode( ',', $raw ) as $part ) {
            $part = sanitize_key( trim( $part ) );
            if ( isset( $rows[ $part ] ) ) {
                $rows[ $part ] = true;
            }
        }
        return $rows;
    }

    /** The rows that are on, as the comma list the attribute carries. */
    public function filter_rows_attr( $rows ) {
        $on = array();
        foreach ( self::filter_rows_available() as $key ) {
            if ( ! empty( $rows[ $key ] ) ) {
                $on[] = $key;
            }
        }
        return empty( $on ) ? 'none' : implode( ',', $on );
    }

    /**
     * The organizer actually queried: what the visitor chose, inside what the
     * block was scoped to.
     *
     * THE SAME CLAMP THE CATEGORY GETS, and for the same reason. `organizer=`
     * on a snippet is the author saying what this block IS; a choice in the
     * dropdown is a visitor narrowing what is already there. A hand-written
     * `uc_org` naming an organizer outside the scope is dropped, so the block
     * shows its own events rather than somebody else's.
     *
     * @param string $scope
     * @param string $active
     * @return string
     */
    public function effective_organizer( $scope, $active ) {
        $scope  = $this->slug_list( $scope );
        $active = $this->slug_list( $active );

        if ( '' === $active ) {
            return $scope;
        }
        if ( '' === $scope ) {
            return $active;
        }
        $inside = array_intersect( explode( ',', $active ), explode( ',', $scope ) );
        return empty( $inside ) ? $scope : implode( ',', $inside );
    }

    /** What the visitor asked for in the organizer dropdown, if anything. */
    private function requested_organizer() {
        return isset( $_GET['uc_org'] ) ? $this->slug_list( wp_unslash( $_GET['uc_org'] ) ) : '';
    }

    /**
     * Whether the visitor-facing search and category buttons should render.
     *
     * Accepts what a shortcode attribute or a data attribute might carry,
     * yes/no, true/false, 1/0, and defaults to showing them.
     *
     * SUPERSEDED BY `filters` AND KEPT AS ITS FALLBACK (3.50.0). Every block
     * already pasted on sfaf.org carries this and no `filters`, so this is what
     * decides for them. See filter_rows().
     */
    private function show_filters( $raw ) {
        $raw = strtolower( trim( (string) $raw ) );
        if ( $raw === '' ) {
            return true;
        }
        return ! in_array( $raw, array( 'no', 'false', '0', 'off' ), true );
    }

    /**
     * Shared WP_Query args for upcoming events.
     *
     * @param int   $per_page Posts per page; 0 or less means all.
     * @param int   $paged    1-based page number.
     * @param array $filters  Normalized filters, see normalize_filters().
     */
    private function build_query_args( $per_page, $paged, $filters ) {
        $filters = $this->normalize_filters( $filters );

        $args = array(
            'post_type'   => 'uc_event',
            'post_status' => 'publish',
            'meta_key'    => '_uc_event_date',
            'orderby'     => 'meta_value',
            'order'       => 'ASC',
            'meta_query'  => array(
                array(
                    'key'     => '_uc_event_date',
                    'value'   => current_time( 'Y-m-d' ),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ),
            ),
        );

        /*
         * PRIVATE EVENTS COME OUT HERE, WHICH IS ALSO WHY THE EMBED IS COVERED.
         *
         * This is the one builder behind the list, the sidebar, the search
         * results and every payload SFAF_Embed serves, because build_payload()
         * calls render_events() and render_calendar_block() and both come
         * through here. Excluding at the builder rather than at each renderer
         * is what makes "hidden from the shortcode but still in the embed
         * payload" impossible rather than merely unlikely.
         *
         * Before the date window and before the search, so nothing downstream
         * can widen it back. See SFAF_Privacy::exclude() for why the clause
         * nests rather than appends.
         */
        SFAF_Privacy::exclude( $args );
        // And cancelled events the organizer chose to hide. A different
        // question about the same query, applied at the same place so the
        // two cannot be excluded from one builder and not the other.
        SFAF_Cancellation::exclude( $args );

        /*
         * BOUND TO ONE MONTH, when the caller asked for that.
         *
         * Appended as a sibling of the "today or later" clause, so the two are
         * ANDed and the window is "from today, and no later than the end of
         * this month". On the current month that means the rest of it, which is
         * what somebody looking at the current month wants: the days that have
         * gone are not coming back.
         */
        if ( '' !== $filters['bound_month'] ) {
            /*
             * BOTH ENDS, AND THE FIRST VERSION ONLY SET ONE.
             *
             * With an upper bound alone the window was "from today to the end
             * of October", so October's sidebar opened with the last days of
             * August in it and the tab under it counted them. The grid beside
             * it showed October. That is precisely the disagreement between the
             * two halves this release exists to remove, and it was introduced
             * by the change meant to remove it.
             *
             * The lower bound is the first of the month. It sits alongside the
             * builder's "today or later" clause rather than replacing it, so
             * both hold and the effective floor is whichever is later: the
             * current month shows the rest of itself, and a future month shows
             * all of itself.
             */
            $args['meta_query'][] = array(
                'key'     => '_uc_event_date',
                'value'   => $filters['bound_month'] . '-01',
                'compare' => '>=',
                'type'    => 'DATE',
            );
            $args['meta_query'][] = array(
                'key'     => '_uc_event_date',
                'value'   => $this->month_last_day( $filters['bound_month'] ),
                'compare' => '<=',
                'type'    => 'DATE',
            );
        }

        if ( $per_page <= 0 ) {
            $args['posts_per_page'] = -1;
        } else {
            $args['posts_per_page'] = $per_page;
            $args['paged']          = max( 1, $paged );
        }

        /*
         * SEARCH, ADDED TO A QUERY THAT IS ALREADY RESTRICTED.
         *
         * post_status is 'publish' and the date window is "today or later",
         * both set above and neither touched by this. SFAF_Search only ever
         * appends to the WHERE, so a search can narrow this result set and has
         * no way to widen it: there is no search term that reaches a draft, a
         * pending event, a dismissed import or a past one, because search does
         * not choose which events are eligible.
         */
        if ( '' !== $filters['s'] ) {
            SFAF_Search::apply( $args, $filters['s'] );
        }

        // Slug-based taxonomy filters. Several slugs in one filter are an OR;
        // two different filters combine as an AND (WP_Query's tax_query default).
        $taxonomies = array(
            'category'  => 'uc_event_category',
            'organizer' => 'uc_organizer',
            'venue'     => 'uc_venue',
        );
        foreach ( $taxonomies as $key => $taxonomy ) {
            if ( $filters[ $key ] !== '' ) {
                $args['tax_query'][] = array(
                    'taxonomy' => $taxonomy,
                    'field'    => 'slug',
                    'terms'    => explode( ',', $filters[ $key ] ),
                );
            }
        }

        // Series.
        //
        // THE SNIPPET CONTRACT IS UNCHANGED. series="123" still takes a single
        // integer and still means "only this series", exactly as every embed
        // snippet and shortcode on sfaf.org already says. What changed is what
        // the integer is looked up in: it used to be a post ID matched against
        // _uc_series_parent, and it is now resolved by SFAF_Series::resolve(),
        // which tries the old parent ID first and the term ID second. That is
        // what lets existing embed code keep working without being regenerated.
        $series_term = SFAF_Series::resolve( $filters['series'] );
        if ( $series_term > 0 ) {
            $args['tax_query'][] = array(
                'taxonomy' => SFAF_Series::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => $series_term,
            );
        }

        /*
         * THE GROUPS THE VISITOR CHOSE. Several slugs are an OR, because
         * choosing two groups means "either of these", and the clause ANDs with
         * everything above it, because it is narrowing a set that has already
         * been narrowed by category and by the block's own scope.
         *
         * A slug that reaches here has already been checked against the list of
         * groups this block actually contains. See effective_groups().
         */
        if ( '' !== $filters['groups'] ) {
            $args['tax_query'][] = array(
                'taxonomy' => SFAF_Series::TAXONOMY,
                'field'    => 'slug',
                'terms'    => explode( ',', $filters['groups'] ),
            );
        }

        return $args;
    }

    /* ---------------------------------------------------------------------
     * The second level: groups.
     *
     * WHAT THIS IS FOR. The visitor filter bar is category-only on purpose: a
     * person reading the calendar should never have to learn what an organizer
     * or a series is. But somebody who found one Wednesday of a group that runs
     * every Wednesday had no way to ask for the rest of its dates.
     *
     * SO IT IS A SECOND LEVEL, NOT A SECOND BAR. Nothing shows until a category
     * is chosen, so nobody is ever looking at two taxonomies at once, and the
     * row is derived from what is actually in front of them rather than from a
     * list of everything the site has.
     *
     * THE WORD "SERIES" IS NEVER SHOWN. It is the name of a data structure. The
     * row is called Groups and the pills carry the term's own public name.
     * ------------------------------------------------------------------- */

    /**
     * The groups a block currently contains, in name order.
     *
     * DERIVED FROM THE EVENTS, NOT FROM THE SERIES LIST. get_terms() on
     * uc_series would answer "every series on the site", which is a different
     * question and a longer list: a series with nothing in this category, or
     * nothing upcoming, or nothing inside this block's scope, would be offered
     * and would return an empty calendar when pressed.
     *
     * So the same query the list runs is run once for ids, and the terms are
     * asked for those objects. That means every pill has at least one event
     * behind it by construction, and it also means the list respects the block's
     * scope for free: whatever category, organizer, venue, series and search the
     * block was built with are already in these filters.
     *
     * THE GROUP SELECTION ITSELF IS NOT APPLIED when deriving. A row that shrank
     * to the one pill you just pressed would take away the way back.
     *
     * @param array $filters Normalized filters, group selection ignored.
     * @return WP_Term[]
     */
    public function available_groups( $filters ) {
        $filters           = $this->normalize_filters( $filters );
        $filters['groups'] = '';

        /*
         * NO CATEGORY GATE HERE ANY MORE (3.50.0), AND THAT IS A REAL CHANGE.
         *
         * 3.11.0 derived the second level only once a category had been chosen,
         * so that nobody was looking at two taxonomies at once. That was the
         * right default when the bar was all-or-nothing. It is wrong now: a
         * block can offer the SERIES row and NOT the category row, which is
         * exactly the organizer-scoped case this was built for, and under the
         * old gate that block could never show a single pill.
         *
         * The row is offered when the block says to offer it, and the caller
         * asks only then, so the query below is still not paid for by a block
         * that has no series row.
         */

        $args                   = $this->build_query_args( 0, 1, $filters );
        $args['posts_per_page'] = 300;
        $args['fields']         = 'ids';
        $args['no_found_rows']  = true;
        $args['update_post_meta_cache'] = false;
        $args['update_post_term_cache'] = false;
        unset( $args['paged'] );

        $q = new WP_Query( $args );
        if ( empty( $q->posts ) ) {
            return array();
        }

        $terms = get_terms( array(
            'taxonomy'   => SFAF_Series::TAXONOMY,
            'object_ids' => array_map( 'intval', $q->posts ),
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        // object_ids can return the same term once per object it is on.
        $unique = array();
        foreach ( $terms as $term ) {
            $unique[ (int) $term->term_id ] = $term;
        }
        $terms = array_values( $unique );
        usort( $terms, function ( $a, $b ) {
            return strcasecmp( $a->name, $b->name );
        } );

        return $terms;
    }

    /**
     * The group selection actually queried: what was asked for, kept to what
     * this block contains.
     *
     * THE CLAMP IS THE DERIVED LIST, and that is the whole of it. The list was
     * built under the block's own category, organizer, venue, series and search,
     * so a group that is not in it is a group this block does not contain, and a
     * slug naming one can only have come from a stale link or a hand-written
     * parameter. It is DROPPED rather than honoured and rather than returning
     * nothing: showing the block's own events is the honest answer to both.
     *
     * @param WP_Term[] $available From available_groups().
     * @param string    $requested Slug list.
     * @return string
     */
    public function effective_groups( $available, $requested ) {
        $requested = $this->slug_list( $requested );
        if ( '' === $requested || empty( $available ) ) {
            return '';
        }

        $allowed = wp_list_pluck( $available, 'slug' );
        $inside  = array_values( array_intersect( explode( ',', $requested ), $allowed ) );

        return empty( $inside ) ? '' : implode( ',', $inside );
    }

    /**
     * The groups a visitor asked for in the URL.
     *
     * uc_group, following uc_cat, so a narrowed calendar is a real address that
     * survives a reload and can be sent to somebody.
     *
     * @return string
     */
    private function requested_groups() {
        return isset( $_GET['uc_group'] ) ? $this->slug_list( wp_unslash( $_GET['uc_group'] ) ) : '';
    }

    /**
     * Derive and clamp in one step, for the paths that have filters and a
     * requested selection but no rendered block to have derived from.
     *
     * THE CLAMP IS RE-DERIVED SERVER-SIDE, NOT TRUSTED. The browser sends what
     * it thinks is selected; this works out what the block actually contains
     * from the same filters the block was built with, and keeps only the
     * overlap. A crafted request therefore reaches nothing a visitor could not
     * have reached by pressing the pills.
     *
     * Skipped entirely when nothing was asked for, so the ordinary page of
     * events pays for no extra query.
     *
     * @param array  $filters   Normalized filters.
     * @param string $requested Slug list from the request.
     * @return string
     */
    public function clamp_groups( $filters, $requested ) {
        $requested = $this->slug_list( $requested );
        if ( '' === $requested ) {
            return '';
        }
        return $this->effective_groups( $this->available_groups( $filters ), $requested );
    }

    /* ---------------------------------------------------------------------
     * View modes
     *
     * ONE RENDERER, THREE VIEWS. The shortcode and the cross-domain embed have
     * always shared render_calendar_block() and render_events(); the embed
     * endpoint calls those methods rather than owning a copy. Adding the month
     * grid and the sidebar here means both get them at once and neither can
     * drift, which is why this build needed no refactor to satisfy "share the
     * rendering code".
     *
     *   list      the vertical card list (default)
     *   calendar  month grid
     *   combined  the month grid and the list, side by side
     *   sidebar   compact, count-limited, for narrow placements
     *
     * COMBINED COMPOSES THE OTHER TWO AND IS NOT A THIRD RENDERER.
     *
     * It is the existing month grid and the existing list, in a two-column
     * wrapper. Both panels were already being rendered by render_calendar_block()
     * whenever the toggle was on, because flipping a toggle should not cost a
     * round trip, so this mode is very nearly free: it shows both instead of
     * hiding one. A fix to either renderer reaches this mode without anybody
     * remembering it exists, which is the point.
     *
     * THEY ARE TWO VIEWS OF ONE FILTERED SET, NOT ONE DRIVING THE OTHER. The
     * category bar, the group pills, the search and the block's scope all apply
     * to both, because both are built from the same $filters. Clicking a date
     * in the grid does what it has always done and does not touch the list: the
     * list is "what is coming up", not "what is on the day you pressed", and
     * making it follow the grid would take that view away with nothing to
     * replace it.
     * ------------------------------------------------------------------- */

    /** The four display modes, and the fallback for anything unrecognised. */
    public function normalize_view( $raw ) {
        $view = strtolower( trim( (string) $raw ) );
        return in_array( $view, array( 'list', 'calendar', 'combined', 'sidebar' ), true ) ? $view : 'list';
    }

    /** Whether this view shows the grid and the list at once. */
    public function is_combined_view( $view ) {
        return 'combined' === $view;
    }

    /**
     * Read a source_links attribute into a flag.
     *
     * THREE ANSWERS, NOT TWO. 'yes' and 'no' are what a snippet or a shortcode
     * said; null is what "the attribute was not there" means, and it resolves
     * to sfaf_source_links_default() at read time rather than here. That is
     * what keeps the shipped default in exactly one function: a block written
     * before this feature existed follows the default forever, including after
     * somebody flips it, rather than being frozen at whatever it was when the
     * snippet was pasted.
     *
     * @param mixed $raw
     * @return bool|null
     */
    public function normalize_source_links( $raw ) {
        if ( null === $raw || '' === $raw ) {
            return null;
        }
        if ( is_bool( $raw ) ) {
            return $raw;
        }
        $v = strtolower( trim( (string) $raw ) );
        if ( in_array( $v, array( 'yes', '1', 'true', 'on' ), true ) ) {
            return true;
        }
        if ( in_array( $v, array( 'no', '0', 'false', 'off' ), true ) ) {
            return false;
        }
        return null;
    }

    /**
     * The month a grid is showing, as Y-m.
     *
     * TIMEZONE. "This month" is resolved with current_time(), which is the
     * SITE's clock, never the server's and never the visitor's. See
     * month_grid_data() for the fuller note on why day assignment cannot be
     * allowed anywhere near a browser.
     *
     * @param string $raw
     * @return string
     */
    public function normalize_month( $raw ) {
        /*
         * THE CURRENT MONTH IS THE FLOOR, AND IT IS ENFORCED HERE (3.45.0).
         *
         * A public calendar has no reason to browse backwards: everything
         * before today has happened. The controls have never offered it past
         * the current month, but the month rides a parameter on the REST route
         * and on the ajax loader, so ANYTHING could ask for 2019-03 and get a
         * payload built for it. This is the one place all four callers pass
         * through, which is why the clamp is here rather than on the route.
         *
         * CLAMPED, NOT REFUSED. An out-of-range month comes back as the current
         * one, and the response says which month it actually built, so a caller
         * that asked for something old gets a calendar rather than an error. A
         * 400 would be correct and would break any bookmark of a month that has
         * since passed, which is a real thing on a page somebody left open.
         *
         * WHAT THIS MUST NOT REACH, and does not: the single event page, which
         * is a URL rather than navigation. People arrive at a past event from
         * bookmarks, from search results and from reminder emails they kept,
         * and that has to resolve. It never calls this. Nor does caladmin,
         * whose Events list and Archived view are staff screens that need the
         * past; nothing in SFAF_Portal calls this either.
         *
         * A Y-m string compares correctly with a plain string comparison, which
         * is the one thing this format is good for.
         */
        $floor = current_time( 'Y-m' );

        $raw = trim( (string) $raw );
        if ( preg_match( '/^(\d{4})-(\d{2})$/', $raw, $m ) ) {
            $month = (int) $m[2];
            if ( $month >= 1 && $month <= 12 ) {
                return ( $raw < $floor ) ? $floor : $raw;
            }
        }
        return $floor;
    }

    /**
     * The last calendar day of a month, as Y-m-d.
     *
     * Built in UTC for the reason month_grid_days() sets out: these are
     * calendar days rather than moments, and stepping through a zone that
     * observes DST can land on the same date twice.
     *
     * @param string $month Y-m.
     * @return string
     */
    public function month_last_day( $month ) {
        $month = $this->normalize_month( $month );
        $first = new DateTimeImmutable( $month . '-01', new DateTimeZone( 'UTC' ) );
        return $first->modify( 'last day of this month' )->format( 'Y-m-d' );
    }

    /**
     * The month before or after this one, clamped by the floor.
     *
     * @param string $month Y-m.
     * @param int    $step  -1 or 1.
     * @return string Y-m, or '' when the step would go below the floor.
     */
    public function month_step( $month, $step ) {
        $month = $this->normalize_month( $month );
        $first = new DateTimeImmutable( $month . '-01', new DateTimeZone( 'UTC' ) );
        $moved = $first->modify( ( $step > 0 ? '+' : '-' ) . abs( (int) $step ) . ' month' )->format( 'Y-m' );
        return ( $moved < current_time( 'Y-m' ) ) ? '' : $moved;
    }

    /**
     * How many events fall in a month, for the tabs under the sidebar.
     *
     * Counted through build_query_args() rather than a query of its own, so a
     * tab cannot promise events the list would not show: the same privacy
     * exclusion, the same cancelled-and-hidden exclusion and the same filters.
     *
     * @param string $month Y-m.
     * @param array  $filters
     * @return int
     */
    public function month_event_count( $month, $filters ) {
        $filters['bound_month'] = $month;
        $counted = new WP_Query( array_merge(
            $this->build_query_args( -1, 1, $filters ),
            array( 'fields' => 'ids', 'no_found_rows' => false, 'posts_per_page' => 200 )
        ) );
        return (int) $counted->post_count;
    }

    /**
     * A month, clamped, or '' when nothing usable was asked for.
     *
     * normalize_month() answers "which month am I drawing", so it always names
     * one. This answers "is the query bound to a month", where the honest
     * answer is often no, and an empty string must not become the current month
     * or every unbound list would silently gain an upper bound.
     *
     * @param mixed $raw
     * @return string Y-m or ''.
     */
    private function normalize_month_or_blank( $raw ) {
        $raw = trim( (string) $raw );
        return ( '' === $raw ) ? '' : $this->normalize_month( $raw );
    }

    /**
     * Is this month the floor, so there is nothing before it to offer?
     *
     * @param string $month Y-m, already normalized.
     * @return bool
     */
    public function is_floor_month( $month ) {
        return ( (string) $month === current_time( 'Y-m' ) );
    }

    /**
     * Every calendar day the grid for one month must contain.
     *
     * ROW COUNT IS COMPUTED, NEVER FIXED. A month starting late in the week and
     * running 30 or 31 days spans SIX rows, not five: May 2026, August 2026 and
     * January 2027 all do. A hardcoded five-row grid drops the last days of
     * those months on the floor. The grid runs from the Sunday on or before the
     * 1st to the Saturday on or after the last day, and the row count falls out
     * of the length of that range.
     *
     * DATE ARITHMETIC IS DONE IN UTC ON PURPOSE. These are calendar days, not
     * moments: stepping "+1 day" through a timezone that observes DST can land
     * on the same date twice or skip one, which would duplicate or lose a
     * column. UTC has no transitions, so a whole-day step is always a whole day.
     * The dates themselves are site-local; only the arithmetic is neutral.
     *
     * @param string $month Y-m.
     * @return array{days:string[],rows:int,first:string,last:string,start:string,end:string,label:string,prev:string,next:string}
     */
    public function month_grid_days( $month ) {
        $month = $this->normalize_month( $month );
        $utc   = new DateTimeZone( 'UTC' );

        $first = new DateTimeImmutable( $month . '-01', $utc );
        $last  = $first->modify( 'last day of this month' );

        // Sunday first, matching the day-name order the calendar already uses.
        $start = $first->modify( '-' . (int) $first->format( 'w' ) . ' days' );
        $end   = $last->modify( '+' . ( 6 - (int) $last->format( 'w' ) ) . ' days' );

        $days   = array();
        $cursor = $start;
        // A guard, not a limit: six rows is 42 cells and the loop cannot
        // legitimately exceed that. It exists so a malformed date can never
        // spin here.
        for ( $i = 0; $i < 43 && $cursor <= $end; $i++ ) {
            $days[] = $cursor->format( 'Y-m-d' );
            $cursor = $cursor->modify( '+1 day' );
        }

        return array(
            'days'  => $days,
            'rows'  => (int) ceil( count( $days ) / 7 ),
            'first' => $first->format( 'Y-m-d' ),
            'last'  => $last->format( 'Y-m-d' ),
            'start' => $start->format( 'Y-m-d' ),
            'end'   => $end->format( 'Y-m-d' ),
            /* Through the one formatter, and via the Y-m-d STRING rather than
               the timestamp. This DateTimeImmutable is UTC on purpose (see the
               docblock: the day arithmetic must not meet a DST transition), so
               its instant for the 1st at midnight is the previous evening in
               San Francisco, and handing that timestamp to a site-timezone
               formatter would label August as July. The string carries the
               calendar date without the instant, which is the fact wanted here. */
            'label' => sfaf_ap_date( $first->format( 'Y-m-d' ), 'month_year' ),
            'prev'  => $first->modify( '-1 month' )->format( 'Y-m' ),
            'next'  => $first->modify( '+1 month' )->format( 'Y-m' ),
        );
    }

    /**
     * Events for one month grid, bucketed by the day they fall on.
     *
     * TIMEZONE, THE WHOLE ANSWER. _uc_event_date is stored as a plain Y-m-d
     * string that is ALREADY the site-local calendar day: the GoFundMe Pro
     * adapter converts the campaign's UTC timestamp with wp_date() before
     * storing it, Eventbrite supplies local wall-clock, and a person typing
     * into the editor is typing a date in this office's terms. So the day an
     * event belongs to is not computed here at all, it is read.
     *
     * That is what makes the grid safe. Nothing converts, nothing calls
     * strtotime() against the server's timezone, and no browser Date object is
     * ever involved: an 8pm event on the 5th appears on the 5th for a visitor
     * in Sydney exactly as it does for one in San Francisco. "Today" comes from
     * current_time(), which is the site's clock for the same reason.
     *
     * Occurrences are separate posts, each with its own _uc_event_date, so this
     * is one date-range query with no expansion step.
     *
     * @param string $month   Y-m.
     * @param array  $filters Normalized filters.
     * @return array{grid:array,events:array<string,int[]>,total:int}
     */
    public function month_grid_data( $month, $filters ) {
        $grid    = $this->month_grid_days( $month );
        $filters = $this->normalize_filters( $filters );

        $args = array(
            'post_type'              => 'uc_event',
            'post_status'            => 'publish',
            'posts_per_page'         => 300,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
            /*
             * A NAMED meta_query clause, ordered by name, and deliberately no
             * 'meta_key' alongside it. Setting meta_key AND a meta_query clause
             * on the same key makes WP_Query join wp_postmeta twice for that
             * key; ordering then refers to whichever join it picks, and a
             * second clause (the series filter below) can multiply rows. The
             * named form is unambiguous: one join, one sort, no duplicates.
             */
            'orderby'                => array( 'event_date' => 'ASC', 'ID' => 'ASC' ),
            'meta_query'             => array(
                'event_date' => array(
                    'key'     => '_uc_event_date',
                    'value'   => array( $grid['start'], $grid['end'] ),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ),
            ),
        );

        // The month grid is a second builder, so it needs the exclusion of its
        // own: a private event kept out of the list and left on the calendar
        // grid beside it would be found by anybody clicking a date. Appended as
        // a sibling of the named clause above, never wrapped around it, for the
        // double-join reason set out in the note there. See
        // SFAF_Privacy::exclude().
        SFAF_Privacy::exclude( $args );
        // And cancelled events the organizer chose to hide. A different
        // question about the same query, applied at the same place so the
        // two cannot be excluded from one builder and not the other.
        SFAF_Cancellation::exclude( $args );

        foreach ( array( 'category' => 'uc_event_category', 'organizer' => 'uc_organizer', 'venue' => 'uc_venue' ) as $key => $taxonomy ) {
            if ( '' !== $filters[ $key ] ) {
                $args['tax_query'][] = array(
                    'taxonomy' => $taxonomy,
                    'field'    => 'slug',
                    'terms'    => explode( ',', $filters[ $key ] ),
                );
            }
        }
        // Same resolution as list_query_args(); see the note there on why the
        // number in an existing snippet keeps meaning what it always meant.
        $series_term = SFAF_Series::resolve( $filters['series'] );
        if ( $series_term > 0 ) {
            $args['tax_query'][] = array(
                'taxonomy' => SFAF_Series::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => $series_term,
            );
        }

        // The chosen groups, so the grid cannot contradict the list beside it.
        // Already clamped by whoever built these filters.
        if ( ! empty( $filters['groups'] ) ) {
            $args['tax_query'][] = array(
                'taxonomy' => SFAF_Series::TAXONOMY,
                'field'    => 'slug',
                'terms'    => explode( ',', $filters['groups'] ),
            );
        }

        $query  = new WP_Query( $args );
        $by_day = array();
        foreach ( $query->posts as $post ) {
            $day = (string) get_post_meta( $post->ID, '_uc_event_date', true );
            if ( '' === $day ) {
                continue;
            }
            $by_day[ $day ][] = (int) $post->ID;
        }

        // Within a day, earliest start first. A dateless start sorts last.
        foreach ( $by_day as $day => $ids ) {
            usort( $ids, function ( $a, $b ) {
                $ta = (string) get_post_meta( $a, '_uc_start_time', true );
                $tb = (string) get_post_meta( $b, '_uc_start_time', true );
                if ( '' === $ta && '' === $tb ) { return $a - $b; }
                if ( '' === $ta ) { return 1; }
                if ( '' === $tb ) { return -1; }
                return strcmp( $ta, $tb );
            } );
            $by_day[ $day ] = $ids;
        }

        /*
         * TWO COUNTS, BECAUSE THEY ARE TWO DIFFERENT QUESTIONS.
         *
         * 'total' is everything the grid draws, which includes the leading and
         * trailing days borrowed from the neighbouring months. 'in_month' is
         * only the days that belong to the month named in the heading.
         *
         * The caption used to report 'total' while saying "this month", which
         * was wrong by up to twelve days. It also sat next to the block's own
         * "N upcoming events", and the two disagreeing looked like a bug when
         * it is not one: that number counts every published event from today
         * forward, across all future months, while this one counts a single
         * month including days already past. A calendar opened at the end of
         * July can legitimately show one event beside a total of thirty-eight,
         * because the other thirty-seven are in August and later. Both are now
         * labelled so the difference is readable rather than alarming.
         */
        $prefix   = substr( $grid['first'], 0, 7 );
        $in_month = 0;
        foreach ( $by_day as $day => $ids ) {
            if ( substr( $day, 0, 7 ) === $prefix ) {
                $in_month += count( $ids );
            }
        }

        /*
         * CLOSURES, PER DAY, FOR THE WHOLE GRID SPAN.
         *
         * Resolved here rather than in the cell loop so the option is read once
         * for a month rather than forty-two times, and so the renderer's job
         * stays 'draw what you were given'. A multi-day closure is ONE entry
         * and answers for each of its days: see SFAF_Closures::covering().
         *
         * Deliberately NOT merged into $by_day. A closure is not an event and
         * must not end up in a structure that counts events, is passed to
         * anything expecting post ids, or is sorted by start time. Keeping it in
         * its own key is what makes that a type error rather than a judgement.
         */
        $closed = array();
        foreach ( $grid['days'] as $day ) {
            $row = SFAF_Closures::covering( $day );
            if ( $row ) {
                $closed[ $day ] = $row;
            }
        }

        return array(
            'grid'     => $grid,
            'events'   => $by_day,
            'closed'   => $closed,
            'total'    => count( $query->posts ),
            'in_month' => $in_month,
        );
    }


    /**
     * The combined view's three pieces, built in ONE place (3.45.1).
     *
     * WHAT WENT WRONG. The first render composed head, grid and sidebar in
     * render_calendar_block(); the ajax redraw composed the same three in
     * ajax_load_month(). Two compositions of one thing, which is the fault the
     * image picker had in 3.43.1 and the fault this file's own report named in
     * 3.45.0: the same calls in two places, and a check asking whether a string
     * exists anywhere is satisfied by either copy.
     *
     * They had already drifted in a way that only showed after navigating. The
     * ajax decided whether the grid drew its own head from a BOOLEAN THE CLIENT
     * SENT. Any caller that did not send it, including a browser holding an
     * older calendar.js, got a grid with a head inside the left column while
     * the spanning head above it stayed put: two headings, the live one no
     * longer spanning, which is what "it goes left aligned when you navigate"
     * looks like.
     *
     * SO THE SHAPE IS NOT A PARAMETER ANY MORE. Both callers ask this, and this
     * decides. There is no way for the two to produce different markup for the
     * same month, because there is no second place that produces it.
     *
     * @param string $month   Y-m, already normalized.
     * @param array  $filters
     * @param int    $count   Sidebar length.
     * @param string $heading Sidebar heading, '' for the default.
     * @return array{head:string,grid:string,side:string}
     */
    public function render_combined_parts( $month, $filters, $count, $heading = '' ) {
        $month = $this->normalize_month( $month );
        return array(
            'head' => $this->render_month_head( $month ),
            // false: the head spans both halves, so the grid never draws one.
            'grid' => $this->render_month_grid( $month, $filters, false ),
            'side' => $this->render_sidebar( $filters, $count, ( '' !== $heading ? $heading : null ), $month ),
        );
    }

    /**
     * The month name, with the navigation either side.
     *
     * ITS OWN METHOD SO THE COMBINED MODE CAN PUT IT ACROSS BOTH HALVES.
     * In every other mode render_month_grid() emits it in place, exactly
     * where it always was. One renderer either way, so the two cannot
     * drift into showing different controls for the same month.
     *
     * @param string $month Y-m.
     * @return string
     */
    public function render_month_head( $month ) {
        $month  = $this->normalize_month( $month );
        $grid   = $this->month_grid_days( $month );
        $prefix = substr( $grid['first'], 0, 7 );

        ob_start();
        ?>
            <?php
            /*
             * THE MONTH NAME IS THE HEADING OF THE WHOLE CALENDAR (3.45.0).
             *
             * Centred, with a control either side, rather than pushed left with
             * a date range and a count crowded around it. In the combined mode
             * this row spans both halves, so it reads as governing the grid AND
             * the list beneath it rather than labelling the grid alone.
             *
             * THE EVENT COUNT IS GONE. "29 events coming up" beside a grid that
             * is already showing them is a number nobody needs and it competed
             * with the month name for the same glance. The caption below still
             * carries it, because a screen reader arriving at the table has no
             * grid to look at. The list view's own count is untouched.
             *
             * NO PREVIOUS BUTTON ON THE FLOOR. The current month is as far back
             * as a public calendar goes, so on it there is nothing to point at
             * and the control is absent rather than disabled: a dead button is
             * a promise the calendar cannot keep.
             */
            $at_floor = $this->is_floor_month( $prefix );
            ?>
            <div class="uc-month-head<?php echo $at_floor ? ' is-floor' : ''; ?>">
                <?php
                /*
                 * THE THREE CONTROLS ARE ONE GROUP (3.80.0).
                 *
                 * 3.45.0 put previous at the far left and Today and next at the
                 * far right so the month name could be centred between them. On
                 * a 1200px block that is 900px of travel between the two
                 * buttons somebody uses alternately, and paging back and forth
                 * through a schedule means crossing the calendar every time.
                 *
                 * They are one segmented control now, kept together, with the
                 * month name to their left. The centring goes with it, and that
                 * is the trade: a name that sits left is read just as easily,
                 * and the three controls being reachable without moving the
                 * pointer is worth more than symmetry.
                 *
                 * THE EMPTY BACK CELL IS GONE WITH IT. It existed so the
                 * heading would not jump sideways on the floor month, and there
                 * is nothing to jump now: the group is anchored right whether
                 * or not it holds two buttons or three.
                 */
                ?>
                <div class="uc-month-heading">
                    <?php
                    /*
                     * THE MONTH NAME AND NOTHING ELSE (3.45.1).
                     *
                     * The line under it read "26 Jul to 5 Sep, 16 events": the
                     * grid's full span, which runs into the neighbouring months
                     * because the grid starts on a Sunday, plus a count. It
                     * explained the greyed cells at each end, which is a
                     * question nobody asks, and it put two more things beside
                     * the one word this row exists to say.
                     *
                     * The span is still in the table's caption, where a screen
                     * reader meets it before the grid, and the greyed cells are
                     * marked as outside the month in the markup itself.
                     */
                    ?>
                    <h3 class="uc-month-label" aria-live="polite"><?php echo esc_html( $grid['label'] ); ?></h3>
                </div>

                <?php
                /*
                 * PREVIOUS, TODAY, NEXT, IN THAT ORDER, which is the order they
                 * mean: back, here, forward. Today is between them rather than
                 * beside them, so the group reads as one axis.
                 *
                 * NO PREVIOUS BUTTON ON THE FLOOR MONTH, unchanged from 3.45.0:
                 * the current month is as far back as a public calendar goes,
                 * and a dead button is a promise the calendar cannot keep.
                 * Today goes with it, because on the floor month Today is where
                 * you already are.
                 *
                 * THE CHEVRONS ARE sfaf_icon() PATHS, NOT &lsaquo; AND &rsaquo;
                 * Those are TEXT: they take the host's font, its weight and its
                 * line box, so their size and their vertical centring were
                 * whatever sfaf.org's body font happened to give them. An
                 * inline SVG is the same shape at the same size on every host,
                 * which is the whole reason this project has an icon set.
                 *
                 * ONE GLYPH, ROTATED, NOT TWO. That is what the icon set's own
                 * note on 'chevron' says to do, and it is also the reason not
                 * to add a 'chevron-left': the map is one list and the category
                 * icon picker is curated from it, so a key added for a
                 * navigation button would turn up as something a manager could
                 * choose for a programme.
                 */
                ?>
                <div class="uc-month-nav-group">
                    <?php if ( ! $at_floor ) : ?>
                        <button type="button" class="uc-month-nav uc-month-prev" data-goto="<?php echo esc_attr( $grid['prev'] ); ?>"
                                aria-label="Previous month"><?php
                            echo sfaf_icon( 'chevron', array( 'size' => '18px' ) );
                        ?></button>
                        <button type="button" class="uc-month-nav uc-month-today" data-goto="<?php echo esc_attr( current_time( 'Y-m' ) ); ?>">Today</button>
                    <?php endif; ?>
                    <button type="button" class="uc-month-nav uc-month-next" data-goto="<?php echo esc_attr( $grid['next'] ); ?>"
                            aria-label="Next month"><?php
                        echo sfaf_icon( 'chevron', array( 'size' => '18px' ) );
                    ?></button>
                </div>
            </div>

        <?php
        return ob_get_clean();
    }

    /**
     * The month grid itself.
     *
     * A REAL TABLE, not a grid of divs. A month is tabular data — seven named
     * columns, one row per week — and a table gives a screen reader the column
     * headers, row structure and navigation commands for free. Day cells carry
     * a roving tabindex so the whole month is reachable with the arrow keys.
     *
     * VARIABLE ROW HEIGHT falls out of using a table: a cell with three events
     * makes its row taller, and the other cells in that row grow with it,
     * because that is what table rows do. No "+N more" truncation, no fixed
     * cell height, and no clipping.
     *
     * @param string $month
     * @param array  $filters
     * @return string
     */
    public function render_month_grid( $month, $filters, $with_head = true ) {
        $data  = $this->month_grid_data( $month, $filters );
        $grid  = $data['grid'];
        $today = current_time( 'Y-m-d' );
        $prefix = substr( $grid['first'], 0, 7 );

        $day_names  = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
        $day_short  = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
        $day_letter = array( 'S', 'M', 'T', 'W', 'T', 'F', 'S' );

        ob_start();
        ?>
        <div class="uc-month" data-month="<?php echo esc_attr( $prefix ); ?>"
             data-prev="<?php echo esc_attr( $grid['prev'] ); ?>"
             data-next="<?php echo esc_attr( $grid['next'] ); ?>"
             data-rows="<?php echo (int) $grid['rows']; ?>"
             data-today="<?php echo esc_attr( $today ); ?>">

            <?php
            /*
             * THE HEAD IS RENDERED SOMEWHERE ELSE IN THE COMBINED MODE, which
             * is why it is a method and takes a flag. There it spans both
             * halves, so it reads as governing the calendar rather than the
             * grid alone. See render_month_head().
             */
            if ( $with_head ) {
                echo $this->render_month_head( $month );
            }
            ?>
            <?php // The wrapper carries the outer border and radius: a
                  // border-collapse table cannot round its own corners. ?>
            <div class="uc-month-wrap">
            <table class="uc-month-grid" role="grid">
                <caption class="uc-visually-hidden"><?php
                    echo esc_html( sprintf(
                        /* translators: 1: month name, 2: event count phrase */
                        '%1$s, %2$s. Use the arrow keys to move between days.',
                        $grid['label'],
                        sprintf( _n( '%d event this month', '%d events this month', (int) $data['in_month'] ), (int) $data['in_month'] )
                    ) );
                ?></caption>
                <thead>
                    <tr>
                        <?php foreach ( $day_names as $i => $name ) : ?>
                            <th scope="col">
                                <abbr title="<?php echo esc_attr( $name ); ?>">
                                    <span class="uc-day-name-full" aria-hidden="true"><?php echo esc_html( $day_short[ $i ] ); ?></span>
                                    <span class="uc-day-name-min" aria-hidden="true"><?php echo esc_html( $day_letter[ $i ] ); ?></span>
                                    <span class="uc-visually-hidden"><?php echo esc_html( $name ); ?></span>
                                </abbr>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $first_focus = true;
                    foreach ( array_chunk( $grid['days'], 7 ) as $week ) :
                        ?>
                        <tr>
                        <?php foreach ( $week as $day ) :
                            $ids       = isset( $data['events'][ $day ] ) ? $data['events'][ $day ] : array();
                            $in_month  = ( substr( $day, 0, 7 ) === $prefix );
                            $is_today  = ( $day === $today );
                            $day_num   = (int) substr( $day, 8, 2 );
                            // Through the formatter: this was 'l j F Y', which is
                            // "Monday 4 August 2026", day-month-year rather than
                            // AP's order. It is the cell's screen-reader label,
                            // so it is read aloud on every arrow-key move.
                            $readable  = sfaf_ap_date( $day, 'full' );

                            $closed_row = isset( $data['closed'][ $day ] ) ? $data['closed'][ $day ] : null;

                            $classes = array( 'uc-day' );
                            if ( ! $in_month ) { $classes[] = 'uc-day-out'; }
                            if ( $is_today )   { $classes[] = 'uc-day-today'; }
                            if ( empty( $ids ) ) { $classes[] = 'uc-day-empty'; }
                            if ( $closed_row )  { $classes[] = 'uc-day-closed'; }

                            // Roving tabindex: one cell in the grid is in the tab
                            // order, the arrow keys move focus between the rest.
                            $tabindex = 0;
                            if ( $first_focus && ( $is_today || $day === $grid['first'] ) ) {
                                $tabindex    = 0;
                                $first_focus = false;
                            } else {
                                $tabindex = -1;
                            }

                            // Never colour alone: today and out-of-month days each
                            // carry a word for screen readers and a shape in CSS.
                            $label = $readable . '. ' . ( empty( $ids )
                                ? 'No events'
                                : count( $ids ) . ( 1 === count( $ids ) ? ' event' : ' events' ) );
                            if ( $is_today )  { $label = 'Today, ' . $label; }
                            if ( ! $in_month ) { $label .= '. Outside ' . $grid['label']; }
                            // Before the count, because it is the more important
                            // fact about the day and colour must never be the
                            // only thing carrying it.
                            /*
                             * THE NOTE GOES IN THE SPOKEN LABEL IN FULL (3.84.0),
                             * and it is appended here rather than folded into
                             * SFAF_Closures::text(). text() is the closure's name
                             * sentence and the admin table shows it as such; a
                             * note belongs to the day being described, which is
                             * this call site's question and not text()'s.
                             *
                             * This is what makes the cell's shortening safe: the
                             * visible text may be cut, the spoken one never is.
                             */
                            if ( $closed_row ) {
                                $closed_said = SFAF_Closures::text( $closed_row );
                                $closed_full = SFAF_Closures::note( $closed_row );
                                if ( '' !== $closed_full ) {
                                    $closed_said .= '. ' . $closed_full;
                                }
                                $label = $closed_said . '. ' . $label;
                            }
                            ?>
                            <td class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
                                data-day="<?php echo esc_attr( $day ); ?>"
                                data-count="<?php echo (int) count( $ids ); ?>"
                                tabindex="<?php echo (int) $tabindex; ?>"
                                role="gridcell"
                                aria-label="<?php echo esc_attr( $label ); ?>">
                                <span class="uc-day-num" aria-hidden="true"><?php echo (int) $day_num; ?></span>
                                <?php if ( $closed_row ) : ?>
                                    <?php
                                    /*
                                     * NOT A LINK, AND NOT AN EVENT. A span with
                                     * no href and no data-day handling of its
                                     * own: there is nothing to open, and a
                                     * closure that could be clicked would be a
                                     * closure somebody expects a page for.
                                     * aria-hidden because the cell's own label
                                     * already reads it.
                                     */
                                    ?>
                                    <?php
                                    /*
                                     * TWO LINES, AND THE WORD IS THE LARGER.
                                     * "CLOSED" is what somebody scanning the
                                     * grid needs; the closure's own name is
                                     * the detail under it. The uppercase is
                                     * CSS, not markup, so a screen reader
                                     * reading this would say the word rather
                                     * than spell it — though none does, since
                                     * the cell's own aria-label already
                                     * carries the whole sentence.
                                     *
                                     * The name line is dropped by CSS below
                                     * 560px, where the cell has no room for
                                     * it. Nothing is lost: the aria-label
                                     * still has it and the day panel names it
                                     * in full.
                                     */
                                    $closed_name = SFAF_Closures::name( $closed_row );
                                    /*
                                     * THE NOTE, SHORTENED FOR THE CELL (3.84.0).
                                     * note_short() cuts on a word boundary and
                                     * marks the cut with an ellipsis; the title
                                     * carries the whole thing, and the aria-label
                                     * above already has it in full, so nothing is
                                     * only available to a mouse.
                                     *
                                     * Dropped below 560px by the same rule as the
                                     * name line, where the cell has no room. The
                                     * day panel and the list card both still
                                     * carry it.
                                     */
                                    $closed_note  = SFAF_Closures::note( $closed_row );
                                    $closed_brief = SFAF_Closures::note_short( $closed_row );
                                    ?>
                                    <span class="uc-day-closed-mark" aria-hidden="true">
                                        <span class="uc-closed-word">Closed</span>
                                        <?php if ( '' !== $closed_name ) : ?>
                                            <span class="uc-closed-name"><?php echo esc_html( $closed_name ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( '' !== $closed_brief ) : ?>
                                            <span class="uc-closed-note" title="<?php echo esc_attr( $closed_note ); ?>"><?php echo esc_html( $closed_brief ); ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( ! empty( $ids ) ) : ?>
                                    <?php
                                    /*
                                     * DOTS ARE THE MOBILE TREATMENT ONLY.
                                     *
                                     * They are rendered here because the phone
                                     * layout needs them and re-fetching a month
                                     * on resize would be absurd, but CSS hides
                                     * them above 640px. Before 2.10.1 the
                                     * stylesheet that did the hiding never
                                     * reached the embed, so they showed up at
                                     * desktop width as a stray dot after every
                                     * date. If you are changing this, the rule
                                     * to check is `.uc-day-dots { display:none }`
                                     * in the desktop block, not this markup.
                                     */
                                    ?>
                                    <span class="uc-day-dots" aria-hidden="true"><?php
                                        foreach ( $ids as $id ) {
                                            $color = sfaf_event_category_color( $id );
                                            echo '<span class="uc-day-dot" style="background:' . esc_attr( $color ) . '"></span>';
                                        }
                                    ?></span>
                                    <?php
                                    /*
                                     * EVERY EVENT ON THE DAY, WITH NO CAP AND NO
                                     * "+2 MORE".
                                     *
                                     * This loop has never truncated and still
                                     * does not. The cell grows to fit and the
                                     * week's row grows with it, which is a
                                     * decision recorded on the td rule: a
                                     * minimum height, never a maximum. A busy
                                     * day makes the grid taller, and that is
                                     * the honest rendering of a busy day.
                                     */
                                    ?>
                                    <ul class="uc-day-events">
                                        <?php foreach ( $ids as $id ) :
                                            $start  = (string) get_post_meta( $id, '_uc_start_time', true );
                                            $shades = sfaf_category_shades( sfaf_event_category_color( $id ) );
                                            /* See render_event_card() for why the word rather than a
                                             * colour, and why this is not the closure treatment. The
                                             * mobile day panel clones this markup, so marking the row
                                             * here reaches that surface without a second renderer. */
                                            $ev_off = SFAF_Cancellation::is_cancelled( $id );
                                            ?>
                                            <li class="uc-day-event<?php echo $ev_off ? ' is-cancelled' : ''; ?>">
                                                <?php
                                                /*
                                                 * INK AND TINT, NOT THE RAW HUE.
                                                 * --cat-ink draws the ring and
                                                 * --cat-media fills the
                                                 * placeholder behind the icon.
                                                 * See sfaf_day_event_thumb().
                                                 */
                                                ?>
                                                <?php
                                                /*
                                                 * WHAT THE HOVER PREVIEW READS (3.75.0).
                                                 *
                                                 * ATTRIBUTES ON THE LINK, NOT A HIDDEN BLOCK PER TILE.
                                                 * A busy month here runs to sixty events, and sixty
                                                 * hidden panels with sixty images in them is sixty
                                                 * images the browser may decide to fetch for a preview
                                                 * nobody opens. One shared panel filled from these on
                                                 * hover fetches exactly the image being looked at.
                                                 *
                                                 * EVERY VALUE IS ALREADY ON THE PAGE SOMEWHERE, so
                                                 * nothing here is a second source of truth: the title
                                                 * is the title beside it, the image is the one
                                                 * sfaf_day_event_thumb() drew, and the date, time and
                                                 * place are the same helpers the event page uses.
                                                 *
                                                 * THE PREVIEW IS AN ENHANCEMENT AND THESE ARE INERT
                                                 * WITHOUT IT. With no script, or on a touch device
                                                 * where nothing hovers, this is a link with some
                                                 * data attributes on it and behaves exactly as it
                                                 * did.
                                                 */
                                                $pv_time = sfaf_ap_time_range(
                                                    $start,
                                                    (string) get_post_meta( $id, '_uc_end_time', true )
                                                );
                                                ?>
                                                <a href="<?php echo esc_url( sfaf_event_link( $id ) ); ?>"<?php echo sfaf_new_tab_attrs(); ?>
                                                   data-uc-preview
                                                   data-uc-pv-title="<?php echo esc_attr( get_the_title( $id ) ); ?>"
                                                   data-uc-pv-img="<?php echo esc_url( sfaf_event_image_url( $id ) ); ?>"
                                                   data-uc-pv-date="<?php echo esc_attr( sfaf_ap_date( (string) get_post_meta( $id, '_uc_event_date', true ), 'full' ) ); ?>"
                                                   data-uc-pv-time="<?php echo esc_attr( $pv_time ); ?>"
                                                   data-uc-pv-place="<?php echo esc_attr( sfaf_event_location_short( $id ) ); ?>"
                                                   <?php echo $ev_off ? ' data-uc-pv-off="Cancelled"' : ''; ?>
                                                   style="--cat-ink: <?php echo esc_attr( $shades['ink'] ); ?>; --cat-media: <?php echo esc_attr( $shades['media'] ); ?>">
                                                    <?php echo sfaf_day_event_thumb( $id ); ?>
                                                    <span class="uc-day-event-text">
                                                        <?php if ( $ev_off ) : ?>
                                                            <span class="uc-day-event-off">Cancelled</span>
                                                        <?php endif; ?>
                                                        <span class="uc-day-event-title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
                                                        <?php if ( '' !== $start ) : ?>
                                                            <span class="uc-day-event-time"><?php echo esc_html( sfaf_ap_time( $start ) ); ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php echo sfaf_new_tab_note(); ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php
            // A month with nothing in it is common and is not a failure: whole
            // months here have no Friday or Sunday events, and a calendar
            // opened at the end of a month can be genuinely bare. Saying so
            // outright is what stops forty-two empty cells reading as a broken
            // grid, and it is also what a failed month must NOT look like,
            // which is why the error box is separate and says something else.
            if ( 0 === (int) $data['in_month'] ) :
                ?>
                <p class="uc-month-empty">Nothing scheduled in <?php echo esc_html( $grid['label'] ); ?>. Use the arrows to look at another month.</p>
            <?php endif; ?>

            <?php // Mobile: the grid above collapses to date + dots, and the
                  // selected day's events render here as full list cards. ?>
            <div class="uc-month-day-panel" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Sidebar view: the next N occurrences, for a narrow column.
     *
     * OCCURRENCES, NOT PROGRAMS. A weekly group appearing four times in ten
     * rows is expected: each occurrence is its own post with its own date, and
     * collapsing them would hide the next actual date somebody can turn up to.
     *
     * @param array $filters
     * @param int   $count
     * @return string
     */
    /**
     * The sidebar's heading, and the three-state rule behind it.
     *
     * THERE ARE TWO DIFFERENT KINDS OF EMPTY HERE and they must not collapse
     * into one:
     *
     *   null  the caller never mentioned a heading  -> the default
     *   ''    the caller asked for no heading       -> nothing rendered
     *   'X'   the caller wrote one                  -> X
     *
     * Every existing embed on sfaf.org was pasted before this parameter
     * existed, so every one of them is the null case and has to keep working
     * with a sensible heading rather than suddenly growing a blank gap or an
     * unwanted line. And somebody who deliberately clears the field has asked
     * for silence, which a default would override.
     *
     * That is why nothing in this chain uses '' as its default: the shortcode
     * attribute defaults to null, the REST argument declares no default at all
     * so an absent parameter stays null, and embed.js only sends the parameter
     * when the block actually carries the attribute.
     *
     * @param string|null $raw
     * @return string '' means render no heading.
     */
    public static function sidebar_heading( $raw ) {
        if ( null === $raw ) {
            return 'Upcoming event dates';
        }
        // Trimmed, so a field containing only spaces means the same as a field
        // somebody cleared. Length-capped because this arrives over a public
        // endpoint and a heading is one line.
        return substr( trim( sanitize_text_field( (string) $raw ) ), 0, 80 );
    }

    /**
     * How many upcoming dates a sidebar shows.
     *
     * ONE PLACE, because there are two callers now: the sidebar display mode and
     * the combined mode's right-hand column, which is the same renderer. A
     * default of 10 written twice is a default that drifts.
     *
     * @param mixed $raw The block's count attribute.
     * @return int
     */
    public static function sidebar_count( $raw ) {
        $count = (int) $raw;
        return ( $count > 0 ) ? $count : 10;
    }

    public function render_sidebar( $filters, $count, $heading = null, $month = '' ) {
        $count = max( 1, min( 50, (int) $count ) );

        /*
         * BOUND TO A MONTH, OR NOT, AND THE CALLER DECIDES (3.45.0).
         *
         * The sidebar display mode is still "what is coming up" and passes no
         * month. The combined mode passes the month its grid is showing, which
         * is what makes the two halves one calendar rather than two views that
         * happen to sit beside each other: navigating to October moves both.
         *
         * THIS IS THE ONLY RENDERER THAT BINDS (3.45.2). The bound month is a
         * parameter this method sets, and the list never sets it. It used to be
         * a filter any caller could fill in by naming a key, and the block
         * filled it in for every mode; see normalize_filters().
         */
        $month = ( '' === $month ) ? '' : $this->normalize_month( $month );
        if ( '' !== $month ) {
            $filters['bound_month'] = $month;
        }

        $events = $this->render_events( $count, 1, $filters, 'sidebar' );
        $head   = self::sidebar_heading( $heading );

        // "See all" points at the calendar on this site, carrying the same
        // filter so the visitor lands on the programme they were looking at.
        $filters = $this->normalize_filters( $filters );
        $all_url = SFAF_Embed::calendar_url();
        $qs      = array_filter( array(
            'uc_category'  => $filters['category'],
            'uc_organizer' => $filters['organizer'],
            'uc_venue'     => $filters['venue'],
            'uc_series'    => $filters['series'] ? $filters['series'] : '',
        ) );
        if ( ! empty( $qs ) ) {
            $all_url = add_query_arg( $qs, $all_url );
        }

        ob_start();
        ?>
        <div class="uc-sidebar" data-count="<?php echo (int) $count; ?>">
            <?php if ( '' !== $head ) : ?>
                <?php // An h3, not an h2: this block is embedded inside somebody
                      // else's page and must not claim a level above the heading
                      // of the section it was pasted into. ?>
                <h3 class="uc-sidebar-heading"><?php echo esc_html( $head ); ?></h3>
            <?php endif; ?>
            <div class="uc-sidebar-list">
                <?php if ( '' !== $events['html'] ) : ?>
                    <?php echo $events['html']; ?>
                <?php else : ?>
                    <?php // REQUIRED EMPTY STATE. A programme on hiatus must not
                          // leave a blank box on a live page: say so, and still
                          // offer the way through to everything else. ?>
                    <p class="uc-sidebar-empty"><?php
                        echo esc_html( '' !== $month ? 'Nothing scheduled this month.' : 'No upcoming dates scheduled just now.' );
                    ?></p>
                <?php endif; ?>
            </div>
            <?php if ( '' !== $month ) { echo $this->render_month_tabs( $month, $filters ); } ?>
            <a class="uc-sidebar-all" href="<?php echo esc_url( $all_url ); ?>">See all events &rarr;</a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * The neighbouring months, with what is on in each.
     *
     * UNDER THE LIST, BECAUSE THAT IS WHERE SOMEBODY RUNS OUT. They have read
     * this month; the question they have then is what is next, and the count
     * answers it before they spend a click finding out it is empty.
     *
     * THE NEXT MONTH ALWAYS SHOWS. The previous one appears only once somebody
     * has moved forward, because the current month is the floor and a control
     * pointing at nothing is worse than no control. So on the current month
     * there is one tab, and a month or more forward there are two and somebody
     * can walk in either direction.
     *
     * These are buttons rather than links, and carry the same data-goto the
     * month navigation uses, so one handler moves the whole view and there is
     * no second definition of what changing month means.
     *
     * @param string $month Y-m, already normalized.
     * @param array  $filters
     * @return string
     */
    private function render_month_tabs( $month, $filters ) {
        $prev = $this->month_step( $month, -1 );
        $next = $this->month_step( $month, 1 );

        $tabs = array();
        if ( '' !== $prev ) {
            $tabs[] = array( 'month' => $prev, 'dir' => 'prev' );
        }
        if ( '' !== $next ) {
            $tabs[] = array( 'month' => $next, 'dir' => 'next' );
        }
        if ( empty( $tabs ) ) {
            return '';
        }

        ob_start();
        ?>
        <div class="uc-month-tabs" role="group" aria-label="Other months">
            <?php foreach ( $tabs as $tab ) :
                $count = $this->month_event_count( $tab['month'], $filters );
                $label = sfaf_ap_date( $tab['month'] . '-01', 'month_year' );
                ?>
                <button type="button"
                        class="uc-month-tab uc-month-tab-<?php echo esc_attr( $tab['dir'] ); ?>"
                        data-goto="<?php echo esc_attr( $tab['month'] ); ?>">
                    <span class="uc-month-tab-name"><?php echo esc_html( $label ); ?></span>
                    <span class="uc-month-tab-count"><?php echo (int) $count; ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * One sidebar row: thumbnail, title, then date and time underneath.
     *
     * NO ACCENT BAR. Every row carried a 3px left border in its own category
     * colour. It is gone in 3.17.0 and nothing replaces it, because in the
     * placement this mode is actually for, embedded on a programme or a series
     * page, every event in the list is the same programme: the stripe was ten
     * colours saying one thing, which is the kaleidoscope the brand guide warns
     * against (p.18) doing no work at all. A row is now a picture, a title and
     * a line of detail, separated from the next by a hairline.
     *
     * THE THUMBNAIL IS THE EVENT'S OWN IMAGE, or the branded category tile when
     * it has none, which is the same pair the list card uses. That is where the
     * category signal went: into the picture, where it is already carrying its
     * weight, rather than into a bar beside it.
     *
     * @param int $post_id
     * @return string
     */
    private function render_sidebar_row( $post_id ) {
        $date  = (string) get_post_meta( $post_id, '_uc_event_date', true );
        $start = (string) get_post_meta( $post_id, '_uc_start_time', true );
        $end   = (string) get_post_meta( $post_id, '_uc_end_time', true );
        $slugs = implode( ' ', wp_list_pluck( sfaf_event_categories( $post_id ), 'slug' ) );

        /*
         * One quiet second line: "Tue, Aug 4 · 6-7:30 pm".
         *
         * THE TWO HALVES ARE SEPARATE ELEMENTS, WHICH IS A LAYOUT DECISION AND
         * NOT A TIDINESS ONE. As one string this line breaks wherever the
         * column runs out, so in a narrow placement it wrapped as "Tue, Aug 4 ·
         * 6-7:30" / "pm" or "Tue, / Aug 4 · 6-7:30 pm": a date split from its
         * month, or a clock split from its meridiem. With the date and the
         * clock each in their own span the CSS can hold each one whole and put
         * the only break between them, and at the narrowest widths stack them
         * and drop the separator. See .uc-when-date in calendar.css.
         *
         * Both halves are optional and the separator only appears when both are
         * there, so an undated event does not print a stray dot.
         */
        $when  = ( '' !== $date )
            ? sfaf_ap_date( $date, 'weekday' ) . ', ' . sfaf_ap_date( $date, 'short' )
            : '';
        $clock = sfaf_ap_time_range( $start, $end );

        /* The third public surface. See render_event_card() for why the word
         * and not the colour. This row is one line of text beside a 44px
         * thumbnail, so the word goes above the title where the card puts it
         * rather than competing with the date line below. */
        $off = SFAF_Cancellation::is_cancelled( $post_id );

        ob_start();
        ?>
        <a class="uc-sidebar-row<?php echo $off ? ' is-cancelled' : ''; ?>" href="<?php echo esc_url( sfaf_event_link( $post_id ) ); ?>"<?php echo sfaf_new_tab_attrs(); ?>
           data-category="<?php echo esc_attr( $slugs ); ?>">
            <span class="uc-sidebar-thumb"><?php echo sfaf_thumb_media( $post_id ); ?></span>
            <span class="uc-sidebar-body">
                <?php if ( $off ) : ?>
                    <span class="uc-sidebar-off">Cancelled</span>
                <?php endif; ?>
                <span class="uc-sidebar-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
                <?php if ( '' !== $when || '' !== $clock ) : ?>
                    <span class="uc-sidebar-when">
                        <?php if ( '' !== $when ) : ?>
                            <span class="uc-when-date"><?php echo esc_html( $when ); ?></span>
                        <?php endif; ?>
                        <?php if ( '' !== $when && '' !== $clock ) : ?>
                            <span class="uc-when-sep" aria-hidden="true">·</span>
                        <?php endif; ?>
                        <?php if ( '' !== $clock ) : ?>
                            <span class="uc-when-time"><?php echo esc_html( $clock ); ?></span>
                        <?php endif; ?>
                    </span>
                <?php else : ?>
                    <span class="uc-sidebar-when"><span class="uc-when-date">Date to be confirmed</span></span>
                <?php endif; ?>
                <?php
                /*
                 * THE VENUE ON ITS OWN LINE (3.45.0).
                 *
                 * NOT APPENDED TO THE DATE AND TIME. That line is already two
                 * elements held apart so a clock cannot be split from its
                 * meridiem, and a third clause on it would break wherever the
                 * column ran out. The short form is used, so this is "Strut"
                 * rather than the full postal address: on a row 44px tall
                 * beside a thumbnail, the street and the zip are the longest
                 * thing present and carry the least.
                 *
                 * It is left out entirely when there is nothing to say, so an
                 * online event does not get a blank line.
                 */
                $where = sfaf_event_location_short( $post_id );
                ?>
                <?php if ( '' !== $where ) : ?>
                    <span class="uc-sidebar-where"><?php echo esc_html( $where ); ?></span>
                <?php endif; ?>
            </span>
            <?php echo sfaf_new_tab_note(); ?>
        </a>
        <?php
        return ob_get_clean();
    }

    /**
     * How many groups is still a row of pills rather than a list to open.
     *
     * Judged on the count that will actually render for THIS category, not on
     * how many the site has: seven categories of three groups each are seven
     * comfortable rows, and one category of twenty is the only one that needs
     * folding away.
     */
    const GROUP_PILL_LIMIT = 6;

    /**
     * The Groups row.
     *
     * PILLS OR A FOLDED LIST, decided here on the real count. Up to six is a row
     * somebody can read at a glance; beyond that a row wraps to three lines and
     * stops being scannable, so it becomes a disclosure holding the same
     * checkboxes. Both are multi-select, because asking for two groups means
     * "either of these", and both post the same parameter.
     *
     * THE WORD "SERIES" DOES NOT APPEAR. A visitor should not have to know that
     * the calendar has a taxonomy called that; they are asking for a group whose
     * name they already know from the poster.
     *
     * @param WP_Term[] $available
     * @param string    $active Slug list.
     */
    /**
     * Which organizers each group's events actually name.
     *
     * THIS IS RELATIONSHIP (B), AND (A) WAS REJECTED ON THE DATA. The obvious
     * reading of "narrow the groups by organizer" is that a group belongs to an
     * organizer. Nothing stores that: a series carries a description, an image
     * and a FAQ set, and no organizer, because an organizer is a property of
     * the EVENTS in it. So the relationship is derived from the events, and a
     * group appears under every organizer that runs anything in it. That also
     * handles collaboration correctly, which (a) could not: Strut Community
     * Events holds events from two organizers and belongs under both.
     *
     * A GROUP WITH NO ORGANIZERED EVENTS GETS AN EMPTY LIST, and the picker
     * treats an empty list as "always show" rather than "never matches". That
     * is the difference between missing information and a statement, and on the
     * current data it is the commonest case rather than an edge: around a third
     * of the series have no organizer on any of their events while Mark is
     * still setting them by hand. Hiding those the moment somebody picks an
     * organizer would empty most of the list and read as a broken control. As
     * the events gain organizers these groups start narrowing on their own,
     * with nothing here to change.
     *
     * PUBLISHED ONLY, because this decides what a visitor is offered and a
     * draft is not on their calendar.
     *
     * @param WP_Term[] $groups
     * @return array group slug => organizer slugs
     */
    private function group_organizer_map( $groups ) {
        $map = array();
        if ( empty( $groups ) ) {
            return $map;
        }

        /* One cache entry for the whole map. It is read on every render of a
         * block that shows the picker, and it changes only when an event's
         * organizers or series change, which is what the embed cache generation
         * already moves on. */
        $key    = 'sfaf_group_org_map_' . md5( implode( ',', wp_list_pluck( $groups, 'slug' ) ) );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        foreach ( $groups as $term ) {
            /*
             * PRIVATE EVENTS ARE EXCLUDED, and the committed guard is what said
             * so: private-events-test.php refused this query on the first
             * attempt because it named uc_event and did not exclude. It was
             * right to. This decides what a PUBLIC visitor is offered, and a
             * private event's organizer appearing in that list would say that
             * somebody is running something under that name on a screen where
             * the event itself is deliberately unreachable.
             */
            $args = array(
                'post_type'      => 'uc_event',
                'post_status'    => 'publish',
                'posts_per_page' => 200,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => array( array(
                    'taxonomy' => SFAF_Series::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => (int) $term->term_id,
                ) ),
            );
            /* It MUTATES rather than returns, so this is two statements and not
             * one. Wrapping the literal in the call is a fatal, by reference. */
            SFAF_Privacy::exclude( $args );
            $ids = get_posts( $args );

            $slugs = array();
            if ( ! empty( $ids ) ) {
                $orgs = wp_get_object_terms( $ids, 'uc_organizer', array( 'fields' => 'slugs' ) );
                if ( ! is_wp_error( $orgs ) ) {
                    $slugs = array_values( array_unique( $orgs ) );
                }
            }
            $map[ $term->slug ] = $slugs;
        }

        set_transient( $key, $map, 10 * MINUTE_IN_SECONDS );
        return $map;
    }

    /**
     * Organizers and groups, in one dropdown, in the top layer.
     *
     * WHY ONE CONTROL. Two dropdowns side by side asked two questions that are
     * the same question to a visitor: "whose events are these". Programa Latino
     * exists as an organizer AND as a series, so the two lists could show what
     * looks like the same name twice with nothing to tell them apart. The
     * headings are what tell them apart, which is why this is grouped rather
     * than one list of thirty-four names.
     *
     * THIS MAKES GROUPS A FIRST-LEVEL FILTER, and that undoes a decision worth
     * naming rather than quietly reversing: render_group_row() renders nothing
     * until a category has been chosen, so that "nobody is ever looking at two
     * taxonomies at once". Merging the controls necessarily ends that, because
     * the organizer half has always been first-level. The headings carry the
     * job the staging used to do.
     *
     * THE TOP LAYER, NOT A z-index. The panel is a popover, so the browser puts
     * it in the top layer and it cannot be trapped by an ancestor that has
     * become a containing block. That is the hover preview's lesson from
     * 3.75.0 applied before it had to be learned again here: a stacking value
     * alone was not enough there and would not be enough here.
     *
     * IT WORKS WITH NO SCRIPT, AND THAT IS NEW RATHER THAN PRESERVED. The
     * filter bar has never had a no-script path: the organizer select and the
     * group checkboxes were both read by JavaScript and there was no form and
     * no submit anywhere in this file. So this is a real GET form around real
     * checkboxes with a real submit button. With script off the panel is an
     * open <details>, every box is a checkbox, and Apply reloads the page with
     * the choices in the query string. With script on, the button is hidden and
     * the choices apply as they are made.
     *
     * @param WP_Term[] $organizers
     * @param WP_Term[] $groups
     * @param string[]  $org_on
     * @param string[]  $group_on
     * @param array     $map        group slug => organizer slugs
     */
    private function render_who_picker( $organizers, $groups, $org_on, $group_on, $map ) {
        if ( empty( $organizers ) && empty( $groups ) ) {
            return;
        }

        $id    = 'uc-who-' . wp_rand( 1000, 9999 );
        $count = count( $org_on ) + count( $group_on );

        /*
         * THE CLOSED TRIGGER SAYS WHAT IS SELECTED. With thirty-four options
         * behind one press, "Organizers and groups" on a control with three
         * filters running is a control that hides its own state. Names while
         * they fit, a count after that, because six names is longer than the
         * bar and a number is still the truth.
         */
        $picked_names = array();
        foreach ( $organizers as $o ) {
            if ( in_array( $o->slug, $org_on, true ) ) { $picked_names[] = $o->name; }
        }
        foreach ( $groups as $g ) {
            if ( in_array( $g->slug, $group_on, true ) ) { $picked_names[] = $g->name; }
        }
        if ( 0 === $count ) {
            $label = 'Organizers and groups';
        } elseif ( $count <= 2 ) {
            $label = implode( ', ', $picked_names );
        } else {
            $label = $count . ' selected';
        }

        /*
         * SELECTED ITEMS SIT AT THE TOP, AND THE ORDER IS DECIDED HERE, ON THE
         * SERVER, ONCE. The list must not reorder while somebody is clicking
         * down it: the thing just ticked would move out from under the cursor
         * and the next click would land on something else. So the server emits
         * the order for the state it is rendering, and the script reorders only
         * when the panel is next opened.
         */
        $sorter = function ( $terms, $on ) {
            $sel = array();
            $rest = array();
            foreach ( $terms as $t ) {
                if ( in_array( $t->slug, $on, true ) ) { $sel[] = $t; } else { $rest[] = $t; }
            }
            return array_merge( $sel, $rest );
        };
        $organizers = $sorter( $organizers, $org_on );
        $groups     = $sorter( $groups, $group_on );
        ?>
        <div class="uc-who" data-uc-who>
            <button type="button" class="uc-who-trigger<?php echo $count ? ' active' : ''; ?>"
                    data-uc-who-trigger
                    popovertarget="<?php echo esc_attr( $id ); ?>"
                    aria-label="Filter by organizer or group">
                <span class="uc-who-label" data-uc-who-label><?php echo esc_html( $label ); ?></span>
                <?php echo sfaf_icon( 'chevron', array( 'class' => 'uc-who-chevron' ) ); ?>
            </button>

            <div class="uc-who-panel" id="<?php echo esc_attr( $id ); ?>" popover data-uc-who-panel>
                <?php if ( ! empty( $organizers ) ) : ?>
                    <p class="uc-who-heading" id="<?php echo esc_attr( $id ); ?>-org">Organizers</p>
                    <div class="uc-who-list" role="group" aria-labelledby="<?php echo esc_attr( $id ); ?>-org">
                        <?php foreach ( $organizers as $o ) : ?>
                            <label class="uc-who-opt">
                                <input type="checkbox" name="uc_org[]"
                                       value="<?php echo esc_attr( $o->slug ); ?>"
                                       data-uc-who-organizer
                                       <?php checked( in_array( $o->slug, $org_on, true ) ); ?> />
                                <span><?php echo esc_html( $o->name ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $groups ) ) : ?>
                    <p class="uc-who-heading" id="<?php echo esc_attr( $id ); ?>-grp">Groups</p>
                    <div class="uc-who-list" role="group" aria-labelledby="<?php echo esc_attr( $id ); ?>-grp" data-uc-who-groups>
                        <?php foreach ( $groups as $g ) :
                            $orgs_of = isset( $map[ $g->slug ] ) ? $map[ $g->slug ] : array(); ?>
                            <label class="uc-who-opt" data-uc-who-group-orgs="<?php echo esc_attr( implode( ' ', $orgs_of ) ); ?>">
                                <input type="checkbox" name="uc_group[]"
                                       value="<?php echo esc_attr( $g->slug ); ?>"
                                       data-uc-who-group
                                       <?php checked( in_array( $g->slug, $group_on, true ) ); ?> />
                                <span><?php echo esc_html( $g->name ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="uc-who-none" data-uc-who-none hidden>No groups match those organizers.</p>
                <?php endif; ?>

                <div class="uc-who-foot">
                    <button type="button" class="uc-who-clear" data-uc-who-clear>Clear</button>
                    <button type="submit" class="uc-who-apply" data-uc-who-apply>Apply</button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_group_row( $available, $active ) {
        if ( empty( $available ) ) {
            return;
        }

        $on   = ( '' === $active ) ? array() : explode( ',', $active );
        $many = ( count( $available ) > self::GROUP_PILL_LIMIT );
        ?>
        <div class="uc-groups<?php echo $many ? ' uc-groups-many' : ''; ?>" data-uc-groups>
            <span class="uc-groups-label" id="uc-groups-label">Groups</span>

            <?php if ( ! $many ) : ?>
                <div class="uc-groups-pills" role="group" aria-labelledby="uc-groups-label">
                    <?php foreach ( $available as $term ) :
                        $picked = in_array( $term->slug, $on, true ); ?>
                        <button type="button" class="uc-group-pill<?php echo $picked ? ' active' : ''; ?>"
                                data-uc-group="<?php echo esc_attr( $term->slug ); ?>"
                                aria-pressed="<?php echo $picked ? 'true' : 'false'; ?>">
                            <?php
                            /*
                             * THE TICK IS WHAT SAYS "AND", and it is the one
                             * difference from the category row above that is
                             * not shape or size. Categories are pick-one and
                             * their chosen chip fills; groups are pick-any and
                             * a chosen pill gets this. Rendered on every pill
                             * and shown only on a chosen one, so the markup
                             * the row folds into beyond six, which is literally
                             * checkboxes, is saying the same thing.
                             */
                            echo sfaf_icon( 'check', array( 'class' => 'uc-group-tick' ) );
                            echo esc_html( $term->name );
                            ?>
                        </button>
                    <?php endforeach; ?>
                    <?php if ( ! empty( $on ) ) : ?>
                        <button type="button" class="uc-group-clear" data-uc-group-clear>Clear</button>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <details class="uc-groups-list"<?php echo ! empty( $on ) ? ' open' : ''; ?>>
                    <summary>
                        <?php
                        echo esc_html(
                            empty( $on )
                                ? 'Choose a group (' . count( $available ) . ')'
                                : count( $on ) . ' of ' . count( $available ) . ' chosen'
                        );
                        /*
                         * The native marker is off, so this had no marker at
                         * all and read as a pill that happened to open. Same
                         * glyph as the organizer control, rotated by the same
                         * rule, so both things on this bar that open say so
                         * the same way.
                         */
                        echo sfaf_icon( 'chevron', array( 'class' => 'uc-groups-chevron' ) );
                        ?>
                    </summary>
                    <div class="uc-groups-options" role="group" aria-labelledby="uc-groups-label">
                        <?php foreach ( $available as $term ) :
                            $picked = in_array( $term->slug, $on, true ); ?>
                            <label class="uc-check uc-group-option">
                                <input type="checkbox" data-uc-group="<?php echo esc_attr( $term->slug ); ?>"
                                       <?php checked( $picked ); ?> />
                                <span><?php echo esc_html( $term->name ); ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if ( ! empty( $on ) ) : ?>
                            <button type="button" class="uc-group-clear" data-uc-group-clear>Clear all</button>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Where the visitor is, and the way back up.
     *
     * All events > Support groups > the groups chosen. Every segment to the left
     * of where they are is a control that steps back one level, because the
     * commonest thing after narrowing twice is wanting to be one level out, and
     * the alternative is hunting for whichever chip is lit.
     *
     * NAMED UP TO TWO, COUNTED AFTER THAT. Three long group names on one line
     * wrap into an unreadable tangle at the width a sidebar embed gets, and
     * "3 groups" is both shorter and truthful.
     *
     * @param string    $scope_category The block's own scope, never steppable.
     * @param string    $active_category
     * @param WP_Term[] $available
     * @param string    $active_groups
     */
    private function render_breadcrumb( $scope_category, $active_category, $available, $active_groups ) {
        if ( '' === $active_category && '' === $active_groups ) {
            return; // nothing has been narrowed, so there is nowhere to go back to
        }

        $cat_names = array();
        foreach ( explode( ',', $active_category ) as $slug ) {
            if ( '' === $slug ) {
                continue;
            }
            $term = get_term_by( 'slug', $slug, 'uc_event_category' );
            if ( $term && ! is_wp_error( $term ) ) {
                $cat_names[] = $term->name;
            }
        }

        $on         = ( '' === $active_groups ) ? array() : explode( ',', $active_groups );
        $by_slug    = array();
        foreach ( $available as $term ) {
            $by_slug[ $term->slug ] = $term->name;
        }
        $group_names = array();
        foreach ( $on as $slug ) {
            if ( isset( $by_slug[ $slug ] ) ) {
                $group_names[] = $by_slug[ $slug ];
            }
        }
        ?>
        <nav class="uc-crumbs" aria-label="Filters applied">
            <?php
            // "All events" means everything this block is about, which on a
            // scoped block is not everything on the calendar. It is still the
            // top of what a visitor can reach from here.
            ?>
            <button type="button" class="uc-crumb" data-uc-crumb="all">
                <?php echo esc_html( '' !== $scope_category ? 'All of these events' : 'All events' ); ?>
            </button>

            <?php if ( ! empty( $cat_names ) ) : ?>
                <span class="uc-crumb-sep" aria-hidden="true">&rsaquo;</span>
                <?php if ( ! empty( $group_names ) ) : ?>
                    <button type="button" class="uc-crumb" data-uc-crumb="category">
                        <?php echo esc_html( implode( ', ', $cat_names ) ); ?>
                    </button>
                <?php else : ?>
                    <span class="uc-crumb uc-crumb-here" aria-current="true"><?php echo esc_html( implode( ', ', $cat_names ) ); ?></span>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( ! empty( $group_names ) ) : ?>
                <span class="uc-crumb-sep" aria-hidden="true">&rsaquo;</span>
                <span class="uc-crumb uc-crumb-here" aria-current="true">
                    <?php echo esc_html(
                        count( $group_names ) <= 2
                            ? implode( ' and ', $group_names )
                            : count( $group_names ) . ' groups'
                    ); ?>
                </span>
            <?php endif; ?>
        </nav>
        <?php
    }

    /**
     * The visitor-facing list / calendar toggle.
     *
     * Not shown in sidebar mode: a 250px column has no room for a month grid,
     * so offering one would be a broken promise.
     *
     * @param string $view Which view is active.
     * @return string
     */
    private function render_view_toggle( $view, $home = 'calendar' ) {
        /*
         * THE CALENDAR BUTTON GOES HOME, NOT TO A FIXED VIEW (3.82.0).
         *
         * In a combined block the calendar half IS the combined layout, so the
         * button carries data-view="combined". Sending it to "calendar" would
         * collapse the block to a grid the visitor never chose and give them no
         * way back, which is a worse outcome than the missing toggle this fixes.
         *
         * The label follows the destination for the same reason: the tooltip
         * and the screen reader name have to describe where it goes.
         */
        $cal_view  = ( 'combined' === $home ) ? 'combined' : 'calendar';
        $cal_label = ( 'combined' === $home )
            ? 'Show the calendar and upcoming dates'
            : 'Show events on a calendar';

        $options = array(
            'list'    => array( 'Show events as a list', 'menu', 'list' ),
            $cal_view => array( $cal_label, 'calendar', $cal_view ),
        );

        ob_start();
        ?>
        <div class="uc-view-toggle" role="group" aria-label="Choose how events are displayed">
            <?php foreach ( $options as $key => $opt ) :
                $active = ( $key === $view );
                ?>
                <?php
                /*
                 * ICONS ONLY, WITH REAL NAMES. The label is on aria-label and
                 * title rather than on screen, so the control stays compact
                 * without becoming a mystery to a screen reader or to anyone
                 * hovering it.
                 *
                 * The pressed state is not colour: the active button is filled
                 * AND inset, and carries aria-pressed. In greyscale, in a
                 * forced-colours theme, and to assistive technology it still
                 * reads as the one that is on.
                 */
                ?>
                <button type="button" class="uc-view-btn<?php echo $active ? ' active' : ''; ?>"
                        data-view="<?php echo esc_attr( $key ); ?>"
                        title="<?php echo esc_attr( $opt[0] ); ?>"
                        aria-label="<?php echo esc_attr( $opt[0] ); ?>"
                        aria-pressed="<?php echo $active ? 'true' : 'false'; ?>">
                    <?php echo sfaf_icon( $opt[1], array( 'size' => '17px' ) ); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    /**
     * Render one page of event cards.
     *
     * This is the single rendering path behind the shortcodes, the load-more
     * AJAX handler and the public embed endpoint. An embed therefore cannot
     * drift from what the shortcode shows — there is only one renderer.
     *
     * @param int    $per_page Posts per page; 0 or less means all.
     * @param int    $paged    1-based page number.
     * @param array  $filters  category / organizer / series / venue.
     * @param string $render   'card' or 'compact'.
     * @return array{html:string,total:int,max_pages:int,page:int}
     */
    public function render_events( $per_page, $paged, $filters, $render = 'card' ) {
        $query = new WP_Query( $this->build_query_args( $per_page, $paged, $filters ) );

        // One query for every card's RSVP count rather than one per card.
        sfaf_prime_rsvp_counts( wp_list_pluck( $query->posts, 'ID' ) );

        ob_start();
        // 'none' is a caller that wants found_posts and no markup. The combined
        // mode's count line is the only one, and it has no list to fill.
        if ( 'none' !== $render ) {
            /*
             * CLOSURES ARE WOVEN IN BY DATE, and only into the full card list.
             *
             * The sidebar and the compact list are narrow, dense and read as
             * "what is coming up"; a closure card in them would be a third of
             * the column spent saying nothing is happening. The card list is
             * the one that reads as a calendar, so it is the one that says the
             * office is shut.
             *
             * Each closure is emitted before the first event that falls on or
             * after its start date, so a reader meets it where it belongs in
             * the sequence rather than in a block at the top.
             */
            $pending = ( 'card' === $render ) ? $this->closures_for( wp_list_pluck( $query->posts, 'ID' ) ) : array();

            while ( $query->have_posts() ) {
                $query->the_post();

                if ( $pending ) {
                    $this_day = (string) get_post_meta( get_the_ID(), '_uc_event_date', true );
                    while ( $pending && '' !== $this_day && $pending[0]['start'] <= $this_day ) {
                        echo $this->render_closure_card( array_shift( $pending ) );
                    }
                }

                if ( 'compact' === $render ) {
                    echo $this->render_compact_card( get_the_ID() );
                } elseif ( 'sidebar' === $render ) {
                    echo $this->render_sidebar_row( get_the_ID() );
                } else {
                    echo $this->render_event_card( get_the_ID() );
                }
            }

            // Anything left starts after the last event on this page.
            foreach ( $pending as $row ) {
                echo $this->render_closure_card( $row );
            }

            wp_reset_postdata();
        }

        return array(
            'html'      => ob_get_clean(),
            'total'     => (int) $query->found_posts,
            'max_pages' => (int) $query->max_num_pages,
            'page'      => max( 1, (int) $paged ),
        );
    }

    /**
     * A closure, as a card in a list.
     *
     * FLAT, AND NOT AN EVENT CARD WITH DIFFERENT WORDS. A separate renderer for
     * the reason PROJECT.md gives for every other one: a method with no code
     * path to a link, a registration count or a category ring cannot grow one
     * by being changed carelessly. There is no image, no time, no ring, no
     * chip, no RSVP, nothing clickable and no <a> at all.
     *
     * ONE CARD PER CLOSURE, NOT PER DAY. A four-day closure is one entry and
     * reads as one card saying the range. Four identical cards in a row would
     * be four times the noise for one fact. The month grid does the opposite
     * and marks four squares, because there a square IS a day.
     *
     * @param array $row From SFAF_Closures.
     * @return string
     */
    public function render_closure_card( $row ) {
        ob_start();
        ?>
        <?php
        /*
         * THE SAME LABEL THE GRID DRAWS, in the same two lines and the same
         * classes, so the two renderers cannot come to disagree about what a
         * closure looks like. They still answer different questions — this one
         * is a span, the grid is a day — but a reader moving between them sees
         * one treatment.
         *
         * The hatch is on the card and the text is on a solid panel inside it,
         * which is the grid cell's arrangement at a larger size.
         */
        $name = SFAF_Closures::name( $row );
        /*
         * THE NOTE IN FULL HERE (3.84.0), where the grid cell gets the short
         * form. A card has the width for a sentence and this is the surface
         * somebody reads when they want to know what a closure means for them.
         * Both surfaces read the same stored string through SFAF_Closures, so
         * they can differ in length and never in content.
         */
        $note = SFAF_Closures::note( $row );
        ?>
        <div class="uc-closure-card uc-closed-hatch" role="note">
            <span class="uc-closure-panel">
                <span class="uc-closure-label">
                    <span class="uc-closed-word">Closed</span>
                    <?php if ( '' !== $name ) : ?>
                        <span class="uc-closed-name"><?php echo esc_html( $name ); ?></span>
                    <?php endif; ?>
                </span>
                <span class="uc-closure-when"><?php echo esc_html( SFAF_Closures::when( $row ) ); ?></span>
                <?php if ( '' !== $note ) : ?>
                    <span class="uc-closure-note"><?php echo esc_html( $note ); ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * The closures that belong in a list showing these events.
     *
     * SCOPED TO WHAT THE LIST ACTUALLY SHOWS, from the first event's date to
     * the last. A list of the next twelve events covering six weeks shows the
     * closures in those six weeks; it does not show every closure ever entered,
     * which on a page of five events would be mostly closures.
     *
     * An empty list shows none. There is no window to be inside, and a page
     * that found no events answering with three closures would be answering a
     * question nobody asked.
     *
     * @param int[] $ids Event ids, in the order the list shows them.
     * @return array[]
     */
    private function closures_for( $ids ) {
        if ( empty( $ids ) ) {
            return array();
        }

        $dates = array();
        foreach ( $ids as $id ) {
            $d = (string) get_post_meta( $id, '_uc_event_date', true );
            if ( '' !== $d ) {
                $dates[] = $d;
            }
        }
        if ( empty( $dates ) ) {
            return array();
        }

        sort( $dates );
        return SFAF_Closures::spans( $dates[0], end( $dates ) );
    }

    /** Pagination control markup for the chosen style. */
    private function render_pagination( $style, $paged, $max ) {
        if ( $max <= 1 ) {
            return '';
        }
        if ( $style === 'pages' ) {
            return $this->render_page_links( $paged, $max );
        }
        if ( $style === 'infinite' ) {
            return '<div class="uc-pagination uc-pagination-infinite">'
                . '<div class="uc-infinite-sentinel" aria-hidden="true"></div>'
                . '<div class="uc-loading-indicator">Loading…</div></div>';
        }
        return '<div class="uc-pagination uc-pagination-loadmore">'
            . '<button type="button" class="uc-load-more">Load More</button></div>';
    }

    /**
     * Numbered Next/Previous page links (uses the uc_page query arg).
     *
     * In an embed these are buttons instead: the page is being rendered inside
     * a REST request, so add_query_arg() would build a link back to the REST
     * URL rather than to the host page. embed.js reads the page number off the
     * button and swaps the list contents.
     */
    private function render_page_links( $current, $max ) {
        $embed = sfaf_is_embed_context();
        $link  = function ( $page, $class, $label ) use ( $embed ) {
            if ( $embed ) {
                return '<button type="button" class="' . esc_attr( $class ) . '" data-page="' . (int) $page . '">' . $label . '</button>';
            }
            return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( 'uc_page', $page ) ) . '">' . $label . '</a>';
        };

        ob_start();
        ?>
        <nav class="uc-pagination uc-pagination-pages" aria-label="Events pagination">
            <?php if ( $current > 1 ) : ?>
                <?php echo $link( $current - 1, 'uc-page-link uc-page-prev', '&larr; Previous' ); ?>
            <?php endif; ?>
            <span class="uc-page-numbers">
                <?php for ( $i = 1; $i <= $max; $i++ ) : ?>
                    <?php if ( $i === (int) $current ) : ?>
                        <span class="uc-page-num current"><?php echo (int) $i; ?></span>
                    <?php else : ?>
                        <?php echo $link( $i, 'uc-page-num', (string) (int) $i ); ?>
                    <?php endif; ?>
                <?php endfor; ?>
            </span>
            <?php if ( $current < $max ) : ?>
                <?php echo $link( $current + 1, 'uc-page-link uc-page-next', 'Next &rarr;' ); ?>
            <?php endif; ?>
        </nav>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Shortcodes
     * ------------------------------------------------------------------- */

    /**
     * Main calendar shortcode: [sfaf_calendar]
     * Attributes: category, organizer, series, venue, per_page, show_filters, layout
     * per_page overrides the global setting; per_page="0"/"-1" shows all.
     * series takes a series parent event ID and shows that series' occurrences.
     */
    public function render_calendar( $atts ) {
        $atts = shortcode_atts( array(
            'category'     => '',
            'organizer'    => '',
            'series'       => '',
            'venue'        => '',
            // A preset search, so [sfaf_calendar s="harm reduction"] renders a
            // page of matching events rather than an empty box to type into.
            's'            => '',
            'per_page'     => '',
            'show_filters' => 'yes',
            // Which filter rows to offer: any of category, organizer, series,
            // or 'none'. Empty falls back to show_filters. See filter_rows().
            'filters'      => '',
            'layout'       => 'cards',
            /*
             * THE SAME FOUR DISPLAY MODES THE EMBED OFFERS, from the same
             * renderer. view="list" opens on the cards, view="combined" shows
             * the grid and the list side by side, and view="sidebar" renders
             * the narrow column with count="10" sizing it.
             *
             * THE DEFAULT IS THE MONTH GRID SINCE 3.75.0, AND IT WAS THE LIST.
             * The reason it was the list is written down and is a real one:
             * "real months have entire weeks with no Friday or Sunday events,
             * and an empty-looking grid is a poor first impression". That was
             * an argument about a thin calendar, and the calendar is not thin
             * any more: the import put 287 events across 32 series on it. A
             * grid is how people expect to read a month, and the toggle is
             * still there for anybody who wants the list.
             *
             * A VISITOR'S OWN CHOICE STILL WINS. embed.js remembers which view
             * somebody last pressed, per block, and a remembered choice beats
             * this. So this changes what a first visit opens on and nothing
             * about what a returning one does.
             */
            'view'         => 'calendar',
            'toggle'       => 'yes',
            /*
             * OPEN EVENTS TO THEIR SOURCE LISTING. Absent means the shipped
             * default, which is sfaf_source_links_default() and nothing else:
             * this attribute must not hold a second copy of it, or flipping the
             * default would be two edits and one of them would be forgotten.
             */
            'source_links' => '',
            'month'        => '',
            'count'        => '',
            /*
             * NULL, NOT ''. shortcode_atts returns the default only when the
             * attribute is absent, so null here is what lets
             * [sfaf_calendar view="sidebar"] take the default heading while
             * [sfaf_calendar view="sidebar" heading=""] renders none. See
             * sidebar_heading().
             */
            'heading'      => null,
        ), $atts );

        $block = $this->render_calendar_block( $atts );
        return $block['html'];
    }

    /**
     * The whole calendar block: filter bar, count, event list, pagination.
     *
     * Split out of render_calendar() so the embed endpoint can serve exactly
     * the same markup the shortcode produces, and get the paging numbers back
     * without running the query twice.
     *
     * @param array $args category, organizer, series, venue, per_page,
     *                    show_filters, layout, and an optional explicit page
     *                    (0 falls back to the uc_page query arg).
     * @return array{html:string,total:int,page:int,per_page:int,max_pages:int,has_more:bool}
     */
    public function render_calendar_block( $args = array() ) {
        $args = wp_parse_args( $args, array(
            'category'     => '',
            'organizer'    => '',
            'series'       => '',
            'venue'        => '',
            's'            => '',
            'per_page'     => '',
            'show_filters' => 'yes',
            // Which filter rows to offer: any of category, organizer, series,
            // or 'none'. Empty falls back to show_filters. See filter_rows().
            'filters'      => '',
            'layout'       => 'cards',
            'page'         => 0,
            // Same default as the shortcode above, and for the same reason. A
            // second copy that disagreed would make the same block open one way
            // through the page and another through the feed.
            'view'         => 'calendar',
            'toggle'       => 'yes',
            'month'        => '',
            'count'        => 0,
            'source_links' => '',
            // What the visitor picked, as distinct from what the block is
            // scoped to. See effective_category().
            'active_category'  => null,
            'active_organizer' => null,
            'active_groups'    => null,
        ) );

        $filters = $this->normalize_filters( $args );
        $view    = $this->normalize_view( $args['view'] );

        /*
         * WHERE THIS BLOCK'S EVENTS LINK, SET ONCE FOR THE WHOLE RENDER.
         *
         * Every renderer inside this block asks sfaf_event_link(), which reads
         * this flag, so setting it here reaches the card, the compact card, the
         * sidebar row and the month grid at once, in every display mode
         * including the combined one, without any of them being told about the
         * setting.
         *
         * SAVED AND RESTORED, not set and cleared. Two blocks on one page are
         * two renders in one request, and the second must not inherit the
         * first's choice; restoring what was there also means the embed's own
         * outer setting survives a nested call.
         */
        $prev_source_links = sfaf_source_links_flag();
        sfaf_set_source_links( $this->normalize_source_links( $args['source_links'] ) );

        /*
         * THE FILTER BAR RUNS A QUERY NOW.
         *
         * It used to hide cards in the page with JavaScript, which had the same
         * two faults the old search had and one of its own. It only ever
         * considered the events already downloaded, so on a paginated calendar
         * "Support Groups" showed the support groups on page one and reported
         * that as the total. It compared one slug on the card against one slug
         * on the button, so an event in two categories appeared under whichever
         * one happened to be printed and was invisible under the other. And the
         * count it wrote was the number of cards left visible, not the number of
         * matching events.
         *
         * Asking the server fixes all three at once, and the count changing when
         * a category is chosen is the correct behaviour rather than a side
         * effect: it is now the number of events in that category.
         */
        /* WHICH ROWS THIS BLOCK OFFERS. Resolved once, here, and read by the
         * bar below, by the data attribute the snippet carries, and by whether
         * the second level is derived at all. */
        $rows = $this->filter_rows( $args['filters'], $args['show_filters'] );

        $scope_category  = $filters['category'];
        $active_category = ( null === $args['active_category'] )
            ? $this->requested_category()
            : $this->slug_list( $args['active_category'] );

        $requested           = $active_category;
        $filters['category'] = $this->effective_category( $scope_category, $requested );

        /*
         * THE ORGANIZER, THE SAME WAY (3.50.0).
         *
         * The dropdown was a client-side stub: it rendered on this site, was
         * left out of embeds "rather than shipped dead", and had no handler in
         * either script, so choosing an organizer did nothing anywhere. A
         * toggle for a control that does nothing is furniture, so it runs a
         * real query now, through the same clamp the category gets.
         */
        $scope_organizer  = $filters['organizer'];
        $active_organizer = ( null === $args['active_organizer'] )
            ? $this->requested_organizer()
            : $this->slug_list( $args['active_organizer'] );
        $filters['organizer'] = $this->effective_organizer( $scope_organizer, $active_organizer );
        $active_organizer     = ( '' !== $active_organizer && $filters['organizer'] === $active_organizer )
            ? $filters['organizer']
            : '';

        /*
         * A BUTTON IS ONLY MARKED PRESSED WHEN IT IS WHAT IS BEING SHOWN. If the
         * chosen category fell outside the block's scope it was dropped, and the
         * list is the block's own events; lighting up a chip in that state would
         * label the list as something it is not.
         */
        $active_category = ( '' !== $requested && $filters['category'] === $requested ) ? $filters['category'] : '';

        /*
         * THE SECOND LEVEL. Derived after the category is settled, because the
         * whole point is that it lists what is inside the chosen category, and
         * clamped against that derived list, which is what stops a hand-written
         * uc_group reaching past the block's scope. See available_groups().
         *
         * A block already scoped to one series is not offered a group row: it
         * can only ever contain the one thing the block already is, and a filter
         * whose only option is "what you are looking at" is furniture.
         */
        $available_groups = ( empty( $rows['series'] ) || $filters['series'] > 0 )
            ? array()
            : $this->available_groups( $filters );
        $requested_groups = ( null === $args['active_groups'] )
            ? $this->requested_groups()
            : $this->slug_list( $args['active_groups'] );

        $filters['groups'] = $this->effective_groups( $available_groups, $requested_groups );
        $active_groups     = $filters['groups'];

        // Sidebar is a different shape entirely: no filter bar, no pagination,
        // no toggle, a count rather than a page size. It returns early rather
        // than threading "unless sidebar" through everything below.
        if ( 'sidebar' === $view ) {
            $count = self::sidebar_count( $args['count'] );
            return array(
                'html'      => $this->render_sidebar( $filters, $count, isset( $args['heading'] ) ? $args['heading'] : null ),
                'total'     => 0,
                'page'      => 1,
                'per_page'  => $count,
                'max_pages' => 1,
                'has_more'  => false,
                'view'      => 'sidebar',
            );
        }

        $compact  = ( $args['layout'] === 'compact' );
        $per_page = $this->resolve_per_page( $args['per_page'] );
        $paginate = ( $per_page > 0 );
        $style    = $this->pagination_style();
        $toggle   = $this->show_filters( $args['toggle'] );
        $combined = $this->is_combined_view( $view );

        /*
         * THE COMBINED MODE KEEPS ITS TOGGLE, AND 3.45.0 WAS RIGHT UNTIL THE
         * BLOCK GOT NARROW (3.82.0).
         *
         * WHAT THIS USED TO SAY, AND WHY IT WAS REASONABLE. "The toggle exists
         * to switch between the grid and the list. Here both are on screen, so
         * it has nothing to switch." That is true SIDE BY SIDE, which is the
         * only shape this mode had been looked at in.
         *
         * IT IS FALSE THE MOMENT THE PANELS STACK. Stacked, what is on screen
         * is a month grid and, a long way below it, a short list of upcoming
         * dates. It is not the list view: the list view is every event with its
         * picture, its excerpt and its meta, paged. A day carrying nine events
         * makes that grid enormous, and the list is the answer to exactly that.
         *
         * AND PHP CANNOT KNOW WHICH SHAPE IT IS IN. The panels stack on
         * flex-wrap, at a width decided in the browser, so a decision made here
         * is made for both shapes at once. Turning the toggle off was therefore
         * turning it off for the shape that needs it most.
         *
         * SO THE CALENDAR BUTTON MEANS "THE COMBINED VIEW" HERE, and carries
         * data-view="combined" rather than "calendar": pressing it must put the
         * visitor back in the layout the block was configured for, not collapse
         * it to a grid they never asked for. render_view_toggle() takes the home
         * view for that reason and for no other.
         */
        /*
         * PAGINATION COMES BACK WITH THE TOGGLE (3.82.0), and it has to.
         *
         * It was turned off here on the reasoning that "there is no list to
         * page", which was true while the combined mode had no list panel. It
         * has one now, and without paging a visitor who pressed List would be
         * handed all 287 events in one response.
         *
         * NOTHING APPEARS UNDER THE COMBINED PANELS. render_pagination() is
         * emitted INSIDE .uc-panel-list, so it is hidden and shown with the
         * list it pages and cannot turn up beneath a grid.
         */
        $month    = $this->normalize_month( $args['month'] );

        if ( (int) $args['page'] > 0 ) {
            $paged = max( 1, (int) $args['page'] );
        } else {
            $paged = ( $paginate && $style === 'pages' && isset( $_GET['uc_page'] ) ) ? max( 1, intval( $_GET['uc_page'] ) ) : 1;
        }

        /*
         * THE COMBINED MODE ASKS FOR THE TOTAL AND NO CARDS.
         *
         * It still shows "29 events coming up", which is the whole filtered set
         * and is what both halves are views of, so the query still has to run.
         * What it does not need is twelve cards built and thrown away, which is
         * what happened for the first day of this panel being a sidebar. 'none'
         * runs the query and renders nothing; one row is asked for because
         * found_posts does not depend on how many were returned.
         */
        /*
         * THE COMBINED MODE BUILDS ITS CARDS NOW (3.83.0).
         *
         * IT USED TO ASK FOR THE TOTAL AND NOTHING ELSE, with 'none', and the
         * reason was good: the mode showed a grid and a sidebar, so twelve cards
         * were built and thrown away on every load. 3.82.0 gave it a list panel
         * for the toggle to reach, and nothing here was changed to match.
         *
         * SO THE LIST PANEL GOT AN EMPTY STRING AND RENDERED ITS EMPTY STATE.
         * "No upcoming events found." beside a sidebar listing the same events
         * perfectly, which is exactly how it was reported. The query had always
         * run and had always found them; it was told to draw none of them.
         *
         * THERE IS NO 'none' PATH LEFT, because there is no mode that wants the
         * count without the cards any more. One call, one render mode, and the
         * branch that could disagree with itself is gone.
         *
         * BUILT RATHER THAN FETCHED ON DEMAND, and that is the no-script rule:
         * pressing the toggle with no script cannot fetch anything, so the
         * cards have to be in the document already.
         */
        $events = $this->render_events( $per_page, $paged, $filters, $compact ? 'compact' : 'card' );
        $max    = $paginate ? $events['max_pages'] : 1;

        // Controls that cannot work from another origin are dropped in an
        // embed rather than rendered dead. See the organizer filter below.
        $embed = sfaf_is_embed_context();

        ob_start();
        ?>
        <div class="uc-calendar<?php echo $compact ? ' uc-calendar-compact' : ''; ?> uc-view-<?php echo esc_attr( $view ); ?>"
             <?php // The redraw sends this back, so the server decides the shape
                   // from the same value the first render used. ?>
             data-view="<?php echo esc_attr( $view ); ?>"
             data-category="<?php echo esc_attr( $filters['category'] ); ?>"
             data-render="<?php echo $compact ? 'compact' : 'card'; ?>"
             data-per-page="<?php echo (int) $per_page; ?>"
             <?php // The block's own scope, which a chip may narrow and can never widen. ?>
             data-scope-category="<?php echo esc_attr( $scope_category ); ?>"
             data-active-category="<?php echo esc_attr( $active_category ); ?>"
             data-active-groups="<?php echo esc_attr( $active_groups ); ?>"
             <?php // Which filter rows this block offers, and which organizer the
                   // visitor picked. Both travel with the pasted snippet. ?>
             data-filters="<?php echo esc_attr( $this->filter_rows_attr( $rows ) ); ?>"
             data-scope-organizer="<?php echo esc_attr( $scope_organizer ); ?>"
             data-active-organizer="<?php echo esc_attr( $active_organizer ); ?>"
             data-filter-category="<?php echo esc_attr( $filters['category'] ); ?>"
             data-filter-organizer="<?php echo esc_attr( $filters['organizer'] ); ?>"
             data-filter-series="<?php echo (int) $filters['series']; ?>"
             data-filter-venue="<?php echo esc_attr( $filters['venue'] ); ?>"
             <?php // Carried so paging and "load more" keep the search applied. ?>
             data-filter-s="<?php echo esc_attr( $filters['s'] ); ?>"
             data-pagination="<?php echo esc_attr( $style ); ?>"
             data-page="<?php echo (int) $paged; ?>"
             <?php // data-view is declared once, at the top of this tag. It was
                   // written twice, and a browser keeps the first and drops the
                   // second, so the two could have disagreed with no sign of it. ?>
             <?php
             /*
              * CARRIED ON THE BLOCK SO IT TRAVELS WITH THE PASTED SNIPPET, and
              * echoed back only when the snippet actually said something. An
              * absent attribute is "follow the shipped default", which is what
              * lets flipping sfaf_source_links_default() change every block
              * already pasted on sfaf.org instead of only the ones regenerated
              * afterwards.
              */
             $sl = $this->normalize_source_links( $args['source_links'] );
             if ( null !== $sl ) :
                 ?>data-source-links="<?php echo $sl ? 'yes' : 'no'; ?>"<?php
             endif;
             ?>
             data-month="<?php echo esc_attr( $month ); ?>"
             data-max-pages="<?php echo (int) $max; ?>">

            <?php
            /*
             * THE BAR EXISTS WHEN ANY ROW DOES. Search rides with it: it is not
             * one of the three toggles, and a block with no rows at all has no
             * bar to put it in.
             */
            $any_row = ( ! empty( $rows['category'] ) || ! empty( $rows['organizer'] ) || ! empty( $rows['series'] ) );
            ?>
            <?php if ( $any_row ) : ?>
            <?php
            /*
             * A REAL GET FORM ROUND THE BAR (3.85.0), AND IT IS NEW RATHER THAN
             * RESTORED. Nothing in this file had a <form>, a submit or a
             * <noscript>: the search box, the organizer select and the group
             * checkboxes were all read by JavaScript, so with script off the
             * filter bar rendered and did nothing at all. Saying the calendar
             * works without script was true of the LISTS and was never true of
             * the filters.
             *
             * ACTION IS THE CURRENT PAGE, so a submit reloads the page the
             * block is on with the choices in the query string, which is where
             * uc_cat, uc_org and uc_group are already read from. The month is
             * carried as a hidden field so applying a filter does not throw
             * somebody back to today.
             *
             * The script does not submit this. It intercepts, exactly as
             * initLoadMore() intercepts the pagination links, so the behaviour
             * with script is unchanged and the markup underneath is real.
             */
            ?>
            <form class="uc-filter-form" method="get" action="" data-uc-filter-form>
                <?php if ( '' !== $filters['s'] ) : ?>
                    <input type="hidden" name="uc_s" value="<?php echo esc_attr( $filters['s'] ); ?>" />
                <?php endif; ?>
                <?php if ( '' !== $active_category ) : ?>
                    <input type="hidden" name="uc_cat" value="<?php echo esc_attr( $active_category ); ?>" />
                <?php endif; ?>
            <div class="uc-filters">
                <div class="uc-search-wrap">
                    <?php
                    /*
                     * THE GLYPH IS AN ELEMENT, NOT A BACKGROUND, AND THAT IS
                     * THE WHOLE REASON IT IS HERE RATHER THAN IN THE
                     * STYLESHEET. The recorded way an affordance disappears on
                     * a host page is a `background:` shorthand somewhere in
                     * the theme resetting `background-image` to none; that is
                     * how the select arrow was lost in 3.18.0. A glyph with no
                     * background to reset cannot be lost that way. It is also
                     * on the WRAPPER rather than the field, so a host rule
                     * shaped `input[type="search"]` reaches the input and
                     * never reaches this.
                     *
                     * aria-hidden, because the field already says "Search
                     * events" and a second voice for the same thing is noise.
                     */
                    echo sfaf_icon( 'search', array( 'class' => 'uc-search-icon' ) );
                    /*
                     * The value is rendered back in so a search survives a
                     * page load: the shortcode accepts s="…", and the embed
                     * re-renders the whole block on every search.
                     */
                    ?>
                    <input type="search" class="uc-search" value="<?php echo esc_attr( $filters['s'] ); ?>"
                           aria-label="Search events" placeholder="Search events..." />
                </div>
                <?php if ( ! empty( $rows['category'] ) ) : ?>
                <div class="uc-filter-buttons">
                    <?php
                    /*
                     * WHICH BUTTONS EXIST, AND WHY IT IS NOT SIMPLY "ALL OF
                     * THEM". A block scoped with category="fundraising" used to
                     * print a button for every category on the site, all but one
                     * of which could only ever empty the list. The bar now offers
                     * the block's own categories when it has a scope, and "All
                     * Events" means "everything this block is about" rather than
                     * everything on the calendar.
                     */
                    $categories = get_terms( array(
                        'taxonomy'   => 'uc_event_category',
                        'hide_empty' => true,
                    ) );
                    if ( is_wp_error( $categories ) ) {
                        $categories = array();
                    }
                    if ( '' !== $scope_category ) {
                        $in_scope   = explode( ',', $scope_category );
                        $categories = array_values( array_filter( $categories, function ( $c ) use ( $in_scope ) {
                            return in_array( $c->slug, $in_scope, true );
                        } ) );
                    }
                    $active_slugs = ( '' === $active_category ) ? array() : explode( ',', $active_category );
                    ?>
                    <button class="uc-filter-btn<?php echo empty( $active_slugs ) ? ' active' : ''; ?>"
                            data-category="all" aria-pressed="<?php echo empty( $active_slugs ) ? 'true' : 'false'; ?>">All Events</button>
                    <?php foreach ( $categories as $cat ) :
                        $color = sfaf_category_color( $cat->term_id );
                        $on    = in_array( $cat->slug, $active_slugs, true );
                    ?>
                        <button class="uc-filter-btn<?php echo $on ? ' active' : ''; ?>"
                                data-category="<?php echo esc_attr( $cat->slug ); ?>"
                                aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"
                                style="--cat-color: <?php echo esc_attr( $color ); ?>">
                            <?php
                            /*
                             * `--cat-color` HAS BEEN EMITTED HERE AND READ BY
                             * NOTHING SINCE 3.31.0, which removed the accent
                             * bar that was the only rule using it. The dot is
                             * what reads it now, and it is the same colour the
                             * cards below already carry for that category, so
                             * the row is legible as "the categories you can
                             * see" rather than as eight anonymous buttons.
                             *
                             * "All Events" gets none, because it is not a
                             * category and a dot there would claim it is.
                             */
                            ?>
                            <span class="uc-filter-dot" aria-hidden="true"></span>
                            <?php echo esc_html( $cat->name ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php
                /*
                 * THE ORGANIZER ROW, WHICH NOW WORKS EVERYWHERE (3.50.0).
                 *
                 * It used to be skipped in embeds because it did nothing: the
                 * note here said so, and called it a client-side stub. It runs
                 * the same server query the chips do, so there is no longer any
                 * reason for an embed to be a special case, and a block that
                 * asks for this row on somebody else's page gets a working one.
                 *
                 * The options are the block's OWN organizers when it is scoped,
                 * exactly as the chips are: offering every organizer on the site
                 * would list dozens that can only ever empty the block.
                 */
                /*
                 * EITHER HALF IS ENOUGH TO RENDER THE CONTROL, and gating it on
                 * the organizer row alone was a real fault the committed
                 * toggles test caught: filters="category,series" asked for
                 * groups, got no picker at all, and lost a control it had
                 * before the merge. The two halves are independent switches on
                 * one control now, so each is resolved separately and the
                 * control renders when either has something to show.
                 */
                $organizers = array();
                $org_on     = array();
                if ( ! empty( $rows['organizer'] ) ) {
                    $organizers = get_terms( array(
                        'taxonomy'   => 'uc_organizer',
                        'hide_empty' => true,
                    ) );
                    if ( is_wp_error( $organizers ) ) {
                        $organizers = array();
                    }
                    if ( '' !== $scope_organizer ) {
                        $org_scope  = explode( ',', $scope_organizer );
                        $organizers = array_values( array_filter( $organizers, function ( $o ) use ( $org_scope ) {
                            return in_array( $o->slug, $org_scope, true );
                        } ) );
                    }
                    $org_on = ( '' === $active_organizer ) ? array() : explode( ',', $active_organizer );
                }
                $who_groups = ! empty( $rows['series'] ) ? $available_groups : array();
                if ( ! empty( $organizers ) || ! empty( $who_groups ) ) :
                ?>
                <?php
                /*
                 * ONE CONTROL FOR BOTH (3.85.0). The native <select> that stood
                 * here could hold exactly one organizer, which an event with
                 * three makes useless, and the groups row below asked the same
                 * question a second time in a second control.
                 * render_who_picker() carries the reasoning, including what
                 * merging the two costs and what it replaces.
                 */
                $this->render_who_picker(
                    $organizers,
                    $who_groups,
                    $org_on,
                    ( '' === $active_groups ) ? array() : explode( ',', $active_groups ),
                    $this->group_organizer_map( $who_groups )
                );
                ?>
                <?php endif; ?>
            </div>
            </form>

            <?php
            /*
             * THE GROUPS ROW IS GONE FROM HERE (3.85.0), and with it the
             * staging this comment used to describe: groups rendered only once
             * a category had been chosen, so that nobody was ever looking at
             * two taxonomies at once. They are in the Organizers and groups
             * picker now, under their own heading, which is first-level.
             *
             * render_group_row() NOW HAS NO CALLER. It is left in place for one
             * release rather than deleted, because the merge is the part of
             * this change most likely to be reversed and rebuilding it from a
             * changelog is worse than an unreferenced method somebody can see.
             * If the picker survives 3.85.0 it should go; PROJECT.md says so
             * and TESTING.md carries the item that settles it.
             *
             * The breadcrumb stays. It names what is currently narrowing the
             * list, which is a different job from choosing it, and it is the
             * only thing on the bar that says so in words.
             */
            $this->render_breadcrumb( $scope_category, $active_category, $available_groups, $active_groups );
            ?>
            <?php endif; ?>

            <div class="uc-view-bar">
                <?php
                /*
                 * "upcoming" is doing real work in this sentence. This counts
                 * every published event from today forward, across all months;
                 * the month grid below counts one month, including days that
                 * have already been. Those two numbers disagreeing is correct,
                 * and the wording is what stops it looking like a fault: a
                 * calendar opened on the last day of July can honestly show one
                 * event in July beside thirty-eight still to come.
                 */
                ?>
                <?php
                /*
                 * NOT IN A MONTH VIEW (3.45.1).
                 *
                 * "29 events coming up" counts every published event from today
                 * forward, across all months. Beside a grid showing one month
                 * that is a number about something else, and 3.45.0 was asked
                 * to remove it. What was removed then was the count inside the
                 * month head, which is a different number in a different
                 * element: this is the one that was on screen.
                 *
                 * The list view keeps it, because there it counts exactly what
                 * the list is paging through.
                 */
                ?>
                <?php if ( ! $combined && 'calendar' !== $view ) : ?>
                    <div class="uc-event-count">
                        <span class="uc-count-number"><?php echo (int) $events['total']; ?></span>
                        <?php echo esc_html( _n( 'event coming up', 'events coming up', (int) $events['total'] ) ); ?>
                    </div>
                <?php endif; ?>
                <?php if ( $toggle ) { echo $this->render_view_toggle( $view, $combined ? "combined" : $view ); } ?>
            </div>

            <?php
            /*
             * BOTH PANELS ARE RENDERED, and the toggle just shows one.
             *
             * The month grid is one extra date-range query, and rendering it
             * here means flipping the toggle is instant instead of a round trip
             * to another domain. Month NAVIGATION still fetches, because that is
             * genuinely new data. When the toggle is switched off, only the
             * chosen view is built, so a calendar-only embed does not pay for a
             * list it will never show.
             *
             * THE COMBINED MODE NO LONGER WANTS THE LIST AT ALL. Its right-hand
             * column is the sidebar renderer, so the card list is not built, not
             * emitted and not hidden; see the panel below. That is why $combined
             * has come out of $want_list and stayed in $want_grid.
             */
            $want_list = ( $toggle || 'list' === $view );
            $want_grid = ( $toggle || $combined || 'calendar' === $view );

            /*
             * WHICH PANELS ARE VISIBLE, as opposed to which are built.
             *
             * In the combined mode both, and neither carries `hidden`. In every
             * other mode exactly one, exactly as before. Written as a closure so
             * the two panels below cannot drift into asking the question two
             * different ways, which is how one of them ends up hidden in a mode
             * nobody tested.
             */
            $panel_hidden = function ( $panel ) use ( $view, $combined ) {
                if ( $combined ) {
                    /*
                     * THE COMBINED MODE SHOWS THE GRID AND THE SIDEBAR, AND
                     * CARRIES THE LIST HIDDEN (3.82.0). It used to show
                     * everything it built, which was correct while it did not
                     * build a list. It builds one now so the toggle has
                     * somewhere to go, and a list panel visible under the grid
                     * would be both views at once.
                     */
                    return ( 'list' === $panel ) ? ' hidden' : '';
                }
                return ( $panel === $view ) ? '' : ' hidden';
            };
            ?>

            <?php
            /*
             * BOTH PANELS BUFFERED, THEN EMITTED IN THE RIGHT ORDER.
             *
             * The combined mode wants the grid first: on the left when the two
             * sit side by side, and on top when they stack. That could have
             * been `order: -1` in CSS and deliberately is not. `order` changes
             * what the eye sees and leaves the DOM alone, so tab order and a
             * screen reader would still meet the list first while everybody
             * else reads the grid first. Emitting them in the order they are
             * read keeps those the same thing.
             *
             * Every other mode keeps the original list-then-grid order, because
             * exactly one of them is visible and reordering would be a change
             * with no reader.
             *
             * THE COMBINED MODE'S SECOND PANEL IS THE SIDEBAR, NOT THE LIST, and
             * that is a narrower claim than it sounds: it is render_sidebar(),
             * the same method the sidebar display mode returns, with the same
             * heading, the same count of upcoming dates and the same "See all
             * events" link. Nothing here is a third rendering of anything.
             *
             * WHY. A list card is the main content of a page at full width: a
             * photograph in 16/9, a title, an excerpt, three lines of meta and a
             * footer with a button, about 690px of height each. Beside a month
             * grid that is one and a half events in view and it reads as heavy.
             * The sidebar row was designed for exactly this column, 44px of
             * thumbnail with a title and one quiet line, and is already correct
             * at this width because it is already shipped at this width.
             */
            ob_start();
            ?>
            <div class="uc-view-panel uc-panel-list"<?php echo $panel_hidden( 'list' ); ?>>
                <?php if ( $want_list ) : ?>
                    <div class="uc-event-list">
                        <?php if ( $events['html'] !== '' ) : ?>
                            <?php echo $events['html']; ?>
                        <?php else : ?>
                            <div class="uc-no-events">
                                <p>No upcoming events found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php
                    if ( $paginate ) {
                        echo $this->render_pagination( $style, $paged, $max );
                    }
                    ?>
                <?php endif; ?>
            </div>
            <?php
            $panel_list = ob_get_clean();

            ob_start();
            ?>
            <?php
            /*
             * THE COMBINED MODE'S THREE PIECES COME FROM ONE PLACE, and so does
             * the ajax redraw's. See render_combined_parts().
             */
            $parts = $combined
                ? $this->render_combined_parts(
                    $month,
                    $filters,
                    self::sidebar_count( $args['count'] ),
                    isset( $args['heading'] ) ? (string) $args['heading'] : ''
                )
                : array( 'head' => '', 'grid' => '', 'side' => '' );
            ?>
            <div class="uc-view-panel uc-panel-calendar"<?php echo $panel_hidden( 'calendar' ); ?>>
                <?php
                if ( $want_grid ) {
                    echo $combined ? $parts['grid'] : $this->render_month_grid( $month, $filters );
                }
                ?>
            </div>
            <?php
            $panel_grid = ob_get_clean();

            /*
             * THE RIGHT-HAND COLUMN, built only for the mode that has one.
             *
             * No `hidden` and no $panel_hidden() call: this panel exists in
             * exactly one mode and is visible in it, so there is no question to
             * ask. A panel that cannot be hidden cannot be hidden by mistake,
             * which is the fault 3.31.1 shipped.
             */
            $panel_side = '';
            if ( $combined ) {
                /*
                 * THE SAME MONTH THE GRID IS SHOWING (3.45.0).
                 *
                 * Passed rather than left to default, because that is the whole
                 * of what makes these one calendar: the grid and the list agree
                 * about which month they are describing, and moving one moves
                 * the other. $month is the value render_month_grid() was handed
                 * on the line above, so they cannot disagree by construction.
                 */
                $panel_side = '<div class="uc-view-panel uc-panel-sidebar">' . $parts['side'] . '</div>';
            }
            ?>

            <?php
            /*
             * WHAT THE SIDEBAR NEEDS TO BE REBUILT ON NAVIGATION (3.45.0).
             *
             * Moving month redraws both halves, and the ajax that does it has
             * to rebuild the sidebar with the SAME count and heading it was
             * first rendered with, or October's list would silently be a
             * different length from September's. Stamped here rather than
             * guessed there.
             */
            $side_count = $combined ? self::sidebar_count( $args['count'] ) : 0;
            $side_head  = ( $combined && isset( $args['heading'] ) ) ? (string) $args['heading'] : '';
            ?>
            <div class="uc-view-panels<?php echo $combined ? ' uc-view-panels-combined' : ''; ?>"
                 <?php if ( $combined ) : ?>
                 data-uc-combined="1"
                 data-uc-side-count="<?php echo (int) $side_count; ?>"
                 data-uc-side-heading="<?php echo esc_attr( $side_head ); ?>"
                 <?php endif; ?>>
                <?php
                /*
                 * ONE CALENDAR, NOT TWO CARDS SIDE BY SIDE (3.45.0).
                 *
                 * The month name spans the top of the container, so it governs
                 * both halves, and the two panels sit inside one border with a
                 * divider between them rather than carrying an edge each. The
                 * grid is told not to draw its own head, because there is one
                 * head and it is up here.
                 *
                 * The head is emitted FIRST in the document as well as at the
                 * top visually, which is the same reason the grid is emitted
                 * before the sidebar: the order somebody reads it in and the
                 * order it is in are kept the same thing.
                 */
                /*
                 * THE LIST PANEL RIDES ALONG IN THE COMBINED MODE TOO (3.82.0),
                 * hidden, and it is emitted LAST so the reading order is the
                 * one on screen: head, grid, sidebar, and then the panel that
                 * only appears when somebody asks for it.
                 */
                echo $combined
                    ? '<div class="uc-combined-head">' . $parts['head'] . '</div>' . $panel_grid . $panel_side . $panel_list
                    : $panel_list . $panel_grid;
                ?>
            </div>

            <?php
            /*
             * WHOLE-CALENDAR SUBSCRIPTION IS OUT OF SCOPE, AND THE BUTTONS ARE
             * GONE RATHER THAN LEFT LOOKING AVAILABLE.
             *
             * Three "Subscribe" buttons used to sit here pointing at
             * ?uc_ical=1. Nothing has ever handled that query string, so all
             * three quietly loaded the homepage. A control that does nothing is
             * worse than no control: it makes a promise the calendar cannot
             * keep, and it does it on the one screen where somebody is looking
             * for exactly that feature.
             *
             * PER-EVENT "Add to Calendar" is unaffected and stays exactly as it
             * is — sfaf_add_to_calendar(), the Google link and the .ics
             * download at /?uc_ics=ID. That one works.
             */
            ?>
        </div>
        <?php
        $html = ob_get_clean();

        // Put the link destination back to whatever it was before this block.
        // See the note where it was set.
        sfaf_set_source_links( $prev_source_links );

        return array(
            'html'      => $html,
            'total'     => $events['total'],
            'page'      => $paged,
            'per_page'  => $per_page,
            'max_pages' => $max,
            'has_more'  => ( $paginate && $paged < $max ),
            'view'      => $view,
        );
    }

    /**
     * Upcoming events widget shortcode: [upcoming_events]
     * Attributes: category, organizer, series, venue, per_page (or legacy count), title.
     * per_page="0"/"-1" shows all with no pagination.
     */
    public function render_upcoming( $atts ) {
        $atts = shortcode_atts( array(
            'category'  => '',
            'organizer' => '',
            'series'    => '',
            'venue'     => '',
            'count'     => '',
            'per_page'  => '',
            'title'     => 'Upcoming Events',
        ), $atts );

        $filters = $this->normalize_filters( $atts );

        // per_page attribute wins; fall back to legacy count; then global.
        $raw      = ( $atts['per_page'] !== '' ) ? $atts['per_page'] : $atts['count'];
        $per_page = $this->resolve_per_page( $raw );
        $paginate = ( $per_page > 0 );
        $style    = $this->pagination_style();
        $paged    = ( $paginate && $style === 'pages' && isset( $_GET['uc_page'] ) ) ? max( 1, intval( $_GET['uc_page'] ) ) : 1;

        $events = $this->render_events( $per_page, $paged, $filters, 'compact' );
        $max    = $paginate ? $events['max_pages'] : 1;

        ob_start();
        ?>
        <div class="uc-upcoming-widget"
             data-render="compact"
             data-per-page="<?php echo (int) $per_page; ?>"
             data-filter-category="<?php echo esc_attr( $filters['category'] ); ?>"
             data-filter-organizer="<?php echo esc_attr( $filters['organizer'] ); ?>"
             data-filter-series="<?php echo (int) $filters['series']; ?>"
             data-filter-venue="<?php echo esc_attr( $filters['venue'] ); ?>"
             data-pagination="<?php echo esc_attr( $style ); ?>"
             data-page="<?php echo (int) $paged; ?>"
             data-max-pages="<?php echo (int) $max; ?>">
            <?php if ( $atts['title'] ) : ?>
                <h3 class="uc-upcoming-title"><?php echo esc_html( $atts['title'] ); ?></h3>
            <?php endif; ?>
            <div class="uc-upcoming-list">
                <?php if ( $events['html'] !== '' ) : ?>
                    <?php echo $events['html']; ?>
                <?php else : ?>
                    <p class="uc-no-events">No upcoming events.</p>
                <?php endif; ?>
            </div>
            <?php
            if ( $paginate ) {
                echo $this->render_pagination( $style, $paged, $max );
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: return the next page of event cards (Load More / Infinite scroll).
     */
    public function ajax_load_events() {
        check_ajax_referer( 'uc_nonce', 'nonce' );

        $page     = max( 1, isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1 );
        $per_page = isset( $_POST['per_page'] ) ? intval( $_POST['per_page'] ) : 12;
        $render   = ( isset( $_POST['render'] ) && $_POST['render'] === 'compact' ) ? 'compact' : 'card';

        $filters = array();
        // 's' rides along with the rest, because the search is a filter now
        // rather than a pass over the cards this endpoint already returned.
        foreach ( array( 'category', 'organizer', 'series', 'venue', 's' ) as $key ) {
            $filters[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
        }

        // The scope rides along so it can be enforced here rather than trusted
        // to the browser. Without it a crafted request could show a scoped block
        // events its author never put in it. See effective_category().
        $filters['category'] = $this->effective_category(
            isset( $_POST['scope_category'] ) ? sanitize_text_field( wp_unslash( $_POST['scope_category'] ) ) : '',
            $filters['category']
        );
        $filters['groups'] = $this->clamp_groups(
            $filters,
            isset( $_POST['groups'] ) ? sanitize_text_field( wp_unslash( $_POST['groups'] ) ) : ''
        );

        if ( $per_page <= 0 ) {
            wp_send_json( array( 'html' => '', 'has_more' => false, 'total' => 0, 'max_pages' => 1 ) );
        }

        $events = $this->render_events( $per_page, $page, $filters, $render );

        wp_send_json( array(
            'html'      => $events['html'],
            'has_more'  => ( $page < $events['max_pages'] ),
            // Added in 3.8.0 so the count above the list is the number of
            // MATCHING events rather than the number of cards left on screen.
            'total'     => $events['total'],
            'max_pages' => $events['max_pages'],
        ) );
    }

    /**
     * AJAX: the whole calendar block, re-rendered.
     *
     * WHY A WHOLE BLOCK AND NOT JUST THE LIST. The category chips only ever
     * changed the rows, so swapping the list was enough. A group choice changes
     * the controls too: the Groups row is derived from what the chosen category
     * contains, and the breadcrumb states where the visitor now is. Rebuilding
     * those in the browser would be a second implementation of both, in another
     * language, free to disagree with the first. The server already knows how to
     * draw them.
     *
     * The scope and the choice arrive as separate parameters and stay separate,
     * so a chip can still never promote itself into the block's own definition.
     */
    public function ajax_load_block() {
        check_ajax_referer( 'uc_nonce', 'nonce' );

        $args = array(
            'category'        => isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '',
            'active_category' => isset( $_POST['active_category'] ) ? sanitize_text_field( wp_unslash( $_POST['active_category'] ) ) : '',
            'active_groups'   => isset( $_POST['active_groups'] ) ? sanitize_text_field( wp_unslash( $_POST['active_groups'] ) ) : '',
            'organizer'       => isset( $_POST['organizer'] ) ? sanitize_text_field( wp_unslash( $_POST['organizer'] ) ) : '',
            /* The organizer the visitor picked, kept apart from the block's own
             * scope above for the same reason the category's two are. */
            'active_organizer' => isset( $_POST['active_organizer'] ) ? sanitize_text_field( wp_unslash( $_POST['active_organizer'] ) ) : '',
            /* Which rows the block offers. Without this a redraw would rebuild
             * the bar from the shipped default and quietly hand back controls
             * the block was generated without. */
            'filters'         => isset( $_POST['filters'] ) ? sanitize_text_field( wp_unslash( $_POST['filters'] ) ) : '',
            'venue'           => isset( $_POST['venue'] ) ? sanitize_text_field( wp_unslash( $_POST['venue'] ) ) : '',
            'series'          => isset( $_POST['series'] ) ? absint( $_POST['series'] ) : 0,
            's'               => isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '',
            'per_page'        => isset( $_POST['per_page'] ) ? intval( $_POST['per_page'] ) : '',
            'layout'          => ( isset( $_POST['layout'] ) && 'compact' === $_POST['layout'] ) ? 'compact' : 'cards',
            'view'            => isset( $_POST['view'] ) ? sanitize_text_field( wp_unslash( $_POST['view'] ) ) : 'list',
            'toggle'          => ( isset( $_POST['toggle'] ) && 'no' === $_POST['toggle'] ) ? 'no' : 'yes',
            'month'           => isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '',
            'page'            => 1,
        );

        $block = $this->render_calendar_block( $args );

        wp_send_json( array(
            'html'      => $block['html'],
            'total'     => $block['total'],
            'max_pages' => $block['max_pages'],
            'has_more'  => $block['has_more'],
        ) );
    }

    /**
     * AJAX: one month's grid, for month navigation on this site.
     *
     * The embed uses the public REST route for the same thing, because it
     * cannot obtain a WordPress nonce from another origin. Both call
     * render_month_grid(), so the markup is identical and only the transport
     * differs.
     *
     * Cached in a transient keyed by month and filters, so a hundred visitors
     * browsing to September is one query, not a hundred. Its generation counter
     * is the embed's, which means every existing invalidation hook already
     * covers this path too.
     */
    public function ajax_load_month() {
        check_ajax_referer( 'uc_nonce', 'nonce' );

        $month   = $this->normalize_month( isset( $_POST['month'] ) ? wp_unslash( $_POST['month'] ) : '' );
        $filters = array();
        foreach ( array( 'category', 'organizer', 'series', 'venue', 'groups' ) as $key ) {
            $filters[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
        }
        // Clamped exactly as the list is, so choosing a category or a group
        // narrows the month grid too and cannot widen it. The transient key
        // below is built from the normalized filters, so a clamped request
        // shares the cache entry with the honest one rather than creating a
        // second.
        $filters['category'] = $this->effective_category(
            isset( $_POST['scope_category'] ) ? sanitize_text_field( wp_unslash( $_POST['scope_category'] ) ) : '',
            $filters['category']
        );
        $filters['groups'] = $this->clamp_groups( $filters, $filters['groups'] );

        /*
         * THE COMBINED MODE MOVES BOTH HALVES (3.45.0).
         *
         * Navigation used to redraw the grid alone, which was right while the
         * sidebar showed "what is coming up" regardless of month. Now that it
         * shows the month the grid is showing, redrawing one without the other
         * would leave October's grid beside September's list, which is exactly
         * the disagreement this release exists to remove.
         *
         * The count and the heading come off the block rather than from
         * defaults, so a rebuilt sidebar is the same sidebar.
         */
        /*
         * THE SHAPE COMES FROM THE VIEW, NOT FROM A BOOLEAN (3.45.1).
         *
         * This read a `combined` flag the browser sent, so a caller that did
         * not send it got a different shape: a grid carrying its own head,
         * dropped into the left column under a spanning head that stayed where
         * it was. A browser holding an older calendar.js is exactly such a
         * caller, and that is what "the heading goes left aligned when you
         * navigate" was.
         *
         * The view is normalized by the same function the block uses, and
         * is_combined_view() is the same question asked in the same words, so
         * the redraw cannot decide a shape the first render would not have.
         */
        $view     = $this->normalize_view( isset( $_POST['view'] ) ? wp_unslash( $_POST['view'] ) : '' );
        $combined = $this->is_combined_view( $view );
        $side_ct  = isset( $_POST['side_count'] ) ? absint( $_POST['side_count'] ) : 0;
        $side_hd  = isset( $_POST['side_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['side_heading'] ) ) : '';

        // The cache is per shape as well as per month: a combined payload
        // carries two more pieces of markup and must not be served to a grid.
        $key    = 'sfaf_month_' . md5( wp_json_encode( array(
            'month'    => $month,
            'filters'  => $this->normalize_filters( $filters ),
            'day'      => current_time( 'Y-m-d' ),
            'combined' => $combined ? array( $side_ct, $side_hd ) : false,
            'version'  => (int) get_option( SFAF_Embed::CACHE_VERSION_OPTION, 1 ),
        ) ) );
        $cached = get_transient( $key );

        if ( is_array( $cached ) && isset( $cached['html'] ) ) {
            wp_send_json_success( array_merge( $cached, array( 'month' => $month, 'cached' => true ) ) );
        }

        if ( $combined ) {
            // The same three pieces the first render composed, from the same
            // method. See render_combined_parts().
            $parts   = $this->render_combined_parts( $month, $filters, $side_ct, $side_hd );
            $payload = array( 'html' => $parts['grid'], 'head' => $parts['head'], 'side' => $parts['side'] );
        } else {
            $payload = array( 'html' => $this->render_month_grid( $month, $filters ) );
        }

        set_transient( $key, $payload, 10 * MINUTE_IN_SECONDS );

        wp_send_json_success( array_merge( $payload, array( 'month' => $month, 'cached' => false ) ) );
    }

    /**
     * Render a full event card. This is the LIST display mode and nothing else.
     *
     * ONE RENDERER, TWO DELIVERIES, AND AS OF 3.2.0 NO DIFFERENCE AT ALL. This
     * same method produces the cards for the [sfaf_calendar] shortcode on this
     * site and for the embed on sfaf.org, via render_events(). There is no
     * second code path and no embed-only variant, which is what makes the two
     * identical by construction rather than by anyone remembering to change
     * both. The last remaining split was the RSVP button, which had to be a
     * link in an embed because a modal cannot post cross-origin; the card no
     * longer opens a modal, so the two surfaces now emit the same bytes.
     *
     * THE STRUCTURE, TOP TO BOTTOM.
     * -----------------------------------------------------------------------
     *   header    category chip + who is running it | day, month and date
     *   media     inset, rounded, 150px, or a branded category tile
     *   title     links to the event
     *   summary   one clamped line
     *   meta      time, place and series: icon then text, stacked
     *   funding   only when switched on, and only with real figures
     *   footer    places left | View event, and Donate where there is one
     *
     * WHAT LEFT THE CARD, AND WHY IT IS NOT A DELETION. Add to Calendar, the
     * share buttons and the reminder controls are gone from here and unchanged
     * on the single event template, which renders every one of them. Nobody
     * scanning a list of thirty events is adding the fourth one to their
     * calendar without opening it first; those controls were charging every
     * card a row of chrome to serve a decision that happens one page later.
     *
     * NO IMAGE GRADIENT. The old banner sat text over the image and needed a
     * scrim to stay legible. Text now sits below the image on the card surface,
     * so there is nothing to rescue and nothing to draw.
     *
     * CLASSES THAT LOOK COSMETIC AND ARE NOT. .uc-event-card, .uc-card-title,
     * .uc-card-excerpt and .uc-card-meta are the hooks calendar.js and embed.js
     * search and filter through (searchTextOf(), the category filter). They are
     * kept on the rebuilt markup on purpose.
     *
     * @param int $post_id
     * @return string
     */
    private function render_event_card( $post_id ) {
        $date       = get_post_meta( $post_id, '_uc_event_date', true );
        $start_time = get_post_meta( $post_id, '_uc_start_time', true );
        $end_time   = get_post_meta( $post_id, '_uc_end_time', true );
        $location   = sfaf_event_location( $post_id );

        /*
         * EVERY CATEGORY, AND THE FIRST ONE DECIDES THE COLOUR.
         *
         * An event tagged both "Support Groups" and "Workshops" is two things
         * and says so: one chip each, in the order sfaf_event_categories()
         * fixes. The card can only be drawn in one colour, so the first
         * category supplies it, and because that order is decided in one place
         * the colour cannot change between the list, the month grid and the
         * placeholder image.
         *
         * data-category carries ALL of the slugs, space separated, because it
         * is what the filter scripts read: with one slug on it an event with
         * two categories appeared under only one of them.
         */
        $categories = sfaf_event_categories( $post_id );

        $has_cat   = ! empty( $categories );
        $cat_slugs = $has_cat ? implode( ' ', wp_list_pluck( $categories, 'slug' ) ) : '';
        $cat_color = $has_cat ? sfaf_category_color( $categories[0]->term_id ) : sfaf_default_category_color();
        $shades    = sfaf_category_shades( $cat_color );
        $chips     = sfaf_category_chips_html( $post_id, 'card' );

        /*
         * WHO IS RUNNING THIS. An imported event names the platform it came
         * from, because that is the honest answer to "whose event is this".
         * The organizer taxonomy on an Eventbrite import is ours, not theirs,
         * and is frequently empty. A native event names its organizer.
         */
        /*
         * EVERY ORGANIZER, NOT THE FIRST (3.40.0). A co-hosted event named one
         * of its two hosts on a card and the other nowhere.
         *
         * THE IMPORTED PATH IS UNCHANGED and is still checked first: an event
         * from Eventbrite or GoFundMe Pro names the platform, because that is
         * the honest answer to "whose event is this", and its organizer
         * taxonomy is ours rather than theirs and is frequently empty.
         */
        $source = sfaf_event_source_label( $post_id );
        $byline = ( '' !== $source )
            ? $source
            : SFAF_Organizers::phrase( $post_id );

        // strtotime( '' ) is false, not 0, and date() on false silently means
        // "now". That is how an undated event would have printed today's
        // date as its own. An undated event renders no date block.
        $date_ts = $date ? strtotime( $date ) : false;

        // "6-7:30 pm". Was "6:00 PM to 7:30 PM", which broke every one of the
        // guide's four time rules at once. See sfaf_ap_time_range().
        $time = sfaf_ap_time_range( $start_time, $end_time );

        // EVERY LINK ON THIS CARD COMES OFF ONE VARIABLE, and that variable
        // asks sfaf_event_link() rather than get_permalink(). See the note on
        // that function: a renderer that resolves its own URL is a display mode
        // where "open events to source listing" silently does nothing.
        $permalink = sfaf_event_link( $post_id );

        /*
         * "VIEW EVENT", ALWAYS, AND THE PREVIOUS LABEL WAS A LIE.
         *
         * 3.1.0 varied the word on this button: RSVP where the event took
         * registrations, Donate for an appeal, View event otherwise. The
         * button did not do any of those things. It went to the event page,
         * where a person then had to find the RSVP form themselves, so a card
         * saying "RSVP" was promising an action it could not perform and the
         * Galaxy Digital volunteer events fell through the gap entirely
         * because no fourth label existed for them.
         *
         * A label describes what pressing it does. This one goes to the event
         * page, so it says so, on every event and on every surface. There is
         * no per-event variation left to get wrong.
         *
         * DONATE IS THE ONE EXCEPTION, and it is an exception because it is
         * not the same button doing something else: it is a second button
         * going somewhere else entirely, straight to the donation page,
         * rendered only when there is one. It carries the heavier weight of
         * the two because it is the action with a consequence; View event is
         * navigation and takes the outline.
         */
        $donate_url = get_post_meta( $post_id, '_uc_gofundme_url', true );
        $can_donate = ( $donate_url && sfaf_show_feature( $post_id, 'donate' ) );

        /*
         * The footer's supporting line. Still worth saying even though the
         * button no longer mentions it: how many places are left is what a
         * person scanning a list is deciding on, and it is the same sentence
         * the event page shows, from the same helper.
         *
         * Native events only for RSVPs. An imported event's remaining places
         * live on the platform that sold them and we would be guessing.
         */
        $note = ( '' === $source ) ? sfaf_rsvp_spots_text( $post_id ) : '';
        if ( '' === $note ) {
            $note = sfaf_volunteer_spots_text( $post_id );
        }

        $summary = wp_trim_words( sfaf_flatten_html( get_the_excerpt( $post_id ) ?: get_the_content( null, false, $post_id ) ), 25 );

        /*
         * A CANCELLED EVENT SAYS SO ON THE CARD (3.72.0).
         *
         * SFAF_Cancellation::exclude() only removes the ones somebody chose to
         * hide. An event cancelled and left listed, which is the default and the
         * recommended answer because people who registered come looking for it,
         * rendered here exactly like a live one: same title, same time, same
         * "View event" button. The event page has said "This event has been
         * cancelled" since 3.36.0 and every surface in front of it said nothing,
         * so the only person who found out was the one who clicked.
         *
         * THE WORD, NOT THE COLOUR. `is-cancelled` carries the styling and
         * `.uc-lc-cancelled` carries the text, and the text is what a card in a
         * screenshot, a card printed out, and a card read by anybody who does
         * not separate red from the category hue beside it all still have. Six
         * of the ten category colours are already reds and oranges, so a red
         * treatment here is not even reliably distinguishable from an ordinary
         * card in this particular palette.
         *
         * NOT THE CLOSURE TREATMENT. A closure says the office is shut on a day,
         * which is a fact about the day and belongs on the day cell. This is one
         * event being off while everything around it goes ahead. Reusing the
         * closure's "CLOSED" block would say the wrong thing in the wrong place.
         */
        $cancelled = SFAF_Cancellation::is_cancelled( $post_id );

        ob_start();
        ?>
        <div class="uc-event-card uc-lc<?php echo $cancelled ? ' is-cancelled' : ''; ?>" data-category="<?php echo esc_attr( $cat_slugs ); ?>"
             style="--uc-cat: <?php echo esc_attr( $cat_color ); ?>; --uc-cat-tint: <?php echo esc_attr( $shades['tint'] ); ?>; --uc-cat-media: <?php echo esc_attr( $shades['media'] ); ?>; --uc-cat-ink: <?php echo esc_attr( $shades['ink'] ); ?>">

            <div class="uc-lc-head">
                <div class="uc-lc-ident">
                    <?php echo $chips; ?>
                    <?php if ( '' !== $byline ) : ?>
                        <?php
                        /*
                         * THE EXTERNAL MARKER GOES HERE AND NOWHERE ELSE ON THE
                         * CARD.
                         *
                         * The byline already names the platform the event came
                         * from, so an arrow beside it says "and that is where
                         * this link goes" in the one place a visitor is already
                         * reading the answer. Putting it on the title as well
                         * and on the button as well would be three marks for
                         * one fact, which is the noise the brief asked this not
                         * to become.
                         *
                         * Empty on a native event and empty when the setting is
                         * off, because sfaf_external_marker() asks whether this
                         * link actually leaves rather than whether the setting
                         * exists.
                         */
                        ?>
                        <span class="uc-lc-byline"><?php echo esc_html( $byline ); echo sfaf_external_marker( $post_id ); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( $date_ts ) : ?>
                    <div class="uc-lc-date">
                        <span class="uc-lc-dow"><?php echo esc_html( sfaf_ap_date( $date_ts, 'weekday' ) ); ?></span>
                        <span class="uc-lc-md"><?php echo esc_html( sfaf_ap_date( $date_ts, 'short' ) ); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            /*
             * The image is inset inside the card padding and rounded, not bled
             * to the card edge: it is one element of the card, not its lid.
             * Wrapped in a link so the picture is clickable, but hidden from
             * assistive tech: the title below is the same destination and is
             * the one that reads properly.
             */
            ?>
            <div class="uc-lc-media">
                <?php // No new-tab note on this one: it is aria-hidden and out
                      // of the tab order, so it has no name to add a sentence
                      // to. The title below is the same destination. ?>
                <a href="<?php echo esc_url( $permalink ); ?>"<?php echo sfaf_new_tab_attrs(); ?> tabindex="-1" aria-hidden="true"><?php echo sfaf_list_card_media( $post_id ); ?></a>
            </div>

            <?php
            /*
             * ABOVE THE TITLE, NOT AFTER IT. Somebody scanning a list reads
             * titles, so the word has to be in the path their eye is already
             * taking rather than at the end of a line they may not finish. It
             * is also before the title in the reading order, which is where a
             * screen reader needs it: "Cancelled, Coffee Social" is the useful
             * order and "Coffee Social, cancelled" makes them listen to the
             * whole card first.
             */
            ?>
            <?php if ( $cancelled ) : ?>
                <p class="uc-lc-cancelled">Cancelled</p>
            <?php endif; ?>

            <h3 class="uc-card-title uc-lc-title">
                <a href="<?php echo esc_url( $permalink ); ?>"<?php echo sfaf_new_tab_attrs(); ?>><?php echo esc_html( get_the_title( $post_id ) ); ?><?php echo sfaf_new_tab_note(); ?></a>
            </h3>

            <?php if ( '' !== $summary ) : ?>
                <p class="uc-card-excerpt uc-lc-summary"><?php echo esc_html( $summary ); ?></p>
            <?php endif; ?>

            <div class="uc-card-meta uc-lc-meta">
                <?php if ( '' !== $time ) : ?>
                    <span class="uc-meta-item"><?php echo sfaf_icon( 'clock', array( 'size' => '15px' ) ); ?><span><?php echo esc_html( $time ); ?></span></span>
                <?php endif; ?>
                <?php if ( $location ) : ?>
                    <span class="uc-meta-item"><?php echo sfaf_icon( 'pin', array( 'size' => '15px' ) ); ?><span><?php echo esc_html( $location ); ?></span></span>
                <?php endif; ?>
                <?php
                // Omitted entirely when the event is in no series. An empty
                // row labelled "Event Series" was the thing this replaces.
                $series = sfaf_series_dates_link( $post_id );
                if ( '' !== $series ) {
                    echo '<span class="uc-meta-item">' . sfaf_icon( 'repeat', array( 'size' => '15px' ) ) . $series . '</span>';
                }
                ?>
            </div>

            <?php echo sfaf_fundraising_progress( $post_id ); ?>

            <div class="uc-lc-foot">
                <?php if ( '' !== $note ) : ?>
                    <span class="uc-lc-note"><?php echo esc_html( $note ); ?></span>
                <?php endif; ?>

                <?php
                /*
                 * Both are plain links now, on both surfaces. The embed split
                 * existed only because the RSVP label opened a modal that
                 * cannot post cross-origin; with no modal on the card there is
                 * nothing left to differ about, so the shortcode and the embed
                 * emit byte-identical markup here.
                 */
                ?>
                <div class="uc-lc-actions">
                    <?php echo sfaf_action_button( array(
                        'label'   => 'View event',
                        'href'    => $permalink,
                        'variant' => 'secondary',
                        'new_tab' => true,
                    ) ); ?>
                    <?php if ( $can_donate ) : ?>
                        <?php echo sfaf_action_button( array(
                            'label'    => 'Donate',
                            'href'     => $donate_url,
                            'variant'  => 'primary',
                            'external' => true,
                        ) ); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a compact card for the upcoming events widget
     */
    private function render_compact_card( $post_id ) {
        $date       = get_post_meta( $post_id, '_uc_event_date', true );
        $start_time = get_post_meta( $post_id, '_uc_start_time', true );
        $location   = sfaf_event_location( $post_id );

        /*
         * NO ACCENT STRIPE. 3.19.0, and this is the one that was actually on
         * screen.
         *
         * The stripe was never a border. It was a real element,
         * <div class="uc-compact-accent"> with its colour in an inline style,
         * pinned to the left edge by position: absolute inside a card that is
         * position: relative; overflow: hidden. That is why searching the
         * stylesheets for border-left found nothing: the CSS only sized and
         * positioned it, and the colour was here in PHP.
         *
         * The category slugs stay, because they are what the filter bar
         * matches on and have nothing to do with the stripe.
         */
        $categories = sfaf_event_categories( $post_id );
        $cat_slugs  = ! empty( $categories ) ? implode( ' ', wp_list_pluck( $categories, 'slug' ) ) : '';

        $date_ts = strtotime( $date );

        ob_start();
        ?>
        <a href="<?php echo esc_url( sfaf_event_link( $post_id ) ); ?>"<?php echo sfaf_new_tab_attrs(); ?> class="uc-compact-card"
           data-category="<?php echo esc_attr( $cat_slugs ); ?>">
            <div class="uc-compact-thumb"><?php echo sfaf_event_thumbnail( $post_id, 'thumbnail' ); ?></div>
            <div class="uc-compact-date">
                <?php // Through the one formatter, which is date_i18n() and so the
                      // SITE's clock. These were date( 'M' ) and date( 'j' ), the
                      // server's, so an evening event could show the wrong day. ?>
                <span class="uc-compact-month"><?php echo esc_html( sfaf_ap_date( $date_ts, 'month' ) ); ?></span>
                <span class="uc-compact-day"><?php echo esc_html( sfaf_ap_date( $date_ts, 'daynum' ) ); ?></span>
            </div>
            <div class="uc-compact-info">
                <div class="uc-compact-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></div>
                <div class="uc-compact-meta">
                    <?php if ( $start_time ) : ?>
                        <?php echo esc_html( sfaf_ap_time( $start_time ) ); ?>
                    <?php endif; ?>
                    <?php if ( $location ) : ?>
                        &middot; <?php echo esc_html( $location ); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php echo sfaf_new_tab_note(); ?>
        </a>
        <?php
        return ob_get_clean();
    }
}
