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

    /** How many consents are on file. */
    public static function count() {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    }
}
