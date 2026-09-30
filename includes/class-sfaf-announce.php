<?php
/**
 * TELLING REGISTRANTS THAT AN EVENT WAS CANCELLED OR MOVED.
 *
 * ONE PERSON GETS ONE EMAIL, WHATEVER THEY REGISTERED FOR.
 * ---------------------------------------------------------------------------
 * That is the whole reason this is a class rather than a loop at each call
 * site. Changing a recurrence pattern across upcoming occurrences can move
 * twelve dates at once, and somebody registered for six of them would get six
 * near-identical emails from a naive implementation. They would read the first,
 * skim the second, and delete the rest, which is how the one that said
 * something different gets deleted too.
 *
 * So a run is built as: gather every affected event, resolve every registrant
 * across all of them, GROUP BY ADDRESS, and send one message per address. The
 * address is the grouping key for the same reason it is the deduplication key
 * in SFAF_Reminders::notify_list(): it is what actually receives the message,
 * and one person may hold two registrations under two different names.
 *
 * WHO IS NOT TOLD
 * ---------------------------------------------------------------------------
 * Teams are not notified, and neither is the notification list. Those people
 * were presumably part of the decision to cancel or move it, and a team that
 * chose a new date does not need an email telling them the date changed.
 * Registrants only.
 *
 * WHAT IT DOES NOT DO
 * ---------------------------------------------------------------------------
 * It does not decide whether to send. The prompt asks that, once, and passes
 * the answer in. A sender that decided for itself would send from the editor,
 * from the bulk operation and from anything else that ever writes the same
 * state, which is the mistake SFAF_Cancellation::set() is careful not to make.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Announce {

    /**
     * Tell everybody registered for these events that they are cancelled.
     *
     * @param int[] $event_ids
     * @return array{people:int,sent:int,failed:int,events:int}
     */
    public static function cancelled( $event_ids ) {
        return self::run( 'cancelled', $event_ids, array() );
    }

    /**
     * Tell everybody registered for these events that something moved.
     *
     * @param int[] $event_ids
     * @param array $changes_by_event event_id => array<label,array{from,to}>
     * @return array{people:int,sent:int,failed:int,events:int}
     */
    public static function changed( $event_ids, $changes_by_event ) {
        return self::run( 'changed', $event_ids, $changes_by_event );
    }

    /**
     * Tell everybody registered that a cancelled event is happening again.
     *
     * THE THIRD THING THIS CLASS ANNOUNCES, and it groups by person across
     * events exactly as the other two do, so somebody registered for three
     * reinstated dates gets one message.
     *
     * `$was_by_event` IS SHAPED LIKE $changes_by_event on purpose, so run()
     * needs no third parameter and no branch: it is a map from event id to
     * whatever that event's builder wants, and here what it wants is the
     * date the event used to be on. An event whose date did not move simply
     * has no entry.
     *
     * @param int[] $event_ids
     * @param array $was_by_event event_id => previous Y-m-d, where it moved
     * @return array{people:int,sent:int,failed:int,events:int}
     */
    public static function reinstated( $event_ids, $was_by_event = array() ) {
        return self::run( 'reinstated', $event_ids, $was_by_event );
    }

    /**
     * The shared machinery.
     *
     * @param string $type 'cancelled'|'changed'|'reinstated'
     * @param int[]  $event_ids
     * @param array  $changes_by_event
     * @return array
     */
    private static function run( $type, $event_ids, $changes_by_event ) {
        $result = array( 'people' => 0, 'sent' => 0, 'failed' => 0, 'events' => 0 );

        $event_ids = array_values( array_unique( array_map( 'absint', (array) $event_ids ) ) );
        if ( empty( $event_ids ) ) {
            return $result;
        }

        /*
         * ADDRESS => THE EVENTS THEY HOLD A PLACE FOR.
         *
         * Built across every affected event before a single message is
         * composed, because the message depends on the whole set: somebody on
         * four of the twelve moved dates needs one email about four dates, and
         * that cannot be known while looping one event at a time.
         */
        $by_person = array();

        foreach ( $event_ids as $event_id ) {
            $rows = self::registrants( $event_id );
            if ( empty( $rows ) ) {
                continue;
            }
            $result['events']++;

            foreach ( $rows as $row ) {
                $email = strtolower( trim( (string) $row->email ) );
                if ( ! is_email( $email ) ) {
                    continue;
                }
                if ( ! isset( $by_person[ $email ] ) ) {
                    $by_person[ $email ] = array( 'person' => $row, 'events' => array() );
                }
                $by_person[ $email ]['events'][] = (int) $event_id;

                /*
                 * THE FIRST ROW SEEN IS THE PERSON. Every row reaching here is
                 * a confirmed registration, so any of them names them and
                 * carries a token that cancels one of their places. Until
                 * 3.53.0 this had to prefer a 'confirmed' row over a
                 * 'subscribed' one, because the two produced different copy;
                 * with one status there is nothing left to prefer.
                 */
            }
        }

        foreach ( $by_person as $email => $bundle ) {
            $result['people']++;

            $their_events = $bundle['events'];
            sort( $their_events );

            $built = ( 1 === count( $their_events ) )
                ? self::one_event( $type, $their_events[0], $bundle['person'], $changes_by_event )
                : self::several_events( $type, $their_events, $bundle['person'], $changes_by_event );

            if ( ! $built ) {
                continue;
            }

            /*
             * THE REPLY-TO IS THE FIRST AFFECTED EVENT'S, which is the right
             * answer when there is one and an arbitrary but harmless one when
             * there are several: a recurrence's occurrences share an organizer,
             * so in practice every candidate resolves to the same address.
             */
            $sent = SFAF_Email::send(
                $email,
                $built['subject'],
                $built['html'],
                $built['text'],
                SFAF_Reminders::reply_to_for( $their_events[0] )
            );

            if ( $sent ) {
                $result['sent']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /** One affected event: the ordinary message, built by SFAF_Notifications. */
    private static function one_event( $type, $event_id, $person, $changes_by_event ) {
        $context = array();
        if ( 'changed' === $type ) {
            $context['changes'] = isset( $changes_by_event[ $event_id ] ) ? $changes_by_event[ $event_id ] : array();
        }
        /*
         * REINSTATED CARRIES THE DATE THE EVENT USED TO BE ON (3.73.0), and
         * only where it moved. An event put back on its original date has no
         * entry, the builder finds none, and the message simply does not
         * mention a move, which is the truth about that event.
         */
        if ( 'reinstated' === $type ) {
            $context['was'] = isset( $changes_by_event[ $event_id ] ) ? (string) $changes_by_event[ $event_id ] : '';
        }
        return SFAF_Notifications::build( $type, $event_id, $person, $context );
    }

    /**
     * Several affected events, in one message.
     *
     * NOT A CONCATENATION OF THE SINGLE MESSAGES. Six copies of a banner, six
     * greetings and six footers in one email is worse than six emails. It is
     * one message with one greeting that lists the dates, because what the
     * reader needs is the list.
     */
    private static function several_events( $type, $event_ids, $person, $changes_by_event ) {
        // One group, one language: the first date's (3.106.0).
        $lang = sfaf_event_language( $event_ids[0] );
        $rows = array();
        foreach ( $event_ids as $id ) {
            $date  = (string) get_post_meta( $id, '_uc_event_date', true );
            $start = (string) get_post_meta( $id, '_uc_start_time', true );
            $end   = (string) get_post_meta( $id, '_uc_end_time', true );
            $when  = sfaf_ap_date( $date, 'full', $lang );
            $clock = sfaf_ap_time_range( $start, $end, 'zone', $lang );

            if ( 'changed' === $type && ! empty( $changes_by_event[ $id ] ) ) {
                $bits  = array();
                $kinds = array( 'Date' => 'date', 'Time' => 'time', 'Location' => 'location' );
                foreach ( $changes_by_event[ $id ] as $label => $pair ) {
                    $kind = isset( $kinds[ $label ] ) ? $kinds[ $label ] : '';
                    $from = sfaf_ap_restate( $kind, (string) $pair['from'], $lang );
                    $to   = sfaf_ap_restate( $kind, (string) $pair['to'], $lang );
                    if ( 'time' === $kind ) {
                        if ( 'not set' !== $pair['from'] ) { $from = sfaf_ap_zoned( $from, $lang ); }
                        if ( 'not set' !== $pair['to'] ) { $to = sfaf_ap_zoned( $to, $lang ); }
                    }
                    $name   = '' !== $kind ? SFAF_Messages::label( $kind, $lang ) : $label;
                    $bits[] = strtolower( $name ) . ' ' . $from . ' ' . SFAF_Messages::label( 'to', $lang ) . ' ' . $to;
                }
                $value = implode( '; ', $bits );
            } else {
                $value = $clock;
            }
            /*
             * KEYED BY THE DATE, NOT THE TITLE (3.106.0). Every date in a
             * series has the same title, so keying on it kept only the last
             * row of six. The title is in the heading and the subject.
             */
            $key = $when;
            while ( isset( $rows[ $key ] ) ) {
                $key .= ' ';
            }
            $rows[ $key ] = $value;
        }
        $cancel = ( $person && ! empty( $person->token ) && 'cancelled' !== $type ) ? SFAF_Reminders::cancel_url( $person->token ) : '';
        return self::several_compose( $type, $lang, $person, get_the_title( $event_ids[0] ), count( $event_ids ), $rows, $cancel );
    }

    /**
     * The several-dates message with the sample event, for the Templates
     * screen and EMAILS.md (3.106.0): two dates, through the same composer.
     */
    public static function several_sample( $type, $person, $s ) {
        $rows = array(
            sfaf_ap_date( '2026-11-12', 'full', $s['lang'] ) => $s['time'],
            sfaf_ap_date( '2026-11-19', 'full', $s['lang'] ) => $s['time'],
        );
        return self::several_compose( $type, $s['lang'], $person, $s['title'], 2, $rows, 'cancelled' === $type ? '' : $s['cancel_link'] );
    }

    /** One email for several dates, from the catalogue's "several" variant. */
    private static function several_compose( $type, $lang, $person, $title, $n, $rows, $cancel ) {
        $first = ( $person && ! empty( $person->first_name ) ) ? trim( (string) $person->first_name ) : '';
        if ( '' === $first && $person && ! empty( $person->name ) ) {
            $first = trim( (string) $person->name );
        }
        $values = array(
            'first_name' => $first, 'title' => $title, 'count' => (string) (int) $n, 'cancel_link' => $cancel,
            'date' => '', 'time' => '', 'location' => '', 'event_link' => '',
        );
        return SFAF_Messages::compose( $type, 'several', $lang, $values, array(
            'details'   => $rows,
            'preheader' => $title,
        ) );
    }

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /**
     * Everybody who holds a place at this event.
     *
     * ONE STATUS. This asked for `IN ('confirmed','subscribed')` between 3.39.0
     * and 3.53.0, and the reason was sound while 'subscribed' existed: somebody
     * who had pressed "Get Reminders" had asked in as many words to be told
     * about this event, and counting registrations only made the change prompt
     * never render on an event whose only interest was theirs.
     *
     * WHAT CHANGED IS THE STORAGE, NOT THE PRINCIPLE. There is no 'subscribed'
     * row any more. Following a series is its own record, against the series,
     * and the only thing it ever receives is a message about new dates. So the
     * audience for "this date moved" and "this date is off" is the people
     * holding a place at it, and it agrees with SFAF_Reminders::recipients()
     * again, which is the invariant worth keeping: "who is told about this
     * event" is one question with one answer.
     *
     * 'cancelled' stays out, as it always has: that is somebody who has already
     * asked to stop hearing about it.
     *
     * @param int $event_id
     * @return object[]
     */
    public static function registrants( $event_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_id = %d AND status = 'confirmed' ORDER BY id ASC",
            (int) $event_id
        ) );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * How many confirmed registrations these events carry between them, and how
     * many DISTINCT people that is.
     *
     * THE PROMPT SHOWS BOTH, because they are different numbers and the
     * difference is the thing somebody needs to understand before they press
     * send. Twelve registrations across six moved dates may be four people.
     *
     * @param int[] $event_ids
     * @return array{registrations:int,people:int,events:int}
     */
    public static function count_affected( $event_ids ) {
        $event_ids = array_values( array_unique( array_map( 'absint', (array) $event_ids ) ) );
        $out = array( 'registrations' => 0, 'people' => 0, 'events' => 0 );
        if ( empty( $event_ids ) ) {
            return $out;
        }

        $seen = array();
        foreach ( $event_ids as $id ) {
            $rows = self::registrants( $id );
            if ( empty( $rows ) ) {
                continue;
            }
            $out['events']++;
            foreach ( $rows as $row ) {
                $out['registrations']++;
                $email = strtolower( trim( (string) $row->email ) );
                if ( '' !== $email ) {
                    $seen[ $email ] = true;
                }
            }
        }
        $out['people'] = count( $seen );
        return $out;
    }

    /**
     * Does anybody need telling about this event? The prompt's trigger, and the
     * refusal to delete an event out from under people.
     *
     * SAME AUDIENCE AS registrants(), AND IT HAS TO BE. This is the delete
     * guard as well as the prompt's trigger, so a person this counts is a
     * person the announcement can reach and vice versa. If the two ever
     * disagreed, one direction refuses a deletion nobody would be told about
     * and the other allows one that strands people.
     */
    public static function has_registrations( $event_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = 'confirmed'",
            (int) $event_id
        ) ) > 0;
    }
}
