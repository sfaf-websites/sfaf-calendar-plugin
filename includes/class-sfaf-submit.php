<?php
/**
 * The public community submission form.
 *
 * WHAT IT IS FOR. A campaign runs a calendar and wants the public to propose
 * events for it. The first is Cycle to Zero, where people riding, hosting or
 * fundraising suggest their own events for the ride's calendar. It replaces a
 * Google Form, and the events it produces land in the same pending queue as
 * everything else.
 *
 * IT IS NOT THE STAFF REQUEST FORM WITH THE EMAIL CHECK REMOVED.
 * ---------------------------------------------------------------------------
 * `SFAF_Request` is reached by a link sent to an sfaf.org mailbox: the mailbox
 * is the gate, the submitter is a colleague, and the questions assume somebody
 * who knows what a series and a venue are. This has no gate at all. The URL is
 * public and printed on a campaign site, the submitter is a stranger, and the
 * questions are the ones a stranger can answer. The two share their machinery
 * through `SFAF_Submissions` and share nothing else.
 *
 * THE URL NAMES A SERIES, AND THAT IS THE WHOLE OF THE CONFIGURATION.
 * ---------------------------------------------------------------------------
 *     /?uc_event_submit=cycle-to-zero
 *
 * The slug is a series slug. The series record already carries a name and an
 * image, so pointing the form at one gives it a heading, a banner and the
 * series every submission joins, with nothing else to set up. A second campaign
 * next year is a series and a URL, not another build, and there is deliberately
 * NO separate banner setting: a second place to put the picture is a second
 * place for it to be wrong.
 *
 * AN UNKNOWN SLUG IS NOT AN ERROR PAGE THAT SAYS WHAT EXISTS. It says the link
 * is not right and stops. Listing the series would turn this into a directory
 * of every campaign the calendar knows about, which is more than a submission
 * form needs to expose.
 *
 * NO REST ROUTE, deliberately, and for the same reason SFAF_Request has none:
 * `is_embed_request()` matches an exact string, so a new route under our
 * namespace would fail CORS from sfaf.org. This hangs off a front-end query var
 * on `template_redirect`, which needs no rewrite rule and no flush.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Submit {

    /** The query var that opens this form. Its value is a series slug. */
    const QUERY_VAR = 'uc_event_submit';

    /*
     * WHAT A SUBMISSION RECORDS.
     *
     * The submitter's own name and address reuse the request form's keys, so
     * the pending queue's "Submitted by" column reads both kinds with no second
     * branch. What kind it is comes from SFAF_Submissions::META_KIND, which is
     * asked explicitly rather than inferred from which fields are filled in.
     */
    const META_IMAGE   = '_uc_submitted_image';
    const META_SERIES  = '_uc_submitted_series';

    /* Fields the event page shows, which the staff form does not collect. */
    const META_COST    = '_uc_cost';
    const META_AGE     = '_uc_age_restriction';
    const META_CONTACT = '_uc_public_contact';
    const META_RSVP_URL = '_uc_rsvp_url';

    public static function register() {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
    }

    /**
     * The address of one campaign's form.
     *
     * @param string $slug
     * @return string
     */
    public static function url( $slug ) {
        return add_query_arg( self::QUERY_VAR, rawurlencode( (string) $slug ), home_url( '/' ) );
    }

    /* =====================================================================
     * The front controller
     * ================================================================== */

    public static function maybe_render() {
        if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
            return;
        }

        nocache_headers();

        /*
         * ASKING FOR NOTHING FAILS SAFELY. `?uc_event_submit=` with no value,
         * or a value that is not a series, gets the same short page: the link
         * is not right. No form is drawn, so there is nothing to post to.
         */
        $slug   = sanitize_title( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
        $series = ( '' !== $slug ) ? get_term_by( 'slug', $slug, SFAF_Series::TAXONOMY ) : false;

        if ( ! $series || is_wp_error( $series ) ) {
            self::render_unknown();
            exit;
        }

        $posted = ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] );
        $action = isset( $_POST['uc_submit_action'] ) ? sanitize_key( wp_unslash( $_POST['uc_submit_action'] ) ) : '';

        if ( $posted && 'submit_event' === $action ) {
            self::handle_submission( $series );
            exit;
        }

        self::render_form( $series, array(), array() );
        exit;
    }

    /* =====================================================================
     * Validation
     * ================================================================== */

    /**
     * Everything from the browser, checked and reduced.
     *
     * NOTHING IS TRUSTED AND NOTHING IS OPTIONAL-BY-ACCIDENT. Every value is
     * either required and refused when empty, or optional and stored only when
     * it survives its own check. There is no branch that writes a raw value.
     *
     * SUBMITTING NOTHING FAILS SAFELY: an empty POST produces a full set of
     * errors and no event, because every required field is checked for being
     * empty before it is checked for anything else.
     *
     * @param array $post
     * @return array{clean:array,errors:array}
     */
    public static function validate( $post ) {
        $clean  = array();
        $errors = array();

        $line = function ( $key, $max ) use ( $post ) {
            return SFAF_Submissions::line(
                isset( $post[ $key ] ) ? wp_unslash( $post[ $key ] ) : '',
                $max
            );
        };

        /* ---- Who is submitting. Internal, never shown publicly. ---- */
        $clean['submitter_name'] = $line( 'submitter_name', 120 );
        if ( '' === $clean['submitter_name'] ) {
            $errors['submitter_name'] = 'Tell us your name, so we can get back to you.';
        }

        $email = isset( $post['submitter_email'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $post['submitter_email'] ) ) ) ) : '';
        $clean['submitter_email'] = is_email( $email ) ? $email : '';
        if ( '' === $clean['submitter_email'] ) {
            $errors['submitter_email'] = 'Give an email address we can reach you at.';
        }

        /* ---- The event. ---- */
        $clean['title'] = $line( 'title', 200 );
        if ( '' === $clean['title'] ) {
            $errors['title'] = 'The event needs a name.';
        }

        $clean['description'] = SFAF_Submissions::prose(
            isset( $post['description'] ) ? wp_unslash( $post['description'] ) : ''
        );
        $clean['description'] = SFAF_Request::cap( $clean['description'], 8000 );
        if ( '' === trim( wp_strip_all_tags( $clean['description'] ) ) ) {
            $errors['description'] = 'Write a description. An event with none is blank on the calendar.';
        }

        /* ---- When. ---- */
        $clean['date'] = SFAF_Request::clean_date( isset( $post['date'] ) ? $post['date'] : '' );
        if ( '' === $clean['date'] ) {
            $errors['date'] = 'Give the date, as a real calendar date.';
        } elseif ( $clean['date'] < current_time( 'Y-m-d' ) ) {
            $errors['date'] = 'That date has passed. Give the date the event actually happens.';
        }

        $clean['start'] = SFAF_Request::clean_time( isset( $post['start_time'] ) ? $post['start_time'] : '' );
        $clean['end']   = SFAF_Request::clean_time( isset( $post['end_time'] ) ? $post['end_time'] : '' );
        if ( '' === $clean['start'] ) {
            $errors['start_time'] = 'Give a start time.';
        }
        if ( '' === $clean['end'] ) {
            $errors['end_time'] = 'Give an end time.';
        }
        if ( '' !== $clean['start'] && '' !== $clean['end'] && $clean['end'] <= $clean['start'] ) {
            $errors['end_time'] = 'The end time needs to be after the start time.';
        }

        /* ---- Where. Free text: a stranger does not know our venue list. ---- */
        $clean['location'] = $line( 'location', 250 );
        if ( '' === $clean['location'] ) {
            $errors['location'] = 'Say where it happens, so people can get there.';
        }

        /* ---- Optional detail. ---- */
        $clean['cost'] = $line( 'cost', 120 );
        $clean['age']  = $line( 'age_restriction', 120 );

        $clean['rsvp_url'] = SFAF_Submissions::url( isset( $post['rsvp_url'] ) ? wp_unslash( $post['rsvp_url'] ) : '' );
        if ( '' === $clean['rsvp_url'] && ! empty( $post['rsvp_url'] ) ) {
            $errors['rsvp_url'] = 'That does not look like a web address. It needs to start with http:// or https://.';
        }

        /* ---- Shown publicly, and distinct from the submitter's details. ---- */
        $clean['contact'] = $line( 'contact', 200 );
        if ( '' === $clean['contact'] ) {
            $errors['contact'] = 'Give the contact people should use about this event. It goes on the listing.';
        }

        $clean['notes'] = SFAF_Submissions::line(
            isset( $post['notes'] ) ? wp_unslash( $post['notes'] ) : '',
            2000
        );

        return array( 'clean' => $clean, 'errors' => $errors );
    }

    /* =====================================================================
     * Submission
     * ================================================================== */

    /**
     * @param WP_Term $series
     */
    private static function handle_submission( $series ) {
        /* The honeypot fails exactly as a success looks. */
        if ( SFAF_Submissions::trapped() ) {
            self::render_done( $series, '' );
            return;
        }

        /*
         * RATE LIMITED ON THE CLIENT AND ON THE CAMPAIGN. The client limit is
         * what stops one person filling the queue; the campaign limit is what
         * stops a distributed run filling ONE calendar, which is the thing the
         * organisers would actually notice. Both are counters that expire.
         */
        if ( ! SFAF_Submissions::allow( 'submit_ip', SFAF_Submissions::client(), 5, HOUR_IN_SECONDS )
            || ! SFAF_Submissions::allow( 'submit_series', (string) $series->term_id, 60, HOUR_IN_SECONDS ) ) {
            self::render_form( $series, array(), array(
                'form' => 'That is several submissions in a short time. Give it a few minutes, then try again.',
            ) );
            return;
        }

        /*
         * TURNSTILE BEFORE THE FIELDS AND BEFORE THE UPLOAD. There is no reason
         * to validate, or to write a file to disk, for a request that has
         * already failed the check that decides whether a person sent it.
         */
        if ( ! SFAF_Turnstile::verify() ) {
            self::render_form( $series, self::validate( $_POST )['clean'], array(
                'form' => 'That did not pass the robot check. Tick the box again and resend.',
            ) );
            return;
        }

        $checked = self::validate( $_POST );

        $upload = SFAF_Uploads::store( 'uc_image', function () {
            return SFAF_Submissions::allow( 'upload_ip', SFAF_Submissions::client(), 10, HOUR_IN_SECONDS );
        } );
        if ( '' !== $upload['error'] ) {
            $checked['errors']['uc_image'] = $upload['error'];
        }

        if ( ! empty( $checked['errors'] ) ) {
            /*
             * A REJECTED SUBMISSION THAT UPLOADED A FILE DOES NOT KEEP IT. The
             * form cannot put a file back into a file input, so the visitor has
             * to choose it again anyway, and keeping the first one would leave
             * an orphan on disk for every failed attempt.
             */
            if ( $upload['id'] ) {
                wp_delete_attachment( $upload['id'], true );
            }
            self::render_form( $series, $checked['clean'], $checked['errors'] );
            return;
        }

        $event_id = self::create_event( $checked['clean'], $series, (int) $upload['id'] );
        if ( ! $event_id ) {
            if ( $upload['id'] ) {
                wp_delete_attachment( $upload['id'], true );
            }
            self::render_form( $series, $checked['clean'], array(
                'form' => 'Something went wrong saving that. Try once more, and if it happens again let the organisers know.',
            ) );
            return;
        }

        self::notify_admins( $event_id, $checked['clean'], $series );
        self::confirm_to_submitter( $event_id, $checked['clean'], $series );

        self::render_done( $series, $checked['clean']['title'] );
    }

    /**
     * Write the submission as a pending event.
     *
     * PENDING, NOT PUBLISH, AND THE STATUS IS NAMED HERE RATHER THAN TAKEN FROM
     * THE FORM. Nothing a stranger sends appears on a calendar until somebody
     * approves it, and there is no value any field could hold that changes that.
     *
     * post_author stays 0: nobody was logged in, and attributing this to a user
     * id would put a name against work they did not do.
     *
     * THE SUBMITTED IMAGE IS NOT THE FEATURED IMAGE. It is recorded as its own
     * meta so the pending row can show what was sent, and set_post_thumbnail()
     * is deliberately NOT called: the published image is chosen at approval from
     * the calendar folder, and nothing points at the submissions folder
     * permanently. Emptying that folder leaves this meta pointing at an
     * attachment that has gone, which SFAF_Uploads::url() answers with '' and
     * the row simply shows no thumbnail.
     *
     * @param array   $c
     * @param WP_Term $series
     * @param int     $image_id
     * @return int
     */
    private static function create_event( $c, $series, $image_id ) {
        $event_id = wp_insert_post( array(
            'post_type'    => 'uc_event',
            'post_status'  => 'pending',
            'post_title'   => $c['title'],
            'post_content' => $c['description'],
            'post_author'  => 0,
        ), true );

        if ( is_wp_error( $event_id ) || ! $event_id ) {
            return 0;
        }
        $event_id = (int) $event_id;

        update_post_meta( $event_id, '_uc_event_date', $c['date'] );
        update_post_meta( $event_id, '_uc_start_time', $c['start'] );
        update_post_meta( $event_id, '_uc_end_time', $c['end'] );
        update_post_meta( $event_id, '_uc_location', $c['location'] );

        SFAF_Series::set_for_event( $event_id, (int) $series->term_id );
        update_post_meta( $event_id, self::META_SERIES, (int) $series->term_id );

        /* Optional, and written only when there is something to write, so a
         * blank one is absent rather than an empty string the template would
         * then have to test for a second way. */
        foreach ( array(
            self::META_COST     => $c['cost'],
            self::META_AGE      => $c['age'],
            self::META_CONTACT  => $c['contact'],
            self::META_RSVP_URL => $c['rsvp_url'],
        ) as $key => $value ) {
            if ( '' !== $value ) {
                update_post_meta( $event_id, $key, $value );
            }
        }

        if ( $image_id ) {
            update_post_meta( $event_id, self::META_IMAGE, $image_id );
        }

        update_post_meta( $event_id, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_COMMUNITY );
        update_post_meta( $event_id, SFAF_Request::META_NAME, $c['submitter_name'] );
        update_post_meta( $event_id, SFAF_Request::META_EMAIL, $c['submitter_email'] );
        update_post_meta( $event_id, SFAF_Request::META_AT, current_time( 'mysql' ) );
        if ( '' !== $c['notes'] ) {
            update_post_meta( $event_id, SFAF_Request::META_NOTES, $c['notes'] );
        }

        return $event_id;
    }

    /* =====================================================================
     * Who is told
     * ================================================================== */

    /**
     * One email per submission, to the people who approve them.
     *
     * THE SAME AUDIENCE AS A STAFF REQUEST, resolved by the same method, so
     * there is one answer to "who approves things" and adding somebody is still
     * a matter of giving them Admin on the Users screen.
     *
     * @param int     $event_id
     * @param array   $c
     * @param WP_Term $series
     */
    private static function notify_admins( $event_id, $c, $series ) {
        $admins = SFAF_Request::admin_users();
        if ( empty( $admins ) ) {
            return;
        }

        $queue = SFAF_Portal::link( 'pending' );
        $edit  = SFAF_Portal::link( 'events/edit/' . $event_id );

        $rows = array(
            'Event'    => $c['title'],
            'Calendar' => $series->name,
            'Date'     => sfaf_ap_date( $c['date'], 'full' ),
            'Time'     => sfaf_ap_time_range( $c['start'], $c['end'] ),
            'Where'    => $c['location'],
        );
        if ( '' !== $c['cost'] ) {
            $rows['Cost'] = $c['cost'];
        }
        if ( '' !== $c['age'] ) {
            $rows['Ages'] = $c['age'];
        }
        $rows['Contact shown publicly'] = $c['contact'];
        if ( '' !== $c['rsvp_url'] ) {
            $rows['Registration link'] = $c['rsvp_url'];
        }
        $rows['Submitted by'] = $c['submitter_name'] . ' (' . $c['submitter_email'] . ')';

        $html = SFAF_Email::heading( 'Somebody submitted an event' )
            . SFAF_Email::para( 'A member of the public submitted an event to the ' . $series->name . ' calendar. It is in the pending queue and nobody can see it yet.' )
            . SFAF_Email::details( $rows )
            . SFAF_Email::button_row( array( SFAF_Email::button( $edit, 'Open the submission' ) ) )
            . SFAF_Email::link_para( $queue, 'See everything waiting' );

        $text = 'A member of the public submitted an event to the ' . $series->name . " calendar.\n\n"
            . $c['title'] . "\n" . sfaf_ap_date( $c['date'], 'full' ) . "\n"
            . sfaf_ap_time_range( $c['start'], $c['end'] ) . "\n"
            . $c['location'] . "\n\n"
            . 'Submitted by: ' . $c['submitter_name'] . ' (' . $c['submitter_email'] . ")\n\n"
            . 'Open it: ' . $edit . "\nThe queue: " . $queue;

        $subject = 'Event submitted: ' . $c['title'];
        $shell   = SFAF_Email::shell( 'An event was submitted', $html );

        /*
         * ONE MESSAGE EACH, and the SUBMITTER IS THE REPLY-TO, so answering a
         * question about the submission goes to the person who sent it. That
         * address is internal to this message; it is never on the listing.
         */
        foreach ( $admins as $user ) {
            SFAF_Email::send( $user->user_email, $subject, $shell, $text, $c['submitter_email'] );
        }
    }

    /**
     * A copy for the person who sent it, and nothing after that.
     *
     * @param int     $event_id
     * @param array   $c
     * @param WP_Term $series
     */
    private static function confirm_to_submitter( $event_id, $c, $series ) {
        if ( '' === $c['submitter_email'] ) {
            return;
        }

        $rows = array(
            'Event'    => $c['title'],
            'Calendar' => $series->name,
            'Date'     => sfaf_ap_date( $c['date'], 'full' ),
            'Time'     => sfaf_ap_time_range( $c['start'], $c['end'] ),
            'Where'    => $c['location'],
        );
        if ( '' !== $c['cost'] ) {
            $rows['Cost'] = $c['cost'];
        }
        if ( '' !== $c['age'] ) {
            $rows['Ages'] = $c['age'];
        }
        $rows['Contact on the listing'] = $c['contact'];
        if ( '' !== $c['rsvp_url'] ) {
            $rows['Registration link'] = $c['rsvp_url'];
        }

        $html = SFAF_Email::heading( 'Thanks, that is with the organisers' )
            . SFAF_Email::para( 'Here is what you sent to the ' . $series->name . ' calendar. Somebody reads every submission before it goes up, and will email you if anything needs sorting out.' )
            . SFAF_Email::details( $rows )
            . SFAF_Email::rule()
            . SFAF_Email::small_para( 'Your name and email address are not shown on the calendar. You will not get another message about this automatically.' );

        $text = "Thanks, that is with the organisers.\n\n"
            . $c['title'] . "\n" . sfaf_ap_date( $c['date'], 'full' ) . "\n"
            . sfaf_ap_time_range( $c['start'], $c['end'] ) . "\n"
            . $c['location'] . "\n\n"
            . 'Somebody reads every submission before it goes on the calendar. '
            . 'Your name and email are not shown there.';

        SFAF_Email::send(
            $c['submitter_email'],
            'We have your event: ' . $c['title'],
            SFAF_Email::shell( 'Your event submission', $html ),
            $text
        );
    }

    /* =====================================================================
     * Screens
     * ================================================================== */

    private static function render_unknown() {
        SFAF_Submissions::page_open( 'That link is not right' );
        ?>
        <div class="uc-request-card">
            <h1>That link is not right</h1>
            <p>This address does not open a submission form. Check the link you were given, or go back to the campaign page you came from and follow it again.</p>
        </div>
        <?php
        SFAF_Submissions::page_close();
    }

    /**
     * @param WP_Term $series
     * @param string  $title
     */
    private static function render_done( $series, $title ) {
        SFAF_Submissions::page_open( 'Event submitted' );
        ?>
        <div class="uc-request-card">
            <?php self::banner( $series ); ?>
            <h1>Thanks, that is with the organisers</h1>
            <?php if ( '' !== $title ) : ?>
                <p><strong><?php echo esc_html( $title ); ?></strong> has gone to the <?php echo esc_html( $series->name ); ?> team, and we have emailed you a copy of what you sent.</p>
            <?php else : ?>
                <p>Your event has gone to the <?php echo esc_html( $series->name ); ?> team.</p>
            <?php endif; ?>
            <p class="uc-hint">
                Somebody reads every submission before it goes on the calendar, so it will not appear straight away. If something needs sorting out they will email you.
            </p>
            <p><a class="uc-btn" href="<?php echo esc_url( self::url( $series->slug ) ); ?>">Submit another event</a></p>
        </div>
        <?php
        SFAF_Submissions::page_close();
    }

    /**
     * The series image, across the top.
     *
     * NO SEPARATE BANNER SETTING. A series already carries a picture and this
     * is it, so a campaign that changes its artwork changes it in one place and
     * the form follows.
     *
     * @param WP_Term $series
     */
    private static function banner( $series ) {
        $src = SFAF_Series::image_url( (int) $series->term_id, 'large' );
        if ( '' === $src ) {
            return;
        }
        ?>
        <div class="uc-submit-banner">
            <img src="<?php echo esc_url( $src ); ?>" alt="" />
        </div>
        <?php
    }

    /**
     * The form.
     *
     * @param WP_Term $series
     * @param array   $c      Values to put back after a rejected submission.
     * @param array   $errors field => message.
     */
    private static function render_form( $series, $c, $errors ) {
        $v = function ( $key, $fallback = '' ) use ( $c ) {
            return isset( $c[ $key ] ) ? $c[ $key ] : $fallback;
        };
        $err = function ( $key ) use ( $errors ) {
            return isset( $errors[ $key ] ) ? $errors[ $key ] : '';
        };

        /*
         * THE EDITOR IS ENQUEUED BEFORE THE PAGE OPENS, because this builds its
         * own document and page_open() prints what has been enqueued by the
         * time it runs. Asking afterwards is asking too late, which is the trap
         * caladmin already documents.
         */
        SFAF_Rich_Text::enqueue();

        SFAF_Submissions::page_open(
            'Submit an event: ' . $series->name,
            array( 'body_class' => 'uc-request-page uc-submit-page', 'editor' => true )
        );
        ?>
        <div class="uc-request-card">
            <?php self::banner( $series ); ?>
            <h1>Submit an event to <?php echo esc_html( $series->name ); ?></h1>
            <p class="uc-hint">
                Tell us about your event and the organisers will look at it before it goes on the calendar.
                Your name and email stay with the organisers and are not shown on the listing.
            </p>

            <?php if ( '' !== $err( 'form' ) ) : ?>
                <p class="uc-field-error" role="alert"><?php echo esc_html( $err( 'form' ) ); ?></p>
            <?php elseif ( ! empty( $errors ) ) : ?>
                <p class="uc-field-error" role="alert">Some of this needs another look. The fields are marked below.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( self::url( $series->slug ) ); ?>" class="uc-form" enctype="multipart/form-data">
                <input type="hidden" name="uc_submit_action" value="submit_event" />
                <?php SFAF_Submissions::honeypot(); ?>

                <fieldset class="uc-field-group">
                    <legend class="uc-field-label">About you</legend>
                    <p class="uc-hint">Kept by the organisers. Never shown on the calendar.</p>
                    <label class="uc-field">
                        <span class="uc-field-label">Your name</span>
                        <input type="text" name="submitter_name" required maxlength="120" value="<?php echo esc_attr( $v( 'submitter_name' ) ); ?>" />
                        <?php SFAF_Submissions::field_error( $err( 'submitter_name' ) ); ?>
                    </label>
                    <label class="uc-field">
                        <span class="uc-field-label">Your email</span>
                        <input type="email" name="submitter_email" required maxlength="200" autocomplete="email" value="<?php echo esc_attr( $v( 'submitter_email' ) ); ?>" />
                        <?php SFAF_Submissions::field_error( $err( 'submitter_email' ) ); ?>
                    </label>
                </fieldset>

                <label class="uc-field">
                    <span class="uc-field-label">Event name</span>
                    <input type="text" name="title" required maxlength="200" value="<?php echo esc_attr( $v( 'title' ) ); ?>" />
                    <?php SFAF_Submissions::field_error( $err( 'title' ) ); ?>
                </label>

                <div class="uc-field">
                    <span class="uc-field-label">Description</span>
                    <?php
                    SFAF_Rich_Text::render(
                        'uc-submit-description',
                        'description',
                        (string) $v( 'description' ),
                        array( 'rows' => 8 )
                    );
                    ?>
                    <span class="uc-hint">What it is, who it is for, and what somebody should expect.</span>
                    <?php SFAF_Submissions::field_error( $err( 'description' ) ); ?>
                </div>

                <label class="uc-field">
                    <span class="uc-field-label">Date</span>
                    <input type="date" name="date" required value="<?php echo esc_attr( $v( 'date' ) ); ?>" />
                    <?php SFAF_Submissions::field_error( $err( 'date' ) ); ?>
                </label>

                <div class="uc-field-row">
                    <label class="uc-field">
                        <span class="uc-field-label">Start</span>
                        <input type="time" name="start_time" required value="<?php echo esc_attr( $v( 'start' ) ); ?>" />
                        <?php SFAF_Submissions::field_error( $err( 'start_time' ) ); ?>
                    </label>
                    <label class="uc-field">
                        <span class="uc-field-label">End</span>
                        <input type="time" name="end_time" required value="<?php echo esc_attr( $v( 'end' ) ); ?>" />
                        <?php SFAF_Submissions::field_error( $err( 'end_time' ) ); ?>
                    </label>
                </div>

                <label class="uc-field">
                    <span class="uc-field-label">Where</span>
                    <input type="text" name="location" required maxlength="250" value="<?php echo esc_attr( $v( 'location' ) ); ?>"
                           placeholder="470 Castro St, San Francisco" />
                    <?php SFAF_Submissions::field_error( $err( 'location' ) ); ?>
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">Cost</span>
                    <input type="text" name="cost" maxlength="120" value="<?php echo esc_attr( $v( 'cost' ) ); ?>"
                           placeholder="Free, $15 at the door, $20 suggested donation" />
                    <span class="uc-hint">Leave this empty and the listing says nothing about cost. No money is taken here.</span>
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">Age restriction</span>
                    <input type="text" name="age_restriction" maxlength="120" value="<?php echo esc_attr( $v( 'age' ) ); ?>"
                           placeholder="All ages, 18+, 21+ after 9pm" />
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">Registration link</span>
                    <input type="url" name="rsvp_url" maxlength="500" value="<?php echo esc_attr( $v( 'rsvp_url' ) ); ?>"
                           placeholder="https://" />
                    <span class="uc-hint">If people sign up somewhere else, put that address here.</span>
                    <?php SFAF_Submissions::field_error( $err( 'rsvp_url' ) ); ?>
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">Contact for the listing</span>
                    <input type="text" name="contact" required maxlength="200" value="<?php echo esc_attr( $v( 'contact' ) ); ?>"
                           placeholder="rides@example.org, or 415 555 0100" />
                    <span class="uc-hint">This one IS shown publicly, for people asking about the event.</span>
                    <?php SFAF_Submissions::field_error( $err( 'contact' ) ); ?>
                </label>

                <?php SFAF_Submissions::image_field( $err( 'uc_image' ) ); ?>

                <label class="uc-field">
                    <span class="uc-field-label">Anything else we should know</span>
                    <textarea name="notes" rows="3" maxlength="2000"><?php echo esc_textarea( $v( 'notes' ) ); ?></textarea>
                    <span class="uc-hint">For the organisers. Not shown on the listing.</span>
                </label>

                <?php SFAF_Turnstile::field(); ?>

                <div class="uc-form-actions uc-form-actions-primary">
                    <p class="uc-form-actions-note">Somebody reads every submission before it goes on the calendar. You will get a copy by email.</p>
                    <button type="submit" class="uc-btn uc-btn-primary">Submit this event</button>
                </div>
            </form>
        </div>
        <?php
        SFAF_Submissions::page_close( array( 'editor' => true ) );
    }
}
