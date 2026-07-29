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
            update_post_meta( $post_id, self::META_IMAGE, esc_url_raw( (string) $event['image_url'] ) );
        }
        if ( ! empty( $event['timezone'] ) ) {
            update_post_meta( $post_id, self::META_TIMEZONE, sanitize_text_field( (string) $event['timezone'] ) );
        }

        return (int) $post_id;
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
    public static function run_adapter( $adapter ) {
        $result = array(
            'slug'    => $adapter->slug(),
            'label'   => $adapter->label(),
            'fetched' => 0,
            'new'     => 0,
            'known'   => 0,
            'invalid' => 0,
            'failed'  => 0,
            'error'   => '',
            'notes'   => array(),
            'new_ids' => array(),
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
        $seen = array();

        foreach ( $items as $item ) {
            $result['fetched']++;

            $event = $adapter->normalize( $item );

            if ( ! is_array( $event ) || empty( $event['external_id'] ) || empty( $event['external_source'] ) ) {
                $result['invalid']++;
                continue;
            }

            $key = $event['external_source'] . '|' . $event['external_id'];
            if ( isset( $seen[ $key ] ) ) {
                $result['known']++;
                continue;
            }
            $seen[ $key ] = true;

            // Known in ANY state — published, pending, dismissed, trashed —
            // means leave it alone. A dismissed event is never resurrected.
            if ( self::find_existing( $event['external_source'], $event['external_id'] ) ) {
                $result['known']++;
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

        return $result;
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

        foreach ( self::active_adapters() as $adapter ) {
            try {
                $results[] = self::run_adapter( $adapter );
            } catch ( \Throwable $e ) {
                // A broken adapter is contained here rather than taking the
                // whole fetch — and the rest still run.
                $results[] = array(
                    'slug'    => $adapter->slug(),
                    'label'   => $adapter->label(),
                    'fetched' => 0,
                    'new'     => 0,
                    'known'   => 0,
                    'invalid' => 0,
                    'failed'  => 0,
                    'error'   => 'The source failed unexpectedly: ' . $e->getMessage(),
                    'notes'   => array(),
                    'new_ids' => array(),
                );
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
        if ( '' !== $result['error'] ) {
            return sprintf( '%s: failed — %s', $result['label'], $result['error'] );
        }

        $parts = array( sprintf( '%d new', (int) $result['new'] ) );
        if ( $result['known'] ) {
            $parts[] = sprintf( '%d already known', (int) $result['known'] );
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
        $query = new WP_Query( array(
            'post_type'              => 'uc_event',
            'post_status'            => $status,
            'posts_per_page'         => (int) $limit,
            'meta_key'               => '_uc_event_date',
            'orderby'                => 'meta_value',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ) );

        return wp_list_pluck( $query->posts, 'ID' );
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
