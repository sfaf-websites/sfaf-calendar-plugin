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
    $location   = sfaf_event_location( $post_id );

    // The pattern that GENERATED this event, kept only so the page can say
    // "repeats weekly". It is a fact about where the event came from, not a
    // rule anything still follows: this event is complete in itself and
    // nothing regenerates it. See SFAF_Recurrence.
    $recurrence = SFAF_Recurrence::pattern_of( $post_id );

    $venues     = wp_get_post_terms( $post_id, 'uc_venue' );

    $cat_color = sfaf_event_category_color( $post_id );

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

            <?php
            /*
             * CANCELLED, SAID FIRST AND SAID PLAINLY.
             *
             * Above everything, before the title, because it is the only thing
             * on this page that matters to somebody who came here to check when
             * it starts. A cancelled event that is set to STAY is reachable by
             * design: somebody who registered comes looking, and finding the
             * page unchanged with a working Register button would be worse than
             * the event having vanished.
             *
             * role="status" so a screen reader announces it rather than leaving
             * it to be discovered in reading order, and the word is in the text
             * rather than carried by the red alone.
             */
            if ( SFAF_Cancellation::is_cancelled( $post_id ) ) :
                $why = trim( (string) get_post_meta( $post_id, '_uc_cancelled_reason', true ) );
                ?>
                <div class="uc-single-cancelled" role="status">
                    <p class="uc-cancelled-head">This event has been cancelled.</p>
                    <?php if ( '' !== $why ) : ?>
                        <p class="uc-cancelled-why"><?php echo esc_html( $why ); ?></p>
                    <?php endif; ?>
                    <p class="uc-cancelled-note">
                        It is not going ahead. If you RSVP'd, you do not need to do anything.
                    </p>
                </div>
            <?php endif; ?>

            <?php
            /*
             * BACK TO THE CALENDAR THEY CAME FROM, NOT TO THIS SITE'S ARCHIVE.
             *
             * This page is served from the resources site. The calendar people
             * actually read is a page on sfaf.org, or an embed of it somewhere
             * else again. get_post_type_archive_link() pointed here, so "All
             * Events" took a visitor from a site they knew to one they had never
             * seen and that is not a public surface at all.
             *
             * sfaf_calendar_return_url() prefers the referrer, so it returns
             * them to the exact calendar they clicked from, and falls back to
             * the Calendar home URL setting when there is no referrer. See the
             * header of that function for what a referrer has to satisfy before
             * it is followed.
             */
            ?>
            <a href="<?php echo esc_url( sfaf_calendar_return_url() ); ?>" class="uc-single-back">&larr; All Events</a>

            <header class="uc-single-header">
                <div class="uc-single-badges">
                    <?php
                    // Every category, each one a link back to the calendar
                    // filtered to it. Same destination rule as the back link.
                    echo sfaf_category_chips_html( $post_id, 'single' );
                    ?>
                    <?php if ( '' !== $recurrence_label ) : ?>
                        <span class="uc-badge uc-badge-recurrence"><?php echo sfaf_icon( 'repeat' ); ?> <?php echo esc_html( $recurrence_label ); ?></span>
                    <?php endif; ?>
                    <?php if ( sfaf_is_galaxy_need( $post_id ) ) : ?>
                        <span class="uc-badge uc-badge-volunteer"><?php echo sfaf_icon( 'handshake' ); ?> Volunteer</span>
                    <?php endif; ?>
                </div>

                <h1 class="uc-single-title"><?php the_title(); ?></h1>

                <?php
                /*
                 * "Hosted by A and B", not "Hosted by A, B".
                 *
                 * This page already named every organizer an event had, joined
                 * with a comma, so the names were right and the English was
                 * not. SFAF_Organizers::phrase() is the one place the joining
                 * is decided, so the card, the search-engine listing and this
                 * cannot word it three ways. AP style, so no serial comma:
                 * "A, B and C".
                 *
                 * An event with ONE organizer renders exactly the string it
                 * always did.
                 */
                $organizer_phrase = SFAF_Organizers::phrase( $post_id );
                if ( '' !== $organizer_phrase ) :
                ?>
                    <p class="uc-single-organizer">Hosted by <?php echo esc_html( $organizer_phrase ); ?></p>
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

                    <?php
                    /*
                     * THE VIDEO, ABOVE THE DESCRIPTION AND ON THIS PAGE ONLY.
                     *
                     * SFAF_Video::resolve() is the whole rule about which video
                     * an event shows, so this asks nothing about series or ticks
                     * and cannot disagree with anything else. It returns an
                     * empty string when there is nothing to draw, which is every
                     * event that has not been given one.
                     *
                     * No card, no list row, no hover preview and no email calls
                     * this. A frame is 16:9 of somebody's screen and a card is
                     * 300px of a column; in mail it is an empty box, because no
                     * mail client runs an iframe.
                     */
                    echo SFAF_Video::embed_html( $post_id );
                    ?>

                    <div class="uc-single-body">
                        <?php the_content(); ?>
                    </div>

                    <?php
                    // Donate block (only shows when a campaign URL is set).
                    echo sfaf_donate_block( $post_id );

                    // Galaxy Digital volunteer signup (imported needs only).
                    echo sfaf_galaxy_block( $post_id );

                    // Frequently asked questions. This event's own, and the only
                    // ones there are: see sfaf_faq_meta_key().
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
                                    <span><strong><?php echo esc_html( sfaf_ap_date( $date_ts, 'full' ) ); ?></strong></span>
                                </li>
                            <?php endif; ?>
                            <?php if ( $start_time ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'clock' ); ?></span>
                                    <span><?php echo esc_html( sfaf_ap_time_range( $start_time, $end_time ) ); ?></span>
                                </li>
                            <?php endif; ?>
                            <?php
                            /*
                             * ONLINE: THE WORDS, AND NOTHING THAT LEADS ANYWHERE.
                             *
                             * Not a maps link, because "Online Event" is not an
                             * address and handing it to Google as a search is
                             * both nonsense and a request this page should not
                             * be making on a visitor's behalf. NOT the meeting
                             * link either, at any price: this is the public
                             * page, and it is the first place anybody looking
                             * for the link would try. See SFAF_Online.
                             */
                            ?>
                            <?php
                            /*
                             * THE NAME OF THE PLACE COMES ABOVE THE ADDRESS
                             * (3.93.0), AND BOTH KINDS OF PLACE READ THE SAME.
                             *
                             * An event either names a venue or carries its own
                             * typed place name, and until now only the first of
                             * those had a line at all, printed BELOW the
                             * address. A street number is not an answer to
                             * "where is this"; the name is, and it is what
                             * somebody reads first.
                             *
                             * Two sources, one line, and the venue is asked
                             * first for the same reason sfaf_event_location()
                             * asks it first: a promoted event keeps no text of
                             * its own, so there is never both.
                             *
                             * An online event's venue term was cleared by
                             * SFAF_Online::set(), so this is already empty for
                             * one; the explicit test is the second mechanism,
                             * for a row written before 3.62.0 by something that
                             * predates that rule.
                             */
                            $place_name = '';
                            if ( ! SFAF_Online::is_online( $post_id ) ) {
                                $place_name = ! empty( $venues )
                                    ? implode( ', ', wp_list_pluck( $venues, 'name' ) )
                                    : sfaf_event_location_name( $post_id );
                            }
                            ?>
                            <?php if ( '' !== $place_name ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'venue' ); ?></span>
                                    <span><?php echo esc_html( $place_name ); ?></span>
                                </li>
                            <?php endif; ?>
                            <?php
                            /*
                             * HOW THEY CAN ATTEND, ABOVE THE ADDRESS (3.96.0).
                             *
                             * Only a hybrid event has anything to say: an
                             * in-person event's address is the answer and an
                             * online one already reads "Online Event" below.
                             * Above the address rather than under it, because
                             * somebody who is joining online needs to know that
                             * before they read a street they are not going to.
                             *
                             * The same video icon the online line uses, because
                             * it is the same fact about the same event. It is
                             * never the meeting link; see
                             * sfaf_event_format_line().
                             */
                            $format_line = sfaf_event_format_line( $post_id );
                            ?>
                            <?php if ( '' !== $format_line ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'video' ); ?></span>
                                    <span><?php echo esc_html( $format_line ); ?></span>
                                </li>
                            <?php endif; ?>
                            <?php if ( SFAF_Online::is_online( $post_id ) ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'video' ); ?></span>
                                    <span><?php echo esc_html( SFAF_Online::LABEL ); ?></span>
                                </li>
                            <?php elseif ( $location ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'pin' ); ?></span>
                                    <?php // A plain link to Google Maps, which costs a
                                          // visitor nothing until they follow it. The map
                                          // itself is further down, behind a button. ?>
                                    <span><a class="uc-fact-maplink" href="<?php echo esc_url( sfaf_map_search_url( $location ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $location ); ?></a></span>
                                </li>
                            <?php endif; ?>
                            <?php
                            /*
                             * WHAT A COMMUNITY SUBMISSION ADDS (3.46.0).
                             *
                             * Each is blank by default and BLANK MEANS THE LINE
                             * IS NOT THERE, rather than a line saying nothing is
                             * known. An event with no cost given is not a free
                             * event, so inventing "Free" here would put a claim
                             * on the page that nobody made.
                             *
                             * Every one of these came from a public form, so
                             * every one is escaped as text. The registration link
                             * is the single exception and it is the reason
                             * sfaf_event_rsvp_url() re-checks the scheme on the
                             * way out rather than trusting what was stored.
                             */
                            $cost    = sfaf_event_cost( $post_id );
                            $ages    = sfaf_event_age_restriction( $post_id );
                            $contact = sfaf_event_public_contact( $post_id );
                            $signup  = sfaf_event_rsvp_url( $post_id );
                            ?>
                            <?php if ( '' !== $cost ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'ticket' ); ?></span>
                                    <span><?php echo esc_html( $cost ); ?></span>
                                </li>
                            <?php endif; ?>
                            <?php if ( '' !== $ages ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'users' ); ?></span>
                                    <span><?php echo esc_html( $ages ); ?></span>
                                </li>
                            <?php endif; ?>
                            <?php if ( '' !== $contact ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'mail' ); ?></span>
                                    <span><?php echo esc_html( $contact ); ?></span>
                                </li>
                            <?php endif; ?>
                            <?php if ( '' !== $signup ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'link' ); ?></span>
                                    <span><a href="<?php echo esc_url( $signup ); ?>" target="_blank" rel="noopener noreferrer nofollow ugc">Register for this event</a></span>
                                </li>
                            <?php endif; ?>
                            <?php
                            /* The venue's own site, beside the address rather
                             * than on the card. See sfaf_event_venue_website().
                             *
                             * NOT ON AN ONLINE EVENT. This one is a community
                             * submission field and belongs to SFAF_Submit, not
                             * to the location picker, so SFAF_Online::set() does
                             * not clear it and should not: it is not this
                             * feature's field to delete. It is simply not shown
                             * beside "Online Event", where a venue's website is
                             * an answer to a question nobody asked. */
                            $venue_site = SFAF_Online::is_online( $post_id ) ? '' : sfaf_event_venue_website( $post_id );
                            if ( '' !== $venue_site ) : ?>
                                <li>
                                    <span class="uc-fact-icon"><?php echo sfaf_icon( 'home' ); ?></span>
                                    <span><a href="<?php echo esc_url( $venue_site ); ?>" target="_blank" rel="noopener noreferrer nofollow ugc">Venue website</a></span>
                                </li>
                            <?php endif; ?>                        </ul>

                        <?php
                        // RSVP block.
                        echo sfaf_rsvp_block( $post_id );

                        // Add to calendar + follow the series.
                        $secondary = sfaf_add_to_calendar( $post_id ) . sfaf_follow_series_button( $post_id );
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
