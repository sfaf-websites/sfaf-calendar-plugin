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
                array(
                    'key'     => '_wp_attached_file',
                    'value'   => '^' . preg_quote( SFAF_Media_Folder::prefix() ),
                    'compare' => 'REGEXP',
                ),
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
        if ( '' !== $title && self::looks_like_a_filename( $title, $file ) ) {
            $title = '';
        }
        if ( '' === $file ) {
            $file = ( '' !== $title ) ? $title : 'Untitled picture';
        }

        return array(
            'id'    => $id,
            'thumb' => $thumb,
            'file'  => $file,
            'title' => $title,
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

        $mine   = array();
        $others = array();
        foreach ( $rows as $row ) {
            $tagged = false;
            if ( $series ) {
                foreach ( $row['tags'] as $term ) {
                    if ( (int) $term->term_id === $series ) {
                        $tagged = true;
                        break;
                    }
                }
            }
            if ( $tagged ) {
                $mine[] = $row;
            } else {
                $others[] = $row;
            }
        }

        $current = null;
        foreach ( $rows as $row ) {
            if ( $row['id'] === $chosen ) {
                $current = $row;
                break;
            }
        }

        $groups = array();
        if ( ! empty( $mine ) ) {
            $groups[] = array( 'head' => 'For this series', 'rows' => $mine );
            $groups[] = array( 'head' => 'Everything else', 'rows' => $others );
        } else {
            $groups[] = array( 'head' => '', 'rows' => $rows );
        }
        ?>
        <details class="uc-picker uc-image-picker" data-uc-image-picker>
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
                    <?php foreach ( $groups as $group ) : ?>
                        <?php if ( empty( $group['rows'] ) ) { continue; } ?>
                        <?php if ( '' !== $group['head'] ) : ?>
                            <?php /* A heading, not an option. It carries no filter text, so
                                     searching narrows to pictures and the headings go with
                                     the rows they head rather than floating over nothing. */ ?>
                            <p class="uc-picker-group-head"><?php echo esc_html( $group['head'] ); ?></p>
                        <?php endif; ?>
                        <?php foreach ( $group['rows'] as $row ) : ?>
                            <?php
                            $names = array();
                            foreach ( $row['tags'] as $term ) {
                                $names[] = $term->name;
                            }
                            $hay = strtolower( trim( $row['file'] . ' ' . $row['title'] . ' ' . implode( ' ', $names ) ) );
                            ?>
                            <label class="uc-check uc-picker-option uc-image-option"
                                   data-uc-filter-text="<?php echo esc_attr( $hay ); ?>">
                                <input type="radio" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo (int) $row['id']; ?>"
                                       <?php checked( $row['id'], $chosen ); ?>
                                       data-uc-image-option
                                       data-uc-image-thumb="<?php echo esc_url( $row['thumb'] ); ?>"
                                       data-uc-image-name="<?php echo esc_attr( '' !== $row['title'] ? $row['title'] : $row['file'] ); ?>" />
                                <img class="uc-image-option-thumb" src="<?php echo esc_url( $row['thumb'] ); ?>" alt="" loading="lazy" />
                                <span class="uc-image-option-text">
                                    <span class="uc-image-option-name"><?php
                                        echo esc_html( '' !== $row['title'] ? $row['title'] : $row['file'] );
                                    ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <p class="uc-muted uc-picker-empty" data-uc-filter-empty hidden>No pictures match that.</p>
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
        return ! is_wp_error( $done );
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
