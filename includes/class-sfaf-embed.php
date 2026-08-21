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
        add_action( 'transition_post_status', array( $this, 'flush_cache_on_transition' ), 10, 3 );

        // META-ONLY CHANGES, which is how a fetch usually moves a date.
        //
        // SFAF_Sources::update_event() calls wp_update_post() only when a post
        // FIELD changed; a refreshed date, time or location is written straight
        // through update_post_meta(), which fires none of the hooks above. The
        // month grid is built entirely from _uc_event_date, so without these an
        // imported event could move to another day at the source and the grid
        // would keep it on the old one until the TTL ran out.
        add_action( 'updated_post_meta', array( $this, 'flush_cache_on_meta' ), 10, 4 );
        add_action( 'added_post_meta', array( $this, 'flush_cache_on_meta' ), 10, 4 );
        add_action( 'deleted_post_meta', array( $this, 'flush_cache_on_meta' ), 10, 4 );
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

    /**
     * URL of the embed script that remote sites load.
     *
     * NO ?ver= HERE, ON PURPOSE, and it is the fix for a real defect.
     *
     * This URL is baked into a snippet somebody copies once and pastes into a
     * page on another site. A version in it is therefore not a cache-buster at
     * all: it is a permanent pin to whatever the plugin happened to be on the
     * day the snippet was copied. The host page then kept loading an old
     * embed.js, and because the script derived its stylesheet URL from its own
     * src, an old calendar.css with it. New markup, old CSS, and a month grid
     * that arrived on sfaf.org with no styling for any of it.
     *
     * Unversioned, the browser revalidates on its normal schedule and a
     * released change reaches every embedded page without anyone re-pasting
     * anything. Freshness comes from HTTP caching, which is the mechanism that
     * can actually see a new release; a hardcoded string cannot.
     *
     * NOTE FOR ANY BLOCK PASTED BEFORE 2.10.1: it still carries the old pinned
     * URL and will keep loading a cached old script until that cache expires.
     * Re-copying the block from the generator replaces it for good.
     */
    public static function script_url() {
        return SFAF_PLUGIN_URL . 'public/js/embed.js';
    }

    /**
     * URL of the stylesheet the embed needs, as the RUNNING plugin sees it.
     *
     * Sent in every payload so a possibly-stale embed.js does not have to guess
     * from its own src. Versioned, because this one is resolved fresh on every
     * request rather than frozen into a snippet, so here a version really is a
     * cache-buster.
     */
    public static function style_url() {
        return SFAF_PLUGIN_URL . 'public/css/calendar.css?ver=' . SFAF_VERSION;
    }

    /* endpoint_url() removed in 3.28.0: never called. embed.js builds the two
       URLs it tries for itself, because it has to work from a remote page that
       cannot ask this plugin anything. */

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
            // A sanitize_callback is invoked by core as
            // call_user_func( $cb, $value, $request, $param_name ) — three
            // arguments, always (WP_REST_Request::sanitize_params).
            //
            // That rules out bare PHP built-ins here. Since PHP 8 an internal
            // function raises ArgumentCountError when handed more arguments
            // than it accepts, so 'intval' fatals the request: intval() takes
            // ($value, $base) and gets a third. WordPress's own helpers are
            // userland functions, where PHP silently ignores extra arguments,
            // which is why 'absint' and 'sanitize_text_field' are fine.
            //
            // Anything that is not a WordPress userland helper is wrapped in a
            // one-argument closure below.
            'args'                => array(
                'category'     => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
                'organizer'    => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
                'venue'        => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
                'series'       => array( 'type' => 'integer', 'default' => 0,       'sanitize_callback' => 'absint' ),
                // The search box. sanitize_text_field is a WordPress userland
                // function, so it tolerates the three arguments core passes a
                // sanitize_callback: see the long note above.
                's'            => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
                // Not absint: a negative per_page must stay negative so
                // normalize_params() sees it as "unset" and falls back to the
                // configured default, rather than flipping -5 into a real 5.
                'per_page'     => array( 'type' => 'integer', 'default' => 0,       'sanitize_callback' => function ( $value ) { return (int) $value; } ),
                'page'         => array( 'type' => 'integer', 'default' => 1,       'sanitize_callback' => 'absint' ),
                'show_filters' => array( 'type' => 'string',  'default' => 'yes',   'sanitize_callback' => 'sanitize_text_field' ),
                'layout'       => array( 'type' => 'string',  'default' => 'cards', 'sanitize_callback' => 'sanitize_text_field' ),
                // block = the whole calendar, items = just the cards for one
                // page, month = just the month grid. Empty picks for you:
                // block for page 1, items after.
                'mode'         => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),

                /*
                 * 2.10.0: display mode, month and sidebar count.
                 *
                 * DELIBERATELY ADDED TO THIS ROUTE RATHER THAN A NEW ONE.
                 * Every CORS mechanism in this file is gated on
                 * is_embed_request(), which compares the route string exactly:
                 *
                 *     $request->get_route() === '/sfaf-calendar/v1/embed'
                 *
                 * A second route would have matched none of the three, so
                 * preflight, response headers and the rest_pre_serve_request
                 * fallback would all have skipped it and every month
                 * navigation from another domain would have been blocked by
                 * the browser with a CORS error. Same route, more parameters,
                 * same headers: nothing about the cross-origin path changes.
                 */
                'view'         => array( 'type' => 'string',  'default' => 'list',  'sanitize_callback' => 'sanitize_text_field' ),
                'toggle'       => array( 'type' => 'string',  'default' => 'yes',   'sanitize_callback' => 'sanitize_text_field' ),
                // Empty default, NOT a yes or a no: absent means "the shipped
                // default", which is resolved by sfaf_source_links_default().
                'source_links' => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
                'month'        => array( 'type' => 'string',  'default' => '',      'sanitize_callback' => 'sanitize_text_field' ),
                'count'        => array( 'type' => 'integer', 'default' => 0,       'sanitize_callback' => 'absint' ),

                /*
                 * 3.18.0: the sidebar's heading.
                 *
                 * ON THIS ROUTE, AS EVERY OTHER PARAMETER IS. Every CORS
                 * mechanism in this file is gated on is_embed_request(), which
                 * compares the route string exactly, so a second route would
                 * match none of preflight, the response headers or the
                 * rest_pre_serve_request fallback: it would pass every test on
                 * this site and be blocked by the browser the moment sfaf.org
                 * asked for it. Same route, one more parameter, same headers.
                 *
                 * NO 'default' KEY, AND THAT IS THE WHOLE MECHANISM. WP_REST
                 * fills a declared default into the request, which would make
                 * an absent parameter indistinguishable from an empty one.
                 * Without it, get_param() returns null when the caller said
                 * nothing and '' when the caller sent heading= deliberately,
                 * which is the difference between "give me the default" and
                 * "give me no heading". See SFAF_Shortcodes::sidebar_heading().
                 */
                'heading'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),

                /*
                 * 3.8.0: the category a VISITOR chose from the filter bar, kept
                 * separate from `category`, which is what the embed snippet was
                 * scoped to by whoever wrote it. The filter bar runs a real
                 * query now instead of hiding cards, and this is the parameter
                 * it sends. A value outside the snippet's own scope is dropped
                 * rather than honoured, in SFAF_Shortcodes::effective_category().
                 */
                'active_category' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),

                /*
                 * 3.11.0: the groups a visitor chose from the second-level row.
                 *
                 * ON THIS ROUTE, NOT A NEW ONE, and that is not a preference.
                 * Every CORS mechanism in this file is gated on
                 * is_embed_request(), which compares the route string exactly.
                 * A second route would match none of preflight, the response
                 * headers or the rest_pre_serve_request fallback, so it would
                 * work when tested on this site and be blocked by the browser
                 * the moment sfaf.org asked for it. Same route, more
                 * parameters, same headers.
                 *
                 * A slug outside what the snippet's own scope contains is
                 * dropped server-side, exactly as active_category is. See
                 * SFAF_Shortcodes::effective_groups().
                 */
                'active_groups' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),

                /*
                 * 3.50.0: the organizer a visitor chose, and which filter rows
                 * this snippet offers at all.
                 *
                 * ON THIS ROUTE, NOT A NEW ONE, for the reason given above:
                 * is_embed_request() compares the route string exactly, so a
                 * second route would match none of preflight, the response
                 * headers or the rest_pre_serve_request fallback. Same route,
                 * more parameters, same headers.
                 */
                'active_organizer' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'filters'          => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
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

        // Stamped after the cache, never from it. A payload cached under the
        // previous release would otherwise hand out that release's stylesheet
        // URL for the rest of its TTL, which is a smaller version of exactly
        // the staleness this whole fix is about.
        $payload['css_url'] = self::style_url();

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

        // Load-more appends cards, so it asks for items. Month navigation
        // replaces just the grid. Numbered pagination replaces the whole thing,
        // so it asks for a block on any page.
        $mode = (string) $request->get_param( 'mode' );
        if ( ! in_array( $mode, array( 'block', 'items', 'month' ), true ) ) {
            $mode = ( $page > 1 ) ? 'items' : 'block';
        }

        // A sidebar count is capped for the same reason per_page is: this is a
        // public endpoint and an unbounded number is free work for anyone.
        $count = absint( $request->get_param( 'count' ) );
        if ( $count <= 0 ) {
            $count = 10;
        }

        $scope_category  = (string) $request->get_param( 'category' );
        $active_category = (string) $request->get_param( 'active_category' );

        return array(
            'category'     => $scope_category,
            // What the query actually runs with, computed once here so the
            // three modes below cannot disagree about it.
            'effective_category' => $this->shortcodes->effective_category( $scope_category, $active_category ),
            'active_category'    => $active_category,
            'active_groups'      => (string) $request->get_param( 'active_groups' ),
            'organizer'    => (string) $request->get_param( 'organizer' ),
            'active_organizer'   => (string) $request->get_param( 'active_organizer' ),
            /* Which filter rows the snippet offers. Carried rather than
             * defaulted, so a redraw cannot hand back a control the block was
             * generated without. */
            'filters'            => (string) $request->get_param( 'filters' ),
            'venue'        => (string) $request->get_param( 'venue' ),
            'series'       => absint( $request->get_param( 'series' ) ),
            /*
             * Capped, because this is a public endpoint and the search term is
             * the one parameter a caller can put arbitrary length into.
             * SFAF_Search caps the number of words as well; this caps the
             * bytes before they ever reach it.
             */
            's'            => substr( trim( (string) $request->get_param( 's' ) ), 0, 120 ),
            'per_page'     => min( $per_page, $max ),
            'page'         => $page,
            'show_filters' => (string) $request->get_param( 'show_filters' ),
            'layout'       => $layout,
            'mode'         => $mode,
            'view'         => $this->shortcodes->normalize_view( $request->get_param( 'view' ) ),
            'toggle'       => (string) $request->get_param( 'toggle' ),
            'source_links' => (string) $request->get_param( 'source_links' ),
            // Normalized here, not in the renderer, so an unparseable month
            // cannot make two visitors share a cache key for different months.
            'month'        => $this->shortcodes->normalize_month( $request->get_param( 'month' ) ),
            'count'        => min( 50, $count ),
            /*
             * null when the caller never sent it, '' when they sent it empty.
             * Passed through untouched so sidebar_heading() can tell the two
             * apart; sanitising or casting here would flatten them.
             */
            'heading'      => $request->get_param( 'heading' ),
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

        /*
         * WHERE THIS BLOCK'S EVENTS LINK, FOR THE ITEMS AND MONTH MODES TOO.
         *
         * The block mode gets it from render_calendar_block(), which reads
         * $params['source_links'] itself. The other two call render_events()
         * and render_month_grid() directly, so without this a snippet set to
         * open events at their source would do so on first paint and then hand
         * back resources links on page two and on every month navigation. Set
         * for the whole payload, cleared in the finally beside the embed flag.
         */
        sfaf_set_source_links( $this->shortcodes->normalize_source_links( $params['source_links'] ) );

        /*
         * items and month are given the EFFECTIVE category, because both render
         * a result set directly and neither has a filter bar of its own to
         * reconcile. The block mode is handed the scope and the choice
         * separately, because it draws the buttons and has to know which one to
         * mark as pressed.
         */
        $resolved             = $params;
        $resolved['category'] = $params['effective_category'];
        /*
         * The group selection is re-derived and clamped here for items and
         * month, exactly as the browser-driven paths on this site are: the
         * request says what it thinks is chosen, and the server works out what
         * this snippet actually contains before believing any of it. The block
         * mode does its own, because it also has to draw the row.
         */
        $resolved['groups'] = $this->shortcodes->clamp_groups( $resolved, $params['active_groups'] );

        try {
            if ( $params['mode'] === 'month' ) {
                // Month navigation replaces the grid only. Everything around it
                // (filter bar, toggle, list panel) is already on the page and
                // must not be rebuilt underneath the visitor.
                $payload = array(
                    'mode'      => 'month',
                    'html'      => '',
                    'month'     => $params['month'],
                    'total'     => 0,
                    'page'      => 1,
                    'max_pages' => 1,
                    'has_more'  => false,
                );

                /*
                 * EXCEPT IN THE COMBINED MODE, WHERE THERE ARE THREE PIECES
                 * (3.45.2).
                 *
                 * 3.45.0 moved the month name out of the grid so it could span
                 * both halves, and 3.45.1 put the composition in one method so
                 * the first render and the redraw could not disagree. THIS
                 * ROUTE WAS NEVER TOLD. It kept calling render_month_grid()
                 * with the head left on, so navigating in an embed dropped a
                 * grid carrying its own month name and its own previous/next
                 * into the left column, underneath the spanning head, which
                 * still said the month before. Two navigations, each moving one
                 * half. First load was correct, because first load is a block
                 * and the block has always composed this properly.
                 *
                 * The sidebar comes back too, for the reason it does on this
                 * site: the right-hand column lists the month the grid is
                 * showing, so a redraw that moves one and not the other is the
                 * disagreement 3.45.0 exists to remove.
                 *
                 * SAME METHOD AS BOTH OTHER CALLERS. There is no fourth
                 * composition here; this asks the one that already exists.
                 *
                 * ONE BRANCH, NOT A GRID OVERWRITTEN. Rendering the grid into
                 * the payload above and then replacing it here would run the
                 * month's query twice on every navigation, and leave a
                 * composed-then-discarded grid as the thing a later reader sees
                 * first.
                 */
                if ( $this->shortcodes->is_combined_view( $params['view'] ) ) {
                    $parts = $this->shortcodes->render_combined_parts(
                        $params['month'],
                        $resolved,
                        $params['count'],
                        ( null === $params['heading'] ) ? '' : (string) $params['heading']
                    );
                    $payload['html'] = $parts['grid'];
                    $payload['head'] = $parts['head'];
                    $payload['side'] = $parts['side'];
                } else {
                    $payload['html'] = $this->shortcodes->render_month_grid( $params['month'], $resolved );
                }
            } elseif ( $params['mode'] === 'items' ) {
                $events  = $this->shortcodes->render_events(
                    $params['per_page'],
                    $params['page'],
                    $resolved,
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
            sfaf_set_source_links( null );
        }

        /*
         * EVERY PIECE OF MARKUP IN THE PAYLOAD, NOT THE FIRST ONE (3.45.2).
         *
         * This read 'html' by name, and a combined month payload carries 'head'
         * and 'side' as well. The sidebar is full of images and event links, so
         * naming one key would have sent a column of root-relative URLs to a
         * page on another domain: exactly the blank placeholders this pass
         * exists to prevent, appearing only after somebody navigated.
         *
         * Undo any lazy-loading rewrite first, so the real image URL is in src
         * before URLs are resolved; then make everything absolute. Order
         * matters: a URL parked in data-orig-src has to be moved into src
         * before absolutize_urls() can qualify it. The host page is on another
         * domain, so a root-relative path like /wp-content/uploads/photo.jpg
         * would otherwise resolve against the host and 404.
         */
        foreach ( array( 'html', 'head', 'side' ) as $piece ) {
            if ( ! isset( $payload[ $piece ] ) || '' === $payload[ $piece ] ) {
                continue;
            }
            $payload[ $piece ] = $this->absolutize_urls( $this->normalize_lazy_images( $payload[ $piece ] ) );
        }

        $payload['per_page']     = $params['per_page'];
        $payload['calendar_url'] = self::calendar_url();
        // The authoritative stylesheet URL. embed.js derives one from its own
        // src as a first guess; this is the one the running plugin actually
        // serves, and it wins. See adoptStylesheet() in embed.js.
        $payload['css_url']      = self::style_url();
        $payload['cached']       = false;

        // The card style chosen under branding, as a bare slug ('minimal',
        // 'shadow', …). Read straight from the same option sfaf_body_class()
        // reads — uc_settings['brand_card_style'] — rather than through a
        // helper of our own.
        //
        // On this site the style reaches the CSS as a body class. An embed has
        // no body of ours to put a class on, so the slug travels in the payload
        // and embed.js puts it on the block itself. Inlined deliberately: a
        // helper here is one more callable that can go missing in a partial
        // deployment, and this is the third undefined-callable fatal on this
        // endpoint. get_option() and sanitize_html_class() are WordPress core.
        $uc_settings           = get_option( 'uc_settings', array() );
        $card_style            = isset( $uc_settings['brand_card_style'] ) ? $uc_settings['brand_card_style'] : '';
        $payload['card_style'] = $card_style ? sanitize_html_class( $card_style ) : '';

        return $payload;
    }

    /* ---------------------------------------------------------------------
     * Lazy-loading normalisation
     *
     * Image optimisers on this site rewrite <img> tags before the markup ever
     * reaches us: the real URL is moved into data-orig-src / data-srcset, src
     * and srcset are replaced with a blank inline SVG, and a "lazyload" class
     * is added. On this site that is invisible, because the optimiser's own
     * script swaps the real image back in on page load.
     *
     * An embed carries the markup but not that script, so on a remote page the
     * blank placeholder is simply the final state and the card stays empty.
     * Since the embed is required to work with no host-page JavaScript at all,
     * the tags are put back into the form a browser understands unaided before
     * the response goes out.
     * ------------------------------------------------------------------- */

    /**
     * Attributes an optimiser parks the real image URL in, best first.
     *
     * @return array{src:string[],srcset:string[]}
     */
    private function lazy_attribute_map() {
        return array(
            'src' => array(
                'data-orig-src',    // Jetpack / Photon, several cache plugins
                'data-lazy-src',    // WP Rocket
                'data-src',         // lazysizes, a3 Lazy Load, generic
                'data-original',    // jQuery.lazyload (older)
                'data-echo',
            ),
            'srcset' => array(
                'data-orig-srcset',
                'data-lazy-srcset',
                'data-srcset',
            ),
        );
    }

    /**
     * True when a value is a stand-in rather than a real image.
     *
     * Optimisers use an inline data: URI — a blank SVG of the right aspect
     * ratio, or a 1x1 GIF — so the layout does not jump. Nothing the calendar
     * renders legitimately uses a data: URI in src or srcset, so treating them
     * all as placeholders is safe here.
     *
     * @param string $value Attribute value.
     * @return bool
     */
    private function is_placeholder_url( $value ) {
        $value = trim( $value );
        return ( '' === $value ) || ( 0 === stripos( $value, 'data:' ) );
    }

    /**
     * Rewrite one <img> tag so the real image is in src/srcset.
     *
     * @param string $tag The full tag, '<img …>'.
     * @return string
     */
    private function normalize_img_tag( $tag ) {
        $inner = preg_replace( '#^<img\b#i', '', $tag );
        $inner = preg_replace( '#/?>$#', '', (string) $inner );

        // WordPress and every optimiser in this list emit quoted values.
        if ( ! preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("|\')(.*?)\2/s', (string) $inner, $found, PREG_SET_ORDER ) ) {
            return $tag;
        }

        $attrs = array();
        foreach ( $found as $one ) {
            $name = strtolower( $one[1] );
            if ( ! array_key_exists( $name, $attrs ) ) {
                $attrs[ $name ] = $one[3];
            }
        }

        $map = $this->lazy_attribute_map();

        $real_src = '';
        foreach ( $map['src'] as $key ) {
            if ( isset( $attrs[ $key ] ) && ! $this->is_placeholder_url( $attrs[ $key ] ) ) {
                $real_src = trim( $attrs[ $key ] );
                break;
            }
        }

        $real_srcset = '';
        foreach ( $map['srcset'] as $key ) {
            if ( isset( $attrs[ $key ] ) && ! $this->is_placeholder_url( $attrs[ $key ] ) ) {
                $real_srcset = trim( $attrs[ $key ] );
                break;
            }
        }

        // Not a lazy-loaded tag: leave it exactly as rendered.
        if ( '' === $real_src && '' === $real_srcset ) {
            return $tag;
        }

        if ( '' !== $real_src ) {
            $attrs['src'] = $real_src;
        }
        if ( '' !== $real_srcset ) {
            $attrs['srcset'] = $real_srcset;
        } elseif ( isset( $attrs['srcset'] ) && $this->is_placeholder_url( $attrs['srcset'] ) ) {
            // A placeholder srcset outranks a real src, so it has to go.
            unset( $attrs['srcset'] );
        }

        // src still blank but a real srcset survived: promote its first
        // candidate, so browsers without srcset support still show something.
        if ( ( ! isset( $attrs['src'] ) || $this->is_placeholder_url( $attrs['src'] ) ) && ! empty( $attrs['srcset'] ) ) {
            $first          = preg_split( '/\s+/', trim( strtok( $attrs['srcset'], ',' ) ), 2 );
            $attrs['src']   = $first[0];
        }

        // Drop the plumbing so nothing on the host page can act on it.
        foreach ( array_merge( $map['src'], $map['srcset'], array( 'data-sizes', 'data-orig-sizes', 'data-ll-status', 'data-was-processed', 'data-lazy-srcset-done' ) ) as $key ) {
            unset( $attrs[ $key ] );
        }

        // "sizes=auto" is a lazysizes convention the browser cannot use.
        if ( isset( $attrs['sizes'] ) && 'auto' === strtolower( trim( $attrs['sizes'] ) ) ) {
            unset( $attrs['sizes'] );
        }

        // Strip the optimiser's classes: if the consuming page happens to run a
        // lazy loader of its own, a leftover "lazyload" would invite it to blank
        // the image out again.
        if ( isset( $attrs['class'] ) ) {
            $class = preg_replace( '/\b(lazyload(ed|ing)?|lazy|ll-(loaded|error)|no-lazyload|skip-lazy)\b/i', ' ', $attrs['class'] );
            $class = trim( preg_replace( '/\s+/', ' ', (string) $class ) );
            if ( '' === $class ) {
                unset( $attrs['class'] );
            } else {
                $attrs['class'] = $class;
            }
        }

        // Native lazy loading needs no script, so the deferral survives.
        if ( ! isset( $attrs['loading'] ) ) {
            $attrs['loading'] = 'lazy';
        }
        if ( ! isset( $attrs['decoding'] ) ) {
            $attrs['decoding'] = 'async';
        }

        $out = '<img';
        foreach ( $attrs as $name => $value ) {
            $out .= ' ' . $name . '="' . str_replace( '"', '&quot;', $value ) . '"';
        }
        return $out . ' />';
    }

    /**
     * Put every lazy-loaded <img> in the markup back into native form.
     *
     * @param string $html Rendered markup.
     * @return string
     */
    private function normalize_lazy_images( $html ) {
        if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<img' ) ) {
            return $html;
        }

        $out = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function ( $m ) {
                return $this->normalize_img_tag( $m[0] );
            },
            $html
        );

        return ( null === $out ) ? $html : $out;
    }

    /* ---------------------------------------------------------------------
     * Absolute URLs
     *
     * A browser resolves a relative URL against the page it is on. For an embed
     * that page belongs to someone else, so anything not fully qualified points
     * at the wrong domain. WordPress core emits absolute URLs for attachments,
     * but an image URL typed into a meta field (_uc_image_url, the series
     * image, a synced remote image) is stored exactly as entered — often as
     * /wp-content/uploads/… — and those are the ones that broke.
     *
     * Rewriting the finished markup rather than each image helper means every
     * URL the renderer can emit is covered, including any added later, and the
     * shortcode path on this site is untouched.
     * ------------------------------------------------------------------- */

    /**
     * Resolve one URL against this site.
     *
     * @param string $url Raw URL from the markup (still HTML-escaped).
     * @return string Absolute URL, or the input unchanged when it is not a
     *                relative reference.
     */
    private function absolutize_url( $url ) {
        $url = trim( $url );

        if ( '' === $url ) {
            return $url;
        }
        // Fragments, and anything already carrying a scheme (https:, mailto:,
        // tel:, data:) is either already absolute or not a location at all.
        if ( '#' === $url[0] || preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
            return $url;
        }

        $home = wp_parse_url( home_url( '/' ) );
        if ( empty( $home['scheme'] ) || empty( $home['host'] ) ) {
            return $url;
        }
        $origin = $home['scheme'] . '://' . $home['host']
            . ( empty( $home['port'] ) ? '' : ':' . $home['port'] );

        // Protocol-relative: //cdn.example.org/x.jpg — keep the host, add ours.
        if ( 0 === strpos( $url, '//' ) ) {
            return $home['scheme'] . ':' . $url;
        }
        // Root-relative: /wp-content/uploads/x.jpg. The leading slash already
        // means "from the domain root", so the origin alone is the right base
        // even when WordPress lives in a subdirectory.
        if ( '/' === $url[0] ) {
            return $origin . $url;
        }
        // Bare relative: uploads/x.jpg — resolve from the site root.
        return rtrim( home_url( '/' ), '/' ) . '/' . $url;
    }

    /**
     * Resolve every candidate in a srcset/data-srcset value.
     *
     * Format is "url 320w, url 2x" — comma separated, each entry an optional
     * descriptor after the URL. Only the URL part is rewritten.
     *
     * @param string $value Attribute value.
     * @return string
     */
    private function absolutize_srcset( $value ) {
        $out = array();
        foreach ( explode( ',', $value ) as $candidate ) {
            $candidate = trim( $candidate );
            if ( '' === $candidate ) {
                continue;
            }
            $bits = preg_split( '/\s+/', $candidate, 2 );
            $url  = $this->absolutize_url( $bits[0] );
            $out[] = isset( $bits[1] ) ? $url . ' ' . $bits[1] : $url;
        }
        return implode( ', ', $out );
    }

    /**
     * Make every URL-bearing attribute in a block of markup absolute.
     *
     * Covers the plain single-URL attributes and the srcset pair. data-src /
     * data-srcset are included because a lazy-loading script on the host page
     * may swap them into src/srcset after the markup lands, at which point a
     * relative value would fail exactly like an unrewritten src.
     *
     * @param string $html Rendered markup.
     * @return string
     */
    private function absolutize_urls( $html ) {
        if ( ! is_string( $html ) || '' === $html || false === strpos( $html, '<' ) ) {
            return $html;
        }

        // A closure declared inside a method keeps the enclosing class scope and
        // its $this binding, so it can call these private methods directly.
        $html = preg_replace_callback(
            '/\s(src|data-src|href|poster)=(["\'])(.*?)\2/i',
            function ( $m ) {
                return ' ' . $m[1] . '=' . $m[2] . $this->absolutize_url( $m[3] ) . $m[2];
            },
            $html
        );

        $html = preg_replace_callback(
            '/\s(srcset|data-srcset)=(["\'])(.*?)\2/i',
            function ( $m ) {
                return ' ' . $m[1] . '=' . $m[2] . $this->absolutize_srcset( $m[3] ) . $m[2];
            },
            $html
        );

        return ( null === $html ) ? '' : $html;
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
        //
        // WP_REST_Response has no remove_header() — that method exists on
        // WP_REST_Request and WP_REST_Server, but WP_HTTP_Response (the parent
        // here) only exposes get_headers()/set_headers()/header(). Calling it
        // fatals the request, so the header is dropped by rewriting the set.
        // HTTP header names are case-insensitive, so match that way.
        $headers = $response->get_headers();
        foreach ( array_keys( $headers ) as $name ) {
            if ( 0 === strcasecmp( $name, 'Access-Control-Allow-Credentials' ) ) {
                unset( $headers[ $name ] );
            }
        }
        $response->set_headers( $headers );

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
     * The parameters that actually change what a given mode renders.
     *
     * WHY NOT JUST HASH EVERYTHING. A month grid does not care about per_page,
     * page, layout or the filter bar, but those travel on every request. Keying
     * on the whole set would give the same September grid a different cache
     * entry for every block on the site that happens to page differently, which
     * is exactly the "a hundred visitors, a hundred queries" case the cache
     * exists to prevent. Trimming to the fields that matter means one entry per
     * month-and-filter, shared by every block asking for it.
     *
     * @param array $params
     * @return array
     */
    private function cache_identity( $params ) {
        // The filter set is common to every mode.
        $identity = array(
            'mode'      => $params['mode'],
            'category'  => $params['category'],
            // The visitor's chosen category and groups are part of the identity:
            // two people reading the same block with different pills pressed
            // must not share a cache entry.
            'active_category' => isset( $params['active_category'] ) ? $params['active_category'] : '',
            'active_groups'   => isset( $params['active_groups'] ) ? $params['active_groups'] : '',
            'organizer' => $params['organizer'],
            /* The chosen organizer for the same reason the chosen category is
             * here, and the row set because two snippets with the same filters
             * but different toggles render different markup. */
            'active_organizer' => isset( $params['active_organizer'] ) ? $params['active_organizer'] : '',
            'filters'          => isset( $params['filters'] ) ? $params['filters'] : '',
            'venue'     => $params['venue'],
            'series'    => $params['series'],
            // Part of the identity even though searches are not cached below.
            // If they ever are, the key is already right; leaving it out would
            // mean a cached unsearched page being served to a search, and one
            // visitor's search results being served to the next visitor.
            's'         => isset( $params['s'] ) ? $params['s'] : '',
        );

        if ( 'month' === $params['mode'] ) {
            $identity['month'] = $params['month'];
            /*
             * THE CACHE IS PER SHAPE AS WELL AS PER MONTH (3.45.2).
             *
             * A combined month payload carries two more pieces of markup than a
             * grid one, and the two were sharing an entry: whichever shape was
             * asked for first was served to the other, so an ordinary month
             * view could be handed a headless grid and lose its month name
             * entirely. The count and the heading are in the key for the reason
             * they are in the sidebar's below, because the sidebar is part of
             * what this payload now contains.
             */
            if ( $this->shortcodes->is_combined_view( $params['view'] ) ) {
                $identity['view']    = 'combined';
                $identity['count']   = $params['count'];
                $identity['heading'] = ( null === $params['heading'] ) ? '~default~' : (string) $params['heading'];
            }
            return $identity;
        }

        if ( 'sidebar' === $params['view'] ) {
            $identity['view']  = 'sidebar';
            $identity['count'] = $params['count'];
            /*
             * THE HEADING IS PART OF THE CACHE KEY, because it is part of the
             * rendered HTML. Two blocks on the same page with the same filter
             * and count and different headings are two different responses,
             * and leaving this out would have served the first one's heading to
             * the second. Cast to a string that keeps null and '' apart, for
             * the same reason they are kept apart everywhere else.
             */
            $identity['heading'] = ( null === $params['heading'] ) ? '~default~' : (string) $params['heading'];
            return $identity;
        }

        $identity['view']         = $params['view'];
        $identity['toggle']       = $params['toggle'];
        // Part of the cache identity: two blocks differing only in where their
        // events link are two different payloads.
        $identity['source_links'] = $params['source_links'];
        $identity['month']        = $params['month'];
        $identity['per_page']     = $params['per_page'];
        $identity['page']         = $params['page'];
        $identity['layout']       = $params['layout'];
        $identity['show_filters'] = $params['show_filters'];

        return $identity;
    }

    /**
     * Transient name for one parameter set.
     *
     * The current date is part of the key because "upcoming" is relative to
     * today: without it, a response rendered yesterday would keep listing an
     * event that has since passed until its TTL ran out. It matters for the
     * month grid too, which marks today.
     *
     * The generation counter is the invalidation mechanism: see flush_cache().
     */
    private function cache_key( $params ) {
        $identity = array(
            'params'  => $this->cache_identity( $params ),
            'day'     => current_time( 'Y-m-d' ),
            'version' => $this->cache_version(),
        );
        return 'sfaf_embed_' . md5( wp_json_encode( $identity ) );
    }

    /**
     * SEARCHES ARE NOT CACHED, and that is a deliberate policy rather than an
     * oversight.
     *
     * Every other parameter this endpoint takes is drawn from a small fixed
     * set: a handful of category slugs, a few organizers, a month. The search
     * term is the one thing a caller can put anything at all into, so caching
     * it means one transient per distinct string anybody has ever typed, in an
     * options table, from a public endpoint. That is a slow leak at best and
     * something worth doing on purpose at worst.
     *
     * The thing being given up is small. A search is a long tail of one-off
     * queries with almost no repeat rate, so the hit rate would have been near
     * zero anyway, and running the query is what a search is for.
     */
    private function cacheable( $params ) {
        return $this->cache_ttl() > 0 && '' === trim( (string) ( isset( $params['s'] ) ? $params['s'] : '' ) );
    }

    /** @return array|null Cached payload, or null when there is nothing usable. */
    private function get_cached( $params ) {
        if ( ! $this->cacheable( $params ) ) {
            return null;
        }
        $cached = get_transient( $this->cache_key( $params ) );
        return ( is_array( $cached ) && isset( $cached['html'] ) ) ? $cached : null;
    }

    private function set_cached( $params, $payload ) {
        if ( ! $this->cacheable( $params ) ) {
            return;
        }
        set_transient( $this->cache_key( $params ), $payload, $this->cache_ttl() );
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
     * Flush on any status transition for an event.
     *
     * save_post covers most of it, but not everything: publishing a scheduled
     * post, an import moving a vanished event to draft, or a status set through
     * wp_update_post() with no other change can all land here first. A post
     * appearing on or disappearing from the public calendar is precisely what a
     * cached month must not survive.
     *
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     */
    public function flush_cache_on_transition( $new_status, $old_status, $post ) {
        if ( ! $post instanceof WP_Post || 'uc_event' !== $post->post_type ) {
            return;
        }
        if ( $new_status === $old_status ) {
            return;
        }
        $this->flush_cache();
    }

    /**
     * Flush when a rendering-relevant meta value changes on an event.
     *
     * Scoped twice over, because meta writes are frequent and a flush is not
     * free: the post must be a uc_event, and the key must be one the cards or
     * the grid actually read. A private bookkeeping key like
     * _uc_source_updated_at is written on every single fetch and changes
     * nothing a visitor sees, so it must not throw the cache away.
     *
     * @param int    $meta_id
     * @param int    $post_id
     * @param string $meta_key
     * @param mixed  $meta_value
     */
    public function flush_cache_on_meta( $meta_id, $post_id, $meta_key = '', $meta_value = null ) {
        static $keys = null;
        if ( null === $keys ) {
            $keys = array_flip( array(
                '_uc_event_date', '_uc_start_time', '_uc_end_time', '_uc_end_date',
                '_uc_location', '_uc_recurrence', '_uc_capacity',
                '_uc_image_url', '_uc_image_override', '_uc_external_image', '_thumbnail_id',
                // A series is a term now, so a change to one arrives on the
                // taxonomy hooks rather than here. What is left of the old
                // group is the recurrence marker, which nothing renders.
                '_uc_faqs',
                '_uc_gofundme_url', '_uc_gofundme_goal', '_uc_gofundme_raised',
                '_uc_rsvp_enabled', '_uc_show_rsvp', '_uc_show_donate',
                '_uc_show_social', '_uc_show_calendar', '_uc_show_reminders',
            ) );
        }

        if ( ! isset( $keys[ (string) $meta_key ] ) ) {
            return;
        }
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
        // uc_series is in this list because a series now carries the name on
        // the "Part of series" badge and the image an event falls back to, so
        // renaming one or changing its image changes what a card renders.
        $watched = array( 'uc_event_category', 'uc_organizer', 'uc_venue', SFAF_Series::TAXONOMY );
        if ( ! in_array( $taxonomy, $watched, true ) ) {
            return;
        }
        $this->flush_cache();
    }
}
