<?php
/**
 * The calendar folder: the only images the caladmin picker offers.
 *
 * WHY THE PICKER IS NARROWED AT ALL. Event photographs are cropped to 16:9 and
 * filled, so the wrong shape loses faces and the wrong weight makes a card slow.
 * The media library holds every image the whole site has ever used, at every
 * size anybody happened to upload. Asking somebody to pick the right one from
 * that, correctly, every time, is asking them to remember a rule. Offering only
 * pictures that are already right removes the rule.
 *
 * THE FILTER IS ON THE FILE PATH, NOT ON WP MEDIA FOLDER'S API, AND THAT IS THE
 * WHOLE DESIGN DECISION HERE.
 * ---------------------------------------------------------------------------
 * The folder is made with WP Media Folder, and files in it are on disk at
 * `wp-content/uploads/calendar/`, so WordPress's own `_wp_attached_file` meta
 * reads `calendar/latino.jpg`. That is core metadata, written by core, and
 * present whatever plugin put the file there.
 *
 * So this asks core, never the plugin. If WP Media Folder is deactivated
 * tomorrow, every file stays where it is, `_wp_attached_file` still starts with
 * `calendar/`, and this picker carries on working with nothing to change. The
 * folder stops being MANAGEABLE, because there is no longer a screen for
 * dragging things into it, but nothing here breaks and no event loses a
 * picture. Going through the plugin's own taxonomy would have tied the event
 * editor's picker to a third-party plugin staying installed, and that is a
 * dependency worth not having for a feature this small.
 *
 * It also means the rule is legible: an image is a calendar image if it is in
 * that directory. Somebody can verify that with an FTP client.
 *
 * WHAT IT IS NOT. It is not a permission and it is not validation. Anything
 * already chosen goes on rendering, and the URL field beside the picker still
 * takes any address at all. The point is to make the easy path the right one,
 * not to refuse the others. See SFAF_Portal::render_manager_control().
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Media_Folder {

    /**
     * The folder, relative to the uploads directory. No leading slash, because
     * that is the shape `_wp_attached_file` stores.
     */
    const FOLDER = 'calendar';

    /**
     * The request key the picker sets, on both the query and the upload.
     *
     * One key for both so there is one thing to search for. It rides the
     * media query as part of wp.media's `library` and the upload as a plupload
     * multipart param, and in both cases arrives in $_REQUEST.
     */
    const FLAG = 'uc_calendar_media';

    public static function register() {
        add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'restrict_query' ) );
        add_filter( 'upload_dir', array( __CLASS__, 'upload_to_folder' ) );
        add_filter( 'map_meta_cap', array( __CLASS__, 'gate_upload' ), 10, 3 );
        add_action( 'add_attachment', array( __CLASS__, 'tag_upload_with_series' ) );
        add_action( 'add_attachment', array( __CLASS__, 'file_into_library_folder' ) );
    }

    /**
     * Tag a picture uploaded from the event editor with that event's series.
     *
     * WHAT THIS FIXES, AND IT IS NOT WHERE THE FILE WENT (3.87.0). An image
     * uploaded while editing an event was reported as going nowhere. The file
     * was never lost: the folder flag rides the uploader's multipart params, so
     * `upload_dir` fired and it landed in `uploads/calendar/` correctly, and it
     * has always been on the Images screen.
     *
     * IT VANISHED FROM THE PICKER THAT UPLOADED IT. wp.media refreshes its
     * library the moment an upload finishes, using the frame's own arguments,
     * and for an event in a series those include `uc_series`. restrict_query()
     * then narrows on that term, and a picture uploaded two seconds ago carries
     * no term at all, so it cannot be returned. 3.74.0 and 3.80.0 were both
     * working exactly as designed; what was missing was that a picture uploaded
     * FOR an event is a picture in that event's series.
     *
     * TAGGING RATHER THAN WIDENING THE PICKER. Widening after an upload would
     * answer a different question from the one asked and would undo the hiding
     * 3.80.0 added on purpose. The series here is a fact rather than a guess:
     * somebody was editing an event in it when they pressed Upload. Tagging
     * makes the picture visible to the open picker, to the Images screen, and
     * to every other event in the same series.
     *
     * BOTH KEYS OR NOTHING. The series alone is not enough: an upload from
     * anywhere else in WordPress must not be filed into a calendar series
     * because a request key happened to be present. asked_for() gates it, which
     * is the same gate the folder routing uses.
     *
     * THROUGH SFAF_Media::add_tag(), which already refuses anything that is not
     * an attachment, is not in the folder, or names a term that does not exist.
     * A second implementation of that rule here is a second place for it to
     * drift.
     *
     * @param int $attachment_id
     */
    /**
     * Also file a caladmin upload into the media library's own Calendar folder.
     *
     * THE PROBLEM, AND BOTH HALVES OF IT ARE TRUE AT ONCE (3.90.0). Fourteen
     * pictures sit in `uploads/calendar/` and the media library's Calendar
     * folder shows five. The folder in that library belongs to WP Media Folder,
     * which files by TAXONOMY ASSIGNMENT rather than by location, and this
     * plugin has never written that assignment. So an upload made here lands in
     * the right directory and is invisible in the place Mark uses to find
     * things. Mark's requirement is one upload, one place.
     *
     * THE TAXONOMY IS DISCOVERED, NOT NAMED, AND THAT IS THE WHOLE DESIGN.
     * WP Media Folder is commercial, is not on wordpress.org, and is not on the
     * build machine, so its taxonomy name could not be verified from here.
     * Hardcoding a guess would either work silently or fail silently, and there
     * would be no way to tell which. Instead this asks WordPress what
     * taxonomies attachments actually carry and looks for a term that is
     * literally the calendar folder's name.
     *
     * WHY THAT CANNOT MISFILE ANYTHING. Three conditions, all required: the
     * taxonomy must be registered for `attachment`, it must not be one of ours,
     * and it must already contain a term whose slug or name matches the folder.
     * A taxonomy with no such term is skipped, so nothing is created and nothing
     * is invented. If WP Media Folder is absent, or its Calendar folder is
     * named something else, this does nothing at all and the upload behaves
     * exactly as it did.
     *
     * APPENDED, NEVER REPLACING. `wp_set_object_terms()` with $append true, so
     * a picture that is already filed somewhere else in that library keeps its
     * other folders. This adds a fact; it does not take one away.
     *
     * TURN IT OFF WITH ONE FILTER, and correct the name with another, so
     * neither needs a release:
     *
     *     add_filter( 'sfaf_library_folder_enabled', '__return_false' );
     *     add_filter( 'sfaf_library_folder_term', function () { return 'events'; } );
     *
     * @param int $attachment_id
     */
    public static function file_into_library_folder( $attachment_id ) {
        if ( ! self::asked_for() ) {
            return;
        }
        if ( ! apply_filters( 'sfaf_library_folder_enabled', true ) ) {
            return;
        }

        $wanted = (string) apply_filters( 'sfaf_library_folder_term', self::FOLDER );
        if ( '' === $wanted ) {
            return;
        }

        /* Ours are not media-library folders and must never be written here.
         * uc_series is the calendar's own tag and is set by its own rule. */
        $mine = array( 'uc_series', 'uc_event_category', 'uc_organizer', 'uc_venue' );

        foreach ( get_object_taxonomies( 'attachment', 'names' ) as $tax ) {
            if ( in_array( $tax, $mine, true ) ) {
                continue;
            }
            $term = get_term_by( 'slug', $wanted, $tax );
            if ( ! $term || is_wp_error( $term ) ) {
                $term = get_term_by( 'name', $wanted, $tax );
            }
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            wp_set_object_terms( (int) $attachment_id, array( (int) $term->term_id ), $tax, true );
        }
    }

    public static function tag_upload_with_series( $attachment_id ) {
        if ( ! self::asked_for() ) {
            return;
        }
        $series = self::asked_series();
        if ( $series < 1 ) {
            return;
        }
        if ( ! class_exists( 'SFAF_Media' ) ) {
            return;
        }
        SFAF_Media::add_tag( array( (int) $attachment_id ), $series );
    }

    /**
     * Who may upload THROUGH THIS PICKER.
     *
     * WHY THIS IS A CAPABILITY FILTER AND NOT A HIDDEN TAB (3.74.0). portal.js
     * hides wp.media's Upload Files tab for anybody who is not a calendar
     * admin, and a hidden tab is a hidden tab: the upload endpoint is a URL and
     * a POST to it is a request anybody can construct. This is the refusal, and
     * it happens where the file arrives.
     *
     * IT IS NARROW ON PURPOSE, IN TWO DIRECTIONS. It only answers when the
     * request carries our own flag, which the picker sets and nothing else
     * does, so an upload made anywhere else on the site, in the WordPress media
     * library or by another plugin, is untouched. And it only ever REMOVES the
     * capability: it cannot hand `upload_files` to somebody who does not have
     * it, which would be a plugin quietly widening what a WordPress role means.
     *
     * NOT A ROLE WRITE. Nothing here calls add_cap, set_role or touches
     * wp_capabilities. It answers one question about one request and the answer
     * is gone when the request ends. See CLAUDE.md 7.
     *
     * @param string[] $caps The primitive capabilities required.
     * @param string   $cap  The capability being asked about.
     * @param int      $user_id
     * @return string[]
     */
    public static function gate_upload( $caps, $cap, $user_id ) {
        if ( 'upload_files' !== $cap ) {
            return $caps;
        }
        if ( ! self::asked_for() ) {
            return $caps;
        }
        if ( class_exists( 'SFAF_Media' ) && SFAF_Media::can_upload( (int) $user_id ) ) {
            return $caps;
        }
        // do_not_allow is WordPress's own way of saying no, and it is what
        // every core map_meta_cap branch returns for a refusal.
        return array( 'do_not_allow' );
    }

    /**
     * The uploads-relative folder, with its trailing slash.
     *
     * @return string
     */
    public static function prefix() {
        return self::FOLDER . '/';
    }

    /**
     * Is this attachment in the calendar folder?
     *
     * Reads the same meta the filter queries, so a single attachment and a
     * whole list can never disagree about what counts.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function holds( $attachment_id ) {
        $file = get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
        return self::path_is_inside( (string) $file );
    }

    /**
     * Does an uploads-relative path sit inside the folder?
     *
     * ANCHORED AT THE FRONT, which a LIKE '%calendar/%' would not be: that
     * would match `2026/08/calendar-flyer/x.jpg` and `photos/calendar/x.jpg`,
     * neither of which is the folder this means. The query side anchors the
     * same way, with a REGEXP.
     *
     * @param string $file An uploads-relative path, as _wp_attached_file stores it.
     * @return bool
     */
    public static function path_is_inside( $file ) {
        $file = ltrim( (string) $file, '/' );
        return ( 0 === strpos( $file, self::prefix() ) );
    }

    /**
     * The folder as an anchored pattern for a meta_query REGEXP.
     *
     * NO DELIMITER ARGUMENT ON preg_quote(). Passing '/' as the delimiter would
     * escape the folder separator into `calendar\/`, and this string is handed
     * to MySQL, not to PCRE. MySQL 8 reads `\/` as a literal slash, but 5.7's
     * POSIX engine has no such escape and the behaviour is not defined, so the
     * pattern would be right on one server and a guess on another. Nothing else
     * in a folder name needs quoting, and quoting the rest is still correct.
     *
     * @return string
     */
    private static function pattern() {
        return '^' . preg_quote( self::prefix() );
    }

    /**
     * Was this request made by the caladmin picker?
     *
     * READ OFF $_REQUEST, NOT OFF THE FILTERED ARGUMENTS, AND THAT IS NOT AN
     * OVERSIGHT. `wp_ajax_query_attachments()` runs the incoming query through
     * `array_intersect_key()` against a whitelist of the keys core knows,
     * BEFORE `ajax_query_attachments_args` fires. Any key of our own is gone by
     * the time the filter sees its argument, so looking for the flag there
     * would find nothing and the picker would silently show the whole library.
     * The raw request still carries it.
     *
     * @return bool
     */
    private static function asked_for() {
        if ( ! empty( $_REQUEST[ self::FLAG ] ) ) {
            return true;
        }
        /*
         * wp.media nests the library's own arguments under `query` when it asks
         * for attachments, so the flag arrives one level down on that route and
         * at the top level on an upload.
         */
        if ( isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) && ! empty( $_REQUEST['query'][ self::FLAG ] ) ) {
            return true;
        }
        return false;
    }

    /**
     * Narrow the media library query to the calendar folder.
     *
     * ONLY WHEN ASKED. This filter is global, so it runs for the WordPress
     * media library too, and narrowing that would be a plugin quietly hiding
     * most of somebody's images on a screen that has nothing to do with events.
     * The flag is set by the caladmin picker and by nothing else.
     *
     * @param array $args WP_Query arguments.
     * @return array
     */
    public static function restrict_query( $args ) {
        if ( ! self::asked_for() ) {
            return $args;
        }

        $clause = array(
            'key'     => '_wp_attached_file',
            'value'   => self::pattern(),
            'compare' => 'REGEXP',
        );

        /*
         * ADDED TO WHATEVER IS ALREADY THERE rather than replacing it. Another
         * plugin, or a later version of this one, may have its own meta_query
         * on this screen, and overwriting it would break that silently.
         */
        if ( ! empty( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
            $args['meta_query'] = array(
                'relation' => 'AND',
                $args['meta_query'],
                array( $clause ),
            );
        } else {
            $args['meta_query'] = array( $clause );
        }

        /*
         * AND THE SERIES, WHEN THE PICKER ASKED FOR ONE (3.74.0).
         *
         * The event editor opens the library on the event's own series, with an
         * "All calendar images" trigger beside it that asks without this. The
         * term id arrives inside wp.media's `query`, which is where the folder
         * flag arrives too, so both are read the same way and the same note
         * above about array_intersect_key applies to both.
         *
         * A TERM THAT DOES NOT EXIST NARROWS TO NOTHING, which is correct: the
         * request asked for a series and there is no such series, so there are
         * no pictures in it. Widening back to the whole folder would answer a
         * different question from the one asked.
         */
        $series = self::asked_series();
        if ( $series > 0 ) {
            $clause = array(
                'taxonomy' => 'uc_series',
                'field'    => 'term_id',
                'terms'    => $series,
            );
            if ( ! empty( $args['tax_query'] ) && is_array( $args['tax_query'] ) ) {
                $args['tax_query'] = array(
                    'relation' => 'AND',
                    $args['tax_query'],
                    array( $clause ),
                );
            } else {
                $args['tax_query'] = array( $clause );
            }
        }

        return $args;
    }

    /**
     * Which series the picker asked for, if any.
     *
     * Read off the raw request for the reason asked_for() gives: core strips
     * unknown keys out of the query before the filter sees its argument.
     *
     * @return int 0 when none was asked for.
     */
    private static function asked_series() {
        if ( isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) && ! empty( $_REQUEST['query']['uc_series'] ) ) {
            return (int) $_REQUEST['query']['uc_series'];
        }
        if ( ! empty( $_REQUEST['uc_series'] ) ) {
            return (int) $_REQUEST['uc_series'];
        }
        return 0;
    }

    /**
     * Send an upload made from caladmin into the calendar folder.
     *
     * SO THE EVENT DOES NOT WAIT ON ANYBODY. Somebody setting up an event with
     * a picture nobody has prepared can upload it here, and it lands in the
     * folder, which means it is offered next time and can be resized later
     * without moving it. The alternative was an upload landing in this month's
     * directory, invisible to this picker, and the event held up until somebody
     * with access to the media library moved it.
     *
     * The year and month setting is deliberately overridden for these: the
     * folder IS the organisation for calendar images, and a date directory
     * underneath would put the same pictures in twelve places a year.
     *
     * @param array $dirs From wp_upload_dir().
     * @return array
     */
    public static function upload_to_folder( $dirs ) {
        if ( ! self::asked_for() ) {
            return $dirs;
        }
        if ( empty( $dirs['basedir'] ) || empty( $dirs['baseurl'] ) ) {
            return $dirs;
        }

        $dirs['subdir'] = '/' . self::FOLDER;
        $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
        $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];

        return $dirs;
    }

    /**
     * Is there anything in the folder at all?
     *
     * FOR THE PICKER'S HINT, which is the one place this could go wrong
     * quietly. If the folder were ever renamed, or WP Media Folder's settings
     * changed so files stopped landing on disk there, the picker would open on
     * an empty grid and read as broken. Knowing this means the screen can say
     * which of the two it is, and point at the fix.
     *
     * ASKED ONCE PER REQUEST. The editor and the pending queue can both render
     * several image fields on one page, and this is a meta query.
     *
     * @return bool
     */
    public static function has_any() {
        static $any = null;
        if ( null !== $any ) {
            return $any;
        }
        $found = get_posts( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_wp_attached_file',
                    'value'   => self::pattern(),
                    'compare' => 'REGEXP',
                ),
            ),
        ) );
        $any = ! empty( $found );
        return $any;
    }
}
