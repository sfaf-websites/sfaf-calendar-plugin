<?php
/**
 * Single event template for the SFAF Calendar plugin.
 * Loaded via the template_include filter unless the theme provides its own
 * single-uc_event.php.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

while ( have_posts() ) :
    the_post();
    $post_id = get_the_ID();

    $date       = get_post_meta( $post_id, '_uc_event_date', true );
    $start_time = get_post_meta( $post_id, '_uc_start_time', true );
    $end_time   = get_post_meta( $post_id, '_uc_end_time', true );
    $location   = get_post_meta( $post_id, '_uc_location', true );

    // The pattern that GENERATED this event, kept only so the page can say
    // "repeats weekly". It is a fact about where the event came from, not a
    // rule anything still follows: this event is complete in itself and
    // nothing regenerates it. See SFAF_Recurrence.
    $recurrence = SFAF_Recurrence::pattern_of( $post_id );

    $categories = wp_get_post_terms( $post_id, 'uc_event_category' );
    $organizers = wp_get_post_terms( $post_id, 'uc_organizer' );
    $venues     = wp_get_post_terms( $post_id, 'uc_venue' );

    $cat_color = ! empty( $categories ) ? ( get_term_meta( $categories[0]->term_id, '_uc_category_color', true ) ?: '#16BECF' ) : '#16BECF';

    $date_ts = $date ? strtotime( $date ) : false;

    $recurrence_label = $recurrence
        ? SFAF_Recurrence::pattern_label( $recurrence, $date )
        : '';

    $settings   = get_option( 'uc_settings', array() );
    $brand_logo = isset( $settings['brand_logo'] ) ? $settings['brand_logo'] : '';
    ?>
    <div class="uc-single" style="--event-color: <?php echo esc_attr( $cat_color ); ?>">
        <div class="uc-single-inner">

            <?php if ( $brand_logo ) : ?>
                <div class="uc-single-brand">
                    <img src="<?php echo esc_url( $brand_logo ); ?>" alt="" class="uc-single-logo" />
                </div>
            <?php endif; ?>

            <a href="<?php echo esc_url( get_post_type_archive_link( 'uc_event' ) ); ?>" class="uc-single-back">&larr; All Events</a>

            <header class="uc-single-header">
                <div class="uc-single-badges">
                    <?php foreach ( $categories as $cat ) :
                        $c = get_term_meta( $cat->term_id, '_uc_category_color', true ) ?: '#16BECF'; ?>
                        <span class="uc-badge" style="--badge-color: <?php echo esc_attr( $c ); ?>"><?php echo esc_html( $cat->name ); ?></span>
                    <?php endforeach; ?>
                    <?php if ( '' !== $recurrence_label ) : ?>
                        <span class="uc-badge uc-badge-recurrence"><?php echo sfaf_icon( 'repeat' ); ?> <?php echo esc_html( $recurrence_label ); ?></span>
                    <?php endif; ?>
                    <?php if ( sfaf_is_galaxy_need( $post_id ) ) : ?>
                        <span class="uc-badge uc-badge-volunteer"><?php echo sfaf_icon( 'handshake' ); ?> Volunteer</span>
                    <?php endif; ?>
                </div>

                <h1 class="uc-single-title"><?php the_title(); ?></h1>

                <?php if ( ! empty( $organizers ) ) : ?>
                    <p class="uc-single-organizer">Hosted by
                        <?php
                        $names = wp_list_pluck( $organizers, 'name' );
                        echo esc_html( implode( ', ', $names ) );
                        ?>
                    </p>
                <?php endif; ?>

                <?php
                $series_link = sfaf_series_link( $post_id );
                if ( $series_link ) {
                    echo '<p class="uc-single-series-link">' . $series_link . '</p>';
                }
                ?>
            </header>

            <div class="uc-single-grid">
                <div class="uc-single-main">
                    <?php if ( sfaf_event_image_url( $post_id ) ) : ?>
                        <div class="uc-single-image"><?php echo sfaf_event_thumbnail( $post_id, 'large' ); ?></div>
                    <?php endif; ?>

                    <div class="uc-single-body">
                        <?php the_content(); ?>
                    </div>

                    <?php
                    // Donate block (only shows when a campaign URL is set).
                    echo sfaf_donate_block( $post_id );

                    // Galaxy Digital volunteer signup (imported needs only).
                    echo sfaf_galaxy_block( $post_id );

                    // Frequently asked questions. This event's own, and the only
                    // ones there are — see sfaf_faq_meta_key().
                    echo sfaf_faq_accordion_html( $post_id );

                    // Other events in this series.
                    echo sfaf_series_list_html( $post_id );

                    /*
                     * Getting there: the address as a link, and a map behind a
                     * button. Nothing is requested from Google on page view.
                     * See the header of the location helpers in
                     * sfaf-template-functions.php for why that is not
                     * negotiable on these pages.
                     */
                    echo sfaf_event_map_html( $post_id );
                    ?>
                </div>

                <aside class="uc-single-sidebar">
                    <div class="uc-single-card">
                        <ul class="uc-single-facts">
                            <?php if ( $date_ts ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'calendar' ); ?></span>
                                    <span><strong><?php echo esc_html( date_i18n( 'l, F j, Y', $date_ts ) ); ?></strong></span>
                                </li>
                            <?php endif; ?>
                            <?php if ( $start_time ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'clock' ); ?></span>
                                    <span><?php echo esc_html( date( 'g:i A', strtotime( $start_time ) ) ); ?><?php echo $end_time ? ' – ' . esc_html( date( 'g:i A', strtotime( $end_time ) ) ) : ''; ?></span>
                                </li>
                            <?php endif; ?>
                            <?php if ( $location ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'pin' ); ?></span>
                                    <?php // A plain link to Google Maps, which costs a
                                          // visitor nothing until they follow it. The map
                                          // itself is further down, behind a button. ?>
                                    <span><a class="uc-fact-maplink" href="<?php echo esc_url( sfaf_map_search_url( $location ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $location ); ?></a></span>
                                </li>
                            <?php endif; ?>
                            <?php if ( ! empty( $venues ) ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'venue' ); ?></span>
                                    <span><?php echo esc_html( implode( ', ', wp_list_pluck( $venues, 'name' ) ) ); ?></span>
                                </li>
                            <?php endif; ?>
                        </ul>

                        <?php
                        // RSVP block.
                        echo sfaf_rsvp_block( $post_id );

                        // Add to calendar + reminders.
                        $secondary = sfaf_add_to_calendar( $post_id ) . sfaf_reminders_button( $post_id );
                        if ( trim( $secondary ) !== '' ) :
                        ?>
                            <div class="uc-single-actions"><?php echo $secondary; ?></div>
                        <?php endif; ?>

                        <?php
                        // Social share (full variant).
                        echo sfaf_social_share_buttons( $post_id );
                        ?>
                    </div>
                </aside>
            </div>
        </div>
    </div>
    <?php
endwhile;

get_footer();
