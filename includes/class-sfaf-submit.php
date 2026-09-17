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
    /* WHAT WAS WRONG WITH THE PICTURE, WHEN IT WAS TAKEN ANYWAY (3.93.0).
     * A picture under SFAF_Uploads::MIN_WIDTH used to be refused, which
     * refused the whole submission with it. It is accepted now and the
     * sentence travels with it to the pending row, where somebody who can
     * see the picture decides. Written only when there is something to say,
     * so its absence means the picture was fine. */
    const META_IMAGE_NOTE = '_uc_submitted_image_note';
    const META_SERIES  = '_uc_submitted_series';

    /**
     * UP TO TWO MORE PICTURES, AND THEY ARE NOT CANDIDATES FOR ANYTHING.
     *
     * The featured picture answers "what should this event look like". These
     * answer "here are some other photographs of it", and the difference is
     * that nothing chooses between them: no picker lists them, no approval
     * copies one anywhere, and nothing reads them looking for a thumbnail. The
     * only way one is ever used is somebody downloading it, sizing it, and
     * putting it into a description through Insert image.
     *
     * STORED AS AN ARRAY OF ATTACHMENT IDS, with the warnings in a second array
     * in the same order. Two keys rather than one array of pairs, because the
     * ids are what every reader wants and a reader that has to unpack a shape
     * to get them is a reader that will one day unpack it wrong.
     *
     * THEY LIVE IN THE SUBMISSIONS FOLDER like the featured one, so the whole
     * folder can still be emptied at any time and a row whose files have gone
     * simply draws fewer thumbnails.
     */
    const META_IMAGE_EXTRA      = '_uc_submitted_image_extra';
    const META_IMAGE_EXTRA_NOTE = '_uc_submitted_image_extra_note';

    /** How many extras a form offers. Two, and the forms render this many. */
    const MAX_EXTRA = 2;

    /**
     * The file input names for the extra pictures.
     *
     * TWO SEPARATE SINGLE-FILE INPUTS, NOT ONE MULTIPLE. SFAF_Uploads::inspect()
     * reads $_FILES[ $field ] as one file, and a `multiple` input gives it
     * arrays in every slot. Two inputs mean the whole upload path, every one of
     * its thirteen checks, runs exactly as it does for the featured picture
     * with nothing changed and nothing duplicated.
     *
     * @return string[]
     */
    public static function extra_fields() {
        $out = array();
        for ( $i = 1; $i <= self::MAX_EXTRA; $i++ ) {
            $out[] = 'uc_image_extra_' . $i;
        }
        return $out;
    }

    /**
     * Put the extra pictures through the same upload code the featured one uses.
     *
     * THE SAME LIMITER, DELIBERATELY. Three files is three uploads, and the
     * rate limit counts uploads. Somebody attaching three pictures uses three
     * of their allowance, which is the honest accounting; sharing one slot
     * across a submission would let a form be the unit and a form can carry
     * three files.
     *
     * A REFUSED EXTRA DOES NOT REFUSE THE SUBMISSION. The error is returned
     * against its own field so the form can say which picture it was.
     *
     * @param callable|null $limiter
     * @return array{ids:int[],warnings:string[],errors:array<string,string>}
     */
    public static function store_extras( $limiter = null ) {
        $ids      = array();
        $warnings = array();
        $errors   = array();

        foreach ( self::extra_fields() as $field ) {
            $up = SFAF_Uploads::store( $field, $limiter );
            if ( '' !== $up['error'] ) {
                $errors[ $field ] = $up['error'];
                continue;
            }
            if ( ! $up['id'] ) {
                continue;   // Nothing was attached to this input.
            }
            $ids[]      = (int) $up['id'];
            $warnings[] = (string) $up['warning'];
        }

        return array( 'ids' => $ids, 'warnings' => $warnings, 'errors' => $errors );
    }

    /**
     * The extra pictures on an event, as attachment ids.
     *
     * @param int $event_id
     * @return int[]
     */
    public static function extras( $event_id ) {
        $raw = get_post_meta( (int) $event_id, self::META_IMAGE_EXTRA, true );
        if ( ! is_array( $raw ) ) {
            return array();
        }
        return array_values( array_filter( array_map( 'intval', $raw ) ) );
    }

    /**
     * What was wrong with each extra picture, in the same order as extras().
     *
     * @param int $event_id
     * @return string[]
     */
    public static function extra_notes( $event_id ) {
        $raw = get_post_meta( (int) $event_id, self::META_IMAGE_EXTRA_NOTE, true );
        return is_array( $raw ) ? array_values( array_map( 'strval', $raw ) ) : array();
    }

    /**
     * Store what store_extras() produced, and say nothing when there is nothing.
     *
     * @param int      $event_id
     * @param int[]    $ids
     * @param string[] $warnings
     */
    public static function save_extras( $event_id, $ids, $warnings ) {
        $event_id = (int) $event_id;
        if ( ! $event_id || empty( $ids ) ) {
            return;
        }
        update_post_meta( $event_id, self::META_IMAGE_EXTRA, array_values( array_map( 'intval', $ids ) ) );
        // Only when one of them has something to say, so an absent key means
        // every extra was fine.
        if ( array_filter( $warnings ) ) {
            update_post_meta( $event_id, self::META_IMAGE_EXTRA_NOTE, array_values( $warnings ) );
        }
    }

    /**
     * Everybody the submitter asked to have told about registrations.
     *
     * THE FIRST OF THESE IS THE SUBMITTER, AND IT IS NOT STORED TWICE. The
     * submitter's own address stays where every reader already looks for it,
     * SFAF_Request::META_EMAIL, so `kind()`, `submitter()`, the confirmation
     * and the pending queue are untouched by this existing at all. This key
     * holds the WHOLE list, first entry included, and exists only when the
     * submitter named more than themselves.
     *
     * WHAT IT IS NOT. It is not a second notification list and it is not an
     * ownership record. It is what one form answered, read once, at approval,
     * by SFAF_Submissions::notify_addresses(). Nothing on the event side reads
     * it, and nothing about editing an event afterwards goes anywhere near it:
     * the list a manager maintains is the event's own, in
     * SFAF_Reminders::NOTIFY_EMAILS_META.
     */
    const META_NOTIFY_EMAILS = '_uc_submitted_notify_emails';

    /**
     * How many addresses the About you field takes.
     *
     * ONE NUMBER, READ BY THE CONTROL, THE VALIDATOR AND THE READER, so the
     * button can only disappear at the count the server would refuse past.
     */
    const MAX_EMAILS = 5;

    /* Fields the event page shows, which the staff form does not collect. */
    const META_COST    = '_uc_cost';
    const META_AGE     = '_uc_age_restriction';
    const META_CONTACT = '_uc_public_contact';
    const META_RSVP_URL = '_uc_rsvp_url';

    /* Where a submitted address is kept, part by part, for promotion later. */
    const META_STREET = '_uc_submitted_street';
    const META_CITY   = '_uc_submitted_city';
    const META_STATE  = '_uc_submitted_state';
    const META_ZIP    = '_uc_submitted_zip';

    /* The event's own contact, which IS public. Three fields, not one box. */
    const META_CONTACT_NAME  = '_uc_contact_name';
    const META_CONTACT_EMAIL = '_uc_contact_email';
    const META_CONTACT_PHONE = '_uc_contact_phone';

    const META_VENUE_URL = '_uc_venue_website';

    /**
     * What it costs to come, as a closed list plus an escape.
     *
     * A CHOICE, NOT A BOX (3.47.0). An open field produced "Free", "free" and
     * "No charge" for one thing, which is three things to a reader skimming a
     * calendar and three things to anybody filtering later. The list matches
     * the Google Form this replaces, so nobody has to learn a new answer.
     *
     * THERE IS NO "NOT SAYING" ANY MORE (3.48.0). It read as an option and was
     * really a way to answer without answering, and an event either costs
     * something or it does not. Saying FREE explicitly is worth having, because
     * it is the question people ask; leaving cost blank was not an answer to it.
     * So the field is required, and its three entries are three real answers.
     *
     * 'other' IS A REAL STORED VALUE and not a marker for "empty": it means the
     * submitter had something to say that the list does not cover, and what
     * they said is in the companion field, which is REQUIRED when it is chosen.
     * Other with an empty box is no answer wearing the shape of one.
     *
     * ONE LIST, READ BY THE CONTROL AND BY THE VALIDATOR, so a value the form
     * can offer is exactly a value the validator will keep.
     *
     * @return array<string,string>
     */
    public static function cost_options() {
        return array(
            'free'     => 'Free',
            'donation' => 'Donation',
            'other'    => 'Something else',
        );
    }

    /**
     * Who may come, as a closed list plus an escape.
     *
     * @return array<string,string>
     */
    public static function age_options() {
        return array(
            ''      => 'Not saying',
            'all'   => 'All ages',
            '18'    => '18+',
            '21'    => '21+',
            'other' => 'Something else',
        );
    }

    /**
     * The words a chosen key turns into on the event page.
     *
     * SEPARATE FROM THE LABELS ABOVE, because a control says "Not saying" and a
     * listing says nothing at all. One method decides the public wording, so a
     * card, an email and the event page cannot phrase the same choice three
     * ways.
     *
     * @param string $key
     * @param string $other What they typed when they picked 'other'.
     * @param string $which 'cost' or 'age'.
     * @return string '' when there is nothing to show.
     */
    public static function choice_phrase( $key, $other, $which ) {
        $key = (string) $key;
        if ( '' === $key ) {
            return '';
        }
        if ( 'other' === $key ) {
            return trim( (string) $other );
        }
        $words = ( 'cost' === $which )
            ? array( 'free' => 'Free', 'donation' => 'Donation' )
            : array( 'all' => 'All ages', '18' => '18+', '21' => '21+' );
        return isset( $words[ $key ] ) ? $words[ $key ] : '';
    }

    /**
     * A series name as its owners write it.
     *
     * "Cycle To Zero" IS NOT A NAME ANYBODY CHOSE (3.47.0). It is what title
     * case does to "Cycle to Zero", and the campaign's own site and style guide
     * both keep the lower-case "to". WordPress does not title-case a term name,
     * so whatever is stored is what shows; this fixes the ONE class of mistake
     * that gets typed by hand, which is capitalising the small words.
     *
     * THE STORED NAME IS NOT REWRITTEN. Only what is printed. A term name is
     * somebody's data and a display helper has no business editing it, and the
     * moment this guessed wrong on a real name there would be no way to
     * override it.
     *
     * ONLY BETWEEN OTHER WORDS. "To Zero and Beyond" keeps its leading To,
     * because a small word that starts a name is capitalised in every style
     * guide, and the last word is left alone for the same reason.
     *
     * @param WP_Term|string $series
     * @return string
     */
    public static function series_name( $series ) {
        $name = is_object( $series ) ? (string) $series->name : (string) $series;
        $name = trim( $name );
        if ( '' === $name ) {
            return '';
        }

        $small = array( 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in',
            'nor', 'of', 'on', 'or', 'the', 'to', 'up', 'via', 'with' );

        $words = preg_split( '/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $words ) ) {
            return $name;
        }

        /* Which entries are words rather than the whitespace between them. */
        $positions = array();
        foreach ( $words as $i => $w ) {
            if ( '' !== trim( $w ) ) { $positions[] = $i; }
        }
        $last = count( $positions ) - 1;

        foreach ( $positions as $n => $i ) {
            if ( 0 === $n || $n === $last ) {
                continue;
            }
            $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $words[ $i ], 'UTF-8' ) : strtolower( $words[ $i ] );
            /* Only a plainly capitalised small word: ALL CAPS is somebody being
             * deliberate and is left exactly as it was. */
            if ( in_array( $lower, $small, true ) && $words[ $i ] !== $lower && $words[ $i ] !== strtoupper( $words[ $i ] ) ) {
                $words[ $i ] = $lower;
            }
        }

        return implode( '', $words );
    }

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

        /*
         * UP TO MAX_EMAILS ADDRESSES, AND THE FIRST ONE IS THE SUBMITTER.
         *
         * The field posts as an array, so a browser with no script sends one
         * entry and a browser that added rows sends several. Anything past the
         * cap is dropped rather than refused: the control does not offer a
         * sixth box, so a sixth value is a hand-built post body and nothing
         * that arrives one is worth explaining to.
         *
         * AN ADDRESS THAT IS NOT ONE IS AN ERROR RATHER THAN A SILENT DROP.
         * Dropping it would promote the next address to first, which is the
         * one that gets the confirmation and is recorded as who submitted
         * this, so a typo would quietly change whose submission it is.
         */
        $clean['submitter_emails'] = array();
        $bad_email = false;
        $posted_emails = isset( $post['submitter_email'] ) ? wp_unslash( $post['submitter_email'] ) : '';
        foreach ( (array) $posted_emails as $raw_email ) {
            if ( ! is_scalar( $raw_email ) ) {
                continue;
            }
            $one = strtolower( trim( sanitize_text_field( (string) $raw_email ) ) );
            if ( '' === $one ) {
                continue;
            }
            if ( ! is_email( $one ) ) {
                $bad_email = true;
                continue;
            }
            if ( ! in_array( $one, $clean['submitter_emails'], true ) ) {
                $clean['submitter_emails'][] = $one;
            }
            if ( count( $clean['submitter_emails'] ) >= self::MAX_EMAILS ) {
                break;
            }
        }

        $clean['submitter_email'] = isset( $clean['submitter_emails'][0] ) ? $clean['submitter_emails'][0] : '';
        if ( '' === $clean['submitter_email'] ) {
            $errors['submitter_email'] = 'Give an email address we can reach you at.';
        } elseif ( $bad_email ) {
            $errors['submitter_email'] = 'One of those is not an email address. Check them and send again.';
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

        /* ---- Where. A venue we already know, or an address in parts. ----
         *
         * FREE TEXT WAS A LINE SOMEBODY HAD TO PARSE. Venues have carried
         * street, city, state and zip separately since 3.13.0, so one box meant
         * either retyping it at approval or a map that will not resolve.
         *
         * PICKING A VENUE DOES NOT CREATE ONE, AND NEITHER DOES TYPING. The
         * list offers what exists; the parts are stored as the submitter's
         * answer and nothing more. Promoting an address to a real venue is a
         * decision taken at approval, on the screen built for it, once somebody
         * knows it will be used again.
         */
        $clean['venue'] = 0;
        if ( ! empty( $post['venue'] ) ) {
            $id = (int) $post['venue'];
            if ( $id && SFAF_Venues::exists( $id ) ) {
                $clean['venue'] = $id;
            }
        }

        /* THE PLACE NAME, WHICH THIS FORM HAD NOWHERE TO PUT (3.93.0). Text on
         * the event, never a venue: see sfaf_event_location_name(). */
        $clean['venue_name'] = $line( 'venue_name', 120 );
        $clean['street'] = $line( 'street', 200 );
        $clean['city']   = $line( 'city', 100 );
        $clean['state']  = $line( 'state', 40 );
        $clean['zip']    = $line( 'zip', 20 );
        $clean['venue_url'] = SFAF_Submissions::url( isset( $post['venue_url'] ) ? wp_unslash( $post['venue_url'] ) : '' );
        if ( '' === $clean['venue_url'] && ! empty( $post['venue_url'] ) ) {
            $errors['venue_url'] = 'That does not look like a web address. It needs to start with http:// or https://.';
        }

        /* The one line the calendar and the map actually read, composed from
         * whichever half was answered. Kept as _uc_location so every existing
         * reader works with no second code path. */
        $clean['location'] = $clean['venue']
            ? SFAF_Venues::display( $clean['venue'] )
            : trim( implode( ', ', array_filter( array(
                $clean['street'],
                $clean['city'],
                trim( $clean['state'] . ' ' . $clean['zip'] ),
            ) ) ) );

        if ( ! $clean['venue'] && '' === $clean['street'] ) {
            $errors['street'] = 'Say where it happens, so people can get there. Pick a venue above, or give the street address.';
        }

        /* ---- Optional detail, as choices rather than open boxes. ---- */
        /*
         * COST IS REQUIRED, AND SO IS THE BOX BEHIND "Something else".
         *
         * Two refusals rather than one, because they are two different mistakes
         * and a single message would have to describe both. Anything that is
         * not one of the three offered keys is treated as no answer, which is
         * also what a forged value gets.
         */
        $cost_key        = isset( $post['cost'] ) ? sanitize_key( wp_unslash( $post['cost'] ) ) : '';
        $clean['cost']   = array_key_exists( $cost_key, self::cost_options() ) ? $cost_key : '';
        $clean['cost_other'] = ( 'other' === $clean['cost'] ) ? $line( 'cost_other', 120 ) : '';
        if ( '' === $clean['cost'] ) {
            $errors['cost'] = 'Say what it costs to come. Choose Free if there is no charge.';
        } elseif ( 'other' === $clean['cost'] && '' === $clean['cost_other'] ) {
            $errors['cost_other'] = 'Say what it costs, or choose Free or Donation above.';
        }

        $age_key       = isset( $post['age_restriction'] ) ? sanitize_key( wp_unslash( $post['age_restriction'] ) ) : '';
        $clean['age']  = array_key_exists( $age_key, self::age_options() ) ? $age_key : '';
        $clean['age_other'] = ( 'other' === $clean['age'] ) ? $line( 'age_other', 120 ) : '';
        if ( 'other' === $clean['age'] && '' === $clean['age_other'] ) {
            $errors['age_other'] = 'Say who can come, or choose one of the options above.';
        }

        $clean['rsvp_url'] = SFAF_Submissions::url( isset( $post['rsvp_url'] ) ? wp_unslash( $post['rsvp_url'] ) : '' );
        if ( '' === $clean['rsvp_url'] && ! empty( $post['rsvp_url'] ) ) {
            $errors['rsvp_url'] = 'That does not look like a web address. It needs to start with http:// or https://.';
        }

        /* ---- The EVENT's contact. Shown publicly. Three fields, not one box.
         *
         * THERE ARE TWO CONTACT IDEAS ON THIS FORM AND THEY MUST NOT MERGE.
         * The submitter's own name and address, above, are internal and exist
         * so somebody can reach them about an unclear submission. THIS one goes
         * on the public listing, phone included, and is often a different
         * person entirely. They are stored under different keys, labelled
         * differently on the form, and only this one is readable from a
         * template.
         *
         * A NAME, AND AT LEAST ONE WAY TO REACH THEM. A name with neither is a
         * line on a listing that helps nobody, and requiring both would refuse
         * an organizer who only wants to give a phone number.
         */
        /*
         * "USE MY DETAILS" IS ANSWERED HERE AND NOT IN THE BROWSER (3.74.0).
         *
         * The tick hides three fields, and a hidden field posts nothing, so the
         * copy has to happen on this side or a ticked form would arrive with no
         * contact name and be refused for it. Doing it here also means the
         * answer is the same with no script at all, where the three fields are
         * visible and the tick is inert: whatever is in them is overwritten by
         * the submitter's own details, which is what the tick says.
         *
         * IT COPIES ONE WAY AND ONLY ONE WAY. The submitter's name and email
         * become the public contact. Nothing ever writes a public contact back
         * onto the submitter, because those two do different jobs: one is who
         * we reply to and who gets the registrations, the other is what prints
         * on the event page.
         *
         * NO PHONE COMES WITH IT. The form never asked the submitter for one,
         * and inventing an empty value would be the only way to pretend it did.
         */
        $clean['contact_same'] = ! empty( $post['contact_same'] );

        if ( $clean['contact_same'] ) {
            $clean['contact_name']  = $clean['submitter_name'];
            $clean['contact_email'] = $clean['submitter_email'];
            $clean['contact_phone'] = '';

            /*
             * AND THE ERRORS ARE NOT DOUBLED. The two boxes are one box now,
             * so an empty name is already reported against the field somebody
             * can see, up under "About you". Reporting it twice would mark a
             * field that is not on the screen.
             */
        } else {
            $clean['contact_name'] = $line( 'contact_name', 120 );
            if ( '' === $clean['contact_name'] ) {
                $errors['contact_name'] = 'Give the name people should ask for.';
            }

            $c_email = isset( $post['contact_email'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $post['contact_email'] ) ) ) ) : '';
            $clean['contact_email'] = ( '' !== $c_email && is_email( $c_email ) ) ? $c_email : '';
            if ( '' === $clean['contact_email'] && '' !== $c_email ) {
                $errors['contact_email'] = 'That does not look like an email address.';
            }

            $clean['contact_phone'] = $line( 'contact_phone', 40 );

            if ( '' === $clean['contact_email'] && '' === $clean['contact_phone'] && ! isset( $errors['contact_email'] ) ) {
                $errors['contact_email'] = 'Give an email address or a phone number, so people can ask about the event.';
            }
        }

        /*
         * HOW MANY PLACES. THE SAME RULE THE EVENT EDITOR USES, and it is worth
         * naming: BLANK MEANS UNLIMITED, and 0 also means unlimited, so neither
         * is an error. A capacity is a number of places, and refusing 0 would
         * make somebody guess which of blank and 0 the software wanted.
         *
         * IT DOES NOT SWITCH REGISTRATION ON. Whether this calendar takes the
         * registrations, or the submitter's own link does, is a decision taken
         * at approval by somebody who can see both answers. Storing the number
         * records what was asked for without deciding that.
         */
        /* ---- The picture chosen from the calendar folder (3.74.0). ----
         *
         * The same three checks the staff form makes, from the same method,
         * because this is a form a stranger fills in and the id in it decides
         * what gets attached to a public page. An upload is a different field
         * and a different folder; see create_event(). */
        $clean['image'] = SFAF_Submissions::clean_image_choice( $post );

        $clean['capacity'] = 0;
        if ( isset( $post['capacity'] ) && '' !== trim( (string) $post['capacity'] ) ) {
            $cap = (int) $post['capacity'];
            if ( $cap < 0 || $cap > 100000 ) {
                $errors['capacity'] = 'Give a number of places between 0 and 100000, or leave it blank for no limit.';
            } else {
                $clean['capacity'] = $cap;
            }
        }

        $clean['faqs'] = self::clean_faqs( isset( $post['faq'] ) ? $post['faq'] : array() );

        $clean['notes'] = SFAF_Submissions::line(
            isset( $post['notes'] ) ? wp_unslash( $post['notes'] ) : '',
            2000
        );

        return array( 'clean' => $clean, 'errors' => $errors );
    }

    /**
     * Question and answer pairs from the form, reduced to what may be stored.
     *
     * NOT SFAF_FAQ_Sets::clean_rows(), AND THAT IS THE WHOLE POINT. That method
     * sanitises an answer with SFAF_Rich_Text::sanitize(), which is
     * wp_kses_post(): the right rule for somebody with an account, and far too
     * wide for a form anybody on the internet can post to. These answers go
     * through SFAF_Submissions::prose(), the same narrow list the description
     * uses, so a submitted FAQ cannot carry an image, a style or a class.
     *
     * EMPTY ROWS ARE DROPPED SILENTLY. The repeater starts with one row and
     * most submitters will send it untouched; refusing that, or storing a pair
     * of empty strings, would both be wrong. A row counts when it has EITHER a
     * question or an answer, because a half-filled row is something somebody
     * meant and losing it quietly would be worse than showing Mark a gap.
     *
     * CAPPED AT THE SAME NUMBER AS EVERY OTHER FAQ LIST, read from that class
     * rather than typed again here.
     *
     * @param mixed $rows
     * @return array<int,array{question:string,answer:string}>
     */
    public static function clean_faqs( $rows ) {
        if ( ! is_array( $rows ) ) {
            return array();
        }
        $out = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $q = SFAF_Submissions::line( isset( $row['question'] ) ? wp_unslash( $row['question'] ) : '', 300 );
            $a = SFAF_Submissions::prose( isset( $row['answer'] ) ? wp_unslash( $row['answer'] ) : '' );
            $a = SFAF_Request::cap( $a, 4000 );
            /* to_plain(), not wp_strip_all_tags(): the latter joins the text
             * either side of a block tag with nothing between, so "<p>a</p>
             * <p>b</p>" becomes "ab". Only emptiness is being decided here, but
             * the rule is the rule everywhere prose is flattened, and a reader
             * that is wrong in one place gets copied to a place where it
             * matters. See SFAF_Rich_Text::to_plain(). */
            if ( '' === $q && '' === trim( SFAF_Rich_Text::to_plain( $a ) ) ) {
                continue;
            }
            $out[] = array( 'question' => $q, 'answer' => $a );
            if ( count( $out ) >= SFAF_FAQ_Sets::MAX_ROWS ) {
                break;
            }
        }
        return $out;
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
         * organizers would actually notice. Both are counters that expire.
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

        // The extras, through the same upload code and the same limiter.
        $extra = self::store_extras( function () {
            return SFAF_Submissions::allow( 'upload_ip', SFAF_Submissions::client(), 10, HOUR_IN_SECONDS );
        } );
        if ( ! empty( $extra['errors'] ) ) {
            $checked['errors'] = array_merge( $checked['errors'], $extra['errors'] );
        }

        if ( ! empty( $checked['errors'] ) ) {
            /*
             * A REJECTED SUBMISSION THAT UPLOADED A FILE DOES NOT KEEP IT. The
             * form cannot put a file back into a file input, so the visitor has
             * to choose it again anyway, and keeping the first one would leave
             * an orphan on disk for every failed attempt.
             *
             * THE EXTRAS GO THE SAME WAY, for the same reason and with the same
             * force: every one of them is a file this submission uploaded and
             * no submission exists to own them.
             */
            if ( $upload['id'] ) {
                wp_delete_attachment( $upload['id'], true );
            }
            foreach ( $extra['ids'] as $extra_id ) {
                wp_delete_attachment( $extra_id, true );
            }
            self::render_form( $series, $checked['clean'], $checked['errors'] );
            return;
        }

        $event_id = self::create_event( $checked['clean'], $series, (int) $upload['id'] );
        self::save_extras( $event_id, $extra['ids'], $extra['warnings'] );
        /* THE WARNING IS KEPT, NOT SHOWN TO THE SUBMITTER. They have already
         * sent it and cannot act on it from the thank-you page; the person
         * who can act on it is the approver, looking at the picture. */
        if ( $event_id && '' !== (string) $upload['warning'] ) {
            update_post_meta( $event_id, self::META_IMAGE_NOTE, (string) $upload['warning'] );
        }
        if ( ! $event_id ) {
            if ( $upload['id'] ) {
                wp_delete_attachment( $upload['id'], true );
            }
            foreach ( $extra['ids'] as $extra_id ) {
                wp_delete_attachment( $extra_id, true );
            }
            self::render_form( $series, $checked['clean'], array(
                'form' => 'Something went wrong saving that. Try once more, and if it happens again let the organizers know.',
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
        /*
         * ASKED FIRST, BEFORE ANYTHING IS INSERTED OR JOINED.
         *
         * SFAF_Series::organizers_for() reads the series' most recent event and
         * counts pending ones, so the answer changes the moment this submission
         * joins the series below. Asked here it describes the series as it was,
         * which is what "inherit from the series" means. See the write further
         * down for what this cost: every community submission since 3.76.0
         * arrived with no organizer because it was its own source.
         */
        $series_orgs = SFAF_Series::organizers_for( (int) $series->term_id );

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

        /*
         * A CHOSEN VENUE IS A REAL VENUE. A typed address is not, and does not
         * become one: the parts are kept as the submitter's answer so somebody
         * can promote them at approval if this turns out to be a place that is
         * used repeatedly. Nothing here creates a term.
         */
        if ( $c['venue'] ) {
            SFAF_Venues::set_for_event( $event_id, (int) $c['venue'] );
        }

        SFAF_Series::set_for_event( $event_id, (int) $series->term_id );
        update_post_meta( $event_id, self::META_SERIES, (int) $series->term_id );

        /* Optional, and written only when there is something to write, so a
         * blank one is absent rather than an empty string the template would
         * then have to test for a second way. The two choices are stored as the
         * WORDS they mean rather than as their keys: a key is a form's private
         * business, and everything that reads these is showing them to
         * somebody. See choice_phrase(). */
        foreach ( array(
            self::META_COST          => self::choice_phrase( $c['cost'], $c['cost_other'], 'cost' ),
            self::META_AGE           => self::choice_phrase( $c['age'], $c['age_other'], 'age' ),
            self::META_CONTACT_NAME  => $c['contact_name'],
            self::META_CONTACT_EMAIL => $c['contact_email'],
            self::META_CONTACT_PHONE => $c['contact_phone'],
            self::META_RSVP_URL      => $c['rsvp_url'],
            self::META_VENUE_URL     => $c['venue_url'],
            '_uc_location_name'      => $c['venue_name'],
            self::META_STREET        => $c['street'],
            self::META_CITY          => $c['city'],
            self::META_STATE         => $c['state'],
            self::META_ZIP           => $c['zip'],
        ) as $key => $value ) {
            if ( '' !== $value ) {
                update_post_meta( $event_id, $key, $value );
            }
        }

        if ( $c['capacity'] > 0 ) {
            update_post_meta( $event_id, '_uc_capacity', $c['capacity'] );
        }

        /*
         * THE FAQs GO ON THE EVENT, not into a set. A set is a reusable list
         * somebody curates; these are one event's own questions, which is what
         * the event's own FAQ meta is for, and it is the same key the editor
         * writes so they open for review as ordinary FAQs with no second path.
         */
        if ( ! empty( $c['faqs'] ) ) {
            update_post_meta( $event_id, sfaf_faq_meta_key(), $c['faqs'] );
        }

        /*
         * THE ORGANIZER COMES FROM THE SERIES, NOT FROM THE SUBMITTER (3.76.0).
         *
         * This form is only ever reached at a series' own address, so the
         * series is known before a field is filled in, and a stranger is in no
         * position to say which SFAF programme is putting an event on. Asking
         * would be asking somebody to guess at an internal list, and offering
         * that list is the disclosure this form already refuses to make about
         * FAQ sets.
         *
         * IT IS DERIVED AND NOT STORED, AND THAT IS WORTH KNOWING. A series
         * carries a description, an image and a default FAQ set; it does NOT
         * carry an organizer, because an organizer is a property of the EVENTS
         * in it. SFAF_Series::organizers_for() reads it off the most recent
         * event, which is the same rule the caladmin prefill card uses.
         *
         * SO IT CAN BE EMPTY AND THAT IS A REAL STATE, not a failure: a series
         * created for a campaign that has not run yet has no event to read
         * from. **Nothing is invented when it is empty.** The submission
         * arrives with no organizer, exactly as every submission did before
         * this existed, and whoever approves it sets one. A guessed organizer
         * on a public page is worse than none.
         */
        /*
         * ALL OF THEM, NOT THE FIRST. This took organizers_for()[0] and stopped,
         * on the reading that a series has one answer. A series does not: the
         * event it reads from can be co-hosted, and an inherited single name
         * made a co-hosted submission appear under one team's filter and not the
         * other's, which is the whole purpose of the filter.
         *
         * One call with the whole set, never one call per id. wp_set_object_terms()
         * REPLACES by default, so setting them in a loop would leave whichever
         * ran last and lose the rest. That is the 3.8.0 categories fault and the
         * 3.40.0 organizers fault, and it is why this is one write.
         */
        /*
         * THE SET WAS RESOLVED BEFORE THIS EVENT JOINED THE SERIES, and that is
         * the whole of the 3.85.0 fix rather than a detail of it.
         *
         * organizers_for() answers from the series' MOST RECENT EVENT, and
         * prefill_data() counts 'pending' among the statuses it looks at. This
         * event is pending, it has just been added to the series above, and a
         * submission is for an UPCOMING date, so by the time the question was
         * asked here the newest event in the series was this one. It read its
         * own empty organizer set and wrote nothing, every time, and the write
         * looked correct in isolation because the fault is the order.
         *
         * Resolved at the top of this method instead, before set_for_event()
         * puts it in the series, so the question is asked of the series as it
         * was. submissions-test.php asserts that ordering rather than the write,
         * because the write has been right since 3.76.0.
         */
        if ( ! empty( $series_orgs ) ) {
            wp_set_object_terms( $event_id, array_map( 'intval', $series_orgs ), 'uc_organizer' );
        }

        if ( $image_id ) {
            update_post_meta( $event_id, self::META_IMAGE, $image_id );
        }

        /*
         * A PICTURE CHOSEN FROM THE FOLDER IS THE EVENT'S PICTURE (3.74.0),
         * and an uploaded one still is not. The two are different in the one
         * way that matters: a folder image is already approved, already the
         * right shape and already on the public calendar, so it can be the
         * thumbnail the moment it is chosen. An upload is a working copy in
         * another folder that somebody has to look at first, which is why
         * "Use this image" on the pending row exists.
         *
         * validate() has already checked that this id is an attachment, is an
         * image, and is in the calendar folder. See clean_image_choice().
         */
        if ( ! empty( $c['image'] ) ) {
            set_post_thumbnail( $event_id, (int) $c['image'] );
        }

        update_post_meta( $event_id, SFAF_Submissions::META_KIND, SFAF_Submissions::KIND_COMMUNITY );
        update_post_meta( $event_id, SFAF_Request::META_NAME, $c['submitter_name'] );
        update_post_meta( $event_id, SFAF_Request::META_EMAIL, $c['submitter_email'] );
        /* Only when there is more than the submitter. One address is already
         * recorded above, and a second key repeating it would be a second
         * answer to who sent this. */
        $extra_emails = isset( $c['submitter_emails'] ) ? (array) $c['submitter_emails'] : array();
        if ( count( $extra_emails ) > 1 ) {
            update_post_meta( $event_id, self::META_NOTIFY_EMAILS, array_values( $extra_emails ) );
        }
        update_post_meta( $event_id, SFAF_Request::META_AT, current_time( 'mysql' ) );
        if ( '' !== $c['notes'] ) {
            update_post_meta( $event_id, SFAF_Request::META_NOTES, $c['notes'] );
        }

        return $event_id;
    }

    /**
     * The event's public contact, as one readable line.
     *
     * ONE PLACE, because it appears in two emails and on the event page,
     * and three formatters would phrase the same three fields three ways.
     * The phone is included deliberately: the form says it will be, and a
     * line that quietly dropped it would make that sentence untrue.
     *
     * @param array $c
     * @return string
     */
    public static function contact_line( $c ) {
        $parts = array_filter( array(
            isset( $c['contact_name'] ) ? $c['contact_name'] : '',
            isset( $c['contact_email'] ) ? $c['contact_email'] : '',
            isset( $c['contact_phone'] ) ? $c['contact_phone'] : '',
        ) );
        return implode( ', ', $parts );
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
        /* One resolver for both forms. See SFAF_Submissions::alert_recipients(). */
        $to = SFAF_Submissions::alert_recipients();
        if ( empty( $to ) ) {
            return;
        }

        $queue = SFAF_Portal::link( 'pending' );
        $edit  = SFAF_Portal::link( 'events/edit/' . $event_id );

        $rows = array(
            'Event'    => $c['title'],
            'Calendar' => self::series_name( $series ),
            'Date'     => sfaf_ap_date( $c['date'], 'full' ),
            'Time'     => sfaf_ap_time_range( $c['start'], $c['end'] ),
            'Where'    => $c['location'],
        );
        $cost_words = self::choice_phrase( $c['cost'], $c['cost_other'], 'cost' );
        $age_words   = self::choice_phrase( $c['age'], $c['age_other'], 'age' );
        if ( '' !== $cost_words ) {
            $rows['Cost'] = $cost_words;
        }
        if ( '' !== $age_words ) {
            $rows['Ages'] = $age_words;
        }
        $rows['Contact shown publicly'] = self::contact_line( $c );
        if ( '' !== $c['venue_url'] ) {
            $rows['Venue website'] = $c['venue_url'];
        }
        if ( '' !== $c['rsvp_url'] ) {
            $rows['Registration link'] = $c['rsvp_url'];
        }
        $rows['Submitted by'] = $c['submitter_name'] . ' (' . $c['submitter_email'] . ')';
        /* Named on the form as people who should get the registrations, which
         * is a thing the approver decides on rather than discovers. */
        $also = array_slice( isset( $c['submitter_emails'] ) ? (array) $c['submitter_emails'] : array(), 1 );
        if ( ! empty( $also ) ) {
            $rows['They also asked to tell'] = implode( ', ', $also );
        }

        $html = SFAF_Email::heading( 'Somebody submitted an event' )
            . SFAF_Email::para( 'A member of the public submitted an event to the ' . self::series_name( $series ) . ' calendar. It is in the pending queue and nobody can see it yet.' )
            . SFAF_Email::details( $rows )
            /*
             * THE QUEUE IS THE BUTTON, THE EVENT IS THE LINK (3.47.0).
             *
             * These were the other way round, and sending an approver
             * straight into an editor skips the screen the review
             * actually happens on: what else is waiting, what came from
             * where, and the reject action. The event is still one click
             * away for somebody who only wants to read this one.
             */
            . SFAF_Email::button_row( array( SFAF_Email::button( $queue, 'Open the pending queue' ) ) )
            . SFAF_Email::link_para( $edit, 'Or go straight to this submission' );

        $text = 'A member of the public submitted an event to the ' . self::series_name( $series ) . " calendar.\n\n"
            . $c['title'] . "\n" . sfaf_ap_date( $c['date'], 'full' ) . "\n"
            . sfaf_ap_time_range( $c['start'], $c['end'] ) . "\n"
            . $c['location'] . "\n\n"
            . 'Submitted by: ' . $c['submitter_name'] . ' (' . $c['submitter_email'] . ")\n\n"
            . 'The queue: ' . $queue . "\nThis one: " . $edit;

        $subject = 'Event submitted: ' . $c['title'];
        $shell   = SFAF_Email::shell( 'An event was submitted', $html );

        /*
         * ONE MESSAGE EACH, and the SUBMITTER IS THE REPLY-TO, so answering a
         * question about the submission goes to the person who sent it. That
         * address is internal to this message; it is never on the listing.
         */
        foreach ( $to as $address ) {
            SFAF_Email::send( $address, $subject, $shell, $text, $c['submitter_email'] );
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
            'Calendar' => self::series_name( $series ),
            'Date'     => sfaf_ap_date( $c['date'], 'full' ),
            'Time'     => sfaf_ap_time_range( $c['start'], $c['end'] ),
            'Where'    => $c['location'],
        );
        $cost_words = self::choice_phrase( $c['cost'], $c['cost_other'], 'cost' );
        $age_words   = self::choice_phrase( $c['age'], $c['age_other'], 'age' );
        if ( '' !== $cost_words ) {
            $rows['Cost'] = $cost_words;
        }
        if ( '' !== $age_words ) {
            $rows['Ages'] = $age_words;
        }
        $rows['Contact on the listing'] = self::contact_line( $c );
        if ( '' !== $c['rsvp_url'] ) {
            $rows['Registration link'] = $c['rsvp_url'];
        }

        $html = SFAF_Email::heading( 'Thanks, we have it' )
            . SFAF_Email::para( 'Here is what you sent to the ' . self::series_name( $series ) . ' calendar. Somebody reviews every submission before it goes on the calendar, so it will not appear straight away. If we have questions we will email you.' )
            . SFAF_Email::details( $rows )
            . SFAF_Email::rule()
            . SFAF_Email::small_para( 'Your name and email address are not shown on the calendar. You will not get another message about this automatically.' );

        $text = "Thanks, we have it.\n\n"
            . $c['title'] . "\n" . sfaf_ap_date( $c['date'], 'full' ) . "\n"
            . sfaf_ap_time_range( $c['start'], $c['end'] ) . "\n"
            . $c['location'] . "\n\n"
            . 'Somebody reviews every submission before it goes on the calendar. '
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
            <h1>Thanks, we have it</h1>
            <?php if ( '' !== $title ) : ?>
                <p><strong><?php echo esc_html( $title ); ?></strong> has gone to the <?php echo esc_html( self::series_name( $series ) ); ?> team, and we have emailed you a copy of what you sent.</p>
            <?php else : ?>
                <p>Your event has gone to the <?php echo esc_html( self::series_name( $series ) ); ?> team.</p>
            <?php endif; ?>
            <p class="uc-hint">
                Somebody reviews every submission before it goes on the calendar, so it will not appear straight away. If we have questions we will email you.
            </p>
            <div class="uc-form-actions uc-form-actions-primary">
                <a class="uc-btn uc-btn-primary" href="<?php echo esc_url( self::url( $series->slug ) ); ?>">Submit another event</a>
            </div>
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
        self::render_banner( SFAF_Series::image_url( (int) $series->term_id, 'large' ) );
    }

    /**
     * The banner itself, shared by both public forms.
     *
     * IT IS A LIVE PREVIEW OF THE EVENT, NOT DECORATION (3.80.0). It starts on
     * the series picture and follows the image picker, so what is across the
     * top is what the event will look like. That is the whole reason it is
     * worth having: a form that shows you what you are making reads as event
     * creation rather than as a questionnaire.
     *
     * NO PICTURE MEANS NO BANNER, AND NOT A PLACEHOLDER. A grey box saying
     * nothing is worse than the form simply starting at its heading, and a
     * series with no picture is a real state rather than a missing file.
     *
     * SO THE ELEMENT IS ABSENT, NOT EMPTY, when there is nothing to show on
     * arrival. portal.js creates it if a picture is chosen later; see
     * initFormBanner(). Rendering an empty one and revealing it would leave a
     * blank band on every form whose series has no picture and whose visitor
     * has no script.
     *
     * @param string $src
     */
    public static function render_banner( $src ) {
        $src = (string) $src;
        if ( '' === $src ) {
            return;
        }
        ?>
        <div class="uc-submit-banner" data-uc-form-banner>
            <?php
            /*
             * alt="", BECAUSE IT SAYS NOTHING A SCREEN READER NEEDS. The series
             * name is the heading directly under it and the picture carries no
             * information the form does not already state in words.
             */
            ?>
            <img src="<?php echo esc_url( $src ); ?>" alt="" data-uc-form-banner-img />
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
            'Submit an event: ' . self::series_name( $series ),
            array( 'body_class' => 'uc-request-page uc-submit-page', 'editor' => true )
        );
        ?>
        <div class="uc-request-card">
            <?php self::banner( $series ); ?>
            <h1>Submit an event to <?php echo esc_html( self::series_name( $series ) ); ?></h1>
            <p class="uc-hint">
                Tell us about your event. Somebody reviews every submission before it goes on the calendar.
                Your name and email are not shown on the listing.
            </p>

            <?php if ( '' !== $err( 'form' ) ) : ?>
                <p class="uc-field-error" role="alert"><?php echo esc_html( $err( 'form' ) ); ?></p>
            <?php elseif ( ! empty( $errors ) ) : ?>
                <p class="uc-field-error" role="alert">Some of this needs another look. The fields are marked below.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( self::url( $series->slug ) ); ?>" class="uc-form" enctype="multipart/form-data">
                <input type="hidden" name="uc_submit_action" value="submit_event" />
                <?php SFAF_Submissions::honeypot(); ?>

                <fieldset class="uc-form-section-group">
                    <legend class="uc-field-group-title">About you</legend>
                    <p class="uc-hint">Not shown on the calendar.</p>
                    <label class="uc-field">
                        <span class="uc-field-label">Your name</span>
                        <input type="text" name="submitter_name" required maxlength="120" value="<?php echo esc_attr( $v( 'submitter_name' ) ); ?>" />
                        <?php SFAF_Submissions::field_error( $err( 'submitter_name' ) ); ?>
                    </label>
                    <?php
                    /*
                     * UP TO FIVE ADDRESSES, THE FIRST OF WHICH IS THE
                     * SUBMITTER'S OWN.
                     *
                     * THE SAME REPEATER THE FAQ ROWS USE, so this is a control
                     * on a screen rather than a second control: `data-repeater`,
                     * a rows container, an add button and a template. What is
                     * new is `data-repeater-max`, which is read by
                     * initRepeaters() in portal.js and by nothing else.
                     *
                     * THE BUTTON GOES AT THE CAP RATHER THAN GREYING OUT. A
                     * disabled control is a thing to read and wonder about; a
                     * control that is not there is answered. It is not rendered
                     * at all when the form comes back already holding five.
                     *
                     * NO REMOVE CONTROL, AND NONE IS NEEDED. An empty box is
                     * dropped by validate(), so clearing one is how a row goes,
                     * and there is nothing to confirm and nothing to undo.
                     *
                     * WITH NO SCRIPT THIS IS ONE BOX AND STILL A COMPLETE
                     * FORM. The button does nothing without portal.js, the
                     * field posts as an array either way, and one address is
                     * what this asked for until this release.
                     */
                    $emails = array_values( array_filter( (array) $v( 'submitter_emails', array() ) ) );
                    if ( empty( $emails ) ) {
                        $emails = array( '' );
                    }
                    $emails = array_slice( $emails, 0, self::MAX_EMAILS );
                    ?>
                    <div class="uc-field">
                        <span class="uc-field-label">Your email, and anybody else who should get RSVPs</span>
                        <span class="uc-hint">The first one is yours. Your copy of this submission goes there.</span>
                        <div class="uc-repeater uc-email-repeat" data-repeater
                             data-repeater-max="<?php echo (int) self::MAX_EMAILS; ?>">
                            <div class="uc-repeater-rows">
                                <?php foreach ( $emails as $i => $one ) : ?>
                                    <label class="uc-repeater-row">
                                        <span class="uc-visually-hidden"><?php
                                            echo esc_html( 0 === (int) $i ? 'Your email' : 'Another email address' );
                                        ?></span>
                                        <input type="email" name="submitter_email[]" maxlength="200"
                                               <?php echo ( 0 === (int) $i ) ? 'required autocomplete="email"' : 'autocomplete="off"'; ?>
                                               value="<?php echo esc_attr( $one ); ?>" />
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if ( count( $emails ) < self::MAX_EMAILS ) : ?>
                                <button type="button" class="uc-btn uc-btn-sm uc-repeater-add">+ Add email</button>
                            <?php endif; ?>
                            <template class="uc-repeater-tpl">
                                <label class="uc-repeater-row">
                                    <span class="uc-visually-hidden">Another email address</span>
                                    <input type="email" name="submitter_email[]" maxlength="200" autocomplete="off" value="" />
                                </label>
                            </template>
                        </div>
                        <?php SFAF_Submissions::field_error( $err( 'submitter_email' ) ); ?>
                    </div>
                </fieldset>

                <fieldset class="uc-form-section-group">
                <legend class="uc-field-group-title">The event</legend>

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
                </fieldset>

                <fieldset class="uc-form-section-group">
                <legend class="uc-field-group-title">When</legend>

                <label class="uc-field">
                    <span class="uc-field-label">Date</span>
                    <input type="date" name="date" required value="<?php echo esc_attr( $v( 'date' ) ); ?>" />
                    <?php SFAF_Submissions::field_error( $err( 'date' ) ); ?>
                </label>

                <div class="uc-field-row">
                    <label class="uc-field">
                        <span class="uc-field-label">Start</span>
                        <input type="time" name="start_time" required value="<?php echo esc_attr( $v( 'start' ) ); ?>"<?php echo sfaf_time_step_attr( $v( 'start' ) ); ?> />
                        <?php SFAF_Submissions::field_error( $err( 'start_time' ) ); ?>
                    </label>
                    <label class="uc-field">
                        <span class="uc-field-label">End</span>
                        <input type="time" name="end_time" required value="<?php echo esc_attr( $v( 'end' ) ); ?>"<?php echo sfaf_time_step_attr( $v( 'end' ) ); ?> />
                        <?php SFAF_Submissions::field_error( $err( 'end_time' ) ); ?>
                    </label>
                </div>
                </fieldset>

                <fieldset class="uc-form-section-group">
                    <legend class="uc-field-group-title">Where it happens</legend>
                    <?php $venues = SFAF_Venues::all(); ?>
                    <?php if ( ! empty( $venues ) ) : ?>
                        <label class="uc-field">
                            <span class="uc-field-label">Venue</span>
                            <select name="venue" data-uc-reveal="uc-address" data-uc-reveal-when="0">
                                <option value="0">Enter location manually</option>
                                <?php foreach ( (array) $venues as $venue ) : ?>
                                    <option value="<?php echo (int) $venue->term_id; ?>" <?php selected( (int) $v( 'venue' ), (int) $venue->term_id ); ?>>
                                        <?php echo esc_html( $venue->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="uc-hint">Pick one of these, or enter the location manually and give the address below.</span>
                        </label>
                    <?php endif; ?>

                    <div class="uc-address-parts uc-reveal-target" id="uc-address">
                        <?php
                        /*
                         * THE PLACE NAME, ABOVE THE ADDRESS AND NOT REQUIRED.
                         * An event at a restaurant or a partner site could not
                         * say what the place was called, so the page read "470
                         * Castro St" and nothing else. The hint says what it
                         * will NOT do, which is the one thing somebody would
                         * otherwise assume: filling it in does not add the
                         * place to the venue list.
                         */
                        ?>
                        <label class="uc-field">
                            <span class="uc-field-label">Place name</span>
                            <input type="text" name="venue_name" maxlength="120" value="<?php echo esc_attr( $v( 'venue_name' ) ); ?>"
                                   placeholder="Strut" />
                            <span class="uc-hint">Shown above the address on the event page. Leave it blank if the address is the whole answer.</span>
                        </label>
                        <label class="uc-field">
                            <span class="uc-field-label">Street address</span>
                            <input type="text" name="street" maxlength="200" value="<?php echo esc_attr( $v( 'street' ) ); ?>"
                                   placeholder="470 Castro St" />
                            <?php SFAF_Submissions::field_error( $err( 'street' ) ); ?>
                        </label>
                        <div class="uc-field-row">
                            <label class="uc-field">
                                <span class="uc-field-label">City</span>
                                <input type="text" name="city" maxlength="100" value="<?php echo esc_attr( $v( 'city' ) ); ?>" placeholder="San Francisco" />
                            </label>
                            <label class="uc-field uc-field-narrow">
                                <span class="uc-field-label">State</span>
                                <input type="text" name="state" maxlength="40" value="<?php echo esc_attr( $v( 'state' ) ); ?>" placeholder="CA" />
                            </label>
                            <label class="uc-field uc-field-narrow">
                                <span class="uc-field-label">ZIP</span>
                                <input type="text" name="zip" maxlength="20" value="<?php echo esc_attr( $v( 'zip' ) ); ?>" placeholder="94114" />
                            </label>
                        </div>
                    </div>

                    <label class="uc-field">
                        <span class="uc-field-label">Venue website</span>
                        <input type="url" name="venue_url" maxlength="500" value="<?php echo esc_attr( $v( 'venue_url' ) ); ?>" placeholder="https://" />
                        <span class="uc-hint">Shown beside the address on the event page.</span>
                        <?php SFAF_Submissions::field_error( $err( 'venue_url' ) ); ?>
                    </label>
                </fieldset>

                <fieldset class="uc-form-section-group">
                <legend class="uc-field-group-title">Cost and who can come</legend>

                <div class="uc-field">
                    <span class="uc-field-label">Cost</span>
                    <?php
                    /*
                     * THE EMPTY OPTION IS A PROMPT, NOT AN ANSWER.
                     *
                     * A required <select> whose first entry is a real answer
                     * pre-selects that answer, so everybody who never touched
                     * the control would submit "Free", which is a claim nobody
                     * made. This entry is disabled, so it cannot be chosen back
                     * once somebody has moved off it, and it fails `required`,
                     * so the browser asks before the server has to.
                     */
                    ?>
                    <select name="cost" required data-uc-reveal="uc-cost-other">
                        <option value="" disabled <?php selected( '', (string) $v( 'cost' ) ); ?>>Choose one</option>
                        <?php foreach ( self::cost_options() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) $v( 'cost' ), (string) $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php SFAF_Submissions::field_error( $err( 'cost' ) ); ?>
                    <label class="uc-field uc-reveal-target" id="uc-cost-other">
                        <span class="uc-field-label">What it costs</span>
                        <input type="text" name="cost_other" maxlength="120" value="<?php echo esc_attr( $v( 'cost_other' ) ); ?>"
                               placeholder="$15 at the door" />
                        <?php SFAF_Submissions::field_error( $err( 'cost_other' ) ); ?>
                    </label>
                </div>

                <div class="uc-field">
                    <span class="uc-field-label">Age restriction</span>
                    <select name="age_restriction" data-uc-reveal="uc-age-other">
                        <?php foreach ( self::age_options() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) $v( 'age' ), (string) $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label class="uc-field uc-reveal-target" id="uc-age-other">
                        <span class="uc-field-label">Who can come</span>
                        <input type="text" name="age_other" maxlength="120" value="<?php echo esc_attr( $v( 'age_other' ) ); ?>"
                               placeholder="21+ after 9pm" />
                        <?php SFAF_Submissions::field_error( $err( 'age_other' ) ); ?>
                    </label>
                </div>
                </fieldset>

                <?php
                /*
                 * SIGNING UP, AS ONE SECTION (3.68.0). The capacity moved down
                 * one place to sit beside the link, which is the only field
                 * that changed position on this form. They are one question,
                 * "how do people get a place and how many are there", and the
                 * capacity was between Cost and Age because that is the order
                 * the fields were written in rather than a grouping anybody
                 * chose.
                 */
                ?>
                <fieldset class="uc-form-section-group">
                <legend class="uc-field-group-title">Signing up</legend>

                <label class="uc-field">
                    <span class="uc-field-label">Capacity</span>
                    <input type="number" name="capacity" min="0" max="100000" value="<?php echo esc_attr( $v( 'capacity' ) ? $v( 'capacity' ) : '' ); ?>" />
                    <span class="uc-hint">Leave it blank if there is no limit.</span>
                    <?php SFAF_Submissions::field_error( $err( 'capacity' ) ); ?>
                </label>

                <label class="uc-field">
                    <span class="uc-field-label">Registration link</span>
                    <input type="url" name="rsvp_url" maxlength="500" value="<?php echo esc_attr( $v( 'rsvp_url' ) ); ?>"
                           placeholder="https://" />
                    <span class="uc-hint">If people sign up somewhere else, put that address here.</span>
                    <?php SFAF_Submissions::field_error( $err( 'rsvp_url' ) ); ?>
                </label>
                </fieldset>

                <?php
                /*
                 * THE SECOND CONTACT, AND THE FORM SAYS SO IN AS MANY WORDS.
                 *
                 * "About you" at the top is internal. This is the event's own
                 * contact and goes on the public page, so the legend, the hint
                 * and the phone field all say that rather than leaving somebody
                 * to work it out from the field order.
                 */
                ?>
                <fieldset class="uc-form-section-group">
                    <legend class="uc-field-group-title">Contact for the event</legend>
                    <p class="uc-hint"><strong>This appears on the public listing.</strong></p>
                    <?php
                    /*
                     * ONE SET OF DETAILS FOR MOST PEOPLE (3.74.0).
                     *
                     * The form asked for a name and an email twice, thirty
                     * fields apart, and for most submitters the two answers are
                     * the same answer. This is the tick that says so.
                     *
                     * THE DIRECTION IS WHAT MAKES IT SAFE, AND IT ONLY RUNS ONE
                     * WAY. "About you" is collected first and is internal: it
                     * reaches the notification list and the alert, and appears
                     * on no public page. This copies it ONTO the public contact
                     * when somebody asks for that, and nothing ever copies the
                     * other way. So the default is off, the separate fields are
                     * visible, and nothing anybody typed becomes public because
                     * a control was left alone.
                     *
                     * IT REVEALS RATHER THAN HIDES, like every other reveal on
                     * this form: with no script the tick is inert and all three
                     * fields are on the page, which is the form as it was.
                     * validate() does the copying, so the answer is the same
                     * whether the browser ran anything or not.
                     */
                    ?>
                    <label class="uc-check">
                        <input type="checkbox" name="contact_same" value="1"
                               data-uc-reveal="uc-contact-own" data-uc-reveal-when="unchecked"
                               <?php checked( (bool) $v( 'contact_same' ) ); ?> />
                        Use my name and email as the contact on the event page
                    </label>
                    <div class="uc-reveal-target" id="uc-contact-own">
                        <p class="uc-hint">
                            Give the details people should use to ask about the event, which may not be yours.
                        </p>
                        <label class="uc-field">
                            <span class="uc-field-label">Name</span>
                            <input type="text" name="contact_name" required maxlength="120" value="<?php echo esc_attr( $v( 'contact_name' ) ); ?>" />
                            <?php SFAF_Submissions::field_error( $err( 'contact_name' ) ); ?>
                        </label>
                        <div class="uc-field-row">
                            <label class="uc-field">
                                <span class="uc-field-label">Email</span>
                                <input type="email" name="contact_email" maxlength="200" value="<?php echo esc_attr( $v( 'contact_email' ) ); ?>" />
                                <?php SFAF_Submissions::field_error( $err( 'contact_email' ) ); ?>
                            </label>
                            <label class="uc-field">
                                <span class="uc-field-label">Phone</span>
                                <input type="tel" name="contact_phone" maxlength="40" value="<?php echo esc_attr( $v( 'contact_phone' ) ); ?>" />
                            </label>
                        </div>
                        <span class="uc-hint">Give an email address, a phone number, or both. The phone number is shown too.</span>
                    </div>
                </fieldset>

                <fieldset class="uc-form-section-group uc-request-images">
                    <legend class="uc-field-group-title">Event Image</legend>
                    <?php
                    /*
                     * THE PICKER, ON THIS FORM AT LAST (3.74.0).
                     *
                     * WHAT WAS HERE. An upload and nothing else, so the only
                     * answer to "what picture should this event have" was a
                     * file from the submitter's own computer. Somebody
                     * submitting a Strut event had no way to use the Strut
                     * photograph that already exists and is already the right
                     * shape, and whoever approved it had to go and set one.
                     *
                     * FILTERED TO THIS EVENT'S SERIES, WHICH THE URL ALREADY
                     * NAMES. This form is only ever reached at a series' own
                     * address, so the series is known before a single field is
                     * filled in.
                     *
                     * IT HIDES RATHER THAN GROUPING, FROM 3.80.0. It used to
                     * lead with that programme's pictures and list everything
                     * else under them, which left the list as long as it was
                     * before. Only the chosen series' pictures are written out
                     * now, and a series with none gets a sentence saying so.
                     *
                     * SHOWING THE FOLDER TO A STRANGER IS NOT SHOWING THE
                     * SERIES LIST. The refusal that keeps FAQ sets off this
                     * form is about NAMES: a dropdown of set names is a
                     * directory of this calendar's programming handed to
                     * anybody who opens the form, and this calendar carries
                     * HIV, substance use and trans health programming. The
                     * pictures are different in kind. They are the images
                     * already published on the public calendar's own event
                     * pages, so nothing here is visible that a visitor to the
                     * calendar cannot already see, and the group heading names
                     * only the series whose link they were given.
                     */
                    SFAF_Media::picker( array(
                        'name'   => 'image_id',
                        'chosen' => (int) $v( 'image' ),
                        'series' => ( $series && ! is_wp_error( $series ) ) ? (int) $series->term_id : 0,
                        /*
                         * LOCKED, BECAUSE THE URL IS THE SERIES. There is no
                         * control on this page that can change it, so the
                         * server writes out that series' pictures and nothing
                         * else, and the filter cannot be wrong at any point
                         * during the visit or defeated by a script that did not
                         * load.
                         */
                        'series_locked' => true,
                    ) );
                    ?>
                    <div class="uc-request-upload">
                        <p class="uc-hint">Or send your own, and somebody will size it for the calendar.</p>
                        <?php SFAF_Submissions::image_field( $err( 'uc_image' ) ); ?>
                    </div>
                    <?php SFAF_Submissions::extra_images_field( $errors ); ?>
                </fieldset>

                <?php
                /*
                 * THE SAME REPEATER THE EVENT EDITOR HAS, and deliberately the
                 * same markup: `data-repeater`, a rows container, an add button
                 * and a template with __I__ in the name. initRepeaters() in
                 * portal.js already drives that shape, so this adds a control
                 * to a screen rather than a second control.
                 *
                 * ONE ROW TO START, EMPTY. A submitter with nothing to add
                 * leaves it and it is dropped on save; a submitter with one
                 * question does not have to find a button before they can type.
                 *
                 * NO FAQ SET PICKER HERE, AND NOT FOR SIMPLICITY. The staff
                 * request form offers one from 3.52.0 and this form
                 * deliberately does not.
                 *
                 * LISTING THE SETS WOULD TELL A STRANGER WHAT PROGRAMMES THIS
                 * CALENDAR RUNS. Set names are internal: they are written by
                 * managers, for managers, about recurring programming, and a
                 * dropdown of them is a directory of that programming handed to
                 * anybody who opens the form. This calendar carries HIV,
                 * substance use and trans health programming, so the names
                 * themselves are the disclosure.
                 *
                 * IT IS THE SAME REFUSAL THIS FORM ALREADY MAKES. An address
                 * naming an unknown series says the link is not right; it does
                 * not list the series that do exist, for exactly this reason.
                 * Adding a picker here would give away through one control what
                 * the other one is careful not to.
                 *
                 * AND A STRANGER IS NOT SUBMITTING INTO AN INTERNAL SERIES.
                 * They are submitting one event to one campaign whose link they
                 * were given. The sets are not theirs to reach.
                 *
                 * DO NOT ADD IT FOR CONSISTENCY WITH THE STAFF FORM. That form
                 * is behind an emailed token to an sfaf.org address, so whoever
                 * reads it already works here. The two forms differ because
                 * their readers do.
                 *
                 * THE SAME RICH TEXT CONTROL THE EDITOR USES, through
                 * SFAF_Rich_Text::deferred(), with the one shared toolbar:
                 * bold, italic, bullets, numbers, link and unlink.
                 *
                 * IT WAS A PLAIN TEXTAREA UNTIL 3.51.0, on the reasoning that a
                 * stranger answering "is there parking" wants no formatting.
                 * What that argument also said was that "what survives is the
                 * same narrow list either way", AND THAT WAS NOT TRUE OF FAQ
                 * ANSWERS. The two paths genuinely differ:
                 *
                 *   caladmin   SFAF_Rich_Text::sanitize(), which is
                 *              wp_kses_post(), the wide WordPress rule.
                 *   this form  SFAF_Submissions::prose(), the narrow anonymous
                 *              list. See clean_faqs() below.
                 *
                 * The sentence was true of the DESCRIPTION, which takes prose()
                 * on this form and on the staff one, and it was carried across
                 * to a field where it did not hold.
                 *
                 * NOTHING WAS LOOSENED TO DO THIS. prose() already permits
                 * exactly what this toolbar can produce, anchors included, so
                 * the control and the rule now agree instead of the control
                 * being narrower than the rule for no stated reason.
                 *
                 * IT IS STILL A REAL TEXTAREA WITH A REAL NAME. deferred()
                 * renders one and the browser upgrades it; if the editor's
                 * scripts never run, the row still holds its content, still
                 * posts and still saves.
                 */
                $faq_rows = (array) $v( 'faqs', array() );
                if ( empty( $faq_rows ) ) {
                    $faq_rows = array( array( 'question' => '', 'answer' => '' ) );
                }
                ?>
                <fieldset class="uc-form-section-group">
                    <legend class="uc-field-group-title">Questions people often ask</legend>
                    <p class="uc-hint">Parking, what to bring, whether to book. Leave it empty if there is nothing.</p>
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

                <fieldset class="uc-form-section-group">
                    <legend class="uc-field-group-title">Anything else we should know</legend>
                    <label class="uc-field">
                        <span class="uc-visually-hidden">Anything else we should know</span>
                        <textarea name="notes" rows="3" maxlength="2000"><?php echo esc_textarea( $v( 'notes' ) ); ?></textarea>
                        <span class="uc-hint">Not shown on the listing.</span>
                    </label>
                </fieldset>

                <?php SFAF_Turnstile::field(); ?>

                <div class="uc-form-actions uc-form-actions-primary">
                    <p class="uc-form-actions-note">Somebody reviews every submission before it goes on the calendar. You will get a copy by email.</p>
                    <button type="submit" class="uc-btn uc-btn-primary">Submit this event</button>
                </div>
            </form>
        </div>
        <?php
        SFAF_Submissions::page_close( array( 'editor' => true ) );
    }
}
