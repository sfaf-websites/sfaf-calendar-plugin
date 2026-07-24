<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Shortcodes {

    public function register() {
        add_shortcode( 'sfaf_calendar', array( $this, 'render_calendar' ) );
        add_shortcode( 'upcoming_events', array( $this, 'render_upcoming' ) );
        add_action( 'wp_ajax_uc_load_events', array( $this, 'ajax_load_events' ) );
        add_action( 'wp_ajax_nopriv_uc_load_events', array( $this, 'ajax_load_events' ) );
    }

    /* ---------------------------------------------------------------------
     * Pagination helpers
     * ------------------------------------------------------------------- */

    /**
     * Resolve per-page: a shortcode attribute overrides the global setting.
     * 0 or -1 means "show all events with no pagination".
     */
    private function resolve_per_page( $raw ) {
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

    /** Numbered Next/Previous page links (uses the uc_page query arg). */
    private function render_page_links( $current, $max ) {
        ob_start();
        ?>
        <nav class="uc-pagination uc-pagination-pages" aria-label="Events pagination">
            <?php if ( $current > 1 ) : ?>
                <a class="uc-page-link uc-page-prev" href="<?php echo esc_url( add_query_arg( 'uc_page', $current - 1 ) ); ?>">&larr; Previous</a>
            <?php endif; ?>
            <span class="uc-page-numbers">
                <?php for ( $i = 1; $i <= $max; $i++ ) : ?>
                    <?php if ( $i === (int) $current ) : ?>
                        <span class="uc-page-num current"><?php echo (int) $i; ?></span>
                    <?php else : ?>
                        <a class="uc-page-num" href="<?php echo esc_url( add_query_arg( 'uc_page', $i ) ); ?>"><?php echo (int) $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </span>
            <?php if ( $current < $max ) : ?>
                <a class="uc-page-link uc-page-next" href="<?php echo esc_url( add_query_arg( 'uc_page', $current + 1 ) ); ?>">Next &rarr;</a>
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
        ), $atts );

        $filters  = $this->normalize_filters( $atts );
        $compact  = ( $atts['layout'] === 'compact' );
        $per_page = $this->resolve_per_page( $atts['per_page'] );
        $paginate = ( $per_page > 0 );
        $style    = $this->pagination_style();
        $paged    = ( $paginate && $style === 'pages' && isset( $_GET['uc_page'] ) ) ? max( 1, intval( $_GET['uc_page'] ) ) : 1;

        $query = new WP_Query( $this->build_query_args( $per_page, $paged, $filters ) );
        $max   = $paginate ? (int) $query->max_num_pages : 1;
        sfaf_prime_rsvp_counts( wp_list_pluck( $query->posts, 'ID' ) );

        ob_start();
        ?>
        <div class="uc-calendar<?php echo $compact ? ' uc-calendar-compact' : ''; ?>"
             data-category="<?php echo esc_attr( $filters['category'] ); ?>"
             data-render="<?php echo $compact ? 'compact' : 'card'; ?>"
             data-per-page="<?php echo (int) $per_page; ?>"
             data-filter-category="<?php echo esc_attr( $filters['category'] ); ?>"
             data-filter-organizer="<?php echo esc_attr( $filters['organizer'] ); ?>"
             data-filter-series="<?php echo (int) $filters['series']; ?>"
             data-filter-venue="<?php echo esc_attr( $filters['venue'] ); ?>"
             data-pagination="<?php echo esc_attr( $style ); ?>"
             data-page="<?php echo (int) $paged; ?>"
             data-max-pages="<?php echo (int) $max; ?>">

            <?php if ( $atts['show_filters'] === 'yes' ) : ?>
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
            </div>
            <?php endif; ?>

            <div class="uc-event-count">
                <span class="uc-count-number"><?php echo (int) $query->found_posts; ?></span> upcoming events
            </div>

            <div class="uc-event-list">
                <?php if ( $query->have_posts() ) : ?>
                    <?php while ( $query->have_posts() ) : $query->the_post(); ?>
                        <?php echo $compact ? $this->render_compact_card( get_the_ID() ) : $this->render_event_card( get_the_ID() ); ?>
                    <?php endwhile; ?>
                    <?php wp_reset_postdata(); ?>
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

            <div class="uc-subscribe">
                <span>Subscribe:</span>
                <a href="<?php echo esc_url( home_url( '?uc_ical=1' ) ); ?>" class="uc-subscribe-btn">+ Google Calendar</a>
                <a href="<?php echo esc_url( home_url( '?uc_ical=1' ) ); ?>" class="uc-subscribe-btn">+ iCalendar</a>
                <a href="<?php echo esc_url( home_url( '?uc_ical=1' ) ); ?>" class="uc-subscribe-btn">+ Outlook</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
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

        $query = new WP_Query( $this->build_query_args( $per_page, $paged, $filters ) );
        $max   = $paginate ? (int) $query->max_num_pages : 1;
        sfaf_prime_rsvp_counts( wp_list_pluck( $query->posts, 'ID' ) );

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
                <?php if ( $query->have_posts() ) : ?>
                    <?php while ( $query->have_posts() ) : $query->the_post(); ?>
                        <?php echo $this->render_compact_card( get_the_ID() ); ?>
                    <?php endwhile; ?>
                    <?php wp_reset_postdata(); ?>
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

        $query = new WP_Query( $this->build_query_args( $per_page, $page, $filters ) );
        sfaf_prime_rsvp_counts( wp_list_pluck( $query->posts, 'ID' ) );

        ob_start();
        while ( $query->have_posts() ) {
            $query->the_post();
            echo ( $render === 'compact' ) ? $this->render_compact_card( get_the_ID() ) : $this->render_event_card( get_the_ID() );
        }
        wp_reset_postdata();
        $html = ob_get_clean();

        wp_send_json( array(
            'html'     => $html,
            'has_more' => ( $page < (int) $query->max_num_pages ),
        ) );
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

        ob_start();
        ?>
        <div class="uc-event-card" data-category="<?php echo esc_attr( $cat_slug ); ?>">
            <div class="uc-card-accent" style="background: <?php echo esc_attr( $cat_color ); ?>"></div>

            <div class="uc-card-thumb"><?php echo sfaf_event_thumbnail( $post_id, 'medium' ); ?></div>

            <div class="uc-card-date">
                <span class="uc-card-month"><?php echo esc_html( $month ); ?></span>
                <span class="uc-card-day"><?php echo esc_html( $day ); ?></span>
                <span class="uc-card-weekday"><?php echo esc_html( $weekday ); ?></span>
            </div>

            <div class="uc-card-content">
                <div class="uc-card-badges">
                    <?php if ( $cat_name ) : ?>
                        <span class="uc-badge" style="--badge-color: <?php echo esc_attr( $cat_color ); ?>">
                            <?php echo esc_html( $cat_name ); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( $recurrence && isset( $recurrence_labels[ $recurrence ] ) ) : ?>
                        <span class="uc-badge uc-badge-recurrence">🔄 <?php echo esc_html( $recurrence_labels[ $recurrence ] ); ?></span>
                    <?php endif; ?>
                    <?php if ( sfaf_is_galaxy_need( $post_id ) ) : ?>
                        <span class="uc-badge uc-badge-volunteer">🤝 Volunteer</span>
                    <?php endif; ?>
                </div>

                <h3 class="uc-card-title">
                    <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
                </h3>

                <p class="uc-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt( $post_id ) ?: get_the_content( null, false, $post_id ), 25 ) ); ?></p>

                <div class="uc-card-meta">
                    <?php if ( $start_time ) : ?>
                        <span class="uc-meta-item">🕐 <?php echo esc_html( date( 'g:i A', strtotime( $start_time ) ) ); ?><?php echo $end_time ? ' - ' . esc_html( date( 'g:i A', strtotime( $end_time ) ) ) : ''; ?></span>
                    <?php endif; ?>
                    <?php if ( $location ) : ?>
                        <span class="uc-meta-item">📍 <?php echo esc_html( $location ); ?></span>
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

        $date_ts = strtotime( $date );

        ob_start();
        ?>
        <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="uc-compact-card">
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
