<?php
/**
 * Following a series.
 *
 * ONE THING ARRIVES, AND IT IS THE ONLY THING THIS EVER SENDS: an email when
 * new dates are added to a series. Not the morning-of reminder, not the
 * cancelled or changed announcements, not the SFAF newsletter. A follower is a
 * person watching one programme's schedule, and that is the whole of it.
 *
 * WHY THIS IS ITS OWN TABLE AND NOT A STATUS ON A REGISTRATION
 * ---------------------------------------------------------------------------
 * It used to be one. "Get Reminders" wrote a row into `uc_rsvps` against a
 * single event id with status 'subscribed', which put that address on the
 * morning-of reminder list and on the announcement list, and told them in the
 * cancel flow that they had released a place they never held. Three separate
 * pieces of copy had to branch on `$is_subscriber` to undo the claim the
 * storage was making, and every query that asked "who is registered" had to
 * remember to say `IN ('confirmed','subscribed')` or silently disagree with the
 * next one.
 *
 * The fix is the storage, not more branches. A follower is not a registration,
 * is not attached to an event, and shares no query with one. Nothing in this
 * file reads `uc_rsvps` and nothing that reads `uc_rsvps` reads this.
 *
 * IT IS NOT MARKETING CONSENT EITHER. Nothing here writes to `uc_optins` and
 * nothing here feeds the newsletter list. Those are two different permissions
 * and on this calendar the distinction matters more than usual: somebody
 * following a testing programme's dates has not agreed to be mailed by a
 * foundation about anything else, and a table that made those the same row
 * would be the evidence for a consent nobody gave.
 *
 * PENDING UNTIL CONFIRMED
 * ---------------------------------------------------------------------------
 * Typing an address is not consent to be mailed at it, and anybody can type
 * anybody's. So a submission writes a PENDING row, which is in no audience,
 * and an email goes to the address asking whoever holds it to confirm. Only
 * that confirmation makes the record active.
 *
 * THE UNSUBSCRIBE LINK IS IN THAT FIRST EMAIL, so a follower is never without
 * a route out. The mechanism this replaces minted a token at subscribe time and
 * then never delivered it to anybody: the only message carrying one arrived on
 * the morning of the event, by which point stopping reminders stops nothing.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Follow {

    /**
     * How long an unconfirmed record is worth confirming.
     *
     * THIRTY DAYS, MATCHED TO SFAF_Request::COOKIE_DAYS, and the match is the
     * argument. That constant answers "how long after somebody typed their
     * address into a public form is it still reasonable to act on", which is
     * this question with a different verb. The other lifetime in the plugin,
     * SFAF_Request::TOKEN_TTL, is sixty minutes because that link opens a form
     * that can create an event: it is a login link and is priced like one. This
     * link creates nothing, opens nothing, and grants no capability. It says
     * yes to one row.
     *
     * The unsubscribe token has NO lifetime at all, matched to the RSVP cancel
     * token, which also never expires. A credential that only ever removes
     * somebody from a list is one that must still work the day they finally go
     * looking for it.
     */
    const CONFIRM_DAYS = 30;

    /** The query var whose VALUE is a confirm token. */
    const VAR_CONFIRM = 'uc_follow_confirm';

    /** The query var whose VALUE is an unsubscribe token. */
    const VAR_STOP = 'uc_follow_stop';

    /* =====================================================================
     * Wiring
     * ================================================================== */

    /**
     * ADMIN-AJAX AND A FRONT-END QUERY VAR, AND NO REST ROUTE.
     *
     * The same arrangement as the registration form, the cancel link, the staff
     * request form and the community submission form, for the same reason: the
     * embed's CORS gate compares the route string exactly, so a second REST
     * route matches none of it and would be unreachable from any embedded
     * calendar. See SFAF_Cron's note on the nudge endpoint.
     */
    public function register() {
        add_action( 'wp_ajax_uc_follow_series', array( $this, 'ajax_follow' ) );
        add_action( 'wp_ajax_nopriv_uc_follow_series', array( $this, 'ajax_follow' ) );

        add_action( 'template_redirect', array( $this, 'maybe_handle_link' ) );
    }

    /** The followers table. */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'uc_series_followers';
    }

    /* =====================================================================
     * Addresses
     * ================================================================== */

    /** One spelling of an address, for comparing and for storing. */
    public static function normalize( $email ) {
        return strtolower( trim( (string) $email ) );
    }

    /**
     * The key the unique index is built on.
     *
     * CASE IS NOT A SECOND PERSON. `Sam@Example.org` and `sam@example.org` are
     * one mailbox, so the uniqueness has to be over the lowercased form. It is
     * hashed rather than indexed directly because a composite key over
     * varchar(200) in utf8mb4 can exceed the older 767-byte index limit, which
     * is the same reason the reminder ledger keys on a hash. The readable
     * address is stored beside it.
     */
    public static function key_for( $email ) {
        return hash( 'sha256', self::normalize( $email ) );
    }

    /* =====================================================================
     * Following
     * ================================================================== */

    /**
     * Take a submission. Writes a pending row and sends the confirmation.
     *
     * THE CALLER IS NOT TOLD WHICH OF THESE HAPPENED, and nor is the person.
     * See ajax_follow().
     *
     * @param int    $term_id Series term.
     * @param string $email   Address as typed.
     * @return bool Whether a confirmation was sent. For tests and for nothing else.
     */
    public static function follow( $term_id, $email ) {
        global $wpdb;

        $term_id = (int) $term_id;
        $email   = self::normalize( $email );

        if ( '' === $email || ! is_email( $email ) ) {
            return false;
        }
        $term = SFAF_Series::get( $term_id );
        if ( ! $term ) {
            return false;
        }

        // Anything unconfirmed and out of time goes now, so the lookup below
        // cannot find a dead row and refuse to reissue against it.
        self::purge_expired();

        $key      = self::key_for( $email );
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM " . self::table() . " WHERE term_id = %d AND email_key = %s LIMIT 1",
            $term_id,
            $key
        ) );

        // Already following. Nothing is written and nothing is sent: a second
        // confirmation email to somebody already confirmed is mail they did not
        // ask for, from a form anybody can post to.
        if ( $existing && 'active' === (string) $existing->status ) {
            return false;
        }

        $confirm = SFAF_Reminders::new_token();
        $now     = current_time( 'mysql' );

        if ( $existing ) {
            /*
             * A NEW LINK REPLACES THE OLD ONE. Somebody who asked twice because
             * the first email did not arrive gets one live link, not two, and
             * the clock restarts from the ask they actually remember making.
             */
            $wpdb->update(
                self::table(),
                array( 'confirm_token' => $confirm, 'created_at' => $now ),
                array( 'id' => (int) $existing->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            $row_id = (int) $existing->id;
        } else {
            $ok = $wpdb->insert( self::table(), array(
                'term_id'       => $term_id,
                'email'         => $email,
                'email_key'     => $key,
                'status'        => 'pending',
                'token'         => SFAF_Reminders::new_token(),
                'confirm_token' => $confirm,
                'created_at'    => $now,
            ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );

            if ( ! $ok ) {
                return false;
            }
            $row_id = (int) $wpdb->insert_id;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            $row_id
        ) );
        if ( ! $row ) {
            return false;
        }

        return self::send_confirmation( $term, $row );
    }

    /**
     * Confirm a pending record.
     *
     * @param string $token
     * @return object|null The row, now active.
     */
    public static function confirm( $token ) {
        global $wpdb;

        $row = self::resolve_confirm( $token );
        if ( ! $row ) {
            return null;
        }

        $wpdb->update(
            self::table(),
            array( 'status' => 'active', 'confirmed_at' => current_time( 'mysql' ), 'confirm_token' => '' ),
            array( 'id' => (int) $row->id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        $row->status       = 'active';
        $row->confirm_token = '';
        return $row;
    }

    /**
     * Stop following.
     *
     * THE ROW GOES, and this is the one place this file differs from every
     * other record in the plugin. A registration is kept at status 'cancelled'
     * because attendance is history and the row is the only evidence a person
     * ever signed up. A follow is not history: it is a standing instruction to
     * send mail, and withdrawing it means there is nothing left worth holding
     * an address for. Somebody who follows again starts from the confirmation.
     *
     * @param string $token
     * @return object|null The row as it was, for the page that says so.
     */
    public static function stop( $token ) {
        global $wpdb;

        $row = self::resolve_stop( $token );
        if ( ! $row ) {
            return null;
        }

        $wpdb->delete( self::table(), array( 'id' => (int) $row->id ), array( '%d' ) );
        return $row;
    }

    /**
     * Which pending record a confirm token belongs to.
     *
     * AN EMPTY TOKEN IS REJECTED BEFORE ANY QUERY, for the reason the cancel
     * link's resolver gives: a blank value in a URL must never match a column
     * that any row could hold blank. A confirmed row has its confirm token
     * cleared, so '' would otherwise match every follower on the site.
     *
     * THE AGE IS CHECKED HERE TOO, not only by the sweep. A row is inert the
     * moment it is out of time, whether or not anything has got round to
     * deleting it.
     */
    private static function resolve_confirm( $token ) {
        global $wpdb;

        $token = trim( (string) $token );
        if ( '' === $token ) {
            return null;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE confirm_token = %s AND confirm_token <> '' AND status = 'pending' LIMIT 1",
            $token
        ) );
        if ( ! $row ) {
            return null;
        }

        if ( strtotime( (string) $row->created_at ) < self::expiry_cutoff() ) {
            return null;
        }
        return $row;
    }

    /** Which record an unsubscribe token belongs to. Pending or active. */
    private static function resolve_stop( $token ) {
        global $wpdb;

        $token = trim( (string) $token );
        if ( '' === $token ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE token = %s AND token <> '' LIMIT 1",
            $token
        ) );
    }

    /** The moment before which a pending record is out of time. */
    private static function expiry_cutoff() {
        return strtotime( current_time( 'mysql' ) ) - ( self::CONFIRM_DAYS * DAY_IN_SECONDS );
    }

    /**
     * Delete every unconfirmed record that is out of time.
     *
     * SWEPT ON WRITE, NOT ON A SCHEDULE. Unconfirmed rows can only accumulate
     * while submissions are arriving, and a submission is exactly when this
     * runs, so the pile is bounded by its own inflow. That is the same bargain
     * SFAF_Request makes by holding its tokens in transients: no cron task, no
     * entry in SFAF_Cron::tasks() to fail quietly, and nothing to explain in the
     * run log for a table that on this site will hold tens of rows.
     */
    public static function purge_expired() {
        global $wpdb;

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE status = 'pending' AND created_at < %s",
            gmdate( 'Y-m-d H:i:s', self::expiry_cutoff() )
        ) );
    }

    /* =====================================================================
     * Reading
     * ================================================================== */

    /**
     * Everybody following a series.
     *
     * PENDING RECORDS ARE NOT HERE, and that is the whole of what pending
     * means: somebody who has not confirmed is in no audience, so there is no
     * caller anywhere that has to remember to filter them out.
     *
     * NOTHING CALLS THIS YET, ON PURPOSE, AND IT IS NOT DEAD CODE. Part 2 is
     * the organizer's screen that announces new dates, and this is the audience
     * it reads. Nothing in this release sends a follower anything.
     *
     * @param int $term_id
     * @return object[]
     */
    public static function active_followers( $term_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE term_id = %d AND status = 'active' ORDER BY id ASC",
            (int) $term_id
        ) );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * How many people are following a series.
     *
     * @param int $term_id
     * @return int
     */
    public static function count_followers( $term_id ) {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE term_id = %d AND status = 'active'",
            (int) $term_id
        ) );
    }

    /* =====================================================================
     * The form
     * ================================================================== */

    /**
     * Take the address the dialog posted.
     *
     * THE SAME ANSWER WHATEVER HAPPENED, which is the staff request form's rule
     * and is copied here with its reasoning intact. Sent, already following, or
     * refused by a rate limit: all three render the identical sentence. A
     * different one for any of them answers "is that address known here" for
     * anybody who cares to ask, which on a calendar carrying HIV testing and
     * trans health programmes is not a question a public form should answer.
     *
     * IT IS ALWAYS A SUCCESS, TOO. An error for the rate-limited case would be
     * the same disclosure wearing a different colour.
     */
    public function ajax_follow() {
        check_ajax_referer( 'uc_nonce', 'nonce' );

        $term_id = isset( $_POST['series_id'] ) ? intval( $_POST['series_id'] ) : 0;
        $email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

        $said = array(
            'success' => true,
            'message' => "Almost there! Check your email to confirm and you're all set.",
        );

        // A malformed address is the one thing said plainly. It reveals nothing
        // about anybody, and somebody who mistyped their own needs to know
        // rather than waiting for a link that was never going to arrive.
        if ( '' === $email || ! is_email( $email ) ) {
            wp_send_json( array( 'success' => false, 'message' => 'Please enter a valid email address.' ) );
        }

        /*
         * TWO SUBJECTS, AND NEITHER CLOSES IT ALONE. An address limit alone is
         * defeated by using a different one each time; a client limit alone
         * punishes a whole office behind one address for one person's typing.
         * Both are required, as on both public forms.
         */
        if ( SFAF_Submissions::allow( 'follow_addr', $email, 3, HOUR_IN_SECONDS )
            && SFAF_Submissions::allow( 'follow_ip', SFAF_Submissions::client(), 10, HOUR_IN_SECONDS ) ) {
            self::follow( $term_id, $email );
        }

        wp_send_json( $said );
    }

    /* =====================================================================
     * The links in the email
     * ================================================================== */

    /** Where a confirm link points. */
    public static function confirm_url( $token ) {
        return add_query_arg( self::VAR_CONFIRM, $token, home_url( '/' ) );
    }

    /** Where an unsubscribe link points. */
    public static function stop_url( $token ) {
        return add_query_arg( self::VAR_STOP, $token, home_url( '/' ) );
    }

    /**
     * Handle both links.
     *
     * A GET NEVER CHANGES ANYTHING, WHICH DIRECTION IT GOES IN.
     *
     * The cancel link already works this way and gives the reason: mail
     * clients, security scanners and link previewers fetch the URLs in an email
     * without a person ever touching them. For the unsubscribe link that would
     * quietly drop somebody off a list they wanted to be on. For the confirm
     * link it is worse than it looks: a scanner that follows it turns a record
     * the person never answered into an active one, which is precisely the
     * thing confirming exists to prove. So both open a page that asks, and the
     * POST from that page is what acts.
     */
    public function maybe_handle_link() {
        $confirm = isset( $_GET[ self::VAR_CONFIRM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::VAR_CONFIRM ] ) ) : '';
        $stop    = isset( $_GET[ self::VAR_STOP ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::VAR_STOP ] ) ) : '';

        if ( '' === $confirm && '' === $stop ) {
            return;
        }

        nocache_headers();

        if ( '' !== $confirm ) {
            $this->handle_confirm( $confirm );
            return;
        }
        $this->handle_stop( $stop );
    }

    /** The confirm page: ask, then act on the post. */
    private function handle_confirm( $token ) {
        $row = self::resolve_confirm( $token );
        if ( ! $row ) {
            self::page(
                'That link is not valid',
                '<p>This link has expired or was not recognized. Follow the series again from any of its event pages and a new link will arrive.</p>'
            );
        }

        $term = SFAF_Series::get( (int) $row->term_id );
        $name = $term ? $term->name : 'this series';

        if ( self::posted( $token ) ) {
            $done = self::confirm( $token );
            if ( ! $done ) {
                self::page( 'That link is not valid', '<p>This link has expired or was not recognized.</p>' );
            }
            self::page(
                "You're following " . $name,
                "<p>We'll email you when a new date is added.</p>"
                . '<p class="uc-notice-fine"><a href="' . esc_url( self::stop_url( (string) $done->token ) ) . '">Stop these emails</a></p>'
            );
        }

        /*
         * THE SAME FOUR LINES THE DIALOG USES, because this is the same offer
         * being made at the second half of one flow. Somebody arriving here
         * from the email should recognize what they pressed on the event page.
         */
        self::page(
            'Follow ' . $name . '?',
            '<p>Be the first to know when new dates are added.</p>'
            . self::ask( $token, 'Yes, follow this series', 'One email when dates are added. Stop any time.' )
        );
    }

    /** The unsubscribe page: ask, then act on the post. */
    private function handle_stop( $token ) {
        $row = self::resolve_stop( $token );
        if ( ! $row ) {
            self::page(
                'That link is not valid',
                '<p>This link was not recognized. It may already have been used, in which case nothing further is being sent to that address about this series.</p>'
            );
        }

        $term = SFAF_Series::get( (int) $row->term_id );
        $name = $term ? $term->name : 'this series';

        if ( self::posted( $token ) ) {
            self::stop( $token );
            self::page(
                'Stopped',
                '<p>You will not hear about new dates for ' . esc_html( $name ) . '.</p>'
            );
        }

        self::page(
            'Stop emails about ' . $name . '?',
            '<p>You will not hear when a new date is added.</p>'
            . self::ask( $token, 'Yes, stop these emails' )
        );
    }

    /* =====================================================================
     * The page these links open
     * ================================================================== */

    /**
     * A public page, styled like the event pages somebody came from.
     *
     * ONE RENDERER, SHARED WITH THE CANCEL LINK since 3.55.0. It used to be
     * written out here, and the copy in SFAF_Reminders was still going out
     * through wp_die() with no stylesheet three releases later, which is what
     * two copies of a document shape buys. sfaf_notice_page() carries the whole
     * explanation, including why no enqueue could ever have reached this page.
     *
     * This does not return.
     */
    private static function page( $title, $html ) {
        sfaf_notice_page( $title, $html );
    }

    /**
     * Was this page posted back with the token it was drawn with?
     *
     * hash_equals rather than ===, so the comparison of two secrets is not
     * timed, which is the same care resolve_token() takes on the cancel link.
     */
    private static function posted( $token ) {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            return false;
        }
        if ( ! isset( $_POST['uc_follow_token'] ) ) {
            return false;
        }
        return hash_equals( (string) $token, sanitize_text_field( wp_unslash( $_POST['uc_follow_token'] ) ) );
    }

    /**
     * The one-button form both pages draw. The POST is what acts.
     *
     * @param string $token
     * @param string $label
     * @param string $fine  Optional line under the button.
     */
    private static function ask( $token, $label, $fine = '' ) {
        $out = '<form method="post" class="uc-notice-form">'
            . '<input type="hidden" name="uc_follow_token" value="' . esc_attr( $token ) . '" />'
            . '<button type="submit" class="uc-notice-btn">' . esc_html( $label ) . '</button>';
        if ( '' !== $fine ) {
            $out .= '<p class="uc-notice-fine">' . esc_html( $fine ) . '</p>';
        }
        return $out . '</form>';
    }

    /* =====================================================================
     * The confirmation email
     * ================================================================== */

    /**
     * Ask the address to confirm.
     *
     * Built by SFAF_Email and handed to wp_mail() like every other message this
     * plugin sends. No transport of its own.
     *
     * @param WP_Term $term
     * @param object  $row
     * @return bool
     */
    private static function send_confirmation( $term, $row ) {
        $name    = $term->name;
        $confirm = self::confirm_url( (string) $row->confirm_token );
        $stop    = self::stop_url( (string) $row->token );
        $days    = (int) self::CONFIRM_DAYS;

        /*
         * THE SERIES NAME APPEARS ONCE, IN THE HEADING. The body used to say it
         * again, which on a name like "Programa Latino: Grupo de Apoyo" is most
         * of two consecutive lines. The heading has already answered "which
         * one"; the body's job is what will arrive.
         *
         * PLAINER THAN THE PAGE, ON PURPOSE. This calendar carries HIV,
         * substance use and trans health programming, and the subject line is
         * the part that shows in a preview pane or a shared inbox. It states
         * what the message is and stops. "Almost there!" is in the body, where
         * somebody has already chosen to open it.
         */
        $subject = "Confirm you're following " . $name;

        $html  = SFAF_Email::heading( $subject );
        // Raw, not escaped: SFAF_Email::para() escapes what it is given, and a
        // series called "Women & Trans Night" would otherwise arrive as
        // "Women &amp;amp; Trans Night".
        $html .= SFAF_Email::para(
            "Almost there! Confirm below and we'll email you whenever a new date is added."
        );
        $html .= SFAF_Email::button_row( array( SFAF_Email::button( $confirm, 'Yes, follow this series' ) ) );
        $html .= SFAF_Email::small_para( 'This link works for the next ' . $days . ' days.' );
        $html .= SFAF_Email::rule();
        /*
         * THE UNSUBSCRIBE LINK STAYS IN THIS FIRST MESSAGE. It is the guarantee
         * 3.53.0 was built around: a follower is never without a route out, and
         * the mechanism this replaced minted a token and delivered it to nobody.
         * The sentence around it got shorter; the link did not move.
         */
        $html .= SFAF_Email::small_para(
            "Didn't ask for this? Ignore it and nothing happens. You can "
            . '<a href="' . esc_url( $stop ) . '" style="color:' . SFAF_Email::C_TEAL . ';">stop these emails</a> any time.'
        );

        $text  = $subject . "\n\n";
        $text .= "Almost there! Confirm below and we'll email you whenever a new date is added.\n\n";
        $text .= $confirm . "\n\n";
        $text .= 'This link works for the next ' . $days . " days.\n\n";
        $text .= "Didn't ask for this? Ignore it and nothing happens.\n";
        $text .= 'You can stop these emails any time: ' . $stop . "\n";
        $text .= "\n" . SFAF_Email::POSTAL;

        return SFAF_Email::send(
            (string) $row->email,
            $subject,
            SFAF_Email::shell( $subject, $html ),
            $text
        );
    }
}
