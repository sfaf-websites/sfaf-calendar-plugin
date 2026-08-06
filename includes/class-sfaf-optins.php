<?php
/**
 * Email marketing opt-ins.
 *
 * STORAGE ONLY. Nothing here talks to Pardot, Mailchimp or anything else, and
 * no integration is chosen yet. What it does do is record consent in a form
 * somebody can evidence later.
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

    /**
     * Record one consent.
     *
     * @param string $email  Consenting address.
     * @param string $name   Name given on the form, if any.
     * @param int    $event_id Event the form belonged to (0 when none).
     * @param string $source Which form it came from, e.g. 'rsvp'.
     * @return bool
     */
    public static function record( $email, $name, $event_id, $source ) {
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
        ), array( '%s', '%s', '%d', '%s', '%s', '%s' ) );

        if ( $inserted ) {
            /**
             * Fires when consent is recorded. The hook a future Pardot (or
             * other) push attaches to, so the integration never has to change
             * this file.
             */
            do_action( 'sfaf_optin_recorded', $wpdb->insert_id, $email, $event_id, $source );
        }

        return (bool) $inserted;
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
            "SELECT email, event_id FROM $table WHERE LOWER(email) IN ($placeholders)",
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
