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

    /**
     * "This date was chosen by hand, not produced by the pattern."
     *
     * WHY A GROUP NEEDS TO KNOW THE DIFFERENCE. A weekly Wednesday group may
     * also meet on one Saturday, and that Saturday belongs to the programme: it
     * is in the group, a bulk edit reaches it, and a time change applies to it.
     * What it is NOT is an instance of the pattern, so a later pattern edit that
     * moves Wednesdays to Tuesdays must leave it exactly where it is. Without a
     * marker there is nothing to tell repattern_group() which dates it may move, and
     * the Saturday would be shifted to a Sunday nobody chose.
     *
     * ABSENT MEANS "PATTERN DATE", which is what every occurrence generated
     * before 3.23.0 is. That is the correct reading for them: they came out of
     * the generator. Dates added by hand from the series screen before this
     * existed are indistinguishable from pattern dates and keep being treated as
     * pattern dates, which is exactly what happens today; from now on they are
     * marked and are left alone.
     */
    const EXTRA_META = '_uc_recurrence_extra';

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
        /*
         * ONLINE, THE MEETING LINK AND WHICH MESSAGES CARRY IT.
         *
         * Written out as literals rather than spread from
         * SFAF_Online::meta_keys(), for the reason stated on $copied_taxonomies
         * below: a static property initializer is a constant expression, and a
         * function call here would make this class depend on another having
         * loaded first. The two lists are checked against each other in
         * .claude/online-events-test.php so they cannot drift.
         */
        '_uc_online', '_uc_hybrid', '_uc_meeting_url', '_uc_online_send',
        // Both capacities travel, for the reason the format does: an occurrence
        // of a hybrid series runs both formats and needs both limits.
        '_uc_capacity', '_uc_capacity_online',
        '_uc_rsvp_enabled', '_uc_gofundme_url', '_uc_gofundme_goal',
        '_uc_pardot_campaigns', '_uc_organizer_email', '_uc_notify_organizer',
        '_uc_email_subject', '_uc_email_body', '_uc_email_replyto',
        '_uc_show_rsvp', '_uc_show_donate', '_uc_show_social', '_uc_show_calendar', '_uc_show_reminders',
        '_uc_image_url', '_uc_image_url_typed',
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
     * The patterns on offer, key => label. LEGACY SHORTHANDS ONLY.
     *
     * These five are the whole of what the engine could express before 3.14.0,
     * and they are kept because they are stored on every event generated up to
     * now. They are also still valid input: each one is exactly equivalent to a
     * structured pattern, and parse_pattern() below normalises it to one.
     *
     * WHAT THEY COULD NOT SAY, which is why the structured form exists:
     * a group meeting Tuesdays AND Thursdays, any interval other than one or
     * two weeks, an nth-weekday chosen rather than inferred from the start
     * date, or "the LAST Friday" as distinct from "the fifth Friday".
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

    /** Upper bounds, so a typed interval cannot ask for a thousand years. */
    const MAX_INTERVAL = 52;

    /**
     * Normalise any pattern, old or new, into one shape.
     *
     * THE STORED FORM IS STILL A SINGLE STRING, deliberately. PATTERN_META has
     * held one since 3.0.0 and is read by pattern_label(), schedule_sentence(),
     * has_cadence() and repattern_group(); giving it a second, structured
     * shape would have meant every one of those learning which it was holding.
     * So the structure is IN the string, after a colon:
     *
     *   daily                 every day
     *   daily:3               every 3 days
     *   weekly                every week, on the seed's own weekday
     *   biweekly              every 2 weeks, on the seed's own weekday
     *   weekly:1:2,4          every week on Tuesday and Thursday
     *   weekly:3:1            every 3 weeks on Monday
     *   monthly               every month on the same date
     *   monthly:2             every 2 months on the same date
     *   monthly_nth           every month on the seed's own nth weekday
     *   monthly_nth:1:1       the first Monday of every month
     *   monthly_nth:-1:5      the LAST Friday of every month
     *   custom                no pattern at all: the dates were chosen by hand
     *
     * Weekdays are 0 for Sunday through 6 for Saturday, matching date('w').
     * An ordinal of -1 means last, which is NOT the same as 5: a month with
     * four Fridays has a last Friday and no fifth one.
     *
     * 'custom' IS A PATTERN THAT PRODUCES NO DATES, and that is not a
     * contradiction. It is stored so that a screen looking at a group can say
     * what kind of group it is: "these dates were chosen individually" rather
     * than "the pattern has been lost". dates() returns nothing for it and the
     * explicit list supplies everything, so there is no arithmetic to get wrong
     * and nothing for a pattern edit to move.
     *
     * @param string $raw
     * @return array|null {type, interval, days[], nth, dow} or null if unusable.
     */
    public static function parse_pattern( $raw ) {
        $raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
        if ( '' === $raw ) {
            return null;
        }
        // sanitize_key() cannot be used here: it strips the colons and commas
        // that carry the structure, which would silently turn "weekly:1:2,4"
        // into an unrecognised key and drop the pattern.
        if ( ! preg_match( '/^[a-z_]+(:-?[0-9]+)?(:-?[0-9]+(,-?[0-9]+)*)?$/', $raw ) ) {
            return null;
        }

        $bits = explode( ':', $raw );
        $type = $bits[0];
        $num  = isset( $bits[1] ) ? (int) $bits[1] : 0;

        $interval = function ( $n ) {
            $n = (int) $n;
            return ( $n >= 1 ) ? min( $n, self::MAX_INTERVAL ) : 1;
        };

        switch ( $type ) {
            case 'custom':
                return array( 'type' => 'custom', 'interval' => 1, 'days' => array(), 'nth' => 0, 'dow' => -1 );

            case 'daily':
                return array( 'type' => 'daily', 'interval' => $interval( $num ?: 1 ), 'days' => array(), 'nth' => 0, 'dow' => -1 );

            case 'biweekly':
                return array( 'type' => 'weekly', 'interval' => 2, 'days' => array(), 'nth' => 0, 'dow' => -1 );

            case 'weekly':
                $days = array();
                if ( isset( $bits[2] ) ) {
                    foreach ( explode( ',', $bits[2] ) as $d ) {
                        $d = (int) $d;
                        if ( $d >= 0 && $d <= 6 && ! in_array( $d, $days, true ) ) {
                            $days[] = $d;
                        }
                    }
                    sort( $days );
                }
                return array( 'type' => 'weekly', 'interval' => $interval( $num ?: 1 ), 'days' => $days, 'nth' => 0, 'dow' => -1 );

            case 'monthly':
                return array( 'type' => 'monthly', 'interval' => $interval( $num ?: 1 ), 'days' => array(), 'nth' => 0, 'dow' => -1 );

            case 'monthly_nth':
                $nth = isset( $bits[1] ) ? (int) $bits[1] : 0;   // 0 means "infer from the start date"
                $dow = isset( $bits[2] ) ? (int) $bits[2] : -1;
                if ( $nth < -1 || $nth > 5 || 0 === $nth && isset( $bits[1] ) ) {
                    $nth = 0;
                }
                if ( $dow < 0 || $dow > 6 ) {
                    $dow = -1;
                }
                return array( 'type' => 'monthly_nth', 'interval' => 1, 'days' => array(), 'nth' => $nth, 'dow' => $dow );
        }
        return null;
    }

    /**
     * Build the canonical stored string from a spec.
     *
     * @param array $spec
     * @return string
     */
    public static function pattern_string( $spec ) {
        if ( ! is_array( $spec ) || empty( $spec['type'] ) ) {
            return '';
        }
        $interval = isset( $spec['interval'] ) ? max( 1, min( (int) $spec['interval'], self::MAX_INTERVAL ) ) : 1;

        switch ( $spec['type'] ) {
            case 'custom':
                return 'custom';

            case 'daily':
                return ( 1 === $interval ) ? 'daily' : 'daily:' . $interval;

            case 'weekly':
                $days = isset( $spec['days'] ) ? array_values( array_unique( array_map( 'intval', (array) $spec['days'] ) ) ) : array();
                sort( $days );
                if ( empty( $days ) ) {
                    // No day chosen means "the seed's own weekday", which is
                    // exactly what the legacy shorthands mean.
                    return ( 2 === $interval ) ? 'biweekly' : ( 1 === $interval ? 'weekly' : 'weekly:' . $interval );
                }
                return 'weekly:' . $interval . ':' . implode( ',', $days );

            case 'monthly':
                return ( 1 === $interval ) ? 'monthly' : 'monthly:' . $interval;

            case 'monthly_nth':
                $nth = isset( $spec['nth'] ) ? (int) $spec['nth'] : 0;
                $dow = isset( $spec['dow'] ) ? (int) $spec['dow'] : -1;
                if ( 0 === $nth || $dow < 0 ) {
                    return 'monthly_nth';
                }
                return 'monthly_nth:' . $nth . ':' . $dow;
        }
        return '';
    }

    /** Coerce anything submitted into a pattern we recognise, or ''. */
    public static function clean_pattern( $raw ) {
        $spec = self::parse_pattern( $raw );
        return $spec ? self::pattern_string( $spec ) : '';
    }

    /** Weekday names, index 0 = Sunday, matching date('w'). */
    public static function weekday_names( $short = false ) {
        $long  = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
        $abbr  = array( 'S', 'M', 'T', 'W', 'T', 'F', 'S' );
        return $short ? $abbr : $long;
    }

    /** "first", "second", … and "last" for -1. */
    public static function ordinal_word( $n ) {
        $n = (int) $n;
        if ( -1 === $n ) {
            return 'last';
        }
        $words = array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth' );
        return isset( $words[ $n ] ) ? $words[ $n ] : 'first';
    }

    /**
     * The label for a pattern, in plain language, against a real date.
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
        $spec = self::parse_pattern( $pattern );
        if ( ! $spec ) {
            return '';
        }
        $names = self::weekday_names();

        switch ( $spec['type'] ) {
            case 'custom':
                // No cadence to describe. What there is instead is a list, and
                // the caller has it: summary() counts it and the schedule screen
                // prints it. Saying "on chosen dates" is the whole of what is
                // true about a custom group without reading that list.
                return 'On chosen dates';

            case 'daily':
                return ( 1 === $spec['interval'] ) ? 'Every day' : sprintf( 'Every %d days', $spec['interval'] );

            case 'weekly':
                $days = $spec['days'];
                if ( empty( $days ) && $start ) {
                    $dow = self::dow_of( $start );
                    if ( null !== $dow ) {
                        $days = array( $dow );
                    }
                }
                $on = '';
                if ( ! empty( $days ) ) {
                    $list = array();
                    foreach ( $days as $d ) {
                        $list[] = $names[ $d ];
                    }
                    $on = ' on ' . self::join_words( $list );
                }
                if ( 1 === $spec['interval'] ) {
                    return 'Every week' . $on;
                }
                if ( 2 === $spec['interval'] && 1 === count( $days ) ) {
                    // "Every other Wednesday" is what people say, and it is
                    // unambiguous.
                    return 'Every other ' . $names[ $days[0] ];
                }
                return sprintf( 'Every %d weeks', $spec['interval'] ) . $on;

            case 'monthly':
                /*
                 * NO ORDINAL. This read "Every month on the 4th" and it reaches
                 * a visitor: pattern_label() is what schedule_sentence() prints
                 * on the single event page. The brand guide's date rule is "no
                 * ordinals, ever", and DESIGN.md §3 states it as "August 4,
                 * never August 4th"; a bare "the 4th" is the same construction
                 * with the month left off.
                 *
                 * "on day 4" keeps the fact, which is the thing a person needs
                 * to know about a monthly pattern, and says it without the
                 * suffix. The alternative considered was the sibling wording
                 * below, "on the same weekday", which needs no number at all -
                 * but that case has a weekday to name and this one would have
                 * been throwing the date away.
                 *
                 * The day number comes from the formatter's 'daynum' style, so
                 * it is the site's clock rather than the server's.
                 */
                $day   = $start ? sfaf_ap_date( $start, 'daynum' ) : '';
                $every = ( 1 === $spec['interval'] ) ? 'Every month' : sprintf( 'Every %d months', $spec['interval'] );
                return ( '' !== $day ) ? $every . ' on day ' . $day : $every;

            case 'monthly_nth':
                $nth = $spec['nth'];
                $dow = $spec['dow'];
                if ( 0 === $nth || $dow < 0 ) {
                    if ( ! $start ) {
                        return 'Every month, on the same weekday';
                    }
                    $derived = self::nth_weekday_of_month( $start );
                    if ( ! $derived ) {
                        return 'Every month, on the same weekday';
                    }
                    $nth = $derived['nth'];
                    $dow = $derived['dow'];
                }
                return sprintf( 'Every %s %s of the month', self::ordinal_word( $nth ), $names[ $dow ] );
        }
        return '';
    }

    /** "Monday", "Monday and Thursday", "Monday, Wednesday and Friday". */
    private static function join_words( $list ) {
        $list = array_values( $list );
        $n    = count( $list );
        if ( 0 === $n ) { return ''; }
        if ( 1 === $n ) { return $list[0]; }
        if ( 2 === $n ) { return $list[0] . ' and ' . $list[1]; }
        // SERIAL COMMA, WHICH IS SFAF HOUSE STYLE: "Monday, Wednesday, and
        // Friday". Two items take no comma at all; three or more take one
        // before the "and". ucJoinWords() in portal.js is the same function on
        // the other side and the cross-check compares their output, so these
        // two change together or the check fails.
        return implode( ', ', array_slice( $list, 0, -1 ) ) . ', and ' . $list[ $n - 1 ];
    }

    /** date('w') for a Y-m-d, or null. */
    public static function dow_of( $date ) {
        try {
            $d = new DateTimeImmutable( (string) $date, wp_timezone() );
        } catch ( Exception $e ) {
            return null;
        }
        return (int) $d->format( 'w' );
    }

    /**
     * Which weekday-of-the-month a date is: the 2nd Friday, the 4th Tuesday.
     *
     * THE 'weekday' KEY IS GONE, AND NOTHING READ IT. It held $d->format( 'l' ),
     * an English weekday name built at the call site, and all five callers use
     * 'nth' and 'dow' only: the labels come from weekday_names(), which is the
     * one list the whole class names weekdays from. A spare formatted string
     * that nobody displays is a second source of wording waiting to be picked
     * up, so it is removed rather than routed through the formatter.
     *
     * @param string $date Y-m-d
     * @return array{nth:int,dow:int}|null
     */
    public static function nth_weekday_of_month( $date ) {
        try {
            $d = new DateTimeImmutable( (string) $date, wp_timezone() );
        } catch ( Exception $e ) {
            return null;
        }
        $day = (int) $d->format( 'j' );
        return array(
            'nth' => (int) ceil( $day / 7 ),
            'dow' => (int) $d->format( 'w' ),
        );
    }

    /**
     * The dates a pattern produces AFTER the start date.
     *
     * The start date is not in the list because it is the event the manager is
     * already creating. It is part of the group all the same: generate()
     * stamps it.
     *
     * TWO WAYS TO STOP, AND EITHER MAY BE USED. $end is a date, inclusive.
     * $limit is a count of dates to return. Whichever comes first wins, and
     * MAX_OCCURRENCES is the backstop under both, because a pattern with no
     * end and no count is a request to fill the database.
     *
     * DATE ARITHMETIC IS SITE-LOCAL, matching how _uc_event_date is stored: a
     * plain Y-m-d that is already the site's calendar day.
     *
     * A NOTE ON 'monthly'. "+1 month" from the 31st lands in the following
     * month, because PHP normalises 31 February to 3 March. That behaviour is
     * inherited unchanged from the pre-3.0 engine rather than quietly altered:
     * it is why monthly_nth exists as a separate choice for anybody who means
     * "the last Friday" rather than "the 31st".
     *
     * EXPLICIT DATES ARRIVE ALONGSIDE THE PATTERN AND ARE MERGED HERE, so that
     * one function answers "what dates does this produce" for all three cases:
     * a pattern on its own, a pattern with one-off dates beside it, and a set of
     * dates with no pattern at all. Every count anybody is shown comes from
     * this, so there is no second arithmetic to disagree with it.
     *
     * @param string   $start Y-m-d
     * @param string   $end   Y-m-d inclusive, or '' when $limit is doing the work.
     * @param string   $pattern
     * @param int      $limit Maximum PATTERN dates to return; 0 for no count limit.
     * @param string[] $extra Explicit Y-m-d dates chosen by hand.
     * @return string[] Y-m-d, ascending, unique, none of them the start date.
     */
    public static function dates( $start, $end, $pattern, $limit = 0, $extra = array() ) {
        $start = (string) $start;
        if ( '' === $start ) {
            return array();
        }
        return self::merge_dates(
            $start,
            self::pattern_dates( $start, $end, $pattern, $limit ),
            $extra
        );
    }

    /**
     * Merge pattern dates with hand-picked ones into the set that gets created.
     *
     * THE RULES, AND BOTH ENGINES FOLLOW THEM IN THIS ORDER:
     *
     *   1. An explicit date must be a real Y-m-d. Anything else is dropped
     *      rather than guessed at, because a guess here creates a post.
     *   2. A date on or before the start is dropped. The start date is the event
     *      being created and already exists; an explicit date equal to it would
     *      produce a second event on the same day, and one before it would put
     *      an occurrence in front of the event that anchors the pattern.
     *   3. Duplicates go, including a date the pattern already produces. Ticking
     *      a Wednesday that the weekly pattern was going to make anyway asks for
     *      one event, not two.
     *   4. The result is sorted ascending, so the count and the list are in the
     *      order somebody reads a calendar in.
     *   5. MAX_OCCURRENCES caps the WHOLE set, not each half. It is a guard on
     *      how many posts one save may create, and it does not care which half
     *      a date came from.
     *
     * @param string   $start
     * @param string[] $pattern_dates
     * @param string[] $extra
     * @return string[]
     */
    private static function merge_dates( $start, $pattern_dates, $extra ) {
        $out = array();
        foreach ( (array) $pattern_dates as $d ) {
            $out[ (string) $d ] = true;
        }
        foreach ( self::clean_dates( $extra, $start ) as $d ) {
            $out[ $d ] = true;
        }
        $out = array_keys( $out );
        sort( $out );
        return array_slice( $out, 0, self::MAX_OCCURRENCES );
    }

    /**
     * A submitted list of explicit dates, reduced to the ones that are usable.
     *
     * Public because the editor's save path needs the same reduction before it
     * decides whether there is anything to generate, and a second copy of rules
     * 1 and 2 above is exactly how the two would drift.
     *
     * @param mixed  $dates
     * @param string $after Y-m-d; dates on or before this are dropped. '' keeps all.
     * @return string[] Ascending, unique.
     */
    public static function clean_dates( $dates, $after = '' ) {
        $after = (string) $after;
        $out   = array();
        foreach ( (array) $dates as $d ) {
            $d = trim( (string) $d );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
                continue;
            }
            // A real calendar day, not merely four digits and two dashes:
            // "2026-02-31" matches the shape and is not a date.
            list( $y, $m, $day ) = array_map( 'intval', explode( '-', $d ) );
            if ( ! checkdate( $m, $day, $y ) ) {
                continue;
            }
            if ( '' !== $after && $d <= $after ) {
                continue;
            }
            $out[ $d ] = true;
        }
        $out = array_keys( $out );
        sort( $out );
        return $out;
    }

    /**
     * The dates the PATTERN alone produces. See dates() for the merged set.
     *
     * @param string $start
     * @param string $end
     * @param string $pattern
     * @param int    $limit
     * @return string[]
     */
    private static function pattern_dates( $start, $end, $pattern, $limit = 0 ) {
        $spec = self::parse_pattern( $pattern );
        if ( ! $spec || ! $start ) {
            return array();
        }
        // A custom group has no cadence: every date it has was chosen by hand
        // and arrives through $extra. Returning here rather than falling into
        // the switch is what makes "custom" cost nothing to support.
        if ( 'custom' === $spec['type'] ) {
            return array();
        }
        $limit = max( 0, (int) $limit );
        if ( '' === (string) $end && $limit <= 0 ) {
            return array();
        }

        $tz = wp_timezone();
        try {
            $from = new DateTimeImmutable( (string) $start, $tz );
            // No end date means "count only": run to the backstop horizon and
            // let $limit stop it.
            $stop = ( '' !== (string) $end )
                ? new DateTimeImmutable( (string) $end, $tz )
                : $from->modify( '+10 years' );
        } catch ( Exception $e ) {
            return array();
        }
        if ( $stop < $from ) {
            return array();
        }

        $cap   = ( $limit > 0 ) ? min( $limit, self::MAX_OCCURRENCES ) : self::MAX_OCCURRENCES;
        $dates = array();

        switch ( $spec['type'] ) {

            case 'daily':
                $cur = $from;
                while ( count( $dates ) < $cap ) {
                    $cur = $cur->modify( '+' . $spec['interval'] . ' day' );
                    if ( $cur > $stop ) { break; }
                    $dates[] = $cur->format( 'Y-m-d' );
                }
                break;

            case 'weekly':
                $days = $spec['days'];
                if ( empty( $days ) ) {
                    $days = array( (int) $from->format( 'w' ) );
                }
                sort( $days );
                /*
                 * WEEK BLOCKS, NOT A RUNNING STEP. With several days chosen the
                 * old "+1 week" arithmetic has nothing to step: Tuesday and
                 * Thursday are not one interval apart. So the cursor walks in
                 * blocks of $interval weeks from the week the start date is in,
                 * and each block emits its chosen days in order. An interval of
                 * 1 emits every week; 2 emits every other week, which is what
                 * biweekly always meant.
                 */
                $week_start = $from->modify( '-' . (int) $from->format( 'w' ) . ' days' );
                for ( $block = 0; count( $dates ) < $cap; $block++ ) {
                    $base = $week_start->modify( '+' . ( $block * $spec['interval'] ) . ' weeks' );
                    if ( $base > $stop->modify( '+7 days' ) ) { break; }
                    foreach ( $days as $d ) {
                        $hit = $base->modify( '+' . $d . ' days' );
                        if ( $hit <= $from ) { continue; }
                        if ( $hit > $stop ) { continue; }
                        if ( count( $dates ) >= $cap ) { break; }
                        $dates[] = $hit->format( 'Y-m-d' );
                    }
                    // A guard against a pathological interval producing an
                    // endless loop with nothing ever emitted.
                    if ( $block > self::MAX_OCCURRENCES ) { break; }
                }
                break;

            case 'monthly':
                $cur = $from;
                while ( count( $dates ) < $cap ) {
                    $cur = $cur->modify( '+' . $spec['interval'] . ' month' );
                    if ( $cur > $stop ) { break; }
                    $dates[] = $cur->format( 'Y-m-d' );
                }
                break;

            case 'monthly_nth':
                $nth = $spec['nth'];
                $dow = $spec['dow'];
                if ( 0 === $nth || $dow < 0 ) {
                    $derived = self::nth_weekday_of_month( $start );
                    if ( ! $derived ) { return array(); }
                    $nth = $derived['nth'];
                    $dow = $derived['dow'];
                }
                $names  = self::weekday_names();
                $day    = $names[ $dow ];
                $cursor = new DateTimeImmutable( $from->format( 'Y-m-01' ), $tz );

                for ( $i = 0; $i < self::MAX_OCCURRENCES && count( $dates ) < $cap; $i++ ) {
                    $cursor = $cursor->modify( '+1 month' );
                    if ( $cursor > $stop ) { break; }

                    if ( -1 === $nth ) {
                        // "last friday of this month" resolves exactly and
                        // always exists, which is the whole difference from
                        // asking for a fifth one.
                        $hit = $cursor->modify( 'last ' . $day . ' of this month' );
                    } else {
                        /*
                         * A MONTH THAT DOES NOT HAVE A FIFTH FRIDAY IS SKIPPED,
                         * not moved to the fourth. Somebody who set up a
                         * fifth-Friday group means the fifth Friday; silently
                         * holding it a week early in the months that have only
                         * four would put an event on a date nobody chose.
                         */
                        $hit = $cursor->modify( sprintf( '%s %s of this month', self::ordinal_word( $nth ), $day ) );
                        if ( ! $hit || $hit->format( 'Y-m' ) !== $cursor->format( 'Y-m' ) ) {
                            continue;
                        }
                    }
                    if ( ! $hit || $hit > $stop || $hit <= $from ) { continue; }
                    $dates[] = $hit->format( 'Y-m-d' );
                }
                break;
        }

        return $dates;
    }

    /**
     * The sentence under the repeat control: what this will do, and to how many.
     *
     * WHY THIS IS A FUNCTION AND NOT A TEMPLATE STRING. Generation creates real
     * posts, once, and the number in this sentence is the number that will
     * exist afterwards. It therefore has to be produced by the same code that
     * produces the dates, not assembled beside it: a sentence that says 21 while
     * dates() makes 23 is worse than no sentence, because somebody read it and
     * pressed the button.
     *
     * The server renders this on page load and portal.js recomputes it on every
     * change from a mirror of this function. The two are cross-checked over a
     * matrix of cases by .claude/recurrence-crosscheck.php.
     *
     * THE PROMPTS ARE PART OF THE CONTRACT. An incomplete control returns the
     * one thing missing rather than a count, because a count computed from an
     * unanswered question is a number somebody will believe.
     *
     * @param string   $start   Y-m-d of the event itself.
     * @param string   $end     Y-m-d the pattern runs until, or ''.
     * @param string   $pattern
     * @param int      $limit   Pattern dates to create after the seed.
     * @param string[] $extra   Explicit dates.
     * @param string   $tail    ", until Dec 31 2026" or ", for a year". Caller's words.
     * @return string
     */
    public static function summary( $start, $end, $pattern, $limit = 0, $extra = array(), $tail = '' ) {
        $spec = self::parse_pattern( $pattern );
        if ( ! $spec ) {
            return 'Does not repeat. One event will be created.';
        }
        if ( '' === (string) $start ) {
            return 'Set the event date first, since every repeat is counted from it.';
        }

        $clean = self::clean_dates( $extra, $start );
        $made  = self::dates( $start, $end, $pattern, $limit, $clean );
        $total = count( $made ) + 1; // the event itself is the first occurrence

        if ( 'custom' === $spec['type'] ) {
            if ( empty( $clean ) ) {
                return 'Add the dates this happens on.';
            }
            // "5 dates. 5 events will be created." The two numbers are the same
            // number on purpose: on a custom schedule a date IS an event, and
            // saying both is what makes that obvious without a sentence about
            // it. The event's own date is one of them, and the list on screen
            // shows it as such.
            return sprintf(
                '%d %s. %d %s will be created.',
                $total, _n( 'date', 'dates', $total ),
                $total, _n( 'event', 'events', $total )
            );
        }

        /*
         * "PICK AT LEAST ONE DAY" IS NOT HANDLED HERE, AND THAT IS DELIBERATE.
         *
         * A weekly pattern with no day ticked is a valid instruction to this
         * engine: dates() reads the weekday off the start date, which is what
         * the bare 'weekly' shorthand has always meant. The prompt belongs to
         * the CONTROL, where a manager has just unticked the last circle and the
         * form is momentarily saying nothing; portal.js shows it there before it
         * asks for a count. Putting it here as well would make this function
         * return a prompt for a case it can answer, and the mirror would have to
         * grow the same unreachable branch to keep the cross-check quiet.
         */
        /*
         * "PLUS N EXTRA DATES" COUNTS THE ONES THAT ADD A DATE, NOT THE ONES
         * IN THE BOX.
         *
         * Tick a Wednesday next to a weekly Wednesday pattern and the merge
         * collapses it: one event, not two. Saying "plus 1 extra date" beside a
         * count that did not move describes an event that is not going to
         * exist, and the manager is left deciding which of the two numbers to
         * believe. This is also exactly the set generate() marks with
         * EXTRA_META, so the sentence describes the stored outcome rather than
         * the contents of a control.
         */
        $net  = array_diff( $clean, self::dates( $start, $end, $pattern, $limit ) );
        $plus = '';
        if ( ! empty( $net ) ) {
            $n    = count( $net );
            $plus = sprintf( ', plus %d extra %s', $n, _n( 'date', 'dates', $n ) );
        }

        return self::pattern_label( $pattern, $start ) . (string) $tail . $plus . '. '
            . sprintf( '%d %s will be created.', $total, _n( 'event', 'events', $total ) );
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
     * EXTRA DATES JOIN THE SAME GROUP AND SAY SO. A Saturday added beside a
     * weekly Wednesday pattern is part of the same programme: "edit all upcoming
     * occurrences" must reach it, a time change must apply to it, and it must
     * appear on the same schedule. What it must NOT do is move when the pattern
     * moves, so each one is stamped with EXTRA_META and repattern_group() reads it.
     *
     * @param int      $seed_id
     * @param string   $pattern
     * @param string   $end_date Y-m-d, inclusive. '' when $limit ends it instead.
     * @param int      $limit    Pattern occurrences to create AFTER the seed; 0 for none.
     * @param string[] $extra    Explicit dates chosen by hand.
     * @return array{group:string,created:int[],dates:string[],extra:string[],skipped:int}
     */
    public static function generate( $seed_id, $pattern, $end_date, $limit = 0, $extra = array() ) {
        $seed_id = (int) $seed_id;
        $out     = array( 'group' => '', 'created' => array(), 'dates' => array(), 'extra' => array(), 'skipped' => 0 );

        $seed = get_post( $seed_id );
        if ( ! $seed || 'uc_event' !== $seed->post_type ) {
            return $out;
        }
        if ( '' !== self::group_of( $seed_id ) ) {
            return $out; // already generated from
        }

        $pattern = self::clean_pattern( $pattern );
        $start   = (string) get_post_meta( $seed_id, '_uc_event_date', true );
        $chosen  = self::clean_dates( $extra, $start );
        $dates   = self::dates( $start, $end_date, $pattern, $limit, $chosen );
        if ( empty( $dates ) ) {
            return $out;
        }

        /*
         * A GROUP WITH NO CADENCE IS RECORDED AS 'custom', NOT AS BLANK.
         *
         * Those are different facts and the schedule screen has to tell them
         * apart: 'custom' means "these dates were chosen one at a time", and an
         * empty pattern means "this event is not on a pattern at all", which is
         * what an event carrying no group says. Storing blank here would put a
         * group on screen under a sentence saying it has no pattern to change,
         * with a weekday selector offering to move dates that were never on a
         * weekday. This is the only place the two can be confused, so it is
         * settled here rather than guessed at by every reader.
         */
        if ( '' === $pattern ) {
            $pattern = 'custom';
        }

        /*
         * WHICH OF THE MERGED DATES IS AN EXTRA IS DECIDED HERE, ONCE.
         *
         * A date that the pattern also produces is NOT an extra even if
         * somebody typed it in: merge_dates() has already collapsed the two
         * into one date, and that one date came out of the pattern, so a
         * pattern edit is entitled to move it. So the test is "chosen by hand
         * AND not produced by the pattern", not merely "chosen by hand".
         */
        $from_pattern = self::dates( $start, $end_date, $pattern, $limit );
        $is_extra     = array_fill_keys( array_diff( $chosen, $from_pattern ), true );

        $group = self::new_group_id();
        update_post_meta( $seed_id, self::GROUP_META, $group );
        update_post_meta( $seed_id, self::PATTERN_META, $pattern );

        foreach ( $dates as $date ) {
            $extra_date = isset( $is_extra[ $date ] );
            $id         = self::create_occurrence( $seed, $date, $group, $pattern, $extra_date );
            if ( $id ) {
                $out['created'][] = $id;
                $out['dates'][]   = $date;
                if ( $extra_date ) {
                    $out['extra'][] = $date;
                }
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
     * A TITLE OVERRIDE IS THE ONE THING A COPY MAY DIFFER IN, and the slug
     * follows it. "Use this event's details on another date" exists so that one
     * date of a weekly group can be called "Annual picnic" or "Guest speaker"
     * while keeping the location, description, times, category, organizer and
     * FAQs of the rest; a copy that kept the group's title would make that date
     * indistinguishable in a list, which is the thing somebody renaming it is
     * trying to fix. Everything else about the copy is untouched, so this stays
     * one idea of what an occurrence is rather than becoming two.
     *
     * @param WP_Post $seed
     * @param string  $date
     * @param string  $group
     * @param string  $pattern
     * @param bool    $is_extra Chosen by hand rather than produced by the pattern.
     * @param string  $title    Replaces the seed's title. '' keeps it.
     * @return int New post ID, or 0.
     */
    private static function create_occurrence( $seed, $date, $group, $pattern, $is_extra = false, $title = '' ) {
        $title = trim( (string) $title );
        $title = ( '' !== $title ) ? sanitize_text_field( $title ) : '';
        $use   = ( '' !== $title ) ? $title : $seed->post_title;

        /*
         * The slug follows whichever title is being used, so a renamed date
         * does not sit at the group's URL with somebody else's words in it.
         *
         * SFAF_Privacy::readable_base() rather than $seed->post_name, because a
         * private seed's post_name IS ITS TOKEN, and naming occurrences after
         * it hands every date to anybody holding the seed's link. It returns
         * the address the seed would have if it were public.
         */
        $base = ( '' !== $title )
            ? sanitize_title( $title )
            : SFAF_Privacy::readable_base( $seed );
        if ( '' === $base ) {
            $base = 'event';
        }

        /*
         * DECIDED BEFORE THE INSERT. A private occurrence used to be created at
         * the predictable {base}-{date} and randomized a moment later, which
         * left that address behind as a _wp_old_slug for core to redirect. The
         * post is now never named anything guessable in the first place.
         */
        $slug_plan = SFAF_Privacy::occurrence_slug( $seed->ID, $base, $date );

        $id = wp_insert_post( array(
            'post_type'    => 'uc_event',
            'post_status'  => $seed->post_status,
            'post_title'   => $use,
            'post_name'    => $slug_plan['post_name'],
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

        /*
         * PRIVACY TRAVELS WITH THE COPY, AND EACH DATE GETS ITS OWN TOKEN.
         *
         * A private event repeated weekly must not produce twelve public dates:
         * "the reception is private" is a fact about the event, and every
         * occurrence is the same event on another day.
         *
         * THE INDEPENDENT TOKEN IS THE PART THAT IS NOT OBVIOUS. Occurrence
         * slugs are {seed-slug}-{date}, so if the seed's token were simply
         * inherited, being sent one date would hand somebody every other date
         * by editing the date on the end of the URL. One forwarded link has to
         * be one forwarded link, so each occurrence gets its own token.
         *
         * THE TOKEN IS ALREADY ON THE POST by the time this runs: it came from
         * occurrence_slug() above and went in with the insert. Randomizing here
         * instead is what used to leave the predictable address behind as an
         * old slug, which core then redirected, which defeated exactly the
         * guarantee the paragraph above describes.
         *
         * Not in $copied_meta, because copying the flag without replacing the
         * slug would produce an event that claims to be private at a guessable
         * address, which is the one state this feature cannot have.
         */
        if ( $slug_plan['private'] ) {
            update_post_meta( $id, SFAF_Privacy::META, '1' );
            update_post_meta( $id, SFAF_Privacy::YOAST_NOINDEX_META, '1' );
            // The address this date would have if it were public, so making one
            // occurrence public again lands on words rather than a token.
            update_post_meta( $id, SFAF_Privacy::PREV_SLUG_META, $slug_plan['prev_slug'] );
        }

        update_post_meta( $id, '_uc_event_date', $date );
        update_post_meta( $id, self::GROUP_META, $group );
        update_post_meta( $id, self::PATTERN_META, $pattern );
        // Written only when true. Absent is the answer for every pattern date
        // and for every occurrence made before this marker existed, so there is
        // one meaning for "no row" rather than two.
        if ( $is_extra ) {
            update_post_meta( $id, self::EXTRA_META, '1' );
        }

        return $id;
    }

    /**
     * Whether one occurrence was chosen by hand rather than produced by the
     * pattern.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_extra_date( $post_id ) {
        return '1' === (string) get_post_meta( (int) $post_id, self::EXTRA_META, true );
    }

    /**
     * Whether a pattern describes a cadence, as opposed to a list of dates.
     *
     * WHY THE DISTINCTION IS WORTH A FUNCTION. "Extra date" means "chosen by
     * hand rather than produced by the pattern", and in a group with no pattern
     * every date was chosen by hand. Marking them is still correct and the
     * marker is still written, because it means what it says; SHOWING it on
     * every row of a custom group would be labelling every date with the one
     * thing they all have in common, which tells a manager nothing and buries
     * the case where the label is the whole point. So the screens ask this
     * before they show the tag.
     *
     * @param string $pattern
     * @return bool
     */
    public static function has_cadence( $pattern ) {
        $spec = self::parse_pattern( $pattern );
        return ( $spec && 'custom' !== $spec['type'] );
    }

    /**
     * The upcoming occurrences of a group split into the two kinds.
     *
     * Used by the schedule screen to label its rows and by the pattern-edit
     * confirmation to name how many dates it is about to leave alone. One
     * function, so the number in the warning and the number of labelled rows
     * cannot disagree.
     *
     * @param string $group
     * @return array{pattern:int[],extra:int[]}
     */
    public static function split_group( $group ) {
        $out = array( 'pattern' => array(), 'extra' => array() );
        foreach ( self::upcoming_in_group( $group ) as $id ) {
            if ( self::is_extra_date( $id ) ) {
                $out['extra'][] = (int) $id;
            } else {
                $out['pattern'][] = (int) $id;
            }
        }
        return $out;
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
     * Every event in a group, past included, earliest first.
     *
     * The schedule screen shows the past as well: a manager needs to see that
     * the group has been running since March, and needs it visibly separated
     * from what is still to come so that nothing offers to edit it.
     *
     * @param string $group
     * @return int[]
     */
    public static function all_in_group( $group ) {
        $group = (string) $group;
        if ( '' === $group ) {
            return array();
        }

        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => SFAF_Series::editable_statuses(),
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'orderby'                => array( 'event_date' => 'ASC', 'ID' => 'ASC' ),
            'meta_query'             => array(
                'group'      => array( 'key' => self::GROUP_META, 'value' => $group ),
                'event_date' => array( 'key' => '_uc_event_date', 'compare' => 'EXISTS' ),
            ),
        ) );

        return array_map( 'intval', $q->posts );
    }

    /* =====================================================================
     * The schedule, in words and as an editable thing
     *
     * WHAT THIS ADDS, AND WHAT IT DELIBERATELY DOES NOT.
     *
     * The pattern has been stored since 3.0.0 and never shown anywhere after
     * the event was created, so a manager looking at a group could not see what
     * cadence it was on and had no way to add a date to it. Both are fixed here.
     *
     * NOTHING BELOW REGENERATES ANYTHING. These are bulk writes over a list of
     * existing posts, decided once, when somebody presses a button. The pattern
     * is still a record of where the events came from, not a rule anything
     * follows: adding a date does not consult it, and removing one does not make
     * anything notice a gap. That is what makes a removed holiday stay removed.
     *
     * EVERY WRITE HERE IS BOUNDED BY upcoming_in_group(), WHICH IS ALREADY
     * "TODAY OR LATER". Past occurrences are the historical record of something
     * that happened in front of people who attended it, and there is no code
     * path from this screen that can reach one.
     * ================================================================== */

    /**
     * The schedule as a sentence: "Every other Wednesday, 6:00 PM to 7:30 PM".
     *
     * pattern_label() answers "what cadence" in the abstract, which is what the
     * event editor needs. This answers "when does this actually happen", which
     * is what somebody looking at a schedule needs, and it needs the weekday and
     * the clock to do it.
     *
     * @param string $pattern
     * @param string $date  Y-m-d of any occurrence, for the weekday and the day number.
     * @param string $start H:i
     * @param string $end   H:i
     * @return string
     */
    public static function schedule_sentence( $pattern, $date, $start = '', $end = '' ) {
        // One source for the words. This used to hold its own copy of the
        // labelling, so a pattern the engine understood could be described one
        // way here and another way in pattern_label(); with the structured
        // patterns there would have been far more room for the two to drift.
        $when = self::pattern_label( $pattern, $date );
        if ( '' === $when ) {
            // The formatter's 'full' style IS 'l, F j, Y'. Spelling it out here
            // was a second copy of the house date format, which is the thing
            // one formatter exists to prevent.
            $when = $date ? sfaf_ap_date( (string) $date, 'full' ) : '';
            if ( '' === $when ) {
                $when = 'No repeating pattern';
            }
        }

        $clock = self::time_phrase( $start, $end );
        return ( '' !== $clock ) ? $when . ', ' . $clock : $when;
    }

    /**
     * "6-7:30 pm", "from 6 pm", or ''.
     *
     * @param string $start
     * @param string $end
     * @return string
     */
    public static function time_phrase( $start, $end = '' ) {
        $start = trim( (string) $start );
        $end   = trim( (string) $end );
        if ( '' === $start ) {
            return '';
        }
        if ( '' === $end ) {
            return 'from ' . sfaf_ap_time( $start );
        }
        // AP style, through the one formatter. This string reaches the public
        // event page as the repeat badge, so it is not an admin-only phrase.
        return sfaf_ap_time_range( $start, $end );
    }

    /**
     * Write a start and end time across the upcoming occurrences of a group.
     *
     * @param string $group
     * @param string $start H:i, required.
     * @param string $end   H:i, '' clears.
     * @return int How many events were written.
     */
    public static function retime_group( $group, $start, $end ) {
        $ids = self::upcoming_in_group( $group );
        if ( empty( $ids ) || '' === trim( (string) $start ) ) {
            return 0;
        }

        foreach ( $ids as $id ) {
            update_post_meta( $id, '_uc_start_time', sanitize_text_field( $start ) );
            if ( '' === trim( (string) $end ) ) {
                delete_post_meta( $id, '_uc_end_time' );
            } else {
                update_post_meta( $id, '_uc_end_time', sanitize_text_field( $end ) );
            }
        }
        return count( $ids );
    }

    /**
     * A pattern with everything it implies written out.
     *
     * WHY IT EXISTS: TO COMPARE TWO PATTERNS HONESTLY. 'weekly' and
     * 'weekly:1:3' are the same schedule for a group anchored on a Wednesday,
     * because the bare shorthand means "the seed's own weekday". A form that
     * prefills the day circles from the anchor and is submitted unchanged
     * produces the explicit string, and comparing that to the stored shorthand
     * as text says the pattern changed when nothing did. The screen would then
     * report "schedule updated, 0 occurrences changed", which is two sentences
     * contradicting each other over a button somebody pressed by mistake.
     *
     * WHAT IT RESOLVES: the weekday a bare 'weekly' or 'biweekly' implies, and
     * the ordinal and weekday a bare 'monthly_nth' implies. Both come from the
     * anchor, which is the same place pattern_label() and pattern_dates() take
     * them from, so a canonical string describes exactly the schedule the
     * shorthand described.
     *
     * @param string $pattern
     * @param string $anchor Y-m-d the pattern is anchored to.
     * @return string
     */
    public static function canonical_pattern( $pattern, $anchor = '' ) {
        $spec   = self::parse_pattern( $pattern );
        $anchor = (string) $anchor;
        if ( ! $spec ) {
            return '';
        }
        if ( 'weekly' === $spec['type'] && empty( $spec['days'] ) && '' !== $anchor ) {
            $dow = self::dow_of( $anchor );
            if ( null !== $dow ) {
                $spec['days'] = array( $dow );
            }
        }
        if ( 'monthly_nth' === $spec['type'] && ( 0 === $spec['nth'] || $spec['dow'] < 0 ) && '' !== $anchor ) {
            $derived = self::nth_weekday_of_month( $anchor );
            if ( $derived ) {
                $spec['nth'] = $derived['nth'];
                $spec['dow'] = $derived['dow'];
            }
        }
        return self::pattern_string( $spec );
    }

    /**
     * Add one more date to a group, copied from an existing occurrence.
     *
     * IDENTICAL TO THE OTHERS BY CONSTRUCTION, because it goes through the same
     * create_occurrence() the generator uses: the same meta list, the same
     * taxonomies, the same image, the same slug scheme. There is no second idea
     * here of what an occurrence is.
     *
     * CAPACITY COMES ACROSS AND REGISTRATIONS DO NOT. Capacity is a property of
     * the event and is in the copied list; RSVPs are rows in another table keyed
     * by event id, and nothing here writes to it. A date added today therefore
     * starts with the group's capacity and nobody registered, which is what a
     * new date is.
     *
     * @param int    $seed_id     The occurrence to copy.
     * @param string $date        Y-m-d.
     * @param string $start       H:i, '' to keep the seed's.
     * @param string $end         H:i, '' to keep the seed's.
     * @param bool   $join_group  Whether it joins the seed's recurrence group.
     * @param string $title       Replaces the copy's title. '' keeps the seed's.
     * @return int|WP_Error New post ID.
     */
    public static function add_occurrence( $seed_id, $date, $start = '', $end = '', $join_group = true, $title = '' ) {
        $seed = get_post( (int) $seed_id );
        if ( ! $seed || 'uc_event' !== $seed->post_type ) {
            return new WP_Error( 'sfaf_add_date_no_seed', 'There is no event to copy this date from.' );
        }

        $date = trim( (string) $date );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return new WP_Error( 'sfaf_add_date_bad_date', 'Give the new date as a real date.' );
        }

        $group   = $join_group ? self::group_of( $seed->ID ) : '';
        $pattern = $join_group ? self::pattern_of( $seed->ID ) : '';

        /*
         * "JOIN THE GROUP" AND "THERE IS A GROUP TO JOIN" ARE TWO QUESTIONS.
         * A series whose dates were never generated from a pattern has no group
         * marker on any of them, so asking to join one has nothing to join.
         * Deciding that here rather than downstream is what keeps the two
         * markers and the cleanup below in agreement: without it a seed with no
         * group produced an occurrence carrying an EMPTY group meta row and an
         * extra-date marker describing a group that does not exist.
         */
        $joined = ( '' !== $group );

        /*
         * A DATE ADDED FROM THE SCHEDULE SCREEN IS AN EXTRA DATE BY DEFINITION.
         * Somebody typed it; the pattern did not produce it. Marking it here is
         * what stops a later pattern change from dragging a one-off Saturday
         * session along with the Wednesdays, and it is the same marker the
         * editor's extra-dates picker writes, so there is one meaning of "extra"
         * rather than one per screen.
         */
        $id = self::create_occurrence( $seed, $date, $group, $pattern, $joined, $title );
        if ( ! $id ) {
            return new WP_Error( 'sfaf_add_date_failed', 'The date could not be added.' );
        }

        /*
         * A DATE THAT IS NOT IN A GROUP CARRIES NEITHER MARKER.
         * create_occurrence() writes whatever it is given, so an empty group
         * would leave an empty meta row behind and upcoming_in_group() matches
         * on value, not existence. Removing them outright is what makes this a
         * genuine one-off that no bulk edit can reach.
         */
        if ( ! $joined ) {
            delete_post_meta( $id, self::GROUP_META );
            delete_post_meta( $id, self::PATTERN_META );
            delete_post_meta( $id, self::EXTRA_META );
        }

        if ( '' !== trim( (string) $start ) ) {
            update_post_meta( $id, '_uc_start_time', sanitize_text_field( $start ) );
        }
        if ( '' !== trim( (string) $end ) ) {
            update_post_meta( $id, '_uc_end_time', sanitize_text_field( $end ) );
        }

        return $id;
    }

    /**
     * Lay out N dates on a pattern, starting from the period a date is in.
     *
     * WHY THIS IS NOT dates(). dates() answers "what does this pattern produce
     * AFTER the seed", which is the generator's question: the seed is an event
     * that already exists and must not be produced twice. This answers "where
     * would N occurrences sit if this pattern had been the one all along",
     * which is what changing a group's pattern needs, and the difference is
     * that the anchor's own period is IN the answer rather than excluded from
     * it. A weekly Wednesday group moved to Tuesdays has to be able to land on
     * the Tuesday of the same week; through dates() the first Tuesday available
     * is in the week after, and the whole group would slip forward by six days
     * for no reason a manager could see.
     *
     * THE FLOOR IS NOT THE ANCHOR AND THE TWO ARE BOTH NEEDED. The anchor says
     * which week or month to start laying out from; the floor says what may not
     * be crossed, and it is today. A group whose next date is this Wednesday can
     * legitimately move back to this Tuesday if that Tuesday has not been yet,
     * and must not if it has.
     *
     * THE ARITHMETIC IS THE SAME ARITHMETIC pattern_dates() USES, deliberately,
     * including the "+N month" behaviour that carries a 31st into the following
     * month and the rule that a month with no fifth Friday is skipped rather
     * than served a fourth. A second monthly calculation in this file would be
     * a second answer to the same question.
     *
     * @param string $pattern
     * @param string $anchor Y-m-d whose week or month the lay-out starts from.
     * @param int    $count  How many dates to return.
     * @param string $floor  Y-m-d; nothing earlier is returned. '' for no floor.
     * @return string[] Y-m-d, ascending. Fewer than $count if it ran out of room.
     */
    public static function plan_from( $pattern, $anchor, $count, $floor = '' ) {
        $spec   = self::parse_pattern( $pattern );
        $count  = (int) $count;
        $anchor = (string) $anchor;
        $floor  = (string) $floor;

        if ( ! $spec || 'custom' === $spec['type'] || $count < 1 || '' === $anchor ) {
            return array();
        }
        $count = min( $count, self::MAX_OCCURRENCES );

        $tz = wp_timezone();
        try {
            $from = new DateTimeImmutable( $anchor, $tz );
        } catch ( Exception $e ) {
            return array();
        }

        // Twice the cap, because the floor can legitimately reject the first
        // period or two before anything is emitted. A guard, not a feature.
        $rounds = self::MAX_OCCURRENCES * 2;
        $out    = array();

        $keep = function ( $ymd ) use ( &$out, $floor ) {
            if ( '' !== $floor && $ymd < $floor ) {
                return;
            }
            $out[] = $ymd;
        };

        switch ( $spec['type'] ) {

            case 'daily':
                $cur = $from;
                for ( $i = 0; $i < $rounds && count( $out ) < $count; $i++ ) {
                    $keep( $cur->format( 'Y-m-d' ) );
                    $cur = $cur->modify( '+' . $spec['interval'] . ' day' );
                }
                break;

            case 'weekly':
                $days = $spec['days'];
                if ( empty( $days ) ) {
                    $days = array( (int) $from->format( 'w' ) );
                }
                sort( $days );
                // Week blocks from the Sunday of the anchor's own week, which is
                // the block the generator would have used had this pattern
                // produced the group. Same shape as pattern_dates().
                $week0 = $from->modify( '-' . (int) $from->format( 'w' ) . ' days' );
                for ( $b = 0; $b < $rounds && count( $out ) < $count; $b++ ) {
                    $base = $week0->modify( '+' . ( $b * $spec['interval'] ) . ' weeks' );
                    foreach ( $days as $dw ) {
                        if ( count( $out ) >= $count ) {
                            break;
                        }
                        $keep( $base->modify( '+' . (int) $dw . ' days' )->format( 'Y-m-d' ) );
                    }
                }
                break;

            case 'monthly':
                // The day of the month comes from the anchor, because it is the
                // only date in the question that has one.
                $cur = $from;
                for ( $i = 0; $i < $rounds && count( $out ) < $count; $i++ ) {
                    $keep( $cur->format( 'Y-m-d' ) );
                    $cur = $cur->modify( '+' . $spec['interval'] . ' month' );
                }
                break;

            case 'monthly_nth':
                $nth = $spec['nth'];
                $dow = $spec['dow'];
                if ( 0 === $nth || $dow < 0 ) {
                    $derived = self::nth_weekday_of_month( $anchor );
                    if ( ! $derived ) {
                        return array();
                    }
                    $nth = $derived['nth'];
                    $dow = $derived['dow'];
                }
                $names = self::weekday_names();
                $day   = $names[ $dow ];
                $month = new DateTimeImmutable( $from->format( 'Y-m-01' ), $tz );

                for ( $i = 0; $i < $rounds && count( $out ) < $count; $i++ ) {
                    $cursor = $month->modify( '+' . $i . ' months' );
                    if ( -1 === $nth ) {
                        $hit = $cursor->modify( 'last ' . $day . ' of this month' );
                    } else {
                        $hit = $cursor->modify( sprintf( '%s %s of this month', self::ordinal_word( $nth ), $day ) );
                        // A month with no fifth Friday is skipped, never served
                        // a fourth. Same rule as the generator.
                        if ( ! $hit || $hit->format( 'Y-m' ) !== $cursor->format( 'Y-m' ) ) {
                            continue;
                        }
                    }
                    if ( ! $hit ) {
                        continue;
                    }
                    $keep( $hit->format( 'Y-m-d' ) );
                }
                break;
        }

        return $out;
    }

    /**
     * Move a group's upcoming pattern dates onto a different pattern.
     *
     * WHAT CHANGES AND WHAT DOES NOT. The dates move; the number of them does
     * not. A group with twelve upcoming sessions has twelve upcoming sessions
     * afterwards, on the new cadence, and nothing is created and nothing is
     * deleted. That is what makes this safe to offer beside a button that says
     * "update 12 occurrences": the number in the sentence is the number of
     * events before and after, so there is no second outcome to explain.
     *
     * THE PAST IS NOT TOUCHED, and cannot be. split_group() is built on
     * upcoming_in_group(), which is "today or later" at the query, so there is
     * no code path from here that reaches a session that has already happened.
     *
     * EXTRA DATES ARE LEFT WHERE THEY ARE, which is the same guarantee
     * repattern_group() gives and for the same reason: a Saturday somebody added
     * beside a weekly Wednesday group was never on the pattern, so there is
     * nothing about it for a pattern change to recompute. They stay in the
     * group, so a time change still reaches them, and the count of what was
     * left alone is returned so the screen can say so.
     *
     * THE PATTERN IS WRITTEN ON EVERY UPCOMING OCCURRENCE, EXTRAS INCLUDED.
     * pattern_of() is read off whichever occurrence a screen happens to have,
     * so a group carrying two different pattern strings would describe itself
     * differently depending on which date was opened. The past keeps the
     * pattern that actually produced it, which is the true record.
     *
     * WHY IT REFUSES RATHER THAN HALF-MOVING. If the lay-out cannot produce a
     * date for every occurrence, writing the ones it did produce would leave
     * the rest of the group sitting on the old cadence with no way to tell
     * which was which. Nothing is written in that case and 'refused' says so.
     *
     * @param string $group
     * @param string $pattern The new pattern, in stored form.
     * @return array{moved:int,left:int,first:string,last:string,pattern:string,refused:bool}
     */
    public static function repattern_group( $group, $pattern ) {
        $out = array(
            'moved'   => 0,
            'left'    => 0,
            'first'   => '',
            'last'    => '',
            'pattern' => '',
            'refused' => false,
        );

        $pattern = self::clean_pattern( $pattern );
        // 'custom' is not a cadence to move a group onto: it means "these dates
        // were chosen one at a time", and there is no arithmetic in it to lay
        // anything out with.
        if ( '' === $pattern || ! self::has_cadence( $pattern ) ) {
            return $out;
        }

        $split       = self::split_group( $group );
        $ids         = $split['pattern'];
        $out['left'] = count( $split['extra'] );
        if ( empty( $ids ) ) {
            return $out;
        }

        $anchor = (string) get_post_meta( $ids[0], '_uc_event_date', true );
        if ( '' === $anchor ) {
            return $out;
        }

        $dates = self::plan_from( $pattern, $anchor, count( $ids ), current_time( 'Y-m-d' ) );
        if ( count( $dates ) < count( $ids ) ) {
            $out['refused'] = true;
            return $out;
        }

        foreach ( $ids as $i => $id ) {
            $old = (string) get_post_meta( $id, '_uc_event_date', true );
            if ( $dates[ $i ] !== $old ) {
                update_post_meta( $id, '_uc_event_date', $dates[ $i ] );
                $out['moved']++;
            }
        }

        foreach ( self::upcoming_in_group( $group ) as $id ) {
            update_post_meta( $id, self::PATTERN_META, $pattern );
        }

        $out['first']   = $dates[0];
        $out['last']    = $dates[ count( $ids ) - 1 ];
        $out['pattern'] = $pattern;
        return $out;
    }

    /**
     * The last date a group has been generated out to.
     *
     * "Generated to" is the last date that EXISTS, extra dates included, because
     * that is what somebody looking at the schedule can see. The anchor a new
     * run is calculated from is a different question and is answered inside
     * extend_group(), which needs the last PATTERN date rather than this one.
     *
     * @param string $group
     * @return string Y-m-d, or ''.
     */
    public static function horizon( $group ) {
        $ids = self::all_in_group( $group );
        if ( empty( $ids ) ) {
            return '';
        }
        return (string) get_post_meta( (int) end( $ids ), '_uc_event_date', true );
    }

    /**
     * Every date a group has ever held, INCLUDING ONES THAT WERE REMOVED.
     *
     * Trashing an occurrence leaves its meta intact, so a removed date is still
     * findable, and finding it is the whole point: a holiday somebody took off
     * the schedule in December must not reappear because somebody extended the
     * series in January. This is what makes "nothing regenerates it" survive the
     * arrival of a generator that runs more than once.
     *
     * @param string $group
     * @return array<string,true> Y-m-d keys.
     */
    private static function group_dates_including_removed( $group ) {
        $group = (string) $group;
        if ( '' === $group ) {
            return array();
        }

        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => array_merge( SFAF_Series::editable_statuses(), array( 'trash' ) ),
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'group'      => array( 'key' => self::GROUP_META, 'value' => $group ),
                'event_date' => array( 'key' => '_uc_event_date', 'compare' => 'EXISTS' ),
            ),
        ) );

        $out = array();
        foreach ( $q->posts as $id ) {
            $d = (string) get_post_meta( (int) $id, '_uc_event_date', true );
            if ( '' !== $d ) {
                $out[ $d ] = true;
            }
        }
        return $out;
    }

    /**
     * Carry a group's existing pattern further into the future.
     *
     * THE COMMON CASE THIS IS FOR: a series runs to December 31 and has to keep
     * going into the new year. Nothing about the cadence changes. Until now the
     * only way to do it was to add the dates one at a time.
     *
     * IT ONLY EVER ADDS, AND ONLY EVER AFTER WHAT IS ALREADY THERE. Three
     * separate rules make that true and each one is load-bearing:
     *
     *   1. The lay-out starts from the last PATTERN date, not the last date. A
     *      group whose latest occurrence is a Saturday somebody added by hand
     *      would otherwise be re-anchored onto Saturdays.
     *   2. A date the group already holds is skipped, and "already holds"
     *      includes TRASHED occurrences. Removing December 24 is meant to stick;
     *      an extend run three weeks later must not quietly put it back. See
     *      group_dates_including_removed().
     *   3. Nothing before today is created. A dormant group whose last date was
     *      in March would otherwise be back-filled with six months of sessions
     *      that never happened.
     *
     * WHAT IT MAKES ARE PATTERN DATES, not extras: the cadence produced them,
     * so a later pattern change is entitled to move them.
     *
     * THIS IS THE FUNCTION A SCHEDULED TOP-UP WOULD CALL, and it is written to
     * be called that way rather than adapted later. It takes a group and a
     * date and nothing else; it derives its own seed, anchor and pattern from
     * the stored data; it touches no request state and returns rather than
     * redirects; and it is idempotent, because a second run with the same
     * horizon finds every date already taken and creates nothing. An "ongoing
     * series" cron would call it once a week with today plus twelve months and
     * need no other entry point.
     *
     * @param string $group
     * @param string $until Y-m-d, inclusive.
     * @return array{created:int[],dates:string[],from:string,anchor:string,skipped:int,reason:string}
     */
    public static function extend_group( $group, $until ) {
        $out = array(
            'created' => array(),
            'dates'   => array(),
            'from'    => '',
            'anchor'  => '',
            'skipped' => 0,
            'reason'  => '',
        );

        $group = (string) $group;
        $until = trim( (string) $until );

        if ( '' === $group ) {
            $out['reason'] = 'no_group';
            return $out;
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
            $out['reason'] = 'bad_date';
            return $out;
        }

        $ids = self::all_in_group( $group );
        if ( empty( $ids ) ) {
            $out['reason'] = 'no_dates';
            return $out;
        }

        /*
         * THE CADENCE IS CHECKED FIRST, AND THE ORDER OF THESE TWO IS A BUG
         * THAT WAS CAUGHT RATHER THAN A STYLE. Every date in a Custom group is
         * marked as an extra date, because every one of them was chosen by
         * hand, so the "last pattern date" search below finds nothing there and
         * returned 'no_pattern_date'. That reason reads as a fault in the data;
         * the truth is that the group has no cadence to carry forward, which is
         * a normal state with its own sentence. The pattern is on every
         * occurrence in a group, so any of them answers this.
         */
        $pattern = self::pattern_of( (int) end( $ids ) );
        if ( ! self::has_cadence( $pattern ) ) {
            $out['reason'] = 'no_cadence';
            return $out;
        }

        // The anchor and the content both come from the LAST PATTERN
        // OCCURRENCE. One post, for two reasons that happen to agree: it is
        // where the cadence's arithmetic has to resume from, and it is the
        // occurrence whose title and details are the group's own rather than a
        // one-off's. A date added through "use this event's details on another
        // date" may carry a title of its own, and copying that forward would
        // name every new session after one picnic.
        $seed_id = 0;
        foreach ( array_reverse( $ids ) as $id ) {
            if ( self::is_extra_date( $id ) ) {
                continue;
            }
            $seed_id       = (int) $id;
            $out['anchor'] = (string) get_post_meta( $seed_id, '_uc_event_date', true );
            break;
        }
        if ( ! $seed_id || '' === $out['anchor'] ) {
            $out['reason'] = 'no_pattern_date';
            return $out;
        }

        $out['from'] = self::horizon( $group );
        if ( $until <= $out['from'] ) {
            $out['reason'] = 'not_further';
            return $out;
        }

        $seed = get_post( $seed_id );
        if ( ! $seed || 'uc_event' !== $seed->post_type ) {
            $out['reason'] = 'no_pattern_date';
            return $out;
        }

        $taken = self::group_dates_including_removed( $group );
        $today = current_time( 'Y-m-d' );

        foreach ( self::dates( $out['anchor'], $until, $pattern, 0 ) as $date ) {
            if ( isset( $taken[ $date ] ) || $date < $today ) {
                $out['skipped']++;
                continue;
            }
            $new = self::create_occurrence( $seed, $date, $group, $pattern, false );
            if ( $new ) {
                $out['created'][] = $new;
                $out['dates'][]   = $date;
            } else {
                $out['skipped']++;
            }
        }

        if ( empty( $out['created'] ) && '' === $out['reason'] ) {
            $out['reason'] = 'nothing_missing';
        }
        return $out;
    }

    /**
     * Fields the "all upcoming" scope FORBIDS, and why.
     *
     * THESE ARE NOT LOCKS WAITING TO BE OPENED. Until 3.23.0 every field was
     * locked behind its own pencil and this list named the two that had no
     * pencil at all; the pencils are gone and the modal is the only gate, so
     * this is now the whole of what a chosen scope refuses. The editor renders
     * exactly these disabled, with the reason printed under the control, because
     * a field that silently refuses to open reads as broken and a field that
     * opens and then quietly does nothing is worse.
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
     * contradict, so it stays editable.
     *
     * @param int[] $ids The events the save would write.
     * @return array<string,string> field => the reason, shown to the manager.
     */
    public static function bulk_locked_fields( $ids ) {
        $locked = array(
            'date' => 'Each occurrence has its own date, so this is edited on one event at a time. Change the scope above to edit this date.',
        );

        foreach ( (array) $ids as $id ) {
            if ( sfaf_get_rsvp_count( (int) $id ) > 0 ) {
                $locked['capacity'] = 'Some of these dates already have RSVPs against them, and capacity is counted per date.';
                break;
            }
        }

        return $locked;
    }
    /**
     * "Does this repeat?", asked so that the answer is readable.
     *
     * WHAT WAS WRONG WITH THE DROPDOWN. Six options, each of which had to be
     * reverse-engineered: "every Thursday" was spelled "Every week" and only
     * meant Thursday if the date above happened to be one, "the first Monday of
     * the month" was spelled "Every month, on the same weekday", and a group
     * meeting Tuesdays AND Thursdays could not be expressed at all. The list
     * described the ARITHMETIC. This describes the schedule.
     *
     * FOUR CONTROLS, EACH ANSWERING ONE QUESTION. How often (segmented), on
     * which days (circles), when it stops (ends), and what that comes to (the
     * summary). The summary is the important one: generation is a creation-time
     * action that makes N independent posts, so the number is stated before the
     * button is pressed rather than discovered afterwards.
     *
     * NO JAVASCRIPT: every section is visible and every control is a real
     * input. The server reads repeat_mode and uses only the fields belonging to
     * it, exactly as the location picker reads location_mode. What is lost
     * without script is the folding away of the sections that do not apply and
     * the live summary; nothing becomes unreachable and nothing is built by
     * script.
     *
     * KEYBOARD: the day circles are checkboxes with visible labels, styled
     * round; the mode switch is a radio group. Both are focusable, both answer
     * to Space, and both are announced as what they are.
     *
     * @param int    $uid_seed A number that makes this control's ids unique on the
     *                         page: the event id where there is one, 0 on a form
     *                         for an event that does not exist yet.
     * @param string $date The event's own date, which anchors every pattern.
     */
    public static function render_control( $uid_seed, $date, $prefill = array() ) {
        $uid  = 'uc-rep-' . (int) $uid_seed;
        $dow  = $date ? (int) SFAF_Recurrence::dow_of( $date ) : (int) current_time( 'w' );
        $days = SFAF_Recurrence::weekday_names();
        $abbr = SFAF_Recurrence::weekday_names( true );

        /*
         * THE PREFILL, WHICH IS HOW A CAPTURED REQUEST REACHES THE APPROVER
         * (3.72.0).
         *
         * IT IS A STARTING POSITION, NOT A STORED VALUE. Every control below
         * still posts and is still read back by from_post(), so nothing here
         * decides anything: an approver who changes the answer changes it, and
         * an approver who presses save without looking gets what the requester
         * asked for, which is the whole point of showing it.
         *
         * DERIVED FROM THE PATTERN STRING, so there is one shape of truth. The
         * request stores what from_post() produced; parse_pattern() turns it
         * back into the spec the controls were built from. A second encoding
         * carrying "which radio was on" would be free to disagree with the
         * pattern beside it.
         *
         * NOTHING IS PRESELECTED BY DEFAULT, and that stays true for every
         * screen that passes no prefill: $pre_mode is '' and the segmented
         * control lands on Never, exactly as it always has.
         */
        $pre         = self::parse_pattern( isset( $prefill['pattern'] ) ? (string) $prefill['pattern'] : '' );
        $pre_dates   = isset( $prefill['dates'] ) ? (array) $prefill['dates'] : array();
        $pre_until   = isset( $prefill['until'] ) ? (string) $prefill['until'] : '';
        $pre_limit   = isset( $prefill['limit'] ) ? (int) $prefill['limit'] : 0;
        $pre_mode    = '';
        $pre_weekly  = 1;
        $pre_days    = array();
        $pre_monthly = 'date';

        if ( $pre ) {
            switch ( $pre['type'] ) {
                case 'custom':
                    $pre_mode = 'custom';
                    break;
                case 'daily':
                    $pre_mode = 'daily';
                    break;
                case 'weekly':
                    $pre_mode   = 'weekly';
                    $pre_weekly = max( 1, isset( $pre['interval'] ) ? (int) $pre['interval'] : 1 );
                    $pre_days   = isset( $pre['days'] ) ? array_map( 'intval', (array) $pre['days'] ) : array();
                    break;
                case 'monthly':
                    $pre_mode = 'monthly';
                    break;
                case 'monthly_nth':
                    $pre_mode    = 'monthly';
                    $pre_monthly = 'nth';
                    break;
            }
        } elseif ( ! empty( $pre_dates ) ) {
            /* Dates and no cadence IS Custom, which is what from_post() would
             * have stored had the pattern survived. A request that named three
             * dates and no rule opens on Custom with those three in the list. */
            $pre_mode = 'custom';
        }

        /* "Ends" follows whichever of the two bounds came with the pattern. An
         * unbounded pattern lands on Never, which is the control's own default
         * and is what the year cap is for. */
        $pre_ends = ( '' !== $pre_until ) ? 'on' : ( ( $pre_limit > 0 ) ? 'after' : 'never' );

        /* The control counts the event itself; the engine counts what it
         * creates. from_post() takes one off on the way in, so this puts it
         * back, and the number an approver sees is the number a requester
         * typed. */
        $pre_count = ( $pre_limit > 0 ) ? ( $pre_limit + 1 ) : 12;

        /* A weekly pattern names its own days; with none named the control
         * falls back to the event's own weekday, which is what it has always
         * done. */
        if ( empty( $pre_days ) ) {
            $pre_days = array( $dow );
        }

        // Through the formatter, and with no ordinal suffix: see the note in
        // SFAF_Recurrence::pattern_label(). The old 'jS' here read "the 4th".
        $day_num = $date ? sfaf_ap_date( $date, 'daynum' ) : '';
        $nth     = $date ? SFAF_Recurrence::nth_weekday_of_month( $date ) : null;
        ?>
        <div class="uc-repeat" data-uc-repeat data-uc-repeat-date="<?php echo esc_attr( $date ); ?>">

            <span class="uc-field-label">Repeats
                <?php echo sfaf_help(
                    'uc-help-repeat-' . (int) $uid_seed,
                    'On save this creates one separate event per date, all grouped so they can be edited together afterwards. It happens once: nothing regenerates, and the schedule is edited on the series from then on.',
                    'repeating'
                ); ?>
            </span>

            <?php
            /*
             * ---- How often. A radio group that looks like a switch. ----
             *
             * CUSTOM IS THE FIFTH OPTION AND IT IS NOT A PATTERN. It covers the
             * programme that meets on a Monday one week, a Tuesday the next and
             * a Wednesday after that: there is no cadence to express, so nothing
             * is stored as one. Choosing it reveals the same date picker the
             * other four modes get, and in that mode the picker holds the whole
             * schedule rather than additions to it.
             */
            ?>
            <div class="uc-seg" role="radiogroup" aria-label="How often this repeats">
                <?php foreach ( array(
                    ''        => 'Never',
                    'daily'   => 'Daily',
                    'weekly'  => 'Weekly',
                    'monthly' => 'Monthly',
                    'custom'  => 'Custom',
                ) as $val => $label ) : ?>
                    <label class="uc-seg-opt">
                        <input type="radio" name="repeat_mode" value="<?php echo esc_attr( $val ); ?>"
                               <?php checked( $pre_mode === $val ); ?> data-uc-repeat-mode />
                        <span><?php echo esc_html( $label ); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php // ---- Weekly ------------------------------------------- ?>
            <div class="uc-repeat-panel" data-uc-repeat-panel="weekly">
                <div class="uc-repeat-every">
                    <span>Every</span>
                    <input type="number" name="repeat_weekly_interval" value="<?php echo (int) $pre_weekly; ?>" min="1" max="52"
                           class="uc-repeat-num" aria-label="Weeks between occurrences" />
                    <span>week(s) on</span>
                </div>
                <div class="uc-days" role="group" aria-label="Which days of the week">
                    <?php foreach ( $abbr as $i => $letter ) : ?>
                        <label class="uc-day">
                            <input type="checkbox" name="repeat_days[]" value="<?php echo (int) $i; ?>"
                                   <?php checked( in_array( (int) $i, $pre_days, true ) ); ?> data-uc-repeat-day />
                            <span aria-hidden="true"><?php echo esc_html( $letter ); ?></span>
                            <span class="uc-visually-hidden"><?php echo esc_html( $days[ $i ] ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="uc-hint">The event's own day is ticked to start with. Tick more than one for a group that meets twice a week.</p>
            </div>

            <?php // ---- Monthly ------------------------------------------ ?>
            <div class="uc-repeat-panel" data-uc-repeat-panel="monthly">
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_monthly_mode" value="date" <?php checked( 'nth' !== $pre_monthly ); ?> data-uc-repeat-monthly />
                    <span>On <strong><?php echo esc_html( $day_num ? 'day ' . $day_num : 'the same date' ); ?></strong> of each month</span>
                </label>
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_monthly_mode" value="nth" <?php checked( 'nth' === $pre_monthly ); ?> data-uc-repeat-monthly />
                    <span>On the</span>
                </label>
                <div class="uc-repeat-nth">
                    <select name="repeat_nth" aria-label="Which occurrence in the month">
                        <?php foreach ( array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', -1 => 'last' ) as $n => $word ) : ?>
                            <option value="<?php echo (int) $n; ?>" <?php selected(
                                ( $pre && 'monthly_nth' === $pre['type'] )
                                    ? ( (int) $pre['nth'] === (int) $n )
                                    : ( $nth && (int) $nth['nth'] === (int) $n )
                            ); ?>><?php echo esc_html( $word ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="repeat_nth_dow" aria-label="Which weekday">
                        <?php foreach ( $days as $i => $name ) : ?>
                            <option value="<?php echo (int) $i; ?>" <?php selected(
                                ( $pre && 'monthly_nth' === $pre['type'] )
                                    ? ( (int) $pre['dow'] === (int) $i )
                                    : ( $i === $dow )
                            ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span>of each month</span>
                </div>
                <?php // "last" is not "fifth": a month with four Fridays has a
                      // last Friday and no fifth one, and the engine skips the
                      // months a fifth would fall outside. ?>
                <p class="uc-hint">Choose <em>last</em> rather than <em>fourth</em> if you mean the final one, since some months have five.</p>
            </div>

            <?php // ---- Ends --------------------------------------------- ?>
            <div class="uc-repeat-panel" data-uc-repeat-panel="ends">
                <span class="uc-field-label">Ends</span>
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_ends" value="never" <?php checked( 'never' === $pre_ends ); ?> data-uc-repeat-ends />
                    <span>No end date</span>
                </label>
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_ends" value="on" <?php checked( 'on' === $pre_ends ); ?> data-uc-repeat-ends />
                    <span>On</span>
                    <input type="date" name="repeat_until" value="<?php echo esc_attr( $pre_until ); ?>" class="uc-repeat-date"
                           aria-label="Repeat until this date" />
                </label>
                <label class="uc-radio-row">
                    <input type="radio" name="repeat_ends" value="after" <?php checked( 'after' === $pre_ends ); ?> data-uc-repeat-ends />
                    <span>After</span>
                    <input type="number" name="repeat_count" value="<?php echo (int) $pre_count; ?>" min="2" max="366" class="uc-repeat-num"
                           aria-label="How many occurrences in total" />
                    <span>occurrences</span>
                </label>
                <?php
                /*
                 * "NO END DATE" CANNOT MEAN FOREVER, AND SAYS SO.
                 *
                 * Generation makes real posts, once. There is no pattern left
                 * afterwards for anything to extend, so an unbounded choice
                 * would have to mean "as many as we are willing to create",
                 * and pretending otherwise would be the one place on this
                 * screen that lies about what the software does.
                 */
                ?>
                <p class="uc-hint">
                    No end date creates a year of dates. Generation happens once, so there is no pattern left running
                    afterwards; add more dates later from the series screen.
                </p>
            </div>

            <?php
            /*
             * ---- The dates picker: Custom's whole schedule, or extras ----
             *
             * ONE CONTROL FOR BOTH JOBS, and one field name, because they are
             * the same job. In Custom mode the list IS the schedule. Beside
             * Daily, Weekly or Monthly the same list is dates the pattern does
             * not cover: a weekly Wednesday group that also meets on one
             * Saturday. Either way each entry becomes an event in the same
             * recurrence group, so "edit all upcoming occurrences" reaches
             * them. Only the label changes, and portal.js changes it.
             *
             * NO JAVASCRIPT: FOUR EMPTY SLOTS. Adding rows without script is
             * the one thing this control cannot do, so the server renders four
             * ordinary date inputs carrying the same repeat_dates[] name. With
             * script they are hidden and the add-and-list interaction replaces
             * them; without it, four dates can still be typed and saved. One
             * field name, one parser, in both cases.
             */
            ?>
            <div class="uc-repeat-panel uc-dates" data-uc-repeat-panel="dates">
                <span class="uc-field-label" data-uc-dates-label>Dates</span>

                <?php // The add row. Hidden until portal.js takes it over, so a
                      // browser with no script is never shown a button that
                      // does nothing. ?>
                <div class="uc-dates-add" data-uc-dates-add hidden>
                    <input type="date" class="uc-repeat-date" data-uc-dates-input
                           aria-label="A date this also happens on" />
                    <button type="button" class="uc-btn uc-btn-sm" data-uc-dates-addbtn>Add date</button>
                </div>

                <ol class="uc-dates-list" data-uc-dates-list></ol>

                <div class="uc-dates-slots" data-uc-dates-slots>
                    <?php
                    /*
                     * FOUR SLOTS, OR AS MANY AS THERE ARE DATES TO SHOW
                     * (3.72.0). Four is the no-script allowance and it is
                     * unchanged; a prefilled control has to be able to render
                     * every date it was given, or an approver opening a request
                     * for six chosen dates would silently lose two. portal.js
                     * hides these and rebuilds the list from their values, so
                     * the script path reads them all either way.
                     */
                    $slot_count = max( 4, count( $pre_dates ) );
                    ?>
                    <?php for ( $i = 0; $i < $slot_count; $i++ ) : ?>
                        <input type="date" name="repeat_dates[]" value="<?php echo esc_attr( isset( $pre_dates[ $i ] ) ? (string) $pre_dates[ $i ] : '' ); ?>" class="uc-repeat-date"
                               aria-label="<?php echo esc_attr( sprintf( 'Date %d', $i + 1 ) ); ?>" />
                    <?php endfor; ?>
                </div>

                <p class="uc-hint" data-uc-dates-hint>
                    Every date here becomes its own event, at the same start and end time, in the same group as the
                    rest. A date the pattern already covers is not added twice.
                </p>
            </div>

            <?php
            /*
             * THE SUMMARY, AND THE COUNT.
             *
             * Rendered by the server for the page load and recomputed by
             * portal.js on every change, from the same rules. The number is
             * the whole point: this creates N independent events and nobody
             * should meet that number for the first time afterwards.
             *
             * BOTH SIDES CALL A FUNCTION RATHER THAN ASSEMBLING A SENTENCE.
             * SFAF_Recurrence::summary() is the server's, ucRecurrenceSummary()
             * is the mirror, and .claude/recurrence-crosscheck.php runs the two
             * against each other. A count that disagrees with what generation
             * makes is the one bug on this screen that costs real posts.
             */
            ?>
            <p class="uc-repeat-summary" data-uc-repeat-summary aria-live="polite">
                <?php
                /* The prefill's own summary, so a control that opens on Weekly
                 * does not open under a sentence reading "Does not repeat".
                 * With no prefill every argument is the empty default this has
                 * always passed. */
                echo esc_html( SFAF_Recurrence::summary(
                    $date,
                    $pre_until,
                    isset( $prefill['pattern'] ) ? (string) $prefill['pattern'] : '',
                    $pre_limit,
                    $pre_dates,
                    ''
                ) );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * How many dates "no end date" creates.
     *
     * Generation is one-off and makes real posts, so unbounded is not a thing
     * this can offer. A year is the honest reading of "keep going", it is what
     * the control tells the manager it will do, and the summary states the
     * resulting number before anything is created.
     *
     * IT MOVED HERE WITH from_post() IN 3.72.0. It was
     * SFAF_Portal::REPEAT_OPEN_ENDED_LIMIT and had exactly one reader, which is
     * that method; a bound on generation belongs with the thing that generates
     * rather than with one of the two screens that ask.
     */
    const REPEAT_OPEN_ENDED_LIMIT = 52;

    /**
     * Read the recurrence control back into a pattern, an end date and a count.
     *
     * ALL FOUR PANELS POST, ALWAYS, because without script they are all on
     * screen and even with it they are only hidden. So this reads repeat_mode
     * first and then looks at nothing else: the weekly interval on a form
     * saved as Monthly is a field somebody never saw, and honouring it would
     * be honouring a value nobody chose. Same rule as the location picker,
     * which reads location_mode and then ignores whichever branch lost.
     *
     * THE DEFAULT IS ALWAYS "NO", in every direction. An unrecognised mode, a
     * missing end, a count of zero: each returns something that generates
     * nothing, because this function's mistakes create posts.
     *
     * THE EXPLICIT DATES ARE READ FOR EVERY MODE EXCEPT "NEVER", and that is
     * the one place this function does not follow "read the mode and ignore the
     * rest". The picker is a single control shown in five of the six states, so
     * repeat_dates[] belongs to the mode rather than to a branch of it: under
     * Custom it is the schedule, beside a pattern it is the additions. Under
     * Never the whole control is off and nothing is read.
     *
     * @return array{0:string,1:string,2:int,3:string[]} pattern, end date,
     *         occurrence limit, explicit dates.
     */
    public static function from_post( $post ) {
        $mode = isset( $post['repeat_mode'] ) ? sanitize_key( wp_unslash( $post['repeat_mode'] ) ) : '';

        // The pre-3.14.0 form posted a single `repeat` select. Still honoured,
        // because a browser can hold a form open across a plugin update.
        if ( '' === $mode && isset( $post['repeat'] ) ) {
            $legacy = SFAF_Recurrence::clean_pattern( wp_unslash( $post['repeat'] ) );
            $until  = isset( $post['repeat_until'] ) ? sanitize_text_field( wp_unslash( $post['repeat_until'] ) ) : '';
            return array( $legacy, $until, 0, array() );
        }

        // Cleaned against the event's own date, so a date on or before it never
        // reaches the generator. SFAF_Recurrence::clean_dates() is the only
        // implementation of that rule; this does not re-state it.
        $own_date = isset( $post['date'] ) ? sanitize_text_field( wp_unslash( $post['date'] ) ) : '';
        $extra    = ( '' !== $mode && isset( $post['repeat_dates'] ) )
            ? SFAF_Recurrence::clean_dates( wp_unslash( $post['repeat_dates'] ), $own_date )
            : array();

        /*
         * CUSTOM ENDS HERE. There is no cadence, so there is no interval to
         * read, no weekday to read and nothing for "Ends" to bound: the list is
         * the whole answer. A Custom save with an empty list generates nothing
         * and leaves a perfectly good one-off event, which is the right outcome
         * for somebody who chose Custom and then changed their mind.
         */
        if ( 'custom' === $mode ) {
            return array( empty( $extra ) ? '' : 'custom', '', 0, $extra );
        }

        $spec = null;
        if ( 'daily' === $mode ) {
            $spec = array( 'type' => 'daily', 'interval' => 1 );
        } elseif ( 'weekly' === $mode ) {
            $days = array();
            if ( isset( $post['repeat_days'] ) && is_array( $post['repeat_days'] ) ) {
                foreach ( wp_unslash( $post['repeat_days'] ) as $d ) {
                    $d = (int) $d;
                    if ( $d >= 0 && $d <= 6 ) {
                        $days[] = $d;
                    }
                }
            }
            $spec = array(
                'type'     => 'weekly',
                'interval' => isset( $post['repeat_weekly_interval'] ) ? (int) $post['repeat_weekly_interval'] : 1,
                'days'     => $days,
            );
        } elseif ( 'monthly' === $mode ) {
            $monthly = isset( $post['repeat_monthly_mode'] ) ? sanitize_key( wp_unslash( $post['repeat_monthly_mode'] ) ) : 'date';
            if ( 'nth' === $monthly ) {
                $spec = array(
                    'type' => 'monthly_nth',
                    'nth'  => isset( $post['repeat_nth'] ) ? (int) $post['repeat_nth'] : 1,
                    'dow'  => isset( $post['repeat_nth_dow'] ) ? (int) $post['repeat_nth_dow'] : 0,
                );
            } else {
                $spec = array( 'type' => 'monthly', 'interval' => 1 );
            }
        }

        if ( ! $spec ) {
            return array( '', '', 0, $extra );
        }
        $pattern = SFAF_Recurrence::pattern_string( $spec );
        if ( '' === $pattern ) {
            return array( '', '', 0, $extra );
        }

        $ends  = isset( $post['repeat_ends'] ) ? sanitize_key( wp_unslash( $post['repeat_ends'] ) ) : 'never';
        $until = '';
        $limit = 0;

        /*
         * AN UNUSABLE "ENDS" ANSWER DROPS THE PATTERN AND KEEPS THE EXTRAS.
         *
         * Those are two separate instructions and only one of them is broken. A
         * manager who ticked Weekly, forgot the end date and added a Saturday
         * has asked for the Saturday unambiguously; throwing it away because the
         * other half of the form is incomplete would silently discard a date
         * they typed. The pattern is dropped because "until" with no date is not
         * an instruction, and the summary said so before the save.
         */
        if ( 'on' === $ends ) {
            $until = isset( $post['repeat_until'] ) ? sanitize_text_field( wp_unslash( $post['repeat_until'] ) ) : '';
            if ( '' === $until ) {
                return array( '', '', 0, $extra );
            }
        } elseif ( 'after' === $ends ) {
            // The control counts the event itself as the first occurrence,
            // because that is what somebody means by "after 12". The engine
            // counts dates it CREATES, which is one fewer.
            $total = isset( $post['repeat_count'] ) ? (int) $post['repeat_count'] : 0;
            $limit = max( 0, $total - 1 );
            if ( $limit <= 0 ) {
                return array( '', '', 0, $extra );
            }
        } else {
            // No end date. Bounded at a year, and the control says so.
            $limit = self::REPEAT_OPEN_ENDED_LIMIT;
        }

        return array( $pattern, $until, $limit, $extra );
    }

}
