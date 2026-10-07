<?php
/**
 * CHECK-IN (3.110.0).
 *
 * The RSVP list of one event offers two ways to say who came, remembered per
 * event: "Check in by name", a Check in button on each registered row that a
 * second press takes back, or "Enter a count", one number. Both write the one
 * attendance figure on the event, _uc_attendance: by name it is the number of
 * registered rows checked in, recounted on every press; by count it is the
 * number typed.
 *
 * A row's check-in is uc_rsvps.checked_in_at (schema 13), the site's clock,
 * NULL when not checked in. Only a confirmed row can be checked in.
 *
 * WHO: anybody the event gate lets edit the event, which is admins, the
 * event's editors and a contributor who can edit it. The routes ask
 * can_edit_event() before calling anything here.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Checkin {

    const MODE_META   = '_uc_checkin_mode';
    const ATTEND_META = '_uc_attendance';

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uc_rsvps';
    }

    /** 'name' or 'count'; 'name' until somebody picks. */
    public static function mode( $event_id ) {
        return 'count' === (string) get_post_meta( (int) $event_id, self::MODE_META, true ) ? 'count' : 'name';
    }

    public static function set_mode( $event_id, $mode ) {
        update_post_meta( (int) $event_id, self::MODE_META, 'count' === $mode ? 'count' : 'name' );
        if ( 'count' !== $mode ) {
            self::recount( $event_id );
        }
    }

    /** The attendance figure, or null when nothing has been recorded. */
    public static function attendance( $event_id ) {
        $v = get_post_meta( (int) $event_id, self::ATTEND_META, true );
        return ( '' === (string) $v ) ? null : (int) $v;
    }

    /** The registered rows, keyed by id. */
    private static function confirmed( $event_id ) {
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %d AND status = %s", (int) $event_id, 'confirmed' ) );
        $out   = array();
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->id ] = $r;
        }
        return $out;
    }

    /** How many registered people are checked in. */
    public static function checked_in_count( $event_id ) {
        $n = 0;
        foreach ( self::confirmed( $event_id ) as $r ) {
            if ( ! empty( $r->checked_in_at ) ) {
                $n++;
            }
        }
        return $n;
    }

    /** By name, the figure is the checked-in count. */
    private static function recount( $event_id ) {
        update_post_meta( (int) $event_id, self::ATTEND_META, self::checked_in_count( $event_id ) );
    }

    /**
     * Check a registered row in, or, if it is checked in, take it back.
     *
     * @return array|null checked: bool, at: 'Y-m-d H:i:s' or '', count: the
     *                    event's checked-in count after; null for a row that
     *                    cannot be checked in.
     */
    public static function toggle( $rsvp_id ) {
        global $wpdb;
        $table = self::table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $rsvp_id ) );
        if ( ! $row || 'confirmed' !== (string) $row->status ) {
            return null;
        }
        $now = empty( $row->checked_in_at ) ? current_time( 'mysql' ) : null;
        $wpdb->update( $table, array( 'checked_in_at' => $now ), array( 'id' => (int) $rsvp_id ) );
        self::recount( (int) $row->event_id );
        return array(
            'checked' => null !== $now,
            'at'      => null !== $now ? $now : '',
            'count'   => self::checked_in_count( (int) $row->event_id ),
        );
    }

    /** "Enter a count": the number of people who came. */
    public static function set_count( $event_id, $count ) {
        update_post_meta( (int) $event_id, self::ATTEND_META, max( 0, (int) $count ) );
    }
}
