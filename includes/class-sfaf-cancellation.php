<?php
/**
 * CANCELLED IS A STATE AN EVENT IS IN, NOT A DELETION.
 *
 * An event that is not happening still has to exist. Somebody registered for it,
 * and that registration is the record that they did; the event is what the
 * record points at. Deleting it strands them, and until 3.36.0 deleting was the
 * only thing on offer, so it happened.
 *
 * WHY META AND NOT A post_status.
 * ---------------------------------------------------------------------------
 * The obvious shape is a `cancelled` post status beside `publish`. It is the
 * wrong one here, and SFAF_Sources already wrote down why in another context:
 * this plugin names `post_status => 'publish'` BY HAND in the shortcodes, the
 * embed payload, the REST feed, the .ics, the reminder query, the summary query
 * and the series listings. A new status is invisible to every one of those until
 * each is found and changed, and the failure mode of missing one is an event
 * that is cancelled everywhere except the place nobody checked.
 *
 * A meta flag inverts that. Nothing changes about which queries return the
 * event, so nothing silently drops it; the two places that must behave
 * differently ask, and everywhere else keeps working. It is the same decision,
 * for the same reason, as SFAF_Privacy, and the exclusion clause below is
 * deliberately the same shape so the two compose rather than fight.
 *
 * The organizer's choice needs a second field anyway, which settles it: a status
 * cannot carry "cancelled and still listed" and "cancelled and hidden" without
 * becoming two statuses.
 *
 * WHAT CANCELLING DOES
 * ---------------------------------------------------------------------------
 *   Public listing   The organizer chooses. STAY (marked cancelled) or HIDE.
 *                    STAY is the default: somebody who registered may come
 *                    looking, and an event that has simply vanished tells them
 *                    nothing at all.
 *   Registrations    Kept. Every one of them. They are the record.
 *   New registration Refused. See SFAF_RSVP.
 *   Reminders        Not sent. Neither the morning-of nor the two-hour summary.
 *                    Both of those select on date and status, and a cancelled
 *                    event still has a date and still says publish, so both ask
 *                    this class explicitly. That is the whole reason is_cancelled()
 *                    is public and cheap.
 *   Imported events  See the note on refetch below.
 *
 * IMPORTED EVENTS, AND WHY A REFETCH CANNOT UN-CANCEL ONE
 * ---------------------------------------------------------------------------
 * Cancelling here is a LOCAL decision about a local event. The source still
 * holds its own copy and a refetch will keep seeing it, so the question is what
 * happens on the next fetch.
 *
 * Two things stop it coming back. SFAF_Sources::update_event() never writes
 * post_status and refuses anything on the adapter's manager_fields() list, and
 * the cancellation meta is on that list, so a payload cannot clear it however it
 * is shaped. And skip_refresh() below takes a cancelled event out of the refresh
 * path entirely, so the source cannot move the date or the location of an event
 * that is not happening and produce a "changed" notification for it.
 *
 * What it does NOT claim: cancelling here does not cancel anything at
 * Eventbrite or GoFundMe Pro. If the event is still running there, people can
 * still register there, and this plugin has no way to know or to stop it. The
 * editor says so where somebody cancels an imported event, because a silent
 * half-cancellation is worse than a refusal.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Cancellation {

    /** '1' when the event is cancelled. Absent otherwise. */
    const META = '_uc_cancelled';

    /** 'stay' (listed, marked) or 'hide' (off the public calendar). */
    const VISIBILITY_META = '_uc_cancelled_visibility';

    /** When it was cancelled, so the editor and the emails can say. */
    const AT_META = '_uc_cancelled_at';

    /** The default, and the better answer. See the note at the top. */
    const DEFAULT_VISIBILITY = 'stay';

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /** Is this event cancelled? */
    public static function is_cancelled( $post_id ) {
        return '1' === (string) get_post_meta( (int) $post_id, self::META, true );
    }

    /** 'stay' or 'hide'. Meaningless unless the event is cancelled. */
    public static function visibility( $post_id ) {
        $v = (string) get_post_meta( (int) $post_id, self::VISIBILITY_META, true );
        return ( 'hide' === $v ) ? 'hide' : self::DEFAULT_VISIBILITY;
    }

    /** Should this event be kept off the public calendar? */
    public static function is_hidden( $post_id ) {
        return self::is_cancelled( $post_id ) && 'hide' === self::visibility( $post_id );
    }

    /** When it was cancelled, or 0. */
    public static function cancelled_at( $post_id ) {
        return (int) get_post_meta( (int) $post_id, self::AT_META, true );
    }

    /**
     * Should a scheduled job leave this event alone?
     *
     * ONE QUESTION, ASKED BY BOTH JOBS, so the morning-of reminder and the
     * pre-event summary cannot come to different conclusions about the same
     * event. Both of their queries ask for post_status 'publish' and today's
     * date, and a cancelled event satisfies both, which is exactly the trap
     * this method exists to close.
     */
    public static function skip_scheduled( $post_id ) {
        return self::is_cancelled( $post_id );
    }

    /**
     * Should a source refresh leave this event alone?
     *
     * Separate from skip_scheduled() although they answer the same today,
     * because they are different questions and one of them may change. A
     * refresh is skipped so the source cannot move the date or the location of
     * an event that is not happening, which would otherwise produce a "this
     * event has moved" email about an event that is cancelled.
     */
    public static function skip_refresh( $post_id ) {
        return self::is_cancelled( $post_id );
    }

    /* ---------------------------------------------------------------------
     * The public-query exclusion
     * ------------------------------------------------------------------- */

    /**
     * The clause that keeps HIDDEN cancelled events out of a public query.
     *
     * ONLY THE HIDDEN ONES. A cancelled event set to STAY is supposed to be on
     * the public calendar, wearing a cancelled badge, so it must not be
     * excluded here. The clause therefore tests the VISIBILITY key rather than
     * the cancelled key: 'hide' is only ever written alongside a cancellation,
     * so one comparison answers both halves and there is no second key to get
     * out of step with the first.
     */
    public static function meta_clause() {
        return array(
            'relation' => 'OR',
            array( 'key' => self::VISIBILITY_META, 'compare' => 'NOT EXISTS' ),
            array( 'key' => self::VISIBILITY_META, 'value' => 'hide', 'compare' => '!=' ),
        );
    }

    /**
     * Add the clause to a WP_Query args array, in place.
     *
     * Same shape and same reasoning as SFAF_Privacy::exclude(), deliberately:
     * appended beside an existing AND, wrapped only around an explicit OR,
     * because appending to an OR would make the date window optional and
     * wrapping an AND would move a named clause a level deeper. The two are
     * called one after the other on the same args and must compose without
     * either noticing the other.
     *
     * @param array $args WP_Query args, modified in place.
     */
    public static function exclude( &$args ) {
        $existing = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

        if ( empty( $existing ) ) {
            $args['meta_query'] = array( self::meta_clause() );
            return;
        }

        $relation = isset( $existing['relation'] ) ? strtoupper( (string) $existing['relation'] ) : 'AND';

        if ( 'AND' === $relation ) {
            $existing[]         = self::meta_clause();
            $args['meta_query'] = $existing;
            return;
        }

        $args['meta_query'] = array(
            'relation' => 'AND',
            $existing,
            self::meta_clause(),
        );
    }

    /* ---------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------- */

    /**
     * Cancel an event, or un-cancel it.
     *
     * IT SENDS NOTHING. Deciding who to tell is the caller's business and is
     * asked at the prompt, because the same state change is reached from the
     * editor, from a bulk series operation and (for un-cancelling) from an
     * ordinary save. A write that mailed people as a side effect would send
     * from all three.
     *
     * @param int    $post_id
     * @param bool   $cancelled
     * @param string $visibility 'stay'|'hide'. Ignored when un-cancelling.
     * @return bool Whether the state actually changed.
     */
    public static function set( $post_id, $cancelled, $visibility = self::DEFAULT_VISIBILITY ) {
        $post_id = (int) $post_id;
        $was     = self::is_cancelled( $post_id );

        if ( ! $cancelled ) {
            delete_post_meta( $post_id, self::META );
            delete_post_meta( $post_id, self::VISIBILITY_META );
            delete_post_meta( $post_id, self::AT_META );
            return $was;
        }

        update_post_meta( $post_id, self::META, '1' );
        update_post_meta( $post_id, self::VISIBILITY_META, ( 'hide' === $visibility ) ? 'hide' : 'stay' );
        if ( ! $was ) {
            update_post_meta( $post_id, self::AT_META, time() );
        }
        return ! $was;
    }

    /**
     * How a cancelled event is described in a list. One phrase, one place.
     *
     * @param int $post_id
     * @return string '' when the event is not cancelled.
     */
    public static function label( $post_id ) {
        if ( ! self::is_cancelled( $post_id ) ) {
            return '';
        }
        return ( 'hide' === self::visibility( $post_id ) )
            ? 'Cancelled, hidden from the public calendar'
            : 'Cancelled, still listed';
    }
}
