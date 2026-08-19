<?php
/**
 * The public event request form.
 *
 * WHAT IT IS FOR. Most SFAF staff will never manage events; they want to ask
 * MarCom to add one. That was an email or an Asana task and somebody retyping
 * it into caladmin. This is one page that puts the same information straight
 * into the pending queue, with nothing retyped.
 *
 * IT IS NOT A RESTRICTED VIEW OF caladmin, and that is the whole shape of it.
 * No sidebar, no events list, no navigation, no account. A cut-down portal
 * would mean every screen in the portal growing a second set of permission
 * questions, and the answer to "what can a requester see" would live in
 * forty places instead of this file.
 *
 * ACCESS: A LINK, NOT A PASSWORD.
 * ---------------------------------------------------------------------------
 * Somebody types their sfaf.org address, and a link arrives that opens the
 * form. The link carries a token, exactly as the reminder cancel link does:
 * `SFAF_Reminders::new_token()` is the same generator, so there is one answer
 * to "how strong is that token". The token IS the credential, which is what
 * lets this work with no account at all.
 *
 * A link beats a code because it is one click with nothing to type and no
 * second screen to build, and because a code somebody types is a code somebody
 * forwards in a Slack message that outlives its usefulness.
 *
 * NO REST ROUTE, deliberately. `is_embed_request()` matches an exact string,
 * so a new route would fail CORS from sfaf.org. This hangs off a front-end
 * query var on `template_redirect`, which is the same arrangement the cancel
 * link and the .ics endpoint already use, and it needs no rewrite rule and no
 * flush, so it works on an install updated by overwriting the folder.
 *
 * IT IS A PUBLIC SURFACE. Everything from the browser is re-derived or checked
 * against something that already exists: every id must resolve to a real term,
 * every date must parse, every string is length-capped and stripped of markup,
 * and the image must be an attachment that is already in the calendar folder.
 * See validate() for the complete list of what is refused.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Request {

    /** The only domain that may ask for a link. */
    const DOMAIN = 'sfaf.org';

    /** How long a link works for. */
    const TOKEN_TTL = 3600;

    /** Transient prefixes. One per thing being counted, so they cannot collide. */
    const TOKEN_PREFIX = 'sfaf_evreq_tok_';
    const RATE_PREFIX  = 'sfaf_evreq_rate_';

    /* What a request records about itself, beyond the event's own fields. */
    const META_NAME   = '_uc_request_name';
    const META_EMAIL  = '_uc_request_email';
    const META_AT     = '_uc_request_at';
    const META_REPEAT = '_uc_request_repeat';
    const META_NOTES  = '_uc_request_notes';
    const META_VENUE  = '_uc_request_venue_other';

    /**
     * How often it repeats, in the words a requester would use.
     *
     * PLAIN LANGUAGE, AND NOT THE RECURRENCE ENGINE'S ENCODING. The editor
     * stores patterns like `weekly:1:2,4`, built by a control that asks four
     * questions, and generation is a creation-time action that makes N
     * independent posts. A request is not an event yet, so this records what
     * was ASKED FOR, in words, and whoever approves it sets the real schedule
     * on a screen built for that. Half-filling PATTERN_META from a public form
     * would put an unreviewed pattern one button press away from creating
     * fifty-two posts.
     */
    public static function repeat_options() {
        return array(
            'none'      => 'It happens once',
            'weekly'    => 'Every week',
            'fortnight' => 'Every two weeks',
            'monthly'   => 'Every month',
            'other'     => 'Something else (say so in the notes)',
        );
    }

    public static function register() {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
    }

    /* =====================================================================
     * URLs
     * ================================================================== */

    /** Where somebody starts: the page that asks for an address. */
    public static function start_url() {
        return add_query_arg( 'uc_event_request', '1', home_url( '/' ) );
    }

    /** The link that arrives by email. */
    public static function form_url( $token ) {
        return add_query_arg(
            array( 'uc_event_request' => '1', 'uc_token' => $token ),
            home_url( '/' )
        );
    }

    /* =====================================================================
     * Tokens
     * ================================================================== */

    /**
     * Store a token for an address and return it.
     *
     * A TRANSIENT, NOT A TABLE, AND NOT A ROW ON ANYTHING.
     *
     * The reminder token hangs off a registration row that already exists.
     * There is no row here: nothing about a person who has not submitted
     * anything is worth keeping. A transient is the store WordPress already
     * has for "this is true for the next hour", it expires without a cleanup
     * job, and it means no schema change and no SFAF_DB_VERSION bump for a
     * value whose entire life is sixty minutes.
     *
     * THE TOKEN IS NOT THE KEY. The transient is keyed on a hash of it, so the
     * usable credential is never written to the options table. Somebody who can
     * read the database can already read everything; somebody who can see a
     * stray backup or a log of option names cannot turn one into a working
     * link.
     *
     * @param string $email
     * @return string The token to put in the link.
     */
    private static function issue_token( $email ) {
        $token = SFAF_Reminders::new_token();
        set_transient( self::TOKEN_PREFIX . self::key_for( $token ), $email, self::TOKEN_TTL );
        return $token;
    }

    /** The lookup key for a token. Never the token itself. */
    private static function key_for( $token ) {
        return hash( 'sha256', (string) $token );
    }

    /**
     * The address a token belongs to, or '' if it is not a live one.
     *
     * @param string $token
     * @return string
     */
    public static function resolve_token( $token ) {
        $token = trim( (string) $token );
        // Shape-checked first, so a junk value never reaches the store and a
        // very long one cannot be used to make very long option lookups.
        if ( '' === $token || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return '';
        }
        $email = get_transient( self::TOKEN_PREFIX . self::key_for( $token ) );
        if ( ! is_string( $email ) || ! self::is_staff_address( $email ) ) {
            return '';
        }
        return $email;
    }

    /* =====================================================================
     * Addresses and rate limits
     * ================================================================== */

    /**
     * Is this one of ours?
     *
     * The domain, or a subdomain of it, and nothing that merely ends in the
     * same letters: `notsfaf.org` and `sfaf.org.example.com` are both refused.
     *
     * @param string $email
     * @return bool
     */
    public static function is_staff_address( $email ) {
        $email = strtolower( trim( (string) $email ) );
        if ( ! is_email( $email ) ) {
            return false;
        }
        $at = strrpos( $email, '@' );
        if ( false === $at ) {
            return false;
        }
        $domain = substr( $email, $at + 1 );
        return ( $domain === self::DOMAIN || self::ends_with( $domain, '.' . self::DOMAIN ) );
    }

    private static function ends_with( $haystack, $needle ) {
        $len = strlen( $needle );
        return ( 0 !== $len && substr( $haystack, -$len ) === $needle );
    }

    /**
     * Count one action against a limit, and say whether it may proceed.
     *
     * WHY BOTH AN ADDRESS AND AN ADDRESS-LESS COUNTER EXIST. An open form with
     * an email step is a way to send mail to arbitrary sfaf.org addresses:
     * without a per-address limit, one person's inbox can be filled, and
     * without a per-client limit, every address in the organisation can be
     * hit once each. Neither limit alone closes it.
     *
     * A COUNTER, NOT A LOCKOUT. It expires on its own, so nothing has to
     * unblock anybody and a mistake costs somebody an hour rather than an
     * account.
     *
     * @param string $bucket What is being counted.
     * @param string $who    The subject, already normalised.
     * @param int    $limit  How many are allowed in the window.
     * @param int    $window Seconds.
     * @return bool True when this one is allowed.
     */
    private static function allow( $bucket, $who, $limit, $window ) {
        $key   = self::RATE_PREFIX . $bucket . '_' . hash( 'sha256', (string) $who );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return false;
        }
        // The window starts at the first hit and is not extended by later ones,
        // so somebody cannot be held out indefinitely by their own retries.
        set_transient( $key, $count + 1, $window );
        return true;
    }

    /**
     * Who is asking, for rate limiting only.
     *
     * NOT TREATED AS IDENTITY AND NOT STORED. It is spoofable and it is shared:
     * an office behind one address is one client here. That is why it is the
     * second limit rather than the only one.
     *
     * @return string
     */
    private static function client() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return '' !== $ip ? $ip : 'unknown';
    }

    /* =====================================================================
     * The front controller
     * ================================================================== */

    public static function maybe_render() {
        if ( empty( $_GET['uc_event_request'] ) ) {
            return;
        }

        nocache_headers();

        $posted = ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] );
        $action = isset( $_POST['uc_request_action'] ) ? sanitize_key( wp_unslash( $_POST['uc_request_action'] ) ) : '';

        if ( $posted && 'send_link' === $action ) {
            self::handle_link_request();
            exit;
        }

        $token = isset( $_GET['uc_token'] ) ? sanitize_text_field( wp_unslash( $_GET['uc_token'] ) ) : '';
        if ( $posted && 'submit_request' === $action ) {
            $token = isset( $_POST['uc_token'] ) ? sanitize_text_field( wp_unslash( $_POST['uc_token'] ) ) : '';
        }

        if ( '' === $token ) {
            self::render_start();
            exit;
        }

        $email = self::resolve_token( $token );
        if ( '' === $email ) {
            self::render_expired();
            exit;
        }

        if ( $posted && 'submit_request' === $action ) {
            self::handle_submission( $token, $email );
            exit;
        }

        self::render_form( $token, $email, array(), array() );
        exit;
    }

    /* =====================================================================
     * Step one: ask for a link
     * ================================================================== */

    private static function handle_link_request() {
        $email = isset( $_POST['email'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['email'] ) ) ) ) : '';

        /*
         * THE HONEYPOT. A field no person sees and every naive bot fills. It
         * fails exactly as a success looks, because telling a bot it was caught
         * is telling whoever wrote it what to change.
         */
        if ( ! empty( $_POST['uc_website'] ) ) {
            self::render_link_sent();
            return;
        }

        if ( ! self::is_staff_address( $email ) ) {
            /*
             * SAID PLAINLY, because this reveals nothing about a person. The
             * rule is the domain, and somebody who mistyped their own address
             * needs to know that rather than watching a link never arrive.
             */
            self::render_start( 'That is not an ' . self::DOMAIN . ' address. This form is for SFAF staff, so use your work address.' );
            return;
        }

        /*
         * THE SAME ANSWER WHATEVER HAPPENS NEXT.
         *
         * Refused by a rate limit, sent, or silently dropped because the
         * mailbox does not exist: all three render the identical page. A
         * different screen for any of them would answer "is this a real
         * address here" for anybody who asked, and would say which addresses
         * are worth trying again later.
         */
        if ( self::allow( 'link_addr', $email, 3, self::TOKEN_TTL )
            && self::allow( 'link_ip', self::client(), 10, self::TOKEN_TTL ) ) {
            self::send_link( $email );
        }

        self::render_link_sent();
    }

    private static function send_link( $email ) {
        $url  = self::form_url( self::issue_token( $email ) );
        $mins = (int) round( self::TOKEN_TTL / 60 );

        $html = SFAF_Email::heading( 'Request an event' )
            . SFAF_Email::para( 'Here is your link to the event request form. It works for the next ' . $mins . ' minutes.' )
            . SFAF_Email::button_row( array( SFAF_Email::button( $url, 'Open the form' ) ) )
            . SFAF_Email::rule()
            . SFAF_Email::small_para( 'If you did not ask for this, nothing has happened and you can ignore it. The link only opens a form; it gives no access to anything else.' );

        $text = "Here is your link to the event request form. It works for the next {$mins} minutes.\n\n{$url}\n\n"
            . "If you did not ask for this, nothing has happened and you can ignore it.";

        SFAF_Email::send( $email, 'Your link to the event request form', SFAF_Email::shell( 'Your event request link', $html ), $text );
    }

    /* =====================================================================
     * Step two: the request itself
     * ================================================================== */

    /**
     * Everything a submission may contain, checked one field at a time.
     *
     * NOTHING IS TRUSTED AND NOTHING IS PASSED THROUGH. Every id is resolved
     * against a term that already exists, every date is parsed and compared
     * back to what it parsed from, every string is capped and stripped, and
     * anything not on a known list is dropped rather than stored. What comes
     * back is either a clean array or a list of things to tell somebody.
     *
     * @param array $post Usually $_POST.
     * @return array{clean:array,errors:array<string,string>}
     */
    public static function validate( $post ) {
        $clean  = array();
        $errors = array();

        $str = function ( $key, $max ) use ( $post ) {
            $raw = isset( $post[ $key ] ) ? wp_unslash( $post[ $key ] ) : '';
            if ( ! is_string( $raw ) ) {
                return '';
            }
            /* Markup is stripped rather than escaped: this is a request form,
             * nobody needs to send HTML through it, and a description that
             * arrives as text can never be anything else later. */
            $out = sanitize_textarea_field( $raw );
            return trim( self::cap( $out, $max ) );
        };

        /* ---- Who is asking, and what it is. ---- */
        $clean['name'] = $str( 'requester_name', 120 );
        if ( '' === $clean['name'] ) {
            $errors['requester_name'] = 'Tell us who is asking.';
        }

        $clean['title'] = $str( 'title', 200 );
        if ( '' === $clean['title'] ) {
            $errors['title'] = 'The event needs a name.';
        }

        $clean['description'] = $str( 'description', 5000 );
        if ( '' === $clean['description'] ) {
            $errors['description'] = 'Write a description. An event with none is blank on the calendar.';
        }

        /* ---- Terms. Every id must resolve to a real one. ---- */
        $clean['categories'] = array();
        $posted_cats = isset( $post['categories'] ) ? (array) $post['categories'] : array();
        foreach ( $posted_cats as $raw ) {
            $id   = (int) $raw;
            $term = $id ? get_term( $id, 'uc_event_category' ) : null;
            if ( $term && ! is_wp_error( $term ) ) {
                $clean['categories'][] = $id;
            }
        }
        $clean['categories'] = array_values( array_unique( $clean['categories'] ) );

        $clean['series'] = 0;
        if ( ! empty( $post['series'] ) ) {
            $id   = (int) $post['series'];
            $term = $id ? get_term( $id, SFAF_Series::TAXONOMY ) : null;
            if ( $term && ! is_wp_error( $term ) ) {
                $clean['series'] = $id;
            }
        }

        $clean['venue']       = 0;
        $clean['venue_other'] = '';
        if ( ! empty( $post['venue'] ) ) {
            $id = (int) $post['venue'];
            if ( $id && SFAF_Venues::exists( $id ) ) {
                $clean['venue'] = $id;
            }
        }
        if ( ! $clean['venue'] ) {
            $clean['venue_other'] = $str( 'venue_other', 200 );
        }

        /* ---- When. ---- */
        $clean['date'] = self::clean_date( isset( $post['date'] ) ? $post['date'] : '' );
        if ( '' === $clean['date'] ) {
            $errors['date'] = 'Give the date, as a real calendar date.';
        } elseif ( $clean['date'] < current_time( 'Y-m-d' ) ) {
            $errors['date'] = 'That date has passed. Give the date the event actually happens.';
        }

        $clean['start'] = self::clean_time( isset( $post['start_time'] ) ? $post['start_time'] : '' );
        $clean['end']   = self::clean_time( isset( $post['end_time'] ) ? $post['end_time'] : '' );
        if ( '' === $clean['start'] ) {
            $errors['start_time'] = 'Give a start time.';
        }
        if ( '' === $clean['end'] ) {
            $errors['end_time'] = 'Give an end time.';
        }
        if ( '' !== $clean['start'] && '' !== $clean['end'] && $clean['end'] <= $clean['start'] ) {
            $errors['end_time'] = 'The end time needs to be after the start time.';
        }

        /* ---- Repeating, in words. ---- */
        $repeat = isset( $post['repeat'] ) ? sanitize_key( wp_unslash( $post['repeat'] ) ) : 'none';
        $clean['repeat'] = array_key_exists( $repeat, self::repeat_options() ) ? $repeat : 'none';

        $clean['repeat_until'] = '';
        if ( 'none' !== $clean['repeat'] ) {
            $until = self::clean_date( isset( $post['repeat_until'] ) ? $post['repeat_until'] : '' );
            if ( '' !== $until && '' !== $clean['date'] && $until < $clean['date'] ) {
                $errors['repeat_until'] = 'The last date cannot be before the first one.';
            } else {
                $clean['repeat_until'] = $until;
            }
        }

        /* ---- The picture. ---- */
        $clean['image'] = 0;
        if ( ! empty( $post['image_id'] ) ) {
            $id = (int) $post['image_id'];
            /*
             * THREE CHECKS, NOT ONE. It must be an attachment, it must be an
             * image, and it must already be in the calendar folder. An id is
             * the easiest thing in the world to change in a form, and without
             * the last check this field would attach any file in the media
             * library, including a private PDF, to a public event.
             */
            if ( $id && 'attachment' === get_post_type( $id )
                && 0 === strpos( (string) get_post_mime_type( $id ), 'image/' )
                && SFAF_Media_Folder::holds( $id ) ) {
                $clean['image'] = $id;
            }
        }

        /* ---- Registration. ---- */
        $clean['rsvp']     = ! empty( $post['rsvp'] );
        $clean['capacity'] = 0;
        if ( $clean['rsvp'] && isset( $post['capacity'] ) && '' !== $post['capacity'] ) {
            $cap = (int) $post['capacity'];
            if ( $cap < 0 || $cap > 100000 ) {
                $errors['capacity'] = 'Give a number of places between 0 and 100000, or leave it blank for no limit.';
            } else {
                $clean['capacity'] = $cap;
            }
        }

        $clean['notes'] = $str( 'notes', 2000 );

        return array( 'clean' => $clean, 'errors' => $errors );
    }

    /**
     * Cut a string to a length without cutting a character in half.
     *
     * mbstring IS NOT GUARANTEED. It is a compiled extension, WordPress ships
     * its own fallbacks precisely because hosts turn up without it, and a
     * length cap that fatals is worse than one that is a byte out. So the
     * multibyte version is used where it exists and the byte version where it
     * does not, and the byte version trims any trailing partial character so a
     * cut name cannot end in half a letter.
     *
     * @param string $text
     * @param int    $max Characters.
     * @return string
     */
    public static function cap( $text, $max ) {
        $text = (string) $text;
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $text, 0, $max, 'UTF-8' );
        }
        if ( strlen( $text ) <= $max ) {
            return $text;
        }
        $out = substr( $text, 0, $max );
        // Drop a dangling continuation byte sequence left by the cut.
        while ( '' !== $out && ( ord( $out[ strlen( $out ) - 1 ] ) & 0xC0 ) === 0x80 ) {
            $out = substr( $out, 0, -1 );
        }
        // And the lead byte of the character those belonged to.
        if ( '' !== $out && ( ord( $out[ strlen( $out ) - 1 ] ) & 0xC0 ) === 0xC0 ) {
            $out = substr( $out, 0, -1 );
        }
        return $out;
    }

    /**
     * A date, only if it is a real one.
     *
     * PARSED AND COMPARED BACK. checkdate() alone accepts '2026-02-30' after
     * PHP has helpfully rolled it into March, so the parsed value is
     * reformatted and must equal what arrived.
     *
     * @param mixed $raw
     * @return string 'Y-m-d' or ''.
     */
    public static function clean_date( $raw ) {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) {
            return '';
        }
        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return '';
        }
        return $raw;
    }

    /**
     * A 24-hour time, or ''.
     *
     * @param mixed $raw
     * @return string 'H:i' or ''.
     */
    public static function clean_time( $raw ) {
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        // <input type="time"> sends seconds in some browsers when a step is set.
        if ( ! preg_match( '/^(\d{2}):(\d{2})(?::\d{2})?$/', $raw, $m ) ) {
            return '';
        }
        if ( (int) $m[1] > 23 || (int) $m[2] > 59 ) {
            return '';
        }
        return $m[1] . ':' . $m[2];
    }

    private static function handle_submission( $token, $email ) {
        if ( ! empty( $_POST['uc_website'] ) ) {
            self::render_done( '' );
            return;
        }

        /*
         * RATE LIMITED ON THE TOKEN AND ON THE CLIENT. The token limit is what
         * stops one working link being used to fill the pending queue; the
         * client limit is what stops somebody collecting several links first.
         */
        if ( ! self::allow( 'submit_tok', $token, 5, self::TOKEN_TTL )
            || ! self::allow( 'submit_ip', self::client(), 10, self::TOKEN_TTL ) ) {
            self::render_form( $token, $email, array(), array(
                'form' => 'That is several requests in a short time. Give it a few minutes, then try again.',
            ) );
            return;
        }

        $checked = self::validate( $_POST );
        if ( ! empty( $checked['errors'] ) ) {
            self::render_form( $token, $email, $checked['clean'], $checked['errors'] );
            return;
        }

        $event_id = self::create_event( $checked['clean'], $email );
        if ( ! $event_id ) {
            self::render_form( $token, $email, $checked['clean'], array(
                'form' => 'Something went wrong saving that. Try once more, and if it happens again email the MarCom team.',
            ) );
            return;
        }

        self::notify_admins( $event_id, $checked['clean'], $email );
        self::confirm_to_requester( $event_id, $checked['clean'], $email );

        self::render_done( $checked['clean']['title'] );
    }

    /**
     * Write the request as a pending event.
     *
     * PENDING, NOT DRAFT, because the pending queue is where somebody looks.
     * The status is set explicitly here and never taken from the form.
     *
     * post_author is left at 0 on purpose: nobody logged in made this, and
     * attributing it to whichever user id happened to be handy would put a
     * name against work they did not do. Who asked is recorded as meta and the
     * queue reads it from there.
     *
     * @param array  $c     Clean values from validate().
     * @param string $email The address the token belongs to.
     * @return int Event id, or 0.
     */
    private static function create_event( $c, $email ) {
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

        if ( $c['venue'] ) {
            SFAF_Venues::set_for_event( $event_id, $c['venue'] );
        } elseif ( '' !== $c['venue_other'] ) {
            update_post_meta( $event_id, '_uc_location', $c['venue_other'] );
            update_post_meta( $event_id, self::META_VENUE, $c['venue_other'] );
        }

        if ( ! empty( $c['categories'] ) ) {
            wp_set_object_terms( $event_id, $c['categories'], 'uc_event_category' );
        }
        if ( $c['series'] ) {
            SFAF_Series::set_for_event( $event_id, $c['series'] );
        }

        /*
         * THE SERIES PREFILLS THE PHOTO AND NOTHING ELSE, and it does it by
         * doing nothing: an event in a series with no picture of its own
         * already falls back to the series image through
         * sfaf_event_image_url(). So there is no copying here, which means
         * there is nothing to go stale if the series photo changes before
         * anybody approves this.
         */
        if ( $c['image'] ) {
            set_post_thumbnail( $event_id, $c['image'] );
        }

        if ( $c['rsvp'] ) {
            update_post_meta( $event_id, '_uc_rsvp_enabled', '1' );
            if ( $c['capacity'] > 0 ) {
                update_post_meta( $event_id, '_uc_capacity', $c['capacity'] );
            }
        }

        update_post_meta( $event_id, self::META_NAME, $c['name'] );
        update_post_meta( $event_id, self::META_EMAIL, $email );
        update_post_meta( $event_id, self::META_AT, current_time( 'mysql' ) );
        update_post_meta( $event_id, self::META_REPEAT, self::repeat_phrase( $c ) );
        if ( '' !== $c['notes'] ) {
            update_post_meta( $event_id, self::META_NOTES, $c['notes'] );
        }

        return $event_id;
    }

    /**
     * What was asked for about repeating, as one readable line.
     *
     * @param array $c
     * @return string
     */
    public static function repeat_phrase( $c ) {
        $options = self::repeat_options();
        $key     = isset( $c['repeat'] ) ? $c['repeat'] : 'none';
        if ( 'none' === $key || ! isset( $options[ $key ] ) ) {
            return $options['none'];
        }
        $phrase = $options[ $key ];
        if ( ! empty( $c['repeat_until'] ) ) {
            $phrase .= ', until ' . sfaf_ap_date( $c['repeat_until'], 'short_year' );
        } else {
            $phrase .= ', with no end date given';
        }
        return $phrase;
    }

    /* =====================================================================
     * Who is told
     * ================================================================== */

    /**
     * Everybody whose calendar role is Admin.
     *
     * TWO QUERIES, BECAUSE THERE ARE TWO WAYS TO BE ONE. A site administrator
     * is a calendar Admin without any `_uc_calendar_role` meta at all, so the
     * meta query alone misses exactly the people this is for. Both sets are
     * gathered and then each candidate is put through get_role(), which is the
     * one function that answers this, so the result cannot disagree with what
     * the portal itself would say.
     *
     * @return WP_User[]
     */
    public static function admin_users() {
        $candidates = array();

        $by_meta = get_users( array(
            'meta_key'   => '_uc_calendar_role',
            'meta_value' => 'admin',
            'number'     => 200,
        ) );
        $by_cap = get_users( array(
            'capability' => 'manage_options',
            'number'     => 200,
        ) );

        foreach ( array_merge( (array) $by_meta, (array) $by_cap ) as $user ) {
            if ( $user instanceof WP_User ) {
                $candidates[ (int) $user->ID ] = $user;
            }
        }

        $out = array();
        foreach ( $candidates as $id => $user ) {
            if ( 'admin' === SFAF_Portal::get_role( $id ) && is_email( $user->user_email ) ) {
                $out[] = $user;
            }
        }
        return $out;
    }

    /**
     * One email per request, to the people who approve them.
     *
     * @param int    $event_id
     * @param array  $c
     * @param string $email
     */
    private static function notify_admins( $event_id, $c, $email ) {
        $admins = self::admin_users();
        if ( empty( $admins ) ) {
            return;
        }

        $queue = SFAF_Portal::link( 'pending' );
        $edit  = SFAF_Portal::link( 'events/edit/' . $event_id );

        $rows = array(
            'Event'     => $c['title'],
            'Date'      => sfaf_ap_date( $c['date'], 'full' ),
            'Time'      => sfaf_ap_time_range( $c['start'], $c['end'] ),
            'Repeats'   => self::repeat_phrase( $c ),
            'Requested by' => $c['name'] . ' (' . $email . ')',
        );

        $html = SFAF_Email::heading( 'An event has been requested' )
            . SFAF_Email::para( $c['name'] . ' has asked for an event to be added. It is in the pending queue as a staff request.' )
            . SFAF_Email::details( $rows )
            . SFAF_Email::button_row( array( SFAF_Email::button( $edit, 'Open the request' ) ) )
            . SFAF_Email::link_para( $queue, 'See everything waiting' );

        $text = $c['name'] . " has asked for an event to be added.\n\n"
            . $c['title'] . "\n" . sfaf_ap_date( $c['date'], 'full' ) . "\n"
            . sfaf_ap_time_range( $c['start'], $c['end'] ) . "\n\n"
            . "Requested by: " . $c['name'] . ' (' . $email . ")\n\n"
            . "Open it: " . $edit . "\nThe queue: " . $queue;

        $subject = 'Event requested: ' . $c['title'];
        $shell   = SFAF_Email::shell( $c['name'] . ' asked for an event', $html );

        /*
         * ONE MESSAGE EACH, ADDRESSED TO THEM. Not one message with everybody
         * in the To line, which shows each admin the others' addresses and
         * makes a reply-all the default answer.
         *
         * The requester is the reply-to, so answering a question about the
         * request goes to the person who asked rather than to a mailbox.
         */
        foreach ( $admins as $user ) {
            SFAF_Email::send( $user->user_email, $subject, $shell, $text, $email );
        }
    }

    /**
     * Tell the requester what they sent, and that it is now somebody's job.
     *
     * NOTHING AFTER THIS. No "still waiting" mail and no approval notice:
     * chasing is a person's job, and a system that nags on somebody's behalf
     * teaches people to filter it.
     *
     * @param int    $event_id
     * @param array  $c
     * @param string $email
     */
    private static function confirm_to_requester( $event_id, $c, $email ) {
        $rows = array(
            'Event'    => $c['title'],
            'Date'     => sfaf_ap_date( $c['date'], 'full' ),
            'Time'     => sfaf_ap_time_range( $c['start'], $c['end'] ),
            'Repeats'  => self::repeat_phrase( $c ),
            'Where'    => $c['venue'] ? SFAF_Venues::display( $c['venue'] ) : $c['venue_other'],
            'Register' => $c['rsvp'] ? ( $c['capacity'] > 0 ? 'Yes, ' . $c['capacity'] . ' places' : 'Yes, no limit on places' ) : 'No',
        );

        $html = SFAF_Email::heading( 'Thanks, that is with the team' )
            . SFAF_Email::para( 'Here is what you sent. The MarCom team will look at it and put it on the calendar, or come back to you if something needs sorting out.' )
            . SFAF_Email::details( $rows )
            . SFAF_Email::rule()
            . SFAF_Email::small_para( 'Nothing else happens automatically. If it is urgent, tell somebody on the team directly.' );

        $text = "Thanks, that is with the team.\n\n"
            . $c['title'] . "\n" . sfaf_ap_date( $c['date'], 'full' ) . "\n"
            . sfaf_ap_time_range( $c['start'], $c['end'] ) . "\n\n"
            . "The MarCom team will look at it. Nothing else happens automatically.";

        SFAF_Email::send( $email, 'We have your event request', SFAF_Email::shell( 'Your event request', $html ), $text );
    }

    /* =====================================================================
     * Screens
     *
     * ITS OWN DOCUMENT, like the portal, because this must not inherit a
     * theme's header, its navigation or its cookie banner. It reuses the
     * portal stylesheet so it looks like the rest of the calendar, and adds
     * nothing that would let somebody navigate anywhere.
     * ================================================================== */

    private static function page_open( $title ) {
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
<link rel="stylesheet" href="<?php echo esc_url( SFAF_PLUGIN_URL . 'public/css/portal.css?ver=' . SFAF_VERSION ); ?>" />
</head>
<body class="uc-portal uc-request-page">
<div class="uc-request-wrap">
        <?php
    }

    private static function page_close() {
        ?>
</div>
<script src="<?php echo esc_url( SFAF_PLUGIN_URL . 'public/js/portal.js?ver=' . SFAF_VERSION ); ?>"></script>
</body></html><?php
    }

    private static function render_start( $error = '' ) {
        self::page_open( 'Request an event' );
        ?>
        <div class="uc-request-card">
            <h1>Request an event</h1>
            <p class="uc-hint">
                Ask the MarCom team to put an event on the SFAF calendar. Enter your work address and
                we will send you a link to the form.
            </p>
            <?php if ( '' !== $error ) : ?>
                <p class="uc-field-error" role="alert"><?php echo esc_html( $error ); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( self::start_url() ); ?>" class="uc-form">
                <input type="hidden" name="uc_request_action" value="send_link" />
                <label class="uc-field">
                    <span class="uc-field-label">Your work email address</span>
                    <input type="email" name="email" required autocomplete="email"
                           placeholder="you@<?php echo esc_attr( self::DOMAIN ); ?>" />
                </label>
                <?php self::honeypot(); ?>
                <div class="uc-form-actions uc-form-actions-primary">
                    <button type="submit" class="uc-btn uc-btn-primary">Send me the link</button>
                </div>
            </form>
        </div>
        <?php
        self::page_close();
    }

    private static function render_link_sent() {
        self::page_open( 'Check your email' );
        ?>
        <div class="uc-request-card">
            <h1>Check your email</h1>
            <p>
                If that is an <?php echo esc_html( self::DOMAIN ); ?> address, a link to the form is on its way.
                It works for the next <?php echo (int) round( self::TOKEN_TTL / 60 ); ?> minutes.
            </p>
            <p class="uc-hint">
                Nothing arrived? Check the junk folder, then ask for another link. If you have asked
                several times in the last hour, give it a few minutes first.
            </p>
            <p><a class="uc-btn" href="<?php echo esc_url( self::start_url() ); ?>">Ask again</a></p>
        </div>
        <?php
        self::page_close();
    }

    private static function render_expired() {
        self::page_open( 'That link has expired' );
        ?>
        <div class="uc-request-card">
            <h1>That link has expired</h1>
            <p>Links work for <?php echo (int) round( self::TOKEN_TTL / 60 ); ?> minutes. Ask for a new one and it will open the form.</p>
            <p><a class="uc-btn uc-btn-primary" href="<?php echo esc_url( self::start_url() ); ?>">Get a new link</a></p>
        </div>
        <?php
        self::page_close();
    }

    private static function render_done( $title ) {
        self::page_open( 'Request sent' );
        ?>
        <div class="uc-request-card">
            <h1>That is with the team</h1>
            <?php if ( '' !== $title ) : ?>
                <p><strong><?php echo esc_html( $title ); ?></strong> has gone to the MarCom team, and we have emailed you a copy of what you sent.</p>
            <?php else : ?>
                <p>Your request has gone to the MarCom team.</p>
            <?php endif; ?>
            <p class="uc-hint">
                Somebody will check it and put it on the calendar, or come back to you if something needs
                sorting out. Nothing else happens automatically, so if it is urgent tell somebody directly.
            </p>
        </div>
        <?php
        self::page_close();
    }

    /** A field no person sees. See handle_link_request(). */
    private static function honeypot() {
        ?>
        <div class="uc-hp" aria-hidden="true">
            <label>Website<input type="text" name="uc_website" value="" tabindex="-1" autocomplete="off" /></label>
        </div>
        <?php
    }

    /**
     * The form itself.
     *
     * @param string $token
     * @param string $email
     * @param array  $c      Values to put back, after a rejected submission.
     * @param array  $errors field => message.
     */
    private static function render_form( $token, $email, $c, $errors ) {
        $v = function ( $key, $fallback = '' ) use ( $c ) {
            return isset( $c[ $key ] ) ? $c[ $key ] : $fallback;
        };
        $err = function ( $key ) use ( $errors ) {
            return isset( $errors[ $key ] ) ? $errors[ $key ] : '';
        };

        self::page_open( 'Request an event' );
        ?>
        <div class="uc-request-card">
            <h1>Request an event</h1>
            <p class="uc-hint">
                Asking as <strong><?php echo esc_html( $email ); ?></strong>. Fill this in and the MarCom
                team will take it from here.
            </p>

            <?php if ( '' !== $err( 'form' ) ) : ?>
                <p class="uc-field-error" role="alert"><?php echo esc_html( $err( 'form' ) ); ?></p>
            <?php elseif ( ! empty( $errors ) ) : ?>
                <p class="uc-field-error" role="alert">Some of this needs another look. The fields are marked below.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( self::form_url( $token ) ); ?>" class="uc-form">
                <input type="hidden" name="uc_request_action" value="submit_request" />
                <input type="hidden" name="uc_token" value="<?php echo esc_attr( $token ); ?>" />
                <?php self::honeypot(); ?>

                <label class="uc-field">
                    <span class="uc-field-label">Your name</span>
                    <input type="text" name="requester_name" required maxlength="120" value="<?php echo esc_attr( $v( 'name' ) ); ?>" />
                    <?php self::field_error( $err( 'requester_name' ) ); ?>
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">Event name</span>
                    <input type="text" name="title" required maxlength="200" value="<?php echo esc_attr( $v( 'title' ) ); ?>" />
                    <?php self::field_error( $err( 'title' ) ); ?>
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">Description</span>
                    <textarea name="description" rows="5" required maxlength="5000"><?php echo esc_textarea( $v( 'description' ) ); ?></textarea>
                    <span class="uc-hint">What it is, who it is for, and what somebody should expect.</span>
                    <?php self::field_error( $err( 'description' ) ); ?>
                </label>

                <?php $cats = get_terms( array( 'taxonomy' => 'uc_event_category', 'hide_empty' => false ) ); ?>
                <?php if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) : ?>
                    <fieldset class="uc-field">
                        <legend class="uc-field-label">Categories</legend>
                        <div class="uc-check-grid">
                            <?php foreach ( $cats as $term ) : ?>
                                <label class="uc-check">
                                    <input type="checkbox" name="categories[]" value="<?php echo (int) $term->term_id; ?>"
                                        <?php checked( in_array( (int) $term->term_id, (array) $v( 'categories', array() ), true ) ); ?> />
                                    <?php echo esc_html( $term->name ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endif; ?>

                <?php $all_series = SFAF_Series::all(); ?>
                <?php if ( ! empty( $all_series ) ) : ?>
                    <label class="uc-field">
                        <span class="uc-field-label">Part of a series?</span>
                        <select name="series" data-uc-request-series>
                            <option value="0">Not part of one</option>
                            <?php foreach ( $all_series as $s ) : ?>
                                <option value="<?php echo (int) $s->term_id; ?>" <?php selected( (int) $v( 'series' ), (int) $s->term_id ); ?>>
                                    <?php echo esc_html( $s->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="uc-hint">Choosing one uses that series' photo if you do not pick a picture below. It does not fill in anything else.</span>
                    </label>
                <?php endif; ?>

                <label class="uc-field">
                    <span class="uc-field-label">Date</span>
                    <input type="date" name="date" required value="<?php echo esc_attr( $v( 'date' ) ); ?>" />
                    <?php self::field_error( $err( 'date' ) ); ?>
                </label>

                <div class="uc-field-row">
                    <label class="uc-field">
                        <span class="uc-field-label">Start</span>
                        <input type="time" name="start_time" required value="<?php echo esc_attr( $v( 'start' ) ); ?>" />
                        <?php self::field_error( $err( 'start_time' ) ); ?>
                    </label>
                    <label class="uc-field">
                        <span class="uc-field-label">End</span>
                        <input type="time" name="end_time" required value="<?php echo esc_attr( $v( 'end' ) ); ?>" />
                        <?php self::field_error( $err( 'end_time' ) ); ?>
                    </label>
                </div>

                <label class="uc-field">
                    <span class="uc-field-label">Does it repeat?</span>
                    <select name="repeat">
                        <?php foreach ( self::repeat_options() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v( 'repeat', 'none' ), $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">If it repeats, until when?</span>
                    <input type="date" name="repeat_until" value="<?php echo esc_attr( $v( 'repeat_until' ) ); ?>" />
                    <?php self::field_error( $err( 'repeat_until' ) ); ?>
                </label>

                <?php $venues = SFAF_Venues::all(); ?>
                <label class="uc-field">
                    <span class="uc-field-label">Where</span>
                    <select name="venue">
                        <option value="0">Somewhere else (say where below)</option>
                        <?php foreach ( (array) $venues as $venue ) : ?>
                            <option value="<?php echo (int) $venue->term_id; ?>" <?php selected( (int) $v( 'venue' ), (int) $venue->term_id ); ?>>
                                <?php echo esc_html( $venue->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="uc-field">
                    <span class="uc-field-label">If somewhere else, where</span>
                    <input type="text" name="venue_other" maxlength="200" value="<?php echo esc_attr( $v( 'venue_other' ) ); ?>"
                           placeholder="470 Castro St, San Francisco" />
                </label>

                <?php self::render_image_choice( (int) $v( 'image' ) ); ?>

                <label class="uc-check">
                    <input type="checkbox" name="rsvp" value="1" <?php checked( (bool) $v( 'rsvp', false ) ); ?> />
                    People need to register
                </label>
                <label class="uc-field">
                    <span class="uc-field-label">How many places, if there is a limit</span>
                    <input type="number" name="capacity" min="0" max="100000" value="<?php echo esc_attr( $v( 'capacity' ) ? $v( 'capacity' ) : '' ); ?>" />
                    <?php self::field_error( $err( 'capacity' ) ); ?>
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">Anything else we should know</span>
                    <textarea name="notes" rows="3" maxlength="2000"><?php echo esc_textarea( $v( 'notes' ) ); ?></textarea>
                </label>

                <div class="uc-form-actions uc-form-actions-primary">
                    <p class="uc-form-actions-note">This goes to the MarCom team. You will get a copy of it by email.</p>
                    <button type="submit" class="uc-btn uc-btn-primary">Send the request</button>
                </div>
            </form>
        </div>
        <?php
        self::page_close();
    }

    private static function field_error( $message ) {
        if ( '' === $message ) {
            return;
        }
        echo '<span class="uc-field-error">' . esc_html( $message ) . '</span>';
    }

    /**
     * Pick a picture, from the calendar folder only.
     *
     * NOT wp.media, AND IT CANNOT BE. The media frame needs a logged-in user
     * with upload_files, so on a page with no account it would not open at
     * all. This is the same RULE as the event editor's picker, in the only
     * shape that works here: the folder, rendered as a list of radio buttons,
     * chosen server-side from the same SFAF_Media_Folder query.
     *
     * THERE IS NO UPLOAD, and that is deliberate rather than unfinished. An
     * upload endpoint reachable with no account is the highest-risk thing this
     * form could carry, and the brief already supplies the alternative: ask
     * Roxane for a picture. So the answer to "nothing here suits" is a person,
     * not a file input.
     *
     * @param int $chosen
     */
    private static function render_image_choice( $chosen ) {
        $images = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 60,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array(
                    'key'     => '_wp_attached_file',
                    'value'   => '^' . preg_quote( SFAF_Media_Folder::prefix() ),
                    'compare' => 'REGEXP',
                ),
            ),
        ) );
        ?>
        <fieldset class="uc-field uc-request-images">
            <legend class="uc-field-label">Picture</legend>
            <p class="uc-hint uc-hint-spec">
                <strong>1200 x 675 pixels, 16:9 landscape.</strong> Cards crop to this shape and fill it.
            </p>
            <?php if ( empty( $images ) ) : ?>
                <p class="uc-hint">
                    There are no calendar pictures to choose from yet. Ask Roxane Chicoine for one before
                    you submit, or send this without a picture and say so in the notes.
                </p>
            <?php else : ?>
                <div class="uc-request-image-grid">
                    <label class="uc-request-image uc-request-image-none">
                        <input type="radio" name="image_id" value="0" <?php checked( 0, $chosen ); ?> />
                        <span>No picture</span>
                    </label>
                    <?php foreach ( $images as $img ) :
                        $thumb = wp_get_attachment_image_url( $img->ID, 'medium' );
                        if ( ! $thumb ) {
                            continue;
                        } ?>
                        <label class="uc-request-image">
                            <input type="radio" name="image_id" value="<?php echo (int) $img->ID; ?>" <?php checked( (int) $img->ID, $chosen ); ?> />
                            <img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy" />
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="uc-hint">
                    Nothing here suitable? Ask <strong>Roxane Chicoine</strong> for an image before you
                    submit, and it will be in this list.
                </p>
            <?php endif; ?>
        </fieldset>
        <?php
    }
}
