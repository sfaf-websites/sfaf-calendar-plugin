<?php
/**
 * ONLINE EVENTS: no address, and a meeting link that is a credential.
 *
 * WHAT THIS IS. One tick on the event, default off. Ticking it says the event
 * has no place: the venue picker goes, "Online Event" is what renders wherever
 * an address would have, and a meeting link can be entered and sent out with
 * the confirmation, the morning-of reminder, both, or neither.
 *
 * WHAT THIS IS NOT, TODAY. There is no per-registrant screening. Everybody who
 * registers gets whatever the ticks select, and the approval workflow that will
 * sit in front of that is being decided separately. This build is shaped so
 * that arriving does not mean unpicking anything: the link is a field on the
 * EVENT, and which MESSAGE carries it is decided per message, at build time, by
 * sends_with(). A later release puts a per-person gate in front of the same
 * call and nothing else here moves.
 *
 * THE LINK IS A CREDENTIAL AND THAT IS THE WHOLE DESIGN CONSTRAINT.
 * ---------------------------------------------------------------------------
 * Anybody holding a Zoom link can join the meeting. This calendar carries HIV
 * services, substance use programmes and trans health groups, so the people in
 * that room are the thing being protected, not the URL.
 *
 * So the link NEVER REACHES A PUBLIC SURFACE. Not the event page, not a card,
 * not the sidebar, not the month grid, not the embed payload, not the REST
 * feed, not the satellite feed, not the search index, not the structured data,
 * not the .ics anybody can request. The only way it becomes public is an
 * organizer typing it into the description themselves, which is their decision
 * and not this feature's doing.
 *
 * THAT CLAIM IS ASSERTED AS A WHITELIST, NOT AS A CHECKLIST.
 * ---------------------------------------------------------------------------
 * Exactly the inversion SFAF_Privacy uses, for exactly its reason: a list of
 * places to hide from is only ever as complete as the person writing it, and
 * the failure here is a renderer nobody thought of. So link() is the ONE reader
 * of the meta, .claude/online-events-test.php enumerates every place in the
 * source that names the key or calls link(), and anything not on the whitelist
 * FAILS. A field added later cannot leak it silently, because it will be
 * neither excluded nor listed.
 *
 * The test also renders for real: it builds every public payload for an online
 * event carrying a known link and asserts the string is not in the bytes. The
 * whitelist proves nothing NEW reads it; the render proves the readers that
 * already exist do not print it.
 *
 * THE .ics, AND WHY IT IS WIDER THAN THE EMAIL.
 * ---------------------------------------------------------------------------
 * The link may go into the calendar file ONLY on the confirmation's copy, which
 * is the case where the person already holds it. Mark has decided this and it
 * is recorded rather than assumed, because it is genuinely wider distribution
 * than the email: a calendar entry syncs to the person's phone, to their laptop
 * and to any calendar they have shared with a partner, an assistant or a
 * household, and anybody with sight of that calendar can read the link.
 *
 * The .ics endpoint is public and addressed by post id, so a plain opt-in flag
 * would be walkable in four digits, which is the mistake SFAF_Privacy already
 * made once on this exact route. The join copy therefore requires `j=`, an HMAC
 * over the event id keyed on the site's auth salt, and only ics_url_with_link()
 * builds one. It is stateless on purpose: no row, no registrant, nothing for a
 * per-registrant gate to have to unpick later.
 *
 * WHEN THE LINK IS ONLY GOING OUT WITH THE MORNING-OF REMINDER IT IS NOT IN THE
 * .ics AT ALL, because the .ics is offered by the confirmation.
 *
 * NEITHER A VENUE NOR A LOCATION SURVIVES THE TICK.
 * ---------------------------------------------------------------------------
 * SFAF_Venues' rule is that an event has EITHER a venue reference OR its own
 * location text, never both, so nothing downstream has to decide which wins.
 * Online is a third case and it obeys that rule the same way: set() clears the
 * term, the composed line and the four address parts, so there is no stale
 * value for a renderer added next year to find and believe.
 *
 * Turning the tick back OFF does not restore an address, because nothing is
 * kept to restore it from. It also clears the link and the delivery ticks, so
 * an in-person event never carries a meeting URL nothing reads: an orphan
 * credential sitting in meta is precisely the thing a future field leaks.
 *
 * AN IMPORTED EVENT IS NOT OFFERED THIS. Eventbrite and GoFundMe Pro own the
 * location field and write it on every fetch, and neither has any concept of
 * this tick, so an online event from a platform says so in the text the
 * platform sends. Refusing costs nothing: the control is not rendered there,
 * the save is gated on the same lock the location field is, and
 * SFAF_Sources::import_event() refuses these three keys outright, the way it
 * already refuses the privacy flag.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Online {

    /** '1' when the event is online. Absent means in person, so a default costs no writes. */
    const META = '_uc_online';

    /**
     * The meeting link.
     *
     * READ THROUGH link() AND NOWHERE ELSE. This constant is named only in the
     * places the whitelist in .claude/online-events-test.php allows, and the
     * sweep fails on any other.
     */
    const META_LINK = '_uc_meeting_url';

    /** Which messages carry the link: an array of keys from deliveries(). */
    const META_SEND = '_uc_online_send';

    /** What renders wherever an address would have. */
    const LABEL = 'Online Event';

    /**
     * Every meta key this feature owns.
     *
     * ONE LIST, so the recurrence copy, the group write and the import refusal
     * cannot fall out of step with each other. A key added here travels, and is
     * refused where it should be refused, without four separate edits.
     *
     * @return string[]
     */
    public static function meta_keys() {
        return array( self::META, self::META_LINK, self::META_SEND );
    }

    /**
     * The messages a link can ride on, key => label.
     *
     * TICKBOXES, NOT A CHOICE. Both, one, or neither: a group that meets weekly
     * may want the link on the morning-of reminder only, and a one-off workshop
     * may want it the moment somebody registers. Nothing about the two is
     * exclusive, so nothing here makes them look it.
     *
     * The keys are SFAF_Notifications kinds on purpose. build_confirmation()
     * and build_reminder() each ask sends_with() for their own kind, so a third
     * message later is a key here and one call there.
     *
     * @return array<string,string>
     */
    public static function deliveries() {
        return array(
            /*
             * THE CALENDAR CONSEQUENCE IS IN THIS LABEL, NOT IN A PARAGRAPH
             * UNDER BOTH TICKS.
             *
             * Only this tick puts the link in the calendar file, so only this
             * tick says so, and it says so where the decision is being made.
             * The reminder carries no calendar file at all, which is why its
             * label is the plain one.
             */
            'confirmation' => 'Send the link with the registration confirmation (also added to their calendar file)',
            'reminder'     => 'Send the link with the morning-of reminder',
        );
    }

    /* =====================================================================
     * Reading
     * ================================================================== */

    /** Whether this event has no place. */
    public static function is_online( $event_id ) {
        return '1' === (string) get_post_meta( (int) $event_id, self::META, true );
    }

    /**
     * The meeting link. THE ONE READER OF THE META.
     *
     * Returns '' for an event that is not online, whatever is stored, so a
     * caller that forgets to ask is_online() first cannot print a link
     * belonging to a state the event is no longer in. set() clears the key
     * anyway; this is the second mechanism, and both are cheap.
     *
     * @param int $event_id
     * @return string '' when there is no link, or the event is not online.
     */
    public static function link( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! self::is_online( $event_id ) ) {
            return '';
        }
        return trim( (string) get_post_meta( $event_id, self::META_LINK, true ) );
    }

    /** Whether a link has actually been entered. */
    public static function has_link( $event_id ) {
        return '' !== self::link( $event_id );
    }

    /**
     * Which messages this event's link rides on.
     *
     * STORED AS THE SET THAT IS ON, which is the opposite of
     * SFAF_Notifications::OFF_META and right for the opposite reason: these
     * default to OFF, so an event nobody has configured stores nothing and
     * sends nothing. There is no ambiguity to resolve; absent means neither.
     *
     * @return string[]
     */
    public static function sends( $event_id ) {
        if ( ! self::is_online( (int) $event_id ) ) {
            return array();
        }
        $stored = get_post_meta( (int) $event_id, self::META_SEND, true );
        $stored = is_array( $stored ) ? $stored : array();
        return array_values( array_intersect( array_keys( self::deliveries() ), array_map( 'strval', $stored ) ) );
    }

    /**
     * Does this message carry joining details at all?
     *
     * TRUE WITH NO LINK ENTERED, and that is the point. The tick selects the
     * MESSAGE; whether that message carries a URL or the sentence saying one is
     * coming is decided by whether a link exists. A manager who ticks
     * "confirmation" before the room is booked still gets the promise sent on
     * their behalf, which is the honest thing to tell somebody who has just
     * registered for a meeting with no address.
     *
     * @param int    $event_id
     * @param string $kind A key from deliveries().
     */
    public static function sends_with( $event_id, $kind ) {
        return in_array( (string) $kind, self::sends( $event_id ), true );
    }

    /* =====================================================================
     * Writing
     * ================================================================== */

    /**
     * Set the whole state at once: online or not, the link, the deliveries.
     *
     * ONE ENTRY POINT, because the three are not independent. Online with a
     * stale venue is a contradiction, in person with a meeting link is an
     * orphan credential, and a delivery tick on an event that is not online
     * selects a message that will never carry anything. All three are reachable
     * by writing the keys separately, so nothing writes them separately.
     *
     * @param int      $event_id
     * @param bool     $online
     * @param string   $link       Raw; sanitized here.
     * @param string[] $deliveries Keys from deliveries(); anything else dropped.
     */
    public static function set( $event_id, $online, $link = '', $deliveries = array() ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return;
        }

        if ( ! $online ) {
            /*
             * OFF CLEARS EVERYTHING THIS FEATURE OWNS.
             *
             * Not "leave the link in case they tick it back on". A URL nothing
             * reads is exactly the shape of value a field added later picks up
             * and prints, and this one lets somebody into a support group. The
             * cost of clearing it is retyping a link; the cost of keeping it is
             * unbounded and lands on somebody else.
             */
            foreach ( self::meta_keys() as $key ) {
                delete_post_meta( $event_id, $key );
            }
            return;
        }

        update_post_meta( $event_id, self::META, '1' );

        /*
         * THE PLACE GOES, ALL OF IT.
         *
         * The term, the composed line and the four parts, in one place, so no
         * combination of them survives into a state that says the event has no
         * address. See the file header and class-sfaf-venues.php.
         */
        SFAF_Venues::set_for_event( $event_id, 0 );
        delete_post_meta( $event_id, '_uc_location' );
        foreach ( sfaf_location_part_keys() as $key ) {
            delete_post_meta( $event_id, $key );
        }

        $clean = esc_url_raw( trim( (string) $link ) );
        if ( '' !== $clean ) {
            update_post_meta( $event_id, self::META_LINK, $clean );
        } else {
            delete_post_meta( $event_id, self::META_LINK );
        }

        $valid = array_values( array_intersect(
            array_keys( self::deliveries() ),
            array_map( 'strval', (array) $deliveries )
        ) );
        if ( empty( $valid ) ) {
            delete_post_meta( $event_id, self::META_SEND );
        } else {
            update_post_meta( $event_id, self::META_SEND, $valid );
        }
    }

    /* =====================================================================
     * What an email says
     * ================================================================== */

    /** The sentence sent when the tick is on and no link has been entered yet. */
    const NO_LINK_YET = 'A link to join will be sent before the event.';

    /** The heading above the joining details in a message. */
    const JOIN_LABEL = 'Joining online';

    /**
     * The joining block for one message, as HTML.
     *
     * Returns '' when this message does not carry it, so a builder is one
     * concatenation and has no condition of its own to get wrong.
     *
     * @param int    $event_id
     * @param string $kind A key from deliveries().
     * @return string
     */
    public static function joining_html( $event_id, $kind ) {
        if ( ! self::sends_with( $event_id, $kind ) ) {
            return '';
        }
        $url  = self::link( $event_id );
        $html = SFAF_Email::label( self::JOIN_LABEL );

        if ( '' === $url ) {
            return $html . SFAF_Email::para( self::NO_LINK_YET );
        }

        /*
         * A BUTTON AND THE URL IN FULL UNDER IT. A meeting link is pasted into
         * a browser as often as it is clicked, and a button on its own gives
         * somebody nothing to copy. The full URL is also what a reader whose
         * client has stripped the styling ends up with.
         */
        return $html
            . SFAF_Email::button( $url, 'Join the event', 'primary' )
            . SFAF_Email::small_para( 'Or paste this into your browser: ' . esc_html( $url ) );
    }

    /**
     * The same block as plain text, already newline-terminated.
     *
     * @param int    $event_id
     * @param string $kind
     * @return string
     */
    public static function joining_text( $event_id, $kind ) {
        if ( ! self::sends_with( $event_id, $kind ) ) {
            return '';
        }
        $url = self::link( $event_id );
        if ( '' === $url ) {
            return self::JOIN_LABEL . "\n" . self::NO_LINK_YET . "\n\n";
        }
        return self::JOIN_LABEL . "\n" . $url . "\n\n";
    }

    /* =====================================================================
     * The .ics join token
     * ================================================================== */

    /** The query argument carrying the token. */
    const ICS_JOIN_ARG = 'j';

    /**
     * The token that lets one .ics request carry the link.
     *
     * KEYED ON THE EVENT ID AND THE SITE SALT, and deliberately NOT on the link
     * itself: an organizer correcting a Zoom URL must not break the button in
     * every confirmation already sent. wp_salt( 'auth' ) is published nowhere,
     * so the token cannot be computed from outside, which is the only property
     * this needs to have.
     *
     * @param int $event_id
     * @return string '' when there is nothing to authorise.
     */
    public static function ics_join_token( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id || ! self::is_online( $event_id ) ) {
            return '';
        }
        return substr( hash_hmac( 'sha256', 'sfaf-ics-join|' . $event_id, wp_salt( 'auth' ) ), 0, 32 );
    }

    /**
     * Is this .ics request the confirmation's copy?
     *
     * hash_equals rather than ===, because this is a secret comparison and a
     * timing-safe one costs nothing.
     *
     * @param int    $event_id
     * @param string $provided
     * @return bool
     */
    public static function ics_join_ok( $event_id, $provided ) {
        $expected = self::ics_join_token( $event_id );
        $provided = (string) $provided;
        if ( '' === $expected || '' === $provided ) {
            return false;
        }
        return hash_equals( $expected, $provided );
    }

    /**
     * The .ics address that carries the link, for the confirmation and nothing
     * else.
     *
     * BUILT ON sfaf_ics_url(), so a PRIVATE online event keeps its `k=` token
     * as well. The two gates are independent and both still apply.
     *
     * Returns the ordinary address when this event's link is not going out with
     * the confirmation, so the button in that email is never missing and never
     * carries more than it should.
     *
     * @param int $event_id
     * @return string
     */
    public static function ics_url_with_link( $event_id ) {
        $event_id = (int) $event_id;
        $url      = sfaf_ics_url( $event_id );

        if ( ! self::sends_with( $event_id, 'confirmation' ) || ! self::has_link( $event_id ) ) {
            return $url;
        }
        $token = self::ics_join_token( $event_id );
        if ( '' === $token ) {
            return $url;
        }
        return add_query_arg( self::ICS_JOIN_ARG, $token, $url );
    }
}
