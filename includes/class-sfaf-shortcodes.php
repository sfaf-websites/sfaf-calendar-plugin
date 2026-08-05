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
        );
    }

    /**
     * Sanitize a comma-separated list of term slugs down to a clean CSV string.
     * Returns '' when nothing usable is left, which every caller reads as "no filter".
     */
    private function slug_list( $raw ) {
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
     * Whether the visitor-facing search and category buttons should render.
     *
     * Accepts what a shortcode attribute or a data attribute might carry —
     * yes/no, true/false, 1/0 — and defaults to showing them.
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

        return $args;
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
     *   sidebar   compact, count-limited, for narrow placements
     * ------------------------------------------------------------------- */

    /** The three display modes, and the fallback for anything unrecognised. */
    public function normalize_view( $raw ) {
        $view = strtolower( trim( (string) $raw ) );
        return in_array( $view, array( 'list', 'calendar', 'sidebar' ), true ) ? $view : 'list';
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
        $raw = trim( (string) $raw );
        if ( preg_match( '/^(\d{4})-(\d{2})$/', $raw, $m ) ) {
            $month = (int) $m[2];
            if ( $month >= 1 && $month <= 12 ) {
                return $raw;
            }
        }
        return current_time( 'Y-m' );
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
            'label' => $first->format( 'F Y' ),
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

        return array(
            'grid'     => $grid,
            'events'   => $by_day,
            'total'    => count( $query->posts ),
            'in_month' => $in_month,
        );
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
    public function render_month_grid( $month, $filters ) {
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
            // Compact header: what month this is on the left, controls on the
            // right. The date range underneath is the grid's real span, which
            // is what explains the greyed cells at each end.
            $range = date_i18n( 'j M', strtotime( $grid['start'] . ' 12:00:00' ) )
                . ' to ' . date_i18n( 'j M', strtotime( $grid['end'] . ' 12:00:00' ) );
            ?>
            <div class="uc-month-head">
                <div class="uc-month-heading">
                    <h3 class="uc-month-label" aria-live="polite"><?php echo esc_html( $grid['label'] ); ?></h3>
                    <p class="uc-month-range">
                        <?php echo esc_html( $range ); ?>
                        <span class="uc-month-count"><?php
                            echo esc_html( sprintf(
                                _n( '%d event', '%d events', (int) $data['in_month'] ),
                                (int) $data['in_month']
                            ) );
                        ?></span>
                    </p>
                </div>

                <?php // Previous / Today / Next as one segmented control. ?>
                <div class="uc-month-nav-group" role="group" aria-label="Change month">
                    <button type="button" class="uc-month-nav uc-month-prev" data-goto="<?php echo esc_attr( $grid['prev'] ); ?>"
                            aria-label="Previous month"><span aria-hidden="true">&lsaquo;</span></button>
                    <button type="button" class="uc-month-nav uc-month-today" data-goto="<?php echo esc_attr( current_time( 'Y-m' ) ); ?>">Today</button>
                    <button type="button" class="uc-month-nav uc-month-next" data-goto="<?php echo esc_attr( $grid['next'] ); ?>"
                            aria-label="Next month"><span aria-hidden="true">&rsaquo;</span></button>
                </div>
            </div>

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
                            $readable  = date_i18n( 'l j F Y', strtotime( $day . ' 12:00:00' ) );

                            $classes = array( 'uc-day' );
                            if ( ! $in_month ) { $classes[] = 'uc-day-out'; }
                            if ( $is_today )   { $classes[] = 'uc-day-today'; }
                            if ( empty( $ids ) ) { $classes[] = 'uc-day-empty'; }

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
                            ?>
                            <td class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
                                data-day="<?php echo esc_attr( $day ); ?>"
                                data-count="<?php echo (int) count( $ids ); ?>"
                                tabindex="<?php echo (int) $tabindex; ?>"
                                role="gridcell"
                                aria-label="<?php echo esc_attr( $label ); ?>">
                                <span class="uc-day-num" aria-hidden="true"><?php echo (int) $day_num; ?></span>
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
                                    <ul class="uc-day-events">
                                        <?php foreach ( $ids as $id ) :
                                            $start = (string) get_post_meta( $id, '_uc_start_time', true );
                                            $color = sfaf_event_category_color( $id );
                                            ?>
                                            <li class="uc-day-event">
                                                <a href="<?php echo esc_url( get_permalink( $id ) ); ?>" style="--cat-color: <?php echo esc_attr( $color ); ?>">
                                                    <span class="uc-day-event-title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
                                                    <?php if ( '' !== $start ) : ?>
                                                        <span class="uc-day-event-time"><?php echo esc_html( date_i18n( 'g:ia', strtotime( $start ) ) ); ?></span>
                                                    <?php endif; ?>
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
    public function render_sidebar( $filters, $count ) {
        $count  = max( 1, min( 50, (int) $count ) );
        $events = $this->render_events( $count, 1, $filters, 'sidebar' );

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
            <div class="uc-sidebar-list">
                <?php if ( '' !== $events['html'] ) : ?>
                    <?php echo $events['html']; ?>
                <?php else : ?>
                    <?php // REQUIRED EMPTY STATE. A programme on hiatus must not
                          // leave a blank box on a live page: say so, and still
                          // offer the way through to everything else. ?>
                    <p class="uc-sidebar-empty">No upcoming dates scheduled just now.</p>
                <?php endif; ?>
            </div>
            <a class="uc-sidebar-all" href="<?php echo esc_url( $all_url ); ?>">See all events &rarr;</a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * One sidebar row: date, title, start time. No image, no description.
     *
     * @param int $post_id
     * @return string
     */
    private function render_sidebar_row( $post_id ) {
        $date  = (string) get_post_meta( $post_id, '_uc_event_date', true );
        $start = (string) get_post_meta( $post_id, '_uc_start_time', true );
        $ts    = $date ? strtotime( $date . ' 12:00:00' ) : 0;

        $color = sfaf_event_category_color( $post_id );
        $slugs = implode( ' ', wp_list_pluck( sfaf_event_categories( $post_id ), 'slug' ) );

        ob_start();
        ?>
        <a class="uc-sidebar-row" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"
           data-category="<?php echo esc_attr( $slugs ); ?>"
           style="--cat-color: <?php echo esc_attr( $color ); ?>">
            <span class="uc-sidebar-date">
                <?php if ( $ts ) : ?>
                    <span class="uc-sidebar-mon"><?php echo esc_html( date_i18n( 'M', $ts ) ); ?></span>
                    <span class="uc-sidebar-day"><?php echo esc_html( date_i18n( 'j', $ts ) ); ?></span>
                <?php else : ?>
                    <span class="uc-sidebar-mon">TBC</span>
                <?php endif; ?>
            </span>
            <span class="uc-sidebar-body">
                <span class="uc-sidebar-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
                <?php if ( '' !== $start ) : ?>
                    <span class="uc-sidebar-time"><?php echo esc_html( date_i18n( 'g:i A', strtotime( $start ) ) ); ?></span>
                <?php endif; ?>
            </span>
        </a>
        <?php
        return ob_get_clean();
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
    private function render_view_toggle( $view ) {
        $options = array(
            'list'     => array( 'Show events as a list', 'menu' ),
            'calendar' => array( 'Show events on a calendar', 'calendar' ),
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
        while ( $query->have_posts() ) {
            $query->the_post();
            if ( 'compact' === $render ) {
                echo $this->render_compact_card( get_the_ID() );
            } elseif ( 'sidebar' === $render ) {
                echo $this->render_sidebar_row( get_the_ID() );
            } else {
                echo $this->render_event_card( get_the_ID() );
            }
        }
        wp_reset_postdata();

        return array(
            'html'      => ob_get_clean(),
            'total'     => (int) $query->found_posts,
            'max_pages' => (int) $query->max_num_pages,
            'page'      => max( 1, (int) $paged ),
        );
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
            'layout'       => 'cards',
            // The same three display modes the embed offers, from the same
            // renderer. view="calendar" opens on the month grid, view="sidebar"
            // renders the narrow column, count="10" sizes it.
            'view'         => 'list',
            'toggle'       => 'yes',
            'month'        => '',
            'count'        => '',
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
            'layout'       => 'cards',
            'page'         => 0,
            'view'         => 'list',
            'toggle'       => 'yes',
            'month'        => '',
            'count'        => 0,
            // What the visitor picked, as distinct from what the block is
            // scoped to. See effective_category().
            'active_category' => null,
        ) );

        $filters = $this->normalize_filters( $args );
        $view    = $this->normalize_view( $args['view'] );

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
        $scope_category  = $filters['category'];
        $active_category = ( null === $args['active_category'] )
            ? $this->requested_category()
            : $this->slug_list( $args['active_category'] );

        $requested           = $active_category;
        $filters['category'] = $this->effective_category( $scope_category, $requested );

        /*
         * A BUTTON IS ONLY MARKED PRESSED WHEN IT IS WHAT IS BEING SHOWN. If the
         * chosen category fell outside the block's scope it was dropped, and the
         * list is the block's own events; lighting up a chip in that state would
         * label the list as something it is not.
         */
        $active_category = ( '' !== $requested && $filters['category'] === $requested ) ? $filters['category'] : '';

        // Sidebar is a different shape entirely: no filter bar, no pagination,
        // no toggle, a count rather than a page size. It returns early rather
        // than threading "unless sidebar" through everything below.
        if ( 'sidebar' === $view ) {
            $count = (int) $args['count'];
            if ( $count <= 0 ) {
                $count = 10;
            }
            return array(
                'html'      => $this->render_sidebar( $filters, $count ),
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
        $month    = $this->normalize_month( $args['month'] );

        if ( (int) $args['page'] > 0 ) {
            $paged = max( 1, (int) $args['page'] );
        } else {
            $paged = ( $paginate && $style === 'pages' && isset( $_GET['uc_page'] ) ) ? max( 1, intval( $_GET['uc_page'] ) ) : 1;
        }

        $events = $this->render_events( $per_page, $paged, $filters, $compact ? 'compact' : 'card' );
        $max    = $paginate ? $events['max_pages'] : 1;

        // Controls that cannot work from another origin are dropped in an
        // embed rather than rendered dead. See the organizer filter below.
        $embed = sfaf_is_embed_context();

        ob_start();
        ?>
        <div class="uc-calendar<?php echo $compact ? ' uc-calendar-compact' : ''; ?> uc-view-<?php echo esc_attr( $view ); ?>"
             data-category="<?php echo esc_attr( $filters['category'] ); ?>"
             data-render="<?php echo $compact ? 'compact' : 'card'; ?>"
             data-per-page="<?php echo (int) $per_page; ?>"
             <?php // The block's own scope, which a chip may narrow and can never widen. ?>
             data-scope-category="<?php echo esc_attr( $scope_category ); ?>"
             data-active-category="<?php echo esc_attr( $active_category ); ?>"
             data-filter-category="<?php echo esc_attr( $filters['category'] ); ?>"
             data-filter-organizer="<?php echo esc_attr( $filters['organizer'] ); ?>"
             data-filter-series="<?php echo (int) $filters['series']; ?>"
             data-filter-venue="<?php echo esc_attr( $filters['venue'] ); ?>"
             <?php // Carried so paging and "load more" keep the search applied. ?>
             data-filter-s="<?php echo esc_attr( $filters['s'] ); ?>"
             data-pagination="<?php echo esc_attr( $style ); ?>"
             data-page="<?php echo (int) $paged; ?>"
             data-view="<?php echo esc_attr( $view ); ?>"
             data-month="<?php echo esc_attr( $month ); ?>"
             data-max-pages="<?php echo (int) $max; ?>">

            <?php if ( $this->show_filters( $args['show_filters'] ) ) : ?>
            <div class="uc-filters">
                <div class="uc-search-wrap">
                    <?php
                    /*
                     * The value is rendered back in so a search survives a
                     * page load: the shortcode accepts s="…", and the embed
                     * re-renders the whole block on every search.
                     */
                    ?>
                    <input type="search" class="uc-search" value="<?php echo esc_attr( $filters['s'] ); ?>"
                           aria-label="Search events" placeholder="Search events..." />
                </div>
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
                            <?php echo esc_html( $cat->name ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php
                /*
                 * The organizer dropdown is a client-side stub on this site and
                 * is left out of embeds rather than shipped dead: embed blocks
                 * are normally already scoped to an organizer, and a control
                 * that does nothing on someone else's page is worse than none.
                 */
                if ( ! $embed ) :
                ?>
                <div class="uc-organizer-filter">
                    <select class="uc-organizer-select">
                        <option value="all">All Organizers</option>
                        <?php
                        $organizers = get_terms( array(
                            'taxonomy'   => 'uc_organizer',
                            'hide_empty' => true,
                        ) );
                        foreach ( $organizers as $org ) :
                        ?>
                            <option value="<?php echo esc_attr( $org->slug ); ?>"><?php echo esc_html( $org->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
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
                <div class="uc-event-count">
                    <span class="uc-count-number"><?php echo (int) $events['total']; ?></span>
                    <?php echo esc_html( _n( 'event coming up', 'events coming up', (int) $events['total'] ) ); ?>
                </div>
                <?php if ( $toggle ) { echo $this->render_view_toggle( $view ); } ?>
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
             */
            $want_list = ( $toggle || 'list' === $view );
            $want_grid = ( $toggle || 'calendar' === $view );
            ?>

            <div class="uc-view-panel uc-panel-list"<?php echo ( 'list' === $view ) ? '' : ' hidden'; ?>>
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

            <div class="uc-view-panel uc-panel-calendar"<?php echo ( 'calendar' === $view ) ? '' : ' hidden'; ?>>
                <?php if ( $want_grid ) { echo $this->render_month_grid( $month, $filters ); } ?>
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
        return array(
            'html'      => ob_get_clean(),
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
        foreach ( array( 'category', 'organizer', 'series', 'venue' ) as $key ) {
            $filters[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
        }
        // Clamped exactly as the list is, so choosing a category narrows the
        // month grid too and cannot widen it. The transient key below is built
        // from the normalized filters, so a clamped request shares the cache
        // entry with the honest one rather than creating a second.
        $filters['category'] = $this->effective_category(
            isset( $_POST['scope_category'] ) ? sanitize_text_field( wp_unslash( $_POST['scope_category'] ) ) : '',
            $filters['category']
        );

        $key    = 'sfaf_month_' . md5( wp_json_encode( array(
            'month'   => $month,
            'filters' => $this->normalize_filters( $filters ),
            'day'     => current_time( 'Y-m-d' ),
            'version' => (int) get_option( SFAF_Embed::CACHE_VERSION_OPTION, 1 ),
        ) ) );
        $cached = get_transient( $key );

        if ( is_string( $cached ) && '' !== $cached ) {
            wp_send_json_success( array( 'html' => $cached, 'month' => $month, 'cached' => true ) );
        }

        $html = $this->render_month_grid( $month, $filters );
        set_transient( $key, $html, 10 * MINUTE_IN_SECONDS );

        wp_send_json_success( array( 'html' => $html, 'month' => $month, 'cached' => false ) );
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
        $location   = get_post_meta( $post_id, '_uc_location', true );

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
        $organizers = wp_get_post_terms( $post_id, 'uc_organizer' );

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
        $source = sfaf_event_source_label( $post_id );
        $byline = ( '' !== $source )
            ? $source
            : ( ( ! is_wp_error( $organizers ) && ! empty( $organizers ) ) ? $organizers[0]->name : '' );

        // strtotime( '' ) is false, not 0, and date() on false silently means
        // "now". That is how an undated event would have printed today's
        // date as its own. An undated event renders no date block.
        $date_ts = $date ? strtotime( $date ) : false;

        $time = '';
        if ( $start_time ) {
            $time = date_i18n( 'g:i A', strtotime( $start_time ) );
            if ( $end_time ) {
                $time .= ' to ' . date_i18n( 'g:i A', strtotime( $end_time ) );
            }
        }

        $permalink = get_permalink( $post_id );

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

        $summary = wp_trim_words( get_the_excerpt( $post_id ) ?: get_the_content( null, false, $post_id ), 25 );

        ob_start();
        ?>
        <div class="uc-event-card uc-lc" data-category="<?php echo esc_attr( $cat_slugs ); ?>"
             style="--uc-cat: <?php echo esc_attr( $cat_color ); ?>; --uc-cat-tint: <?php echo esc_attr( $shades['tint'] ); ?>; --uc-cat-media: <?php echo esc_attr( $shades['media'] ); ?>; --uc-cat-ink: <?php echo esc_attr( $shades['ink'] ); ?>">

            <div class="uc-lc-head">
                <div class="uc-lc-ident">
                    <?php echo $chips; ?>
                    <?php if ( '' !== $byline ) : ?>
                        <span class="uc-lc-byline"><?php echo esc_html( $byline ); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( $date_ts ) : ?>
                    <div class="uc-lc-date">
                        <span class="uc-lc-dow"><?php echo esc_html( date_i18n( 'D', $date_ts ) ); ?></span>
                        <span class="uc-lc-md"><?php echo esc_html( date_i18n( 'M j', $date_ts ) ); ?></span>
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
                <a href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true"><?php echo sfaf_list_card_media( $post_id, $cat_name ); ?></a>
            </div>

            <h3 class="uc-card-title uc-lc-title">
                <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
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
        $location   = get_post_meta( $post_id, '_uc_location', true );

        // First category for the accent stripe, every slug for the filter. Same
        // rule as the full card; see render_event_card().
        $categories = sfaf_event_categories( $post_id );
        $cat_color  = ! empty( $categories ) ? sfaf_category_color( $categories[0]->term_id ) : sfaf_default_category_color();
        $cat_slugs  = ! empty( $categories ) ? implode( ' ', wp_list_pluck( $categories, 'slug' ) ) : '';

        $date_ts = strtotime( $date );

        ob_start();
        ?>
        <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="uc-compact-card"
           data-category="<?php echo esc_attr( $cat_slugs ); ?>">
            <div class="uc-compact-accent" style="background: <?php echo esc_attr( $cat_color ); ?>"></div>
            <div class="uc-compact-thumb"><?php echo sfaf_event_thumbnail( $post_id, 'thumbnail' ); ?></div>
            <div class="uc-compact-date">
                <span class="uc-compact-month"><?php echo esc_html( date( 'M', $date_ts ) ); ?></span>
                <span class="uc-compact-day"><?php echo esc_html( date( 'j', $date_ts ) ); ?></span>
            </div>
            <div class="uc-compact-info">
                <div class="uc-compact-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></div>
                <div class="uc-compact-meta">
                    <?php if ( $start_time ) : ?>
                        <?php echo esc_html( date( 'g:i A', strtotime( $start_time ) ) ); ?>
                    <?php endif; ?>
                    <?php if ( $location ) : ?>
                        &middot; <?php echo esc_html( $location ); ?>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php
        return ob_get_clean();
    }
}
