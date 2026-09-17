<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_RSVP {

    public function register() {
        add_action( 'wp_ajax_uc_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );
        add_action( 'wp_ajax_nopriv_uc_submit_rsvp', array( $this, 'ajax_submit_rsvp' ) );
        /*
         * NO uc_subscribe_reminder ENDPOINT. It was the "Get Reminders" dialog
         * on the event page, and it went in 3.53.0 with the row it wrote.
         *
         * It inserted into THIS table, at status 'subscribed', against a single
         * event id, for somebody who held no place. That put them on the
         * morning-of reminder list, on the announcement list, and into every
         * query that asked who was registered; and the copy they received then
         * had to branch on the difference to stop telling them they had
         * released a place they never held. What the button was always meant to
         * offer is knowing when a series gains new dates, which is a different
         * subject with a different lifetime and now has its own table. See
         * SFAF_Follow.
         */

        /*
         * NO uc_export_rsvps ENDPOINT. It was the download link on the
         * WordPress RSVP screen, which went in 3.27.0, and it is removed with
         * it rather than left registered.
         *
         * That is not tidiness. It was gated on edit_posts, so any Author on
         * the site could fetch every registration ever taken as a CSV by
         * calling admin-ajax directly, and once the screen it belonged to was
         * gone nothing would ever have made anybody look at it again. The
         * export in /caladmin is the one that remains: SFAF_Portal::
         * export_rsvps_csv(), gated on can_view_all and on a nonce.
         */

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
            'event_id'   => isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0,
            'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
            'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
            'email'      => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
            'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
            // Present and truthy only when the box was actually ticked. An
            // absent field is a "no", which is the safe reading and the one an
            // unchecked checkbox actually produces.
            'optin'      => ! empty( $_POST['optin'] ),
            // How they said they would attend. Only a hybrid event asks, and
            // submit() validates this against that event's own list rather
            // than trusting it. See the capacity block there.
            'format'     => isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '',
        ) );

        wp_send_json( $result );
    }

    /**
     * The one display name, built from the pair.
     *
     * Everything that wants a person as a single string asks this: the staff
     * summary's table, the alert's Name row, the dashboard activity line, the
     * opt-in record. Nothing rebuilds "first space last" for itself, so the
     * greeting, the list and the export cannot disagree about what somebody is
     * called.
     *
     * A LAST NAME IS ALLOWED TO BE MISSING and produces the first name alone,
     * with no trailing space. The `name` column is the fallback, which is what
     * a row written before the pair existed, or by anything outside submit(),
     * will have.
     *
     * @param object|array $row A uc_rsvps row.
     * @return string
     */
    public static function display_name( $row ) {
        $row   = (object) $row;
        $first = isset( $row->first_name ) ? trim( (string) $row->first_name ) : '';
        $last  = isset( $row->last_name ) ? trim( (string) $row->last_name ) : '';
        $both  = trim( $first . ' ' . $last );
        if ( '' !== $both ) {
            return $both;
        }
        return isset( $row->name ) ? trim( (string) $row->name ) : '';
    }

    /**
     * A plain US ten-digit number, punctuated. Anything else, untouched.
     *
     * FORMATTED ON SAVE, NOT AS THEY TYPE, and that is the decision rather than
     * an implementation detail. A number cannot be recognised until it is
     * finished: "+44 20" and "(202) " begin identically as far as the third
     * keystroke, so an as-you-type formatter has to guess at every character
     * and then take its guess back. It also has to put the caret somewhere
     * after rewriting the value, which is where that pattern goes wrong on
     * phones and with a screen reader. Formatting once, server side, when the
     * value is complete, means the visitor types whatever they type and nothing
     * moves under them. It also covers every path into the table rather than
     * only the one that runs this script.
     *
     * NOTHING IS EVER REJECTED. This returns the original string unchanged
     * whenever it is not certain, which is every international number, every
     * country code (a leading 1 included), every extension, and anything with a
     * letter in it. A phone field somebody cannot complete is worse than an
     * unformatted number, and this function has no failure mode that empties or
     * refuses the value.
     *
     * @param string $raw What they typed.
     * @return string
     */
    public static function format_phone( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return '';
        }

        // Anything that is not a digit or one of the four separators Americans
        // write a local number with means this is not a plain ten-digit number:
        // a +, an x, "ext", a letter, a slash. Left exactly as entered.
        if ( preg_match( '/[^0-9 ().\-]/', $raw ) ) {
            return $raw;
        }

        $digits = preg_replace( '/\D/', '', $raw );
        if ( 10 !== strlen( $digits ) ) {
            // Nine digits is a typo we do not correct, eleven is a country code
            // in front of one, and both are kept as typed.
            return $raw;
        }

        return sprintf( '(%s) %s-%s',
            substr( $digits, 0, 3 ),
            substr( $digits, 3, 3 ),
            substr( $digits, 6 )
        );
    }

    /**
     * Submit an RSVP
     */
    public function submit( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';

        /*
         * FIRST NAME AND EMAIL. A LAST NAME IS NOT ASKED FOR HERE EITHER.
         *
         * The field is optional on the form, so it has to be optional in the
         * validator: a check that quietly required it would turn "optional"
         * into an error message somebody cannot get past.
         */
        $data['first_name'] = isset( $data['first_name'] ) ? trim( (string) $data['first_name'] ) : '';
        $data['last_name']  = isset( $data['last_name'] ) ? trim( (string) $data['last_name'] ) : '';

        if ( empty( $data['event_id'] ) || '' === $data['first_name'] || empty( $data['email'] ) ) {
            return array( 'success' => false, 'message' => 'First name and email are required.' );
        }

        if ( ! is_email( $data['email'] ) ) {
            return array( 'success' => false, 'message' => 'Please enter a valid email address.' );
        }

        // Make sure this is a real, published event before accepting an RSVP.
        $event = get_post( $data['event_id'] );
        if ( ! $event || $event->post_type !== 'uc_event' || $event->post_status !== 'publish' ) {
            return array( 'success' => false, 'message' => 'This event could not be found.' );
        }

        /*
         * A CANCELLED EVENT TAKES NO NEW REGISTRATIONS.
         *
         * Checked at the write and not only by hiding the form. A cancelled
         * event set to STAY is still on the public calendar by design, so its
         * page is reachable, and a form removed from a template is not a
         * refusal: an open tab from before the cancellation still posts, and so
         * does anybody constructing the request. Defect one in PROJECT.md §5
         * was a screen that relied on not being linked to.
         *
         * It says what happened rather than "not available", because the person
         * reading it very likely came from a link they were sent and needs to
         * know the event is off rather than that the button is broken.
         */
        if ( SFAF_Cancellation::is_cancelled( $data['event_id'] ) ) {
            return array( 'success' => false, 'message' => 'This event has been cancelled, so registrations are closed.' );
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

        /*
         * WHICH FORMAT THIS PERSON IS IN, AND THE CAPACITY THAT BELONGS TO IT.
         *
         * A HYBRID EVENT ASKS AND EVERYTHING ELSE DOES NOT. So the answer is
         * taken from the post only when the event actually has two formats, and
         * it is validated against the event's own list rather than trusted: a
         * posted "online" on an in-person event would otherwise count against a
         * capacity that event has never set, which is a limit of zero, which is
         * unlimited. Anything unrecognised falls back to the first format the
         * event offers, so a form posted without the field still registers
         * somebody rather than failing silently.
         *
         * A NON-HYBRID EVENT STORES '', which is what every existing row holds
         * and what the counters expect. See the `format` column's note in
         * sfaf-calendar.php.
         */
        $formats = sfaf_event_formats( $data['event_id'] );
        $hybrid  = SFAF_Online::is_hybrid( $data['event_id'] );
        $format  = '';
        if ( $hybrid ) {
            $asked  = isset( $data['format'] ) ? (string) $data['format'] : '';
            $format = in_array( $asked, $formats, true ) ? $asked : $formats[0];
        }
        $data['format'] = $format;

        /*
         * CAPACITY IS PER FORMAT, AND "THE EVENT IS FULL" IS A DIFFERENT
         * QUESTION. Somebody picking a full format on an event whose other
         * format has room is told about the format, not about the event: the
         * form can still take them, and turning them away would refuse a
         * registration the event wanted.
         */
        if ( sfaf_format_full( $data['event_id'], $hybrid ? $format : '' ) ) {
            if ( $hybrid && ! sfaf_event_full( $data['event_id'] ) ) {
                $other = ( SFAF_Online::MODE_ONLINE === $format ) ? 'in person' : 'online';
                return array(
                    'success' => false,
                    'message' => 'That option is full. There are still places to attend ' . $other . '.',
                );
            }
            return array( 'success' => false, 'message' => 'This event is at capacity.' );
        }

        // Insert RSVP.
        //
        // THE TOKEN IS WRITTEN HERE, WITH THE ROW, because the confirmation
        // email carries the cancel link and goes out in the next few lines. It
        // is 128 random bits from SFAF_Reminders::new_token(), the same
        // generator the reminder ledger uses, and it is the only credential the
        // cancel page accepts: no account, no session, nothing derived from the
        // address.
        // The phone is punctuated here, once, on the way in. See format_phone()
        // for why this is on save rather than on the keyboard.
        $data['phone'] = self::format_phone( $data['phone'] ?? '' );

        // The single string, derived from the pair and written with them. One
        // writer, so it cannot disagree with the columns it comes from.
        $data['name'] = trim( $data['first_name'] . ' ' . $data['last_name'] );

        $token    = SFAF_Reminders::new_token();
        $inserted = $wpdb->insert( $table, array(
            'event_id'   => $data['event_id'],
            'name'       => $data['name'],
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $data['email'],
            'phone'      => $data['phone'],
            'status'     => 'confirmed',
            'token'      => $token,
            'created_at' => current_time( 'mysql' ),
            // '' on every event that never asked. See the column's note.
            'format'     => $data['format'],
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        if ( $inserted ) {
            /*
             * THE COUNT CACHE IS NOW WRONG, AND SOMETHING IS ABOUT TO READ IT.
             *
             * sfaf_get_rsvp_count() memoizes for the request, and the capacity
             * check thirty lines above is a read: on any event WITH a capacity
             * it stored the pre-insert number. The registration alert then
             * built its "0 of 12 places taken" from that copy, and so did the
             * count handed back to the capacity bar on the card. The row that
             * was just written was the one missing from both.
             *
             * Same fault, same remedy, as the cancellation path: forget the
             * cached number the moment the number moves. Everything after this
             * line counts the row that was just inserted.
             */
            sfaf_clear_rsvp_count_cache( $data['event_id'] );

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
            //
            // $data CARRIES first_name AND last_name SEPARATELY, which is what
            // the Salesforce admin was asked for and what a Pardot prospect
            // record wants. A payload built from this hook takes the two fields
            // as they were typed rather than splitting a combined string on a
            // space, which is the operation that turns "Ana Maria Ruiz" into a
            // wrong surname.
            do_action( 'uc_rsvp_submitted', $wpdb->insert_id, $data );

            return array(
                'success'    => true,
                'message'    => 'You are registered! We look forward to seeing you.',
                // FIRST NAME ONLY for the greeting on screen, matching the
                // confirmation email's "You are registered, Mark."
                'first_name' => $data['first_name'],
                'count'      => sfaf_get_rsvp_count( $data['event_id'] ),
                // The same two add-to-calendar destinations the confirmation
                // email carries, built by the same two helpers, so the modal
                // and the email cannot offer different links. Either can be an
                // empty string for an event with no usable start time, and the
                // modal draws only what it is given.
                'gcal'       => sfaf_google_calendar_url( $data['event_id'] ),
                'ics'        => sfaf_ics_url( $data['event_id'] ),
            );
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
            'name'       => isset( $data['name'] ) ? (string) $data['name'] : '',
            // Carried separately because the confirmation greets somebody by
            // their first name and the alert names them in full. Both readings
            // come off one object, so neither builder has to split a string.
            'first_name' => isset( $data['first_name'] ) ? (string) $data['first_name'] : '',
            'last_name'  => isset( $data['last_name'] ) ? (string) $data['last_name'] : '',
            'email'      => isset( $data['email'] ) ? (string) $data['email'] : '',
            'token'      => isset( $data['token'] ) ? (string) $data['token'] : '',
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

        /*
         * SEARCH REACHES BOTH HALVES OF THE NAME, AND THE JOINED FORM TOO.
         *
         * Typing "Ruiz" has to find somebody whose surname it is, and typing
         * "Ana Ruiz" has to find them as well, which only the derived `name`
         * column can answer. Three columns, one term.
         */
        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (r.first_name LIKE %s OR r.last_name LIKE %s OR r.name LIKE %s OR r.email LIKE %s)";
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $search;
            $params[] = $search;
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

    /*
     * csv_escape() WENT WITH export_csv(), its only caller.
     *
     * The formula-injection guard itself did not go anywhere: SFAF_Portal::csv()
     * is the same rule, on the export that remains. Two copies of it existed
     * because two screens exported the same table, which is the duplication
     * 3.27.0 was about.
     */
}
