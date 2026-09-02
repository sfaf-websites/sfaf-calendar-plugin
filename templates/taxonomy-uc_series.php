<?php
/**
 * The public page for one series.
 *
 * WHY THIS FILE EXISTS. The "Part of series: X" badge used to link to the
 * series PARENT POST, which was a real event with a real permalink. There is no
 * parent post any more, so the badge needed a destination, and this is it — see
 * the reasoning in sfaf_series_link().
 *
 * WHAT IT HAS TO SHOW. A series carries a name, a description and an image, and
 * section 2 of the 3.0.0 brief is explicit that a series with NO events is
 * valid and useful: somebody can read what it is about and see that dates may
 * be added. So the description and image come first and the dates come after,
 * and an empty series renders a real page rather than "nothing found".
 *
 * Loaded through the template_include filter unless the theme provides its own
 * taxonomy-uc_series.php, exactly as single-uc_event.php is.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$term = get_queried_object();

if ( ! $term || empty( $term->term_id ) ) {
    get_footer();
    return;
}

$image    = SFAF_Series::image_url( $term->term_id, 'large' );
// public_only: this is the series page a visitor sees, so a private event in
// this series must not be on it. See SFAF_Privacy.
$upcoming = SFAF_Series::events( $term->term_id, array( 'upcoming' => true, 'limit' => 100, 'public_only' => true ) );

// Past dates are the record of what this series has actually been, which is
// often the most useful thing on the page for a programme somebody is deciding
// whether to join. Newest first, and capped, because it is context and not the
// point of the page.
$all  = SFAF_Series::events( $term->term_id, array( 'limit' => 200, 'public_only' => true ) );
$past = array_slice( array_reverse( array_values( array_diff( $all, $upcoming ) ) ), 0, 10 );

$settings   = get_option( 'uc_settings', array() );
$brand_logo = isset( $settings['brand_logo'] ) ? $settings['brand_logo'] : '';
?>
<div class="uc-single uc-series-page">
    <div class="uc-single-inner">

        <?php if ( $brand_logo ) : ?>
            <div class="uc-single-brand">
                <img src="<?php echo esc_url( $brand_logo ); ?>" alt="" class="uc-single-logo" />
            </div>
        <?php endif; ?>

        <a href="<?php echo esc_url( get_post_type_archive_link( 'uc_event' ) ); ?>" class="uc-single-back">&larr; All Events</a>

        <header class="uc-single-header">
            <div class="uc-single-badges">
                <span class="uc-badge uc-badge-recurrence"><?php echo sfaf_icon( 'repeat' ); ?> Series</span>
            </div>
            <h1 class="uc-single-title"><?php echo esc_html( $term->name ); ?></h1>
        </header>

        <div class="uc-single-grid">
            <div class="uc-single-main">
                <?php if ( $image ) : ?>
                    <div class="uc-single-image">
                        <img class="uc-thumb-img" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $term->name ); ?>" />
                    </div>
                <?php endif; ?>

                <?php if ( '' !== trim( (string) $term->description ) ) : ?>
                    <div class="uc-single-body"><?php echo wpautop( wp_kses_post( $term->description ) ); ?></div>
                <?php endif; ?>

                <div class="uc-series-box" data-series>
                    <h2 class="uc-series-heading">Upcoming dates</h2>
                    <?php if ( empty( $upcoming ) ) : ?>
                        <?php // Not an error state. A series with no dates yet is
                              // a series somebody has set up and is about to fill. ?>
                        <p class="uc-empty">No dates scheduled at the moment. Check back, because dates are added to this series as they are confirmed.</p>
                    <?php else : ?>
                        <ul class="uc-series-list">
                            <?php foreach ( $upcoming as $eid ) :
                                // THE STORED STRING, NOT strtotime() OF IT. WordPress runs PHP
                                // in UTC, so strtotime( '2026-08-04' ) is midnight UTC, and a
                                // site-timezone formatter renders that as the 3rd anywhere
                                // west of Greenwich. sfaf_ap_date() takes the date string and
                                // anchors it at midday for exactly this reason.
                                $d  = get_post_meta( $eid, '_uc_event_date', true );
                                $st = get_post_meta( $eid, '_uc_start_time', true ); ?>
                                <li>
                                    <a href="<?php echo esc_url( get_permalink( $eid ) ); ?>"<?php echo sfaf_new_tab_attrs(); ?>>
                                        <span class="uc-series-date"><?php echo esc_html( sfaf_ap_date( $d, 'short' ) ); ?></span>
                                        <span class="uc-series-title"><?php echo esc_html( get_the_title( $eid ) ); ?></span>
                                        <?php if ( $st ) : ?><span class="uc-series-time"><?php echo esc_html( sfaf_ap_time( $st ) ); ?></span><?php endif; ?><?php echo sfaf_new_tab_note(); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty( $past ) ) : ?>
                    <div class="uc-series-box">
                        <h2 class="uc-series-heading">Previously in this series</h2>
                        <ul class="uc-series-list">
                            <?php foreach ( $past as $eid ) :
                                // The stored string, for the reason given in the block above.
                                $d = get_post_meta( $eid, '_uc_event_date', true ); ?>
                                <li>
                                    <a href="<?php echo esc_url( get_permalink( $eid ) ); ?>"<?php echo sfaf_new_tab_attrs(); ?>>
                                        <span class="uc-series-date"><?php echo esc_html( sfaf_ap_date( $d, 'short_year' ) ); ?></span>
                                        <span class="uc-series-title"><?php echo esc_html( get_the_title( $eid ) ); ?></span><?php echo sfaf_new_tab_note(); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
get_footer();
