<?php
/**
 * RECURRENCE IS A GENERATOR, NOT A TEMPLATE.
 *
 * It runs ONCE, when an event is created, and produces N independent uc_event
 * posts. There is no parent, no template, nothing that regenerates, and nothing
 * that syncs. Every event it makes can be edited or deleted freely afterwards
 * and nothing will recreate it or rewrite it.
 *
 * WHAT THIS REPLACES, AND WHY THE REPLACEMENT IS SMALLER. Until 3.0.0 the
 * pattern lived on a parent post and was re-run on every save, which is where
 * an entire category of problems came from:
 *
 *   - generate_series() rewrote children on every parent save, so occurrences
 *     needed a _uc_manually_edited flag to defend themselves from it;
 *   - deleting one occurrence was not enough, because the next save saw a gap
 *     in the date set and helpfully filled it back in — hence
 *     _uc_series_cancelled_dates, a list of dates recorded on the parent so a
 *     cancellation could outlive the post it cancelled;
 *   - the parent was also the first occurrence, so moving the pattern forward
 *     without rewriting history needed promote_to_parent();
 *   - deleting the parent left everything else pointing at a missing post,
 *     hence orphan detection and two repair screens.
 *
 * ALL OF THAT IS GONE, and none of it was replaced by something equivalent.
 * Deleting an event now deletes an event. Nothing brings it back, because
 * nothing is looking at a pattern any more.
 *
 * TWO GROUPINGS, DOING DIFFERENT JOBS. Keep them straight:
 *
 *   SERIES (SFAF_Series, a taxonomy) is the umbrella: filtering, page embeds,
 *   browsing. It may hold DIFFERENT KINDS OF EVENT — an educational session
 *   one week, a social the next — so it is never a target for bulk edits.
 *
 *   RECURRENCE GROUP (this file) is the set of events generated together from
 *   one pattern. Identical by default. This is what "edit all upcoming
 *   occurrences" targets, and the only reason the marker exists.
 *
 * A recurrence group is a MARKER, not a container. It has no settings, no
 * screen and no lifecycle. Removing every event in a group removes the group,
 * because a group is nothing more than the events that carry its ID.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Recurrence {

    /**
     * The marker saying "these events came from one pattern".
     *
     * A random string rather than a post ID: an ID would imply one of the
     * events is more important than the others, which is exactly the mistake
     * the parent post was.
     */
    const GROUP_META = '_uc_recurrence_group';

    /** Which pattern produced a group, kept so screens can say so. */
    const PATTERN_META = '_uc_recurrence_pattern';

    /** Hard ceiling on one generation run. A guard, not a feature. */
    const MAX_OCCURRENCES = 366;

    /**
     * Meta copied from the seed event onto each generated occurrence.
     *
     * Deliberately NOT here: _uc_event_date (each occurrence has its own), the
     * source provenance keys (a generated event is not an import), and the
     * group/pattern markers (written explicitly below).
     */
    private static $copied_meta = array(
        '_uc_start_time', '_uc_end_time', '_uc_location',
        '_uc_capacity', '_uc_rsvp_enabled', '_uc_gofundme_url', '_uc_gofundme_goal',
        '_uc_pardot_campaigns', '_uc_organizer_email', '_uc_notify_organizer',
        '_uc_email_subject', '_uc_email_body', '_uc_email_replyto',
        '_uc_show_rsvp', '_uc_show_donate', '_uc_show_social', '_uc_show_calendar', '_uc_show_reminders',
        '_uc_image_url',
        // FAQs travel with the copy. One key, no inheritance — see
        // sfaf_faq_meta_key().
        '_uc_faqs',
    );

    /**
     * Taxonomies copied onto each generated occurrence, series included.
     *
     * 'uc_series' is written out rather than referenced as
     * SFAF_Series::TAXONOMY, following the same precedent the old
     * $series_meta property set: a static property initializer is a constant
     * expression, and keeping it literal means this class cannot depend on
     * another one having loaded first.
     */
    private static $copied_taxonomies = array(
        'uc_event_category', 'uc_organizer', 'uc_venue', 'uc_series',
    );

    public function register() {
        // Nothing to hook. Generation happens when an editor asks for it, and
        // never on save_post — that hook is what used to make a save rewrite a
        // term's worth of events as a side effect of touching one of them.
    }

    /* =====================================================================
     * Patterns
     * ================================================================== */

    /**
     * The patterns on offer, key => label.
     *
     * The first four are the ones the pre-3.0 engine already generated and the
     * arithmetic below is the same arithmetic; monthly_nth is the addition.
     *
     * @return array<string,string>
     */
    public static function patterns() {
        return array(
            'daily'       => 'Every day',
            'weekly'      => 'Every week',
            'biweekly'    => 'Every 2 weeks',
            'monthly'     => 'Every month, on the same date',
            'monthly_nth' => 'Every month, on the same weekday',
        );
    }

    /** Coerce anything submitted into a pattern we recognise, or ''. */
    public static function clean_pattern( $raw ) {
        $raw = is_string( $raw ) ? sanitize_key( $raw ) : '';
        return array_key_exists( $raw, self::patterns() ) ? $raw : '';
    }

    /**
     * The label for a pattern, with monthly_nth spelled out against a real
     * date: "every 2nd Friday of the month".
     *
     * Naming the actual weekday is the point. "Every month, on the same
     * weekday" is only meaningful next to the date it was derived from, and a
     * label that states the derivation cannot drift away from the behaviour
     * without somebody noticing.
     *
     * @param string $pattern
     * @param string $start Y-m-d the pattern was anchored to.
     * @return string
     */
    public static function pattern_label( $pattern, $start = '' ) {
        $labels = self::patterns();
        if ( ! isset( $labels[ $pattern ] ) ) {
            return '';
        }
        if ( 'monthly_nth' === $pattern && $start ) {
            $nth = self::nth_weekday_of_month( $start );
            if ( $nth ) {
                $ordinals = array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth' );
                return sprintf(
                    'Every %s %s of the month',
                    isset( $ordinals[ $nth['nth'] ] ) ? $ordinals[ $nth['nth'] ] : $nth['nth'] . 'th',
                    $nth['weekday']
                );
            }
        }
        return $labels[ $pattern ];
    }

    /**
     * Which weekday-of-the-month a date is: the 2nd Friday, the 4th Tuesday.
     *
     * @param string $date Y-m-d
     * @return array{nth:int,weekday:string,dow:int}|null
     */
    public static function nth_weekday_of_month( $date ) {
        try {
            $d = new DateTimeImmutable( (string) $date, wp_timezone() );
        } catch ( Exception $e ) {
            return null;
        }
        $day = (int) $d->format( 'j' );
        return array(
            'nth'     => (int) ceil( $day / 7 ),
            'weekday' => $d->format( 'l' ),
            'dow'     => (int) $d->format( 'w' ),
        );
    }

    /**
     * The dates a pattern produces AFTER the start date, through the end date.
     *
     * The start date is not in the list because it is the event the manager is
     * already creating. It is part of the group all the same — generate()
     * stamps it.
     *
     * DATE ARITHMETIC IS SITE-LOCAL, matching how _uc_event_date is stored:
     * a plain Y-m-d that is already the site's calendar day.
     *
     * A NOTE ON 'monthly'. "+1 month" from the 31st lands in the following
     * month, because PHP normalises 31 February to 3 March. That behaviour is
     * inherited unchanged from the pre-3.0 engine rather than quietly altered:
     * it is why monthly_nth exists as a separate choice for anybody who means
     * "the last Friday" rather than "the 31st".
     *
     * @param string $start   Y-m-d
     * @param string $end     Y-m-d inclusive
     * @param string $pattern
     * @return string[] Y-m-d
     */
    public static function dates( $start, $end, $pattern ) {
        $pattern = self::clean_pattern( $pattern );
        if ( '' === $pattern || ! $start || ! $end ) {
            return array();
        }

        $tz = wp_timezone();
        try {
            $cur  = new DateTime( (string) $start, $tz );
            $stop = new DateTime( (string) $end, $tz );
        } catch ( Exception $e ) {
            return array();
        }
        if ( $stop < $cur ) {
            return array();
        }

        if ( 'monthly_nth' === $pattern ) {
            return self::nth_weekday_dates( $start, $end );
        }

        $steps = array(
            'daily'    => '+1 day',
            'weekly'   => '+1 week',
            'biweekly' => '+2 weeks',
            'monthly'  => '+1 month',
        );

        $dates = array();
        $cur->modify( $steps[ $pattern ] );
        while ( $cur <= $stop && count( $dates ) < self::MAX_OCCURRENCES ) {
            $dates[] = $cur->format( 'Y-m-d' );
            $cur->modify( $steps[ $pattern ] );
        }
        return $dates;
    }

    /**
     * "The same weekday-of-the-month, every month" — the 2nd Friday, the 4th
     * Tuesday — derived from the start date rather than asked for separately.
     *
     * A MONTH THAT DOES NOT HAVE A FIFTH FRIDAY IS SKIPPED, not moved to the
     * fourth. Somebody who set up a fifth-Friday group means the fifth Friday;
     * silently holding it a week early in the months that have only four would
     * put an event on a date nobody chose.
     *
     * @param string $start Y-m-d
     * @param string $end   Y-m-d inclusive
     * @return string[] Y-m-d, strictly after $start
     */
    private static function nth_weekday_dates( $start, $end ) {
        $nth = self::nth_weekday_of_month( $start );
        if ( ! $nth ) {
            return array();
        }
        $tz = wp_timezone();
        try {
            $from   = new DateTimeImmutable( (string) $start, $tz );
            $stop   = new DateTimeImmutable( (string) $end, $tz );
            $cursor = new DateTimeImmutable( $from->format( 'Y-m-01' ), $tz );
        } catch ( Exception $e ) {
            return array();
        }

        $names = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
        $day   = $names[ $nth['dow'] ];

        $dates = array();
        for ( $i = 0; $i < self::MAX_OCCURRENCES; $i++ ) {
            $cursor = $cursor->modify( '+1 month' );
            if ( $cursor > $stop ) {
                break;
            }
            // "second friday of March 2027" — PHP resolves this exactly, and
            // returns a date in the FOLLOWING month when the month has no such
            // weekday, which is the case we drop.
            $hit = $cursor->modify( sprintf( '%s %s of this month', self::ordinal( $nth['nth'] ), $day ) );
            if ( ! $hit || $hit->format( 'Y-m' ) !== $cursor->format( 'Y-m' ) ) {
                continue;
            }
            if ( $hit > $stop || $hit <= $from ) {
                continue;
            }
            $dates[] = $hit->format( 'Y-m-d' );
        }
        return $dates;
    }

    /** "first" … "fifth", for PHP's relative date syntax. */
    private static function ordinal( $n ) {
        $words = array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth' );
        return isset( $words[ (int) $n ] ) ? $words[ (int) $n ] : 'first';
    }

    /* =====================================================================
     * Generation — runs once, at creation, and never again
     * ================================================================== */

    /**
     * Generate the rest of a recurrence from a seed event.
     *
     * WHAT COMES OUT: N ordinary uc_event posts, each complete in itself, each
     * carrying the same recurrence group ID as the seed. Nothing points at the
     * seed; nothing will ever read the pattern again.
     *
     * REFUSES TO RUN TWICE on the same seed. A seed that already carries a
     * group ID has already been generated from, and running again would double
     * every date. This is the only "already done" check in the file and it
     * exists because a browser back-button resubmit is a real thing, not
     * because anything regenerates.
     *
     * @param int    $seed_id
     * @param string $pattern
     * @param string $end_date Y-m-d, inclusive.
     * @return array{group:string,created:int[],dates:string[],skipped:int}
     */
    public static function generate( $seed_id, $pattern, $end_date ) {
        $seed_id = (int) $seed_id;
        $out     = array( 'group' => '', 'created' => array(), 'dates' => array(), 'skipped' => 0 );

        $seed = get_post( $seed_id );
        if ( ! $seed || 'uc_event' !== $seed->post_type ) {
            return $out;
        }
        if ( '' !== self::group_of( $seed_id ) ) {
            return $out; // already generated from
        }

        $pattern = self::clean_pattern( $pattern );
        $start   = (string) get_post_meta( $seed_id, '_uc_event_date', true );
        $dates   = self::dates( $start, $end_date, $pattern );
        if ( empty( $dates ) ) {
            return $out;
        }

        $group = self::new_group_id();
        update_post_meta( $seed_id, self::GROUP_META, $group );
        update_post_meta( $seed_id, self::PATTERN_META, $pattern );

        foreach ( $dates as $date ) {
            $id = self::create_occurrence( $seed, $date, $group, $pattern );
            if ( $id ) {
                $out['created'][] = $id;
                $out['dates'][]   = $date;
            } else {
                $out['skipped']++;
            }
        }

        $out['group'] = $group;
        return $out;
    }

    /** A fresh group marker. */
    public static function new_group_id() {
        return 'rg_' . wp_generate_password( 12, false, false );
    }

    /**
     * One generated occurrence: a complete, independent copy of the seed on a
     * different date.
     *
     * THE SLUG SCHEME IS UNCHANGED, {seed-slug}-{YYYY-MM-DD}, because it is
     * what every occurrence created before 3.0.0 already uses and those URLs
     * are live. WordPress appends a numeric suffix only on a genuine collision.
     *
     * @param WP_Post $seed
     * @param string  $date
     * @param string  $group
     * @param string  $pattern
     * @return int New post ID, or 0.
     */
    private static function create_occurrence( $seed, $date, $group, $pattern ) {
        $base = $seed->post_name ? $seed->post_name : sanitize_title( $seed->post_title );

        $id = wp_insert_post( array(
            'post_type'    => 'uc_event',
            'post_status'  => $seed->post_status,
            'post_title'   => $seed->post_title,
            'post_name'    => $base . '-' . $date,
            'post_content' => $seed->post_content,
            'post_excerpt' => $seed->post_excerpt,
            'post_author'  => $seed->post_author,
        ), true );

        if ( is_wp_error( $id ) ) {
            return 0;
        }
        $id = (int) $id;

        foreach ( self::$copied_meta as $key ) {
            $value = get_post_meta( $seed->ID, $key, true );
            if ( '' !== $value && array() !== $value ) {
                update_post_meta( $id, $key, $value );
            }
        }
        foreach ( self::$copied_taxonomies as $tax ) {
            $terms = wp_get_object_terms( $seed->ID, $tax, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) ) {
                wp_set_object_terms( $id, $terms, $tax );
            }
        }
        $thumb = get_post_thumbnail_id( $seed->ID );
        if ( $thumb ) {
            set_post_thumbnail( $id, $thumb );
        }

        update_post_meta( $id, '_uc_event_date', $date );
        update_post_meta( $id, self::GROUP_META, $group );
        update_post_meta( $id, self::PATTERN_META, $pattern );

        return $id;
    }

    /* =====================================================================
     * Recurrence groups — what a bulk edit targets
     * ================================================================== */

    /** The group an event belongs to, or ''. */
    public static function group_of( $post_id ) {
        return (string) get_post_meta( (int) $post_id, self::GROUP_META, true );
    }

    /** The pattern that produced an event's group, or ''. */
    public static function pattern_of( $post_id ) {
        return (string) get_post_meta( (int) $post_id, self::PATTERN_META, true );
    }

    /**
     * Every event in a recurrence group whose date has NOT passed.
     *
     * PAST EVENTS ARE THE HISTORICAL RECORD. A bulk edit must never reach one:
     * moving last month's session to a new time would rewrite something that
     * already happened, in front of everybody who attended it. The cutoff is
     * today in the site's timezone, and "today" is included — an event later
     * today has not happened yet.
     *
     * @param string $group
     * @param array  $statuses
     * @return int[] Post IDs, earliest first.
     */
    public static function upcoming_in_group( $group, $statuses = null ) {
        $group = (string) $group;
        if ( '' === $group ) {
            return array();
        }
        if ( null === $statuses ) {
            $statuses = SFAF_Series::editable_statuses();
        }

        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => (array) $statuses,
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'orderby'                => array( 'event_date' => 'ASC', 'ID' => 'ASC' ),
            'meta_query'             => array(
                'relation'   => 'AND',
                'group'      => array( 'key' => self::GROUP_META, 'value' => $group ),
                'event_date' => array(
                    'key'     => '_uc_event_date',
                    'value'   => current_time( 'Y-m-d' ),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ),
            ),
        ) );

        return array_map( 'intval', $q->posts );
    }

    /**
     * The events an "edit all upcoming occurrences" save would write, for a
     * given event.
     *
     * Includes the event being edited, when its own date has not passed. A
     * manager editing a past occurrence with "all upcoming" chosen changes the
     * upcoming ones and leaves the one in front of them alone — which is
     * strange enough that the editor does not offer the choice at all in that
     * case. See has_bulk_scope().
     *
     * @param int $post_id
     * @return int[]
     */
    public static function bulk_targets( $post_id ) {
        return self::upcoming_in_group( self::group_of( $post_id ) );
    }

    /**
     * Whether the two-button scope choice should appear on this event.
     *
     * Only when the event is part of a recurrence group with MORE THAN ONE
     * upcoming occurrence. A one-off event, or the last one left in a group,
     * just opens for editing — offering a scope choice with one possible
     * outcome is a question with no answer.
     *
     * @param int $post_id
     * @return bool
     */
    public static function has_bulk_scope( $post_id ) {
        return count( self::bulk_targets( $post_id ) ) > 1;
    }

    /**
     * Fields that carry no pencil in "all upcoming" mode, and why.
     *
     * DATE, ALWAYS. The dates are the only thing that makes the occurrences
     * distinct; one date written across twelve of them would collapse the group
     * onto a single day.
     *
     * CAPACITY, WHEN SOMEBODY HAS ALREADY REGISTERED. RSVPs are held against
     * one date, not against the group, so a single capacity written across the
     * set can land below the confirmed count on a date the manager is not
     * looking at — quietly showing an event as full, or as having room it does
     * not have. With no confirmed RSVPs anywhere in the set there is nothing to
     * contradict, so the pencil stays.
     *
     * @param int[] $ids The events the save would write.
     * @return array<string,string> field => the reason, shown to the manager.
     */
    public static function bulk_locked_fields( $ids ) {
        $locked = array(
            'date' => 'Each occurrence has its own date. Change one from its own event.',
        );

        foreach ( (array) $ids as $id ) {
            if ( sfaf_get_rsvp_count( (int) $id ) > 0 ) {
                $locked['capacity'] = 'Some of these dates already have RSVPs against them, and capacity is counted per date.';
                break;
            }
        }

        return $locked;
    }
}
