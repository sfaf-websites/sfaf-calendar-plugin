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
     * @param array $args {
     *     @type int    $series   Term id to filter by, 0 for all.
     *     @type bool   $untagged True for images carrying no series at all.
     *     @type int    $per_page 0 for everything.
     *     @type int    $paged
     * }
     * @return array{ids:int[],total:int,pages:int}
     */
    public static function pictures( $args = array() ) {
        $args = array_merge( array(
            'series'   => 0,
            'untagged' => false,
            'removed'  => false,
            'per_page' => self::PER_PAGE,
            'paged'    => 1,
        ), $args );

        $q = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => $args['per_page'] > 0 ? (int) $args['per_page'] : -1,
            'paged'          => max( 1, (int) $args['paged'] ),
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_wp_attached_file',
                    'value'   => '^' . preg_quote( SFAF_Media_Folder::prefix() ),
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
        return array(
            'ids'   => array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) ),
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
        );
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
        );
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
        ), $args );

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
            if ( ! $locked && (int) $args['series_fixed'] ) {
                echo ' data-uc-image-series-fixed="' . (int) $args['series_fixed'] . '"';
            }
        ?>>
            <summary class="uc-picker-toggle">
                <span class="uc-picker-label"><?php echo esc_html( $args['label'] ); ?></span>
                <?php self::summary_row( $current ); ?>
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
     * What the closed trigger says: the picture chosen, or that none is.
     *
     * @param array|null $row A row from row(), or null.
     */
    public static function summary_row( $row ) {
        ?>
        <span class="uc-picker-count uc-image-current" data-uc-image-current>
            <?php if ( $row ) : ?>
                <img class="uc-image-current-thumb" src="<?php echo esc_url( $row['thumb'] ); ?>" alt="" />
                <span class="uc-image-current-name"><?php
                    echo esc_html( '' !== $row['title'] ? $row['title'] : $row['file'] );
                ?></span>
            <?php else : ?>
                <span class="uc-image-current-name">No picture chosen</span>
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
        if ( $id < 1 || 'attachment' !== get_post_type( $id ) || ! SFAF_Media_Folder::holds( $id ) ) {
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
        if ( $id < 1 || 'attachment' !== get_post_type( $id ) || ! SFAF_Media_Folder::holds( $id ) ) {
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
