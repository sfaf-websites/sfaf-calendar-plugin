<?php
/**
 * Sample event seeding on first activation (only when the calendar is empty).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Get an existing term ID by name or create it.
 */
function sfaf_sample_term( $name, $taxonomy ) {
    $term = get_term_by( 'name', $name, $taxonomy );
    if ( $term ) {
        return (int) $term->term_id;
    }
    $res = wp_insert_term( $name, $taxonomy );
    return is_wp_error( $res ) ? 0 : (int) $res['term_id'];
}

/**
 * Seed realistic SFAF sample events + taxonomy terms.
 *
 * Runs at most once, ever. Two guards, either of which stops it:
 *   1. The sfaf_sample_data_seeded option — set the first time this completes,
 *      so demo data is never re-inserted even if the site is later emptied and
 *      the plugin reactivated.
 *   2. Any existing uc_event post — so it also stays out of the way on a site
 *      that already has real events but has never run this seeder.
 */
function sfaf_install_sample_data() {
    // Once seeded, never again — even if every event is later deleted.
    if ( get_option( 'sfaf_sample_data_seeded' ) ) {
        return;
    }

    $existing = get_posts( array(
        'post_type'   => 'uc_event',
        'post_status' => 'any',
        'numberposts' => 1,
        'fields'      => 'ids',
    ) );
    if ( ! empty( $existing ) ) {
        // Real events already exist: record that seeding is settled so the demo
        // data can never appear later, and bail without inserting anything.
        update_option( 'sfaf_sample_data_seeded', '1' );
        return;
    }

    // Categories + brand colors.
    // Approved SFAF brand colors (brand guide v3.0, p.9).
    $cat_colors = array(
        'Support Groups'  => '#16BECF', // Teal
        'Fundraising'     => '#F04937', // Red
        'Health Services' => '#8CC745', // Green
        'Volunteer'       => '#8D54A2', // Purple
        'Program Groups'  => '#FFD900', // Yellow
    );
    foreach ( $cat_colors as $name => $color ) {
        $tid = sfaf_sample_term( $name, 'uc_event_category' );
        if ( $tid ) {
            update_term_meta( $tid, '_uc_category_color', $color );
        }
    }

    $tz = wp_timezone();

    // Date helpers.
    $next = function( $modifier ) use ( $tz ) {
        $d = new DateTime( 'now', $tz );
        $d->modify( $modifier );
        return $d->format( 'Y-m-d' );
    };
    $plus = function( $base, $modifier ) use ( $tz ) {
        $d = new DateTime( $base, $tz );
        $d->modify( $modifier );
        return $d->format( 'Y-m-d' );
    };

    // First Wednesday of next month for the monthly orientation.
    $fw = new DateTime( 'first day of next month', $tz );
    while ( (int) $fw->format( 'N' ) !== 3 ) {
        $fw->modify( '+1 day' );
    }
    $first_wed = $fw->format( 'Y-m-d' );

    // Saturday ~2 weeks out for the training ride.
    $sat = new DateTime( 'now', $tz );
    $sat->modify( 'next saturday' );
    $sat->modify( '+7 days' );
    $ride_date = $sat->format( 'Y-m-d' );

    $tue = $next( 'next tuesday' );
    $wed = $next( 'next wednesday' );
    $thu = $next( 'next thursday' );

    $events = array(
        array(
            'title'      => 'PROP Contingency Management',
            'category'   => 'Support Groups',
            'organizer'  => 'The Stonewall Project',
            'venue'      => 'Strut',
            'location'   => 'Strut - 470 Castro St, San Francisco',
            'date'       => $tue,
            'start'      => '13:00',
            'end'        => '14:30',
            'recurrence' => 'weekly',
            'end_date'   => $plus( $tue, '+8 weeks' ),
            'rsvp'       => true,
            'capacity'   => 20,
            'excerpt'    => 'A harm-reduction counseling program using contingency management to support people reducing stimulant use.',
            'content'    => "The Stonewall Project's Positive Reinforcement Opportunity Program (PROP) is a contingency-management group for people who want to reduce or stop their stimulant use. Participants set their own goals in a supportive, non-judgmental, harm-reduction environment. Drop-in welcome; counseling and incentives provided.",
        ),
        array(
            'title'      => 'TransLife Galaxy Mental Health Series',
            'category'   => 'Support Groups',
            'organizer'  => 'TransLife',
            'venue'      => 'Strut',
            'location'   => 'Strut - 470 Castro St, San Francisco',
            'date'       => $wed,
            'start'      => '13:00',
            'end'        => '14:30',
            'recurrence' => 'weekly',
            'end_date'   => $plus( $wed, '+8 weeks' ),
            'rsvp'       => true,
            'capacity'   => 15,
            'excerpt'    => 'A weekly mental-health and wellness series for trans and gender-expansive community members.',
            'content'    => "TransLife hosts a weekly mental-health series creating space for trans and gender-expansive folks to connect, build community, and access wellness resources. Facilitated by peer counselors with lived experience.",
        ),
        array(
            'title'        => 'Cycle to Zero 2026 Training Ride: Golden Gate Park',
            'category'     => 'Fundraising',
            'organizer'    => 'Endurance Series',
            'venue'        => 'Golden Gate Park',
            'location'     => 'Golden Gate Park, San Francisco',
            'date'         => $ride_date,
            'start'        => '09:00',
            'end'          => '12:00',
            'recurrence'   => '',
            'end_date'     => '',
            'rsvp'         => true,
            'capacity'     => 50,
            'gofundme_url' => 'https://www.gofundme.com',
            'goal'         => '50000',
            'excerpt'      => 'Join the Cycle to Zero training ride through Golden Gate Park as we prepare for the 2026 ride to end HIV.',
            'content'      => "Gear up for Cycle to Zero 2026 with a guided training ride through Golden Gate Park. All levels welcome. Every mile and every dollar raised supports SFAF's work toward getting new HIV cases to zero. Helmets required; SAG support provided.",
        ),
        array(
            'title'      => 'HIV Testing & Sexual Health Services',
            'category'   => 'Health Services',
            'organizer'  => 'Magnet',
            'venue'      => 'Strut',
            'location'   => 'Strut - 470 Castro St, San Francisco',
            'date'       => $tue,
            'start'      => '10:00',
            'end'        => '19:00',
            'recurrence' => 'weekly',
            'end_date'   => $plus( $tue, '+5 weeks' ),
            'rsvp'       => false,
            'capacity'   => 0,
            'excerpt'    => 'Free, confidential HIV/STI testing and sexual-health services at Magnet. Drop in, no appointment needed.',
            'content'    => "Magnet at Strut offers free and confidential HIV and STI testing, PrEP/PEP services, and sexual-health care in a welcoming, sex-positive environment. Drop-in services are available Tuesday through Saturday, 10:00 AM to 7:00 PM. No appointment necessary.",
        ),
        array(
            'title'      => 'Volunteer Orientation',
            'category'   => 'Volunteer',
            'organizer'  => 'SFAF Volunteer Services',
            'venue'      => 'SFAF Market Street',
            'location'   => 'SFAF - 1035 Market St, San Francisco',
            'date'       => $first_wed,
            'start'      => '17:30',
            'end'        => '19:00',
            'recurrence' => 'monthly',
            'end_date'   => $plus( $first_wed, '+5 months' ),
            'rsvp'       => true,
            'capacity'   => 30,
            'excerpt'    => 'New to SFAF? Start here. Learn about volunteer opportunities across our programs and events.',
            'content'    => "Thinking about volunteering with the San Francisco AIDS Foundation? Join us for a monthly orientation covering our programs, current volunteer needs, and how to get plugged in. Held the first Wednesday of each month.",
        ),
        array(
            'title'      => 'Programa Latino: Grupo de Apoyo',
            'category'   => 'Program Groups',
            'organizer'  => 'Programa Latino',
            'venue'      => 'SFAF Market Street',
            'location'   => 'SFAF - 1035 Market St, San Francisco',
            'date'       => $thu,
            'start'      => '18:00',
            'end'        => '19:30',
            'recurrence' => 'biweekly',
            'end_date'   => $plus( $thu, '+12 weeks' ),
            'rsvp'       => true,
            'capacity'   => 12,
            'excerpt'    => 'A bilingual (Spanish/English) peer support group for the Latino community. Grupo de apoyo bilingüe.',
            'content'    => "Programa Latino hosts a biweekly bilingual (Spanish/English) support group, un grupo de apoyo bilingüe, for Latino community members living with or affected by HIV. Facilitated by bilingual peer counselors in a confidential, welcoming space.",
        ),
    );

    foreach ( $events as $e ) {
        $post_id = wp_insert_post( array(
            'post_type'    => 'uc_event',
            'post_status'  => 'publish',
            'post_title'   => $e['title'],
            'post_content' => $e['content'],
            'post_excerpt' => $e['excerpt'],
        ) );
        if ( is_wp_error( $post_id ) || ! $post_id ) {
            continue;
        }

        $cat = sfaf_sample_term( $e['category'], 'uc_event_category' );
        if ( $cat ) {
            wp_set_object_terms( $post_id, array( $cat ), 'uc_event_category' );
        }
        $org = sfaf_sample_term( $e['organizer'], 'uc_organizer' );
        if ( $org ) {
            wp_set_object_terms( $post_id, array( $org ), 'uc_organizer' );
        }
        if ( ! empty( $e['venue'] ) ) {
            $venue = sfaf_sample_term( $e['venue'], 'uc_venue' );
            if ( $venue ) {
                wp_set_object_terms( $post_id, array( $venue ), 'uc_venue' );
                // The address lives on the venue now and an event refers to it,
                // so the sample venue is given one. Without this the samples
                // would name a venue with no address and read as a half-filled
                // screen rather than an example of the model. See SFAF_Venues.
                if ( '' === (string) get_term_meta( $venue, SFAF_Venues::META_ADDRESS, true ) ) {
                    update_term_meta( $venue, SFAF_Venues::META_ADDRESS, $e['location'] );
                }
            }
        }

        update_post_meta( $post_id, '_uc_event_date', $e['date'] );
        update_post_meta( $post_id, '_uc_start_time', $e['start'] );
        update_post_meta( $post_id, '_uc_end_time', $e['end'] );
        update_post_meta( $post_id, '_uc_location', $e['location'] );
        update_post_meta( $post_id, '_uc_rsvp_enabled', $e['rsvp'] ? '1' : '0' );
        update_post_meta( $post_id, '_uc_capacity', (string) $e['capacity'] );
        if ( ! empty( $e['gofundme_url'] ) ) {
            update_post_meta( $post_id, '_uc_gofundme_url', $e['gofundme_url'] );
            update_post_meta( $post_id, '_uc_gofundme_goal', $e['goal'] );
        }

        /*
         * Recurring samples are GENERATED, once, exactly as a manager creating
         * one would be. What comes out is a set of ordinary, independent events
         * sharing a recurrence group — there is no sample "series parent",
         * because there is no such thing any more.
         *
         * The sample data deliberately creates no series terms: a series is a
         * grouping somebody sets up with a description of what it is for, and
         * inventing one on a fresh install would put a container on the Series
         * screen that nobody asked for and that says nothing.
         */
        if ( $e['recurrence'] && ! empty( $e['end_date'] ) ) {
            SFAF_Recurrence::generate( $post_id, $e['recurrence'], $e['end_date'] );
        }
    }

    // Mark seeding done so it never runs again on a future reactivation.
    update_option( 'sfaf_sample_data_seeded', '1' );
}
