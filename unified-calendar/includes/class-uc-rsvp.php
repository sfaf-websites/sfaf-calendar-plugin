<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class UC_RSVP {

    public function register() {
        add_action( 'wp_ajax_uc_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );
        add_action( 'wp_ajax_nopriv_uc_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );
        add_action( 'wp_ajax_uc_export_rsvps', array( $this, 'export_csv' ) );
    }

    /**
     * Submit RSVP via AJAX
     */
    public function ajax_submit_rsvp() {
        check_ajax_referer( 'uc_nonce', 'nonce' );

        $result = $this->submit( array(
            'event_id' => intval( $_POST['event_id'] ),
            'name'     => sanitize_text_field( $_POST['name'] ),
            'email'    => sanitize_email( $_POST['email'] ),
            'phone'    => sanitize_text_field( $_POST['phone'] ?? '' ),
        ) );

        wp_send_json( $result );
    }

    /**
     * Submit an RSVP
     */
    public function submit( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';

        if ( empty( $data['event_id'] ) || empty( $data['name'] ) || empty( $data['email'] ) ) {
            return array( 'success' => false, 'message' => 'Name and email are required.' );
        }

        if ( ! is_email( $data['email'] ) ) {
            return array( 'success' => false, 'message' => 'Please enter a valid email address.' );
        }

        // Check for duplicate
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND email = %s AND status = 'confirmed'",
            $data['event_id'],
            $data['email']
        ) );

        if ( $existing > 0 ) {
            return array( 'success' => false, 'message' => 'You have already registered for this event.' );
        }

        // Check capacity
        $capacity = (int) get_post_meta( $data['event_id'], '_uc_capacity', true );
        if ( $capacity > 0 ) {
            $current = uc_get_rsvp_count( $data['event_id'] );
            if ( $current >= $capacity ) {
                return array( 'success' => false, 'message' => 'This event is at capacity.' );
            }
        }

        // Insert RSVP
        $inserted = $wpdb->insert( $table, array(
            'event_id'   => $data['event_id'],
            'name'       => $data['name'],
            'email'      => $data['email'],
            'phone'      => $data['phone'] ?? '',
            'status'     => 'confirmed',
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );

        if ( $inserted ) {
            // Fire action for integrations (Google Sheets, Pardot, etc.)
            do_action( 'uc_rsvp_submitted', $wpdb->insert_id, $data );

            return array(
                'success' => true,
                'message' => 'You are registered! We look forward to seeing you.',
                'count'   => uc_get_rsvp_count( $data['event_id'] ),
            );
        }

        return array( 'success' => false, 'message' => 'Something went wrong. Please try again.' );
    }

    /**
     * Get RSVPs for an event
     */
    public static function get_rsvps( $event_id, $status = 'confirmed' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE event_id = %d AND status = %s ORDER BY created_at DESC",
            $event_id,
            $status
        ) );
    }

    /**
     * Get all RSVPs with event info (for admin page)
     */
    public static function get_all_rsvps( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';
        
        $where = "WHERE 1=1";
        $params = array();

        if ( ! empty( $args['event_id'] ) ) {
            $where .= " AND r.event_id = %d";
            $params[] = intval( $args['event_id'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (r.name LIKE %s OR r.email LIKE %s)";
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $sql = "SELECT r.*, p.post_title as event_title 
                FROM $table r 
                LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID 
                $where 
                ORDER BY r.created_at DESC";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        return $wpdb->get_results( $sql );
    }

    /**
     * Export RSVPs as CSV
     */
    public function export_csv() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Unauthorized' );
        }

        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $rsvps = $event_id ? self::get_rsvps( $event_id ) : self::get_all_rsvps();
        
        $filename = $event_id 
            ? 'rsvps-event-' . $event_id . '-' . date( 'Y-m-d' ) . '.csv'
            : 'rsvps-all-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'Event', 'Name', 'Email', 'Phone', 'Status', 'Date Registered' ) );

        foreach ( $rsvps as $rsvp ) {
            $event_title = isset( $rsvp->event_title ) ? $rsvp->event_title : get_the_title( $rsvp->event_id );
            fputcsv( $output, array(
                $event_title,
                $rsvp->name,
                $rsvp->email,
                $rsvp->phone,
                $rsvp->status,
                $rsvp->created_at,
            ) );
        }

        fclose( $output );
        exit;
    }
}
