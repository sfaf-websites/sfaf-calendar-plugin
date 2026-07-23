<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class UC_Shortcodes {

    public function register() {
        add_shortcode( 'unified_calendar', array( $this, 'render_calendar' ) );
        add_shortcode( 'upcoming_events', array( $this, 'render_upcoming' ) );
    }

    /**
     * Main calendar shortcode: [unified_calendar]
     * Attributes: category, organizer, per_page, show_filters
     */
    public function render_calendar( $atts ) {
        $atts = shortcode_atts( array(
            'category'     => '',
            'organizer'    => '',
            'per_page'     => 12,
            'show_filters' => 'yes',
        ), $atts );

        $args = array(
            'post_type'      => 'uc_event',
            'posts_per_page' => intval( $atts['per_page'] ),
            'post_status'    => 'publish',
            'meta_key'       => '_uc_event_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'     => '_uc_event_date',
                    'value'   => date( 'Y-m-d' ),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ),
            ),
        );

        if ( $atts['category'] ) {
            $args['tax_query'][] = array(
                'taxonomy' => 'uc_event_category',
                'field'    => 'slug',
                'terms'    => explode( ',', $atts['category'] ),
            );
        }

        if ( $atts['organizer'] ) {
            $args['tax_query'][] = array(
                'taxonomy' => 'uc_organizer',
                'field'    => 'slug',
                'terms'    => explode( ',', $atts['organizer'] ),
            );
        }

        $query = new WP_Query( $args );

        ob_start();
        ?>
        <div class="uc-calendar" data-category="<?php echo esc_attr( $atts['category'] ); ?>">
            
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
                        $color = get_term_meta( $cat->term_id, '_uc_category_color', true ) ?: '#16BECF';
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
                <span class="uc-count-number"><?php echo $query->found_posts; ?></span> upcoming events
            </div>

            <div class="uc-event-list">
                <?php if ( $query->have_posts() ) : ?>
                    <?php while ( $query->have_posts() ) : $query->the_post(); ?>
                        <?php echo $this->render_event_card( get_the_ID() ); ?>
                    <?php endwhile; ?>
                    <?php wp_reset_postdata(); ?>
                <?php else : ?>
                    <div class="uc-no-events">
                        <p>No upcoming events found.</p>
                    </div>
                <?php endif; ?>
            </div>

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
     * Attributes: category, organizer, count
     * Use on any page to show upcoming events for that group
     */
    public function render_upcoming( $atts ) {
        $atts = shortcode_atts( array(
            'category'  => '',
            'organizer' => '',
            'count'     => 5,
            'title'     => 'Upcoming Events',
        ), $atts );

        $args = array(
            'post_type'      => 'uc_event',
            'posts_per_page' => intval( $atts['count'] ),
            'post_status'    => 'publish',
            'meta_key'       => '_uc_event_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'     => '_uc_event_date',
                    'value'   => date( 'Y-m-d' ),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ),
            ),
        );

        if ( $atts['category'] ) {
            $args['tax_query'][] = array(
                'taxonomy' => 'uc_event_category',
                'field'    => 'slug',
                'terms'    => explode( ',', $atts['category'] ),
            );
        }

        if ( $atts['organizer'] ) {
            $args['tax_query'][] = array(
                'taxonomy' => 'uc_organizer',
                'field'    => 'slug',
                'terms'    => explode( ',', $atts['organizer'] ),
            );
        }

        $query = new WP_Query( $args );

        ob_start();
        ?>
        <div class="uc-upcoming-widget">
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
        </div>
        <?php
        return ob_get_clean();
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
        $rsvp_enabled = get_post_meta( $post_id, '_uc_rsvp_enabled', true );
        $capacity     = get_post_meta( $post_id, '_uc_capacity', true );
        $rsvp_count   = uc_get_rsvp_count( $post_id );
        $gofundme     = get_post_meta( $post_id, '_uc_gofundme_campaign', true );

        $categories = wp_get_post_terms( $post_id, 'uc_event_category' );
        $organizers = wp_get_post_terms( $post_id, 'uc_organizer' );

        $cat_slug  = ! empty( $categories ) ? $categories[0]->slug : '';
        $cat_name  = ! empty( $categories ) ? $categories[0]->name : '';
        $cat_color = ! empty( $categories ) ? ( get_term_meta( $categories[0]->term_id, '_uc_category_color', true ) ?: '#16BECF' ) : '#16BECF';
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

        ob_start();
        ?>
        <div class="uc-event-card" data-category="<?php echo esc_attr( $cat_slug ); ?>">
            <div class="uc-card-accent" style="background: <?php echo esc_attr( $cat_color ); ?>"></div>
            
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
                </div>

                <h3 class="uc-card-title">
                    <a href="<?php echo get_permalink( $post_id ); ?>"><?php echo get_the_title( $post_id ); ?></a>
                </h3>
                
                <p class="uc-card-excerpt"><?php echo wp_trim_words( get_the_excerpt( $post_id ) ?: get_the_content( null, false, $post_id ), 25 ); ?></p>

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

                <?php if ( $rsvp_enabled === '1' ) : ?>
                    <div class="uc-card-rsvp">
                        <?php if ( $capacity > 0 ) : ?>
                            <div class="uc-capacity-bar">
                                <div class="uc-capacity-fill" style="width: <?php echo min( ( $rsvp_count / $capacity ) * 100, 100 ); ?>%"></div>
                            </div>
                            <span class="uc-capacity-text"><?php echo $rsvp_count; ?>/<?php echo $capacity; ?> spots filled</span>
                        <?php else : ?>
                            <span class="uc-capacity-text"><?php echo $rsvp_count; ?> registered</span>
                        <?php endif; ?>
                        <button class="uc-rsvp-btn" data-event-id="<?php echo $post_id; ?>">RSVP</button>
                    </div>
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
        $cat_color  = ! empty( $categories ) ? ( get_term_meta( $categories[0]->term_id, '_uc_category_color', true ) ?: '#16BECF' ) : '#16BECF';

        $date_ts = strtotime( $date );

        ob_start();
        ?>
        <a href="<?php echo get_permalink( $post_id ); ?>" class="uc-compact-card">
            <div class="uc-compact-accent" style="background: <?php echo esc_attr( $cat_color ); ?>"></div>
            <div class="uc-compact-date">
                <span class="uc-compact-month"><?php echo date( 'M', $date_ts ); ?></span>
                <span class="uc-compact-day"><?php echo date( 'j', $date_ts ); ?></span>
            </div>
            <div class="uc-compact-info">
                <div class="uc-compact-title"><?php echo get_the_title( $post_id ); ?></div>
                <div class="uc-compact-meta">
                    <?php if ( $start_time ) : ?>
                        <?php echo date( 'g:i A', strtotime( $start_time ) ); ?>
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
