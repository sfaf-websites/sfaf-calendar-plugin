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

    /* ---------------------------------------------------------------------
     * Field ownership — declared once, consumed twice
     *
     * An adapter says which of an event's fields belong to its platform. That
     * single declaration does two jobs:
     *
     *   1. update_event() writes ONLY the fields named here. A field an
     *      adapter does not claim is never touched by a fetch, no matter what
     *      normalize() puts in the payload.
     *   2. The editor renders exactly these fields disabled, with a lock and
     *      an "Edit on <platform>" link.
     *
     * They cannot drift, because there is no second list to keep in step. A
     * field added to owned_fields() becomes both refreshed and locked in the
     * same commit; one removed becomes both left alone and editable. The old
     * arrangement — a hardcoded map in the framework and a hardcoded sentence
     * in the editor — could tell a manager a field was theirs to edit while
     * the next fetch quietly overwrote it.
     *
     * THE FIELD NAMES are the editor's, not the payload's:
     *
     *   title, description, image, date, start_time, end_time, end_date,
     *   location, source_url, faqs
     * ------------------------------------------------------------------- */

    /**
     * Fields this platform owns and a fetch will overwrite.
     *
     * Defaults to none: an adapter that declares nothing writes nothing and
     * locks nothing, which is the safe direction to fail in.
     *
     * @return string[]
     */
    public function owned_fields() {
        return array();
    }

    /**
     * Fields this platform cannot supply, which a manager owns permanently.
     *
     * These arrive empty on every import by design and must be excluded from
     * every fetch, now and from any later cron. The editor highlights them
     * while they are empty so nobody has to remember which ones they are.
     *
     * @return string[]
     */
    public function manager_fields() {
        return array();
    }

    /**
     * One sentence explaining why manager_fields() are empty, shown in the
     * editor beside them. Without it the next person assumes the import broke.
     *
     * @return string
     */
    public function manager_fields_note() {
        return '';
    }

    /* ---------------------------------------------------------------------
     * Three hooks from 3.101.0, all no-ops by default, so an adapter that
     * does not know about them behaves exactly as it did.
     * ------------------------------------------------------------------- */

    /**
     * Whether the SCHEDULED runner may fetch this source.
     *
     * A manual "Fetch updates" always runs every active source. This is the
     * per-source Auto-Import switch, and it gates only the unattended run, so
     * a source can be fetched by hand and looked at before anything repeats.
     *
     * @return bool
     */
    public function runs_unattended() {
        return true;
    }

    /** What the fetch report says when runs_unattended() is false. */
    public function unattended_off_reason() {
        return 'Auto-Import is off.';
    }

    /**
     * After an item has been created or refreshed, for what the common shape
     * cannot carry: a series, a place name and its address parts.
     *
     * Called only for an event the framework was allowed to write, so a
     * dismissed or trashed event never reaches it.
     *
     * @param int   $post_id
     * @param array $event   What normalize() returned.
     * @param bool  $is_new  Created by this run.
     * @return array Label => {from, to}, added to the run's report of changes.
     */
    public function after_save( $post_id, $event, $is_new ) {
        return array();
    }

    /**
     * After a run, with its result, which it may add notes to.
     *
     * @param array $result One run_adapter() result.
     */
    public function after_run( &$result ) {
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

    /**
     * What the source calls this item's kind. Empty on every row imported
     * before 3.59.0, and that emptiness is load-bearing: see last_day().
     */
    const META_SOURCE_TYPE = '_uc_source_type';
    const META_REMOVED_WHY = '_uc_source_removed_reason';

    /**
     * The key an imported FAQ row carries its ID-at-the-source in.
     *
     * A row WITH this key was written by an import and belongs to the
     * platform. A row WITHOUT it was typed by a person and is never touched.
     * That one distinction is the whole of the FAQ ownership model.
     *
     * Named for "the source", not for GoFundMe Pro: an event has exactly one
     * source, so the ID is unambiguous without repeating the platform on
     * every row, and a later platform that supplies FAQs needs no new key.
     */
    const FAQ_SOURCE_ID = 'source_faq_id';

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
            'label'                     => 'Imported, pending review',
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
     * @param array $event      Common event shape.
     * @param array $faq_counts Set by reference to {added, updated, removed}.
     * @return int|WP_Error New post ID.
     */
    public static function import_event( $event, &$faq_counts = null ) {
        $title = isset( $event['title'] ) ? trim( (string) $event['title'] ) : '';
        if ( '' === $title ) {
            $title = '(untitled imported event)';
        }

        // Fields the adapter says a manager owns permanently are not written
        // here and never will be. For GoFundMe Pro that is the image and the
        // description, which their API does not expose at all — see
        // SFAF_Source_GFMP::manager_fields(). Previously this "worked"
        // because the adapter happened to send empty values; now the
        // framework refuses them whatever the adapter sends.
        $manager = self::manager_fields_for( isset( $event['external_source'] ) ? $event['external_source'] : '' );

        $description = in_array( 'description', $manager, true )
            ? ''
            : ( isset( $event['description'] ) ? (string) $event['description'] : '' );

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

        /*
         * WHAT THE SOURCE CALLS THIS THING. Stored because nothing stored it
         * before 3.59.0, which is why no existing row could be re-judged and
         * why four of them sat in the queues on a fundraising window read as an
         * event's end. It is provenance, not a field anybody edits: no screen
         * shows it and no form writes it.
         */
        if ( ! empty( $event['source_type'] ) ) {
            update_post_meta( $post_id, self::META_SOURCE_TYPE, sanitize_key( $event['source_type'] ) );
        }
        update_post_meta( $post_id, self::META_IMPORTED_AT, time() );

        if ( ! empty( $event['source_url'] ) ) {
            update_post_meta( $post_id, self::META_SOURCE_URL, esc_url_raw( (string) $event['source_url'] ) );
        }
        if ( ! empty( $event['image_url'] ) && ! in_array( 'image', $manager, true ) ) {
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

        // FAQs from the platform. A brand-new event has no manual rows to
        // protect, so this is simply "write what the source sent".
        $faq_counts = self::sync_faqs( $post_id, $event, true );

        return (int) $post_id;
    }

    /* ---------------------------------------------------------------------
     * Field ownership, read from the adapter
     * ------------------------------------------------------------------- */

    /**
     * Fields the platform behind this source owns and refreshes.
     *
     * @param string $source Adapter slug.
     * @return string[]
     */
    public static function owned_fields_for( $source ) {
        $adapter = self::adapter( (string) $source );
        return $adapter ? array_map( 'strval', (array) $adapter->owned_fields() ) : array();
    }

    /**
     * Fields a manager owns permanently on events from this source.
     *
     * @param string $source Adapter slug.
     * @return string[]
     */
    public static function manager_fields_for( $source ) {
        $adapter = self::adapter( (string) $source );
        return $adapter ? array_map( 'strval', (array) $adapter->manager_fields() ) : array();
    }

    /**
     * THE ONE DESCRIPTION OF "HAS THIS FIELD BEEN FILLED IN".
     *
     * Each entry answers the question twice over, because it genuinely has two
     * halves and they used to be written down in two different places:
     *
     *   'phrase'  how the field is named to a person.
     *   'inputs'  THE `name` ATTRIBUTE EXACTLY AS THE EDITOR EMITS IT, brackets
     *             and all. See the warning below: this used to hold the PHP key
     *             instead, and the two are the same string for every field
     *             except the two that take several values.
     *   field_is_filled() below is the storage half, read on page load and by
     *             the pending queue.
     *
     * > **`category[]` IS THE NAME; `$_POST['category']` IS THE KEY, AND
     * > STORING THE KEY HERE COST 3.49.1.** PHP turns a `name="category[]"`
     * > control into `$_POST['category']`, so the old entries genuinely were
     * > "the names save_event_from_post() reads back" and the docblock was
     * > accurate about its own half. The browser uses the same list as a DOM
     * > selector, and `[name="category"]` matches NOTHING when the control is
     * > `category[]`. The live check then took its "these controls are not on
     * > this form" branch and fell back to the stored answer, which on an
     * > unsaved import is empty, so choosing categories never cleared the
     * > warning.
     * >
     * > **The name attribute is the one string both halves can derive from.**
     * > PHP's key is this minus a trailing `[]`, mechanically; a DOM selector
     * > cannot recover the brackets from the key. So the attribute is what is
     * > stored, and anything wanting the POST key strips the suffix.
     *
     * WHY THE CONTROL NAMES BELONG HERE. The publish warning, the amber field
     * highlight and the queue icon all came off missing_manager_fields(), so
     * they agreed with each other — and all three were still wrong, because
     * every one of them was computed once, server-side, from the state at page
     * load, and nothing re-ran while the manager typed. Filling in the image
     * and the description therefore did not clear the warning: the page had
     * been rendered before either of them existed, and the Publish button was
     * carrying a sentence written before the form was touched.
     *
     * Naming the controls next to the storage is what lets the browser ask the
     * SAME question the server asks, off this one list, instead of a second
     * list in JavaScript that would drift the first time a field was renamed.
     *
     * A control counts as filled when its trimmed value is neither empty nor
     * "0" — "0" is the None option on the category and organizer selects and
     * the no-attachment value of the featured-image field, and is not a
     * description, a title or a location anybody means to type.
     *
     * @return array<string,array{phrase:string,inputs:string[]}>
     */
    public static function completeness_fields() {
        return array(
            'image'       => array( 'phrase' => 'an image',     'inputs' => array( 'featured_image_id', 'image_url' ) ),
            'description' => array( 'phrase' => 'a description', 'inputs' => array( 'description' ) ),
            'title'       => array( 'phrase' => 'a title',       'inputs' => array( 'title' ) ),
            'location'    => array( 'phrase' => 'a location',    'inputs' => array( 'location' ) ),
            'date'        => array( 'phrase' => 'a date',        'inputs' => array( 'date' ) ),
            /* Checkbox groups since 3.8.0 and 3.40.0. The brackets are part of
             * the name and are what the browser has to match on. */
            'category'    => array( 'phrase' => 'a category',    'inputs' => array( 'category[]' ) ),
            'organizer'   => array( 'phrase' => 'an organizer',  'inputs' => array( 'organizer[]' ) ),
        );
    }

    /**
     * WHAT AN EVENT NEEDS BEFORE THE EDITOR WILL PUBLISH IT (3.99.0).
     *
     * A second list beside completeness_fields(), not an extension of it,
     * because the two answer different questions. That one is "what has this
     * PLATFORM left for a manager to fill in", and it is empty for a hand-made
     * event; this one is "what does ANY event need before it goes on the public
     * calendar". The import warning stays exactly as it was.
     *
     * READ BY THREE THINGS: the save, which holds a publish back while any of
     * these is missing (SFAF_Organizers::requirement()); the flash that names
     * them; and the editor's asterisks. A draft needs only a title, and the
     * title field's own `required` is what enforces that.
     *
     * A LOCATION IS A VENUE, AN ADDRESS, OR THE ONLINE TICK. Hybrid counts as
     * both, because it is an online event that also has a room.
     *
     * The phrase is how field_phrase() names a key, so the order here is the
     * order the flash lists them in.
     *
     * @return array<string,array{phrase:string}>
     */
    public static function publish_fields() {
        return array(
            'title'       => array( 'phrase' => 'a title' ),
            'date'        => array( 'phrase' => 'a date' ),
            'start_time'  => array( 'phrase' => 'a start time' ),
            'end_time'    => array( 'phrase' => 'an end time' ),
            'organizer'   => array( 'phrase' => 'an organizer' ),
            'category'    => array( 'phrase' => 'a category' ),
            'description' => array( 'phrase' => 'a description' ),
            'location'    => array( 'phrase' => 'a location' ),
        );
    }

    /**
     * The `$_POST` key a control name arrives under.
     *
     * One line, in one place, so nothing has to remember which way the
     * conversion goes. PHP drops the trailing `[]` and collects the values into
     * an array under what is left.
     *
     * @param string $input A name attribute from completeness_fields().
     * @return string
     */
    public static function post_key_for_input( $input ) {
        $input = (string) $input;
        return ( '[]' === substr( $input, -2 ) ) ? substr( $input, 0, -2 ) : $input;
    }

    /**
     * The live-check payload for one event's editor: every manager-owned field
     * with the controls that fill it and whether storage says it is filled now.
     *
     * Handed to the browser so the warning, the highlight and the confirmation
     * can be recomputed as the manager types, from this list and no other.
     *
     * @param int $post_id
     * @return array
     */
    public static function completeness_payload( $post_id ) {
        $post_id = (int) $post_id;
        $source  = (string) get_post_meta( $post_id, self::META_SOURCE, true );
        $fields  = self::completeness_fields();
        $out     = array();

        foreach ( self::manager_fields_for( $source ) as $field ) {
            if ( ! isset( $fields[ $field ] ) ) {
                continue;
            }
            $out[] = array(
                'field'  => $field,
                'phrase' => $fields[ $field ]['phrase'],
                'inputs' => array_values( $fields[ $field ]['inputs'] ),
                'filled' => (bool) self::field_is_filled( $post_id, $field ),
            );
        }
        return $out;
    }

    /**
     * Human labels for the fields a manager still has to fill in.
     *
     * The pending-queue indicator, the editor's highlight and the publish
     * confirmation all ask this one question, so they can never disagree
     * about what is missing. See completeness_fields() for the half of the
     * answer that lets the browser ask it too.
     *
     * @param int $post_id
     * @return string[] e.g. array( 'image', 'description' ) → 'an image', 'a description'
     */
    public static function missing_manager_fields( $post_id ) {
        $post_id = (int) $post_id;
        $source  = (string) get_post_meta( $post_id, self::META_SOURCE, true );
        if ( '' === $source ) {
            return array();
        }
        return self::missing_fields( $post_id, self::manager_fields_for( $source ) );
    }

    /**
     * The same question, about a named list of fields.
     *
     * WHY THIS IS SEPARATE FROM THE ONE ABOVE (3.68.0). The pending queue marks
     * an imported event that still needs an image, and does it off the adapter's
     * declaration, which is the right source for an import and answers nothing
     * at all for anything else: an event with no source gets `array()` from
     * missing_manager_fields() by its first guard.
     *
     * A SUBMITTED EVENT CAN ARRIVE WITH NO LOCATION. The staff form does not
     * require one, deliberately, because both forms land in Pending and a
     * manager sees them before anything is published. What was missing was that
     * the queue said nothing about it, so the gap was only findable by opening
     * the event.
     *
     * SO THE STATE IS EXTENDED RATHER THAN A SECOND ONE ADDED. Same icon, same
     * amber row, same wording out of field_phrase(), same filled test out of
     * field_is_filled(). A second mechanism would be a second answer to "what
     * is this event still missing", and the two would have disagreed the first
     * time either list changed.
     *
     * @param int      $post_id
     * @param string[] $fields Keys from completeness_fields().
     * @return string[] Those of them that are not filled in.
     */
    public static function missing_fields( $post_id, $fields ) {
        $post_id = (int) $post_id;
        if ( ! $post_id ) {
            return array();
        }
        $missing = array();
        foreach ( (array) $fields as $field ) {
            if ( ! self::field_is_filled( $post_id, $field ) ) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /**
     * Whether a manager-owned field has been filled in on this event.
     *
     * The image counts as filled by EITHER a featured image or a pasted URL,
     * because either one is what the single-event page will show. It
     * deliberately does not count the source's own image: for a source that
     * declares the image manager-owned there is never going to be one.
     *
     * @param int    $post_id
     * @param string $field
     * @return bool
     */
    public static function field_is_filled( $post_id, $field ) {
        $post_id = (int) $post_id;

        switch ( (string) $field ) {
            case 'image':
                /* The folder rule (3.83.0): a picture the calendar will not
                 * draw does not make this field filled, or the completeness
                 * prompt would say an event has an image and the card would
                 * show the placeholder. */
                return function_exists( 'sfaf_event_has_own_image' ) && sfaf_event_has_own_image( $post_id );
            case 'description':
                // Flattened rather than stripped. Only emptiness is being asked
                // here, so both answer the same, but keeping one way of turning
                // prose into text means there is no exception to explain later.
                return '' !== trim( sfaf_flatten_html( (string) get_post_field( 'post_content', $post_id ) ) );
            case 'title':
                return '' !== trim( (string) get_post_field( 'post_title', $post_id ) );
            case 'location':
                /*
                 * AN ONLINE EVENT HAS NO LOCATION AND IS NOT MISSING ONE.
                 * SFAF_Online::set_online() deletes _uc_location, because there
                 * is nowhere to go, so the bare meta test would report every
                 * online event as incomplete and put an amber mark on it
                 * forever. Asked of SFAF_Online rather than of a second meta
                 * key here, so there is one answer to "is this online".
                 */
                if ( class_exists( 'SFAF_Online' ) && SFAF_Online::is_online( $post_id ) ) {
                    return true;
                }
                return '' !== trim( (string) get_post_meta( $post_id, '_uc_location', true ) );
            case 'date':
                return '' !== trim( (string) get_post_meta( $post_id, '_uc_event_date', true ) );
            case 'category':
                return self::has_term( $post_id, 'uc_event_category' );
            case 'organizer':
                return self::has_term( $post_id, 'uc_organizer' );

            /*
             * A SETTING IS NOT A BLANK. fundraising_progress is a manager-owned
             * BOOLEAN: it is declared so a fetch can never write it, not
             * because somebody has to fill it in. Off is a complete answer and
             * the correct default, so "filled" is true either way and this
             * never joins the list of things an event still needs before
             * publishing. Spelled out rather than left to the fall-through
             * below, because the difference between "not a completeness field"
             * and "a completeness field nobody added a case for" is exactly the
             * kind of thing this switch should not leave to inference.
             */
            case 'fundraising_progress':
                return true;
        }
        return true;
    }

    /**
     * Whether an event has at least one term in a taxonomy.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return bool
     */
    private static function has_term( $post_id, $taxonomy ) {
        $terms = wp_get_object_terms( (int) $post_id, $taxonomy, array( 'fields' => 'ids' ) );
        return ! is_wp_error( $terms ) && ! empty( $terms );
    }

    /**
     * The same list phrased for a person: "an image and a description".
     *
     * @param string[] $fields
     * @return string
     */
    public static function field_phrase( $fields ) {
        // The wording comes off completeness_fields() rather than a second
        // list here, so the browser and the server name a field identically.
        // publish_fields() adds the two times, which only it names; where a
        // key is in both, the two phrases are the same words.
        $words = array_merge( self::publish_fields(), self::completeness_fields() );

        $out = array();
        foreach ( (array) $fields as $field ) {
            $out[] = isset( $words[ $field ]['phrase'] ) ? $words[ $field ]['phrase'] : $field;
        }

        if ( empty( $out ) ) {
            return '';
        }
        if ( 1 === count( $out ) ) {
            return $out[0];
        }
        $last = array_pop( $out );
        if ( 1 === count( $out ) ) {
            return $out[0] . " and " . $last;
        }
        // The serial comma, SFAF house style: "an image, a description, and a
        // category". phrase() in portal.js is the same joining on the browser
        // side and carries the same rule.
        return implode( ", ", $out ) . ", and " . $last;
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
     * WHAT IS ALLOWED TO BE WRITTEN is not decided here any more. The
     * adapter's owned_fields() decides, and the editor locks the same list, so
     * "a fetch will overwrite this" and "you cannot type in this" are one
     * statement rather than two that can disagree. manager_fields() are
     * refused outright whatever the payload contains.
     *
     * @param int   $post_id
     * @param array $event   Common event shape.
     * @param array $context {clean_fetch: bool} — whether this run is entitled
     *                       to conclude that something missing from it has
     *                       really gone. Governs FAQ row removal exactly as it
     *                       governs event removal.
     * @return array|WP_Error {changed: array<string,array{from:string,to:string}>, faqs: array}
     */
    public static function update_event( $post_id, $event, $context = array() ) {
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

        /*
         * NO CANCELLED-EVENT LOCK HERE, AND IT WAS REMOVED RATHER THAN KEPT AS
         * A BELT (3.38.0).
         *
         * 3.36.0 refused a refresh on a cancelled event, to stop a source
         * moving the date of something that was not happening. Cancelling is
         * for native events only since 3.38.0, and a native event is never
         * refreshed by anything, so the guard now protects a state that cannot
         * be reached. A check that can never fire is a check nobody can reason
         * about later: it reads as evidence the case is possible.
         */
        $source  = isset( $event['external_source'] ) ? (string) $event['external_source'] : '';
        $owned   = self::owned_fields_for( $source );
        $manager = self::manager_fields_for( $source );

        /**
         * Whether a fetch may write this field.
         *
         * An adapter that declares nothing at all keeps the pre-2.8.0
         * behaviour of writing whatever it sends — a third-party adapter
         * registered through the sfaf_source_adapters filter must not stop
         * working because it has not been taught about this declaration.
         * A manager-owned field is refused either way.
         */
        $may_write = function ( $field ) use ( $owned, $manager ) {
            if ( in_array( $field, $manager, true ) ) {
                return false;
            }
            return empty( $owned ) || in_array( $field, $owned, true );
        };

        $changed = array();

        /* ---- Post fields. post_status is deliberately not among them. ---- */
        $postarr = array( 'ID' => $post_id );

        $title = ( $may_write( 'title' ) && isset( $event['title'] ) ) ? trim( (string) $event['title'] ) : '';
        if ( '' !== $title && $title !== $post->post_title ) {
            $postarr['post_title'] = $title;
            $changed['Title']      = array( 'from' => $post->post_title, 'to' => $title );
        }

        $description = ( $may_write( 'description' ) && isset( $event['description'] ) ) ? (string) $event['description'] : '';
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
        // 'owns' is the editor-facing field name this meta belongs to. The
        // timezone has no control in the editor and rides with the date,
        // which every adapter that supplies times owns anyway.
        $meta_map = array(
            '_uc_event_date'    => array( 'key' => 'start_date', 'label' => 'Date',       'owns' => 'date' ),
            '_uc_start_time'    => array( 'key' => 'start_time', 'label' => 'Start time', 'owns' => 'start_time' ),
            '_uc_end_time'      => array( 'key' => 'end_time',   'label' => 'End time',   'owns' => 'end_time' ),
            '_uc_end_date'      => array( 'key' => 'end_date',   'label' => 'End date',   'owns' => 'end_date' ),
            '_uc_location'      => array( 'key' => 'location',   'label' => 'Location',   'owns' => 'location' ),
            self::META_TIMEZONE => array( 'key' => 'timezone',   'label' => 'Timezone',   'owns' => 'date' ),
        );
        foreach ( $meta_map as $meta_key => $spec ) {
            if ( ! $may_write( $spec['owns'] ) ) {
                continue;
            }
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

        /*
         * ---- The item's type, refreshed quietly. ----
         *
         * NOT IN THE MAP ABOVE, on purpose. That map records every change into
         * $changed, which is what the fetch report prints as "which events
         * moved, and what changed on them". The type filling in on an existing
         * row is not news to a manager — it is this build catching up on
         * something that was never stored — and the first run after 3.59.0
         * would otherwise report every still-returned event as changed.
         *
         * IT IS ALSO NOT MANAGER-OWNED and has no $may_write() gate for the
         * same reason the source slug and external id have none: nothing in any
         * editor writes it, so there is no edit here to overwrite.
         *
         * This is what lets a row imported before 3.59.0 learn its type: the
         * next fetch that still returns its campaign fills it in, and the sweep
         * judges it properly from then on. A campaign the source has stopped
         * returning never gets one, and is judged on its start date forever.
         * That is correct and is not worked around.
         */
        if ( ! empty( $event['source_type'] ) ) {
            $type_now = sanitize_key( $event['source_type'] );
            if ( (string) get_post_meta( $post_id, self::META_SOURCE_TYPE, true ) !== $type_now ) {
                update_post_meta( $post_id, self::META_SOURCE_TYPE, $type_now );
            }
        }

        /* ---- URLs. ---- */
        $url_map = array(
            self::META_SOURCE_URL => array( 'key' => 'source_url', 'label' => 'Source URL' ),
        );
        foreach ( $url_map as $meta_key => $spec ) {
            if ( ! $may_write( 'source_url' ) ) {
                continue;
            }
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

        /* ---- The source image: SOURCE-OWNED, so absence counts. ----
         *
         * Every other field here follows "empty in, leave alone", because an
         * empty title or date from the source means "nothing to say" and a
         * manager may have filled the gap in by hand.
         *
         * This one is different. _uc_external_image belongs entirely to the
         * source — a person's image goes in _uc_image_url, which is never
         * touched here — so the source no longer offering one is real
         * information, and leaving a stale URL behind would keep showing an
         * image the platform has withdrawn. So absence clears it, and the
         * event falls back through _uc_image_url, then the series image, then
         * the branded placeholder.
         *
         * This is deliberately NOT generalised to description or any other
         * field. A hand-written description must survive a refetch.
         *
         * AND IT DOES NOT RUN AT ALL for a source that declares the image
         * manager-owned. On GoFundMe Pro the image is a person's work, not the
         * platform's — the platform has none to offer — so the "absence
         * clears it" rule above would be actively destructive here. This is
         * the reason that exclusion is a declaration and not a coincidence.
         */
        if ( $may_write( 'image' ) ) {
            $image         = isset( $event['image_url'] ) ? trim( (string) $event['image_url'] ) : '';
            $current_image = (string) get_post_meta( $post_id, self::META_IMAGE, true );

            if ( '' === $image ) {
                if ( '' !== $current_image ) {
                    delete_post_meta( $post_id, self::META_IMAGE );
                    $changed['Source image'] = array(
                        'from' => $current_image,
                        'to'   => '(cleared: the source no longer offers one)',
                    );
                }
            } else {
                $image = esc_url_raw( $image );
                if ( '' !== $image && $current_image !== $image ) {
                    update_post_meta( $post_id, self::META_IMAGE, $image );
                    $changed['Source image'] = array( 'from' => $current_image, 'to' => $image );
                }
            }
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
                /*
                 * NOR THE PRIVACY FLAG, EVER, WHATEVER AN ADAPTER SENDS.
                 *
                 * A hard refusal rather than a $may_write() gate, because this
                 * is not a field a source can have a wrong opinion about: it
                 * has no opinion at all. GoFundMe Pro and Eventbrite have no
                 * concept of a private event, so any _uc_private arriving from
                 * one is a coincidence of key naming, and the cost of honouring
                 * it once would be a donor reception quietly appearing on the
                 * public calendar at the next hourly fetch.
                 *
                 * The declared manager_fields() entry is what makes the editor
                 * badge it as permanently the manager's. This is what makes
                 * that true rather than declared.
                 */
                if ( SFAF_Privacy::META === $meta_key ) {
                    continue;
                }
                /*
                 * NOR ANYTHING BELONGING TO THE ONLINE TICK, AND ONE OF THE
                 * THREE IS A CREDENTIAL.
                 *
                 * Same hard refusal and same reasoning as the privacy flag
                 * above: no platform has a concept of this tick, so a key of
                 * that name arriving from one is a coincidence of naming. The
                 * cost of honouring it once is not a wrong label. It is an
                 * adapter writing _uc_meeting_url, which would put a URL this
                 * plugin has never seen into the confirmation email of
                 * everybody who registers, over the manager's signature.
                 *
                 * The tick is not offered on an imported event at all, so there
                 * is no editor state for this to contradict.
                 */
                if ( in_array( $meta_key, SFAF_Online::meta_keys(), true ) ) {
                    continue;
                }
                // The Donate box has an editor control, so it is gated by the
                // declaration like any other visible field. Without this an
                // adapter could write a box the editor left editable.
                if ( '_uc_gofundme_url' === $meta_key && ! $may_write( 'donate_url' ) ) {
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

        /* ---- FAQs. ---- */
        $faqs = array( 'added' => 0, 'updated' => 0, 'removed' => 0 );
        if ( $may_write( 'faqs' ) ) {
            $faqs = self::sync_faqs( $post_id, $event, ! empty( $context['clean_fetch'] ) );
            foreach ( array( 'added' => 'FAQs added', 'updated' => 'FAQs updated', 'removed' => 'FAQs removed' ) as $k => $label ) {
                if ( $faqs[ $k ] > 0 ) {
                    $changed[ $label ] = array( 'from' => '', 'to' => (string) $faqs[ $k ] );
                }
            }
        }

        update_post_meta( $post_id, self::META_UPDATED_AT, time() );

        return array( 'changed' => $changed, 'faqs' => $faqs );
    }

    /* ---------------------------------------------------------------------
     * FAQ synchronisation
     * ------------------------------------------------------------------- */

    /**
     * Bring an event's FAQ rows into line with what the source sent.
     *
     * ============================================================
     * THIS IS A DELIBERATE EXCEPTION TO THE LOCAL-FIELDS RULE.
     * DO NOT "FIX" IT BACK.
     * ============================================================
     *
     * Everywhere else in this file, a fetch protects what a person has typed:
     * "empty in, leave alone", a hand-written description survives, a chosen
     * image wins. FAQs imported from a platform are different on purpose,
     * because the platform's FAQ is the answer of record — if the source
     * changes its refund policy and this calendar keeps showing the old one
     * because somebody once corrected a typo in it, the calendar is lying to
     * attendees. So an imported row IS overwritten from source, local edits
     * to it and all.
     *
     * The protection is scoped rather than removed: source wins over its OWN
     * rows only, identified by the source_faq_id stored on each of them.
     *
     *   - Stored ID present in the response  → question, answer and position
     *                                          refreshed from source. Local
     *                                          edits to that row are lost.
     *                                          Intended.
     *   - Response ID with no stored row     → added.
     *   - Stored ID absent from the response → removed, but ONLY under the
     *                                          clean-fetch guard below.
     *   - Row with NO source_faq_id          → a person wrote it. Never
     *                                          touched, never reordered away,
     *                                          never removed. Appended after
     *                                          the imported rows.
     *
     * THE CLEAN-FETCH GUARD is the same one that governs taking an event off
     * the calendar (see handle_removals): "missing from the response" and "the
     * request went wrong" look identical from here, and guessing wrong throws
     * away content. Rows are removed only when the caller confirms a complete
     * run, the adapter confirms it read the whole FAQ list, and that list is
     * not empty. An empty list never removes anything — same rule as the event
     * removal step, and for the same reason. The cost is that a campaign which
     * genuinely deletes every FAQ keeps them here until one is added back; that
     * is the direction worth failing in.
     *
     * @param int   $post_id
     * @param array $event       Common event shape, optionally carrying faqs / faqs_clean.
     * @param bool  $clean_fetch Whether the run may conclude something has gone.
     * @return array {added:int, updated:int, removed:int}
     */
    public static function sync_faqs( $post_id, $event, $clean_fetch ) {
        $counts = array( 'added' => 0, 'updated' => 0, 'removed' => 0 );

        // No 'faqs' key at all means this fetch has nothing to say about FAQs
        // — either the adapter does not do them or the lookup failed. Either
        // way the event's rows are left exactly as they are.
        if ( ! isset( $event['faqs'] ) || ! is_array( $event['faqs'] ) ) {
            return $counts;
        }

        $post_id = (int) $post_id;

        /*
         * ONE KEY, AND THE ID MATCHING IS UNAFFECTED BY THAT.
         *
         * Imported rows are identified by the source_faq_id they carry, never
         * by which meta key they sit in, so collapsing three keys into one
         * changed nothing about how a refresh finds the row it has to update.
         * What it did remove is the question this used to have to answer first
         * — "is this event a series child, a parent or standalone?" — which
         * decided the key and could be answered differently by the importer
         * and the editor.
         */
        $meta_key = sfaf_faq_meta_key();
        $existing = sfaf_get_faqs( $post_id );

        // Incoming rows, sanitized exactly as a hand-typed row is by the two
        // editors, so an imported row and a manual one are indistinguishable
        // once stored and render identically.
        $incoming = array();
        foreach ( $event['faqs'] as $row ) {
            if ( ! is_array( $row ) || empty( $row[ self::FAQ_SOURCE_ID ] ) ) {
                continue;
            }
            $question = sanitize_text_field( isset( $row['question'] ) ? $row['question'] : '' );
            // KEPT AS MARKUP, NOT STRIPPED (3.44.0). An answer is rich text now,
            // and a platform that sends HTML had its paragraph boundaries
            // removed here, which is the joining fault in the storing direction.
            $answer   = SFAF_Rich_Text::sanitize( isset( $row['answer'] ) ? $row['answer'] : '' );

            // A row that sanitizes down to nothing would be dropped again by
            // sfaf_normalize_faqs() on the next read, and so be counted as
            // "added" on every fetch forever. Skip it here instead.
            if ( '' === $question && '' === $answer ) {
                continue;
            }

            $incoming[] = array(
                'question'          => $question,
                'answer'            => $answer,
                self::FAQ_SOURCE_ID => sanitize_text_field( (string) $row[ self::FAQ_SOURCE_ID ] ),
            );
        }

        $incoming_ids = array();
        foreach ( $incoming as $row ) {
            $incoming_ids[ $row[ self::FAQ_SOURCE_ID ] ] = true;
        }

        // Split what is already stored into the platform's rows and people's.
        $stored_by_id = array();
        $manual       = array();
        foreach ( $existing as $row ) {
            $id = isset( $row[ self::FAQ_SOURCE_ID ] ) ? (string) $row[ self::FAQ_SOURCE_ID ] : '';
            if ( '' === $id ) {
                $manual[] = $row;
            } else {
                $stored_by_id[ $id ] = $row;
            }
        }

        $may_remove = $clean_fetch && ! empty( $event['faqs_clean'] ) && ! empty( $incoming );

        // Imported rows first, in the source's order.
        $out = array();
        foreach ( $incoming as $row ) {
            $id = $row[ self::FAQ_SOURCE_ID ];
            if ( isset( $stored_by_id[ $id ] ) ) {
                $was = $stored_by_id[ $id ];
                if ( $was['question'] !== $row['question'] || $was['answer'] !== $row['answer'] ) {
                    $counts['updated']++;
                }
            } else {
                $counts['added']++;
            }
            $out[] = $row;
        }

        // Rows the source no longer lists.
        foreach ( $stored_by_id as $id => $row ) {
            if ( isset( $incoming_ids[ $id ] ) ) {
                continue;
            }
            if ( $may_remove ) {
                $counts['removed']++;
                continue;
            }
            $out[] = $row; // guard says no — keep it
        }

        // People's rows last, untouched and in their own order.
        foreach ( $manual as $row ) {
            $out[] = $row;
        }

        // Ordering alone is a real change worth writing, so compare the whole
        // list rather than only the counters.
        if ( $out !== $existing ) {
            update_post_meta( $post_id, $meta_key, $out );
        }

        return $counts;
    }

    /** A short, single-line version of a value, for change reports. */
    private static function excerpt( $value ) {
        $value = trim( preg_replace( '/\s+/', ' ', sfaf_flatten_html( (string) $value ) ) );
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
            'refused'      => 0,
            'unpublished'  => 0,
            'ended'        => 0,
            'vanished'     => 0,
            'reappeared'   => 0,
            'removal_ran'  => false,
            'removal_skip' => '',
            'faq_added'    => 0,
            'faq_updated'  => 0,
            'faq_removed'  => 0,
            'error'        => (string) $error,
            'notes'        => array(),
            'changed'      => array(),
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
            /*
             * NOT AN EVENT, AND NOT A FAULT. Counted apart from 'invalid',
             * which means the source sent something this code could not read.
             * A refused item was read perfectly well and is simply not an
             * event: a general fundraiser, or something whose date has gone.
             */
            'refused'       => 0,
            'unpublished'   => 0,
            'ended'         => 0,
            'vanished'      => 0,
            'reappeared'    => 0,
            'removal_ran'   => false,
            'removal_skip'  => '',
            'faq_added'     => 0,
            'faq_updated'   => 0,
            'faq_removed'   => 0,
            'error'         => '',
            'notes'         => array(),
            /*
             * WHICH EVENTS MOVED, BY NAME.
             *
             * The report counted four updated and never said which four, which
             * is the one thing a manager cannot reconstruct: a source quietly
             * overwriting a date or a description is exactly what they are
             * reading this panel to catch. Each entry is
             * { id, title, kind: new|updated, fields: [] }.
             *
             * It replaces new_ids/updated_ids, which carried post IDs nothing
             * ever read, and the images[] diagnostic, which existed only to
             * answer the GoFundMe Pro image question. That answer is known and
             * permanent, so the collection goes with the table.
             */
            'changed'       => array(),
        );

        $response = $adapter->fetch();

        if ( is_wp_error( $response ) ) {
            $result['error'] = $response->get_error_message();
            $adapter->after_run( $result );
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

        // Whether this run may conclude that something missing from it has
        // really gone. It is the adapter's own word, decided before the loop
        // so every event in the run is judged by the same standard, and it is
        // the first half of the test handle_removals() applies. The second
        // half — that the run produced at least one usable event — is true by
        // construction for any event we are actually updating.
        $clean_fetch = is_array( $response ) && ! empty( $response['complete'] );

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
                        'Back at the source: "%s" reappeared and has been refreshed, but is left as a draft. Republish it by hand if it should go live again.',
                        isset( $event['title'] ) ? $event['title'] : $event['external_id']
                    );
                }

                $update = self::update_event( $existing, $event, array( 'clean_fetch' => $clean_fetch ) );
                if ( is_wp_error( $update ) ) {
                    $result['failed']++;
                    $result['notes'][] = sprintf( 'Could not refresh #%d: %s', $existing, $update->get_error_message() );
                    continue;
                }

                $extra = $adapter->after_save( $existing, $event, false );
                if ( is_array( $extra ) && ! empty( $extra ) ) {
                    $update['changed'] = array_merge( $update['changed'], $extra );
                }

                if ( ! empty( $update['faqs'] ) ) {
                    $result['faq_added']   += (int) $update['faqs']['added'];
                    $result['faq_updated'] += (int) $update['faqs']['updated'];
                    $result['faq_removed'] += (int) $update['faqs']['removed'];
                }

                if ( empty( $update['changed'] ) ) {
                    $result['unchanged']++;
                } else {
                    $result['updated']++;
                    $result['changed'][] = array(
                        'id'     => (int) $existing,
                        'title'  => isset( $event['title'] ) ? (string) $event['title'] : (string) $event['external_id'],
                        'kind'   => 'updated',
                        // The keys ARE the labels: update_event() names them
                        // "Title", "Description", "Source image" and so on, so
                        // the panel prints what changed without a second list
                        // that could fall behind the first.
                        'fields' => array_keys( $update['changed'] ),
                    );
                }
                continue;
            }

            /*
             * NOTHING EXISTS FOR THIS ITEM YET, so this is the one moment a
             * row would be created and the only place the rule is applied.
             * Everything above this line has already happened: the id is in
             * $seen_ids, so the campaign still counts as present at the source
             * and nothing already imported is disturbed. See import_refusal().
             */
            $refusal = self::import_refusal( $event );
            if ( '' !== $refusal ) {
                $result['refused']++;
                $result['notes'][] = sprintf(
                    'Not imported: "%s", because %s.',
                    isset( $event['title'] ) && '' !== $event['title'] ? $event['title'] : $event['external_id'],
                    $refusal
                );
                continue;
            }

            $faq_counts = array();
            $post_id    = self::import_event( $event, $faq_counts );
            if ( is_wp_error( $post_id ) ) {
                $result['failed']++;
                $result['notes'][] = sprintf(
                    'Could not save "%s": %s',
                    isset( $event['title'] ) ? $event['title'] : $event['external_id'],
                    $post_id->get_error_message()
                );
                continue;
            }

            $adapter->after_save( $post_id, $event, true );

            $result['new']++;
            $result['changed'][] = array(
                'id'     => (int) $post_id,
                'title'  => isset( $event['title'] ) ? (string) $event['title'] : (string) $event['external_id'],
                'kind'   => 'new',
                'fields' => array(),
            );
            if ( ! empty( $faq_counts['added'] ) ) {
                $result['faq_added'] += (int) $faq_counts['added'];
            }
        }

        self::handle_removals( $adapter, $response, $seen_ids, $result );

        $adapter->after_run( $result );

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
                '%s: "%s" %s and has been made a draft. It is off the calendar but kept, and can be republished.',
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
     * @param string $trigger 'manual' (Fetch updates) or 'scheduled' (the
     *                        runner). Only a scheduled run asks an adapter's
     *                        runs_unattended(), since 3.101.0.
     * @return array One result array per active source.
     */
    public static function run_all( $trigger = 'manual' ) {
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
            if ( 'scheduled' === $trigger && ! $adapter->runs_unattended() ) {
                $held         = self::blank_result( $adapter, true, (string) $adapter->unattended_off_reason(), '' );
                $held['held'] = true;
                $results[]    = $held;
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
     * A one-line summary of a run, in the words a manager would use.
     *
     * WHAT THIS USED TO SAY, AND WHY IT CHANGED. Every run printed the full
     * counter set whether or not anything had happened, so a quiet fetch read
     * "GoFundMe Pro: 0 new, 4 updated, 19 unchanged (of 23 fetched)". Five
     * numbers, four of them zero or irrelevant, and the reader still had to
     * work out whether that was a good outcome.
     *
     * The rule now is QUIET SUCCESS, LOUD FAILURE. Nothing happening is one
     * short clause. Something happening names only what happened. A source
     * that failed, timed out, or went silent keeps its own sentence and is
     * marked at the row so it cannot be skimmed past.
     *
     * @param array $result One entry from run_all().
     * @return string
     */
    public static function summarize( $result ) {
        // Connected, and held back from the unattended run by its own switch.
        if ( ! empty( $result['held'] ) ) {
            return sprintf( '%s: %s A manual fetch still reads it.', $result['label'], $result['reason'] );
        }
        if ( ! empty( $result['skipped'] ) ) {
            return sprintf( '%s: not connected. %s', $result['label'], $result['reason'] );
        }

        if ( '' !== $result['error'] ) {
            return sprintf( '%s: failed. %s', $result['label'], $result['error'] );
        }

        // A source that ran and found nothing has to say so in its own words:
        // silence here and "nothing new" mean very different things, and a
        // platform that has stopped answering must not read as a quiet week.
        if ( 0 === (int) $result['fetched'] ) {
            return sprintf( '%s: connected, but the source returned nothing at all.', $result['label'] );
        }

        $moved = array();
        if ( $result['new'] ) {
            $moved[] = sprintf( '%d %s added', (int) $result['new'], self::plural( (int) $result['new'], 'event' ) );
        }
        if ( $result['updated'] ) {
            $moved[] = sprintf( '%d %s updated', (int) $result['updated'], self::plural( (int) $result['updated'], 'event' ) );
        }
        if ( $result['unpublished'] ) {
            $moved[] = sprintf( '%d taken off the calendar', (int) $result['unpublished'] );
        }
        if ( $result['reappeared'] ) {
            $moved[] = sprintf( '%d back at the source', (int) $result['reappeared'] );
        }
        if ( $result['failed'] ) {
            $moved[] = sprintf( '%d could not be saved', (int) $result['failed'] );
        }
        if ( $result['invalid'] ) {
            $moved[] = sprintf( '%d could not be read', (int) $result['invalid'] );
        }
        /*
         * SAID OUT LOUD, because a refusal is invisible otherwise: no row
         * appears and the count of new events simply stays where it was. On a
         * source where most campaigns are not events that is most of the run,
         * and somebody watching for an event that never arrived needs the
         * number here before they go looking in the notes for the reason.
         *
         * Not folded in with "could not be read". Nothing went wrong with
         * these; they were read correctly and are not events.
         */
        if ( isset( $result['refused'] ) && $result['refused'] ) {
            $moved[] = sprintf( '%d not an event or already past', (int) $result['refused'] );
        }

        $checked = sprintf( '%d checked.', (int) $result['fetched'] );

        if ( empty( $moved ) ) {
            return sprintf( '%s: nothing new. %s', $result['label'], $checked );
        }

        return sprintf( '%s: %s. %s', $result['label'], implode( ', ', $moved ), $checked );
    }

    /**
     * Singular or plural for a counted noun.
     *
     * @param int    $n
     * @param string $word
     * @return string
     */
    private static function plural( $n, $word ) {
        return ( 1 === (int) $n ) ? $word : $word . 's';
    }

    /**
     * The fields that changed on one event, as a readable phrase.
     *
     * update_event() keys its change list by label already ("Title",
     * "Description", "Source image"), so most of these pass straight through.
     * The exceptions are the adapter's own meta extras, which are keyed by
     * meta key because that is what the writer had: those get a name here
     * rather than showing a manager "_uc_gofundme_goal".
     *
     * @param string[] $fields
     * @return string
     */
    public static function field_change_phrase( $fields ) {
        $names = array(
            '_uc_gofundme_url'    => 'donate link',
            '_uc_gofundme_goal'   => 'fundraising goal',
            '_uc_gofundme_raised' => 'amount raised',
        );

        $out = array();
        foreach ( (array) $fields as $field ) {
            $field = (string) $field;
            if ( isset( $names[ $field ] ) ) {
                $out[] = $names[ $field ];
                continue;
            }
            // An unmapped meta key would otherwise reach the screen raw.
            if ( 0 === strpos( $field, '_uc_' ) ) {
                $out[] = str_replace( '_', ' ', substr( $field, 4 ) );
                continue;
            }
            $out[] = strtolower( $field );
        }

        $out = array_values( array_unique( array_filter( $out ) ) );
        if ( empty( $out ) ) {
            return '';
        }
        if ( 1 === count( $out ) ) {
            return $out[0];
        }
        $last = array_pop( $out );
        if ( 1 === count( $out ) ) {
            return $out[0] . " and " . $last;
        }
        // The serial comma, SFAF house style: "an image, a description, and a
        // category". phrase() in portal.js is the same joining on the browser
        // side and carries the same rule.
        return implode( ", ", $out ) . ", and " . $last;
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

        /*
         * SPENT ROWS ARE NOT SHOWN, in either queue.
         *
         * The sweep moves an expired PENDING row to dismissed, but it runs on
         * the scheduled runner and this screen may be opened between two runs.
         * Filtering here as well means a row that expired an hour ago is gone
         * the moment somebody looks, rather than gone whenever the runner next
         * fires, and it is what makes an already-dismissed expired row vanish
         * from the Dismissed list, which no status move could do.
         *
         * BOTH ARMS ARE NEEDED and they are not redundant. This one decides
         * what is SHOWN; the sweep decides what is STORED, which is what stops
         * the next fetch treating the row as new. Either alone leaves one of
         * the two problems standing.
         *
         * The meta cache is primed by the query above, so this costs no
         * queries. Queue statuses only, so it cannot reach a published event.
         */
        $ids = array_values( array_filter( $ids, function ( $id ) {
            return ! self::queue_row_is_spent( $id );
        } ) );

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

    /* ---------------------------------------------------------------------
     * What belongs on an events calendar
     *
     * MARK'S RULE, IN ONE PLACE: the only date that matters is when the event
     * takes place. Something with no event date is not an event, and something
     * whose date has gone is not a decision anybody still has to make.
     *
     * There is ONE definition of "past" here and both users of it read this,
     * so the import gate and the queue sweep cannot come to disagree about
     * which day an event stops counting.
     * ------------------------------------------------------------------- */

    /**
     * Item kinds whose end date really is the end of an event.
     *
     * ONE LIST, READ BY BOTH THINGS THAT ASK. The import gate asks it to decide
     * what may become an event; last_day() asks it to decide whether an end
     * date can be believed. Two lists would eventually disagree about a type
     * and the disagreement would be invisible.
     *
     *   event                                 Eventbrite. Everything it
     *                                         returns is an event, and its
     *                                         end really is the event's end.
     *   ticketed, registration,
     *   reg_w_fund, fund_for_entry            GoFundMe Pro's four event-shaped
     *                                         campaign types, from the `type`
     *                                         enum on its Campaign schema.
     *
     * AN UNRECOGNISED TYPE IS NOT ON THIS LIST, and a type the platform adds
     * later will not default into being treated as an event.
     */
    const EVENT_TYPES = array( 'event', 'ticketed', 'registration', 'reg_w_fund', 'fund_for_entry' );

    /** Does this source's own word for an item's kind mean "an event"? */
    public static function type_is_event_shaped( $type ) {
        $type = strtolower( trim( (string) $type ) );
        return ( '' !== $type && in_array( $type, self::EVENT_TYPES, true ) );
    }

    /**
     * The last day an item occupies the calendar.
     *
     * THE END DATE ONLY WHEN THE END DATE MEANS THAT. This is the correction
     * 3.58.0 should have made and did not.
     *
     * What the previous comment here said was that a conference running
     * Thursday to Sunday is not over on Friday, so the end date wins when there
     * is one. That is true of a conference and false of most of what arrives.
     * `_uc_end_date` is written only by a source, and on a GoFundMe Pro
     * campaign it holds `ended_at`, which the platform documents as "Date/time
     * of when the campaign ends" — the close of the FUNDRAISING WINDOW. A
     * donation page collecting until December therefore reported a December
     * last day and never cleared, while the screen showed a start date months
     * past. Four rows sat in the queues on exactly that.
     *
     * It is the same finding as 3.58.0 one field over: `started_at` was
     * established there as a fundraising-window boundary rather than an event
     * date, and this rule was then built on `ended_at` without applying it. The
     * comment claimed an event semantics the data does not carry, which is the
     * more expensive half of the mistake — code can be read, but a comment
     * asserting a meaning is believed.
     *
     * SO THE END DATE IS BELIEVED ONLY WHERE THE TYPE SAYS IT CAN BE:
     *
     *   type is event-shaped   the end date when there is one, else the start.
     *                          A real multi-day event still waits for its last
     *                          day, which is what the old comment wanted.
     *   type is anything else  the start date.
     *   TYPE IS UNKNOWN        the start date. Every row imported before
     *                          3.59.0 is in this case, because nothing stored
     *                          the type until then, and the start is the only
     *                          field whose meaning is reliable on those rows.
     *
     * Unknown resolving to the start date is deliberate and is the opposite of
     * what the IMPORT gate does with an unknown type, which imports it. The two
     * are not inconsistent: each errs towards the outcome a person can see and
     * undo. A stray row in a queue is one click to dismiss; an event silently
     * refused is invisible. Here, a row cleared a little early is visible in
     * Dismissed and restorable, while a row that never clears is the fault
     * being fixed.
     *
     * @param string $date     Y-m-d start.
     * @param string $end_date Y-m-d end, or ''.
     * @param string $type     The source's word for the kind, or '' if unknown.
     * @return string Y-m-d, or '' when there is no date at all.
     */
    public static function last_day( $date, $end_date = '', $type = '' ) {
        $date     = trim( (string) $date );
        $end_date = trim( (string) $end_date );

        if ( '' === $date ) {
            return '';
        }

        if ( ! self::type_is_event_shaped( $type ) ) {
            return $date;
        }

        // A nonsense end date earlier than the start is ignored rather than
        // trusted; the start is the one the calendar actually shows.
        return ( '' !== $end_date && $end_date >= $date ) ? $end_date : $date;
    }

    /** Today, in the site's timezone. Never the server's. */
    public static function today() {
        $now = new DateTime( 'now', wp_timezone() );
        return $now->format( 'Y-m-d' );
    }

    /**
     * Has this event's date gone by?
     *
     * TODAY IS NOT PAST. An event happening this afternoon is still an event,
     * and a queue row for it is still a decision. Only strictly earlier dates
     * count, which is why this compares Y-m-d strings rather than timestamps:
     * there is no time of day at which today becomes yesterday.
     *
     * A dateless event is NOT past. It has no date to have gone by, and the
     * two cases are refused for different reasons and say different things.
     * In the queue that means a dateless row STAYS: filling the date in is the
     * job somebody is there to do, and sweeping it out would remove the work
     * item for being the thing they were about to do.
     *
     * @param string $date
     * @param string $end_date
     * @param string $type     The source's word for the kind, or '' if unknown.
     * @return bool
     */
    public static function date_has_passed( $date, $end_date = '', $type = '' ) {
        $last = self::last_day( $date, $end_date, $type );
        if ( '' === $last ) {
            return false;
        }
        /*
         * THE DAY HAS TO BE OVER, NOT STARTED. Both sides are Y-m-d and the
         * test is strictly earlier, so an event happening today is never past:
         * today == today is not "<". A Thursday event stays all Thursday
         * whatever time it runs, and goes at the first sweep after midnight —
         * within a quarter of an hour of it, since the runner is on fifteen
         * minutes. Comparing timestamps instead would have cleared an evening
         * event at breakfast.
         */
        return $last < self::today();
    }

    /**
     * Why this item must not become a new event, or '' when it may.
     *
     * APPLIED ONLY WHERE A ROW WOULD BE CREATED, and that placement is the
     * whole safety of it. Called from the branch in run_adapter() that has
     * already established there is no existing event, so:
     *
     *   - NOTHING ALREADY IMPORTED IS RE-JUDGED. An event that is published,
     *     or sitting in a queue, or dismissed, is a decision somebody already
     *     made and is not revisited because a rule arrived later.
     *   - THE REMOVAL SAFEGUARD IS UNTOUCHED. The item's id is added to
     *     $seen_ids before this is asked, so a campaign refused here is still
     *     "present at the source" as far as handle_removals() is concerned.
     *     Refusing in normalize() instead would drop the id out of that set
     *     and make a live campaign look deleted, which would unpublish the
     *     very published events this must not reach.
     *
     * THE ADAPTER'S OWN VERDICT COMES FIRST because it is the more specific
     * fact. A GoFundMe Pro donation page is not an event whatever date it
     * carries, and saying "its date has passed" about one would be true and
     * beside the point.
     *
     * @param array $event Normalized event.
     * @return string Reason, or '' to import.
     */
    public static function import_refusal( $event ) {
        if ( ! is_array( $event ) ) {
            return 'it could not be read';
        }

        // Set by an adapter that can tell an event from something else its
        // platform also calls a campaign. Absent means the adapter has no
        // opinion, which is the right default for a source that only ever
        // returns events.
        if ( ! empty( $event['not_an_event'] ) ) {
            return (string) $event['not_an_event'];
        }

        $date = isset( $event['start_date'] ) ? (string) $event['start_date'] : '';
        $end  = isset( $event['end_date'] ) ? (string) $event['end_date'] : '';
        // The item's own type, straight from the adapter. At import it is
        // always known, so a genuine multi-day event is judged by its end.
        $type = isset( $event['source_type'] ) ? (string) $event['source_type'] : '';

        if ( '' === trim( $date ) ) {
            return 'it has no event date';
        }

        if ( self::date_has_passed( $date, $end, $type ) ) {
            return sprintf( 'its date (%s) has already passed', self::last_day( $date, $end, $type ) );
        }

        return '';
    }

    /**
     * Is this queue row finished with?
     *
     * QUEUES ONLY. Every caller is a queue query, and the two queue statuses
     * are the only ones any of them ask for, so this cannot reach a published
     * event by any route. That boundary is the one thing about the 2026-07-29
     * agreement that must survive: a PUBLISHED event that expires is simply a
     * past event and stays exactly where it is.
     *
     * @param int $post_id
     * @return bool
     */
    public static function queue_row_is_spent( $post_id ) {
        $post_id = (int) $post_id;

        // Gone from the source. Defensive rather than load-bearing: a PENDING
        // row that vanishes is already made a draft by handle_removals(), so
        // it has left the queue by another door. A dismissed row is never
        // marked, because removal does not look at dismissed events and this
        // build does not change what it looks at.
        if ( get_post_meta( $post_id, self::META_REMOVED_AT, true ) ) {
            return true;
        }

        /*
         * THE TYPE DECIDES WHETHER THE END DATE COUNTS. Empty on every row
         * imported before 3.59.0 and on every campaign the source has stopped
         * returning, and empty means "judge on the start date". See last_day().
         */
        return self::date_has_passed(
            (string) get_post_meta( $post_id, '_uc_event_date', true ),
            (string) get_post_meta( $post_id, '_uc_end_date', true ),
            (string) get_post_meta( $post_id, self::META_SOURCE_TYPE, true )
        );
    }

    /**
     * Clear spent rows out of the decision queues.
     *
     * THE LAST PIECE OF THE ORIGINAL IMPORT DESIGN, agreed 2026-07-29 and
     * recorded in PROJECT.md §8. Pending and Dismissed are queues of decisions.
     * An event whose date has gone is not a decision anybody still has to make,
     * so leaving it there is clutter that hides the rows that do need somebody.
     *
     * WHAT "DISAPPEARS" MEANS IN STORAGE: a spent PENDING row is moved to
     * dismissed. Nothing is deleted and nothing is trashed.
     *
     *   - IT CANNOT COME BACK. `uc_dismissed` is in all_statuses(), which is
     *     what find_existing() searches, so the next fetch matches this row
     *     and takes the "not updatable" branch instead of creating a new one.
     *     Deleting it would have let the very next fetch import it again, and
     *     trashing it would have worked for thirty days and then let WordPress
     *     empty the trash and the fetch re-import it.
     *   - IT IS STILL THERE. Dismissed is an existing, visible, reversible
     *     state with a Restore button already on it.
     *
     * A row that is ALREADY dismissed is left exactly as it is: it is in the
     * right status, and queue_ids() stops showing it. There is nowhere better
     * for it to go and moving it would only churn post_modified.
     *
     * PUBLISHED EVENTS ARE NOT REACHABLE FROM HERE. The query names the two
     * queue statuses and nothing else, so a published event that expires stays
     * published and simply becomes a past event, which is the distinction the
     * agreement is most explicit about.
     *
     * @return array{status:string,summary:string,counts:array}
     */
    public static function sweep_queues() {
        $query = new WP_Query( array(
            'post_type'              => 'uc_event',
            // The two decision queues, named. This list is the boundary.
            'post_status'            => array( self::STATUS_PENDING, self::STATUS_DISMISSED ),
            'posts_per_page'         => 500,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ) );

        $dismissed = 0;
        $hidden    = 0;

        foreach ( $query->posts as $post_id ) {
            $post_id = (int) $post_id;
            if ( ! self::queue_row_is_spent( $post_id ) ) {
                continue;
            }

            if ( self::STATUS_DISMISSED === get_post_status( $post_id ) ) {
                $hidden++;   // already where it belongs
                continue;
            }

            wp_update_post( array( 'ID' => $post_id, 'post_status' => self::STATUS_DISMISSED ) );
            $dismissed++;
        }

        if ( 0 === $dismissed && 0 === $hidden ) {
            $summary = 'Queues: nothing to clear.';
        } else {
            $summary = sprintf(
                'Queues: %d moved to dismissed, %d already dismissed and now hidden.',
                $dismissed,
                $hidden
            );
        }

        return array(
            'status'  => 'ok',
            'summary' => $summary,
            'counts'  => array( 'dismissed' => $dismissed, 'hidden' => $hidden ),
        );
    }

    /**
     * How many events sit in a queue status.
     *
     * COUNTED THE SAME WAY THE LIST IS DRAWN. This used to ask the database
     * for found_posts, which was cheaper and, once queue_ids() started hiding
     * spent rows, wrong: the badge said four and the list showed two. A count
     * beside a list must be a count OF that list, or somebody goes looking for
     * rows that are not there. Same reasoning as the scoped counts elsewhere.
     */
    public static function queue_count( $status ) {
        return count( self::queue_ids( $status ) );
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
                sprintf( '%s is not connected. %s', $adapter->label(), $adapter->inactive_reason() )
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

        // A single-event re-fetch that succeeded IS a complete answer about
        // that one event, so it carries the same authority over its FAQ rows
        // as a clean bulk run. The adapter still has to confirm it read the
        // whole FAQ list (faqs_clean) before anything is removed.
        $update = self::update_event( $post_id, $event, array( 'clean_fetch' => true ) );
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

    /**
     * Does this event take its registrations somewhere else? (3.97.0)
     *
     * THE ONE READER OF THIS RULE, and every surface asks it: the editor
     * control, the save that refuses, the capacity boxes that hide, the event
     * page's primary action and the one-time pass on install. Written once so
     * "which events cannot take RSVPs here" has one answer and cannot be true
     * on the editor and false in the save.
     *
     * IT KEYS ON THE IMPORT SOURCE AND NOTHING ELSE. A GoFundMe Pro campaign or
     * an Eventbrite listing takes registrations on its own page: that is where
     * the tickets are, where the capacity is counted and where the attendee
     * list lives. A second registration here would be a second list nobody
     * reconciles, and somebody who registered on this calendar would not be on
     * the platform's door list.
     *
     * A HAND-MADE EVENT IS UNTOUCHED WHATEVER LINKS IT CARRIES. An event with a
     * Donate URL, an external marker or a registration link typed into its
     * description is still this calendar's own event, and its manager may still
     * take names here. Keying on anything but the import source would lock
     * events nobody imported, which is the fault worth being most careful
     * about: it takes a working feature away from somebody who was using it.
     *
     * @param int $post_id
     * @return bool
     */
    public static function takes_rsvps_at_source( $post_id ) {
        return ( '' !== (string) get_post_meta( (int) $post_id, self::META_SOURCE, true ) );
    }

    /**
     * Where somebody registers instead, when the source owns registration.
     *
     * THE PAGE THE IMPORT ALREADY CARRIES. `source_url` is in both adapters'
     * owned_fields(), so it is written on every fetch and is always the current
     * address: nothing new is stored for this and nothing can go stale.
     *
     * Returns '' when there is no source or no URL, and the caller draws
     * nothing rather than an empty button.
     *
     * @param int $post_id
     * @return string
     */
    public static function registration_url( $post_id ) {
        if ( ! self::takes_rsvps_at_source( $post_id ) ) {
            return '';
        }
        return (string) get_post_meta( (int) $post_id, self::META_SOURCE_URL, true );
    }
}
