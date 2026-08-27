<?php
/**
 * DAYS SFAF IS CLOSED.
 *
 * A closure is not an event and must never become one. It has no landing page,
 * no permalink, nothing to click, nothing to register for, and nobody is
 * reminded about it. It exists so somebody looking at the calendar in November
 * can see that the office is shut on Thanksgiving without an "event" called
 * Thanksgiving appearing in the list, in the .ics feed, in search results and in
 * the pending queue.
 *
 * WHY AN OPTION AND NOT A POST TYPE.
 * ---------------------------------------------------------------------------
 * A post type is the obvious shape and it is the wrong one, for the same reason
 * teams and FAQ sets are not post types: a closure is a date, a label and
 * nothing else, with no permalink, no revisions, no author and no editing
 * screen of its own worth having. Registering one would bring an archive
 * nobody wants, a REST route nobody asked for, and a row in every WP_Query
 * over posts that somebody would eventually have to remember to exclude.
 *
 * That last one is the whole argument. Every leak this class has to avoid is a
 * leak that only exists if closures are posts. They are not, so:
 *
 *   The pending queue     queries uc_event. A closure is not a post.
 *   The .ics feed         builds from uc_event ids. A closure has no id.
 *   Search                SFAF_Search whitelists post meta on uc_event.
 *   RSVP                  every path starts from an event_id that must resolve
 *                         to a uc_event post.
 *   Reminders, summaries  WP_Query over uc_event.
 *   The REST feed         WP_Query over uc_event.
 *   Sitemaps, SEO         hooked to post types.
 *   The recurrence group  post meta on uc_event.
 *   Teams and organizers  taxonomy relationships on uc_event.
 *
 * None of those needs a new exclusion, because none of them can see an option.
 * The audit in .claude/closures-test.php asserts that rather than trusting it:
 * it fails if this file ever gains a register_post_type() call.
 *
 * A MULTI-DAY CLOSURE IS ONE ENTRY SPANNING DATES.
 * ---------------------------------------------------------------------------
 * One entry, a start and an end, not one row per day. Somebody closing for the
 * winter break enters it once and edits it once, and a four-day closure that
 * had to be typed four times would be four chances to type it differently. The
 * renderers expand it: the month grid marks every day in the range, and a list
 * shows the range as one card, because those are different questions. See
 * covering() and spans().
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Closures {

    /** Where closures live. Autoload on: every calendar render asks. */
    const OPTION = 'sfaf_closures';

    /** A ceiling, so a runaway loop cannot fill the option. */
    const MAX = 200;

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /**
     * Every closure, earliest first.
     *
     * @return array[] Each array{id:string,label:string,start:string,end:string}
     */
    public static function all() {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $out = array();
        foreach ( $raw as $id => $row ) {
            if ( ! is_array( $row ) || empty( $row['start'] ) ) {
                continue;
            }
            $start = self::clean_date( $row['start'] );
            if ( '' === $start ) {
                continue;
            }
            $end = isset( $row['end'] ) ? self::clean_date( $row['end'] ) : '';
            // An end before the start is a typo, not a range. Treated as a
            // single day rather than refused, because refusing at READ time
            // would hide a row somebody can no longer find to correct.
            if ( '' === $end || $end < $start ) {
                $end = $start;
            }
            $out[] = array(
                'id'    => (string) $id,
                'label' => isset( $row['label'] ) ? (string) $row['label'] : '',
                'start' => $start,
                'end'   => $end,
            );
        }

        usort( $out, function ( $a, $b ) {
            return strcmp( $a['start'], $b['start'] );
        } );

        return $out;
    }

    /** One closure, or null. */
    public static function get( $id ) {
        foreach ( self::all() as $row ) {
            if ( $row['id'] === (string) $id ) {
                return $row;
            }
        }
        return null;
    }

    /**
     * The closure covering this date, or null.
     *
     * THE MONTH GRID'S QUESTION. It asks per day, so this answers per day, and
     * a four-day closure answers for each of its four days from the one entry.
     *
     * @param string $date Y-m-d
     * @return array|null
     */
    public static function covering( $date ) {
        $date = self::clean_date( $date );
        if ( '' === $date ) {
            return null;
        }
        foreach ( self::all() as $row ) {
            if ( $date >= $row['start'] && $date <= $row['end'] ) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Closures overlapping a window, as spans rather than days.
     *
     * A LIST'S QUESTION, and it is not the grid's. A list shows a four-day
     * closure as one card reading "Closed December 24 to 27", because four
     * identical cards in a row is four times the noise for one fact. The grid
     * marks four squares, because a square is a day and the day IS the answer
     * there.
     *
     * @param string $from Y-m-d inclusive
     * @param string $to   Y-m-d inclusive
     * @return array[]
     */
    public static function spans( $from, $to ) {
        $from = self::clean_date( $from );
        $to   = self::clean_date( $to );
        if ( '' === $from || '' === $to ) {
            return array();
        }
        $out = array();
        foreach ( self::all() as $row ) {
            // Overlap, not containment: a closure that starts before the window
            // and ends inside it is in the window.
            if ( $row['end'] >= $from && $row['start'] <= $to ) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Is anything closed on this date? */
    public static function is_closed( $date ) {
        return null !== self::covering( $date );
    }

    /**
     * How a closure reads. One phrase, one place, every surface.
     *
     * "Closed for Thanksgiving" when there is a label, and "Closed" when there
     * is not, rather than "Closed for" trailing off.
     */
    public static function text( $row ) {
        return ( '' !== self::name( $row ) ) ? 'Closed for ' . self::name( $row ) : 'Closed';
    }

    /**
     * Just the closure's own name, with no sentence around it.
     *
     * THE SECOND LINE OF THE LABEL, in both renderers. text() is the sentence
     * form and is what a screen reader gets, because "Closed for Labor Day"
     * before an event count is a fact read in one breath. This is the same
     * name for the two-line label a sighted reader sees, where "CLOSED" is
     * already its own line and repeating the word under it would be noise.
     *
     * One accessor rather than two renderers each reaching into $row['label'],
     * so a closure with no name behaves the same way in both.
     *
     * @param array $row
     * @return string '' when the closure was never given a name.
     */
    public static function name( $row ) {
        return isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';
    }

    /**
     * The dates a closure covers, as a phrase. For a list card.
     *
     * @param array $row
     * @return string
     */
    public static function when( $row ) {
        if ( $row['start'] === $row['end'] ) {
            return sfaf_ap_date( $row['start'], 'full' );
        }
        // An AP range, through the one formatter. Both ends in full, because a
        // closure can span a month boundary and "December 30 to 2" is not a
        // date range anybody reads correctly.
        return sfaf_ap_date( $row['start'], 'full' ) . ' to ' . sfaf_ap_date( $row['end'], 'full' );
    }

    /* ---------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------- */

    /**
     * Create or update a closure.
     *
     * @param string $id    '' to create.
     * @param string $label
     * @param string $start Y-m-d
     * @param string $end   Y-m-d, or '' for a single day.
     * @return string|WP_Error The id.
     */
    public static function save( $id, $label, $start, $end = '' ) {
        $label = trim( wp_strip_all_tags( (string) $label ) );
        $start = self::clean_date( $start );
        $end   = self::clean_date( $end );

        if ( '' === $start ) {
            return new WP_Error( 'sfaf_closure_no_date', 'Give the closure a start date.' );
        }
        if ( '' === $end ) {
            $end = $start;
        }
        if ( $end < $start ) {
            return new WP_Error( 'sfaf_closure_backwards', 'The last day is before the first day.' );
        }

        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }

        $id = trim( (string) $id );
        if ( '' === $id || ! isset( $raw[ $id ] ) ) {
            if ( count( $raw ) >= self::MAX ) {
                return new WP_Error( 'sfaf_closure_full', 'There are already too many closures recorded.' );
            }
            // Readable, so the option dump is legible, and unique by the date it
            // starts plus a counter for two closures on one day.
            $base = 'c' . str_replace( '-', '', $start );
            $id   = $base;
            $n    = 2;
            while ( isset( $raw[ $id ] ) ) {
                $id = $base . '-' . $n;
                $n++;
            }
        }

        $raw[ $id ] = array(
            'label' => $label,
            'start' => $start,
            'end'   => $end,
        );

        update_option( self::OPTION, $raw );
        return $id;
    }

    /**
     * Delete a closure.
     *
     * ALWAYS ALLOWED, and nothing has to be checked first. No event refers to
     * one, nothing is registered for one, and removing it leaves every event on
     * that day exactly where it was. It is the category rule from PROJECT.md,
     * arrived at trivially: there is nothing on the other side of the
     * relationship because there is no relationship.
     */
    public static function delete( $id ) {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) || ! isset( $raw[ (string) $id ] ) ) {
            return new WP_Error( 'sfaf_closure_missing', 'That closure no longer exists.' );
        }
        unset( $raw[ (string) $id ] );
        update_option( self::OPTION, $raw );
        return true;
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /**
     * A date, or ''. Y-m-d only, and checked for being a real one.
     *
     * checkdate() rather than strtotime(): strtotime('2026-02-31') answers
     * March 3rd, which would silently move a closure to a day nobody chose.
     */
    private static function clean_date( $date ) {
        $date = trim( (string) $date );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
            return '';
        }
        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return '';
        }
        return $date;
    }
}
