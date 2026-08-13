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

        // Keep the attendance record readable after its event is deleted.
        add_action( 'before_delete_post', array( __CLASS__, 'snapshot_event_title' ) );
    }

    /**
     * ATTENDANCE RECORDS OUTLIVE THE EVENT, ON PURPOSE.
     *
     * Deleting an event is a decision about what appears on the calendar. It
     * is not a decision to forget that thirty people came, and for a public
     * health nonprofit that history is worth something: who attended what, how
     * full a group ran, whether a series was working. Those rows are also the
     * only evidence that a person ever registered, which matters if they ever
     * ask.
     *
     * So the rows stay. What was wrong was not that they survived, but that
     * they survived UNREADABLE: the RSVP list joins on the post, and with the
     * post gone the Event column rendered blank. A blank cell is not history,
     * it is a bug that looks like a bug.
     *
     * The title is therefore copied onto the rows at the moment the event is
     * deleted, which is the last moment it can be known. Rows orphaned before
     * 2.12.0 have no snapshot to recover, and render as "Deleted event (#id)"
     * rather than as nothing.
     *
     * The reminder ledger gets the same treatment for the same reason: it is
     * the record of what was actually sent to whom.
     */
    public static function snapshot_event_title( $post_id ) {
        global $wpdb;

        $post = get_post( $post_id );
        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return;
        }
        $title = $post->post_title !== '' ? $post->post_title : '(untitled event)';

        $wpdb->update(
            $wpdb->prefix . 'uc_rsvps',
            array( 'event_title' => $title ),
            array( 'event_id' => (int) $post_id ),
            array( '%s' ),
            array( '%d' )
        );

        $wpdb->update(
            $wpdb->prefix . 'uc_reminder_log',
            array( 'event_title' => $title ),
            array( 'event_id' => (int) $post_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * The name to show for a row whose event may or may not still exist.
     *
     * @param object $row A uc_rsvps or uc_reminder_log row, optionally with a
     *                    joined event_title from the posts table.
     * @return string
     */
    public static function event_label( $row ) {
        // The live post title, when the join found one.
        if ( ! empty( $row->post_title ) ) {
            return (string) $row->post_title;
        }
        $live = isset( $row->event_id ) ? get_the_title( $row->event_id ) : '';
        if ( $live ) {
            return $live;
        }
        // The snapshot taken when the event was deleted.
        if ( ! empty( $row->event_title ) ) {
            return $row->event_title . ' (deleted)';
        }
        return sprintf( 'Deleted event (#%d)', isset( $row->event_id ) ? (int) $row->event_id : 0 );
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

        // Insert RSVP.
        //
        // THE TOKEN IS WRITTEN HERE, WITH THE ROW, because the confirmation
        // email carries the cancel link and goes out in the next few lines. It
        // is 128 random bits from SFAF_Reminders::new_token(), the same
        // generator the reminder ledger uses, and it is the only credential the
        // cancel page accepts: no account, no session, nothing derived from the
        // address.
        $token    = SFAF_Reminders::new_token();
        $inserted = $wpdb->insert( $table, array(
            'event_id'   => $data['event_id'],
            'name'       => $data['name'],
            'email'      => $data['email'],
            'phone'      => $data['phone'] ?? '',
            'status'     => 'confirmed',
            'token'      => $token,
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        if ( $inserted ) {
            // The marketing opt-in, if it was ticked. Recorded separately from
            // the RSVP on purpose: they are two different consents, and the
            // RSVP row must never be the evidence for a mailing list.
            if ( ! empty( $data['optin'] ) ) {
                SFAF_Optins::record( $data['email'], $data['name'], $data['event_id'], 'rsvp' );
            }

            // The token travels with the data so the routed emails can build a
            // cancel link without a second query for the row just written.
            $data['token']   = $token;
            $data['rsvp_id'] = (int) $wpdb->insert_id;

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

        // A token here too, for the same reason the registration gets one: this
        // row is a promise to email somebody, so it needs a way for them to
        // stop it that works with no account.
        $inserted = $wpdb->insert( $table, array(
            'event_id'   => $event_id,
            'name'       => '',
            'email'      => $email,
            'phone'      => '',
            'status'     => 'subscribed',
            'token'      => SFAF_Reminders::new_token(),
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );

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

        /*
         * ONE SHAPE FOR THE PERSON, PASSED TO BOTH BUILDERS.
         *
         * The confirmation needs their name and their token; the alert needs
         * their name and address. Handing both the same object means the two
         * emails cannot disagree about who just registered.
         */
        $person = (object) array(
            'name'  => isset( $data['name'] ) ? (string) $data['name'] : '',
            'email' => isset( $data['email'] ) ? (string) $data['email'] : '',
            'token' => isset( $data['token'] ) ? (string) $data['token'] : '',
        );

        /*
         * 1) THE CONFIRMATION, to the person.
         *
         * Two switches, and they mean different things. The site-wide one in
         * Settings is "this site sends confirmations at all"; the per-event one
         * is "this event does". The site-wide default is ON when nothing has
         * been saved, which is the 3.25.0 change: a fresh install used to send
         * nothing until somebody found a toggle.
         */
        if ( self::confirmations_enabled() && SFAF_Notifications::on( $event_id, 'confirmation' ) ) {
            SFAF_Notifications::send_confirmation( $event_id, $person );
        }

        /*
         * 2) THE REGISTRATION ALERT, to the event's notification list.
         *
         * ONE LIST NOW. This used to read a single address field, falling back
         * to a single site-wide address, while the morning-of reminder went to
         * a resolved list of people, teams and typed addresses. Both answered
         * "who finds out"; only one of them had been upgraded. The old address
         * was folded into the list by sfaf_migrate_notification_lists() on
         * upgrade, so nothing that used to be told stops being told.
         */
        SFAF_Notifications::send_alert( $event_id, $person );

        // 3) Pardot prospect / Google Sheet row — third-party, mocked for the demo.
        if ( ! empty( $settings['route_pardot'] ) && $settings['route_pardot'] === '1' ) {
            do_action( 'uc_mock_pardot_prospect', $event_id, $data );
        }
        if ( ! empty( $settings['route_google_sheet'] ) && $settings['route_google_sheet'] === '1' ) {
            do_action( 'uc_mock_google_sheet_row', $event_id, $data );
        }
    }

    /**
     * Whether this site sends confirmation emails at all.
     *
     * ON UNLESS SWITCHED OFF, which reverses the old reading of the same
     * setting. It used to be `! empty( $settings['route_confirmation_email'] )`,
     * so an install where nobody had opened Settings and pressed Save sent no
     * confirmations and gave no sign of it. Absent now means on, and an explicit
     * '0' still means off, exactly like SFAF_Reminders::enabled().
     */
    public static function confirmations_enabled() {
        $settings = get_option( 'uc_settings', array() );
        return ! isset( $settings['route_confirmation_email'] ) || '1' === (string) $settings['route_confirmation_email'];
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

        /*
         * ROWS WHOSE EVENT HAS GONE.
         *
         * These are the reason a list of every registration still exists at
         * all. They cannot be reached through an event, because there is no
         * event to click, so without a way to ask for them specifically they
         * would be findable only by scrolling the whole table.
         *
         * The test is on the JOIN, not on a flag: an orphan is a row whose
         * event_id matches no post, which is the definition and needs nothing
         * written down at deletion time to stay true.
         */
        if ( ! empty( $args['orphans'] ) ) {
            $where .= " AND p.ID IS NULL";
        }

        // Aliased to post_title, NOT to event_title: the table now has its own
        // event_title column holding the snapshot taken when an event was
        // deleted, and aliasing the join over the top of it would null the
        // snapshot out in exactly the case it exists for. See event_label().
        $sql = "SELECT r.*, p.post_title AS post_title
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
     * How many registrations belong to an event that no longer exists.
     *
     * Drives the link to them from the Events list, which appears only when
     * there is something behind it. A permanent link to an empty screen is
     * clutter; a link that appears when history exists is a signpost.
     *
     * @return int
     */
    public static function orphan_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table r LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID WHERE p.ID IS NULL"
        );
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
            $event_title = self::event_label( $rsvp );
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
