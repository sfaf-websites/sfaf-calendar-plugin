<?php
/**
 * TEAM AND ACCESS: WHICH EVENTS A PERSON HAS (3.110.1).
 *
 * The rule, which CLAUDE.md 7 and PROJECT.md 5 carry: a team decides which
 * events a person has; their level decides what they can do on those events.
 *
 * A person's events are the ones they created, plus the ones whose "Team and
 * access" names a team they are on or names them. Calendar admins have every
 * event. SFAF_Portal::user_can_edit_event() combines those and is the only
 * answer anything asks; this class answers only "does Team and access name
 * this person", which that function asks last, after calendar access.
 *
 *   _uc_event_teams        the event's own teams (SFAF_Teams::ACCESS_META)
 *   _uc_event_people       the event's own people, user ids
 *   _uc_access_own         '1' when the event keeps its own, even an empty one
 *   _sfaf_series_access_teams / _sfaf_series_access_people   the series default
 *
 * THE SERIES DEFAULT IS INHERITED, NOT COPIED. An event with nothing of its own
 * follows its series as it stands today, so a change to the series default
 * reaches every event that never set its own. Saving an event whose choice
 * equals its series default stores nothing, so it keeps following.
 *
 * LIVE BOTH WAYS. Nothing is copied onto a person or an event: joining a team,
 * or being named, takes effect on the next page load, and so does leaving.
 *
 * NOTIFICATIONS ARE NOT ACCESS. _uc_notify_teams is a different key and grants
 * nothing; see PROJECT.md 5 for why that is permanent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Access {

    const PEOPLE_META   = '_uc_event_people';
    const OWN_META      = '_uc_access_own';
    const SERIES_TEAMS  = '_sfaf_series_access_teams';
    const SERIES_PEOPLE = '_sfaf_series_access_people';
    const MAX_PEOPLE    = 20;

    /** Team ids that exist, in order, no repeats, at most MAX_PER_EVENT. */
    private static function clean_teams( $ids ) {
        $known = SFAF_Teams::all();
        $out   = array();
        foreach ( (array) $ids as $id ) {
            $id = sanitize_key( (string) $id );
            if ( '' !== $id && isset( $known[ $id ] ) && ! in_array( $id, $out, true ) ) {
                $out[] = $id;
            }
            if ( count( $out ) >= SFAF_Teams::MAX_PER_EVENT ) {
                break;
            }
        }
        return $out;
    }

    /** Positive user ids, no repeats, at most MAX_PEOPLE, sorted. */
    private static function clean_people( $ids ) {
        $out = array();
        foreach ( (array) $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && ! in_array( $id, $out, true ) ) {
                $out[] = $id;
            }
        }
        sort( $out );
        return array_slice( $out, 0, self::MAX_PEOPLE );
    }

    /** Whether the event keeps its own Team and access rather than its series'. */
    public static function has_own( $event_id ) {
        $event_id = (int) $event_id;
        if ( '1' === (string) get_post_meta( $event_id, self::OWN_META, true ) ) {
            return true;
        }
        // Events from before 3.110.1 hold teams with no marker: they are their own.
        $teams  = get_post_meta( $event_id, SFAF_Teams::ACCESS_META, true );
        $people = get_post_meta( $event_id, self::PEOPLE_META, true );
        return ! empty( $teams ) || ! empty( $people );
    }

    /** The series' default teams. */
    public static function series_teams( $series_id ) {
        return (int) $series_id ? self::clean_teams( get_term_meta( (int) $series_id, self::SERIES_TEAMS, true ) ) : array();
    }

    /** The series' default people. */
    public static function series_people( $series_id ) {
        return (int) $series_id ? self::clean_people( get_term_meta( (int) $series_id, self::SERIES_PEOPLE, true ) ) : array();
    }

    private static function series_of( $event_id ) {
        return class_exists( 'SFAF_Series' ) ? (int) SFAF_Series::id_for_event( (int) $event_id ) : 0;
    }

    /** The teams that give this event to their members: its own, or its series'. */
    public static function teams( $event_id ) {
        if ( self::has_own( $event_id ) ) {
            return SFAF_Teams::access_for_event( $event_id );
        }
        return self::series_teams( self::series_of( $event_id ) );
    }

    /** The people this event is given to by name: its own, or its series'. */
    public static function people( $event_id ) {
        if ( self::has_own( $event_id ) ) {
            return self::clean_people( get_post_meta( (int) $event_id, self::PEOPLE_META, true ) );
        }
        return self::series_people( self::series_of( $event_id ) );
    }

    /**
     * Does Team and access name this person, by team or by name?
     *
     * It answers only that. Calendar access, the organizer and admins are
     * SFAF_Portal::user_can_edit_event()'s, which is the one gate.
     */
    public static function names_user( $user_id, $event_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return false;
        }
        if ( in_array( $user_id, self::people( $event_id ), true ) ) {
            return true;
        }
        $all = SFAF_Teams::all();
        foreach ( self::teams( $event_id ) as $tid ) {
            if ( isset( $all[ $tid ] ) && in_array( $user_id, $all[ $tid ]['users'], true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Store an event's Team and access. Equal to its series default, nothing is
     * stored and the event follows the series; otherwise it is the event's own,
     * an empty choice included.
     */
    public static function set( $event_id, $teams, $people ) {
        $event_id = (int) $event_id;
        $teams    = self::clean_teams( $teams );
        $people   = self::clean_people( $people );
        $series   = self::series_of( $event_id );
        $follows  = ( $teams === self::series_teams( $series ) && $people === self::series_people( $series ) );

        if ( $follows ) {
            delete_post_meta( $event_id, self::OWN_META );
            delete_post_meta( $event_id, SFAF_Teams::ACCESS_META );
            delete_post_meta( $event_id, self::PEOPLE_META );
            return;
        }
        update_post_meta( $event_id, self::OWN_META, '1' );
        SFAF_Teams::set_access_for_event( $event_id, $teams );
        if ( $people ) {
            update_post_meta( $event_id, self::PEOPLE_META, $people );
        } else {
            delete_post_meta( $event_id, self::PEOPLE_META );
        }
    }

    /** Store a series' default Team and access. */
    public static function set_series( $series_id, $teams, $people ) {
        $series_id = (int) $series_id;
        if ( ! $series_id ) {
            return;
        }
        $teams  = self::clean_teams( $teams );
        $people = self::clean_people( $people );
        if ( $teams ) {
            update_term_meta( $series_id, self::SERIES_TEAMS, $teams );
        } else {
            delete_term_meta( $series_id, self::SERIES_TEAMS );
        }
        if ( $people ) {
            update_term_meta( $series_id, self::SERIES_PEOPLE, $people );
        } else {
            delete_term_meta( $series_id, self::SERIES_PEOPLE );
        }
    }

    /**
     * Give the series default to the series' upcoming events that keep their
     * own Team and access (3.110.2), so a team added today reaches events made
     * before it. An event that follows the series already has it and is not
     * touched. Additive: an event keeps what it had, and gains the default's
     * people and as many of its teams as fit under MAX_PER_EVENT.
     *
     * @return array{updated:int,full:int} full: events with no room for a team.
     */
    public static function apply_series_to_upcoming( $series_id ) {
        $series_id = (int) $series_id;
        $teams     = self::series_teams( $series_id );
        $people    = self::series_people( $series_id );
        $out       = array( 'updated' => 0, 'full' => 0 );
        if ( ! $series_id || ( ! $teams && ! $people ) ) {
            return $out;
        }
        $ids = SFAF_Series::events( $series_id, array( 'status' => SFAF_Series::editable_statuses(), 'limit' => -1, 'upcoming' => true ) );
        foreach ( (array) $ids as $id ) {
            $id = (int) ( is_object( $id ) ? $id->ID : $id );
            if ( ! self::has_own( $id ) ) {
                continue;
            }
            $own_t = SFAF_Teams::access_for_event( $id );
            $own_p = self::people( $id );
            $want_t = array_values( array_unique( array_merge( $own_t, $teams ) ) );
            $new_t  = array_slice( $want_t, 0, SFAF_Teams::MAX_PER_EVENT );
            if ( count( $want_t ) > count( $new_t ) ) {
                $out['full']++;
            }
            $new_p = array_values( array_unique( array_merge( $own_p, $people ) ) );
            sort( $new_p );
            if ( $new_t === $own_t && $new_p === $own_p ) {
                continue;
            }
            self::set( $id, $new_t, $new_p );
            $out['updated']++;
        }
        return $out;
    }

    /**
     * The series whose default names this team, for refusing to delete a team
     * that is still giving people events.
     *
     * @return array<int,string> term id => series name
     */
    public static function series_using_team( $team_id ) {
        $out = array();
        if ( ! class_exists( 'SFAF_Series' ) ) {
            return $out;
        }
        foreach ( (array) SFAF_Series::all() as $term ) {
            if ( in_array( (string) $team_id, self::series_teams( $term->term_id ), true ) ) {
                $out[ (int) $term->term_id ] = (string) $term->name;
            }
        }
        return $out;
    }

    /**
     * The form's choice, read from a POST. present: the section was on the
     * form, so an empty choice means none rather than "this form did not ask".
     *
     * @return array{present:bool,teams:string[],people:int[]}
     */
    public static function from_post( $post, $prefix = 'access' ) {
        $present = ! empty( $post[ $prefix . '_present' ] );
        $teams   = isset( $post[ $prefix . '_teams' ] ) ? (array) wp_unslash( $post[ $prefix . '_teams' ] ) : array();
        $people  = isset( $post[ $prefix . '_people' ] ) ? (array) wp_unslash( $post[ $prefix . '_people' ] ) : array();
        return array( 'present' => $present, 'teams' => self::clean_teams( $teams ), 'people' => self::clean_people( $people ) );
    }
}
