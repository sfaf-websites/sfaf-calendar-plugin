<?php
/**
 * Eventbrite — authentication.
 *
 * STEP 1 of the integration: proving the private token authenticates, nothing
 * else. There is deliberately no event fetching, no import and no display code
 * here yet, for the same reason the GoFundMe Pro file was built this way — "can
 * we authenticate at all?" is worth answering on its own, before anything is
 * built on top of it.
 *
 * AUTHENTICATION — much simpler than GoFundMe Pro.
 * ---------------------------------------------------------------------------
 * Eventbrite issues a long-lived PRIVATE TOKEN from the account's API keys
 * page. There is no client_credentials round trip, no token host separate from
 * the data host, and nothing to refresh:
 *
 *   GET https://www.eventbriteapi.com/v3/users/me/
 *       Authorization: Bearer <private token>
 *
 *       -> { "id": …, "name": …, "emails": [ { "email": …, "primary": true } ] }
 *
 * Because there is no token to hold, "connected" cannot be derived from a
 * stored token the way it is for GoFundMe Pro. It is instead derived from the
 * record of a real successful /users/me/ call — see verification_record(). That
 * record is fingerprinted against the token and the base URL that produced it,
 * so changing either one drops the badge back to "Not connected" rather than
 * leaving a stale claim on screen.
 *
 * SECRETS: the private token is never echoed back to the browser, never logged
 * and never included in an error message. The settings screen shows only
 * whether a token is stored; the connection test reports the account it reached.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Eventbrite {

    /** Default API base — the v3 host from Eventbrite's documentation. */
    const DEFAULT_API_BASE = 'https://www.eventbriteapi.com/v3';

    /**
     * Where the proof-of-connection record lives.
     *
     * Its own option rather than a key inside uc_settings: the settings screen
     * re-saves that whole array on every submit, which would otherwise wipe or
     * stale-write the record. Autoload off — it is only read on the settings
     * screen and when a call is actually being made.
     */
    const STATUS_OPTION = 'sfaf_eventbrite_status';

    public function register() {
        add_action( 'wp_ajax_sfaf_eventbrite_connect', array( $this, 'ajax_connect' ) );
        add_action( 'wp_ajax_sfaf_eventbrite_preview', array( $this, 'ajax_preview' ) );
    }

    /* ---------------------------------------------------------------------
     * Configuration
     * ------------------------------------------------------------------- */

    /**
     * The API base URL.
     *
     * Settings field first so it can be corrected on a live site without a
     * rebuild, then the documented default. The filter still wins over both,
     * for environments configured in code:
     *
     *     add_filter( 'sfaf_eventbrite_api_base', function () { return '…'; } );
     *
     * @return string Base URL with no trailing slash.
     */
    public static function api_base() {
        $settings   = get_option( 'uc_settings', array() );
        $configured = isset( $settings['eventbrite_api_base'] ) ? trim( (string) $settings['eventbrite_api_base'] ) : '';
        $url        = ( '' !== $configured ) ? $configured : self::DEFAULT_API_BASE;
        return untrailingslashit( (string) apply_filters( 'sfaf_eventbrite_api_base', $url ) );
    }

    /**
     * The endpoint the connection test calls.
     *
     * The trailing slash is Eventbrite's own convention for this route and is
     * kept deliberately — the API redirects without it.
     *
     * @return string
     */
    public static function me_endpoint() {
        return self::api_base() . '/users/me/';
    }

    /**
     * The User-Agent sent with every Eventbrite request.
     *
     * The lesson from the GoFundMe Pro build: WordPress's default agent got a
     * Cloudflare interstitial rather than an answer, and it read like a
     * credential failure. An identifying agent is both the polite thing to send
     * and what a filter like that expects to see. Filterable because tuning it
     * is the cheapest lever if a challenge ever appears:
     *
     *     add_filter( 'sfaf_eventbrite_user_agent', function () { return '…'; } );
     *
     * @return string
     */
    public static function user_agent() {
        $default = 'SFAF-Calendar/' . SFAF_VERSION . ' (+https://sfaf.org)';
        return (string) apply_filters( 'sfaf_eventbrite_user_agent', $default );
    }

    /**
     * Base arguments for any request to Eventbrite — auth test or, later, data.
     *
     * Centralised so the two cannot drift: whatever gets the connection test
     * answered is then automatically also sent on every event call.
     *
     * The agent goes in the 'user-agent' argument rather than the headers
     * array, which is WordPress's own slot for it — setting both would send the
     * header twice on the cURL transport.
     *
     * @param array $args Arguments to merge over the defaults.
     * @return array
     */
    public static function request_args( $args = array() ) {
        $defaults = array(
            'timeout'     => 20,
            'redirection' => 3,
            'user-agent'  => self::user_agent(),
            'headers'     => array(
                'Accept' => 'application/json',
            ),
        );

        // Merge headers rather than letting a caller's array replace them.
        $headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
        unset( $args['headers'] );

        $merged            = array_merge( $defaults, $args );
        $merged['headers'] = array_merge( $defaults['headers'], $headers );

        return $merged;
    }

    /**
     * Request arguments carrying the bearer token.
     *
     * The single place the Authorization header is built, so no future call can
     * invent its own spelling of it.
     *
     * @param string $token Private token.
     * @param array  $args  Arguments to merge over the defaults.
     * @return array
     */
    public static function auth_args( $token, $args = array() ) {
        $headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
        $headers['Authorization'] = 'Bearer ' . trim( (string) $token );
        $args['headers'] = $headers;
        return self::request_args( $args );
    }

    /**
     * The stored private token.
     *
     * Returned raw and byte-for-byte — it is an opaque credential, not display
     * text, and nothing here is allowed to reshape it.
     *
     * @return string
     */
    public static function private_token() {
        $settings = get_option( 'uc_settings', array() );
        return isset( $settings['eventbrite_private_token'] ) ? trim( (string) $settings['eventbrite_private_token'] ) : '';
    }

    /** True when a token is on file, without revealing it. */
    public static function has_token() {
        return '' !== self::private_token();
    }

    /* ---------------------------------------------------------------------
     * The authenticated call
     * ------------------------------------------------------------------- */

    /**
     * Fetch the account behind a token: GET {api_base}/users/me/.
     *
     * The token is passed in rather than read here so the settings screen can
     * test what is currently typed in the form, before it has been saved.
     *
     * @param string $token Private token.
     * @return array|WP_Error Account details on success.
     */
    /**
     * Build a full URL for an API path.
     *
     * @param string $path  Path below the base, e.g. 'users/me/organizations/'.
     * @param array  $query Query arguments.
     * @return string
     */
    public static function endpoint( $path, $query = array() ) {
        $url = self::api_base() . '/' . ltrim( (string) $path, '/' );
        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }
        return $url;
    }

    /**
     * GET a URL with the bearer token and return its decoded JSON.
     *
     * The one place a response from Eventbrite is judged, so every call —
     * the connection test, the organizations lookup, each page of events —
     * gets the identical treatment: bot-challenge check first, then status,
     * then "is this even JSON". Anything that goes wrong comes back as a
     * WP_Error naming the real status, the endpoint and Eventbrite's own
     * message.
     *
     * @param string $token Private token.
     * @param string $url   Absolute URL.
     * @return array|WP_Error Decoded body on success.
     */
    private static function request_json( $token, $url ) {
        $response = wp_remote_get( $url, self::auth_args( $token ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'sfaf_eventbrite_unreachable',
                sprintf( 'Could not reach %s — %s', $url, $response->get_error_message() )
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );
        $body   = json_decode( $raw, true );

        // A bot-protection interstitial is not an API error and reads nothing
        // like one, so it is called out before anything else.
        $challenge = self::challenge_detail( $response, $raw );
        if ( '' !== $challenge ) {
            return new WP_Error(
                'sfaf_eventbrite_challenge',
                sprintf( 'HTTP %d from %s — %s', $status, $url, $challenge )
            );
        }

        if ( 200 !== $status ) {
            return new WP_Error(
                'sfaf_eventbrite_http_' . $status,
                sprintf( 'HTTP %d from %s — %s', $status, $url, self::error_detail( $body, $raw, $status ) )
            );
        }

        if ( ! is_array( $body ) ) {
            return new WP_Error(
                'sfaf_eventbrite_unexpected',
                sprintf( 'HTTP 200 from %s but the response was not JSON. %s', $url, self::error_detail( $body, $raw, $status ) )
            );
        }

        return $body;
    }

    /**
     * Fetch the account behind a token: GET {api_base}/users/me/.
     *
     * The token is passed in rather than read here so the settings screen can
     * test what is currently typed in the form, before it has been saved.
     *
     * @param string $token Private token.
     * @return array|WP_Error Account details on success.
     */
    public static function fetch_me( $token ) {
        $token = trim( (string) $token );

        if ( '' === $token ) {
            return new WP_Error( 'sfaf_eventbrite_missing', 'A private token is required.' );
        }

        $endpoint = self::me_endpoint();
        $body     = self::request_json( $token, $endpoint );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        if ( empty( $body['id'] ) ) {
            return new WP_Error(
                'sfaf_eventbrite_unexpected',
                sprintf( 'HTTP 200 from %s but the response did not look like an Eventbrite user.', $endpoint )
            );
        }

        return array(
            'id'    => (string) $body['id'],
            'name'  => isset( $body['name'] ) && is_string( $body['name'] ) ? $body['name'] : '',
            'email' => self::primary_email( $body ),
        );
    }

    /**
     * The primary email from a /users/me/ payload.
     *
     * Eventbrite returns every address on the account; the one flagged primary
     * is the one worth showing, with the first address as a fallback for
     * accounts that flag none.
     *
     * @param array $body Decoded response body.
     * @return string
     */
    private static function primary_email( $body ) {
        if ( empty( $body['emails'] ) || ! is_array( $body['emails'] ) ) {
            return '';
        }

        $first = '';
        foreach ( $body['emails'] as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['email'] ) || ! is_string( $entry['email'] ) ) {
                continue;
            }
            if ( '' === $first ) {
                $first = $entry['email'];
            }
            if ( ! empty( $entry['primary'] ) ) {
                return $entry['email'];
            }
        }

        return $first;
    }

    /**
     * Recognise a bot-protection challenge, and say so plainly.
     *
     * Carried over from the GoFundMe Pro build, where a Cloudflare interstitial
     * arrived as an HTML page and read exactly like an auth failure — it is a
     * 403 — when the credentials had never been examined at all. Eventbrite has
     * not shown one, and this is here so that if it ever does we know
     * immediately instead of chasing the token.
     *
     * Detection is two-sided: the body's tell-tale phrases, and the response
     * headers Cloudflare adds. Either alone is enough.
     *
     * @param array|WP_Error $response Full wp_remote_* response.
     * @param string         $raw      Raw body.
     * @return string Explanation, or '' when this is not a challenge.
     */
    private static function challenge_detail( $response, $raw ) {
        $body_tells = array(
            'Just a moment',
            'Enable JavaScript and cookies to continue',
            'cf-browser-verification',
            'cf_chl_opt',
            'Checking your browser before accessing',
            'Attention Required! | Cloudflare',
            'Access denied | ',
        );

        $hit = '';
        foreach ( $body_tells as $tell ) {
            if ( false !== stripos( $raw, $tell ) ) {
                $hit = $tell;
                break;
            }
        }

        // Header side: cf-mitigated marks a request Cloudflare acted on.
        if ( '' === $hit ) {
            $mitigated = wp_remote_retrieve_header( $response, 'cf-mitigated' );
            if ( ! empty( $mitigated ) ) {
                $hit = 'cf-mitigated: ' . ( is_array( $mitigated ) ? implode( ',', $mitigated ) : $mitigated );
            }
        }

        if ( '' === $hit ) {
            return '';
        }

        return sprintf(
            'Bot protection blocked this request (matched "%s") — the token was never checked, so this is not a credential problem. '
            . 'Next things to try: confirm the API base URL in settings; ask Eventbrite to allow this server; '
            . 'or tune the agent via the sfaf_eventbrite_user_agent filter. Agent sent: %s',
            $hit,
            self::user_agent()
        );
    }

    /**
     * A readable reason from an error response.
     *
     * Eventbrite's error envelope is { "status_code", "error", "error_detail",
     * "error_description" }; the description is the human sentence. Falls back
     * to a short slice of the raw body so an HTML error page cannot flood the
     * notice.
     *
     * @param mixed  $body   Decoded body.
     * @param string $raw    Raw body.
     * @param int    $status HTTP status.
     * @return string
     */
    private static function error_detail( $body, $raw, $status ) {
        if ( is_array( $body ) ) {
            $parts = array();
            foreach ( array( 'error_description', 'error', 'message', 'detail' ) as $key ) {
                if ( ! empty( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
                    $parts[] = $body[ $key ];
                }
            }
            if ( ! empty( $parts ) ) {
                // Eventbrite sends both a code (NOT_AUTHORIZED) and a sentence;
                // showing both saves a trip to the documentation.
                return implode( ' — ', array_unique( $parts ) );
            }
        }

        $raw = trim( wp_strip_all_tags( $raw ) );
        if ( '' === $raw ) {
            if ( 404 === $status || 405 === $status ) {
                return 'No response body. A 404/405 here usually means the API base URL is wrong — the default is ' . self::DEFAULT_API_BASE . '.';
            }
            return 'No response body.';
        }
        return ( strlen( $raw ) > 300 ) ? substr( $raw, 0, 300 ) . '…' : $raw;
    }

    /* ---------------------------------------------------------------------
     * Fetching events
     *
     * STEP 2: reading and previewing only. Nothing here creates a uc_event
     * post or touches the pending queue — fetch_events() returns data and the
     * settings screen displays it, so the shape can be inspected before any
     * mapping is written against it. Step 3 imports by calling the very same
     * fetch_events(), which is why it returns normalised rows and keeps the
     * untouched API payload alongside them.
     *
     * The route is two calls deep, and the old one-call shortcut
     * (/users/me/events/) is deprecated and deliberately not used:
     *
     *   GET {api_base}/users/me/organizations/
     *   GET {api_base}/organizations/{organization_id}/events/
     *
     * An account can own more than one organization, so the second call is
     * made once per organization and the results combined.
     * ------------------------------------------------------------------- */

    /** Pages to follow per collection before giving up, unless filtered. */
    const DEFAULT_MAX_PAGES = 20;

    /**
     * Follow an Eventbrite collection to the end and return every item.
     *
     * Eventbrite paginates with a `pagination` object: `has_more_items` says
     * whether to keep going and `continuation` is the token that fetches the
     * next page. The loop stops on the first of: no more items, no
     * continuation token to follow, or the page cap — and when it stops early
     * it says so rather than quietly returning a partial list that would look
     * complete.
     *
     * @param string $token          Private token.
     * @param string $path           Path below the API base.
     * @param string $collection_key Key holding the list, e.g. 'events'.
     * @param array  $query          Query arguments applied to every page.
     * @return array|WP_Error {items, pages, truncated, truncated_reason, endpoint}
     */
    private static function fetch_all_pages( $token, $path, $collection_key, $query = array() ) {
        $max_pages = (int) apply_filters( 'sfaf_eventbrite_max_pages', self::DEFAULT_MAX_PAGES );
        if ( $max_pages < 1 ) {
            $max_pages = 1;
        }

        $items            = array();
        $pages            = 0;
        $continuation     = '';
        $truncated        = false;
        $truncated_reason = '';
        $first_endpoint   = self::endpoint( $path, $query );

        do {
            $page_query = $query;
            if ( '' !== $continuation ) {
                $page_query['continuation'] = $continuation;
            }

            $url  = self::endpoint( $path, $page_query );
            $body = self::request_json( $token, $url );
            if ( is_wp_error( $body ) ) {
                return $body;
            }
            $pages++;

            if ( isset( $body[ $collection_key ] ) && is_array( $body[ $collection_key ] ) ) {
                foreach ( $body[ $collection_key ] as $item ) {
                    if ( is_array( $item ) ) {
                        $items[] = $item;
                    }
                }
            } elseif ( 1 === $pages ) {
                // First page with no list at all means the endpoint answered
                // with something other than the collection we asked for —
                // worth naming, along with what it did return.
                $keys = array_keys( $body );
                return new WP_Error(
                    'sfaf_eventbrite_unexpected',
                    sprintf(
                        'HTTP 200 from %s but the response had no "%s" list. Keys returned: %s',
                        $url,
                        $collection_key,
                        empty( $keys ) ? '(none)' : implode( ', ', $keys )
                    )
                );
            }

            $pagination   = isset( $body['pagination'] ) && is_array( $body['pagination'] ) ? $body['pagination'] : array();
            $has_more     = ! empty( $pagination['has_more_items'] );
            $continuation = isset( $pagination['continuation'] ) ? (string) $pagination['continuation'] : '';

            if ( $has_more && '' === $continuation ) {
                $truncated        = true;
                $truncated_reason = sprintf(
                    'Eventbrite reported more items after page %d but sent no continuation token, so the list stops there.',
                    $pages
                );
                break;
            }

            if ( $has_more && $pages >= $max_pages ) {
                $truncated        = true;
                $truncated_reason = sprintf(
                    'Stopped at the %d-page limit with more items still available. Raise it with the sfaf_eventbrite_max_pages filter.',
                    $max_pages
                );
                break;
            }
        } while ( $has_more );

        return array(
            'items'            => $items,
            'pages'            => $pages,
            'truncated'        => $truncated,
            'truncated_reason' => $truncated_reason,
            'endpoint'         => $first_endpoint,
        );
    }

    /**
     * The organizations this token can see.
     *
     * GET {api_base}/users/me/organizations/
     *
     * @param string $token Private token.
     * @return array|WP_Error {items, pages, truncated, truncated_reason, endpoint}
     */
    public static function fetch_organizations( $token ) {
        $token = trim( (string) $token );
        if ( '' === $token ) {
            return new WP_Error( 'sfaf_eventbrite_missing', 'A private token is required.' );
        }
        return self::fetch_all_pages( $token, 'users/me/organizations/', 'organizations' );
    }

    /**
     * Events belonging to one organization.
     *
     * GET {api_base}/organizations/{organization_id}/events/
     *
     * @param string $token  Private token.
     * @param string $org_id Organization ID.
     * @param array  $query  Query arguments (status, expand, …).
     * @return array|WP_Error {items, pages, truncated, truncated_reason, endpoint}
     */
    public static function fetch_organization_events( $token, $org_id, $query = array() ) {
        $token  = trim( (string) $token );
        $org_id = trim( (string) $org_id );

        if ( '' === $token ) {
            return new WP_Error( 'sfaf_eventbrite_missing', 'A private token is required.' );
        }
        if ( '' === $org_id ) {
            return new WP_Error( 'sfaf_eventbrite_missing_org', 'An organization ID is required to fetch events.' );
        }

        return self::fetch_all_pages( $token, 'organizations/' . rawurlencode( $org_id ) . '/events/', 'events', $query );
    }

    /**
     * Every event the token can see, across every organization it owns.
     *
     * The seam step 3 will import through: it returns normalised rows for
     * display and mapping, each carrying the untouched API payload, plus a
     * per-organization account of what was fetched and from where.
     *
     * One organization failing does not lose the others — its error is
     * recorded against that organization and the rest still come back. Only a
     * failure of the organizations lookup itself is fatal, since without it
     * there is nothing to iterate.
     *
     * @param string $token Private token.
     * @param array  $args  {
     *     @type string $status Event status to request. Default 'live'.
     *     @type string $expand Comma-separated expansions. Default 'venue,logo'.
     * }
     * @return array|WP_Error
     */
    public static function fetch_events( $token, $args = array() ) {
        $token = trim( (string) $token );
        if ( '' === $token ) {
            return new WP_Error( 'sfaf_eventbrite_missing', 'A private token is required.' );
        }

        $defaults = array(
            // Published events only by default.
            'status' => (string) apply_filters( 'sfaf_eventbrite_event_status', 'live' ),
            // Expansions ride along as query parameters; venue gives the
            // location and logo gives the image, both of which the mapping
            // step will want and neither of which is present without asking.
            'expand' => (string) apply_filters( 'sfaf_eventbrite_event_expand', 'venue,logo' ),
        );
        $args = array_merge( $defaults, is_array( $args ) ? $args : array() );

        $query = array();
        if ( '' !== trim( (string) $args['status'] ) ) {
            $query['status'] = trim( (string) $args['status'] );
        }
        if ( '' !== trim( (string) $args['expand'] ) ) {
            $query['expand'] = trim( (string) $args['expand'] );
        }

        $orgs_result = self::fetch_organizations( $token );
        if ( is_wp_error( $orgs_result ) ) {
            return $orgs_result;
        }

        $organizations = array();
        $events        = array();
        $sample_raw    = null;
        $notes         = array();

        if ( $orgs_result['truncated'] ) {
            $notes[] = 'Organizations: ' . $orgs_result['truncated_reason'];
        }

        foreach ( $orgs_result['items'] as $org ) {
            $org_id   = isset( $org['id'] ) ? (string) $org['id'] : '';
            $org_name = isset( $org['name'] ) && is_string( $org['name'] ) ? $org['name'] : '';

            $row = array(
                'id'       => $org_id,
                'name'     => $org_name,
                'count'    => 0,
                'pages'    => 0,
                'endpoint' => '',
                'error'    => '',
            );

            if ( '' === $org_id ) {
                $row['error'] = 'This organization came back without an id, so its events could not be requested.';
                $organizations[] = $row;
                continue;
            }

            $result = self::fetch_organization_events( $token, $org_id, $query );

            if ( is_wp_error( $result ) ) {
                // Keep going: one bad organization should not hide the rest.
                $row['error']    = $result->get_error_message();
                $row['endpoint'] = self::endpoint( 'organizations/' . rawurlencode( $org_id ) . '/events/', $query );
                $organizations[] = $row;
                continue;
            }

            $row['pages']    = (int) $result['pages'];
            $row['endpoint'] = (string) $result['endpoint'];
            $row['count']    = count( $result['items'] );

            if ( $result['truncated'] ) {
                $notes[] = sprintf( 'Events for %s: %s', ( '' !== $org_name ) ? $org_name : $org_id, $result['truncated_reason'] );
            }

            foreach ( $result['items'] as $event ) {
                if ( null === $sample_raw ) {
                    $sample_raw = $event;
                }
                $events[] = self::normalize_event( $event, $org_id, $org_name );
            }

            $organizations[] = $row;
        }

        return array(
            'organizations'      => $organizations,
            'organization_count' => count( $organizations ),
            'events'             => $events,
            'total'              => count( $events ),
            'sample_raw'         => $sample_raw,
            'notes'              => $notes,
            'status'             => isset( $query['status'] ) ? $query['status'] : '(any)',
            'expand'             => isset( $query['expand'] ) ? $query['expand'] : '(none)',
            'endpoints'          => array(
                'organizations' => (string) $orgs_result['endpoint'],
                'events'        => self::endpoint( 'organizations/{organization_id}/events/', $query ),
            ),
        );
    }

    /**
     * Flatten one raw event into the fields the preview shows and the import
     * will map from.
     *
     * Deliberately lossless in one respect: the untouched payload is kept
     * under 'raw', so nothing is decided here about what matters. Values are
     * read defensively because expansions are optional — ask for `venue` on an
     * online-only event and there simply is no venue object.
     *
     * @param array  $event    Raw event from the API.
     * @param string $org_id   Owning organization ID.
     * @param string $org_name Owning organization name.
     * @return array
     */
    private static function normalize_event( $event, $org_id = '', $org_name = '' ) {
        $text = function ( $value ) {
            return is_string( $value ) ? $value : '';
        };

        // name/description are objects with text+html, not plain strings.
        $name_text = '';
        if ( isset( $event['name'] ) && is_array( $event['name'] ) && isset( $event['name']['text'] ) ) {
            $name_text = $text( $event['name']['text'] );
        } elseif ( isset( $event['name'] ) && is_string( $event['name'] ) ) {
            $name_text = $event['name'];
        }

        $desc_text = '';
        $desc_html = '';
        if ( isset( $event['description'] ) && is_array( $event['description'] ) ) {
            $desc_text = isset( $event['description']['text'] ) ? $text( $event['description']['text'] ) : '';
            $desc_html = isset( $event['description']['html'] ) ? $text( $event['description']['html'] ) : '';
        }
        $summary = isset( $event['summary'] ) ? $text( $event['summary'] ) : '';

        $start = isset( $event['start'] ) && is_array( $event['start'] ) ? $event['start'] : array();
        $end   = isset( $event['end'] ) && is_array( $event['end'] ) ? $event['end'] : array();

        // Venue only exists when expand=venue was honoured and the event has one.
        $venue = isset( $event['venue'] ) && is_array( $event['venue'] ) ? $event['venue'] : array();
        $address = isset( $venue['address'] ) && is_array( $venue['address'] ) ? $venue['address'] : array();
        $address_display = '';
        if ( isset( $address['localized_address_display'] ) ) {
            $address_display = $text( $address['localized_address_display'] );
        }
        if ( '' === $address_display ) {
            // Fall back to assembling the parts, for payloads without the
            // pre-localized string.
            $parts = array();
            foreach ( array( 'address_1', 'address_2', 'city', 'region', 'postal_code', 'country' ) as $key ) {
                if ( isset( $address[ $key ] ) && '' !== $text( $address[ $key ] ) ) {
                    $parts[] = $text( $address[ $key ] );
                }
            }
            $address_display = implode( ', ', $parts );
        }

        // Logo likewise only exists when expand=logo was honoured.
        $logo = isset( $event['logo'] ) && is_array( $event['logo'] ) ? $event['logo'] : array();
        $logo_url = isset( $logo['url'] ) ? $text( $logo['url'] ) : '';
        $logo_original = '';
        if ( isset( $logo['original'] ) && is_array( $logo['original'] ) && isset( $logo['original']['url'] ) ) {
            $logo_original = $text( $logo['original']['url'] );
        }

        return array(
            'id'             => isset( $event['id'] ) ? (string) $event['id'] : '',
            'name'           => $name_text,
            'status'         => isset( $event['status'] ) ? $text( $event['status'] ) : '',
            'url'            => isset( $event['url'] ) ? $text( $event['url'] ) : '',
            'start_local'    => isset( $start['local'] ) ? $text( $start['local'] ) : '',
            'start_utc'      => isset( $start['utc'] ) ? $text( $start['utc'] ) : '',
            'start_timezone' => isset( $start['timezone'] ) ? $text( $start['timezone'] ) : '',
            'end_local'      => isset( $end['local'] ) ? $text( $end['local'] ) : '',
            'end_utc'        => isset( $end['utc'] ) ? $text( $end['utc'] ) : '',
            'end_timezone'   => isset( $end['timezone'] ) ? $text( $end['timezone'] ) : '',
            'online_event'   => ! empty( $event['online_event'] ),
            'listed'         => ! empty( $event['listed'] ),
            'currency'       => isset( $event['currency'] ) ? $text( $event['currency'] ) : '',
            'capacity'       => isset( $event['capacity'] ) && is_numeric( $event['capacity'] ) ? (int) $event['capacity'] : null,
            'venue_id'       => isset( $event['venue_id'] ) ? (string) $event['venue_id'] : '',
            'venue_name'     => isset( $venue['name'] ) ? $text( $venue['name'] ) : '',
            'venue_address'  => $address_display,
            'venue_expanded' => ! empty( $venue ),
            'logo_url'       => $logo_url,
            'logo_original'  => $logo_original,
            'logo_expanded'  => ! empty( $logo ),
            'description'    => array(
                'has_text'    => ( '' !== $desc_text ),
                'has_html'    => ( '' !== $desc_html ),
                'text_length' => strlen( $desc_text ),
                'html_length' => strlen( $desc_html ),
                'has_summary' => ( '' !== $summary ),
                'excerpt'     => ( '' !== $desc_text ) ? ( ( strlen( $desc_text ) > 160 ) ? substr( $desc_text, 0, 160 ) . '…' : $desc_text ) : $summary,
            ),
            'organization_id'   => $org_id,
            'organization_name' => $org_name,
            // The untouched payload, so the mapping step decides for itself.
            'raw'               => $event,
        );
    }

    /* ---------------------------------------------------------------------
     * Proof of connection
     * ------------------------------------------------------------------- */

    /**
     * Fingerprint of the credentials a verification was made with.
     *
     * A hash, never the token: it only has to answer "are these still the same
     * settings that worked?", which is why the base URL is folded in too — a
     * verification against one host proves nothing about another.
     *
     * @param string $token Private token.
     * @return string
     */
    private static function fingerprint( $token ) {
        return hash( 'sha256', self::api_base() . '|' . trim( (string) $token ) );
    }

    /**
     * Record a successful call. Holds no credential.
     *
     * @param array  $account Result of fetch_me().
     * @param string $token   The token that worked, for fingerprinting only.
     */
    private static function store_verification( $account, $token ) {
        update_option( self::STATUS_OPTION, array(
            'account_id'   => isset( $account['id'] ) ? (string) $account['id'] : '',
            'name'         => isset( $account['name'] ) ? (string) $account['name'] : '',
            'email'        => isset( $account['email'] ) ? (string) $account['email'] : '',
            'endpoint'     => self::me_endpoint(),
            'fingerprint'  => self::fingerprint( $token ),
            'verified_at'  => time(),
        ), false );
    }

    /** Forget the recorded connection (used when a test fails). */
    public static function clear_verification() {
        delete_option( self::STATUS_OPTION );
    }

    /** The stored verification record, or array() when there is none. */
    public static function verification_record() {
        $record = get_option( self::STATUS_OPTION, array() );
        return is_array( $record ) ? $record : array();
    }

    /**
     * Connection status for the settings screen.
     *
     * "Connected" means a real HTTP 200 from /users/me/ was recorded, with the
     * token and base URL still in place that produced it. There is no stored
     * flag anyone can set by hand, so the badge cannot claim a connection that
     * was never made.
     *
     * @return array{connected:bool,name:string,email:string,endpoint:string,verified_at:int,verified_human:string,has_token:bool}
     */
    public static function status() {
        $record = self::verification_record();
        $token  = self::private_token();

        $connected = ( '' !== $token )
            && ! empty( $record['account_id'] )
            && ! empty( $record['fingerprint'] )
            && hash_equals( (string) $record['fingerprint'], self::fingerprint( $token ) );

        $verified_at = isset( $record['verified_at'] ) ? (int) $record['verified_at'] : 0;

        return array(
            'connected'      => $connected,
            'name'           => $connected && isset( $record['name'] ) ? (string) $record['name'] : '',
            'email'          => $connected && isset( $record['email'] ) ? (string) $record['email'] : '',
            'endpoint'       => $connected && isset( $record['endpoint'] ) ? (string) $record['endpoint'] : '',
            'verified_at'    => $connected ? $verified_at : 0,
            'verified_human' => ( $connected && $verified_at ) ? human_time_diff( $verified_at, time() ) : '',
            'has_token'      => ( '' !== $token ),
        );
    }

    /* ---------------------------------------------------------------------
     * Settings-screen connection test
     * ------------------------------------------------------------------- */

    /**
     * Test the connection with whatever is currently in the form.
     *
     * The token comes from the POST when supplied so the button can be used
     * before saving; a blank token means "use the one already stored", which is
     * what the write-only field submits when it has not been retyped.
     */
    public function ajax_connect() {
        check_ajax_referer( 'uc_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
        }

        // Not sanitize_text_field: a private token is an opaque string and must
        // reach Eventbrite byte-for-byte. It is never echoed back out.
        $token = isset( $_POST['private_token'] ) ? trim( (string) wp_unslash( $_POST['private_token'] ) ) : '';
        if ( '' === $token ) {
            $token = self::private_token();
        }

        $account = self::fetch_me( $token );

        if ( is_wp_error( $account ) ) {
            // A failed test invalidates any previous claim of a connection.
            self::clear_verification();
            wp_send_json_error( array(
                'message'  => $account->get_error_message(),
                'endpoint' => self::me_endpoint(),
            ) );
        }

        self::store_verification( $account, $token );

        $who = ( '' !== $account['name'] ) ? $account['name'] : 'account ' . $account['id'];
        if ( '' !== $account['email'] ) {
            $who .= ' (' . $account['email'] . ')';
        }

        wp_send_json_success( array(
            'message'  => sprintf( 'Connected as %s. Verified by a live call to %s.', $who, self::me_endpoint() ),
            'endpoint' => self::me_endpoint(),
            'pill'     => sprintf( 'Connected as %s', ( '' !== $account['name'] ) ? $account['name'] : $account['id'] ),
            // Deliberately no token, no length hints.
        ) );
    }

    /**
     * Fetch every event and hand the raw shape back to the settings screen.
     *
     * Read-only, by design for this step: nothing is created, queued or
     * stored. The response is what came back from Eventbrite, flattened for
     * display with the untouched payload of the first event alongside it, so
     * the mapping can be written against data that has actually been seen.
     */
    public function ajax_preview() {
        check_ajax_referer( 'uc_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
        }

        // As with the connection test: the form's token when it has been
        // retyped, otherwise the stored one. Never sanitised, never echoed.
        $token = isset( $_POST['private_token'] ) ? trim( (string) wp_unslash( $_POST['private_token'] ) ) : '';
        if ( '' === $token ) {
            $token = self::private_token();
        }

        $args = array();
        if ( isset( $_POST['status'] ) ) {
            $status = sanitize_text_field( wp_unslash( $_POST['status'] ) );
            // "all" is this screen's word, not Eventbrite's — the API has no
            // such status, and the way to ask for every one is to send none.
            $args['status'] = ( 'all' === $status ) ? '' : $status;
        }

        $result = self::fetch_events( $token, $args );

        if ( is_wp_error( $result ) ) {
            // An auth failure here means the stored "Connected" badge is no
            // longer telling the truth, so it is withdrawn.
            $code = $result->get_error_code();
            if ( 'sfaf_eventbrite_http_401' === $code || 'sfaf_eventbrite_http_403' === $code ) {
                self::clear_verification();
            }
            wp_send_json_error( array(
                'message'  => $result->get_error_message(),
                'endpoint' => self::endpoint( 'users/me/organizations/' ),
            ) );
        }

        // The raw sample is pretty-printed here rather than in the browser so
        // the screen shows exactly the JSON this server received.
        $sample = null === $result['sample_raw']
            ? ''
            : wp_json_encode( $result['sample_raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        // The per-event 'raw' payload stays on the server. fetch_events() still
        // returns it — step 3 maps from it — but shipping every untouched
        // event object to the browser would make this response many times
        // larger for no gain, when one full sample already shows the shape.
        $rows = array();
        foreach ( $result['events'] as $event ) {
            unset( $event['raw'] );
            $rows[] = $event;
        }

        wp_send_json_success( array(
            'organizations'      => $result['organizations'],
            'organization_count' => $result['organization_count'],
            'events'             => $rows,
            'total'              => $result['total'],
            'notes'              => $result['notes'],
            'status'             => $result['status'],
            'expand'             => $result['expand'],
            'endpoints'          => $result['endpoints'],
            'sample_raw'         => is_string( $sample ) ? $sample : '',
        ) );
    }
}
