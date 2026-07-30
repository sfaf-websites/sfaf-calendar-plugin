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

    /**
     * The set a series applies to each new occurrence, stored on the parent.
     *
     * This is the part that actually solves the problem, rather than a
     * dropdown that solves it only when somebody remembers to use it.
     */
    const SERIES_META = '_uc_series_default_faq_set';

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
            $a = sanitize_textarea_field( isset( $row['answer'] ) ? $row['answer'] : '' );
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
        $key     = sfaf_event_faq_meta_key( $post_id );
        $rows    = sfaf_normalize_faqs( get_post_meta( $post_id, $key, true ) );

        // A series child shows the inherited series FAQ too, unless it is
        // overriding. Saving "these FAQs" from such an event should mean what
        // is on screen, so the inherited rows come along.
        if ( '_uc_event_faq' === $key && ! sfaf_faq_is_override( $post_id ) ) {
            $rows = array_merge( sfaf_get_series_faq( $post_id ), $rows );
        }

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

        self::clear_series_default( $id );
        return true;
    }

    /** Forget a deleted set on any series that had chosen it as its default. */
    private static function clear_series_default( $set_id ) {
        $query = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => SFAF_Sources::all_statuses(),
            'posts_per_page'         => 500,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array( 'key' => self::SERIES_META, 'value' => (string) $set_id ),
            ),
        ) );

        foreach ( $query->posts as $post_id ) {
            delete_post_meta( $post_id, self::SERIES_META );
        }
    }

    /* ---------------------------------------------------------------------
     * Applying
     * ------------------------------------------------------------------- */

    /**
     * Copy a set's rows onto an event.
     *
     * THE THREE STORAGE CASES, all handled by sfaf_event_faq_meta_key():
     *
     *   STANDALONE event (and every imported event is one) -> _uc_series_faq.
     *       Rows land in the event's own block. Nothing else to think about.
     *
     *   SERIES PARENT -> _uc_series_faq, the shared block every occurrence in
     *       the series inherits at display time. Applying a set to the parent
     *       therefore reaches every occurrence at once, which is almost always
     *       what somebody applying a set to a series means.
     *
     *   SERIES CHILD -> _uc_event_faq, that one occurrence's extra questions.
     *       _uc_faq_override is DELIBERATELY NOT TOUCHED here. That flag
     *       decides whether the inherited series FAQ is shown above these rows
     *       or replaced by them, which is a display decision a person made,
     *       and silently flipping it while "applying a set" would change what
     *       every visitor sees on that page for reasons the manager never
     *       asked for. So a set applied to an occurrence adds to what is
     *       inherited unless the manager has already chosen to replace it, and
     *       the editor says so next to the control.
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
        $key      = sfaf_event_faq_meta_key( $post_id );
        $existing = sfaf_normalize_faqs( get_post_meta( $post_id, $key, true ) );

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
     * The set a series applies to each new occurrence, or ''.
     *
     * @param int $parent_id
     * @return string
     */
    public static function series_default( $parent_id ) {
        return (string) get_post_meta( (int) $parent_id, self::SERIES_META, true );
    }

    /**
     * Choose (or clear) the set a series applies to new occurrences.
     *
     * @param int    $parent_id
     * @param string $set_id '' to clear.
     */
    public static function set_series_default( $parent_id, $set_id ) {
        $parent_id = (int) $parent_id;
        $set_id    = trim( (string) $set_id );

        if ( '' === $set_id || ! self::get( $set_id ) ) {
            delete_post_meta( $parent_id, self::SERIES_META );
            return;
        }
        update_post_meta( $parent_id, self::SERIES_META, $set_id );
    }

    /**
     * Apply a series' default set to a newly created occurrence.
     *
     * Called from SFAF_Recurrence::create_child(), which is the only place a
     * new occurrence comes into existence. This is the whole point of the
     * feature: the manager should not have to remember to pick a set for the
     * fifty-second weekly occurrence.
     *
     * Only runs on an occurrence that has no FAQs of its own, so re-generating
     * a series never stacks the same questions up again. apply() would catch
     * duplicates anyway; this avoids the work entirely.
     *
     * @param int $child_id
     * @param int $parent_id
     * @return bool Whether anything was applied.
     */
    public static function apply_series_default( $child_id, $parent_id ) {
        $set_id = self::series_default( $parent_id );
        if ( '' === $set_id ) {
            return false;
        }
        if ( ! empty( sfaf_normalize_faqs( get_post_meta( (int) $child_id, '_uc_event_faq', true ) ) ) ) {
            return false;
        }

        $result = self::apply( $child_id, $set_id, 'append' );
        return ! is_wp_error( $result ) && $result['added'] > 0;
    }
}
