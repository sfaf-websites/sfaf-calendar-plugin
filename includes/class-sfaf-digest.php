<?php
/**
 * Digest emails, and the preferences behind them (3.102.0).
 *
 * A PERSON'S OWN LIST OF EVENTS, DAILY OR WEEKLY. Somebody signed in to
 * caladmin chooses none (the default), daily or weekly on their Preferences
 * screen, and optionally narrows it by venue, series and organizer.
 *
 *   daily   at the morning-of reminder hour, that day's events
 *   weekly  Mondays at the same hour, the seven days from that Monday
 *
 * STORED PER USER, NEVER PER EVENT, in the user meta META. Nobody sets anybody
 * else's: the save takes no user id and writes the signed-in person's own. An
 * admin sees who is subscribed to what on the Users screen, read only.
 *
 * WHICH EVENTS, AND IT IS THE EVENT GATE THAT DECIDES. Every published event
 * in the window that SFAF_Portal::user_can_edit_event() allows for this
 * person: an admin or editor sees every one, a contributor their own and their
 * teams'. Then the filters: a group with nothing ticked does not narrow, and
 * the groups narrow together. Cancelled events are left out, as they are from
 * every other scheduled message.
 *
 * WHAT A ROW SAYS: the title, the date, the time with its zone, the place, how
 * many have registered, and a link to that event's registrations in caladmin.
 * NEVER A NAME. The link is asked of the gate again, per event, in build():
 * the list is decided by one call and the link by another, so neither can
 * widen the other.
 *
 * SEND-ONCE. The reminder ledger, keyed "digest|period|user" on event 0, which
 * no post has. Claimed before the send, so a second run, or two at once,
 * fails the claim. A per-user marker (DONE_META) says the period has been
 * looked at, sent or empty, so a quiet day is not rebuilt every fifteen
 * minutes. AN EMPTY DIGEST IS NOT SENT and takes no ledger row.
 *
 * @package SFAF_Calendar
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Digest {

    /** User meta: array{frequency, venues, series, organizers}. */
    const META = '_uc_digest';

    /** User meta: the last period looked at, "daily:Y-m-d" or "weekly:Y-m-d". */
    const DONE_META = '_uc_digest_done';

    /** The three answers, and what the screen calls them. */
    public static function frequencies() {
        return array(
            'none'   => 'None',
            'daily'  => 'Daily, at 6 am, with that day\'s events',
            'weekly' => 'Weekly, Mondays at 6 am, with the seven days ahead',
        );
    }

    /**
     * The filter groups: preference key => taxonomy, and what to call it.
     *
     * ONE LIST, read by the screen, the save, the filter and the Users line, so
     * a group added here is drawn, saved, applied and described together.
     */
    public static function groups() {
        return array(
            'venues'     => array( 'taxonomy' => 'uc_venue',     'label' => 'Venues' ),
            'series'     => array( 'taxonomy' => 'uc_series',    'label' => 'Series' ),
            'organizers' => array( 'taxonomy' => 'uc_organizer', 'label' => 'Organizers' ),
        );
    }

    /* =====================================================================
     * Preferences
     * ================================================================== */

    /**
     * One person's preferences, normalised. Absent means none.
     *
     * @param int $user_id
     * @return array{frequency:string,venues:int[],series:int[],organizers:int[]}
     */
    public static function prefs( $user_id ) {
        $raw = get_user_meta( (int) $user_id, self::META, true );
        $raw = is_array( $raw ) ? $raw : array();
        $out = array(
            'frequency' => ( isset( $raw['frequency'] ) && isset( self::frequencies()[ $raw['frequency'] ] ) ) ? (string) $raw['frequency'] : 'none',
        );
        foreach ( array_keys( self::groups() ) as $g ) {
            $out[ $g ] = isset( $raw[ $g ] ) ? array_values( array_unique( array_filter( array_map( 'intval', (array) $raw[ $g ] ) ) ) ) : array();
        }
        return $out;
    }

    /**
     * Store the SIGNED-IN person's preferences from what the screen posted.
     *
     * THE USER ID IS THE CALLER'S OWN, never read from the request, which is
     * what makes "nobody sets anybody else's" a property of the code rather
     * than of the form. Each ticked id is kept only if it is a term of that
     * group's taxonomy now, so the store holds nothing the screen could not
     * have offered.
     *
     * @param int   $user_id The signed-in person.
     * @param array $input   frequency, and a list of ids per group.
     * @return array The stored preferences.
     */
    public static function save( $user_id, $input ) {
        $input = is_array( $input ) ? $input : array();
        $freq  = isset( $input['frequency'] ) ? sanitize_key( (string) $input['frequency'] ) : 'none';
        $prefs = array( 'frequency' => isset( self::frequencies()[ $freq ] ) ? $freq : 'none' );

        foreach ( self::groups() as $g => $spec ) {
            $kept = array();
            foreach ( isset( $input[ $g ] ) ? (array) $input[ $g ] : array() as $id ) {
                $id   = (int) $id;
                $term = $id ? get_term( $id, $spec['taxonomy'] ) : null;
                if ( $term && ! is_wp_error( $term ) ) {
                    $kept[] = $id;
                }
            }
            $prefs[ $g ] = array_values( array_unique( $kept ) );
        }

        update_user_meta( (int) $user_id, self::META, $prefs );
        return $prefs;
    }

    /**
     * One line for the Users screen: what this person receives. Read only.
     *
     * @param int $user_id
     * @return string '' when they receive no digest.
     */
    public static function describe( $user_id ) {
        $p = self::prefs( $user_id );
        if ( 'none' === $p['frequency'] ) {
            return '';
        }
        $parts = array();
        foreach ( self::groups() as $g => $spec ) {
            $names = array();
            foreach ( $p[ $g ] as $id ) {
                $t = get_term( (int) $id, $spec['taxonomy'] );
                if ( $t && ! is_wp_error( $t ) ) {
                    $names[] = $t->name;
                }
            }
            if ( $names ) {
                $parts[] = $spec['label'] . ': ' . implode( ', ', $names );
            }
        }
        $line = ( 'daily' === $p['frequency'] ? 'Daily digest' : 'Weekly digest' );
        return $parts ? $line . '. ' . implode( '. ', $parts ) . '.' : $line . ', every event they can open.';
    }

    /* =====================================================================
     * When, and which events
     * ================================================================== */

    /**
     * The period due now for a frequency, or null when none is.
     *
     * @param string   $frequency
     * @param DateTime $now In the site timezone.
     * @return array{key:string,start:string,end:string}|null
     */
    public static function period( $frequency, $now ) {
        $hour = SFAF_Reminders::DUE_HOUR;
        if ( (int) $now->format( 'G' ) < $hour ) {
            return null;
        }
        $today = $now->format( 'Y-m-d' );
        if ( 'daily' === $frequency ) {
            return array( 'key' => 'daily:' . $today, 'start' => $today, 'end' => $today );
        }
        if ( 'weekly' === $frequency && '1' === $now->format( 'N' ) ) {
            $end = ( clone $now )->modify( '+6 days' )->format( 'Y-m-d' );
            return array( 'key' => 'weekly:' . $today, 'start' => $today, 'end' => $end );
        }
        return null;
    }

    /**
     * Whether an event passes this person's filters.
     *
     * NOTHING TICKED IN A GROUP DOES NOT NARROW. Within a group any match
     * counts; across groups every group that has something ticked must match.
     */
    public static function matches( $event_id, $prefs ) {
        $event_id = (int) $event_id;
        if ( ! empty( $prefs['venues'] ) && ! in_array( (int) SFAF_Venues::id_for_event( $event_id ), $prefs['venues'], true ) ) {
            return false;
        }
        if ( ! empty( $prefs['series'] ) && ! in_array( (int) SFAF_Series::id_for_event( $event_id ), $prefs['series'], true ) ) {
            return false;
        }
        if ( ! empty( $prefs['organizers'] ) ) {
            $orgs = wp_get_object_terms( $event_id, 'uc_organizer', array( 'fields' => 'ids' ) );
            $orgs = is_wp_error( $orgs ) ? array() : array_map( 'intval', (array) $orgs );
            if ( ! array_intersect( $orgs, $prefs['organizers'] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * The events one digest lists: published, in the window, not cancelled,
     * allowed by the event gate, and passing the filters. Soonest first.
     *
     * @return int[]
     */
    public static function events_for( $user_id, $start, $end, $prefs ) {
        $q = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array( 'key' => '_uc_event_date', 'value' => array( $start, $end ), 'compare' => 'BETWEEN', 'type' => 'DATE' ),
            ),
        ) );

        $out = array();
        foreach ( $q->posts as $id ) {
            $id = (int) $id;
            if ( SFAF_Cancellation::skip_scheduled( $id ) ) {
                continue;
            }
            // THE GATE, the one every route asks. See PROJECT.md 5.
            if ( ! SFAF_Portal::user_can_edit_event( (int) $user_id, $id ) ) {
                continue;
            }
            if ( ! self::matches( $id, $prefs ) ) {
                continue;
            }
            $out[] = $id;
        }

        usort( $out, function ( $a, $b ) {
            $ka = get_post_meta( $a, '_uc_event_date', true ) . ' ' . get_post_meta( $a, '_uc_start_time', true );
            $kb = get_post_meta( $b, '_uc_event_date', true ) . ' ' . get_post_meta( $b, '_uc_start_time', true );
            return strcmp( $ka, $kb );
        } );
        return $out;
    }

    /* =====================================================================
     * The message
     * ================================================================== */

    /**
     * The registrations line for one event, and whether its list can be linked.
     *
     * @return array{line:string,listed:bool}
     */
    private static function registrations( $event_id ) {
        if ( SFAF_Sources::takes_rsvps_at_source( $event_id ) ) {
            $prov = SFAF_Sources::provenance( $event_id );
            return array( 'line' => 'Registration is on ' . ( '' !== $prov['label'] ? $prov['label'] : 'the source' ), 'listed' => false );
        }
        if ( '1' !== (string) get_post_meta( $event_id, '_uc_rsvp_enabled', true ) ) {
            return array( 'line' => 'Not taking registrations', 'listed' => false );
        }
        $n   = (int) sfaf_get_rsvp_count( $event_id );
        $cap = (int) sfaf_event_capacity( $event_id );
        return array(
            'line'   => $cap > 0 ? sprintf( '%d of %d places taken', $n, $cap ) : sprintf( '%d registered', $n ),
            'listed' => true,
        );
    }

    /**
     * Build one person's digest, or null when there is nothing in it.
     *
     * THE RSVP LINK IS ASKED OF THE GATE HERE, PER EVENT, whatever list it is
     * handed. events_for() already asked, and this asks again, so a list built
     * some other way still cannot carry a link to a screen that refuses them.
     *
     * @param int    $user_id
     * @param int[]  $event_ids
     * @param string $frequency daily|weekly
     * @return array{subject:string,html:string,text:string}|null
     */
    public static function build( $user_id, $event_ids, $frequency ) {
        $event_ids = array_values( array_filter( array_map( 'intval', (array) $event_ids ) ) );
        if ( empty( $event_ids ) ) {
            return null;
        }
        $n       = count( $event_ids );
        $weekly  = ( 'weekly' === $frequency );
        $heading = $weekly ? 'Your events this week' : 'Your events today';

        $html = SFAF_Email::heading( $heading );
        $html .= SFAF_Email::para( sprintf( '%d %s.', $n, 1 === $n ? 'event' : 'events' ) );
        $text = $heading . "\n\n" . sprintf( '%d %s.', $n, 1 === $n ? 'event' : 'events' ) . "\n\n";

        foreach ( $event_ids as $id ) {
            $reg   = self::registrations( $id );
            $rows  = array(
                'Date'          => sfaf_ap_date( (string) get_post_meta( $id, '_uc_event_date', true ), 'full' ),
                'Time'          => sfaf_ap_time_range( (string) get_post_meta( $id, '_uc_start_time', true ), (string) get_post_meta( $id, '_uc_end_time', true ), 'zone' ),
                'Place'         => sfaf_event_location( $id ),
                'Registrations' => $reg['line'],
            );
            $title = get_the_title( $id );
            $link  = ( $reg['listed'] && SFAF_Portal::user_can_edit_event( (int) $user_id, $id ) )
                ? add_query_arg( 'event_id', $id, SFAF_Portal::link( 'rsvps' ) )
                : '';

            $html .= SFAF_Email::rule();
            $html .= SFAF_Email::label( $title );
            $html .= SFAF_Email::details( $rows );
            if ( '' !== $link ) {
                $html .= SFAF_Email::link_para( $link, 'See who has registered' );
            }

            $text .= $title . "\n";
            foreach ( $rows as $label => $value ) {
                if ( '' !== trim( (string) $value ) ) {
                    $text .= $label . ': ' . $value . "\n";
                }
            }
            if ( '' !== $link ) {
                $text .= 'See who has registered: ' . $link . "\n";
            }
            $text .= "\n";
        }

        $events = SFAF_Portal::link( 'events' );
        $prefs  = SFAF_Portal::link( 'preferences' );
        $html .= SFAF_Email::rule();
        $html .= SFAF_Email::button( $events, 'Open your events', 'primary' );
        $html .= SFAF_Email::small_para( 'Change what this digest includes on your <a href="' . esc_url( $prefs ) . '" style="color:' . SFAF_Email::C_TEAL . ';">Preferences</a> screen.' );

        $text .= 'Open your events: ' . $events . "\n";
        $text .= 'Change what this digest includes on your Preferences screen: ' . $prefs . "\n";
        $text .= "\n" . SFAF_Email::POSTAL;

        return array(
            'subject' => $weekly ? sprintf( 'This week: %d %s', $n, 1 === $n ? 'event' : 'events' ) : sprintf( 'Today: %d %s', $n, 1 === $n ? 'event' : 'events' ),
            'html'    => SFAF_Email::shell( sprintf( '%s: %d %s.', $heading, $n, 1 === $n ? 'event' : 'events' ), $html ),
            'text'    => $text,
        );
    }

    /* =====================================================================
     * Sending
     * ================================================================== */

    /**
     * Everybody who asked for a digest and still has calendar access.
     *
     * CALENDAR ACCESS IS REQUIRED, because the event gate answers true for an
     * organizer's own events whether or not they can still sign in, and a
     * digest of links to a portal that refuses them is the defect PROJECT.md 5
     * names.
     *
     * @return int[]
     */
    public static function subscribers() {
        $out = array();
        foreach ( get_users( array( 'meta_key' => self::META, 'fields' => array( 'ID' ) ) ) as $u ) {
            $id = (int) ( is_object( $u ) ? $u->ID : $u );
            if ( 'none' === self::prefs( $id )['frequency'] ) {
                continue;
            }
            if ( '' === SFAF_Portal::get_role( $id ) ) {
                continue;
            }
            $out[] = $id;
        }
        return $out;
    }

    /**
     * Send one person their digest for one period.
     *
     * @param int   $user_id
     * @param array $period From period().
     * @param string $frequency
     * @return string sent|empty|already|failed|no_address
     */
    public static function send_for_user( $user_id, $period, $frequency ) {
        $user = get_userdata( (int) $user_id );
        if ( ! $user || ! is_email( $user->user_email ) ) {
            return 'no_address';
        }

        $prefs = self::prefs( $user_id );
        $built = self::build( $user_id, self::events_for( $user_id, $period['start'], $period['end'], $prefs ), $frequency );
        if ( ! $built ) {
            return 'empty';
        }

        $claim = SFAF_Reminders::claim_key( 0, 'digest|' . $period['key'] . '|' . (int) $user_id, $user->user_email, 'digest' );
        if ( ! $claim ) {
            return 'already';
        }
        $sent = SFAF_Email::send( $user->user_email, $built['subject'], $built['html'], $built['text'] );
        SFAF_Reminders::finish_row( $claim['id'], $sent ? 'sent' : 'failed' );
        return $sent ? 'sent' : 'failed';
    }

    /**
     * The runner's task.
     *
     * @return array{status:string,summary:string,counts:array}
     */
    public static function run() {
        $now    = new DateTime( 'now', wp_timezone() );
        $counts = array( 'sent' => 0, 'empty' => 0, 'already' => 0, 'failed' => 0, 'no_address' => 0 );

        foreach ( self::subscribers() as $uid ) {
            $freq   = self::prefs( $uid )['frequency'];
            $period = self::period( $freq, $now );
            if ( ! $period ) {
                continue;
            }
            if ( (string) get_user_meta( $uid, self::DONE_META, true ) === $period['key'] ) {
                continue;
            }
            $what = self::send_for_user( $uid, $period, $freq );
            $counts[ $what ]++;
            update_user_meta( $uid, self::DONE_META, $period['key'] );
        }

        $looked = array_sum( $counts );
        if ( ! $looked ) {
            return array( 'status' => 'ok', 'summary' => 'Nothing due: no digest is waiting to go out.', 'counts' => $counts );
        }
        return array(
            'status'  => 'ok',
            'summary' => sprintf(
                '%d sent, %d with no events and not sent, %d already recorded, %d failed.',
                $counts['sent'], $counts['empty'], $counts['already'], $counts['failed']
            ),
            'counts'  => $counts,
        );
    }
}
