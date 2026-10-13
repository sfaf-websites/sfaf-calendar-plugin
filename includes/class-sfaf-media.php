<?php
/**
 * The calendar's images, tagged by series.
 *
 * WHY THIS EXISTS. Contributors and editors never see wp-admin, so the
 * WordPress media library is unavailable to most of the people who maintain
 * this calendar. An organizer who wants to know what pictures already exist for
 * their programme has nowhere to look, and WordPress's own tagging asks you to
 * TYPE a tag rather than pick one, which is how a library ends up with
 * "Strut", "strut" and "STRUT".
 *
 * THE TAGS ARE THE SERIES. NOT A SECOND VOCABULARY.
 * ---------------------------------------------------------------------------
 * There is no tag to create, no list to keep in step and no way for the two to
 * disagree about what a programme is called: a new series makes a new tag
 * available the moment it exists, and renaming a series renames the tag. The
 * mechanism is that `uc_series` is registered for attachments as well as for
 * events, so an image carries the same terms an event does.
 *
 * DELETING A SERIES MUST NOT TOUCH THE IMAGES, AND THIS IS HOW THAT IS
 * GUARANTEED rather than intended. `wp_delete_term()` deletes the term and its
 * rows in `term_relationships`. It does not read, write or delete a single
 * post, and nothing in this plugin hooks `delete_term` to do so. So an image
 * tagged only with a series that is then deleted keeps its file, its id, its
 * title and every event pointing at it, and loses one relationship row. It is
 * then untagged, which is a state the media screen has a filter for, so it
 * surfaces rather than disappearing. See `.claude/media-tags-test.php`, which
 * asserts exactly that and refuses to pass if anything starts deleting.
 *
 * AN IMAGE MAY CARRY SEVERAL TAGS, because a photograph of a group at a clinic
 * is genuinely the picture for two programmes, and a taxonomy already says that
 * without anything being added.
 *
 * AND THE SERIES ARCHIVE STAYS EVENTS ONLY. `uc_series` is a public taxonomy
 * with a rewrite, so attaching a second object type to it would put images into
 * whatever the theme renders at /event-series/<slug>/. The guard below keeps
 * that query to `uc_event`, which is what it has always listed.
 *
 * WHAT THIS CLASS IS NOT. It is not a second media library and it does not move
 * files. The calendar folder is still SFAF_Media_Folder's rule, read off core's
 * own `_wp_attached_file`, and everything here asks that class rather than
 * repeating it.
 *
 * @package SFAF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFAF_Media {

    /**
     * The tag vocabulary, which is the series taxonomy and not a new one.
     *
     * Named here so every caller reads one constant, and so the day somebody
     * asks for tags that are not series there is one place that answers.
     */
    const TAXONOMY = 'uc_series';

    /** How many pictures a page of the media screen shows. */
    const PER_PAGE = 48;

    /** The filter value meaning "images with no series on them at all". */
    const UNTAGGED = 'none';
    /**
     * The filter value meaning "pictures taken out of the folder".
     *
     * A STRING LIKE UNTAGGED RATHER THAN AN ID, because it is a view rather
     * than a series and the filter control reads one value. It is also the only
     * route back: without a view that asks for them, a removal is one way in
     * practice however recoverable it is in principle.
     */
    const REMOVED_VIEW = 'removed';

    /**
     * Post meta: a person typed this picture's name, on the Images screen.
     *
     * WHY THIS EXISTS, AND IT IS A DEFECT 3.76.0 SHIPPED WITHOUT SEEING.
     * `looks_like_a_filename()` blanks a title that matches the file it came
     * from, which is right about WordPress's own derived titles and is a GUESS
     * about everybody else's. The guess is wrong in the commonest case there
     * is: a well-named file is usually named after the picture, so
     *
     *     "Cycle To Zero"  on  cycle-to-zero.jpg
     *     "Strut SFAF San Francisco"  on  strut-sfaf-san-francisco.jpg
     *
     * were both thrown away. **Somebody could type a name on the Images screen,
     * save it, and watch every picker go on showing the file name**, which
     * makes the remedy 3.76.0 added for exactly that complaint not work.
     *
     * THE ANSWER IS TO KNOW RATHER THAN GUESS. A title saved here was typed by
     * a person, and that is a fact rather than a shape: it is recorded at the
     * write and trusted at the read. The heuristic stays and still covers every
     * picture nobody has named, which is what it was written for.
     *
     * CLEARING THE BOX CLEARS THE MARK, so emptying a name really does go back
     * to the file name rather than leaving an empty title that is trusted.
     */
    const META_NAMED = '_uc_media_named';

    /**
     * TAKEN OUT OF THE CALENDAR FOLDER, WITHOUT TAKING ANYTHING ELSE (3.81.0).
     *
     * WHAT REMOVE DOES, AND THE CHOICE IS THE POINT. It does NOT delete the
     * file and it does not delete the attachment. It takes the picture out of
     * the set this calendar offers: gone from the Images screen's default view,
     * gone from every picker, not eligible to become a series' picture. The
     * file stays on the server, the attachment keeps its id, and wp-admin's own
     * Media Library still has it.
     *
     * WHY NOT DELETE. Deleting is the one action on this screen nothing puts
     * back, and the thing somebody wants nine times out of ten is "stop offering
     * me this", not "destroy it". A recoverable action that covers the common
     * case beats an irreversible one that covers it and one other.
     *
     * WHY A MARKER RATHER THAN MOVING THE FILE. "Out of the folder" could be
     * literal: move it to another directory and the existing path clause stops
     * matching. That is a filesystem operation with its own failure modes,
     * permissions and half-done states, and it changes the URL, which breaks any
     * place the URL was copied rather than the id referenced. A meta write is
     * atomic, changes no URL, and is undone by deleting it. The outcome a person
     * sees is the same.
     *
     * NOTHING IS STRANDED, AND THAT IS ENFORCED RATHER THAN HOPED FOR. An image
     * in use as an event's own picture or as a series' picture is refused, and
     * the refusal names what is using it. See uses_of().
     */
    const META_REMOVED = '_uc_media_removed';

    /**
     * On an attachment uploaded from an event's own screen (3.110.3): who, when
     * and for which event, as array( 'by' => user id, 'at' => timestamp,
     * 'event' => id ). META_UPLOAD_SENT marks that websites@ has been told, so
     * the second save of the same picture sends nothing.
     */
    const META_UPLOAD      = '_uc_event_upload';
    const META_UPLOAD_SENT = '_uc_event_upload_sent';

    /** Where the one-off upload notice goes. */
    const UPLOAD_NOTICE_TO = 'websites@sfaf.org';

    /** active_ids(), once per request. */
    private static $active = null;

    public static function register() {
        /*
         * AFTER SFAF_Series::register_taxonomy(), which is what creates the
         * taxonomy. `register_taxonomy_for_object_type()` adds an object type
         * to one that exists; calling it first would silently do nothing.
         * `init` at a later priority is the arrangement that guarantees the
         * order without either class knowing about the other's hook.
         */
        add_action( 'init', array( __CLASS__, 'attach_to_attachments' ), 20 );
        add_action( 'pre_get_posts', array( __CLASS__, 'keep_archive_to_events' ) );
    }

    /**
     * Let an attachment carry series terms.
     */
    public static function attach_to_attachments() {
        if ( ! taxonomy_exists( self::TAXONOMY ) ) {
            return;
        }
        register_taxonomy_for_object_type( self::TAXONOMY, 'attachment' );
    }

    /**
     * The series archive lists events, as it always has.
     *
     * THIS IS THE PRICE OF USING ONE TAXONOMY FOR BOTH, and it is worth paying
     * once here rather than inventing a parallel vocabulary. Without it, a
     * visitor at /event-series/strut/ would be shown attachment pages for every
     * image tagged Strut, mixed in with the events.
     *
     * THE MAIN QUERY ONLY. A tax_query somebody else builds is theirs, and this
     * has no business narrowing it.
     *
     * @param WP_Query $query
     */
    public static function keep_archive_to_events( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( ! $query->is_tax( self::TAXONOMY ) ) {
            return;
        }
        $query->set( 'post_type', 'uc_event' );
    }

    /* =====================================================================
     * Who may do what
     *
     * THREE ANSWERS, NOT ONE, AND THEY ARE DELIBERATELY NOT THE SAME SHAPE.
     * An editor can tag images they cannot upload, because organising a
     * library and adding to it are different jobs: tagging is reversible and
     * changes nothing on any page, and an upload puts a file on the server
     * forever.
     * ================================================================== */

    /**
     * Upload: administrators only.
     *
     * @param WP_User|int $user
     * @return bool
     */
    public static function can_upload( $user ) {
        $id = is_object( $user ) ? (int) $user->ID : (int) $user;
        return ( 'admin' === SFAF_Portal::get_role( $id ) );
    }

    /**
     * Tag: administrators and editors.
     *
     * @param WP_User|int $user
     * @return bool
     */
    public static function can_tag( $user ) {
        $id = is_object( $user ) ? (int) $user->ID : (int) $user;
        return in_array( SFAF_Portal::get_role( $id ), array( 'admin', 'editor' ), true );
    }

    /* =====================================================================
     * Reading the folder
     * ================================================================== */

    /**
     * Pictures from the calendar folder, newest first.
     *
     * ONE QUERY BUILDER FOR EVERY SCREEN THAT SHOWS THESE. The media screen,
     * the picker on both public forms and the count on the media screen's
     * filters all ask this, so "which images are the calendar's" is answered
     * in one place and cannot drift between a grid and a picker.
     *
     * THREE PLACES FROM 3.110.3, and 'place' says which: 'series' (the
     * calendar folder, the default every older caller already meant), 'other'
     * (Other images), 'submitted' (calendar-submissions/), or 'all'.
     * 'library' is series and other together. Nothing but the Images screen
     * asks for 'submitted' or 'all'.
     *
     * @param array $args {
     *     @type string $place    series, other, library, submitted or all.
     *     @type int    $series   Term id to filter by, 0 for all.
     *     @type bool   $untagged True for images carrying no series at all.
     *     @type string $search   Narrows to a file name or name containing this.
     *     @type bool   $active   Only pictures an upcoming published event uses.
     *     @type int    $per_page 0 for everything.
     *     @type int    $paged
     * }
     * @return array{ids:int[],total:int,pages:int}
     */
    public static function pictures( $args = array() ) {
        $args = array_merge( array(
            'place'    => 'series',
            'series'   => 0,
            'untagged' => false,
            'removed'  => false,
            'search'   => '',
            'active'   => false,
            'per_page' => self::PER_PAGE,
            'paged'    => 1,
        ), $args );

        switch ( $args['place'] ) {
            case 'other':
                $want = array( SFAF_Media_Folder::other_prefix() );
                break;
            case 'library':
                $want = array( SFAF_Media_Folder::prefix(), SFAF_Media_Folder::other_prefix() );
                break;
            case 'submitted':
                $want = array( SFAF_Uploads::prefix() );
                break;
            case 'all':
                $want = array( SFAF_Media_Folder::prefix(), SFAF_Media_Folder::other_prefix(), SFAF_Uploads::prefix() );
                break;
            default:
                $want = array( SFAF_Media_Folder::prefix() );
        }
        /* One folder is the pattern every caller has always sent; several are
         * an alternation. preg_quote() with no delimiter, for the reason
         * SFAF_Media_Folder::pattern() gives. */
        $pattern = ( 1 === count( $want ) )
            ? '^' . preg_quote( $want[0] )
            : '^(' . implode( '|', array_map( 'preg_quote', $want ) ) . ')';

        /*
         * SEARCH AND ACTIVE ARE ANSWERED HERE, AFTER THE QUERY, and the page is
         * cut from what is left. A file name and a title are two places to
         * look and WP_Query cannot OR a meta value with a title; the folder
         * holds dozens of pictures, not thousands, so reading every id once is
         * the cheaper thing to get right.
         */
        $after = ( '' !== trim( (string) $args['search'] ) || ! empty( $args['active'] ) );
        $per   = (int) $args['per_page'];

        $q = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => ( $per > 0 && ! $after ) ? $per : -1,
            'paged'          => $after ? 1 : max( 1, (int) $args['paged'] ),
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_wp_attached_file',
                    'value'   => $pattern,
                    'compare' => 'REGEXP',
                ),
                /*
                 * REMOVED PICTURES ARE OUT OF EVERY ANSWER THIS GIVES, which is
                 * what makes remove mean anything: this is the one builder the
                 * grid, both pickers and the counts all go through, so
                 * excluding here excludes everywhere at once. `removed => true`
                 * is the one view that asks for them, and it is the Images
                 * screen's own filter, not a picker's.
                 */
                $args['removed']
                    ? array( 'key' => self::META_REMOVED, 'compare' => 'EXISTS' )
                    : array( 'key' => self::META_REMOVED, 'compare' => 'NOT EXISTS' ),
            ),
        );

        if ( $args['untagged'] ) {
            /*
             * NOT IN with every term, rather than a "no terms" flag, because
             * WP_Tax_Query has no such flag. `operator => NOT EXISTS` does
             * exist and is what this is: it asks for objects with no row in
             * this taxonomy at all, which is exactly what an image whose only
             * series has been deleted looks like.
             */
            $q['tax_query'] = array(
                array(
                    'taxonomy' => self::TAXONOMY,
                    'operator' => 'NOT EXISTS',
                ),
            );
        } elseif ( (int) $args['series'] > 0 ) {
            $q['tax_query'] = array(
                array(
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => (int) $args['series'],
                ),
            );
        }

        $query = new WP_Query( $q );
        $ids   = array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );
        if ( ! $after ) {
            return array(
                'ids'   => $ids,
                'total' => (int) $query->found_posts,
                'pages' => (int) $query->max_num_pages,
            );
        }

        $needle = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $args['search'] ) ) );
        $live   = ! empty( $args['active'] ) ? self::active_ids() : null;
        $kept   = array();
        foreach ( $ids as $id ) {
            if ( null !== $live && ! isset( $live[ $id ] ) ) {
                continue;
            }
            if ( '' !== $needle && false === strpos( self::search_text( $id ), $needle ) ) {
                continue;
            }
            $kept[] = $id;
        }
        $total = count( $kept );
        $pages = $per > 0 ? (int) ceil( $total / $per ) : ( $total ? 1 : 0 );
        $page  = $per > 0 ? array_slice( $kept, ( max( 1, (int) $args['paged'] ) - 1 ) * $per, $per ) : $kept;
        return array( 'ids' => $page, 'total' => $total, 'pages' => $pages );
    }

    /**
     * What the search matches on one picture: its file name and its name, in
     * lower case. The pickers write the same string into data-uc-filter-text,
     * so typing finds the same pictures whether the page or the server looks.
     *
     * @param int $id
     * @return string
     */
    public static function search_text( $id ) {
        $file  = basename( (string) get_post_meta( (int) $id, '_wp_attached_file', true ) );
        $title = trim( (string) get_the_title( (int) $id ) );
        return strtolower( trim( preg_replace( '/\s+/', ' ', $file . ' ' . $title ) ) );
    }

    /**
     * Pictures in use by an event that is published and not yet past, as
     * attachment id => true. Asked once per request.
     *
     * ITS OWN PICTURE, NOT ITS SERIES' ONE. An event showing its series picture
     * by fallback has not been given that picture, so a series picture counts
     * as active only where some event chose it.
     *
     * @return array<int,bool>
     */
    public static function active_ids() {
        if ( null !== self::$active ) {
            return self::$active;
        }
        $memo  = array();
        $today = class_exists( 'SFAF_Sources' ) ? SFAF_Sources::today() : gmdate( 'Y-m-d' );
        $events = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ),
                array(
                    'relation' => 'OR',
                    array( 'key' => '_uc_event_date', 'value' => $today, 'compare' => '>=', 'type' => 'CHAR' ),
                    array( 'key' => '_uc_end_date', 'value' => $today, 'compare' => '>=', 'type' => 'CHAR' ),
                ),
            ),
        ) );
        foreach ( (array) $events as $ev ) {
            $ev = (int) ( is_object( $ev ) ? $ev->ID : $ev );
            $date = (string) get_post_meta( $ev, '_uc_event_date', true );
            $end  = (string) get_post_meta( $ev, '_uc_end_date', true );
            $last = ( '' !== $end && $end >= $date ) ? $end : $date;
            if ( '' === $last || $last < $today ) {
                continue;
            }
            $att = (int) get_post_meta( $ev, '_thumbnail_id', true );
            if ( $att ) {
                $memo[ $att ] = true;
            }
        }
        self::$active = $memo;
        return $memo;
    }

    /** Forget active_ids() within this request, for a check that changes events. */
    public static function forget_active() {
        self::$active = null;
    }

    /**
     * One picture, as the rows every screen renders.
     *
     * ONE NAME PER ROW: THE TITLE, OR THE FILE NAME. A title says what a
     * picture is FOR, which is the question somebody choosing is asking, so it
     * leads where there is a real one. Where there is not, the file name is
     * what tells two photographs of the same event apart at 64px. Moved here
     * from SFAF_Request in 3.74.0 so the media screen and the pickers cannot
     * describe one picture two ways.
     *
     * @param int $id
     * @return array|null Null when there is no usable image.
     */
    public static function row( $id ) {
        $id    = (int) $id;
        $thumb = wp_get_attachment_image_url( $id, 'medium' );
        if ( ! $thumb ) {
            return null;
        }

        $file  = basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );
        $title = trim( (string) get_the_title( $id ) );

        /*
         * A NAME SOMEBODY TYPED IS TRUSTED WITHOUT BEING ASKED ABOUT (3.78.0).
         * The heuristic below is for titles nobody chose; a title saved on the
         * Images screen was chosen, and it is kept even when it matches the
         * file, which is the commonest case for a well-named file. See
         * META_NAMED for the defect this closes.
         */
        $named = ( '' !== $title ) && get_post_meta( $id, self::META_NAMED, true );

        if ( ! $named && '' !== $title && self::looks_like_a_filename( $title, $file ) ) {
            $title = '';
        }
        if ( '' === $file ) {
            $file = ( '' !== $title ) ? $title : 'Untitled picture';
        }

        return array(
            'id'    => $id,
            'thumb' => $thumb,
            /*
             * THE BANNER SIZE (3.80.0). `medium` is a 300px crop and the form's
             * banner runs the full width of the card, so the thumbnail used in
             * the list would arrive soft. Falls back to the thumbnail rather
             * than to nothing: a registration that has no `large` is an image
             * smaller than large, and showing it is better than showing a gap.
             */
            'full'  => wp_get_attachment_image_url( $id, 'large' ) ?: $thumb,
            'file'  => $file,
            'title' => $title,
            /* WordPress's own key, so a picture described in the Media Library
             * arrives here already filled in. See set_alt(). */
            'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'removed' => (bool) get_post_meta( $id, self::META_REMOVED, true ),
            'tags'  => self::tags_of( $id ),
            'search' => self::search_text( $id ),
        );
    }

    /**
     * Which of the three places a picture is in (3.110.3): 'series', 'other',
     * 'submitted', or '' for anywhere else.
     *
     * @param int $id
     * @return string
     */
    public static function place_of( $id ) {
        $rel = ltrim( (string) get_post_meta( (int) $id, '_wp_attached_file', true ), '/' );
        if ( SFAF_Media_Folder::path_is_inside( $rel ) ) {
            return 'series';
        }
        if ( 0 === strpos( $rel, SFAF_Media_Folder::other_prefix() ) ) {
            return 'other';
        }
        return SFAF_Uploads::path_is_inside( $rel ) ? 'submitted' : '';
    }

    /**
     * Several pictures, as rows, skipping anything with no usable image.
     *
     * @param int[] $ids
     * @return array<int,array>
     */
    public static function rows( $ids ) {
        $rows = array();
        foreach ( (array) $ids as $id ) {
            $row = self::row( $id );
            if ( $row ) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Is this title just the file name wearing a title's clothes?
     *
     * WordPress makes a title out of the file name on upload, so
     * `dsc_0043.jpg` becomes "Dsc 0043", which is not a name anybody chose and
     * is worse than showing the file name itself. Moved here from SFAF_Request
     * with row(), which is its only caller.
     *
     * @param string $title
     * @param string $file
     * @return bool
     */
    public static function looks_like_a_filename( $title, $file = '' ) {
        $title = trim( (string) $title );
        if ( '' === $title ) {
            return true;
        }

        $file = trim( (string) $file );
        if ( '' !== $file ) {
            $derived = preg_replace( '/\.[a-z0-9]+$/i', '', $file );
            $derived = trim( preg_replace( '/\s+/', ' ', str_replace( array( '-', '_' ), ' ', $derived ) ) );
            if ( '' !== $derived && 0 === strcasecmp( $derived, $title ) ) {
                return true;
            }
        }

        if ( preg_match( '/\.(jpe?g|png|gif|webp|tiff?|bmp)$/i', $title ) ) {
            return true;
        }
        $letters = preg_match_all( '/[a-z]/i', $title );
        $digits  = preg_match_all( '/[0-9]/', $title );
        if ( $digits > 0 && $letters <= $digits ) {
            return true;
        }
        return false;
    }

    /* =====================================================================
     * Tagging
     * ================================================================== */

    /**
     * The series on one picture, as term objects.
     *
     * @param int $id
     * @return array<int,WP_Term>
     */
    /**
     * What is relying on this picture, in words, or an empty array.
     *
     * WHATEVER REMOVE DOES, IT MUST NOT STRAND AN EVENT, and this is the method
     * that makes that true rather than hoped for. Two things can be relying on
     * a picture and they are stored in different places, so both are asked:
     *
     *   . AN EVENT'S OWN PICTURE, which is WordPress's `_thumbnail_id`.
     *   . A SERIES' PICTURE, which is the term meta SFAF_Series::META_IMAGE_ID.
     *
     * A SERIES THAT ONLY *TAGS* THIS PICTURE IS NOT USING IT, and that is the
     * distinction the whole of 3.81.0 turns on. A tag is filing; it says which
     * programme a picture belongs to. Only a picture a series has been GIVEN,
     * or that an event has chosen, is one something would lose. A series falling
     * back to a tagged picture through image_id() is covered, because removing
     * it takes it out of earliest_for_series() and the series falls back to the
     * next one or to nothing, which is a change in what is offered rather than a
     * dangling reference.
     *
     * NAMES, NOT IDS. The refusal is read by a person deciding what to do next,
     * and "used by 3 things" is not a sentence anybody can act on.
     *
     * @param int $id
     * @return string[] Human names, most specific first.
     */
    public static function uses_of( $id ) {
        $id  = (int) $id;
        $out = array();
        if ( ! $id ) {
            return $out;
        }

        $events = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array( 'key' => '_thumbnail_id', 'value' => (string) $id ),
            ),
        ) );
        foreach ( $events as $ev ) {
            $out[] = 'the event "' . ( get_the_title( $ev ) ?: '(untitled)' ) . '"';
        }

        $terms = get_terms( array(
            'taxonomy'   => SFAF_Series::TAXONOMY,
            'hide_empty' => false,
            'meta_query' => array(
                array( 'key' => SFAF_Series::META_IMAGE_ID, 'value' => (string) $id ),
            ),
        ) );
        if ( is_array( $terms ) ) {
            foreach ( $terms as $t ) {
                $out[] = 'the series "' . $t->name . '"';
            }
        }

        return $out;
    }

    /**
     * What is using each of these pictures, in two queries for the whole page.
     *
     * WHY THIS EXISTS AND uses_of() IS NOT ENOUGH (3.83.0). Remove was reported
     * three times as doing nothing. Everything between the press and the write
     * has been proved correct: the button belongs to the remove form, the form
     * carries the right action and nonce, the handler is placed and gated
     * correctly, the marker writes and pictures() excludes it. The one branch
     * that cannot be exercised without the site is the REFUSAL, and a refusal
     * that arrives as a flash band after a page reload is indistinguishable
     * from nothing happening, which is exactly what was reported.
     *
     * SO THE SCREEN SAYS IT BEFORE THE PRESS. A picture something is relying on
     * shows what is relying on it and has no Remove button at all, which is this
     * project's own rule: a control that cannot do anything should not be on
     * screen. The refusal in the handler stays, because a POST is a request
     * anybody can construct and drawing no button is a render rather than a
     * guard.
     *
     * TWO QUERIES FOR THE WHOLE PAGE, not two per picture. The Images screen
     * shows 48 at a time, and uses_of() per row would be 96 queries to draw one
     * grid.
     *
     * @param int[] $ids
     * @return array<int,string[]> attachment id => human names of what uses it.
     */
    public static function uses_map( $ids ) {
        $ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
        $out = array();
        if ( empty( $ids ) ) {
            return $out;
        }

        /* Events whose own picture is one of these. 'any' rather than a status
         * list: a draft relying on a picture is relying on it just as much. */
        $events = get_posts( array(
            'post_type'      => 'uc_event',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array( 'key' => '_thumbnail_id', 'value' => $ids, 'compare' => 'IN' ),
            ),
        ) );
        foreach ( $events as $ev ) {
            $att = (int) get_post_meta( $ev, '_thumbnail_id', true );
            if ( $att ) {
                $out[ $att ][] = 'the event "' . ( get_the_title( $ev ) ?: '(untitled)' ) . '"';
            }
        }

        /* Series that have been GIVEN one of these as their picture. A series
         * that merely tags it is not using it: a tag is filing, and removing the
         * picture moves that series to the next one tagged. */
        $terms = get_terms( array(
            'taxonomy'   => SFAF_Series::TAXONOMY,
            'hide_empty' => false,
            'meta_query' => array(
                array( 'key' => SFAF_Series::META_IMAGE_ID, 'value' => $ids, 'compare' => 'IN' ),
            ),
        ) );
        if ( is_array( $terms ) ) {
            foreach ( $terms as $t ) {
                $att = (int) get_term_meta( $t->term_id, SFAF_Series::META_IMAGE_ID, true );
                if ( $att ) {
                    $out[ $att ][] = 'the series "' . $t->name . '"';
                }
            }
        }

        return $out;
    }

    /**
     * Take a picture out of the calendar folder, or put it back.
     *
     * REFUSED WHILE ANYTHING IS USING IT, and the refusal is the return value
     * rather than a silent no-op: the screen has to be able to say which events
     * and which series, and a boolean cannot. Same shape as the venue and team
     * deletion rules, which refuse while an event names them and name the
     * events.
     *
     * PUTTING IT BACK IS NEVER REFUSED. Nothing can be relying on a picture that
     * is not being offered, so there is no case to check.
     *
     * @param int  $id
     * @param bool $remove True to take it out, false to put it back.
     * @return true|string[] True, or the list of things using it.
     */
    public static function set_removed( $id, $remove = true ) {
        $id = (int) $id;
        if ( ! $id ) {
            return array( 'no such picture' );
        }

        if ( ! $remove ) {
            delete_post_meta( $id, self::META_REMOVED );
            return true;
        }

        $uses = self::uses_of( $id );
        if ( ! empty( $uses ) ) {
            return $uses;
        }

        update_post_meta( $id, self::META_REMOVED, time() );
        return true;
    }

    /**
     * The earliest picture tagged to this series, or 0.
     *
     * WHAT IT IS FOR. SFAF_Series::image_id() falls back to this when a series
     * has no picture of its own, which is every one of the thirty the import
     * created. See the long note there for why a tag is a fallback rather than a
     * second setting, and why it is the earliest rather than the newest.
     *
     * IT ASKS THE SAME FOLDER THE PICKER DOES. A picture tagged to a series but
     * sitting outside the calendar folder is not one this calendar offers
     * anywhere, so it must not become a programme's picture by a route nobody
     * can see. Removed pictures are excluded for the same reason.
     *
     * ONE QUERY, IDS ONLY, ORDERED BY ID. No post objects are hydrated: the
     * caller wants a number.
     *
     * @param int $term_id
     * @return int
     */
    public static function earliest_for_series( $term_id ) {
        $term_id = (int) $term_id;
        if ( ! $term_id ) {
            return 0;
        }

        $q = new WP_Query( array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => array(
                array(
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ),
            ),
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_wp_attached_file',
                    'value'   => '^' . preg_quote( SFAF_Media_Folder::prefix() ),
                    'compare' => 'REGEXP',
                ),
                array(
                    'key'     => self::META_REMOVED,
                    'compare' => 'NOT EXISTS',
                ),
            ),
        ) );

        return empty( $q->posts ) ? 0 : (int) $q->posts[0];
    }

    /**
     * Move a picture into series pictures or Other images (3.110.3).
     *
     * THE FILE MOVES, AND THE ATTACHMENT KEEPS ITS ID. Every event and series
     * that chose it by id goes on pointing at it. Each size moves with the
     * original, and a name already taken in the folder gets a number. A URL
     * stored as text (an event's typed or copied picture URL, a series'
     * picture URL) is rewritten to the new address, file by file.
     *
     * TO OTHER IMAGES, every series tag comes off, because an Other image
     * belongs to no series; refused while a series has it as its picture,
     * naming the series. TO SERIES PICTURES, $term_id is required and is added.
     * From submissions it is the approval's move: see approve_event.
     *
     * @param int    $id
     * @param string $to      'series' or 'other'.
     * @param int    $term_id The series, for 'series'.
     * @return true|WP_Error
     */
    public static function move( $id, $to, $term_id = 0 ) {
        $id      = (int) $id;
        $term_id = (int) $term_id;
        if ( $id < 1 || 'attachment' !== get_post_type( $id ) ) {
            return new WP_Error( 'uc_move', 'That picture is not there any more.' );
        }
        $from_ok = SFAF_Media_Folder::offers( $id ) || SFAF_Uploads::holds( $id );
        if ( ! $from_ok || ! in_array( $to, array( 'series', 'other' ), true ) ) {
            return new WP_Error( 'uc_move', 'That picture cannot be moved from here.' );
        }
        if ( 'series' === $to ) {
            $term = $term_id ? get_term( $term_id, self::TAXONOMY ) : null;
            if ( ! $term || is_wp_error( $term ) ) {
                return new WP_Error( 'uc_move', 'Choose a series.' );
            }
        } else {
            $giving = get_terms( array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'meta_query' => array( array( 'key' => SFAF_Series::META_IMAGE_ID, 'value' => (string) $id ) ),
            ) );
            if ( is_array( $giving ) && $giving ) {
                return new WP_Error( 'uc_move', 'The ' . implode( ', ', wp_list_pluck( $giving, 'name' ) ) . ' series uses this as its picture. Give that series another picture first.' );
            }
        }

        $dest = ( 'series' === $to ) ? SFAF_Media_Folder::FOLDER : SFAF_Media_Folder::OTHER;
        $rel  = ltrim( (string) get_post_meta( $id, '_wp_attached_file', true ), '/' );
        if ( 0 !== strpos( $rel, $dest . '/' ) ) {
            $moved = self::move_files( $id, $rel, $dest );
            if ( is_wp_error( $moved ) ) {
                return $moved;
            }
        }

        if ( 'series' === $to ) {
            wp_set_object_terms( $id, array( $term_id ), self::TAXONOMY, true );
        } else {
            wp_delete_object_term_relationships( $id, self::TAXONOMY );
        }
        self::forget_active();
        return true;
    }

    /**
     * The disk half of move(): every file, then the attachment's own record,
     * then any URL stored as text. All the renames or none.
     *
     * @return true|WP_Error
     */
    private static function move_files( $id, $rel, $dest ) {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'uc_move', 'The uploads folder is not writable.' );
        }
        $base_dir = rtrim( $uploads['basedir'], '/\\' );
        $base_url = rtrim( $uploads['baseurl'], '/' );
        $old_dir  = dirname( $rel );
        $old_file = basename( $rel );
        $to_dir   = $base_dir . '/' . $dest;
        if ( ! is_dir( $to_dir ) && ! wp_mkdir_p( $to_dir ) ) {
            return new WP_Error( 'uc_move', 'The folder could not be made.' );
        }
        if ( ! file_exists( $to_dir . '/index.html' ) ) {
            @file_put_contents( $to_dir . '/index.html', '' );
        }

        $new_file = wp_unique_filename( $to_dir, $old_file );
        $old_stem = pathinfo( $old_file, PATHINFO_FILENAME );
        $new_stem = pathinfo( $new_file, PATHINFO_FILENAME );
        $rename   = function ( $name ) use ( $old_stem, $new_stem ) {
            return ( 0 === strpos( $name, $old_stem ) ) ? $new_stem . substr( $name, strlen( $old_stem ) ) : $name;
        };

        $meta  = wp_get_attachment_metadata( $id );
        $meta  = is_array( $meta ) ? $meta : array();
        $pairs = array( $old_file => $new_file );
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $k => $size ) {
                if ( ! empty( $size['file'] ) ) {
                    $pairs[ $size['file'] ] = $rename( $size['file'] );
                    $meta['sizes'][ $k ]['file'] = $pairs[ $size['file'] ];
                }
            }
        }
        if ( ! empty( $meta['original_image'] ) ) {
            $pairs[ $meta['original_image'] ] = $rename( $meta['original_image'] );
            $meta['original_image'] = $pairs[ $meta['original_image'] ];
        }

        $done = array();
        foreach ( $pairs as $from => $to ) {
            $src = $base_dir . '/' . $old_dir . '/' . $from;
            if ( ! file_exists( $src ) ) {
                continue;
            }
            if ( ! @rename( $src, $to_dir . '/' . $to ) ) {
                foreach ( $done as $back_from => $back_to ) {
                    @rename( $back_to, $back_from );
                }
                return new WP_Error( 'uc_move', 'The picture could not be moved. Nothing was changed.' );
            }
            $done[ $src ] = $to_dir . '/' . $to;
        }

        update_attached_file( $id, $dest . '/' . $new_file );
        $meta['file'] = $dest . '/' . $new_file;
        wp_update_attachment_metadata( $id, $meta );

        /* URLs stored as text, rewritten file by file. The folder and the file
         * together, so strut.jpg cannot catch big-strut.jpg. */
        global $wpdb;
        foreach ( $pairs as $from => $to ) {
            $old_url = $base_url . '/' . $old_dir . '/' . $from;
            $new_url = $base_url . '/' . $dest . '/' . $to;
            $like    = '%' . $wpdb->esc_like( '/' . $old_dir . '/' . $from ) . '%';
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_uc_image_url','_uc_image_url_typed') AND meta_value LIKE %s",
                $like
            ) );
            foreach ( (array) $rows as $r ) {
                update_post_meta( (int) $r->post_id, $r->meta_key, str_replace( $old_url, $new_url, (string) $r->meta_value ) );
            }
            $terms = $wpdb->get_results( $wpdb->prepare(
                "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                SFAF_Series::META_IMAGE_URL,
                $like
            ) );
            foreach ( (array) $terms as $t ) {
                update_term_meta( (int) $t->term_id, SFAF_Series::META_IMAGE_URL, str_replace( $old_url, $new_url, (string) $t->meta_value ) );
            }
        }
        return true;
    }

    /**
     * The submitted picture a pending event still carries, or 0 (3.110.3).
     * Only while the file is in calendar-submissions/: once approval has
     * filed it, it is the event's picture and not a submitted one.
     *
     * @param int $event_id
     * @return int
     */
    public static function submitted_picture( $event_id ) {
        $att = (int) get_post_meta( (int) $event_id, SFAF_Submit::META_IMAGE, true );
        if ( ! $att || 'attachment' !== get_post_type( $att ) || ! SFAF_Uploads::holds( $att ) ) {
            return 0;
        }
        return $att;
    }

    /**
     * The event a submitted picture came with, or null.
     *
     * @param int $id
     * @return WP_Post|null
     */
    public static function submitted_with( $id ) {
        $id = (int) $id;
        /* The extras are a serialized list of ids, so the id is matched as the
         * list writes it, i:501; rather than as 501, which 1501 contains. */
        $asks = array(
            array( 'key' => SFAF_Submit::META_IMAGE, 'value' => (string) $id, 'compare' => '=' ),
            array( 'key' => SFAF_Submit::META_IMAGE_EXTRA, 'value' => 'i:' . $id . ';', 'compare' => 'LIKE' ),
        );
        foreach ( $asks as $ask ) {
            $found = get_posts( array(
                'post_type'      => 'uc_event',
                // Every status, the trash included: a rejected event is trashed.
                'post_status'    => array_keys( get_post_stati() ),
                'posts_per_page' => 1,
                'no_found_rows'  => true,
                'meta_query'     => array( $ask ),
            ) );
            if ( $found ) {
                return $found[0];
            }
        }
        return null;
    }

    /**
     * Tell websites@ about a picture uploaded from an event's own screen, once.
     *
     * Asked after every save of the event. Its picture is looked at: if it came
     * through "+ Upload a picture for this event" and nobody has been told, one
     * message goes, carrying the event, who uploaded it and when, the caladmin
     * link and the picture itself, and the picture is marked. A second save
     * with the same picture, or another event choosing it later, sends nothing.
     *
     * @param int $event_id
     * @return bool Whether a message went.
     */
    public static function notify_event_upload( $event_id ) {
        $event_id = (int) $event_id;
        $att      = (int) get_post_thumbnail_id( $event_id );
        if ( ! $att ) {
            return false;
        }
        $up = get_post_meta( $att, self::META_UPLOAD, true );
        if ( ! is_array( $up ) || get_post_meta( $att, self::META_UPLOAD_SENT, true ) ) {
            return false;
        }

        $who   = ! empty( $up['by'] ) ? get_userdata( (int) $up['by'] ) : null;
        $name  = $who ? (string) $who->display_name : 'Somebody';
        $email = $who ? (string) $who->user_email : '';
        $at    = ! empty( $up['at'] ) ? (int) $up['at'] : time();
        $title = get_the_title( $event_id ) ?: '(untitled)';
        $link  = SFAF_Portal::link( 'events/edit/' . $event_id );
        $src   = (string) wp_get_attachment_image_url( $att, 'large' );
        $path  = (string) get_attached_file( $att );
        $when  = sfaf_ap_datetime( $at );
        $by    = $name . ( '' !== $email ? ' (' . $email . ')' : '' );

        $body  = SFAF_Email::heading( 'Picture uploaded for an event' );
        $body .= SFAF_Email::details( array( 'Event' => $title, 'Uploaded by' => $by, 'When' => $when ) );
        if ( '' !== $src ) {
            $body .= '<p style="margin:0 0 14px 0;"><img src="' . esc_url( $src ) . '" alt="" width="540" style="display:block;width:100%;max-width:540px;height:auto;border:0;" /></p>';
        }
        $body .= SFAF_Email::button( $link, 'Open the event in caladmin' );
        $text  = "A picture was uploaded for an event.\n\nEvent: " . $title . "\nUploaded by: " . $by . "\nWhen: " . $when
            . "\n\nOpen the event in caladmin: " . $link . "\n\nThe picture is attached.";

        $sent = SFAF_Email::send(
            self::UPLOAD_NOTICE_TO,
            'Picture uploaded: ' . $title,
            SFAF_Email::shell( 'Picture uploaded for ' . $title, $body ),
            $text,
            '',
            ( '' !== $path ) ? array( $path ) : array()
        );
        if ( $sent ) {
            update_post_meta( $att, self::META_UPLOAD_SENT, time() );
        }
        return (bool) $sent;
    }

    public static function tags_of( $id ) {
        $terms = get_the_terms( (int) $id, self::TAXONOMY );
        return ( is_array( $terms ) ) ? $terms : array();
    }

    /**
     * Add one tag to several pictures.
     *
     * IT ADDS AND NEVER REPLACES, which is `$append = true` and is the whole of
     * the promise the control makes on screen. An image can be the picture for
     * two programmes, and a bulk action that quietly took the first one off
     * would be a way to lose work by pressing something labelled "add".
     *
     * THE FOLDER IS CHECKED PER ID, AT THE WRITE. The screen draws only calendar
     * images, and that is a render rather than a refusal: a POST is a list
     * anybody can construct. An id outside the folder, or one that is not an
     * image, is dropped rather than failing the run.
     *
     * @param int[] $ids
     * @param int   $term_id
     * @return array{did:int,refused:int}
     */
    public static function add_tag( $ids, $term_id ) {
        $term_id = (int) $term_id;
        $did     = 0;
        $refused = 0;

        $term = $term_id ? get_term( $term_id, self::TAXONOMY ) : null;
        if ( ! $term || is_wp_error( $term ) ) {
            return array( 'did' => 0, 'refused' => count( (array) $ids ) );
        }

        foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $id ) {
            if ( $id < 1 || 'attachment' !== get_post_type( $id ) || ! SFAF_Media_Folder::holds( $id ) ) {
                $refused++;
                continue;
            }
            $set = wp_set_object_terms( $id, array( $term_id ), self::TAXONOMY, true );
            if ( is_wp_error( $set ) ) {
                $refused++;
                continue;
            }
            $did++;
        }

        return array( 'did' => $did, 'refused' => $refused );
    }

    /* =====================================================================
     * The picker
     *
     * ONE PICKER, TWO FORMS, AND IT IS THE ONE THE STAFF FORM ALREADY HAD.
     * 3.67.0 built a self-built picture chooser on the staff request form: a
     * <details>, a list of radios, and a search box portal.js reveals. The
     * community form never had one at all, so somebody submitting an event
     * from outside SFAF could only send a file from their own computer. This
     * is that picker, moved here and given a series filter, and both forms
     * call it.
     *
     * IT IS NOT wp.media, DELIBERATELY. Neither public form has a logged-in
     * user, so there is no media library to open and no capability to open it
     * with. Radios in a <details> work with no script at all, which on a page
     * reached by a link on somebody's phone is the difference between a
     * control and a decoration.
     * ================================================================== */

    /**
     * The picture chooser.
     *
     * THE SERIES' OWN PICTURES COME FIRST AND EVERYTHING ELSE FOLLOWS, which
     * is the "way to see everything" without a toggle to press. A toggle would
     * need script to work and would hide half the library behind a control
     * somebody has to discover; two groups in one list are visible, searchable
     * and need nothing. An event with no series sees one ungrouped list, as
     * before.
     *
     * THE SERIES NAMES ARE PART OF WHAT THE SEARCH MATCHES, so typing a
     * programme name finds its pictures wherever they are in the list.
     *
     * @param array $args {
     *     @type string $name    The radio's field name.
     *     @type int    $chosen  Currently chosen attachment id.
     *     @type int    $series  Term id whose pictures lead, 0 for none.
     *     @type string $label   The closed trigger's label.
     *     @type int    $limit   How many to offer at all.
     * }
     */
    public static function picker( $args = array() ) {
        $args = array_merge( array(
            'name'   => 'image_id',
            'chosen' => 0,
            'series' => 0,
            'label'  => 'Choose a picture',
            'limit'  => 60,
            /* The series' own photo. Empty means ask SFAF_Series for it, which
             * is what a form with a FIXED series wants; a form where the series
             * is a question passes '' as well and lets portal.js overwrite the
             * row as somebody changes the select. */
            'series_thumb' => '',
            /* True where the series cannot change on this page, which is what
             * lets the server do the hiding on its own. See below. */
            'series_locked' => false,
            /*
             * A THIRD CASE, FOR THE EVENT EDITOR (3.94.0): the series cannot
             * change AND there has to be a way to see past it.
             *
             * Locked emits only that series' pictures, which is right on a
             * public form where there is nothing else on offer. In caladmin
             * the escape has to exist, so every row is written out with its
             * series on it and the script hides what does not match, exactly
             * as it does for the staff form's select. The difference is only
             * where the id comes from: an attribute rather than a control.
             *
             * Pass a term id. It is ignored unless series_locked is false,
             * because the two are answers to the same question.
             */
            'series_fixed' => 0,
            /* Renders the way past the filter. Only meaningful with
             * series_fixed, since without it nothing is filtered. */
            'show_all' => false,
            /*
             * THE EVENT EDITOR'S SERIES DROPDOWN DRIVES THE FILTER (3.107.0).
             * The attribute is emitted even for 0, so portal.js has a picker
             * to bind on Add event and narrows the moment a series is chosen,
             * with no save. See initFixedSeriesImageFilter().
             */
            'series_follow' => false,
            /* What the summary names when no row is chosen (3.108.1): the
               event editor passes the inherited series picture's name, or a
               picture held only as a URL. Empty is "No picture chosen". */
            'current_name' => '',
            /*
             * TWO GROUPS, FOR THE EVENT EDITOR (3.110.3): the chosen series'
             * pictures, then Other images. Pictures of other series are not
             * offered, and a submitted file never is. A search over the file
             * name and the name, newest first, and "Show active images only".
             * The public forms keep the list below.
             */
            'groups' => false,
        ), $args );

        if ( ! empty( $args['groups'] ) ) {
            self::grouped_picker( $args );
            return;
        }

        $chosen = (int) $args['chosen'];
        $series = (int) $args['series'];

        /* One query for the lot, then split, rather than two queries whose
         * overlap would have to be worked out afterwards. */
        $all  = self::pictures( array( 'per_page' => (int) $args['limit'] ) );
        $rows = self::rows( $all['ids'] );
        if ( empty( $rows ) ) {
            return;
        }

        /*
         * IT HIDES, IT DOES NOT GROUP (3.80.0).
         *
         * 3.76.0 built this as two groups, the series' pictures under a heading
         * and everything else under another. That was the wrong answer to the
         * question being asked. At the forty or fifty pictures this folder is
         * heading for, a list that still contains all of them after a series is
         * chosen is still a long list, and the heading only tells somebody
         * where to stop reading rather than saving them the reading.
         *
         * THE ARGUMENT AGAINST IS REAL AND WAS PUT: a filter nobody can escape
         * blocks the person who wants a picture tagged to another series. Mark
         * has weighed that and chosen hiding. The remedy for that case is the
         * message below and a note to MarCom, not a way back to the full list,
         * because a way back is the grouping again with an extra click on it.
         *
         * AN EVENT WITH NO SERIES SEES EVERYTHING, because there is nothing to
         * filter on. That is the $series === 0 path and it is unchanged.
         */
        $mine = array();
        foreach ( $rows as $row ) {
            if ( ! $series ) {
                continue;
            }
            foreach ( $row['tags'] as $term ) {
                if ( (int) $term->term_id === $series ) {
                    $mine[] = $row;
                    break;
                }
            }
        }

        $current = null;
        foreach ( $rows as $row ) {
            if ( $row['id'] === $chosen ) {
                $current = $row;
                break;
            }
        }

        /*
         * WHICH ROWS ARE WRITTEN INTO THE PAGE AT ALL, and the two forms differ
         * because their series differ in kind.
         *
         *   LOCKED (the community form). Its series is fixed by the URL and
         *   cannot change while somebody is on the page, so the server emits
         *   only that series' pictures. Nothing depends on a script, and there
         *   is no moment at which the list could be showing the wrong series.
         *
         *   NOT LOCKED (the staff form). Its series is a <select>. The server
         *   cannot know which one will be chosen, so every row is written out
         *   carrying the series it belongs to and portal.js hides what does not
         *   match. With no script the list is the full folder, which is longer
         *   and complete; nothing is hidden that a script has to come back and
         *   reveal. Same rule as the FAQ set peek and the reveal initialiser.
         *
         * THE WRONG WAY ROUND WOULD BE TO FILTER THE STAFF FORM ON THE SERVER
         * TOO, which would be correct on arrival and then quietly stale the
         * moment somebody changed the select with scripting off: a list that is
         * confidently showing the wrong series is worse than one showing all of
         * them.
         */
        $locked  = ! empty( $args['series_locked'] ) && $series;
        $offered = ( $locked && ! empty( $mine ) ) ? $mine : ( $locked ? array() : $rows );
        $none    = ( $series && empty( $mine ) );
        ?>
        <details class="uc-picker uc-image-picker" data-uc-image-picker<?php
            /* THE FIXED SERIES TRAVELS AS AN ATTRIBUTE, so the narrowing has
             * one implementation whether the id comes from a select or from
             * here. See initFixedSeriesImageFilter() in portal.js. */
            if ( ! $locked && ( (int) $args['series_fixed'] || ! empty( $args['series_follow'] ) ) ) {
                echo ' data-uc-image-series-fixed="' . (int) $args['series_fixed'] . '"';
            }
        ?>>
            <summary class="uc-picker-toggle">
                <span class="uc-picker-label"><?php echo esc_html( $args['label'] ); ?></span>
                <?php self::summary_row( $current, (string) $args['current_name'] ); ?>
                <span class="uc-disclosure-chevron" aria-hidden="true"><?php
                    echo sfaf_icon( 'chevron', array( 'size' => '16px' ) );
                ?></span>
            </summary>

            <div class="uc-picker-panel" data-uc-filter-scope>
                <label class="uc-picker-filter uc-image-search">
                    <span class="uc-visually-hidden">Search pictures</span>
                    <?php // No name, so it posts nothing. It narrows the list under it. ?>
                    <input type="search" placeholder="Search pictures&hellip;"
                           data-uc-filter autocomplete="off" />
                </label>

                <div class="uc-picker-options uc-image-options" data-uc-filter-list>
                    <?php
                    /*
                     * "NO PICTURE" IS NOT WHAT HAPPENS WHEN THE SERIES HAS ONE
                     * (3.76.0).
                     *
                     * An event with no picture of its own already falls back to
                     * its series' photo at display time, and this row is what
                     * says so. On the STAFF form portal.js has filled it in
                     * since 3.68.0, reading the photo off the series `<select>`
                     * as somebody changes it.
                     *
                     * THE COMMUNITY FORM HAS NO SUCH SELECT. Its series is
                     * fixed by the URL, so there is no option element to read a
                     * photo from, `initRequestSeriesImage()` returns at its
                     * first guard, and the row said "No picture chosen" on a
                     * series with a perfectly good default. **The form did not
                     * know about the series photo at all**, rather than knowing
                     * and showing the wrong state.
                     *
                     * SO THE SERVER FILLS IT IN, which is the right half to fix
                     * either way: a form whose series cannot change has nothing
                     * to wait for a script to tell it, and the staff form gets
                     * a correct first paint instead of a correct second one.
                     * The script still overrides on change and has to: there
                     * the series IS a question.
                     */
                    $fallback = ( $series && '' === $args['series_thumb'] )
                        ? SFAF_Series::image_url( $series, 'medium' )
                        : (string) $args['series_thumb'];
                    ?>
                    <label class="uc-check uc-picker-option uc-image-option" data-uc-filter-text="no picture"
                           data-uc-image-default>
                        <input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="0" <?php checked( 0, $chosen ); ?>
                               data-uc-image-option data-uc-image-name="<?php
                                   echo esc_attr( '' !== $fallback ? 'The series picture' : 'No picture' );
                               ?>" />
                        <?php if ( '' !== $fallback ) : ?>
                            <?php // Same box, carrying the photo as a background. See the 3.68.0 note in portal.css. ?>
                            <span class="uc-image-option-thumb uc-image-option-thumb-series" aria-hidden="true"
                                  style="background-image: url('<?php echo esc_url( $fallback ); ?>');"></span>
                            <span class="uc-image-option-text">
                                <span class="uc-image-option-name">The series picture</span>
                                <span class="uc-image-option-note">Used when you do not choose one</span>
                            </span>
                        <?php else : ?>
                            <span class="uc-image-option-thumb uc-image-option-blank" aria-hidden="true"></span>
                            <span class="uc-image-option-text">
                                <span class="uc-image-option-name">No picture</span>
                            </span>
                        <?php endif; ?>
                    </label>
                    <?php foreach ( $offered as $row ) : ?>
                        <?php
                        $names = array();
                        $ids   = array();
                        foreach ( $row['tags'] as $term ) {
                            $names[] = $term->name;
                            $ids[]   = (int) $term->term_id;
                        }
                        $hay = strtolower( trim( $row['file'] . ' ' . $row['title'] . ' ' . implode( ' ', $names ) ) );
                        ?>
                        <label class="uc-check uc-picker-option uc-image-option"
                               data-uc-filter-text="<?php echo esc_attr( $hay ); ?>"
                               <?php
                               /*
                                * THE SERIES THIS PICTURE BELONGS TO, ALWAYS
                                * EMITTED, INCLUDING WHEN IT BELONGS TO NONE.
                                *
                                * An empty attribute and an absent one have to
                                * mean the same thing here, so portal.js is
                                * reading a list rather than asking whether the
                                * attribute exists. A picture with no series is
                                * hidden by every series, which is the point:
                                * an untagged picture is not "for everybody",
                                * it is one nobody has filed yet.
                                *
                                * SPACE PADDED AT BOTH ENDS so a substring test
                                * cannot match 12 inside 121.
                                */
                               ?>
                               data-uc-image-series="<?php echo esc_attr( $ids ? ' ' . implode( ' ', $ids ) . ' ' : '' ); ?>">
                            <input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo (int) $row['id']; ?>"
                                   <?php checked( $row['id'], $chosen ); ?>
                                   data-uc-image-option
                                   data-uc-image-thumb="<?php echo esc_url( $row['thumb'] ); ?>"
                                   data-uc-image-full="<?php echo esc_url( $row['full'] ); ?>"
                                   data-uc-image-name="<?php echo esc_attr( '' !== $row['title'] ? $row['title'] : $row['file'] ); ?>" />
                            <img class="uc-image-option-thumb" src="<?php echo esc_url( $row['thumb'] ); ?>" alt="" loading="lazy" />
                            <span class="uc-image-option-text">
                                <span class="uc-image-option-name"><?php
                                    echo esc_html( '' !== $row['title'] ? $row['title'] : $row['file'] );
                                ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php
                /*
                 * NOTHING FOR THIS SERIES, SAID RATHER THAN FALLEN BACK FROM.
                 *
                 * A quiet fallback to the whole folder is indistinguishable
                 * from the filter not working, and it was reported as exactly
                 * that twice before the filter even existed. So the empty case
                 * gets a sentence, and the sentence says who to ask.
                 *
                 * THE "no picture" ROW ABOVE STAYS VISIBLE THROUGH THIS. It is
                 * what makes "they can still submit without an image" true, and
                 * it is not one of the folder's pictures, so no series filter
                 * has anything to say about it.
                 *
                 * Rendered hidden and revealed by portal.js where the series can
                 * change; rendered plainly where it cannot.
                 */
                ?>
                <p class="uc-muted uc-picker-no-series" data-uc-image-none<?php echo $none && $locked ? '' : ' hidden'; ?>>
                    No images are available for that series yet. Contact MarCom for an event image to be added.
                </p>
                <p class="uc-muted uc-picker-empty" data-uc-filter-empty hidden>No pictures match that.</p>
                <?php if ( ! empty( $args['show_all'] ) ) : ?>
                    <?php
                    /*
                     * THE WAY PAST THE SERIES FILTER.
                     *
                     * RENDERED VISIBLE AND HIDDEN BY THE SCRIPT, never the
                     * other way round. A control rendered with the `hidden`
                     * attribute and revealed by script is how the last one of
                     * these stayed
                     * invisible for sixteen releases: nothing ever removed the
                     * attribute. With no script at all the list is unfiltered
                     * anyway, so a button reading "show everything" on a list
                     * already showing everything is harmless, and is the
                     * honest no-script state.
                     */
                    ?>
                    <button type="button" class="uc-btn uc-btn-sm uc-picker-show-all" data-uc-image-show-all>All calendar images</button>
                <?php endif; ?>
            </div>
        </details>
        <?php
    }

    /**
     * The event editor's picker: series pictures, then Other images (3.110.3).
     *
     * EVERY SERIES PICTURE IS WRITTEN OUT, carrying its series, and the
     * script shows the ones in the series the dropdown names, which is how the
     * group follows the dropdown with no save. With no series chosen the group
     * is empty and says so. Other images are never narrowed by series.
     *
     * NOTHING FROM calendar-submissions/ IS EVER IN THIS LIST: the two queries
     * name their places, and a submitted file is in neither.
     *
     * @param array $args As picker().
     */
    private static function grouped_picker( $args ) {
        $chosen = (int) $args['chosen'];
        $series = (int) $args['series'];
        $limit  = max( (int) $args['limit'], 200 );
        $pics   = self::rows( self::pictures( array( 'place' => 'series', 'per_page' => $limit ) )['ids'] );
        $others = self::rows( self::pictures( array( 'place' => 'other', 'per_page' => $limit ) )['ids'] );
        $live   = self::active_ids();

        $current = null;
        foreach ( array_merge( $pics, $others ) as $row ) {
            if ( $row['id'] === $chosen ) {
                $current = $row;
                break;
            }
        }
        $fallback = ( $series && '' === $args['series_thumb'] )
            ? SFAF_Series::image_url( $series, 'medium' )
            : (string) $args['series_thumb'];
        ?>
        <details class="uc-picker uc-image-picker" data-uc-image-picker data-uc-image-groups
                 data-uc-image-series-fixed="<?php echo (int) $args['series_fixed']; ?>">
            <summary class="uc-picker-toggle">
                <span class="uc-picker-label"><?php echo esc_html( $args['label'] ); ?></span>
                <?php self::summary_row( $current, (string) $args['current_name'] ); ?>
                <span class="uc-disclosure-chevron" aria-hidden="true"><?php
                    echo sfaf_icon( 'chevron', array( 'size' => '16px' ) );
                ?></span>
            </summary>

            <div class="uc-picker-panel" data-uc-filter-scope>
                <div class="uc-image-tools">
                    <label class="uc-picker-filter uc-image-search">
                        <span class="uc-visually-hidden">Search pictures by name</span>
                        <?php // No name, so it posts nothing. It narrows the list under it. ?>
                        <input type="search" placeholder="Search by name&hellip;"
                               data-uc-filter data-uc-image-search autocomplete="off" />
                    </label>
                    <label class="uc-check uc-image-active-only">
                        <input type="checkbox" data-uc-image-active-only />
                        Show active images only
                    </label>
                </div>

                <div class="uc-picker-options uc-image-options" data-uc-filter-list>
                    <label class="uc-check uc-picker-option uc-image-option" data-uc-filter-text="no picture the series picture"
                           data-uc-image-default>
                        <input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="0" <?php checked( 0, $chosen ); ?>
                               data-uc-image-option data-uc-image-name="<?php
                                   echo esc_attr( '' !== $fallback ? 'The series picture' : 'No picture' );
                               ?>" />
                        <?php if ( '' !== $fallback ) : ?>
                            <span class="uc-image-option-thumb uc-image-option-thumb-series" aria-hidden="true"
                                  style="background-image: url('<?php echo esc_url( $fallback ); ?>');"></span>
                            <span class="uc-image-option-text">
                                <span class="uc-image-option-name">The series picture</span>
                                <span class="uc-image-option-note">Used when you do not choose one</span>
                            </span>
                        <?php else : ?>
                            <span class="uc-image-option-thumb uc-image-option-blank" aria-hidden="true"></span>
                            <span class="uc-image-option-text">
                                <span class="uc-image-option-name">No picture</span>
                            </span>
                        <?php endif; ?>
                    </label>

                    <p class="uc-image-group-head" data-uc-image-group-head="series">Series pictures</p>
                    <?php foreach ( $pics as $row ) : ?>
                        <?php
                        $ids = array();
                        foreach ( $row['tags'] as $term ) {
                            $ids[] = (int) $term->term_id;
                        }
                        self::option( $row, $args['name'], $chosen, isset( $live[ $row['id'] ] ),
                            ' data-uc-image-series="' . esc_attr( $ids ? ' ' . implode( ' ', $ids ) . ' ' : '' ) . '"' );
                        ?>
                    <?php endforeach; ?>
                    <p class="uc-muted uc-image-group-none" data-uc-image-choose<?php echo $series ? ' hidden' : ''; ?>>Choose a series to see its pictures.</p>
                    <p class="uc-muted uc-image-group-none" data-uc-image-none hidden>No pictures for this series yet.</p>

                    <p class="uc-image-group-head" data-uc-image-group-head="other">Other images</p>
                    <?php foreach ( $others as $row ) : ?>
                        <?php self::option( $row, $args['name'], $chosen, isset( $live[ $row['id'] ] ), ' data-uc-image-group="other"' ); ?>
                    <?php endforeach; ?>
                    <?php if ( empty( $others ) ) : ?>
                        <p class="uc-muted uc-image-group-none">No other images yet.</p>
                    <?php endif; ?>
                </div>
                <p class="uc-muted uc-picker-empty" data-uc-filter-empty hidden>No pictures match that.</p>
            </div>
        </details>
        <?php
    }

    /**
     * One picture as a radio row, in either picker.
     *
     * @param array  $row    From row().
     * @param string $name   The radio's name.
     * @param int    $chosen The chosen attachment id.
     * @param bool   $active In use by an upcoming published event.
     * @param string $attrs  Extra attributes, already escaped.
     */
    private static function option( $row, $name, $chosen, $active, $attrs ) {
        ?>
        <label class="uc-check uc-picker-option uc-image-option"
               data-uc-filter-text="<?php echo esc_attr( $row['search'] ); ?>"
               data-uc-image-active="<?php echo $active ? '1' : '0'; ?>"<?php echo $attrs; ?>>
            <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $row['id']; ?>"
                   <?php checked( $row['id'], $chosen ); ?>
                   data-uc-image-option
                   data-uc-image-thumb="<?php echo esc_url( $row['thumb'] ); ?>"
                   data-uc-image-full="<?php echo esc_url( $row['full'] ); ?>"
                   data-uc-image-name="<?php echo esc_attr( '' !== $row['title'] ? $row['title'] : $row['file'] ); ?>" />
            <img class="uc-image-option-thumb" src="<?php echo esc_url( $row['thumb'] ); ?>" alt="" loading="lazy" />
            <span class="uc-image-option-text">
                <span class="uc-image-option-name"><?php
                    echo esc_html( '' !== $row['title'] ? $row['title'] : $row['file'] );
                ?></span>
            </span>
        </label>
        <?php
    }

    /**
     * What the closed trigger says: the picture chosen, or that none is.
     *
     * @param array|null $row A row from row(), or null.
     */
    public static function summary_row( $row, $fallback_name = '' ) {
        ?>
        <span class="uc-picker-count uc-image-current" data-uc-image-current>
            <?php if ( $row ) : ?>
                <img class="uc-image-current-thumb" src="<?php echo esc_url( $row['thumb'] ); ?>" alt="" />
                <span class="uc-image-current-name"><?php
                    echo esc_html( '' !== $row['title'] ? $row['title'] : $row['file'] );
                ?></span>
            <?php else : ?>
                <span class="uc-image-current-name"><?php echo esc_html( '' !== (string) $fallback_name ? (string) $fallback_name : 'No picture chosen' ); ?></span>
            <?php endif; ?>
        </span>
        <?php
    }

    /**
     * Give one picture a name a person chose.
     *
     * WHY THIS EXISTS. Every picker in this plugin shows a picture's TITLE where
     * it has a real one and its FILE NAME where it does not, and `row()` decides
     * which through `looks_like_a_filename()`. An image uploaded without a title
     * gets one from WordPress made out of the file, which is not a name anybody
     * chose, so it is blanked and the file name shows instead. Correct, and
     * useless until somebody can type the real name somewhere.
     *
     * THE FILE DOES NOT MOVE AND THE ID DOES NOT CHANGE. A title is what a
     * picker shows; every event pointing at this attachment goes on pointing at
     * it, and nothing resolves a picture by its name.
     *
     * THE FOLDER IS CHECKED, so this cannot be used to rename an arbitrary
     * attachment somewhere else in the media library.
     *
     * @param int    $id
     * @param string $title Empty puts the row back to its file name.
     * @return bool
     */
    public static function rename( $id, $title ) {
        $id = (int) $id;
        if ( $id < 1 || 'attachment' !== get_post_type( $id ) || ! SFAF_Media_Folder::offers( $id ) ) {
            return false;
        }
        $clean = sanitize_text_field( (string) $title );
        $done  = wp_update_post( array( 'ID' => $id, 'post_title' => $clean ), true );
        if ( is_wp_error( $done ) ) {
            return false;
        }

        /*
         * AND THE MARK GOES ON OR COMES OFF WITH IT. A name typed here is
         * trusted by row() without being put through looks_like_a_filename(),
         * which is what makes "Cycle To Zero" stick on cycle-to-zero.jpg.
         * Clearing the box clears the mark, so emptying a name really does put
         * the row back to its file name rather than leaving an empty title
         * that is trusted.
         */
        if ( '' !== $clean ) {
            update_post_meta( $id, self::META_NAMED, 1 );
        } else {
            delete_post_meta( $id, self::META_NAMED );
        }
        return true;
    }

    /**
     * What a screen reader reads instead of this picture (3.81.0).
     *
     * A DIFFERENT JOB FROM THE NAME, WHICH IS WHY IT IS A SECOND FIELD AND NOT A
     * SECOND USE OF THE FIRST. The name is how somebody FINDS a picture in a
     * chooser, so it is written for the person picking it: "Cycle To Zero". Alt
     * text is what stands in for the picture when the picture is not there, so
     * it is written for the person who cannot see it: "three cyclists on a
     * coastal road". Somebody who cannot see the image is not helped by being
     * told the programme's name, which the page around it already says.
     *
     * SO IT IS NEVER FILLED IN FROM THE SERIES, and that is the one rule here
     * worth enforcing rather than just documenting. Auto-filling alt text with a
     * programme name would put a wrong description on every picture at once,
     * quietly, and wrong alt text is worse than none: a screen reader announces
     * it as though it were a description.
     *
     * WORDPRESS'S OWN KEY, `_wp_attachment_image_alt`, so a picture given alt
     * text here has it everywhere WordPress reads alt text, and one given it in
     * the Media Library arrives here already filled in. A key of our own would
     * have been a second answer to a question WordPress already answers.
     *
     * @param int    $id
     * @param string $alt
     * @return bool
     */
    public static function set_alt( $id, $alt ) {
        $id = (int) $id;
        if ( $id < 1 || 'attachment' !== get_post_type( $id ) || ! SFAF_Media_Folder::offers( $id ) ) {
            return false;
        }
        $clean = sanitize_text_field( (string) $alt );
        if ( '' === $clean ) {
            delete_post_meta( $id, '_wp_attachment_image_alt' );
        } else {
            update_post_meta( $id, '_wp_attachment_image_alt', $clean );
        }
        return true;
    }

    /**
     * Take one tag off one picture.
     *
     * THE IMAGE IS NEVER TOUCHED, only the relationship. This is the undo for
     * a tag applied to the wrong programme, and it is per image because a bulk
     * untag is a way to undo an afternoon's work with one press.
     *
     * @param int $id
     * @param int $term_id
     * @return bool
     */
    public static function remove_tag( $id, $term_id ) {
        $id      = (int) $id;
        $term_id = (int) $term_id;
        if ( $id < 1 || $term_id < 1 || ! SFAF_Media_Folder::holds( $id ) ) {
            return false;
        }
        $done = wp_remove_object_terms( $id, array( $term_id ), self::TAXONOMY );
        return ( true === $done );
    }
}
