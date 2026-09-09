<?php
/**
 * What the two public forms share.
 *
 * THERE ARE TWO FORMS AND THEY ARE NOT THE SAME FORM.
 * ---------------------------------------------------------------------------
 * `SFAF_Request` is staff, reached by a link emailed to an sfaf.org address.
 * `SFAF_Submit` is the public, reached by a plain URL naming a series, with no
 * email check and no login at all. They ask different questions, they are
 * protected differently, and they produce differently marked pending rows.
 *
 * What they genuinely have in common is here, once: the rate limiter, the
 * honeypot, the page chrome, the prose allow-list, and the one function that
 * answers what kind of pending event something is. Everything else stayed with
 * the form that owns it, because two things that merely look alike are not one
 * thing, and a shared method with a mode flag is how the combined view shipped
 * five faults.
 *
 * WHY THE PROSE ALLOW-LIST IS HERE AND NOT IN SFAF_Rich_Text.
 * ---------------------------------------------------------------------------
 * `SFAF_Rich_Text::sanitize()` is `wp_kses_post()`, and its own docblock gives
 * the reason: it is the rule WordPress already applies to post content, and a
 * second list would be a second answer that could drift. That reasoning holds
 * for STAFF prose, written by somebody with an account, and it is left alone.
 *
 * It does not hold for a form anybody on the internet can post to.
 * `wp_kses_post()` permits `<img>`, `<video>`, `<audio>`, `<iframe>` on some
 * configurations, inline `style`, and `class`/`id` on nearly everything. From
 * an anonymous submitter that is a tracking pixel, an off-site request made by
 * every visitor who opens the event, and a way to move things around the page
 * they were not meant to be on. So submitted prose gets a narrower list, and
 * the list is EXACTLY WHAT THE TOOLBAR CAN PRODUCE: the editor cannot make
 * anything else, so nothing legitimate is lost by refusing the rest.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Submissions {

    /** Transient prefix for every counter either form keeps. */
    const RATE_PREFIX = 'sfaf_subm_rate_';

    /**
     * How a pending event says what it is.
     *
     * ITS OWN KEY, RATHER THAN INFERRED FROM WHICH FIELDS ARE FILLED IN. Both
     * forms record a submitter address, so "has an email" cannot tell a staff
     * request from a community submission, and the pending queue used exactly
     * that test. A row that is asked what it is must be able to answer.
     */
    const META_KIND = '_uc_submission_kind';

    const KIND_STAFF     = 'staff';
    const KIND_COMMUNITY = 'community';

    /**
     * Where a submission alert goes when nobody has set an address.
     *
     * A CONSTANT SO IT IS NOT TYPED INTO A TEMPLATE. The Settings screen shows
     * it as the field's value and as its placeholder, and alert_recipients()
     * falls back to it, which is three places reading one string rather than
     * three copies of an address to keep in step.
     */
    const DEFAULT_ALERT_EMAIL = 'websites@sfaf.org';

    /* =====================================================================
     * Who hears that something arrived
     * ================================================================== */

    /**
     * Every address told that a submission has arrived. One answer, both forms.
     *
     * WHY THIS STOPPED BEING "EVERYBODY WITH ADMIN" (3.72.0). It was
     * SFAF_Request::admin_users(), so the audience was a consequence of who had
     * been given a role rather than a decision anybody took: giving somebody
     * Admin so they could fix one event signed them up to every submission from
     * then on, and there was no screen that said so or could undo it. A named
     * list is a decision, it is visible, and it is one field to change.
     *
     * THE FALLBACK IS THE OLD BEHAVIOUR, and it is the right one when the field
     * is empty: an empty list must not mean nobody is told that a stranger has
     * submitted an event to a public calendar. Emptying the box widens the
     * audience rather than silencing it, which is the safe direction for this
     * particular message.
     *
     * RE-CHECKED ON THE WAY OUT. A stored address can predate the rule that
     * would have refused it, and this one becomes a To line.
     *
     * @return string[] Lower-cased, deduplicated, never empty unless the site
     *                  has no admins and no setting.
     */
    public static function alert_recipients() {
        $settings = get_option( 'uc_settings', array() );
        $raw      = isset( $settings['submission_alert_emails'] )
            ? (string) $settings['submission_alert_emails']
            : self::DEFAULT_ALERT_EMAIL;

        $out = array();
        foreach ( preg_split( '/[,\r\n]+/', $raw ) as $one ) {
            $one = strtolower( trim( $one ) );
            if ( '' !== $one && is_email( $one ) && ! in_array( $one, $out, true ) ) {
                $out[] = $one;
            }
        }

        if ( ! empty( $out ) ) {
            return $out;
        }

        foreach ( SFAF_Request::admin_users() as $user ) {
            $one = strtolower( trim( (string) $user->user_email ) );
            if ( '' !== $one && is_email( $one ) && ! in_array( $one, $out, true ) ) {
                $out[] = $one;
            }
        }
        return $out;
    }

    /* =====================================================================
     * What kind of pending event is this
     * ================================================================== */

    /**
     * One answer, for the badge, the panel and the notification.
     *
     * THE ORDER MATTERS AND IS NOT ALPHABETICAL. An import is decided first
     * because it is decided by a different subsystem entirely and a row can
     * only be one thing. Then the explicit marker. Then the legacy test, which
     * is the ONLY thing that keeps requests made before this release showing as
     * requests: they have an address and no marker, and there is no migration.
     *
     * @param int $event_id
     * @return string 'import' | 'community' | 'staff' | 'local'
     */
    public static function kind( $event_id ) {
        $event_id = (int) $event_id;
        if ( ! $event_id ) {
            return 'local';
        }

        if ( class_exists( 'SFAF_Sources' ) ) {
            $prov = SFAF_Sources::provenance( $event_id );
            if ( is_array( $prov ) && ! empty( $prov['source'] ) ) {
                return 'import';
            }
        }

        $marked = (string) get_post_meta( $event_id, self::META_KIND, true );
        if ( self::KIND_COMMUNITY === $marked ) {
            return self::KIND_COMMUNITY;
        }
        if ( self::KIND_STAFF === $marked ) {
            return self::KIND_STAFF;
        }

        /* Written by every request since 3.43.0, and by nothing else. */
        if ( '' !== (string) get_post_meta( $event_id, SFAF_Request::META_EMAIL, true ) ) {
            return self::KIND_STAFF;
        }

        return 'local';
    }

    /**
     * What the queue calls it. One phrase per kind, in one place.
     *
     * @param string $kind
     * @return string '' when there is nothing to badge.
     */
    public static function kind_label( $kind ) {
        $labels = array(
            self::KIND_STAFF     => 'Staff request',
            self::KIND_COMMUNITY => 'Community submission',
        );
        return isset( $labels[ $kind ] ) ? $labels[ $kind ] : '';
    }

    /* =====================================================================
     * What happens when a submission is approved
     * ================================================================== */

    /**
     * Who sent this, if anybody did.
     *
     * ONE READER FOR BOTH FORMS, because a staff request and a community
     * submission record their submitter under the same two keys and the
     * approval screen has no reason to care which it is looking at.
     *
     * `usable` IS SEPARATE FROM `email` ON PURPOSE. An address can be stored
     * and still not be one: it predates a validation change, or it was a
     * request from before the field existed. The approval prompt has to say so
     * rather than offer to send to nothing, and a caller that only looked at
     * whether the string was empty would offer it.
     *
     * @param int $event_id
     * @return array{name:string,email:string,usable:bool,is_submission:bool}
     */
    public static function submitter( $event_id ) {
        $event_id = (int) $event_id;
        $kind     = self::kind( $event_id );
        $email    = strtolower( trim( (string) get_post_meta( $event_id, SFAF_Request::META_EMAIL, true ) ) );
        $name     = trim( (string) get_post_meta( $event_id, SFAF_Request::META_NAME, true ) );

        return array(
            'name'          => ( '' !== $name ) ? $name : $email,
            'email'         => $email,
            'usable'        => ( '' !== $email && is_email( $email ) ),
            'is_submission' => in_array( $kind, array( self::KIND_STAFF, self::KIND_COMMUNITY ), true ),
        );
    }

    /**
     * Every address the submitter asked to have told, the submitter first.
     *
     * ONE READER FOR BOTH FORMS. The staff form takes a single address and
     * gets a list of one; the community form takes up to
     * SFAF_Submit::MAX_EMAILS and gets what was actually sent. The approval
     * screen asks this rather than counting fields, so a form that offered a
     * different number would not need a second branch here.
     *
     * RE-DERIVED AND RE-CHECKED, NEVER TAKEN FROM A REQUEST. These go onto a
     * list that is sent registrant names and addresses, so each one is checked
     * again on the way out and the cap is applied again, whatever is in the
     * database. A stored value can predate the rule that would have refused it.
     *
     * @param int $event_id
     * @return string[] Lower-cased, deduplicated, capped, the submitter first.
     */
    public static function notify_addresses( $event_id ) {
        $event_id = (int) $event_id;
        $who      = self::submitter( $event_id );

        $out = array();
        if ( ! empty( $who['usable'] ) ) {
            $out[] = $who['email'];
        }

        $stored = get_post_meta( $event_id, SFAF_Submit::META_NOTIFY_EMAILS, true );
        foreach ( (array) $stored as $one ) {
            if ( ! is_scalar( $one ) ) {
                continue;
            }
            $one = strtolower( trim( (string) $one ) );
            if ( '' === $one || ! is_email( $one ) || in_array( $one, $out, true ) ) {
                continue;
            }
            $out[] = $one;
            if ( count( $out ) >= SFAF_Submit::MAX_EMAILS ) {
                break;
            }
        }

        return $out;
    }

    /**
     * Put the submitter on the event's notification list.
     *
     * A TYPED ADDRESS, WHICH IS EXACTLY WHAT THIS IS. The list already accepts
     * free-text addresses for people who have no account here, and somebody who
     * submitted through a public form is precisely that. So this is one more
     * entry on a list that already exists rather than a mechanism of its own,
     * and everything downstream treats it the way it treats any typed address:
     * `user_id` 0, no caladmin links offered, no capability inferred.
     *
     * WHAT THAT MEANS THEY WILL RECEIVE, and it is worth being plain about it,
     * because it is registrant data going to somebody outside SFAF:
     *
     *   - THE REGISTRATION ALERT, each time somebody registers. It names the
     *     person who just registered and how many places are taken.
     *   - THE MORNING-OF SUMMARY, two hours before the event, which lists
     *     EVERYBODY REGISTERED, by name and email address.
     *
     * One list carries both, so this is one decision rather than two, and the
     * control that offers it says so in those words.
     *
     * IDEMPOTENT, and it does not disturb what is already there. Approving
     * twice, or approving an event a manager has already added them to, leaves
     * one entry.
     *
     * @param int    $event_id
     * @param string $email
     * @return bool True when the list changed.
     */
    public static function add_to_notify_list( $event_id, $email ) {
        $event_id = (int) $event_id;
        $email    = sanitize_email( trim( (string) $email ) );
        if ( ! $event_id || ! $email || ! is_email( $email ) ) {
            return false;
        }

        $stored = get_post_meta( $event_id, SFAF_Reminders::NOTIFY_EMAILS_META, true );
        $list   = is_array( $stored ) ? $stored : array();


        foreach ( $list as $existing ) {
            if ( strtolower( trim( (string) $existing ) ) === strtolower( $email ) ) {
                return false;
            }
        }

        $list[] = $email;
        update_post_meta( $event_id, SFAF_Reminders::NOTIFY_EMAILS_META, array_values( $list ) );
        return true;
    }

    /**
     * Tell the submitter their event is on the calendar.
     *
     * THIS IS THE ONE MESSAGE EITHER FORM SENDS AFTER ITS CONFIRMATION, and
     * until 3.48.0 there were none: both forms said so, in as many words, on
     * the grounds that chasing is a person's job and a system that nags teaches
     * people to filter it. That reasoning still holds for "still waiting". It
     * does not hold for "it is live", which is a fact the submitter cannot find
     * out any other way and the thing they are actually waiting to hear.
     *
     * IT IS STILL NOT AUTOMATIC. A manager ticks it, per event, at approval.
     * Nothing here fires on a status change, so an event published by any other
     * route sends nothing.
     *
     * @param int  $event_id
     * @param bool $on_notify_list Whether they were also added to the list, so
     *                             the message can say what they will now get.
     * @return bool
     */
    public static function send_published_notice( $event_id, $on_notify_list = false ) {
        $event_id = (int) $event_id;
        $who      = self::submitter( $event_id );
        if ( ! $who['usable'] ) {
            return false;
        }

        /*
         * COMMUNITY SUBMISSIONS ONLY, CHECKED HERE AS WELL AS ON THE CONTROL
         * (3.72.0).
         *
         * render_approve_ask() no longer draws the tick for a staff request, and
         * that is not a refusal: a POST is a request anybody can construct, and
         * a control that is not drawn has never been a permission in this
         * codebase. The reason for the rule is on that renderer.
         *
         * ONLY THE SUBMITTER, AND ONLY EVER THE FIRST ADDRESS. The community
         * form takes up to SFAF_Submit::MAX_EMAILS, but the others were named by
         * the submitter as people who should get the registrations, which is a
         * different request from "tell me what happened to what I sent". This
         * message speaks to the person who sent it. notify_addresses() already
         * draws that line for the notification list and this is the same line.
         */
        if ( self::KIND_COMMUNITY !== self::kind( $event_id ) ) {
            return false;
        }

        $title = get_the_title( $event_id );
        $date  = (string) get_post_meta( $event_id, '_uc_event_date', true );
        $start = (string) get_post_meta( $event_id, '_uc_start_time', true );
        $end   = (string) get_post_meta( $event_id, '_uc_end_time', true );
        $link  = get_permalink( $event_id );

        $rows = array( 'Event' => $title );
        if ( '' !== $date ) {
            $rows['Date'] = sfaf_ap_date( $date, 'full' );
        }
        if ( '' !== $start ) {
            $rows['Time'] = sfaf_ap_time_range( $start, $end );
        }

        $html = SFAF_Email::heading( 'Your event is on the calendar' )
            . SFAF_Email::para( 'The event you sent us has been reviewed and is now published.' )
            . SFAF_Email::details( $rows )
            . SFAF_Email::button_row( array( SFAF_Email::button( $link, 'See your event' ) ) );

        $text = "Your event is on the calendar.\n\n" . $title . "\n";
        if ( '' !== $date ) {
            $text .= sfaf_ap_date( $date, 'full' ) . "\n";
        }
        $text .= "\n" . $link;

        if ( $on_notify_list ) {
            /*
             * SAID BECAUSE THEY WILL START RECEIVING MAIL. Somebody who is
             * added to a notification list and then gets a message listing
             * strangers' names and addresses should have been told it was
             * coming, and told what it is, by the people who added them.
             */
            $html .= SFAF_Email::rule()
                . SFAF_Email::small_para( 'You will get an email each time somebody registers, and a list of everybody registered on the morning of the event. Reply to this message if you would rather not.' );
            $text .= "\n\nYou will get an email each time somebody registers, and a list of everybody registered on the morning of the event. Reply if you would rather not.";
        }

        return (bool) SFAF_Email::send(
            $who['email'],
            'Your event is on the calendar: ' . $title,
            SFAF_Email::shell( 'Your event is published', $html ),
            $text
        );
    }

    /**
     * Tell somebody outside SFAF that what they sent is not going on.
     *
     * THE ONLY MESSAGE IN THIS PLUGIN THAT TELLS SOMEBODY NO, and the wording
     * is the whole of the work in it.
     *
     * WHAT IT DOES NOT DO, and each of these was a sentence that got written
     * and taken out again:
     *
     *   It does not apologise. "We are sorry to say" makes a routine editorial
     *   decision sound like bad news somebody is breaking gently, and the
     *   reader then looks for what went wrong.
     *
     *   It does not invite an appeal it cannot honour. "Let us know if you
     *   think this is a mistake" reads as a door somebody can push on, and
     *   there is nobody behind it: this queue is reviewed once and the row is
     *   gone. Saying nothing about reversing it is what makes it a decision.
     *
     *   It does not explain itself on the calendar's behalf. The reviewer's
     *   note is the reason, when there is one, and a stock sentence about what
     *   this calendar is for would be a second reason that might contradict it.
     *
     *   It does not thank them for their submission in the first line. That is
     *   the shape of every rejection anybody has read and it delays the answer
     *   by a sentence. The thanks are at the end, where they are not standing
     *   in front of the thing the reader opened the message to find out.
     *
     * NO CALENDAR LINK AND NO EVENT PAGE, because there is not one: the row is
     * trashed in the same request. A button going nowhere is worse than none.
     *
     * @param int    $event_id
     * @param string $note Plain text, already sanitised. May be empty.
     * @return bool
     */
    public static function send_rejected_notice( $event_id, $note = '' ) {
        $event_id = (int) $event_id;
        $who      = self::submitter( $event_id );
        if ( ! $who['usable'] ) {
            return false;
        }
        /* Community only, for the reason on send_published_notice(). */
        if ( self::KIND_COMMUNITY !== self::kind( $event_id ) ) {
            return false;
        }

        $title = get_the_title( $event_id );
        $date  = (string) get_post_meta( $event_id, '_uc_event_date', true );
        $note  = trim( (string) $note );

        $rows = array( 'Event' => $title );
        if ( '' !== $date ) {
            $rows['Date'] = sfaf_ap_date( $date, 'full' );
        }

        $html = SFAF_Email::heading( 'This one is not going on the calendar' )
            . SFAF_Email::para( 'We looked at the event you sent us and it is not being published.' )
            . SFAF_Email::details( $rows );

        $text = "This one is not going on the calendar.\n\n"
            . "We looked at the event you sent us and it is not being published.\n\n"
            . $title . "\n";
        if ( '' !== $date ) {
            $text .= sfaf_ap_date( $date, 'full' ) . "\n";
        }

        if ( '' !== $note ) {
            $html .= SFAF_Email::para( $note );
            $text .= "\n" . $note . "\n";
        }

        $html .= SFAF_Email::rule()
            . SFAF_Email::small_para( 'Thanks for sending it.' );
        $text .= "\nThanks for sending it.\n";
        $text .= "\n" . SFAF_Email::POSTAL;

        return (bool) SFAF_Email::send(
            $who['email'],
            'About the event you sent us: ' . $title,
            SFAF_Email::shell( 'Not going on the calendar', $html ),
            $text
        );
    }

    /* =====================================================================
     * Rate limiting
     * ================================================================== */

    /**
     * Count one action against a limit, and say whether it may proceed.
     *
     * A COUNTER, NOT A LOCKOUT. It expires on its own, so nothing has to
     * unblock anybody and a mistake costs somebody an hour rather than an
     * account. The window starts at the first hit and is NOT extended by later
     * ones, so nobody can be held out indefinitely by their own retries.
     *
     * The subject is hashed rather than stored: an address in a transient key
     * is an address sitting in the options table for anyone with database
     * access to read.
     *
     * @param string $bucket What is being counted.
     * @param string $who    The subject, already normalised.
     * @param int    $limit  How many are allowed in the window.
     * @param int    $window Seconds.
     * @return bool True when this one is allowed.
     */
    public static function allow( $bucket, $who, $limit, $window ) {
        $key   = self::RATE_PREFIX . $bucket . '_' . hash( 'sha256', (string) $who );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $key, $count + 1, $window );
        return true;
    }

    /**
     * Who is asking, for rate limiting only.
     *
     * NOT TREATED AS IDENTITY AND NOT STORED. It is spoofable and it is shared:
     * a whole office behind one address is one client here. That is exactly why
     * it is never the only limit.
     *
     * REMOTE_ADDR AND NOTHING ELSE. X-Forwarded-For is written by the client
     * and, unless every proxy in front of this site is known and trusted, a
     * limiter that reads it can be defeated by sending a different value each
     * time, which is worse than no limiter because it looks like one.
     *
     * @return string
     */
    public static function client() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return ( '' !== $ip ) ? $ip : 'unknown';
    }

    /* =====================================================================
     * Text arriving from a form
     * ================================================================== */

    /**
     * Prose from a public form, reduced to what the toolbar can produce.
     *
     * WHAT IS ALLOWED, AND NOTHING ELSE:
     *
     *   p, br               paragraphs and the breaks inside them
     *   strong, b, em, i    the toolbar's bold and italic
     *   ul, ol, li          the toolbar's two list buttons
     *   h3                  the only heading `block_formats` offers
     *   a[href,title]       the toolbar's link button
     *   blockquote          survives a paste without becoming a div
     *
     * NO ATTRIBUTES BEYOND href AND title. No `class`, no `id`, no `style`, no
     * `target`, no `rel`, and no data attributes, because none of them can be
     * produced by the control and every one of them is a way to reach outside
     * the box the prose is drawn in.
     *
     * NO IMG. Somebody who wants a picture on the event uses the upload field,
     * which is checked; an `<img src>` in prose is an off-site request made by
     * every visitor and is checked by nothing.
     *
     * THE HREF IS CHECKED FOR ITS PROTOCOL, not just kses-escaped. kses already
     * drops `javascript:`, and this narrows further to the three schemes that
     * make sense in an event description, so `data:` cannot smuggle a document
     * into a link.
     *
     * @param mixed $value Already unslashed, as WordPress orders it.
     * @return string
     */
    public static function prose( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $allowed = array(
            'p'          => array(),
            'br'         => array(),
            'strong'     => array(),
            'b'          => array(),
            'em'         => array(),
            'i'          => array(),
            'ul'         => array(),
            'ol'         => array(),
            'li'         => array(),
            'h3'         => array(),
            'blockquote' => array(),
            'a'          => array( 'href' => true, 'title' => true ),
        );

        $out = wp_kses( $value, $allowed, array( 'http', 'https', 'mailto' ) );

        /*
         * AND THE RESULT IS NORMALISED. wp_kses() removes tags; it does not
         * collapse what removing them left behind, so a paste can arrive as
         * several hundred empty paragraphs that are correct HTML and read as a
         * blank description with a scrollbar.
         */
        $out = preg_replace( '#(?:<p>\s*</p>\s*)+#i', '', $out );
        $out = preg_replace( '#(?:<br\s*/?>\s*){3,}#i', '<br /><br />', $out );

        return trim( (string) $out );
    }

    /**
     * A single-line value: no markup at all, whitespace collapsed, capped.
     *
     * FOR EVERY FIELD THAT IS NOT PROSE. A title, a name, a location, a cost.
     * None of them has any reason to carry markup, and a value that arrives as
     * text can never be anything else later, wherever it is printed.
     *
     * @param mixed $raw Already unslashed.
     * @param int   $max Characters.
     * @return string
     */
    public static function line( $raw, $max ) {
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $out = sanitize_text_field( $raw );
        $out = preg_replace( '/\s+/u', ' ', $out );
        if ( null === $out ) {
            /* preg_replace returns null on a malformed UTF-8 subject. */
            $out = sanitize_text_field( $raw );
        }
        return trim( SFAF_Request::cap( $out, $max ) );
    }

    /**
     * An external link, or ''.
     *
     * THE SCHEME IS CHECKED AFTER esc_url_raw() RATHER THAN BEFORE. esc_url_raw
     * normalises as well as filters, so a value inspected first is not the
     * value that would be stored. Only http and https: a `mailto:` in a field
     * labelled "link to register" is a mistake worth refusing rather than
     * storing and rendering as a broken button.
     *
     * @param mixed $raw
     * @param int   $max
     * @return string
     */
    public static function url( $raw, $max = 500 ) {
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $raw = trim( $raw );
        if ( '' === $raw ) {
            return '';
        }
        $url = esc_url_raw( SFAF_Request::cap( $raw, $max ), array( 'http', 'https' ) );
        if ( '' === $url ) {
            return '';
        }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        return ( 'http' === $scheme || 'https' === $scheme ) ? $url : '';
    }

    /* =====================================================================
     * Page chrome
     * ================================================================== */

    /**
     * The document both forms are drawn in.
     *
     * IT BUILDS ITS OWN DOCUMENT, so anything WordPress would normally print
     * has to be asked for. That is the same arrangement caladmin has, and the
     * same trap: an editor enqueued AFTER this runs has nothing printed for it.
     * Callers enqueue first, then open the page. See SFAF_Rich_Text::enqueue().
     *
     * @param string $title
     * @param array  $args 'body_class', 'editor' (print what wp_editor needs).
     */
    public static function page_open( $title, $args = array() ) {
        $args = array_merge( array( 'body_class' => 'uc-request-page', 'editor' => false ), $args );

        status_header( 200 );
        header( 'Content-Type: text/html; charset=utf-8' );
        /* Nothing here should ever be framed or indexed. */
        header( 'X-Frame-Options: SAMEORIGIN' );
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $title ); ?></title>
<?php
/*
 * THE FAVICON (3.66.0). This document is hand-written and calls wp_head()
 * nowhere, so nothing supplies an icon unless this page asks for one. Both
 * public forms sat with the browser's blank page mark until this release, for
 * exactly the reason they had no stylesheet until 3.44.0.
 */
sfaf_favicon_links();
?>
<link rel="stylesheet" href="<?php echo esc_url( SFAF_PLUGIN_URL . 'public/css/portal.css?ver=' . SFAF_VERSION ); ?>" />
<?php
if ( $args['editor'] ) {
    wp_print_styles();
    wp_print_head_scripts();
}
?>
</head>
<body class="uc-portal <?php echo esc_attr( $args['body_class'] ); ?>">
<div class="uc-request-wrap">
        <?php
    }

    /**
     * @param array $args 'editor'.
     */
    public static function page_close( $args = array() ) {
        $args = array_merge( array( 'editor' => false ), $args );
        ?>
</div>
<?php
if ( $args['editor'] ) {
    /*
     * BEFORE portal.js, AND THE ORDER IS THE WHOLE FIX.
     *
     * These two were the other way round until 3.51.0, and it cost nothing
     * while the only editor on these pages was rendered by wp_editor(): that
     * one prints its own initialiser into the footer scripts and starts itself
     * whenever it runs. A DEFERRED editor does not. portal.js starts those, by
     * calling wp.editor.initialize(), and wp.editor is one of the things
     * wp_print_footer_scripts() prints. Loaded first, portal.js found no
     * wp.editor, returned at its first guard, and every deferred row stayed the
     * plain textarea it began as, silently and correctly.
     */
    wp_print_footer_scripts();

    /*
     * THE TOOLBAR, FOR THE ROWS THE BROWSER BUILDS. Printed by caladmin's
     * foot() and, until 3.51.0, nowhere else, so a public form had no settings
     * block for portal.js to read even once wp.editor was there.
     *
     * SAME JSON, SAME METHOD, NO SECOND COPY. A toolbar written out again here
     * would be a second answer to what a submitter may type.
     */
    ?><script type="application/json" id="uc-rich-settings"><?php
        echo SFAF_Rich_Text::settings_json();
    ?></script><?php
}
?>
<script src="<?php echo esc_url( SFAF_PLUGIN_URL . 'public/js/portal.js?ver=' . SFAF_VERSION ); ?>"></script>
</body></html><?php
    }

    /**
     * A field no person sees and every naive bot fills.
     *
     * It fails exactly as a success looks, because telling a bot it was caught
     * is telling whoever wrote it what to change.
     */
    public static function honeypot() {
        ?>
        <div class="uc-hp" aria-hidden="true">
            <label>Website<input type="text" name="uc_website" value="" tabindex="-1" autocomplete="off" /></label>
        </div>
        <?php
    }

    /** Was the honeypot filled in? */
    public static function trapped() {
        return ! empty( $_POST['uc_website'] );
    }

    /** One error line under a field. */
    public static function field_error( $message ) {
        if ( '' === (string) $message ) {
            return;
        }
        echo '<span class="uc-field-error">' . esc_html( $message ) . '</span>';
    }

    /**
     * The image field both forms carry.
     *
     * ONE CONTROL, so the accepted formats and the size ceiling are stated
     * once and cannot disagree with what SFAF_Uploads actually enforces: the
     * numbers below are read from that class rather than typed here.
     *
     * @param string $error
     */
    public static function image_field( $error = '' ) {
        $mb = (int) round( SFAF_Uploads::MAX_BYTES / 1048576 );
        ?>
        <?php
        /*
         * "UPLOAD ONE" RATHER THAN "PICTURE" (3.72.0). This control sits inside
         * a section whose legend already reads "Event Image", so a label
         * repeating that noun said nothing; what the field needs to say is that
         * it is the other way of answering the same question, beside a chooser.
         *
         * THE MINIMUM IS SAID BEFORE A FILE IS CHOSEN, not after it is refused.
         * A number in a message somebody meets only on failure is a rule they
         * learn by breaking it, and the file they would have to go and find
         * again is on a phone in another room.
         */
        ?>
        <label class="uc-field uc-field-upload">
            <span class="uc-field-label">Or upload one</span>
            <input type="file" name="uc_image" accept="image/jpeg,image/png,image/gif,image/webp" />
            <span class="uc-hint">
                JPEG, PNG, GIF or WebP, up to <?php echo (int) $mb; ?>MB, and at least
                <?php echo (int) SFAF_Uploads::MIN_WIDTH; ?> pixels wide. Landscape works best.
                Leave this empty if you do not have one.
            </span>
            <?php self::field_error( $error ); ?>
        </label>
        <?php
    }
}
