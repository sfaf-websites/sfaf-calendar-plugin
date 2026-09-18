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

    /**
     * "This event runs both formats at once."
     *
     * A SEPARATE KEY FROM `_uc_online`, never both. See the block below
     * meta_keys() for why hybrid must not answer yes to is_online().
     */
    const META_HYBRID = '_uc_hybrid';

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
        return array( self::META, self::META_HYBRID, self::META_LINK, self::META_SEND );
    }

    /* =====================================================================
     * THE THIRD FORMAT (3.96.0)
     *
     * AN EVENT IS IN PERSON, ONLINE, OR HYBRID, and hybrid runs both at once.
     * It is a THIRD MODE rather than a second tick on top of online, because
     * the two ticks would have four combinations and only three of them mean
     * anything. mode() answers with one of three words and every reader asks
     * it, so "online and hybrid at the same time" is not a state that exists.
     *
     * HYBRID IS NOT `_uc_online`, AND THAT IS THE WHOLE REASON IT IS A SEPARATE
     * KEY. is_online() is what clears the address and puts "Online Event"
     * wherever a place would go, on every one of the surfaces this file's
     * header lists. A hybrid event HAS a place, so it must not answer yes to
     * that question, or the address it keeps would never be drawn.
     *
     * WHAT HYBRID SHARES WITH ONLINE IS THE LINK, so the two questions are
     * separated: is_online() is "has no place" and has_online_format() is "some
     * registrants join by link". link() and sends() gate on the second, which
     * is how the meeting link reaches a hybrid event without a second reader
     * of the meta being written. The whitelist in online-events-test.php is
     * unchanged, because link() is still the only thing that reads the key.
     * ================================================================== */

    const MODE_IN_PERSON = 'in_person';
    const MODE_ONLINE    = 'online';
    const MODE_HYBRID    = 'hybrid';

    /**
     * The three formats, key => label, in the order the editor offers them.
     *
     * @return array<string,string>
     */
    public static function modes() {
        return array(
            self::MODE_IN_PERSON => 'In person',
            self::MODE_ONLINE    => 'Online',
            self::MODE_HYBRID    => 'Hybrid',
        );
    }

    /**
     * Which of the three this event is. THE ONE READER OF BOTH KEYS.
     *
     * HYBRID IS ASKED FIRST. The two keys cannot both be set by set(), but a
     * direct write, an import or a half-finished migration could leave both,
     * and answering "online" for an event carrying the hybrid key would take
     * its address away. Deciding an order here means there is no combination
     * that has no answer.
     *
     * @param int $event_id
     * @return string One of the MODE_ constants.
     */
    public static function mode( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return self::MODE_IN_PERSON;
        }
        if ( '1' === (string) get_post_meta( $event_id, self::META_HYBRID, true ) ) {
            return self::MODE_HYBRID;
        }
        if ( '1' === (string) get_post_meta( $event_id, self::META, true ) ) {
            return self::MODE_ONLINE;
        }
        return self::MODE_IN_PERSON;
    }

    /** Whether this event runs both formats at once. */
    public static function is_hybrid( $event_id ) {
        return self::MODE_HYBRID === self::mode( (int) $event_id );
    }

    /**
     * Whether ANY registrant joins by link: online or hybrid.
     *
     * This is the question the meeting link, the delivery ticks and the join
     * copy all actually ask. is_online() asks the narrower one, "has no
     * place", and only the address and location renderers want that.
     *
     * @param int $event_id
     * @return bool
     */
    public static function has_online_format( $event_id ) {
        $mode = self::mode( (int) $event_id );
        return ( self::MODE_ONLINE === $mode || self::MODE_HYBRID === $mode );
    }

    /**
     * Whether ANY registrant turns up somewhere: in person or hybrid.
     *
     * @param int $event_id
     * @return bool
     */
    public static function has_in_person_format( $event_id ) {
        $mode = self::mode( (int) $event_id );
        return ( self::MODE_IN_PERSON === $mode || self::MODE_HYBRID === $mode );
    }

    /**
     * Normalize whatever a caller passed into one of the three modes.
     *
     * TRUE AND FALSE STILL MEAN WHAT THEY MEANT, because the import and the
     * older tests pass them and "online or not" was the only question when
     * they were written. Anything unrecognised is in person, which is the mode
     * that grants nothing.
     *
     * @param mixed $mode
     * @return string
     */
    public static function normalize_mode( $mode ) {
        if ( true === $mode ) {
            return self::MODE_ONLINE;
        }
        if ( false === $mode || null === $mode ) {
            return self::MODE_IN_PERSON;
        }
        $mode = (string) $mode;
        return array_key_exists( $mode, self::modes() ) ? $mode : self::MODE_IN_PERSON;
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
        /*
         * THE WIDER QUESTION, BECAUSE A HYBRID EVENT HAS A LINK (3.96.0).
         * is_online() is "has no place", which a hybrid event answers no to
         * while still needing its link read. has_online_format() is the
         * question this actually asks: does anybody join by link.
         */
        if ( ! self::has_online_format( $event_id ) ) {
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
        // Hybrid too: the ticks select which message carries the link, and a
        // hybrid event has one. See link().
        if ( ! self::has_online_format( (int) $event_id ) ) {
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

        /*
         * $online IS A MODE NOW (3.96.0), and true and false still mean what
         * they always meant. One entry point still, for the reason above: the
         * format, the place, the link and the delivery ticks are not
         * independent, and hybrid is the case that makes that plainest. It
         * keeps the place AND takes a link, which is exactly the combination
         * the online branch below exists to make impossible.
         */
        $mode = self::normalize_mode( $online );

        if ( self::MODE_IN_PERSON === $mode ) {
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

        /*
         * EXACTLY ONE OF THE TWO KEYS, ALWAYS. Written as a set-and-clear pair
         * rather than as two independent writes, so there is no ordering in
         * which an event briefly carries both and no path that leaves the one
         * it is moving away from behind.
         */
        if ( self::MODE_HYBRID === $mode ) {
            update_post_meta( $event_id, self::META_HYBRID, '1' );
            delete_post_meta( $event_id, self::META );
        } else {
            update_post_meta( $event_id, self::META, '1' );
            delete_post_meta( $event_id, self::META_HYBRID );
        }

        /*
         * THE PLACE GOES, ALL OF IT, AND ONLY FOR A PURELY ONLINE EVENT.
         *
         * The term, the composed line and the four parts, in one place, so no
         * combination of them survives into a state that says the event has no
         * address. See the file header and class-sfaf-venues.php.
         *
         * A HYBRID EVENT KEEPS EVERY ONE OF THEM, because half its registrants
         * are turning up there. That is the whole difference between the two
         * modes on this side of the class, and it is why hybrid could not be a
         * second tick sitting on top of online: this block would have run.
         */
        if ( self::MODE_ONLINE === $mode ) {
            SFAF_Venues::set_for_event( $event_id, 0 );
            delete_post_meta( $event_id, '_uc_location' );
            foreach ( sfaf_location_part_keys() as $key ) {
                delete_post_meta( $event_id, $key );
            }
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
    public static function joining_html( $event_id, $kind, $format = '' ) {
        if ( ! self::sends_with( $event_id, $kind ) ) {
            return '';
        }
        /*
         * AND NOT TO SOMEBODY WHO SAID THEY ARE COMING IN PERSON (3.96.0).
         *
         * A hybrid event has an address and a meeting link, and the rule is
         * that no message carries both. The address half is refused in
         * SFAF_Notifications::facts(); this is the other half, and the two are
         * the same decision read from opposite ends.
         *
         * THE LINK IS A CREDENTIAL, so the gate is written as "only when the
         * person asked for it" rather than "unless they did not". An empty
         * format, which is what every non-hybrid event stores, is not an
         * in-person answer: it means the event never asked, and a purely online
         * event's registrants must still be sent the link.
         */
        if ( self::is_hybrid( $event_id ) && self::MODE_ONLINE !== (string) $format ) {
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
    public static function joining_text( $event_id, $kind, $format = '' ) {
        if ( ! self::sends_with( $event_id, $kind ) ) {
            return '';
        }
        /*
         * AND NOT TO SOMEBODY WHO SAID THEY ARE COMING IN PERSON (3.96.0).
         *
         * A hybrid event has an address and a meeting link, and the rule is
         * that no message carries both. The address half is refused in
         * SFAF_Notifications::facts(); this is the other half, and the two are
         * the same decision read from opposite ends.
         *
         * THE LINK IS A CREDENTIAL, so the gate is written as "only when the
         * person asked for it" rather than "unless they did not". An empty
         * format, which is what every non-hybrid event stores, is not an
         * in-person answer: it means the event never asked, and a purely online
         * event's registrants must still be sent the link.
         */
        if ( self::is_hybrid( $event_id ) && self::MODE_ONLINE !== (string) $format ) {
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
        /*
         * has_online_format(), NOT is_online() (3.97.3).
         *
         * THIS MINTS AND VERIFIES, so the narrow question broke both ends at
         * once: a HYBRID event answers no to is_online() by design, because
         * that is the question that takes an address away, so no token could be
         * made for one and no token presented for one could ever be accepted.
         * The calendar file offered to a hybrid event's ONLINE registrant could
         * not carry the link whatever the callers did.
         *
         * 3.97.2 WIDENED THE READER IN sfaf_output_ics() AND STOPPED THERE,
         * which fixed the half that decides whether to look for a token and
         * left the half that decides whether one exists. Both had to move, and
         * this is the one that had no test on it.
         *
         * NOTHING ABOUT WHO GETS ONE CHANGES. ics_url_with_link() still refuses
         * to mint for anybody whose format is not online on a hybrid event, so
         * widening what CAN carry a token does not widen who is handed one.
         */
        if ( ! $event_id || ! self::has_online_format( $event_id ) ) {
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
    public static function ics_url_with_link( $event_id, $format = '' ) {
        $event_id = (int) $event_id;
        $url      = sfaf_ics_url( $event_id );

        if ( ! self::sends_with( $event_id, 'confirmation' ) || ! self::has_link( $event_id ) ) {
            return $url;
        }
        /*
         * AND NOT TO SOMEBODY WHO SAID THEY ARE COMING IN PERSON (3.96.0).
         *
         * THE SAME GATE AS joining_html(), AND IT HAS TO BE HERE AS WELL. The
         * calendar file is a SECOND way the link leaves, and a wider one: an
         * .ics syncs to the person's phone, their laptop and any calendar they
         * have shared. Gating the email and not the file would have handed the
         * credential to every in-person registrant of every hybrid event, in
         * the one copy that spreads furthest.
         *
         * WRITTEN AS AN ALLOW, like the other one: an empty format means the
         * event never asked, which is every non-hybrid event, and those must
         * keep working exactly as they did.
         */
        if ( self::is_hybrid( $event_id ) && self::MODE_ONLINE !== (string) $format ) {
            return $url;
        }
        $token = self::ics_join_token( $event_id );
        if ( '' === $token ) {
            return $url;
        }
        return add_query_arg( self::ICS_JOIN_ARG, $token, $url );
    }
}
