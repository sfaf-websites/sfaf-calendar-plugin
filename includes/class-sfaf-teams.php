<?php
/**
 * Teams: a named filter over users.
 *
 * WHAT A TEAM IS, AND WHAT IT DELIBERATELY IS NOT.
 *
 * A team is a NAME and a SET OF USER IDS. It is not a list of email addresses,
 * and an event that names a team does not store who was in it. The event
 * stores the team; the people are worked out when the mail is about to go.
 *
 * That single decision is the whole design, and everything below follows from
 * it:
 *
 *   REMOVING SOMEBODY IS ONE ACTION. Take a person out of the Philanthropy
 *   team and they stop receiving notifications for every event that names it,
 *   at once, with nothing to go and tidy up. Delete their WordPress account
 *   and the same thing happens, because a membership that no longer resolves
 *   to a user resolves to nobody.
 *
 *   ADDING SOMEBODY IS ALSO ONE ACTION, AND IT REACHES BACKWARDS. Somebody
 *   added to a team today starts receiving notifications for events that named
 *   that team last month. That is not a bug to be fixed with a snapshot: it is
 *   what "the Philanthropy team is told about this" means. A new colleague
 *   should hear about the events their team is running, and the alternative is
 *   a manager reopening a term's worth of events to add one person.
 *
 *   THERE IS NO SNAPSHOT ANYWHERE. Not of addresses, not of membership, not
 *   of a resolved recipient list. The only stored things are the team's name,
 *   its member ids, and the team ids an event names. Everything a person
 *   actually receives is computed from those at send time. See
 *   SFAF_Reminders::notify_list(), which is the one place that resolution
 *   happens for both the screen and the mail, so the count a manager reads and
 *   the people who get mail cannot disagree.
 *
 * DELETION IS BLOCKED WHILE A TEAM IS IN USE. The alternative offered was to
 * warn and delete anyway, which cannot be made safe: the events keep a team id
 * that resolves to nothing, and a notification that silently stops going out
 * looks exactly like one that is still going out. Blocking is the only version
 * where nothing can quietly stop working. events_using() names the events, so
 * the manager is told what to change rather than just refused, and because a
 * team in use cannot be deleted, no event can ever hold a dangling team id.
 *
 * STORAGE. One option, not a post type or a taxonomy. A team is a name and a
 * handful of integers with no permalink, no revisions and no editing screen of
 * its own, and both of the alternatives would have brought a menu entry and an
 * archive nobody wants. Same reasoning, and same shape, as SFAF_FAQ_Sets.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Teams {

    /** Where teams live. Autoload off: only the portal reads them. */
    const OPTION = 'sfaf_teams';

    /** Team ids an event notifies. An array of ids, on the event. */
    const EVENT_META = '_uc_notify_teams';

    /** A sanity ceiling, so a runaway loop cannot fill the option. */
    const MAX_TEAMS = 100;

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /**
     * Every team, name-ordered.
     *
     * Membership is returned as stored. It is NOT filtered against existing
     * users here, because this is also what the editing screen writes back,
     * and quietly dropping a member on every read would turn a temporary
     * lookup failure into permanent data loss. Resolution to real people
     * happens in emails(), where a missing user is simply skipped.
     *
     * @return array[] id => array{id:string,name:string,users:int[],created:int,updated:int}
     */
    public static function all() {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $teams = array();
        foreach ( $raw as $id => $team ) {
            if ( ! is_array( $team ) ) {
                continue;
            }
            $teams[ (string) $id ] = array(
                'id'      => (string) $id,
                'name'    => isset( $team['name'] ) ? (string) $team['name'] : '(unnamed team)',
                'users'   => self::clean_ids( isset( $team['users'] ) ? $team['users'] : array() ),
                'created' => isset( $team['created'] ) ? (int) $team['created'] : 0,
                'updated' => isset( $team['updated'] ) ? (int) $team['updated'] : 0,
            );
        }

        uasort( $teams, function ( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return $teams;
    }

    /**
     * One team by id, or null.
     *
     * @param string $id
     * @return array|null
     */
    public static function get( $id ) {
        $teams = self::all();
        $id    = (string) $id;
        return isset( $teams[ $id ] ) ? $teams[ $id ] : null;
    }

    /** Unique, positive integer ids, in the order given. */
    private static function clean_ids( $ids ) {
        $out = array();
        foreach ( (array) $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && ! in_array( $id, $out, true ) ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------- */

    /**
     * Create or rename a team, and set its membership.
     *
     * A FORM CAN ONLY SPEAK FOR THE PEOPLE IT SHOWED. The membership picker
     * lists the calendar's own users, so a team member who is not one of them
     * (an account that lost its calendar role, or one added before it had one)
     * has no checkbox and would be dropped by the next save of an unrelated
     * field. $offered is how a caller says which ids its form actually
     * offered: anything stored outside that set is kept. Pass null when the
     * caller genuinely knows the whole membership, which add_member() and
     * remove_member() do.
     *
     * @param string     $id      Existing id, or '' to create.
     * @param string     $name
     * @param int[]      $users
     * @param int[]|null $offered Ids the form showed, or null for all of them.
     * @return string|WP_Error The team id.
     */
    public static function save( $id, $name, $users = array(), $offered = null ) {
        $name = trim( sanitize_text_field( $name ) );
        if ( '' === $name ) {
            return new WP_Error( 'sfaf_team_no_name', 'Give the team a name so it can be found later.' );
        }

        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }

        $id  = trim( (string) $id );
        $now = time();

        if ( '' === $id || ! isset( $raw[ $id ] ) ) {
            if ( count( $raw ) >= self::MAX_TEAMS ) {
                return new WP_Error( 'sfaf_team_too_many', 'There are already as many teams as this supports.' );
            }
            // A readable id, so the stored option is legible and so a team
            // reference on an event says something when read by eye.
            $base = sanitize_key( $name );
            if ( '' === $base ) {
                $base = 'team';
            }
            $id = $base;
            $n  = 2;
            while ( isset( $raw[ $id ] ) ) {
                $id = $base . '-' . $n;
                $n++;
            }
            $raw[ $id ] = array( 'created' => $now );
        }

        $members = self::clean_ids( $users );

        if ( is_array( $offered ) ) {
            // Keep every stored member the form could not have unticked,
            // in front of what it did submit, so the order is stable.
            $offered = self::clean_ids( $offered );
            $unseen  = array();
            foreach ( self::clean_ids( isset( $raw[ $id ]['users'] ) ? $raw[ $id ]['users'] : array() ) as $uid ) {
                if ( ! in_array( $uid, $offered, true ) ) {
                    $unseen[] = $uid;
                }
            }
            $members = self::clean_ids( array_merge( $unseen, $members ) );
        }

        $raw[ $id ]['name']    = $name;
        $raw[ $id ]['users']   = $members;
        $raw[ $id ]['updated'] = $now;
        if ( empty( $raw[ $id ]['created'] ) ) {
            $raw[ $id ]['created'] = $now;
        }

        update_option( self::OPTION, $raw, false );
        return $id;
    }

    /**
     * Add a user to a team.
     *
     * @param string $id
     * @param int    $user_id
     * @return bool Whether anything changed.
     */
    public static function add_member( $id, $user_id ) {
        $team = self::get( $id );
        if ( ! $team || (int) $user_id <= 0 ) {
            return false;
        }
        if ( in_array( (int) $user_id, $team['users'], true ) ) {
            return false;
        }
        $team['users'][] = (int) $user_id;
        self::save( $team['id'], $team['name'], $team['users'] );
        return true;
    }

    /**
     * Take a user out of a team.
     *
     * THIS IS THE WHOLE OF "REMOVE THEM FROM EVERY EVENT". Nothing else has to
     * happen and no event is touched, because no event ever stored them.
     *
     * @param string $id
     * @param int    $user_id
     * @return bool Whether anything changed.
     */
    public static function remove_member( $id, $user_id ) {
        $team = self::get( $id );
        if ( ! $team ) {
            return false;
        }
        $before = $team['users'];
        $after  = array_values( array_diff( $before, array( (int) $user_id ) ) );
        if ( $after === $before ) {
            return false;
        }
        self::save( $team['id'], $team['name'], $after );
        return true;
    }

    /**
     * Delete a team, refusing while any event names it.
     *
     * @param string $id
     * @return true|WP_Error
     */
    public static function delete( $id ) {
        $id   = (string) $id;
        $team = self::get( $id );
        if ( ! $team ) {
            return new WP_Error( 'sfaf_team_missing', 'That team no longer exists.' );
        }

        $in_use = self::events_using( $id );
        if ( ! empty( $in_use ) ) {
            return new WP_Error(
                'sfaf_team_in_use',
                sprintf(
                    'This team is still named by %d %s, so deleting it would stop those notifications without saying so. Take it off them first, then delete it.',
                    count( $in_use ),
                    ( 1 === count( $in_use ) ) ? 'event' : 'events'
                ),
                array( 'events' => $in_use )
            );
        }

        $raw = get_option( self::OPTION, array() );
        if ( is_array( $raw ) ) {
            unset( $raw[ $id ] );
            update_option( self::OPTION, $raw, false );
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Use
     * ------------------------------------------------------------------- */

    /**
     * The team ids an event names.
     *
     * @param int $event_id
     * @return string[]
     */
    public static function for_event( $event_id ) {
        $ids = get_post_meta( (int) $event_id, self::EVENT_META, true );
        $out = array();
        foreach ( (array) $ids as $id ) {
            $id = (string) $id;
            if ( '' !== $id && ! in_array( $id, $out, true ) ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Set the teams an event names. Unknown ids are dropped.
     *
     * @param int      $event_id
     * @param string[] $ids
     */
    public static function set_for_event( $event_id, $ids ) {
        $known = self::all();
        $keep  = array();
        foreach ( (array) $ids as $id ) {
            $id = sanitize_key( (string) $id );
            if ( isset( $known[ $id ] ) && ! in_array( $id, $keep, true ) ) {
                $keep[] = $id;
            }
        }

        if ( empty( $keep ) ) {
            delete_post_meta( (int) $event_id, self::EVENT_META );
            return;
        }
        update_post_meta( (int) $event_id, self::EVENT_META, $keep );
    }

    /**
     * Events that name this team.
     *
     * EXACT, NOT A LIKE. The obvious implementation is a meta_query with LIKE
     * against the serialized array, which matches on a substring and would
     * therefore answer this question wrongly for any two team ids where one
     * contains the other. This asks for the small set of events that name any
     * team at all and then checks each one properly. Only events where a
     * manager actually picked a team carry the meta, so that set is small.
     *
     * @param string $id
     * @return array[] Each array{id:int,title:string,status:string}
     */
    public static function events_using( $id ) {
        $id = (string) $id;

        $q = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => array( 'publish', 'pending', 'draft', 'future', 'private' ),
            'posts_per_page'         => 200,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array( 'key' => self::EVENT_META, 'compare' => 'EXISTS' ),
            ),
        ) );

        $out = array();
        foreach ( $q->posts as $post_id ) {
            if ( in_array( $id, self::for_event( $post_id ), true ) ) {
                $out[] = array(
                    'id'     => (int) $post_id,
                    'title'  => get_the_title( $post_id ) ? get_the_title( $post_id ) : '(untitled)',
                    'status' => get_post_status( $post_id ),
                );
            }
        }
        return $out;
    }

    /**
     * The team's members, as email => display name, RIGHT NOW.
     *
     * Called at send time and at render time from the same place, so what a
     * manager is shown and what is actually mailed are the same computation.
     * A member whose account has gone simply is not here, and a member with no
     * usable address is skipped rather than producing a broken recipient.
     *
     * @param string $id
     * @return array<string,string> lowercased email => display name
     */
    public static function emails( $id ) {
        $team = self::get( $id );
        if ( ! $team ) {
            return array();
        }

        $out = array();
        foreach ( $team['users'] as $uid ) {
            $user = get_userdata( $uid );
            if ( ! $user || ! is_email( $user->user_email ) ) {
                continue;
            }
            $out[ strtolower( trim( $user->user_email ) ) ] = $user->display_name;
        }
        return $out;
    }

    /**
     * How many people a team currently REACHES. Not how many are in it.
     *
     * These are two different numbers and conflating them is what made the
     * Users screen say "0 people right now" beside a team that had members:
     * this counts emails(), which skips a member whose account has gone and a
     * member with no usable address. That is the right number to print beside
     * a notification picker, where the question is who will actually be
     * mailed, and the wrong number to print beside a membership list. Use
     * member_count() for that.
     *
     * @param string $id
     * @return int
     */
    public static function size( $id ) {
        return count( self::emails( $id ) );
    }

    /**
     * The team's members as real accounts, in the stored order.
     *
     * A stored id whose account has been deleted is skipped HERE and left
     * alone in the option, for the same reason all() does not filter: a
     * lookup failure must never become a silent deletion. Nothing writes the
     * membership back except a save that was actually asked for.
     *
     * @param string $id
     * @return WP_User[]
     */
    public static function members( $id ) {
        $team = self::get( $id );
        if ( ! $team ) {
            return array();
        }

        $out = array();
        foreach ( $team['users'] as $uid ) {
            $user = get_userdata( $uid );
            if ( $user ) {
                $out[] = $user;
            }
        }
        return $out;
    }

    /**
     * How many people are IN a team. The number to print beside a member list.
     *
     * @param string $id
     * @return int
     */
    public static function member_count( $id ) {
        return count( self::members( $id ) );
    }

    /**
     * Every team a user belongs to, for the Users screen.
     *
     * @param int $user_id
     * @return array[] The team records.
     */
    public static function for_user( $user_id ) {
        $out = array();
        foreach ( self::all() as $team ) {
            if ( in_array( (int) $user_id, $team['users'], true ) ) {
                $out[] = $team;
            }
        }
        return $out;
    }

    /**
     * Set exactly which teams a user belongs to.
     *
     * The other half of for_user(): the Calendar Users screen edits membership
     * a person at a time rather than a team at a time, so this walks every team
     * once and adds or drops this one user.
     *
     * IT WRITES ONE OPTION AND NOTHING ELSE. No role, no capability, no user
     * meta. Being in a team says who gets notified and says nothing whatever
     * about what somebody may do, and there is deliberately no code path from
     * here to anything that decides access.
     *
     * @param int      $user_id
     * @param string[] $team_ids The complete list this user should be in.
     * @return int How many teams changed.
     */
    public static function set_for_user( $user_id, $team_ids ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return 0;
        }

        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return 0;
        }

        $wanted = array();
        foreach ( (array) $team_ids as $tid ) {
            $tid = sanitize_key( (string) $tid );
            if ( '' !== $tid && isset( $raw[ $tid ] ) ) {
                $wanted[] = $tid;
            }
        }

        $changed = 0;
        foreach ( $raw as $id => $team ) {
            if ( ! is_array( $team ) ) {
                continue;
            }
            $before = self::clean_ids( isset( $team['users'] ) ? $team['users'] : array() );
            $after  = in_array( (string) $id, $wanted, true )
                ? ( in_array( $user_id, $before, true ) ? $before : array_merge( $before, array( $user_id ) ) )
                : array_values( array_diff( $before, array( $user_id ) ) );

            if ( $after !== $before ) {
                $raw[ $id ]['users']   = $after;
                $raw[ $id ]['updated'] = time();
                $changed++;
            }
        }

        if ( $changed ) {
            update_option( self::OPTION, $raw, false );
        }
        return $changed;
    }

    /**
     * Drop a user from every team.
     *
     * Called when somebody is removed from the calendar system, so a team does
     * not keep pointing at an account that no longer has any business being
     * notified. Resolution would have skipped them anyway once the account was
     * gone; this is for the case where the account remains but their calendar
     * access does not.
     *
     * @param int $user_id
     * @return int How many teams changed.
     */
    public static function forget_user( $user_id ) {
        $user_id = (int) $user_id;
        $raw     = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return 0;
        }

        $changed = 0;
        foreach ( $raw as $id => $team ) {
            if ( ! is_array( $team ) || empty( $team['users'] ) ) {
                continue;
            }
            $after = array_values( array_diff( self::clean_ids( $team['users'] ), array( $user_id ) ) );
            if ( $after !== self::clean_ids( $team['users'] ) ) {
                $raw[ $id ]['users']   = $after;
                $raw[ $id ]['updated'] = time();
                $changed++;
            }
        }

        if ( $changed ) {
            update_option( self::OPTION, $raw, false );
        }
        return $changed;
    }
}
