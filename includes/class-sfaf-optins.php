<?php
/**
 * Email marketing opt-ins.
 *
 * THE ONE RECORD OF CONSENT, for every sign-up box on the RSVP form: "Sign me
 * up for SFAF Newsletter and Updates", and since 3.124.0 a team's "Sign me up
 * for [team] email updates". Each row says which list (list_key, list_name),
 * when, on which event and in which language. Nothing here talks to Pardot or
 * Mailchimp: the SFAF rows wait for Pardot, and a team's row is handed to
 * SFAF_Mailchimp::enqueue(), whose queue does the sending.
 *
 * WHY THE TIMESTAMP AND THE FORM NAME ARE NOT OPTIONAL
 * ---------------------------------------------------------------------------
 * Whoever eventually wires this to a marketing platform will be asked, by that
 * platform or by somebody's compliance officer, when and where each address
 * consented. Reconstructing that after the fact is somewhere between painful
 * and impossible; recording it at the moment of consent costs one row. So every
 * opt-in stores its own timestamp and the form it came from, and each consent
 * is a separate row rather than a flag on an address — a person who ticks the
 * box at three events has three pieces of evidence, not one that keeps moving.
 *
 * THE BOX IS NEVER PRE-TICKED. RSVPing is one action and subscribing is
 * another, and an RSVP must never subscribe anybody as a side effect. The
 * checkbox is rendered unchecked, and a row is written only when it arrives
 * checked.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Optins {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uc_optins';
    }

    /** The SFAF newsletter's list key, and its name on Email Opt-ins. */
    const SFAF_LIST = 'sfaf';
    const SFAF_NAME = 'SFAF Newsletter';

    /**
     * Record one consent.
     *
     * @param string $email  Consenting address.
     * @param string $name   Name given on the form, if any.
     * @param int    $event_id Event the form belonged to (0 when none).
     * @param string $source Which form it came from, e.g. 'rsvp'.
     * @param array  $extra  list_key, list_name, lang, rsvp_id (3.124.0).
     * @return int The row's id, or 0.
     */
    public static function record( $email, $name, $event_id, $source, $extra = array() ) {
        global $wpdb;

        $email = sanitize_email( $email );
        if ( ! $email || ! is_email( $email ) ) {
            return false;
        }

        $inserted = $wpdb->insert( self::table(), array(
            'email'        => $email,
            'name'         => sanitize_text_field( $name ),
            'event_id'     => (int) $event_id,
            'source_form'  => sanitize_key( $source ),
            'consented_at' => current_time( 'mysql' ),
            'created_gmt'  => current_time( 'mysql', true ),
            'list_key'     => isset( $extra['list_key'] ) ? substr( (string) $extra['list_key'], 0, 40 ) : self::SFAF_LIST,
            'list_name'    => isset( $extra['list_name'] ) ? sanitize_text_field( (string) $extra['list_name'] ) : self::SFAF_NAME,
            'lang'         => isset( $extra['lang'] ) && in_array( $extra['lang'], array( 'en', 'es' ), true ) ? $extra['lang'] : '',
            'rsvp_id'      => isset( $extra['rsvp_id'] ) ? (int) $extra['rsvp_id'] : 0,
        ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ) );

        if ( $inserted ) {
            /**
             * Fires when consent is recorded. The hook a future Pardot (or
             * other) push attaches to, so the integration never has to change
             * this file.
             */
            do_action( 'sfaf_optin_recorded', $wpdb->insert_id, $email, $event_id, $source );
        }

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    /**
     * The boxes a registration's form ticked, as the RSVP row keeps them
     * between a waitlist join and its confirmation: 'sfaf' and 'mc:' plus a
     * connection id, comma-separated.
     *
     * @param bool     $sfaf
     * @param string[] $connections
     * @return string
     */
    public static function ticks_string( $sfaf, $connections ) {
        $out = $sfaf ? array( self::SFAF_LIST ) : array();
        foreach ( (array) $connections as $id ) {
            $out[] = 'mc:' . sanitize_key( (string) $id );
        }
        return implode( ',', $out );
    }

    /**
     * Record a confirmed registration's ticks: the SFAF one as a row here, a
     * team's as a row here handed to the Mailchimp queue.
     *
     * ONCE CONFIRMED (3.124.0). Called from SFAF_RSVP::submit() for a
     * registration and from SFAF_Waitlist::admit() for a waitlisted one, so a
     * waitlist sign-up counts when its place is confirmed and not before.
     * Until 3.124.0 a waitlist join's SFAF tick was not kept at all.
     *
     * Nothing is recorded without an email address.
     *
     * @param array  $person event_id, rsvp_id, email, name, first_name, last_name, lang.
     * @param string $ticks  ticks_string().
     * @return int How many were recorded.
     */
    public static function record_ticks( $person, $ticks ) {
        $email = isset( $person['email'] ) ? (string) $person['email'] : '';
        if ( '' === trim( $email ) || '' === (string) $ticks ) {
            return 0;
        }
        $event_id = (int) $person['event_id'];
        $base     = array(
            'lang'    => isset( $person['lang'] ) ? (string) $person['lang'] : '',
            'rsvp_id' => isset( $person['rsvp_id'] ) ? (int) $person['rsvp_id'] : 0,
        );
        $name = isset( $person['name'] ) ? (string) $person['name'] : trim( ( $person['first_name'] ?? '' ) . ' ' . ( $person['last_name'] ?? '' ) );
        $n    = 0;
        foreach ( array_unique( array_filter( explode( ',', (string) $ticks ) ) ) as $tick ) {
            if ( self::SFAF_LIST === $tick ) {
                $n += self::record( $email, $name, $event_id, 'rsvp', $base ) ? 1 : 0;
                continue;
            }
            if ( 0 !== strpos( $tick, 'mc:' ) || ! class_exists( 'SFAF_Mailchimp' ) ) {
                continue;
            }
            $conn = SFAF_Mailchimp::get( substr( $tick, 3 ) );
            if ( ! $conn ) {
                continue;
            }
            $row = self::record( $email, $name, $event_id, 'rsvp', $base + array( 'list_key' => $tick, 'list_name' => $conn['name'] ) );
            if ( $row ) {
                $n++;
                SFAF_Mailchimp::enqueue( $row, $conn['id'], array(
                    'event_id'   => $event_id,
                    'rsvp_id'    => $base['rsvp_id'],
                    'email'      => $email,
                    'first_name' => isset( $person['first_name'] ) ? (string) $person['first_name'] : '',
                    'last_name'  => isset( $person['last_name'] ) ? (string) $person['last_name'] : '',
                ) );
            }
        }
        return $n;
    }

    /**
     * The list a row was for, as Email Opt-ins names it. Rows from before
     * 3.124.0 have no list and were all SFAF's.
     */
    public static function list_label( $row ) {
        $key = isset( $row->list_key ) ? (string) $row->list_key : '';
        if ( '' === $key || self::SFAF_LIST === $key ) {
            return self::SFAF_NAME;
        }
        return '' !== (string) $row->list_name ? (string) $row->list_name : $key;
    }

    /** A row's language as Email Opt-ins shows it. */
    public static function lang_label( $row ) {
        $lang = isset( $row->lang ) ? (string) $row->lang : '';
        return 'es' === $lang ? 'Spanish' : ( 'en' === $lang ? 'English' : '' );
    }

    /**
     * All recorded consents, newest first, with the event title joined on.
     *
     * @param array $args search, limit.
     * @return object[]
     */
    public static function all( $args = array() ) {
        global $wpdb;
        $table  = self::table();
        $limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 500;
        $search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

        $where  = 'WHERE 1=1';
        $params = array();
        if ( '' !== $search ) {
            $where   .= ' AND (o.email LIKE %s OR o.name LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT o.*, p.post_title AS event_title
                FROM $table o
                LEFT JOIN {$wpdb->posts} p ON o.event_id = p.ID
                $where
                ORDER BY o.id DESC
                LIMIT %d";
        $params[] = $limit;

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Which of these addresses consented, and on which event.
     *
     * ONE QUERY FOR A WHOLE PAGE OF REGISTRATIONS. The registrations table
     * marks the rows where somebody also ticked "SFAF news and updates", and
     * asking per row would be one round trip per person. The key is email AND
     * event, because consent was given on a particular form: somebody who
     * ticked it in March and not in June is marked in March and not in June,
     * which is what the record actually says.
     *
     * Addresses are compared lowercased, because record() stores what
     * sanitize_email() returned and the RSVP table stores what the person
     * typed.
     *
     * @param string[] $emails
     * @return array<string,bool> "email|event_id" => true
     */
    public static function consent_index( $emails ) {
        global $wpdb;

        $emails = array_map( 'strtolower', array_map( 'trim', array_map( 'strval', (array) $emails ) ) );
        $emails = array_values( array_unique( array_filter( $emails ) ) );
        if ( empty( $emails ) ) {
            return array();
        }
        // Bounded, so a pathological page cannot build an unbounded IN list.
        $emails = array_slice( $emails, 0, 1000 );

        $table        = self::table();
        $placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
        $rows         = $wpdb->get_results( $wpdb->prepare(
            // The SFAF newsletter's rows only: a team's list is not "SFAF news and updates".
            "SELECT email, event_id FROM $table WHERE LOWER(email) IN ($placeholders) AND list_key IN ('', 'sfaf')",
            $emails
        ) );

        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[ strtolower( (string) $row->email ) . '|' . (int) $row->event_id ] = true;
        }
        return $out;
    }

    /** How many consents are on file. */
    public static function count() {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    }
}
