<?php
/**
 * Third-party event sources — the import framework.
 *
 * PART A: fetching into a review queue. A manual "Fetch updates" run asks every
 * activated source for its events, and anything genuinely new lands in the
 * Pending queue for a manager to publish or dismiss. Nothing here updates an
 * event that has already been imported, unpublishes one that vanished at the
 * source, or expires anything — that is Part B, deliberately left out so this
 * half can be tested on its own.
 *
 * THE SHAPE OF IT
 * ---------------------------------------------------------------------------
 * A source is an adapter: a small class that knows how to talk to one platform
 * and nothing else. Eventbrite is the first, GoFundMe Pro and EveryAction are
 * expected to follow, and the framework does not know or care which is which —
 * it walks the registry, calls fetch() then normalize() on each adapter, and
 * imports whatever comes back in the common shape. Adding a platform means
 * writing an adapter and registering it; no file in here changes.
 *
 * THE COMMON EVENT SHAPE — what normalize() must return:
 *
 *     array(
 *         'external_source' => 'eventbrite',   // required, the adapter's slug
 *         'external_id'     => '1234567890',   // required, stable at the source
 *         'title'           => 'Event name',   // required
 *         'description'     => '…',            // plain text or safe HTML
 *         'start_date'      => '2026-08-15',   // Y-m-d, required
 *         'start_time'      => '18:00',        // H:i, may be ''
 *         'end_date'        => '2026-08-15',   // Y-m-d, may be ''
 *         'end_time'        => '21:00',        // H:i, may be ''
 *         'timezone'        => 'America/Los_Angeles',
 *         'location'        => 'Venue, 123 Main St, San Francisco, CA',
 *         'source_url'      => 'https://…',    // the page on the platform
 *         'image_url'       => 'https://…',
 *     )
 *
 * The date/time split matches the meta this calendar already stores
 * (_uc_event_date + _uc_start_time), so an imported event is an ordinary
 * uc_event from the moment it exists and every existing screen can read it.
 *
 * WHAT COMES FROM WHERE. The platform owns the title, description, times,
 * location, image and its own URL. The category, organizer and series are
 * local decisions and are never guessed at import — a manager assigns them
 * when publishing, which is why Publish opens the event rather than pushing it
 * straight to the calendar.
 *
 * NOT LIVE UNTIL PUBLISHED. Imported events are uc_event posts in one of two
 * custom statuses, uc_imported and uc_dismissed. Every public query in this
 * plugin asks for post_status 'publish' by name — the shortcodes, the embed
 * (which renders through the shortcode class), the REST feed, the .ics
 * endpoint, the series listings — so an event in either custom status is
 * excluded everywhere by construction, not by a filter someone has to
 * remember to add. Both statuses are registered non-public, non-queryable and
 * protected, so a direct URL to one 404s for anybody without edit rights.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * What every source adapter must provide.
 *
 * An abstract class rather than an interface so a later adapter can inherit
 * shared behaviour if this grows one.
 */
abstract class SFAF_Source_Adapter {

    /** Machine name, stored on every event this adapter imports. */
    abstract public function slug();

    /** Human name, shown on badges and in the fetch report. */
    abstract public function label();

    /**
     * Whether this source is configured well enough to be worth calling.
     *
     * An inactive adapter is skipped silently by run_all() — an unconfigured
     * platform is not an error worth reporting on every fetch.
     *
     * @return bool
     */
    abstract public function is_active();

    /**
     * Why this source is not active, for the fetch report.
     *
     * Concrete rather than abstract so an adapter need not implement it, but
     * every adapter should: "GoFundMe Pro: not connected" is far less use than
     * "GoFundMe Pro: no Organization ID stored".
     *
     * @return string
     */
    public function inactive_reason() {
        return 'not configured';
    }

    /**
     * Pull everything this source has to offer.
     *
     * @return array|WP_Error {
     *     @type array $items Raw items, each later passed to normalize().
     *     @type array $notes Human-readable warnings — anything incomplete.
     * }
     */
    abstract public function fetch();

    /**
     * Map one raw item to the common event shape.
     *
     * @param mixed $item One entry from fetch()'s items.
     * @return array|null The common shape, or null to skip this item.
     */
    abstract public function normalize( $item );

    /**
     * Re-fetch a single item by its ID at the source.
     *
     * Backs the per-event "Refresh from source" button. Concrete rather than
     * abstract so an adapter that has no single-item endpoint simply inherits
     * "not supported" instead of being forced to fake one.
     *
     * @param string $external_id
     * @return array|WP_Error|null Raw item, an error, or null when unsupported.
     */
    public function fetch_one( $external_id ) {
        return null;
    }
}

class SFAF_Sources {

    /** Fetched, awaiting a manager's decision. */
    const STATUS_PENDING = 'uc_imported';

    /** Dismissed — kept, not deleted, and never re-imported. */
    const STATUS_DISMISSED = 'uc_dismissed';

    /* Meta written on every imported event. Part B and the click-out /
     * edit-on-source features read these. */
    const META_SOURCE      = '_uc_external_source';
    const META_EXTERNAL_ID = '_uc_external_id';
    const META_SOURCE_URL  = '_uc_source_url';
    const META_IMAGE       = '_uc_external_image';
    const META_TIMEZONE    = '_uc_external_timezone';
    const META_IMPORTED_AT = '_uc_imported_at';

    /* Part B: refresh and removal bookkeeping. */
    const META_UPDATED_AT  = '_uc_source_updated_at';
    const META_REMOVED_AT  = '_uc_source_removed_at';
    const META_REMOVED_WHY = '_uc_source_removed_reason';

    /**
     * Where an event goes when it has disappeared at the source.
     *
     * `draft` rather than a new status, and rather than uc_dismissed:
     *
     *   - It is excluded from every public query already. Every public path in
     *     this plugin names post_status 'publish' by hand — shortcodes, embed,
     *     REST feed, .ics, series listings, RSVP guards — so a draft is off the
     *     calendar the moment it is set, with no new status to teach them.
     *   - It stays findable and restorable: the portal's Events list includes
     *     drafts by default, so it is visible where a manager already looks,
     *     and publishing it again is one click.
     *   - uc_dismissed would have been wrong. That means "a manager rejected
     *     this", and events in it are deliberately never re-imported or
     *     updated. Reusing it would have destroyed the distinction between a
     *     human decision and a disappearance at the source.
     *
     * META_REMOVED_AT / META_REMOVED_WHY are what tell it apart from an
     * ordinary human draft.
     */
    const STATUS_REMOVED = 'draft';

    /**
     * Statuses an update is allowed to touch.
     *
     * uc_dismissed and trash are deliberately absent: a dismissed event is a
     * decision a person made, and neither it nor a trashed one should be
     * revived, rewritten or resurrected by a fetch.
     *
     * @return string[]
     */
    public static function updatable_statuses() {
        return array( self::STATUS_PENDING, 'publish', 'pending', 'draft', 'future' );
    }

    /**
     * Statuses an event can be REMOVED from when it vanishes at the source.
     *
     * Only things that are live or awaiting review. A draft is already off the
     * calendar (and may be one of ours from a previous removal), and dismissed
     * and trashed events stay exactly where they are.
     *
     * @return string[]
     */
    public static function removable_statuses() {
        return array( 'publish', 'future', 'pending', self::STATUS_PENDING );
    }

    /** Adapters registered in code. */
    private static $adapters = array();

    /**
     * Register the custom statuses.
     *
     * Must run on `init`, which is where sfaf_init() calls it.
     */
    public function register() {
        self::register_statuses();
    }

    public static function register_statuses() {
        register_post_status( self::STATUS_PENDING, array(
            'label'                     => 'Imported — pending review',
            'public'                    => false,
            'publicly_queryable'        => false,
            'internal'                  => false,
            'private'                   => false,
            'protected'                 => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => false,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Imported <span class="count">(%s)</span>',
                'Imported <span class="count">(%s)</span>'
            ),
        ) );

        register_post_status( self::STATUS_DISMISSED, array(
            'label'                     => 'Dismissed',
            'public'                    => false,
            'publicly_queryable'        => false,
            'internal'                  => false,
            'private'                   => false,
            'protected'                 => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => false,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Dismissed <span class="count">(%s)</span>',
                'Dismissed <span class="count">(%s)</span>'
            ),
        ) );
    }

    /* ---------------------------------------------------------------------
     * The registry
     * ------------------------------------------------------------------- */

    /**
     * Add an adapter.
     *
     * @param SFAF_Source_Adapter $adapter
     */
    public static function register_adapter( $adapter ) {
        if ( $adapter instanceof SFAF_Source_Adapter ) {
            self::$adapters[ $adapter->slug() ] = $adapter;
        }
    }

    /**
     * Every registered adapter.
     *
     * The filter is the seam for adapters that live outside this plugin:
     *
     *     add_filter( 'sfaf_source_adapters', function ( $adapters ) {
     *         $adapters['everyaction'] = new My_EveryAction_Adapter();
     *         return $adapters;
     *     } );
     *
     * @return SFAF_Source_Adapter[] Keyed by slug.
     */
    public static function adapters() {
        $adapters = apply_filters( 'sfaf_source_adapters', self::$adapters );
        if ( ! is_array( $adapters ) ) {
            return array();
        }

        // A filter can return anything; only real adapters get called.
        $valid = array();
        foreach ( $adapters as $adapter ) {
            if ( $adapter instanceof SFAF_Source_Adapter ) {
                $valid[ $adapter->slug() ] = $adapter;
            }
        }
        return $valid;
    }

    /** Adapters that are configured enough to call. */
    public static function active_adapters() {
        $active = array();
        foreach ( self::adapters() as $slug => $adapter ) {
            if ( $adapter->is_active() ) {
                $active[ $slug ] = $adapter;
            }
        }
        return $active;
    }

    /** One adapter by slug, or null. */
    public static function adapter( $slug ) {
        $adapters = self::adapters();
        return isset( $adapters[ $slug ] ) ? $adapters[ $slug ] : null;
    }

    /** An adapter's display name, falling back to the stored slug. */
    public static function source_label( $slug ) {
        $adapter = self::adapter( $slug );
        return $adapter ? $adapter->label() : (string) $slug;
    }

    /* ---------------------------------------------------------------------
     * Matching — the thing that stops duplicates
     * ------------------------------------------------------------------- */

    /**
     * Every post status an imported event could be sitting in.
     *
     * Spelled out rather than using WP_Query's 'any', which silently omits any
     * status registered with exclude_from_search — which both of ours are.
     * Using 'any' here would miss every pending and dismissed event and
     * re-import the lot on the next fetch.
     *
     * Trash is included deliberately: an event a manager threw away should not
     * come back on the next run either.
     *
     * @return string[]
     */
    public static function all_statuses() {
        return array(
            'publish', 'pending', 'draft', 'future', 'private', 'trash',
            self::STATUS_PENDING, self::STATUS_DISMISSED,
        );
    }

    /**
     * Find an already-known event by its identity at the source.
     *
     * @param string $source      Adapter slug.
     * @param string $external_id ID at the source.
     * @return int Post ID, or 0 when this event is new.
     */
    public static function find_existing( $source, $external_id ) {
        $source      = (string) $source;
        $external_id = (string) $external_id;

        if ( '' === $source || '' === $external_id ) {
            return 0;
        }

        $query = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => self::all_statuses(),
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'relation' => 'AND',
                array( 'key' => self::META_SOURCE, 'value' => $source ),
                array( 'key' => self::META_EXTERNAL_ID, 'value' => $external_id ),
            ),
        ) );

        return empty( $query->posts ) ? 0 : (int) $query->posts[0];
    }

    /* ---------------------------------------------------------------------
     * Importing
     * ------------------------------------------------------------------- */

    /**
     * Create one event in the pending queue.
     *
     * The caller has already established this event is new; this does not
     * check again.
     *
     * @param array $event Common event shape.
     * @return int|WP_Error New post ID.
     */
    public static function import_event( $event ) {
        $title = isset( $event['title'] ) ? trim( (string) $event['title'] ) : '';
        if ( '' === $title ) {
            $title = '(untitled imported event)';
        }

        $description = isset( $event['description'] ) ? (string) $event['description'] : '';

        $post_id = wp_insert_post( array(
            'post_type'    => 'uc_event',
            'post_status'  => self::STATUS_PENDING,
            'post_title'   => $title,
            'post_content' => wp_kses_post( $description ),
            'post_author'  => get_current_user_id(),
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Platform-owned fields, written to the meta keys this calendar
        // already uses so every existing screen reads an imported event
        // without knowing it was imported.
        $meta = array(
            '_uc_event_date' => isset( $event['start_date'] ) ? (string) $event['start_date'] : '',
            '_uc_start_time' => isset( $event['start_time'] ) ? (string) $event['start_time'] : '',
            '_uc_end_time'   => isset( $event['end_time'] ) ? (string) $event['end_time'] : '',
            '_uc_end_date'   => isset( $event['end_date'] ) ? (string) $event['end_date'] : '',
            '_uc_location'   => isset( $event['location'] ) ? (string) $event['location'] : '',
        );
        foreach ( $meta as $key => $value ) {
            if ( '' !== $value ) {
                update_post_meta( $post_id, $key, $value );
            }
        }

        // Provenance. These are what Part B matches on and what the
        // click-out / edit-on-source links use.
        update_post_meta( $post_id, self::META_SOURCE, (string) $event['external_source'] );
        update_post_meta( $post_id, self::META_EXTERNAL_ID, (string) $event['external_id'] );
        update_post_meta( $post_id, self::META_IMPORTED_AT, time() );

        if ( ! empty( $event['source_url'] ) ) {
            update_post_meta( $post_id, self::META_SOURCE_URL, esc_url_raw( (string) $event['source_url'] ) );
        }
        if ( ! empty( $event['image_url'] ) ) {
            // The source's image goes in its OWN key and nowhere else.
            //
            // _uc_image_url is the manual override — it is what the "Or enter
            // image URL" field on the event form writes, and a fetch must
            // never touch it. sfaf_event_image_url() reads the manual one
            // first and falls through to this, so a pasted banner always wins
            // and clearing it reveals whatever the source currently has.
            //
            // (2.6.0 wrote both to the same key, which meant a refetch would
            // have overwritten a manager's image. migrate_image_split()
            // untangles the events created that way.)
            //
            // A URL, not a media-library attachment: third-party images stay
            // remote by design.
            update_post_meta( $post_id, self::META_IMAGE, esc_url_raw( (string) $event['image_url'] ) );
        }
        if ( ! empty( $event['timezone'] ) ) {
            update_post_meta( $post_id, self::META_TIMEZONE, sanitize_text_field( (string) $event['timezone'] ) );
        }

        // Platform-specific extras, written to meta this calendar already
        // understands — the GoFundMe Pro adapter uses this to fill in
        // _uc_gofundme_url and _uc_gofundme_goal so the donate block picks an
        // imported campaign up with no special-casing anywhere.
        //
        // Restricted to _uc_-prefixed keys: an adapter describes an event, it
        // does not get to write arbitrary post meta.
        if ( ! empty( $event['meta'] ) && is_array( $event['meta'] ) ) {
            foreach ( $event['meta'] as $meta_key => $meta_value ) {
                $meta_key = (string) $meta_key;
                if ( 0 !== strpos( $meta_key, '_uc_' ) || ! is_scalar( $meta_value ) ) {
                    continue;
                }
                $meta_value = (string) $meta_value;
                if ( '' === $meta_value ) {
                    continue;
                }
                update_post_meta(
                    $post_id,
                    $meta_key,
                    ( false !== strpos( $meta_key, '_url' ) ) ? esc_url_raw( $meta_value ) : sanitize_text_field( $meta_value )
                );
            }
        }

        return (int) $post_id;
    }

    /**
     * Refresh an already-imported event from the source.
     *
     * WHAT THIS TOUCHES — the platform's fields, and only when the source
     * actually supplied a value:
     *
     *   title, description, date, start/end time, end date, location,
     *   timezone, source URL, the source image, and adapter meta extras.
     *
     * WHAT IT NEVER TOUCHES — every local decision:
     *
     *   post_status (an update NEVER changes whether an event is live),
     *   category, organizer, series, FAQs, RSVP settings, capacity, the
     *   featured image, and _uc_image_url — the manual image override.
     *
     * EMPTY IN, LEAVE ALONE. A blank value from the source is treated as
     * "nothing to say", not as "clear this". Most GoFundMe Pro campaigns have
     * no date and a manager fills one in at approval; writing the source's
     * empty date back on the next fetch would erase that work. The same
     * applies to description and location.
     *
     * @param int   $post_id
     * @param array $event Common event shape.
     * @return array|WP_Error {changed: array<string,array{from:string,to:string}>}
     */
    public static function update_event( $post_id, $event ) {
        $post_id = (int) $post_id;
        $post    = get_post( $post_id );

        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return new WP_Error( 'sfaf_sources_not_event', 'That post is not an event.' );
        }
        if ( ! in_array( $post->post_status, self::updatable_statuses(), true ) ) {
            return new WP_Error(
                'sfaf_sources_not_updatable',
                sprintf( 'Events in the "%s" state are left alone by a refresh.', $post->post_status )
            );
        }

        $changed = array();

        /* ---- Post fields. post_status is deliberately not among them. ---- */
        $postarr = array( 'ID' => $post_id );

        $title = isset( $event['title'] ) ? trim( (string) $event['title'] ) : '';
        if ( '' !== $title && $title !== $post->post_title ) {
            $postarr['post_title'] = $title;
            $changed['Title']      = array( 'from' => $post->post_title, 'to' => $title );
        }

        $description = isset( $event['description'] ) ? (string) $event['description'] : '';
        if ( '' !== trim( $description ) ) {
            $new_content = wp_kses_post( $description );
            if ( $new_content !== $post->post_content ) {
                $postarr['post_content'] = $new_content;
                $changed['Description']  = array(
                    'from' => self::excerpt( $post->post_content ),
                    'to'   => self::excerpt( $new_content ),
                );
            }
        }

        if ( count( $postarr ) > 1 ) {
            wp_update_post( $postarr );
        }

        /* ---- Platform meta, each skipped when the source said nothing. ---- */
        $meta_map = array(
            '_uc_event_date'    => array( 'key' => 'start_date', 'label' => 'Date' ),
            '_uc_start_time'    => array( 'key' => 'start_time', 'label' => 'Start time' ),
            '_uc_end_time'      => array( 'key' => 'end_time',   'label' => 'End time' ),
            '_uc_end_date'      => array( 'key' => 'end_date',   'label' => 'End date' ),
            '_uc_location'      => array( 'key' => 'location',   'label' => 'Location' ),
            self::META_TIMEZONE => array( 'key' => 'timezone',   'label' => 'Timezone' ),
        );
        foreach ( $meta_map as $meta_key => $spec ) {
            $value = isset( $event[ $spec['key'] ] ) ? trim( (string) $event[ $spec['key'] ] ) : '';
            if ( '' === $value ) {
                continue; // empty in, leave alone
            }
            $current = (string) get_post_meta( $post_id, $meta_key, true );
            if ( $current === $value ) {
                continue;
            }
            update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
            $changed[ $spec['label'] ] = array( 'from' => $current, 'to' => $value );
        }

        /* ---- URLs. ---- */
        $url_map = array(
            self::META_SOURCE_URL => array( 'key' => 'source_url', 'label' => 'Source URL' ),
            // The SOURCE image only. _uc_image_url is the manual override and
            // is never written here — that is the whole point of the split.
            self::META_IMAGE      => array( 'key' => 'image_url',  'label' => 'Source image' ),
        );
        foreach ( $url_map as $meta_key => $spec ) {
            $value = isset( $event[ $spec['key'] ] ) ? trim( (string) $event[ $spec['key'] ] ) : '';
            if ( '' === $value ) {
                continue;
            }
            $value   = esc_url_raw( $value );
            $current = (string) get_post_meta( $post_id, $meta_key, true );
            if ( '' === $value || $current === $value ) {
                continue;
            }
            update_post_meta( $post_id, $meta_key, $value );
            $changed[ $spec['label'] ] = array( 'from' => $current, 'to' => $value );
        }

        /* ---- Adapter meta extras (GoFundMe URL, goal, raised …). ---- */
        if ( ! empty( $event['meta'] ) && is_array( $event['meta'] ) ) {
            foreach ( $event['meta'] as $meta_key => $meta_value ) {
                $meta_key = (string) $meta_key;
                if ( 0 !== strpos( $meta_key, '_uc_' ) || ! is_scalar( $meta_value ) ) {
                    continue;
                }
                // Never let an adapter's extras reach the manual image field.
                if ( '_uc_image_url' === $meta_key || '_uc_image_override' === $meta_key ) {
                    continue;
                }
                $meta_value = trim( (string) $meta_value );
                if ( '' === $meta_value ) {
                    continue;
                }
                $meta_value = ( false !== strpos( $meta_key, '_url' ) )
                    ? esc_url_raw( $meta_value )
                    : sanitize_text_field( $meta_value );
                $current = (string) get_post_meta( $post_id, $meta_key, true );
                if ( $current === $meta_value ) {
                    continue;
                }
                update_post_meta( $post_id, $meta_key, $meta_value );
                // The raised-at stamp changes on every fetch and is noise in a
                // change report, so it is applied but not reported.
                if ( '_uc_gofundme_raised_at' !== $meta_key ) {
                    $changed[ $meta_key ] = array( 'from' => $current, 'to' => $meta_value );
                }
            }
        }

        update_post_meta( $post_id, self::META_UPDATED_AT, time() );

        return array( 'changed' => $changed );
    }

    /** A short, single-line version of a value, for change reports. */
    private static function excerpt( $value ) {
        $value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) );
        if ( '' === $value ) {
            return '(empty)';
        }
        return ( strlen( $value ) > 90 ) ? substr( $value, 0, 90 ) . '…' : $value;
    }

    /**
     * Run one adapter and report what happened.
     *
     * Never throws: a source that fails returns a result carrying its error,
     * so the caller can show it beside the sources that succeeded.
     *
     * @param SFAF_Source_Adapter $adapter
     * @return array
     */
    /**
     * An empty result row — every counter the report knows about, at zero.
     *
     * One definition so a skipped, failed and completed source all carry the
     * same keys and the report never has to guard for a missing one.
     *
     * @param SFAF_Source_Adapter $adapter
     * @param bool                $skipped
     * @param string              $reason
     * @param string              $error
     * @return array
     */
    private static function blank_result( $adapter, $skipped = false, $reason = '', $error = '' ) {
        return array(
            'slug'         => $adapter->slug(),
            'label'        => $adapter->label(),
            'skipped'      => (bool) $skipped,
            'reason'       => (string) $reason,
            'fetched'      => 0,
            'new'          => 0,
            'updated'      => 0,
            'unchanged'    => 0,
            'untouched'    => 0,
            'invalid'      => 0,
            'failed'       => 0,
            'unpublished'  => 0,
            'ended'        => 0,
            'vanished'     => 0,
            'reappeared'   => 0,
            'removal_ran'  => false,
            'removal_skip' => '',
            'error'        => (string) $error,
            'notes'        => array(),
            'images'       => array(),
            'new_ids'      => array(),
            'updated_ids'  => array(),
        );
    }

    public static function run_adapter( $adapter ) {
        $result = array(
            'slug'          => $adapter->slug(),
            'label'         => $adapter->label(),
            'skipped'       => false,
            'reason'        => '',
            'fetched'       => 0,
            'new'           => 0,
            'updated'       => 0,
            'unchanged'     => 0,
            'untouched'     => 0,
            'invalid'       => 0,
            'failed'        => 0,
            'unpublished'   => 0,
            'ended'         => 0,
            'vanished'      => 0,
            'reappeared'    => 0,
            'removal_ran'   => false,
            'removal_skip'  => '',
            'error'         => '',
            'notes'         => array(),
            'images'        => array(),
            'new_ids'       => array(),
            'updated_ids'   => array(),
        );

        $response = $adapter->fetch();

        if ( is_wp_error( $response ) ) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        $items = ( is_array( $response ) && isset( $response['items'] ) && is_array( $response['items'] ) )
            ? $response['items']
            : array();

        if ( is_array( $response ) && ! empty( $response['notes'] ) && is_array( $response['notes'] ) ) {
            foreach ( $response['notes'] as $note ) {
                $result['notes'][] = (string) $note;
            }
        }

        // Guards against the same event arriving twice in one run — an event
        // shared across two organizations, say.
        $seen     = array();
        $seen_ids = array();

        foreach ( $items as $item ) {
            $result['fetched']++;

            $event = $adapter->normalize( $item );

            if ( ! is_array( $event ) || empty( $event['external_id'] ) || empty( $event['external_source'] ) ) {
                $result['invalid']++;
                continue;
            }

            $key = $event['external_source'] . '|' . $event['external_id'];
            if ( isset( $seen[ $key ] ) ) {
                continue; // the same event twice in one run
            }
            $seen[ $key ]           = true;
            $seen_ids[]             = (string) $event['external_id'];

            // Adapters may report which of the platform's image fields they
            // resolved to, so the report can show what was actually picked up
            // rather than just that something was.
            if ( ! empty( $event['image_field'] ) ) {
                $result['images'][] = array(
                    'title' => isset( $event['title'] ) ? (string) $event['title'] : (string) $event['external_id'],
                    'field' => (string) $event['image_field'],
                    'url'   => isset( $event['image_url'] ) ? (string) $event['image_url'] : '',
                );
            }

            $existing = self::find_existing( $event['external_source'], $event['external_id'] );

            if ( $existing ) {
                $status = get_post_status( $existing );

                // Dismissed and trashed events are decisions a person made.
                // They are neither updated nor resurrected.
                if ( ! in_array( $status, self::updatable_statuses(), true ) ) {
                    $result['untouched']++;
                    continue;
                }

                // An event that had been removed at the source and is back is
                // reported, never auto-republished — putting something live
                // again without a person looking is exactly what we do not do.
                if ( get_post_meta( $existing, self::META_REMOVED_AT, true ) ) {
                    delete_post_meta( $existing, self::META_REMOVED_AT );
                    delete_post_meta( $existing, self::META_REMOVED_WHY );
                    $result['reappeared']++;
                    $result['notes'][] = sprintf(
                        'Back at the source: "%s" reappeared and has been refreshed, but is left as a draft — republish it by hand if it should go live again.',
                        isset( $event['title'] ) ? $event['title'] : $event['external_id']
                    );
                }

                $update = self::update_event( $existing, $event );
                if ( is_wp_error( $update ) ) {
                    $result['failed']++;
                    $result['notes'][] = sprintf( 'Could not refresh #%d: %s', $existing, $update->get_error_message() );
                    continue;
                }

                if ( empty( $update['changed'] ) ) {
                    $result['unchanged']++;
                } else {
                    $result['updated']++;
                    $result['updated_ids'][] = (int) $existing;
                }
                continue;
            }

            $post_id = self::import_event( $event );
            if ( is_wp_error( $post_id ) ) {
                $result['failed']++;
                $result['notes'][] = sprintf(
                    'Could not save "%s": %s',
                    isset( $event['title'] ) ? $event['title'] : $event['external_id'],
                    $post_id->get_error_message()
                );
                continue;
            }

            $result['new']++;
            $result['new_ids'][] = (int) $post_id;
        }

        self::handle_removals( $adapter, $response, $seen_ids, $result );

        return $result;
    }

    /**
     * Take events off the calendar when they have gone from the source.
     *
     * THE GUARD IS THE POINT. "Not in the response" and "the fetch broke" look
     * identical from here, and getting it wrong silently pulls live events off
     * the public calendar. So removal only runs when the adapter can say the
     * run was CLEAN — every request succeeded, pagination reached the last
     * page, and at least one item came back. Any doubt at all and the whole
     * removal step is skipped for that source, and the report says why.
     *
     * It is per source: one platform failing never affects another's events.
     *
     * @param SFAF_Source_Adapter $adapter
     * @param array               $response The adapter's fetch() return.
     * @param string[]            $seen_ids External IDs present in this run.
     * @param array               $result   Modified by reference.
     */
    private static function handle_removals( $adapter, $response, $seen_ids, &$result ) {
        $complete = ! empty( $response['complete'] );
        $reason   = isset( $response['complete_reason'] ) ? (string) $response['complete_reason'] : '';

        if ( ! $complete ) {
            $result['removal_skip'] = ( '' !== $reason )
                ? $reason
                : 'the source did not confirm a complete run';
            return;
        }

        // Belt and braces on top of the adapter's own word: a run that
        // produced nothing usable is never grounds for removing anything.
        if ( empty( $seen_ids ) ) {
            $result['removal_skip'] = 'the run returned no usable events, which is never treated as "everything was deleted"';
            return;
        }

        $result['removal_ran'] = true;

        // IDs the adapter saw but deliberately filtered out — a campaign that
        // was unpublished at source, say. Absent from the fetched set for a
        // known reason, which is worth telling apart from a disappearance.
        $filtered = array();
        if ( ! empty( $response['filtered_ids'] ) && is_array( $response['filtered_ids'] ) ) {
            foreach ( $response['filtered_ids'] as $id ) {
                $filtered[ (string) $id ] = true;
            }
        }

        $seen = array_flip( array_map( 'strval', $seen_ids ) );

        $query = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => self::removable_statuses(),
            'posts_per_page'         => 500,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array( 'key' => self::META_SOURCE, 'value' => $adapter->slug() ),
            ),
        ) );

        foreach ( $query->posts as $post_id ) {
            $external_id = (string) get_post_meta( $post_id, self::META_EXTERNAL_ID, true );
            if ( '' === $external_id || isset( $seen[ $external_id ] ) ) {
                continue;
            }

            $why = isset( $filtered[ $external_id ] ) ? 'ended' : 'vanished';

            wp_update_post( array( 'ID' => $post_id, 'post_status' => self::STATUS_REMOVED ) );
            update_post_meta( $post_id, self::META_REMOVED_AT, time() );
            update_post_meta( $post_id, self::META_REMOVED_WHY, $why );

            $result['unpublished']++;
            $result[ $why ]++;
            $result['notes'][] = sprintf(
                '%s: "%s" %s and has been made a draft — it is off the calendar but kept, and can be republished.',
                ( 'ended' === $why ) ? 'Closed at source' : 'Gone from source',
                get_the_title( $post_id ),
                ( 'ended' === $why )
                    ? 'is no longer an active campaign at the source'
                    : 'is no longer returned by the source at all'
            );
        }
    }

    /**
     * Run every active source.
     *
     * One source failing — or throwing outright — does not stop the others.
     *
     * @return array One result array per active source.
     */
    public static function run_all() {
        $results = array();

        // Every REGISTERED adapter is reported on, not just the active ones.
        // A source that is switched off says so and why; a source that ran and
        // found nothing says that instead. Neither should look like the other,
        // and neither should collapse into a bare "0 fetched".
        foreach ( self::adapters() as $adapter ) {
            if ( ! $adapter->is_active() ) {
                $results[] = self::blank_result( $adapter, true, (string) $adapter->inactive_reason(), '' );
                continue;
            }

            try {
                $results[] = self::run_adapter( $adapter );
            } catch ( \Throwable $e ) {
                // A broken adapter is contained here rather than taking the
                // whole fetch — and the rest still run.
                $results[] = self::blank_result( $adapter, false, '', 'The source failed unexpectedly: ' . $e->getMessage() );
            }
        }

        return $results;
    }

    /**
     * A one-line summary of a run, e.g. "Eventbrite: 2 new".
     *
     * @param array $result One entry from run_all().
     * @return string
     */
    public static function summarize( $result ) {
        if ( ! empty( $result['skipped'] ) ) {
            return sprintf( '%s: not connected — %s', $result['label'], $result['reason'] );
        }

        if ( '' !== $result['error'] ) {
            return sprintf( '%s: failed — %s', $result['label'], $result['error'] );
        }

        // A source that ran and found nothing has to say so in its own words:
        // "0 new" beside "connected" reads very differently from silence.
        if ( 0 === (int) $result['fetched'] ) {
            return sprintf( '%s: connected, but the source returned no events at all.', $result['label'] );
        }

        $parts = array(
            sprintf( '%d new', (int) $result['new'] ),
            sprintf( '%d updated', (int) $result['updated'] ),
            sprintf( '%d unchanged', (int) $result['unchanged'] ),
        );
        if ( $result['unpublished'] ) {
            $parts[] = sprintf( '%d unpublished', (int) $result['unpublished'] );
        }
        if ( $result['reappeared'] ) {
            $parts[] = sprintf( '%d back at source', (int) $result['reappeared'] );
        }
        if ( $result['untouched'] ) {
            $parts[] = sprintf( '%d dismissed, left alone', (int) $result['untouched'] );
        }
        if ( $result['invalid'] ) {
            $parts[] = sprintf( '%d unusable', (int) $result['invalid'] );
        }
        if ( $result['failed'] ) {
            $parts[] = sprintf( '%d could not be saved', (int) $result['failed'] );
        }

        return sprintf( '%s: %s (of %d fetched)', $result['label'], implode( ', ', $parts ), (int) $result['fetched'] );
    }

    /* ---------------------------------------------------------------------
     * Queue reads and moves
     * ------------------------------------------------------------------- */

    /**
     * Imported events in one of the queue statuses, soonest first.
     *
     * @param string $status STATUS_PENDING or STATUS_DISMISSED.
     * @param int    $limit  Maximum rows.
     * @return int[] Post IDs.
     */
    public static function queue_ids( $status, $limit = 200 ) {
        // Deliberately NOT ordered by the _uc_event_date meta. Setting
        // meta_key in WP_Query implies the meta must exist, which would
        // silently drop every dateless import — and plenty of GoFundMe Pro
        // campaigns have no date at all, which is exactly the case the manager
        // needs to see in order to fill it in. Fetch by status, sort below.
        $query = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => $status,
            'posts_per_page'         => (int) $limit,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ) );

        $ids = wp_list_pluck( $query->posts, 'ID' );

        // Soonest first, with the dateless ones last rather than missing. The
        // meta cache is primed by the query above, so this costs no queries.
        usort( $ids, function ( $a, $b ) {
            $da = (string) get_post_meta( $a, '_uc_event_date', true );
            $db = (string) get_post_meta( $b, '_uc_event_date', true );
            if ( '' === $da && '' === $db ) {
                return $b - $a; // newest import first among the dateless
            }
            if ( '' === $da ) {
                return 1;
            }
            if ( '' === $db ) {
                return -1;
            }
            return strcmp( $da, $db );
        } );

        return $ids;
    }

    /** How many events sit in a queue status. */
    public static function queue_count( $status ) {
        $query = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => $status,
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );
        return (int) $query->found_posts;
    }

    /** True when this post is an imported event sitting in the queue. */
    public static function is_queued( $post_id ) {
        $status = get_post_status( $post_id );
        return in_array( $status, array( self::STATUS_PENDING, self::STATUS_DISMISSED ), true );
    }

    /**
     * Move a queued event between the two sub-sections.
     *
     * Refuses anything that is not a uc_event currently in one of the queue
     * statuses, so this can never be pointed at a live event.
     *
     * @param int    $post_id
     * @param string $status STATUS_PENDING or STATUS_DISMISSED.
     * @return bool
     */
    public static function move( $post_id, $status ) {
        $post_id = (int) $post_id;
        $post    = get_post( $post_id );

        if ( ! $post || 'uc_event' !== $post->post_type ) {
            return false;
        }
        if ( ! in_array( $status, array( self::STATUS_PENDING, self::STATUS_DISMISSED ), true ) ) {
            return false;
        }
        if ( ! self::is_queued( $post_id ) ) {
            return false;
        }

        wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ) );
        return true;
    }

    /**
     * Re-fetch one event from its source and apply the same update path.
     *
     * Backs the "Refresh from source" button on the event form. Uses the very
     * same update_event() the bulk fetch uses, so the local-field protections
     * are identical and cannot drift.
     *
     * @param int $post_id
     * @return array|WP_Error {changed, label}
     */
    public static function refresh_event( $post_id ) {
        $post_id = (int) $post_id;
        $prov    = self::provenance( $post_id );

        if ( '' === $prov['source'] || '' === $prov['external_id'] ) {
            return new WP_Error( 'sfaf_sources_not_imported', 'This event did not come from a third-party source.' );
        }

        $adapter = self::adapter( $prov['source'] );
        if ( ! $adapter ) {
            return new WP_Error(
                'sfaf_sources_no_adapter',
                sprintf( 'No adapter is registered for "%s", so this event cannot be refreshed.', $prov['source'] )
            );
        }
        if ( ! $adapter->is_active() ) {
            return new WP_Error(
                'sfaf_sources_inactive',
                sprintf( '%s is not connected — %s.', $adapter->label(), $adapter->inactive_reason() )
            );
        }

        $item = $adapter->fetch_one( $prov['external_id'] );

        if ( is_wp_error( $item ) ) {
            return $item;
        }
        if ( null === $item ) {
            return new WP_Error(
                'sfaf_sources_no_single_fetch',
                sprintf( '%s cannot re-fetch a single event.', $adapter->label() )
            );
        }

        $event = $adapter->normalize( $item );
        if ( ! is_array( $event ) || empty( $event['external_id'] ) ) {
            return new WP_Error( 'sfaf_sources_unusable', 'The source returned something this adapter could not read.' );
        }

        $update = self::update_event( $post_id, $event );
        if ( is_wp_error( $update ) ) {
            return $update;
        }

        // A single refresh proves the event is still there, so a stale
        // "removed" mark is cleared — but the status is deliberately left as
        // it is. Nothing here republishes anything.
        if ( get_post_meta( $post_id, self::META_REMOVED_AT, true ) ) {
            delete_post_meta( $post_id, self::META_REMOVED_AT );
            delete_post_meta( $post_id, self::META_REMOVED_WHY );
        }

        $update['label'] = $adapter->label();
        return $update;
    }

    /* ---------------------------------------------------------------------
     * One-time migration
     * ------------------------------------------------------------------- */

    /**
     * Untangle the image keys on events imported by 2.6.0.
     *
     * That release wrote the source's image to BOTH _uc_external_image and
     * _uc_image_url, before the two had been separated. _uc_image_url is the
     * manual override, so those events now look as though a person had chosen
     * that image — which would stop the source image ever refreshing.
     *
     * The two being byte-identical is what identifies an importer-written
     * value: a human pasting the exact same URL the API returned is not a case
     * worth protecting against, and anything a person actually changed differs
     * and is left alone.
     *
     * Runs once, guarded by an option.
     */
    public static function migrate_image_split() {
        if ( get_option( 'sfaf_sources_image_split_done' ) ) {
            return;
        }

        $query = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => self::all_statuses(),
            'posts_per_page'         => 500,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array( 'key' => self::META_IMAGE, 'compare' => 'EXISTS' ),
            ),
        ) );

        foreach ( $query->posts as $post_id ) {
            $external = (string) get_post_meta( $post_id, self::META_IMAGE, true );
            $manual   = (string) get_post_meta( $post_id, '_uc_image_url', true );
            if ( '' === $external || $external !== $manual ) {
                continue;
            }
            delete_post_meta( $post_id, '_uc_image_url' );
            delete_post_meta( $post_id, '_uc_image_override' );
        }

        update_option( 'sfaf_sources_image_split_done', '1', false );
    }

    /**
     * The provenance stored on an event, for badges and source links.
     *
     * @param int $post_id
     * @return array{source:string,label:string,external_id:string,source_url:string,image_url:string,timezone:string,imported_at:int}
     */
    public static function provenance( $post_id ) {
        $post_id = (int) $post_id;
        $source  = (string) get_post_meta( $post_id, self::META_SOURCE, true );

        return array(
            'source'      => $source,
            'label'       => ( '' !== $source ) ? self::source_label( $source ) : '',
            'external_id' => (string) get_post_meta( $post_id, self::META_EXTERNAL_ID, true ),
            'source_url'  => (string) get_post_meta( $post_id, self::META_SOURCE_URL, true ),
            'image_url'   => (string) get_post_meta( $post_id, self::META_IMAGE, true ),
            'timezone'    => (string) get_post_meta( $post_id, self::META_TIMEZONE, true ),
            'imported_at' => (int) get_post_meta( $post_id, self::META_IMPORTED_AT, true ),
        );
    }
}
