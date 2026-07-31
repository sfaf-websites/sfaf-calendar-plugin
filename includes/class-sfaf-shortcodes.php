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

        // Series: given a series parent ID, return that parent's occurrences.
        // Every occurrence stores the parent ID in _uc_series_parent, and the
        // parent points at itself, so one clause covers parent and children.
        if ( $filters['series'] > 0 ) {
            $args['meta_query'][] = array(
                'key'   => '_uc_series_parent',
                'value' => $filters['series'],
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
        if ( $filters['series'] > 0 ) {
            $args['meta_query'][] = array( 'key' => '_uc_series_parent', 'value' => $filters['series'] );
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
                                            $cats  = wp_get_post_terms( $id, 'uc_event_category' );
                                            $color = ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? sfaf_category_color( $cats[0]->term_id ) : '#16BECF';
                                            echo '<span class="uc-day-dot" style="background:' . esc_attr( $color ) . '"></span>';
                                        }
                                    ?></span>
                                    <ul class="uc-day-events">
                                        <?php foreach ( $ids as $id ) :
                                            $start = (string) get_post_meta( $id, '_uc_start_time', true );
                                            $cats  = wp_get_post_terms( $id, 'uc_event_category' );
                                            $color = ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? sfaf_category_color( $cats[0]->term_id ) : '#16BECF';
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

        $cats  = wp_get_post_terms( $post_id, 'uc_event_category' );
        $color = ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? sfaf_category_color( $cats[0]->term_id ) : '#16BECF';

        ob_start();
        ?>
        <a class="uc-sidebar-row" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"
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
            'per_page'     => '',
            'show_filters' => 'yes',
            'layout'       => 'cards',
            'page'         => 0,
            'view'         => 'list',
            'toggle'       => 'yes',
            'month'        => '',
            'count'        => 0,
        ) );

        $filters = $this->normalize_filters( $args );
        $view    = $this->normalize_view( $args['view'] );

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
             data-filter-category="<?php echo esc_attr( $filters['category'] ); ?>"
             data-filter-organizer="<?php echo esc_attr( $filters['organizer'] ); ?>"
             data-filter-series="<?php echo (int) $filters['series']; ?>"
             data-filter-venue="<?php echo esc_attr( $filters['venue'] ); ?>"
             data-pagination="<?php echo esc_attr( $style ); ?>"
             data-page="<?php echo (int) $paged; ?>"
             data-view="<?php echo esc_attr( $view ); ?>"
             data-month="<?php echo esc_attr( $month ); ?>"
             data-max-pages="<?php echo (int) $max; ?>">

            <?php if ( $this->show_filters( $args['show_filters'] ) ) : ?>
            <div class="uc-filters">
                <div class="uc-search-wrap">
                    <input type="text" class="uc-search" placeholder="Search events..." />
                </div>
                <div class="uc-filter-buttons">
                    <button class="uc-filter-btn active" data-category="all">All Events</button>
                    <?php
                    $categories = get_terms( array(
                        'taxonomy'   => 'uc_event_category',
                        'hide_empty' => true,
                    ) );
                    foreach ( $categories as $cat ) :
                        $color = sfaf_category_color( $cat->term_id );
                    ?>
                        <button class="uc-filter-btn" data-category="<?php echo esc_attr( $cat->slug ); ?>"
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
        foreach ( array( 'category', 'organizer', 'series', 'venue' ) as $key ) {
            $filters[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
        }

        if ( $per_page <= 0 ) {
            wp_send_json( array( 'html' => '', 'has_more' => false ) );
        }

        $events = $this->render_events( $per_page, $page, $filters, $render );

        wp_send_json( array(
            'html'     => $events['html'],
            'has_more' => ( $page < $events['max_pages'] ),
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
     * Render a full event card
     */
    private function render_event_card( $post_id ) {
        $date         = get_post_meta( $post_id, '_uc_event_date', true );
        $start_time   = get_post_meta( $post_id, '_uc_start_time', true );
        $end_time     = get_post_meta( $post_id, '_uc_end_time', true );
        $location     = get_post_meta( $post_id, '_uc_location', true );
        $recurrence   = get_post_meta( $post_id, '_uc_recurrence', true );

        $categories = wp_get_post_terms( $post_id, 'uc_event_category' );
        $organizers = wp_get_post_terms( $post_id, 'uc_organizer' );

        $cat_slug  = ! empty( $categories ) ? $categories[0]->slug : '';
        $cat_name  = ! empty( $categories ) ? $categories[0]->name : '';
        $cat_color = ! empty( $categories ) ? sfaf_category_color( $categories[0]->term_id ) : '#16BECF';
        $org_name  = ! empty( $organizers ) ? $organizers[0]->name : '';

        $date_ts   = strtotime( $date );
        $month     = date( 'M', $date_ts );
        $day       = date( 'j', $date_ts );
        $weekday   = date( 'D', $date_ts );

        $recurrence_labels = array(
            'daily'    => 'Daily',
            'weekly'   => 'Weekly',
            'biweekly' => 'Every 2 Weeks',
            'monthly'  => 'Monthly',
        );

        // Action buttons (each helper respects its display toggle).
        $actions = sfaf_add_to_calendar( $post_id ) . sfaf_reminders_button( $post_id ) . sfaf_social_share_buttons( $post_id, true );

        // Date and time as one phrase, because that is how it is read: "Sat 15
        // Aug, 6:00 PM". It leads the meta row on an events calendar, ahead of
        // organizer, because it is the thing a visitor is actually deciding on.
        $when = $date_ts ? date_i18n( 'D j M', $date_ts ) : '';
        if ( $start_time ) {
            $when .= ( '' !== $when ? ', ' : '' ) . date_i18n( 'g:i A', strtotime( $start_time ) );
            if ( $end_time ) {
                $when .= ' to ' . date_i18n( 'g:i A', strtotime( $end_time ) );
            }
        }

        ob_start();
        ?>
        <div class="uc-event-card" data-category="<?php echo esc_attr( $cat_slug ); ?>">
            <?php
            /*
             * FULL-WIDTH BANNER, NOT A CROPPED BLOCK.
             *
             * The old card put a 150px 4:3 thumbnail on the left. The branded
             * placeholder is a 1600x900 SVG with preserveAspectRatio="slice",
             * so squeezing it into 4:3 cut both sides off the centred label and
             * rendered "Program Groups" as "gram Gro". Roughly half of imported
             * GoFundMe Pro events will never have a real image, because their
             * API does not expose one, so that placeholder is not an edge case.
             *
             * At full card width in its own 16:9 ratio the SVG fits exactly,
             * nothing is sliced, and the placeholder reads as a deliberate
             * branded banner rather than a failed image.
             */
            ?>
            <div class="uc-card-banner">
                <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" tabindex="-1" aria-hidden="true">
                    <?php echo sfaf_event_thumbnail( $post_id, 'large' ); ?>
                </a>
                <span class="uc-card-datechip">
                    <span class="uc-card-month"><?php echo esc_html( $month ); ?></span>
                    <span class="uc-card-day"><?php echo esc_html( $day ); ?></span>
                    <span class="uc-card-weekday"><?php echo esc_html( $weekday ); ?></span>
                </span>
            </div>

            <div class="uc-card-accent" style="background: <?php echo esc_attr( $cat_color ); ?>"></div>

            <div class="uc-card-content">
                <div class="uc-card-badges">
                    <?php if ( $cat_name ) : ?>
                        <span class="uc-badge" style="--badge-color: <?php echo esc_attr( $cat_color ); ?>">
                            <?php echo esc_html( $cat_name ); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( $recurrence && isset( $recurrence_labels[ $recurrence ] ) ) : ?>
                        <span class="uc-badge uc-badge-recurrence"><?php echo sfaf_icon( 'repeat' ); ?> <?php echo esc_html( $recurrence_labels[ $recurrence ] ); ?></span>
                    <?php endif; ?>
                    <?php if ( sfaf_is_galaxy_need( $post_id ) ) : ?>
                        <span class="uc-badge uc-badge-volunteer"><?php echo sfaf_icon( 'handshake' ); ?> Volunteer</span>
                    <?php endif; ?>
                </div>

                <h3 class="uc-card-title">
                    <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
                </h3>

                <p class="uc-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt( $post_id ) ?: get_the_content( null, false, $post_id ), 25 ) ); ?></p>

                <div class="uc-card-meta">
                    <?php if ( '' !== $when ) : ?>
                        <span class="uc-meta-item uc-meta-when"><?php echo sfaf_icon( 'clock' ); ?> <?php echo esc_html( $when ); ?></span>
                    <?php endif; ?>
                    <?php if ( $location ) : ?>
                        <span class="uc-meta-item"><?php echo sfaf_icon( 'pin' ); ?> <?php echo esc_html( $location ); ?></span>
                    <?php endif; ?>
                    <?php if ( $org_name ) : ?>
                        <span class="uc-meta-item uc-meta-organizer"><?php echo esc_html( $org_name ); ?></span>
                    <?php endif; ?>
                </div>

                <?php
                $series_link = sfaf_series_link( $post_id );
                if ( $series_link ) {
                    echo '<div class="uc-card-series">' . $series_link . '</div>';
                }

                // Donate block + Galaxy volunteer block + RSVP block (helpers handle visibility).
                echo sfaf_donate_block( $post_id );
                echo sfaf_galaxy_block( $post_id );
                echo sfaf_rsvp_block( $post_id );

                if ( trim( $actions ) !== '' ) :
                ?>
                    <div class="uc-card-actions"><?php echo $actions; ?></div>
                <?php endif; ?>
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

        $categories = wp_get_post_terms( $post_id, 'uc_event_category' );
        $cat_color  = ! empty( $categories ) ? sfaf_category_color( $categories[0]->term_id ) : '#16BECF';
        $cat_slug   = ! empty( $categories ) ? $categories[0]->slug : '';

        $date_ts = strtotime( $date );

        ob_start();
        ?>
        <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="uc-compact-card"
           data-category="<?php echo esc_attr( $cat_slug ); ?>">
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
