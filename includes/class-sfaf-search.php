<?php
/**
 * Event search: one definition of "what searching an event looks through".
 *
 * THE PROBLEM. WordPress's own search covers post_title, post_content and
 * post_excerpt and nothing else. Most of an event is not in any of those: the
 * location is meta, the venue, organizer, series and category are taxonomy
 * terms, the FAQs are meta, and which platform an imported event came from is
 * meta. So searching for a venue, an organizer, a neighbourhood or a question
 * somebody answered in the FAQ found nothing, and the search looked broken
 * because it was answering a much narrower question than it appeared to.
 *
 * WHAT IS SEARCHED, and it is a WHITELIST rather than "everything except":
 *
 *   the post          title, description (post_content), excerpt
 *   meta              location, FAQ questions and answers
 *   taxonomy          category, organizer, venue, series, by term NAME
 *   imported events   the platform's display name, so "Eventbrite" and
 *                     "GoFundMe" both find their events even though what is
 *                     stored is the slug
 *
 * WHAT IS DELIBERATELY NOT SEARCHED, AND MUST NEVER BE.
 *
 * No attendee, subscriber or recipient data is reachable from here in any way:
 * not RSVP names, not RSVP email addresses, not the per-event notification
 * list, not the teams an event notifies, not the reply-to or organizer
 * address, not the confirmation email subject or body. Two separate things
 * keep that true. The obvious one is that the RSVP and reminder-log tables are
 * not in any query this builds. The one that will still hold in a year is that
 * META_KEYS is a list of what to look in, not a list of what to skip: a meta
 * key invented next year is unsearchable until somebody adds it here on
 * purpose, so nobody can make personal data searchable by forgetting about it.
 *
 * This is a search for EVENTS. Registrations are reached from the event they
 * belong to, which is the correct route and the only one.
 *
 * WHY NOT A CONCATENATED SEARCH-INDEX META KEY. That is the usual answer to
 * "meta LIKE across many keys is slow", and it was the right thing to weigh.
 * It was not taken because the slowness it avoids comes from meta_query with
 * several LIKE conditions, which WP_Query compiles into one JOIN against
 * postmeta PER CONDITION: six joins over the same table, each unindexed on
 * meta_value. What this does instead is one correlated EXISTS per source
 * (meta, terms, platform), each of which is an index seek on post_id and then
 * a scan of that post's own handful of rows. The cost is a few rows per
 * candidate post rather than a cross product, which at this calendar's size is
 * nothing.
 *
 * The index would also have brought a rebuild-on-save hook, a backfill for
 * existing events and a way for search results to be silently wrong whenever
 * the two got out of step. Reading the live data cannot go stale. If this
 * calendar ever reaches a size where the EXISTS scan matters, the index is
 * still the answer and this class is where it would go, with the same public
 * interface: see the note on where() for the number to watch.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Search {

    /**
     * The WP_Query argument this class answers to.
     *
     * Deliberately not 's'. WordPress adds its own title/content/excerpt WHERE
     * for 's', which would AND with this one and mean an event had to match
     * BOTH the narrow built-in search and the broad one, quietly making the
     * result set smaller than either. So the built-in is not used at all and
     * the post fields are covered here instead.
     */
    const QUERY_VAR = 'sfaf_search';

    /**
     * Meta keys a search looks inside. A WHITELIST. See the header.
     *
     * Anything holding an address, a name or a message body is absent on
     * purpose: _uc_organizer_email, _uc_email_replyto, _uc_email_subject,
     * _uc_email_body, _uc_notify_users, _uc_notify_emails, _uc_notify_teams.
     * Times are absent because nobody searches for "09:00" and it would only
     * add noise.
     */
    const META_KEYS = array(
        '_uc_location',
        '_uc_faqs',
    );

    /** Taxonomies searched by term name. */
    const TAXONOMIES = array(
        'uc_event_category',
        'uc_organizer',
        'uc_venue',
        'uc_series',
    );

    /**
     * The keys inside a stored FAQ row.
     *
     * FAQ rows live as one serialized array, so the LIKE that finds a question
     * also sees the array's own key names in the serialized text. Searching
     * for the word "answer" would otherwise match every event that has any
     * FAQ at all. These three words are therefore not looked for in that key,
     * which removes the only false positive the approach actually produces.
     */
    const FAQ_STRUCTURE_WORDS = array( 'question', 'answer', 'source_faq_id' );

    /** Most words honoured from one search box, so a paste cannot build a monster query. */
    const MAX_WORDS = 8;

    /** Shortest word worth searching for. */
    const MIN_WORD = 2;

    /**
     * Register the one filter that does the work.
     *
     * Global and always attached, but inert unless a query asks for it by
     * setting the query var. That is deliberately not the add_filter /
     * remove_filter dance around each call site: this runs on three surfaces
     * and a filter left attached by an early return would rewrite the WHERE of
     * every later query on the page.
     */
    public static function register() {
        add_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 10, 2 );
    }

    /**
     * Put a search onto a WP_Query argument array.
     *
     * @param array  $args   Query args, modified in place.
     * @param string $search Raw text from the box.
     */
    public static function apply( &$args, $search ) {
        $search = trim( (string) $search );
        if ( '' === $search ) {
            return;
        }
        $args[ self::QUERY_VAR ] = $search;
    }

    /**
     * Split a search box into words.
     *
     * ALL WORDS MUST MATCH, each of them anywhere in the event. "golden gate"
     * finds the event at Golden Gate Park whether the words are in the
     * location, the title or one in each. Requiring every word is what makes
     * adding a word narrow the results, which is the thing people expect a
     * search box to do.
     *
     * @param string $raw
     * @return string[]
     */
    public static function words( $raw ) {
        $raw   = trim( preg_replace( '/\s+/', ' ', (string) $raw ) );
        $words = array();
        foreach ( explode( ' ', $raw ) as $word ) {
            $word = trim( $word );
            // Deliberately mb_strlen: a two-character search in a language
            // with multibyte characters is still a two-character search.
            if ( function_exists( 'mb_strlen' ) ) {
                $len = mb_strlen( $word );
            } else {
                $len = strlen( $word );
            }
            if ( $len < self::MIN_WORD ) {
                continue;
            }
            if ( ! in_array( $word, $words, true ) ) {
                $words[] = $word;
            }
            if ( count( $words ) >= self::MAX_WORDS ) {
                break;
            }
        }
        return $words;
    }

    /**
     * The WHERE fragment for one search, or '' when there is nothing to add.
     *
     * SHAPE, AND WHERE THE COST IS. One AND per word; inside each word, one OR
     * across the places it could be. The post columns are a plain LIKE on the
     * row already being examined. The other three are correlated EXISTS
     * subqueries, each of which starts from an index on post_id or object_id
     * and looks at that post's own rows only, and each of which stops at the
     * first hit rather than counting.
     *
     * THE NUMBER TO WATCH is the count of candidate posts the WHERE has to be
     * evaluated against, not the size of postmeta. Status, date and taxonomy
     * conditions are applied by WP_Query in the same WHERE, so on the public
     * calendar that candidate set is already only published upcoming events.
     * If that set ever reaches tens of thousands, replace the two EXISTS
     * clauses with a single LIKE against a concatenated index meta key built
     * on save. Nothing outside this method would have to change.
     *
     * @param string[] $words
     * @return string
     */
    public static function where( $words ) {
        global $wpdb;

        if ( empty( $words ) ) {
            return '';
        }

        $posts = $wpdb->posts;
        $sql   = '';

        foreach ( $words as $word ) {
            $like = '%' . $wpdb->esc_like( $word ) . '%';
            $ors  = array();

            // The post itself: title, description, excerpt.
            $ors[] = $wpdb->prepare(
                "{$posts}.post_title LIKE %s OR {$posts}.post_content LIKE %s OR {$posts}.post_excerpt LIKE %s",
                $like, $like, $like
            );

            // Meta, from the whitelist. FAQ structure words are skipped for
            // the FAQ key only: see FAQ_STRUCTURE_WORDS.
            $keys = self::META_KEYS;
            if ( in_array( strtolower( $word ), self::FAQ_STRUCTURE_WORDS, true ) ) {
                $keys = array_values( array_diff( $keys, array( '_uc_faqs' ) ) );
            }
            if ( ! empty( $keys ) ) {
                $placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
                $ors[] = $wpdb->prepare(
                    "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} sfm"
                    . " WHERE sfm.post_id = {$posts}.ID"
                    . " AND sfm.meta_key IN ( {$placeholders} )"
                    . " AND sfm.meta_value LIKE %s )",
                    array_merge( $keys, array( $like ) )
                );
            }

            // Taxonomy term names: category, organizer, venue, series.
            $tax_placeholders = implode( ', ', array_fill( 0, count( self::TAXONOMIES ), '%s' ) );
            $ors[] = $wpdb->prepare(
                "EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} sftr"
                . " INNER JOIN {$wpdb->term_taxonomy} sftt ON sftt.term_taxonomy_id = sftr.term_taxonomy_id"
                . " INNER JOIN {$wpdb->terms} sft ON sft.term_id = sftt.term_id"
                . " WHERE sftr.object_id = {$posts}.ID"
                . " AND sftt.taxonomy IN ( {$tax_placeholders} )"
                . " AND sft.name LIKE %s )",
                array_merge( self::TAXONOMIES, array( $like ) )
            );

            /*
             * Imported events, by the platform's NAME.
             *
             * What is stored is the adapter slug, so "gfmp" would be the only
             * thing a LIKE on the meta could find, and nobody searches for
             * that. The word is matched against each adapter's display name
             * here, in PHP, and the slugs that matched go into the query. So
             * "GoFundMe", "gofundme pro" and "Eventbrite" all work while the
             * stored value stays exactly as the importer wrote it.
             */
            $slugs = self::source_slugs_matching( $word );
            if ( ! empty( $slugs ) ) {
                $slug_placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );
                $ors[] = $wpdb->prepare(
                    "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} sfs"
                    . " WHERE sfs.post_id = {$posts}.ID"
                    . " AND sfs.meta_key = %s"
                    . " AND sfs.meta_value IN ( {$slug_placeholders} ) )",
                    array_merge( array( SFAF_Sources::META_SOURCE ), $slugs )
                );
            }

            $sql .= ' AND ( ' . implode( ' OR ', $ors ) . ' ) ';
        }

        return $sql;
    }

    /**
     * Adapter slugs whose display name or slug contains this word.
     *
     * @param string $word
     * @return string[]
     */
    private static function source_slugs_matching( $word ) {
        if ( ! class_exists( 'SFAF_Sources' ) ) {
            return array();
        }

        $out = array();
        foreach ( SFAF_Sources::adapters() as $slug => $adapter ) {
            $label = (string) $adapter->label();
            if ( false !== stripos( $label, $word ) || false !== stripos( (string) $slug, $word ) ) {
                $out[] = (string) $slug;
            }
        }
        return $out;
    }

    /**
     * The posts_clauses filter. Inert unless the query asked for a search.
     *
     * @param array    $clauses
     * @param WP_Query $query
     * @return array
     */
    public static function clauses( $clauses, $query ) {
        if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
            return $clauses;
        }

        $raw = $query->get( self::QUERY_VAR );
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return $clauses;
        }

        $where = self::where( self::words( $raw ) );
        if ( '' === $where ) {
            return $clauses;
        }

        /*
         * APPENDED, NEVER REPLACED.
         *
         * Everything already in this WHERE is a restriction somebody else put
         * there: the post type, the post status, the date window that makes a
         * view "upcoming" or "archived", the author restriction that keeps a
         * contributor to their own events, and any taxonomy filter. Adding to
         * it can only ever narrow the result. That is what makes it impossible
         * for a search to widen its way into an unpublished event: search does
         * not choose which events are eligible, it filters the ones that
         * already were.
         */
        $clauses['where'] .= $where;

        // EXISTS returns no duplicate rows, unlike a JOIN, so no DISTINCT or
        // GROUP BY is needed and none is added. Paging counts stay correct.
        return $clauses;
    }
}
