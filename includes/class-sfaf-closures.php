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
            /*
             * THE NOTE IS REBUILT HERE TOO (3.84.0), and it has to be. This
             * rebuilds every row from a fixed set of keys rather than passing
             * the stored array through, so a field added to save() and not to
             * this list is written, stored, and then silently dropped by every
             * reader: get(), covering() and spans() all come through here. That
             * is how the note behaved for its first draft.
             *
             * Re-cleaned on read like the dates above, for the same reason: the
             * option can be edited by hand or restored from an old backup, and
             * a reader should not be the first thing to trust it.
             */
            /*
             * AND WHAT STAYS OPEN (3.105.0), for the same reason as the note:
             * a key missing from this list is written by save() and then read
             * back by nobody. A row saved before 3.105.0 has none, which is an
             * empty list.
             */
            $out[] = array(
                'id'    => (string) $id,
                'label' => isset( $row['label'] ) ? (string) $row['label'] : '',
                'start' => $start,
                'end'   => $end,
                'note'  => isset( $row['note'] ) ? self::clean_note( $row['note'] ) : '',
                'open'  => isset( $row['open'] ) ? self::clean_open( $row['open'], false ) : array(),
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

    /* ---------------------------------------------------------------------
     * The closed-day warning (3.105.0)
     *
     * A WARNING, NEVER A REFUSAL. Closures are calendar-wide and an event on
     * one is often right: the pharmacy that stays open holds its own events,
     * and a closure for the office says nothing about a march in the park. So
     * nothing here can stop a save, and nothing reaches the save path at all.
     * ------------------------------------------------------------------- */

    /**
     * Every closure that has not ended, for the script that names them.
     *
     * Only the name and the dates: the note and what stays open are for the
     * calendar, and a form needs to know only that the day is closed and what
     * the closure is called.
     *
     * @return array[] Each array{start:string,end:string,name:string}
     */
    public static function upcoming_payload() {
        $today = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        $out   = array();
        foreach ( self::all() as $row ) {
            if ( $row['end'] < $today ) {
                continue;
            }
            $out[] = array( 'start' => $row['start'], 'end' => $row['end'], 'name' => self::name( $row ) );
        }
        return $out;
    }

    /**
     * The line under a date field, and the data it is drawn from. ONE RENDER
     * for the event editor and both public forms.
     *
     * Drawn empty and hidden; portal.js fills it in as soon as a date is
     * picked, and for a repeating event names every generated date that falls
     * on a closure. With no script it stays hidden, which costs nothing: the
     * warning never decided anything.
     *
     * @return string
     */
    public static function date_warning_markup() {
        $payload = self::upcoming_payload();
        if ( ! $payload ) {
            return '';
        }
        // A span, because it sits inside the date field's <label>.
        return '<span class="uc-closed-warn" data-uc-closed-warn role="status" hidden></span>'
            . '<script type="application/json" data-uc-closures>'
            . wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP )
            . '</script>';
    }

    /**
     * The closure a stored event date falls on, phrased for a flag: "Closed:
     * Thanksgiving", or "Closed" for a closure with no name. '' when open.
     *
     * @param string $date Y-m-d
     * @return string
     */
    public static function flag_for( $date ) {
        $row = self::covering( $date );
        if ( ! $row ) {
            return '';
        }
        $name = self::name( $row );
        return '' !== $name ? 'Closed: ' . $name : 'Closed';
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
     * The closure's free text note, or '' (3.84.0).
     *
     * WHAT IT IS FOR. SFAF can be closed overall while one site stays open, and
     * "Closed for Labor Day" alone is then wrong for whoever is standing
     * outside the 6th Street Center. The note is the place to say so.
     *
     * FREE TEXT, NOT A LIST OF VENUES. That was considered and set aside: a
     * venue picker turns a sentence somebody wants to write into a data model
     * with its own rules about a site that is half open. See PROJECT.md.
     *
     * A closure saved before this existed has no 'note' key at all, so this is
     * read with isset() rather than assumed. That is the whole of the migration:
     * an absent note is an empty note and renders as it always did.
     *
     * @param array $row
     * @return string
     */
    public static function note( $row ) {
        return isset( $row['note'] ) ? trim( (string) $row['note'] ) : '';
    }

    /**
     * The note, trimmed to fit a month grid cell (3.84.0).
     *
     * WHY THE GRID GETS A SHORTER ONE. A day cell is already the tightest space
     * on the calendar and Mark has days carrying nine events. A note written for
     * the list card ("The 6th Street Center is open 9 to 5 as usual") would push
     * the events out of the cell or be clipped by overflow with no sign that
     * anything was cut.
     *
     * TRUNCATION IS VISIBLE, WITH THE FULL TEXT STILL REACHABLE. The cell shows
     * an ellipsis so a reader can see there is more, and the renderer puts the
     * whole note on the title attribute, so it is one hover or one tap away and
     * a screen reader gets all of it. Cutting text and saying nothing is the
     * failure this avoids.
     *
     * CUT ON A WORD BOUNDARY, never mid-word, because "Closed for Labor D..."
     * reads as a rendering fault rather than a deliberate shortening.
     *
     * @param array $row
     * @param int   $limit Characters before trimming.
     * @return string
     */
    public static function note_short( $row, $limit = 32 ) {
        $note  = self::note( $row );
        $limit = max( 8, (int) $limit );

        if ( '' === $note ) {
            return '';
        }

        $len = function_exists( 'mb_strlen' ) ? mb_strlen( $note ) : strlen( $note );
        if ( $len <= $limit ) {
            return $note;
        }

        $cut = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, $limit ) : substr( $note, 0, $limit );
        $sp  = strrpos( $cut, ' ' );
        if ( false !== $sp && $sp >= 8 ) {
            $cut = substr( $cut, 0, $sp );
        }

        return rtrim( $cut, " \t\n\r\0\x0B,.;:" ) . '…';
    }

    /**
     * Strip and bound a note on the way in.
     *
     * TAGS OUT. This is typed in the WordPress admin by an administrator and
     * rendered on the public calendar, so it goes through the same stripping a
     * closure's name does rather than being trusted for being staff-written.
     *
     * A CEILING, because the option holds up to 200 closures in one row and a
     * note is the only unbounded field any of them has.
     */
    private static function clean_note( $note ) {
        $note = trim( wp_strip_all_tags( (string) $note ) );
        $note = preg_replace( '/\s+/u', ' ', $note );
        return ( function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 200 ) : substr( $note, 0, 200 ) );
    }

    /**
     * What stays open, one line per row (3.105.0).
     *
     * "Strut Pharmacy open 10 am–2 pm". The venue is stored by reference and
     * its name read here, at display, so renaming a venue renames the line;
     * a row whose venue has since been deleted says nothing rather than
     * naming a place that no longer exists. The hours go through the one
     * formatter, which puts the en dash in.
     *
     * STRUCTURED, WHERE 3.84.0 SET THIS ASIDE FOR FREE TEXT. The note stays
     * for anything else; this is the one sentence people kept writing into it,
     * and it is the one that has to be right to the hour.
     *
     * @param array $row
     * @return string[]
     */
    public static function open_lines( $row ) {
        $lines = array();
        foreach ( isset( $row['open'] ) ? (array) $row['open'] : array() as $o ) {
            $venue = class_exists( 'SFAF_Venues' ) ? SFAF_Venues::get( (int) $o['venue'] ) : null;
            if ( ! $venue ) {
                continue;
            }
            $lines[] = $venue->name . ' open ' . sfaf_ap_time_range( $o['from'], $o['to'] );
        }
        return $lines;
    }

    /**
     * Rows of what stays open, cleaned (3.105.0).
     *
     * Each is a venue id, an opening time and a closing time, H:i. A row
     * missing any of the three is dropped, so an empty row added and never
     * filled in is not stored. On the way in the venue must exist; on the way
     * out it is left for open_lines() to resolve, so a venue deleted later
     * does not rewrite the option.
     *
     * @param mixed $rows
     * @param bool  $check_venue
     * @return array[] Each array{venue:int,from:string,to:string}
     */
    private static function clean_open( $rows, $check_venue = true ) {
        $out = array();
        foreach ( is_array( $rows ) ? $rows : array() as $o ) {
            if ( ! is_array( $o ) ) {
                continue;
            }
            $venue = isset( $o['venue'] ) ? (int) $o['venue'] : 0;
            $from  = isset( $o['from'] ) ? trim( (string) $o['from'] ) : '';
            $to    = isset( $o['to'] ) ? trim( (string) $o['to'] ) : '';
            if ( ! $venue || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $from ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $to ) ) {
                continue;
            }
            if ( $check_venue && class_exists( 'SFAF_Venues' ) && ! SFAF_Venues::exists( $venue ) ) {
                continue;
            }
            $out[] = array( 'venue' => $venue, 'from' => $from, 'to' => $to );
            if ( count( $out ) >= 20 ) {
                break;
            }
        }
        return $out;
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
     * @param string $note
     * @param array  $open  What stays open: rows of venue, from, to (3.105.0).
     * @return string|WP_Error The id.
     */
    public static function save( $id, $label, $start, $end = '', $note = '', $open = array() ) {
        $label = trim( wp_strip_all_tags( (string) $label ) );
        $note  = self::clean_note( $note );
        $open  = self::clean_open( $open );
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
            'note'  => $note,
            'open'  => $open,
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
