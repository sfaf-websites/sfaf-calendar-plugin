<?php
/**
 * The six per-event emails, who gets them, and whether they are switched on.
 *
 * NONE OF THIS HAS EVER RUN. See the note at the top of SFAF_Email.
 *
 * THE SIX
 * ---------------------------------------------------------------------------
 *   confirmation  to the person, the moment they register
 *   reminder      to everybody registered on the morning of the event, with the
 *                 notification list copied in (sent by SFAF_Reminders)
 *   alert         to the notification list, the moment somebody registers
 *   summary       to the notification list, two hours before the event, listing
 *                 who is coming
 *   cancel_alert  to the notification list, the moment somebody cancels their
 *                 registration
 *   day_before    to the notification list, at 6 am the day before, with the
 *                 number registered and never the names (3.102.0)
 *
 * ONE NOTIFICATION LIST, AND THIS REVERSES A DOCUMENTED DECISION.
 * ---------------------------------------------------------------------------
 * Until 3.25.0 there were two: registrations went to a single address field
 * (_uc_organizer_email) and the reminder copy went to the full picker of
 * people, teams and typed addresses. That split was DELIBERATE, not an
 * oversight. The note on SFAF_Portal::render_notify_box() argued it: "The two
 * are genuinely separate mechanisms and are not merged... Presenting them as
 * one list would be a lie about what the software does."
 *
 * The argument was about the code as it stood, and the code has changed. Both
 * fields answered the same question, "who finds out", and the difference
 * between them was that one had been upgraded and the other had not. A manager
 * had to name the same colleague twice, in two different shapes, and a team
 * could not be told about a registration at all. There is now one list, it is
 * the picker, and everybody on it gets all four staff emails.
 *
 * ALL OR NOTHING PER PERSON, ON PURPOSE. Per-recipient control means a matrix
 * of five toggles times N people, on a screen a manager visits to set up an
 * event, and nobody has asked for it. The event-level switches below turn a
 * whole kind of email off for everybody, which is the case that actually comes
 * up.
 *
 * DEFAULT ON, AND STORED AS THE EXCEPTION.
 * ---------------------------------------------------------------------------
 * All six are on for an event that says nothing, so creating an event is
 * title, date, time, place, category, image, save, and working email. The meta
 * records only what somebody has switched OFF, so a default costs no writes and
 * an event created before any of this existed behaves like one created after.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Notifications {

    /** Which kinds are switched off for an event: array of keys. */
    const OFF_META = '_uc_notify_off';

    /** The claim that stops a second pre-event summary. */
    const SUMMARY_CLAIM_META = '_uc_summary_claimed_at';

    /** How long before the event the summary goes out. */
    const SUMMARY_LEAD_SECONDS = 7200; // two hours

    /** The day-before pass for this event has completed (3.102.0). */
    const DAY_BEFORE_DONE_META = '_uc_day_before_sent_at';

    /** Every kind, and what a manager is turning off when they untick it. */
    public static function kinds() {
        return array(
            'confirmation' => array(
                'label' => 'Confirmation to the person who registers',
                'note'  => 'Sent the moment somebody registers. Carries the date, the time, the place, add-to-calendar links, and a link to cancel.',
            ),
            'alert' => array(
                'label' => 'Alert to your notification list when somebody registers',
                'note'  => 'One email per registration, as it happens.',
            ),
            /*
             * THE SIXTH, ADDED 3.102.0: how many are coming, the day before.
             * The summary two hours out is too late to order food or set out a
             * room. This says the number and never the names, so it is safe
             * for everybody on the list; the RSVP link is per recipient.
             */
            'day_before' => array(
                'label' => 'Count of who is coming, to your notification list, the day before',
                'note'  => 'Sent at 6 am the day before. Gives the number registered, not the names. Nothing is sent when nobody has registered.',
            ),
            'reminder' => array(
                'label' => 'Morning-of reminder to everybody registered',
                'note'  => 'Sent at 6 am on the day, or at midnight when the event starts earlier. Your notification list is copied in.',
            ),
            'summary' => array(
                'label' => 'Who is coming, to your notification list, two hours before',
                'note'  => 'Lists everybody registered. Nothing is sent when nobody has registered.',
            ),
            /*
             * THE FIFTH, ADDED 3.56.0. The alert's opposite number.
             *
             * The list heard about every registration and nothing about a
             * cancellation, so a count read off the last alert drifted from the
             * truth and only opening the registrations screen corrected it.
             *
             * IT IS A KIND, NOT A MECHANISM. Adding the key here is the whole
             * of the wiring: on() reads it, set_off() intersects against
             * array_keys( kinds() ) so the form saves it, off_count() counts
             * it, and the caladmin card renders it by iterating this list. That
             * is the shared-field-list rule, and it is why an event created
             * before this existed behaves like one created after: the meta
             * records only what is switched OFF, so absent means on.
             */
            'cancel_alert' => array(
                'label' => 'Alert to your notification list when somebody cancels',
                'note'  => 'One email per cancellation, as it happens. Names who cancelled and what the count is now.',
            ),
        );
    }

    /**
     * Is this kind switched on for this event?
     *
     * Absent meta means on. That is what makes the common path free: an event
     * nobody has configured gets all six.
     */
    public static function on( $event_id, $kind ) {
        $off = get_post_meta( (int) $event_id, self::OFF_META, true );
        $off = is_array( $off ) ? $off : array();
        return ! in_array( $kind, $off, true );
    }

    /** Store the set that is off. Pass every key the form offered. */
    public static function set_off( $event_id, $off_keys ) {
        $valid = array_keys( self::kinds() );
        $off   = array_values( array_intersect( $valid, array_map( 'strval', (array) $off_keys ) ) );
        if ( empty( $off ) ) {
            delete_post_meta( (int) $event_id, self::OFF_META );
            return;
        }
        update_post_meta( (int) $event_id, self::OFF_META, $off );
    }

    /** How many of the five are off, for the disclosure summary line. */
    public static function off_count( $event_id ) {
        $off = get_post_meta( (int) $event_id, self::OFF_META, true );
        return is_array( $off ) ? count( $off ) : 0;
    }

    /* =====================================================================
     * Recipients
     * ================================================================== */

    /**
     * The staff list: SFAF_Reminders::notify_list(), which is the one
     * resolution of people, teams and typed addresses, deduplicated by address
     * and computed at send time so a team change takes effect immediately.
     *
     * @return array<string,string> lowercased email => label
     */
    public static function staff( $event_id ) {
        return SFAF_Reminders::notify_list( $event_id );
    }

    /**
     * The same list, with the account behind each address.
     *
     * THE ONE RESOLUTION FOR BOTH MESSAGES THAT LINK INTO CALADMIN.
     * send_alert() and send_summary_for_event() each build a different version
     * for a recipient who can open their screen and one who cannot, and both
     * ask this. See SFAF_Reminders::notify_entries() for why the user id has to
     * come out of the resolution rather than from a lookup on the address.
     *
     * They ask DIFFERENT capabilities of that id, because they link to
     * different screens with different gates. What is shared is who is on the
     * list and which account each address belongs to, which is this.
     *
     * @return array<string,array{label:string,user_id:int}>
     */
    public static function staff_entries( $event_id ) {
        return SFAF_Reminders::notify_entries( $event_id );
    }

    /* =====================================================================
     * Building
     * ================================================================== */

    /**
     * Build one message.
     *
     * @param string $type   confirmation|reminder|alert|summary
     * @param int    $event_id
     * @param object $person For confirmation and reminder: the registrant, with
     *                       ->name, ->first_name, ->email and ->token. For
     *                       alert: whoever just registered. Unused by summary.
     * @param array  $context Facts about the RECIPIENT rather than the event.
     *                       Read by the two messages that link into caladmin,
     *                       and EACH NAMES THE CAPABILITY ITS OWN LINK NEEDS
     *                       rather than sharing one flag:
     *                         alert    'can_view_all'   /caladmin/rsvps
     *                         summary  'can_edit_event' /caladmin/events/edit/N
     *                       Those are different gates on the portal, so one key
     *                       for both would be a claim that they are the same.
     *                       Absent means false, so a caller that says nothing
     *                       gets the public event page.
     * @return array{subject:string,html:string,text:string}|null
     */
    public static function build( $type, $event_id, $person = null, $context = array() ) {
        switch ( $type ) {
            case 'confirmation':
                return self::build_confirmation( $event_id, $person, $context );
            case 'reminder':
                return self::build_reminder( $event_id, $person, $context );
            case 'alert':
                return self::build_alert( $event_id, $person, $context );
            /*
             * NOT 'cancelled', WHICH IS A DIFFERENT MESSAGE TO A DIFFERENT
             * AUDIENCE. 'cancelled' tells REGISTRANTS the EVENT is off.
             * 'cancel_alert' tells STAFF that one REGISTRANT has dropped out.
             * The keys are deliberately not near-identical words.
             */
            case 'cancel_alert':
                return self::build_cancel_alert( $event_id, $person, $context );
            case 'summary':
                return self::build_summary( $event_id, $context );
            case 'day_before':
                return self::build_day_before( $event_id, $context );
            case 'cancelled':
                return self::build_cancelled( $event_id, $person, $context );
            case 'changed':
                return self::build_changed( $event_id, $person, $context );
            /*
             * THE FIFTH THING THAT CAN HAPPEN TO A REGISTRATION (3.73.0).
             * Told it was off, and now it is on. See build_reinstated().
             */
            case 'reinstated':
                return self::build_reinstated( $event_id, $person, $context );
            /* THE WAITLIST (3.106.0). See SFAF_Waitlist. */
            case 'waitlist':
                return self::build_waitlist( $event_id, $person, $context );
            /* EMAIL REGISTRANTS, FROM THE RSVP LIST (3.110.0). See SFAF_Registrant_Mail. */
            case 'registrant_message':
                return self::build_registrant_message( $event_id, $person, $context );
            /* THE WAITLIST, TOLD THE EVENT IS OFF (3.106.1). See SFAF_Announce. */
            case 'waitlist_cancelled':
                return self::build_waitlist_cancelled( $event_id, $person, $context );
        }
        return null;
    }

    /** The event's facts, once, for every builder. */
    private static function facts( $event_id, $format = '', $lang = 'en', $context = array() ) {
        /*
         * THE SAMPLE EVENT (3.106.0). The Templates screen, EMAILS.md and the
         * render test hand in fixed facts rather than an event, so they go
         * through the builder that sends and not a copy of it.
         */
        if ( ! empty( $context['sample'] ) ) {
            $x = $context['sample'];
            return array(
                'title' => $x['title'], 'date' => $x['date'], 'time' => $x['time'], 'location' => $x['location'],
                'url' => $x['event_link'], 'lang' => $x['lang'], 'sample' => $x,
            );
        }
        $date  = (string) get_post_meta( $event_id, '_uc_event_date', true );
        $start = (string) get_post_meta( $event_id, '_uc_start_time', true );
        $end   = (string) get_post_meta( $event_id, '_uc_end_time', true );

        $location = sfaf_event_location( $event_id );
        if ( '' !== (string) $format
            && SFAF_Online::MODE_ONLINE === (string) $format
            && SFAF_Online::is_hybrid( $event_id ) ) {
            $location = ( 'en' === $lang ) ? SFAF_Online::LABEL : SFAF_Messages::label( 'online_event', $lang );
        }

        return array(
            'title'    => get_the_title( $event_id ),
            'date'     => sfaf_ap_date( $date, 'full', $lang ),
            'time'     => sfaf_ap_time_range( $start, $end, 'zone', $lang ),
            'location' => $location,
            'url'      => (string) get_permalink( $event_id ),
            'lang'     => $lang,
            'sample'   => null,
        );
    }

    /**
     * The language a registrant message goes out in (3.106.0): the sample's,
     * else the event's. Staff messages never ask this and stay English.
     */
    private static function lang_for( $event_id, $context ) {
        if ( ! empty( $context['sample'] ) ) {
            return SFAF_Messages::lang( $context['sample']['lang'] );
        }
        return sfaf_event_language( $event_id );
    }

    /** A person's first name, falling back to the whole name, or ''. */
    private static function first_name( $person ) {
        $first = ( $person && ! empty( $person->first_name ) ) ? trim( (string) $person->first_name ) : '';
        if ( '' === $first && $person && ! empty( $person->name ) ) {
            $first = trim( (string) $person->name );
        }
        return $first;
    }

    /**
     * The tokens every registrant message can use (3.106.0).
     *
     * THE MEETING LINK IS ONLY EVER HANDED OVER BY THE CALLER, which has asked
     * SFAF_Online's gates first. It is '' here, so a template that names
     * {meeting_link} in a message nobody may be sent it prints nothing.
     */
    private static function values( $event_id, $f, $person ) {
        $x = $f['sample'];
        $cancel = $x ? $x['cancel_link'] : ( ( $person && ! empty( $person->token ) ) ? SFAF_Reminders::cancel_url( $person->token ) : '' );
        return array(
            'first_name'   => self::first_name( $person ),
            'last_name'    => ( $person && ! empty( $person->last_name ) ) ? (string) $person->last_name : '',
            'title'        => $f['title'],
            'date'         => $f['date'],
            'time'         => $f['time'],
            'location'     => $f['location'],
            'organizer'    => $x ? $x['organizer'] : ( class_exists( 'SFAF_Organizers' ) ? SFAF_Organizers::phrase( $event_id ) : '' ),
            'event_link'   => $f['url'],
            'cancel_link'  => $cancel,
            'meeting_link' => '',
        );
    }

    /**
     * The joining block and which variant of the message it makes, for the
     * confirmation and the reminder. The block comes from SFAF_Online, whose
     * two gates decide whether this person may be sent the link; the variant
     * is read off what they answered, so there is no third opinion.
     *
     * @return array{variant:string,html:string,text:string,link:string}
     */
    private static function joining( $event_id, $kind, $person, $f, $context ) {
        $lang = $f['lang'];
        if ( $f['sample'] ) {
            $variant = isset( $context['variant'] ) ? (string) $context['variant'] : 'in_person';
            if ( 'in_person' === $variant ) {
                return array( 'variant' => 'in_person', 'html' => '', 'text' => '', 'link' => '' );
            }
            $link = ( 'online_link' === $variant ) ? $f['sample']['meeting_link'] : '';
            return array( 'variant' => $variant, 'html' => SFAF_Messages::joining_html( $link, $lang ),
                'text' => SFAF_Messages::joining_text( $link, $lang ), 'link' => $link );
        }
        $html = SFAF_Online::joining_html( $event_id, $kind, self::person_format( $person ), $lang );
        if ( '' === $html ) {
            return array( 'variant' => 'in_person', 'html' => '', 'text' => '', 'link' => '' );
        }
        $link = SFAF_Online::link( $event_id );
        return array(
            'variant' => ( '' !== $link ) ? 'online_link' : 'online_pending',
            'html'    => $html,
            'text'    => SFAF_Online::joining_text( $event_id, $kind, self::person_format( $person ), $lang ),
            'link'    => $link,
        );
    }

    /**
     * Which format one recipient is in, for a hybrid event.
     *
     * '' FOR EVERY EVENT THAT NEVER ASKED, which is what the row stores and
     * what every reader downstream treats as "this event has one format". A
     * person object with no format field, which is what the test send and the
     * older callers hand over, is the same case.
     *
     * @param object|array $person
     * @return string
     */
    private static function person_format( $person ) {
        if ( is_array( $person ) ) {
            return isset( $person['format'] ) ? (string) $person['format'] : '';
        }
        return isset( $person->format ) ? (string) $person->format : '';
    }

    /** The detail rows every message shows, in the same order every time. */
    private static function detail_rows( $f ) {
        $lang = isset( $f['lang'] ) ? $f['lang'] : 'en';
        return array(
            SFAF_Messages::label( 'event', $lang )    => $f['title'],
            SFAF_Messages::label( 'date', $lang )     => $f['date'],
            SFAF_Messages::label( 'time', $lang )     => $f['time'],
            SFAF_Messages::label( 'location', $lang ) => $f['location'],
        );
    }

    /** The same facts as plain text. */
    private static function detail_text( $f ) {
        $lines = array();
        foreach ( self::detail_rows( $f ) as $label => $value ) {
            if ( '' !== trim( (string) $value ) ) {
                $lines[] = $label . ': ' . $value;
            }
        }
        return implode( "\n", $lines );
    }

    /**
     * (a) THE CONFIRMATION.
     *
     * NO EVENT IMAGE, AND THAT IS A DECISION RATHER THAN AN OMISSION. The
     * banner is already a large picture; a second one pushes the date, the time
     * and the address below the fold on a phone, which is the part somebody
     * opens this email to re-read. Half of the imported events have no
     * photograph at all and would render a flat colour block in its place.
     */
    private static function build_confirmation( $event_id, $person, $context = array() ) {
        $lang = self::lang_for( $event_id, $context );
        $f    = self::facts( $event_id, self::person_format( $person ), $lang, $context );
        $x    = $f['sample'];
        $join = self::joining( $event_id, 'confirmation', $person, $f, $context );

        $values = self::values( $event_id, $f, $person );
        $values['meeting_link'] = $join['link'];

        /*
         * THE .ics ADDRESS, WHICH IS THE ONE PLACE THAT DIFFERS FROM EVERY
         * OTHER MESSAGE. ics_url_with_link() adds the token that lets the file
         * carry the meeting link when this event's link goes out with the
         * confirmation. See SFAF_Online and sfaf_output_ics().
         */
        if ( $x ) {
            $gcal = $x['gcal'];
            $ics  = $x['ics'];
            $donate = $x['donate_link'];
            $custom = '';
        } else {
            $gcal = sfaf_google_calendar_url( $event_id );
            $ics = SFAF_Online::ics_url_with_link( $event_id, self::person_format( $person ) );
            $donate = sfaf_donate_url( $event_id );
            $custom = self::custom_body( $event_id, 'confirmation' );
        }

        // {attendee_name} in an event's own custom copy still means the whole
        // name, because that is what it has always meant.
        $tokens = array(
            'name'       => ( $person && ! empty( $person->name ) ) ? (string) $person->name : $values['first_name'],
            'first_name' => $values['first_name'],
            'last_name'  => $values['last_name'],
            'cancel_url' => $values['cancel_link'],
        );

        $out = SFAF_Messages::compose( 'confirmation', $join['variant'], $lang, $values, array(
            'intro_override' => '' !== $custom ? sfaf_replace_tokens( $custom, $event_id, $tokens ) : '',
            'details'        => self::detail_rows( $f ),
            'joining'        => $join['html'],
            'joining_text'   => $join['text'],
            'calendar'       => array( 'label' => 'add_to_calendar', 'gcal' => $gcal, 'ics' => $ics ),
            'event_url'      => $f['url'],
            'donate'         => $donate,
            'preheader'      => sprintf( '%s, %s', $f['date'], $f['time'] ? $f['time'] : SFAF_Messages::label( 'tbc', $lang ) ),
            // A place opened and they were next on the waitlist (3.110.0).
            'waitlist_added' => ( $person && ! empty( $person->from_waitlist ) ),
        ) );
        if ( ! $x ) {
            $out['subject'] = self::subject( $event_id, 'confirmation', $out['subject'] );
        }
        return $out;
    }

    /** (b) THE MORNING-OF REMINDER. */
    private static function build_reminder( $event_id, $person, $context = array() ) {
        /*
         * THE STAFF COPY IS ENGLISH, like every staff message (3.106.0), and
         * says it is the copy. Registrants get the event's language.
         */
        $staff = ( $person && ! empty( $person->is_staff ) );
        $lang  = $staff ? 'en' : self::lang_for( $event_id, $context );
        $f     = self::facts( $event_id, self::person_format( $person ), $lang, $context );
        $x     = $f['sample'];
        $join  = self::joining( $event_id, 'reminder', $person, $f, $context );

        $values = self::values( $event_id, $f, $person );
        $values['meeting_link'] = $join['link'];

        $parts = array(
            'details'      => self::detail_rows( $f ),
            'joining'      => $join['html'],
            'joining_text' => $join['text'],
            'event_url'    => $f['url'],
            'event_button' => true,
            // The donate line goes to registrants, not to the staff copy.
            'donate'       => $staff ? '' : ( $x ? $x['donate_link'] : sfaf_donate_url( $event_id ) ),
            'preheader'    => sprintf( SFAF_Messages::label( 'pre_today', $lang ), $f['time'] ? $f['time'] : $f['date'] ),
        );
        if ( $staff ) {
            $parts['heading']        = sprintf( '%s is today.', $f['title'] );
            $parts['intro_override'] = 'This is the copy of the reminder everybody registered has just been sent.';
        }
        $out = SFAF_Messages::compose( 'reminder', $join['variant'], $lang, $values, $parts );
        if ( ! $x ) {
            $out['subject'] = self::subject( $event_id, 'reminder', $out['subject'] );
        }
        return $out;
    }

    /**
     * (c) THE REGISTRATION ALERT, to staff.
     *
     * THE BUTTON GOES WHERE THE READER CAN ACTUALLY GO, AND THAT DIFFERS PER
     * RECIPIENT.
     *
     * Somebody who has just been told a person registered wants the
     * REGISTRATION LIST, not the public page: who else is coming, how full it
     * is, the address to write to. So the button is the RSVP screen for this
     * event. That screen asks the event gate, user_can_edit_event(), and the
     * notification list is not: it holds contributors, people reached through a team, and typed
     * addresses that are not accounts at all. A link that answers "Denied" is
     * worse than the public page, because it also tells somebody there is a
     * screen they are not allowed to see.
     *
     * Hence $context['can_edit_event'], decided by send_alert() one recipient
     * at a time with the same gate the screen asks (3.110.1; it was
     * can_view_all, which an editor outside the event would have passed).
     * Absent means false, so any caller that does not say gets the public page.
     *
     * @param array $context can_edit_event: bool.
     */
    private static function build_alert( $event_id, $person, $context = array() ) {
        $f     = self::facts( $event_id );
        $who   = ( $person && ! empty( $person->name ) ) ? (string) $person->name : 'Somebody';
        $email = ( $person && ! empty( $person->email ) ) ? (string) $person->email : '';
        /*
         * WHICH FORMAT THEY PICKED, AND THE NUMBER THAT BELONGS TO IT (3.96.0).
         *
         * THIS MESSAGE GOES TO STAFF, so naming the format is not a disclosure:
         * the list already receives the registrant's name and address. What it
         * needs is the fact somebody organizing the room actually acts on,
         * which is whether this person is coming to it.
         *
         * AND THE COUNT HAS TO MATCH THE FORMAT, or the sentence is worse than
         * no sentence. "Lee registered. 11 of 12 places taken" beside "Online"
         * reports the room's capacity at somebody joining by link, and whoever
         * reads it sets out a chair.
         */
        $format = self::person_format( $person );
        $hybrid = SFAF_Online::is_hybrid( $event_id );

        if ( $hybrid && '' !== $format ) {
            $count  = (int) sfaf_get_rsvp_count_by_format( $event_id, $format );
            $cap    = (int) sfaf_event_capacity( $event_id, $format );
            $word   = ( SFAF_Online::MODE_ONLINE === $format ) ? 'online' : 'in person';
            $places = sfaf_capacity_limited( $event_id, $format )
                ? sprintf( '%d of %d places taken %s', $count, $cap, $word )
                : sprintf( '%d registered %s so far', $count, $word );
        } else {
            $count  = (int) sfaf_get_rsvp_count( $event_id );
            $cap    = (int) sfaf_event_capacity( $event_id );
            $places = sfaf_capacity_limited( $event_id )
                ? sprintf( '%d of %d places taken', $count, $cap )
                : sprintf( '%d registered so far', $count );
        }

        $can_view = ! empty( $context['can_edit_event'] );
        if ( $can_view ) {
            $link  = add_query_arg( 'event_id', (int) $event_id, SFAF_Portal::link( 'rsvps' ) );
            $label = 'See who has registered';
        } else {
            $link  = $f['url'];
            $label = 'Open this event';
        }

        $rows = array( 'Name' => $who, 'Email' => $email );
        // Its own row on a hybrid event, and absent everywhere else: a row
        // reading "In person" on an event that offers nothing else is a line of
        // noise on every alert the calendar sends.
        if ( $hybrid && '' !== $format ) {
            $rows['Attending'] = ( SFAF_Online::MODE_ONLINE === $format ) ? 'Online' : 'In person';
        }
        /*
         * THEIR ANSWERS, UNDER THEIR NAME (3.106.2), one row per question with
         * any additional info in brackets. The summary, the day-before count
         * and the digest do not carry them.
         */
        $answers = ( $person && ! empty( $person->rsvp_id ) ) ? self::answer_rows( (int) $person->rsvp_id ) : array();
        $rows = $rows + $answers + self::detail_rows( $f );

        $html  = SFAF_Email::heading( sprintf( 'New registration for %s', $f['title'] ) );
        $html .= SFAF_Email::para( $places . '.' );
        $html .= SFAF_Email::details( $rows );
        if ( $link ) {
            $html .= SFAF_Email::button( $link, $label, 'primary' );
        }

        $text  = sprintf( "New registration for %s\n\n", $f['title'] );
        $text .= $places . ".\n\n";
        $text .= 'Name: ' . $who . "\n";
        if ( $email ) { $text .= 'Email: ' . $email . "\n"; }
        // The other half of the same message. A rule that holds in the HTML and
        // not in the text part is not a rule.
        if ( $hybrid && '' !== $format ) {
            $text .= 'Attending: ' . ( ( SFAF_Online::MODE_ONLINE === $format ) ? 'Online' : 'In person' ) . "\n";
        }
        foreach ( $answers as $q => $a ) {
            $text .= $q . ': ' . $a . "\n";
        }
        $text .= self::detail_text( $f ) . "\n\n";
        if ( $link ) {
            $text .= $label . ': ' . $link . "\n";
        }
        $text .= "\n" . SFAF_Email::POSTAL;

        return array(
            'subject' => sprintf( 'New registration: %s', $f['title'] ),
            'html'    => SFAF_Email::shell( sprintf( '%s registered. %s.', $who, $places ), $html ),
            'text'    => $text,
        );
    }

    /**
     * (d) THE PRE-EVENT SUMMARY, to staff, two hours before.
     *
     * THE BUTTON IS PER RECIPIENT, FOR THE SAME REASON THE ALERT'S IS. This
     * message goes to the same notification list, which is gated on nothing,
     * and it linked every one of them into /caladmin. It was found by the
     * inventory the alert work prompted rather than by anybody meeting it.
     *
     * The capability asked is can_edit_event, not can_view_all, because that is
     * what render_event_form() gates this URL on. A contributor who created the
     * event can open it and keeps the link; a contributor merely added to the
     * list cannot and gets the public page.
     *
     * @param array $context can_edit_event: bool.
     */
    /**
     * (d2) SOMEBODY CANCELLED THEIR REGISTRATION, to staff, as it happens.
     *
     * THE ALERT'S OPPOSITE NUMBER, and deliberately the same shape. The list
     * heard about every registration and nothing about a cancellation, so a
     * count read off the last alert drifted from the truth and only opening the
     * registrations screen corrected it. Same audience, same switch mechanism,
     * same detail block, so the two read as a pair in an inbox.
     *
     * WHAT IT DISCLOSES: the name and address of the person who cancelled, and
     * the resulting count. That is exactly what the registration alert already
     * discloses about the same person to the same list, which is what makes it
     * consistent rather than a new disclosure. It is still registrant data on an
     * HIV, substance use and trans health calendar, and it is written down here
     * so nobody has to reconstruct it from the builder.
     *
     * SHORT, AND NO BUTTON. The alert offers "see who has registered" because
     * somebody reading it may want to act. There is nothing to do about a
     * cancellation, so the message states the fact and stops.
     *
     * @param array $context 'count' => the count AFTER the cancellation.
     */
    /**
     * One registration's answers as detail rows: question => "Option (info), Option".
     *
     * @return array<string,string>
     */
    public static function answer_rows( $rsvp_id ) {
        $rows = array();
        foreach ( SFAF_Questions::answers_for( (int) $rsvp_id ) as $q => $picked ) {
            $bits = array();
            foreach ( $picked as $a ) {
                $bits[] = '' !== $a[1] ? $a[0] . ' (' . $a[1] . ')' : $a[0];
            }
            $rows[ $q ] = implode( ', ', $bits );
        }
        return $rows;
    }

    private static function build_cancel_alert( $event_id, $person, $context = array() ) {
        $f     = self::facts( $event_id );
        $who   = ( $person && ! empty( $person->name ) ) ? (string) $person->name : 'Somebody';
        $email = ( $person && ! empty( $person->email ) ) ? (string) $person->email : '';

        /*
         * THE COUNT IS PASSED IN, NOT READ HERE. sfaf_get_rsvp_count() memoizes
         * per request, and this message is built after the row has already been
         * moved to 'cancelled'. The sender clears that cache and hands the fresh
         * number over rather than letting each of these ask again, which is the
         * fault "the request count cache goes stale on write" records: a
         * memoized read before the insert once served "0 of 12" to the alert.
         */
        $count = isset( $context['count'] ) ? (int) $context['count'] : (int) sfaf_get_rsvp_count( $event_id );
        $cap   = (int) sfaf_event_capacity( $event_id );

        $places = sfaf_capacity_limited( $event_id )
            ? sprintf( '%d of %d places taken', $count, $cap )
            : sprintf( '%d still registered', $count );

        $rows = array( 'Name' => $who, 'Email' => $email ) + self::detail_rows( $f );

        $html  = SFAF_Email::heading( sprintf( 'Cancellation for %s', $f['title'] ) );
        $html .= SFAF_Email::para( $places . '.' );
        $html .= SFAF_Email::details( $rows );

        $text  = sprintf( "Cancellation for %s\n\n", $f['title'] );
        $text .= $places . ".\n\n";
        $text .= 'Name: ' . $who . "\n";
        if ( $email ) { $text .= 'Email: ' . $email . "\n"; }
        $text .= self::detail_text( $f ) . "\n";
        $text .= "\n" . SFAF_Email::POSTAL;

        return array(
            'subject' => sprintf( 'Cancellation: %s', $f['title'] ),
            'html'    => SFAF_Email::shell( sprintf( '%s cancelled. %s.', $who, $places ), $html ),
            'text'    => $text,
        );
    }

    /**
     * (e) IT IS CANCELLED.
     *
     * NO CANCEL LINK, and that is not an oversight. Every other message to a
     * registrant carries one so they can cancel a registration they cannot use. There
     * is no place to release here: the event is not happening, and offering to
     * cancel a registration for it would read as though something were still
     * required of them.
     *
     * The reason, when the organizer gave one, goes above the details rather
     * than below. Somebody reading "cancelled" wants to know why before they
     * want to be reminded when it was going to be.
     */
    private static function build_cancelled( $event_id, $person, $context = array() ) {
        $lang = self::lang_for( $event_id, $context );
        $f    = self::facts( $event_id, '', $lang, $context );
        $x    = $f['sample'];

        // The public reason and the note to registrants are the manager's own
        // words, printed as written. See SFAF_Cancellation.
        $extra = array();
        if ( ! $x ) {
            $extra[] = trim( (string) get_post_meta( $event_id, '_uc_cancelled_reason', true ) );
            $extra[] = SFAF_Cancellation::message( $event_id );
        }

        return SFAF_Messages::compose( 'cancelled', 'default', $lang, self::values( $event_id, $f, $person ), array(
            'extra'     => $extra,
            'lead'      => 'was_going_to_be',
            'details'   => self::detail_rows( $f ),
            'preheader' => sprintf( SFAF_Messages::label( 'pre_cancelled', $lang ), $f['date'] ),
        ) );
    }

    /**
     * (f) IT HAS MOVED.
     *
     * OLD VALUE TO NEW VALUE, FOR EACH THING THAT ACTUALLY MOVED, and this is
     * the whole difference between this message and simply resending the
     * details. A registrant should not have to remember what the event used to
     * be in order to work out what changed about it. "This event has moved from
     * Tuesday, August 12 to Wednesday, August 13" answers the question; a
     * message that only carries the new date makes the reader do the diff, and
     * some of them will not.
     *
     * THE CANCEL LINK IS HERE, unlike the cancellation message. Somebody who
     * cannot make the new time should be able to release their place in one
     * click, and this is the moment they find out they might not be able to
     * make it.
     *
     * @param array $context {changes: array<string,array{from:string,to:string}>}
     *                       Keyed by the field label, already formatted.
     */
    private static function build_changed( $event_id, $person, $context = array() ) {
        $lang    = self::lang_for( $event_id, $context );
        $f       = self::facts( $event_id, self::person_format( $person ), $lang, $context );
        $x       = $f['sample'];
        $changes = ( isset( $context['changes'] ) && is_array( $context['changes'] ) ) ? $context['changes'] : array();
        if ( $x && ! $changes ) {
            $changes = array( 'Date' => array( 'from' => sfaf_ap_date( '2026-11-05', 'full' ), 'to' => sfaf_ap_date( '2026-11-12', 'full' ) ) );
        }

        /*
         * WHAT MOVED, OLD VALUE TO NEW. The values arrive as the formatter's
         * English, because that is what the save compared; they are said again
         * in the event's language by the same formatter. The labels are the
         * detail labels in that language.
         */
        $kinds = array( 'Date' => 'date', 'Time' => 'time', 'Location' => 'location' );
        $rows  = array();
        foreach ( $changes as $label => $pair ) {
            $kind  = isset( $kinds[ $label ] ) ? $kinds[ $label ] : '';
            $from  = sfaf_ap_restate( $kind, (string) $pair['from'], $lang );
            $to    = sfaf_ap_restate( $kind, (string) $pair['to'], $lang );
            if ( 'time' === $kind ) {
                $from = ( 'not set' === $pair['from'] ) ? $from : sfaf_ap_zoned( sfaf_ap_restate( 'time', (string) $pair['from'], $lang ), $lang );
                $to   = ( 'not set' === $pair['to'] ) ? $to : sfaf_ap_zoned( sfaf_ap_restate( 'time', (string) $pair['to'], $lang ), $lang );
            }
            $rows[ '' !== $kind ? SFAF_Messages::label( $kind, $lang ) : $label ] = array( $from, $to );
        }

        return SFAF_Messages::compose( 'changed', 'default', $lang, self::values( $event_id, $f, $person ), array(
            'changes'   => $rows,
            'lead'      => 'now_is',
            'details'   => self::detail_rows( $f ),
            'calendar'  => array( 'label' => 'update_calendar',
                'gcal' => $x ? $x['gcal'] : sfaf_google_calendar_url( $event_id ),
                'ics'  => $x ? $x['ics'] : sfaf_ics_url( $event_id ) ),
            'event_url' => $f['url'],
            'preheader' => sprintf( SFAF_Messages::label( 'pre_now', $lang ), sprintf( '%s, %s', $f['date'], $f['time'] ? $f['time'] : SFAF_Messages::label( 'tbc', $lang ) ) ),
        ) );
    }

    /**
     * (g) IT IS BACK ON.
     *
     * WHY THIS EXISTS. Cancelling tells everybody registered that the event
     * is off. Reinstating told them nothing at all, so somebody who had
     * been written to and had crossed it out of their week had no way of
     * learning it was on again except by going back to look.
     *
     * IT GOES OUT WHETHER OR NOT THE DATE CHANGED, and that is the whole
     * difference between this and `changed`. `changed` is for a live event
     * whose details moved, and it opens on the move, which is meaningless
     * to somebody who thinks the thing is not happening. What they need to
     * be told first is that it IS happening.
     *
     * WHERE THE DATE HAS MOVED, IT SAYS SO IN THE SAME MESSAGE rather than
     * sending two. `$context['was']` carries what the date used to be, and
     * the reader gets one sentence about the change under the headline
     * about the event being back.
     *
     * IT CARRIES A CANCEL LINK, ALWAYS, and that is not a courtesy. A
     * registration made for a Wednesday and reinstated onto a Thursday is a
     * commitment nobody re-made; the machinery is the same token the
     * confirmation and the reminder already use, so there is nothing new
     * here except offering it at the moment it is most needed.
     *
     * NOTHING ABOUT THE REGISTRATION ITSELF CHANGES. It survived the
     * cancellation untouched, it survives this untouched, and the message
     * says so: somebody who is happy with the new date does nothing.
     *
     * @param int    $event_id
     * @param object $person
     * @param array  $context {
     *     @type string $was The previous date, Y-m-d, when it moved.
     * }
     */
    private static function build_reinstated( $event_id, $person, $context = array() ) {
        $lang  = self::lang_for( $event_id, $context );
        $f     = self::facts( $event_id, self::person_format( $person ), $lang, $context );
        $x     = $f['sample'];
        $was   = isset( $context['was'] ) ? trim( (string) $context['was'] ) : '';
        if ( $x ) {
            $moved = ( 'moved' === ( isset( $context['variant'] ) ? $context['variant'] : '' ) );
        } else {
            $moved = ( '' !== $was && $was !== (string) get_post_meta( $event_id, '_uc_event_date', true ) );
        }

        $values = self::values( $event_id, $f, $person );
        $values['old_date'] = $x ? $x['old_date'] : ( '' !== $was ? sfaf_ap_date( $was, 'full', $lang ) : '' );

        return SFAF_Messages::compose( 'reinstated', $moved ? 'moved' : 'same_date', $lang, $values, array(
            'details'   => self::detail_rows( $f ),
            'calendar'  => array( 'label' => 'back_in_calendar',
                'gcal' => $x ? $x['gcal'] : sfaf_google_calendar_url( $event_id ),
                'ics'  => $x ? $x['ics'] : sfaf_ics_url( $event_id ) ),
            'event_url' => $f['url'],
            'preheader' => sprintf( SFAF_Messages::label( 'pre_back', $lang ), $f['date'] ),
        ) );
    }

    /**
     * (h) ON THE WAITLIST (3.106.0). Their position, no calendar file: they do
     * not have a place, and a calendar entry would say they did.
     *
     * @param array $context position: int.
     */
    /**
     * (l) A MESSAGE FROM THE RSVP LIST (3.110.0): the subject and body
     * somebody wrote for this one send, with the six tokens filled for this
     * person, in the standard wrapper with the event's details, in the event's
     * language. No cancel link and no donate line: it is a note, not a
     * registration message.
     *
     * @param array $context subject: string; body: string, paragraphs and {tokens}.
     */
    private static function build_registrant_message( $event_id, $person, $context = array() ) {
        $lang   = self::lang_for( $event_id, $context );
        $f      = self::facts( $event_id, self::person_format( $person ), $lang, $context );
        $values = self::values( $event_id, $f, $person );
        return SFAF_Messages::compose( 'registrant_message', 'default', $lang, $values, array(
            'text'      => array(
                'subject' => isset( $context['subject'] ) ? (string) $context['subject'] : '',
                'intro'   => isset( $context['body'] ) ? (string) $context['body'] : '',
            ),
            'details'   => self::detail_rows( $f ),
            'event_url' => $f['url'],
            'preheader' => $f['title'],
        ) );
    }

    private static function build_waitlist( $event_id, $person, $context = array() ) {
        $lang   = self::lang_for( $event_id, $context );
        $f      = self::facts( $event_id, self::person_format( $person ), $lang, $context );
        $values = self::values( $event_id, $f, $person );
        $values['position'] = $f['sample'] ? $f['sample']['position'] : (string) ( isset( $context['position'] ) ? (int) $context['position'] : 0 );
        return SFAF_Messages::compose( 'waitlist', 'default', $lang, $values, array(
            'details'   => self::detail_rows( $f ),
            'event_url' => $f['url'],
            'preheader' => sprintf( SFAF_Messages::label( 'pre_waitlist', $lang ), $f['date'] ),
        ) );
    }

    /**
     * (k) THE EVENT THEY WERE WAITING FOR IS CANCELLED (3.106.1).
     *
     * No cancel link, because there is nothing left to give up, and no donate
     * line. The public reason goes in; the note to registrants does not,
     * because it was written for the people holding a place.
     *
     * @param array $context events: int[], every cancelled date this person
     *                       was waiting for, this one first. One date draws
     *                       the details; several draw a row per date.
     */
    private static function build_waitlist_cancelled( $event_id, $person, $context = array() ) {
        $lang   = self::lang_for( $event_id, $context );
        $f      = self::facts( $event_id, '', $lang, $context );
        $x      = $f['sample'];
        $values = self::values( $event_id, $f, $person );
        $values['cancel_link'] = '';
        $values['series']      = $x ? $x['series'] : SFAF_Series::name_for_event( $event_id );

        $ids = ( isset( $context['events'] ) && is_array( $context['events'] ) && $context['events'] )
            ? array_values( array_map( 'intval', $context['events'] ) ) : array( (int) $event_id );

        if ( $x ) {
            $variant = ( isset( $context['variant'] ) && 'no_dates' === $context['variant'] ) ? 'no_dates' : 'dates';
            $next    = ( 'dates' === $variant ) ? $x['next'] : array();
            $extra   = array();
        } else {
            $next    = self::next_in_series( $ids, $lang );
            $variant = $next ? 'dates' : 'no_dates';
            $extra   = array( trim( (string) get_post_meta( $event_id, '_uc_cancelled_reason', true ) ) );
        }

        if ( count( $ids ) > 1 ) {
            // A row per date, keyed by the date, as the several-dates messages do.
            $details = array();
            foreach ( $ids as $id ) {
                $key = sfaf_ap_date( (string) get_post_meta( $id, '_uc_event_date', true ), 'full', $lang );
                while ( isset( $details[ $key ] ) ) {
                    $key .= ' ';
                }
                $details[ $key ] = sfaf_ap_time_range( (string) get_post_meta( $id, '_uc_start_time', true ), (string) get_post_meta( $id, '_uc_end_time', true ), 'zone', $lang );
            }
        } else {
            $details = self::detail_rows( $f );
        }

        return SFAF_Messages::compose( 'waitlist_cancelled', $variant, $lang, $values, array(
            'extra'     => $extra,
            'lead'      => 'was_going_to_be',
            'details'   => $details,
            'more'      => $next,
            'preheader' => sprintf( SFAF_Messages::label( 'pre_cancelled', $lang ), $f['date'] ),
        ) );
    }

    /**
     * The next three published dates in the series of the first of these
     * events, each array( "date, time", url ): not cancelled, not private, not
     * started, and none of the events given. Empty when there is no series.
     *
     * @param int[]  $event_ids
     * @param string $lang
     * @return array[]
     */
    public static function next_in_series( $event_ids, $lang = 'en' ) {
        $event_ids = array_values( array_map( 'intval', (array) $event_ids ) );
        $term      = $event_ids ? SFAF_Series::id_for_event( $event_ids[0] ) : 0;
        if ( ! $term ) {
            return array();
        }
        $now = current_time( 'mysql' );
        $out = array();
        foreach ( SFAF_Series::events( $term, array( 'upcoming' => true, 'status' => array( 'publish' ), 'limit' => 50, 'public_only' => true ) ) as $id ) {
            if ( in_array( (int) $id, $event_ids, true ) || SFAF_Cancellation::is_cancelled( $id ) ) {
                continue;
            }
            $dt = sfaf_event_datetimes( $id );
            if ( $dt && $dt[0]->format( 'Y-m-d H:i:s' ) <= $now ) {
                continue;   // today's, already begun
            }
            $when  = sfaf_ap_date( (string) get_post_meta( $id, '_uc_event_date', true ), 'full', $lang );
            $clock = sfaf_ap_time_range( (string) get_post_meta( $id, '_uc_start_time', true ), (string) get_post_meta( $id, '_uc_end_time', true ), 'zone', $lang );
            $out[] = array( '' !== $clock ? $when . ', ' . $clock : $when, (string) get_permalink( $id ) );
            if ( 3 === count( $out ) ) {
                break;
            }
        }
        return $out;
    }

    private static function build_summary( $event_id, $context = array() ) {
        $f    = self::facts( $event_id );
        $rows = SFAF_RSVP::get_rsvps( $event_id, 'confirmed' );
        $n    = count( $rows );
        if ( ! $n ) {
            // Not a message. See send_summary_for_event(): an empty list is a
            // reason not to send, not a thing to send.
            return null;
        }

        /*
         * WHO IS COMING, AND ON A HYBRID EVENT HOW (3.96.0).
         *
         * This is the list an organizer reads before the doors open, so the
         * thing they need first is how many chairs. One number cannot say that
         * for an event where half the registrants are joining by link, so a
         * hybrid event gets both counts in the sentence and two labelled
         * groups in the list.
         *
         * THE GROUPING IS A PARTITION, and that is asserted rather than
         * assumed: every row lands in exactly one group and the groups add up
         * to $n. A row whose format was never recorded, which is any
         * registration taken before the event became hybrid, gets a group of
         * its own rather than being folded into one of the two or quietly
         * dropped. Somebody is expecting those people too.
         */
        $hybrid = SFAF_Online::is_hybrid( $event_id );
        $groups = array();
        if ( $hybrid ) {
            $groups = array(
                SFAF_Online::MODE_IN_PERSON => array( 'label' => 'In person', 'rows' => array() ),
                SFAF_Online::MODE_ONLINE    => array( 'label' => 'Online',    'rows' => array() ),
                ''                          => array( 'label' => 'Format not recorded', 'rows' => array() ),
            );
            foreach ( $rows as $row ) {
                $rf = isset( $row->format ) ? (string) $row->format : '';
                if ( ! isset( $groups[ $rf ] ) ) {
                    $rf = '';
                }
                $groups[ $rf ]['rows'][] = $row;
            }
            // A group with nobody in it is not a heading.
            $groups = array_filter( $groups, function ( $g ) { return ! empty( $g['rows'] ); } );
        }

        if ( $hybrid ) {
            $counted = array();
            foreach ( $groups as $g ) {
                $counted[] = count( $g['rows'] ) . ' ' . strtolower( $g['label'] );
            }
            $places = implode( ', ', $counted );
            $cap_in = (int) sfaf_event_capacity( $event_id, SFAF_Online::MODE_IN_PERSON );
            $cap_on = (int) sfaf_event_capacity( $event_id, SFAF_Online::MODE_ONLINE );
            $lim_in = sfaf_capacity_limited( $event_id, SFAF_Online::MODE_IN_PERSON );
            $lim_on = sfaf_capacity_limited( $event_id, SFAF_Online::MODE_ONLINE );
            if ( $lim_in || $lim_on ) {
                $places .= sprintf(
                    ' (%s in person, %s online)',
                    $lim_in ? 'of ' . $cap_in : 'no limit',
                    $lim_on ? 'of ' . $cap_on : 'no limit'
                );
            }
        } else {
            $cap    = (int) sfaf_event_capacity( $event_id );
            $places = sfaf_capacity_limited( $event_id ) ? sprintf( '%d of %d places taken', $n, $cap ) : sprintf( '%d registered', $n );
        }

        if ( ! empty( $context['can_edit_event'] ) ) {
            $link  = SFAF_Portal::link( 'events/edit/' . (int) $event_id );
            $label = 'Open this event';
        } else {
            $link  = $f['url'];
            $label = 'See the event page';
        }

        $html  = SFAF_Email::heading( sprintf( '%s starts soon', $f['title'] ) );
        $html .= SFAF_Email::para( sprintf( '%s. Here is who to expect.', $places ) );
        $html .= SFAF_Email::details( self::detail_rows( $f ) );
        if ( $hybrid ) {
            // One table per group, each under its own heading, so the list is
            // read the way the room is set up rather than alphabetically across
            // two different kinds of attendance.
            foreach ( $groups as $g ) {
                $html .= SFAF_Email::label( $g['label'] . ' (' . count( $g['rows'] ) . ')' );
                $html .= SFAF_Email::people_table( $g['rows'] );
            }
        } else {
            $html .= SFAF_Email::people_table( $rows );
        }
        if ( $link ) {
            $html .= SFAF_Email::button( $link, $label, 'primary' );
        }

        $text  = sprintf( "%s starts soon\n\n%s. Here is who to expect.\n\n", $f['title'], $places );
        $text .= self::detail_text( $f ) . "\n\n";
        if ( $hybrid ) {
            foreach ( $groups as $g ) {
                $text .= $g['label'] . ' (' . count( $g['rows'] ) . ")\n";
                foreach ( $g['rows'] as $row ) {
                    $name = SFAF_RSVP::display_name( $row );
                    $text .= '- ' . ( '' !== $name ? $name : 'No name given' ) . ( '' !== (string) $row->email ? ' <' . $row->email . '>' : ', no email' ) . "\n";
                }
                $text .= "\n";
            }
        }
        // The flat list, for an event with one format. The grouped one above has
        // already written every row for a hybrid event, and writing them twice
        // is the fault this whole section is guarding against.
        if ( ! $hybrid ) {
            foreach ( $rows as $row ) {
                // The full name here, and in the HTML table beside it: this is
                // the list an organizer reads at the door, where telling two
                // people apart is the point. display_name() is the one place
                // that joins the pair.
                $name = SFAF_RSVP::display_name( $row );
                $text .= '- ' . ( '' !== $name ? $name : 'No name given' ) . ( '' !== (string) $row->email ? ' <' . $row->email . '>' : ', no email' ) . "\n";
            }
        }
        if ( $link ) {
            $text .= "\n" . $label . ': ' . $link . "\n";
        }
        $text .= "\n" . SFAF_Email::POSTAL;

        return array(
            'subject' => sprintf( 'Starting soon: %s, %s registered', $f['title'], $n ),
            'html'    => SFAF_Email::shell( sprintf( '%s. %s.', $f['time'] ? $f['time'] : $f['date'], $places ), $html ),
            'text'    => $text,
        );
    }

    /**
     * (d3) THE DAY BEFORE, to staff (3.102.0).
     *
     * THE NUMBER, NEVER THE NAMES. The summary two hours out names everybody,
     * because it is the list somebody reads at the door. This goes a day ahead,
     * to the same list, and the list is gated on nothing, so it says how many
     * and links to who for the people who may open that screen.
     *
     * THE LINK IS THE SCOPED RSVP LIST, asked of the event gate, which is what
     * /caladmin/rsvps?event_id=N applies. Anybody the gate refuses gets the
     * public event page, exactly as the summary decides.
     *
     * @param array $context can_edit_event: bool.
     * @return array|null Null when nobody is registered: not a message.
     */
    private static function build_day_before( $event_id, $context = array() ) {
        $f = self::facts( $event_id );
        $n = (int) sfaf_get_rsvp_count( $event_id );
        if ( $n < 1 ) {
            return null;
        }

        $cap    = (int) sfaf_event_capacity( $event_id );
        $places = sfaf_capacity_limited( $event_id ) ? sprintf( '%d of %d places taken', $n, $cap ) : sprintf( '%d registered', $n );

        if ( ! empty( $context['can_edit_event'] ) ) {
            $link  = add_query_arg( 'event_id', (int) $event_id, SFAF_Portal::link( 'rsvps' ) );
            $label = 'See who has registered';
        } else {
            $link  = $f['url'];
            $label = 'See the event page';
        }

        $html  = SFAF_Email::heading( sprintf( 'Tomorrow: %s', $f['title'] ) );
        $html .= SFAF_Email::para( $places . '.' );
        $html .= SFAF_Email::details( self::detail_rows( $f ) );
        if ( $link ) {
            $html .= SFAF_Email::button( $link, $label, 'primary' );
        }

        $text  = sprintf( "Tomorrow: %s\n\n%s.\n\n", $f['title'], $places );
        $text .= self::detail_text( $f ) . "\n\n";
        if ( $link ) {
            $text .= $label . ': ' . $link . "\n";
        }
        $text .= "\n" . SFAF_Email::POSTAL;

        return array(
            'subject' => sprintf( 'Tomorrow: %s, %d registered', $f['title'], $n ),
            'html'    => SFAF_Email::shell( sprintf( '%s. %s.', $f['time'] ? $f['time'] : $f['date'], $places ), $html ),
            'text'    => $text,
        );
    }

    /**
     * Custom copy, when somebody has written some.
     *
     * The event's own field wins, then the site-wide default in Settings, then
     * nothing, which means the shipped copy above. The shell, the details table
     * and the buttons are not customisable: those are the parts that have to be
     * right, and a manager who wants different wording wants different WORDING.
     */
    private static function custom_body( $event_id, $kind ) {
        // The event's own copy only. The Settings screen's site-wide copy
        // became the English templates in 3.106.0; see
        // SFAF_Messages::migrate_settings().
        $per_event = array( 'confirmation' => '_uc_email_body' );
        if ( isset( $per_event[ $kind ] ) ) {
            return trim( (string) get_post_meta( $event_id, $per_event[ $kind ], true ) );
        }
        return '';
    }

    /** Custom subject, same order of precedence. */
    private static function subject( $event_id, $kind, $default ) {
        $per_event = array( 'confirmation' => '_uc_email_subject' );
        if ( isset( $per_event[ $kind ] ) ) {
            $own = trim( (string) get_post_meta( $event_id, $per_event[ $kind ], true ) );
            if ( '' !== $own ) {
                return sfaf_replace_tokens( $own, $event_id, array() );
            }
        }
        return $default;
    }

    /** Custom copy is plain text written in a textarea. Render it as blocks. */
    private static function paragraphs( $text ) {
        $out    = '';
        $blocks = preg_split( "/\n\s*\n/", trim( (string) $text ) );
        foreach ( $blocks as $block ) {
            $block = trim( $block );
            if ( '' === $block ) {
                continue;
            }
            // Single newlines inside a block are line breaks the writer meant.
            $out .= '<p style="margin:0 0 14px 0; font-family:' . SFAF_Email::FONT . '; font-size:16px; line-height:1.5; color:' . SFAF_Email::C_INK . ';">'
                . nl2br( esc_html( $block ) ) . '</p>';
        }
        return $out;
    }

    /* =====================================================================
     * Sending
     * ================================================================== */

    /** The confirmation, to the person who just registered. */
    public static function send_confirmation( $event_id, $person ) {
        if ( ! self::on( $event_id, 'confirmation' ) ) {
            return false;
        }
        $built = self::build( 'confirmation', $event_id, $person );
        if ( ! $built ) {
            return false;
        }
        return SFAF_Email::send(
            $person->email,
            $built['subject'],
            $built['html'],
            $built['text'],
            SFAF_Reminders::reply_to_for( $event_id )
        );
    }

    /**
     * The registration alert, to everybody on the notification list.
     *
     * REPLY-TO IS THE PERSON WHO REGISTERED, not the event's address. A staff
     * member reading "somebody registered" who presses reply means to reply to
     * them, and this is the one message where that is true.
     */
    public static function send_alert( $event_id, $person ) {
        if ( ! self::on( $event_id, 'alert' ) ) {
            return 0;
        }

        $reply = ( ! empty( $person->email ) && is_email( $person->email ) )
            ? $person->email
            : SFAF_Reminders::reply_to_for( $event_id );

        /*
         * BUILT PER RECIPIENT, BECAUSE THE LINK IN IT IS PER RECIPIENT.
         *
         * There are exactly two versions of this message and the only
         * difference is where the button points, so they are built at most once
         * each and reused: a list of thirty people costs two builds, not
         * thirty. $variants is keyed on the capability, which is the whole of
         * what varies.
         *
         * THE CAPABILITY IS ASKED OF THE ACCOUNT, ONE PERSON AT A TIME. A team
         * is not a permission, so a team that resolves to an editor and a
         * contributor produces one of each message. A typed address carries
         * user_id 0 and can never reach the RSVP link, which is right: it is a
         * string in a box, not somebody with an account, and there is nothing
         * to check it against.
         */
        $variants = array();
        $sent     = 0;

        foreach ( self::staff_entries( $event_id ) as $email => $entry ) {
            $can = ( ! empty( $entry['user_id'] ) && SFAF_Portal::user_can_edit_event( (int) $entry['user_id'], (int) $event_id ) );
            $key = $can ? 'view' : 'public';

            if ( ! isset( $variants[ $key ] ) ) {
                $variants[ $key ] = self::build( 'alert', $event_id, $person, array( 'can_edit_event' => $can ) );
            }
            $built = $variants[ $key ];
            if ( ! $built ) {
                continue;
            }

            if ( SFAF_Email::send( $email, $built['subject'], $built['html'], $built['text'], $reply ) ) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * Tell the notification list that somebody cancelled.
     *
     * THE LISTENER ON uc_rsvp_cancelled, WHICH FIRED INTO NOTHING UNTIL 3.56.0.
     * The hook has been in SFAF_Reminders::cancel_rsvp() since the cancel link
     * was built and nothing had ever subscribed to it. Following a series had
     * exactly this shape before 3.53.0, so it was checked rather than assumed.
     *
     * ONE MESSAGE FOR EVERYBODY, unlike the alert and the summary. Those build
     * per recipient because each carries a button whose destination depends on
     * what that person may open. This one has no button, so there is nothing to
     * vary and one build serves the whole list.
     *
     * THE PERSON IS LOOKED UP HERE. The hook carries the event id and the
     * address only, and the message names who cancelled, so the row is read
     * back. It has already been moved to 'cancelled' by the time this runs,
     * which is why the lookup does not filter on status.
     *
     * THE ROW, WHEN THE HOOK CARRIES IT (3.105.0). A removal by staff passes
     * the id of the one row it released, which is the only way to name
     * somebody who registered without an email: every such row has the
     * address '', so looking up by address would name whoever was newest.
     *
     * @param int    $event_id
     * @param string $email
     * @param int    $rsvp_id 0 when the hook did not carry one.
     * @return int How many were sent.
     */
    public static function send_cancel_alert( $event_id, $email, $rsvp_id = 0 ) {
        $event_id = (int) $event_id;

        if ( ! self::on( $event_id, 'cancel_alert' ) ) {
            return 0;
        }

        /*
         * THE COUNT IS READ AFTER THE CACHE IS CLEARED. cancel_rsvp() clears it
         * before firing this hook, so this read is the post-cancellation number.
         * It is resolved once and handed to the builder rather than read inside
         * it, for the reason the builder gives.
         */
        $count = (int) sfaf_get_rsvp_count( $event_id );

        global $wpdb;
        $table = $wpdb->prefix . 'uc_rsvps';
        $row   = ( (int) $rsvp_id > 0 )
            ? $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND event_id = %d",
                (int) $rsvp_id,
                $event_id
            ) )
            : $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND email = %s ORDER BY id DESC LIMIT 1",
                $event_id,
                (string) $email
            ) );

        $person = (object) array(
            'name'  => $row ? SFAF_RSVP::display_name( $row ) : '',
            'email' => (string) $email,
        );

        $built = self::build( 'cancel_alert', $event_id, $person, array( 'count' => $count ) );
        if ( ! $built ) {
            return 0;
        }

        /*
         * REPLY-TO IS THE EVENT'S, NOT THE PERSON'S. The registration alert
         * replies to the registrant because a staff member reading it may want
         * to answer them. Somebody who has just cancelled is not waiting for a
         * reply, and putting their address in Reply-To on a message to a whole
         * list invites one they did not ask for.
         */
        $reply = SFAF_Reminders::reply_to_for( $event_id );

        $sent = 0;
        foreach ( self::staff( $event_id ) as $to => $label ) {
            if ( SFAF_Email::send( $to, $built['subject'], $built['html'], $built['text'], $reply ) ) {
                $sent++;
            }
        }
        return $sent;
    }

    /* =====================================================================
     * The pre-event summary
     * ================================================================== */

    /**
     * Events whose summary is due: starting within the lead time, not started,
     * native, and not already claimed.
     *
     * @return int[]
     */
    public static function summary_due_events() {
        $tz    = wp_timezone();
        $now   = new DateTime( 'now', $tz );
        $today = $now->format( 'Y-m-d' );

        $query = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => '_uc_event_date', 'value' => $today ),
                array( 'key' => self::SUMMARY_CLAIM_META, 'compare' => 'NOT EXISTS' ),
            ),
        ) );

        $due = array();
        foreach ( $query->posts as $id ) {
            // Same reasoning as SFAF_Reminders::due_events(), and the same one
            // question asked, so the two jobs cannot come to different
            // conclusions about the same event. See SFAF_Cancellation.
            if ( SFAF_Cancellation::skip_scheduled( $id ) ) {
                continue;
            }
            if ( SFAF_Reminders::is_imported( $id ) ) {
                continue;
            }
            if ( ! self::on( $id, 'summary' ) ) {
                continue;
            }
            $start = self::start_datetime( $id );
            if ( ! $start ) {
                // No start time means no "two hours before" to compute. The
                // reminder still goes out in the morning; this one does not
                // invent a time to be early to.
                continue;
            }
            $lead = (int) $start->format( 'U' ) - self::SUMMARY_LEAD_SECONDS;
            if ( (int) $now->format( 'U' ) < $lead ) {
                continue; // too early
            }
            if ( $now > $start ) {
                continue; // it has already started; a "starting soon" now is noise
            }
            $due[] = (int) $id;
        }
        return $due;
    }

    /** The event's start as a DateTime in the site timezone, or null. */
    private static function start_datetime( $event_id ) {
        $date  = (string) get_post_meta( $event_id, '_uc_event_date', true );
        $start = (string) get_post_meta( $event_id, '_uc_start_time', true );
        if ( '' === $date || '' === $start ) {
            return null;
        }
        try {
            return new DateTime( $date . ' ' . $start, wp_timezone() );
        } catch ( Exception $e ) {
            return null;
        }
    }

    /**
     * Send one event's summary.
     *
     * THE CLAIM IS add_post_meta() WITH $unique = true, which is the same trick
     * SFAF_Cron uses with add_option(): the row either does not exist and this
     * creates it, or it does and this returns false. Two overlapping runs
     * cannot both send. The claim is taken BEFORE the mail goes out, so an
     * interruption mid-send costs one summary rather than sending it twice.
     *
     * BUILT PER RECIPIENT, exactly as send_alert() is, and for the same reason:
     * the button opens a caladmin screen that not everybody on the notification
     * list may open. Two versions at most, cached on the capability, so a list
     * of thirty people costs two builds.
     *
     * THE PUBLIC VERSION DOUBLES AS THE PROBE. "Is there anything to send at
     * all" does not depend on who is reading, so it is asked once, before the
     * claim is taken, and the answer is the version most recipients get anyway.
     * A null there still means nobody registered, and still means the claim is
     * not taken, so somebody registering in the next hour can still be summed
     * up.
     *
     * @return array{recipients:int,sent:int,skipped:string}
     */
    public static function send_summary_for_event( $event_id ) {
        $out = array( 'recipients' => 0, 'sent' => 0, 'skipped' => '' );

        $variants = array( 'public' => self::build( 'summary', $event_id ) );
        if ( ! $variants['public'] ) {
            // Nobody registered. Not sent, and not claimed either: if somebody
            // registers in the next hour the summary can still go out.
            $out['skipped'] = 'nobody registered';
            return $out;
        }

        if ( ! add_post_meta( $event_id, self::SUMMARY_CLAIM_META, time(), true ) ) {
            $out['skipped'] = 'already sent';
            return $out;
        }

        $reply = SFAF_Reminders::reply_to_for( $event_id );

        /*
         * THE CAPABILITY IS ASKED OF THE ACCOUNT THE RESOLUTION FOUND, never of
         * the address. staff_entries() is the same one resolution the alert
         * uses: it carries a user id per address, and that id is 0 for a
         * free-text address even when the string matches somebody's account,
         * because a typed address is not that person on this list. Looking the
         * address up here with get_user_by( 'email' ) would answer a different
         * question and hand a caladmin link to a string in a box.
         *
         * A team is resolved to people and each person is asked separately: a
         * team is a set of names, not a permission.
         */
        foreach ( self::staff_entries( $event_id ) as $email => $entry ) {
            $can = ( ! empty( $entry['user_id'] ) && SFAF_Portal::user_can_edit_event( (int) $entry['user_id'], $event_id ) );
            $key = $can ? 'edit' : 'public';

            if ( ! isset( $variants[ $key ] ) ) {
                $variants[ $key ] = self::build( 'summary', $event_id, null, array( 'can_edit_event' => $can ) );
            }
            $built = $variants[ $key ];
            if ( ! $built ) {
                continue;
            }

            $out['recipients']++;
            if ( SFAF_Email::send( $email, $built['subject'], $built['html'], $built['text'], $reply ) ) {
                $out['sent']++;
            }
        }
        return $out;
    }

    /**
     * The summary pass, for SFAF_Cron.
     *
     * @return array{status:string,summary:string,counts:array}
     */
    public static function run_summaries() {
        $counts = array( 'events' => 0, 'recipients' => 0, 'sent' => 0, 'skipped' => 0 );

        $events = self::summary_due_events();
        foreach ( $events as $event_id ) {
            $one = self::send_summary_for_event( $event_id );
            if ( '' !== $one['skipped'] ) {
                $counts['skipped']++;
                continue;
            }
            $counts['events']++;
            $counts['recipients'] += $one['recipients'];
            $counts['sent']       += $one['sent'];
        }

        if ( ! $counts['events'] ) {
            return array(
                'status'  => 'ok',
                'summary' => 'Nothing due: no event today is within two hours of starting with somebody registered.',
                'counts'  => $counts,
            );
        }

        return array(
            'status'  => 'ok',
            'summary' => sprintf(
                '%d event%s: %d sent to %d recipient%s.',
                $counts['events'], 1 === $counts['events'] ? '' : 's',
                $counts['sent'], $counts['recipients'], 1 === $counts['recipients'] ? '' : 's'
            ),
            'counts'  => $counts,
        );
    }

    /* =====================================================================
     * The day before (3.102.0)
     * ================================================================== */

    /**
     * Events tomorrow whose day-before count is due now: native, published,
     * not cancelled, switched on, not already done, and the morning-of
     * reminder hour reached today.
     *
     * @return int[]
     */
    public static function day_before_due_events() {
        $tz  = wp_timezone();
        $now = new DateTime( 'now', $tz );
        $due = new DateTime( $now->format( 'Y-m-d' ) . ' ' . sprintf( '%02d:00', SFAF_Reminders::DUE_HOUR ), $tz );
        if ( $now < $due ) {
            return array();
        }
        $tomorrow = ( clone $now )->modify( '+1 day' )->format( 'Y-m-d' );

        $query = new WP_Query( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => '_uc_event_date', 'value' => $tomorrow ),
                array( 'key' => self::DAY_BEFORE_DONE_META, 'compare' => 'NOT EXISTS' ),
            ),
        ) );

        $out = array();
        foreach ( $query->posts as $id ) {
            // The same three questions the reminder and the summary ask, in
            // the same order, so no job reaches a different answer about the
            // same event.
            if ( SFAF_Cancellation::skip_scheduled( $id ) ) {
                continue;
            }
            if ( SFAF_Reminders::is_imported( $id ) ) {
                continue;
            }
            if ( ! self::on( $id, 'day_before' ) ) {
                continue;
            }
            $out[] = (int) $id;
        }
        return $out;
    }

    /**
     * Send one event's day-before count.
     *
     * NOBODY REGISTERED: NOT SENT AND NOT MARKED DONE, the summary's rule, so
     * somebody registering later that day is still counted.
     *
     * EACH SEND IS CLAIMED IN THE LEDGER FIRST, keyed "day_before|address" on
     * this event, which is a different key from the morning-of reminder's for
     * the same person on the same event. A second pass fails the claim.
     *
     * @return array{recipients:int,sent:int,already:int,skipped:string}
     */
    public static function send_day_before_for_event( $event_id ) {
        $event_id = (int) $event_id;
        $out      = array( 'recipients' => 0, 'sent' => 0, 'already' => 0, 'skipped' => '' );

        $variants = array( 'public' => self::build( 'day_before', $event_id, null, array( 'can_edit_event' => false ) ) );
        if ( ! $variants['public'] ) {
            $out['skipped'] = 'nobody registered';
            return $out;
        }

        $reply = SFAF_Reminders::reply_to_for( $event_id );

        foreach ( self::staff_entries( $event_id ) as $email => $entry ) {
            $can = ( ! empty( $entry['user_id'] ) && SFAF_Portal::user_can_edit_event( (int) $entry['user_id'], $event_id ) );
            $key = $can ? 'edit' : 'public';
            if ( ! isset( $variants[ $key ] ) ) {
                $variants[ $key ] = self::build( 'day_before', $event_id, null, array( 'can_edit_event' => $can ) );
            }
            $built = $variants[ $key ];
            if ( ! $built ) {
                continue;
            }

            $out['recipients']++;
            $claim = SFAF_Reminders::claim_key( $event_id, 'day_before|' . $email, $email, 'day_before' );
            if ( ! $claim ) {
                $out['already']++;
                continue;
            }
            $sent = SFAF_Email::send( $email, $built['subject'], $built['html'], $built['text'], $reply );
            SFAF_Reminders::finish_row( $claim['id'], $sent ? 'sent' : 'failed' );
            if ( $sent ) {
                $out['sent']++;
            }
        }

        update_post_meta( $event_id, self::DAY_BEFORE_DONE_META, time() );
        return $out;
    }

    /**
     * The day-before pass, for SFAF_Cron.
     *
     * @return array{status:string,summary:string,counts:array}
     */
    public static function run_day_before() {
        $counts = array( 'events' => 0, 'recipients' => 0, 'sent' => 0, 'already' => 0, 'skipped' => 0 );
        foreach ( self::day_before_due_events() as $event_id ) {
            $one = self::send_day_before_for_event( $event_id );
            if ( '' !== $one['skipped'] ) {
                $counts['skipped']++;
                continue;
            }
            $counts['events']++;
            $counts['recipients'] += $one['recipients'];
            $counts['sent']       += $one['sent'];
            $counts['already']    += $one['already'];
        }

        if ( ! $counts['events'] ) {
            return array(
                'status'  => 'ok',
                'summary' => 'Nothing due: no event tomorrow has somebody registered and a count still to send.',
                'counts'  => $counts,
            );
        }
        return array(
            'status'  => 'ok',
            'summary' => sprintf(
                '%d event%s tomorrow: %d sent to %d recipient%s, %d already recorded.',
                $counts['events'], 1 === $counts['events'] ? '' : 's',
                $counts['sent'], $counts['recipients'], 1 === $counts['recipients'] ? '' : 's',
                $counts['already']
            ),
            'counts'  => $counts,
        );
    }
}
