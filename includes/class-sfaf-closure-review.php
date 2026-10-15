<?php
/**
 * Events on a day SFAF is closed, and the organizer's decision (3.112.0).
 *
 * WHEN A CLOSURE IS SAVED, every published or scheduled event with a day on
 * it is found, and its organizer (post_author) gets one email naming the
 * event, the date and the closure, with Keep and Cancel. Seven days before a
 * date nobody has decided, a reminder goes to the same person. Pending has a
 * tab, On closed days, listing every undecided one for whoever can edit it.
 *
 * A LINK IN AN EMAIL NEVER ACTS. Keep and Cancel open a caladmin page that
 * asks; Keep is a POST from that page, and Cancel there is the editor's own
 * cancel form and dialog, with the registrants question. A crawler, a mail
 * scanner or a preview pane following the link changes nothing.
 *
 * Three lists on the event, each of closure ids:
 *   _uc_closed_kept      the organizer kept it on that closure
 *   _uc_closed_notified  the first email went
 *   _uc_closed_reminded  the seven-day reminder went
 * A cancelled event is never undecided, so nothing more is sent for it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Closure_Review {

    const META_KEPT     = '_uc_closed_kept';
    const META_NOTIFIED = '_uc_closed_notified';
    const META_REMINDED = '_uc_closed_reminded';

    /** How many days before an undecided date the reminder goes. */
    const REMIND_DAYS = 7;

    /* ---------------------------------------------------------------------
     * Which events are on a closure
     * ------------------------------------------------------------------- */

    /**
     * Every published or scheduled event with a day on a closure that has not
     * ended, as one row per event and closure: the first of its days on it.
     *
     * @param string $closure_id Only this closure, or '' for every one.
     * @return array<int,array{event_id:int,closure:array,date:string,kept:bool}>
     */
    public static function items( $closure_id = '' ) {
        $today    = current_time( 'Y-m-d' );
        $closures = array();
        foreach ( SFAF_Closures::all() as $row ) {
            if ( $row['end'] < $today ) {
                continue;
            }
            if ( '' !== $closure_id && $row['id'] !== (string) $closure_id ) {
                continue;
            }
            $closures[] = $row;
        }
        if ( empty( $closures ) ) {
            return array();
        }

        $q = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => array( 'publish', 'future' ),
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'OR',
                array( 'key' => '_uc_event_date', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ),
                array( 'key' => '_uc_last_date', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ),
            ),
        ) );

        $out = array();
        foreach ( (array) $q->posts as $id ) {
            $id = (int) $id;
            if ( SFAF_Cancellation::is_cancelled( $id ) ) {
                continue;
            }
            $days = sfaf_event_days( $id );
            foreach ( $closures as $c ) {
                foreach ( $days as $day ) {
                    if ( $day >= $today && $day >= $c['start'] && $day <= $c['end'] ) {
                        $out[] = array(
                            'event_id' => $id,
                            'closure'  => $c,
                            'date'     => $day,
                            'kept'     => self::is_kept( $id, $c['id'] ),
                        );
                        break;
                    }
                }
            }
        }
        usort( $out, function ( $a, $b ) { return strcmp( $a['date'], $b['date'] ); } );
        return $out;
    }

    /** The rows nobody has decided. */
    public static function undecided( $closure_id = '' ) {
        return array_values( array_filter( self::items( $closure_id ), function ( $i ) { return ! $i['kept']; } ) );
    }

    /** One row, or null when the event is not on that closure (any more). */
    public static function item( $event_id, $closure_id ) {
        foreach ( self::items( $closure_id ) as $i ) {
            if ( (int) $i['event_id'] === (int) $event_id ) {
                return $i;
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------------
     * The records on the event
     * ------------------------------------------------------------------- */

    private static function listed( $event_id, $key ) {
        $v = get_post_meta( (int) $event_id, $key, true );
        return is_array( $v ) ? array_map( 'strval', $v ) : array();
    }

    private static function add( $event_id, $key, $closure_id ) {
        $list = self::listed( $event_id, $key );
        if ( ! in_array( (string) $closure_id, $list, true ) ) {
            $list[] = (string) $closure_id;
            update_post_meta( (int) $event_id, $key, $list );
        }
    }

    public static function is_kept( $event_id, $closure_id ) {
        return in_array( (string) $closure_id, self::listed( $event_id, self::META_KEPT ), true );
    }

    /** Keep: the date is reviewed and held, and nothing more is sent about it. */
    public static function keep( $event_id, $closure_id ) {
        self::add( $event_id, self::META_KEPT, $closure_id );
    }

    /* ---------------------------------------------------------------------
     * The two emails
     * ------------------------------------------------------------------- */

    /** The caladmin page the Keep and Cancel buttons open. */
    public static function link( $event_id, $closure_id, $do ) {
        return add_query_arg(
            array( 'closure' => rawurlencode( (string) $closure_id ), 'do' => ( 'cancel' === $do ) ? 'cancel' : 'keep' ),
            SFAF_Portal::link( 'closed-day/' . (int) $event_id )
        );
    }

    /**
     * The values a closed-day message fills its tokens from.
     *
     * @param array $item From items().
     * @return array
     */
    public static function values( $item ) {
        $id     = (int) $item['event_id'];
        $author = get_userdata( (int) get_post_field( 'post_author', $id ) );
        $first  = ( $author && ! empty( $author->first_name ) ) ? $author->first_name : ( $author ? $author->display_name : '' );
        $name   = SFAF_Closures::name( $item['closure'] );
        return array(
            'first_name'   => (string) $first,
            'title'        => get_the_title( $id ),
            'date'         => sfaf_ap_date( $item['date'], 'full' ),
            'closure'      => '' !== $name ? $name : 'a closure',
            'keep_link'    => self::link( $id, $item['closure']['id'], 'keep' ),
            'cancel_link'  => self::link( $id, $item['closure']['id'], 'cancel' ),
        );
    }

    /**
     * Build one of the two messages from its values.
     *
     * @param string $key    'closed_day' or 'closed_day_reminder'.
     * @param array  $values From values() or the sample.
     * @return array{subject:string,html:string,text:string}
     */
    public static function build( $key, $values ) {
        return SFAF_Messages::compose( $key, 'default', 'en', $values, array(
            'details' => array(
                'Event'       => $values['title'],
                'Date'        => $values['date'],
                'SFAF closed' => $values['closure'],
            ),
            'buttons' => array(
                array( $values['keep_link'], 'Keep the event', 'primary' ),
                array( $values['cancel_link'], 'Cancel the event', 'outline' ),
            ),
        ) );
    }

    /**
     * Send one message to the event's organizer.
     *
     * @return bool Whether it was handed to wp_mail().
     */
    private static function send( $key, $item ) {
        $author = get_userdata( (int) get_post_field( 'post_author', (int) $item['event_id'] ) );
        if ( ! $author || ! is_email( $author->user_email ) ) {
            return false;
        }
        $m = self::build( $key, self::values( $item ) );
        return (bool) SFAF_Email::send( $author->user_email, $m['subject'], $m['html'], $m['text'] );
    }

    /**
     * After a closure is saved: one email per undecided event on it, to its
     * organizer, once. Saving the closure again sends nothing new.
     *
     * @param string $closure_id
     * @return int How many went.
     */
    public static function on_closure_saved( $closure_id ) {
        $sent = 0;
        foreach ( self::undecided( $closure_id ) as $item ) {
            $id = (int) $item['event_id'];
            if ( in_array( (string) $closure_id, self::listed( $id, self::META_NOTIFIED ), true ) ) {
                continue;
            }
            if ( self::send( 'closed_day', $item ) ) {
                $sent++;
            }
            self::add( $id, self::META_NOTIFIED, $closure_id );
        }
        return $sent;
    }

    /**
     * The daily pass: a reminder to the organizer of every undecided date
     * seven days away or nearer, once.
     *
     * @return array{status:string,summary:string,counts:array}
     */
    public static function run_reminders() {
        $today = current_time( 'Y-m-d' );
        $limit = gmdate( 'Y-m-d', strtotime( $today . ' +' . self::REMIND_DAYS . ' days' ) );
        $sent  = 0;
        foreach ( self::undecided() as $item ) {
            if ( $item['date'] > $limit ) {
                continue;
            }
            $id = (int) $item['event_id'];
            if ( in_array( (string) $item['closure']['id'], self::listed( $id, self::META_REMINDED ), true ) ) {
                continue;
            }
            if ( self::send( 'closed_day_reminder', $item ) ) {
                $sent++;
            }
            self::add( $id, self::META_REMINDED, $item['closure']['id'] );
        }
        return array(
            'status'  => 'ok',
            'summary' => sprintf( '%d %s sent.', $sent, 1 === $sent ? 'reminder' : 'reminders' ),
            'counts'  => array( 'sent' => $sent ),
        );
    }
}
