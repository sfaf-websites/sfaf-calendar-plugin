<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_RSVP {

    public function register() {
        add_action( 'wp_ajax_uc_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );
        add_action( 'wp_ajax_nopriv_uc_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );
        add_action( 'wp_ajax_uc_subscribe_reminder', array( $this, 'ajax_subscribe_reminder' ) );
        add_action( 'wp_ajax_nopriv_uc_subscribe_reminder', array( $this, 'ajax_subscribe_reminder' ) );
        add_action( 'wp_ajax_uc_export_rsvps', array( $this, 'export_csv' ) );

        // Route a confirmed RSVP to email / (mocked) integrations.
        add_action( 'uc_rsvp_submitted', array( $this, 'route_submission' ), 10, 2 );
    }

    /**
     * Submit RSVP via AJAX
     */
    public function ajax_submit_rsvp() {
        check_ajax_referer( 'uc_nonce', 'nonce' );

        $result = $this->submit( array(
            'event_id' => isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0,
            'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
            'email'    => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
            'phone'    => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
            // Present and truthy only when the box was actually ticked. An
            // absent field is a "no", which is the safe reading and the one an
            // unchecked checkbox actually produces.
            'optin'    => ! empty( $_POST['optin'] ),
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

        // Make sure this is a real, published event before accepting an RSVP.
        $event = get_post( $data['event_id'] );
        if ( ! $event || $event->post_type !== 'uc_event' || $event->post_status !== 'publish' ) {
            return array( 'success' => false, 'message' => 'This event could not be found.' );
        }

        // Don't accept RSVPs for events that don't have RSVP enabled.
        if ( get_post_meta( $data['event_id'], '_uc_rsvp_enabled', true ) !== '1' ) {
            return array( 'success' => false, 'message' => 'RSVP is not available for this event.' );
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
            $current = sfaf_get_rsvp_count( $data['event_id'] );
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
            // The marketing opt-in, if it was ticked. Recorded separately from
            // the RSVP on purpose: they are two different consents, and the
            // RSVP row must never be the evidence for a mailing list.
            if ( ! empty( $data['optin'] ) ) {
                SFAF_Optins::record( $data['email'], $data['name'], $data['event_id'], 'rsvp' );
            }

            // Fire action for integrations (email, Google Sheets, Pardot, etc.)
            do_action( 'uc_rsvp_submitted', $wpdb->insert_id, $data );

            return array(
                'success' => true,
                'message' => 'You are registered! We look forward to seeing you.',
                'count'   => sfaf_get_rsvp_count( $data['event_id'] ),
            );
        }

        return array( 'success' => false, 'message' => 'Something went wrong. Please try again.' );
    }

    /**
     * Subscribe to event reminders via AJAX (separate from RSVP).
     */
    public function ajax_subscribe_reminder() {
        check_ajax_referer( 'uc_nonce', 'nonce' );

        $event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
        $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

        wp_send_json( $this->subscribe_reminder( $event_id, $email ) );
    }

    /**
     * Save a reminder subscriber to the RSVP table with status "subscribed".
     */
    public function subscribe_reminder( $event_id, $email ) {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';

        if ( ! $email || ! is_email( $email ) ) {
            return array( 'success' => false, 'message' => 'Please enter a valid email address.' );
        }

        $event = get_post( $event_id );
        if ( ! $event || $event->post_type !== 'uc_event' || $event->post_status !== 'publish' ) {
            return array( 'success' => false, 'message' => 'This event could not be found.' );
        }

        // Already subscribed? Treat as success (idempotent).
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND email = %s AND status = 'subscribed'",
            $event_id,
            $email
        ) );
        if ( $existing > 0 ) {
            return array( 'success' => true, 'message' => "You're already on the reminder list for this event." );
        }

        $inserted = $wpdb->insert( $table, array(
            'event_id'   => $event_id,
            'name'       => '',
            'email'      => $email,
            'phone'      => '',
            'status'     => 'subscribed',
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );

        if ( $inserted ) {
            do_action( 'uc_reminder_subscribed', $wpdb->insert_id, $event_id, $email );
            return array( 'success' => true, 'message' => "You're on the list! We'll send you a reminder." );
        }

        return array( 'success' => false, 'message' => 'Something went wrong. Please try again.' );
    }

    /**
     * Route a confirmed RSVP: confirmation email + organizer notification.
     * Pardot / Google Sheets are mocked for the demo (action stubs only).
     *
     * @param int   $rsvp_id Inserted RSVP row id.
     * @param array $data    RSVP data.
     */
    public function route_submission( $rsvp_id, $data ) {
        $settings = get_option( 'uc_settings', array() );
        $event_id = $data['event_id'];

        // 1) Confirmation email to the attendee (gated by global toggle).
        if ( ! empty( $settings['route_confirmation_email'] ) && $settings['route_confirmation_email'] === '1' ) {
            $subject = get_post_meta( $event_id, '_uc_email_subject', true );
            $body    = get_post_meta( $event_id, '_uc_email_body', true );
            $replyto = get_post_meta( $event_id, '_uc_email_replyto', true );

            if ( '' === $subject ) {
                $subject = ! empty( $settings['email_rsvp_subject'] ) ? $settings['email_rsvp_subject'] : "You're registered for {event_name}";
            }
            if ( '' === $body ) {
                $body = ! empty( $settings['email_rsvp_body'] ) ? $settings['email_rsvp_body'] : "Hi {attendee_name},\n\nYou're registered for {event_name} on {event_date}.\n\nWe look forward to seeing you!";
            }
            if ( '' === $replyto && ! empty( $settings['email_rsvp_replyto'] ) ) {
                $replyto = $settings['email_rsvp_replyto'];
            }

            $subject = sfaf_replace_tokens( $subject, $event_id, $data );
            $body    = sfaf_replace_tokens( $body, $event_id, $data );

            $headers = array();
            if ( $replyto ) {
                $headers[] = 'Reply-To: ' . $replyto;
            }
            wp_mail( $data['email'], $subject, $body, $headers );
        }

        // 2) Organizer notification.
        $notify_event  = get_post_meta( $event_id, '_uc_notify_organizer', true ) === '1';
        $route_global  = ! empty( $settings['route_email_organizer'] ) && $settings['route_email_organizer'] === '1';
        $organizer     = get_post_meta( $event_id, '_uc_organizer_email', true );
        if ( ! $organizer && ! empty( $settings['route_organizer_email'] ) ) {
            $organizer = $settings['route_organizer_email'];
        }

        if ( ( $notify_event || $route_global ) && $organizer && is_email( $organizer ) ) {
            $subject = sprintf( 'New RSVP: %s', get_the_title( $event_id ) );
            $body    = sprintf(
                "%s (%s) just registered for %s.",
                $data['name'],
                $data['email'],
                get_the_title( $event_id )
            );
            wp_mail( $organizer, $subject, $body );
        }

        // 3) Pardot prospect / Google Sheet row — third-party, mocked for the demo.
        if ( ! empty( $settings['route_pardot'] ) && $settings['route_pardot'] === '1' ) {
            do_action( 'uc_mock_pardot_prospect', $event_id, $data );
        }
        if ( ! empty( $settings['route_google_sheet'] ) && $settings['route_google_sheet'] === '1' ) {
            do_action( 'uc_mock_google_sheet_row', $event_id, $data );
        }
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

        if ( ! empty( $args['status'] ) ) {
            $where .= " AND r.status = %s";
            $params[] = $args['status'];
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

        check_admin_referer( 'uc_export_rsvps' );

        $event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
        $rsvps = $event_id ? self::get_rsvps( $event_id ) : self::get_all_rsvps();

        $filename = $event_id
            ? 'rsvps-event-' . $event_id . '-' . current_time( 'Y-m-d' ) . '.csv'
            : 'rsvps-all-' . current_time( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'Event', 'Name', 'Email', 'Phone', 'Status', 'Date Registered' ) );

        foreach ( $rsvps as $rsvp ) {
            $event_title = isset( $rsvp->event_title ) ? $rsvp->event_title : get_the_title( $rsvp->event_id );
            fputcsv( $output, array(
                $this->csv_escape( $event_title ),
                $this->csv_escape( $rsvp->name ),
                $this->csv_escape( $rsvp->email ),
                $this->csv_escape( $rsvp->phone ),
                $this->csv_escape( $rsvp->status ),
                $this->csv_escape( $rsvp->created_at ),
            ) );
        }

        fclose( $output );
        exit;
    }

    /**
     * Neutralize CSV/spreadsheet formula injection.
     * A leading =, +, -, @, tab or carriage return can be interpreted as a
     * formula by Excel/Sheets, so prefix those values with a single quote.
     */
    private function csv_escape( $value ) {
        $value = (string) $value;
        if ( $value !== '' && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            $value = "'" . $value;
        }
        return $value;
    }
}
