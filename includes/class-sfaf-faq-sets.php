<?php
/**
 * Saved, reusable FAQ sets.
 *
 * THE PROBLEM. A manager runs the same event every year, or twelve times a
 * year, and retypes the same eight questions each time: what to bring, where
 * to park, whether it is wheelchair accessible, what happens if it rains. The
 * answers are nearly identical and typing them again is both tedious and how
 * inconsistencies creep in.
 *
 * COPY, NOT LINK. Applying a set COPIES its rows onto the event. Editing the
 * set afterwards changes nothing that has already been applied.
 *
 * That is the important decision here and it is deliberate. A link would mean
 * one edit updating every event that ever used the set, which sounds like the
 * better feature and is the wrong one: the answers drift year to year (the
 * start time moves, the route changes, the venue closes), so a manager editing
 * "the 2027 parking answer" would silently rewrite the 2025 and 2026 events
 * that are still on the calendar as past events. Copying fails safe: the worst
 * case is having to fix two events instead of one, rather than rewriting
 * history nobody looked at.
 *
 * It also makes deletion safe. Deleting a set cannot affect a single event,
 * because no event refers to one.
 *
 * WHOLE SETS, NOT INDIVIDUAL ITEMS. A set is applied or it is not. Item-level
 * reuse ("insert just the parking question") is a bigger interface for a
 * smaller problem and can come later if it is genuinely wanted.
 *
 * SETS ARE BORN FROM EVENTS. There is no blank set-builder screen to find and
 * learn. A manager writes the FAQs on a real event, presses "Save these as a
 * set", and names it. The sets screen exists to rename, edit and delete them
 * afterwards, not to create them from nothing.
 *
 * WHAT A SET NEVER CONTAINS: a source_faq_id. Rows applied from a set are
 * manager-written by definition, so they never carry a platform ID, which is
 * exactly what keeps them out of reach of SFAF_Sources::sync_faqs(). Saving a
 * set FROM an event with imported rows copies the text of those rows but not
 * their IDs, so the copy becomes ordinary manager content wherever it lands.
 *
 * STORAGE. One option, not a post type: a set is a small list of strings with
 * no permalink, no revisions, no taxonomy and no author-level permissions of
 * its own, and a post type would have brought a menu entry and an editing
 * screen nobody needs. Autoload is off, since only the two FAQ screens read
 * it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_FAQ_Sets {

    /** Where the sets live. Autoload off: only the FAQ screens read them. */
    const OPTION = 'sfaf_faq_sets';

    /** Hard ceiling on rows in a set, so a runaway paste cannot bloat the option. */
    const MAX_ROWS = 50;

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /**
     * Every saved set, name-ordered.
     *
     * @return array[] Each array{id:string,name:string,rows:array,created:int,updated:int}
     */
    public static function all() {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $sets = array();
        foreach ( $raw as $id => $set ) {
            if ( ! is_array( $set ) ) {
                continue;
            }
            $sets[ (string) $id ] = array(
                'id'      => (string) $id,
                'name'    => isset( $set['name'] ) ? (string) $set['name'] : '(unnamed set)',
                'rows'    => self::clean_rows( isset( $set['rows'] ) ? $set['rows'] : array() ),
                'created' => isset( $set['created'] ) ? (int) $set['created'] : 0,
                'updated' => isset( $set['updated'] ) ? (int) $set['updated'] : 0,
            );
        }

        uasort( $sets, function ( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return $sets;
    }

    /**
     * One set by ID.
     *
     * @param string $id
     * @return array|null
     */
    public static function get( $id ) {
        $sets = self::all();
        $id   = (string) $id;
        return isset( $sets[ $id ] ) ? $sets[ $id ] : null;
    }

    /** How many sets exist. */
    public static function count() {
        return count( self::all() );
    }

    /**
     * Sanitize a list of rows down to question/answer pairs.
     *
     * Uses exactly the sanitizers the two FAQ editors use, so a row from a set
     * and a row typed by hand are byte-identical in storage and render the
     * same. Any source_faq_id on the way in is dropped rather than copied: see
     * the note at the top of this file.
     *
     * @param mixed $rows
     * @return array[]
     */
    public static function clean_rows( $rows ) {
        $out = array();
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $q = sanitize_text_field( isset( $row['question'] ) ? $row['question'] : '' );
            $a = SFAF_Rich_Text::sanitize( isset( $row['answer'] ) ? $row['answer'] : '' );
            if ( '' === $q && '' === $a ) {
                continue;
            }
            $out[] = array( 'question' => $q, 'answer' => $a );
            if ( count( $out ) >= self::MAX_ROWS ) {
                break;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------- */

    /**
     * Create or update a set.
     *
     * @param string $id   Existing ID, or '' to create one.
     * @param string $name
     * @param array  $rows
     * @return string|WP_Error The set ID.
     */
    public static function save( $id, $name, $rows ) {
        $name = trim( sanitize_text_field( $name ) );
        if ( '' === $name ) {
            return new WP_Error( 'sfaf_faq_set_no_name', 'Give the set a name so it can be found later.' );
        }

        $rows = self::clean_rows( $rows );
        if ( empty( $rows ) ) {
            return new WP_Error( 'sfaf_faq_set_empty', 'A set needs at least one question with something in it.' );
        }

        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }

        $id  = trim( (string) $id );
        $now = time();

        if ( '' === $id || ! isset( $raw[ $id ] ) ) {
            // Not uniqid(): a readable ID makes the option dump legible, and
            // the numeric suffix keeps two sets of the same name apart.
            $base = sanitize_key( $name );
            if ( '' === $base ) {
                $base = 'set';
            }
            $id = $base;
            $n  = 2;
            while ( isset( $raw[ $id ] ) ) {
                $id = $base . '-' . $n;
                $n++;
            }
            $raw[ $id ] = array( 'created' => $now );
        }

        $raw[ $id ]['name']    = $name;
        $raw[ $id ]['rows']    = $rows;
        $raw[ $id ]['updated'] = $now;
        if ( empty( $raw[ $id ]['created'] ) ) {
            $raw[ $id ]['created'] = $now;
        }

        update_option( self::OPTION, $raw, false );
        return $id;
    }

    /**
     * Save an event's current FAQ rows as a new set.
     *
     * Imported rows are included by their text but never by their ID, so what
     * comes out is a set of ordinary questions with no tie to any platform.
     *
     * @param int    $post_id
     * @param string $name
     * @return string|WP_Error
     */
    public static function create_from_event( $post_id, $name ) {
        $post_id = (int) $post_id;

        // What is on the event IS what is on screen. Nothing is inherited from
        // anywhere, so there is nothing to merge in before saving — see the
        // note above sfaf_faq_meta_key().
        $rows = sfaf_get_faqs( $post_id );

        if ( empty( $rows ) ) {
            return new WP_Error( 'sfaf_faq_set_nothing', 'This event has no FAQs to save yet.' );
        }

        return self::save( '', $name, $rows );
    }

    /**
     * Delete a set.
     *
     * SAFE BY CONSTRUCTION. Rows were copied when the set was applied, so no
     * event holds a reference to a set and nothing on the calendar changes.
     * The only thing that has to be cleaned up is any series still pointing at
     * it as its default for new occurrences.
     *
     * @param string $id
     * @return bool
     */
    public static function delete( $id ) {
        $raw = get_option( self::OPTION, array() );
        $id  = (string) $id;
        if ( ! is_array( $raw ) || ! isset( $raw[ $id ] ) ) {
            return false;
        }

        unset( $raw[ $id ] );
        update_option( self::OPTION, $raw, false );

        // The only thing that can still name a deleted set is a series holding
        // it as its default for new events. One term-meta query, not a sweep
        // over every event.
        SFAF_Series::clear_faq_set( $id );
        return true;
    }

    /* ---------------------------------------------------------------------
     * Applying
     * ------------------------------------------------------------------- */

    /**
     * Copy a set's rows onto an event.
     *
     * ONE DESTINATION. Rows land in the event's own FAQ block and there is
     * nowhere else they could go — which is the whole of what used to be three
     * storage cases and a note about not touching an override flag.
     *
     * IMPORTED ROWS ARE NEVER DISTURBED, in any mode. They stay at the front
     * in the source's order, because that is the order sync_faqs() will put
     * them back in on the next fetch anyway; applying a set in front of them
     * would just be undone. Even "replace" only replaces the manager's own
     * rows: it cannot delete a platform's, and if it did the next fetch would
     * restore them and the manager would think the button was broken.
     *
     * @param int    $post_id
     * @param string $set_id
     * @param string $mode 'append' (default) or 'replace'.
     * @return array|WP_Error {added:int, skipped:int, replaced:int, name:string}
     */
    public static function apply( $post_id, $set_id, $mode = 'append' ) {
        $post_id = (int) $post_id;
        $set     = self::get( $set_id );

        if ( ! $set ) {
            return new WP_Error( 'sfaf_faq_set_missing', 'That FAQ set no longer exists.' );
        }
        $post = get_post( $post_id );
        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return new WP_Error( 'sfaf_faq_set_not_event', 'That is not an event.' );
        }

        $replace  = ( 'replace' === $mode );
        $key      = sfaf_faq_meta_key();
        $existing = sfaf_get_faqs( $post_id );

        $imported = array();
        $manual   = array();
        foreach ( $existing as $row ) {
            if ( sfaf_faq_is_imported( $row ) ) {
                $imported[] = $row;
            } else {
                $manual[] = $row;
            }
        }

        $replaced = $replace ? count( $manual ) : 0;
        $keep     = $replace ? array() : $manual;

        // Questions already present are not added again. Without this,
        // pressing Apply twice, or a series default landing on an occurrence
        // that already has it, silently doubles the block.
        $seen = array();
        foreach ( array_merge( $imported, $keep ) as $row ) {
            $seen[ self::fingerprint( $row['question'] ) ] = true;
        }

        $added   = 0;
        $skipped = 0;
        foreach ( $set['rows'] as $row ) {
            $print = self::fingerprint( $row['question'] );
            if ( isset( $seen[ $print ] ) ) {
                $skipped++;
                continue;
            }
            $seen[ $print ] = true;
            $keep[]         = $row;
            $added++;
        }

        update_post_meta( $post_id, $key, array_merge( $imported, $keep ) );

        return array(
            'added'    => $added,
            'skipped'  => $skipped,
            'replaced' => $replaced,
            'name'     => $set['name'],
            'key'      => $key,
        );
    }

    /** Case- and space-insensitive question identity, for the duplicate check. */
    private static function fingerprint( $question ) {
        return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $question ) ) );
    }

    /* ---------------------------------------------------------------------
     * Series defaults
     * ------------------------------------------------------------------- */

    /**
     * The set a series applies to each event created into it, or ''.
     *
     * Stored on the series TERM, which is why this is a one-line delegation
     * rather than a meta read: a series is not a post any more, and this class
     * has no business knowing how a series stores anything.
     *
     * @param int $term_id
     * @return string
     */
    public static function series_default( $term_id ) {
        return SFAF_Series::default_faq_set( $term_id );
    }

    /* set_series_default() removed in 3.28.0: never called. series_default()
       above is the read side and IS used, so the pair was only ever half a
       pair.

       IT LEAVES SFAF_Series::update_faq_set() ORPHANED, which is worth knowing
       rather than assuming. This was a pass-through to it, and it was that
       method's only caller; the series editor saves the FAQ set through
       SFAF_Series::update(), which writes every field at once. So update_faq_set()
       is now uncalled too. It is NOT removed here, because it was not on the
       approved list for this build and a deletion nobody reviewed is how a
       one-line cleanup turns into a surprise. Flagged for the next pass. */

    /**
     * Apply a series' default set to an event just created into it.
     *
     * INHERITANCE-LIKE CONVENIENCE AT THE ONE MOMENT IT HELPS. The rows are
     * COPIED, at creation, and are then the event's own: the manager gets a
     * pre-filled block they can edit or clear, and the event is never tied to
     * something it may later need to differ from. That is the difference
     * between this and the inheritance that used to live in the display layer,
     * which meant editing a series rewrote what visitors saw on events that had
     * already happened.
     *
     * Only runs on an event with no FAQs of its own, so nothing ever stacks up
     * duplicates. apply() would catch them anyway; this avoids the work.
     *
     * @param int $post_id
     * @param int $term_id Series the event was created into.
     * @return bool Whether anything was applied.
     */
    public static function apply_series_default( $post_id, $term_id ) {
        $set_id = self::series_default( $term_id );
        if ( '' === $set_id ) {
            return false;
        }
        if ( ! empty( sfaf_get_faqs( $post_id ) ) ) {
            return false;
        }

        $result = self::apply( $post_id, $set_id, 'append' );
        return ! is_wp_error( $result ) && $result['added'] > 0;
    }
}
