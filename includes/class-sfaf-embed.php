<?php
/**
 * Public embed endpoint.
 *
 * Serves the calendar as rendered HTML to pages on other domains. The markup is
 * produced by SFAF_Shortcodes, the same renderer the shortcode uses, so an embed
 * and a shortcode can never show different cards.
 *
 * What goes out is only what a visitor already sees on a public event page:
 * titles, dates, locations, images and aggregate RSVP counts. No attendee name,
 * email or phone number is ever part of the card markup.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Embed {

    /** REST namespace and route. */
    const REST_NAMESPACE = 'sfaf-calendar/v1';
    const REST_ROUTE     = '/embed';

    /**
     * Option holding the cache generation counter.
     *
     * Cached responses are keyed by this number rather than deleted one by one:
     * every filter combination any site asks for is its own transient, and there
     * is no way to enumerate them under a persistent object cache. Bumping the
     * counter orphans the whole previous generation at once, and the orphans
     * expire on their own.
     */
    const CACHE_VERSION_OPTION = 'sfaf_embed_cache_version';

    /** @var SFAF_Shortcodes */
    private $shortcodes;

    public function __construct( SFAF_Shortcodes $shortcodes ) {
        $this->shortcodes = $shortcodes;
    }

    public function register() {
        add_action( 'rest_api_init', array( $this, 'register_route' ) );

        // CORS is sent three ways so it survives as many setups as possible, all
        // scoped to the embed route (the key-gated /events feed is never touched):
        //
        //   1. On the WP_REST_Response object itself (handle_request + preflight).
        //      Core emits these via send_headers() early in serve_request, before
        //      the body, and REST cache plugins store them with the response — so
        //      a cached hit still carries them.
        //   2. An explicit OPTIONS preflight short-circuit (rest_pre_dispatch),
        //      answered before core's generic OPTIONS handler.
        //   3. A final header() pass on rest_pre_serve_request, which also covers
        //      error/edge responses that never reach our callback.
        add_filter( 'rest_pre_dispatch', array( $this, 'handle_preflight' ), 9, 3 );
        add_filter( 'rest_pre_serve_request', array( $this, 'send_embed_headers' ), 20, 3 );

        // Cache invalidation. Anything that can change a rendered card bumps the
        // generation counter, so the next request re-renders.
        add_action( 'save_post_uc_event', array( $this, 'flush_cache_on_save' ), 10, 2 );
        add_action( 'deleted_post', array( $this, 'flush_cache_on_delete' ), 10, 2 );
        add_action( 'trashed_post', array( $this, 'flush_cache_on_status_change' ) );
        add_action( 'untrashed_post', array( $this, 'flush_cache_on_status_change' ) );
        add_action( 'created_term', array( $this, 'flush_cache_for_term' ), 10, 3 );
        add_action( 'edited_term', array( $this, 'flush_cache_for_term' ), 10, 3 );
        add_action( 'delete_term', array( $this, 'flush_cache_for_term' ), 10, 3 );
        // Category colour is written on the taxonomy-specific hook, which fires
        // after edited_term — flush again there, late, or a request landing in
        // between could cache the old colour back in.
        add_action( 'created_uc_event_category', array( $this, 'flush_cache' ), 99 );
        add_action( 'edited_uc_event_category', array( $this, 'flush_cache' ), 99 );
        add_action( 'update_option_uc_settings', array( $this, 'flush_cache' ) );

        // Cards show a live RSVP count, so a new registration dates them too.
        add_action( 'uc_rsvp_submitted', array( $this, 'flush_cache' ) );
    }

    /* ---------------------------------------------------------------------
     * URLs (shared with the generator screen)
     * ------------------------------------------------------------------- */

    /** URL of the embed script that remote sites load. */
    public static function script_url() {
        return SFAF_PLUGIN_URL . 'public/js/embed.js?ver=' . SFAF_VERSION;
    }

    /** URL of the embed endpoint itself. */
    public static function endpoint_url() {
        return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
    }

    /** Where to send a visitor when an embed can't load: the calendar here. */
    public static function calendar_url() {
        $archive = get_post_type_archive_link( 'uc_event' );
        return $archive ? $archive : home_url( '/' );
    }

    /* ---------------------------------------------------------------------
     * Route
     * ------------------------------------------------------------------- */

    public function register_route() {
        register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_request' ),
            // Public on purpose: this is the same event information the site
            // already publishes, and remote pages have no way to authenticate.
            'permission_callback' => '__return_true',
            'args'                => array(
                'category'     => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
                'organizer'    => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
                'venue'        => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
                'series'       => array( 'type' => 'integer', 'default' => 0,       'sanitize_callback' => 'absint' ),
                'per_page'     => array( 'type' => 'integer', 'default' => 0,       'sanitize_callback' => 'intval' ),
                'page'         => array( 'type' => 'integer', 'default' => 1,       'sanitize_callback' => 'absint' ),
                'show_filters' => array( 'type' => 'string',  'default' => 'yes',   'sanitize_callback' => 'sanitize_text_field' ),
                'layout'       => array( 'type' => 'string',  'default' => 'cards', 'sanitize_callback' => 'sanitize_text_field' ),
                // block = the whole calendar, items = just the cards for one
                // page. Empty picks for you: block for page 1, items after.
                'mode'         => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ) );
    }

    /**
     * GET /wp-json/sfaf-calendar/v1/embed
     *
     * Page 1 returns the whole calendar block (filters, count, list, paging
     * control) so the remote page has something complete to drop in. Later
     * pages return just the cards, for the load-more button to append.
     */
    public function handle_request( $request ) {
        $params = $this->normalize_params( $request );

        $payload = $this->get_cached( $params );
        if ( null === $payload ) {
            $payload = $this->build_payload( $params );
            $this->set_cached( $params, $payload );
        } else {
            $payload['cached'] = true;
        }

        $response = new WP_REST_Response( $payload, 200 );

        // Put CORS + browser-cache headers on the response object itself. Core
        // sends these via send_headers() before the body, and REST cache layers
        // store them with the response — the most reliable place for them.
        $this->apply_cors_to_response( $response, $request );

        $browser_ttl = (int) apply_filters( 'sfaf_embed_browser_cache_ttl', MINUTE_IN_SECONDS );
        if ( $browser_ttl > 0 ) {
            $response->header( 'Cache-Control', 'public, max-age=' . $browser_ttl );
        }

        return $response;
    }

    /**
     * Read the request into the parameter set the renderer expects.
     *
     * @return array
     */
    private function normalize_params( $request ) {
        $layout = ( $request->get_param( 'layout' ) === 'compact' ) ? 'compact' : 'cards';

        // "Show all with no pagination" is a shortcode-only option. On a public
        // endpoint an unbounded page is a free way to make the site do work, so
        // per_page always resolves to a real, capped number here.
        $max      = max( 1, (int) apply_filters( 'sfaf_embed_max_per_page', 100 ) );
        $per_page = (int) $request->get_param( 'per_page' );
        if ( $per_page <= 0 ) {
            $per_page = $this->shortcodes->resolve_per_page( '' );
        }
        if ( $per_page <= 0 ) {
            $per_page = 12;
        }

        $page = max( 1, absint( $request->get_param( 'page' ) ) );

        // Load-more appends cards, so it asks for items. Numbered pagination
        // replaces the whole thing, so it asks for a block on any page.
        $mode = (string) $request->get_param( 'mode' );
        if ( ! in_array( $mode, array( 'block', 'items' ), true ) ) {
            $mode = ( $page > 1 ) ? 'items' : 'block';
        }

        return array(
            'category'     => (string) $request->get_param( 'category' ),
            'organizer'    => (string) $request->get_param( 'organizer' ),
            'venue'        => (string) $request->get_param( 'venue' ),
            'series'       => absint( $request->get_param( 'series' ) ),
            'per_page'     => min( $per_page, $max ),
            'page'         => $page,
            'show_filters' => (string) $request->get_param( 'show_filters' ),
            'layout'       => $layout,
            'mode'         => $mode,
        );
    }

    /**
     * Render the response body.
     *
     * The embed context flag is set for exactly the length of the render: it
     * turns the RSVP and reminder controls into links to this site, because
     * neither can post to admin-ajax from another origin.
     */
    private function build_payload( $params ) {
        sfaf_set_embed_context( true );

        try {
            if ( $params['mode'] === 'items' ) {
                $events  = $this->shortcodes->render_events(
                    $params['per_page'],
                    $params['page'],
                    $params,
                    $params['layout'] === 'compact' ? 'compact' : 'card'
                );
                $payload = array(
                    'mode'      => 'items',
                    'html'      => $events['html'],
                    'total'     => $events['total'],
                    'page'      => $events['page'],
                    'max_pages' => $events['max_pages'],
                    'has_more'  => ( $events['page'] < $events['max_pages'] ),
                );
            } else {
                $block   = $this->shortcodes->render_calendar_block( $params );
                $payload = array(
                    'mode'      => 'block',
                    'html'      => $block['html'],
                    'total'     => $block['total'],
                    'page'      => $block['page'],
                    'max_pages' => $block['max_pages'],
                    'has_more'  => $block['has_more'],
                );
            }
        } finally {
            sfaf_set_embed_context( false );
        }

        $payload['per_page']     = $params['per_page'];
        $payload['calendar_url'] = self::calendar_url();
        $payload['cached']       = false;

        return $payload;
    }

    /* ---------------------------------------------------------------------
     * Cross-origin headers
     * ------------------------------------------------------------------- */

    /** True when a REST request targets the embed route (and only that route). */
    private function is_embed_request( $request ) {
        return ( $request instanceof WP_REST_Request )
            && $request->get_route() === '/' . self::REST_NAMESPACE . self::REST_ROUTE;
    }

    /**
     * The CORS header set for a request.
     *
     * Open to any origin by default — this is public, published event data with
     * no personal fields, and the sites that embed it are not all known in
     * advance. The allowed list is filterable so it can be narrowed later
     * without touching this file:
     *
     *     add_filter( 'sfaf_embed_allowed_origins', function () {
     *         return array( 'https://www.sfaf.org' );
     *     } );
     *
     * @return array<string,string> Header name => value.
     */
    private function cors_headers( $request = null ) {
        $origin  = get_http_origin();
        $allowed = (array) apply_filters( 'sfaf_embed_allowed_origins', array( '*' ), $request );

        // A simple GET needs no preflight, but declare the preflight answers too
        // so a stricter future request (or a proxy that forces one) still works.
        $headers = array(
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age'       => '600',
        );

        if ( in_array( '*', $allowed, true ) ) {
            $headers['Access-Control-Allow-Origin'] = '*';
        } elseif ( $origin && in_array( $origin, $allowed, true ) ) {
            // Reflecting one origin: caches must key on the Origin header.
            $headers['Access-Control-Allow-Origin'] = esc_url_raw( $origin );
            $headers['Vary']                        = 'Origin';
        } else {
            // Not allowed: no Allow-Origin, so the browser blocks the read.
            $headers['Vary'] = 'Origin';
        }

        return $headers;
    }

    /** Copy the CORS headers onto a WP_REST_Response object. */
    private function apply_cors_to_response( $response, $request ) {
        if ( ! $response instanceof WP_REST_Response ) {
            return;
        }
        // This endpoint never uses cookies; a stray Allow-Credentials would make
        // the wildcard origin invalid, so make sure it isn't set on our response.
        $response->remove_header( 'Access-Control-Allow-Credentials' );
        foreach ( $this->cors_headers( $request ) as $name => $value ) {
            $response->header( $name, $value );
        }
    }

    /**
     * Answer a CORS preflight (OPTIONS) for the embed route ourselves.
     *
     * Hooked to rest_pre_dispatch at priority 9 so it runs before core's generic
     * OPTIONS handler. Returning a response short-circuits dispatch with a 200,
     * the CORS headers, and no body.
     *
     * @param mixed           $result  Dispatch result (null to continue).
     * @param WP_REST_Server  $server  REST server.
     * @param WP_REST_Request $request Current request.
     * @return mixed
     */
    public function handle_preflight( $result, $server, $request ) {
        if ( null !== $result || ! $this->is_embed_request( $request ) ) {
            return $result;
        }
        if ( strtoupper( $request->get_method() ) !== 'OPTIONS' ) {
            return $result;
        }
        $response = new WP_REST_Response( null, 200 );
        $this->apply_cors_to_response( $response, $request );
        return $response;
    }

    /**
     * Final CORS pass on rest_pre_serve_request.
     *
     * The response-object headers (set in handle_request / handle_preflight) are
     * the primary mechanism; this is a fallback that also reaches error and
     * edge-case responses which never ran our callback. It emits via header()
     * with replace semantics, so it overrides core's reflected-origin CORS and
     * has the final say for our route.
     *
     * @param bool             $served  Whether the request was already served.
     * @param WP_HTTP_Response $result  Response object.
     * @param WP_REST_Request  $request Request object.
     * @return bool
     */
    public function send_embed_headers( $served, $result, $request ) {
        if ( ! $this->is_embed_request( $request ) || headers_sent() ) {
            return $served;
        }

        // Core's rest_send_cors_headers reflects the origin and adds
        // Allow-Credentials; drop that so our (possibly wildcard) origin is valid.
        header_remove( 'Access-Control-Allow-Credentials' );

        foreach ( $this->cors_headers( $request ) as $name => $value ) {
            // Vary must append (there may be other Vary values); the rest replace.
            header( $name . ': ' . $value, 'Vary' !== $name );
        }

        return $served;
    }

    /* ---------------------------------------------------------------------
     * Caching
     * ------------------------------------------------------------------- */

    /** How long a rendered response stays cached. 0 disables caching. */
    private function cache_ttl() {
        return (int) apply_filters( 'sfaf_embed_cache_ttl', 10 * MINUTE_IN_SECONDS );
    }

    /** Current cache generation. */
    private function cache_version() {
        return (int) get_option( self::CACHE_VERSION_OPTION, 1 );
    }

    /**
     * Transient name for one parameter set.
     *
     * The current date is part of the key because "upcoming" is relative to
     * today: without it, a response rendered yesterday would keep listing an
     * event that has since passed until its TTL ran out.
     */
    private function cache_key( $params ) {
        $identity = array(
            'params'  => $params,
            'day'     => current_time( 'Y-m-d' ),
            'version' => $this->cache_version(),
        );
        return 'sfaf_embed_' . md5( wp_json_encode( $identity ) );
    }

    /** @return array|null Cached payload, or null when there is nothing usable. */
    private function get_cached( $params ) {
        if ( $this->cache_ttl() <= 0 ) {
            return null;
        }
        $cached = get_transient( $this->cache_key( $params ) );
        return ( is_array( $cached ) && isset( $cached['html'] ) ) ? $cached : null;
    }

    private function set_cached( $params, $payload ) {
        $ttl = $this->cache_ttl();
        if ( $ttl > 0 ) {
            set_transient( $this->cache_key( $params ), $payload, $ttl );
        }
    }

    /**
     * Retire every cached response by moving to the next cache generation.
     * Accepts and ignores whatever arguments the hook it is attached to passes.
     */
    public function flush_cache() {
        update_option( self::CACHE_VERSION_OPTION, $this->cache_version() + 1 );
    }

    /**
     * Flush when an event is saved — but not for autosaves or revisions, which
     * would otherwise throw the cache away every thirty seconds while someone
     * is typing in the editor.
     */
    public function flush_cache_on_save( $post_id, $post = null ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        $this->flush_cache();
    }

    /** Flush when an event is deleted for good. */
    public function flush_cache_on_delete( $post_id, $post = null ) {
        if ( $post instanceof WP_Post && $post->post_type !== 'uc_event' ) {
            return;
        }
        $this->flush_cache();
    }

    /** Flush when an event is trashed or restored. */
    public function flush_cache_on_status_change( $post_id ) {
        if ( get_post_type( $post_id ) !== 'uc_event' ) {
            return;
        }
        $this->flush_cache();
    }

    /**
     * Flush on term changes — a renamed category or a new colour changes the
     * badge on every card that carries it, and the filter bar as well.
     */
    public function flush_cache_for_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
        if ( ! in_array( $taxonomy, array( 'uc_event_category', 'uc_organizer', 'uc_venue' ), true ) ) {
            return;
        }
        $this->flush_cache();
    }
}
