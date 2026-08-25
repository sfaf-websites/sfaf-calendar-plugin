<?php
/**
 * The four emails, who gets them, and whether they are switched on.
 *
 * NONE OF THIS HAS EVER RUN. See the note at the top of SFAF_Email.
 *
 * THE FOUR
 * ---------------------------------------------------------------------------
 *   confirmation  to the person, the moment they register
 *   reminder      to everybody registered on the morning of the event, with the
 *                 notification list copied in (sent by SFAF_Reminders)
 *   alert         to the notification list, the moment somebody registers
 *   summary       to the notification list, two hours before the event, listing
 *                 who is coming
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
 * the picker, and everybody on it gets all three staff emails.
 *
 * ALL OR NOTHING PER PERSON, ON PURPOSE. Per-recipient control means a matrix
 * of four toggles times N people, on a screen a manager visits to set up an
 * event, and nobody has asked for it. The event-level switches below turn a
 * whole kind of email off for everybody, which is the case that actually comes
 * up.
 *
 * DEFAULT ON, AND STORED AS THE EXCEPTION.
 * ---------------------------------------------------------------------------
 * All four are on for an event that says nothing, so creating an event is
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
            'reminder' => array(
                'label' => 'Morning-of reminder to everybody registered',
                'note'  => 'Sent at 6 am on the day, or at midnight when the event starts earlier. Your notification list is copied in.',
            ),
            'summary' => array(
                'label' => 'Who is coming, to your notification list, two hours before',
                'note'  => 'Lists everybody registered. Nothing is sent when nobody has registered.',
            ),
        );
    }

    /**
     * Is this kind switched on for this event?
     *
     * Absent meta means on. That is what makes the common path free: an event
     * nobody has configured gets all four.
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

    /** How many of the four are off, for the disclosure summary line. */
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
                return self::build_confirmation( $event_id, $person );
            case 'reminder':
                return self::build_reminder( $event_id, $person );
            case 'alert':
                return self::build_alert( $event_id, $person, $context );
            case 'summary':
                return self::build_summary( $event_id, $context );
            case 'cancelled':
                return self::build_cancelled( $event_id, $person );
            case 'changed':
                return self::build_changed( $event_id, $person, $context );
        }
        return null;
    }

    /** The event's facts, once, for every builder. */
    private static function facts( $event_id ) {
        $date  = (string) get_post_meta( $event_id, '_uc_event_date', true );
        $start = (string) get_post_meta( $event_id, '_uc_start_time', true );
        $end   = (string) get_post_meta( $event_id, '_uc_end_time', true );

        return array(
            'title'    => get_the_title( $event_id ),
            'date'     => sfaf_ap_date( $date, 'full' ),
            'time'     => sfaf_ap_time_range( $start, $end ),
            'location' => sfaf_event_location( $event_id ),
            'url'      => (string) get_permalink( $event_id ),
        );
    }

    /** The detail rows every message shows, in the same order every time. */
    private static function detail_rows( $f ) {
        return array(
            'Event'    => $f['title'],
            'Date'     => $f['date'],
            'Time'     => $f['time'],
            'Location' => $f['location'],
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
    private static function build_confirmation( $event_id, $person ) {
        $f = self::facts( $event_id );

        /*
         * THE GREETING IS THE FIRST NAME. "You are registered, Mark." is how a
         * person is addressed; "You are registered, Mark Sapoznikov." is how a
         * database addresses a record, and it lands in front of somebody who
         * has just handed over their details for a health service. The full
         * name is still what the staff-facing messages and the list show, where
         * identifying somebody exactly is the point.
         *
         * $person->name is the fallback and it matters: a caller that predates
         * the split, or the test send, hands over one string. Falling back to
         * the whole of it is better than greeting nobody.
         */
        $first = ( $person && ! empty( $person->first_name ) ) ? trim( (string) $person->first_name ) : '';
        if ( '' === $first && $person && ! empty( $person->name ) ) {
            $first = trim( (string) $person->name );
        }
        $hello  = ( '' !== $first ) ? sprintf( 'You are registered, %s.', $first ) : 'You are registered.';
        $cancel = ( $person && ! empty( $person->token ) ) ? SFAF_Reminders::cancel_url( $person->token ) : '';

        // {attendee_name} in custom copy still means the whole name, because
        // that is what it has always meant and somebody's template says so.
        // {first_name} and {last_name} are the new pair, for a writer who wants
        // the greeting's reading.
        $tokens = array(
            'name'       => ( $person && ! empty( $person->name ) ) ? (string) $person->name : $first,
            'first_name' => $first,
            'last_name'  => ( $person && ! empty( $person->last_name ) ) ? (string) $person->last_name : '',
            'cancel_url' => $cancel,
        );

        $gcal = sfaf_google_calendar_url( $event_id );
        $ics  = sfaf_ics_url( $event_id );

        $custom = self::custom_body( $event_id, 'confirmation' );

        $html  = SFAF_Email::heading( $hello );
        if ( '' !== $custom ) {
            $html .= self::paragraphs( sfaf_replace_tokens( $custom, $event_id, $tokens ) );
        } else {
            $html .= SFAF_Email::para( 'We have your place. Here are the details.' );
        }
        $html .= SFAF_Email::details( self::detail_rows( $f ) );

        /*
         * ADD TO CALENDAR: A HEADING AND TWO SHORT LABELS.
         *
         * The pair read "Add to Google Calendar" and "Add to Apple or Outlook"
         * until 3.34.0, which wrapped to three lines and two inside their
         * buttons and made a matched pair of different heights. The words that
         * wrapped are the words both buttons shared, so they are said once,
         * above, and each button now carries only the thing that tells them
         * apart. The glyph is the plugin's own calendar mark and not a platform
         * logo; see SFAF_Email::icon() for why, and for what a reader sees when
         * their client blocks pictures.
         */
        $buttons = array();
        if ( $gcal ) { $buttons[] = SFAF_Email::button( $gcal, 'Google', 'primary', true, true ); }
        if ( $ics )  { $buttons[] = SFAF_Email::button( $ics, 'Apple or Outlook', 'outline', true, true ); }
        if ( $buttons ) {
            $html .= SFAF_Email::label( 'Add to calendar' );
            $html .= SFAF_Email::button_row( $buttons );
        }

        if ( $f['url'] ) {
            $html .= SFAF_Email::link_para( $f['url'], 'See the event page' );
        }
        if ( $cancel ) {
            $html .= SFAF_Email::rule();
            $html .= SFAF_Email::small_para(
                'Cannot make it? <a href="' . esc_url( $cancel ) . '" style="color:' . SFAF_Email::C_TEAL . ';">Release your place</a> so somebody else can take it. We will ask you to confirm.'
            );
        }

        $text = $hello . "\n\n";
        if ( '' !== $custom ) {
            $text .= sfaf_replace_tokens( $custom, $event_id, $tokens ) . "\n\n";
        } else {
            $text .= "We have your place. Here are the details.\n\n";
        }
        $text .= self::detail_text( $f ) . "\n\n";
        if ( $gcal || $ics ) { $text .= "Add to calendar\n"; }
        if ( $gcal ) { $text .= 'Google: ' . $gcal . "\n"; }
        if ( $ics )  { $text .= 'Apple or Outlook: ' . $ics . "\n"; }
        if ( $f['url'] ) { $text .= 'Event page: ' . $f['url'] . "\n"; }
        if ( $cancel ) {
            $text .= "\nCannot make it? Release your place so somebody else can take it. We will ask you to confirm: " . $cancel . "\n";
        }
        $text .= "\n" . SFAF_Email::POSTAL;

        return array(
            'subject' => self::subject( $event_id, 'confirmation', sprintf( 'You are registered for %s', $f['title'] ) ),
            'html'    => SFAF_Email::shell( sprintf( '%s, %s', $f['date'], $f['time'] ? $f['time'] : 'time to be confirmed' ), $html ),
            'text'    => $text,
        );
    }

    /** (b) THE MORNING-OF REMINDER. */
    private static function build_reminder( $event_id, $person ) {
        $f      = self::facts( $event_id );
        $cancel = ( $person && ! empty( $person->token ) ) ? SFAF_Reminders::cancel_url( $person->token ) : '';
        $staff  = ( $person && ! empty( $person->is_staff ) );
        $custom = self::custom_body( $event_id, 'reminder' );

        $head = $staff ? sprintf( '%s is today.', $f['title'] ) : 'Your event is today.';

        $html  = SFAF_Email::heading( $head );
        if ( '' !== $custom ) {
            $html .= self::paragraphs( sfaf_replace_tokens( $custom, $event_id, array( 'cancel_url' => $cancel ) ) );
        } elseif ( $staff ) {
            $html .= SFAF_Email::para( 'This is the copy of the reminder everybody registered has just been sent.' );
        }
        $html .= SFAF_Email::details( self::detail_rows( $f ) );

        if ( $f['url'] ) {
            $html .= SFAF_Email::button( $f['url'], 'See the event page', 'primary' );
        }
        if ( $cancel ) {
            $html .= SFAF_Email::rule();
            $html .= SFAF_Email::small_para(
                'Cannot make it? <a href="' . esc_url( $cancel ) . '" style="color:' . SFAF_Email::C_TEAL . ';">Release your place</a> so somebody else can take it. We will ask you to confirm.'
            );
        }

        $text = $head . "\n\n";
        if ( '' !== $custom ) {
            $text .= sfaf_replace_tokens( $custom, $event_id, array( 'cancel_url' => $cancel ) ) . "\n\n";
        } elseif ( $staff ) {
            $text .= "This is the copy of the reminder everybody registered has just been sent.\n\n";
        }
        $text .= self::detail_text( $f ) . "\n\n";
        if ( $f['url'] ) { $text .= 'Event page: ' . $f['url'] . "\n"; }
        if ( $cancel ) {
            $text .= "\nCannot make it? Release your place so somebody else can take it. We will ask you to confirm: " . $cancel . "\n";
        }
        $text .= "\n" . SFAF_Email::POSTAL;

        return array(
            'subject' => self::subject( $event_id, 'reminder', sprintf( 'Today: %s', $f['title'] ) ),
            'html'    => SFAF_Email::shell( sprintf( 'Today, %s', $f['time'] ? $f['time'] : $f['date'] ), $html ),
            'text'    => $text,
        );
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
     * event. That screen is gated on can_view_all, and the notification list is
     * not: it holds contributors, people reached through a team, and typed
     * addresses that are not accounts at all. A link that answers "Denied" is
     * worse than the public page, because it also tells somebody there is a
     * screen they are not allowed to see.
     *
     * Hence $context['can_view_all'], decided by send_alert() one recipient at
     * a time. Absent means false, so any caller that does not say gets the
     * public page, which is what everybody got before this existed.
     *
     * @param array $context can_view_all: bool.
     */
    private static function build_alert( $event_id, $person, $context = array() ) {
        $f     = self::facts( $event_id );
        $who   = ( $person && ! empty( $person->name ) ) ? (string) $person->name : 'Somebody';
        $email = ( $person && ! empty( $person->email ) ) ? (string) $person->email : '';
        $count = (int) sfaf_get_rsvp_count( $event_id );
        $cap   = (int) get_post_meta( $event_id, '_uc_capacity', true );

        $places = $cap > 0
            ? sprintf( '%d of %d places taken', $count, $cap )
            : sprintf( '%d registered so far', $count );

        $can_view = ! empty( $context['can_view_all'] );
        if ( $can_view ) {
            $link  = add_query_arg( 'event_id', (int) $event_id, SFAF_Portal::link( 'rsvps' ) );
            $label = 'See who has registered';
        } else {
            $link  = $f['url'];
            $label = 'Open this event';
        }

        $rows = array( 'Name' => $who, 'Email' => $email ) + self::detail_rows( $f );

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
     * (e) IT IS CANCELLED.
     *
     * NO CANCEL LINK, and that is not an oversight. Every other message to a
     * registrant carries one so they can release a place they cannot use. There
     * is no place to release here: the event is not happening, and offering to
     * cancel a registration for it would read as though something were still
     * required of them.
     *
     * The reason, when the organizer gave one, goes above the details rather
     * than below. Somebody reading "cancelled" wants to know why before they
     * want to be reminded when it was going to be.
     */
    private static function build_cancelled( $event_id, $person ) {
        $f      = self::facts( $event_id );
        $reason = trim( (string) get_post_meta( $event_id, '_uc_cancelled_reason', true ) );

        $first = ( $person && ! empty( $person->first_name ) ) ? trim( (string) $person->first_name ) : '';
        if ( '' === $first && $person && ! empty( $person->name ) ) {
            $first = trim( (string) $person->name );
        }

        $head = sprintf( '%s is cancelled.', $f['title'] );

        $html  = SFAF_Email::heading( $head );
        $html .= SFAF_Email::para(
            ( '' !== $first ? $first . ', this' : 'This' )
            . ' event is not going ahead, and you do not need to do anything.'
        );
        if ( '' !== $reason ) {
            $html .= SFAF_Email::para( $reason );
        }
        $html .= SFAF_Email::para( 'It was going to be:' );
        $html .= SFAF_Email::details( self::detail_rows( $f ) );
        /*
         * EVERYBODY READING THIS HOLDS A PLACE. Until 3.53.0 this sentence had
         * a second version for somebody who had pressed "Get Reminders" and had
         * no registration to have kept. That row no longer exists, so there is
         * one sentence again and it is true of every reader.
         */
        $html .= SFAF_Email::small_para(
            'Your registration has been kept as a record that you signed up. Nothing else will be sent about this event.'
        );

        $text  = $head . "\n\n";
        $text .= ( '' !== $first ? $first . ', this' : 'This' ) . " event is not going ahead, and you do not need to do anything.\n\n";
        if ( '' !== $reason ) {
            $text .= $reason . "\n\n";
        }
        $text .= "It was going to be:\n\n";
        $text .= self::detail_text( $f ) . "\n\n";
        $text .= "Your registration has been kept as a record that you signed up. Nothing else will be sent\nabout this event.\n";
        $text .= "\n" . SFAF_Email::POSTAL;

        return array(
            'subject' => sprintf( 'Cancelled: %s', $f['title'] ),
            'html'    => SFAF_Email::shell( sprintf( 'Cancelled, %s', $f['date'] ), $html ),
            'text'    => $text,
        );
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
        $f       = self::facts( $event_id );
        $changes = ( isset( $context['changes'] ) && is_array( $context['changes'] ) ) ? $context['changes'] : array();
        $cancel  = ( $person && ! empty( $person->token ) ) ? SFAF_Reminders::cancel_url( $person->token ) : '';

        $first = ( $person && ! empty( $person->first_name ) ) ? trim( (string) $person->first_name ) : '';
        if ( '' === $first && $person && ! empty( $person->name ) ) {
            $first = trim( (string) $person->name );
        }

        /*
         * THE HEADLINE NAMES THE CHANGE WHEN THERE IS EXACTLY ONE, because that
         * is the case where a sentence can carry the whole message and the
         * reader is done. With two or more it says so and the list below does
         * the work; a headline trying to hold three moves is a headline nobody
         * finishes.
         */
        if ( 1 === count( $changes ) ) {
            $only  = key( $changes );
            $pair  = current( $changes );
            $head  = sprintf( '%s has a new %s.', $f['title'], strtolower( $only ) );
            $lead  = sprintf(
                'It has moved from %s to %s.',
                $pair['from'],
                $pair['to']
            );
        } else {
            $head = sprintf( '%s has changed.', $f['title'] );
            $lead = 'Some details have moved. Here is what is different.';
        }

        $html  = SFAF_Email::heading( $head );
        $html .= SFAF_Email::para( ( '' !== $first ? $first . ', ' : '' ) . lcfirst( $lead ) );

        if ( count( $changes ) > 1 ) {
            $rows = array();
            foreach ( $changes as $label => $pair ) {
                $rows[ $label ] = $pair['from'] . '  to  ' . $pair['to'];
            }
            $html .= SFAF_Email::details( $rows );
        }

        $html .= SFAF_Email::para( 'The event is now:' );
        $html .= SFAF_Email::details( self::detail_rows( $f ) );

        $gcal = sfaf_google_calendar_url( $event_id );
        $ics  = sfaf_ics_url( $event_id );
        $buttons = array();
        if ( $gcal ) { $buttons[] = SFAF_Email::button( $gcal, 'Google', 'primary', true, true ); }
        if ( $ics )  { $buttons[] = SFAF_Email::button( $ics, 'Apple or Outlook', 'outline', true, true ); }
        if ( $buttons ) {
            // The old entry in somebody's calendar is now wrong, so the one
            // thing they most likely need is a corrected one.
            $html .= SFAF_Email::label( 'Update your calendar' );
            $html .= SFAF_Email::button_row( $buttons );
        }

        if ( $f['url'] ) {
            $html .= SFAF_Email::link_para( $f['url'], 'See the event page' );
        }
        if ( $cancel ) {
            $html .= SFAF_Email::rule();
            $html .= SFAF_Email::small_para(
                'Cannot make the new time? <a href="' . esc_url( $cancel ) . '" style="color:' . SFAF_Email::C_TEAL . ';">Release your place</a> so somebody else can take it. We will ask you to confirm.'
            );
        }

        $text  = $head . "\n\n";
        $text .= ( '' !== $first ? $first . ', ' : '' ) . lcfirst( $lead ) . "\n\n";
        if ( count( $changes ) > 1 ) {
            foreach ( $changes as $label => $pair ) {
                $text .= $label . ': ' . $pair['from'] . ' to ' . $pair['to'] . "\n";
            }
            $text .= "\n";
        }
        $text .= "The event is now:\n\n" . self::detail_text( $f ) . "\n\n";
        if ( $gcal || $ics ) { $text .= "Update your calendar\n"; }
        if ( $gcal ) { $text .= 'Google: ' . $gcal . "\n"; }
        if ( $ics )  { $text .= 'Apple or Outlook: ' . $ics . "\n"; }
        if ( $f['url'] ) { $text .= 'Event page: ' . $f['url'] . "\n"; }
        if ( $cancel ) {
            $text .= "\nCannot make the new time? Release your place so somebody else can take it. We will ask\nyou to confirm: " . $cancel . "\n";
        }
        $text .= "\n" . SFAF_Email::POSTAL;

        return array(
            'subject' => sprintf( 'Changed: %s', $f['title'] ),
            'html'    => SFAF_Email::shell( sprintf( 'Now %s, %s', $f['date'], $f['time'] ? $f['time'] : 'time to be confirmed' ), $html ),
            'text'    => $text,
        );
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

        $cap    = (int) get_post_meta( $event_id, '_uc_capacity', true );
        $places = $cap > 0 ? sprintf( '%d of %d places taken', $n, $cap ) : sprintf( '%d registered', $n );

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
        $html .= SFAF_Email::people_table( $rows );
        if ( $link ) {
            $html .= SFAF_Email::button( $link, $label, 'primary' );
        }

        $text  = sprintf( "%s starts soon\n\n%s. Here is who to expect.\n\n", $f['title'], $places );
        $text .= self::detail_text( $f ) . "\n\n";
        foreach ( $rows as $row ) {
            // The full name here, and in the HTML table beside it: this is the
            // list an organizer reads at the door, where telling two people
            // apart is the point. display_name() is the one place that joins
            // the pair.
            $name = SFAF_RSVP::display_name( $row );
            $text .= '- ' . ( '' !== $name ? $name : 'No name given' ) . ' <' . $row->email . '>' . "\n";
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
     * Custom copy, when somebody has written some.
     *
     * The event's own field wins, then the site-wide default in Settings, then
     * nothing, which means the shipped copy above. The shell, the details table
     * and the buttons are not customisable: those are the parts that have to be
     * right, and a manager who wants different wording wants different WORDING.
     */
    private static function custom_body( $event_id, $kind ) {
        $per_event = array( 'confirmation' => '_uc_email_body' );
        if ( isset( $per_event[ $kind ] ) ) {
            $own = trim( (string) get_post_meta( $event_id, $per_event[ $kind ], true ) );
            if ( '' !== $own ) {
                return $own;
            }
        }

        $settings = get_option( 'uc_settings', array() );
        $keys     = array( 'confirmation' => 'email_rsvp_body', 'reminder' => 'email_dayof_body' );
        if ( isset( $keys[ $kind ] ) && ! empty( $settings[ $keys[ $kind ] ] ) ) {
            return trim( (string) $settings[ $keys[ $kind ] ] );
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
        $settings = get_option( 'uc_settings', array() );
        $keys     = array( 'confirmation' => 'email_rsvp_subject', 'reminder' => 'email_dayof_subject' );
        if ( isset( $keys[ $kind ] ) && ! empty( $settings[ $keys[ $kind ] ] ) ) {
            return sfaf_replace_tokens( trim( (string) $settings[ $keys[ $kind ] ] ), $event_id, array() );
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
            $can = ( ! empty( $entry['user_id'] ) && SFAF_Portal::user_can_view_all( (int) $entry['user_id'] ) );
            $key = $can ? 'view' : 'public';

            if ( ! isset( $variants[ $key ] ) ) {
                $variants[ $key ] = self::build( 'alert', $event_id, $person, array( 'can_view_all' => $can ) );
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
}
