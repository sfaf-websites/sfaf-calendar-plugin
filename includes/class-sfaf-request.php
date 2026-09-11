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

    /** Where a live link is stored, keyed on a hash of the token. */
    const TOKEN_PREFIX = 'sfaf_evreq_tok_';

    /* What a request records about itself, beyond the event's own fields. */
    const META_NAME   = '_uc_request_name';
    const META_EMAIL  = '_uc_request_email';
    const META_AT     = '_uc_request_at';
    const META_REPEAT = '_uc_request_repeat';
    const META_NOTES  = '_uc_request_notes';
    const META_VENUE  = '_uc_request_venue_other';

    /**
     * What the requester asked for about repeating, as the engine spells it.
     *
     * FOUR KEYS, AND NONE OF THEM IS SFAF_Recurrence::PATTERN_META. That is the
     * whole point of them existing (3.72.0). The request form asks the real
     * question with the real control now, so what comes back is a real pattern
     * string, and storing it under the real key would make a pending request
     * that nobody has read look exactly like a schedule a manager built: every
     * reader of PATTERN_META would start answering about it, and the schedule
     * screen's generate button would be one press from creating a year of posts
     * for an event still awaiting review.
     *
     * READ BY ONE THING. The schedule screen fills its control in from these
     * when an approved request first opens, and the approver presses the button
     * with the summary in front of them. Nothing else reads them and nothing
     * generates from them.
     */
    const META_PATTERN       = '_uc_request_pattern';
    const META_PATTERN_UNTIL = '_uc_request_pattern_until';
    const META_PATTERN_LIMIT = '_uc_request_pattern_limit';
    const META_PATTERN_DATES = '_uc_request_pattern_dates';

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
     * Remembering a confirmed address for 30 days
     *
     * WHAT IT IS AND WHAT IT IS NOT. Once somebody has followed a link sent to
     * their sfaf.org mailbox, this browser is told to remember that address so
     * they go straight to the form for a month instead of asking for a link
     * every time. It is NOT a login: it grants nothing anywhere else, it is
     * read on this one query var, and the only thing it can do is prefill the
     * form and mint a fresh short-lived token for that same address.
     *
     * IT IS SIGNED, WHICH IS THE WHOLE OF ITS SECURITY. A cookie holding a
     * plain address would let anybody submit as any colleague by editing one
     * string. The value carries an HMAC over the address and its expiry, keyed
     * on a WordPress salt, so a cookie this server did not write is refused. It
     * still says nothing secret, so it does not need to be secret; it needs to
     * be unforgeable.
     *
     * THE DOMAIN RULE STILL APPLIES ON THE WAY OUT. remembered() re-asks
     * is_staff_address(), so an address that stopped qualifying, or a cookie
     * from before the rule changed, is refused rather than trusted because it
     * was trusted once.
     *
     * IT IS SHOWN, NOT SILENT. A shared machine is the case this has to be safe
     * on, so the form says which address it is about to submit as, and offers a
     * way to switch that clears the cookie.
     * ================================================================== */

    /** The cookie's name, and how long it lasts. */
    const COOKIE      = 'sfaf_evreq_who';
    const COOKIE_DAYS = 30;

    /**
     * The signature over an address and its expiry.
     *
     * wp_salt() rather than a constant of ours: it is already per-install,
     * already secret, and already rotated by the same tooling that rotates
     * everything else. A hard-coded key in a plugin file is the same key on
     * every site that installs it.
     *
     * @param string $email
     * @param int    $expires
     * @return string
     */
    private static function cookie_signature( $email, $expires ) {
        return hash_hmac( 'sha256', strtolower( $email ) . '|' . (int) $expires, wp_salt( 'auth' ) );
    }

    /**
     * Remember this address in this browser.
     *
     * Called only after a token has resolved, which is the moment the mailbox
     * has proved the address belongs to whoever is holding the link.
     *
     * @param string $email
     */
    public static function remember( $email ) {
        if ( headers_sent() || ! self::is_staff_address( $email ) ) {
            return;
        }
        $email   = strtolower( trim( $email ) );
        $expires = time() + ( self::COOKIE_DAYS * DAY_IN_SECONDS );
        setcookie( self::COOKIE, $email . '|' . $expires . '|' . self::cookie_signature( $email, $expires ), array(
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => is_ssl(),
            /* No script has any reason to read this, and one that could would
             * be reading a colleague's address off a shared machine. */
            'httponly' => true,
            'samesite' => 'Lax',
        ) );
    }

    /** Stop remembering, and say so to the browser rather than just to us. */
    public static function forget() {
        if ( headers_sent() ) {
            return;
        }
        setcookie( self::COOKIE, '', array(
            'expires'  => time() - DAY_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ) );
        unset( $_COOKIE[ self::COOKIE ] );
    }

    /**
     * The remembered address, or '' if there is not a good one.
     *
     * EVERY FAILURE CLEARS THE COOKIE. A malformed, expired, unsigned or
     * no-longer-staff value is not just ignored: it is removed, so a browser
     * carrying a bad one stops sending it rather than failing this check on
     * every page load forever.
     *
     * @return string
     */
    public static function remembered() {
        if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
            return '';
        }

        $parts = explode( '|', (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) );
        if ( 3 !== count( $parts ) ) {
            self::forget();
            return '';
        }

        $email   = strtolower( trim( $parts[0] ) );
        $expires = (int) $parts[1];
        $given   = (string) $parts[2];

        if ( $expires < time() ) {
            self::forget();
            return '';
        }
        /* hash_equals, not ===: a timing-safe comparison costs nothing here and
         * the alternative leaks how much of a forged signature was right. */
        if ( ! hash_equals( self::cookie_signature( $email, $expires ), $given ) ) {
            self::forget();
            return '';
        }
        if ( ! self::is_staff_address( $email ) ) {
            self::forget();
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
     * without a per-client limit, every address in the organization can be
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
        return SFAF_Submissions::allow( $bucket, $who, $limit, $window );
    }

    private static function client() {
        return SFAF_Submissions::client();
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

        /*
         * SWITCHING ADDRESS IS AN ACTION, AND IT IS THE FIRST THING CHECKED.
         * Somebody on a shared machine who has noticed a colleague's address on
         * the form needs the way out to work whatever else is in the request.
         */
        if ( ! empty( $_GET['uc_switch'] ) ) {
            self::forget();
            self::render_start();
            exit;
        }

        $token = isset( $_GET['uc_token'] ) ? sanitize_text_field( wp_unslash( $_GET['uc_token'] ) ) : '';
        if ( $posted && 'submit_request' === $action ) {
            $token = isset( $_POST['uc_token'] ) ? sanitize_text_field( wp_unslash( $_POST['uc_token'] ) ) : '';
        }

        if ( '' === $token ) {
            /*
             * A REMEMBERED ADDRESS SKIPS THE EMAIL STEP, AND NOTHING ELSE.
             *
             * The cookie does not authorise a submission by itself: it names an
             * address, and a FRESH token is minted for that address exactly as
             * if a link had just been followed. So the submission path below is
             * unchanged, the token still expires in an hour, and every rate
             * limit still counts. What is saved is the round trip through a
             * mailbox, not any of the checking.
             */
            $known = self::remembered();
            if ( '' !== $known ) {
                self::render_form( self::issue_token( $known ), $known, array(), array(), true );
                exit;
            }
            self::render_start();
            exit;
        }

        $email = self::resolve_token( $token );
        if ( '' === $email ) {
            /* The link failed, so whatever this browser was remembering about
             * it is not worth keeping either. */
            self::forget();
            self::render_expired();
            exit;
        }

        if ( $posted && 'submit_request' === $action ) {
            self::handle_submission( $token, $email );
            exit;
        }

        /* Following a live link is the proof that the mailbox is theirs, so
         * this is the one place the cookie is written. */
        self::remember( $email );
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

        /*
         * PROSE, NOT A STRIPPED LINE (3.46.0).
         *
         * 3.43.0 stripped markup from everything here, and said why: nobody
         * needs to send HTML through a request form, and text can never become
         * anything else later. Staff behind an emailed link is a lower risk
         * than that rule was written for, so the description is a rich text
         * field now.
         *
         * IT IS STILL SANITISED ON THE WAY IN, and by the narrow list rather
         * than by wp_kses_post(). The editor is trusted to be convenient, never
         * to be the check: what actually arrives is a POST body, and a POST
         * body is a POST body whoever the form was drawn for. See
         * SFAF_Submissions::prose() for exactly what survives.
         */
        $clean['description'] = self::cap(
            SFAF_Submissions::prose( isset( $post['description'] ) ? wp_unslash( $post['description'] ) : '' ),
            8000
        );
        if ( '' === trim( wp_strip_all_tags( $clean['description'] ) ) ) {
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

        /* ---- Who is putting it on (3.76.0). ----
         *
         * CHECKED AGAINST THE TAXONOMY, never trusted. It is a select on the
         * form and an integer in a POST, and this page is reached by a link, so
         * the only thing that makes an id an organizer is asking. 0 is the
         * "Not sure" answer and is a real one: it stores nothing. */
        $clean['organizer'] = 0;
        if ( ! empty( $post['organizer'] ) ) {
            $oid = (int) $post['organizer'];
            if ( $oid && SFAF_Organizers::exists( $oid ) ) {
                $clean['organizer'] = $oid;
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

        /*
         * ---- Repeating: the real pattern, read by the real reader (3.72.0).
         *
         * SFAF_Recurrence::from_post() is the same method caladmin's save
         * calls, handed this form's POST. It returns a pattern string, an end
         * date, an occurrence limit and any explicitly named dates, and it
         * returns an empty pattern for every answer it cannot make sense of,
         * which is the behaviour that matters here: this form is filled in by
         * somebody with no account and the default in every direction is "no".
         *
         * ANCHORED ON THIS FORM'S OWN DATE. from_post() reads $post['date']
         * for that, which is the field this form already posts under that
         * name, so the extra dates are cleaned against the start date exactly
         * as they are in caladmin.
         *
         * NOTHING IS ARMED. What comes back is stored on the pending request
         * under keys of its own; see create_event(). PATTERN_META is not
         * written and no occurrence is generated.
         *
         * THE OLD `repeat` SELECT IS STILL READ, and deliberately. A browser
         * can hold a form open across a plugin update, and from_post() honours
         * that legacy field itself for exactly that reason. What is kept here
         * is the human phrase: repeat_phrase() puts a sentence in the email to
         * the approver, and a pattern string like `weekly:1:3` is not one.
         */
        $repeat = isset( $post['repeat'] ) ? sanitize_key( wp_unslash( $post['repeat'] ) ) : 'none';
        $clean['repeat'] = array_key_exists( $repeat, self::repeat_options() ) ? $repeat : 'none';

        list( $r_pattern, $r_until, $r_limit, $r_dates ) = SFAF_Recurrence::from_post( $post );
        $clean['pattern']       = $r_pattern;
        $clean['pattern_limit'] = (int) $r_limit;
        $clean['pattern_dates'] = $r_dates;

        /*
         * AN "UNTIL" WITH NOTHING TO BOUND IS NOT AN ANSWER, and it is dropped
         * rather than stored. Somebody who chooses "it happens once" and leaves
         * a date in the until field, which the old select made easy and the new
         * control still allows without script, has said nothing about
         * repeating; keeping the date would put "until December 3" on a
         * one-off request and give the approver something to reconcile that
         * nobody meant. The mirror of the rule from_post() applies in the other
         * direction, where "until" with no date drops the pattern.
         */
        $clean['repeat_until'] = '';
        if ( '' !== $r_pattern ) {
            if ( '' !== $r_until && '' !== $clean['date'] && $r_until < $clean['date'] ) {
                $errors['repeat_until'] = 'The last date cannot be before the first one.';
            } else {
                $clean['repeat_until'] = $r_until;
            }
        }

        /* ---- The picture. ----
         *
         * The three checks moved to SFAF_Submissions::clean_image_choice() in
         * 3.74.0, when the community form gained the same picker. One rule for
         * what a form may attach to a public page. */
        $clean['image'] = SFAF_Submissions::clean_image_choice( $post );

        /* ---- Who should be able to edit it. ----
         *
         * TEAMS, NEVER INDIVIDUALS. A team is a name and a set of user ids that
         * resolves AT READ TIME, so adding somebody to a team hands them every
         * event that team owns and removing them takes it back. A typed address
         * is a string nobody maintains. The requester knows the team's name and
         * has no business seeing who is in it, so this offers names and nothing
         * else. See SFAF_Teams.
         *
         * THE CAP IS THE ONE THAT ALREADY EXISTS. An event names at most
         * SFAF_Teams::MAX_PER_EVENT teams in caladmin, and a second number here
         * would be a second answer to the same question, free to disagree.
         *
         * OVER THE CAP IS AN ERROR, NOT A SILENT TRIM. set_access_for_event()
         * stops at the cap, so passing three would quietly drop one and the
         * requester would never know which. With scripting off there is nothing
         * to stop a third box being ticked, so the server has to say so.
         */
        $clean['teams'] = array();
        $known_teams    = SFAF_Teams::all();
        foreach ( (array) ( isset( $post['request_teams'] ) ? $post['request_teams'] : array() ) as $team_id ) {
            // A hand-built post body can nest an array here, and casting one to
            // a string is a PHP warning and an empty key. Anything that is not
            // a scalar names no team.
            if ( ! is_scalar( $team_id ) ) {
                continue;
            }
            $team_id = sanitize_key( (string) $team_id );
            if ( '' !== $team_id && isset( $known_teams[ $team_id ] ) && ! in_array( $team_id, $clean['teams'], true ) ) {
                $clean['teams'][] = $team_id;
            }
        }
        if ( count( $clean['teams'] ) > SFAF_Teams::MAX_PER_EVENT ) {
            $errors['request_teams'] = sprintf(
                'Choose at most %d %s.',
                SFAF_Teams::MAX_PER_EVENT,
                ( 1 === SFAF_Teams::MAX_PER_EVENT ) ? 'team' : 'teams'
            );
        }

        /* ---- RSVP. ---- */
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

        /* ---- FAQs: a set, questions of their own, or both. ----
         *
         * NEITHER IS REQUIRED AND NEITHER VALIDATES. A set id naming nothing is
         * dropped by faqs_for(), and an untouched repeater row is dropped by
         * clean_faqs(). There is no way for a requester to get this wrong, so
         * there is no error to raise about it.
         *
         * The set id is kept on $clean as well, so a form that comes back with
         * errors elsewhere still shows the set they picked. */
        $clean['faq_set'] = isset( $post['faq_set'] ) ? sanitize_text_field( wp_unslash( $post['faq_set'] ) ) : '';
        $clean['faq_own'] = SFAF_Submit::clean_faqs( isset( $post['faq'] ) ? $post['faq'] : array() );
        $clean['faqs']    = self::faqs_for( $clean['faq_set'], $clean['faq_own'] );

        $clean['notes'] = $str( 'notes', 2000 );

        return array( 'clean' => $clean, 'errors' => $errors );
    }

    /**
     * A set's questions, then the requester's own, as one list.
     *
     * A COPY OF THE SET, NOT A REFERENCE TO IT, and that is caladmin's rule
     * followed rather than a new one invented. SFAF_FAQ_Sets documents why at
     * the top of that file: a link would mean one edit rewriting every event
     * that ever used the set, including past events whose answers were correct
     * at the time. Copying fails safe, and it is also what makes deleting a set
     * harmless. So nothing here stores the set id on the event; what is stored
     * is the text, and the set is free to change or vanish afterwards.
     *
     * THE SET'S QUESTIONS COME FIRST AND THE REQUESTER'S ARE APPENDED. No
     * interleaving. A manager reordering them at approval is one drag; a rule
     * that guessed at an order would be a rule to explain.
     *
     * DUPLICATES ARE SKIPPED, by the same fingerprint apply() uses, so a
     * requester who picks the parking set AND types "Is there parking?" sends
     * one of it rather than two.
     *
     * THE SET'S ROWS ARE RE-CLEANED THROUGH THE ANONYMOUS RULE. They were
     * written in caladmin under wp_kses_post(), which is wider than anything
     * this form accepts. Running them through the narrow list keeps one answer
     * to "what may arrive from this form" regardless of where the text came
     * from, and costs nothing: the short toolbar cannot produce anything the
     * narrow list rejects. NOTHING IS WIDENED to carry a set across.
     *
     * CAPPED WHERE EVERY OTHER FAQ LIST IS, at SFAF_FAQ_Sets::MAX_ROWS, read
     * from that class rather than typed again.
     *
     * @param string $set_id
     * @param array  $own Already through SFAF_Submit::clean_faqs().
     * @return array<int,array{question:string,answer:string}>
     */
    public static function faqs_for( $set_id, $own ) {
        $out  = array();
        $seen = array();

        $set = ( '' !== (string) $set_id ) ? SFAF_FAQ_Sets::get( $set_id ) : null;
        if ( $set ) {
            /* clean_faqs() takes the raw shape the form posts, which is what a
             * set's rows already are: question and answer strings. */
            foreach ( SFAF_Submit::clean_faqs( $set['rows'] ) as $row ) {
                $print = SFAF_FAQ_Sets::fingerprint( $row['question'] );
                if ( '' !== $print && isset( $seen[ $print ] ) ) {
                    continue;
                }
                $seen[ $print ] = true;
                $out[]          = $row;
            }
        }

        foreach ( (array) $own as $row ) {
            $print = SFAF_FAQ_Sets::fingerprint( isset( $row['question'] ) ? $row['question'] : '' );
            if ( '' !== $print && isset( $seen[ $print ] ) ) {
                continue;
            }
            $seen[ $print ] = true;
            $out[]          = $row;
        }

        return array_slice( $out, 0, SFAF_FAQ_Sets::MAX_ROWS );
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

        /*
         * THE UPLOAD IS COUNTED SEPARATELY FROM THE SUBMISSION. A rejected form
         * can be resent several times over, and each attempt may carry a file;
         * without its own counter, the disk work rides on a limit that was set
         * for creating pending posts.
         */
        $upload = SFAF_Uploads::store( 'uc_image', function () use ( $token ) {
            return SFAF_Submissions::allow( 'upload_tok', $token, 10, self::TOKEN_TTL );
        } );
        if ( '' !== $upload['error'] ) {
            $checked['errors']['uc_image'] = $upload['error'];
        }

        if ( ! empty( $checked['errors'] ) ) {
            /* A file input cannot be refilled by the server, so the visitor has
             * to choose it again anyway; keeping this one would leave an orphan
             * on disk for every rejected attempt. */
            if ( $upload['id'] ) {
                wp_delete_attachment( $upload['id'], true );
            }
            self::render_form( $token, $email, $checked['clean'], $checked['errors'] );
            return;
        }

        $event_id = self::create_event( $checked['clean'], $email, (int) $upload['id'] );
        if ( ! $event_id ) {
            if ( $upload['id'] ) {
                wp_delete_attachment( $upload['id'], true );
            }
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
    private static function create_event( $c, $email, $upload_id = 0 ) {
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
        /* Who is putting it on, when the requester said. "Not sure" is 0 and
         * writes nothing, which leaves the event exactly as it arrived before
         * this field existed. */
        if ( ! empty( $c['organizer'] ) ) {
            wp_set_object_terms( $event_id, array( (int) $c['organizer'] ), 'uc_organizer' );
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

        /*
         * A SENT FILE IS A WORKING COPY, NOT THE FEATURED IMAGE. It is recorded
         * so the pending row can show what arrived, and set_post_thumbnail() is
         * deliberately not called on it: the published picture is chosen at
         * approval from the calendar folder, so nothing points at the
         * submissions folder permanently and it can be emptied. A picture
         * PICKED from the calendar folder above is a different thing and does
         * become the thumbnail, because it is already an approved image.
         */
        if ( $upload_id ) {
            update_post_meta( $event_id, SFAF_Submit::META_IMAGE, (int) $upload_id );
        }

        /*
         * THE TEAM GOES ON THROUGH THE ONE WRITER (3.66.0).
         *
         * SFAF_Teams::set_access_for_event() is what caladmin calls, so this is
         * the same path rather than a second one: it drops ids that name no
         * team, refuses duplicates, stops at MAX_PER_EVENT and deletes the meta
         * when nothing is left. Writing _uc_event_teams here directly would be
         * a second definition of what a valid access list is, free to drift
         * from the one the portal enforces.
         *
         * IT IS WRITTEN ON A PENDING ROW, WHICH IS THE POINT. Access resolves
         * at read time, so the named team can open the request in caladmin
         * before anybody approves it, which is the whole reason the requester
         * was asked.
         */
        if ( ! empty( $c['teams'] ) ) {
            SFAF_Teams::set_access_for_event( $event_id, $c['teams'] );
        }

        update_post_meta( $event_id, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_STAFF );
        update_post_meta( $event_id, self::META_NAME, $c['name'] );
        update_post_meta( $event_id, self::META_EMAIL, $email );
        update_post_meta( $event_id, self::META_AT, current_time( 'mysql' ) );
        /*
         * ON THE EVENT'S OWN FAQ META, which is the key the editor writes, so
         * these open for review as ordinary FAQs with no second path and no
         * marker saying where they came from. Same as the community form.
         */
        if ( ! empty( $c['faqs'] ) ) {
            update_post_meta( $event_id, sfaf_faq_meta_key(), $c['faqs'] );
        }

        update_post_meta( $event_id, self::META_REPEAT, self::repeat_phrase( $c ) );

        /*
         * THE PATTERN, CAPTURED AND NOT ARMED (3.72.0).
         *
         * ITS OWN KEYS, AND THE NAMES ARE THE GUARANTEE. Writing
         * SFAF_Recurrence::PATTERN_META here would make an unreviewed request
         * indistinguishable from a manager's own schedule, and every screen and
         * job that asks "does this event repeat" would start answering yes
         * about a row nobody has approved. These four keys are read by exactly
         * one thing, the schedule screen's prefill at approval, and by nothing
         * that generates.
         *
         * WRITTEN ONLY WHEN THERE IS SOMETHING TO WRITE, so a one-off request
         * carries no recurrence meta at all rather than a row of empties. An
         * empty pattern with dates in the list is a real answer, though: it is
         * Custom, which is a schedule made of named dates and no cadence.
         */
        if ( '' !== $c['pattern'] ) {
            update_post_meta( $event_id, self::META_PATTERN, $c['pattern'] );
            if ( '' !== $c['repeat_until'] ) {
                update_post_meta( $event_id, self::META_PATTERN_UNTIL, $c['repeat_until'] );
            }
            if ( $c['pattern_limit'] > 0 ) {
                update_post_meta( $event_id, self::META_PATTERN_LIMIT, (int) $c['pattern_limit'] );
            }
        }
        if ( ! empty( $c['pattern_dates'] ) ) {
            update_post_meta( $event_id, self::META_PATTERN_DATES, array_values( (array) $c['pattern_dates'] ) );
        }

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
        /*
         * THE PATTERN'S OWN WORDS, THROUGH THE ENGINE'S OWN LABELLER (3.72.0).
         *
         * This used to read a five-option select, and the sentence it produced
         * was the best that control could do: "Every week, with no end date
         * given", which does not say which day. The form asks the real question
         * now, so pattern_label() can say "every Wednesday" or "the first Monday
         * of the month", and the two places this phrase is printed, the email to
         * the approver and the pending panel, get an answer somebody can act on.
         *
         * ONE LABELLER, NOT A SECOND SENTENCE BUILT HERE. pattern_label() is
         * what the schedule screen prints, so the request and the schedule
         * describe the same pattern in the same words.
         *
         * THE LEGACY SELECT STILL ANSWERS where it is all there is: a form held
         * open in a browser across this update posts `repeat` and no pattern,
         * and its five phrases are still the honest description of what that
         * form was able to say.
         */
        $pattern = isset( $c['pattern'] ) ? (string) $c['pattern'] : '';
        $dates   = isset( $c['pattern_dates'] ) ? (array) $c['pattern_dates'] : array();

        if ( '' !== $pattern ) {
            $phrase = SFAF_Recurrence::pattern_label( $pattern, isset( $c['date'] ) ? (string) $c['date'] : '' );
            if ( '' === $phrase ) {
                $phrase = 'Repeats';
            }
            if ( ! empty( $c['repeat_until'] ) ) {
                $phrase .= ', until ' . sfaf_ap_date( $c['repeat_until'], 'short_year' );
            } elseif ( ! empty( $c['pattern_limit'] ) ) {
                $phrase .= ', ' . ( (int) $c['pattern_limit'] + 1 ) . ' dates in all';
            } else {
                $phrase .= ', with no end date given';
            }
            if ( ! empty( $dates ) ) {
                $phrase .= ', plus ' . count( $dates ) . ' ' . _n( 'date named', 'dates named', count( $dates ) );
            }
            return $phrase;
        }

        if ( ! empty( $dates ) ) {
            return count( $dates ) . ' ' . _n( 'chosen date', 'chosen dates', count( $dates ) );
        }

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
        /*
         * THE AUDIENCE IS A SETTING NOW (3.72.0), resolved by
         * SFAF_Submissions::alert_recipients() so this form and the community
         * form cannot disagree about who is told. It still falls back to
         * everybody with Admin, which is what this method used to ask for
         * directly. See that method for why the fallback goes that way.
         */
        $to = SFAF_Submissions::alert_recipients();
        if ( empty( $to ) ) {
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
            /* The queue first, for the reason in SFAF_Submit: the review
             * screen is where the decision is taken, and an editor link
             * skips it. */
            . SFAF_Email::button_row( array( SFAF_Email::button( $queue, 'Open the pending queue' ) ) )
            . SFAF_Email::link_para( $edit, 'Or go straight to this request' );

        $text = $c['name'] . " has asked for an event to be added.\n\n"
            . $c['title'] . "\n" . sfaf_ap_date( $c['date'], 'full' ) . "\n"
            . sfaf_ap_time_range( $c['start'], $c['end'] ) . "\n\n"
            . "Requested by: " . $c['name'] . ' (' . $email . ")\n\n"
            . "The queue: " . $queue . "\nThis one: " . $edit;

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
        foreach ( $to as $address ) {
            SFAF_Email::send( $address, $subject, $shell, $text, $email );
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
            'RSVP'     => $c['rsvp'] ? ( $c['capacity'] > 0 ? 'Yes, ' . $c['capacity'] . ' places' : 'Yes, no limit on places' ) : 'No',
        );

        $html = SFAF_Email::heading( 'Thanks, that is with the team' )
            . SFAF_Email::para( 'Here is what you sent. The MarCom team will look at it and put it on the calendar, or email you if we have questions.' )
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

    /**
     * The document, the honeypot and the rate limiter now live in
     * SFAF_Submissions, because the community form needs every one of them
     * and a second copy is a second thing to change. These stay as thin
     * delegations rather than being deleted at every call site, so this file
     * still reads as one form from top to bottom.
     *
     * @param string $title
     * @param array  $args 'editor'.
     */
    private static function page_open( $title, $args = array() ) {
        SFAF_Submissions::page_open( $title, array_merge( array( 'body_class' => 'uc-request-page' ), $args ) );
    }

    /** @param array $args 'editor'. */
    private static function page_close( $args = array() ) {
        SFAF_Submissions::page_close( $args );
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
            <div class="uc-form-actions uc-form-actions-primary">
                <a class="uc-btn uc-btn-primary" href="<?php echo esc_url( self::start_url() ); ?>">Ask again</a>
            </div>
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
            <div class="uc-form-actions uc-form-actions-primary">
                <a class="uc-btn uc-btn-primary" href="<?php echo esc_url( self::start_url() ); ?>">Get a new link</a>
            </div>
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

    /** A field no person sees. See SFAF_Submissions::honeypot(). */
    private static function honeypot() {
        SFAF_Submissions::honeypot();
    }

    /**
     * The form itself.
     *
     * @param string $token
     * @param string $email
     * @param array  $c      Values to put back, after a rejected submission.
     * @param array  $errors field => message.
     */
    private static function render_form( $token, $email, $c, $errors, $remembered = false ) {
        $v = function ( $key, $fallback = '' ) use ( $c ) {
            return isset( $c[ $key ] ) ? $c[ $key ] : $fallback;
        };
        $err = function ( $key ) use ( $errors ) {
            return isset( $errors[ $key ] ) ? $errors[ $key ] : '';
        };

        /*
         * ENQUEUED BEFORE THE PAGE OPENS. This builds its own document, so
         * page_open() prints whatever has been enqueued BY THE TIME IT RUNS.
         * Asking after it is asking too late, and the editor silently stays a
         * plain textarea. caladmin documents the same trap.
         */
        SFAF_Rich_Text::enqueue();

        self::page_open( 'Request an event', array( 'editor' => true ) );
        ?>
        <div class="uc-request-card">
            <h1>Request an event</h1>
            <?php
            /*
             * WHOSE REQUEST THIS IS ABOUT TO BE, ON THE FORM.
             *
             * It always said the address. What it did not have was a way out,
             * and a remembered address on a shared machine is exactly the case
             * where somebody needs one: without it, a colleague's name goes on
             * a request nobody notices was theirs.
             */
            ?>
            <p class="uc-hint uc-request-who-line">
                Asking as <strong><?php echo esc_html( $email ); ?></strong>.
                <a href="<?php echo esc_url( add_query_arg( 'uc_switch', '1', self::start_url() ) ); ?>">Not you? Use a different address</a>
            </p>
            <?php if ( $remembered ) : ?>
                <p class="uc-hint">
                    This browser will remember you for <?php echo (int) self::COOKIE_DAYS; ?> days, so you can come
                    straight back to this form.
                </p>
            <?php endif; ?>

            <?php if ( '' !== $err( 'form' ) ) : ?>
                <p class="uc-field-error" role="alert"><?php echo esc_html( $err( 'form' ) ); ?></p>
            <?php elseif ( ! empty( $errors ) ) : ?>
                <p class="uc-field-error" role="alert">Some of this needs another look. The fields are marked below.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( self::form_url( $token ) ); ?>" class="uc-form" enctype="multipart/form-data">
                <input type="hidden" name="uc_request_action" value="submit_request" />
                <input type="hidden" name="uc_token" value="<?php echo esc_attr( $token ); ?>" />
                <?php self::honeypot(); ?>

                <?php
                /*
                 * THE SERIES IS THE FIRST QUESTION (3.68.0).
                 *
                 * It was the fifth, under About the event, after the
                 * description and the categories. Choosing one is what gives
                 * the event its picture, so a requester met the picture
                 * chooser before they had been asked the thing that answers
                 * it. New Event asks first for the same reason.
                 *
                 * ITS OWN SECTION, NOT A FIELD AT THE TOP OF ANOTHER ONE.
                 * "About the event" is the event's own facts; which series it
                 * joins is a fact about the calendar, and the two read as one
                 * question if they share a heading.
                 */
                $all_series = SFAF_Series::all();
                ?>

                <?php
                /*
                 * WHO IS PUTTING IT ON (3.76.0), AND IT GOES FIRST.
                 *
                 * THE FORM HAD NO ORGANIZER FIELD AT ALL, and nothing anywhere
                 * recorded that as a decision: it was simply never added, so
                 * every staff request arrived with no organizer and whoever
                 * approved it had to know or ask. The requester is the one
                 * person who certainly knows.
                 *
                 * FIRST, ABOVE THE SERIES, because organizer then series then
                 * picture is the order these three depend on each other: the
                 * series decides the default picture, and the organizer is the
                 * one of the three that depends on nothing.
                 *
                 * A CLOSED LIST AND AN ESCAPE THAT IS NOT A TEXT BOX. "Not
                 * sure" is a real answer from somebody booking a room for a
                 * colleague, and it posts nothing rather than a name that would
                 * have to be matched against the taxonomy by hand. Creating an
                 * organizer is a caladmin decision and stays one: see
                 * "Create-from-the-editor is a field, never a button".
                 */
                $all_orgs = SFAF_Organizers::all();
                ?>
                <?php if ( ! empty( $all_orgs ) ) : ?>
                    <fieldset class="uc-form-section-group">
                        <legend class="uc-field-group-title">Organizer</legend>
                        <label class="uc-field">
                            <span class="uc-field-label">Who is putting this on?</span>
                            <select name="organizer">
                                <option value="0">Not sure</option>
                                <?php foreach ( $all_orgs as $o ) : ?>
                                    <option value="<?php echo (int) $o->term_id; ?>"
                                        <?php selected( (int) $v( 'organizer' ), (int) $o->term_id ); ?>>
                                        <?php echo esc_html( $o->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="uc-hint">Shown on the event page as who is running it. Leave it at Not sure and somebody here will set it.</span>
                        </label>
                    </fieldset>
                <?php endif; ?>

                <?php if ( ! empty( $all_series ) ) : ?>
                    <fieldset class="uc-form-section-group">
                        <legend class="uc-field-group-title">Series</legend>
                        <label class="uc-field">
                            <span class="uc-field-label">Part of a series?</span>
                            <select name="series" data-uc-request-series>
                                <option value="0" data-uc-series-thumb="">Not part of one</option>
                                <?php foreach ( $all_series as $s ) : ?>
                                    <?php
                                    /* The series picture, for the image control below. It is
                                     * shown there and never written into a field: an event in
                                     * a series with no picture of its own already falls back
                                     * to this one at display time, so copying it would only
                                     * make a value that can go stale. See create_event(). */
                                    $s_thumb = SFAF_Series::image_url( (int) $s->term_id, 'medium' );
                                    ?>
                                    <option value="<?php echo (int) $s->term_id; ?>"
                                            data-uc-series-thumb="<?php echo esc_url( $s_thumb ); ?>"
                                            <?php selected( (int) $v( 'series' ), (int) $s->term_id ); ?>>
                                        <?php echo esc_html( $s->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="uc-hint">Choosing one uses that series' photo unless you pick a picture below. It fills in nothing else.</span>
                        </label>
                    </fieldset>
                <?php endif; ?>

                <?php
                /*
                 * THE PICTURE SITS DIRECTLY UNDER THE SERIES (3.76.0).
                 *
                 * It was six sections further down, after Location, and the
                 * series is the thing that decides its default: the "nothing
                 * chosen" row fills in with the series photo the moment the
                 * select changes, and a change nobody witnesses is a change
                 * that has to be explained in a hint instead of seen.
                 *
                 * Organizer, then series, then picture, which is the order
                 * these three actually depend on each other.
                 */
                self::render_image_choice( (int) $v( 'image' ), $err( 'uc_image' ), (int) $v( 'series' ) );
                ?>

                <fieldset class="uc-form-section-group">
                <legend class="uc-field-group-title">About the event</legend>

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

                <div class="uc-field">
                    <span class="uc-field-label">Description</span>
                    <?php
                    SFAF_Rich_Text::render(
                        'uc-request-description',
                        'description',
                        (string) $v( 'description' ),
                        array( 'rows' => 6 )
                    );
                    ?>
                    <span class="uc-hint">What it is, who it is for, and what somebody should expect.</span>
                    <?php self::field_error( $err( 'description' ) ); ?>
                </div>

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

                </fieldset>

                <fieldset class="uc-form-section-group">
                <legend class="uc-field-group-title">When</legend>

                <label class="uc-field">
                    <span class="uc-field-label">Date</span>
                    <input type="date" name="date" required value="<?php echo esc_attr( $v( 'date' ) ); ?>" />
                    <?php self::field_error( $err( 'date' ) ); ?>
                </label>

                <div class="uc-field-row">
                    <label class="uc-field">
                        <span class="uc-field-label">Start</span>
                        <input type="time" name="start_time" required value="<?php echo esc_attr( $v( 'start' ) ); ?>"<?php echo sfaf_time_step_attr( $v( 'start' ) ); ?> />
                        <?php self::field_error( $err( 'start_time' ) ); ?>
                    </label>
                    <label class="uc-field">
                        <span class="uc-field-label">End</span>
                        <input type="time" name="end_time" required value="<?php echo esc_attr( $v( 'end' ) ); ?>"<?php echo sfaf_time_step_attr( $v( 'end' ) ); ?> />
                        <?php self::field_error( $err( 'end_time' ) ); ?>
                    </label>
                </div>

                <?php
                /*
                 * THE SAME REPEAT CONTROL CALADMIN HAS (3.72.0).
                 *
                 * WHAT WAS HERE. A five-option select ("Every week", "Every two
                 * weeks", "Every month", "Something else, say so in the notes")
                 * and a bare "until when" date. That pair cannot say "every
                 * Wednesday": "Every week" names no day, so an end date sat
                 * under a frequency that had not said what it was repeating
                 * ON, and the approver had to read the notes to find out. It
                 * could not say "Tuesdays and Thursdays" at all, and "the first
                 * Monday of the month" fell into "Something else".
                 *
                 * SFAF_Recurrence::render_control() is the control caladmin's
                 * New Event uses, rendered from one place so the two forms
                 * cannot differ in what somebody is allowed to say.
                 *
                 * THE PATTERN IS CAPTURED, NOT ARMED, AND THAT IS THE WHOLE OF
                 * WHY THIS IS SAFE. Nothing on this page generates anything.
                 * The pattern is stored on the pending request under its own
                 * keys, never under SFAF_Recurrence::PATTERN_META, and
                 * generation is a creation-time action taken by an approver on
                 * the schedule screen with the summary in front of them. An
                 * unapproved request must never put fifty-two posts one press
                 * away, which is what half-filling the real key would do.
                 *
                 * IT NEEDS THE START DATE, which is the field above this one,
                 * because every pattern is anchored to it. portal.js reads the
                 * date input and keeps the control's summary in step; the
                 * server anchors on whatever arrives with the POST.
                 */
                SFAF_Recurrence::render_control( 0, (string) $v( 'date' ) );
                ?>

                </fieldset>

                <fieldset class="uc-form-section-group">
                <legend class="uc-field-group-title">Location</legend>

                <?php $venues = SFAF_Venues::all(); ?>
                <label class="uc-field">
                    <span class="uc-field-label">Location</span>
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
                </fieldset>

                <fieldset class="uc-form-section-group">
                <legend class="uc-field-group-title">RSVP</legend>

                <label class="uc-check">
                    <input type="checkbox" name="rsvp" value="1" <?php checked( (bool) $v( 'rsvp', false ) ); ?> />
                    People need to RSVP
                </label>
                <label class="uc-field">
                    <span class="uc-field-label">How many places, if there is a limit</span>
                    <input type="number" name="capacity" min="0" max="100000" value="<?php echo esc_attr( $v( 'capacity' ) ? $v( 'capacity' ) : '' ); ?>" />
                    <?php self::field_error( $err( 'capacity' ) ); ?>
                </label>
                </fieldset>

                <?php
                /*
                 * FAQs: A SET, QUESTIONS OF THEIR OWN, OR BOTH.
                 *
                 * THE PICKER IS OFFERED HERE AND NOT ON THE COMMUNITY FORM, and
                 * that is a privacy decision rather than a simplicity one. This
                 * form is behind an emailed token to an sfaf.org address, so
                 * whoever is reading it already works here and the set names
                 * tell them nothing they do not know. See the note where the
                 * community form builds its own FAQ section.
                 *
                 * THE SET IS COPIED, NOT LINKED. faqs_for() does the combining,
                 * and SFAF_FAQ_Sets explains at the top of that file why a copy
                 * is the right answer. Nothing here records which set was used.
                 *
                 * THE SAME REPEATER AND THE SAME EDITOR THE COMMUNITY FORM HAS,
                 * through SFAF_Rich_Text::deferred() and the one shared
                 * toolbar. A second toolbar would be a second answer to what
                 * somebody may type.
                 */
                $faq_sets = SFAF_FAQ_Sets::all();
                $faq_rows = (array) $v( 'faq_own', array() );
                if ( empty( $faq_rows ) ) {
                    $faq_rows = array( array( 'question' => '', 'answer' => '' ) );
                }
                ?>
                <fieldset class="uc-form-section-group">
                    <legend class="uc-field-group-title">Questions people often ask</legend>
                    <p class="uc-hint">Parking, what to bring, whether to book. Leave it empty if there is nothing.</p>

                    <?php if ( ! empty( $faq_sets ) ) : ?>
                        <label class="uc-field uc-faq-set-pick">
                            <span class="uc-field-label">Use a saved set</span>
                            <select name="faq_set">
                                <option value="">None</option>
                                <?php foreach ( $faq_sets as $set ) : ?>
                                    <option value="<?php echo esc_attr( $set['id'] ); ?>"
                                        <?php selected( (string) $v( 'faq_set' ), (string) $set['id'] ); ?>>
                                        <?php echo esc_html( $set['name'] ); ?>
                                        (<?php echo (int) count( $set['rows'] ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="uc-hint">Its questions are copied in, ahead of any you add below. Editing the set later does not change this event.</span>
                        </label>

                        <?php
                        /*
                         * WHAT IS ACTUALLY IN THE SET (3.76.0).
                         *
                         * The select named a set and a count and showed nothing
                         * else, so a requester picked blind and had to remember
                         * what "Clinic basics (4)" contains. caladmin has never
                         * had that problem: applying a set there drops the
                         * questions into editable rows where they can be read.
                         *
                         * EVERY SET IS RENDERED AND THE SCRIPT HIDES THE REST,
                         * which is the progressive-enhancement rule this form
                         * already follows everywhere: start from visible and
                         * hide, never start hidden and show. With no script a
                         * requester sees all of them under a heading naming
                         * each, which is longer and complete; with script they
                         * see the one they chose.
                         *
                         * READ-ONLY, AND NOT A SECOND SET OF FIELDS. The rows
                         * are copied server-side by faqs_for() at validate
                         * time, exactly as before. Making them editable here
                         * would be a second place the set's text can be
                         * changed, and it would post a copy of the set that
                         * could disagree with the set.
                         */
                        ?>
                        <div class="uc-faq-set-peek" data-uc-faq-set-lists>
                            <?php foreach ( $faq_sets as $set ) : ?>
                                <?php if ( empty( $set['rows'] ) ) { continue; } ?>
                                <div class="uc-faq-set-list" data-uc-faq-set-list="<?php echo esc_attr( $set['id'] ); ?>">
                                    <p class="uc-field-label">In &ldquo;<?php echo esc_html( $set['name'] ); ?>&rdquo;</p>
                                    <ul>
                                        <?php foreach ( $set['rows'] as $row ) : ?>
                                            <li><?php echo esc_html( isset( $row['question'] ) ? $row['question'] : '' ); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="uc-repeater" data-repeater>
                        <div class="uc-repeater-rows">
                            <?php foreach ( $faq_rows as $i => $row ) : ?>
                                <?php sfaf_faq_row( array(
                                    'index'     => (int) $i,
                                    'question'  => isset( $row['question'] ) ? $row['question'] : '',
                                    'answer'    => isset( $row['answer'] ) ? $row['answer'] : '',
                                    'maxlength' => 300,
                                ) ); ?>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="uc-btn uc-btn-sm uc-repeater-add">+ Add a question</button>
                        <template class="uc-repeater-tpl">
                            <?php sfaf_faq_row( array( 'maxlength' => 300 ) ); ?>
                        </template>
                    </div>
                </fieldset>

                <?php self::render_team_choice( (array) $v( 'teams', array() ), $err( 'request_teams' ) ); ?>

                <fieldset class="uc-form-section-group">
                    <legend class="uc-field-group-title">Anything else we should know</legend>
                    <label class="uc-field">
                        <span class="uc-visually-hidden">Anything else we should know</span>
                        <textarea name="notes" rows="3" maxlength="2000"><?php echo esc_textarea( $v( 'notes' ) ); ?></textarea>
                    </label>
                </fieldset>

                <div class="uc-form-actions uc-form-actions-primary">
                    <p class="uc-form-actions-note">This goes to the MarCom team. You will get a copy of it by email.</p>
                    <button type="submit" class="uc-btn uc-btn-primary">Send the request</button>
                </div>
            </form>
        </div>
        <?php
        self::page_close( array( 'editor' => true ) );
    }

    private static function field_error( $message ) {
        if ( '' === $message ) {
            return;
        }
        echo '<span class="uc-field-error">' . esc_html( $message ) . '</span>';
    }

    /**
     * Which team should be able to work on this event.
     *
     * WHY A TEAM AND NOT A PERSON. Membership resolves at read time, so adding
     * somebody to a team hands them every event that team owns and removing
     * them takes it back on the same read. A typed name or address is a string
     * that is correct on the day it is typed and nobody's job afterwards. The
     * caladmin editor has offered exactly this since teams existed; the staff
     * form has not, so a requester could say what the event is and not who
     * should be able to touch it.
     *
     * NAMES ONLY, NEVER MEMBERSHIP. The requester knows the team's name, which
     * is the whole of what they are being asked. Who is on it is caladmin's
     * business and appears nowhere here.
     *
     * WHAT MAKES SHOWING THE LIST SAFE, AND IT IS WORTH BEING EXPLICIT. This
     * page is unauthenticated and reached by a link, and links get forwarded.
     * The gate is that the form is only ever rendered after a token sent to a
     * verified sfaf.org mailbox has resolved, which by the time this method
     * runs has already happened. So this costs nothing extra: anybody who can
     * see this list has already proved an sfaf.org address. Mark has confirmed
     * team names may be shown once that is true.
     *
     * CHECKBOXES, SO IT WORKS WITH NO SCRIPT. Nothing here is built by
     * JavaScript and nothing here is inert without it. The cap is enforced by
     * validate() rather than by disabling boxes in the browser, because a
     * browser-side cap is not a cap on a page a form can be posted to.
     *
     * @param string[] $chosen
     * @param string   $error
     */
    private static function render_team_choice( $chosen, $error = '' ) {
        $teams = SFAF_Teams::all();
        if ( empty( $teams ) ) {
            // No teams, no question. A fieldset saying there is nothing to
            // choose is a thing to read and not a thing to answer.
            return;
        }

        $chosen = array_map( 'strval', (array) $chosen );
        $cap    = SFAF_Teams::MAX_PER_EVENT;
        ?>
        <?php // NO .uc-field ON A SECTION. That class is (0,2,1) inside
              // .uc-request-card and strips the border and padding this one is
              // drawn with. See the note beside that rule in portal.css. ?>
        <fieldset class="uc-form-section-group uc-request-teams">
            <legend class="uc-field-group-title">Who should be able to edit it</legend>
            <p class="uc-hint">
                Choose up to <?php echo (int) $cap; ?>. Anyone on a team you choose can open this event in the
                calendar tool. Leave it empty and only the MarCom team can.
            </p>
            <?php self::field_error( $error ); ?>
            <div class="uc-picker-options">
                <?php foreach ( $teams as $team ) : ?>
                    <label class="uc-check uc-picker-option">
                        <input type="checkbox" name="request_teams[]"
                               value="<?php echo esc_attr( $team['id'] ); ?>"
                               <?php checked( in_array( (string) $team['id'], $chosen, true ) ); ?> />
                        <span><?php echo esc_html( $team['name'] ); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Pick a picture, from the calendar folder only.
     *
     * NOT wp.media, AND IT CANNOT BE. The media frame needs a logged-in user
     * with upload_files, so on a page with no account it would not open at
     * all. This is the same RULE as the event editor's picker, in the only
     * shape that works here: the folder, rendered as radio buttons, chosen
     * server-side from the same SFAF_Media_Folder query. The folder is not
     * widened for this form and never has been.
     *
     * THE CHOICE IS NOT AN UPLOAD, and the two are separate on purpose. An
     * upload endpoint reachable with no account is the highest-risk thing this
     * form could carry, so what SFAF_Uploads takes below is a WORKING COPY that
     * lands outside the calendar folder and never becomes the event's picture
     * on its own. Only an id already in the calendar folder can be chosen here,
     * and validate() checks that again rather than trusting this list.
     *
     * @param int    $chosen
     * @param string $upload_error
     */
    private static function render_image_choice( $chosen, $upload_error = '', $series = 0 ) {
        $any = SFAF_Media_Folder::has_any();
        ?>
        <?php // NO .uc-field ON A SECTION, for the reason given on the team
              // section above. ?>
        <fieldset class="uc-form-section-group uc-request-images">
            <legend class="uc-field-group-title">Event Image</legend>
            <?php if ( ! $any ) : ?>
                <p class="uc-hint">
                    There are no calendar pictures to choose from yet. Ask Roxane Chicoine for an image for
                    your event, or send this without a picture and say so in the notes.
                </p>
            <?php else : ?>
                <?php SFAF_Media::picker( array(
                    'name'   => 'image_id',
                    'chosen' => (int) $chosen,
                    'series' => (int) $series,
                ) ); ?>
                <p class="uc-hint">
                    Nothing here suitable? Ask <strong>Roxane Chicoine</strong> for an image for your event.
                </p>
            <?php endif; ?>

            <?php
            /*
             * OR SEND ONE. The list above is the approved folder, and picking
             * from it gives the event a finished picture straight away. This is
             * the other case: somebody has a photo of their own. It is a
             * WORKING COPY rather than the published image, which is why it
             * lands in a different folder and does not become the thumbnail.
             * See SFAF_Uploads.
             */
            ?>
            <div class="uc-request-upload">
                <p class="uc-hint">Or send your own, and somebody will size it for the calendar.</p>
                <?php SFAF_Submissions::image_field( $upload_error ); ?>
            </div>
        </fieldset>
        <?php
    }
}
