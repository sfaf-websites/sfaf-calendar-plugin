<?php
/**
 * PUBLISHED WITH NO PICTURE (3.141.0): one email to the calendar admins.
 *
 * Sent when an event goes to Published and nothing would draw a picture for
 * it: not its own, not its platform's, not its series'. An event with no
 * picture of its own in a series that has one has a picture, and nobody is
 * told. Drafts, pending events and a save of an event already published send
 * nothing, because only the move to Published is listened for.
 *
 * ONE EMAIL PER SERIES, NOT ONE PER DATE. Publishing twelve dates of a series
 * with no picture is one message naming the series, whichever screen did it,
 * and the series is marked so its later dates do not send another. An event
 * in no series is its own message, and is marked too.
 *
 * DECIDED AT THE END OF THE REQUEST, NOT AT THE MOVE. The editor publishes
 * first and writes the picture after, so asking at transition_post_status
 * would report a picture that was a moment away. Every move is noted, and
 * shutdown asks once, after everything the request wrote.
 *
 * To the calendar admins (SFAF_Portal::admin_recipients()), the people the
 * orphan alert goes to, with one copy for Asana (SFAF_Asana 'nopicture').
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_No_Picture {

    /** On an event and on a series term: when the message about it went. */
    const META_TOLD = '_sfaf_nopic_told';

    /** @var int[] Events that moved to Published in this request. */
    private static $moved = array();

    private static $hooked = false;

    public static function register() {
        add_action( 'transition_post_status', array( __CLASS__, 'note' ), 10, 3 );
    }

    /** Note an event's move to Published. Nothing is decided here. */
    public static function note( $new_status, $old_status, $post ) {
        if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post || 'uc_event' !== $post->post_type ) {
            return;
        }
        self::$moved[ (int) $post->ID ] = (int) $post->ID;
        if ( ! self::$hooked ) {
            self::$hooked = true;
            add_action( 'shutdown', array( __CLASS__, 'flush' ) );
        }
    }

    /** Whether anything would draw a picture for this event. */
    public static function has_picture( $event_id ) {
        return '' !== (string) sfaf_event_image_url( (int) $event_id );
    }

    /**
     * Decide and send, once per request. Public so a check can call it.
     *
     * @return array[] What was sent: array{series:int,events:int[]} each.
     */
    public static function flush() {
        $ids          = array_values( self::$moved );
        self::$moved  = array();
        $by_series    = array();
        $alone        = array();
        foreach ( $ids as $id ) {
            if ( 'publish' !== get_post_status( $id ) || self::has_picture( $id ) || get_post_meta( $id, self::META_TOLD, true ) ) {
                continue;
            }
            $sid = class_exists( 'SFAF_Series' ) ? (int) SFAF_Series::id_for_event( $id ) : 0;
            if ( $sid ) {
                $by_series[ $sid ][] = $id;
            } else {
                $alone[] = $id;
            }
        }
        $sent = array();
        foreach ( $by_series as $sid => $events ) {
            if ( get_term_meta( $sid, self::META_TOLD, true ) ) {
                foreach ( $events as $id ) { update_post_meta( $id, self::META_TOLD, time() ); }
                continue;
            }
            if ( self::send( $events, $sid ) ) {
                update_term_meta( $sid, self::META_TOLD, time() );
                foreach ( $events as $id ) { update_post_meta( $id, self::META_TOLD, time() ); }
                $sent[] = array( 'series' => $sid, 'events' => $events );
            }
        }
        foreach ( $alone as $id ) {
            if ( self::send( array( $id ), 0 ) ) {
                update_post_meta( $id, self::META_TOLD, time() );
                $sent[] = array( 'series' => 0, 'events' => array( $id ) );
            }
        }
        return $sent;
    }

    /** Who added it, as the message says it. */
    public static function added_by( $event_id ) {
        $c = SFAF_Access::creator( (int) $event_id );
        switch ( $c['kind'] ) {
            case 'account':
                return $c['name'] . ( '' !== $c['email'] ? ' (' . $c['email'] . ')' : '' );
            case 'request':
                return ( '' !== $c['name'] ? $c['name'] : 'Somebody' ) . ', on the staff request form';
            case 'submission':
                return ( '' !== $c['name'] ? $c['name'] : 'Somebody' ) . ', on the community form';
            case 'import':
                return 'Imported from ' . $c['name'];
            case 'gone':
                return 'A former user';
        }
        return 'Not recorded';
    }

    /**
     * One message, about a series or one event.
     *
     * @param int[] $events Published in this request, none with a picture.
     * @param int   $series_id 0 for an event in no series.
     * @return bool Whether it went to anybody.
     */
    private static function send( $events, $series_id ) {
        $to = SFAF_Portal::admin_recipients();
        if ( empty( $to ) || empty( $events ) ) {
            return false;
        }
        $first = (int) $events[0];
        $who   = self::added_by( $first );
        if ( $series_id ) {
            $term  = SFAF_Series::get( $series_id );
            $name  = $term ? (string) $term->name : '(untitled series)';
            $link  = SFAF_Portal::link( 'series/edit/' . (int) $series_id );
            $n     = count( $events );
            $rows  = array(
                'Series'   => $name,
                'Dates'    => sprintf( '%d %s published', $n, 1 === $n ? 'date' : 'dates' ),
                'Added by' => $who,
            );
            $what  = 'series';
            $label = 'Open the series in caladmin';
        } else {
            $name  = get_the_title( $first ) ?: '(untitled)';
            $link  = SFAF_Portal::link( 'events/edit/' . $first );
            $date  = (string) get_post_meta( $first, '_uc_event_date', true );
            $rows  = array(
                'Event'    => $name,
                'Date'     => '' !== $date ? sfaf_ap_date( $date ) : '',
                'Added by' => $who,
            );
            $what  = 'event';
            $label = 'Open the event in caladmin';
        }
        $name    = wp_specialchars_decode( $name, ENT_QUOTES );
        $subject = 'Published with no picture: ' . $name;
        $html    = SFAF_Email::heading( 'Published with no picture' );
        $html   .= SFAF_Email::details( $rows );
        $html   .= SFAF_Email::para( 'series' === $what ? 'Add a picture to the series.' : 'Add a picture to the event.' );
        $html   .= SFAF_Email::button( $link, $label );
        $text    = "Published with no picture.\n\n";
        foreach ( $rows as $k => $v ) {
            if ( '' !== trim( (string) $v ) ) {
                $text .= $k . ': ' . $v . "\n";
            }
        }
        $text   .= "\n" . ( 'series' === $what ? 'Add a picture to the series.' : 'Add a picture to the event.' )
            . "\n\n" . $label . ': ' . $link . "\n";
        $shell   = SFAF_Email::shell( 'Published with no picture: ' . $name, $html );
        $went    = false;
        foreach ( array_keys( $to ) as $address ) {
            if ( SFAF_Email::send( $address, $subject, $shell, $text ) ) {
                $went = true;
            }
        }
        if ( $went ) {
            SFAF_Asana::copy( 'nopicture', array_keys( $to ), $subject, $shell, $text );
        }
        return $went;
    }
}
